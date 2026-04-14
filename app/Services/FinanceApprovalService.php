<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Collection;

class FinanceApprovalService
{
    public const MODE_SOLO = 'solo';
    public const MODE_TEAM = 'team';
    public const APPROVAL_STATUS_DRAFT = 'draft';
    public const APPROVAL_STATUS_SUBMITTED = 'submitted';
    public const APPROVAL_STATUS_PENDING_APPROVAL = 'pending_approval';
    public const APPROVAL_STATUS_APPROVED = 'approved';
    public const APPROVAL_STATUS_REJECTED = 'rejected';
    public const APPROVAL_STATUS_PAID = 'paid';
    public const APPROVAL_STATUS_PROCESSED = 'processed';

    public const APPROVAL_STATUSES = [
        self::APPROVAL_STATUS_DRAFT,
        self::APPROVAL_STATUS_SUBMITTED,
        self::APPROVAL_STATUS_PENDING_APPROVAL,
        self::APPROVAL_STATUS_APPROVED,
        self::APPROVAL_STATUS_REJECTED,
        self::APPROVAL_STATUS_PAID,
        self::APPROVAL_STATUS_PROCESSED,
    ];

    private const ROLE_OPTIONS = [
        ['key' => 'admin', 'label_key' => 'settings.company.finance.roles.admin'],
        ['key' => 'sales_manager', 'label_key' => 'settings.company.finance.roles.sales_manager'],
        ['key' => 'member', 'label_key' => 'settings.company.finance.roles.member'],
        ['key' => 'seller', 'label_key' => 'settings.company.finance.roles.seller'],
    ];

    public function roleOptions(): array
    {
        return self::ROLE_OPTIONS;
    }

    public function modeFor(User $user): string
    {
        $owner = $this->resolveOwner($user);
        if (! $owner) {
            return self::MODE_TEAM;
        }

        $planModules = app(CompanyFeatureService::class)->resolvePlanModules();
        $planKey = app(BillingSubscriptionService::class)->resolvePlanKey($owner, $planModules);

        if ($planKey && app(BillingPlanService::class)->isOwnerOnlyPlan($planKey)) {
            return self::MODE_SOLO;
        }

        return self::MODE_TEAM;
    }

    public function settingsFor(User $user): array
    {
        $owner = $this->resolveOwner($user);
        $saved = is_array($owner?->company_finance_settings) ? $owner->company_finance_settings : [];

        return $this->normalizeSettings($saved);
    }

    public function normalizeSettings(array $settings): array
    {
        $defaults = $this->defaultSettings();

        return [
            'expense' => [
                'roles' => $this->normalizeRoles(data_get($settings, 'expense.roles'), $defaults['expense']['roles']),
            ],
            'invoice' => [
                'roles' => $this->normalizeRoles(data_get($settings, 'invoice.roles'), $defaults['invoice']['roles']),
                'auto_approve_under_amount' => $this->normalizeOptionalThreshold(
                    data_get($settings, 'invoice.auto_approve_under_amount'),
                    $defaults['invoice']['auto_approve_under_amount'] ?? null,
                ),
            ],
        ];
    }

