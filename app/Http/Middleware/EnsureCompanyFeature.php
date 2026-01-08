<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyFeature
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        if ($user->isSuperadmin()) {
            return $next($request);
        }

        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->find($ownerId);

        if (!$owner || !$owner->hasCompanyFeature($feature)) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Module unavailable for your plan.',
                ], 403);
            }

            $message = 'Module indisponible pour votre plan.';
            $previous = url()->previous();
            $current = $request->fullUrl();
            $fallback = route('dashboard');
            $target = $previous && $previous !== $current ? $previous : $fallback;

            return redirect()->to($target)->with('warning', $message);
        }

        return $next($request);
    }

    private function featureLabel(string $feature): string
    {
        $labels = [
            'quotes' => 'Quotes',
            'plan_scans' => 'Plan scans',
            'invoices' => 'Invoices',
            'jobs' => 'Jobs',
            'products' => 'Products',
            'sales' => 'Sales',
            'services' => 'Services',
            'tasks' => 'Tasks',
            'team_members' => 'Team members',
        ];

        if (isset($labels[$feature])) {
            return $labels[$feature];
        }

        return ucfirst(str_replace('_', ' ', $feature));
    }
}
