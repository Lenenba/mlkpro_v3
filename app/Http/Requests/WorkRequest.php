<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WorkRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => 'required|exists:customers,id',
            'type' => 'required|string', // Example of restricted types
            'category' => 'required|string', // Example of restricted types
            'description' => 'required|string|max:255', // Optional: restrict the length
            'work_date' => 'required|date|after:today', // Ensure the work date is in the future
            'time_spent' => 'nullable|integer|min:0', // Optional: ensure it's non-negative if provided
            'base_cost' => 'nullable|numeric|min:0', // Ensure the cost is non-negative if provided
        ];
    }
}
