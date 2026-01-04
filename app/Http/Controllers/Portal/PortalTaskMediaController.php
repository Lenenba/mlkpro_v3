<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Task;
use App\Models\TaskMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PortalTaskMediaController extends Controller
{
    private function portalCustomer(Request $request): Customer
    {
        $customer = $request->user()?->customerProfile;
        if (!$customer) {
            abort(403);
        }

        return $customer;
    }

    public function store(Request $request, Task $task)
    {
        $customer = $this->portalCustomer($request);
        if ($customer->auto_validate_tasks) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Task actions are handled by the company.',
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => 'Task actions are handled by the company.',
            ]);
        }

        $task->loadMissing('work');

        $workCustomerId = $task->work?->customer_id;
        if ($task->customer_id !== $customer->id && $workCustomerId !== $customer->id) {
            abort(403);
        }

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
                'source' => 'client',
            ],
        ]);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Proof uploaded successfully.',
                'media' => [
                    'id' => $media->id,
                    'type' => $media->type,
                    'media_type' => $media->media_type,
                    'url' => Storage::disk('public')->url($media->path),
                    'note' => $media->meta['note'] ?? null,
                ],
            ], 201);
        }

        return redirect()->back()->with('success', 'Proof uploaded successfully.');
    }
}
