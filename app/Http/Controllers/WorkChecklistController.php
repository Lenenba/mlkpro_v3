<?php

namespace App\Http\Controllers;

use App\Models\Work;
use App\Models\WorkChecklistItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class WorkChecklistController extends Controller
{
    public function update(Request $request, Work $work, WorkChecklistItem $item): RedirectResponse
    {
        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        if (!$user || $work->user_id !== $accountId) {
            abort(403);
        }

        if ($item->work_id !== $work->id) {
            abort(404);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'done'])],
        ]);

        $item->status = $validated['status'];
        $item->completed_at = $validated['status'] === 'done' ? now() : null;
        $item->save();

        return redirect()->back()->with('success', 'Checklist updated.');
    }
}

