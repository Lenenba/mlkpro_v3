<?php

namespace App\Modules\AiAssistant\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAiKnowledgeItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:191'],
            'content' => ['required', 'string', 'max:20000'],
            'category' => ['nullable', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
