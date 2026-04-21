<?php

namespace App\Http\Controllers;

use App\Models\PlaybookRun;
use App\Models\Quote;
use App\Models\SavedSegment;
use App\Models\User;
use App\Services\CompanyFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PlaybookRunController extends Controller
{
    public function __construct(
        private readonly CompanyFeatureService $companyFeatureService,
    ) {}

    public function index(Request $request)
    {
        [$owner, $allowedModules] = $this->resolveAccess($request->user());
        if ($allowedModules === []) {
            abort(403);
        }

        $validated = $request->validate([
            'module' => ['nullable', 'string', Rule::in($allowedModules)],
            'status' => ['nullable', 'string', Rule::in($this->allowedStatuses())],
            'origin' => ['nullable', 'string', Rule::in($this->allowedOrigins())],
        ]);

        $filters = [
            'module' => $validated['module'] ?? '',
            'status' => $validated['status'] ?? '',
            'origin' => $validated['origin'] ?? '',
            'per_page' => $this->resolveDataTablePerPage($request),
        ];

        $scopedRunsQuery = PlaybookRun::query()
            ->where('user_id', $owner->id)
            ->whereIn('module', $allowedModules)
            ->when($filters['module'], fn ($query, $module) => $query->where('module', $module))
            ->when($filters['origin'], fn ($query, $origin) => $query->where('origin', $origin));

        $runsQuery = (clone $scopedRunsQuery)
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status));

        $runs = $runsQuery
            ->with([
                'playbook:id,name,is_active,schedule_type,next_run_at,last_run_at',
                'savedSegment:id,name,module',
                'requestedBy:id,name',
            ])
            ->orderByDesc('created_at')
            ->paginate((int) $filters['per_page'])
            ->withQueryString()
            ->through(fn (PlaybookRun $run): array => $this->mapRun($run));

        $stats = [
            'total' => (int) (clone $scopedRunsQuery)->count(),
            'active' => (int) (clone $scopedRunsQuery)
                ->whereIn('status', [
                    PlaybookRun::STATUS_PENDING,
                    PlaybookRun::STATUS_RUNNING,
                ])
                ->count(),
            'completed' => (int) (clone $scopedRunsQuery)
                ->where('status', PlaybookRun::STATUS_COMPLETED)
                ->count(),
            'failed' => (int) (clone $scopedRunsQuery)
                ->where('status', PlaybookRun::STATUS_FAILED)
                ->count(),
            'processed' => (int) (clone $scopedRunsQuery)->sum('processed_count'),
            'skipped' => (int) (clone $scopedRunsQuery)->sum('skipped_count'),
        ];

        return $this->inertiaOrJson('Campaigns/PlaybookRuns', [
            'runs' => $runs,
            'filters' => $filters,
            'stats' => $stats,
            'enums' => [
                'modules' => array_values($allowedModules),
                'statuses' => $this->allowedStatuses(),
                'origins' => $this->allowedOrigins(),
            ],
        ]);
    }

    /**
     * @return array{0: User, 1: array<int, string>}
     */
    private function resolveAccess(?User $user): array
    {
        if (! $user) {
            abort(401);
        }

        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->find($ownerId);

        if (! $owner) {
            abort(403);
        }

        $allowedModules = [];

        if ((int) $user->id === (int) $owner->id && $this->companyFeatureService->hasFeature($owner, 'requests')) {
            $allowedModules[] = SavedSegment::MODULE_REQUEST;
        }

        if ($this->canViewCustomerRuns($user, $owner)) {
            $allowedModules[] = SavedSegment::MODULE_CUSTOMER;
        }

        if (
            $this->companyFeatureService->hasFeature($owner, 'quotes')
            && Gate::forUser($user)->allows('viewAny', Quote::class)
        ) {
            $allowedModules[] = SavedSegment::MODULE_QUOTE;
        }

        return [$owner, $allowedModules];
    }

    private function canViewCustomerRuns(User $user, User $owner): bool
    {
        if ((int) $user->accountOwnerId() !== (int) $owner->id) {
            return false;
        }

        if ((int) $user->id === (int) $owner->id) {
            return true;
        }

        if ((string) $owner->company_type !== 'products') {
            return true;
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        return (bool) $membership?->hasPermission('sales.manage');
    }

    /**
     * @return array<int, string>
     */
    private function allowedStatuses(): array
    {
        return [
            PlaybookRun::STATUS_PENDING,
            PlaybookRun::STATUS_RUNNING,
            PlaybookRun::STATUS_COMPLETED,
            PlaybookRun::STATUS_FAILED,
            PlaybookRun::STATUS_CANCELED,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function allowedOrigins(): array
    {
        return [
            PlaybookRun::ORIGIN_MANUAL,
            PlaybookRun::ORIGIN_SCHEDULED,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRun(PlaybookRun $run): array
    {
        $summary = is_array($run->summary) ? $run->summary : [];

        return [
            'id' => $run->id,
            'playbook_id' => $run->playbook_id,
            'saved_segment_id' => $run->saved_segment_id,
            'module' => (string) $run->module,
            'action_key' => (string) $run->action_key,
            'origin' => (string) $run->origin,
            'status' => (string) $run->status,
            'selected_count' => (int) ($run->selected_count ?? 0),
            'processed_count' => (int) ($run->processed_count ?? 0),
            'success_count' => (int) ($run->success_count ?? 0),
            'failed_count' => (int) ($run->failed_count ?? 0),
            'skipped_count' => (int) ($run->skipped_count ?? 0),
            'created_at' => optional($run->created_at)->toJSON(),
            'started_at' => optional($run->started_at)->toJSON(),
            'finished_at' => optional($run->finished_at)->toJSON(),
            'scheduled_for' => optional($run->scheduled_for)->toJSON(),
            'requested_by' => $run->requestedBy ? [
                'id' => $run->requestedBy->id,
                'name' => $run->requestedBy->name,
            ] : null,
            'playbook' => $run->playbook ? [
                'id' => $run->playbook->id,
                'name' => $run->playbook->name,
                'is_active' => (bool) $run->playbook->is_active,
                'schedule_type' => $run->playbook->schedule_type,
                'next_run_at' => optional($run->playbook->next_run_at)->toJSON(),
                'last_run_at' => optional($run->playbook->last_run_at)->toJSON(),
            ] : null,
            'saved_segment' => $run->savedSegment ? [
                'id' => $run->savedSegment->id,
                'name' => $run->savedSegment->name,
                'module' => $run->savedSegment->module,
            ] : null,
            'summary' => [
                'message' => (string) ($summary['message'] ?? ''),
                'errors' => is_array($summary['errors'] ?? null)
                    ? array_values($summary['errors'])
                    : [],
            ],
        ];
    }
}
