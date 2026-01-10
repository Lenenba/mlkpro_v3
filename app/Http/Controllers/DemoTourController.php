<?php

namespace App\Http\Controllers;

use App\Models\DemoTourProgress;
use App\Models\DemoTourStep;
use App\Models\User;
use App\Services\Demo\DemoAccountService;
use App\Services\Demo\DemoContextService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DemoTourController extends Controller
{
    public function steps(Request $request, DemoContextService $context)
    {
        $user = $request->user();
        if (!$this->isGuidedDemo($user)) {
            abort(403);
        }

        $steps = DemoTourStep::query()
            ->orderBy('order_index')
            ->get();

        $payload = $steps->map(function (DemoTourStep $step) use ($user, $context) {
            $data = $step->payload_json ?? [];
            $routeParams = $context->resolveRouteParams($user, $data['route_params'] ?? []);

            return [
                'key' => $step->key,
                'title' => $step->title,
                'description' => $step->description,
                'route_name' => $step->route_name,
                'route_params' => $routeParams,
                'selector' => $step->selector,
                'placement' => $step->placement,
                'order_index' => $step->order_index,
                'is_required' => (bool) $step->is_required,
                'group' => $data['group'] ?? 'General',
                'completion' => $data['completion'] ?? null,
                'payload' => $data,
            ];
        })->values();

        return response()->json(['steps' => $payload]);
    }

    public function progress(Request $request)
    {
        $user = $request->user();
        if (!$this->isGuidedDemo($user)) {
            abort(403);
        }

        $progress = DemoTourProgress::query()
            ->where('user_id', $user->id)
            ->get(['step_key', 'status', 'completed_at', 'metadata_json'])
            ->map(fn (DemoTourProgress $entry) => [
                'step_key' => $entry->step_key,
                'status' => $entry->status,
                'completed_at' => $entry->completed_at,
                'metadata' => $entry->metadata_json,
            ])
            ->values();

        return response()->json(['progress' => $progress]);
    }

    public function updateProgress(Request $request)
    {
        $user = $request->user();
        if (!$this->isGuidedDemo($user)) {
            abort(403);
        }

        $validated = $request->validate([
            'step_key' => ['required', 'string', Rule::exists('demo_tour_steps', 'key')],
            'status' => ['required', Rule::in(['pending', 'done', 'skipped'])],
            'metadata' => ['nullable', 'array'],
        ]);

        $completedAt = in_array($validated['status'], ['done', 'skipped'], true) ? now() : null;

        $progress = DemoTourProgress::updateOrCreate(
            [
                'user_id' => $user->id,
                'step_key' => $validated['step_key'],
            ],
            [
                'status' => $validated['status'],
                'completed_at' => $completedAt,
                'metadata_json' => $validated['metadata'] ?? null,
            ]
        );

        return response()->json([
            'progress' => [
                'step_key' => $progress->step_key,
                'status' => $progress->status,
                'completed_at' => $progress->completed_at,
                'metadata' => $progress->metadata_json,
            ],
        ]);
    }

    public function reset(Request $request)
    {
        $user = $request->user();
        if (!$this->isGuidedDemo($user)) {
            abort(403);
        }

        DemoTourProgress::query()
            ->where('user_id', $user->id)
            ->delete();

        return response()->json(['message' => 'Tour progress reset.']);
    }

    private function isGuidedDemo(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        if (!(bool) $user->is_demo_user) {
            return false;
        }

        return $user->demo_type === DemoAccountService::TYPE_GUIDED || $user->demo_role === 'guided_demo';
    }
}
