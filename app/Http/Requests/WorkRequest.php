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
            'customer_id' => 'required|integer|exists:customers,id',
            'job_title' => 'required|string|max:255',
            'instructions' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'is_all_day' => 'nullable|boolean',
            'later' => 'nullable|boolean',
            'ends' => 'nullable|string',
            'frequencyNumber' => 'nullable|integer',
            'frequency' => 'nullable|string',
            'totalVisits' => 'nullable|integer',
            'repeatsOn' => 'nullable|array',
            'repeatsOn.*' => 'string',
            'type' => 'nullable|string',
            'category' => 'nullable|string',
            'status' => 'nullable|string|in:scheduled,in_progress,completed,cancelled',
            'is_completed' => 'nullable|boolean',
            'subtotal' => 'nullable|numeric',
            'total' => 'nullable|numeric',
            'products' => 'nullable|array',
            'products.*.id' => 'required_with:products|integer|exists:products,id',
            'products.*.quantity' => 'required_with:products|integer|min:1',
            'products.*.price' => 'nullable|numeric|min:0',
            'products.*.total' => 'nullable|numeric|min:0',
            'team_member_ids' => 'nullable|array',
            'team_member_ids.*' => 'integer|exists:team_members,id',
        ];
    }
}
