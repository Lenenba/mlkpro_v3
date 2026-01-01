<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicTaskMediaController extends Controller
{
    public function store(Request $request, Task $task): RedirectResponse
    {
        $task->loadMissing('work.customer');
        $customer = $task->customer ?: $task->work?->customer;
        if ($customer && $customer->auto_validate_tasks) {
            return redirect()->back()->withErrors([
                'status' => 'Task actions are handled by the company.',
            ]);
        }

        $validated = $request->validate([
            'type' => ['required', Rule::in(['execution', 'completion', 'other'])],
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,mp4,mov,webm,ogg|max:25600',
            'note' => 'nullable|string|max:255',
        ]);

        $file = $request->file('file');
        if (!$file) {
            return redirect()->back()->withErrors([
                'file' => 'Upload failed.',
            ]);
        }

        $path = $file->store('task-media', 'public');
        $mime = $file->getMimeType() ?: '';
        $mediaType = str_starts_with($mime, 'video/') ? 'video' : 'image';
        if (!$task->account_id) {
            return redirect()->back()->withErrors([
                'status' => 'Unable to record proof.',
            ]);
        }

        TaskMedia::create([
            'task_id' => $task->id,
            'user_id' => $task->account_id,
            'type' => $validated['type'],
            'media_type' => $mediaType,
            'path' => $path,
            'meta' => [
                'note' => $validated['note'] ?? null,
                'source' => 'client-public',
            ],
        ]);

        return redirect()->back()->with('success', 'Proof uploaded successfully.');
    }
}
