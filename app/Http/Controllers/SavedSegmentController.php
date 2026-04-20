<?php

namespace App\Http\Controllers;

use App\Models\SavedSegment;
use App\Models\User;
use App\Services\CompanyFeatureService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SavedSegmentController extends Controller
{
    public function __construct(
        private readonly CompanyFeatureService $companyFeatureService,
    ) {
    }

    public function index(Request $request)
    {
        [$owner, $canManage] = $this->resolveAccess($request->user());
        if (! $canManage) {
            abort(403);
        }

        $module = $this->validatedModule($request);
        $this->ensureModuleFeatureEnabled($owner, $module);

        return response()->json([
            'segments' => $this->listSegments($owner->id, $module),
        ]);
    }

    public function store(Request $request)
    {
        [$owner, $canManage] = $this->resolveAccess($request->user());
        if (! $canManage) {
            abort(403);
        }

        $validated = $this->validatedPayload($request, $owner);
        $this->ensureModuleFeatureEnabled($owner, $validated['module']);

        $segment = SavedSegment::create([
            'user_id' => $owner->id,
            'created_by_user_id' => $request->user()?->id,
            'updated_by_user_id' => $request->user()?->id,
            ...$validated,
        ]);

        return response()->json([
            'message' => 'Saved segment created.',
            'segment' => $segment->fresh(),
        ], 201);
    }

    public function update(Request $request, SavedSegment $savedSegment)
    {
        [$owner, $canManage] = $this->resolveAccess($request->user());
        if (! $canManage) {
            abort(403);
        }

        $this->ensureOwnedSegment($owner, $savedSegment);
        $validated = $this->validatedPayload($request, $owner, $savedSegment);

        $savedSegment->fill([
            ...$validated,
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

        return response()->json([
            'message' => 'Saved segment updated.',
            'segment' => $savedSegment->fresh(),
        ]);
    }

    public function destroy(Request $request, SavedSegment $savedSegment)
    {
        [$owner, $canManage] = $this->resolveAccess($request->user());
        if (! $canManage) {
            abort(403);
        }

        $this->ensureOwnedSegment($owner, $savedSegment);
        $savedSegment->delete();

        return response()->json([
            'message' => 'Saved segment deleted.',
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

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, User $owner, ?SavedSegment $savedSegment = null): array
    {
        $module = $savedSegment?->module ?? $this->validatedModule($request);

        $validated = $request->validate([
            'module' => ['sometimes', 'string', Rule::in([$module])],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('saved_segments', 'name')
                    ->where(fn ($query) => $query
                        ->where('user_id', $owner->id)
                        ->where('module', $module))
                    ->ignore($savedSegment?->id),
            ],
            'description' => 'nullable|string|max:1024',
            'filters' => 'nullable|array',
            'sort' => 'nullable|array',
            'search_term' => 'nullable|string|max:255',
            'is_shared' => 'nullable|boolean',
        ]);

        return [
            'module' => $module,
            'name' => trim((string) $validated['name']),
            'description' => isset($validated['description']) && $validated['description'] !== null
                ? trim((string) $validated['description'])
                : null,
            'filters' => is_array($validated['filters'] ?? null) ? $validated['filters'] : [],
            'sort' => is_array($validated['sort'] ?? null) ? $validated['sort'] : [],
            'search_term' => isset($validated['search_term']) && trim((string) $validated['search_term']) !== ''
                ? trim((string) $validated['search_term'])
                : null,
            'is_shared' => (bool) ($validated['is_shared'] ?? false),
        ];
    }

    private function validatedModule(Request $request): string
    {
        $validated = $request->validate([
            'module' => ['required', 'string', Rule::in(SavedSegment::allowedModules())],
        ]);

        return (string) $validated['module'];
    }

    private function ensureOwnedSegment(User $owner, SavedSegment $savedSegment): void
    {
        if ((int) $savedSegment->user_id !== (int) $owner->id) {
            abort(404);
        }

        $this->ensureModuleFeatureEnabled($owner, (string) $savedSegment->module);
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

    private function listSegments(int $ownerId, string $module)
    {
        return SavedSegment::query()
            ->byUser($ownerId)
            ->where('module', $module)
            ->orderByDesc('updated_at')
            ->orderBy('name')
            ->get([
                'id',
                'user_id',
                'created_by_user_id',
                'updated_by_user_id',
                'module',
                'name',
                'description',
                'filters',
                'sort',
                'search_term',
                'is_shared',
                'cached_count',
                'last_resolved_at',
                'created_at',
                'updated_at',
            ]);
    }
}
