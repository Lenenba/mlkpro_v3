<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CRM\MyNextActionsService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class MyNextActionsController extends Controller
{
    public function __construct(
        private readonly MyNextActionsService $myNextActionsService,
    ) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        if (! $actor || $actor->isClient() || $actor->isSuperadmin() || $actor->isPlatformAdmin()) {
            abort(403);
        }

        $ownerId = $actor->accountOwnerId();
        $owner = (int) $ownerId === (int) $actor->id
            ? $actor
            : User::query()->find($ownerId);

        if (! $owner) {
            abort(403);
        }

        if ((string) $owner->company_type === 'products') {
            abort(404);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', 'string', Rule::in($this->sourceOptions())],
            'subject_type' => ['nullable', 'string', Rule::in($this->subjectTypeOptions())],
            'due_state' => ['nullable', 'string', Rule::in($this->dueStateOptions())],
            'reference_time' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', Rule::in($this->perPageOptions())],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? $this->defaultPerPage());
        $page = max(1, (int) ($validated['page'] ?? 1));

        $filters = [
            'search' => trim((string) ($validated['search'] ?? '')),
            'source' => (string) ($validated['source'] ?? ''),
            'subject_type' => (string) ($validated['subject_type'] ?? ''),
            'due_state' => (string) ($validated['due_state'] ?? 'all'),
            'reference_time' => $validated['reference_time'] ?? null,
            'per_page' => $perPage,
        ];

        $payload = $this->myNextActionsService->execute($actor, array_filter($filters, function (mixed $value, string $key): bool {
            if ($key === 'due_state') {
                return is_string($value) && $value !== '' && $value !== 'all';
            }

            return ! ($value === null || $value === '');
        }, ARRAY_FILTER_USE_BOTH));

        $pagination = $this->paginateItems(
            collect($payload['items']),
            $page,
            $perPage,
            $request
        );

        return $this->inertiaOrJson('CRM/MyNextActions', [
            'items' => $pagination->items(),
            'count' => $payload['count'],
            'stats' => $payload['stats'],
            'reference_time' => $payload['reference_time'],
            'filters' => $filters,
            'pagination' => $pagination->toArray(),
            'options' => [
                'sources' => $this->sourceOptions(),
                'subject_types' => $this->subjectTypeOptions(),
                'due_states' => $this->dueStateOptions(),
                'per_page_options' => $this->perPageOptions(),
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function sourceOptions(): array
    {
        return [
            'sales_activity',
            'task',
            'request_follow_up',
            'quote_follow_up',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function subjectTypeOptions(): array
    {
        return [
            'request',
            'quote',
            'task',
            'customer',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function dueStateOptions(): array
    {
        return [
            'all',
            'overdue',
            'today',
            'upcoming',
        ];
    }

    /**
     * @return array<int, int>
     */
    private function perPageOptions(): array
    {
        return [6, 9, 12, 18];
    }

    private function defaultPerPage(): int
    {
        return 9;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     */
    private function paginateItems(Collection $items, int $page, int $perPage, Request $request): LengthAwarePaginator
    {
        $paginator = new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values()->all(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'pageName' => 'page',
            ]
        );

        return $paginator
            ->appends($request->except('page'))
            ->onEachSide(1);
    }
}
