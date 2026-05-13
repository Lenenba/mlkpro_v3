<?php

namespace App\Modules\AiAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Requests\StoreAiAssistantSettingRequest;
use Illuminate\Http\Request;

class AiAssistantSettingsController extends Controller
{
    public function edit(Request $request)
    {
        $account = $this->resolveAccount($request);
        $this->authorize('manage', AiAssistantSetting::class);
        $setting = AiAssistantSetting::firstOrCreateForTenant($account);

        return $this->inertiaOrJson('AiAssistant/Settings', [
            'setting' => $this->payload($setting),
            'options' => [
                'tones' => AiAssistantSetting::tones(),
                'languages' => AiAssistantSetting::languages(),
            ],
        ]);
    }

    public function update(StoreAiAssistantSettingRequest $request)
    {
        $account = $this->resolveAccount($request);
        $this->authorize('manage', AiAssistantSetting::class);
        $setting = AiAssistantSetting::firstOrCreateForTenant($account);
        $this->authorize('update', $setting);

        $setting->update($request->validated());

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'AI assistant settings saved.',
                'setting' => $this->payload($setting->fresh() ?? $setting),
            ]);
        }

        return redirect()->route('admin.ai-assistant.settings.edit')->with('success', 'AI assistant settings saved.');
    }

    private function resolveAccount(Request $request): User
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $accountId = (int) $user->accountOwnerId();
        $account = $accountId === (int) $user->id
            ? $user
            : User::query()->find($accountId);

        if (! $account) {
            abort(404);
        }

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AiAssistantSetting $setting): array
    {
        return [
            'id' => (int) $setting->id,
            'assistant_name' => (string) $setting->assistant_name,
            'enabled' => (bool) $setting->enabled,
            'default_language' => (string) $setting->default_language,
            'supported_languages' => array_values((array) $setting->supported_languages),
            'tone' => (string) $setting->tone,
            'greeting_message' => $setting->greeting_message,
            'fallback_message' => $setting->fallback_message,
            'allow_create_prospect' => (bool) $setting->allow_create_prospect,
            'allow_create_client' => (bool) $setting->allow_create_client,
            'allow_create_reservation' => (bool) $setting->allow_create_reservation,
            'allow_reschedule_reservation' => (bool) $setting->allow_reschedule_reservation,
            'allow_create_task' => (bool) $setting->allow_create_task,
            'require_human_validation' => (bool) $setting->require_human_validation,
            'business_context' => $setting->business_context,
            'service_area_rules' => $setting->service_area_rules,
            'working_hours_rules' => $setting->working_hours_rules,
        ];
    }
}
