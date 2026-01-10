<?php

namespace App\Http\Controllers;

use App\Models\DemoTourProgress;
use App\Models\DemoTourStep;
use App\Models\User;
use App\Services\Demo\DemoAccountService;
use App\Services\Demo\DemoContextService;
use App\Services\Demo\DemoResetService;
use App\Services\Demo\DemoSeedService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DemoController extends Controller
{
    public function index()
    {
        if (!config('demo.enabled')) {
            abort(404);
        }

        return inertia('Demo/Index');
    }

    public function login(Request $request, string $type, DemoAccountService $accounts, DemoSeedService $seeds)
    {
        if (!config('demo.enabled')) {
            abort(404);
        }

        $account = $accounts->resolveDemoAccount($type);
        $seeds->seed($account, $type);

        Auth::login($account, true);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function checklist(Request $request, DemoContextService $context)
    {
        $user = $request->user();
        if (!$this->isGuidedDemo($user)) {
            abort(403);
        }

        $steps = DemoTourStep::query()->orderBy('order_index')->get();
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
            ];
        })->values();

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

        return inertia('Demo/Checklist', [
            'steps' => $payload,
            'progress' => $progress,
        ]);
    }

    public function reset(Request $request, DemoResetService $reset, DemoSeedService $seeds)
    {
        $user = $request->user();
        if (!$user || !(bool) $user->is_demo_user) {
            abort(403);
        }

        if (!config('demo.enabled') || !config('demo.allow_reset')) {
            abort(403);
        }

        $accountId = $user->accountOwnerId();
        $account = $accountId === $user->id
            ? $user
            : User::query()->findOrFail($accountId);

        $demoType = $account->demo_type ?: DemoAccountService::TYPE_SERVICE;

        $reset->reset($account);
        $seeds->seed($account, $demoType);

        if ($this->shouldReturnJson($request)) {
            return response()->json(['message' => 'Demo reset complete.']);
        }

        return redirect()->back()->with('success', 'Demo reset complete.');
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
