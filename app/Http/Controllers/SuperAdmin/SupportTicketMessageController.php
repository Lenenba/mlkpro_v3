<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\ActivityLog;
use App\Models\PlatformSupportTicket;
use App\Models\PlatformSupportTicketMedia;
use App\Models\PlatformSupportTicketMessage;
use App\Services\SupportTicketNotificationService;
use App\Support\PlatformPermissions;
use App\Utils\FileHandler;
use App\Utils\RichTextSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class SupportTicketMessageController extends BaseSuperAdminController
{
    public function __construct(private SupportTicketNotificationService $notificationService)
    {
    }

    public function store(Request $request, PlatformSupportTicket $ticket): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::SUPPORT_MANAGE);

        $validated = $request->validate([
            'body' => 'required_without:attachments|string|max:4000',
            'is_internal' => 'nullable|boolean',
            'attachments' => 'nullable|array|max:4',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10000',
        ]);

        $body = RichTextSanitizer::sanitize($validated['body'] ?? '');
        $hasAttachments = $request->hasFile('attachments');
        if (trim(strip_tags($body)) === '' && !$hasAttachments) {
            return redirect()->back()->withErrors([
                'body' => __('validation.required', ['attribute' => 'message']),
            ]);
        }

        $message = PlatformSupportTicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()?->id,
            'body' => $body,
            'is_internal' => (bool) ($validated['is_internal'] ?? false),
        ]);

        $mediaIds = [];
        if ($hasAttachments) {
            foreach ($request->file('attachments', []) as $file) {
                if (!$file instanceof UploadedFile) {
                    continue;
                }

                $mime = $file->getClientMimeType();
                $path = FileHandler::storeFile('support-media', $file);

                $media = PlatformSupportTicketMedia::query()->create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $request->user()?->id,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime' => $mime,
                    'size' => $file->getSize(),
                    'meta' => ['message_id' => $message->id],
                ]);

                $mediaIds[] = $media->id;
            }
        }

        if ($mediaIds) {
            $message->meta = array_merge($message->meta ?? [], [
                'media_ids' => $mediaIds,
            ]);
            $message->save();
        }

        ActivityLog::record($request->user(), $ticket, 'support_ticket.message_added', [
            'message_id' => $message->id,
            'is_internal' => $message->is_internal,
        ]);

        if (!$message->is_internal) {
            $this->notificationService->notifyNewMessage($message, $ticket->load('creator', 'assignedTo', 'account'), $request->user());
        }

        $this->logAudit($request, 'support_ticket.message_added', $ticket, [
            'message_id' => $message->id,
            'is_internal' => $message->is_internal,
        ]);

        return redirect()->back()->with('success', 'Message sent.');
    }
}
