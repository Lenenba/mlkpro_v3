<?php

namespace App\Http\Requests\Payments;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'tip_enabled' => ['nullable', 'boolean'],
            'tip_mode' => ['nullable', Rule::in(['none', 'percent', 'fixed'])],
            'tip_percent' => ['nullable', 'numeric', 'min:0'],
            'tip_amount' => ['nullable', 'numeric', 'min:0'],
            'method' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', Rule::in([
                Payment::STATUS_PENDING,
                Payment::STATUS_PAID,
                Payment::STATUS_COMPLETED,
                Payment::STATUS_FAILED,
                Payment::STATUS_REFUNDED,
                Payment::STATUS_REVERSED,
            ])],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date'],
        ];
    }
}
