<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CRM\MyNextActionsService;
use Illuminate\Http\Request;
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
        ]);

        $filters = [
            'search' => trim((string) ($validated['search'] ?? '')),
            'source' => (string) ($validated['source'] ?? ''),
            'subject_type' => (string) ($validated['subject_type'] ?? ''),
            'due_state' => (string) ($validated['due_state'] ?? 'all'),
            'reference_time' => $validated['reference_time'] ?? null,
        ];

        $payload = $this->myNextActionsService->execute($actor, array_filter($filters, function (mixed $value, string $key): bool {
            if ($key === 'due_state') {
                return is_string($value) && $value !== '' && $value !== 'all';
            }

            return ! ($value === null || $value === '');
        }, ARRAY_FILTER_USE_BOTH));

        return $this->inertiaOrJson('CRM/MyNextActions', [
            'items' => $payload['items'],
            'count' => $payload['count'],
            'stats' => $payload['stats'],
            'reference_time' => $payload['reference_time'],
            'filters' => $filters,
            'options' => [
                'sources' => $this->sourceOptions(),
                'subject_types' => $this->subjectTypeOptions(),
                'due_states' => $this->dueStateOptions(),
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
}