    public function resolveExpenseCreation(
        User $actor,
        float $amount,
        ?string $requestedStatus = null,
        bool $reviewRequired = false
    ): array {
        if ($reviewRequired) {
            return [
                'status' => Expense::STATUS_REVIEW_REQUIRED,
                'current_approver_role_key' => null,
                'current_approval_level' => null,
                'approval_policy_snapshot' => $this->buildApprovalSnapshot($actor, 'expense', $amount, [
                    'status' => Expense::STATUS_REVIEW_REQUIRED,
                    'review_required' => true,
                ]),
                'auto_approved' => false,
            ];
        }

        if ($this->modeFor($actor) === self::MODE_SOLO) {
            $status = $this->normalizeSoloExpenseStatus($requestedStatus);

            return [
                'status' => $status,
                'current_approver_role_key' => null,
                'current_approval_level' => null,
                'approval_policy_snapshot' => $this->buildApprovalSnapshot($actor, 'expense', $amount, [
                    'status' => $status,
                    'auto_approved' => true,
                    'role_key' => 'owner',
                    'approval_order' => 0,
                ]),
                'auto_approved' => true,
            ];
        }

        if ($this->isOwnerActor($actor)) {
            $status = $this->normalizeTeamOwnerExpenseStatus($requestedStatus);

            return [
                'status' => $status,
                'current_approver_role_key' => null,
                'current_approval_level' => null,
                'approval_policy_snapshot' => $this->buildApprovalSnapshot($actor, 'expense', $amount, [
                    'status' => $status,
                    'auto_approved' => false,
                    'role_key' => 'owner',
                    'approval_order' => 0,
                ]),
                'auto_approved' => false,
            ];
        }

        $policy = $this->resolveDocumentPolicy($actor, 'expense', $amount);

        return [
            'status' => $policy['initial_status'],
            'current_approver_role_key' => $policy['role_key'],
            'current_approval_level' => $policy['approval_order'],
            'approval_policy_snapshot' => $this->buildApprovalSnapshot($actor, 'expense', $amount, $policy),
            'auto_approved' => false,
        ];
    }

    public function resolveExpenseSubmission(User $actor, Expense $expense): array
    {
        if ($this->modeFor($actor) === self::MODE_SOLO) {
            return [
                'status' => Expense::STATUS_APPROVED,
                'current_approver_role_key' => null,
                'current_approval_level' => null,
                'approval_policy_snapshot' => $this->buildApprovalSnapshot($actor, 'expense', (float) $expense->total, [
                    'status' => Expense::STATUS_APPROVED,
                    'auto_approved' => true,
                    'role_key' => 'owner',
                    'approval_order' => 0,
                ]),
                'auto_approved' => true,
            ];
        }

        $policy = $this->resolveDocumentPolicy($actor, 'expense', (float) $expense->total);

        return [
            'status' => $policy['initial_status'],
            'current_approver_role_key' => $policy['role_key'],
            'current_approval_level' => $policy['approval_order'],
            'approval_policy_snapshot' => $this->buildApprovalSnapshot($actor, 'expense', (float) $expense->total, $policy),
            'auto_approved' => false,
        ];
    }

