<?php

namespace App\Http\Controllers;

use App\Models\Playbook;
use App\Models\PlaybookRun;
use App\Models\SavedSegment;
use App\Models\User;
use App\Services\CompanyFeatureService;
use App\Services\Playbooks\PlaybookExecutionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlaybookController extends Controller
{
    public function __construct(
        private readonly CompanyFeatureService $companyFeatureService,
        private readonly PlaybookExecutionService $playbookExecutionService,
    ) {}

    public function store(Request $request)
    {
        [$owner, $canManage] = $this->resolveAccess($request->user());
        if (! $canManage) {
            abort(403);
        }

        $baseValidated = $request->validate([
            'saved_segment_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'action_payload' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $segment = SavedSegment::query()
            ->byUser($owner->id)
            ->findOrFail((int) $baseValidated['saved_segment_id']);

        $this->ensureModuleFeatureEnabled($owner, (string) $segment->module);

        $validated = $request->validate([
            'action_key' => [
                'required',
                'string',
                Rule::in($this->allowedActionsForModule((string) $segment->module)),
            ],
        ]);

        $playbook = Playbook::create([
            'user_id' => $owner->id,
            'saved_segment_id' => $segment->id,
            'created_by_user_id' => $request->user()?->id,
            'updated_by_user_id' => $request->user()?->id,
            'module' => (string) $segment->module,
            'name' => trim((string) $baseValidated['name']),
            'action_key' => (string) $validated['action_key'],
            'action_payload' => is_array($baseValidated['action_payload'] ?? null)
                ? $baseValidated['action_payload']
                : [],
            'schedule_type' => Playbook::SCHEDULE_MANUAL,
            'is_active' => (bool) ($baseValidated['is_active'] ?? true),
        ]);

        return response()->json([
            'message' => 'Playbook created.',
            'playbook' => $this->mapPlaybook($playbook->fresh(['savedSegment']) ?? $playbook),
        ], 201);
    }

    public function run(Request $request, Playbook $playbook)
    {
        [$owner, $canManage] = $this->resolveAccess($request->user());
        if (! $canManage) {
            abort(403);
        }

        $this->ensureOwnedPlaybook($owner, $playbook);
        $this->ensureModuleFeatureEnabled($owner, (string) $playbook->module);

        $run = $this->playbookExecutionService->executeManual($playbook, $request->user());

        return response()->json([
            'message' => (string) data_get($run->summary, 'message', 'Playbook executed.'),
            'run' => $this->mapRun($run->fresh(['playbook', 'savedSegment', 'requestedBy']) ?? $run),
        ]);
    }

    /**
     * @return array{0: User, 1: bool}
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

        return [$owner, (int) $user->id === (int) $owner->id];
    }

    private function ensureOwnedPlaybook(User $owner, Playbook $playbook): void
    {
        if ((int) $playbook->user_id !== (int) $owner->id) {
            abort(404);
        }
    }

    private function ensureModuleFeatureEnabled(User $owner, string $module): void
    {
        $feature = match ($module) {
            SavedSegment::MODULE_REQUEST => 'requests',
            SavedSegment::MODULE_QUOTE => 'quotes',
            default => null,
        };

        if ($feature && ! $this->companyFeatureService->hasFeature($owner, $feature)) {
            abort(403);
        }
    }

    /**
     * @return array<int, string>
     */
    private function allowedActionsForModule(string $module): array
    {
        return match ($module) {
            SavedSegment::MODULE_REQUEST => [
                'assign_selected',
                'update_status',
            ],
            SavedSegment::MODULE_CUSTOMER => [
                'portal_enable',
                'portal_disable',
                'archive',
                'restore',
            ],
            SavedSegment::MODULE_QUOTE => [
                'schedule_follow_up',
                'mark_followed_up',
                'create_follow_up_task',
                'archive',
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPlaybook(Playbook $playbook): array
    {
        return [
            'id' => $playbook->id,
            'saved_segment_id' => $playbook->saved_segment_id,
            'module' => (string) $playbook->module,
            'name' => (string) $playbook->name,
            'action_key' => (string) $playbook->action_key,
            'action_payload' => is_array($playbook->action_payload) ? $playbook->action_payload : [],
            'schedule_type' => (string) $playbook->schedule_type,
            'is_active' => (bool) $playbook->is_active,
            'saved_segment' => $playbook->savedSegment ? [
                'id' => $playbook->savedSegment->id,
                'name' => $playbook->savedSegment->name,
                'module' => $playbook->savedSegment->module,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRun(PlaybookRun $run): array
    {
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
            'summary' => is_array($run->summary) ? $run->summary : [],
            'playbook' => $run->playbook ? [
                'id' => $run->playbook->id,
                'name' => $run->playbook->name,
            ] : null,
            'saved_segment' => $run->savedSegment ? [
                'id' => $run->savedSegment->id,
                'name' => $run->savedSegment->name,
                'module' => $run->savedSegment->module,
            ] : null,
            'requested_by' => $run->requestedBy ? [
                'id' => $run->requestedBy->id,
                'name' => $run->requestedBy->name,
            ] : null,
        ];
    }
}
