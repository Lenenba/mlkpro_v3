<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TaskMediaController extends Controller
{
    public function store(Request $request, Task $task)
    {
        $this->authorize('update', $task);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['execution', 'completion', 'other'])],
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,mp4,mov,webm,ogg|max:25600',
            'note' => 'nullable|string|max:255',
        ]);

        $file = $request->file('file');
        if (!$file) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Upload failed.',
                    'errors' => [
                        'file' => ['Upload failed.'],
                    ],
                ], 422);
            }

            return redirect()->back()->withErrors([
                'file' => 'Upload failed.',
            ]);
        }

        $path = $file->store('task-media', 'public');
        $mime = $file->getMimeType() ?: '';
        $mediaType = str_starts_with($mime, 'video/') ? 'video' : 'image';

        $media = TaskMedia::create([
            'task_id' => $task->id,
            'user_id' => Auth::id(),
            'type' => $validated['type'],
            'media_type' => $mediaType,
            'path' => $path,
            'meta' => [
                'note' => $validated['note'] ?? null,
                'source' => Auth::user()?->isClient() ? 'client' : 'team',
            ],
        ]);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Proof uploaded successfully.',
                'media' => $media,
            ], 201);
        }

        return redirect()->back()->with('success', 'Proof uploaded successfully.');
    }
}