    public function authorizeExpenseAction(User $actor, Expense $expense, string $action): array
    {
        if ((int) $actor->accountOwnerId() !== (int) $expense->user_id) {
            return ['allowed' => false, 'message' => 'This expense is not available in your workspace.'];
        }

        if ($action === 'submit') {
            if ($this->isOwnerActor($actor) || (int) $expense->created_by_user_id === (int) $actor->id) {
                return ['allowed' => true] + $this->resolveExpenseSubmission($actor, $expense);
            }

            return ['allowed' => false, 'message' => 'Only the submitter or owner can submit this expense.'];
        }

        if ($action === 'cancel' && in_array($expense->status, [Expense::STATUS_DRAFT, Expense::STATUS_REVIEW_REQUIRED, Expense::STATUS_REJECTED], true)) {
            if ($this->isOwnerActor($actor) || (int) $expense->created_by_user_id === (int) $actor->id) {
                return ['allowed' => true];
            }
        }

        if ($this->isOwnerActor($actor)) {
            return [
                'allowed' => true,
                'owner_override' => true,
                'configured_role_key' => 'owner',
            ];
        }

        $membership = $this->membership($actor);
        if (! $membership) {
            return ['allowed' => false, 'message' => 'Approval actions require an active team membership.'];
        }

        if ((int) $expense->created_by_user_id === (int) $actor->id) {
            return ['allowed' => false, 'message' => 'Submitters cannot advance their own finance documents on team plans.'];
        }

        $roleConfig = $this->roleConfigFor($actor, 'expense', (string) $membership->role);
        if (! $roleConfig) {
            return ['allowed' => false, 'message' => 'Your team role is not configured as a finance approver.'];
        }

        if (! $this->roleCoversAmount($roleConfig, (float) $expense->total)) {
            return ['allowed' => false, 'message' => 'This amount exceeds your finance approval limit.'];
        }

        if (in_array($action, ['approve', 'reject'], true)) {
            $requiredPermission = ((int) ($roleConfig['approval_order'] ?? 1) > 1)
                ? 'expenses.approve_high'
                : 'expenses.approve';

            if (! $membership->hasPermission($requiredPermission)) {
                return ['allowed' => false, 'message' => 'Your role is missing the required approval permission.'];
            }

            if ($action === 'reject' && empty($roleConfig['can_reject'])) {
                return ['allowed' => false, 'message' => 'Your role cannot reject this expense.'];
            }

            $assignedRole = $expense->current_approver_role_key;
            if ($assignedRole && $assignedRole !== $roleConfig['role_key']) {
                return ['allowed' => false, 'message' => 'This expense is waiting for a different approval role.'];
            }
        }

        if (in_array($action, ['mark_due', 'mark_paid', 'mark_reimbursed', 'cancel'], true)) {
            if (! $membership->hasPermission('expenses.pay')) {
                return ['allowed' => false, 'message' => 'Your role is missing the required payment permission.'];
            }

            if (in_array($action, ['mark_paid', 'mark_reimbursed'], true) && empty($roleConfig['can_mark_paid'])) {
                return ['allowed' => false, 'message' => 'Your role cannot mark this expense as paid.'];
            }
        }

        return [
            'allowed' => true,
            'configured_role_key' => $roleConfig['role_key'],
        ];
    }

    public function resolveInvoiceCreation(User $accountOwner, ?User $actor, float $amount): array
    {
        $effectiveActor = $actor ?: $accountOwner;
        $settings = $this->settingsFor($accountOwner);

        if (
            $this->modeFor($accountOwner) === self::MODE_SOLO
            || $this->isOwnerActor($effectiveActor)
        ) {
            return [
                'approval_status' => self::APPROVAL_STATUS_APPROVED,
                'current_approver_role_key' => null,
                'current_approval_level' => null,
                'approval_policy_snapshot' => $this->buildApprovalSnapshot($effectiveActor, 'invoice', $amount, [
                    'status' => self::APPROVAL_STATUS_APPROVED,
                    'auto_approved' => true,
                    'auto_approved_reason' => 'owner_or_solo',
                    'role_key' => 'owner',
                    'approval_order' => 0,
                ]),
                'auto_approved' => true,
                'approved_by_user_id' => $effectiveActor->id,
            ];
        }

        $autoApproveThreshold = $this->normalizeOptionalThreshold(
            data_get($settings, 'invoice.auto_approve_under_amount')
        );
        if ($autoApproveThreshold !== null && $amount <= $autoApproveThreshold) {
            return [
                'approval_status' => self::APPROVAL_STATUS_APPROVED,
                'current_approver_role_key' => null,
                'current_approval_level' => null,
                'approval_policy_snapshot' => $this->buildApprovalSnapshot($effectiveActor, 'invoice', $amount, [
                    'status' => self::APPROVAL_STATUS_APPROVED,
                    'auto_approved' => true,
                    'auto_approved_reason' => 'under_threshold',
                    'role_key' => 'policy_auto',
                    'approval_order' => 0,
                ]),
                'auto_approved' => true,
                'approved_by_user_id' => null,
            ];
        }

        $policy = $this->resolveDocumentPolicy($effectiveActor, 'invoice', $amount);

        return [
            'approval_status' => $policy['initial_status'],
            'current_approver_role_key' => $policy['role_key'],
            'current_approval_level' => $policy['approval_order'],
            'approval_policy_snapshot' => $this->buildApprovalSnapshot($effectiveActor, 'invoice', $amount, $policy),
            'auto_approved' => false,
            'approved_by_user_id' => null,
        ];
    }

