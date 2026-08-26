<?php

namespace App\Services\Demo;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DemoScenarioFingerprint
{
    /**
     * Build a tenant-independent fingerprint of the business dataset.
     *
     * Technical identifiers, access credentials and tenant-specific email
     * suffixes are deliberately excluded so a baseline reset can be compared
     * even though the tenant owner receives new database identifiers.
     */
    public function forOwner(User $owner): string
    {
        $ownerId = (int) $owner->getKey();

        $payload = [
            'identity' => [
                'company_name' => $owner->company_name,
                'company_sector' => $owner->company_sector,
                'company_timezone' => $owner->company_timezone,
                'currency_code' => $owner->currency_code,
            ],
            'team' => DB::table('team_members')
                ->join('users', 'users.id', '=', 'team_members.user_id')
                ->where('team_members.account_id', $ownerId)
                ->orderBy('users.name')
                ->get(['users.name', 'users.profile_picture', 'team_members.role', 'team_members.title', 'team_members.planning_rules'])
                ->map(function (object $row): array {
                    $payload = (array) $row;
                    $rules = json_decode((string) ($payload['planning_rules'] ?? ''), true);
                    if (is_array($rules)) {
                        unset($rules['bookable_service_ids']);
                        $payload['planning_rules'] = $rules;
                    }

                    return $payload;
                })
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'attendance' => DB::table('team_member_attendances')
                ->join('users', 'users.id', '=', 'team_member_attendances.user_id')
                ->where('team_member_attendances.account_id', $ownerId)
                ->orderBy('users.name')
                ->orderBy('team_member_attendances.clock_in_at')
                ->get([
                    'users.name',
                    'team_member_attendances.clock_in_at',
                    'team_member_attendances.clock_out_at',
                    'team_member_attendances.method',
                    'team_member_attendances.clock_out_method',
                    'team_member_attendances.current_status',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'catalog' => DB::table('products')
                ->where('user_id', $ownerId)
                ->orderBy('item_type')
                ->orderBy('name')
                ->get(['name', 'item_type', 'price', 'cost_price', 'stock', 'minimum_stock', 'is_active', 'image', 'tags'])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'offer_packages' => DB::table('offer_packages')
                ->where('user_id', $ownerId)
                ->orderBy('slug')
                ->get([
                    'name',
                    'slug',
                    'type',
                    'status',
                    'description',
                    'image_path',
                    'price',
                    'currency_code',
                    'validity_days',
                    'included_quantity',
                    'unit_type',
                    'is_public',
                    'is_recurring',
                    'recurrence_frequency',
                    'renewal_notice_days',
                    'metadata',
                    'created_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'offer_package_items' => DB::table('offer_package_items')
                ->join('offer_packages', 'offer_packages.id', '=', 'offer_package_items.offer_package_id')
                ->join('products', 'products.id', '=', 'offer_package_items.product_id')
                ->where('offer_packages.user_id', $ownerId)
                ->orderBy('offer_packages.slug')
                ->orderBy('offer_package_items.sort_order')
                ->get([
                    'offer_packages.slug as offer_slug',
                    'products.name as product_name',
                    'offer_package_items.item_type_snapshot',
                    'offer_package_items.name_snapshot',
                    'offer_package_items.quantity',
                    'offer_package_items.unit_price',
                    'offer_package_items.included',
                    'offer_package_items.is_optional',
                    'offer_package_items.sort_order',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'customers' => DB::table('customers')
                ->where('user_id', $ownerId)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['first_name', 'last_name', 'logo', 'tags', 'is_vip', 'loyalty_points_balance', 'created_at'])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'properties' => DB::table('properties')
                ->join('customers', 'customers.id', '=', 'properties.customer_id')
                ->where('customers.user_id', $ownerId)
                ->orderBy('customers.first_name')
                ->orderBy('customers.last_name')
                ->orderBy('customers.company_name')
                ->orderBy('properties.type')
                ->orderBy('properties.is_default')
                ->orderBy('properties.country')
                ->orderBy('properties.state')
                ->orderBy('properties.city')
                ->orderBy('properties.street1')
                ->orderBy('properties.street2')
                ->orderBy('properties.zip')
                ->orderBy('properties.created_at')
                ->get([
                    'customers.first_name as customer_first_name',
                    'customers.last_name as customer_last_name',
                    'customers.company_name as customer_company_name',
                    'properties.type',
                    'properties.is_default',
                    'properties.country',
                    'properties.street1',
                    'properties.street2',
                    'properties.city',
                    'properties.state',
                    'properties.zip',
                    'properties.created_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashSortedRows($rows)),
            'customer_packages' => DB::table('customer_packages')
                ->join('customers', 'customers.id', '=', 'customer_packages.customer_id')
                ->leftJoin('offer_packages', 'offer_packages.id', '=', 'customer_packages.offer_package_id')
                ->leftJoin('invoices as package_invoices', 'package_invoices.id', '=', 'customer_packages.invoice_id')
                ->where('customer_packages.user_id', $ownerId)
                ->orderBy('customers.first_name')
                ->orderBy('customers.last_name')
                ->orderBy('customer_packages.starts_at')
                ->orderBy('offer_packages.name')
                ->get([
                    'customers.first_name',
                    'customers.last_name',
                    'offer_packages.name as offer_name',
                    'package_invoices.number as source_invoice_number',
                    'customer_packages.status',
                    'customer_packages.starts_at',
                    'customer_packages.expires_at',
                    'customer_packages.consumed_at',
                    'customer_packages.initial_quantity',
                    'customer_packages.consumed_quantity',
                    'customer_packages.remaining_quantity',
                    'customer_packages.unit_type',
                    'customer_packages.price_paid',
                    'customer_packages.is_recurring',
                    'customer_packages.recurrence_frequency',
                    'customer_packages.recurrence_status',
                    'customer_packages.current_period_starts_at',
                    'customer_packages.current_period_ends_at',
                    'customer_packages.next_renewal_at',
                    'customer_packages.renewal_count',
                    'customer_packages.metadata',
                ])
                ->map(function (object $row): array {
                    $data = (array) $row;
                    $metadata = json_decode((string) ($data['metadata'] ?? ''), true) ?: [];
                    foreach ([
                        'renewed_from_customer_package_id',
                        'provisioning.invoice_id',
                        'provisioning.invoice_item_id',
                        'recurrence.pending_invoice_id',
                        'recurrence.pending_invoice_item_id',
                        'recurrence.paid_invoice_id',
                        'recurrence.paid_invoice_item_id',
                        'recurrence.renewed_to_customer_package_id',
                        'recurrence.paid_renewed_to_customer_package_id',
                        'recurrence.renewed_by_user_id',
                    ] as $volatilePath) {
                        data_forget($metadata, $volatilePath);
                    }
                    $data['metadata'] = $metadata;

                    return $data;
                })
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'customer_package_usages' => DB::table('customer_package_usages')
                ->join('customer_packages', 'customer_packages.id', '=', 'customer_package_usages.customer_package_id')
                ->join('customers', 'customers.id', '=', 'customer_package_usages.customer_id')
                ->leftJoin('offer_packages', 'offer_packages.id', '=', 'customer_packages.offer_package_id')
                ->leftJoin('products', 'products.id', '=', 'customer_package_usages.product_id')
                ->leftJoin('reservations', 'reservations.id', '=', 'customer_package_usages.reservation_id')
                ->where('customer_package_usages.user_id', $ownerId)
                ->orderBy('customer_package_usages.used_at')
                ->orderBy('customers.first_name')
                ->orderBy('customers.last_name')
                ->get([
                    'customers.first_name',
                    'customers.last_name',
                    'offer_packages.name as offer_name',
                    'products.name as product_name',
                    'reservations.starts_at as reservation_starts_at',
                    'customer_package_usages.quantity',
                    'customer_package_usages.used_at',
                    'customer_package_usages.reversed_at',
                    'customer_package_usages.reversal_reason',
                    'customer_package_usages.note',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'reservations' => DB::table('reservations')
                ->join('customers', 'customers.id', '=', 'reservations.client_id')
                ->join('team_members', 'team_members.id', '=', 'reservations.team_member_id')
                ->join('users as staff_users', 'staff_users.id', '=', 'team_members.user_id')
                ->leftJoin('products as services', 'services.id', '=', 'reservations.service_id')
                ->where('reservations.account_id', $ownerId)
                ->orderBy('reservations.starts_at')
                ->orderBy('staff_users.name')
                ->orderBy('customers.first_name')
                ->orderBy('customers.last_name')
                ->orderBy('services.name')
                ->orderBy('reservations.status')
                ->get([
                    'customers.first_name',
                    'customers.last_name',
                    'staff_users.name as staff_name',
                    'services.name as service_name',
                    'reservations.status',
                    'reservations.source',
                    'reservations.starts_at',
                    'reservations.ends_at',
                    'reservations.duration_minutes',
                    'reservations.buffer_minutes',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'invoices' => DB::table('invoices')
                ->join('customers', 'customers.id', '=', 'invoices.customer_id')
                ->where('invoices.user_id', $ownerId)
                ->orderBy('invoices.created_at')
                ->orderBy('invoices.number')
                ->get([
                    'customers.first_name',
                    'customers.last_name',
                    'invoices.status',
                    'invoices.subtotal',
                    'invoices.tax_total',
                    'invoices.total',
                    'invoices.created_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'pack_invoice_lines' => DB::table('invoice_items')
                ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->join('customers', 'customers.id', '=', 'invoices.customer_id')
                ->where('invoices.user_id', $ownerId)
                ->orderBy('invoices.created_at')
                ->orderBy('invoices.number')
                ->get([
                    'customers.first_name',
                    'customers.last_name',
                    'invoices.status as invoice_status',
                    'invoice_items.title',
                    'invoice_items.quantity',
                    'invoice_items.unit_price',
                    'invoice_items.total',
                    'invoice_items.meta',
                    'invoice_items.created_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->filter(function (array $row): bool {
                    $meta = json_decode((string) ($row['meta'] ?? ''), true);

                    return data_get($meta, 'offer_package_type') === 'pack';
                })
                ->map(function (array $row): array {
                    $meta = json_decode((string) ($row['meta'] ?? ''), true);
                    $row['offer_name'] = data_get($meta, 'offer_package_snapshot.name');
                    unset($row['meta']);

                    return $row;
                })
                ->values()
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'payments' => DB::table('payments')
                ->where('user_id', $ownerId)
                ->orderBy('paid_at')
                ->orderBy('reference')
                ->get(['amount', 'tip_amount', 'method', 'status', 'paid_at', 'reference'])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'sales' => DB::table('sales')
                ->where('user_id', $ownerId)
                ->orderBy('created_at')
                ->orderBy('number')
                ->get([
                    'status',
                    'subtotal',
                    'tax_total',
                    'discount_total',
                    'loyalty_points_redeemed',
                    'loyalty_discount_total',
                    'total',
                    'paid_at',
                    'created_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'quotes' => DB::table('quotes')
                ->join('customers', 'customers.id', '=', 'quotes.customer_id')
                ->where('quotes.user_id', $ownerId)
                ->orderBy('quotes.created_at')
                ->orderBy('quotes.number')
                ->get([
                    'customers.first_name',
                    'customers.last_name',
                    'quotes.job_title',
                    'quotes.status',
                    'quotes.subtotal',
                    'quotes.total',
                    'quotes.initial_deposit',
                    'quotes.created_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'lead_requests' => DB::table('requests')
                ->leftJoin('customers as request_customers', 'request_customers.id', '=', 'requests.customer_id')
                ->leftJoin(
                    'customers as converted_customers',
                    'converted_customers.id',
                    '=',
                    'requests.converted_customer_id',
                )
                ->leftJoin(
                    'team_members as request_assignees',
                    'request_assignees.id',
                    '=',
                    'requests.assigned_team_member_id',
                )
                ->leftJoin('users as request_assignee_users', 'request_assignee_users.id', '=', 'request_assignees.user_id')
                ->leftJoin('public_booking_links', 'public_booking_links.id', '=', 'requests.public_booking_link_id')
                ->leftJoin('requests as duplicate_requests', 'duplicate_requests.id', '=', 'requests.duplicate_of_prospect_id')
                ->leftJoin('requests as merged_requests', 'merged_requests.id', '=', 'requests.merged_into_prospect_id')
                ->leftJoin('users as request_archivers', 'request_archivers.id', '=', 'requests.archived_by_user_id')
                ->leftJoin('users as request_deleters', 'request_deleters.id', '=', 'requests.deleted_by_user_id')
                ->where('requests.user_id', $ownerId)
                ->orderBy('requests.created_at')
                ->orderBy('requests.title')
                ->orderBy('requests.contact_name')
                ->orderBy('requests.status')
                ->orderBy('requests.channel')
                ->get([
                    'request_customers.first_name as customer_first_name',
                    'request_customers.last_name as customer_last_name',
                    'request_customers.company_name as customer_company_name',
                    'converted_customers.first_name as converted_customer_first_name',
                    'converted_customers.last_name as converted_customer_last_name',
                    'converted_customers.company_name as converted_customer_company_name',
                    'request_assignee_users.name as assignee_name',
                    'public_booking_links.name as public_booking_name',
                    'public_booking_links.slug as public_booking_slug',
                    'duplicate_requests.title as duplicate_title',
                    'duplicate_requests.contact_name as duplicate_contact_name',
                    'duplicate_requests.created_at as duplicate_created_at',
                    'merged_requests.title as merged_title',
                    'merged_requests.contact_name as merged_contact_name',
                    'merged_requests.created_at as merged_created_at',
                    'request_archivers.name as archived_by_name',
                    'request_deleters.name as deleted_by_name',
                    'requests.channel',
                    'requests.status',
                    'requests.service_type',
                    'requests.urgency',
                    'requests.title',
                    'requests.description',
                    'requests.contact_name',
                    'requests.contact_phone',
                    'requests.country',
                    'requests.state',
                    'requests.city',
                    'requests.street1',
                    'requests.street2',
                    'requests.postal_code',
                    'requests.lat',
                    'requests.lng',
                    'requests.is_serviceable',
                    'requests.converted_at',
                    'requests.first_response_at',
                    'requests.last_activity_at',
                    'requests.sla_due_at',
                    'requests.triage_priority',
                    'requests.risk_level',
                    'requests.stale_since_at',
                    'requests.archived_at',
                    'requests.archive_reason',
                    'requests.deleted_at',
                    'requests.status_updated_at',
                    'requests.next_follow_up_at',
                    'requests.lost_reason',
                    'requests.meta',
                    'requests.created_at',
                ])
                ->map(function (object $row): array {
                    $data = (array) $row;
                    $data['meta'] = $this->normalizeMetadata($data['meta'] ?? null);

                    return $data;
                })
                ->pipe(fn (Collection $rows): string => $this->hashSortedRows($rows)),
            'service_requests' => DB::table('service_requests')
                ->leftJoin(
                    'customers as service_request_customers',
                    'service_request_customers.id',
                    '=',
                    'service_requests.customer_id',
                )
                ->leftJoin(
                    'requests as service_request_prospects',
                    'service_request_prospects.id',
                    '=',
                    'service_requests.prospect_id',
                )
                ->where('service_requests.user_id', $ownerId)
                ->orderBy('service_requests.submitted_at')
                ->orderBy('service_requests.created_at')
                ->orderBy('service_requests.title')
                ->orderBy('service_requests.requester_name')
                ->orderBy('service_requests.status')
                ->get([
                    'service_request_customers.first_name as customer_first_name',
                    'service_request_customers.last_name as customer_last_name',
                    'service_request_customers.company_name as customer_company_name',
                    'service_request_prospects.title as prospect_title',
                    'service_request_prospects.contact_name as prospect_contact_name',
                    'service_request_prospects.created_at as prospect_created_at',
                    'service_requests.source',
                    'service_requests.channel',
                    'service_requests.status',
                    'service_requests.request_type',
                    'service_requests.service_type',
                    'service_requests.title',
                    'service_requests.description',
                    'service_requests.requester_name',
                    'service_requests.requester_phone',
                    'service_requests.street1',
                    'service_requests.street2',
                    'service_requests.city',
                    'service_requests.state',
                    'service_requests.postal_code',
                    'service_requests.country',
                    'service_requests.source_ref',
                    'service_requests.source_meta',
                    'service_requests.submitted_at',
                    'service_requests.accepted_at',
                    'service_requests.completed_at',
                    'service_requests.cancelled_at',
                    'service_requests.meta',
                    'service_requests.created_at',
                ])
                ->map(function (object $row): array {
                    $data = (array) $row;
                    $data['source_ref'] = $this->normalizeInternalReference($data['source_ref'] ?? null);
                    $data['source_meta'] = $this->normalizeMetadata($data['source_meta'] ?? null);
                    $data['meta'] = $this->normalizeMetadata($data['meta'] ?? null);

                    return $data;
                })
                ->pipe(fn (Collection $rows): string => $this->hashSortedRows($rows)),
            'works' => DB::table('works')
                ->join('customers as work_customers', 'work_customers.id', '=', 'works.customer_id')
                ->leftJoin('quotes as work_quotes', 'work_quotes.id', '=', 'works.quote_id')
                ->where('works.user_id', $ownerId)
                ->orderBy('works.start_date')
                ->orderBy('works.start_time')
                ->orderBy('works.job_title')
                ->orderBy('work_customers.first_name')
                ->orderBy('work_customers.last_name')
                ->get([
                    'work_customers.first_name as customer_first_name',
                    'work_customers.last_name as customer_last_name',
                    'work_customers.company_name as customer_company_name',
                    'work_quotes.number as quote_number',
                    'work_quotes.job_title as quote_title',
                    'work_quotes.status as quote_status',
                    'works.number',
                    'works.job_title',
                    'works.instructions',
                    'works.start_date',
                    'works.end_date',
                    'works.start_time',
                    'works.end_time',
                    'works.is_all_day',
                    'works.later',
                    'works.ends',
                    'works.frequencyNumber',
                    'works.frequency',
                    'works.totalVisits',
                    'works.repeatsOn',
                    'works.type',
                    'works.category',
                    'works.status',
                    'works.is_completed',
                    'works.completed_at',
                    'works.subtotal',
                    'works.total',
                    'works.billing_mode',
                    'works.billing_cycle',
                    'works.billing_grouping',
                    'works.billing_delay_days',
                    'works.billing_date_rule',
                    'works.auto_started_at',
                    'works.auto_completed_at',
                    'works.start_alerted_at',
                    'works.end_alerted_at',
                    'works.deleted_at',
                    'works.created_at',
                ])
                ->map(function (object $row): array {
                    $data = (array) $row;
                    $data['repeatsOn'] = $this->normalizeMetadata($data['repeatsOn'] ?? null);

                    return $data;
                })
                ->pipe(fn (Collection $rows): string => $this->hashSortedRows($rows)),
            'work_assignments' => DB::table('work_team_members')
                ->join('works', 'works.id', '=', 'work_team_members.work_id')
                ->join('customers as assigned_work_customers', 'assigned_work_customers.id', '=', 'works.customer_id')
                ->join('team_members', 'team_members.id', '=', 'work_team_members.team_member_id')
                ->join('users as assigned_staff_users', 'assigned_staff_users.id', '=', 'team_members.user_id')
                ->where('works.user_id', $ownerId)
                ->orderBy('works.start_date')
                ->orderBy('works.start_time')
                ->orderBy('works.job_title')
                ->orderBy('assigned_staff_users.name')
                ->orderBy('work_team_members.role')
                ->get([
                    'assigned_work_customers.first_name as customer_first_name',
                    'assigned_work_customers.last_name as customer_last_name',
                    'assigned_work_customers.company_name as customer_company_name',
                    'works.number as work_number',
                    'works.job_title as work_title',
                    'works.start_date as work_start_date',
                    'works.start_time as work_start_time',
                    'assigned_staff_users.name as staff_name',
                    'team_members.role as team_role',
                    'team_members.title as team_title',
                    'work_team_members.role as assignment_role',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashSortedRows($rows)),
            'work_checklists' => DB::table('work_checklist_items')
                ->join('works', 'works.id', '=', 'work_checklist_items.work_id')
                ->join('customers as checklist_customers', 'checklist_customers.id', '=', 'works.customer_id')
                ->leftJoin('quotes as checklist_quotes', 'checklist_quotes.id', '=', 'work_checklist_items.quote_id')
                ->leftJoin('quote_products', 'quote_products.id', '=', 'work_checklist_items.quote_product_id')
                ->leftJoin('products as checklist_products', 'checklist_products.id', '=', 'quote_products.product_id')
                ->where('works.user_id', $ownerId)
                ->orderBy('works.start_date')
                ->orderBy('works.start_time')
                ->orderBy('works.job_title')
                ->orderBy('work_checklist_items.sort_order')
                ->orderBy('work_checklist_items.title')
                ->get([
                    'checklist_customers.first_name as customer_first_name',
                    'checklist_customers.last_name as customer_last_name',
                    'checklist_customers.company_name as customer_company_name',
                    'works.number as work_number',
                    'works.job_title as work_title',
                    'works.start_date as work_start_date',
                    'works.start_time as work_start_time',
                    'checklist_quotes.number as quote_number',
                    'checklist_quotes.job_title as quote_title',
                    'checklist_products.name as source_product_name',
                    'quote_products.description as source_line_description',
                    'work_checklist_items.title',
                    'work_checklist_items.description',
                    'work_checklist_items.status',
                    'work_checklist_items.sort_order',
                    'work_checklist_items.completed_at',
                    'work_checklist_items.created_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashSortedRows($rows)),
            'expenses' => DB::table('expenses')
                ->where('user_id', $ownerId)
                ->orderBy('expense_date')
                ->orderBy('reference_number')
                ->get(['title', 'category_key', 'supplier_name', 'subtotal', 'tax_amount', 'total', 'status', 'expense_date'])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'mailing_lists' => DB::table('mailing_lists')
                ->where('user_id', $ownerId)
                ->orderBy('name')
                ->get(['name', 'description', 'tags', 'created_at'])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'mailing_list_memberships' => DB::table('mailing_list_customers')
                ->join('mailing_lists', 'mailing_lists.id', '=', 'mailing_list_customers.mailing_list_id')
                ->join('customers', 'customers.id', '=', 'mailing_list_customers.customer_id')
                ->where('mailing_lists.user_id', $ownerId)
                ->orderBy('mailing_lists.name')
                ->orderBy('customers.first_name')
                ->orderBy('customers.last_name')
                ->get([
                    'mailing_lists.name as list_name',
                    'customers.first_name',
                    'customers.last_name',
                    'mailing_list_customers.added_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'campaigns' => DB::table('campaigns')
                ->where('user_id', $ownerId)
                ->whereNull('deleted_at')
                ->orderBy('created_at')
                ->orderBy('name')
                ->get([
                    'name',
                    'campaign_type',
                    'campaign_direction',
                    'offer_mode',
                    'language_mode',
                    'status',
                    'schedule_type',
                    'scheduled_at',
                    'started_at',
                    'completed_at',
                    'locale',
                    'created_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'campaign_channels' => DB::table('campaign_channels')
                ->join('campaigns', 'campaigns.id', '=', 'campaign_channels.campaign_id')
                ->where('campaigns.user_id', $ownerId)
                ->whereNull('campaigns.deleted_at')
                ->orderBy('campaigns.name')
                ->orderBy('campaign_channels.channel')
                ->get([
                    'campaigns.name as campaign_name',
                    'campaign_channels.channel',
                    'campaign_channels.is_enabled',
                    'campaign_channels.subject_template',
                    'campaign_channels.body_template',
                    'campaign_channels.content_override',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'campaign_recipients' => DB::table('campaign_recipients')
                ->join('campaigns', 'campaigns.id', '=', 'campaign_recipients.campaign_id')
                ->join('customers', 'customers.id', '=', 'campaign_recipients.customer_id')
                ->where('campaign_recipients.user_id', $ownerId)
                ->orderBy('campaigns.name')
                ->orderBy('campaign_recipients.queued_at')
                ->orderBy('customers.first_name')
                ->get([
                    'campaigns.name as campaign_name',
                    'customers.first_name',
                    'customers.last_name',
                    'campaign_recipients.channel',
                    'campaign_recipients.status',
                    'campaign_recipients.queued_at',
                    'campaign_recipients.sent_at',
                    'campaign_recipients.delivered_at',
                    'campaign_recipients.opened_at',
                    'campaign_recipients.clicked_at',
                    'campaign_recipients.converted_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'campaign_messages' => DB::table('campaign_messages')
                ->join('campaign_recipients', 'campaign_recipients.id', '=', 'campaign_messages.campaign_recipient_id')
                ->join('campaigns', 'campaigns.id', '=', 'campaign_recipients.campaign_id')
                ->join('customers', 'customers.id', '=', 'campaign_recipients.customer_id')
                ->where('campaign_recipients.user_id', $ownerId)
                ->orderBy('campaigns.name')
                ->orderBy('campaign_messages.created_at')
                ->orderBy('customers.first_name')
                ->get([
                    'campaigns.name as campaign_name',
                    'customers.first_name',
                    'customers.last_name',
                    'campaign_messages.channel',
                    'campaign_messages.subject_rendered',
                    'campaign_messages.body_rendered',
                    'campaign_messages.created_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'campaign_events' => DB::table('campaign_events')
                ->join('campaigns', 'campaigns.id', '=', 'campaign_events.campaign_id')
                ->leftJoin('customers', 'customers.id', '=', 'campaign_events.customer_id')
                ->where('campaign_events.user_id', $ownerId)
                ->orderBy('campaign_events.occurred_at')
                ->orderBy('campaigns.name')
                ->orderBy('campaign_events.event_type')
                ->get([
                    'campaigns.name as campaign_name',
                    'customers.first_name',
                    'customers.last_name',
                    'campaign_events.channel',
                    'campaign_events.event_type',
                    'campaign_events.conversion_type',
                    'campaign_events.occurred_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'promotions' => DB::table('promotions')
                ->where('user_id', $ownerId)
                ->orderBy('start_date')
                ->orderBy('code')
                ->get([
                    'name',
                    'code',
                    'target_type',
                    'discount_type',
                    'discount_value',
                    'start_date',
                    'end_date',
                    'status',
                    'usage_limit',
                    'minimum_order_amount',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'promotion_usages' => DB::table('promotion_usages')
                ->join('promotions', 'promotions.id', '=', 'promotion_usages.promotion_id')
                ->join('sales', 'sales.id', '=', 'promotion_usages.sale_id')
                ->leftJoin('customers', 'customers.id', '=', 'promotion_usages.customer_id')
                ->where('promotion_usages.user_id', $ownerId)
                ->orderBy('promotion_usages.used_at')
                ->orderBy('promotions.code')
                ->orderBy('sales.number')
                ->get([
                    'promotions.name as promotion_name',
                    'promotions.code',
                    'sales.number as sale_number',
                    'customers.first_name',
                    'customers.last_name',
                    'promotion_usages.discount_total',
                    'promotion_usages.used_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'package_behavior_events' => DB::table('customer_behavior_events')
                ->join('customers', 'customers.id', '=', 'customer_behavior_events.customer_id')
                ->leftJoin('products', 'products.id', '=', 'customer_behavior_events.product_id')
                ->where('customer_behavior_events.user_id', $ownerId)
                ->whereIn('customer_behavior_events.event_type', [
                    'customer_package_purchased',
                    'customer_package_low_balance',
                    'customer_package_expired',
                ])
                ->orderBy('customer_behavior_events.occurred_at')
                ->orderBy('customers.first_name')
                ->orderBy('customers.last_name')
                ->get([
                    'customers.first_name',
                    'customers.last_name',
                    'products.name as product_name',
                    'customer_behavior_events.event_type',
                    'customer_behavior_events.occurred_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'loyalty_program' => DB::table('loyalty_programs')
                ->where('user_id', $ownerId)
                ->get([
                    'is_enabled',
                    'points_per_currency_unit',
                    'minimum_spend',
                    'rounding_mode',
                    'points_label',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'loyalty_ledgers' => DB::table('loyalty_point_ledgers')
                ->join('customers', 'customers.id', '=', 'loyalty_point_ledgers.customer_id')
                ->leftJoin('payments', 'payments.id', '=', 'loyalty_point_ledgers.payment_id')
                ->where('loyalty_point_ledgers.user_id', $ownerId)
                ->orderBy('loyalty_point_ledgers.processed_at')
                ->orderBy('customers.first_name')
                ->orderBy('customers.last_name')
                ->orderBy('loyalty_point_ledgers.event')
                ->orderBy('payments.reference')
                ->orderBy('loyalty_point_ledgers.points')
                ->orderBy('loyalty_point_ledgers.amount')
                ->get([
                    'customers.first_name',
                    'customers.last_name',
                    'payments.paid_at as payment_paid_at',
                    'payments.reference as payment_reference',
                    'loyalty_point_ledgers.event',
                    'loyalty_point_ledgers.points',
                    'loyalty_point_ledgers.amount',
                    'loyalty_point_ledgers.processed_at',
                    'loyalty_point_ledgers.meta',
                ])
                ->map(function (object $row): array {
                    $data = (array) $row;
                    $meta = json_decode((string) ($data['meta'] ?? ''), true) ?: [];
                    $data['sale_number'] = $meta['sale_number'] ?? null;
                    unset($data['meta']);

                    return $data;
                })
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'assistant_settings' => DB::table('ai_assistant_settings')
                ->where('tenant_id', $ownerId)
                ->get([
                    'assistant_name',
                    'enabled',
                    'default_language',
                    'supported_languages',
                    'tone',
                    'greeting_message',
                    'fallback_message',
                    'require_human_validation',
                    'business_context',
                    'working_hours_rules',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'assistant_knowledge' => DB::table('ai_knowledge_items')
                ->where('tenant_id', $ownerId)
                ->orderBy('category')
                ->orderBy('title')
                ->get(['title', 'content', 'category', 'is_active', 'created_at'])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'assistant_conversations' => DB::table('ai_conversations')
                ->join('customers', 'customers.id', '=', 'ai_conversations.client_id')
                ->leftJoin('reservations', 'reservations.id', '=', 'ai_conversations.reservation_id')
                ->where('ai_conversations.tenant_id', $ownerId)
                ->orderBy('ai_conversations.created_at')
                ->orderBy('customers.first_name')
                ->get([
                    'customers.first_name',
                    'customers.last_name',
                    'reservations.starts_at as reservation_starts_at',
                    'ai_conversations.channel',
                    'ai_conversations.status',
                    'ai_conversations.detected_language',
                    'ai_conversations.intent',
                    'ai_conversations.confidence_score',
                    'ai_conversations.summary',
                    'ai_conversations.created_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'assistant_messages' => DB::table('ai_messages')
                ->join('ai_conversations', 'ai_conversations.id', '=', 'ai_messages.conversation_id')
                ->join('customers', 'customers.id', '=', 'ai_conversations.client_id')
                ->where('ai_conversations.tenant_id', $ownerId)
                ->orderBy('ai_conversations.created_at')
                ->orderBy('ai_messages.created_at')
                ->orderBy('ai_messages.id')
                ->get([
                    'customers.first_name',
                    'customers.last_name',
                    'ai_messages.sender_type',
                    'ai_messages.content',
                    'ai_messages.created_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'assistant_actions' => DB::table('ai_actions')
                ->join('ai_conversations', 'ai_conversations.id', '=', 'ai_actions.conversation_id')
                ->join('customers', 'customers.id', '=', 'ai_conversations.client_id')
                ->where('ai_actions.tenant_id', $ownerId)
                ->orderBy('ai_actions.created_at')
                ->orderBy('customers.first_name')
                ->get([
                    'customers.first_name',
                    'customers.last_name',
                    'ai_actions.action_type',
                    'ai_actions.status',
                    'ai_actions.executed_at',
                    'ai_actions.created_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'social_accounts' => DB::table('social_account_connections')
                ->where('user_id', $ownerId)
                ->orderBy('platform')
                ->orderBy('label')
                ->get([
                    'platform',
                    'label',
                    'display_name',
                    'account_handle',
                    'auth_method',
                    'status',
                    'is_active',
                    'connected_at',
                    'metadata',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'social_templates' => DB::table('social_post_templates')
                ->where('user_id', $ownerId)
                ->orderBy('name')
                ->get(['name', 'content_payload', 'media_payload', 'link_url', 'created_at'])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'social_posts' => DB::table('social_posts')
                ->where('user_id', $ownerId)
                ->orderBy('created_at')
                ->orderBy('status')
                ->get([
                    'source_type',
                    'content_payload',
                    'media_payload',
                    'link_url',
                    'status',
                    'scheduled_for',
                    'published_at',
                    'metadata',
                    'created_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'social_targets' => DB::table('social_post_targets')
                ->join('social_posts', 'social_posts.id', '=', 'social_post_targets.social_post_id')
                ->join(
                    'social_account_connections',
                    'social_account_connections.id',
                    '=',
                    'social_post_targets.social_account_connection_id',
                )
                ->where('social_posts.user_id', $ownerId)
                ->orderBy('social_posts.created_at')
                ->orderBy('social_account_connections.platform')
                ->get([
                    'social_posts.status as post_status',
                    'social_account_connections.platform',
                    'social_account_connections.label',
                    'social_post_targets.status as target_status',
                    'social_post_targets.published_at',
                    'social_post_targets.metadata',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'tasks' => DB::table('tasks')
                ->leftJoin('customers', 'customers.id', '=', 'tasks.customer_id')
                ->leftJoin('team_members', 'team_members.id', '=', 'tasks.assigned_team_member_id')
                ->leftJoin('users as assignees', 'assignees.id', '=', 'team_members.user_id')
                ->where('tasks.account_id', $ownerId)
                ->orderBy('tasks.due_date')
                ->orderBy('tasks.title')
                ->get([
                    'customers.first_name',
                    'customers.last_name',
                    'assignees.name as assignee_name',
                    'tasks.title',
                    'tasks.status',
                    'tasks.priority',
                    'tasks.due_date',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'transactions' => DB::table('transactions')
                ->join('customers', 'customers.id', '=', 'transactions.customer_id')
                ->leftJoin('quotes', 'quotes.id', '=', 'transactions.quote_id')
                ->where('transactions.user_id', $ownerId)
                ->orderBy('transactions.paid_at')
                ->orderBy('transactions.reference')
                ->get([
                    'customers.first_name',
                    'customers.last_name',
                    'quotes.job_title as quote_title',
                    'transactions.amount',
                    'transactions.type',
                    'transactions.method',
                    'transactions.status',
                    'transactions.reference',
                    'transactions.paid_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
            'stock_movements' => DB::table('product_stock_movements')
                ->join('products', 'products.id', '=', 'product_stock_movements.product_id')
                ->where('products.user_id', $ownerId)
                ->orderBy('product_stock_movements.created_at')
                ->orderBy('products.name')
                ->get([
                    'products.name as product_name',
                    'product_stock_movements.type',
                    'product_stock_movements.quantity',
                    'product_stock_movements.before_quantity',
                    'product_stock_movements.after_quantity',
                    'product_stock_movements.reason',
                    'product_stock_movements.created_at',
                ])
                ->map(fn (object $row): array => (array) $row)
                ->pipe(fn (Collection $rows): string => $this->hashRows($rows)),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Collapse each ordered projection immediately so large scenario sections
     * never remain resident together while the final fingerprint is built.
     */
    private function hashRows(Collection $rows): string
    {
        return hash(
            'sha256',
            json_encode($rows->values()->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Hash a projection as a multiset so equivalent records remain stable even
     * when regenerated with different technical identifiers or insert order.
     */
    private function hashSortedRows(Collection $rows): string
    {
        return $this->hashRows(
            $rows
                ->sortBy(fn (mixed $row): string => json_encode(
                    $row,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ))
                ->values(),
        );
    }

    /**
     * Canonicalize JSON while removing tenant-local identifiers. Business
     * metadata stays fingerprinted, whereas regenerated foreign keys do not.
     */
    private function normalizeMetadata(mixed $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $value = is_string($raw)
            ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR)
            : $raw;

        return $this->normalizeMetadataValue($value);
    }

    private function normalizeMetadataValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->normalizeInternalReference($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->normalizeMetadataValue($item),
                $value,
            );
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if ($this->isTechnicalMetadataKey((string) $key)) {
                continue;
            }

            $normalized[(string) $key] = $this->normalizeMetadataValue($item);
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function isTechnicalMetadataKey(string $key): bool
    {
        return preg_match('/(?:^id$|_id$|_ids$)/i', $key) === 1
            || in_array(strtolower($key), ['services_sur_devis'], true);
    }

    private function normalizeInternalReference(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_replace(
            '/^(lead|request|prospect|customer|service|product|reservation|quote|work):\d+$/i',
            '$1:{record}',
            $value,
        );
    }
}
