<?php

namespace App\Http\Controllers;

use App\Models\Work;
use App\Models\WorkMedia;
use App\Utils\FileHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class WorkMediaController extends Controller
{
    public function store(Request $request, Work $work)
    {
        $user = Auth::user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        if (!$user || $work->user_id !== $accountId) {
            abort(403);
        }

        $validated = $request->validate([
            'type' => ['required', Rule::in(['before', 'after', 'other'])],
            'image' => 'required|image|mimes:jpg,png,jpeg,webp|max:5000',
            'meta' => 'nullable|array',
        ]);

        $path = FileHandler::handleImageUpload('work-media', $request, 'image', null);

        $media = WorkMedia::create([
            'work_id' => $work->id,
            'user_id' => $user->id,
            'type' => $validated['type'],
            'path' => $path,
            'meta' => $validated['meta'] ?? null,
        ]);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Photo uploaded successfully.',
                'media' => $media,
            ], 201);
        }

        return redirect()->back()->with('success', 'Photo uploaded successfully.');
    }
}
