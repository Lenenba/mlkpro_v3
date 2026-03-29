<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\DemoWorkspace;
use App\Services\Demo\DemoWorkspaceCatalog;
use App\Services\Demo\DemoWorkspaceProvisioner;
use App\Services\Demo\DemoWorkspacePurgeService;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DemoWorkspaceController extends BaseSuperAdminController
{
    public function __construct(private DemoWorkspaceCatalog $catalog) {}

    public function index(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);

        $query = DemoWorkspace::query()
            ->with([
                'owner:id,name,email,company_name,trial_ends_at',
                'creator:id,name',
            ])
            ->latest();

        $statsQuery = DemoWorkspace::query();

        $workspaces = $query->paginate(12)->withQueryString();
        $workspaces->through(function (DemoWorkspace $workspace) {
            $modules = collect($workspace->selected_modules ?? []);

            return [
                'id' => $workspace->id,
                'prospect_name' => $workspace->prospect_name,
                'prospect_email' => $workspace->prospect_email,
                'prospect_company' => $workspace->prospect_company,
                'company_name' => $workspace->company_name,
                'company_type' => $workspace->company_type,
                'company_sector' => $workspace->company_sector,
                'seed_profile' => $workspace->seed_profile,
                'team_size' => $workspace->team_size,
                'selected_modules' => $modules->values()->all(),
                'module_labels' => $modules->map(fn (string $key) => $this->catalog->moduleLabel($key))->values()->all(),
                'seed_summary' => $workspace->seed_summary ?? [],
                'access_email' => $workspace->access_email,
                'access_password' => $workspace->access_password,
                'expires_at' => $workspace->expires_at?->toIso8601String(),
                'created_at' => $workspace->created_at?->toIso8601String(),
                'provisioned_at' => $workspace->provisioned_at?->toIso8601String(),
                'status' => $workspace->isExpired() ? 'expired' : 'active',
                'owner_user_id' => $workspace->owner_user_id,
                'owner' => $workspace->owner ? [
                    'id' => $workspace->owner->id,
                    'name' => $workspace->owner->name,
                    'email' => $workspace->owner->email,
                    'company_name' => $workspace->owner->company_name,
                ] : null,
                'creator' => $workspace->creator ? [
                    'id' => $workspace->creator->id,
                    'name' => $workspace->creator->name,
                ] : null,
            ];
        });

        return Inertia::render('SuperAdmin/DemoWorkspaces/Index', [
            'workspaces' => $workspaces,
            'stats' => [
                'total' => (clone $statsQuery)->count(),
                'active' => (clone $statsQuery)->active()->count(),
                'expired' => (clone $statsQuery)->expired()->count(),
                'expiring_soon' => (clone $statsQuery)
                    ->whereNotNull('expires_at')
                    ->whereBetween('expires_at', [now(), now()->addDays(3)])
                    ->count(),
            ],
            'options' => [
                'company_types' => $this->catalog->companyTypes(),
                'sectors' => $this->catalog->sectors(),
                'seed_profiles' => $this->catalog->seedProfiles(),
                'timezones' => $this->catalog->timezones(),
                'locales' => $this->catalog->locales(),
                'modules' => array_values($this->catalog->modules()),
                'presets' => $this->catalog->presets(),
            ],
            'defaults' => $this->catalog->defaults(),
            'can_view_tenant' => $request->user()?->isSuperadmin()
                || $request->user()?->hasPlatformPermission(PlatformPermissions::TENANTS_VIEW),
            'can_impersonate' => $request->user()?->isSuperadmin()
                || $request->user()?->hasPlatformPermission(PlatformPermissions::SUPPORT_IMPERSONATE),
        ]);
    }

    public function store(Request $request, DemoWorkspaceProvisioner $provisioner): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);

        $validated = $request->validate([
            'prospect_name' => ['required', 'string', 'max:120'],
            'prospect_email' => ['nullable', 'email', 'max:190'],
            'prospect_company' => ['nullable', 'string', 'max:160'],
            'company_name' => ['required', 'string', 'max:160'],
            'company_type' => ['required', Rule::in(collect($this->catalog->companyTypes())->pluck('value')->all())],
            'company_sector' => ['required', Rule::in(collect($this->catalog->sectors())->pluck('value')->all())],
            'seed_profile' => ['required', Rule::in(collect($this->catalog->seedProfiles())->pluck('value')->all())],
            'team_size' => ['required', 'integer', 'min:1', 'max:12'],
            'locale' => ['required', Rule::in(collect($this->catalog->locales())->pluck('value')->all())],
            'timezone' => ['required', Rule::in(collect($this->catalog->timezones())->pluck('value')->all())],
            'desired_outcome' => ['nullable', 'string', 'max:1500'],
            'internal_notes' => ['nullable', 'string', 'max:2500'],
            'selected_modules' => ['required', 'array', 'min:1'],
            'selected_modules.*' => ['string', Rule::in($this->catalog->moduleKeys())],
            'expires_at' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $workspace = $provisioner->create($validated, $request->user());

        $this->logAudit($request, 'demo_workspace.created', $workspace, [
            'owner_user_id' => $workspace->owner_user_id,
            'company_name' => $workspace->company_name,
            'modules' => $workspace->selected_modules,
            'expires_at' => $workspace->expires_at?->toIso8601String(),
        ]);

        return redirect()
            ->route('superadmin.demo-workspaces.index')
            ->with('success', 'Demo workspace created and provisioned.');
    }

    public function updateExpiration(
        Request $request,
        DemoWorkspace $demoWorkspace,
        DemoWorkspaceProvisioner $provisioner
    ): RedirectResponse {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);

        $validated = $request->validate([
            'expires_at' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $updatedWorkspace = $provisioner->updateExpiration($demoWorkspace, Carbon::parse($validated['expires_at']));

        $this->logAudit($request, 'demo_workspace.expiration_updated', $demoWorkspace, [
            'expires_at' => $updatedWorkspace->expires_at?->toIso8601String(),
        ]);

        return redirect()
            ->route('superadmin.demo-workspaces.index')
            ->with('success', 'Demo expiration updated.');
    }

    public function destroy(Request $request, DemoWorkspace $demoWorkspace, DemoWorkspacePurgeService $purgeService): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);

        $auditPayload = [
            'owner_user_id' => $demoWorkspace->owner_user_id,
            'company_name' => $demoWorkspace->company_name,
        ];

        $purgeService->purge($demoWorkspace);

        $this->logAudit($request, 'demo_workspace.deleted', null, $auditPayload);

        return redirect()
            ->route('superadmin.demo-workspaces.index')
            ->with('success', 'Demo workspace and all related tenant data were deleted.');
    }
}
