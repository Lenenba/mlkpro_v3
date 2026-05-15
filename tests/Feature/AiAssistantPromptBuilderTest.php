<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use App\Modules\AiAssistant\Services\AiPromptBuilder;

test('ai prompt builder includes only tenant scoped context and recent messages', function () {
    $tenantA = User::factory()->create([
        'company_name' => 'Studio Lumiere',
        'company_timezone' => 'America/Toronto',
    ]);
    $tenantB = User::factory()->create([
        'company_name' => 'Other Tenant',
    ]);

    AiAssistantSetting::factory()->create([
        'tenant_id' => $tenantA->id,
        'assistant_name' => 'Reception Lumiere',
        'business_context' => 'Salon sur rendez-vous a Montreal.',
        'allow_create_client' => false,
        'allow_create_reservation' => true,
    ]);
    AiAssistantSetting::factory()->create([
        'tenant_id' => $tenantB->id,
        'assistant_name' => 'Other Assistant',
        'business_context' => 'Private tenant context.',
    ]);

    $category = ProductCategory::factory()->create();
    Product::query()->create([
        'name' => 'Coupe cheveux',
        'description' => 'Service coupe classique',
        'category_id' => $category->id,
        'user_id' => $tenantA->id,
        'stock' => 0,
        'price' => 5000,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
        'currency_code' => 'CAD',
        'unit' => 'service',
    ]);
    Product::query()->create([
        'name' => 'Service archive',
        'description' => 'Do not include',
        'category_id' => $category->id,
        'user_id' => $tenantA->id,
        'stock' => 0,
        'price' => 1000,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => false,
        'currency_code' => 'CAD',
        'unit' => 'service',
    ]);
    Product::query()->create([
        'name' => 'Other tenant service',
        'description' => 'Cross tenant secret',
        'category_id' => $category->id,
        'user_id' => $tenantB->id,
        'stock' => 0,
        'price' => 1000,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
        'currency_code' => 'CAD',
        'unit' => 'service',
    ]);

    $conversationA = AiConversation::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);
    $conversationB = AiConversation::factory()->create([
        'tenant_id' => $tenantB->id,
    ]);

    AiMessage::factory()->forConversation($conversationA)->create([
        'sender_type' => AiMessage::SENDER_USER,
        'content' => 'Je veux reserver demain.',
    ]);
    AiMessage::factory()->forConversation($conversationA)->assistant()->create([
        'content' => 'Quel service souhaitez-vous reserver?',
    ]);
    AiMessage::factory()->forConversation($conversationB)->create([
        'content' => 'Cross tenant message secret.',
    ]);

    $builder = app(AiPromptBuilder::class);
    $context = $builder->context($conversationA);
    $prompt = $builder->systemPrompt($context, AiAssistantSetting::LANGUAGE_FR);

    expect($context->tenant->is($tenantA))->toBeTrue()
        ->and($context->services->pluck('name')->all())->toBe(['Coupe cheveux'])
        ->and($context->messages)->toHaveCount(2)
        ->and($prompt)->toContain('Reception Lumiere')
        ->and($prompt)->toContain('Studio Lumiere')
        ->and($prompt)->toContain('Salon sur rendez-vous a Montreal.')
        ->and($prompt)->toContain('- Coupe cheveux: Service coupe classique')
        ->and($prompt)->toContain('- user: Je veux reserver demain.')
        ->and($prompt)->toContain('- assistant: Quel service souhaitez-vous reserver?')
        ->and($prompt)->toContain('create_reservation')
        ->and($prompt)->not->toContain('Other tenant service')
        ->and($prompt)->not->toContain('Service archive')
        ->and($prompt)->not->toContain('Cross tenant message secret')
        ->and($prompt)->not->toContain('Private tenant context');
});
