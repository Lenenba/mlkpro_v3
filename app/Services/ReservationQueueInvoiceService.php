<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ReservationQueueItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReservationQueueInvoiceService
{
    public const SOURCE_RESERVATION_QUEUE = 'reservation_queue';

    public function __construct(private readonly ReservationQueueService $queueService) {}

    /**
     * Create the immutable billing snapshot for a queue item once, or return it when it already exists.
     */
    public function findOrCreateForQueueItem(ReservationQueueItem $item): Invoice
    {
        return DB::transaction(function () use ($item): Invoice {
            $queueItem = ReservationQueueItem::query()
                ->with([
                    'service:id,user_id,name,description,price,currency_code',
                    'teamMember.user:id,name',
                    'client:id,first_name,last_name,company_name,email,phone',
                    'reservation:id,client_id',
                    'reservation.client:id,first_name,last_name,company_name,email,phone',
                ])
                ->lockForUpdate()
                ->findOrFail($item->id);

            $existing = Invoice::query()
                ->where('reservation_queue_item_id', $queueItem->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->load(['items', 'reservationQueueItem']);
            }

            $checkout = $this->queueService->checkoutSummary($queueItem);
            $currencyCode = (string) ($checkout['currency_code'] ?? 'CAD');
            $baseAmount = round(max(0, (float) ($checkout['base_amount'] ?? 0)), 2);
            $serviceName = trim((string) ($checkout['service_name'] ?? $queueItem->service?->name ?? ''));
            $serviceName = $serviceName !== '' ? $serviceName : 'Service';
            $customer = $queueItem->client ?: $queueItem->reservation?->client;
            $teamMemberName = trim((string) ($queueItem->teamMember?->user?->name ?? ''));

            $invoice = Invoice::query()->create([
                'reservation_queue_item_id' => $queueItem->id,
                'work_id' => null,
                'customer_id' => $customer?->id,
                'user_id' => $queueItem->account_id,
                'created_by_user_id' => $queueItem->created_by_user_id ?: $queueItem->account_id,
                'status' => 'draft',
                'source' => self::SOURCE_RESERVATION_QUEUE,
                'customer_snapshot' => $this->customerSnapshot($queueItem, $customer),
                'total' => $baseAmount,
                'currency_code' => $currencyCode,
            ]);

            InvoiceItem::query()->create([
                'invoice_id' => $invoice->id,
                'task_id' => null,
                'work_id' => null,
                'assigned_team_member_id' => $queueItem->team_member_id,
                'title' => Str::limit($serviceName, 255, ''),
                'description' => $queueItem->service?->description,
                'assignee_name' => $teamMemberName !== '' ? Str::limit($teamMemberName, 255, '') : null,
                'quantity' => 1,
                'unit_price' => $baseAmount,
                'currency_code' => $currencyCode,
                'total' => $baseAmount,
                'meta' => [
                    'source' => self::SOURCE_RESERVATION_QUEUE,
                    'reservation_queue_item_id' => (int) $queueItem->id,
                    'queue_number' => $queueItem->queue_number,
                    'queue_source' => $queueItem->source,
                    'reservation_id' => $queueItem->reservation_id ? (int) $queueItem->reservation_id : null,
                    'service' => [
                        'id' => $queueItem->service_id ? (int) $queueItem->service_id : null,
                        'name' => $serviceName,
                        'description' => $queueItem->service?->description,
                        'base_amount' => $baseAmount,
                        'currency_code' => $currencyCode,
                    ],
                    'team_member' => [
                        'id' => $queueItem->team_member_id ? (int) $queueItem->team_member_id : null,
                        'name' => $teamMemberName !== '' ? $teamMemberName : null,
                    ],
                ],
            ]);

            return $invoice->load(['items', 'reservationQueueItem']);
        });
    }

    /**
     * @return array<string, int|string|null>
     */
    private function customerSnapshot(ReservationQueueItem $item, ?Customer $customer): array
    {
        $guestName = $this->nullableString(data_get($item->metadata, 'guest_name'));
        $guestEmail = $this->nullableString(data_get($item->metadata, 'guest_email'));
        $guestPhone = $this->nullableString(data_get($item->metadata, 'guest_phone'));
        $customerName = $customer ? $this->customerName($customer) : null;

        return [
            'type' => $customer ? 'customer' : 'guest',
            'customer_id' => $customer?->id,
            'name' => $customerName ?: $guestName,
            'first_name' => $customer?->first_name,
            'last_name' => $customer?->last_name,
            'company_name' => $customer?->company_name,
            'email' => $customer?->email ?: $guestEmail,
            'phone' => $customer?->phone ?: $guestPhone,
            'queue_number' => $item->queue_number,
        ];
    }

    private function customerName(Customer $customer): ?string
    {
        $companyName = $this->nullableString($customer->company_name);
        if ($companyName) {
            return $companyName;
        }

        return $this->nullableString(trim((string) $customer->first_name.' '.(string) $customer->last_name));
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
