<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Jobs\ProvisionDemoWorkspaceJob;
use App\Models\ActivityLog;
use App\Models\DemoWorkspace;
use App\Models\DemoWorkspaceTemplate;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\MarketingSetting;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Demo\DemoWorkspaceCatalog;
use App\Support\PlatformPermissions;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

function demoWorkspaceRoleId(string $name, string $description): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description]
    )->id;
}

function demoWorkspacePlatformAdmin(array $permissions = []): User
{
    $user = User::query()->create([
        'name' => 'Demo Platform Admin',
        'email' => 'demo-platform-admin@example.com',
        'password' => 'password',
        'role_id' => demoWorkspaceRoleId('admin', 'Platform admin role'),
        'onboarding_completed_at' => now(),
    ]);

    PlatformAdmin::query()->create([
        'user_id' => $user->id,
        'role' => 'ops',
        'permissions' => $permissions,
        'is_active' => true,
        'require_2fa' => false,
    ]);

    return $user;
}

function demoWorkspacePayload(array $overrides = []): array
{
    /** @var DemoWorkspaceCatalog $catalog */
    $catalog = app(DemoWorkspaceCatalog::class);
    $modules = $catalog->defaultModules('services', 'salon');

    $payload = [
        'prospect_name' => 'Morgan Prospect',
        'prospect_email' => 'morgan.prospect@example.com',
        'prospect_company' => 'Northwind Collective',
        'company_name' => 'Northwind Demo Studio',
        'company_type' => 'services',
        'company_sector' => 'salon',
        'seed_profile' => 'standard',
        'team_size' => 3,
        'locale' => 'fr',
        'timezone' => 'America/Toronto',
        'desired_outcome' => 'Walk the prospect through bookings, queue management, and front-desk operations.',
        'internal_notes' => 'Prepared after discovery call.',
        'suggested_flow' => $catalog->suggestedFlow('services', 'salon', $modules),
        'selected_modules' => $modules,
        'scenario_packs' => $catalog->defaultScenarioPacks('services', 'salon', $modules),
        'branding_profile' => array_replace(
            $catalog->brandingProfileDefaults('services', 'salon', 'Northwind Demo Studio'),
            [
                'name' => 'Northwind Demo Studio',
                'tagline' => 'A calm, premium walk-through for salon operations.',
                'description' => 'Demo brand tailored for queue, booking, and front desk scenarios.',
                'website_url' => 'https://northwind-demo.example.com',
                'contact_email' => 'hello@northwind-demo.example.com',
                'phone' => '+1 514 555 0101',
            ],
        ),
        'expires_at' => now()->addDays(10)->toDateString(),
    ];

    return array_replace_recursive($payload, $overrides);
}

function demoWorkspaceTemplatePayload(array $overrides = []): array
{
    /** @var DemoWorkspaceCatalog $catalog */
    $catalog = app(DemoWorkspaceCatalog::class);
    $modules = $catalog->defaultModules('services', 'salon');
    $scenarioPacks = $catalog->defaultScenarioPacks('services', 'salon', $modules);

    $payload = [
        'name' => 'Salon fast track',
        'description' => 'Starter template for salon queue demos.',
        'company_type' => 'services',
        'company_sector' => 'salon',
        'seed_profile' => 'standard',
        'team_size' => 3,
        'locale' => 'fr',
        'timezone' => 'America/Toronto',
        'expiration_days' => 14,
        'selected_modules' => $modules,
        'scenario_packs' => $scenarioPacks,
        'branding_profile' => array_replace(
            $catalog->brandingProfileDefaults('services', 'salon', 'Salon fast track'),
            [
                'name' => 'Salon fast track',
                'tagline' => 'Walk bookings to in-service in minutes.',
                'description' => 'Reusable brand profile for salon queue demos.',
            ],
        ),
        'suggested_flow' => $catalog->suggestedFlow('services', 'salon', $modules),
        'is_default' => true,
        'is_active' => true,
    ];

    return array_replace_recursive($payload, $overrides);
}

