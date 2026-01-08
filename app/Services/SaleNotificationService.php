<?php

namespace App\Services;

use App\Models\Sale;
use App\Notifications\ActionEmailNotification;
use App\Notifications\OrderStatusNotification;

class SaleNotificationService
{
    public function notifyStatusChange(Sale $sale, array $changes = []): void
    {
        $customer = $sale->customer;
        if (!$customer) {
            return;
        }
        $owner = $sale->relationLoaded('user')
            ? $sale->user
            : $sale->user()->select(['id', 'company_type'])->first();
        $isProductCompany = $owner?->company_type === 'products';

        $statusLabels = [
            'draft' => 'Brouillon',
            'pending' => 'En attente',
            'paid' => 'Payee',
            'canceled' => 'Annulee',
        ];

        $fulfillmentLabels = [
            'pending' => 'Commande recue',
            'preparing' => 'Preparation',
            'out_for_delivery' => 'En cours de livraison',
            'ready_for_pickup' => 'Pret a retirer',
            'completed' => 'Livree',
            'confirmed' => 'Confirmee',
        ];

        $title = 'Mise a jour de commande';
        $intro = null;

        if (($changes['status'] ?? null) !== null) {
            $newStatus = $sale->status;
            $intro = 'Votre commande est maintenant ' . ($statusLabels[$newStatus] ?? $newStatus) . '.';
        }

        if (($changes['fulfillment_status'] ?? null) !== null) {
            $newFulfillment = $sale->fulfillment_status;
            $intro = 'Livraison: ' . ($fulfillmentLabels[$newFulfillment] ?? $newFulfillment) . '.';
        }

        if (($changes['scheduled_for'] ?? null) !== null && $sale->scheduled_for) {
            $intro = 'Nouvelle estimation: ' . $sale->scheduled_for->toDayDateTimeString() . '.';
        }

        $details = [
            'Commande' => $sale->number ?: "Sale #{$sale->id}",
            'Statut' => $statusLabels[$sale->status] ?? $sale->status,
        ];

        if ($sale->fulfillment_status) {
            $details['Livraison'] = $fulfillmentLabels[$sale->fulfillment_status] ?? $sale->fulfillment_status;
        }

        if ($sale->scheduled_for) {
            $details['ETA'] = $sale->scheduled_for->toDayDateTimeString();
        }

        $actionUrl = route('portal.orders.edit', $sale);

        $message = $intro ?? 'Votre commande a ete mise a jour.';
        if ($customer->portal_user_id) {
            $portalUser = $customer->relationLoaded('portalUser')
                ? $customer->portalUser
                : $customer->portalUser()->first();
            if ($portalUser) {
                $portalUser->notify(new OrderStatusNotification($sale, $title, $message));
            }
        }

        if (!$isProductCompany) {
            $customer->notify(new ActionEmailNotification(
                $title,
                $intro,
                $details,
                $actionUrl,
                'Voir la commande'
            ));

            if (!empty($customer->phone)) {
                $smsMessage = $intro ? "{$title}: {$intro}" : "{$title}: {$details['Statut']}";
                app(SmsNotificationService::class)->send($customer->phone, $smsMessage);
            }
        }

        if ($customer->portal_user_id) {
            app(PushNotificationService::class)->sendToUsers([$customer->portal_user_id], [
                'title' => $title,
                'body' => $message,
                'data' => [
                    'sale_id' => $sale->id,
                    'status' => $sale->status,
                    'fulfillment_status' => $sale->fulfillment_status,
                ],
            ]);
        }
    }
}
