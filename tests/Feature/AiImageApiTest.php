<?php

use App\Models\AssistantCreditTransaction;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Assistant\OpenAiClient;
use App\Services\Assistant\OpenAiRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    config()->set('services.openai.key', 'test-openai-key');
    config()->set('services.openai.image_output_format', 'png');
});

test('ai image api returns the mobile contract in free mode for owners', function () {
    $owner = User::factory()->create([
        'role_id' => aiImageApiRoleId('owner', 'Account owner role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
        'assistant_credit_balance' => 7,
    ]);

    $client = \Mockery::mock(OpenAiClient::class);
    $client->shouldReceive('generateImage')
        ->once()
        ->with('Generate a storefront hero visual.', \Mockery::type('array'))
        ->andReturn([
            'data' => [
                ['b64_json' => base64_encode('fake-image-binary')],
            ],
        ]);
    $this->app->instance(OpenAiClient::class, $client);

    Sanctum::actingAs($owner);

    $response = $this->postJson('/api/v1/ai/images', [
        'prompt' => 'Generate a storefront hero visual.',
        'context' => 'store',
    ])->assertOk()
        ->assertJsonPath('mode', 'free')
        ->assertJsonPath('remaining', 0)
        ->assertJsonPath('credit_balance', 7)
        ->assertJsonPath('url', fn ($value) => is_string($value) && str_contains($value, '/storage/company/ai/'.$owner->id.'/store-'));

    $storedPath = aiImageApiStoredPathFromUrl($response->json('url'));
    Storage::disk('public')->assertExists($storedPath);

    $transaction = AssistantCreditTransaction::query()->sole();

    expect($transaction->user_id)->toBe($owner->id)
        ->and($transaction->type)->toBe('free')
        ->and($transaction->source)->toBe('ai_image_store')
        ->and(data_get($transaction->meta, 'context'))->toBe('store')
        ->and(data_get($transaction->meta, 'mode'))->toBe('free');
});

test('ai image api resolves owner credits for team members and can switch to credit mode', function () {
    $owner = User::factory()->create([
        'role_id' => aiImageApiRoleId('owner', 'Account owner role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
        'assistant_credit_balance' => 2,
    ]);

    AssistantCreditTransaction::query()->create([
        'user_id' => $owner->id,
        'type' => 'free',
        'credits' => 1,
        'source' => 'ai_image_product',
        'meta' => [
            'context' => 'product',
            'mode' => 'free',
        ],
    ]);

    $employee = User::factory()->create([
        'role_id' => aiImageApiRoleId('employee', 'Employee role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'permissions' => ['products.view'],
        'is_active' => true,
    ]);

    $client = \Mockery::mock(OpenAiClient::class);
    $client->shouldReceive('generateImage')
        ->once()
        ->with('Generate a polished product packshot.', \Mockery::type('array'))
        ->andReturn([
            'data' => [
                ['b64_json' => base64_encode('fake-product-image')],
            ],
        ]);
    $this->app->instance(OpenAiClient::class, $client);

    Sanctum::actingAs($employee);

    $response = $this->postJson('/api/v1/ai/images', [
        'prompt' => 'Generate a polished product packshot.',
        'context' => 'product',
    ])->assertOk()
        ->assertJsonPath('mode', 'credit')
        ->assertJsonPath('remaining', 0)
        ->assertJsonPath('credit_balance', 1)
        ->assertJsonPath('url', fn ($value) => is_string($value) && str_contains($value, '/storage/company/ai/'.$owner->id.'/product-'));

    $storedPath = aiImageApiStoredPathFromUrl($response->json('url'));
    Storage::disk('public')->assertExists($storedPath);

    $owner->refresh();

    expect($owner->assistant_credit_balance)->toBe(1);

    $consume = AssistantCreditTransaction::query()
        ->where('type', 'consume')
        ->sole();

    expect($consume->user_id)->toBe($owner->id)
        ->and($consume->source)->toBe('ai_image_product')
        ->and(data_get($consume->meta, 'context'))->toBe('product')
        ->and(data_get($consume->meta, 'mode'))->toBe('credit');
});

test('ai image api returns a stable 429 when free usage and credits are exhausted', function () {
    $owner = User::factory()->create([
        'role_id' => aiImageApiRoleId('owner', 'Account owner role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
        'assistant_credit_balance' => 0,
    ]);

    AssistantCreditTransaction::query()->create([
        'user_id' => $owner->id,
        'type' => 'free',
        'credits' => 1,
        'source' => 'ai_image_store',
        'meta' => [
            'context' => 'store',
            'mode' => 'free',
        ],
    ]);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/ai/images', [
        'prompt' => 'Generate another storefront hero visual.',
        'context' => 'store',
    ])->assertStatus(429)
        ->assertJsonPath('message', 'Limite quotidienne d\'images IA atteinte. Achetez un pack IA pour continuer.');

    expect(AssistantCreditTransaction::query()->count())->toBe(1);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('ai image api refunds consumed credit when OpenAI image generation fails', function () {
    $owner = User::factory()->create([
        'role_id' => aiImageApiRoleId('owner', 'Account owner role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
        'assistant_credit_balance' => 1,
    ]);

    AssistantCreditTransaction::query()->create([
        'user_id' => $owner->id,
        'type' => 'free',
        'credits' => 1,
        'source' => 'ai_image_product',
        'meta' => [
            'context' => 'product',
            'mode' => 'free',
        ],
    ]);

    $client = \Mockery::mock(OpenAiClient::class);
    $client->shouldReceive('generateImage')
        ->once()
        ->andThrow(new OpenAiRequestException(429, 'rate_limit_exceeded', 'Rate limit exceeded.'));
    $this->app->instance(OpenAiClient::class, $client);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/ai/images', [
        'prompt' => 'Generate a premium product hero.',
        'context' => 'product',
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Limite atteinte. Reessayez dans quelques minutes.');

    $owner->refresh();

    expect($owner->assistant_credit_balance)->toBe(1);

    $types = AssistantCreditTransaction::query()
        ->where('user_id', $owner->id)
        ->pluck('type')
        ->all();

    expect($types)->toBe(['free', 'consume', 'refund']);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

function aiImageApiRoleId(string $name, string $description): int
{
    return Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description],
    )->id;
}

function aiImageApiStoredPathFromUrl(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH) ?: $url;

    return ltrim(preg_replace('#^/storage/#', '', $path), '/');
}
