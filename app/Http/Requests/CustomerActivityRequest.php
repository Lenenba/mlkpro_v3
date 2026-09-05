<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerActivityRequest extends FormRequest
{
    public const PERIODS = [
        'last_7_days',
        'last_30_days',
        'last_90_days',
        'last_6_months',
        'current_year',
        'previous_year',
        'all',
        'custom',
    ];

    public const TYPES = [
        'appointments',
        'invoices',
        'payments',
        'notes',
        'communications',
        'profile_changes',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period' => ['nullable', 'string', Rule::in(self::PERIODS)],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_if:period,custom'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_if:period,custom', 'after_or_equal:from'],
            'types' => ['nullable', 'array', 'max:'.count(self::TYPES)],
            'types.*' => ['string', Rule::in(self::TYPES)],
            'cursor' => ['nullable', 'string', 'max:500'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $types = $this->input('types');
        if (is_string($types)) {
            $types = array_filter(array_map('trim', explode(',', $types)));
        }

        if (is_array($types)) {
            $types = array_values(array_unique($types));
        }

        $this->merge([
            'period' => $this->input('period') ?: 'last_90_days',
            'types' => $types,
        ]);
    }
}
