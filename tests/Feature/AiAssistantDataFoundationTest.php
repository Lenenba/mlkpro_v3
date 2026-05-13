<?php

use App\Models\User;
use App\Models\TeamMember;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiKnowledgeItem;
use App\Modules\AiAssistant\Models\AiMessage;
use Inertia\Testing\AssertableInertia as Assert;

test('ai assistant settings can be created with tenant defaults', function () {
    $tenant = User::factory()->create([
        'company_name' => 'Studio Lumiere',
        'company_description' => 'Salon de beaute sur rendez-vous.',
    ]);

    $setting = AiAssistantSetting::firstOrCreateForTenant($tenant);

    expect($setting)
        ->tenant_id->toBe($tenant->id)
        ->assistant_name->toBe('Malikia AI Assistant')
        ->enabled->toBeFalse()
        ->default_language->toBe(AiAssistantSetting::LANGUAGE_FR)
        ->supported_languages->toBe([
            AiAssistantSetting::LANGUAGE_FR,
            AiAssistantSetting::LANGUAGE_EN,
        ])
        ->require_human_validation->toBeTrue()
        ->and($setting->tenant->is($tenant))->toBeTrue();

    expect(AiAssistantSetting::firstOrCreateForTenant($tenant)->is($setting))->toBeTrue();
});

test('ai assistant conversations messages actions and knowledge are tenant scoped', function () {
    $tenantA = User::factory()->create();
    $tenantB = User::factory()->create();

    $conversationA = AiConversation::factory()->create([
        'tenant_id' => $tenantA->id,
        'intent' => AiConversation::INTENT_RESERVATION,
        'confidence_score' => 0.86,
    ]);
    $conversationB = AiConversation::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    $messageA = AiMessage::factory()
        ->forConversation($conversationA)
        ->create([
            'sender_type' => AiMessage::SENDER_USER,
            'content' => 'Je veux reserver demain.',
            'payload' => ['source' => 'public_widget'],
        ]);
    AiMessage::factory()->forConversation($conversationB)->assistant()->create();

    $actionA = AiAction::factory()
        ->forConversation($conversationA)
        ->create([
            'action_type' => AiAction::TYPE_CREATE_PROSPECT,
            'input_payload' => ['contact_name' => 'Amina Diallo'],
        ]);
    AiAction::factory()->forConversation($conversationB)->create();

    $knowledgeA = AiKnowledgeItem::factory()->create([
        'tenant_id' => $tenantA->id,
        'title' => 'Cancellation policy',
    ]);
    AiKnowledgeItem::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    expect(AiConversation::query()->forTenant($tenantA->id)->pluck('id')->all())
        ->toBe([$conversationA->id])
        ->and(AiMessage::query()->forTenant($tenantA->id)->pluck('id')->all())
        ->toBe([$messageA->id])
        ->and(AiAction::query()->forTenant($tenantA->id)->pluck('id')->all())
        ->toBe([$actionA->id])
        ->and(AiKnowledgeItem::query()->forTenant($tenantA->id)->pluck('id')->all())
        ->toBe([$knowledgeA->id]);

    $conversationA->refresh();

    expect($conversationA->tenant->is($tenantA))->toBeTrue()
        ->and($conversationA->messages)->toHaveCount(1)
        ->and($conversationA->actions)->toHaveCount(1)
        ->and($messageA->conversation->is($conversationA))->toBeTrue()
        ->and($actionA->conversation->is($conversationA))->toBeTrue()
        ->and($knowledgeA->tenant->is($tenantA))->toBeTrue();
});

