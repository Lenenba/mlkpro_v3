<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\Work;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $user = $this->user();
        $accountId = $user?->accountOwnerId() ?? 0;

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
            'status' => ['nullable', 'string', Rule::in(Work::STATUSES)],
            'is_completed' => 'nullable|boolean',
            'subtotal' => 'nullable|numeric',
            'total' => 'nullable|numeric',
            'billing_mode' => [
                'nullable',
                'string',
                Rule::in(['per_task', 'per_segment', 'end_of_job', 'deferred']),
            ],
            'billing_cycle' => [
                'nullable',
                'string',
                Rule::in(['weekly', 'biweekly', 'monthly', 'every_n_tasks']),
            ],
            'billing_grouping' => [
                'nullable',
                'string',
                Rule::in(['single', 'periodic']),
            ],
            'billing_delay_days' => 'nullable|integer|min:0|max:365',
            'billing_date_rule' => 'nullable|string|max:50',
            'products' => 'nullable|array',
            'products.*.id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')
                    ->where('user_id', $accountId),
            ],
            'products.*.item_type' => ['nullable', Rule::in([Product::ITEM_TYPE_PRODUCT, Product::ITEM_TYPE_SERVICE])],
            'products.*.name' => 'required_without:products.*.id|string',
            'products.*.description' => 'nullable|string',
            'products.*.source_details' => 'nullable',
            'products.*.quantity' => 'required_with:products|integer|min:1',
            'products.*.price' => 'nullable|numeric|min:0',
            'products.*.total' => 'nullable|numeric|min:0',
            'team_member_ids' => 'nullable|array',
            'team_member_ids.*' => 'integer|exists:team_members,id',
        ];
    }
}
