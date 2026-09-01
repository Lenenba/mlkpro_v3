<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\CompanyRole;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Rbac\AccessControl;
use App\Services\Rbac\CompanyModuleAccess;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

function companyModuleAccessOwner(): User
{
    $ownerRole = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role'],
    );

    return User::factory()->create([
        'role_id' => $ownerRole->id,
        'company_type' => 'services',
        'company_sector' => 'salon',
        'company_features' => [
            'products' => true,
            'sales' => true,
            'requests' => true,
            'quotes' => true,
            'campaigns' => true,
        ],
        'onboarding_completed_at' => now(),
    ]);
}

/**
 * @param  list<string>  $permissionSlugs
 * @param  array<string, mixed>  $attributes
 */
function companyModuleAccessRole(User $owner, array $permissionSlugs, array $attributes = []): CompanyRole
{
    $role = CompanyRole::query()->create(array_merge([
        'company_id' => $owner->id,
        'name' => 'Focused role '.fake()->unique()->numerify('####'),
        'slug' => fake()->unique()->slug(3),
        'description' => 'Role used to verify module isolation.',
        'is_system' => false,
        'is_default' => false,
        'is_editable' => true,
        'is_deletable' => true,
        'is_active' => true,
    ], $attributes));

    $permissionIds = collect($permissionSlugs)
        ->map(function (string $slug): int {
            return (int) Permission::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'group' => str($slug)->before('_')->before('.')->value(),
                    'name' => str($slug)->headline()->value(),
                    'description' => null,
                ],
            )->id;
        });

    $role->permissions()->sync($permissionIds);

    return $role->load('permissions');
}

function companyModuleAccessMember(
    User $owner,
    CompanyRole $companyRole,
    string $teamRole = 'member',
    bool $active = true,
): User {
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role'],
    );
    $member = User::factory()->create([
        'role_id' => $employeeRole->id,
        'onboarding_completed_at' => now(),
    ]);

    TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $member->id,
        'role' => $teamRole,
        'company_role_id' => $companyRole->id,
        'permissions' => [],
        'is_active' => $active,
    ]);

    return $member;
}

/**
 * @return array{customer: Customer, product: Product}
 */
function companyModuleAccessRecords(User $owner): array
{
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Module access products',
    ]);
    $product = Product::query()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => 'Strict role product',
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'tracking_type' => 'none',
        'price' => 25,
        'stock' => 5,
        'minimum_stock' => 1,
        'is_active' => true,
    ]);

    return compact('customer', 'product');
}

function companyModuleAccessService(
    User $owner,
    int $categoryId,
    string $name = 'Strict role service',
): Product {
    return Product::query()->create([
        'user_id' => $owner->id,
        'category_id' => $categoryId,
        'name' => $name,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'tracking_type' => 'none',
        'price' => 45,
        'stock' => 0,
        'minimum_stock' => 0,
        'is_active' => true,
    ]);
}

