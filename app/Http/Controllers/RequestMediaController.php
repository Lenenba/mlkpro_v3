<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\LeadMedia;
use App\Models\Request as LeadRequest;
use App\Services\ProspectInteractionLogger;
use App\Utils\FileHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class RequestMediaController extends Controller
{
    public function store(Request $request, LeadRequest $lead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        $this->ensureProspectWorkspaceWriteAccess($user, $accountId, $request);

        if ($lead->user_id !== $accountId) {
            abort(403);
        }

        $this->ensureLeadIsMutable(
            $lead,
            'lead',
            'Archived prospects must be restored before files can be changed.'
        );

        $validated = $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:10000',
            'meta' => 'nullable|array',
        ]);

        $file = $request->file('file');
        $mime = $file?->getClientMimeType();
        $originalName = $file?->getClientOriginalName();
        $size = $file?->getSize();

        $path = null;
        if ($file && str_starts_with((string) $mime, 'image/')) {
            $path = FileHandler::handleImageUpload('lead-media', $request, 'file', null);
        } elseif ($file) {
            $path = $file->store('lead-media', 'public');
        }

        $media = LeadMedia::create([
            'request_id' => $lead->id,
            'user_id' => $user->id,
            'path' => $path ?? '',
            'original_name' => $originalName,
            'mime' => $mime,
            'size' => $size,
            'meta' => $validated['meta'] ?? null,
        ]);

        $lead->update([
            'last_activity_at' => now(),
        ]);

        ActivityLog::record($user, $lead, 'file_uploaded', [
            'media_id' => $media->id,
            'original_name' => $media->original_name,
            'mime' => $media->mime,
        ], 'Prospect file uploaded');
        app(ProspectInteractionLogger::class)->recordMediaAdded($lead, $user, $media);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'File uploaded.',
                'media' => $media->load('user:id,name'),
            ], 201);
        }

        return redirect()->back()->with('success', 'File uploaded.');
    }

    public function destroy(Request $request, LeadRequest $lead, LeadMedia $media)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        $this->ensureProspectWorkspaceWriteAccess($user, $accountId, $request);

        if ($lead->user_id !== $accountId || $media->request_id !== $lead->id) {
            abort(403);
        }

        $this->ensureLeadIsMutable(
            $lead,
            'lead',
            'Archived prospects must be restored before files can be changed.'
        );

        if ($media->path && Storage::disk('public')->exists($media->path)) {
            Storage::disk('public')->delete($media->path);
        }

        $media->delete();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'File deleted.',
            ]);
        }

        return redirect()->back()->with('success', 'File deleted.');
    }
}
