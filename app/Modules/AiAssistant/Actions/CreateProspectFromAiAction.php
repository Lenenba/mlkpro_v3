<?php

namespace App\Modules\AiAssistant\Actions;

use App\Models\Request as LeadRequest;
use App\Modules\AiAssistant\Models\AiAction;
use Illuminate\Support\Arr;

class CreateProspectFromAiAction
{
    public function execute(AiAction $action): LeadRequest
    {
        $conversation = $action->conversation()->firstOrFail();
        if ($conversation->prospect_id) {
            return LeadRequest::query()->findOrFail((int) $conversation->prospect_id);
        }

        $payload = $action->input_payload ?? [];
        $contactName = trim((string) Arr::get($payload, 'contact_name', $conversation->visitor_name ?? ''));
        $serviceName = trim((string) Arr::get($payload, 'service_name', ''));
        $serviceAddress = trim((string) Arr::get($payload, 'service_address', ''));
        $notes = trim((string) Arr::get($payload, 'notes', ''));
        $description = trim(collect([$notes, $serviceAddress !== '' ? 'Adresse: '.$serviceAddress : null])->filter()->implode("\n"));

        $prospect = LeadRequest::query()->create([
            'user_id' => (int) $action->tenant_id,
            'channel' => 'ai_assistant',
            'status' => LeadRequest::STATUS_NEW,
            'status_updated_at' => now(),
            'last_activity_at' => now(),
            'service_type' => $serviceName ?: null,
            'title' => $serviceName !== '' ? 'AI request - '.$serviceName : 'AI assistant request',
            'description' => $description !== '' ? $description : null,
            'contact_name' => $contactName !== '' ? $contactName : null,
            'contact_email' => strtolower(trim((string) Arr::get($payload, 'contact_email', $conversation->visitor_email ?? ''))) ?: null,
            'contact_phone' => trim((string) Arr::get($payload, 'contact_phone', $conversation->visitor_phone ?? '')) ?: null,
            'meta' => [
                'ai_assistant' => [
                    'conversation_id' => (int) $conversation->id,
                    'source_channel' => (string) $conversation->channel,
                    'intent' => (string) $conversation->intent,
                    'created_from_action_id' => (int) $action->id,
                    'service_address' => $serviceAddress !== '' ? $serviceAddress : null,
                ],
            ],
        ]);

        $conversation->update([
            'prospect_id' => (int) $prospect->id,
            'visitor_name' => $contactName ?: $conversation->visitor_name,
            'visitor_email' => $prospect->contact_email ?: $conversation->visitor_email,
            'visitor_phone' => $prospect->contact_phone ?: $conversation->visitor_phone,
        ]);

        return $prospect;
    }
}
