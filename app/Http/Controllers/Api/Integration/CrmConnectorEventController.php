<?php

namespace App\Http\Controllers\Api\Integration;

use App\Http\Controllers\Controller;
use App\Services\CRM\ConnectorEventIngestionService;
use App\Services\CRM\Connectors\CrmConnectorRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CrmConnectorEventController extends Controller
{
    public function __construct(
        private readonly ConnectorEventIngestionService $ingestionService,
        private readonly CrmConnectorRegistry $registry,
    ) {}

    public function store(Request $request)
    {
        $this->ensureAbility($request, 'crm:write');

        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $validated = $request->validate([
            'connector_key' => ['required', 'string', 'max:40', Rule::in($this->connectorKeys())],
            'family' => ['required', 'string', Rule::in(['message', 'meeting'])],
            'event' => ['required', 'string', 'max:60'],
            'subject_type' => ['required', 'string', Rule::in(['customer', 'request', 'quote'])],
            'subject_id' => ['required', 'integer'],
            'occurred_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'payload' => ['nullable', 'array'],
            'context' => ['nullable', 'array'],
            'context.customer_id' => ['nullable', 'integer'],
            'context.request_id' => ['nullable', 'integer'],
            'context.quote_id' => ['nullable', 'integer'],
        ]);

        $activity = $this->ingestionService->ingest($user, $validated);

        return response()->json([
            'message' => 'CRM connector event recorded.',
            'activity' => $activity->toArray(),
        ], 201);
    }

    /**
     * @return array<int, string>
     */
    private function connectorKeys(): array
    {
        return collect($this->registry->definitions())
            ->pluck('key')
            ->filter(fn ($key) => is_string($key) && trim($key) !== '')
            ->values()
            ->all();
    }

    private function ensureAbility(Request $request, string $ability): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $token = $user->currentAccessToken();
        if ($token && ! $user->tokenCan($ability)) {
            abort(403);
        }
    }
}
