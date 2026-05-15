<?php

namespace App\Modules\AiAssistant\Requests;

use App\Modules\AiAssistant\Models\AiAssistantSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiAssistantSettingRequest extends FormRequest
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
            'assistant_name' => ['required', 'string', 'max:120'],
            'enabled' => ['required', 'boolean'],
            'default_language' => ['required', 'string', Rule::in(AiAssistantSetting::languages())],
            'supported_languages' => ['required', 'array', 'min:1'],
            'supported_languages.*' => ['required', 'string', Rule::in(AiAssistantSetting::languages())],
            'tone' => ['required', 'string', Rule::in(AiAssistantSetting::tones())],
            'greeting_message' => ['nullable', 'string', 'max:2000'],
            'fallback_message' => ['nullable', 'string', 'max:2000'],
            'allow_create_prospect' => ['required', 'boolean'],
            'allow_create_client' => ['required', 'boolean'],
            'allow_create_reservation' => ['required', 'boolean'],
            'allow_reschedule_reservation' => ['required', 'boolean'],
            'allow_create_task' => ['required', 'boolean'],
            'require_human_validation' => ['required', 'boolean'],
            'enable_proactive_suggestions' => ['sometimes', 'boolean'],
            'enable_upsell_suggestions' => ['sometimes', 'boolean'],
            'enable_client_history_recommendations' => ['sometimes', 'boolean'],
            'max_suggestions_per_response' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'require_confirmation_before_ai_action' => ['sometimes', 'boolean'],
            'allow_ai_to_choose_earliest_slot' => ['sometimes', 'boolean'],
            'allow_ai_to_recommend_staff' => ['sometimes', 'boolean'],
            'allow_ai_to_recommend_services' => ['sometimes', 'boolean'],
            'business_context' => ['nullable', 'string', 'max:10000'],
            'service_area_rules' => ['nullable', 'array'],
            'working_hours_rules' => ['nullable', 'array'],
        ];
    }
}
