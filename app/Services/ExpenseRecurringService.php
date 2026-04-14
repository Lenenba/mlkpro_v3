<?php

namespace App\Services;

use App\Models\Expense;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ExpenseRecurringService
{
    public function computeNextDate(
        string $expenseDate,
        string $frequency,
        int $interval = 1,
        ?string $lastGeneratedExpenseDate = null
    ): ?string {
        $anchor = $lastGeneratedExpenseDate ?: $expenseDate;

        try {
            $date = CarbonImmutable::parse($anchor);
        } catch (\Throwable) {
            return null;
        }

        return $this->addInterval($date, $frequency, $interval)?->toDateString();
    }

    /**
     * @return array{generated:int, updated_templates:int, generated_ids:array<int, int>}
     */
    public function generateDueExpenses(?int $accountId = null, ?CarbonImmutable $today = null): array
    {
        $today = $today ?: CarbonImmutable::today();
        $generated = 0;
        $updatedTemplates = 0;
        $generatedIds = [];

        $templates = Expense::query()
            ->when($accountId, fn ($query) => $query->where('user_id', $accountId))
            ->whereNull('recurrence_source_expense_id')
            ->where('is_recurring', true)
            ->whereIn('recurrence_frequency', Expense::RECURRENCE_FREQUENCIES)
            ->whereNotNull('recurrence_next_date')
            ->where(function ($query) use ($today) {
                $query
                    ->whereNull('recurrence_ends_at')
                    ->orWhereDate('recurrence_ends_at', '>=', $today->toDateString());
            })
            ->whereDate('recurrence_next_date', '<=', $today->toDateString())
            ->withCount('generatedRecurrences')
            ->get();

        foreach ($templates as $template) {
            $nextDate = $template->recurrence_next_date
                ? CarbonImmutable::parse($template->recurrence_next_date)
                : null;

            if (! $nextDate) {
                continue;
            }

            $didUpdateTemplate = false;
            $guard = 0;

            while ($nextDate->lessThanOrEqualTo($today) && $guard < 24) {
                $guard += 1;

                if ($template->recurrence_ends_at && $nextDate->greaterThan(CarbonImmutable::parse($template->recurrence_ends_at))) {
                    $template->forceFill([
                        'recurrence_next_date' => null,
                    ])->save();
                    $didUpdateTemplate = true;
                    break;
                }

                $existing = Expense::query()
                    ->where('recurrence_source_expense_id', $template->id)
                    ->whereDate('expense_date', $nextDate->toDateString())
                    ->first();

                if (! $existing) {
                    $generatedExpense = $this->createGeneratedExpense($template, $nextDate);
                    $generated += 1;
                    $generatedIds[] = (int) $generatedExpense->id;
                }

                $nextDate = $this->addInterval($nextDate, (string) $template->recurrence_frequency, (int) ($template->recurrence_interval ?: 1));

                $template->forceFill([
                    'recurrence_next_date' => $nextDate?->toDateString(),
                    'recurrence_last_generated_at' => now(),
                ])->save();

                $didUpdateTemplate = true;

                if (! $nextDate) {
                    break;
                }
            }

            if ($didUpdateTemplate) {
                $updatedTemplates += 1;
            }
        }

        return [
            'generated' => $generated,
            'updated_templates' => $updatedTemplates,
            'generated_ids' => $generatedIds,
        ];
    }

    private function createGeneratedExpense(Expense $template, CarbonImmutable $nextDate): Expense
    {
        $expenseDate = $template->expense_date
            ? CarbonImmutable::parse($template->expense_date)
            : $nextDate;
        $dueOffset = $template->due_date
            ? $expenseDate->diffInDays(CarbonImmutable::parse($template->due_date), false)
            : 0;
        $dueDate = $template->due_date ? $nextDate->addDays($dueOffset)->toDateString() : null;
        $status = filled($template->category_key)
            ? Expense::STATUS_DUE
            : Expense::STATUS_DRAFT;
        $meta = [
            'generated_from_recurrence' => [
                'source_expense_id' => (int) $template->id,
                'source_title' => $template->title,
                'generated_for_date' => $nextDate->toDateString(),
            ],
            'workflow_history' => [[
                'action' => 'generated_from_recurrence',
                'from_status' => null,
                'to_status' => $status,
                'actor_id' => (int) ($template->created_by_user_id ?: $template->user_id),
                'actor_name' => $template->creator?->name ?? $template->accountOwner?->name,
                'at' => now()->toIso8601String(),
            ]],
        ];

        return Expense::query()->create([
            'user_id' => $template->user_id,
            'created_by_user_id' => $template->created_by_user_id ?: $template->user_id,
            'approved_by_user_id' => null,
            'paid_by_user_id' => null,
            'reimbursed_by_user_id' => null,
            'team_member_id' => $template->team_member_id,
            'recurrence_source_expense_id' => $template->id,
            'title' => $template->title,
            'category_key' => $template->category_key,
            'supplier_name' => $template->supplier_name,
            'reference_number' => $template->reference_number,
            'currency_code' => $template->currency_code,
            'subtotal' => $template->subtotal,
            'tax_amount' => $template->tax_amount,
            'total' => $template->total,
            'expense_date' => $nextDate->toDateString(),
            'due_date' => $dueDate,
            'paid_date' => null,
            'approved_at' => null,
            'reimbursed_at' => null,
            'payment_method' => $template->payment_method,
            'status' => $status,
            'reimbursable' => (bool) $template->reimbursable,
            'reimbursement_status' => $template->reimbursable
                ? Expense::REIMBURSEMENT_STATUS_PENDING
                : Expense::REIMBURSEMENT_STATUS_NOT_APPLICABLE,
            'reimbursement_reference' => null,
            'is_recurring' => false,
            'recurrence_frequency' => null,
            'recurrence_interval' => 1,
            'recurrence_next_date' => null,
            'recurrence_ends_at' => null,
            'recurrence_last_generated_at' => null,
            'description' => $template->description,
            'notes' => $template->notes,
            'meta' => $meta,
        ]);
    }

    private function addInterval(CarbonImmutable $date, string $frequency, int $interval): ?CarbonImmutable
    {
        $interval = max(1, $interval);

        return match ($frequency) {
            Expense::RECURRENCE_FREQUENCY_MONTHLY => $date->addMonthsNoOverflow($interval),
            Expense::RECURRENCE_FREQUENCY_YEARLY => $date->addYearsNoOverflow($interval),
            default => null,
        };
    }
}
