<?php

namespace App\Http\Requests\Tasks;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class TaskWriteRequest extends FormRequest
{
    protected function accountId(): int
    {
        $user = $this->user();

        return (int) ($user?->accountOwnerId() ?? $user?->id ?? 0);
    }

    protected function taskStateRules(bool $statusRequired): array
    {
        $statusRule = $statusRequired ? 'required' : 'nullable';

        return [
            'status' => [$statusRule, 'string', Rule::in(Task::STATUSES)],
            'completed_at' => ['nullable', 'date', 'before_or_equal:now'],
            'completion_reason' => ['nullable', 'string', Rule::in(\App\Services\TaskTimingService::completionReasons())],
        ];
    }

    protected function managerRules(): array
    {
        $accountId = $this->accountId();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'work_id' => [
                Rule::requiredIf(fn () => ! $this->boolean('standalone')),
                'nullable',
                'integer',
                Rule::exists('works', 'id')->where('user_id', $accountId),
            ],
            'standalone' => ['nullable', 'boolean'],
            'request_id' => [
                'nullable',
                'integer',
                Rule::exists('requests', 'id')->where('user_id', $accountId),
            ],
            'due_date' => ['nullable', 'date'],
            'assigned_team_member_id' => [
                'nullable',
                'integer',
                Rule::exists('team_members', 'id')->where('account_id', $accountId),
            ],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where('user_id', $accountId),
            ],
            'product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where('user_id', $accountId),
            ],
            'materials' => ['nullable', 'array'],
            'materials.*.id' => ['nullable', 'integer'],
            'materials.*.product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where('user_id', $accountId),
            ],
            'materials.*.warehouse_id' => ['nullable', 'integer'],
            'materials.*.lot_id' => ['nullable', 'integer'],
            'materials.*.label' => ['nullable', 'string', 'max:255'],
            'materials.*.description' => ['nullable', 'string', 'max:2000'],
            'materials.*.unit' => ['nullable', 'string', 'max:50'],
            'materials.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'materials.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'materials.*.billable' => ['nullable', 'boolean'],
            'materials.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'materials.*.source_service_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where('user_id', $accountId),
            ],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
        ];
    }
}