test('owners can read and update ai assistant settings', function () {
    $owner = User::factory()->create([
        'company_features' => [
            'assistant' => true,
        ],
    ]);

    $this->actingAs($owner)
        ->get(route('admin.ai-assistant.settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('AiAssistant/Settings')
            ->where('setting.enabled', false)
            ->where('setting.default_language', AiAssistantSetting::LANGUAGE_FR)
        );

    $payload = [
        'assistant_name' => 'Reception Lumiere',
        'enabled' => true,
        'default_language' => AiAssistantSetting::LANGUAGE_EN,
        'supported_languages' => [
            AiAssistantSetting::LANGUAGE_EN,
            AiAssistantSetting::LANGUAGE_FR,
        ],
        'tone' => AiAssistantSetting::TONE_PROFESSIONAL,
        'greeting_message' => 'Hello, how can I help?',
        'fallback_message' => 'The team will review this request.',
        'allow_create_prospect' => true,
        'allow_create_client' => false,
        'allow_create_reservation' => true,
        'allow_reschedule_reservation' => false,
        'allow_create_task' => false,
        'require_human_validation' => true,
        'business_context' => 'Appointment-only studio.',
        'service_area_rules' => null,
        'working_hours_rules' => null,
    ];

    $this->actingAs($owner)
        ->putJson(route('admin.ai-assistant.settings.update'), $payload)
        ->assertOk()
        ->assertJsonPath('setting.assistant_name', 'Reception Lumiere')
        ->assertJsonPath('setting.enabled', true);

    $this->assertDatabaseHas('ai_assistant_settings', [
        'tenant_id' => $owner->id,
        'assistant_name' => 'Reception Lumiere',
        'enabled' => true,
        'default_language' => AiAssistantSetting::LANGUAGE_EN,
    ]);
});

test('ai assistant settings stay scoped to the authenticated tenant', function () {
    $tenantA = User::factory()->create([
        'company_features' => [
            'assistant' => true,
        ],
    ]);
    $tenantB = User::factory()->create([
        'company_features' => [
            'assistant' => true,
        ],
    ]);

    AiAssistantSetting::factory()->create([
        'tenant_id' => $tenantA->id,
        'assistant_name' => 'Tenant A Assistant',
    ]);
    AiAssistantSetting::factory()->create([
        'tenant_id' => $tenantB->id,
        'assistant_name' => 'Tenant B Assistant',
    ]);

    $this->actingAs($tenantA)
        ->getJson(route('admin.ai-assistant.settings.edit'))
        ->assertOk()
        ->assertJsonPath('setting.assistant_name', 'Tenant A Assistant');

    $this->actingAs($tenantA)
        ->putJson(route('admin.ai-assistant.settings.update'), [
            'assistant_name' => 'Tenant A Updated',
            'enabled' => true,
            'default_language' => AiAssistantSetting::LANGUAGE_FR,
            'supported_languages' => [AiAssistantSetting::LANGUAGE_FR],
            'tone' => AiAssistantSetting::TONE_WARM,
            'greeting_message' => null,
            'fallback_message' => null,
            'allow_create_prospect' => true,
            'allow_create_client' => false,
            'allow_create_reservation' => true,
            'allow_reschedule_reservation' => false,
            'allow_create_task' => false,
            'require_human_validation' => true,
            'business_context' => null,
            'service_area_rules' => null,
            'working_hours_rules' => null,
        ])
        ->assertOk();

    $this->assertDatabaseHas('ai_assistant_settings', [
        'tenant_id' => $tenantA->id,
        'assistant_name' => 'Tenant A Updated',
    ]);
    $this->assertDatabaseHas('ai_assistant_settings', [
        'tenant_id' => $tenantB->id,
        'assistant_name' => 'Tenant B Assistant',
    ]);
});

test('team members without ai assistant management permissions cannot update settings', function () {
    $owner = User::factory()->create([
        'company_features' => [
            'assistant' => true,
        ],
    ]);
    $employee = User::factory()->create();

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'permissions' => ['tasks.view'],
        'is_active' => true,
    ]);

    $this->actingAs($employee)
        ->putJson(route('admin.ai-assistant.settings.update'), [
            'assistant_name' => 'Unauthorized Change',
            'enabled' => true,
            'default_language' => AiAssistantSetting::LANGUAGE_FR,
            'supported_languages' => [AiAssistantSetting::LANGUAGE_FR],
            'tone' => AiAssistantSetting::TONE_WARM,
            'greeting_message' => null,
            'fallback_message' => null,
            'allow_create_prospect' => true,
            'allow_create_client' => false,
            'allow_create_reservation' => true,
            'allow_reschedule_reservation' => false,
            'allow_create_task' => false,
            'require_human_validation' => true,
            'business_context' => null,
            'service_area_rules' => null,
            'working_hours_rules' => null,
        ])
        ->assertForbidden();

    expect(AiAssistantSetting::query()->where('tenant_id', $owner->id)->exists())->toBeFalse();
});
