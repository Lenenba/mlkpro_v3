<?php

namespace App\Modules\AiAssistant\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveAiActionRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
