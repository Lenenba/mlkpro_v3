<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Queries\CRM\BuildSalesInboxIndexData;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesInboxController extends Controller
{
    public function __construct(
        private readonly BuildSalesInboxIndexData $salesInboxIndexData,
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

        if ((int) $actor->id !== (int) $owner->id) {
            $membership = $actor->relationLoaded('teamMembership')
                ? $actor->teamMembership
                : $actor->teamMembership()->first();

            if (! $membership || ! $membership->hasPermission('sales.manage')) {
                abort(403);
            }
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'queue' => ['nullable', 'string', Rule::in(BuildSalesInboxIndexData::QUEUES)],
            'stage' => ['nullable', 'string', Rule::in(BuildSalesInboxIndexData::stageOptions())],
            'reference_time' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', Rule::in($this->dataTablePerPageOptions())],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $payload = $this->salesInboxIndexData->execute($owner->id, $request);
        $paginator = $payload['items'];

        return $this->inertiaOrJson('CRM/SalesInbox', [
            'items' => $paginator->items(),
            'count' => $payload['count'],
            'stats' => $payload['stats'],
            'queues' => $payload['queues'],
            'reference_time' => $payload['reference_time'],
            'filters' => [
                'search' => trim((string) ($validated['search'] ?? '')),
                'queue' => (string) ($validated['queue'] ?? ''),
                'stage' => (string) ($validated['stage'] ?? ''),
                'per_page' => (int) ($validated['per_page'] ?? $this->defaultDataTablePerPage()),
                'reference_time' => $validated['reference_time'] ?? $payload['reference_time'],
            ],
            'pagination' => $paginator->toArray(),
            'options' => [
                'queues' => BuildSalesInboxIndexData::QUEUES,
                'stages' => BuildSalesInboxIndexData::stageOptions(),
                'per_page_options' => $this->dataTablePerPageOptions(),
            ],
        ]);
    }
}
