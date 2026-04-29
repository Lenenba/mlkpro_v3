<?php

namespace App\Http\Requests\Expenses;

use App\Enums\CurrencyCode;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PettyCashMovement;
use App\Models\Sale;
use App\Models\TeamMember;
use App\Models\Work;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $categoryKeys = collect(config('expenses.categories', []))
            ->pluck('key')
            ->filter()
            ->values()
            ->all();

        $paymentMethodKeys = collect(config('expenses.payment_methods', []))
            ->pluck('key')
            ->filter()
            ->values()
            ->all();

        return [
            'title' => 'required|string|max:255',
            'category_key' => ['nullable', 'string', Rule::in($categoryKeys)],
            'supplier_name' => 'nullable|string|max:255',
            'reference_number' => 'nullable|string|max:255',
            'currency_code' => ['nullable', 'string', Rule::in(CurrencyCode::values())],
            'subtotal' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'expense_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:expense_date',
            'paid_date' => 'nullable|date',
            'payment_method' => ['nullable', 'string', Rule::in($paymentMethodKeys)],
            'status' => ['nullable', 'string', Rule::in(Expense::STATUSES)],
            'reimbursable' => 'nullable|boolean',
            'team_member_id' => ['nullable', 'integer', Rule::exists('team_members', 'id')],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')],
            'work_id' => ['nullable', 'integer', Rule::exists('works', 'id')],
            'sale_id' => ['nullable', 'integer', Rule::exists('sales', 'id')],
            'invoice_id' => ['nullable', 'integer', Rule::exists('invoices', 'id')],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')],
            'is_recurring' => 'nullable|boolean',
            'recurrence_frequency' => ['nullable', 'string', Rule::in(Expense::RECURRENCE_FREQUENCIES)],
            'recurrence_interval' => 'nullable|integer|min:1|max:24',
            'recurrence_ends_at' => 'nullable|date|after_or_equal:expense_date',
            'description' => 'nullable|string|max:5000',
            'notes' => 'nullable|string|max:5000',
            'attachments' => 'nullable|array|max:6',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10000',
            'petty_cash_create' => ['nullable', 'boolean'],
            'petty_cash_status' => ['nullable', 'string', Rule::in([PettyCashMovement::STATUS_DRAFT, PettyCashMovement::STATUS_POSTED])],
            'petty_cash_responsible_user_id' => ['nullable', 'integer'],
            'petty_cash_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $status = (string) $this->input('status', Expense::STATUS_DRAFT);
            $categoryKey = trim((string) $this->input('category_key', ''));
            $reimbursable = filter_var($this->input('reimbursable', false), FILTER_VALIDATE_BOOLEAN);
            $teamMemberId = $this->input('team_member_id');
            $isRecurring = filter_var($this->input('is_recurring', false), FILTER_VALIDATE_BOOLEAN);
            $recurrenceFrequency = trim((string) $this->input('recurrence_frequency', ''));
            $accountId = (int) ($this->user()?->accountOwnerId() ?? 0);

            if (in_array($status, [Expense::STATUS_APPROVED, Expense::STATUS_DUE, Expense::STATUS_PAID, Expense::STATUS_REIMBURSED], true)
                && $categoryKey === '') {
                $validator->errors()->add('category_key', 'A category is required before approval or payment.');
            }

            if ($teamMemberId && ! $reimbursable) {
                $validator->errors()->add('team_member_id', 'A team member can only be linked to a reimbursable expense.');
            }

            if ($reimbursable && $teamMemberId) {
                $belongsToAccount = TeamMember::query()
                    ->whereKey($teamMemberId)
                    ->where('account_id', $accountId)
                    ->exists();

                if (! $belongsToAccount) {
                    $validator->errors()->add('team_member_id', 'The selected team member is not available in this workspace.');
                }
            }

            if ($isRecurring && $recurrenceFrequency === '') {
                $validator->errors()->add('recurrence_frequency', 'A recurrence frequency is required when the expense is recurring.');
            }

            if ($isRecurring && $categoryKey === '') {
                $validator->errors()->add('category_key', 'A category is required before enabling recurrence.');
            }

            $scopedLinks = [
                'customer_id' => [Customer::class, 'user_id'],
                'work_id' => [Work::class, 'user_id'],
                'sale_id' => [Sale::class, 'user_id'],
                'invoice_id' => [Invoice::class, 'user_id'],
                'campaign_id' => [Campaign::class, 'user_id'],
            ];

            foreach ($scopedLinks as $field => [$modelClass, $ownerColumn]) {
                $value = $this->input($field);
                if (! $value) {
                    continue;
                }

                $existsForAccount = $modelClass::query()
                    ->whereKey($value)
                    ->where($ownerColumn, $accountId)
                    ->exists();

                if (! $existsForAccount) {
                    $validator->errors()->add($field, 'The selected linked record is not available in this workspace.');
                }
            }

            $subtotal = $this->input('subtotal');
            $taxAmount = $this->input('tax_amount');
            $total = $this->input('total');

            if ($subtotal !== null && $subtotal !== '' && $taxAmount !== null && $taxAmount !== '' && $total !== null && $total !== '') {
                $expectedTotal = round((float) $subtotal + (float) $taxAmount, 2);
                $providedTotal = round((float) $total, 2);

                if (abs($expectedTotal - $providedTotal) > 0.01) {
                    $validator->errors()->add('total', 'Subtotal and tax amount must match the total.');
                }
            }
        });
    }
}