it('keeps complete customer and product modules closed for every role kind without view permissions', function (
    array $roleAttributes,
    string $teamRole,
) {
    $owner = companyModuleAccessOwner();
    $records = companyModuleAccessRecords($owner);
    $role = companyModuleAccessRole($owner, ['manage_cash_register'], $roleAttributes);
    $member = companyModuleAccessMember($owner, $role, $teamRole);

    $this->actingAs($member)->getJson(route('customer.index'))->assertForbidden();
    $this->actingAs($member)->getJson(route('customer.show', $records['customer']))->assertForbidden();
    $this->actingAs($member)->getJson(route('product.index'))->assertForbidden();
    $this->actingAs($member)->getJson(route('product.show', $records['product']))->assertForbidden();

    $this->actingAs($member)
        ->get(route('workspace.hubs.show', ['category' => 'operations']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.account.module_access.customers', false)
            ->where('auth.account.module_access.products', false)
        );

    $this->actingAs($member)->getJson(route('sales.index'))->assertOk();
})->with([
    'custom member role' => [[], 'member'],
    'standard admin role' => [['is_default' => true], 'admin'],
    'demo-style seller role' => [['is_default' => true], 'seller'],
    'global system role' => [[
        'company_id' => null,
        'is_system' => true,
        'is_editable' => false,
        'is_deletable' => false,
    ], 'member'],
]);

it('opens only the complete module whose view permission is assigned through a role', function (
    string $permission,
    bool $customersAllowed,
    bool $productsAllowed,
) {
    $owner = companyModuleAccessOwner();
    $records = companyModuleAccessRecords($owner);
    $role = companyModuleAccessRole($owner, [$permission]);
    $member = companyModuleAccessMember($owner, $role, 'seller');

    $customerResponse = $this->actingAs($member)->getJson(route('customer.index'));
    $productResponse = $this->actingAs($member)->getJson(route('product.index'));

    $customersAllowed ? $customerResponse->assertOk() : $customerResponse->assertForbidden();
    $productsAllowed ? $productResponse->assertOk() : $productResponse->assertForbidden();

    expect($member->can('view', $records['customer']))->toBe($customersAllowed)
        ->and($member->can('view', $records['product']))->toBe($productsAllowed);

    $this->actingAs($member)
        ->get(route('workspace.hubs.show', ['category' => 'operations']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.account.module_access.customers', $customersAllowed)
            ->where('auth.account.module_access.products', $productsAllowed)
        );
})->with([
    'clients only' => ['view_clients', true, false],
    'products only' => ['view_products', false, true],
]);

it('requires module view and the exact create action for full-module creation', function () {
    $owner = companyModuleAccessOwner();
    $records = companyModuleAccessRecords($owner);
    $customerCreateOnlyRole = companyModuleAccessRole($owner, ['create_clients']);
    $productCreateOnlyRole = companyModuleAccessRole($owner, ['create_products']);
    $productCreatorRole = companyModuleAccessRole($owner, ['view_products', 'create_products']);
    $customerCreateOnly = companyModuleAccessMember($owner, $customerCreateOnlyRole);
    $productCreateOnly = companyModuleAccessMember($owner, $productCreateOnlyRole);
    $productCreator = companyModuleAccessMember($owner, $productCreatorRole);
    $payload = [
        'name' => 'Role-created product',
        'category_id' => $records['product']->category_id,
        'price' => 35,
        'stock' => 3,
        'minimum_stock' => 1,
        'tracking_type' => 'none',
        'is_active' => true,
    ];

    expect($customerCreateOnly->can('create', Customer::class))->toBeFalse()
        ->and($productCreateOnly->can('create', Product::class))->toBeFalse()
        ->and($productCreator->can('create', Product::class))->toBeTrue();

    $this->actingAs($customerCreateOnly)
        ->getJson(route('customer.create'))
        ->assertForbidden();
    $this->actingAs($productCreateOnly)
        ->postJson(route('product.quick.store'), $payload)
        ->assertForbidden();
    $this->actingAs($productCreator)
        ->getJson(route('product.options'))
        ->assertOk();
    $this->actingAs($productCreator)
        ->getJson(route('product.index'))
        ->assertOk()
        ->assertJsonPath('canCreate', true)
        ->assertJsonPath('canEdit', false);
    $createdProductId = $this->actingAs($productCreator)
        ->postJson(route('product.quick.store'), $payload)
        ->assertCreated()
        ->json('product.id');

    expect(Product::query()->findOrFail($createdProductId)->user_id)->toBe($owner->id);
});

it('keeps contextual selectors available without opening their complete modules', function () {
    $owner = companyModuleAccessOwner();
    companyModuleAccessRecords($owner);
    $role = companyModuleAccessRole($owner, [
        'manage_cash_register',
        'create_prospects',
        'create_quotes',
        'update_campaigns',
    ]);
    $member = companyModuleAccessMember($owner, $role);

    $this->actingAs($member)
        ->getJson(route('customer.options', ['scope' => 'full']))
        ->assertForbidden();
    $this->actingAs($member)
        ->getJson(route('customer.options', ['scope' => 'request']))
        ->assertOk();
    $this->actingAs($member)
        ->getJson(route('customer.options', ['scope' => 'quote']))
        ->assertOk();
    $this->actingAs($member)
        ->getJson(route('customer.options', ['scope' => 'audience']))
        ->assertOk();
    $this->actingAs($member)
        ->getJson(route('product.search', ['query' => 'Strict', 'scope' => 'sales']))
        ->assertOk()
        ->assertJsonPath('0.name', 'Strict role product');
    $this->actingAs($member)
        ->getJson('/api/v1/product/search?query=Strict&scope=sales')
        ->assertOk()
        ->assertJsonPath('0.name', 'Strict role product');

    $this->actingAs($member)->getJson(route('customer.index'))->assertForbidden();
    $this->actingAs($member)->getJson(route('product.index'))->assertForbidden();
});

it('denies product searches without product module or contextual access', function () {
    $owner = companyModuleAccessOwner();
    companyModuleAccessRecords($owner);
    $role = companyModuleAccessRole($owner, ['view_clients']);
    $member = companyModuleAccessMember($owner, $role);

    $this->actingAs($member)
        ->getJson(route('product.search', ['query' => 'Strict', 'scope' => 'sales']))
        ->assertForbidden();
    $this->actingAs($member)
        ->getJson('/api/v1/product/search?query=Strict&scope=sales')
        ->assertForbidden();
});

it('isolates product search contexts and requires their company feature', function () {
    $owner = companyModuleAccessOwner();
    companyModuleAccessRecords($owner);
    $quoteRole = companyModuleAccessRole($owner, ['create_quotes']);
    $quoteCreator = companyModuleAccessMember($owner, $quoteRole);

    $this->actingAs($quoteCreator)
        ->getJson(route('product.search', ['query' => 'Strict', 'scope' => 'quote']))
        ->assertOk()
        ->assertJsonPath('0.name', 'Strict role product');
    foreach (['job', 'sales', ''] as $scope) {
        $this->actingAs($quoteCreator)
            ->getJson(route('product.search', ['query' => 'Strict', 'scope' => $scope]))
            ->assertForbidden();
    }

    $features = $owner->company_features;
    $features['quotes'] = false;
    $owner->forceFill(['company_features' => $features])->save();

    $this->actingAs($quoteCreator)
        ->getJson(route('product.search', ['query' => 'Strict', 'scope' => 'quote']))
        ->assertForbidden();
});

it('isolates each contextual customer selector to its matching action permission', function (
    string $permission,
    string $allowedScope,
) {
    $owner = companyModuleAccessOwner();
    Customer::factory()->create(['user_id' => $owner->id]);
    $role = companyModuleAccessRole($owner, [$permission]);
    $member = companyModuleAccessMember($owner, $role);

    foreach (['request', 'quote', 'audience'] as $scope) {
        $response = $this->actingAs($member)
            ->getJson(route('customer.options', ['scope' => $scope]));

        $scope === $allowedScope
            ? $response->assertOk()->assertJsonCount(1, 'customers')
            : $response->assertForbidden();
    }

    $this->actingAs($member)
        ->getJson(route('customer.options', ['scope' => 'full']))
        ->assertForbidden();
})->with([
    'request creation' => ['create_prospects', 'request'],
    'quote creation' => ['create_quotes', 'quote'],
    'campaign management' => ['update_campaigns', 'audience'],
]);

it('does not let read-only contextual permissions enumerate customers', function (
    string $permission,
    string $scope,
) {
    $owner = companyModuleAccessOwner();
    Customer::factory()->create(['user_id' => $owner->id]);
    $role = companyModuleAccessRole($owner, [$permission]);
    $member = companyModuleAccessMember($owner, $role);

    $this->actingAs($member)
        ->getJson(route('customer.options', ['scope' => $scope]))
        ->assertForbidden();
})->with([
    'request reader' => ['view_prospects', 'request'],
    'quote reader' => ['view_quotes', 'quote'],
    'campaign reader' => ['view_campaigns', 'audience'],
]);

it('closes contextual customer selectors when their company feature is disabled', function (
    string $permission,
    string $scope,
    string $feature,
) {
    $owner = companyModuleAccessOwner();
    $features = $owner->company_features;
    $features[$feature] = false;
    $owner->forceFill(['company_features' => $features])->save();
    Customer::factory()->create(['user_id' => $owner->id]);
    $role = companyModuleAccessRole($owner, [$permission]);
    $member = companyModuleAccessMember($owner, $role);

    $this->actingAs($member)
        ->getJson(route('customer.options', ['scope' => $scope]))
        ->assertForbidden();
})->with([
    'requests disabled' => ['create_prospects', 'request', 'requests'],
    'quotes disabled' => ['create_quotes', 'quote', 'quotes'],
    'campaigns disabled' => ['update_campaigns', 'audience', 'campaigns'],
]);

it('rejects inactive preloaded memberships in backend and shared navigation checks', function () {
    $owner = companyModuleAccessOwner();
    $owner->forceFill(['company_type' => 'products'])->save();
    $role = companyModuleAccessRole($owner, ['view_clients', 'view_products']);
    $member = companyModuleAccessMember($owner, $role, active: false);
    $inactiveMembership = TeamMember::query()->where('user_id', $member->id)->firstOrFail();
    $inactiveMembership->load('companyRole.permissions');
    $member->setRelation('teamMembership', $inactiveMembership);

    expect(app(AccessControl::class)->userHasPermission($member, 'customers.view', $owner->id))->toBeFalse()
        ->and(app(CompanyModuleAccess::class)->allows($member, 'customers', $owner->id))->toBeFalse()
        ->and(app(CompanyModuleAccess::class)->allows($member, 'products', $owner->id))->toBeFalse();

    $this->actingAs($member)
        ->getJson(route('dashboard', ['fresh' => 1]))
        ->assertForbidden();
});

it('requires company permissions in addition to Sanctum abilities for inventory integrations', function () {
    $owner = companyModuleAccessOwner();
    $records = companyModuleAccessRecords($owner);
    $salesRole = companyModuleAccessRole($owner, ['manage_cash_register']);
    $seller = companyModuleAccessMember($owner, $salesRole, 'seller');

    Sanctum::actingAs($seller, ['inventory:read', 'inventory:write']);

    $this->getJson('/api/v1/integrations/products')->assertForbidden();
    $this->postJson("/api/v1/integrations/products/{$records['product']->id}/adjust", [
        'type' => 'in',
        'quantity' => 2,
    ])->assertForbidden();
});

it('combines inventory abilities with product permissions and tenant isolation', function () {
    $owner = companyModuleAccessOwner();
    $records = companyModuleAccessRecords($owner);
    $otherOwner = companyModuleAccessOwner();
    $otherRecords = companyModuleAccessRecords($otherOwner);
    $inventoryRole = companyModuleAccessRole($owner, ['view_products', 'adjust_stock']);
    $inventoryMember = companyModuleAccessMember($owner, $inventoryRole);

    Sanctum::actingAs($inventoryMember, ['inventory:read', 'inventory:write']);
    $this->getJson('/api/v1/integrations/products')
        ->assertOk()
        ->assertJsonPath('products.data.0.id', $records['product']->id);
    $this->getJson("/api/v1/integrations/products/{$otherRecords['product']->id}")
        ->assertForbidden();
    $this->postJson("/api/v1/integrations/products/{$records['product']->id}/adjust", [
        'type' => 'in',
        'quantity' => 2,
    ])->assertOk();

    Sanctum::actingAs($inventoryMember, ['inventory:read']);
    $this->postJson("/api/v1/integrations/products/{$records['product']->id}/adjust", [
        'type' => 'in',
        'quantity' => 2,
    ])->assertForbidden();

    Sanctum::actingAs($inventoryMember, ['inventory:write']);
    $this->getJson('/api/v1/integrations/products')->assertForbidden();
});

it('requires the dedicated stock permission on internal stock mutations', function () {
    $owner = companyModuleAccessOwner();
    $records = companyModuleAccessRecords($owner);
    $editorRole = companyModuleAccessRole($owner, ['view_products', 'update_products']);
    $stockRole = companyModuleAccessRole($owner, ['view_products', 'adjust_stock']);
    $editor = companyModuleAccessMember($owner, $editorRole);
    $stockManager = companyModuleAccessMember($owner, $stockRole);
    $payload = [
        'type' => 'in',
        'quantity' => 2,
    ];

    $this->actingAs($editor)
        ->postJson(route('product.adjust-stock', $records['product']), $payload)
        ->assertForbidden();
    $this->actingAs($editor)
        ->putJson(route('product.quick-update', $records['product']), ['stock' => 20])
        ->assertForbidden();
    $this->actingAs($editor)
        ->putJson(route('product.quick-update', $records['product']), ['price' => 30])
        ->assertOk();

    $this->actingAs($stockManager)
        ->postJson(route('product.adjust-stock', $records['product']), $payload)
        ->assertOk();

    Sanctum::actingAs($editor, ['inventory:write']);
    $this->postJson("/api/v1/product/{$records['product']->id}/adjust-stock", $payload)
        ->assertForbidden();

    Sanctum::actingAs($stockManager, ['inventory:write']);
    $this->postJson("/api/v1/product/{$records['product']->id}/adjust-stock", $payload)
        ->assertOk();
});

it('exposes only the exact product action abilities assigned to the role', function (
    string $permission,
    array $expectedAbilities,
) {
    $owner = companyModuleAccessOwner();
    companyModuleAccessRecords($owner);
    $role = companyModuleAccessRole($owner, ['view_products', $permission]);
    $member = companyModuleAccessMember($owner, $role);

    $this->actingAs($member)
        ->getJson(route('product.index'))
        ->assertOk()
        ->assertJsonPath('abilities.create', $expectedAbilities['create'])
        ->assertJsonPath('abilities.edit', $expectedAbilities['edit'])
        ->assertJsonPath('abilities.delete', $expectedAbilities['delete'])
        ->assertJsonPath('abilities.stock', $expectedAbilities['stock']);
})->with([
    'creator' => ['create_products', [
        'create' => true,
        'edit' => false,
        'delete' => false,
        'stock' => false,
    ]],
    'editor' => ['update_products', [
        'create' => false,
        'edit' => true,
        'delete' => false,
        'stock' => false,
    ]],
    'deleter' => ['delete_products', [
        'create' => false,
        'edit' => false,
        'delete' => true,
        'stock' => false,
    ]],
    'stock manager' => ['adjust_stock', [
        'create' => false,
        'edit' => false,
        'delete' => false,
        'stock' => true,
    ]],
]);

it('archives owner products in bulk when a member has product edit permission', function () {
    $owner = companyModuleAccessOwner();
    $records = companyModuleAccessRecords($owner);
    $role = companyModuleAccessRole($owner, ['view_products', 'update_products']);
    $member = companyModuleAccessMember($owner, $role);

    $this->actingAs($member)
        ->postJson(route('product.bulk'), [
            'action' => 'archive',
            'ids' => [$records['product']->id],
        ])
        ->assertOk()
        ->assertJsonPath('processed_ids', [$records['product']->id]);

    $this->assertDatabaseHas('products', [
        'id' => $records['product']->id,
        'user_id' => $owner->id,
        'is_active' => false,
    ]);
});

it('deletes owner products in bulk when a member has product delete permission', function () {
    $owner = companyModuleAccessOwner();
    $records = companyModuleAccessRecords($owner);
    $role = companyModuleAccessRole($owner, ['view_products', 'delete_products']);
    $member = companyModuleAccessMember($owner, $role);

    $this->actingAs($member)
        ->postJson(route('product.bulk'), [
            'action' => 'delete',
            'ids' => [$records['product']->id],
        ])
        ->assertOk()
        ->assertJsonPath('processed_ids', [$records['product']->id]);

    $this->assertDatabaseMissing('products', [
        'id' => $records['product']->id,
    ]);
});

it('forbids product bulk mutations without their exact permission', function (string $action) {
    $owner = companyModuleAccessOwner();
    $records = companyModuleAccessRecords($owner);
    $role = companyModuleAccessRole($owner, ['view_products']);
    $member = companyModuleAccessMember($owner, $role);

    $this->actingAs($member)
        ->postJson(route('product.bulk'), [
            'action' => $action,
            'ids' => [$records['product']->id],
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('products', [
        'id' => $records['product']->id,
        'is_active' => true,
    ]);
})->with([
    'archive requires edit' => ['archive'],
    'delete requires delete' => ['delete'],
]);

it('forbids product duplication without product create permission', function () {
    $owner = companyModuleAccessOwner();
    $records = companyModuleAccessRecords($owner);
    $role = companyModuleAccessRole($owner, ['view_products', 'adjust_stock']);
    $member = companyModuleAccessMember($owner, $role);

    $this->actingAs($member)
        ->postJson(route('product.duplicate', $records['product']))
        ->assertForbidden();

    $this->assertDatabaseMissing('products', [
        'user_id' => $owner->id,
        'name' => 'Strict role product (Copy)',
    ]);
});

it('forbids duplicating stocked products without stock permission', function () {
    $owner = companyModuleAccessOwner();
    $records = companyModuleAccessRecords($owner);
    $role = companyModuleAccessRole($owner, ['view_products', 'create_products']);
    $member = companyModuleAccessMember($owner, $role);

    $this->actingAs($member)
        ->postJson(route('product.duplicate', $records['product']))
        ->assertForbidden();

    $this->assertDatabaseMissing('products', [
        'user_id' => $owner->id,
        'name' => 'Strict role product (Copy)',
    ]);
});

it('duplicates zero-stock products with create permission alone', function () {
    $owner = companyModuleAccessOwner();
    $records = companyModuleAccessRecords($owner);
    $records['product']->update(['stock' => 0]);
    $role = companyModuleAccessRole($owner, ['view_products', 'create_products']);
    $member = companyModuleAccessMember($owner, $role);

    $duplicatedProductId = $this->actingAs($member)
        ->postJson(route('product.duplicate', $records['product']))
        ->assertOk()
        ->assertJsonPath('product.user_id', $owner->id)
        ->assertJsonPath('product.stock', 0)
        ->json('product.id');

    $this->assertDatabaseHas('products', [
        'id' => $duplicatedProductId,
        'user_id' => $owner->id,
        'name' => 'Strict role product (Copy)',
        'stock' => 0,
    ]);
});

it('duplicates stocked products when create and stock permissions are both assigned', function () {
    $owner = companyModuleAccessOwner();
    $records = companyModuleAccessRecords($owner);
    $role = companyModuleAccessRole($owner, ['view_products', 'create_products', 'adjust_stock']);
    $member = companyModuleAccessMember($owner, $role);

    $duplicatedProductId = $this->actingAs($member)
        ->postJson(route('product.duplicate', $records['product']))
        ->assertOk()
        ->assertJsonPath('product.user_id', $owner->id)
        ->assertJsonPath('product.stock', 5)
        ->json('product.id');

    $this->assertDatabaseHas('products', [
        'id' => $duplicatedProductId,
        'user_id' => $owner->id,
        'name' => 'Strict role product (Copy)',
        'stock' => 5,
    ]);
});

it('forbids minimum stock updates without stock permission', function () {
    $owner = companyModuleAccessOwner();
    $records = companyModuleAccessRecords($owner);
    $role = companyModuleAccessRole($owner, ['view_products', 'update_products']);
    $member = companyModuleAccessMember($owner, $role);

    $this->actingAs($member)
        ->putJson(route('product.quick-update', $records['product']), ['minimum_stock' => 4])
        ->assertForbidden();

    $this->assertDatabaseHas('products', [
        'id' => $records['product']->id,
        'minimum_stock' => 1,
    ]);
});

it('updates minimum stock when edit and stock permissions are both assigned', function () {
    $owner = companyModuleAccessOwner();
    $records = companyModuleAccessRecords($owner);
    $role = companyModuleAccessRole($owner, ['view_products', 'update_products', 'adjust_stock']);
    $member = companyModuleAccessMember($owner, $role);

    $this->actingAs($member)
        ->putJson(route('product.quick-update', $records['product']), ['minimum_stock' => 4])
        ->assertOk()
        ->assertJsonPath('product.minimum_stock', 4);

    $this->assertDatabaseHas('products', [
        'id' => $records['product']->id,
        'minimum_stock' => 4,
    ]);
});

it('returns only products from the default search for product-module viewers', function () {
    $owner = companyModuleAccessOwner();
    $records = companyModuleAccessRecords($owner);
    $service = companyModuleAccessService($owner, $records['product']->category_id);
    $role = companyModuleAccessRole($owner, ['view_products']);
    $member = companyModuleAccessMember($owner, $role);

    $this->actingAs($member)
        ->getJson(route('product.search', ['query' => 'Strict role']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $records['product']->id)
        ->assertJsonPath('0.item_type', Product::ITEM_TYPE_PRODUCT)
        ->assertJsonMissing(['id' => $service->id]);
});

it('forbids non-product item types in product-module search', function (string $itemType) {
    $owner = companyModuleAccessOwner();
    $records = companyModuleAccessRecords($owner);
    companyModuleAccessService($owner, $records['product']->category_id);
    $role = companyModuleAccessRole($owner, ['view_products']);
    $member = companyModuleAccessMember($owner, $role);

    $this->actingAs($member)
        ->getJson(route('product.search', [
            'query' => 'Strict role',
            'item_type' => $itemType,
        ]))
        ->assertForbidden();
})->with([
    'services' => [Product::ITEM_TYPE_SERVICE],
    'mixed catalog' => ['all'],
]);

it('keeps quote and job searches limited to their authorized context and tenant', function (
    string $permission,
    string $scope,
    string $otherScope,
) {
    $owner = companyModuleAccessOwner();
    $features = $owner->company_features;
    $features['jobs'] = true;
    $owner->forceFill(['company_features' => $features])->save();
    $records = companyModuleAccessRecords($owner);
    $service = companyModuleAccessService($owner, $records['product']->category_id);

    $otherOwner = companyModuleAccessOwner();
    $otherRecords = companyModuleAccessRecords($otherOwner);
    $otherService = companyModuleAccessService($otherOwner, $otherRecords['product']->category_id);

    $role = companyModuleAccessRole($owner, [$permission]);
    $member = companyModuleAccessMember($owner, $role);

    $this->actingAs($member)
        ->getJson(route('product.search', [
            'query' => 'Strict role',
            'scope' => $scope,
            'item_type' => 'all',
        ]))
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonFragment(['id' => $records['product']->id])
        ->assertJsonFragment(['id' => $service->id])
        ->assertJsonMissing(['id' => $otherRecords['product']->id])
        ->assertJsonMissing(['id' => $otherService->id]);

    $this->actingAs($member)
        ->getJson(route('product.search', [
            'query' => 'Strict role',
            'scope' => $scope,
            'item_type' => Product::ITEM_TYPE_SERVICE,
        ]))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $service->id);

    $this->actingAs($member)
        ->getJson(route('product.search', [
            'query' => 'Strict role',
            'scope' => $otherScope,
        ]))
        ->assertForbidden();
})->with([
    'quote creator' => ['create_quotes', 'quote', 'job'],
    'job creator' => ['create_jobs', 'job', 'quote'],
]);
