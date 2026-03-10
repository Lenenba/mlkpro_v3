<?php

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MergeLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $accountId = (int) ($this->user()?->accountOwnerId() ?? $this->user()?->id ?? 0);

        return [
            'source_id' => [
                'required',
                'integer',
                Rule::exists('requests', 'id')->where(fn ($query) => $query->where('user_id', $accountId)),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lead = $this->route('lead');
            $sourceId = (int) ($this->input('source_id') ?? 0);

            if ($lead && $sourceId === (int) $lead->id) {
                $validator->errors()->add('source_id', 'Cannot merge the same request.');
            }
        });
    }
}