function provisionDemoWorkspaceIfNeeded(DemoWorkspace $workspace, User $admin, bool $isReset = false): DemoWorkspace
{
    $workspace = $workspace->fresh(['owner']) ?? $workspace;
    $status = (string) ($workspace->provisioning_status ?? '');

    if (in_array($status, ['queued', 'provisioning'], true)) {
        app(Dispatcher::class)->dispatchSync(new ProvisionDemoWorkspaceJob($workspace->id, $admin->id, $isReset));
    }

    return $workspace->fresh(['owner']) ?? $workspace;
}

it('forbids platform admins without demo permissions from accessing the module', function () {
    $admin = demoWorkspacePlatformAdmin([PlatformPermissions::TENANTS_VIEW]);

    $this->actingAs($admin)
        ->from('/dashboard')
        ->get(route('superadmin.demo-workspaces.index'))
        ->assertRedirect('/dashboard')
        ->assertSessionHas('warning');
});

it('allows platform admins with demo permissions to access the module', function () {
    $admin = demoWorkspacePlatformAdmin([PlatformPermissions::DEMOS_MANAGE]);

    $this->actingAs($admin)
        ->get(route('superadmin.demo-workspaces.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SuperAdmin/DemoWorkspaces/Index')
            ->where('can_view_tenant', false)
            ->where('can_impersonate', false)
            ->has('filters.options')
            ->has('filters.sales_options')
            ->has('stats')
            ->has('workspaces.data')
        );
});

it('allows platform admins with demo permissions to access the demo builder page', function () {
    $admin = demoWorkspacePlatformAdmin([PlatformPermissions::DEMOS_MANAGE]);

    $this->actingAs($admin)
        ->get(route('superadmin.demo-workspaces.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SuperAdmin/DemoWorkspaces/Create')
            ->has('templates')
            ->has('options.modules')
            ->where('options.modules', fn ($modules) => collect($modules)->pluck('key')->contains('expenses'))
            ->has('options.scenario_packs')
            ->has('options.extra_access_roles')
            ->has('defaults.selected_modules')
            ->has('template_defaults.selected_modules')
        );
});

it('includes expenses in default demo module packs for service and commerce demos', function () {
    /** @var DemoWorkspaceCatalog $catalog */
    $catalog = app(DemoWorkspaceCatalog::class);

    expect($catalog->moduleKeys())->toContain('expenses')
        ->and($catalog->defaultModules('services', 'salon'))->toContain('expenses')
        ->and($catalog->defaultModules('products', 'retail'))->toContain('expenses');
});

