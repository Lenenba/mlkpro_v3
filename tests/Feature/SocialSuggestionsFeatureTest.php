<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function pulseSuggestionsRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function pulseSuggestionsOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => pulseSuggestionsRoleId('owner'),
        'email' => 'pulse-suggestions-owner-'.Str::lower(Str::random(10)).'@example.com',
        'company_name' => 'North Studio',
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'locale' => 'fr',
        'company_features' => [
            'social' => true,
            'products' => true,
            'services' => true,
            'campaigns' => true,
            'promotions' => true,
        ],
        'onboarding_completed_at' => now(),
    ], $overrides));
}

function pulseSuggestionsTeamMember(
    User $owner,
    array $permissions = [],
    array $userOverrides = [],
    array $membershipOverrides = []
): User {
    $member = User::factory()->create(array_merge([
        'email' => 'pulse-suggestions-member-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => $owner->company_type,
        'company_features' => $owner->company_features,
        'onboarding_completed_at' => now(),
    ], $userOverrides));

    TeamMember::query()->create(array_merge([
        'account_id' => $owner->id,
        'user_id' => $member->id,
        'role' => 'member',
        'permissions' => $permissions,
        'is_active' => true,
    ], $membershipOverrides));

    return $member;
}

function pulseSuggestionsProduct(User $owner, array $overrides = []): Product
{
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Pulse Suggestion Category '.Str::upper(Str::random(4)),
    ]);

    return Product::query()->create(array_merge([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'name' => 'Laser Cleaner',
        'description' => 'Deep-clean finish for high-traffic interiors and client-facing spaces.',
        'price' => 129.99,
        'stock' => 20,
        'minimum_stock' => 1,
        'is_active' => true,
    ], $overrides));
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('returns pulse suggestions from linked source content', function () {
    $owner = pulseSuggestionsOwner();
    $product = pulseSuggestionsProduct($owner);

    $response = $this->actingAs($owner)
        ->postJson(route('social.suggestions'), [
            'source_type' => 'product',
            'source_id' => $product->id,
        ]);

    $response->assertOk()
        ->assertJsonPath('suggestions.context.locale', 'fr')
        ->assertJsonPath('suggestions.context.source_type', 'product')
        ->assertJsonPath('suggestions.context.source_id', $product->id)
        ->assertJsonPath('suggestions.context.source_label', 'Laser Cleaner')
        ->assertJsonCount(3, 'suggestions.captions')
        ->assertJsonCount(3, 'suggestions.ctas');

    expect((string) $response->json('suggestions.captions.0.text'))->toContain('Laser Cleaner')
        ->and($response->json('suggestions.hashtags'))->toContain('#LaserCleaner');
});

it('builds pulse suggestions from draft text without a linked source', function () {
    $owner = pulseSuggestionsOwner([
        'locale' => 'en',
        'company_name' => 'North Retail',
        'company_type' => 'products',
        'company_sector' => 'retail',
    ]);

    $response = $this->actingAs($owner)
        ->postJson(route('social.suggestions'), [
            'text' => 'Spring availability is open for premium detailing sessions.',
            'link_url' => 'https://example.com/book',
        ]);

    $response->assertOk()
        ->assertJsonPath('suggestions.context.locale', 'en')
        ->assertJsonPath('suggestions.context.source_type', null)
        ->assertJsonCount(3, 'suggestions.captions')
        ->assertJsonCount(3, 'suggestions.ctas');

    expect((string) $response->json('suggestions.captions.0.text'))->toContain('North Retail')
        ->and($response->json('suggestions.hashtags'))->toContain('#Retail');
});

it('allows read only team members to load suggestions but blocks unauthorized members', function () {
    $owner = pulseSuggestionsOwner();
    $viewer = pulseSuggestionsTeamMember($owner, ['social.view']);
    $blocked = pulseSuggestionsTeamMember($owner, []);

    $this->actingAs($viewer)
        ->postJson(route('social.suggestions'), [
            'text' => 'Quick update for the audience.',
        ])
        ->assertOk()
        ->assertJsonCount(3, 'suggestions.captions');

    $this->actingAs($blocked)
        ->postJson(route('social.suggestions'), [
            'text' => 'Blocked',
        ])
        ->assertForbidden();
});

it('blocks pulse suggestions when the social module is unavailable', function () {
    $owner = pulseSuggestionsOwner([
        'company_features' => [
            'social' => false,
            'products' => true,
            'services' => true,
            'campaigns' => true,
            'promotions' => true,
        ],
    ]);

    $this->actingAs($owner)
        ->postJson(route('social.suggestions'), [
            'text' => 'Blocked',
        ])
        ->assertForbidden();
});
