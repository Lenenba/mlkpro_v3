<?php

namespace App\Services\Demo;

use App\Models\AvailabilityException;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\DemoWorkspace;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LoyaltyProgram;
use App\Models\MailingList;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
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
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\TeamMemberShift;
use App\Models\User;
use App\Models\VipTier;
use App\Models\WeeklyAvailability;
use App\Models\Work;
use App\Services\AccountDeletionService;
use App\Services\Campaigns\MarketingSettingsService;
use App\Support\CampaignTemplateLanguage;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
            ? max(1, now()->diffInDays($workspace->expires_at) + 1)
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
            ? max(1, now()->diffInDays($workspace->expires_at) + 1)
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

        return DB::transaction(function () use ($workspace, $payload, $admin, $expiresAt, $isReset) {
            $this->updateProvisioningState(
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

            if ($isReset) {
                $previousOwner = $workspace->owner()->first();

                if ($previousOwner) {
                    $workspace->forceFill([
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

            $this->updateProvisioningState(
                $workspace,
                self::STATUS_PROVISIONING,
                60,
                'Generating realistic sample data'
            );

            $summary = $this->seedEnvironment($owner, $workspace);
            $extraAccessCredentials = $this->buildExtraAccessCredentials(
                $owner,
                $workspace->extra_access_roles ?? []
            );

            return $this->finalizeProvisionedWorkspace($workspace, $summary, [
                'extra_access_credentials' => $extraAccessCredentials,
                'last_reset_at' => $isReset ? now() : $workspace->last_reset_at,
                'last_reset_by_user_id' => $isReset ? $admin->id : $workspace->last_reset_by_user_id,
            ]);
        });
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
     * @return array<string, int>
     */
    private function seedEnvironment(User $owner, DemoWorkspace $workspace): array
    {
        $selectedModules = $workspace->selected_modules ?? [];
        $counts = $this->catalog->seedCounts($workspace->seed_profile);

        $teamMembers = $this->createTeamMembers(
            $owner,
            $selectedModules,
            max(1, (int) $workspace->team_size),
            max(1, (int) ($counts['team'] ?? 1)),
            (string) $workspace->company_sector,
            $workspace->extra_access_roles ?? []
        );
        $catalog = $this->createCatalog($owner, $selectedModules, (int) ($counts['catalog'] ?? 0), (string) $workspace->company_sector);
        $customers = $this->createCustomers($owner, (int) ($counts['customers'] ?? 0), (string) $workspace->company_sector);

        $loyalty = $this->createLoyaltySetup($owner, $selectedModules, $customers);
        $requests = $this->createRequests($owner, $selectedModules, $customers, $teamMembers, (int) ($counts['quotes'] ?? 0), $catalog['services']);
        $quotes = $this->createQuotes($owner, $selectedModules, $customers, $requests, $catalog, (int) ($counts['quotes'] ?? 0));
        $works = $this->createWorks($owner, $selectedModules, $customers, $quotes, $catalog, $teamMembers, (int) ($counts['works'] ?? 0));
        $tasks = $this->createTasks($owner, $selectedModules, $customers, $works, $teamMembers, (int) ($counts['tasks'] ?? 0));
        $invoices = $this->createInvoices($owner, $selectedModules, $customers, $works, $teamMembers);
        $reservationSummary = $this->createReservationFlow(
            $owner,
            $selectedModules,
            $customers,
            $catalog['services'],
            $teamMembers,
            (int) ($counts['reservations'] ?? 0),
            (int) ($counts['queue'] ?? 0),
            (string) $workspace->company_sector
        );
        $sales = $this->createSales($owner, $selectedModules, $customers, $catalog['products'], (int) ($counts['sales'] ?? 0));
        $marketing = $this->createMarketing($owner, $selectedModules, $customers);

        return [
            'customers' => $customers->count(),
            'team_members' => $teamMembers->count(),
            'services' => $catalog['services']->count(),
            'products' => $catalog['products']->count(),
            'requests' => $requests->count(),
            'quotes' => $quotes->count(),
            'works' => $works->count(),
            'tasks' => $tasks->count(),
            'invoices' => $invoices->count(),
            'reservations' => $reservationSummary['reservations'],
            'queue_items' => $reservationSummary['queue_items'],
            'waitlist_entries' => $reservationSummary['waitlist_entries'],
            'sales' => $sales->count(),
            'campaigns' => $marketing['campaigns'],
            'mailing_lists' => $marketing['mailing_lists'],
            'loyalty_program_enabled' => $loyalty ? 1 : 0,
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
        array $requiredAccessRoles = []
    ): Collection {
        $needsTeam = collect(['team_members', 'jobs', 'tasks', 'reservations', 'planning'])
            ->intersect($selectedModules)
            ->isNotEmpty();

        if (! $needsTeam) {
            return collect();
        }

        $targetCount = max(
            1,
            $requestedTeamSize,
            min($profileTeamSize, 6),
            $this->minimumTeamCountForAccessRoles($requiredAccessRoles)
        );
        $profiles = $this->teamProfilesForSector($sector);

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
    private function createCatalog(User $owner, array $selectedModules, int $catalogCount, string $sector): array
    {
        $services = collect();
        $products = collect();
        $total = max(4, $catalogCount);

        if (in_array('services', $selectedModules, true)) {
            $serviceCategory = ProductCategory::create([
                'name' => 'Signature services',
                'user_id' => $owner->id,
                'created_by_user_id' => $owner->id,
            ]);

            $services = collect($this->serviceCatalogForSector($sector))
                ->take(max(4, (int) ceil($total / 2)))
                ->values()
                ->map(function (array $item) use ($owner, $serviceCategory) {
                    return Product::create([
                        'name' => $item['name'],
                        'description' => $item['description'],
                        'category_id' => $serviceCategory->id,
                        'stock' => 0,
                        'minimum_stock' => 0,
                        'price' => $item['price'],
                        'currency_code' => $owner->businessCurrencyCode(),
                        'unit' => 'service',
                        'cost_price' => round($item['price'] * 0.35, 2),
                        'margin_percent' => 65,
                        'tax_rate' => 15,
                        'is_active' => true,
                        'user_id' => $owner->id,
                        'item_type' => Product::ITEM_TYPE_SERVICE,
                        'tracking_type' => 'none',
                    ]);
                });
        }

        if (in_array('products', $selectedModules, true)) {
            $productCategory = ProductCategory::create([
                'name' => 'Featured products',
                'user_id' => $owner->id,
                'created_by_user_id' => $owner->id,
            ]);

            $products = collect($this->productCatalogForSector($sector))
                ->take(max(4, $total))
                ->values()
                ->map(function (array $item, int $index) use ($owner, $productCategory) {
                    return Product::create([
                        'name' => $item['name'],
                        'description' => $item['description'],
                        'category_id' => $productCategory->id,
                        'stock' => 20 + ($index * 3),
                        'minimum_stock' => 5,
                        'price' => $item['price'],
                        'currency_code' => $owner->businessCurrencyCode(),
                        'sku' => 'DEMO-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                        'unit' => 'item',
                        'supplier_name' => 'Demo Supplier Co.',
                        'cost_price' => round($item['price'] * 0.52, 2),
                        'margin_percent' => 48,
                        'tax_rate' => 15,
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

    private function createCustomers(User $owner, int $count, string $sector): Collection
    {
        $profiles = $this->customerProfilesForSector($sector);
        $target = max(6, $count);

        return collect(range(1, $target))->map(function (int $index) use ($owner, $profiles) {
            $profile = $profiles[($index - 1) % count($profiles)];

            return Customer::create([
                'user_id' => $owner->id,
                'first_name' => (string) $profile['first_name'],
                'last_name' => (string) $profile['last_name'],
                'company_name' => $profile['company_name'],
                'email' => strtolower($profile['first_name'].'.'.$profile['last_name']).'+'.$owner->id.$index.'@example.test',
                'phone' => $this->phoneForIndex($index + 20),
                'description' => (string) $profile['description'],
                'tags' => $profile['tags'],
                'refer_by' => 'Website form',
                'salutation' => 'Mr',
                'billing_same_as_physical' => true,
                'discount_rate' => $index % 5 === 0 ? 10 : 0,
                'is_active' => true,
                'portal_access' => false,
                'loyalty_points_balance' => 0,
            ]);
        })->values();
    }

    /**
     * @param  array<int, string>  $selectedModules
     */
    private function createLoyaltySetup(User $owner, array $selectedModules, Collection $customers): ?LoyaltyProgram
    {
        if (! in_array('loyalty', $selectedModules, true)) {
            return null;
        }

        $vipTier = VipTier::create([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'code' => 'VIP-GOLD',
            'name' => 'Gold',
            'perks' => [
                'Priority booking',
                'Early access to launches',
                'Preferred service slots',
            ],
            'is_active' => true,
        ]);

        $customers->take(min(3, $customers->count()))->each(function (Customer $customer) use ($vipTier) {
            $customer->forceFill([
                'is_vip' => true,
                'vip_tier_id' => $vipTier->id,
                'vip_tier_code' => $vipTier->code,
                'vip_since_at' => now()->subMonths(4),
                'loyalty_points_balance' => 1200,
            ])->save();
        });

        return LoyaltyProgram::create([
            'user_id' => $owner->id,
            'is_enabled' => true,
            'points_per_currency_unit' => 1,
            'minimum_spend' => 25,
            'rounding_mode' => LoyaltyProgram::ROUND_FLOOR,
            'points_label' => 'Points',
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
     * @param  Collection<int, TeamMember>  $teamMembers
     * @return Collection<int, Invoice>
     */
    private function createInvoices(
        User $owner,
        array $selectedModules,
        Collection $customers,
        Collection $works,
        Collection $teamMembers
    ): Collection {
        if (! in_array('invoices', $selectedModules, true) || $works->isEmpty()) {
            return collect();
        }

        return $works->take(min(3, $works->count()))->values()->map(function (Work $work, int $index) use ($owner, $customers, $teamMembers) {
            $customer = $customers[$index % $customers->count()];
            $totals = [180, 325, 490];
            $statuses = ['sent', 'partial', 'paid'];
            $total = (float) ($totals[$index % count($totals)] ?? 240);

            $invoice = Invoice::create([
                'work_id' => $work->id,
                'customer_id' => $customer->id,
                'user_id' => $owner->id,
                'status' => $statuses[$index % count($statuses)],
                'total' => $total,
                'currency_code' => $owner->businessCurrencyCode(),
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'work_id' => $work->id,
                'assigned_team_member_id' => $teamMembers->isNotEmpty() ? $teamMembers[$index % $teamMembers->count()]->id : null,
                'title' => $work->job_title,
                'description' => 'Main invoice line created for the demo workspace.',
                'scheduled_date' => $work->start_date,
                'start_time' => $work->start_time,
                'end_time' => $work->end_time,
                'assignee_name' => $teamMembers->isNotEmpty() ? $teamMembers[$index % $teamMembers->count()]->user?->name : null,
                'task_status' => 'completed',
                'quantity' => 1,
                'unit_price' => $total,
                'currency_code' => $owner->businessCurrencyCode(),
                'total' => $total,
            ]);

            if ($index > 0) {
                Payment::create([
                    'invoice_id' => $invoice->id,
                    'customer_id' => $customer->id,
                    'user_id' => $owner->id,
                    'amount' => $index === 1 ? $total / 2 : $total,
                    'currency_code' => $owner->businessCurrencyCode(),
                    'method' => 'card',
                    'provider' => 'demo',
                    'status' => 'paid',
                    'reference' => 'DEMO-PAY-'.Str::upper(Str::random(6)),
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
     * @return array{reservations:int, queue_items:int, waitlist_entries:int}
     */
    private function createReservationFlow(
        User $owner,
        array $selectedModules,
        Collection $customers,
        Collection $services,
        Collection $teamMembers,
        int $reservationCount,
        int $queueCount,
        string $sector
    ): array {
        if (! in_array('reservations', $selectedModules, true) || $services->isEmpty() || $teamMembers->isEmpty()) {
            return [
                'reservations' => 0,
                'queue_items' => 0,
                'waitlist_entries' => 0,
            ];
        }

        ReservationSetting::create([
            'account_id' => $owner->id,
            'team_member_id' => null,
            'business_preset' => in_array($sector, ['salon', 'wellness'], true) ? 'salon' : 'service_general',
            'buffer_minutes' => 10,
            'slot_interval_minutes' => 30,
            'min_notice_minutes' => 0,
            'max_advance_days' => 90,
            'cancellation_cutoff_hours' => 12,
            'allow_client_cancel' => true,
            'allow_client_reschedule' => true,
            'late_release_minutes' => 5,
            'waitlist_enabled' => true,
            'queue_mode_enabled' => true,
            'queue_assignment_mode' => 'team_member',
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_pre_call_threshold' => 2,
            'queue_no_show_on_grace_expiry' => false,
            'deposit_required' => false,
            'deposit_amount' => 0,
            'no_show_fee_enabled' => false,
            'no_show_fee_amount' => 0,
        ]);

        $teamMembers->each(function (TeamMember $member, int $index) use ($owner) {
            ReservationSetting::create([
                'account_id' => $owner->id,
                'team_member_id' => $member->id,
                'business_preset' => 'salon',
                'buffer_minutes' => 10,
                'slot_interval_minutes' => 30,
                'min_notice_minutes' => 0,
                'max_advance_days' => 60,
                'cancellation_cutoff_hours' => 12,
                'allow_client_cancel' => true,
                'allow_client_reschedule' => true,
                'late_release_minutes' => 5,
                'waitlist_enabled' => true,
                'queue_mode_enabled' => true,
                'queue_assignment_mode' => 'team_member',
                'queue_dispatch_mode' => 'fifo_with_appointment_priority',
                'queue_grace_minutes' => 5,
                'queue_pre_call_threshold' => 2,
                'queue_no_show_on_grace_expiry' => false,
                'deposit_required' => false,
                'deposit_amount' => 0,
                'no_show_fee_enabled' => false,
                'no_show_fee_amount' => 0,
            ]);

            ReservationResource::create([
                'account_id' => $owner->id,
                'team_member_id' => $member->id,
                'name' => 'Chair '.($index + 1),
                'type' => 'chair',
                'capacity' => 1,
                'is_active' => true,
                'metadata' => ['kind' => 'barber_chair'],
            ]);

            foreach (range(1, 5) as $dayOffset) {
                WeeklyAvailability::create([
                    'account_id' => $owner->id,
                    'team_member_id' => $member->id,
                    'day_of_week' => $dayOffset,
                    'start_time' => '09:00:00',
                    'end_time' => '17:00:00',
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
                'title' => 'Frontline shift',
                'shift_date' => now()->toDateString(),
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'break_minutes' => 30,
            ]);
        });

        AvailabilityException::create([
            'account_id' => $owner->id,
            'team_member_id' => $teamMembers->first()?->id,
            'date' => now()->addDays(4)->toDateString(),
            'start_time' => '13:00:00',
            'end_time' => '17:00:00',
            'type' => AvailabilityException::TYPE_CLOSED,
            'reason' => 'Training block',
        ]);

        $reservations = collect(range(1, max(4, $reservationCount)))->map(function (int $index) use ($owner, $customers, $services, $teamMembers) {
            $member = $teamMembers[$index % $teamMembers->count()];
            $customer = $customers[$index % $customers->count()];
            $service = $services[$index % $services->count()];
            $startsAt = now()->copy()->startOfDay()->addHours(9 + $index);
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
                'ends_at' => $startsAt->copy()->addMinutes(60),
                'duration_minutes' => 60,
                'buffer_minutes' => 10,
                'internal_notes' => 'Demo reservation generated for queue and booking walkthrough.',
                'client_notes' => $index % 2 === 0 ? 'Customer prefers the senior stylist.' : null,
                'created_by_user_id' => $owner->id,
            ]);
        })->values();

        $queueItems = collect(range(1, max(2, $queueCount)))->map(function (int $index) use ($owner, $customers, $services, $teamMembers, $reservations) {
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
                'estimated_duration_minutes' => 45,
                'checked_in_at' => $checkedInAt,
                'pre_called_at' => in_array($status, [ReservationQueueItem::STATUS_PRE_CALLED, ReservationQueueItem::STATUS_CALLED, ReservationQueueItem::STATUS_IN_SERVICE], true) ? $checkedInAt->copy()->addMinutes(5) : null,
                'called_at' => in_array($status, [ReservationQueueItem::STATUS_CALLED, ReservationQueueItem::STATUS_IN_SERVICE], true) ? $checkedInAt->copy()->addMinutes(10) : null,
                'started_at' => $status === ReservationQueueItem::STATUS_IN_SERVICE ? $checkedInAt->copy()->addMinutes(13) : null,
                'position' => $index,
                'eta_minutes' => max(5, $index * 12),
                'metadata' => ['label' => $customer->company_name ?: trim($customer->first_name.' '.$customer->last_name)],
            ]);
        })->values();

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
        int $count
    ): Collection {
        if (! in_array('sales', $selectedModules, true) || $products->isEmpty()) {
            return collect();
        }

        return collect(range(1, max(2, $count)))->map(function (int $index) use ($owner, $customers, $products) {
            $customer = $customers[$index % $customers->count()];
            $picked = $products->take(min(2, $products->count()));
            $subtotal = (float) $picked->sum('price');
            $sale = Sale::create([
                'user_id' => $owner->id,
                'created_by_user_id' => $owner->id,
                'customer_id' => $customer->id,
                'status' => $index % 2 === 0 ? Sale::STATUS_PAID : Sale::STATUS_PENDING,
                'payment_provider' => 'demo',
                'subtotal' => $subtotal,
                'tax_total' => round($subtotal * 0.15, 2),
                'currency_code' => $owner->businessCurrencyCode(),
                'discount_rate' => $index % 3 === 0 ? 10 : 0,
                'discount_total' => $index % 3 === 0 ? round($subtotal * 0.1, 2) : 0,
                'loyalty_points_redeemed' => 0,
                'loyalty_discount_total' => 0,
                'total' => round($subtotal * 1.15, 2),
                'delivery_fee' => 0,
                'fulfillment_method' => $index % 2 === 0 ? 'pickup' : 'delivery',
                'fulfillment_status' => $index % 2 === 0 ? Sale::FULFILLMENT_READY_FOR_PICKUP : Sale::FULFILLMENT_PENDING,
                'scheduled_for' => now()->addDays($index),
                'source' => 'pos',
                'paid_at' => $index % 2 === 0 ? now()->subHours(6) : null,
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
            }

            return $sale;
        })->values();
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Customer>  $customers
     * @return array{campaigns:int, mailing_lists:int}
     */
    private function createMarketing(User $owner, array $selectedModules, Collection $customers): array
    {
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
            'name' => 'VIP repeat customers',
            'description' => 'Mailing list prepared for a tailored lifecycle campaign demo.',
            'tags' => ['vip', 'repeat', 'demo'],
        ]);

        $mailingList->customers()->attach(
            $customers->take(min(5, $customers->count()))->mapWithKeys(fn (Customer $customer) => [
                $customer->id => [
                    'added_by_user_id' => $owner->id,
                    'added_at' => now()->subDays(3),
                ],
            ])->all()
        );

        Campaign::create([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'name' => 'Spring retention push',
            'campaign_type' => Campaign::TYPE_PROMOTION,
            'campaign_direction' => Campaign::DIRECTION_CUSTOMER_MARKETING,
            'prospecting_enabled' => false,
            'offer_mode' => $owner->company_type === 'products' ? Campaign::OFFER_MODE_PRODUCTS : Campaign::OFFER_MODE_SERVICES,
            'language_mode' => CampaignTemplateLanguage::defaultModeForLocale($owner->locale),
            'type' => Campaign::TYPE_PROMOTION,
            'status' => Campaign::STATUS_DRAFT,
            'schedule_type' => Campaign::SCHEDULE_SCHEDULED,
            'scheduled_at' => now()->addDays(5),
            'locale' => $owner->locale,
            'cta_url' => '/pricing',
            'is_marketing' => true,
            'last_run_at' => null,
            'settings' => [
                'mailing_lists' => [$mailingList->id],
                'objective' => 'Retention',
            ],
        ]);

        return [
            'campaigns' => 1,
            'mailing_lists' => 1,
        ];
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
                || str_contains(strtolower((string) $member->title), 'front desk'),
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
    private function teamProfilesForSector(string $sector): array
    {
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
    private function serviceCatalogForSector(string $sector): array
    {
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
    private function productCatalogForSector(string $sector): array
    {
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
    private function customerProfilesForSector(string $sector): array
    {
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

    private function phoneForIndex(int $index): string
    {
        return '+1 514 555 '.str_pad((string) (1000 + $index), 4, '0', STR_PAD_LEFT);
    }
}
