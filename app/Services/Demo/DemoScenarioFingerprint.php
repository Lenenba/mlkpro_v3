<?php

namespace App\Services\Demo;

use App\Models\User;
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
                ->get(['users.name', 'team_members.role', 'team_members.title', 'team_members.planning_rules'])
                ->map(function (object $row): array {
                    $payload = (array) $row;
                    $rules = json_decode((string) ($payload['planning_rules'] ?? ''), true);
                    if (is_array($rules)) {
                        unset($rules['bookable_service_ids']);
                        $payload['planning_rules'] = $rules;
                    }

                    return $payload;
                })
                ->all(),
            'catalog' => DB::table('products')
                ->where('user_id', $ownerId)
                ->orderBy('item_type')
                ->orderBy('name')
                ->get(['name', 'item_type', 'price', 'cost_price', 'stock', 'minimum_stock', 'is_active', 'tags'])
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'customers' => DB::table('customers')
                ->where('user_id', $ownerId)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['first_name', 'last_name', 'tags', 'is_vip', 'created_at'])
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'reservations' => DB::table('reservations')
                ->join('customers', 'customers.id', '=', 'reservations.client_id')
                ->join('team_members', 'team_members.id', '=', 'reservations.team_member_id')
                ->join('users as staff_users', 'staff_users.id', '=', 'team_members.user_id')
                ->leftJoin('products as services', 'services.id', '=', 'reservations.service_id')
                ->where('reservations.account_id', $ownerId)
                ->orderBy('reservations.starts_at')
                ->orderBy('staff_users.name')
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
                ->all(),
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
                ->all(),
            'payments' => DB::table('payments')
                ->where('user_id', $ownerId)
                ->orderBy('paid_at')
                ->orderBy('reference')
                ->get(['amount', 'tip_amount', 'method', 'status', 'paid_at', 'reference'])
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'sales' => DB::table('sales')
                ->where('user_id', $ownerId)
                ->orderBy('created_at')
                ->orderBy('number')
                ->get(['status', 'subtotal', 'tax_total', 'total', 'paid_at', 'created_at'])
                ->map(fn (object $row): array => (array) $row)
                ->all(),
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
                ->all(),
            'expenses' => DB::table('expenses')
                ->where('user_id', $ownerId)
                ->orderBy('expense_date')
                ->orderBy('reference_number')
                ->get(['title', 'category_key', 'supplier_name', 'subtotal', 'tax_amount', 'total', 'status', 'expense_date'])
                ->map(fn (object $row): array => (array) $row)
                ->all(),
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
                ->all(),
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
                ->all(),
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
                ->all(),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
