<?php

namespace App\Http\Controllers;

use App\Models\LeadNote;
use App\Models\Request as LeadRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RequestNoteController extends Controller
{
    public function store(Request $request, LeadRequest $lead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (!$user || $lead->user_id !== $accountId) {
            abort(403);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $note = LeadNote::create([
            'request_id' => $lead->id,
            'user_id' => $user->id,
            'body' => $validated['body'],
        ]);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Note added.',
                'note' => $note->load('user:id,name'),
            ], 201);
        }

        return redirect()->back()->with('success', 'Note added.');
    }

    public function destroy(Request $request, LeadRequest $lead, LeadNote $note)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (!$user || $lead->user_id !== $accountId || $note->request_id !== $lead->id) {
            abort(403);
        }

        $note->delete();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Note deleted.',
            ]);
        }

        return redirect()->back()->with('success', 'Note deleted.');
    }
}