it('allows platform admins with demo permissions to access a demo workspace details page', function () {
    $admin = demoWorkspacePlatformAdmin([PlatformPermissions::DEMOS_MANAGE]);

    $this->actingAs($admin)
        ->post(route('superadmin.demo-workspaces.store'), demoWorkspacePayload())
        ->assertRedirect(route('superadmin.demo-workspaces.index'));

    $workspace = DemoWorkspace::query()->firstOrFail();

    $this->actingAs($admin)
        ->get(route('superadmin.demo-workspaces.show', $workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SuperAdmin/DemoWorkspaces/Show')
            ->where('workspace.id', $workspace->id)
            ->has('workspace.module_labels')
            ->has('workspace.timeline')
            ->has('options.seed_profiles')
            ->has('options.sales_statuses')
        );
});

it('can create, duplicate and archive demo templates', function () {
    $admin = demoWorkspacePlatformAdmin([PlatformPermissions::DEMOS_MANAGE]);

    $this->actingAs($admin)
        ->post(route('superadmin.demo-workspaces.templates.store'), demoWorkspaceTemplatePayload())
        ->assertRedirect(route('superadmin.demo-workspaces.create'))
        ->assertSessionHas('success');

    $template = DemoWorkspaceTemplate::query()->firstOrFail();

    expect($template->is_default)->toBeTrue();
    expect($template->is_active)->toBeTrue();

    $this->actingAs($admin)
        ->post(route('superadmin.demo-workspaces.templates.duplicate', $template))
        ->assertRedirect(route('superadmin.demo-workspaces.create'))
        ->assertSessionHas('success');

    expect(DemoWorkspaceTemplate::query()->count())->toBe(2);

    $copy = DemoWorkspaceTemplate::query()
        ->whereKeyNot($template->id)
        ->firstOrFail();

    expect($copy->is_default)->toBeFalse();
    expect($copy->is_active)->toBeTrue();

    $this->actingAs($admin)
        ->patch(route('superadmin.demo-workspaces.templates.archive', $template), [
            'is_active' => false,
        ])
        ->assertRedirect(route('superadmin.demo-workspaces.create'))
        ->assertSessionHas('success');

    expect($template->fresh()->is_active)->toBeFalse();
    expect($template->fresh()->is_default)->toBeFalse();
});

it('provisions a realistic service demo workspace from the admin module', function () {
    Storage::fake('public');

    $admin = demoWorkspacePlatformAdmin([PlatformPermissions::DEMOS_MANAGE]);
    $payload = demoWorkspacePayload([
        'prefill_source' => 'crm',
        'prefill_payload' => [
            'prospect_name' => 'Morgan Prospect',
            'company' => 'Northwind Collective',
            'requested_modules' => ['reservations', 'planning'],
        ],
        'extra_access_roles' => ['manager', 'front_desk', 'staff'],
    ]);

    $this->actingAs($admin)
        ->post(route('superadmin.demo-workspaces.store'), $payload)
        ->assertRedirect(route('superadmin.demo-workspaces.index'))
        ->assertSessionHas('success');

    $workspace = DemoWorkspace::query()
        ->with('owner')
        ->firstOrFail();
    $workspace = provisionDemoWorkspaceIfNeeded($workspace, $admin);

    expect($workspace->company_name)->toBe('Northwind Demo Studio');
    expect($workspace->company_type)->toBe('services');
    expect($workspace->owner)->not->toBeNull();
    expect($workspace->owner?->is_demo)->toBeTrue();
    expect($workspace->owner?->demo_type)->toBe('custom');
    expect($workspace->provisioning_status)->toBe('ready');
    expect($workspace->provisioning_progress)->toBe(100);
    expect($workspace->prefill_source)->toBe('crm');
    expect($workspace->prefill_payload['prospect_name'] ?? null)->toBe($payload['prefill_payload']['prospect_name']);
    expect($workspace->prefill_payload['company'] ?? null)->toBe($payload['prefill_payload']['company']);
    expect($workspace->prefill_payload['requested_modules'] ?? null)->toBe($payload['prefill_payload']['requested_modules']);
    expect($workspace->extra_access_roles)->toBe(['manager', 'front_desk', 'staff']);
    expect($workspace->extra_access_credentials)->toHaveCount(3);
    expect($workspace->scenario_packs)->not->toBeEmpty();
    expect($workspace->branding_profile['name'] ?? null)->toBe('Northwind Demo Studio');
    expect($workspace->baseline_snapshot)->not->toBeEmpty();
    expect($workspace->baseline_created_at)->not->toBeNull();
    expect($workspace->seed_summary['customers'] ?? 0)->toBeGreaterThan(0);
    expect($workspace->seed_summary['reservations'] ?? 0)->toBeGreaterThan(0);
    expect($workspace->seed_summary['queue_items'] ?? 0)->toBeGreaterThan(0);
    expect($workspace->seed_summary['invoices'] ?? 0)->toBeGreaterThan(0);
    expect($workspace->seed_summary['expenses'] ?? 0)->toBeGreaterThan(0);
    expect($workspace->seed_summary['expense_attachments'] ?? 0)->toBeGreaterThan(0);
    expect(Expense::query()->where('user_id', $workspace->owner_user_id)->count())->toBeGreaterThan(0);
    expect(ExpenseAttachment::query()
        ->whereIn('expense_id', Expense::query()->where('user_id', $workspace->owner_user_id)->select('id'))
        ->count())->toBeGreaterThan(0);
    expect(TeamMember::query()->where('account_id', $workspace->owner_user_id)->count())->toBeGreaterThan(0);
    Storage::disk('public')->assertExists(
        ExpenseAttachment::query()
            ->whereIn('expense_id', Expense::query()->where('user_id', $workspace->owner_user_id)->select('id'))
            ->value('path')
    );
    expect(data_get(MarketingSetting::query()->where('user_id', $workspace->owner_user_id)->first(), 'templates.brand_profile.name'))
        ->toBe('Northwind Demo Studio');
    expect(ActivityLog::query()
        ->where('subject_type', $workspace->getMorphClass())
        ->where('subject_id', $workspace->id)
        ->pluck('action')
        ->all())
        ->toContain('demo_workspace.queued', 'demo_workspace.prefill_applied', 'demo_workspace.provisioning_started', 'demo_workspace.ready');
});

it('can create a workspace from a template, mark it sent and extend expiration', function () {
    $admin = demoWorkspacePlatformAdmin([PlatformPermissions::DEMOS_MANAGE]);

    $this->actingAs($admin)
        ->post(route('superadmin.demo-workspaces.templates.store'), demoWorkspaceTemplatePayload())
        ->assertRedirect(route('superadmin.demo-workspaces.create'));

    $template = DemoWorkspaceTemplate::query()->firstOrFail();

    $payload = demoWorkspacePayload([
        'demo_workspace_template_id' => $template->id,
        'suggested_flow' => $template->suggested_flow,
        'selected_modules' => $template->selected_modules,
        'scenario_packs' => $template->scenario_packs,
        'branding_profile' => $template->branding_profile,
    ]);

    $this->actingAs($admin)
        ->post(route('superadmin.demo-workspaces.store'), $payload)
        ->assertRedirect(route('superadmin.demo-workspaces.index'));

    $workspace = DemoWorkspace::query()->latest('id')->firstOrFail();
    $workspace = provisionDemoWorkspaceIfNeeded($workspace, $admin);
    $initialExpiration = $workspace->expires_at?->copy();

    expect($workspace->demo_workspace_template_id)->toBe($template->id);
    expect($workspace->suggested_flow)->toBe($template->suggested_flow);
    expect($workspace->scenario_packs)->toBe($template->scenario_packs);

    $this->actingAs($admin)
        ->patch(route('superadmin.demo-workspaces.delivery.update', $workspace), [
            'sent' => true,
        ])
        ->assertRedirect(route('superadmin.demo-workspaces.index'))
        ->assertSessionHas('success');

    expect($workspace->fresh()->sent_at)->not->toBeNull();
    expect($workspace->fresh()->sent_by_user_id)->toBe($admin->id);

    $this->actingAs($admin)
        ->patch(route('superadmin.demo-workspaces.expiration.extend', $workspace), [
            'days' => 7,
        ])
        ->assertRedirect(route('superadmin.demo-workspaces.index'))
        ->assertSessionHas('success');

    expect($workspace->fresh()->expires_at?->greaterThan($initialExpiration))->toBeTrue();
});

it('can provision and fully purge a commerce demo workspace', function () {
    Storage::fake('public');

    $admin = demoWorkspacePlatformAdmin([PlatformPermissions::DEMOS_MANAGE]);

    $payload = demoWorkspacePayload([
        'prospect_name' => 'Taylor Retail',
        'prospect_email' => 'taylor.retail@example.com',
        'company_name' => 'Taylor Retail Demo',
        'company_type' => 'products',
        'company_sector' => 'retail',
        'desired_outcome' => 'Show a commerce flow with catalog, sales, loyalty, and campaigns.',
        'selected_modules' => app(DemoWorkspaceCatalog::class)->defaultModules('products', 'retail'),
        'scenario_packs' => app(DemoWorkspaceCatalog::class)->defaultScenarioPacks(
            'products',
            'retail',
            app(DemoWorkspaceCatalog::class)->defaultModules('products', 'retail')
        ),
        'branding_profile' => array_replace(
            app(DemoWorkspaceCatalog::class)->brandingProfileDefaults('products', 'retail', 'Taylor Retail Demo'),
            [
                'name' => 'Taylor Retail Demo',
                'tagline' => 'Fast checkout and loyalty storytelling.',
            ],
        ),
    ]);

    $this->actingAs($admin)
        ->post(route('superadmin.demo-workspaces.store'), $payload)
        ->assertRedirect(route('superadmin.demo-workspaces.index'));

    $workspace = DemoWorkspace::query()->latest('id')->firstOrFail();
    $workspace = provisionDemoWorkspaceIfNeeded($workspace, $admin);
    $ownerId = $workspace->owner_user_id;
    $expenseAttachmentPaths = ExpenseAttachment::query()
        ->whereIn('expense_id', Expense::query()->where('user_id', $ownerId)->select('id'))
        ->pluck('path')
        ->filter()
        ->values();

    expect($workspace->seed_summary['sales'] ?? 0)->toBeGreaterThan(0);
    expect($workspace->seed_summary['campaigns'] ?? 0)->toBe(1);
    expect($workspace->seed_summary['loyalty_program_enabled'] ?? 0)->toBe(1);
    expect($workspace->seed_summary['expenses'] ?? 0)->toBeGreaterThan(0);
    expect($expenseAttachmentPaths)->not->toBeEmpty();

    $this->actingAs($admin)
        ->delete(route('superadmin.demo-workspaces.destroy', $workspace))
        ->assertRedirect(route('superadmin.demo-workspaces.index', ['status' => 'purged']))
        ->assertSessionHas('success');

    $purgedWorkspace = DemoWorkspace::query()->withTrashed()->find($workspace->id);

    expect($purgedWorkspace)->not->toBeNull();
    expect($purgedWorkspace?->provisioning_status)->toBe('purged');
    expect($purgedWorkspace?->purged_at)->not->toBeNull();
    expect($purgedWorkspace?->owner_user_id)->toBeNull();
    expect(User::query()->find($ownerId))->toBeNull();
    foreach ($expenseAttachmentPaths as $path) {
        Storage::disk('public')->assertMissing($path);
    }
});

it('can clone a demo workspace with fresh credentials and inherited phase two configuration', function () {
    $admin = demoWorkspacePlatformAdmin([PlatformPermissions::DEMOS_MANAGE]);

    $this->actingAs($admin)
        ->post(route('superadmin.demo-workspaces.store'), demoWorkspacePayload([
            'company_name' => 'Northwind Original Demo',
            'branding_profile' => array_replace(
                demoWorkspacePayload()['branding_profile'],
                [
                    'name' => 'Northwind Signature',
                    'tagline' => 'Clone-ready premium salon story.',
                ],
            ),
        ]))
        ->assertRedirect(route('superadmin.demo-workspaces.index'));

    $workspace = DemoWorkspace::query()->with('owner')->firstOrFail();
    $workspace = provisionDemoWorkspaceIfNeeded($workspace, $admin);
    $originalEmail = $workspace->access_email;
    $originalPassword = $workspace->access_password;
    $originalOwnerId = $workspace->owner_user_id;

    $this->actingAs($admin)
        ->post(route('superadmin.demo-workspaces.clone', $workspace), [
            'company_name' => 'Northwind Clone Demo',
            'prospect_name' => 'Jamie Clone',
            'prospect_email' => 'jamie.clone@example.com',
            'prospect_company' => 'Clone Prospect Inc.',
            'clone_data_mode' => 'regenerate_fresh_data',
            'seed_profile' => 'light',
            'expires_at' => now()->addDays(14)->toDateString(),
        ])
        ->assertRedirect(route('superadmin.demo-workspaces.index'))
        ->assertSessionHas('success');

    $clone = DemoWorkspace::query()
        ->with('owner')
        ->whereKeyNot($workspace->id)
        ->latest('id')
        ->firstOrFail();
    $clone = provisionDemoWorkspaceIfNeeded($clone, $admin);

    expect($clone->cloned_from_demo_workspace_id)->toBe($workspace->id);
    expect($clone->owner_user_id)->not->toBe($originalOwnerId);
    expect($clone->access_email)->not->toBe($originalEmail);
    expect($clone->access_password)->not->toBe($originalPassword);
    expect($clone->scenario_packs)->toBe($workspace->scenario_packs);
    expect($clone->branding_profile['name'] ?? null)->toBe('Northwind Signature');
    expect($clone->owner?->company_name)->toBe('Northwind Signature');
    expect($clone->seed_profile)->toBe('light');
    expect($clone->baseline_snapshot)->not->toBeEmpty();
    expect(data_get(MarketingSetting::query()->where('user_id', $clone->owner_user_id)->first(), 'templates.brand_profile.name'))
        ->toBe('Northwind Signature');
});

it('can save a baseline and reset a demo workspace back to that reference dataset', function () {
    $admin = demoWorkspacePlatformAdmin([PlatformPermissions::DEMOS_MANAGE]);
    $baselinePayload = demoWorkspacePayload([
        'company_name' => 'Reset Demo Studio',
        'branding_profile' => array_replace(
            demoWorkspacePayload()['branding_profile'],
            [
                'name' => 'Reset Demo Brand',
                'tagline' => 'Baseline-first demo story.',
            ],
        ),
    ]);

    $this->actingAs($admin)
        ->post(route('superadmin.demo-workspaces.store'), $baselinePayload)
        ->assertRedirect(route('superadmin.demo-workspaces.index'));

    $workspace = DemoWorkspace::query()->with('owner')->firstOrFail();
    $workspace = provisionDemoWorkspaceIfNeeded($workspace, $admin);
    $baselineOwnerId = $workspace->owner_user_id;
    $baselineEmail = $workspace->access_email;
    $baselinePassword = $workspace->access_password;
    $baselineBrandName = $workspace->branding_profile['name'] ?? null;
    $baselineCompanyName = $workspace->company_name;
    $baselineScenarioPacks = $workspace->scenario_packs;

    $this->actingAs($admin)
        ->put(route('superadmin.demo-workspaces.baseline.snapshot', $workspace))
        ->assertRedirect(route('superadmin.demo-workspaces.index'))
        ->assertSessionHas('success');

    $workspace->forceFill([
        'company_name' => 'Mutated Demo Workspace',
        'scenario_packs' => ['retail_checkout'],
        'branding_profile' => array_replace($workspace->branding_profile ?? [], [
            'name' => 'Mutated Demo Brand',
            'tagline' => 'This should disappear after reset.',
        ]),
    ])->save();

    $workspace->owner?->forceFill([
        'company_name' => 'Mutated Demo Brand',
    ])->save();

    MarketingSetting::query()->updateOrCreate(
        ['user_id' => $baselineOwnerId],
        [
            'templates' => [
                'brand_profile' => [
                    'name' => 'Mutated Demo Brand',
                ],
            ],
        ]
    );

    $this->actingAs($admin)
        ->post(route('superadmin.demo-workspaces.baseline.reset', $workspace))
        ->assertRedirect(route('superadmin.demo-workspaces.index'))
        ->assertSessionHas('success');

    $workspace = provisionDemoWorkspaceIfNeeded($workspace, $admin, true);

    expect($workspace->company_name)->toBe($baselineCompanyName);
    expect($workspace->scenario_packs)->toBe($baselineScenarioPacks);
    expect($workspace->branding_profile['name'] ?? null)->toBe($baselineBrandName);
    expect($workspace->owner_user_id)->not->toBe($baselineOwnerId);
    expect($workspace->access_email)->toBe($baselineEmail);
    expect($workspace->access_password)->toBe($baselinePassword);
    expect($workspace->last_reset_by_user_id)->toBe($admin->id);
    expect($workspace->last_reset_at)->not->toBeNull();
    expect(User::query()->find($baselineOwnerId))->toBeNull();
    expect($workspace->owner?->company_name)->toBe($baselineBrandName);
    expect(data_get(MarketingSetting::query()->where('user_id', $workspace->owner_user_id)->first(), 'templates.brand_profile.name'))
        ->toBe($baselineBrandName);
});

it('can update the sales status of a demo workspace and record it in the timeline', function () {
    $admin = demoWorkspacePlatformAdmin([PlatformPermissions::DEMOS_MANAGE]);

    $this->actingAs($admin)
        ->post(route('superadmin.demo-workspaces.store'), demoWorkspacePayload())
        ->assertRedirect(route('superadmin.demo-workspaces.index'));

    $workspace = DemoWorkspace::query()->firstOrFail();

    $this->actingAs($admin)
        ->patch(route('superadmin.demo-workspaces.sales-status.update', $workspace), [
            'sales_status' => 'converted',
        ])
        ->assertRedirect(route('superadmin.demo-workspaces.index'))
        ->assertSessionHas('success');

    expect($workspace->fresh()->sales_status)->toBe('converted');
    expect(ActivityLog::query()
        ->where('subject_type', $workspace->getMorphClass())
        ->where('subject_id', $workspace->id)
        ->where('action', 'demo_workspace.sales_status_changed')
        ->exists())->toBeTrue();
});

it('records login detection events for demo owners and extra role users', function () {
    $admin = demoWorkspacePlatformAdmin([PlatformPermissions::DEMOS_MANAGE]);

    $this->actingAs($admin)
        ->post(route('superadmin.demo-workspaces.store'), demoWorkspacePayload([
            'extra_access_roles' => ['manager', 'front_desk'],
        ]))
        ->assertRedirect(route('superadmin.demo-workspaces.index'));

    $workspace = DemoWorkspace::query()->firstOrFail()->load('owner');
    $workspace = provisionDemoWorkspaceIfNeeded($workspace, $admin);
    $extraCredential = collect($workspace->extra_access_credentials)->first();

    expect($extraCredential)->not->toBeNull();

    if ($workspace->owner) {
        $workspace->owner->forceFill([
            'two_factor_exempt' => true,
        ])->save();
    }

    $this->actingAs($admin)
        ->post(route('logout'))
        ->assertRedirect('/');

    $this->post(route('login'), [
        'email' => $workspace->access_email,
        'password' => $workspace->access_password,
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->post(route('logout'))
        ->assertRedirect('/');

    $this->post(route('login'), [
        'email' => $extraCredential['email'],
        'password' => $extraCredential['password'],
    ])->assertRedirect(route('dashboard', absolute: false));

    $loginEvents = ActivityLog::query()
        ->where('subject_type', $workspace->getMorphClass())
        ->where('subject_id', $workspace->id)
        ->where('action', 'demo_workspace.login_detected')
        ->orderBy('id')
        ->get();

    expect($loginEvents)->toHaveCount(2);
    expect(data_get($loginEvents[0]->properties, 'login_source'))->toBe('owner');
    expect(data_get($loginEvents[0]->properties, 'login_role_key'))->toBe('owner');
    expect(data_get($loginEvents[1]->properties, 'login_source'))->toBe('team_member');
    expect(data_get($loginEvents[1]->properties, 'two_factor'))->toBeFalse();
});
