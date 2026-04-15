<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class WorkspaceCategoryController extends Controller
{
    private const CATEGORIES = [
        'revenue',
        'growth',
        'operations',
        'finance',
        'catalog',
        'workspace',
    ];

    public function show(Request $request, string $category)
    {
        $actor = $request->user();

        if (! $actor || $actor->isClient() || $actor->isSuperadmin() || $actor->isPlatformAdmin()) {
            abort(403);
        }

        if (! in_array($category, self::CATEGORIES, true)) {
            abort(404);
        }

        return $this->inertiaOrJson('Workspace/CategoryHub', [
            'category' => $category,
        ]);
    }
}
