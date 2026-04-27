<?php

namespace App\Http\Requests\Leads;

use App\Models\Request as LeadRequestModel;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLeadRequest extends LeadWriteRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => $this->statusRule(),
            'assigned_team_member_id' => $this->assigneeRule(),
            'next_follow_up_at' => ['nullable', 'date'],
            'lost_reason' => ['nullable', 'string', Rule::in($this->allowedLostReasonValues())],
            'lost_comment' => ['nullable', 'string', 'max:1000'],
            'close_open_tasks' => ['nullable', 'boolean'],
            'status_comment' => ['nullable', 'string', 'max:1000'],
            'channel' => ['nullable', 'string', 'max:50'],
            'urgency' => ['nullable', 'string', 'max:50'],
            'is_serviceable' => ['nullable', 'boolean'],
            'meta' => ['nullable', 'array'],
            'meta.budget' => ['nullable', 'numeric'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lead = $this->route('lead');
            $status = $this->input('status');
            $lostReason = $this->input('lost_reason');

            if ($status === LeadRequestModel::STATUS_LOST && blank($lostReason) && blank($lead?->lost_reason)) {
                $validator->errors()->add('lost_reason', 'Lost reason is required.');
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private function allowedLostReasonValues(): array
    {
        $allowed = array_keys(LeadRequestModel::LOST_REASON_OPTIONS);
        $lead = $this->route('lead');
        $existingReason = trim((string) ($lead?->lost_reason ?? ''));

        if ($existingReason !== '') {
            $allowed[] = $existingReason;
        }

        return array_values(array_unique($allowed));
    }
}
