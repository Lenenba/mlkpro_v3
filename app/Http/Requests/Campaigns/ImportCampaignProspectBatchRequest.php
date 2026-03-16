<?php

namespace App\Http\Requests\Campaigns;

use App\Models\CampaignProspect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ImportCampaignProspectBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_type' => ['required', 'string', 'max:40', 'in:'.implode(',', CampaignProspect::allowedSourceTypes())],
            'source_reference' => ['nullable', 'string', 'max:191'],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'mapping' => ['nullable', 'array'],
            'file' => ['nullable', 'file', 'mimes:csv,txt', 'max:10000'],
            'prospects' => ['nullable', 'array', 'min:1'],
            'prospects.*' => ['array'],
            'prospects.*.company_name' => ['nullable', 'string', 'max:255'],
            'prospects.*.contact_name' => ['nullable', 'string', 'max:255'],
            'prospects.*.first_name' => ['nullable', 'string', 'max:120'],
            'prospects.*.last_name' => ['nullable', 'string', 'max:120'],
            'prospects.*.email' => ['nullable', 'string', 'max:255'],
            'prospects.*.phone' => ['nullable', 'string', 'max:80'],
            'prospects.*.website' => ['nullable', 'string', 'max:255'],
            'prospects.*.city' => ['nullable', 'string', 'max:120'],
            'prospects.*.state' => ['nullable', 'string', 'max:120'],
            'prospects.*.country' => ['nullable', 'string', 'max:120'],
            'prospects.*.industry' => ['nullable', 'string', 'max:120'],
            'prospects.*.company_size' => ['nullable', 'string', 'max:60'],
            'prospects.*.tags' => ['nullable'],
            'prospects.*.owner_notes' => ['nullable', 'string'],
            'prospects.*.metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasFile = $this->file('file') !== null;
            $hasProspects = is_array($this->input('prospects')) && $this->input('prospects') !== [];

            if (! $hasFile && ! $hasProspects) {
                $validator->errors()->add('file', 'Provide a CSV file or a prospects payload.');
            }

            if ($hasFile && $hasProspects) {
                $validator->errors()->add('prospects', 'Choose either CSV import or manual prospects payload.');
            }
        });
    }
}
