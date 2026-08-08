<?php

namespace App\Services\Demo;

use App\Enums\PromotionDiscountType;
use App\Enums\PromotionStatus;
use App\Enums\PromotionTargetType;
use App\Models\AccountingEntry;
use App\Models\AccountingEntryBatch;
use App\Models\AccountingExport;
use App\Models\AccountingPeriod;
use App\Models\AvailabilityException;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\CustomerPackageUsage;
use App\Models\DemoWorkspace;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LoyaltyProgram;
use App\Models\MailingList;
use App\Models\OfferPackage;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Promotion;
use App\Models\PublicBookingLink;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Models\ReservationResource;
use App\Models\ReservationSetting;
use App\Models\ReservationWaitlist;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServiceRequest;
use App\Models\SocialPost;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\TeamMemberShift;
use App\Models\User;
use App\Models\VipTier;
use App\Models\WeeklyAvailability;
use App\Models\Work;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiKnowledgeItem;
use App\Services\AccountDeletionService;
use App\Services\Accounting\AccountingPeriodService;
use App\Services\Accounting\AccountingSyncService;
use App\Services\Campaigns\MarketingSettingsService;
use App\Services\OfferPackages\OfferPackageSalesLineBuilder;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationQueueCheckoutService;
use App\Services\ReservationQueueService;
use App\Support\CampaignTemplateLanguage;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoWorkspaceProvisioner
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private DemoWorkspaceCatalog $catalog,
        private MarketingSettingsService $marketingSettingsService,
        private AccountDeletionService $accountDeletionService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, User $admin): DemoWorkspace
    {
        $payload = $this->normalizePayload($payload);
        $expiresAt = Carbon::parse((string) $payload['expires_at'])->endOfDay();

        return DB::transaction(function () use ($payload, $admin, $expiresAt) {
            $credentials = $this->resolveCredentials(null, (string) $payload['company_name']);
            $owner = $this->createOwner($payload, $credentials, $expiresAt);
            $workspace = $this->persistWorkspaceRecord(
                new DemoWorkspace,
                $payload,
                $admin,
                $owner,
                $credentials,
                $expiresAt,
                true
            );

            $summary = $this->seedEnvironment($owner, $workspace);
            $extraAccessCredentials = $this->buildExtraAccessCredentials(
                $owner,
                $workspace->extra_access_roles ?? []
            );

            return $this->finalizeProvisionedWorkspace($workspace, $summary, [
                'extra_access_credentials' => $extraAccessCredentials,
            ]);
        });
    }

    public function updateExpiration(DemoWorkspace $workspace, Carbon $expiresAt): DemoWorkspace
    {
        $workspace->forceFill([
            'expires_at' => $expiresAt->copy()->endOfDay(),
        ])->save();

        if ($workspace->owner) {
            $workspace->owner->forceFill([
                'trial_ends_at' => $workspace->expires_at,
            ])->save();
        }

        return $workspace->fresh(['owner', 'creator']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function queueCreate(array $payload, User $admin): DemoWorkspace
    {
        $payload = $this->normalizePayload($payload);

        return $this->prepareQueuedWorkspace(new DemoWorkspace, $payload, $admin);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveDraft(array $payload, User $admin): DemoWorkspace
    {
        $payload = $this->normalizePayload($payload);

        return $this->prepareDraftWorkspace(new DemoWorkspace, $payload, $admin);
    }

    public function queueDraft(DemoWorkspace $workspace, User $admin): DemoWorkspace
    {
        $payload = $this->normalizePayload($this->workspaceSnapshotPayload($workspace));

        return $this->prepareQueuedWorkspace($workspace, $payload, $admin);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function clone(DemoWorkspace $workspace, array $overrides, User $admin): DemoWorkspace
    {
        $cloneDataMode = (string) ($overrides['clone_data_mode'] ?? 'keep_current_profile');
        $basePayload = $this->workspaceSnapshotPayload($workspace);
        $days = $workspace->expires_at && $workspace->expires_at->isFuture()
            ? max(1, (int) now()->diffInDays($workspace->expires_at, true) + 1)
            : 14;

        $payload = array_replace_recursive($basePayload, $overrides, [
            'company_name' => trim((string) ($overrides['company_name'] ?? ($workspace->company_name.' Copy'))),
            'seed_profile' => $cloneDataMode === 'keep_current_profile'
                ? (string) $workspace->seed_profile
                : (string) ($overrides['seed_profile'] ?? $workspace->seed_profile),
            'expires_at' => (string) ($overrides['expires_at'] ?? now()->addDays($days)->toDateString()),
            'cloned_from_demo_workspace_id' => $workspace->id,
        ]);

        $notes = trim((string) ($payload['internal_notes'] ?? ''));
        $payload['internal_notes'] = trim(implode("\n", array_filter([
            $notes,
            'Cloned from demo workspace #'.$workspace->id.'.',
            $cloneDataMode === 'keep_current_profile'
                ? 'Clone mode: keep current realism profile.'
                : 'Clone mode: regenerate fresh sample data.',
        ])));

        return $this->create($payload, $admin);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function queueClone(DemoWorkspace $workspace, array $overrides, User $admin): DemoWorkspace
    {
        $cloneDataMode = (string) ($overrides['clone_data_mode'] ?? 'keep_current_profile');
        $basePayload = $this->workspaceSnapshotPayload($workspace);
        $days = $workspace->expires_at && $workspace->expires_at->isFuture()
            ? max(1, (int) now()->diffInDays($workspace->expires_at, true) + 1)
            : 14;

        $payload = array_replace_recursive($basePayload, $overrides, [
            'company_name' => trim((string) ($overrides['company_name'] ?? ($workspace->company_name.' Copy'))),
            'seed_profile' => $cloneDataMode === 'keep_current_profile'
                ? (string) $workspace->seed_profile
                : (string) ($overrides['seed_profile'] ?? $workspace->seed_profile),
            'expires_at' => (string) ($overrides['expires_at'] ?? now()->addDays($days)->toDateString()),
            'cloned_from_demo_workspace_id' => $workspace->id,
            'prefill_source' => 'clone',
            'prefill_payload' => [
                'source_demo_workspace_id' => $workspace->id,
                'clone_data_mode' => $cloneDataMode,
                'source_seed_profile' => $workspace->seed_profile,
                'target_seed_profile' => $cloneDataMode === 'keep_current_profile'
                    ? $workspace->seed_profile
                    : (string) ($overrides['seed_profile'] ?? $workspace->seed_profile),
            ],
        ]);

        $notes = trim((string) ($payload['internal_notes'] ?? ''));
        $payload['internal_notes'] = trim(implode("\n", array_filter([
            $notes,
            'Cloned from demo workspace #'.$workspace->id.'.',
            $cloneDataMode === 'keep_current_profile'
                ? 'Clone mode: keep current realism profile.'
                : 'Clone mode: regenerate fresh sample data.',
        ])));

        return $this->prepareQueuedWorkspace(new DemoWorkspace, $this->normalizePayload($payload), $admin);
    }

    public function saveBaseline(DemoWorkspace $workspace): DemoWorkspace
    {
        $workspace->forceFill([
            'baseline_snapshot' => $this->normalizePayload($this->workspaceSnapshotPayload($workspace)),
            'baseline_created_at' => now(),
        ])->save();

        return $workspace->fresh(['owner', 'creator', 'template', 'sentBy', 'clonedFrom', 'lastResetBy']);
    }

    public function queueResetToBaseline(DemoWorkspace $workspace, User $admin): DemoWorkspace
    {
        $snapshot = is_array($workspace->baseline_snapshot) && $workspace->baseline_snapshot !== []
            ? $workspace->baseline_snapshot
            : $this->workspaceSnapshotPayload($workspace);

        $payload = $this->normalizePayload(array_replace_recursive($snapshot, [
            'expires_at' => $workspace->expires_at?->toDateString() ?? now()->addDays(14)->toDateString(),
            'demo_workspace_template_id' => $workspace->demo_workspace_template_id,
            'cloned_from_demo_workspace_id' => $workspace->cloned_from_demo_workspace_id,
            'prefill_source' => $workspace->prefill_source,
            'prefill_payload' => $workspace->prefill_payload ?? [],
            'extra_access_roles' => $workspace->extra_access_roles ?? [],
        ]));

        return $this->prepareQueuedWorkspace($workspace, $payload, $admin, true);
    }

    public function resetToBaseline(DemoWorkspace $workspace, User $admin): DemoWorkspace
    {
        $snapshot = is_array($workspace->baseline_snapshot) && $workspace->baseline_snapshot !== []
            ? $workspace->baseline_snapshot
            : $this->workspaceSnapshotPayload($workspace);

        $payload = $this->normalizePayload(array_replace_recursive($snapshot, [
            'expires_at' => $workspace->expires_at?->toDateString() ?? now()->addDays(14)->toDateString(),
            'demo_workspace_template_id' => $workspace->demo_workspace_template_id,
            'cloned_from_demo_workspace_id' => $workspace->cloned_from_demo_workspace_id,
        ]));
        $expiresAt = Carbon::parse((string) $payload['expires_at'])->endOfDay();

        return DB::transaction(function () use ($workspace, $payload, $admin, $expiresAt) {
            $previousOwner = $workspace->owner()->first();
            $preferredCredentials = [
                'email' => (string) ($workspace->access_email ?? ''),
                'password' => (string) ($workspace->access_password ?? ''),
            ];

            if ($previousOwner) {
                $workspace->forceFill([
                    'owner_user_id' => null,
                ])->save();

                $this->accountDeletionService->deleteAccount($previousOwner);
            }

            $credentials = $this->resolveCredentials($preferredCredentials, (string) $payload['company_name']);
            $owner = $this->createOwner($payload, $credentials, $expiresAt);
            $workspace = $this->persistWorkspaceRecord(
                $workspace,
                $payload,
                $admin,
                $owner,
                $credentials,
                $expiresAt,
                false
            );

            $summary = $this->seedEnvironment($owner, $workspace);
            $extraAccessCredentials = $this->buildExtraAccessCredentials(
                $owner,
                $workspace->extra_access_roles ?? []
            );

            return $this->finalizeProvisionedWorkspace($workspace, $summary, [
                'extra_access_credentials' => $extraAccessCredentials,
                'last_reset_at' => now(),
                'last_reset_by_user_id' => $admin->id,
            ]);
        });
    }

    public function provisionQueuedWorkspace(DemoWorkspace $workspace, User $admin, bool $isReset = false): DemoWorkspace
    {
        $payload = $this->normalizePayload($this->workspaceSnapshotPayload($workspace));
        $expiresAt = Carbon::parse((string) $payload['expires_at'])->endOfDay();

        $workspace = $this->updateProvisioningState(
            $workspace,
            self::STATUS_PROVISIONING,
            15,
            $isReset ? 'Resetting tenant access' : 'Creating tenant access',
            null,
            [
                'provisioning_started_at' => now(),
                'provisioning_failed_at' => null,
            ]
        );

        $preferredCredentials = $isReset
            ? [
                'email' => (string) ($workspace->access_email ?? ''),
                'password' => (string) ($workspace->access_password ?? ''),
            ]
            : null;

        [$workspace, $owner] = DB::transaction(function () use ($workspace, $payload, $admin, $expiresAt, $isReset, $preferredCredentials) {
            $workingWorkspace = $workspace;

            if ($isReset) {
                $previousOwner = $workingWorkspace->owner()->first();

                if ($previousOwner) {
                    $workingWorkspace->forceFill([
                        'owner_user_id' => null,
                    ])->save();

                    $this->accountDeletionService->deleteAccount($previousOwner);
                }
            }

            $credentials = $this->resolveCredentials($preferredCredentials, (string) $payload['company_name']);
            $owner = $this->createOwner($payload, $credentials, $expiresAt);
            $workspace = $this->persistWorkspaceRecord(
                $workspace,
                $payload,
                $admin,
                $owner,
                $credentials,
                $expiresAt,
                ! $isReset
            );

            return [$workingWorkspace, $owner];
        });

        $workspace = $this->updateProvisioningState(
            $workspace,
            self::STATUS_PROVISIONING,
            60,
            'Generating realistic sample data'
        );

        [$summary, $extraAccessCredentials] = DB::transaction(function () use ($owner, $workspace) {
            $summary = $this->seedEnvironment($owner, $workspace);
            $extraAccessCredentials = $this->buildExtraAccessCredentials(
                $owner,
                $workspace->extra_access_roles ?? []
            );

            return [$summary, $extraAccessCredentials];
        });

        $workspace = $this->updateProvisioningState(
            $workspace,
            self::STATUS_PROVISIONING,
            85,
            'Finalizing access kit'
        );

        return $this->finalizeProvisionedWorkspace($workspace, $summary, [
            'extra_access_credentials' => $extraAccessCredentials,
            'last_reset_at' => $isReset ? now() : $workspace->last_reset_at,
            'last_reset_by_user_id' => $isReset ? $admin->id : $workspace->last_reset_by_user_id,
        ]);
    }

    public function markProvisioningFailed(DemoWorkspace $workspace, \Throwable|string $error): DemoWorkspace
    {
        $message = $error instanceof \Throwable
            ? trim((string) $error->getMessage())
            : trim((string) $error);

        return $this->updateProvisioningState(
            $workspace,
            self::STATUS_FAILED,
            100,
            'Provisioning failed',
            $message !== '' ? $message : 'Unknown provisioning error.',
            [
                'provisioning_failed_at' => now(),
            ]
        );
    }

    public function revokeExtraAccess(DemoWorkspace $workspace, string $roleKey): DemoWorkspace
    {
        return DB::transaction(function () use ($workspace, $roleKey) {
            $resolved = $this->resolveExtraAccessAssignment($workspace, $roleKey);

            if ($resolved['team_member']) {
                $resolved['team_member']->forceFill([
                    'is_active' => false,
                ])->save();
            }

            if ($resolved['user']) {
                $resolved['user']->forceFill([
                    'password' => Hash::make(Str::random(40)),
                    'remember_token' => Str::random(60),
                ])->save();
                $resolved['user']->tokens()->delete();
            }

            $workspace->forceFill([
                'extra_access_credentials' => $this->upsertExtraAccessCredential(
                    $workspace,
                    $roleKey,
                    [
                        'role_label' => $resolved['role_label'],
                        'team_member_id' => $resolved['team_member']?->id,
                        'user_id' => $resolved['user']?->id,
                        'name' => $resolved['user']?->name ?? ($resolved['credential']['name'] ?? null),
                        'title' => $resolved['team_member']?->title ?? ($resolved['credential']['title'] ?? null),
                        'email' => $resolved['user']?->email ?? ($resolved['credential']['email'] ?? null),
                        'password' => null,
                        'login_url' => url('/login'),
                        'status' => 'revoked',
                        'is_active' => false,
                    ]
                ),
            ])->save();

            return $workspace->fresh(['owner', 'creator', 'template', 'sentBy', 'clonedFrom', 'lastResetBy']);
        });
    }

    public function regenerateExtraAccess(DemoWorkspace $workspace, string $roleKey): DemoWorkspace
    {
        return DB::transaction(function () use ($workspace, $roleKey) {
            $resolved = $this->resolveExtraAccessAssignment($workspace, $roleKey, true);
            $teamMember = $resolved['team_member'];
            $user = $resolved['user'];

            if (! $teamMember || ! $user) {
                throw new \RuntimeException('No matching team member could be found for this extra access role.');
            }

            $password = $this->generateExtraAccessPassword();

            $teamMember->forceFill([
                'is_active' => true,
            ])->save();

            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();
            $user->tokens()->delete();

            $workspace->forceFill([
                'extra_access_credentials' => $this->upsertExtraAccessCredential(
                    $workspace,
                    $roleKey,
                    [
                        'role_label' => $resolved['role_label'],
                        'team_member_id' => $teamMember->id,
                        'user_id' => $user->id,
                        'name' => $user->name,
                        'title' => $teamMember->title,
                        'email' => $user->email,
                        'password' => $password,
                        'login_url' => url('/login'),
                        'status' => 'active',
                        'is_active' => true,
                    ]
                ),
            ])->save();

            return $workspace->fresh(['owner', 'creator', 'template', 'sentBy', 'clonedFrom', 'lastResetBy']);
        });
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @return array<int, string>
     */
    private function normalizeModules(array $selectedModules): array
    {
        $valid = array_fill_keys($this->catalog->moduleKeys(), true);

        return collect($selectedModules)
            ->filter(fn ($value) => is_string($value) && isset($valid[$value]))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $selectedModules
     */
    private function normalizeSuggestedFlow(
        mixed $suggestedFlow,
        string $companyType,
        ?string $companySector,
        array $selectedModules,
        array $scenarioPacks = []
    ): string {
        $value = trim((string) ($suggestedFlow ?? ''));

        if ($value !== '') {
            return $value;
        }

        $scenarioFlow = $this->catalog->suggestedFlowFromScenarioPacks($scenarioPacks);
        if ($scenarioFlow !== '') {
            return $scenarioFlow;
        }

        return $this->catalog->suggestedFlow($companyType, $companySector, $selectedModules);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $payload['selected_modules'] = $this->normalizeModules($payload['selected_modules'] ?? []);

        $companyType = (string) ($payload['company_type'] ?? 'services');
        $companySector = $payload['company_sector'] ? (string) $payload['company_sector'] : null;
        $companyName = trim((string) ($payload['company_name'] ?? ''));
        $prospectEmail = $payload['prospect_email'] ? (string) $payload['prospect_email'] : null;

        $payload['scenario_packs'] = $this->normalizeScenarioPacks(
            $payload['scenario_packs'] ?? [],
            $companyType,
            $companySector,
            $payload['selected_modules']
        );
        $payload['branding_profile'] = $this->normalizeBrandingProfile(
            $payload['branding_profile'] ?? [],
            $companyName,
            $companyType,
            $companySector,
            $prospectEmail
        );
        $payload['extra_access_roles'] = $this->normalizeExtraAccessRoles(
            $payload['extra_access_roles'] ?? [],
            $companyType,
            $companySector
        );
        $payload['prefill_source'] = trim((string) ($payload['prefill_source'] ?? ''));
        $payload['prefill_payload'] = is_array($payload['prefill_payload'] ?? null)
            ? $payload['prefill_payload']
            : [];
        $payload['suggested_flow'] = $this->normalizeSuggestedFlow(
            $payload['suggested_flow'] ?? null,
            $companyType,
            $companySector,
            $payload['selected_modules'],
            $payload['scenario_packs']
        );

        return $payload;
    }

    /**
     * @param  array<int, string>  $extraAccessRoles
     * @return array<int, string>
     */
    private function normalizeExtraAccessRoles(
        array $extraAccessRoles,
        string $companyType,
        ?string $companySector
    ): array {
        $valid = array_fill_keys($this->catalog->extraAccessRoleKeys(), true);

        $normalized = collect($extraAccessRoles)
            ->filter(fn ($value) => is_string($value) && isset($valid[$value]))
            ->unique()
            ->values()
            ->all();

        return $normalized !== []
            ? $normalized
            : $this->catalog->defaultExtraAccessRoles($companyType, $companySector);
    }

    /**
     * @param  array<int, string>  $scenarioPacks
     * @param  array<int, string>  $selectedModules
     * @return array<int, string>
     */
    private function normalizeScenarioPacks(
        array $scenarioPacks,
        string $companyType,
        ?string $companySector,
        array $selectedModules
    ): array {
        $valid = array_fill_keys($this->catalog->scenarioPackKeys(), true);

        $normalized = collect($scenarioPacks)
            ->filter(fn ($value) => is_string($value) && isset($valid[$value]))
            ->unique()
            ->values()
            ->all();

        if ($normalized === []) {
            return $this->catalog->defaultScenarioPacks($companyType, $companySector, $selectedModules);
        }

        $compatible = collect($this->catalog->scenarioPackDetails($normalized))
            ->filter(function (array $pack) use ($companyType, $companySector, $selectedModules) {
                if (! in_array($companyType, $pack['company_types'] ?? [], true)) {
                    return false;
                }

                $sectors = $pack['sectors'] ?? [];
                if ($sectors !== [] && ! in_array($companySector, $sectors, true)) {
                    return false;
                }

                return collect($pack['required_modules'] ?? [])
                    ->every(fn (string $moduleKey) => in_array($moduleKey, $selectedModules, true));
            })
            ->pluck('key')
            ->values()
            ->all();

        return $compatible !== []
            ? $compatible
            : $this->catalog->defaultScenarioPacks($companyType, $companySector, $selectedModules);
    }

    /**
     * @param  array<string, mixed>  $brandingProfile
     * @return array<string, mixed>
     */
    private function normalizeBrandingProfile(
        array $brandingProfile,
        string $companyName,
        string $companyType,
        ?string $companySector,
        ?string $prospectEmail
    ): array {
        $defaults = $this->catalog->brandingProfileDefaults($companyType, $companySector, $companyName);
        $allowed = Arr::only($brandingProfile, array_keys($defaults));

        foreach ([
            'primary_color',
            'secondary_color',
            'accent_color',
            'surface_color',
            'hero_background_color',
            'footer_background_color',
            'text_color',
            'muted_color',
        ] as $colorKey) {
            $candidate = strtoupper(trim((string) ($allowed[$colorKey] ?? '')));
            if ($candidate === '' || preg_match('/^#[0-9A-F]{6}$/', $candidate) !== 1) {
                unset($allowed[$colorKey]);

                continue;
            }

            $allowed[$colorKey] = $candidate;
        }

        $profile = array_replace($defaults, $allowed);
        $profile['name'] = trim((string) ($profile['name'] ?? '')) !== ''
            ? trim((string) $profile['name'])
            : $companyName;
        $profile['logo_url'] = trim((string) ($profile['logo_url'] ?? '')) !== ''
            ? trim((string) $profile['logo_url'])
            : trim((string) ($defaults['logo_url'] ?? ''));
        $profile['contact_email'] = trim((string) ($profile['contact_email'] ?? '')) !== ''
            ? trim((string) $profile['contact_email'])
            : $prospectEmail;

        return $profile;
    }

    /**
     * @param  array<string, string>|null  $preferred
     * @return array<string, string>
     */
    private function resolveCredentials(?array $preferred, string $companyName): array
    {
        $email = trim((string) ($preferred['email'] ?? ''));
        $password = trim((string) ($preferred['password'] ?? ''));

        if ($email !== '' && $password !== '') {
            return [
                'email' => $email,
                'password' => $password,
            ];
        }

        return $this->generateCredentials($companyName);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $credentials
     */
    private function persistWorkspaceRecord(
        DemoWorkspace $workspace,
        array $payload,
        User $admin,
        User $owner,
        array $credentials,
        Carbon $expiresAt,
        bool $refreshBaseline
    ): DemoWorkspace {
        $workspace->forceFill([
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $workspace->created_by_user_id ?: $admin->id,
            'demo_workspace_template_id' => $payload['demo_workspace_template_id'] ?? null,
            'cloned_from_demo_workspace_id' => $payload['cloned_from_demo_workspace_id'] ?? $workspace->cloned_from_demo_workspace_id,
            'prospect_name' => (string) $payload['prospect_name'],
            'prospect_email' => $payload['prospect_email'] ?: null,
            'prospect_company' => $payload['prospect_company'] ?: null,
            'company_name' => (string) $payload['company_name'],
            'company_type' => (string) $payload['company_type'],
            'company_sector' => $payload['company_sector'] ?: null,
            'seed_profile' => (string) $payload['seed_profile'],
            'team_size' => (int) $payload['team_size'],
            'locale' => (string) $payload['locale'],
            'timezone' => (string) $payload['timezone'],
            'desired_outcome' => $payload['desired_outcome'] ?: null,
            'internal_notes' => $payload['internal_notes'] ?: null,
            'suggested_flow' => (string) $payload['suggested_flow'],
            'selected_modules' => $payload['selected_modules'],
            'scenario_packs' => $payload['scenario_packs'],
            'branding_profile' => $payload['branding_profile'],
            'prefill_source' => $payload['prefill_source'] !== '' ? $payload['prefill_source'] : null,
            'prefill_payload' => $payload['prefill_payload'],
            'extra_access_roles' => $payload['extra_access_roles'],
            'configuration' => [
                'profile_counts' => $this->catalog->seedCounts((string) $payload['seed_profile']),
                'module_labels' => collect($payload['selected_modules'])
                    ->mapWithKeys(fn (string $key) => [$key => $this->catalog->moduleLabel($key)])
                    ->all(),
                'scenario_pack_labels' => collect($payload['scenario_packs'])
                    ->mapWithKeys(fn (string $key) => [$key => $this->catalog->scenarioPackLabel($key)])
                    ->all(),
                'extra_access_labels' => collect($payload['extra_access_roles'])
                    ->mapWithKeys(function (string $key) {
                        $matched = collect($this->catalog->extraAccessRoles())
                            ->firstWhere('key', $key);
                        $label = is_array($matched)
                            ? (string) ($matched['label'] ?? $key)
                            : $key;

                        return [$key => $label];
                    })
                    ->all(),
            ],
            'access_email' => $credentials['email'],
            'access_password' => $credentials['password'],
            'expires_at' => $expiresAt,
            'provisioned_at' => now(),
            'last_seeded_at' => now(),
        ]);

        if ($refreshBaseline || ! is_array($workspace->baseline_snapshot) || $workspace->baseline_snapshot === []) {
            $workspace->baseline_snapshot = $this->buildBaselineSnapshot($payload);
            $workspace->baseline_created_at = now();
        }

        $workspace->save();
        $this->applyBrandingProfile($owner, $payload['branding_profile']);

        return $workspace;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function prepareQueuedWorkspace(
        DemoWorkspace $workspace,
        array $payload,
        User $admin,
        bool $isReset = false
    ): DemoWorkspace {
        $expiresAt = Carbon::parse((string) $payload['expires_at'])->endOfDay();
        $workspace->forceFill([
            'created_by_user_id' => $workspace->created_by_user_id ?: $admin->id,
            'demo_workspace_template_id' => $payload['demo_workspace_template_id'] ?? null,
            'cloned_from_demo_workspace_id' => $payload['cloned_from_demo_workspace_id'] ?? $workspace->cloned_from_demo_workspace_id,
            'prospect_name' => (string) $payload['prospect_name'],
            'prospect_email' => $payload['prospect_email'] ?: null,
            'prospect_company' => $payload['prospect_company'] ?: null,
            'company_name' => (string) $payload['company_name'],
            'company_type' => (string) $payload['company_type'],
            'company_sector' => $payload['company_sector'] ?: null,
            'seed_profile' => (string) $payload['seed_profile'],
            'team_size' => (int) $payload['team_size'],
            'locale' => (string) $payload['locale'],
            'timezone' => (string) $payload['timezone'],
            'desired_outcome' => $payload['desired_outcome'] ?: null,
            'internal_notes' => $payload['internal_notes'] ?: null,
            'suggested_flow' => (string) $payload['suggested_flow'],
            'selected_modules' => $payload['selected_modules'],
            'scenario_packs' => $payload['scenario_packs'],
            'branding_profile' => $payload['branding_profile'],
            'prefill_source' => $payload['prefill_source'] !== '' ? $payload['prefill_source'] : null,
            'prefill_payload' => $payload['prefill_payload'],
            'extra_access_roles' => $payload['extra_access_roles'],
            'expires_at' => $expiresAt,
            'baseline_snapshot' => $this->buildBaselineSnapshot($payload),
            'baseline_created_at' => $workspace->baseline_created_at ?? now(),
            'provisioning_status' => self::STATUS_QUEUED,
            'provisioning_progress' => 5,
            'provisioning_stage' => $isReset ? 'Queued for baseline reset' : 'Queued for provisioning',
            'provisioning_error' => null,
            'queued_at' => now(),
            'provisioning_started_at' => null,
            'provisioning_finished_at' => null,
            'provisioning_failed_at' => null,
            'purged_at' => null,
        ])->save();

        return $workspace->fresh(['owner', 'creator', 'template', 'sentBy', 'clonedFrom', 'lastResetBy']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function prepareDraftWorkspace(
        DemoWorkspace $workspace,
        array $payload,
        User $admin
    ): DemoWorkspace {
        $expiresAt = Carbon::parse((string) $payload['expires_at'])->endOfDay();

        $workspace->forceFill([
            'created_by_user_id' => $workspace->created_by_user_id ?: $admin->id,
            'demo_workspace_template_id' => $payload['demo_workspace_template_id'] ?? null,
            'cloned_from_demo_workspace_id' => $payload['cloned_from_demo_workspace_id'] ?? $workspace->cloned_from_demo_workspace_id,
            'prospect_name' => (string) $payload['prospect_name'],
            'prospect_email' => $payload['prospect_email'] ?: null,
            'prospect_company' => $payload['prospect_company'] ?: null,
            'company_name' => (string) $payload['company_name'],
            'company_type' => (string) $payload['company_type'],
            'company_sector' => $payload['company_sector'] ?: null,
            'seed_profile' => (string) $payload['seed_profile'],
            'team_size' => (int) $payload['team_size'],
            'locale' => (string) $payload['locale'],
            'timezone' => (string) $payload['timezone'],
            'desired_outcome' => $payload['desired_outcome'] ?: null,
            'internal_notes' => $payload['internal_notes'] ?: null,
            'suggested_flow' => (string) $payload['suggested_flow'],
            'selected_modules' => $payload['selected_modules'],
            'scenario_packs' => $payload['scenario_packs'],
            'branding_profile' => $payload['branding_profile'],
            'prefill_source' => $payload['prefill_source'] !== '' ? $payload['prefill_source'] : null,
            'prefill_payload' => $payload['prefill_payload'],
            'extra_access_roles' => $payload['extra_access_roles'],
            'expires_at' => $expiresAt,
            'baseline_snapshot' => $this->buildBaselineSnapshot($payload),
            'baseline_created_at' => $workspace->baseline_created_at ?? now(),
            'owner_user_id' => null,
            'access_email' => null,
            'access_password' => null,
            'extra_access_credentials' => [],
            'seed_summary' => null,
            'provisioned_at' => null,
            'last_seeded_at' => null,
            'sent_at' => null,
            'sent_by_user_id' => null,
            'provisioning_status' => self::STATUS_DRAFT,
            'provisioning_progress' => 0,
            'provisioning_stage' => 'Draft saved',
            'provisioning_error' => null,
            'queued_at' => null,
            'provisioning_started_at' => null,
            'provisioning_finished_at' => null,
            'provisioning_failed_at' => null,
            'purged_at' => null,
        ])->save();

        return $workspace->fresh(['owner', 'creator', 'template', 'sentBy', 'clonedFrom', 'lastResetBy']);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function updateProvisioningState(
        DemoWorkspace $workspace,
        string $status,
        int $progress,
        ?string $stage = null,
        ?string $error = null,
        array $extra = []
    ): DemoWorkspace {
        $workspace->forceFill([
            'provisioning_status' => $status,
            'provisioning_progress' => max(0, min(100, $progress)),
            'provisioning_stage' => $stage,
            'provisioning_error' => $error,
            ...$extra,
        ])->save();

        return $workspace->fresh(['owner', 'creator', 'template', 'sentBy', 'clonedFrom', 'lastResetBy']);
    }

    /**
     * @param  array<string, int>  $summary
     * @param  array<string, mixed>  $extra
     */
    private function finalizeProvisionedWorkspace(
        DemoWorkspace $workspace,
        array $summary,
        array $extra = []
    ): DemoWorkspace {
        $workspace->forceFill([
            'provisioning_status' => self::STATUS_READY,
            'provisioning_progress' => 100,
            'provisioning_stage' => 'Ready',
            'provisioning_error' => null,
            'provisioning_finished_at' => now(),
            'seed_summary' => $summary,
            'provisioned_at' => now(),
            'last_seeded_at' => now(),
            ...$extra,
        ])->save();

        return $workspace->fresh(['owner', 'creator', 'template', 'sentBy', 'clonedFrom', 'lastResetBy']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildBaselineSnapshot(array $payload): array
    {
        return Arr::only($payload, [
            'demo_workspace_template_id',
            'prospect_name',
            'prospect_email',
            'prospect_company',
            'company_name',
            'company_type',
            'company_sector',
            'seed_profile',
            'team_size',
            'locale',
            'timezone',
            'desired_outcome',
            'internal_notes',
            'suggested_flow',
            'selected_modules',
            'scenario_packs',
            'branding_profile',
            'extra_access_roles',
            'prefill_source',
            'prefill_payload',
            'expires_at',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceSnapshotPayload(DemoWorkspace $workspace): array
    {
        return [
            'demo_workspace_template_id' => $workspace->demo_workspace_template_id,
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
            'suggested_flow' => $workspace->suggested_flow,
            'selected_modules' => $workspace->selected_modules ?? [],
            'scenario_packs' => $workspace->scenario_packs ?? [],
            'branding_profile' => $workspace->branding_profile ?? [],
            'extra_access_roles' => $workspace->extra_access_roles ?? [],
            'prefill_source' => $workspace->prefill_source,
            'prefill_payload' => $workspace->prefill_payload ?? [],
            'expires_at' => $workspace->expires_at?->toDateString() ?? now()->addDays(14)->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $brandingProfile
     */
    private function applyBrandingProfile(User $owner, array $brandingProfile): void
    {
        $owner->forceFill([
            'company_name' => trim((string) ($brandingProfile['name'] ?? '')) ?: $owner->company_name,
            'company_logo' => trim((string) ($brandingProfile['logo_url'] ?? '')) ?: $owner->company_logo,
            'company_description' => trim((string) ($brandingProfile['description'] ?? '')) ?: $owner->company_description,
            'phone_number' => trim((string) ($brandingProfile['phone'] ?? '')) ?: $owner->phone_number,
        ])->save();

        $this->marketingSettingsService->update($owner, [
            'templates' => [
                'brand_profile' => $brandingProfile,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $credentials
     */
    private function createOwner(array $payload, array $credentials, Carbon $expiresAt): User
    {
        $companyName = trim((string) $payload['company_name']);
        $prospectName = trim((string) $payload['prospect_name']);
        $description = trim((string) ($payload['desired_outcome'] ?? ''));
        $timezone = (string) $payload['timezone'];

        return User::create([
            'name' => $prospectName !== '' ? $prospectName : $companyName.' Owner',
            'email' => $credentials['email'],
            'password' => Hash::make($credentials['password']),
            'role_id' => $this->resolveRoleId('owner', 'Account owner role'),
            'locale' => (string) $payload['locale'],
            'currency_code' => $this->currencyForTimezone($timezone),
            'company_name' => $companyName,
            'company_slug' => $this->uniqueCompanySlug($companyName),
            'company_description' => $description !== '' ? $description : 'Custom demo workspace prepared for a prospect walkthrough.',
            'company_country' => $this->countryForTimezone($timezone),
            'company_city' => $this->cityForSector((string) ($payload['company_sector'] ?? '')),
            'company_timezone' => $timezone,
            'company_type' => (string) $payload['company_type'],
            'company_sector' => $payload['company_sector'] ?: null,
            'company_team_size' => (int) $payload['team_size'],
            'onboarding_completed_at' => now(),
            'email_verified_at' => now(),
            'trial_ends_at' => $expiresAt,
            'is_demo' => true,
            'demo_type' => 'custom',
            'is_demo_user' => true,
            'demo_role' => 'custom_demo_owner',
            'company_features' => $this->catalog->featureMap($payload['selected_modules']),
            'company_limits' => $this->buildLimits((string) $payload['seed_profile']),
            'assistant_credit_balance' => in_array('assistant', $payload['selected_modules'], true) ? 250 : 0,
        ]);
    }

    private function uniqueCompanySlug(string $companyName): string
    {
        $base = Str::slug($companyName) ?: 'demo-company';
        $slug = $base.'-demo';
        $suffix = 1;

        while (User::query()->where('company_slug', $slug)->exists()) {
            $slug = $base.'-demo-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function seedEnvironment(User $owner, DemoWorkspace $workspace): array
    {
        $selectedModules = $workspace->selected_modules ?? [];
        $counts = $this->catalog->seedCounts($workspace->seed_profile);
        $isSalonEclat = $this->isSalonEclatWorkspace($workspace);

        $teamMembers = $this->createTeamMembers(
            $owner,
            $selectedModules,
            max(1, (int) $workspace->team_size),
            max(1, (int) ($counts['team'] ?? 1)),
            (string) $workspace->company_sector,
            $workspace->extra_access_roles ?? [],
            $isSalonEclat
        );
        $catalog = $this->createCatalog(
            $owner,
            $selectedModules,
            (int) ($counts['catalog'] ?? 0),
            (string) $workspace->company_sector,
            $isSalonEclat
        );
        $customers = $this->createCustomers(
            $owner,
            (int) ($counts['customers'] ?? 0),
            (string) $workspace->company_sector,
            $isSalonEclat
        );
        $publicBookingLinks = $this->createSalonEclatPublicBookingLink(
            $owner,
            $selectedModules,
            $catalog['services'],
            $isSalonEclat
        );

        $loyalty = $this->createLoyaltySetup($owner, $selectedModules, $customers, $isSalonEclat);
        $offerSummary = $this->createSalonEclatOfferPackages($owner, $catalog['services'], $customers, $isSalonEclat);
        $promotion = $this->createSalonEclatPromotion($owner, $selectedModules, $catalog['services'], $isSalonEclat);
        $requests = $this->createRequests($owner, $selectedModules, $customers, $teamMembers, (int) ($counts['quotes'] ?? 0), $catalog['services']);
        $quotes = $this->createQuotes($owner, $selectedModules, $customers, $requests, $catalog, (int) ($counts['quotes'] ?? 0));
        $serviceRequests = $this->syncServiceRequestsFromLeads($requests);
        $works = $this->createWorks($owner, $selectedModules, $customers, $quotes, $catalog, $teamMembers, (int) ($counts['works'] ?? 0));
        $tasks = $this->createTasks($owner, $selectedModules, $customers, $works, $teamMembers, (int) ($counts['tasks'] ?? 0));
        $invoices = $this->createInvoices(
            $owner,
            $selectedModules,
            $customers,
            $works,
            $catalog['services'],
            $teamMembers,
            $isSalonEclat
        );
        $reservationSummary = $this->createReservationFlow(
            $owner,
            $selectedModules,
            $customers,
            $catalog['services'],
            $teamMembers,
            (int) ($counts['reservations'] ?? 0),
            (int) ($counts['queue'] ?? 0),
            (string) $workspace->company_sector,
            $isSalonEclat
        );
        $sales = $this->createSales(
            $owner,
            $selectedModules,
            $customers,
            $catalog['products'],
            (int) ($counts['sales'] ?? 0),
            $isSalonEclat
        );
        $expenses = $this->createExpenses(
            $owner,
            $selectedModules,
            $teamMembers,
            $catalog,
            (int) ($counts['expenses'] ?? 0),
            (string) $workspace->company_sector
        );
        $marketing = $this->createMarketing($owner, $selectedModules, $customers, $promotion, $isSalonEclat);
        $assistantSummary = $this->createSalonEclatAssistant($owner, $selectedModules, $isSalonEclat);
        $socialPosts = $this->createSalonEclatSocialContent($owner, $selectedModules, $isSalonEclat);
        $accountingSummary = $this->createAccountingSummary($owner, $selectedModules);

        return [
            'customers' => $customers->count(),
            'team_members' => $teamMembers->count(),
            'services' => $catalog['services']->count(),
            'products' => $catalog['products']->count(),
            'requests' => $requests->count(),
            'service_requests' => $serviceRequests->count(),
            'quotes' => $quotes->count(),
            'works' => $works->count(),
            'tasks' => $tasks->count(),
            'invoices' => Invoice::query()->where('user_id', $owner->id)->count(),
            'payments' => Payment::query()->where('user_id', $owner->id)->count(),
            'reservations' => $reservationSummary['reservations'],
            'queue_items' => $reservationSummary['queue_items'],
            'waitlist_entries' => $reservationSummary['waitlist_entries'],
            'completed_checkouts' => $reservationSummary['completed_checkouts'],
            'checkout_invoices' => $reservationSummary['checkout_invoices'],
            'checkout_payments' => $reservationSummary['checkout_payments'],
            'sales' => $sales->count(),
            'expenses' => $expenses->count(),
            'expenses_due' => $expenses->where('status', Expense::STATUS_DUE)->count(),
            'expenses_paid' => $expenses->filter(
                fn (Expense $expense) => in_array($expense->status, [Expense::STATUS_PAID, Expense::STATUS_REIMBURSED], true)
            )->count(),
            'expense_attachments' => (int) $expenses->sum(fn (Expense $expense) => $expense->attachments->count()),
            'campaigns' => $marketing['campaigns'],
            'mailing_lists' => $marketing['mailing_lists'],
            'loyalty_program_enabled' => $loyalty ? 1 : 0,
            'offer_packages' => $offerSummary['offer_packages'],
            'customer_packages' => $offerSummary['customer_packages'],
            'package_usages' => $offerSummary['package_usages'],
            'promotions' => $promotion ? 1 : 0,
            'assistant_settings' => $assistantSummary['settings'],
            'assistant_knowledge_items' => $assistantSummary['knowledge_items'],
            'social_posts' => $socialPosts,
            'public_booking_links' => $publicBookingLinks,
            'client_portal_accounts' => $customers->whereNotNull('portal_user_id')->count(),
            'client_portal_credentials' => $customers
                ->whereNotNull('portal_user_id')
                ->map(fn (Customer $customer) => [
                    'name' => trim($customer->first_name.' '.$customer->last_name),
                    'email' => $customer->email,
                    'password' => 'password',
                    'login_url' => url('/login'),
                ])
                ->values()
                ->all(),
            ...$accountingSummary,
        ];
    }

    private function isSalonEclatWorkspace(DemoWorkspace $workspace): bool
    {
        return (string) $workspace->company_sector === 'salon'
            && in_array('salon_eclat_complete', $workspace->scenario_packs ?? [], true);
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Product>  $services
     */
    private function createSalonEclatPublicBookingLink(
        User $owner,
        array $selectedModules,
        Collection $services,
        bool $isSalonEclat
    ): int {
        if (! $isSalonEclat || ! in_array('reservations', $selectedModules, true) || $services->isEmpty()) {
            return 0;
        }

        $link = PublicBookingLink::query()->create([
            'account_id' => $owner->id,
            'name' => 'Réserver chez Salon Éclat',
            'slug' => 'rendez-vous',
            'description' => 'Réservation publique des coupes, couleurs, soins et services barbier.',
            'is_active' => true,
            'requires_manual_confirmation' => false,
            'requires_deposit' => false,
            'source' => 'salon_eclat_demo',
            'campaign' => 'always_on_booking',
            'metadata' => [
                'seed_source' => 'salon_eclat_complete',
                'accent_color' => '#0f766e',
            ],
        ]);
        $link->services()->sync($services->pluck('id')->all());

        return 1;
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @return array<string, int>
     */
    private function createAccountingSummary(User $owner, array $selectedModules): array
    {
        if (! in_array('accounting', $selectedModules, true)) {
            return [];
        }

        /** @var AccountingSyncService $syncService */
        $syncService = app(AccountingSyncService::class);
        /** @var AccountingPeriodService $periodService */
        $periodService = app(AccountingPeriodService::class);

        $accountId = (int) $owner->id;

        $syncService->syncAccount($accountId);

        $activePeriods = collect($periodService->timeline($accountId)['periods'] ?? [])
            ->filter(function (array $period): bool {
                $status = (string) ($period['status'] ?? AccountingPeriod::STATUS_OPEN);

                return ((int) ($period['entry_count'] ?? 0)) > 0
                    || ((int) ($period['batch_count'] ?? 0)) > 0
                    || in_array($status, [
                        AccountingPeriod::STATUS_IN_REVIEW,
                        AccountingPeriod::STATUS_CLOSED,
                        AccountingPeriod::STATUS_REOPENED,
                    ], true);
            })
            ->count();

        return [
            'accounting_entries' => AccountingEntry::query()->forUser($accountId)->count(),
            'accounting_batches' => AccountingEntryBatch::query()->forUser($accountId)->count(),
            'accounting_review_required_batches' => AccountingEntryBatch::query()
                ->forUser($accountId)
                ->where('status', AccountingEntryBatch::STATUS_REVIEW_REQUIRED)
                ->count(),
            'accounting_active_periods' => $activePeriods,
            'accounting_exports' => AccountingExport::query()->forUser($accountId)->count(),
        ];
    }

    /**
     * @param  array<int, string>  $selectedModules
     */
    private function createTeamMembers(
        User $owner,
        array $selectedModules,
        int $requestedTeamSize,
        int $profileTeamSize,
        string $sector,
        array $requiredAccessRoles = [],
        bool $isSalonEclat = false
    ): Collection {
        $needsTeam = collect(['team_members', 'jobs', 'tasks', 'reservations', 'planning'])
            ->intersect($selectedModules)
            ->isNotEmpty();

        if (! $needsTeam) {
            return collect();
        }

        $profiles = $this->teamProfilesForSector($sector, $isSalonEclat);
        $targetCount = $isSalonEclat
            ? count($profiles)
            : max(
                1,
                $requestedTeamSize,
                min($profileTeamSize, 6),
                $this->minimumTeamCountForAccessRoles($requiredAccessRoles)
            );

        return collect(range(1, $targetCount))->map(function (int $index) use ($owner, $profiles) {
            $profile = $profiles[($index - 1) % count($profiles)];
            $emailDomain = config('demo.accounts_email_domain', 'example.test');

            $employee = User::create([
                'name' => (string) $profile['name'],
                'email' => Str::slug($profile['name']).'-'.$owner->id.'-'.$index.'@'.$emailDomain,
                'password' => Hash::make('password'),
                'role_id' => $this->resolveRoleId('employee', 'Employee role'),
                'locale' => $owner->locale,
                'currency_code' => $owner->businessCurrencyCode(),
                'company_name' => $owner->company_name,
                'company_type' => $owner->company_type,
                'company_sector' => $owner->company_sector,
                'company_timezone' => $owner->company_timezone,
                'email_verified_at' => now(),
                'onboarding_completed_at' => now(),
                'is_demo' => true,
                'demo_type' => 'custom',
                'is_demo_user' => true,
                'demo_role' => 'custom_demo_staff',
            ]);

            return TeamMember::create([
                'account_id' => $owner->id,
                'user_id' => $employee->id,
                'role' => (string) $profile['role'],
                'title' => (string) $profile['title'],
                'phone' => $this->phoneForIndex($index),
                'permissions' => $this->permissionsForTeamRole((string) $profile['role']),
                'planning_rules' => [
                    'break_minutes' => 30,
                    'min_hours_day' => 4,
                    'max_hours_day' => 8,
                    'max_hours_week' => 40,
                ],
                'is_active' => true,
            ]);
        })->values();
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @return array{services: Collection<int, Product>, products: Collection<int, Product>}
     */
    private function createCatalog(
        User $owner,
        array $selectedModules,
        int $catalogCount,
        string $sector,
        bool $isSalonEclat = false
    ): array {
        $services = collect();
        $products = collect();
        $total = max(4, $catalogCount);

        if (in_array('services', $selectedModules, true)) {
            $serviceProfiles = collect($this->serviceCatalogForSector($sector, $isSalonEclat));
            $serviceCategories = $serviceProfiles
                ->map(fn (array $item) => (string) ($item['category'] ?? 'Signature services'))
                ->unique()
                ->mapWithKeys(function (string $category) use ($owner) {
                    $model = ProductCategory::create([
                        'name' => $category,
                        'user_id' => $owner->id,
                        'created_by_user_id' => $owner->id,
                    ]);

                    return [$category => $model];
                });

            $services = $serviceProfiles
                ->take($isSalonEclat ? $serviceProfiles->count() : max(4, (int) ceil($total / 2)))
                ->values()
                ->map(function (array $item) use ($owner, $serviceCategories, $isSalonEclat) {
                    $category = (string) ($item['category'] ?? 'Signature services');

                    return Product::create([
                        'name' => $item['name'],
                        'description' => $item['description'],
                        'tags' => array_values(array_filter([
                            $isSalonEclat ? 'salon-eclat' : null,
                            isset($item['duration']) ? 'duration-'.$item['duration'].'-minutes' : null,
                        ])),
                        'category_id' => $serviceCategories[$category]->id,
                        'stock' => 0,
                        'minimum_stock' => 0,
                        'price' => $item['price'],
                        'currency_code' => $owner->businessCurrencyCode(),
                        'unit' => 'service',
                        'cost_price' => round($item['price'] * 0.35, 2),
                        'margin_percent' => 65,
                        'tax_rate' => $isSalonEclat ? 14.975 : 15,
                        'is_active' => true,
                        'user_id' => $owner->id,
                        'item_type' => Product::ITEM_TYPE_SERVICE,
                        'tracking_type' => 'none',
                    ]);
                });
        }

        if (in_array('products', $selectedModules, true)) {
            $productCategory = ProductCategory::create([
                'name' => $isSalonEclat ? 'Produits capillaires' : 'Featured products',
                'user_id' => $owner->id,
                'created_by_user_id' => $owner->id,
            ]);

            $products = collect($this->productCatalogForSector($sector, $isSalonEclat))
                ->take(max(4, $total))
                ->values()
                ->map(function (array $item, int $index) use ($owner, $productCategory, $isSalonEclat) {
                    return Product::create([
                        'name' => $item['name'],
                        'description' => $item['description'],
                        'category_id' => $productCategory->id,
                        'stock' => (int) ($item['stock'] ?? (20 + ($index * 3))),
                        'minimum_stock' => (int) ($item['minimum_stock'] ?? 5),
                        'price' => $item['price'],
                        'currency_code' => $owner->businessCurrencyCode(),
                        'sku' => 'DEMO-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                        'unit' => 'item',
                        'supplier_name' => $item['supplier'] ?? 'Demo Supplier Co.',
                        'cost_price' => round($item['price'] * 0.52, 2),
                        'margin_percent' => 48,
                        'tax_rate' => $isSalonEclat ? 14.975 : 15,
                        'is_active' => true,
                        'user_id' => $owner->id,
                        'item_type' => Product::ITEM_TYPE_PRODUCT,
                        'tracking_type' => 'stock',
                    ]);
                });
        }

        return [
            'services' => $services,
            'products' => $products,
        ];
    }

    private function createCustomers(User $owner, int $count, string $sector, bool $isSalonEclat = false): Collection
    {
        $profiles = $this->customerProfilesForSector($sector, $isSalonEclat);
        $target = $isSalonEclat ? max(count($profiles), $count) : max(6, $count);

        return collect(range(1, $target))->map(function (int $index) use ($owner, $profiles, $isSalonEclat) {
            $profile = $profiles[($index - 1) % count($profiles)];

            $customer = Customer::create([
                'user_id' => $owner->id,
                'first_name' => (string) $profile['first_name'],
                'last_name' => (string) $profile['last_name'],
                'company_name' => $profile['company_name'] ?? null,
                'email' => Str::slug($profile['first_name'].'.'.$profile['last_name'], '.').'+'.$owner->id.$index.'@example.test',
                'phone' => $this->phoneForIndex($index + 20),
                'description' => (string) $profile['description'],
                'tags' => $profile['tags'],
                'logo' => $profile['logo'] ?? null,
                'refer_by' => $profile['refer_by'] ?? 'Website form',
                'salutation' => $profile['salutation'] ?? 'Mr',
                'billing_same_as_physical' => true,
                'discount_rate' => $profile['discount_rate'] ?? ($index % 5 === 0 ? 10 : 0),
                'is_active' => true,
                'portal_access' => false,
                'loyalty_points_balance' => 0,
            ]);

            $customerName = trim($customer->first_name.' '.$customer->last_name);
            if ($isSalonEclat && $customerName === 'Marie Lefebvre') {
                $portalEmail = Str::slug($customerName, '.').'.salon-eclat-'.$owner->id.'@example.test';
                $portalUser = User::query()->create([
                    'name' => $customerName,
                    'email' => $portalEmail,
                    'password' => Hash::make('password'),
                    'role_id' => $this->resolveRoleId('client', 'Client role'),
                    'locale' => $owner->locale,
                    'currency_code' => $owner->businessCurrencyCode(),
                    'company_name' => $owner->company_name,
                    'company_type' => $owner->company_type,
                    'company_sector' => $owner->company_sector,
                    'company_timezone' => $owner->company_timezone,
                    'email_verified_at' => now(),
                    'onboarding_completed_at' => now(),
                    'is_demo' => true,
                    'demo_type' => 'custom',
                    'is_demo_user' => true,
                    'demo_role' => 'custom_demo_client',
                ]);

                $customer->forceFill([
                    'email' => $portalEmail,
                    'portal_user_id' => $portalUser->id,
                    'portal_access' => true,
                ])->save();
            }

            return $customer;
        })->values();
    }

    /**
     * @param  array<int, string>  $selectedModules
     */
    private function createLoyaltySetup(
        User $owner,
        array $selectedModules,
        Collection $customers,
        bool $isSalonEclat = false
    ): ?LoyaltyProgram {
        if (! in_array('loyalty', $selectedModules, true)) {
            return null;
        }

        $tierProfiles = $isSalonEclat ? [
            ['code' => 'VIP-BRONZE', 'name' => 'Bronze', 'perks' => ['Offres membres', 'Rappel prioritaire']],
            ['code' => 'VIP-ARGENT', 'name' => 'Argent', 'perks' => ['Réservation prioritaire', 'Soin anniversaire']],
            ['code' => 'VIP-OR', 'name' => 'Or', 'perks' => ['Créneaux privilégiés', 'Avant-premières', 'Diagnostic offert']],
        ] : [
            ['code' => 'VIP-GOLD', 'name' => 'Gold', 'perks' => ['Priority booking', 'Early access to launches', 'Preferred service slots']],
        ];
        $vipTiers = collect($tierProfiles)->mapWithKeys(function (array $profile) use ($owner) {
            $tier = VipTier::create([
                'user_id' => $owner->id,
                'created_by_user_id' => $owner->id,
                'updated_by_user_id' => $owner->id,
                ...$profile,
                'is_active' => true,
            ]);

            return [$profile['code'] => $tier];
        });

        if ($isSalonEclat) {
            $customerTiers = [
                'Marie Lefebvre' => ['VIP-ARGENT', 1850],
                'Fatou Camara' => ['VIP-OR', 2450],
                'Thomas Roy' => ['VIP-BRONZE', 640],
            ];

            $customers->each(function (Customer $customer) use ($customerTiers, $vipTiers) {
                $name = trim($customer->first_name.' '.$customer->last_name);
                [$tierCode, $points] = $customerTiers[$name] ?? [null, null];

                if (! $tierCode || ! isset($vipTiers[$tierCode])) {
                    return;
                }

                $tier = $vipTiers[$tierCode];
                $customer->forceFill([
                    'is_vip' => true,
                    'vip_tier_id' => $tier->id,
                    'vip_tier_code' => $tier->code,
                    'vip_since_at' => now()->subMonths($tierCode === 'VIP-OR' ? 18 : 8),
                    'loyalty_points_balance' => $points,
                ])->save();
            });
        } else {
            $vipTier = $vipTiers->first();
            $customers->take(min(3, $customers->count()))->each(function (Customer $customer) use ($vipTier) {
                $customer->forceFill([
                    'is_vip' => true,
                    'vip_tier_id' => $vipTier->id,
                    'vip_tier_code' => $vipTier->code,
                    'vip_since_at' => now()->subMonths(4),
                    'loyalty_points_balance' => 1200,
                ])->save();
            });
        }

        return LoyaltyProgram::create([
            'user_id' => $owner->id,
            'is_enabled' => true,
            'points_per_currency_unit' => 1,
            'minimum_spend' => $isSalonEclat ? 20 : 25,
            'rounding_mode' => LoyaltyProgram::ROUND_FLOOR,
            'points_label' => $isSalonEclat ? 'points' : 'Points',
        ]);
    }

    /**
     * @param  Collection<int, Product>  $services
     * @param  Collection<int, Customer>  $customers
     * @return array{offer_packages:int, customer_packages:int, package_usages:int}
     */
    private function createSalonEclatOfferPackages(
        User $owner,
        Collection $services,
        Collection $customers,
        bool $isSalonEclat
    ): array {
        if (! $isSalonEclat || $services->isEmpty()) {
            return [
                'offer_packages' => 0,
                'customer_packages' => 0,
                'package_usages' => 0,
            ];
        }

        $profiles = [
            [
                'name' => 'Carte 10 brushings',
                'description' => 'Dix séances de brushing à utiliser pendant douze mois.',
                'price' => 300,
                'validity_days' => 365,
                'included_quantity' => 10,
                'service' => 'Brushing seul',
                'is_public' => true,
                'is_recurring' => false,
            ],
            [
                'name' => 'Abonnement Barbe',
                'description' => 'Deux tailles de barbe par mois avec renouvellement automatique.',
                'price' => 40,
                'validity_days' => null,
                'included_quantity' => 2,
                'service' => 'Taille de barbe',
                'is_public' => true,
                'is_recurring' => true,
            ],
            [
                'name' => 'Forfait Couleur Trimestre',
                'description' => 'Trois couleurs racines à utiliser sur une période de six mois.',
                'price' => 255,
                'validity_days' => 183,
                'included_quantity' => 3,
                'service' => 'Couleur racines',
                'is_public' => false,
                'is_recurring' => false,
            ],
        ];

        $offers = collect($profiles)->mapWithKeys(function (array $profile) use ($owner, $services) {
            $offer = OfferPackage::query()->create([
                'user_id' => $owner->id,
                'name' => $profile['name'],
                'type' => OfferPackage::TYPE_FORFAIT,
                'status' => OfferPackage::STATUS_ACTIVE,
                'description' => $profile['description'],
                'pricing_mode' => OfferPackage::PRICING_FIXED,
                'price' => $profile['price'],
                'currency_code' => $owner->businessCurrencyCode(),
                'validity_days' => $profile['validity_days'],
                'included_quantity' => $profile['included_quantity'],
                'unit_type' => OfferPackage::UNIT_SESSION,
                'is_public' => $profile['is_public'],
                'is_recurring' => $profile['is_recurring'],
                'recurrence_frequency' => $profile['is_recurring'] ? OfferPackage::RECURRENCE_MONTHLY : null,
                'renewal_notice_days' => $profile['is_recurring'] ? 7 : null,
                'metadata' => [
                    'seed_source' => 'salon_eclat_complete',
                    'recurrence' => [
                        'carry_over_unused_balance' => false,
                        'payment_grace_days' => 5,
                        'payment_reminder_days' => [0, 3],
                    ],
                ],
            ]);
            $service = $services->firstWhere('name', $profile['service']);

            if ($service) {
                $offer->items()->create([
                    'product_id' => $service->id,
                    'item_type_snapshot' => Product::ITEM_TYPE_SERVICE,
                    'name_snapshot' => $service->name,
                    'description_snapshot' => $service->description,
                    'quantity' => $profile['included_quantity'],
                    'unit_price' => $service->price,
                    'included' => true,
                    'is_optional' => false,
                    'sort_order' => 0,
                    'metadata' => ['seed_source' => 'salon_eclat_complete'],
                ]);
            }

            return [$offer->name => $offer];
        });

        $assignments = [
            'Marie Lefebvre' => ['Carte 10 brushings', 10, 3],
            'Thomas Roy' => ['Abonnement Barbe', 2, 1],
            'Fatou Camara' => ['Forfait Couleur Trimestre', 3, 1],
        ];
        $customerPackages = collect();

        $customers->each(function (Customer $customer) use ($owner, $offers, $assignments, $customerPackages) {
            $customerName = trim($customer->first_name.' '.$customer->last_name);
            [$offerName, $initialQuantity, $consumedQuantity] = $assignments[$customerName] ?? [null, 0, 0];
            $offer = $offerName ? $offers->get($offerName) : null;

            if (! $offer) {
                return;
            }

            $startsAt = $offer->is_recurring
                ? now()->subWeek()->startOfDay()
                : now()->subMonths(3)->startOfDay();
            $periodEnd = $offer->is_recurring ? $startsAt->copy()->addMonth()->subDay() : null;
            $package = CustomerPackage::query()->create([
                'user_id' => $owner->id,
                'customer_id' => $customer->id,
                'offer_package_id' => $offer->id,
                'status' => CustomerPackage::STATUS_ACTIVE,
                'starts_at' => $startsAt->toDateString(),
                'expires_at' => $offer->validity_days ? $startsAt->copy()->addDays($offer->validity_days)->toDateString() : $periodEnd?->toDateString(),
                'initial_quantity' => $initialQuantity,
                'consumed_quantity' => $consumedQuantity,
                'remaining_quantity' => $initialQuantity - $consumedQuantity,
                'unit_type' => OfferPackage::UNIT_SESSION,
                'price_paid' => $offer->price,
                'currency_code' => $owner->businessCurrencyCode(),
                'is_recurring' => $offer->is_recurring,
                'recurrence_frequency' => $offer->recurrence_frequency,
                'recurrence_status' => $offer->is_recurring ? CustomerPackage::RECURRENCE_ACTIVE : null,
                'current_period_starts_at' => $offer->is_recurring ? $startsAt->toDateString() : null,
                'current_period_ends_at' => $periodEnd?->toDateString(),
                'next_renewal_at' => $offer->is_recurring ? $startsAt->copy()->addMonth()->toDateString() : null,
                'renewal_count' => $offer->is_recurring ? 1 : 0,
                'source_details' => array_replace(
                    app(OfferPackageSalesLineBuilder::class)->sourceDetails($offer),
                    ['assignment' => ['source' => 'salon_eclat_complete']]
                ),
                'metadata' => ['narrative' => $customerName.' — '.$offerName],
            ]);

            foreach (range(1, $consumedQuantity) as $usageIndex) {
                CustomerPackageUsage::query()->create([
                    'customer_package_id' => $package->id,
                    'user_id' => $owner->id,
                    'customer_id' => $customer->id,
                    'product_id' => $offer->items()->value('product_id'),
                    'created_by_user_id' => $owner->id,
                    'quantity' => 1,
                    'used_at' => $offer->is_recurring
                        ? now()->subDays($usageIndex * 2)
                        : now()->subWeeks($usageIndex * 2),
                    'note' => 'Passage Salon Éclat — séance '.$usageIndex,
                    'metadata' => ['seed_source' => 'salon_eclat_complete'],
                ]);
            }

            $customerPackages->push($package);
        });

        return [
            'offer_packages' => $offers->count(),
            'customer_packages' => $customerPackages->count(),
            'package_usages' => CustomerPackageUsage::query()->where('user_id', $owner->id)->count(),
        ];
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Product>  $services
     */
    private function createSalonEclatPromotion(
        User $owner,
        array $selectedModules,
        Collection $services,
        bool $isSalonEclat
    ): ?Promotion {
        if (! $isSalonEclat || ! in_array('promotions', $selectedModules, true)) {
            return null;
        }

        $colorService = $services->firstWhere('name', 'Couleur racines');
        $startsAt = now()->subDays(7)->startOfDay();
        $endsAt = now()->addDays(30)->endOfDay();

        if ($colorService) {
            $colorService->forceFill([
                'promo_discount_percent' => 20,
                'promo_start_at' => $startsAt,
                'promo_end_at' => $endsAt,
            ])->save();
        }

        return Promotion::query()->create([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'name' => 'Rentrée couleur — 20 %',
            'code' => 'RENTREE20',
            'target_type' => PromotionTargetType::SERVICE->value,
            'target_id' => $colorService?->id,
            'discount_type' => PromotionDiscountType::PERCENTAGE->value,
            'discount_value' => 20,
            'start_date' => $startsAt->toDateString(),
            'end_date' => $endsAt->toDateString(),
            'status' => PromotionStatus::ACTIVE->value,
            'usage_limit' => 100,
            'minimum_order_amount' => 75,
            'rules' => [
                'seed_source' => 'salon_eclat_complete',
                'message' => '20 % sur les prestations de coloration admissibles.',
            ],
        ]);
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, TeamMember>  $teamMembers
     * @param  Collection<int, Product>  $services
     * @return Collection<int, LeadRequest>
     */
    private function createRequests(
        User $owner,
        array $selectedModules,
        Collection $customers,
        Collection $teamMembers,
        int $count,
        Collection $services
    ): Collection {
        if (! in_array('requests', $selectedModules, true)) {
            return collect();
        }

        return collect(range(1, max(2, $count)))
            ->map(function (int $index) use ($owner, $customers, $teamMembers, $services) {
                $customer = $customers[$index % $customers->count()];
                $member = $teamMembers->isNotEmpty() ? $teamMembers[$index % $teamMembers->count()] : null;
                $service = $services->isNotEmpty() ? $services[$index % $services->count()] : null;
                $statuses = [
                    LeadRequest::STATUS_NEW,
                    LeadRequest::STATUS_CONTACTED,
                    LeadRequest::STATUS_QUALIFIED,
                    LeadRequest::STATUS_QUOTE_SENT,
                ];

                return LeadRequest::create([
                    'user_id' => $owner->id,
                    'customer_id' => $customer->id,
                    'assigned_team_member_id' => $member?->id,
                    'channel' => $index % 2 === 0 ? 'website' : 'phone',
                    'status' => $statuses[$index % count($statuses)],
                    'service_type' => $service?->name,
                    'urgency' => $index % 3 === 0 ? 'high' : 'normal',
                    'title' => 'Need help with '.strtolower($service?->name ?: 'service delivery'),
                    'description' => 'Prospect would like a demo-ready request flow with qualification already started.',
                    'contact_name' => trim($customer->first_name.' '.$customer->last_name),
                    'contact_email' => $customer->email,
                    'contact_phone' => $customer->phone,
                    'country' => $owner->company_country,
                    'city' => $owner->company_city,
                    'street1' => '123 Demo Street',
                    'postal_code' => 'H2X 1Y4',
                    'is_serviceable' => true,
                    'status_updated_at' => now()->subDays(3 - min($index, 3)),
                    'next_follow_up_at' => now()->addDays($index),
                ]);
            })
            ->values();
    }

    /**
     * @param  Collection<int, LeadRequest>  $requests
     * @return Collection<int, ServiceRequest>
     */
    private function syncServiceRequestsFromLeads(Collection $requests): Collection
    {
        return $requests
            ->map(function (LeadRequest $lead): ServiceRequest {
                $lead->refresh();
                [$source, $channel] = $this->serviceRequestSourceFromLeadChannel((string) ($lead->channel ?? ''));
                $quote = $lead->quote()->first();
                $status = $this->serviceRequestStatusFromLead($lead);
                $requestType = $this->serviceRequestTypeFromLead($lead, $quote);
                $submittedAt = $lead->created_at ?? now();
                $acceptedAt = $status === ServiceRequest::STATUS_ACCEPTED
                    ? ($quote?->accepted_at ?? $lead->converted_at ?? $lead->updated_at ?? $submittedAt)
                    : null;

                $serviceRequest = ServiceRequest::query()->updateOrCreate(
                    [
                        'user_id' => (int) $lead->user_id,
                        'source_ref' => 'lead:'.$lead->id,
                    ],
                    [
                        'customer_id' => $lead->customer_id,
                        'prospect_id' => $lead->id,
                        'source' => $source,
                        'channel' => $channel,
                        'status' => $status,
                        'request_type' => $requestType,
                        'service_type' => $lead->service_type,
                        'title' => $lead->title,
                        'description' => $lead->description,
                        'requester_name' => $lead->contact_name,
                        'requester_email' => $lead->contact_email,
                        'requester_phone' => $lead->contact_phone,
                        'street1' => $lead->street1,
                        'street2' => $lead->street2,
                        'city' => $lead->city,
                        'state' => $lead->state,
                        'postal_code' => $lead->postal_code,
                        'country' => $lead->country,
                        'source_meta' => $this->seedSourceMetaFromLead($lead),
                        'submitted_at' => $submittedAt,
                        'accepted_at' => $acceptedAt,
                        'meta' => [
                            'seed' => 'demo_workspace',
                            'seed_origin' => 'demo_workspace_lead_sync',
                            'legacy_request_id' => (int) $lead->id,
                            'legacy_status' => (string) $lead->status,
                        ],
                    ]
                );

                $serviceRequest->timestamps = false;
                $serviceRequest->forceFill([
                    'created_at' => $lead->created_at ?? $submittedAt,
                    'updated_at' => $lead->updated_at ?? ($lead->created_at ?? $submittedAt),
                ])->saveQuietly();
                $serviceRequest->timestamps = true;

                return $serviceRequest->fresh();
            })
            ->values();
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, LeadRequest>  $requests
     * @param  array{services: Collection<int, Product>, products: Collection<int, Product>}  $catalog
     * @return Collection<int, Quote>
     */
    private function createQuotes(
        User $owner,
        array $selectedModules,
        Collection $customers,
        Collection $requests,
        array $catalog,
        int $count
    ): Collection {
        if (! in_array('quotes', $selectedModules, true)) {
            return collect();
        }

        $lines = $catalog['services']->isNotEmpty() ? $catalog['services'] : $catalog['products'];
        if ($lines->isEmpty()) {
            return collect();
        }

        return collect(range(1, max(2, $count)))->map(function (int $index) use ($owner, $customers, $requests, $lines) {
            $customer = $customers[$index % $customers->count()];
            $request = $requests->isNotEmpty() ? $requests[$index % $requests->count()] : null;
            $picked = $lines->take(min(2, $lines->count()));
            $subtotal = (float) $picked->sum('price');
            $statuses = ['draft', 'sent', 'accepted', 'accepted'];

            $quote = Quote::create([
                'user_id' => $owner->id,
                'job_title' => 'Custom package for '.$customer->company_name,
                'status' => $statuses[$index % count($statuses)],
                'customer_id' => $customer->id,
                'request_id' => $request?->id,
                'total' => $subtotal,
                'subtotal' => $subtotal,
                'currency_code' => $owner->businessCurrencyCode(),
                'is_fixed' => true,
                'notes' => 'Prepared for a prospect demo with ready-to-review commercial scope.',
                'messages' => 'Pricing includes onboarding and a first delivery wave.',
                'accepted_at' => $index % 3 === 0 ? now()->subDays(2) : null,
            ]);

            $pivotData = [];
            foreach ($picked as $product) {
                $pivotData[$product->id] = [
                    'quantity' => 1,
                    'price' => (float) $product->price,
                    'description' => $product->description,
                    'total' => (float) $product->price,
                ];
            }
            $quote->syncProductLines($pivotData);
            $quote->refresh();
            $quote->syncRequestStatusFromQuote();

            return $quote;
        })->values();
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, Quote>  $quotes
     * @param  array{services: Collection<int, Product>, products: Collection<int, Product>}  $catalog
     * @param  Collection<int, TeamMember>  $teamMembers
     * @return Collection<int, Work>
     */
    private function createWorks(
        User $owner,
        array $selectedModules,
        Collection $customers,
        Collection $quotes,
        array $catalog,
        Collection $teamMembers,
        int $count
    ): Collection {
        if (! in_array('jobs', $selectedModules, true)) {
            return collect();
        }

        $lines = $catalog['services']->isNotEmpty() ? $catalog['services'] : $catalog['products'];

        return collect(range(1, max(2, $count)))->map(function (int $index) use ($owner, $customers, $quotes, $lines, $teamMembers) {
            $customer = $customers[$index % $customers->count()];
            $quote = $quotes->isNotEmpty() ? $quotes[$index % $quotes->count()] : null;
            $startDate = now()->subDays(max(0, 4 - $index));
            $statuses = [
                Work::STATUS_SCHEDULED,
                Work::STATUS_IN_PROGRESS,
                Work::STATUS_COMPLETED,
                Work::STATUS_PENDING_REVIEW,
            ];
            $attachedLines = $lines->take(min(2, $lines->count()));
            $subtotal = (float) $attachedLines->sum('price');

            $work = Work::create([
                'user_id' => $owner->id,
                'customer_id' => $customer->id,
                'quote_id' => $quote?->id,
                'job_title' => ($quote?->job_title ?: 'Service delivery').' - phase '.$index,
                'instructions' => 'Demo-ready operational record with assigned team and billable scope.',
                'start_date' => $startDate->toDateString(),
                'end_date' => $startDate->copy()->addDay()->toDateString(),
                'start_time' => '09:00:00',
                'end_time' => '11:30:00',
                'is_all_day' => false,
                'later' => false,
                'status' => $statuses[$index % count($statuses)],
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'billing_mode' => 'per_visit',
                'billing_cycle' => 'on_completion',
                'billing_grouping' => 'per_work',
            ]);

            $work->products()->attach(
                $attachedLines->mapWithKeys(fn (Product $product) => [
                    $product->id => [
                        'quantity' => 1,
                        'price' => (float) $product->price,
                        'description' => $product->description,
                        'total' => (float) $product->price,
                    ],
                ])->all()
            );

            if ($teamMembers->isNotEmpty()) {
                $selectedMembers = $teamMembers->take(min(2, $teamMembers->count()));
                $work->teamMembers()->attach(
                    $selectedMembers->mapWithKeys(fn (TeamMember $member, int $memberIndex) => [
                        $member->id => ['role' => $memberIndex === 0 ? 'lead' : 'support'],
                    ])->all()
                );
            }

            return $work;
        })->values();
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, Work>  $works
     * @param  Collection<int, TeamMember>  $teamMembers
     * @return Collection<int, Task>
     */
    private function createTasks(
        User $owner,
        array $selectedModules,
        Collection $customers,
        Collection $works,
        Collection $teamMembers,
        int $count
    ): Collection {
        if (! in_array('tasks', $selectedModules, true) || $works->isEmpty()) {
            return collect();
        }

        $statuses = ['todo', 'in_progress', 'done'];

        return collect(range(1, max(3, $count)))->map(function (int $index) use ($owner, $customers, $works, $teamMembers, $statuses) {
            $work = $works[$index % $works->count()];
            $customer = $customers[$index % $customers->count()];
            $member = $teamMembers->isNotEmpty() ? $teamMembers[$index % $teamMembers->count()] : null;
            $status = $statuses[$index % count($statuses)];

            return Task::create([
                'account_id' => $owner->id,
                'created_by_user_id' => $owner->id,
                'assigned_team_member_id' => $member?->id,
                'customer_id' => $customer->id,
                'work_id' => $work->id,
                'title' => $index % 2 === 0 ? 'Confirm materials and arrival window' : 'Prepare completion checklist',
                'description' => 'Task seeded for the demo to show operational coordination and ownership.',
                'status' => $status,
                'billable' => $index % 3 === 0,
                'due_date' => now()->addDays($index)->toDateString(),
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',
                'completed_at' => $status === 'done' ? now()->subDay() : null,
            ]);
        })->values();
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, Work>  $works
     * @param  Collection<int, Product>  $services
     * @param  Collection<int, TeamMember>  $teamMembers
     * @return Collection<int, Invoice>
     */
    private function createInvoices(
        User $owner,
        array $selectedModules,
        Collection $customers,
        Collection $works,
        Collection $services,
        Collection $teamMembers,
        bool $isSalonEclat = false
    ): Collection {
        if (! in_array('invoices', $selectedModules, true)) {
            return collect();
        }

        $sources = $works->isNotEmpty() ? $works : $services;

        if ($sources->isEmpty()) {
            return collect();
        }

        return $sources->take(min(3, $sources->count()))->values()->map(function (Work|Product $source, int $index) use ($owner, $customers, $teamMembers, $isSalonEclat) {
            $customer = $customers[$index % $customers->count()];
            $totals = [180, 325, 490];
            $statuses = ['sent', 'partial', 'paid'];
            $work = $source instanceof Work ? $source : null;
            $service = $source instanceof Product ? $source : null;
            $subtotal = $work
                ? (float) ($totals[$index % count($totals)] ?? 240)
                : (float) ($service?->price ?? 0);
            $taxTotal = $isSalonEclat ? round($subtotal * 0.14975, 2) : 0;
            $total = round($subtotal + $taxTotal, 2);
            $status = $isSalonEclat
                ? ['paid', 'sent', 'paid'][$index % 3]
                : $statuses[$index % count($statuses)];

            $invoice = Invoice::create([
                'work_id' => $work?->id,
                'customer_id' => $customer->id,
                'user_id' => $owner->id,
                'created_by_user_id' => $owner->id,
                'status' => $status,
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'total' => $total,
                'currency_code' => $owner->businessCurrencyCode(),
                'source' => $isSalonEclat ? 'demo_seed' : null,
                'billing_snapshot' => $isSalonEclat ? [
                    'seed_source' => 'salon_eclat_complete',
                    'tax_rate' => 14.975,
                    'taxes_included' => false,
                ] : null,
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'work_id' => $work?->id,
                'assigned_team_member_id' => $teamMembers->isNotEmpty() ? $teamMembers[$index % $teamMembers->count()]->id : null,
                'title' => $work?->job_title ?? $service?->name ?? 'Service',
                'description' => 'Main invoice line created for the demo workspace.',
                'scheduled_date' => $work?->start_date ?? now()->subDays($index + 1)->toDateString(),
                'start_time' => $work?->start_time,
                'end_time' => $work?->end_time,
                'assignee_name' => $teamMembers->isNotEmpty() ? $teamMembers[$index % $teamMembers->count()]->user?->name : null,
                'task_status' => 'completed',
                'quantity' => 1,
                'unit_price' => $subtotal,
                'currency_code' => $owner->businessCurrencyCode(),
                'total' => $subtotal,
                'meta' => $service ? ['service_id' => $service->id] : null,
            ]);

            $shouldCreatePayment = $isSalonEclat ? in_array($index, [0, 2], true) : $index > 0;
            if ($shouldCreatePayment) {
                $paymentAmount = $isSalonEclat ? $total : ($index === 1 ? $total / 2 : $total);
                Payment::create([
                    'invoice_id' => $invoice->id,
                    'customer_id' => $customer->id,
                    'user_id' => $owner->id,
                    'amount' => $paymentAmount,
                    'currency_code' => $owner->businessCurrencyCode(),
                    'tip_amount' => 0,
                    'tip_type' => 'none',
                    'tip_percent' => null,
                    'tip_base_amount' => 0,
                    'charged_total' => $paymentAmount,
                    'tip_assignee_user_id' => null,
                    'method' => $isSalonEclat ? 'cash' : 'card',
                    'provider' => $isSalonEclat ? 'manual' : 'demo',
                    'status' => $isSalonEclat ? Payment::STATUS_COMPLETED : Payment::STATUS_PAID,
                    'reference' => $isSalonEclat
                        ? 'DEMO-CASH-ECLAT-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)
                        : 'DEMO-PAY-'.Str::upper(Str::random(6)),
                    'provider_reference' => null,
                    'notes' => $isSalonEclat ? 'Paiement comptant historique de démonstration — aucun débit externe.' : null,
                    'paid_at' => now()->subDay(),
                ]);

                $invoice->refreshPaymentStatus();
            }

            return $invoice->fresh();
        })->values();
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, Product>  $services
     * @param  Collection<int, TeamMember>  $teamMembers
     * @return array{
     *     reservations:int,
     *     queue_items:int,
     *     waitlist_entries:int,
     *     completed_checkouts:int,
     *     checkout_invoices:int,
     *     checkout_payments:int
     * }
     */
    private function createReservationFlow(
        User $owner,
        array $selectedModules,
        Collection $customers,
        Collection $services,
        Collection $teamMembers,
        int $reservationCount,
        int $queueCount,
        string $sector,
        bool $isSalonEclat = false
    ): array {
        if (! in_array('reservations', $selectedModules, true) || $services->isEmpty() || $teamMembers->isEmpty()) {
            return [
                'reservations' => 0,
                'queue_items' => 0,
                'waitlist_entries' => 0,
                'completed_checkouts' => 0,
                'checkout_invoices' => 0,
                'checkout_payments' => 0,
            ];
        }

        ReservationSetting::create([
            'account_id' => $owner->id,
            'team_member_id' => null,
            'business_preset' => in_array($sector, ['salon', 'wellness'], true) ? 'salon' : 'service_general',
            'buffer_minutes' => 10,
            'slot_interval_minutes' => $isSalonEclat ? 15 : 30,
            'min_notice_minutes' => $isSalonEclat ? 60 : 0,
            'max_advance_days' => $isSalonEclat ? 60 : 90,
            'cancellation_cutoff_hours' => $isSalonEclat ? 24 : 12,
            'allow_client_cancel' => true,
            'allow_client_reschedule' => true,
            'late_release_minutes' => $isSalonEclat ? 10 : 5,
            'waitlist_enabled' => true,
            'queue_mode_enabled' => true,
            'queue_assignment_mode' => 'team_member',
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_pre_call_threshold' => 2,
            'queue_no_show_on_grace_expiry' => $isSalonEclat,
            'deposit_required' => $isSalonEclat,
            'deposit_amount' => $isSalonEclat ? 20 : 0,
            'no_show_fee_enabled' => $isSalonEclat,
            'no_show_fee_amount' => $isSalonEclat ? 25 : 0,
        ]);

        $teamMembers->each(function (TeamMember $member, int $index) use ($owner, $isSalonEclat) {
            $isColorist = $member->user?->name === 'Léa Moreau';
            ReservationSetting::create([
                'account_id' => $owner->id,
                'team_member_id' => $member->id,
                'business_preset' => 'salon',
                'buffer_minutes' => $isColorist ? 15 : 10,
                'slot_interval_minutes' => $isSalonEclat ? 15 : 30,
                'min_notice_minutes' => $isSalonEclat ? 60 : 0,
                'max_advance_days' => 60,
                'cancellation_cutoff_hours' => $isSalonEclat ? 24 : 12,
                'allow_client_cancel' => true,
                'allow_client_reschedule' => true,
                'late_release_minutes' => $isSalonEclat ? 10 : 5,
                'waitlist_enabled' => true,
                'queue_mode_enabled' => true,
                'queue_assignment_mode' => 'team_member',
                'queue_dispatch_mode' => 'fifo_with_appointment_priority',
                'queue_grace_minutes' => 5,
                'queue_pre_call_threshold' => 2,
                'queue_no_show_on_grace_expiry' => $isSalonEclat,
                'deposit_required' => $isSalonEclat,
                'deposit_amount' => $isSalonEclat ? 20 : 0,
                'no_show_fee_enabled' => $isSalonEclat,
                'no_show_fee_amount' => $isSalonEclat ? 25 : 0,
            ]);

            ReservationResource::create([
                'account_id' => $owner->id,
                'team_member_id' => $member->id,
                'name' => $isSalonEclat ? 'Fauteuil '.($index + 1) : 'Chair '.($index + 1),
                'type' => 'chair',
                'capacity' => 1,
                'is_active' => true,
                'metadata' => ['kind' => 'barber_chair', 'seed_source' => $isSalonEclat ? 'salon_eclat_complete' : 'demo_workspace'],
            ]);

            $availabilityDays = $isSalonEclat ? range(2, 6) : range(1, 5);
            foreach ($availabilityDays as $dayOffset) {
                WeeklyAvailability::create([
                    'account_id' => $owner->id,
                    'team_member_id' => $member->id,
                    'day_of_week' => $dayOffset,
                    'start_time' => '09:00:00',
                    'end_time' => $isSalonEclat && $dayOffset < 6 ? '19:00:00' : '17:00:00',
                    'is_active' => true,
                ]);
            }

            TeamMemberShift::create([
                'account_id' => $owner->id,
                'team_member_id' => $member->id,
                'created_by_user_id' => $owner->id,
                'approved_by_user_id' => $owner->id,
                'approved_at' => now()->subDays(2),
                'kind' => 'shift',
                'status' => 'approved',
                'title' => $isSalonEclat ? 'Quart Salon Éclat' : 'Frontline shift',
                'shift_date' => now()->toDateString(),
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'break_minutes' => 30,
            ]);
        });

        if ($isSalonEclat) {
            ReservationResource::query()->create([
                'account_id' => $owner->id,
                'team_member_id' => null,
                'name' => 'Bac 1',
                'type' => 'wash_basin',
                'capacity' => 1,
                'is_active' => true,
                'metadata' => ['kind' => 'wash_basin', 'seed_source' => 'salon_eclat_complete'],
            ]);
        }

        AvailabilityException::create([
            'account_id' => $owner->id,
            'team_member_id' => $teamMembers->first()?->id,
            'date' => now()->addDays(4)->toDateString(),
            'start_time' => '13:00:00',
            'end_time' => '17:00:00',
            'type' => AvailabilityException::TYPE_CLOSED,
            'reason' => 'Training block',
        ]);

        $reservations = collect(range(1, max(4, $reservationCount)))->map(function (int $index) use ($owner, $customers, $services, $teamMembers, $isSalonEclat) {
            $member = $teamMembers[$index % $teamMembers->count()];
            $customer = $customers[$index % $customers->count()];
            $service = $services[$index % $services->count()];
            $startsAt = $isSalonEclat
                ? $this->salonEclatReservationStart($owner, $index)
                : now()->copy()->startOfDay()->addHours(9 + $index);
            $duration = $isSalonEclat ? $this->salonEclatServiceDuration($service) : 60;
            $statuses = [
                Reservation::STATUS_CONFIRMED,
                Reservation::STATUS_CONFIRMED,
                Reservation::STATUS_COMPLETED,
                Reservation::STATUS_PENDING,
            ];

            return Reservation::create([
                'account_id' => $owner->id,
                'team_member_id' => $member->id,
                'client_id' => $customer->id,
                'service_id' => $service->id,
                'status' => $statuses[$index % count($statuses)],
                'source' => Reservation::SOURCE_STAFF,
                'timezone' => $owner->company_timezone ?: 'UTC',
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes($duration),
                'duration_minutes' => $duration,
                'buffer_minutes' => 10,
                'internal_notes' => $isSalonEclat
                    ? 'Rendez-vous narratif Salon Éclat — prêt pour la démonstration.'
                    : 'Demo reservation generated for queue and booking walkthrough.',
                'client_notes' => $index % 2 === 0 ? 'Customer prefers the senior stylist.' : null,
                'created_by_user_id' => $owner->id,
            ]);
        })->values();

        $queueItems = collect(range(1, max(2, $queueCount)))->map(function (int $index) use ($owner, $customers, $services, $teamMembers, $reservations, $isSalonEclat) {
            $member = $teamMembers[$index % $teamMembers->count()];
            $customer = $customers[$index % $customers->count()];
            $service = $services[$index % $services->count()];
            $reservation = $reservations[$index % $reservations->count()];
            $checkedInAt = now()->subMinutes($index * 7);
            $statuses = [
                ReservationQueueItem::STATUS_CHECKED_IN,
                ReservationQueueItem::STATUS_CALLED,
                ReservationQueueItem::STATUS_IN_SERVICE,
                ReservationQueueItem::STATUS_PRE_CALLED,
            ];
            $status = $statuses[$index % count($statuses)];

            return ReservationQueueItem::create([
                'account_id' => $owner->id,
                'reservation_id' => $reservation->id,
                'client_id' => $customer->id,
                'service_id' => $service->id,
                'team_member_id' => $member->id,
                'created_by_user_id' => $owner->id,
                'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
                'source' => 'staff',
                'queue_number' => 'SAL-'.str_pad((string) (1000 + $index), 4, '0', STR_PAD_LEFT),
                'status' => $status,
                'priority' => $status === ReservationQueueItem::STATUS_IN_SERVICE ? 2 : 0,
                'estimated_duration_minutes' => $isSalonEclat ? $this->salonEclatServiceDuration($service) : 45,
                'checked_in_at' => $checkedInAt,
                'pre_called_at' => in_array($status, [ReservationQueueItem::STATUS_PRE_CALLED, ReservationQueueItem::STATUS_CALLED, ReservationQueueItem::STATUS_IN_SERVICE], true) ? $checkedInAt->copy()->addMinutes(5) : null,
                'called_at' => in_array($status, [ReservationQueueItem::STATUS_CALLED, ReservationQueueItem::STATUS_IN_SERVICE], true) ? $checkedInAt->copy()->addMinutes(10) : null,
                'started_at' => $status === ReservationQueueItem::STATUS_IN_SERVICE ? $checkedInAt->copy()->addMinutes(13) : null,
                'position' => $index,
                'eta_minutes' => max(5, $index * 12),
                'metadata' => ['label' => $customer->company_name ?: trim($customer->first_name.' '.$customer->last_name)],
            ]);
        })->values();

        $completedCheckout = $this->createSalonEclatCompletedCheckout(
            $owner,
            $customers,
            $services,
            $teamMembers,
            $reservations,
            $queueItems,
            $isSalonEclat
        );

        $waitlists = collect(range(1, 2))->map(function (int $index) use ($owner, $customers, $services, $teamMembers) {
            $member = $teamMembers[$index % $teamMembers->count()];
            $customer = $customers[($index + 2) % $customers->count()];
            $service = $services[$index % $services->count()];
            $start = now()->addDays(2)->setTime(14 + $index, 0);

            return ReservationWaitlist::create([
                'account_id' => $owner->id,
                'client_id' => $customer->id,
                'service_id' => $service->id,
                'team_member_id' => $member->id,
                'status' => ReservationWaitlist::STATUS_PENDING,
                'requested_start_at' => $start,
                'requested_end_at' => $start->copy()->addHour(),
                'duration_minutes' => 60,
                'party_size' => 1,
                'notes' => 'Prospect waitlist example for the live demo.',
                'metadata' => ['channel' => 'website'],
            ]);
        })->values();

        return [
            'reservations' => $reservations->count(),
            'queue_items' => $queueItems->count(),
            'waitlist_entries' => $waitlists->count(),
            ...$completedCheckout,
        ];
    }

    /**
     * Seed one truthful, fully connected service checkout without simulating an
     * external card processor. Live Stripe checkout remains an E2E concern.
     *
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, Product>  $services
     * @param  Collection<int, TeamMember>  $teamMembers
     * @param  Collection<int, Reservation>  $reservations
     * @param  Collection<int, ReservationQueueItem>  $queueItems
     * @return array{completed_checkouts:int, checkout_invoices:int, checkout_payments:int}
     */
    private function createSalonEclatCompletedCheckout(
        User $owner,
        Collection $customers,
        Collection $services,
        Collection $teamMembers,
        Collection $reservations,
        Collection $queueItems,
        bool $isSalonEclat
    ): array {
        $empty = [
            'completed_checkouts' => 0,
            'checkout_invoices' => 0,
            'checkout_payments' => 0,
        ];

        if (! $isSalonEclat) {
            return $empty;
        }

        $marie = $customers->first(
            fn (Customer $customer): bool => trim($customer->first_name.' '.$customer->last_name) === 'Marie Lefebvre'
        );
        $paidService = $services->firstWhere('name', 'Coupe femme + brushing');
        $karim = $teamMembers->first(
            fn (TeamMember $member): bool => $member->user?->name === 'Karim Benali'
        );
        $ticket = $queueItems->first(
            fn (ReservationQueueItem $item): bool => $item->status === ReservationQueueItem::STATUS_IN_SERVICE
        );

        if (! $marie instanceof Customer
            || ! $paidService instanceof Product
            || ! $karim instanceof TeamMember
            || ! $ticket instanceof ReservationQueueItem) {
            return $empty;
        }

        $reservation = $reservations->firstWhere('id', $ticket->reservation_id);
        if (! $reservation instanceof Reservation) {
            return $empty;
        }

        $startsAt = $this->salonEclatCompletedCheckoutStart($owner);
        $reservation->forceFill([
            'team_member_id' => $karim->id,
            'client_id' => $marie->id,
            'service_id' => $paidService->id,
            'status' => Reservation::STATUS_CONFIRMED,
            'source' => Reservation::SOURCE_STAFF,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(60),
            'duration_minutes' => 60,
            'internal_notes' => 'Parcours Salon Éclat payé avec taxes, pourboire et reçu.',
        ])->save();

        $ticket->forceFill([
            'reservation_id' => $reservation->id,
            'client_id' => $marie->id,
            'service_id' => $paidService->id,
            'team_member_id' => $karim->id,
            'queue_number' => 'SAL-ECLAT-PAID-001',
            'status' => ReservationQueueItem::STATUS_IN_SERVICE,
            'checked_in_at' => $startsAt->copy()->subMinutes(10),
            'called_at' => $startsAt->copy()->subMinutes(5),
            'started_at' => $startsAt,
            'finished_at' => null,
            'estimated_duration_minutes' => 60,
            'metadata' => array_replace((array) $ticket->metadata, [
                'label' => 'Marie Lefebvre',
                'seed_scenario' => 'salon_eclat_completed_checkout',
            ]),
        ])->save();

        $settings = app(ReservationAvailabilityService::class)
            ->resolveSettings((int) $owner->id, (int) $karim->id);
        $settings['business_preset'] = 'salon';
        $settings['queue_mode_enabled'] = true;
        $settings['queue_dispatch_mode'] = ReservationQueueService::DISPATCH_MODE_FIFO;
        $settings['queue_assignment_mode'] = ReservationQueueService::ASSIGNMENT_MODE_PER_STAFF;

        $originalNotifications = $owner->company_notification_settings;
        $mutedNotifications = is_array($originalNotifications) ? $originalNotifications : [];
        $mutedNotifications['reservations'] = array_replace(
            (array) ($mutedNotifications['reservations'] ?? []),
            ['enabled' => false]
        );
        $owner->forceFill(['company_notification_settings' => $mutedNotifications])->saveQuietly();

        try {
            $awaitingPayment = app(ReservationQueueService::class)->transition(
                $ticket->fresh(),
                'finish',
                $owner,
                $settings
            );
            app(ReservationQueueCheckoutService::class)->checkout(
                $awaitingPayment,
                [
                    'method' => 'cash',
                    'tip_enabled' => true,
                    'tip_mode' => 'percent',
                    'tip_percent' => 18,
                    'reference' => 'DEMO-ECLAT-CASH-001',
                ],
                $owner,
                $settings
            );
        } finally {
            $owner->forceFill(['company_notification_settings' => $originalNotifications])->saveQuietly();
        }

        return [
            'completed_checkouts' => 1,
            'checkout_invoices' => 1,
            'checkout_payments' => 1,
        ];
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Sale>
     */
    private function createSales(
        User $owner,
        array $selectedModules,
        Collection $customers,
        Collection $products,
        int $count,
        bool $isSalonEclat = false
    ): Collection {
        if (! in_array('sales', $selectedModules, true) || $products->isEmpty()) {
            return collect();
        }

        return collect(range(1, max(2, $count)))->map(function (int $index) use ($owner, $customers, $products, $isSalonEclat) {
            $customer = $customers[$index % $customers->count()];
            $picked = $products->take(min(2, $products->count()));
            $subtotal = (float) $picked->sum('price');
            $discountRate = $index % 3 === 0 ? 10 : 0;
            $discountTotal = $discountRate > 0 ? round($subtotal * 0.1, 2) : 0;
            $taxTotal = $isSalonEclat
                ? round(($subtotal - $discountTotal) * 0.14975, 2)
                : round($subtotal * 0.15, 2);
            $total = $isSalonEclat
                ? round($subtotal - $discountTotal + $taxTotal, 2)
                : round($subtotal * 1.15, 2);
            $isPaid = $index % 2 === 0;
            $sale = Sale::create([
                'user_id' => $owner->id,
                'created_by_user_id' => $owner->id,
                'customer_id' => $customer->id,
                'status' => $isPaid ? Sale::STATUS_PAID : Sale::STATUS_PENDING,
                'payment_provider' => $isSalonEclat ? 'manual' : 'demo',
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'currency_code' => $owner->businessCurrencyCode(),
                'discount_rate' => $discountRate,
                'discount_total' => $discountTotal,
                'loyalty_points_redeemed' => 0,
                'loyalty_discount_total' => 0,
                'total' => $total,
                'delivery_fee' => 0,
                'fulfillment_method' => $isSalonEclat ? 'pickup' : ($isPaid ? 'pickup' : 'delivery'),
                'fulfillment_status' => $isSalonEclat && $isPaid
                    ? Sale::FULFILLMENT_COMPLETED
                    : ($isPaid ? Sale::FULFILLMENT_READY_FOR_PICKUP : Sale::FULFILLMENT_PENDING),
                'scheduled_for' => now()->addDays($index),
                'source' => 'pos',
                'paid_at' => $isPaid ? now()->subHours($index * 3) : null,
                'notes' => $isSalonEclat ? 'Vente comptoir Salon Éclat — produits de revente.' : null,
            ]);

            foreach ($picked as $product) {
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'description' => $product->description,
                    'quantity' => 1,
                    'price' => $product->price,
                    'currency_code' => $owner->businessCurrencyCode(),
                    'total' => $product->price,
                ]);

                if ($isSalonEclat && $isPaid) {
                    $product->decrement('stock');
                }
            }

            if ($isSalonEclat && $isPaid) {
                Payment::query()->create([
                    'sale_id' => $sale->id,
                    'customer_id' => $customer->id,
                    'user_id' => $owner->id,
                    'amount' => $total,
                    'currency_code' => $owner->businessCurrencyCode(),
                    'tip_amount' => 0,
                    'tip_type' => 'none',
                    'tip_base_amount' => 0,
                    'charged_total' => $total,
                    'method' => 'cash',
                    'provider' => 'manual',
                    'status' => Payment::STATUS_COMPLETED,
                    'reference' => 'DEMO-POS-CASH-ECLAT-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                    'provider_reference' => null,
                    'paid_at' => $sale->paid_at,
                ]);
            }

            return $sale;
        })->values();
    }

    private function salonEclatServiceDuration(Product $service): int
    {
        return match ((string) $service->name) {
            'Coupe femme + brushing', 'Chignon / événement' => 60,
            'Coupe homme', 'Coupe enfant (-12 ans)', 'Brushing seul', 'Rasage traditionnel' => 30,
            'Couleur racines' => 90,
            'Balayage complet' => 180,
            'Soin profond kératine' => 45,
            'Taille de barbe' => 20,
            default => 60,
        };
    }

    private function salonEclatReservationStart(User $owner, int $index): Carbon
    {
        $timezone = $owner->company_timezone ?: (string) config('app.timezone', 'UTC');
        $date = now($timezone)->startOfDay();
        $openDaysToAdvance = intdiv(max(0, $index), 3);

        while (true) {
            if (in_array($date->dayOfWeekIso, range(2, 6), true)) {
                if ($openDaysToAdvance === 0) {
                    break;
                }

                $openDaysToAdvance--;
            }

            $date->addDay();
        }

        return $date->setTime(10, 0)->utc();
    }

    private function salonEclatCompletedCheckoutStart(User $owner): Carbon
    {
        $timezone = $owner->company_timezone ?: (string) config('app.timezone', 'UTC');
        $date = now($timezone)->subDay()->startOfDay();

        while (! in_array($date->dayOfWeekIso, range(2, 6), true)) {
            $date->subDay();
        }

        return $date->setTime(14, 0)->utc();
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, TeamMember>  $teamMembers
     * @param  array{services: Collection<int, Product>, products: Collection<int, Product>}  $catalog
     * @return Collection<int, Expense>
     */
    private function createExpenses(
        User $owner,
        array $selectedModules,
        Collection $teamMembers,
        array $catalog,
        int $count,
        string $sector
    ): Collection {
        if (! in_array('expenses', $selectedModules, true)) {
            return collect();
        }

        $templates = $this->expenseTemplatesForContext($owner->company_type, $sector);
        $targetCount = max(4, $count);

        return collect(range(1, $targetCount))->map(function (int $index) use ($owner, $teamMembers, $catalog, $templates) {
            $template = $templates[($index - 1) % count($templates)];
            $creatorUserId = $owner->id;

            if (($template['created_by'] ?? 'owner') === 'team' && $teamMembers->isNotEmpty()) {
                $creatorUserId = (int) ($teamMembers[($index - 1) % $teamMembers->count()]->user_id ?: $owner->id);
            }

            $subtotal = round((float) ($template['subtotal'] ?? 0), 2);
            $taxRate = (float) ($template['tax_rate'] ?? 0.15);
            $taxAmount = round($subtotal * $taxRate, 2);
            $total = round($subtotal + $taxAmount, 2);
            $status = (string) ($template['status'] ?? Expense::STATUS_DRAFT);
            $expenseDate = now()->copy()->subDays((int) ($template['expense_days_ago'] ?? 0) + $index);
            $dueDate = $status === Expense::STATUS_DUE || $status === Expense::STATUS_SUBMITTED || $status === Expense::STATUS_APPROVED
                ? $expenseDate->copy()->addDays((int) ($template['due_in_days'] ?? 7))
                : null;
            $paidDate = in_array($status, [Expense::STATUS_PAID, Expense::STATUS_REIMBURSED], true)
                ? $expenseDate->copy()->addDays((int) ($template['paid_after_days'] ?? 1))
                : null;
            $approvedAt = in_array($status, [Expense::STATUS_APPROVED, Expense::STATUS_PAID, Expense::STATUS_REIMBURSED], true)
                ? $expenseDate->copy()->addDay()->setTime(10, 0)
                : null;
            $reference = sprintf('DEMO-EXP-%04d-%02d', $owner->id, $index);
            $linkedCatalog = $this->pickExpenseLinkedCatalogItem($template, $catalog, $index);

            $expense = Expense::create([
                'user_id' => $owner->id,
                'created_by_user_id' => $creatorUserId,
                'approved_by_user_id' => $approvedAt ? $owner->id : null,
                'paid_by_user_id' => $paidDate ? $owner->id : null,
                'title' => (string) $template['title'],
                'category_key' => $template['category_key'] ?? null,
                'supplier_name' => $template['supplier_name'] ?? null,
                'reference_number' => $reference,
                'currency_code' => $owner->businessCurrencyCode(),
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'expense_date' => $expenseDate->toDateString(),
                'due_date' => $dueDate?->toDateString(),
                'paid_date' => $paidDate?->toDateString(),
                'approved_at' => $approvedAt,
                'payment_method' => $template['payment_method'] ?? null,
                'status' => $status,
                'reimbursable' => (bool) ($template['reimbursable'] ?? false),
                'is_recurring' => (bool) ($template['is_recurring'] ?? false),
                'description' => (string) ($template['description'] ?? 'Demo-seeded expense for finance walkthroughs.'),
                'notes' => (string) ($template['notes'] ?? 'Prepared automatically for the demo workspace.'),
                'meta' => array_filter([
                    'seed_source' => 'demo_workspace',
                    'seed_template' => $template['key'] ?? Str::slug((string) $template['title']),
                    'linked_catalog_item' => $linkedCatalog?->name,
                    'linked_catalog_type' => $linkedCatalog?->item_type,
                ], fn ($value) => $value !== null && $value !== ''),
            ]);

            if (($template['attach_receipt'] ?? false) === true) {
                $this->createExpenseAttachmentDemoFile($expense, $owner, $template, $reference);
            }

            return $expense->load(['creator:id,name', 'approver:id,name', 'payer:id,name', 'attachments']);
        })->values();
    }

    /**
     * @param  array{services: Collection<int, Product>, products: Collection<int, Product>}  $catalog
     */
    private function pickExpenseLinkedCatalogItem(array $template, array $catalog, int $index): ?Product
    {
        $catalogType = $template['linked_catalog'] ?? null;
        $items = match ($catalogType) {
            'services' => $catalog['services'] ?? collect(),
            'products' => $catalog['products'] ?? collect(),
            default => collect(),
        };

        if (! $items instanceof Collection || $items->isEmpty()) {
            return null;
        }

        return $items[($index - 1) % $items->count()];
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function createExpenseAttachmentDemoFile(Expense $expense, User $owner, array $template, string $reference): ExpenseAttachment
    {
        $fileName = Str::slug($expense->title).'-'.$expense->id.'.txt';
        $path = 'demo/expenses/'.$owner->id.'/'.$fileName;
        $content = implode(PHP_EOL, [
            'Demo expense receipt',
            'Reference: '.$reference,
            'Company: '.$owner->company_name,
            'Title: '.$expense->title,
            'Supplier: '.($expense->supplier_name ?: 'N/A'),
            'Category: '.($expense->category_key ?: 'other'),
            'Status: '.$expense->status,
            'Total: '.$expense->total.' '.$expense->currency_code,
            'Notes: '.((string) ($template['notes'] ?? 'Prepared for the finance walkthrough.')),
        ]);

        Storage::disk('public')->put($path, $content);

        return ExpenseAttachment::create([
            'expense_id' => $expense->id,
            'user_id' => $owner->id,
            'path' => $path,
            'original_name' => 'receipt-'.$reference.'.txt',
            'mime' => 'text/plain',
            'size' => strlen($content),
            'meta' => [
                'seed_source' => 'demo_workspace',
            ],
        ]);
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Customer>  $customers
     * @return array{campaigns:int, mailing_lists:int}
     */
    private function createMarketing(
        User $owner,
        array $selectedModules,
        Collection $customers,
        ?Promotion $promotion = null,
        bool $isSalonEclat = false
    ): array {
        if (! in_array('campaigns', $selectedModules, true)) {
            return [
                'campaigns' => 0,
                'mailing_lists' => 0,
            ];
        }

        $mailingList = MailingList::create([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'name' => $isSalonEclat ? 'Clientes couleur' : 'VIP repeat customers',
            'description' => $isSalonEclat
                ? 'Clientes couleur, VIP et contacts à reconquérir pour la campagne Salon Éclat.'
                : 'Mailing list prepared for a tailored lifecycle campaign demo.',
            'tags' => $isSalonEclat ? ['couleur', 'vip', 'winback', 'salon-eclat'] : ['vip', 'repeat', 'demo'],
        ]);

        $mailingList->customers()->attach(
            $customers->take(min(5, $customers->count()))->mapWithKeys(fn (Customer $customer) => [
                $customer->id => [
                    'added_by_user_id' => $owner->id,
                    'added_at' => now()->subDays(3),
                ],
            ])->all()
        );
        $publicBookingUrl = $isSalonEclat
            ? PublicBookingLink::query()
                ->where('account_id', $owner->id)
                ->first()
                ?->publicUrl($owner)
            : null;

        $campaign = Campaign::create([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'name' => $isSalonEclat ? 'WINBACK — Vous nous manquez' : 'Spring retention push',
            'campaign_type' => $isSalonEclat ? Campaign::TYPE_WINBACK : Campaign::TYPE_PROMOTION,
            'campaign_direction' => Campaign::DIRECTION_CUSTOMER_MARKETING,
            'prospecting_enabled' => false,
            'offer_mode' => $owner->company_type === 'products' ? Campaign::OFFER_MODE_PRODUCTS : Campaign::OFFER_MODE_SERVICES,
            'language_mode' => CampaignTemplateLanguage::defaultModeForLocale($owner->locale),
            'type' => $isSalonEclat ? Campaign::TYPE_WINBACK : Campaign::TYPE_PROMOTION,
            'status' => Campaign::STATUS_DRAFT,
            'schedule_type' => Campaign::SCHEDULE_SCHEDULED,
            'scheduled_at' => $isSalonEclat ? now()->next('Tuesday')->setTime(10, 0) : now()->addDays(5),
            'locale' => $owner->locale,
            'cta_url' => $publicBookingUrl ?: '/pricing',
            'is_marketing' => true,
            'last_run_at' => null,
            'settings' => [
                'mailing_lists' => [$mailingList->id],
                'objective' => $isSalonEclat ? 'Winback' : 'Retention',
                'promotion_id' => $promotion?->id,
                'promotion_code' => $promotion?->code,
                'subject' => $isSalonEclat ? 'Claire, votre couleur nous manque ✨' : null,
                'message' => $isSalonEclat
                    ? 'Revenez chez Salon Éclat et profitez de 20 % sur votre prochaine coloration avec RENTREE20.'
                    : null,
                'seed_source' => $isSalonEclat ? 'salon_eclat_complete' : 'demo_workspace',
            ],
        ]);

        if ($isSalonEclat && $promotion?->target_id) {
            $campaign->products()->attach($promotion->target_id, [
                'metadata' => json_encode([
                    'promotion_code' => $promotion->code,
                    'seed_source' => 'salon_eclat_complete',
                ]),
            ]);
        }

        return [
            'campaigns' => 1,
            'mailing_lists' => 1,
        ];
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @return array{settings:int, knowledge_items:int}
     */
    private function createSalonEclatAssistant(User $owner, array $selectedModules, bool $isSalonEclat): array
    {
        if (! $isSalonEclat || ! in_array('assistant', $selectedModules, true)) {
            return ['settings' => 0, 'knowledge_items' => 0];
        }

        AiAssistantSetting::query()->updateOrCreate(
            ['tenant_id' => $owner->id],
            [
                'assistant_name' => 'Éclat, votre assistante beauté',
                'enabled' => true,
                'default_language' => AiAssistantSetting::LANGUAGE_FR,
                'supported_languages' => [AiAssistantSetting::LANGUAGE_FR, AiAssistantSetting::LANGUAGE_EN],
                'tone' => AiAssistantSetting::TONE_WARM,
                'greeting_message' => 'Bonjour et bienvenue chez Salon Éclat ✨ Comment puis-je vous aider à choisir ou réserver une prestation?',
                'fallback_message' => 'Je transmets votre demande à Sophie, notre responsable de réception.',
                'allow_create_prospect' => true,
                'allow_create_client' => true,
                'allow_create_reservation' => true,
                'allow_reschedule_reservation' => true,
                'allow_create_task' => false,
                'require_human_validation' => true,
                'enable_proactive_suggestions' => true,
                'enable_upsell_suggestions' => true,
                'enable_client_history_recommendations' => true,
                'max_suggestions_per_response' => 3,
                'require_confirmation_before_ai_action' => true,
                'allow_ai_to_choose_earliest_slot' => true,
                'allow_ai_to_recommend_staff' => true,
                'allow_ai_to_recommend_services' => true,
                'business_context' => 'Salon de coiffure et barbier à Montréal. Coupes, couleur, coiffage, soins capillaires et services de barbier.',
                'working_hours_rules' => [
                    'monday' => 'closed',
                    'tuesday_friday' => '09:00-19:00',
                    'saturday' => '09:00-17:00',
                    'sunday' => 'closed',
                    'timezone' => 'America/Toronto',
                ],
            ]
        );

        $knowledgeItems = collect([
            ['title' => 'Horaires du salon', 'category' => 'horaires', 'content' => 'Salon Éclat est ouvert du mardi au vendredi de 9 h à 19 h et le samedi de 9 h à 17 h. Le salon est fermé le dimanche et le lundi.'],
            ['title' => 'Prestations vedettes', 'category' => 'services', 'content' => 'Le balayage complet dure environ 180 minutes. La coupe femme avec brushing dure 60 minutes. La coupe homme et le brushing seul durent 30 minutes.'],
            ['title' => 'Réservation et acompte', 'category' => 'reservations', 'content' => 'Les rendez-vous peuvent être réservés jusqu’à 60 jours à l’avance. Un acompte de 20 $ peut être demandé pour les longues prestations.'],
            ['title' => 'Annulation et retard', 'category' => 'politiques', 'content' => 'Une annulation est permise jusqu’à 24 heures avant le rendez-vous. Des frais d’absence de 25 $ peuvent s’appliquer.'],
        ])->map(fn (array $item) => AiKnowledgeItem::query()->create([
            'tenant_id' => $owner->id,
            ...$item,
            'is_active' => true,
        ]));

        return [
            'settings' => 1,
            'knowledge_items' => $knowledgeItems->count(),
        ];
    }

    /**
     * @param  array<int, string>  $selectedModules
     */
    private function createSalonEclatSocialContent(User $owner, array $selectedModules, bool $isSalonEclat): int
    {
        if (! $isSalonEclat || ! in_array('social', $selectedModules, true)) {
            return 0;
        }

        $publicBookingUrl = PublicBookingLink::query()
            ->where('account_id', $owner->id)
            ->first()
            ?->publicUrl($owner);

        SocialPost::query()->create([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'source_type' => 'demo_workspace',
            'content_payload' => [
                'text' => 'Nouveau balayage chez Salon Éclat ✨ Des reflets lumineux, un résultat sur mesure et des cheveux pleins de vie. Réservez votre diagnostic couleur.',
                'locale' => 'fr',
                'hashtags' => ['SalonEclat', 'BalayageMontreal', 'CoiffureMontreal'],
            ],
            'media_payload' => [
                'brief' => 'Avant/après balayage lumineux dans l’univers vert et or de Salon Éclat.',
            ],
            'link_url' => $publicBookingUrl ?: url('/'),
            'status' => SocialPost::STATUS_SCHEDULED,
            'scheduled_for' => now()->addDays(3)->setTime(18, 30),
            'metadata' => [
                'seed_source' => 'salon_eclat_complete',
                'quality_score' => 92,
                'approval_ready' => true,
            ],
        ]);

        return 1;
    }

    /**
     * @return array<string, string>
     */
    private function generateCredentials(string $companyName): array
    {
        $domain = config('demo.accounts_email_domain', 'example.test');
        $base = Str::slug($companyName) ?: 'demo-workspace';
        $email = $base.'-'.Str::lower(Str::random(6)).'@'.$domain;

        while (User::query()->where('email', $email)->exists()) {
            $email = $base.'-'.Str::lower(Str::random(6)).'@'.$domain;
        }

        return [
            'email' => $email,
            'password' => 'Demo!'.Str::upper(Str::random(6)),
        ];
    }

    private function resolveRoleId(string $name, string $description): int
    {
        return Role::query()->firstOrCreate(
            ['name' => $name],
            ['description' => $description]
        )->id;
    }

    /**
     * @return array<string, int>
     */
    private function buildLimits(string $seedProfile): array
    {
        $counts = $this->catalog->seedCounts($seedProfile);

        return [
            'quotes' => max(50, ($counts['quotes'] ?? 4) * 12),
            'requests' => max(50, ($counts['quotes'] ?? 4) * 10),
            'jobs' => max(50, ($counts['works'] ?? 4) * 12),
            'tasks' => max(80, ($counts['tasks'] ?? 8) * 10),
            'invoices' => 80,
            'products' => max(50, ($counts['catalog'] ?? 10) * 8),
            'services' => max(30, ($counts['catalog'] ?? 10) * 4),
            'team_members' => max(5, ($counts['team'] ?? 3) * 2),
            'sales' => max(30, ($counts['sales'] ?? 4) * 10),
            'plan_scans' => 25,
        ];
    }

    private function currencyForTimezone(string $timezone): string
    {
        return match ($timezone) {
            'Europe/Paris' => 'EUR',
            'Europe/London' => 'GBP',
            default => 'CAD',
        };
    }

    private function countryForTimezone(string $timezone): string
    {
        return match ($timezone) {
            'Europe/Paris' => 'France',
            'Europe/London' => 'United Kingdom',
            'America/New_York' => 'United States',
            default => 'Canada',
        };
    }

    private function cityForSector(string $sector): string
    {
        return match ($sector) {
            'salon', 'wellness' => 'Montreal',
            'restaurant' => 'Paris',
            'retail' => 'Toronto',
            'field_services' => 'Laval',
            default => 'Montreal',
        };
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function minimumTeamCountForAccessRoles(array $roles): int
    {
        return collect($roles)
            ->filter(fn ($role) => is_string($role) && trim($role) !== '')
            ->unique()
            ->count();
    }

    /**
     * @return array{credential: array<string, mixed>, team_member: TeamMember|null, user: User|null, role_label: string}
     */
    private function resolveExtraAccessAssignment(
        DemoWorkspace $workspace,
        string $roleKey,
        bool $preferInactive = false
    ): array {
        $labelMap = $this->extraAccessLabelMap();
        $credential = collect($workspace->extra_access_credentials ?? [])
            ->first(fn ($item) => is_array($item) && (string) ($item['role_key'] ?? '') === $roleKey);
        $credential = is_array($credential) ? $credential : [];

        $teamMembers = TeamMember::query()
            ->where('account_id', $workspace->owner_user_id)
            ->with('user:id,name,email')
            ->get();

        $teamMemberId = (int) ($credential['team_member_id'] ?? 0);
        $userId = (int) ($credential['user_id'] ?? 0);

        $teamMember = $teamMemberId > 0
            ? $teamMembers->firstWhere('id', $teamMemberId)
            : null;

        if (! $teamMember && $userId > 0) {
            $teamMember = $teamMembers->firstWhere('user_id', $userId);
        }

        if (! $teamMember) {
            $matches = $teamMembers
                ->filter(fn (TeamMember $candidate) => $this->matchesExtraAccessRole($candidate, $roleKey))
                ->values();

            $teamMember = $preferInactive
                ? ($matches->sortBy(fn (TeamMember $candidate) => $candidate->is_active ? 1 : 0)->first())
                : ($matches->sortByDesc(fn (TeamMember $candidate) => $candidate->is_active ? 1 : 0)->first());
        }

        return [
            'credential' => $credential,
            'team_member' => $teamMember,
            'user' => $teamMember?->user,
            'role_label' => (string) ($credential['role_label'] ?? $labelMap[$roleKey] ?? $roleKey),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, array<string, mixed>>
     */
    private function upsertExtraAccessCredential(DemoWorkspace $workspace, string $roleKey, array $attributes): array
    {
        $labelMap = $this->extraAccessLabelMap();
        $credentialsByRole = collect($workspace->extra_access_credentials ?? [])
            ->filter(fn ($credential) => is_array($credential) && is_string($credential['role_key'] ?? null))
            ->mapWithKeys(fn (array $credential) => [(string) $credential['role_key'] => $credential])
            ->all();

        $credentialsByRole[$roleKey] = [
            ...($credentialsByRole[$roleKey] ?? []),
            'role_key' => $roleKey,
            'role_label' => $labelMap[$roleKey] ?? $roleKey,
            'login_url' => url('/login'),
            ...$attributes,
        ];

        $orderedRoles = collect($workspace->extra_access_roles ?? [])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->merge(array_keys($credentialsByRole))
            ->unique()
            ->values();

        return $orderedRoles
            ->map(function (string $key) use ($credentialsByRole, $labelMap) {
                return $credentialsByRole[$key] ?? [
                    'role_key' => $key,
                    'role_label' => $labelMap[$key] ?? $key,
                    'login_url' => url('/login'),
                    'status' => 'pending',
                    'is_active' => false,
                ];
            })
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function extraAccessLabelMap(): array
    {
        return collect($this->catalog->extraAccessRoles())
            ->mapWithKeys(fn (array $role) => [(string) $role['key'] => (string) ($role['label'] ?? $role['key'])])
            ->all();
    }

    private function matchesExtraAccessRole(TeamMember $member, string $roleKey): bool
    {
        return match ($roleKey) {
            'manager' => $member->role === 'admin',
            'front_desk' => $member->role === 'sales_manager'
                || str_contains(strtolower((string) $member->title), 'front desk')
                || str_contains(strtolower((string) $member->title), 'reception')
                || str_contains(strtolower((string) $member->title), 'réception'),
            'staff' => $member->role === 'member',
            default => false,
        };
    }

    private function generateExtraAccessPassword(): string
    {
        return 'Demo!'.Str::upper(Str::random(6));
    }

    /**
     * @param  array<int, string>  $requestedRoles
     * @return array<int, array<string, mixed>>
     */
    private function buildExtraAccessCredentials(User $owner, array $requestedRoles): array
    {
        if ($requestedRoles === []) {
            return [];
        }

        $teamMembers = TeamMember::query()
            ->where('account_id', $owner->id)
            ->with('user:id,name,email')
            ->get();
        $labels = $this->extraAccessLabelMap();

        $assigned = [];

        foreach ($requestedRoles as $roleKey) {
            if (! isset($labels[$roleKey])) {
                continue;
            }

            $member = $teamMembers
                ->first(function (TeamMember $candidate) use ($roleKey, $assigned) {
                    if (in_array($candidate->id, $assigned, true)) {
                        return false;
                    }

                    return $this->matchesExtraAccessRole($candidate, $roleKey);
                });

            if (! $member || ! $member->user) {
                continue;
            }

            $assigned[] = $member->id;

            $credentials[] = [
                'role_key' => $roleKey,
                'role_label' => (string) ($labels[$roleKey] ?? $roleKey),
                'team_member_id' => $member->id,
                'user_id' => $member->user->id,
                'name' => (string) $member->user->name,
                'title' => (string) ($member->title ?? $member->role),
                'email' => (string) $member->user->email,
                'password' => 'password',
                'login_url' => url('/login'),
                'status' => 'active',
                'is_active' => true,
            ];
        }

        return $credentials ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teamProfilesForSector(string $sector, bool $isSalonEclat = false): array
    {
        if ($isSalonEclat) {
            return [
                ['name' => 'Sophie Tremblay', 'title' => 'Admin — Réception', 'role' => 'admin'],
                ['name' => 'Karim Benali', 'title' => 'Coiffeur', 'role' => 'member'],
                ['name' => 'Léa Moreau', 'title' => 'Coloriste', 'role' => 'member'],
            ];
        }

        return match ($sector) {
            'salon', 'wellness' => [
                ['name' => 'Maya Brooks', 'title' => 'Senior Stylist', 'role' => 'admin'],
                ['name' => 'Noah Turner', 'title' => 'Barber', 'role' => 'member'],
                ['name' => 'Lina Carter', 'title' => 'Front Desk Lead', 'role' => 'sales_manager'],
                ['name' => 'Jules Rivers', 'title' => 'Color Specialist', 'role' => 'member'],
            ],
            'retail' => [
                ['name' => 'Emma Cole', 'title' => 'Store Manager', 'role' => 'admin'],
                ['name' => 'Lucas Hart', 'title' => 'Sales Lead', 'role' => 'sales_manager'],
                ['name' => 'Nina Vale', 'title' => 'Floor Specialist', 'role' => 'member'],
            ],
            default => [
                ['name' => 'Alex Carter', 'title' => 'Operations Lead', 'role' => 'admin'],
                ['name' => 'Sam Rivera', 'title' => 'Field Specialist', 'role' => 'member'],
                ['name' => 'Taylor Reed', 'title' => 'Account Coordinator', 'role' => 'sales_manager'],
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    private function permissionsForTeamRole(string $role): array
    {
        return match ($role) {
            'admin' => ['jobs.view', 'jobs.edit', 'tasks.view', 'tasks.edit', 'sales.manage', 'reservations.manage'],
            'sales_manager' => ['sales.manage', 'quotes.view', 'quotes.edit', 'reservations.view'],
            default => ['jobs.view', 'tasks.view', 'tasks.edit', 'reservations.view'],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serviceCatalogForSector(string $sector, bool $isSalonEclat = false): array
    {
        if ($isSalonEclat) {
            return [
                ['category' => 'Coupe', 'name' => 'Coupe femme + brushing', 'description' => 'Consultation, coupe personnalisée et brushing de finition.', 'duration' => 60, 'price' => 65],
                ['category' => 'Coupe', 'name' => 'Coupe homme', 'description' => 'Coupe homme, contours et coiffage.', 'duration' => 30, 'price' => 35],
                ['category' => 'Coupe', 'name' => 'Coupe enfant (-12 ans)', 'description' => 'Coupe douce et adaptée aux enfants de moins de douze ans.', 'duration' => 30, 'price' => 25],
                ['category' => 'Coloration', 'name' => 'Couleur racines', 'description' => 'Coloration des repousses, émulsion et mise en forme.', 'duration' => 90, 'price' => 95],
                ['category' => 'Coloration', 'name' => 'Balayage complet', 'description' => 'Diagnostic couleur, balayage complet, patine et coiffage.', 'duration' => 180, 'price' => 210],
                ['category' => 'Coiffage', 'name' => 'Brushing seul', 'description' => 'Shampoing et brushing professionnel.', 'duration' => 30, 'price' => 35],
                ['category' => 'Coiffage', 'name' => 'Chignon / événement', 'description' => 'Coiffure événementielle personnalisée avec préparation.', 'duration' => 60, 'price' => 85],
                ['category' => 'Soin capillaire', 'name' => 'Soin profond kératine', 'description' => 'Soin réparateur à la kératine et finition brillante.', 'duration' => 45, 'price' => 75],
                ['category' => 'Barbier', 'name' => 'Taille de barbe', 'description' => 'Taille, contours et huile de finition.', 'duration' => 20, 'price' => 25],
                ['category' => 'Barbier', 'name' => 'Rasage traditionnel', 'description' => 'Rasage au blaireau, serviette chaude et soin apaisant.', 'duration' => 30, 'price' => 40],
            ];
        }

        return match ($sector) {
            'salon', 'wellness' => [
                ['name' => 'Signature cut', 'description' => 'Haircut with consultation and finish.', 'price' => 55],
                ['name' => 'Beard sculpt', 'description' => 'Precision beard shaping and treatment.', 'price' => 35],
                ['name' => 'Keratin care', 'description' => 'Smoothing treatment for damaged hair.', 'price' => 120],
                ['name' => 'Color refresh', 'description' => 'Tone and gloss package.', 'price' => 95],
                ['name' => 'Express spa ritual', 'description' => 'Quick relaxation and treatment session.', 'price' => 80],
            ],
            'restaurant' => [
                ['name' => 'Lunch tasting', 'description' => 'Menu tasting slot for partners.', 'price' => 40],
                ['name' => 'Private table booking', 'description' => 'Reserved premium seating experience.', 'price' => 65],
                ['name' => 'Chef consultation', 'description' => 'Custom event planning session.', 'price' => 150],
                ['name' => 'Catering assessment', 'description' => 'On-site catering planning meeting.', 'price' => 90],
            ],
            default => [
                ['name' => 'Site assessment', 'description' => 'On-site discovery and scoping visit.', 'price' => 120],
                ['name' => 'Installation package', 'description' => 'Delivery, setup, and QA handoff.', 'price' => 340],
                ['name' => 'Monthly maintenance', 'description' => 'Recurring service visit with reporting.', 'price' => 180],
                ['name' => 'Emergency intervention', 'description' => 'Priority same-day dispatch slot.', 'price' => 260],
            ],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function productCatalogForSector(string $sector, bool $isSalonEclat = false): array
    {
        if ($isSalonEclat) {
            return [
                ['name' => 'Shampoing réparateur 250 ml', 'description' => 'Shampoing professionnel réparateur pour cheveux fragilisés.', 'price' => 28, 'stock' => 24, 'minimum_stock' => 6, 'supplier' => 'Maison Capillaire Montréal'],
                ['name' => 'Après-shampoing hydratant', 'description' => 'Soin quotidien hydratant et démêlant.', 'price' => 26, 'stock' => 18, 'minimum_stock' => 5, 'supplier' => 'Maison Capillaire Montréal'],
                ['name' => 'Huile capillaire argan 100 ml', 'description' => 'Huile d’argan légère pour nourrir et faire briller.', 'price' => 34, 'stock' => 12, 'minimum_stock' => 4, 'supplier' => 'Argan Boréal'],
                ['name' => 'Cire coiffante mate', 'description' => 'Cire mate à tenue souple pour une finition naturelle.', 'price' => 22, 'stock' => 30, 'minimum_stock' => 8, 'supplier' => 'Barbier Urbain'],
                ['name' => 'Peigne bois artisanal', 'description' => 'Peigne en bois antistatique fabriqué au Québec.', 'price' => 18, 'stock' => 8, 'minimum_stock' => 4, 'supplier' => 'Atelier Bois & Barbe'],
            ];
        }

        return match ($sector) {
            'salon', 'wellness' => [
                ['name' => 'Hydration shampoo', 'description' => 'Retail shampoo for dry hair.', 'price' => 28],
                ['name' => 'Beard oil', 'description' => 'Finishing oil with cedar notes.', 'price' => 22],
                ['name' => 'Keratin mask', 'description' => 'Weekly restorative treatment.', 'price' => 34],
                ['name' => 'Matte styling clay', 'description' => 'Flexible hold styling clay.', 'price' => 26],
                ['name' => 'Scalp serum', 'description' => 'Cooling leave-in scalp treatment.', 'price' => 31],
            ],
            default => [
                ['name' => 'Starter kit', 'description' => 'High-margin entry bundle for new customers.', 'price' => 79],
                ['name' => 'Premium bundle', 'description' => 'Most requested package with accessories.', 'price' => 149],
                ['name' => 'Refill pack', 'description' => 'Repeat purchase pack for loyal customers.', 'price' => 39],
                ['name' => 'Pro accessory', 'description' => 'Upsell item for advanced users.', 'price' => 54],
                ['name' => 'Gift set', 'description' => 'Seasonal gifting package.', 'price' => 95],
            ],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customerProfilesForSector(string $sector, bool $isSalonEclat = false): array
    {
        if ($isSalonEclat) {
            return [
                ['first_name' => 'Marie', 'last_name' => 'Lefebvre', 'description' => 'Cliente fidèle, forte en points et détentrice de la Carte 10 brushings avec sept séances restantes.', 'tags' => ['fidèle', 'brushing', 'forfait'], 'salutation' => 'Mrs', 'logo' => '/images/presets/avatar-1.svg'],
                ['first_name' => 'Julie', 'last_name' => 'Nadeau', 'description' => 'Nouvelle cliente arrivée par le lien de réservation public.', 'tags' => ['nouvelle', 'réservation-web'], 'salutation' => 'Mrs', 'logo' => '/images/presets/avatar-2.svg'],
                ['first_name' => 'Fatou', 'last_name' => 'Camara', 'description' => 'Cliente VIP Or avec un panier élevé et des rendez-vous couleur réguliers.', 'tags' => ['vip', 'couleur', 'premium'], 'salutation' => 'Mrs', 'logo' => '/images/presets/avatar-3.svg'],
                ['first_name' => 'Thomas', 'last_name' => 'Roy', 'description' => 'Client barbier avec un abonnement mensuel de deux tailles.', 'tags' => ['barbier', 'abonnement', 'mensuel'], 'salutation' => 'Mr', 'logo' => '/images/presets/avatar-4.svg'],
                ['first_name' => 'Claire', 'last_name' => 'Dubois', 'description' => 'Cliente à reconquérir : dernière visite il y a cinq mois, cible de la campagne WINBACK.', 'tags' => ['winback', 'inactive-150j', 'couleur'], 'salutation' => 'Mrs'],
                ['first_name' => 'Nicolas', 'last_name' => 'Gagnon', 'description' => 'Walk-in converti en client régulier après une coupe homme.', 'tags' => ['walk-in', 'coupe-homme'], 'salutation' => 'Mr'],
                ['first_name' => 'Isabelle', 'last_name' => 'Fortin', 'description' => 'Réserve un balayage avant chaque changement de saison.', 'tags' => ['balayage', 'saisonnier'], 'salutation' => 'Mrs'],
                ['first_name' => 'Élodie', 'last_name' => 'Martel', 'description' => 'Cliente événementiel, intéressée par les chignons et les soins.', 'tags' => ['événement', 'coiffage'], 'salutation' => 'Mrs'],
                ['first_name' => 'Marc', 'last_name' => 'Bouchard', 'description' => 'Client fidèle pour coupe homme et rasage traditionnel.', 'tags' => ['barbier', 'fidèle'], 'salutation' => 'Mr'],
                ['first_name' => 'Sonia', 'last_name' => 'Bélanger', 'description' => 'Achète régulièrement des produits hydratants après ses services.', 'tags' => ['retail', 'hydratation'], 'salutation' => 'Mrs'],
                ['first_name' => 'Camille', 'last_name' => 'Bergeron', 'description' => 'Jeune cliente attirée par les nouveautés couleur sur Instagram.', 'tags' => ['social', 'couleur'], 'salutation' => 'Mrs'],
                ['first_name' => 'Antoine', 'last_name' => 'Mercier', 'description' => 'Client pressé qui privilégie les rendez-vous tôt le matin.', 'tags' => ['matin', 'coupe-homme'], 'salutation' => 'Mr'],
                ['first_name' => 'Nadia', 'last_name' => 'Haddad', 'description' => 'Cliente soin profond avec recommandation produit personnalisée.', 'tags' => ['soin', 'kératine'], 'salutation' => 'Mrs'],
                ['first_name' => 'Gabriel', 'last_name' => 'Lavoie', 'description' => 'Nouveau client référé par Thomas pour les services de barbier.', 'tags' => ['référence', 'barbier'], 'salutation' => 'Mr'],
                ['first_name' => 'Chloé', 'last_name' => 'Pelletier', 'description' => 'Cliente régulière qui réserve et replanifie depuis son portail.', 'tags' => ['portail', 'régulière'], 'salutation' => 'Mrs'],
                ['first_name' => 'Mélanie', 'last_name' => 'Giroux', 'description' => 'Cliente sensible aux promotions saisonnières de coloration.', 'tags' => ['promotion', 'couleur'], 'salutation' => 'Mrs'],
                ['first_name' => 'Jean', 'last_name' => 'Côté', 'description' => 'Client rasage traditionnel avec forte satisfaction.', 'tags' => ['rasage', 'avis-5-étoiles'], 'salutation' => 'Mr'],
                ['first_name' => 'Roxane', 'last_name' => 'Simard', 'description' => 'Cliente balayage à panier élevé et achats produits récurrents.', 'tags' => ['balayage', 'retail', 'premium'], 'salutation' => 'Mrs'],
                ['first_name' => 'Olivier', 'last_name' => 'Nguyen', 'description' => 'Client mensuel, souvent ajouté à la file depuis le kiosque.', 'tags' => ['kiosque', 'mensuel'], 'salutation' => 'Mr'],
                ['first_name' => 'Anaïs', 'last_name' => 'Beaulieu', 'description' => 'Prospect converti par l’assistant grâce à une recommandation de soin.', 'tags' => ['assistant', 'conversion'], 'salutation' => 'Mrs'],
            ];
        }

        return match ($sector) {
            'salon', 'wellness' => [
                ['first_name' => 'Sarah', 'last_name' => 'Parker', 'company_name' => 'Studio North', 'description' => 'High-value repeat client.', 'tags' => ['vip', 'color']],
                ['first_name' => 'Kevin', 'last_name' => 'Moore', 'company_name' => 'Atelier KM', 'description' => 'Needs fast recurring appointments.', 'tags' => ['beard', 'monthly']],
                ['first_name' => 'Amelie', 'last_name' => 'Roy', 'company_name' => 'Roy Creative', 'description' => 'Books premium treatment packages.', 'tags' => ['premium']],
                ['first_name' => 'David', 'last_name' => 'Lopez', 'company_name' => 'Lopez Legal', 'description' => 'Walk-in converted to regular.', 'tags' => ['walk-in']],
            ],
            'retail' => [
                ['first_name' => 'Sophie', 'last_name' => 'Nguyen', 'company_name' => 'North Market', 'description' => 'Strong average order value.', 'tags' => ['retail', 'repeat']],
                ['first_name' => 'Marcus', 'last_name' => 'Bell', 'company_name' => 'Bell & Co', 'description' => 'Responds well to promotions.', 'tags' => ['promo']],
                ['first_name' => 'Elena', 'last_name' => 'Martin', 'company_name' => 'Maison Martin', 'description' => 'High-potential loyalty prospect.', 'tags' => ['vip']],
                ['first_name' => 'Jordan', 'last_name' => 'Lee', 'company_name' => 'JL Studio', 'description' => 'Frequent pickup customer.', 'tags' => ['pickup']],
            ],
            default => [
                ['first_name' => 'Olivia', 'last_name' => 'Green', 'company_name' => 'Green Properties', 'description' => 'Multi-site account with ongoing needs.', 'tags' => ['account', 'multi-site']],
                ['first_name' => 'Michael', 'last_name' => 'Stone', 'company_name' => 'Stone Logistics', 'description' => 'Needs rapid response and reporting.', 'tags' => ['priority']],
                ['first_name' => 'Chloe', 'last_name' => 'Benoit', 'company_name' => 'Benoit Design', 'description' => 'Values polished quoting flow.', 'tags' => ['quote']],
                ['first_name' => 'Ethan', 'last_name' => 'Cole', 'company_name' => 'Cole Ventures', 'description' => 'Good upsell and maintenance potential.', 'tags' => ['upsell']],
            ],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function expenseTemplatesForContext(string $companyType, string $sector): array
    {
        if ($companyType === 'products') {
            return [
                [
                    'key' => 'inventory-restock',
                    'title' => 'Inventory restock order',
                    'category_key' => 'inventory',
                    'supplier_name' => 'North Supply Distribution',
                    'subtotal' => 420,
                    'tax_rate' => 0.15,
                    'status' => Expense::STATUS_DUE,
                    'payment_method' => 'bank_transfer',
                    'expense_days_ago' => 9,
                    'due_in_days' => 7,
                    'description' => 'Restock order seeded to support the commerce margin and cash-out demo.',
                    'notes' => 'Vendor terms are net 7 to show upcoming outflow.',
                    'linked_catalog' => 'products',
                    'attach_receipt' => true,
                ],
                [
                    'key' => 'packaging-supplies',
                    'title' => 'Packaging and shipping supplies',
                    'category_key' => 'materials',
                    'supplier_name' => 'Parcel Ready Depot',
                    'subtotal' => 165,
                    'tax_rate' => 0.15,
                    'status' => Expense::STATUS_PAID,
                    'payment_method' => 'card',
                    'expense_days_ago' => 14,
                    'paid_after_days' => 1,
                    'description' => 'Consumables tied to order fulfillment and delivery prep.',
                    'notes' => 'Paid on the operations card.',
                    'attach_receipt' => true,
                ],
                [
                    'key' => 'pos-subscription',
                    'title' => 'Point-of-sale software subscription',
                    'category_key' => 'software',
                    'supplier_name' => 'Checkout Cloud',
                    'subtotal' => 89,
                    'tax_rate' => 0.15,
                    'status' => Expense::STATUS_PAID,
                    'payment_method' => 'card',
                    'expense_days_ago' => 21,
                    'paid_after_days' => 0,
                    'is_recurring' => true,
                    'description' => 'Recurring software cost used in the monthly operating spend view.',
                    'notes' => 'Auto-renews monthly.',
                    'attach_receipt' => true,
                ],
                [
                    'key' => 'seasonal-campaign',
                    'title' => 'Seasonal campaign creative spend',
                    'category_key' => 'marketing',
                    'supplier_name' => 'Studio Meridian',
                    'subtotal' => 230,
                    'tax_rate' => 0.15,
                    'status' => Expense::STATUS_APPROVED,
                    'payment_method' => 'bank_transfer',
                    'expense_days_ago' => 5,
                    'due_in_days' => 5,
                    'description' => 'Creative production spend prepared for launch week marketing.',
                    'notes' => 'Approved and waiting for finance release.',
                ],
                [
                    'key' => 'merchant-fees',
                    'title' => 'Merchant and platform fees',
                    'category_key' => 'taxes_fees',
                    'supplier_name' => 'Demo Payments',
                    'subtotal' => 58,
                    'tax_rate' => 0,
                    'status' => Expense::STATUS_PAID,
                    'payment_method' => 'bank_transfer',
                    'expense_days_ago' => 11,
                    'paid_after_days' => 1,
                    'description' => 'Processing and payout fees aligned with seeded sales activity.',
                    'notes' => 'Collected from last payout batch.',
                ],
            ];
        }

        return match ($sector) {
            'salon', 'wellness' => [
                [
                    'key' => 'color-stock',
                    'title' => 'Color and treatment stock refill',
                    'category_key' => 'materials',
                    'supplier_name' => 'Beauty Pro Supply',
                    'subtotal' => 285,
                    'tax_rate' => 0.15,
                    'status' => Expense::STATUS_PAID,
                    'payment_method' => 'card',
                    'expense_days_ago' => 8,
                    'paid_after_days' => 0,
                    'description' => 'Routine refill for color, treatment, and front-desk consumables.',
                    'notes' => 'Restocked before the weekend rush.',
                    'linked_catalog' => 'services',
                    'attach_receipt' => true,
                ],
                [
                    'key' => 'booking-software',
                    'title' => 'Booking and reminder software',
                    'category_key' => 'software',
                    'supplier_name' => 'Schedule Flow',
                    'subtotal' => 79,
                    'tax_rate' => 0.15,
                    'status' => Expense::STATUS_PAID,
                    'payment_method' => 'card',
                    'expense_days_ago' => 18,
                    'paid_after_days' => 0,
                    'is_recurring' => true,
                    'description' => 'Recurring operating cost for reminders, forms, and calendar sync.',
                    'notes' => 'Auto-billed to the owner card.',
                    'attach_receipt' => true,
                ],
                [
                    'key' => 'stylist-reimbursement',
                    'title' => 'Stylist mileage reimbursement',
                    'category_key' => 'reimbursement',
                    'supplier_name' => 'Internal reimbursement',
                    'subtotal' => 42,
                    'tax_rate' => 0,
                    'status' => Expense::STATUS_REIMBURSED,
                    'payment_method' => 'mobile_money',
                    'expense_days_ago' => 6,
                    'paid_after_days' => 1,
                    'reimbursable' => true,
                    'created_by' => 'team',
                    'description' => 'Travel reimbursement seeded to show staff-related spend.',
                    'notes' => 'Submitted by a team member and reimbursed by finance.',
                ],
                [
                    'key' => 'equipment-maintenance',
                    'title' => 'Chair and dryer maintenance',
                    'category_key' => 'equipment',
                    'supplier_name' => 'Studio Repair Co.',
                    'subtotal' => 165,
                    'tax_rate' => 0.15,
                    'status' => Expense::STATUS_DUE,
                    'payment_method' => 'bank_transfer',
                    'expense_days_ago' => 4,
                    'due_in_days' => 6,
                    'description' => 'Upcoming maintenance invoice for salon hardware upkeep.',
                    'notes' => 'Scheduled before the holiday peak.',
                    'attach_receipt' => true,
                ],
                [
                    'key' => 'local-promo',
                    'title' => 'Local flyer and promo print run',
                    'category_key' => 'marketing',
                    'supplier_name' => 'Print Loft',
                    'subtotal' => 110,
                    'tax_rate' => 0.15,
                    'status' => Expense::STATUS_SUBMITTED,
                    'payment_method' => 'bank_transfer',
                    'expense_days_ago' => 3,
                    'due_in_days' => 10,
                    'description' => 'Offline marketing spend created for the local acquisition story.',
                    'notes' => 'Submitted for approval before launch.',
                ],
            ],
            default => [
                [
                    'key' => 'vehicle-fuel',
                    'title' => 'Fleet fuel refill',
                    'category_key' => 'fuel',
                    'supplier_name' => 'Route 24 Energy',
                    'subtotal' => 126,
                    'tax_rate' => 0.15,
                    'status' => Expense::STATUS_PAID,
                    'payment_method' => 'card',
                    'expense_days_ago' => 7,
                    'paid_after_days' => 0,
                    'created_by' => 'team',
                    'description' => 'Field operations spend to support route-based service demos.',
                    'notes' => 'Charged during weekly route prep.',
                    'attach_receipt' => true,
                ],
                [
                    'key' => 'software-suite',
                    'title' => 'Operations software suite',
                    'category_key' => 'software',
                    'supplier_name' => 'Ops Desk',
                    'subtotal' => 119,
                    'tax_rate' => 0.15,
                    'status' => Expense::STATUS_PAID,
                    'payment_method' => 'card',
                    'expense_days_ago' => 16,
                    'paid_after_days' => 0,
                    'is_recurring' => true,
                    'description' => 'Recurring software spend for project, quote, and internal ops.',
                    'notes' => 'Monthly subscription.',
                    'attach_receipt' => true,
                ],
                [
                    'key' => 'subcontractor-bill',
                    'title' => 'Subcontractor invoice',
                    'category_key' => 'professional_services',
                    'supplier_name' => 'Precision Partners',
                    'subtotal' => 340,
                    'tax_rate' => 0.15,
                    'status' => Expense::STATUS_APPROVED,
                    'payment_method' => 'bank_transfer',
                    'expense_days_ago' => 5,
                    'due_in_days' => 4,
                    'description' => 'External specialist cost used to show approval and due states.',
                    'notes' => 'Approved after job review.',
                ],
                [
                    'key' => 'permit-fee',
                    'title' => 'Permit and filing fees',
                    'category_key' => 'taxes_fees',
                    'supplier_name' => 'City Services',
                    'subtotal' => 74,
                    'tax_rate' => 0,
                    'status' => Expense::STATUS_PAID,
                    'payment_method' => 'bank_transfer',
                    'expense_days_ago' => 12,
                    'paid_after_days' => 1,
                    'description' => 'Administrative fees associated with compliant service delivery.',
                    'notes' => 'Paid during pre-job preparation.',
                ],
                [
                    'key' => 'tool-rental',
                    'title' => 'Specialty equipment rental',
                    'category_key' => 'equipment',
                    'supplier_name' => 'Field Gear Rental',
                    'subtotal' => 210,
                    'tax_rate' => 0.15,
                    'status' => Expense::STATUS_DUE,
                    'payment_method' => 'cheque',
                    'expense_days_ago' => 2,
                    'due_in_days' => 8,
                    'description' => 'Rental invoice created to show upcoming short-term equipment costs.',
                    'notes' => 'Reserved for the next project sprint.',
                    'attach_receipt' => true,
                ],
            ],
        };
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function serviceRequestSourceFromLeadChannel(string $channel): array
    {
        $normalized = strtolower(trim($channel));

        return match ($normalized) {
            'web', 'website', 'web_form' => ['public_form', 'web'],
            'phone', 'call' => ['manual_admin', 'phone'],
            'email', 'mail' => ['manual_admin', 'email'],
            'whatsapp', 'wa' => ['manual_admin', 'whatsapp'],
            'sms', 'text' => ['manual_admin', 'sms'],
            'portal' => ['customer_portal', 'portal'],
            'api', 'webhook' => ['api', 'api'],
            'import', 'csv' => ['import', null],
            'campaign' => ['campaign', 'email'],
            'qr' => ['public_form', 'qr'],
            'manual', '' => ['manual_admin', null],
            default => ['manual_admin', $normalized !== '' ? $normalized : null],
        };
    }

    private function serviceRequestStatusFromLead(LeadRequest $lead): string
    {
        return match ((string) $lead->status) {
            LeadRequest::STATUS_CONTACTED,
            LeadRequest::STATUS_QUALIFIED,
            LeadRequest::STATUS_QUOTE_SENT => ServiceRequest::STATUS_IN_PROGRESS,
            LeadRequest::STATUS_CALL_REQUESTED => ServiceRequest::STATUS_PENDING,
            LeadRequest::STATUS_WON,
            LeadRequest::STATUS_CONVERTED => ServiceRequest::STATUS_ACCEPTED,
            LeadRequest::STATUS_LOST => ServiceRequest::STATUS_REFUSED,
            default => ServiceRequest::STATUS_NEW,
        };
    }

    private function serviceRequestTypeFromLead(LeadRequest $lead, ?Quote $quote): string
    {
        if ($quote !== null || $lead->status === LeadRequest::STATUS_QUOTE_SENT) {
            return 'quote_request';
        }

        if ($lead->status === LeadRequest::STATUS_CALL_REQUESTED) {
            return 'contact_request';
        }

        return 'service_request';
    }

    private function seedSourceMetaFromLead(LeadRequest $lead): array
    {
        $meta = (array) ($lead->meta ?? []);

        return collect($meta)
            ->filter(fn ($value, $key) => str_starts_with((string) $key, 'source_'))
            ->all();
    }

    private function phoneForIndex(int $index): string
    {
        return '+1 514 555 '.str_pad((string) (1000 + $index), 4, '0', STR_PAD_LEFT);
    }
}
