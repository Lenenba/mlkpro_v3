<?php

namespace App\Services;

use App\Models\Sale;
use App\Notifications\ActionEmailNotification;
use App\Notifications\OrderStatusNotification;
use App\Services\NotificationPreferenceService;
use App\Support\NotificationDispatcher;

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
        $preferences = app(NotificationPreferenceService::class);
        $portalUser = null;
        if ($customer->portal_user_id) {
            $portalUser = $customer->relationLoaded('portalUser')
                ? $customer->portalUser
                : $customer->portalUser()->first();
            if ($portalUser && $preferences->shouldNotify(
                $portalUser,
                NotificationPreferenceService::CATEGORY_ORDERS,
                NotificationPreferenceService::CHANNEL_IN_APP
            )) {
                $portalUser->notify(new OrderStatusNotification($sale, $title, $message));
            }
        }

        if (!$isProductCompany) {
            NotificationDispatcher::send($customer, new ActionEmailNotification(
                $title,
                $intro,
                $details,
                $actionUrl,
                'Voir la commande'
            ), [
                'sale_id' => $sale->id,
            ]);

            if (!empty($customer->phone)) {
                $smsMessage = $intro ? "{$title}: {$intro}" : "{$title}: {$details['Statut']}";
                app(SmsNotificationService::class)->send($customer->phone, $smsMessage);
            }
        }

        if ($portalUser && $preferences->shouldNotify(
            $portalUser,
            NotificationPreferenceService::CATEGORY_ORDERS,
            NotificationPreferenceService::CHANNEL_PUSH
        )) {
            app(PushNotificationService::class)->sendToUsers([$portalUser->id], [
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

    public function notifyDepositRequested(Sale $sale, float $amount): void
    {
        $customer = $sale->customer;
        if (!$customer) {
            return;
        }

        $portalUser = $customer->portalUser;
        if (!$portalUser) {
            return;
        }

        $formatted = '$' . number_format($amount, 2);
        $title = 'Acompte requis';
        $message = "Un acompte de {$formatted} est requis pour commencer la preparation.";

        $preferences = app(NotificationPreferenceService::class);
        if ($preferences->shouldNotify(
            $portalUser,
            NotificationPreferenceService::CATEGORY_ORDERS,
            NotificationPreferenceService::CHANNEL_IN_APP
        )) {
            $portalUser->notify(new OrderStatusNotification($sale, $title, $message));
        }

        if ($preferences->shouldNotify(
            $portalUser,
            NotificationPreferenceService::CATEGORY_ORDERS,
            NotificationPreferenceService::CHANNEL_PUSH
        )) {
            app(PushNotificationService::class)->sendToUsers([$portalUser->id], [
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

    public function notifyDepositReminder(Sale $sale, float $amount): void
    {
        $customer = $sale->customer;
        if (!$customer) {
            return;
        }

        $portalUser = $customer->portalUser;
        $formatted = '$' . number_format($amount, 2);
        $title = 'Rappel acompte';
        $message = "Rappel: un acompte de {$formatted} est requis pour commencer la preparation.";
        $actionUrl = route('portal.orders.edit', $sale);

        if ($portalUser) {
            $preferences = app(NotificationPreferenceService::class);
            if ($preferences->shouldNotify(
                $portalUser,
                NotificationPreferenceService::CATEGORY_ORDERS,
                NotificationPreferenceService::CHANNEL_IN_APP
            )) {
                $portalUser->notify(new OrderStatusNotification($sale, $title, $message, $actionUrl));
            }

            if ($preferences->shouldNotify(
                $portalUser,
                NotificationPreferenceService::CATEGORY_ORDERS,
                NotificationPreferenceService::CHANNEL_PUSH
            )) {
                app(PushNotificationService::class)->sendToUsers([$portalUser->id], [
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

        if (!empty($customer->email)) {
            $details = [
                'Commande' => $sale->number ?: "Sale #{$sale->id}",
                'Acompte requis' => $formatted,
                'Total' => '$' . number_format((float) $sale->total, 2),
            ];
            NotificationDispatcher::send($customer, new ActionEmailNotification(
                $title,
                $message,
                $details,
                $actionUrl,
                'Payer maintenant'
            ), [
                'sale_id' => $sale->id,
            ]);
        }
    }
}
