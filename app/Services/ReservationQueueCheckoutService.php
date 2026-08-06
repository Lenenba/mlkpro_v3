<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\ReservationQueueItem;
use App\Models\User;
use App\Support\TipCalculator;
use App\Support\TipSettingsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationQueueCheckoutService
{
    public function __construct(
        private readonly ReservationQueueService $queueService,
        private readonly TenantPaymentMethodGuardService $paymentMethodGuard,
        private readonly TipAllocationService $tipAllocationService
    ) {}

    /**
     * @return array{queue_item: ReservationQueueItem, payment: Payment, already_paid: bool}
     */
    public function checkout(
        ReservationQueueItem $item,
        array $attributes,
        User $actor,
        array $settings
    ): array {
        return DB::transaction(function () use ($item, $attributes, $actor, $settings): array {
            $locked = ReservationQueueItem::query()
                ->with([
                    'service:id,user_id,name,price,currency_code',
                    'teamMember:id,user_id',
                ])
                ->lockForUpdate()
                ->findOrFail($item->id);

            $existingPayment = Payment::query()
                ->where('reservation_queue_item_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($existingPayment) {
                if (! in_array($existingPayment->status, Payment::settledStatuses(), true)) {
                    throw ValidationException::withMessages([
                        'payment' => ['A payment is already awaiting confirmation for this queue item.'],
                    ]);
                }

                $queueItem = (string) $locked->status === ReservationQueueItem::STATUS_DONE
                    ? $locked
                    : $this->queueService->transition($locked, 'done', $actor, $settings, [
                        'checkout_settled' => true,
                    ]);

                return [
                    'queue_item' => $queueItem,
                    'payment' => $existingPayment,
                    'already_paid' => true,
                ];
            }

            if ((string) $locked->status !== ReservationQueueItem::STATUS_AWAITING_PAYMENT) {
                throw ValidationException::withMessages([
                    'queue' => ['Finish the service before recording its payment.'],
                ]);
            }

            $checkout = $this->queueService->checkoutSummary($locked);
            $amount = (float) ($checkout['base_amount'] ?? 0);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'payment' => ['No payment is required for this queue item.'],
                ]);
            }

            $method = $this->paymentMethodGuard->evaluate(
                (int) $locked->account_id,
                $attributes['method'] ?? null,
                'walk_in'
            );
            if (! ($method['allowed'] ?? false)) {
                throw ValidationException::withMessages([
                    'method' => [(string) ($method['error_message'] ?? TenantPaymentMethodGuardService::ERROR_MESSAGE)],
                ]);
            }

            $tip = TipCalculator::resolve(
                $amount,
                $attributes,
                TipSettingsResolver::forAccountId((int) $locked->account_id)
            );
            $tipAssigneeUserId = (int) ($locked->teamMember?->user_id ?? 0);
            $payment = Payment::query()->create([
                'reservation_queue_item_id' => $locked->id,
                'customer_id' => $locked->client_id,
                'user_id' => $locked->account_id,
                'amount' => $amount,
                'currency_code' => $checkout['currency_code'],
                'tip_amount' => $tip['tip_amount'],
                'tip_type' => $tip['tip_type'],
                'tip_percent' => $tip['tip_percent'],
                'tip_base_amount' => $tip['tip_base_amount'],
                'charged_total' => $tip['charged_total'],
                'tip_assignee_user_id' => $tip['tip_amount'] > 0 && $tipAssigneeUserId > 0
                    ? $tipAssigneeUserId
                    : null,
                'method' => $method['canonical_method'],
                'provider' => 'manual',
                'status' => Payment::STATUS_COMPLETED,
                'reference' => $this->nullableString($attributes['reference'] ?? null),
                'notes' => $this->checkoutNotes($locked, $attributes['notes'] ?? null),
                'paid_at' => now('UTC'),
            ]);

            $this->tipAllocationService->syncForPayment($payment);
            $queueItem = $this->queueService->transition($locked, 'done', $actor, $settings, [
                'checkout_settled' => true,
            ]);

            ActivityLog::record($actor, $payment, 'queue_checkout_completed', [
                'account_id' => (int) $locked->account_id,
                'queue_item_id' => (int) $locked->id,
                'reservation_id' => $locked->reservation_id ? (int) $locked->reservation_id : null,
                'service_id' => $locked->service_id ? (int) $locked->service_id : null,
                'queue_number' => $locked->queue_number,
                'amount' => $payment->amount,
                'tip_amount' => $payment->tip_amount,
                'charged_total' => $payment->charged_total,
                'method' => $payment->method,
            ], 'Queue checkout completed');

            return [
                'queue_item' => $queueItem,
                'payment' => $payment,
                'already_paid' => false,
            ];
        });
    }

    private function checkoutNotes(ReservationQueueItem $item, mixed $notes): string
    {
        $reference = $item->queue_number ?: '#'.$item->id;
        $prefix = 'Queue checkout '.$reference;
        $notes = $this->nullableString($notes);

        return $notes ? $prefix."\n".$notes : $prefix;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
