<?php

namespace App\Modules\AiAssistant\Requests;

use App\Modules\AiAssistant\Models\AiConversation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendAiMessageRequest extends FormRequest
{
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
            'message' => ['required', 'string', 'max:4000'],
            'company' => ['nullable', 'string', 'max:120'],
            'channel' => ['nullable', 'string', Rule::in([
                AiConversation::CHANNEL_WEB_CHAT,
                AiConversation::CHANNEL_PUBLIC_RESERVATION,
            ])],
            'visitor_name' => ['nullable', 'string', 'max:191'],
            'visitor_email' => ['nullable', 'email', 'max:191'],
            'visitor_phone' => ['nullable', 'string', 'max:80'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
