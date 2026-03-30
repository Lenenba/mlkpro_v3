<?php

namespace App\Services;

use App\Models\Sale;
use App\Notifications\ActionEmailNotification;
use App\Notifications\OrderStatusNotification;
use App\Services\NotificationPreferenceService;
use App\Support\LocalePreference;
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
            : $sale->user()->select(['id', 'company_type', 'locale'])->first();
        $isProductCompany = $owner?->company_type === 'products';
        $customerLocale = LocalePreference::forCustomer($customer, $owner);
        $customerIsFr = str_starts_with($customerLocale, 'fr');

        $statusLabels = $customerIsFr
            ? ['draft' => 'Brouillon', 'pending' => 'En attente', 'paid' => 'Payee', 'canceled' => 'Annulee']
            : ['draft' => 'Draft', 'pending' => 'Pending', 'paid' => 'Paid', 'canceled' => 'Canceled'];

        $fulfillmentLabels = $customerIsFr
            ? [
                'pending' => 'Commande recue',
                'preparing' => 'Preparation',
                'out_for_delivery' => 'En cours de livraison',
                'ready_for_pickup' => 'Pret a retirer',
                'completed' => 'Livree',
                'confirmed' => 'Confirmee',
            ]
            : [
                'pending' => 'Order received',
                'preparing' => 'Preparing',
                'out_for_delivery' => 'Out for delivery',
                'ready_for_pickup' => 'Ready for pickup',
                'completed' => 'Delivered',
                'confirmed' => 'Confirmed',
            ];

        $title = $customerIsFr ? 'Mise a jour de commande' : 'Order update';
        $intro = null;

        if (($changes['status'] ?? null) !== null) {
            $newStatus = $sale->status;
            $intro = $customerIsFr
                ? 'Votre commande est maintenant ' . ($statusLabels[$newStatus] ?? $newStatus) . '.'
                : 'Your order is now ' . ($statusLabels[$newStatus] ?? $newStatus) . '.';
        }

        if (($changes['fulfillment_status'] ?? null) !== null) {
            $newFulfillment = $sale->fulfillment_status;
            $intro = $customerIsFr
                ? 'Livraison: ' . ($fulfillmentLabels[$newFulfillment] ?? $newFulfillment) . '.'
                : 'Delivery: ' . ($fulfillmentLabels[$newFulfillment] ?? $newFulfillment) . '.';
        }

        if (($changes['scheduled_for'] ?? null) !== null && $sale->scheduled_for) {
            $intro = $customerIsFr
                ? 'Nouvelle estimation: ' . $sale->scheduled_for->toDayDateTimeString() . '.'
                : 'Updated ETA: ' . $sale->scheduled_for->toDayDateTimeString() . '.';
        }

        $details = [
            ['label' => $customerIsFr ? 'Commande' : 'Order', 'value' => $sale->number ?: "Sale #{$sale->id}"],
            ['label' => $customerIsFr ? 'Statut' : 'Status', 'value' => $statusLabels[$sale->status] ?? $sale->status],
        ];

        if ($sale->fulfillment_status) {
            $details[] = ['label' => $customerIsFr ? 'Livraison' : 'Delivery', 'value' => $fulfillmentLabels[$sale->fulfillment_status] ?? $sale->fulfillment_status];
        }

        if ($sale->scheduled_for) {
            $details[] = ['label' => 'ETA', 'value' => $sale->scheduled_for->toDayDateTimeString()];
        }

        $actionUrl = route('portal.orders.show', $sale);

        $message = $intro ?? ($customerIsFr ? 'Votre commande a ete mise a jour.' : 'Your order was updated.');
        $preferences = app(NotificationPreferenceService::class);
        $portalUser = null;
        $portalTitle = $title;
        $portalMessage = $message;
        if ($customer->portal_user_id) {
            $portalUser = $customer->relationLoaded('portalUser')
                ? $customer->portalUser
                : $customer->portalUser()->first();
            if ($portalUser && $preferences->shouldNotify(
                $portalUser,
                NotificationPreferenceService::CATEGORY_ORDERS,
                NotificationPreferenceService::CHANNEL_IN_APP
            )) {
                $portalLocale = LocalePreference::forUser($portalUser);
                $portalIsFr = str_starts_with($portalLocale, 'fr');
                $portalStatusLabels = $portalIsFr
                    ? ['draft' => 'Brouillon', 'pending' => 'En attente', 'paid' => 'Payee', 'canceled' => 'Annulee']
                    : ['draft' => 'Draft', 'pending' => 'Pending', 'paid' => 'Paid', 'canceled' => 'Canceled'];
                $portalFulfillmentLabels = $portalIsFr
                    ? [
                        'pending' => 'Commande recue',
                        'preparing' => 'Preparation',
                        'out_for_delivery' => 'En cours de livraison',
                        'ready_for_pickup' => 'Pret a retirer',
                        'completed' => 'Livree',
                        'confirmed' => 'Confirmee',
                    ]
                    : [
                        'pending' => 'Order received',
                        'preparing' => 'Preparing',
                        'out_for_delivery' => 'Out for delivery',
                        'ready_for_pickup' => 'Ready for pickup',
                        'completed' => 'Delivered',
                        'confirmed' => 'Confirmed',
                    ];
                $portalTitle = $portalIsFr ? 'Mise a jour de commande' : 'Order update';
                $portalMessage = $portalIsFr ? 'Votre commande a ete mise a jour.' : 'Your order was updated.';
                if (($changes['status'] ?? null) !== null) {
                    $portalMessage = $portalIsFr
                        ? 'Votre commande est maintenant ' . ($portalStatusLabels[$sale->status] ?? $sale->status) . '.'
                        : 'Your order is now ' . ($portalStatusLabels[$sale->status] ?? $sale->status) . '.';
                }
                if (($changes['fulfillment_status'] ?? null) !== null) {
                    $portalMessage = $portalIsFr
                        ? 'Livraison: ' . ($portalFulfillmentLabels[$sale->fulfillment_status] ?? $sale->fulfillment_status) . '.'
                        : 'Delivery: ' . ($portalFulfillmentLabels[$sale->fulfillment_status] ?? $sale->fulfillment_status) . '.';
                }
                if (($changes['scheduled_for'] ?? null) !== null && $sale->scheduled_for) {
                    $portalMessage = $portalIsFr
                        ? 'Nouvelle estimation: ' . $sale->scheduled_for->toDayDateTimeString() . '.'
                        : 'Updated ETA: ' . $sale->scheduled_for->toDayDateTimeString() . '.';
                }

                $portalUser->notify(new OrderStatusNotification($sale, $portalTitle, $portalMessage, $actionUrl));
            }
        }

        if (!$isProductCompany) {
            NotificationDispatcher::send($customer, new ActionEmailNotification(
                $title,
                $intro,
                $details,
                $actionUrl,
                $customerIsFr ? 'Voir la commande' : 'View order'
            ), [
                'sale_id' => $sale->id,
            ]);

            if (!empty($customer->phone)) {
                $statusValue = $statusLabels[$sale->status] ?? $sale->status;
                $smsMessage = $intro ? "{$title}: {$intro}" : "{$title}: {$statusValue}";
                app(SmsNotificationService::class)->send($customer->phone, $smsMessage);
            }
        }

        if ($portalUser && $preferences->shouldNotify(
            $portalUser,
            NotificationPreferenceService::CATEGORY_ORDERS,
            NotificationPreferenceService::CHANNEL_PUSH
        )) {
            app(PushNotificationService::class)->sendToUsers([$portalUser->id], [
                'title' => $portalTitle,
                'body' => $portalMessage,
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
        $owner = $sale->relationLoaded('user') ? $sale->user : $sale->user()->select(['id', 'locale'])->first();
        $customerLocale = LocalePreference::forCustomer($customer, $owner);
        $customerIsFr = str_starts_with($customerLocale, 'fr');
        $formatted = '$' . number_format($amount, 2);
        $title = $customerIsFr ? 'Acompte requis' : 'Deposit required';
        $message = $customerIsFr
            ? "Un acompte de {$formatted} est requis pour commencer la preparation."
            : "A deposit of {$formatted} is required to start preparation.";
        $actionUrl = route('portal.orders.show', $sale);

        $preferences = app(NotificationPreferenceService::class);
        if ($portalUser) {
            $portalLocale = LocalePreference::forUser($portalUser);
            $portalIsFr = str_starts_with($portalLocale, 'fr');
            $portalTitle = $portalIsFr ? 'Acompte requis' : 'Deposit required';
            $portalMessage = $portalIsFr
                ? "Un acompte de {$formatted} est requis pour commencer la preparation."
                : "A deposit of {$formatted} is required to start preparation.";
            if ($preferences->shouldNotify(
                $portalUser,
                NotificationPreferenceService::CATEGORY_ORDERS,
                NotificationPreferenceService::CHANNEL_IN_APP
            )) {
                $portalUser->notify(new OrderStatusNotification($sale, $portalTitle, $portalMessage, $actionUrl));
            }

            if ($preferences->shouldNotify(
                $portalUser,
                NotificationPreferenceService::CATEGORY_ORDERS,
                NotificationPreferenceService::CHANNEL_PUSH
            )) {
                app(PushNotificationService::class)->sendToUsers([$portalUser->id], [
                    'title' => $portalTitle,
                    'body' => $portalMessage,
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
                ['label' => $customerIsFr ? 'Commande' : 'Order', 'value' => $sale->number ?: "Sale #{$sale->id}"],
                ['label' => $customerIsFr ? 'Acompte requis' : 'Required deposit', 'value' => $formatted],
                ['label' => 'Total', 'value' => '$' . number_format((float) $sale->total, 2)],
            ];
            NotificationDispatcher::send($customer, new ActionEmailNotification(
                $title,
                $message,
                $details,
                $actionUrl,
                $customerIsFr ? 'Payer maintenant' : 'Pay now'
            ), [
                'sale_id' => $sale->id,
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
        $owner = $sale->relationLoaded('user') ? $sale->user : $sale->user()->select(['id', 'locale'])->first();
        $customerLocale = LocalePreference::forCustomer($customer, $owner);
        $customerIsFr = str_starts_with($customerLocale, 'fr');
        $formatted = '$' . number_format($amount, 2);
        $title = $customerIsFr ? 'Rappel acompte' : 'Deposit reminder';
        $message = $customerIsFr
            ? "Rappel: un acompte de {$formatted} est requis pour commencer la preparation."
            : "Reminder: a deposit of {$formatted} is required to start preparation.";
        $actionUrl = route('portal.orders.show', $sale);

        if ($portalUser) {
            $preferences = app(NotificationPreferenceService::class);
            $portalLocale = LocalePreference::forUser($portalUser);
            $portalIsFr = str_starts_with($portalLocale, 'fr');
            $portalTitle = $portalIsFr ? 'Rappel acompte' : 'Deposit reminder';
            $portalMessage = $portalIsFr
                ? "Rappel: un acompte de {$formatted} est requis pour commencer la preparation."
                : "Reminder: a deposit of {$formatted} is required to start preparation.";
            if ($preferences->shouldNotify(
                $portalUser,
                NotificationPreferenceService::CATEGORY_ORDERS,
                NotificationPreferenceService::CHANNEL_IN_APP
            )) {
                $portalUser->notify(new OrderStatusNotification($sale, $portalTitle, $portalMessage, $actionUrl));
            }

            if ($preferences->shouldNotify(
                $portalUser,
                NotificationPreferenceService::CATEGORY_ORDERS,
                NotificationPreferenceService::CHANNEL_PUSH
            )) {
                app(PushNotificationService::class)->sendToUsers([$portalUser->id], [
                    'title' => $portalTitle,
                    'body' => $portalMessage,
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
                ['label' => $customerIsFr ? 'Commande' : 'Order', 'value' => $sale->number ?: "Sale #{$sale->id}"],
                ['label' => $customerIsFr ? 'Acompte requis' : 'Required deposit', 'value' => $formatted],
                ['label' => 'Total', 'value' => '$' . number_format((float) $sale->total, 2)],
            ];
            NotificationDispatcher::send($customer, new ActionEmailNotification(
                $title,
                $message,
                $details,
                $actionUrl,
                $customerIsFr ? 'Payer maintenant' : 'Pay now'
            ), [
                'sale_id' => $sale->id,
            ]);
        }
    }
}
