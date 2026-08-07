<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\ReservationQueueItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReservationQueueInvoiceService
{
    public const SOURCE_RESERVATION_QUEUE = 'reservation_queue';

    public function __construct(
        private readonly ReservationQueueService $queueService,
        private readonly FinanceApprovalService $financeApprovalService
    ) {}

    /**
     * Create the immutable billing snapshot for a queue item once, or return it when it already exists.
     */
    public function findOrCreateForQueueItem(ReservationQueueItem $item): Invoice
    {
        return DB::transaction(function () use ($item): Invoice {
            $queueItem = ReservationQueueItem::query()
                ->with([
                    'service:id,user_id,name,description,price,currency_code,tax_rate',
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
                $existing = $this->repairLegacyDraftInvoice($existing, $queueItem);

                return $existing->load(['items', 'reservationQueueItem']);
            }

            $billing = $this->billingContext($queueItem);
            $currencyCode = $billing['currency_code'];
            $subtotal = $billing['subtotal'];
            $taxRate = $billing['tax_rate'];
            $taxTotal = $billing['tax_total'];
            $invoiceTotal = $billing['invoice_total'];
            $serviceName = $billing['service_name'];
            $customer = $billing['customer'];
            $teamMemberName = $billing['team_member_name'];
            $accountOwner = $billing['account_owner'];
            $approval = $billing['approval'];
            $billingSnapshot = $billing['billing_snapshot'];

            $invoice = Invoice::query()->create([
                'reservation_queue_item_id' => $queueItem->id,
                'work_id' => null,
                'customer_id' => $customer?->id,
                'user_id' => $queueItem->account_id,
                'created_by_user_id' => $queueItem->created_by_user_id ?: $queueItem->account_id,
                'status' => 'draft',
                'approval_status' => $approval['approval_status'],
                'current_approver_role_key' => $approval['current_approver_role_key'],
                'current_approval_level' => $approval['current_approval_level'],
                'approved_by_user_id' => ($approval['auto_approved'] ?? false)
                    ? ($approval['approved_by_user_id'] ?? $accountOwner->id)
                    : null,
                'approved_at' => ($approval['auto_approved'] ?? false) ? now('UTC') : null,
                'approval_meta' => [
                    'approval_policy_snapshot' => $approval['approval_policy_snapshot'] ?? null,
                    'source' => self::SOURCE_RESERVATION_QUEUE,
                ],
                'source' => self::SOURCE_RESERVATION_QUEUE,
                'customer_snapshot' => $this->customerSnapshot($queueItem, $customer),
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'billing_snapshot' => $billingSnapshot,
                'total' => $invoiceTotal,
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
                'unit_price' => $subtotal,
                'currency_code' => $currencyCode,
                'total' => $subtotal,
                'meta' => $this->lineMetadata($queueItem, $billing),
            ]);

            return $invoice->load(['items', 'reservationQueueItem']);
        });
    }

    private function repairLegacyDraftInvoice(Invoice $invoice, ReservationQueueItem $queueItem): Invoice
    {
        $snapshotVersion = (int) data_get($invoice->billing_snapshot, 'version', 0);
        if ((string) $invoice->status !== 'draft'
            || $snapshotVersion >= 1
            || ($invoice->source && (string) $invoice->source !== self::SOURCE_RESERVATION_QUEUE)) {
            return $invoice;
        }

        $payment = Payment::query()
            ->where(function ($query) use ($invoice, $queueItem): void {
                $query->where('invoice_id', $invoice->id)
                    ->orWhere('reservation_queue_item_id', $queueItem->id);
            })
            ->lockForUpdate()
            ->first();
        if ($payment) {
            return $invoice;
        }

        $lineItems = InvoiceItem::query()
            ->where('invoice_id', $invoice->id)
            ->lockForUpdate()
            ->get();
        if ($lineItems->count() !== 1) {
            return $invoice;
        }

        $line = $lineItems->sole();
        $billing = $this->billingContext($queueItem);
        $previous = [
            'subtotal' => $invoice->subtotal === null ? null : (float) $invoice->subtotal,
            'tax_total' => $invoice->tax_total === null ? null : (float) $invoice->tax_total,
            'total' => (float) $invoice->total,
            'approval_status' => $invoice->approval_status,
            'billing_snapshot_version' => $snapshotVersion ?: null,
        ];
        $approval = $billing['approval'];
        $autoApproved = (bool) ($approval['auto_approved'] ?? false);
        $approvalMeta = array_merge((array) $invoice->approval_meta, [
            'approval_policy_snapshot' => $approval['approval_policy_snapshot'] ?? null,
            'source' => self::SOURCE_RESERVATION_QUEUE,
            'legacy_queue_billing_repaired_at' => now('UTC')->toIso8601String(),
        ]);

        $invoice->forceFill([
            'approval_status' => $approval['approval_status'],
            'current_approver_role_key' => $approval['current_approver_role_key'],
            'current_approval_level' => $approval['current_approval_level'],
            'approved_by_user_id' => $autoApproved
                ? ($approval['approved_by_user_id'] ?? $billing['account_owner']->id)
                : null,
            'approved_at' => $autoApproved ? ($invoice->approved_at ?: now('UTC')) : null,
            'approval_meta' => $approvalMeta,
            'source' => self::SOURCE_RESERVATION_QUEUE,
            'subtotal' => $billing['subtotal'],
            'tax_total' => $billing['tax_total'],
            'billing_snapshot' => $billing['billing_snapshot'],
            'total' => $billing['invoice_total'],
            'currency_code' => $billing['currency_code'],
        ])->save();

        $line->forceFill([
            'assigned_team_member_id' => $queueItem->team_member_id,
            'title' => Str::limit($billing['service_name'], 255, ''),
            'description' => $queueItem->service?->description,
            'assignee_name' => $billing['team_member_name'] !== ''
                ? Str::limit($billing['team_member_name'], 255, '')
                : null,
            'quantity' => 1,
            'unit_price' => $billing['subtotal'],
            'currency_code' => $billing['currency_code'],
            'total' => $billing['subtotal'],
            'meta' => array_replace(
                (array) $line->meta,
                $this->lineMetadata($queueItem, $billing)
            ),
        ])->save();

        ActivityLog::record($billing['account_owner'], $invoice, 'queue_billing_snapshot_repaired', [
            'account_id' => (int) $queueItem->account_id,
            'queue_item_id' => (int) $queueItem->id,
            'invoice_id' => (int) $invoice->id,
            'previous' => $previous,
            'current' => [
                'subtotal' => $billing['subtotal'],
                'tax_total' => $billing['tax_total'],
                'total' => $billing['invoice_total'],
                'approval_status' => $approval['approval_status'],
                'billing_snapshot_version' => 1,
            ],
        ], 'Legacy queue invoice billing snapshot repaired');

        return $invoice->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function billingContext(ReservationQueueItem $queueItem): array
    {
        $checkout = $this->queueService->checkoutSummary($queueItem);
        $currencyCode = (string) ($checkout['currency_code'] ?? 'CAD');
        $subtotal = round(max(0, (float) ($checkout['subtotal'] ?? $checkout['base_amount'] ?? 0)), 2);
        $taxRate = round(max(0, (float) ($checkout['tax_rate'] ?? 0)), 4);
        $taxBreakdown = array_values((array) ($checkout['tax_breakdown'] ?? []));
        $taxTotal = round(max(0, (float) ($checkout['tax_total'] ?? 0)), 2);
        $invoiceTotal = round(max(0, (float) ($checkout['invoice_total'] ?? ($subtotal + $taxTotal))), 2);
        $serviceName = trim((string) ($checkout['service_name'] ?? $queueItem->service?->name ?? ''));
        $serviceName = $serviceName !== '' ? $serviceName : 'Service';
        $customer = $queueItem->client ?: $queueItem->reservation?->client;
        $teamMemberName = trim((string) ($queueItem->teamMember?->user?->name ?? ''));
        $accountOwner = User::query()->findOrFail($queueItem->account_id);
        $approval = $this->financeApprovalService->resolveInvoiceCreation(
            $accountOwner,
            $accountOwner,
            $invoiceTotal
        );
        $billingSnapshot = [
            'version' => 1,
            'source' => self::SOURCE_RESERVATION_QUEUE,
            'captured_at' => data_get($queueItem->metadata, 'checkout.opened_at') ?: now('UTC')->toIso8601String(),
            'currency_code' => $currencyCode,
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_breakdown' => $taxBreakdown,
            'tax_total' => $taxTotal,
            'invoice_total' => $invoiceTotal,
            'tip_base_amount' => $subtotal,
            'service' => [
                'id' => $queueItem->service_id ? (int) $queueItem->service_id : null,
                'name' => $serviceName,
            ],
        ];

        return [
            'currency_code' => $currencyCode,
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_total' => $taxTotal,
            'invoice_total' => $invoiceTotal,
            'service_name' => $serviceName,
            'customer' => $customer,
            'team_member_name' => $teamMemberName,
            'account_owner' => $accountOwner,
            'approval' => $approval,
            'billing_snapshot' => $billingSnapshot,
        ];
    }

    /**
     * @param  array<string, mixed>  $billing
     * @return array<string, mixed>
     */
    private function lineMetadata(ReservationQueueItem $queueItem, array $billing): array
    {
        return [
            'source' => self::SOURCE_RESERVATION_QUEUE,
            'reservation_queue_item_id' => (int) $queueItem->id,
            'queue_number' => $queueItem->queue_number,
            'queue_source' => $queueItem->source,
            'reservation_id' => $queueItem->reservation_id ? (int) $queueItem->reservation_id : null,
            'service' => [
                'id' => $queueItem->service_id ? (int) $queueItem->service_id : null,
                'name' => $billing['service_name'],
                'description' => $queueItem->service?->description,
                'base_amount' => $billing['subtotal'],
                'subtotal' => $billing['subtotal'],
                'tax_rate' => $billing['tax_rate'],
                'tax_total' => $billing['tax_total'],
                'invoice_total' => $billing['invoice_total'],
                'currency_code' => $billing['currency_code'],
            ],
            'billing' => $billing['billing_snapshot'],
            'team_member' => [
                'id' => $queueItem->team_member_id ? (int) $queueItem->team_member_id : null,
                'name' => $billing['team_member_name'] !== '' ? $billing['team_member_name'] : null,
            ],
        ];
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