    public function approvalStatuses(): array
    {
        return self::APPROVAL_STATUSES;
    }

    public function approverRecipientsForDocument(
        User $user,
        string $documentType,
        ?string $roleKey,
        ?int $approvalLevel = null,
        ?int $excludeUserId = null
    ): Collection {
        $owner = $this->resolveOwner($user);
        if (! $owner) {
            return collect();
        }

        if (! $roleKey || $roleKey === 'owner') {
            return User::query()
                ->whereKey($owner->id)
                ->when($excludeUserId, fn ($query) => $query->where('id', '!=', $excludeUserId))
                ->get();
        }

        $resolvedLevel = $approvalLevel
            ?? (int) (data_get($this->roleConfigFor($owner, $documentType, $roleKey), 'approval_order', 1));
        $requiredPermission = $this->approvalPermissionForDocument($documentType, $resolvedLevel);

        $members = TeamMember::query()
            ->forAccount($owner->id)
            ->active()
            ->where('role', $roleKey)
            ->with('user:id,name,email,locale')
            ->get()
            ->filter(fn (TeamMember $member) => $member->user && (! $requiredPermission || $member->hasPermission($requiredPermission)))
            ->map(fn (TeamMember $member) => $member->user)
            ->filter(fn (?User $recipient) => $recipient !== null)
            ->reject(fn (User $recipient) => $excludeUserId !== null && (int) $recipient->id === (int) $excludeUserId)
            ->unique('id')
            ->values();

        if ($members->isNotEmpty()) {
            return $members->values();
        }

        return User::query()
            ->whereKey($owner->id)
            ->when($excludeUserId, fn ($query) => $query->where('id', '!=', $excludeUserId))
            ->get();
    }

    public function authorizeInvoiceAction(User $actor, Invoice $invoice, string $action): array
    {
        if ((int) $actor->accountOwnerId() !== (int) $invoice->user_id) {
            return ['allowed' => false, 'message' => 'This invoice is not available in your workspace.'];
        }

        if ($action === 'send'
            && ! in_array((string) $invoice->approval_status, [
                self::APPROVAL_STATUS_APPROVED,
                self::APPROVAL_STATUS_PROCESSED,
            ], true)) {
            return ['allowed' => false, 'message' => 'Only approved invoices can be sent to customers.'];
        }

        if ($this->isOwnerActor($actor)) {
            return [
                'allowed' => true,
                'owner_override' => true,
                'configured_role_key' => 'owner',
            ];
        }

        $membership = $this->membership($actor);
        if (! $membership) {
            return ['allowed' => false, 'message' => 'Approval actions require an active team membership.'];
        }

        if ((int) $invoice->created_by_user_id === (int) $actor->id) {
            return ['allowed' => false, 'message' => 'Submitters cannot advance their own finance documents on team plans.'];
        }

        $roleConfig = $this->roleConfigFor($actor, 'invoice', (string) $membership->role);
        if (! $roleConfig) {
            return ['allowed' => false, 'message' => 'Your team role is not configured as a finance approver.'];
        }

        if (! $this->roleCoversAmount($roleConfig, (float) $invoice->total)) {
            return ['allowed' => false, 'message' => 'This amount exceeds your finance approval limit.'];
        }

        $requiredPermission = ((int) ($roleConfig['approval_order'] ?? 1) > 1)
            ? 'invoices.approve_high'
            : 'invoices.approve';

        if (in_array($action, ['approve', 'reject', 'process', 'send'], true)
            && ! $membership->hasPermission($requiredPermission)) {
            return ['allowed' => false, 'message' => 'Your role is missing the required invoice approval permission.'];
        }

        if ($action === 'reject' && empty($roleConfig['can_reject'])) {
            return ['allowed' => false, 'message' => 'Your role cannot reject this invoice.'];
        }

        if ($action === 'process' && empty($roleConfig['can_mark_processed'])) {
            return ['allowed' => false, 'message' => 'Your role cannot mark this invoice as processed.'];
        }

        if (in_array($action, ['approve', 'reject'], true)) {
            $assignedRole = $invoice->current_approver_role_key;
            if ($assignedRole && $assignedRole !== $roleConfig['role_key']) {
                return ['allowed' => false, 'message' => 'This invoice is waiting for a different approval role.'];
            }
        }

        return [
            'allowed' => true,
            'configured_role_key' => $roleConfig['role_key'],
        ];
    }

