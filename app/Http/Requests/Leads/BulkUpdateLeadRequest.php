<?php

namespace App\Http\Requests\Leads;

use App\Models\Request as LeadRequestModel;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkUpdateLeadRequest extends LeadWriteRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $accountId = $this->accountId();

        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => [
                'integer',
                Rule::exists('requests', 'id')->where(fn ($query) => $query->where('user_id', $accountId)),
            ],
            'status' => $this->statusRule(),
            'assigned_team_member_id' => $this->assigneeRule(),
            'lost_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $status = $this->input('status');
            $hasAssignee = $this->exists('assigned_team_member_id');

            if (! $status && ! $hasAssignee) {
                $validator->errors()->add('status', 'No bulk updates specified.');
            }

            if ($status === LeadRequestModel::STATUS_LOST && blank($this->input('lost_reason'))) {
                $validator->errors()->add('lost_reason', 'Lost reason is required.');
            }
        });
    }
}
