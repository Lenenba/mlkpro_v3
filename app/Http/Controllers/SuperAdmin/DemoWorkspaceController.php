<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Jobs\ProvisionDemoWorkspaceJob;
use App\Mail\DemoWorkspaceAccessMail;
use App\Models\ActivityLog;
use App\Models\DemoWorkspace;
use App\Models\DemoWorkspaceTemplate;
use App\Services\Demo\DemoWorkspaceCatalog;
use App\Services\Demo\DemoWorkspaceProvisioner;
use App\Services\Demo\DemoWorkspacePurgeService;
use App\Services\Demo\DemoWorkspaceTimelineService;
use App\Support\LocalePreference;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DemoWorkspaceController extends BaseSuperAdminController
{
    private const EXPIRING_SOON_DAYS = 3;

    private const CLONE_DATA_MODE_OPTIONS = [
        ['value' => 'keep_current_profile', 'label' => 'Keep current profile'],
        ['value' => 'regenerate_fresh_data', 'label' => 'Regenerate fresh data'],
    ];

    private const SALES_STATUS_OPTIONS = [
        ['value' => 'all', 'label' => 'All sales stages'],
        ['value' => 'discovery', 'label' => 'Discovery'],
        ['value' => 'active_opportunity', 'label' => 'Active opportunity'],
        ['value' => 'converted', 'label' => 'Converted'],
        ['value' => 'lost', 'label' => 'Lost'],
    ];

    public function __construct(private DemoWorkspaceCatalog $catalog) {}

    public function index(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);

        $statusFilter = (string) $request->string('status', 'all');
        $salesStatusFilter = (string) $request->string('sales_status', 'all');
        $perPage = $this->resolveDataTablePerPage($request);

        $query = DemoWorkspace::query()
            ->with([
                'owner:id,name,email,company_name,trial_ends_at',
                'creator:id,name',
                'sentBy:id,name',
                'lastResetBy:id,name',
                'template:id,name',
                'clonedFrom:id,company_name',
            ])
            ->latest();

        $this->applyWorkspaceStatusFilter($query, $statusFilter);
        $this->applySalesStatusFilter($query, $salesStatusFilter);

        $statsQuery = DemoWorkspace::query();
        $workspaces = $query->paginate($perPage)->withQueryString();
        $workspaceIds = $workspaces->getCollection()->pluck('id');
        $timelineByWorkspace = $this->timelineByWorkspace($workspaceIds);
        $workspaces->through(
            fn (DemoWorkspace $workspace) => $this->workspacePayload(
                $workspace,
                $timelineByWorkspace[$workspace->id] ?? []
            )
        );

        return Inertia::render('SuperAdmin/DemoWorkspaces/Index', [
            'workspaces' => $workspaces,
            'stats' => [
                'total' => (clone $statsQuery)->count(),
                'active' => (clone $statsQuery)->active()->count(),
                'sent' => (clone $statsQuery)
                    ->whereNotNull('sent_at')
                    ->where(function ($builder) {
                        $builder->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    })
                    ->count(),
                'expired' => (clone $statsQuery)->expired()->count(),
                'expiring_soon' => (clone $statsQuery)
                    ->whereNotNull('expires_at')
                    ->whereBetween('expires_at', [now(), now()->addDays(self::EXPIRING_SOON_DAYS)])
                    ->count(),
            ],
            'filters' => [
                'status' => $statusFilter,
                'sales_status' => $salesStatusFilter,
                'per_page' => $perPage,
                'options' => $this->statusFilterOptions(),
                'sales_options' => self::SALES_STATUS_OPTIONS,
            ],
            'can_view_tenant' => $request->user()?->isSuperadmin()
                || $request->user()?->hasPlatformPermission(PlatformPermissions::TENANTS_VIEW),
            'can_impersonate' => $request->user()?->isSuperadmin()
                || $request->user()?->hasPlatformPermission(PlatformPermissions::SUPPORT_IMPERSONATE),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);

        $templates = DemoWorkspaceTemplate::query()
            ->with('creator:id,name')
            ->latest()
            ->get()
            ->map(fn (DemoWorkspaceTemplate $template) => $this->templatePayload($template))
            ->values();

        return Inertia::render('SuperAdmin/DemoWorkspaces/Create', [
            'templates' => $templates,
            'options' => [
                'company_types' => $this->catalog->companyTypes(),
                'sectors' => $this->catalog->sectors(),
                'seed_profiles' => $this->catalog->seedProfiles(),
                'clone_data_modes' => self::CLONE_DATA_MODE_OPTIONS,
                'timezones' => $this->catalog->timezones(),
                'locales' => $this->catalog->locales(),
                'modules' => array_values($this->catalog->modules()),
                'presets' => $this->catalog->presets(),
                'scenario_packs' => $this->catalog->scenarioPacks(),
                'extra_access_roles' => $this->catalog->extraAccessRoles(),
                'prefill_sources' => [
                    ['value' => 'discovery_form', 'label' => 'Discovery form'],
                    ['value' => 'crm', 'label' => 'CRM'],
                    ['value' => 'manual_json', 'label' => 'Manual JSON'],
                    ['value' => 'clone', 'label' => 'Clone flow'],
                ],
                'sales_statuses' => self::SALES_STATUS_OPTIONS,
            ],
            'defaults' => $this->catalog->defaults(),
            'template_defaults' => $this->templateDefaults(),
        ]);
    }

    public function show(Request $request, DemoWorkspace $demoWorkspace): Response
    {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);

        $demoWorkspace->load([
            'owner:id,name,email,company_name,trial_ends_at',
            'creator:id,name',
            'sentBy:id,name',
            'lastResetBy:id,name',
            'template:id,name',
            'clonedFrom:id,company_name',
        ]);

        $timeline = $this->timelineByWorkspace(collect([$demoWorkspace->id]));

        return Inertia::render('SuperAdmin/DemoWorkspaces/Show', [
            'workspace' => $this->workspacePayload($demoWorkspace, $timeline[$demoWorkspace->id] ?? []),
            'filters' => [
                'status' => (string) $request->string('status', 'all'),
                'sales_status' => (string) $request->string('sales_status', 'all'),
                'page' => $request->integer('page') > 1 ? $request->integer('page') : null,
            ],
            'options' => [
                'seed_profiles' => $this->catalog->seedProfiles(),
                'clone_data_modes' => self::CLONE_DATA_MODE_OPTIONS,
                'sales_statuses' => self::SALES_STATUS_OPTIONS,
            ],
            'can_view_tenant' => $request->user()?->isSuperadmin()
                || $request->user()?->hasPlatformPermission(PlatformPermissions::TENANTS_VIEW),
            'can_impersonate' => $request->user()?->isSuperadmin()
                || $request->user()?->hasPlatformPermission(PlatformPermissions::SUPPORT_IMPERSONATE),
        ]);
    }

    public function provisioning(Request $request, DemoWorkspace $demoWorkspace): Response
    {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);

        $demoWorkspace->load([
            'owner:id,name,email,company_name,trial_ends_at',
            'creator:id,name',
            'sentBy:id,name',
            'lastResetBy:id,name',
            'template:id,name',
            'clonedFrom:id,company_name',
        ]);

        $timeline = $this->timelineByWorkspace(collect([$demoWorkspace->id]));

        return Inertia::render('SuperAdmin/DemoWorkspaces/Provisioning', [
            'workspace' => $this->workspacePayload($demoWorkspace, $timeline[$demoWorkspace->id] ?? []),
            'filters' => [
                'status' => (string) $request->string('status', 'all'),
                'sales_status' => (string) $request->string('sales_status', 'all'),
                'page' => $request->integer('page') > 1 ? $request->integer('page') : null,
                'per_page' => $this->resolveDataTablePerPage($request),
            ],
            'poll_interval_ms' => 1500,
        ]);
    }

    public function store(
        Request $request,
        DemoWorkspaceProvisioner $provisioner,
        DemoWorkspaceTimelineService $timeline
    ): RedirectResponse {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);

        $saveAsDraft = $request->boolean('save_as_draft');
        $payload = $this->validatedWorkspacePayload($request);
        $workspace = $saveAsDraft
            ? $provisioner->saveDraft($payload, $request->user())
            : $provisioner->queueCreate($payload, $request->user());

        $timeline->record($workspace, $saveAsDraft ? 'demo_workspace.draft_saved' : 'demo_workspace.queued', $request->user(), [
            'template_id' => $workspace->demo_workspace_template_id,
            'modules' => $workspace->selected_modules,
            'scenario_packs' => $workspace->scenario_packs,
            'prefill_source' => $workspace->prefill_source,
        ], $saveAsDraft
            ? 'Demo workspace saved as a draft. Provisioning can be queued later.'
            : 'Demo workspace queued for provisioning.');

        if ($workspace->prefill_source) {
            $timeline->record($workspace, 'demo_workspace.prefill_applied', $request->user(), [
                'prefill_source' => $workspace->prefill_source,
                'prefill_payload_keys' => array_keys($workspace->prefill_payload ?? []),
            ]);
        }

        if ($saveAsDraft === false) {
            $this->dispatchProvisioningJob($workspace, (int) $request->user()->id);
        }

        $this->logAudit($request, $saveAsDraft ? 'demo_workspace.draft_saved' : 'demo_workspace.created', $workspace, [
            'owner_user_id' => $workspace->owner_user_id,
            'company_name' => $workspace->company_name,
            'modules' => $workspace->selected_modules,
            'template_id' => $workspace->demo_workspace_template_id,
            'expires_at' => $workspace->expires_at?->toIso8601String(),
            'prefill_source' => $workspace->prefill_source,
        ]);

        if ($saveAsDraft) {
            return redirect()
                ->route('superadmin.demo-workspaces.show', ['demoWorkspace' => $workspace])
                ->with('success', 'Demo draft saved. Queue provisioning when you are ready.');
        }

        return redirect()
            ->route('superadmin.demo-workspaces.provisioning', ['demoWorkspace' => $workspace])
            ->with('success', 'Demo workspace queued for provisioning.');
    }

    public function queueProvisioning(
        Request $request,
        DemoWorkspace $demoWorkspace,
        DemoWorkspaceProvisioner $provisioner,
        DemoWorkspaceTimelineService $timeline
    ): RedirectResponse {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);

        if ($demoWorkspace->trashed()) {
            throw ValidationException::withMessages([
                'workspace' => ['Purged demos cannot be queued again. Clone the demo if you need a new environment.'],
            ]);
        }

        $currentProvisioningStatus = (string) ($demoWorkspace->provisioning_status ?? '');

        if (! in_array($currentProvisioningStatus, [
            DemoWorkspaceProvisioner::STATUS_DRAFT,
            DemoWorkspaceProvisioner::STATUS_QUEUED,
        ], true)) {
            throw ValidationException::withMessages([
                'workspace' => ['Only draft or queued demos can be dispatched from this action.'],
            ]);
        }

        $queuedWorkspace = $currentProvisioningStatus === DemoWorkspaceProvisioner::STATUS_DRAFT
            ? $provisioner->queueDraft($demoWorkspace, $request->user())
            : $demoWorkspace->fresh(['owner', 'creator', 'template', 'sentBy', 'clonedFrom', 'lastResetBy']);

        $timeline->record($queuedWorkspace, 'demo_workspace.queued', $request->user(), [
            'from_status' => $currentProvisioningStatus,
            'template_id' => $queuedWorkspace->demo_workspace_template_id,
            'modules' => $queuedWorkspace->selected_modules,
            'scenario_packs' => $queuedWorkspace->scenario_packs,
            'prefill_source' => $queuedWorkspace->prefill_source,
        ], $currentProvisioningStatus === DemoWorkspaceProvisioner::STATUS_DRAFT
            ? 'Draft demo queued for provisioning.'
            : 'Queued demo provisioning dispatched again.');

        $this->logAudit($request, $currentProvisioningStatus === DemoWorkspaceProvisioner::STATUS_DRAFT
            ? 'demo_workspace.queued_from_draft'
            : 'demo_workspace.queued_dispatched', $queuedWorkspace, [
                'company_name' => $queuedWorkspace->company_name,
                'modules' => $queuedWorkspace->selected_modules,
                'template_id' => $queuedWorkspace->demo_workspace_template_id,
                'expires_at' => $queuedWorkspace->expires_at?->toIso8601String(),
                'from_status' => $currentProvisioningStatus,
            ]);

        $this->dispatchProvisioningJob($queuedWorkspace, (int) $request->user()->id);

        return redirect()
            ->route('superadmin.demo-workspaces.provisioning', $this->provisioningRouteParameters($request, $queuedWorkspace))
            ->with('success', $currentProvisioningStatus === DemoWorkspaceProvisioner::STATUS_DRAFT
                ? 'Draft demo queued for provisioning.'
                : 'Queued demo provisioning dispatched again.');
    }

    public function updateExpiration(
        Request $request,
        DemoWorkspace $demoWorkspace,
        DemoWorkspaceProvisioner $provisioner,
        DemoWorkspaceTimelineService $timeline
    ): RedirectResponse {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);
        $this->ensureWorkspaceIsNotPurged($demoWorkspace);

        $validated = $request->validate([
            'expires_at' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $updatedWorkspace = $provisioner->updateExpiration($demoWorkspace, Carbon::parse($validated['expires_at']));

        $this->logAudit($request, 'demo_workspace.expiration_updated', $demoWorkspace, [
            'expires_at' => $updatedWorkspace->expires_at?->toIso8601String(),
        ]);
        $timeline->record($updatedWorkspace, 'demo_workspace.expiration_updated', $request->user(), [
            'expires_at' => $updatedWorkspace->expires_at?->toIso8601String(),
        ]);

        return redirect()
            ->route(
                ...$this->redirectAfterWorkspaceAction($request, $updatedWorkspace)
            )
            ->with('success', 'Demo expiration updated.');
    }

    public function extendExpiration(
        Request $request,
        DemoWorkspace $demoWorkspace,
        DemoWorkspaceProvisioner $provisioner,
        DemoWorkspaceTimelineService $timeline
    ): RedirectResponse {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);
        $this->ensureWorkspaceIsNotPurged($demoWorkspace);

        $validated = $request->validate([
            'days' => ['required', 'integer', Rule::in([3, 7, 14])],
        ]);

        $base = $demoWorkspace->expires_at && $demoWorkspace->expires_at->isFuture()
            ? $demoWorkspace->expires_at->copy()
            : now();

        $updatedWorkspace = $provisioner->updateExpiration(
            $demoWorkspace,
            $base->addDays((int) $validated['days'])
        );

        $this->logAudit($request, 'demo_workspace.expiration_extended', $demoWorkspace, [
            'days' => (int) $validated['days'],
            'expires_at' => $updatedWorkspace->expires_at?->toIso8601String(),
        ]);
        $timeline->record($updatedWorkspace, 'demo_workspace.expiration_extended', $request->user(), [
            'days' => (int) $validated['days'],
            'expires_at' => $updatedWorkspace->expires_at?->toIso8601String(),
        ]);

        return redirect()
            ->route(
                ...$this->redirectAfterWorkspaceAction($request, $updatedWorkspace)
            )
            ->with('success', 'Demo expiration extended.');
    }

    public function updateDeliveryStatus(
        Request $request,
        DemoWorkspace $demoWorkspace,
        DemoWorkspaceTimelineService $timeline
    ): RedirectResponse {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);
        $this->ensureWorkspaceIsNotPurged($demoWorkspace);
        $this->ensureWorkspaceHasProvisionedTenant($demoWorkspace, 'Access delivery can be updated only after the demo has been provisioned.');

        $validated = $request->validate([
            'sent' => ['required', 'boolean'],
        ]);

        $sent = (bool) $validated['sent'];

        $demoWorkspace->forceFill([
            'sent_at' => $sent ? now() : null,
            'sent_by_user_id' => $sent ? $request->user()?->id : null,
        ])->save();

        $this->logAudit($request, $sent ? 'demo_workspace.sent' : 'demo_workspace.unsent', $demoWorkspace, [
            'sent_at' => $demoWorkspace->sent_at?->toIso8601String(),
        ]);
        $timeline->record(
            $demoWorkspace,
            $sent ? 'demo_workspace.sent' : 'demo_workspace.unsent',
            $request->user(),
            ['sent_at' => $demoWorkspace->sent_at?->toIso8601String()]
        );

        return redirect()
            ->route(
                ...$this->redirectAfterWorkspaceAction(
                    $request,
                    $demoWorkspace
                )
            )
            ->with('success', $sent ? 'Access kit marked as sent.' : 'Access kit marked as not sent.');
    }

    public function sendAccessEmail(
        Request $request,
        DemoWorkspace $demoWorkspace,
        DemoWorkspaceTimelineService $timeline
    ): RedirectResponse {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);
        $this->ensureWorkspaceIsNotPurged($demoWorkspace);
        $this->ensureWorkspaceHasProvisionedTenant($demoWorkspace, 'Access email can be sent only after the demo has been provisioned.');

        if ($demoWorkspace->isExpired()) {
            throw ValidationException::withMessages([
                'workspace' => ['This demo has already expired. Extend the expiration before sending access.'],
            ]);
        }

        $prospectEmail = trim((string) $demoWorkspace->prospect_email);
        $accessEmail = trim((string) $demoWorkspace->access_email);
        $accessPassword = trim((string) $demoWorkspace->access_password);
        $hasValidProspectEmail = filter_var($prospectEmail, FILTER_VALIDATE_EMAIL) !== false;

        if ($prospectEmail === '' || $hasValidProspectEmail === false) {
            throw ValidationException::withMessages([
                'prospect_email' => ['Add a valid prospect email before sending access.'],
            ]);
        }

        if ($accessEmail === '' || $accessPassword === '') {
            throw ValidationException::withMessages([
                'workspace' => ['Primary demo credentials are not ready yet. Provision the workspace again before sending access.'],
            ]);
        }

        $modules = collect($demoWorkspace->selected_modules ?? []);
        $brandName = trim((string) data_get($demoWorkspace->branding_profile, 'name')) ?: $demoWorkspace->company_name;
        $replyToAddress = trim((string) (data_get($demoWorkspace->branding_profile, 'reply_to_email')
            ?: data_get($demoWorkspace->branding_profile, 'contact_email')));
        $replyToAddress = filter_var($replyToAddress, FILTER_VALIDATE_EMAIL) ? $replyToAddress : '';

        try {
            Mail::to($prospectEmail)->send(new DemoWorkspaceAccessMail(
                companyName: $brandName,
                companyLogo: $this->absoluteLogoUrl(data_get($demoWorkspace->branding_profile, 'logo_url')),
                recipientName: trim((string) $demoWorkspace->prospect_name),
                prospectCompany: trim((string) $demoWorkspace->prospect_company),
                workspaceName: $demoWorkspace->company_name,
                tagline: trim((string) data_get($demoWorkspace->branding_profile, 'tagline')),
                loginUrl: url('/login'),
                accessEmail: $accessEmail,
                accessPassword: $accessPassword,
                expiresAt: $demoWorkspace->expires_at?->toFormattedDateString(),
                templateName: (string) ($demoWorkspace->template?->name ?? ''),
                moduleLabels: $modules
                    ->map(fn (string $key) => $this->catalog->moduleLabel($key))
                    ->values()
                    ->all(),
                scenarioLabels: collect($demoWorkspace->scenario_packs ?? [])
                    ->map(fn (string $key) => $this->catalog->scenarioPackLabel($key))
                    ->values()
                    ->all(),
                extraCredentials: $this->emailableExtraAccessCredentials($demoWorkspace),
                suggestedFlow: trim((string) ($demoWorkspace->suggested_flow ?? '')),
                replyToAddress: $replyToAddress !== '' ? $replyToAddress : null,
                preferredLocale: LocalePreference::normalize((string) $demoWorkspace->locale),
            ));
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route(
                    ...$this->redirectAfterWorkspaceAction($request, $demoWorkspace)
                )
                ->with('error', 'Access email could not be sent. Check the current mail configuration and try again.');
        }

        $demoWorkspace->forceFill([
            'sent_at' => now(),
            'sent_by_user_id' => $request->user()?->id,
        ])->save();

        $properties = [
            'recipient_email' => $prospectEmail,
            'delivery_channel' => 'email',
            'sent_at' => $demoWorkspace->sent_at?->toIso8601String(),
        ];

        $this->logAudit($request, 'demo_workspace.access_email_sent', $demoWorkspace, $properties);
        $timeline->record(
            $demoWorkspace,
            'demo_workspace.access_email_sent',
            $request->user(),
            $properties,
            'Access email sent to the prospect.'
        );

        return redirect()
            ->route(
                ...$this->redirectAfterWorkspaceAction($request, $demoWorkspace)
            )
            ->with('success', sprintf('Access email sent to %s.', $prospectEmail));
    }

    public function updateSalesStatus(
        Request $request,
        DemoWorkspace $demoWorkspace,
        DemoWorkspaceTimelineService $timeline
    ): RedirectResponse {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);

        $validated = $request->validate([
            'sales_status' => ['required', Rule::in(
                collect(self::SALES_STATUS_OPTIONS)
                    ->pluck('value')
                    ->reject(fn (string $value) => $value === 'all')
                    ->all()
            )],
        ]);

        $previous = (string) ($demoWorkspace->sales_status ?? 'discovery');

        $demoWorkspace->forceFill([
            'sales_status' => (string) $validated['sales_status'],
        ])->save();

        $this->logAudit($request, 'demo_workspace.sales_status_changed', $demoWorkspace, [
            'from' => $previous,
            'to' => $demoWorkspace->sales_status,
        ]);
        $timeline->record($demoWorkspace, 'demo_workspace.sales_status_changed', $request->user(), [
            'from' => $previous,
            'to' => $demoWorkspace->sales_status,
        ]);

        return redirect()
            ->route(
                ...$this->redirectAfterWorkspaceAction($request, $demoWorkspace)
            )
            ->with('success', 'Sales status updated.');
    }

    public function cloneWorkspace(
        Request $request,
        DemoWorkspace $demoWorkspace,
        DemoWorkspaceProvisioner $provisioner,
        DemoWorkspaceTimelineService $timeline
    ): RedirectResponse {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:160'],
            'prospect_name' => ['nullable', 'string', 'max:120'],
            'prospect_email' => ['nullable', 'email', 'max:190'],
            'prospect_company' => ['nullable', 'string', 'max:160'],
            'clone_data_mode' => ['nullable', Rule::in(collect(self::CLONE_DATA_MODE_OPTIONS)->pluck('value')->all())],
            'seed_profile' => ['required', Rule::in(collect($this->catalog->seedProfiles())->pluck('value')->all())],
            'expires_at' => ['required', 'date', 'after_or_equal:today'],
        ]);
        $validated['clone_data_mode'] = (string) ($validated['clone_data_mode'] ?? 'keep_current_profile');

        $clone = $provisioner->queueClone($demoWorkspace, $validated, $request->user());
        $cloneModeLabel = $this->cloneDataModeLabel((string) $validated['clone_data_mode']);
        $cloneModeDescription = $this->cloneDataModeDescription((string) $validated['clone_data_mode']);

        $timeline->record($clone, 'demo_workspace.queued', $request->user(), [
            'template_id' => $clone->demo_workspace_template_id,
            'modules' => $clone->selected_modules,
            'scenario_packs' => $clone->scenario_packs,
            'source_demo_workspace_id' => $demoWorkspace->id,
            'clone_data_mode' => $validated['clone_data_mode'],
            'source_seed_profile' => $demoWorkspace->seed_profile,
            'target_seed_profile' => $clone->seed_profile,
        ], 'Cloned demo queued for provisioning. '.$cloneModeDescription);

        $this->logAudit($request, 'demo_workspace.cloned', $clone, [
            'source_demo_workspace_id' => $demoWorkspace->id,
            'owner_user_id' => $clone->owner_user_id,
            'company_name' => $clone->company_name,
            'clone_data_mode' => $validated['clone_data_mode'],
            'source_seed_profile' => $demoWorkspace->seed_profile,
            'target_seed_profile' => $clone->seed_profile,
        ]);

        $this->dispatchProvisioningJob($clone, (int) $request->user()->id);

        return redirect()
            ->route('superadmin.demo-workspaces.provisioning', $this->provisioningRouteParameters($request, $clone))
            ->with('success', 'Cloned demo queued using '.$cloneModeLabel.'.');
    }

    public function snapshotBaseline(
        Request $request,
        DemoWorkspace $demoWorkspace,
        DemoWorkspaceProvisioner $provisioner,
        DemoWorkspaceTimelineService $timeline
    ): RedirectResponse {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);
        $this->ensureWorkspaceIsNotPurged($demoWorkspace);
        $this->ensureWorkspaceHasProvisionedTenant($demoWorkspace, 'A baseline can be saved only after the demo has been provisioned.');

        $updatedWorkspace = $provisioner->saveBaseline($demoWorkspace);

        $this->logAudit($request, 'demo_workspace.baseline_saved', $updatedWorkspace, [
            'baseline_created_at' => $updatedWorkspace->baseline_created_at?->toIso8601String(),
        ]);
        $timeline->record($updatedWorkspace, 'demo_workspace.baseline_saved', $request->user(), [
            'baseline_created_at' => $updatedWorkspace->baseline_created_at?->toIso8601String(),
        ]);

        return redirect()
            ->route(
                ...$this->redirectAfterWorkspaceAction($request, $updatedWorkspace)
            )
            ->with('success', 'Baseline snapshot saved for this demo.');
    }

    public function resetToBaseline(
        Request $request,
        DemoWorkspace $demoWorkspace,
        DemoWorkspaceProvisioner $provisioner,
        DemoWorkspaceTimelineService $timeline
    ): RedirectResponse {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);
        $this->ensureWorkspaceIsNotPurged($demoWorkspace);
        $this->ensureWorkspaceHasProvisionedTenant($demoWorkspace, 'Draft demos must be queued first before they can be reset from baseline.');

        $resetWorkspace = $provisioner->queueResetToBaseline($demoWorkspace, $request->user());

        $this->logAudit($request, 'demo_workspace.reset_to_baseline', $resetWorkspace, [
            'queued_for_reset_at' => $resetWorkspace->queued_at?->toIso8601String(),
        ]);
        $timeline->record($resetWorkspace, 'demo_workspace.reset_queued', $request->user(), [
            'queued_at' => $resetWorkspace->queued_at?->toIso8601String(),
        ]);

        $this->dispatchProvisioningJob($resetWorkspace, (int) $request->user()->id, true);

        return redirect()
            ->route('superadmin.demo-workspaces.provisioning', $this->provisioningRouteParameters($request, $resetWorkspace))
            ->with('success', 'Baseline reset queued for this demo workspace.');
    }

    public function revokeExtraAccess(
        Request $request,
        DemoWorkspace $demoWorkspace,
        DemoWorkspaceProvisioner $provisioner,
        DemoWorkspaceTimelineService $timeline,
        string $roleKey
    ): RedirectResponse {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);
        $this->ensureWorkspaceIsNotPurged($demoWorkspace);
        $this->ensureExtraAccessRoleIsManageable($demoWorkspace, $roleKey);

        $updatedWorkspace = $provisioner->revokeExtraAccess($demoWorkspace, $roleKey);
        $roleLabel = $this->extraAccessRoleLabel($roleKey);

        $this->logAudit($request, 'demo_workspace.extra_access_revoked', $updatedWorkspace, [
            'role_key' => $roleKey,
            'role_label' => $roleLabel,
        ]);
        $timeline->record(
            $updatedWorkspace,
            'demo_workspace.extra_access_revoked',
            $request->user(),
            [
                'role_key' => $roleKey,
                'role_label' => $roleLabel,
            ],
            $roleLabel.' access revoked.'
        );

        return redirect()
            ->route(
                ...$this->redirectAfterWorkspaceAction($request, $updatedWorkspace)
            )
            ->with('success', $roleLabel.' access revoked.');
    }

    public function regenerateExtraAccess(
        Request $request,
        DemoWorkspace $demoWorkspace,
        DemoWorkspaceProvisioner $provisioner,
        DemoWorkspaceTimelineService $timeline,
        string $roleKey
    ): RedirectResponse {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);
        $this->ensureWorkspaceIsNotPurged($demoWorkspace);
        $this->ensureExtraAccessRoleIsManageable($demoWorkspace, $roleKey);

        try {
            $updatedWorkspace = $provisioner->regenerateExtraAccess($demoWorkspace, $roleKey);
        } catch (\RuntimeException $exception) {
            return redirect()
                ->route(
                    ...$this->redirectAfterWorkspaceAction($request, $demoWorkspace)
                )
                ->with('error', $exception->getMessage());
        }

        $roleLabel = $this->extraAccessRoleLabel($roleKey);

        $this->logAudit($request, 'demo_workspace.extra_access_regenerated', $updatedWorkspace, [
            'role_key' => $roleKey,
            'role_label' => $roleLabel,
        ]);
        $timeline->record(
            $updatedWorkspace,
            'demo_workspace.extra_access_regenerated',
            $request->user(),
            [
                'role_key' => $roleKey,
                'role_label' => $roleLabel,
            ],
            $roleLabel.' access regenerated with a fresh password.'
        );

        return redirect()
            ->route(
                ...$this->redirectAfterWorkspaceAction($request, $updatedWorkspace)
            )
            ->with('success', $roleLabel.' access regenerated.');
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);

        $validated = $this->validatedTemplatePayload($request);

        $template = DemoWorkspaceTemplate::create([
            ...$validated,
            'created_by_user_id' => $request->user()?->id,
        ]);

        $this->syncDefaultTemplate($template);

        $this->logAudit($request, 'demo_workspace_template.created', $template, [
            'company_type' => $template->company_type,
            'company_sector' => $template->company_sector,
            'is_default' => $template->is_default,
        ]);

        return redirect()
            ->route('superadmin.demo-workspaces.create')
            ->with('success', 'Demo template created.');
    }

    public function updateTemplate(Request $request, DemoWorkspaceTemplate $demoWorkspaceTemplate): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);

        $demoWorkspaceTemplate->fill($this->validatedTemplatePayload($request))->save();
        $this->syncDefaultTemplate($demoWorkspaceTemplate);

        $this->logAudit($request, 'demo_workspace_template.updated', $demoWorkspaceTemplate, [
            'company_type' => $demoWorkspaceTemplate->company_type,
            'company_sector' => $demoWorkspaceTemplate->company_sector,
            'is_default' => $demoWorkspaceTemplate->is_default,
            'is_active' => $demoWorkspaceTemplate->is_active,
        ]);

        return redirect()
            ->route('superadmin.demo-workspaces.create')
            ->with('success', 'Demo template updated.');
    }

    public function duplicateTemplate(Request $request, DemoWorkspaceTemplate $demoWorkspaceTemplate): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);

        $copy = $demoWorkspaceTemplate->replicate([
            'created_at',
            'updated_at',
        ]);

        $copy->forceFill([
            'created_by_user_id' => $request->user()?->id,
            'name' => $demoWorkspaceTemplate->name.' Copy',
            'is_default' => false,
            'is_active' => true,
        ])->save();

        $this->logAudit($request, 'demo_workspace_template.duplicated', $copy, [
            'source_template_id' => $demoWorkspaceTemplate->id,
        ]);

        return redirect()
            ->route('superadmin.demo-workspaces.create')
            ->with('success', 'Demo template duplicated.');
    }

    public function toggleTemplateArchive(Request $request, DemoWorkspaceTemplate $demoWorkspaceTemplate): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $isActive = (bool) $validated['is_active'];

        $demoWorkspaceTemplate->forceFill([
            'is_active' => $isActive,
            'is_default' => $isActive ? $demoWorkspaceTemplate->is_default : false,
        ])->save();

        $this->logAudit($request, $isActive ? 'demo_workspace_template.restored' : 'demo_workspace_template.archived', $demoWorkspaceTemplate, [
            'is_active' => $demoWorkspaceTemplate->is_active,
        ]);

        return redirect()
            ->route('superadmin.demo-workspaces.create')
            ->with('success', $isActive ? 'Demo template restored.' : 'Demo template archived.');
    }

    public function destroy(
        Request $request,
        DemoWorkspace $demoWorkspace,
        DemoWorkspacePurgeService $purgeService,
    ): RedirectResponse {
        $this->authorizePermission($request, PlatformPermissions::DEMOS_MANAGE);
        $this->ensureWorkspaceIsNotPurged($demoWorkspace);

        $purgedAt = now();
        $auditPayload = [
            'owner_user_id' => $demoWorkspace->owner_user_id,
            'company_name' => $demoWorkspace->company_name,
            'purged_at' => $purgedAt->toIso8601String(),
        ];

        $purgeService->purge($demoWorkspace, $request->user());

        $this->logAudit($request, 'demo_workspace.purged', null, $auditPayload);

        return redirect()
            ->route('superadmin.demo-workspaces.index', [
                ...$this->returnQuery($request),
                'status' => 'purged',
            ])
            ->with('success', 'Demo workspace purged. Its lifecycle history remains visible in the purged filter.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedWorkspacePayload(Request $request): array
    {
        return $request->validate([
            'prospect_name' => ['required', 'string', 'max:120'],
            'prospect_email' => ['nullable', 'email', 'max:190'],
            'prospect_company' => ['nullable', 'string', 'max:160'],
            'company_name' => ['required', 'string', 'max:160'],
            'demo_workspace_template_id' => ['nullable', 'integer', 'exists:demo_workspace_templates,id'],
            'company_type' => ['required', Rule::in(collect($this->catalog->companyTypes())->pluck('value')->all())],
            'company_sector' => ['required', Rule::in(collect($this->catalog->sectors())->pluck('value')->all())],
            'seed_profile' => ['required', Rule::in(collect($this->catalog->seedProfiles())->pluck('value')->all())],
            'team_size' => ['required', 'integer', 'min:1', 'max:12'],
            'locale' => ['required', Rule::in(collect($this->catalog->locales())->pluck('value')->all())],
            'timezone' => ['required', Rule::in(collect($this->catalog->timezones())->pluck('value')->all())],
            'desired_outcome' => ['nullable', 'string', 'max:1500'],
            'internal_notes' => ['nullable', 'string', 'max:2500'],
            'suggested_flow' => ['nullable', 'string', 'max:2500'],
            'selected_modules' => ['required', 'array', 'min:1'],
            'selected_modules.*' => ['string', Rule::in($this->catalog->moduleKeys())],
            'scenario_packs' => ['required', 'array', 'min:1'],
            'scenario_packs.*' => ['string', Rule::in($this->catalog->scenarioPackKeys())],
            'extra_access_roles' => ['nullable', 'array'],
            'extra_access_roles.*' => ['string', Rule::in($this->catalog->extraAccessRoleKeys())],
            'prefill_source' => ['nullable', 'string', 'max:80'],
            'prefill_payload' => ['nullable', 'array'],
            ...$this->brandingProfileValidationRules('branding_profile'),
            'expires_at' => ['required', 'date', 'after_or_equal:today'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedTemplatePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'company_type' => ['required', Rule::in(collect($this->catalog->companyTypes())->pluck('value')->all())],
            'company_sector' => ['required', Rule::in(collect($this->catalog->sectors())->pluck('value')->all())],
            'seed_profile' => ['required', Rule::in(collect($this->catalog->seedProfiles())->pluck('value')->all())],
            'team_size' => ['required', 'integer', 'min:1', 'max:12'],
            'locale' => ['required', Rule::in(collect($this->catalog->locales())->pluck('value')->all())],
            'timezone' => ['required', Rule::in(collect($this->catalog->timezones())->pluck('value')->all())],
            'expiration_days' => ['required', 'integer', 'min:1', 'max:60'],
            'suggested_flow' => ['nullable', 'string', 'max:2500'],
            'selected_modules' => ['required', 'array', 'min:1'],
            'selected_modules.*' => ['string', Rule::in($this->catalog->moduleKeys())],
            'scenario_packs' => ['required', 'array', 'min:1'],
            'scenario_packs.*' => ['string', Rule::in($this->catalog->scenarioPackKeys())],
            ...$this->brandingProfileValidationRules('branding_profile'),
            'is_default' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function brandingProfileValidationRules(string $prefix): array
    {
        return [
            $prefix => ['nullable', 'array'],
            $prefix.'.name' => ['nullable', 'string', 'max:160'],
            $prefix.'.tagline' => ['nullable', 'string', 'max:160'],
            $prefix.'.description' => ['nullable', 'string', 'max:1500'],
            $prefix.'.logo_url' => ['nullable', 'string', 'max:2048'],
            $prefix.'.website_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'.contact_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'.support_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'.booking_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'.contact_email' => ['nullable', 'email', 'max:190'],
            $prefix.'.reply_to_email' => ['nullable', 'email', 'max:190'],
            $prefix.'.phone' => ['nullable', 'string', 'max:60'],
            $prefix.'.address_line_1' => ['nullable', 'string', 'max:255'],
            $prefix.'.address_line_2' => ['nullable', 'string', 'max:255'],
            $prefix.'.city' => ['nullable', 'string', 'max:120'],
            $prefix.'.province' => ['nullable', 'string', 'max:120'],
            $prefix.'.country' => ['nullable', 'string', 'max:120'],
            $prefix.'.postal_code' => ['nullable', 'string', 'max:60'],
            $prefix.'.primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            $prefix.'.secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            $prefix.'.accent_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            $prefix.'.surface_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            $prefix.'.hero_background_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            $prefix.'.footer_background_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            $prefix.'.text_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            $prefix.'.muted_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            $prefix.'.facebook_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'.instagram_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'.linkedin_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'.youtube_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'.tiktok_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'.whatsapp_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'.footer_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function syncDefaultTemplate(DemoWorkspaceTemplate $template): void
    {
        if ($template->is_default === false || $template->is_active === false) {
            return;
        }

        DemoWorkspaceTemplate::query()
            ->where('id', '!=', $template->id)
            ->where('company_type', $template->company_type)
            ->where('company_sector', $template->company_sector)
            ->update(['is_default' => false]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<DemoWorkspace>  $query
     */
    private function applyWorkspaceStatusFilter($query, string $status): void
    {
        if ($status === 'draft') {
            $query->where('provisioning_status', DemoWorkspaceProvisioner::STATUS_DRAFT);

            return;
        }

        if ($status === 'queued') {
            $query->where('provisioning_status', DemoWorkspaceProvisioner::STATUS_QUEUED);

            return;
        }

        if ($status === 'provisioning') {
            $query->where('provisioning_status', DemoWorkspaceProvisioner::STATUS_PROVISIONING);

            return;
        }

        if ($status === 'failed') {
            $query->where('provisioning_status', DemoWorkspaceProvisioner::STATUS_FAILED);

            return;
        }

        if ($status === 'purged') {
            $query->onlyTrashed()
                ->whereNotNull('purged_at');

            return;
        }

        if ($status === 'expired') {
            $query->expired();

            return;
        }

        if ($status === 'expires_soon') {
            $query->whereNotNull('expires_at')
                ->whereBetween('expires_at', [now(), now()->addDays(self::EXPIRING_SOON_DAYS)]);

            return;
        }

        if ($status === 'sent') {
            $query->whereNotNull('sent_at')
                ->where(function ($builder) {
                    $builder->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                });

            return;
        }

        if ($status === 'ready') {
            $query->where('provisioning_status', DemoWorkspaceProvisioner::STATUS_READY)
                ->whereNull('sent_at')
                ->where(function ($builder) {
                    $builder->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now()->addDays(self::EXPIRING_SOON_DAYS));
                });
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<DemoWorkspace>  $query
     */
    private function applySalesStatusFilter($query, string $salesStatus): void
    {
        if ($salesStatus === '' || $salesStatus === 'all') {
            return;
        }

        $query->where('sales_status', $salesStatus);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function statusFilterOptions(): array
    {
        return [
            ['value' => 'all', 'label' => 'All'],
            ['value' => 'draft', 'label' => 'Draft'],
            ['value' => 'queued', 'label' => 'Queued'],
            ['value' => 'provisioning', 'label' => 'Provisioning'],
            ['value' => 'failed', 'label' => 'Failed'],
            ['value' => 'ready', 'label' => 'Ready'],
            ['value' => 'sent', 'label' => 'Sent'],
            ['value' => 'expires_soon', 'label' => 'Expires soon'],
            ['value' => 'expired', 'label' => 'Expired'],
            ['value' => 'purged', 'label' => 'Purged'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function templateDefaults(): array
    {
        $defaults = $this->catalog->defaults();

        return [
            'name' => '',
            'description' => '',
            'company_type' => $defaults['company_type'],
            'company_sector' => $defaults['company_sector'],
            'seed_profile' => $defaults['seed_profile'],
            'team_size' => $defaults['team_size'],
            'locale' => $defaults['locale'],
            'timezone' => $defaults['timezone'],
            'expiration_days' => 14,
            'selected_modules' => $defaults['selected_modules'],
            'scenario_packs' => $defaults['scenario_packs'],
            'branding_profile' => $defaults['branding_profile'],
            'extra_access_roles' => $defaults['extra_access_roles'],
            'suggested_flow' => $defaults['suggested_flow'],
            'is_default' => false,
            'is_active' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workspacePayload(DemoWorkspace $workspace, array $timeline = []): array
    {
        $modules = collect($workspace->selected_modules ?? []);
        $status = $workspace->lifecycleStatus(self::EXPIRING_SOON_DAYS);
        $statusMeta = $this->statusMeta($status);

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
            'locale' => $workspace->locale,
            'timezone' => $workspace->timezone,
            'desired_outcome' => $workspace->desired_outcome,
            'internal_notes' => $workspace->internal_notes,
            'selected_modules' => $modules->values()->all(),
            'module_labels' => $modules->map(fn (string $key) => $this->catalog->moduleLabel($key))->values()->all(),
            'scenario_packs' => $workspace->scenario_packs ?? [],
            'scenario_pack_details' => $this->catalog->scenarioPackDetails($workspace->scenario_packs ?? []),
            'scenario_pack_labels' => collect($workspace->scenario_packs ?? [])
                ->map(fn (string $key) => $this->catalog->scenarioPackLabel($key))
                ->values()
                ->all(),
            'branding_profile' => $workspace->branding_profile ?? [],
            'extra_access_roles' => $workspace->extra_access_roles ?? [],
            'extra_access_credentials' => $this->presentExtraAccessCredentials($workspace),
            'prefill_source' => $workspace->prefill_source,
            'prefill_payload' => $workspace->prefill_payload ?? [],
            'seed_summary' => $workspace->seed_summary ?? [],
            'access_email' => $workspace->access_email,
            'access_password' => $workspace->access_password,
            'suggested_flow' => $workspace->suggested_flow,
            'expires_at' => $workspace->expires_at?->toIso8601String(),
            'created_at' => $workspace->created_at?->toIso8601String(),
            'provisioned_at' => $workspace->provisioned_at?->toIso8601String(),
            'sent_at' => $workspace->sent_at?->toIso8601String(),
            'queued_at' => $workspace->queued_at?->toIso8601String(),
            'provisioning_status' => $workspace->provisioning_status ?? DemoWorkspaceProvisioner::STATUS_READY,
            'provisioning_progress' => (int) ($workspace->provisioning_progress ?? 100),
            'provisioning_stage' => $workspace->provisioning_stage,
            'provisioning_error' => $workspace->provisioning_error,
            'provisioning_started_at' => $workspace->provisioning_started_at?->toIso8601String(),
            'provisioning_finished_at' => $workspace->provisioning_finished_at?->toIso8601String(),
            'provisioning_failed_at' => $workspace->provisioning_failed_at?->toIso8601String(),
            'purged_at' => $workspace->purged_at?->toIso8601String(),
            'baseline_created_at' => $workspace->baseline_created_at?->toIso8601String(),
            'last_reset_at' => $workspace->last_reset_at?->toIso8601String(),
            'sales_status' => $workspace->sales_status ?? 'discovery',
            'sales_status_label' => $this->salesStatusLabel((string) ($workspace->sales_status ?? 'discovery')),
            'status' => $status,
            'status_label' => $statusMeta['label'],
            'status_tone' => $statusMeta['tone'],
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
            'sent_by' => $workspace->sentBy ? [
                'id' => $workspace->sentBy->id,
                'name' => $workspace->sentBy->name,
            ] : null,
            'last_reset_by' => $workspace->lastResetBy ? [
                'id' => $workspace->lastResetBy->id,
                'name' => $workspace->lastResetBy->name,
            ] : null,
            'template' => $workspace->template ? [
                'id' => $workspace->template->id,
                'name' => $workspace->template->name,
            ] : null,
            'cloned_from' => $workspace->clonedFrom ? [
                'id' => $workspace->clonedFrom->id,
                'company_name' => $workspace->clonedFrom->company_name,
            ] : null,
            'access_kit_text' => $this->accessKitText($workspace, $modules),
            'timeline' => $this->augmentLifecycleTimeline($workspace, $timeline),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function templatePayload(DemoWorkspaceTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'company_type' => $template->company_type,
            'company_sector' => $template->company_sector,
            'seed_profile' => $template->seed_profile,
            'team_size' => $template->team_size,
            'locale' => $template->locale,
            'timezone' => $template->timezone,
            'expiration_days' => $template->expiration_days,
            'selected_modules' => $template->selected_modules ?? [],
            'module_labels' => collect($template->selected_modules ?? [])
                ->map(fn (string $key) => $this->catalog->moduleLabel($key))
                ->values()
                ->all(),
            'scenario_packs' => $template->scenario_packs ?? [],
            'scenario_pack_details' => $this->catalog->scenarioPackDetails($template->scenario_packs ?? []),
            'scenario_pack_labels' => collect($template->scenario_packs ?? [])
                ->map(fn (string $key) => $this->catalog->scenarioPackLabel($key))
                ->values()
                ->all(),
            'branding_profile' => $template->branding_profile ?? [],
            'suggested_flow' => $template->suggested_flow,
            'is_active' => $template->is_active,
            'is_default' => $template->is_default,
            'created_at' => $template->created_at?->toIso8601String(),
            'creator' => $template->creator ? [
                'id' => $template->creator->id,
                'name' => $template->creator->name,
            ] : null,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $modules
     */
    private function accessKitText(DemoWorkspace $workspace, $modules): string
    {
        $moduleLabels = $modules
            ->map(fn (string $key) => $this->catalog->moduleLabel($key))
            ->implode(', ');
        $scenarioLabels = collect($workspace->scenario_packs ?? [])
            ->map(fn (string $key) => $this->catalog->scenarioPackLabel($key))
            ->implode(', ');
        $brandName = trim((string) data_get($workspace->branding_profile, 'name'));
        $extraAccess = collect($this->presentExtraAccessCredentials($workspace))
            ->map(function (array $credential) {
                $label = (string) ($credential['role_label'] ?? $credential['role_key'] ?? 'Extra access');
                $email = (string) ($credential['email'] ?? '');
                $password = (string) ($credential['password'] ?? '');

                if ($email === '' || $password === '') {
                    return null;
                }

                return sprintf('%s: %s / %s', $label, $email, $password);
            })
            ->filter()
            ->implode("\n");

        return implode("\n", array_filter([
            'Demo workspace: '.$workspace->company_name,
            $brandName !== '' ? 'Brand: '.$brandName : null,
            'Prospect: '.$workspace->prospect_name,
            'Login URL: '.url('/login'),
            $workspace->access_email ? 'Email: '.$workspace->access_email : null,
            $workspace->access_password ? 'Password: '.$workspace->access_password : null,
            $workspace->expires_at ? 'Expires: '.$workspace->expires_at->toFormattedDateString() : null,
            $workspace->template?->name ? 'Template: '.$workspace->template->name : null,
            $moduleLabels !== '' ? 'Modules: '.$moduleLabels : null,
            $scenarioLabels !== '' ? 'Scenario packs: '.$scenarioLabels : null,
            $extraAccess !== '' ? "Extra role logins:\n".$extraAccess : null,
            $workspace->suggested_flow ? "Suggested testing path:\n".$workspace->suggested_flow : null,
        ]));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function emailableExtraAccessCredentials(DemoWorkspace $workspace): array
    {
        return collect($this->presentExtraAccessCredentials($workspace))
            ->filter(function (array $credential) {
                return (bool) ($credential['is_active'] ?? false)
                    && trim((string) ($credential['email'] ?? '')) !== ''
                    && trim((string) ($credential['password'] ?? '')) !== '';
            })
            ->values()
            ->all();
    }

    private function absoluteLogoUrl(?string $logoUrl): ?string
    {
        $value = trim((string) $logoUrl);

        if ($value === '') {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://', 'data:', 'cid:'])) {
            return $value;
        }

        return url($value);
    }

    /**
     * @return array<string, string>
     */
    private function statusMeta(string $status): array
    {
        return match ($status) {
            'draft' => ['label' => 'Draft', 'tone' => 'stone'],
            'queued' => ['label' => 'Queued', 'tone' => 'blue'],
            'provisioning' => ['label' => 'Provisioning', 'tone' => 'blue'],
            'failed' => ['label' => 'Failed', 'tone' => 'rose'],
            'expired' => ['label' => 'Expired', 'tone' => 'rose'],
            'expires_soon' => ['label' => 'Expires soon', 'tone' => 'amber'],
            'sent' => ['label' => 'Sent', 'tone' => 'blue'],
            'purged' => ['label' => 'Purged', 'tone' => 'stone'],
            default => ['label' => 'Ready', 'tone' => 'emerald'],
        };
    }

    private function salesStatusLabel(string $status): string
    {
        $matched = collect(self::SALES_STATUS_OPTIONS)
            ->firstWhere('value', $status);

        return is_array($matched)
            ? (string) ($matched['label'] ?? $status)
            : ucfirst(str_replace('_', ' ', $status));
    }

    private function cloneDataModeLabel(string $mode): string
    {
        $matched = collect(self::CLONE_DATA_MODE_OPTIONS)
            ->firstWhere('value', $mode);

        return is_array($matched)
            ? Str::lower((string) ($matched['label'] ?? $mode))
            : Str::of($mode)->replace('_', ' ')->lower()->toString();
    }

    private function cloneDataModeDescription(string $mode): string
    {
        return match ($mode) {
            'keep_current_profile' => 'The same realism profile will be reused for the cloned setup.',
            default => 'Fresh sample data will be generated for the cloned setup.',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentExtraAccessCredentials(DemoWorkspace $workspace): array
    {
        $labels = collect($this->catalog->extraAccessRoles())
            ->mapWithKeys(fn (array $role) => [(string) $role['key'] => (string) ($role['label'] ?? $role['key'])])
            ->all();

        $credentialsByRole = collect($workspace->extra_access_credentials ?? [])
            ->filter(fn ($credential) => is_array($credential) && is_string($credential['role_key'] ?? null))
            ->mapWithKeys(fn (array $credential) => [(string) $credential['role_key'] => $credential])
            ->all();

        return collect($workspace->extra_access_roles ?? [])
            ->filter(fn ($roleKey) => is_string($roleKey) && trim($roleKey) !== '')
            ->merge(array_keys($credentialsByRole))
            ->unique()
            ->values()
            ->map(function (string $roleKey) use ($credentialsByRole, $labels) {
                $credential = is_array($credentialsByRole[$roleKey] ?? null)
                    ? $credentialsByRole[$roleKey]
                    : [];

                $email = trim((string) ($credential['email'] ?? ''));
                $password = trim((string) ($credential['password'] ?? ''));
                $status = trim((string) ($credential['status'] ?? ''));
                $isActive = array_key_exists('is_active', $credential)
                    ? (bool) $credential['is_active']
                    : ($status === 'active' || ($status === '' && $email !== '' && $password !== ''));

                if ($status === '') {
                    $status = $isActive
                        ? 'active'
                        : ($credential === [] ? 'pending' : 'revoked');
                }

                return [
                    'role_key' => $roleKey,
                    'role_label' => (string) ($credential['role_label'] ?? $labels[$roleKey] ?? Str::of($roleKey)->replace('_', ' ')->title()),
                    'name' => $credential['name'] ?? null,
                    'title' => $credential['title'] ?? null,
                    'email' => $email !== '' ? $email : null,
                    'password' => $password !== '' ? $password : null,
                    'login_url' => $credential['login_url'] ?? url('/login'),
                    'team_member_id' => isset($credential['team_member_id']) ? (int) $credential['team_member_id'] : null,
                    'user_id' => isset($credential['user_id']) ? (int) $credential['user_id'] : null,
                    'status' => $status,
                    'status_label' => match ($status) {
                        'active' => 'Active',
                        'pending' => 'Pending',
                        default => 'Revoked',
                    },
                    'is_active' => $isActive,
                ];
            })
            ->all();
    }

    private function extraAccessRoleLabel(string $roleKey): string
    {
        $matched = collect($this->catalog->extraAccessRoles())
            ->firstWhere('key', $roleKey);

        return is_array($matched)
            ? (string) ($matched['label'] ?? $roleKey)
            : Str::of($roleKey)->replace('_', ' ')->title()->toString();
    }

    private function ensureExtraAccessRoleIsManageable(DemoWorkspace $workspace, string $roleKey): void
    {
        if (in_array($roleKey, $this->catalog->extraAccessRoleKeys(), true) === false) {
            abort(404);
        }

        if (in_array($roleKey, $workspace->extra_access_roles ?? [], true) === false) {
            throw ValidationException::withMessages([
                'extra_access' => ['This extra role is not enabled for the selected demo.'],
            ]);
        }

        if ($workspace->owner_user_id === null) {
            throw ValidationException::withMessages([
                'extra_access' => ['Extra role logins are available only after the demo has been provisioned.'],
            ]);
        }
    }

    private function ensureWorkspaceIsNotPurged(DemoWorkspace $workspace): void
    {
        if ($workspace->trashed() === false) {
            return;
        }

        throw ValidationException::withMessages([
            'workspace' => ['This action is no longer available because the demo has already been purged.'],
        ]);
    }

    private function ensureWorkspaceHasProvisionedTenant(DemoWorkspace $workspace, string $message): void
    {
        if ($workspace->owner_user_id) {
            return;
        }

        throw ValidationException::withMessages([
            'workspace' => [$message],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $timeline
     * @return array<int, array<string, mixed>>
     */
    private function augmentLifecycleTimeline(DemoWorkspace $workspace, array $timeline): array
    {
        $events = collect($timeline);

        if (
            $workspace->isExpired()
            && $events->contains(fn (array $event) => ($event['action'] ?? null) === 'demo_workspace.expired') === false
        ) {
            $events->push([
                'id' => 'synthetic-expired-'.$workspace->id,
                'action' => 'demo_workspace.expired',
                'label' => 'Expired',
                'description' => 'Demo workspace reached its expiration date.',
                'created_at' => $workspace->expires_at?->toIso8601String(),
                'actor' => null,
                'properties' => [
                    'expires_at' => $workspace->expires_at?->toIso8601String(),
                    'synthetic' => true,
                ],
            ]);
        }

        return $events
            ->sortByDesc(fn (array $event) => (string) ($event['created_at'] ?? ''))
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function indexQuery(Request $request): array
    {
        $status = trim((string) $request->query('status', ''));
        $salesStatus = trim((string) $request->query('sales_status', ''));
        $query = [];

        if ($status !== '') {
            $query['status'] = $status;
        }

        if ($salesStatus !== '') {
            $query['sales_status'] = $salesStatus;
        }

        $page = (int) $request->query('page', 1);

        if ($page > 1) {
            $query['page'] = (string) $page;
        }

        return $query;
    }

    /**
     * @return array<string, string>
     */
    private function returnQuery(Request $request): array
    {
        $status = trim((string) $request->input('return_status', $request->query('status', '')));
        $salesStatus = trim((string) $request->input('return_sales_status', $request->query('sales_status', '')));
        $page = (int) $request->input('return_page', $request->query('page', 1));
        $perPage = $this->resolveDataTablePerPage($request->input('return_per_page', $request->query('per_page')));
        $query = [];

        if ($status !== '') {
            $query['status'] = $status;
        }

        if ($salesStatus !== '') {
            $query['sales_status'] = $salesStatus;
        }

        if ($page > 1) {
            $query['page'] = (string) $page;
        }

        if ($perPage !== $this->defaultDataTablePerPage()) {
            $query['per_page'] = (string) $perPage;
        }

        return $query;
    }

    /**
     * @return array<int, mixed>
     */
    private function redirectAfterWorkspaceAction(Request $request, DemoWorkspace $workspace): array
    {
        $query = $this->returnQuery($request);

        if ((string) $request->input('redirect_to') === 'show') {
            return [
                'superadmin.demo-workspaces.show',
                [
                    'demoWorkspace' => $workspace,
                    ...$query,
                ],
            ];
        }

        return ['superadmin.demo-workspaces.index', $query];
    }

    /**
     * @return array<string, mixed>
     */
    private function provisioningRouteParameters(Request $request, DemoWorkspace $workspace): array
    {
        return [
            'demoWorkspace' => $workspace,
            ...$this->returnQuery($request),
        ];
    }

    private function dispatchProvisioningJob(DemoWorkspace $workspace, int $actorUserId, bool $isReset = false): void
    {
        if ((bool) config('async.workloads.demos.run_inline', false)) {
            ProvisionDemoWorkspaceJob::dispatchSync($workspace->id, $actorUserId, $isReset);

            return;
        }

        ProvisionDemoWorkspaceJob::dispatch($workspace->id, $actorUserId, $isReset);
    }

    /**
     * @param  Collection<int, int>  $workspaceIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function timelineByWorkspace(Collection $workspaceIds): array
    {
        if ($workspaceIds->isEmpty()) {
            return [];
        }

        $logs = ActivityLog::query()
            ->where('subject_type', (new DemoWorkspace)->getMorphClass())
            ->whereIn('subject_id', $workspaceIds->all())
            ->with('user:id,name')
            ->latest()
            ->get()
            ->groupBy('subject_id')
            ->map(fn (Collection $group) => app(DemoWorkspaceTimelineService::class)->presentCollection($group->take(6)))
            ->all();

        return $logs;
    }
}
