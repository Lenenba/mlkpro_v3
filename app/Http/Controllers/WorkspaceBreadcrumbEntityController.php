<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\WorkspaceBreadcrumbEntityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceBreadcrumbEntityController extends Controller
{
    public function __invoke(
        Request $request,
        string $type,
        WorkspaceBreadcrumbEntityService $entities,
    ): JsonResponse {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:15'],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 10);
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return response()->json(
            $entities->search($user, $type, $query, $limit)
        );
    }
}
