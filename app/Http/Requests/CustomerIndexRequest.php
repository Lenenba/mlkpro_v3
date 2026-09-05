<?php

namespace App\Http\Requests;

use App\Enums\CustomerClientType;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $boolean = ['nullable', Rule::in([true, false, 1, 0, '1', '0', 'true', 'false'])];

        return [
            'name' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'has_quotes' => $boolean,
            'has_works' => $boolean,
            'status' => ['nullable', Rule::in(['active', 'archived'])],
            'client_type' => ['nullable', Rule::in(CustomerClientType::values())],
            'is_vip' => $boolean,
            'vip_tier_id' => ['nullable', 'integer', 'min:1'],
            'acquisition_source' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:100'],
            'has_upcoming_appointment' => $boolean,
            'last_appointment_from' => ['nullable', 'date_format:Y-m-d'],
            'last_appointment_to' => ['nullable', 'date_format:Y-m-d'],
            'next_appointment_from' => ['nullable', 'date_format:Y-m-d'],
            'next_appointment_to' => ['nullable', 'date_format:Y-m-d'],
            'appointments_min' => ['nullable', 'integer', 'min:0'],
            'appointments_max' => ['nullable', 'integer', 'min:0'],
            'cancellations_min' => ['nullable', 'integer', 'min:0'],
            'no_shows_min' => ['nullable', 'integer', 'min:0'],
            'has_outstanding_balance' => $boolean,
            'outstanding_min' => ['nullable', 'numeric', 'min:0'],
            'outstanding_max' => ['nullable', 'numeric', 'min:0'],
            'total_invoiced_min' => ['nullable', 'numeric', 'min:0'],
            'total_invoiced_max' => ['nullable', 'numeric', 'min:0'],
            'last_invoice_from' => ['nullable', 'date_format:Y-m-d'],
            'last_invoice_to' => ['nullable', 'date_format:Y-m-d'],
            'payment_statuses' => ['nullable', 'array', 'max:6'],
            'payment_statuses.*' => [
                'string',
                Rule::in([
                    Payment::STATUS_PENDING,
                    Payment::STATUS_PAID,
                    Payment::STATUS_COMPLETED,
                    Payment::STATUS_FAILED,
                    Payment::STATUS_REFUNDED,
                    Payment::STATUS_REVERSED,
                ]),
            ],
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to' => ['nullable', 'date_format:Y-m-d'],
            'has_active_package' => $boolean,
            'package_status' => ['nullable', 'string', 'max:50'],
            'package_remaining_lte' => ['nullable', 'integer', 'min:0'],
            'package_expires_within_days' => ['nullable', 'integer', 'min:0'],
            'package_is_recurring' => $boolean,
            'package_recurrence_status' => ['nullable', 'string', 'max:50'],
            'quick_filters' => ['nullable', 'array', 'max:20'],
            'quick_filters.*' => ['string', 'max:80'],
            'quick_filter_mode' => ['nullable', 'string', 'max:10'],
            'operational_filter' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', Rule::in([
                'company_name',
                'first_name',
                'created_at',
                'quotes_count',
                'works_count',
            ])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $arrays = [];

        foreach (['quick_filters', 'tags', 'payment_statuses'] as $key) {
            $value = $this->input($key);
            if ($value !== null && $value !== '' && ! is_array($value)) {
                $arrays[$key] = [$value];
            }
        }

        if ($arrays !== []) {
            $this->merge($arrays);
        }
    }
}
