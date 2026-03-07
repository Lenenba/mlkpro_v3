<?php

namespace App\Http\Requests\Leads;

use App\Models\Request as LeadRequestModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class LeadWriteRequest extends FormRequest
{
    protected function accountId(): int
    {
        $user = $this->user();

        return (int) ($user?->accountOwnerId() ?? $user?->id ?? 0);
    }

    protected function customerRule(): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists('customers', 'id')->where(fn ($query) => $query->where('user_id', $this->accountId())),
        ];
    }

    protected function assigneeRule(): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists('team_members', 'id')->where(fn ($query) => $query->where('account_id', $this->accountId())),
        ];
    }

    protected function statusRule(): array
    {
        return ['nullable', Rule::in(LeadRequestModel::STATUSES)];
    }
}