    private function defaultSettings(): array
    {
        return [
            'expense' => [
                'roles' => [
                    [
                        'role_key' => 'admin',
                        'max_amount' => 1000,
                        'approval_order' => 1,
                        'can_reject' => true,
                        'can_mark_paid' => true,
                        'can_mark_processed' => false,
                    ],
                    [
                        'role_key' => 'sales_manager',
                        'max_amount' => 5000,
                        'approval_order' => 2,
                        'can_reject' => true,
                        'can_mark_paid' => true,
                        'can_mark_processed' => true,
                    ],
                ],
            ],
            'invoice' => [
                'auto_approve_under_amount' => null,
                'roles' => [
                    [
                        'role_key' => 'admin',
                        'max_amount' => 2500,
                        'approval_order' => 1,
                        'can_reject' => true,
                        'can_mark_paid' => false,
                        'can_mark_processed' => true,
                    ],
                    [
                        'role_key' => 'sales_manager',
                        'max_amount' => 10000,
                        'approval_order' => 2,
                        'can_reject' => true,
                        'can_mark_paid' => false,
                        'can_mark_processed' => true,
                    ],
                ],
            ],
        ];
    }

    private function normalizeRoles(mixed $roles, array $fallback): array
    {
        $allowedRoles = collect(self::ROLE_OPTIONS)->pluck('key')->all();
        $normalized = collect(is_array($roles) ? $roles : [])
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row): array {
                $maxAmount = $row['max_amount'] ?? null;
                $maxAmount = ($maxAmount === '' || $maxAmount === null)
                    ? null
                    : round(max(0, (float) $maxAmount), 2);

                return [
                    'role_key' => trim((string) ($row['role_key'] ?? '')),
                    'max_amount' => $maxAmount,
                    'approval_order' => max(1, (int) ($row['approval_order'] ?? 1)),
                    'can_reject' => array_key_exists('can_reject', $row) ? (bool) $row['can_reject'] : true,
                    'can_mark_paid' => array_key_exists('can_mark_paid', $row) ? (bool) $row['can_mark_paid'] : true,
                    'can_mark_processed' => array_key_exists('can_mark_processed', $row) ? (bool) $row['can_mark_processed'] : true,
                ];
            })
            ->filter(fn (array $row) => in_array($row['role_key'], $allowedRoles, true))
            ->sortBy('approval_order')
            ->values()
            ->all();

        return $normalized !== [] ? $normalized : $fallback;
    }

    private function normalizeOptionalThreshold(mixed $value, mixed $fallback = null): ?float
    {
        $normalized = $value;
        if ($normalized === '' || $normalized === null) {
            $normalized = $fallback;
        }

        if ($normalized === '' || $normalized === null) {
            return null;
        }

        return round(max(0, (float) $normalized), 2);
    }

    private function resolveDocumentPolicy(User $user, string $documentType, float $amount): array
    {
        $roles = data_get($this->settingsFor($user), $documentType.'.roles', []);

        foreach ($roles as $role) {
            if (! is_array($role)) {
                continue;
            }

            if (! $this->roleCoversAmount($role, $amount)) {
                continue;
            }

            return [
                'role_key' => (string) $role['role_key'],
                'approval_order' => (int) ($role['approval_order'] ?? 1),
                'initial_status' => ((int) ($role['approval_order'] ?? 1) > 1)
                    ? self::APPROVAL_STATUS_PENDING_APPROVAL
                    : self::APPROVAL_STATUS_SUBMITTED,
                'can_reject' => (bool) ($role['can_reject'] ?? true),
                'can_mark_paid' => (bool) ($role['can_mark_paid'] ?? true),
                'can_mark_processed' => (bool) ($role['can_mark_processed'] ?? true),
                'escalated' => (int) ($role['approval_order'] ?? 1) > 1,
            ];
        }

        return [
            'role_key' => 'owner',
            'approval_order' => count($roles) + 1,
            'initial_status' => self::APPROVAL_STATUS_PENDING_APPROVAL,
            'can_reject' => true,
            'can_mark_paid' => true,
            'can_mark_processed' => true,
            'escalated' => true,
        ];
    }

    private function buildApprovalSnapshot(User $user, string $documentType, float $amount, array $policy): array
    {
        return array_filter([
            'document_type' => $documentType,
            'approval_mode' => $this->modeFor($user),
            'amount' => round($amount, 2),
            'role_key' => $policy['role_key'] ?? null,
            'approval_order' => $policy['approval_order'] ?? null,
            'status' => $policy['status'] ?? ($policy['initial_status'] ?? null),
            'auto_approved' => (bool) ($policy['auto_approved'] ?? false),
            'auto_approved_reason' => $policy['auto_approved_reason'] ?? null,
            'can_reject' => $policy['can_reject'] ?? null,
            'can_mark_paid' => $policy['can_mark_paid'] ?? null,
            'can_mark_processed' => $policy['can_mark_processed'] ?? null,
            'review_required' => $policy['review_required'] ?? null,
            'evaluated_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null);
    }

    private function normalizeSoloExpenseStatus(?string $requestedStatus): string
    {
        return match ($requestedStatus) {
            Expense::STATUS_DUE,
            Expense::STATUS_PAID,
            Expense::STATUS_REIMBURSED,
            Expense::STATUS_CANCELLED,
            Expense::STATUS_REVIEW_REQUIRED => $requestedStatus,
            default => Expense::STATUS_APPROVED,
        };
    }

    private function normalizeTeamOwnerExpenseStatus(?string $requestedStatus): string
    {
        return match ($requestedStatus) {
            Expense::STATUS_SUBMITTED,
            Expense::STATUS_PENDING_APPROVAL,
            Expense::STATUS_APPROVED,
            Expense::STATUS_REJECTED,
            Expense::STATUS_DUE,
            Expense::STATUS_PAID,
            Expense::STATUS_REIMBURSED,
            Expense::STATUS_CANCELLED,
            Expense::STATUS_REVIEW_REQUIRED => $requestedStatus,
            default => Expense::STATUS_DRAFT,
        };
    }

    private function resolveOwner(User $user): ?User
    {
        $ownerId = $user->accountOwnerId();

        return $ownerId === (int) $user->id
            ? $user
            : User::query()->find($ownerId);
    }

    private function isOwnerActor(User $user): bool
    {
        return $user->isAccountOwner()
            && (int) $user->id === (int) $user->accountOwnerId();
    }

    private function membership(User $user): ?TeamMember
    {
        return $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();
    }

    private function roleConfigFor(User $user, string $documentType, string $roleKey): ?array
    {
        return collect(data_get($this->settingsFor($user), $documentType.'.roles', []))
            ->first(fn ($role) => is_array($role) && ($role['role_key'] ?? null) === $roleKey);
    }

    private function approvalPermissionForDocument(string $documentType, ?int $approvalLevel = null): ?string
    {
        $level = max(1, (int) ($approvalLevel ?? 1));

        return match ($documentType) {
            'expense' => $level > 1 ? 'expenses.approve_high' : 'expenses.approve',
            'invoice' => $level > 1 ? 'invoices.approve_high' : 'invoices.approve',
            default => null,
        };
    }

    private function roleCoversAmount(array $role, float $amount): bool
    {
        $maxAmount = $role['max_amount'] ?? null;

        return $maxAmount === null || $amount <= (float) $maxAmount;
    }
}
