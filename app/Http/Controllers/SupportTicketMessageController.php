<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PlatformSupportTicket;
use App\Models\PlatformSupportTicketMedia;
use App\Models\PlatformSupportTicketMessage;
use App\Services\SupportTicketNotificationService;
use App\Utils\FileHandler;
use App\Utils\RichTextSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class SupportTicketMessageController extends Controller
{
    public function __construct(private SupportTicketNotificationService $notificationService)
    {
    }

    public function store(Request $request, PlatformSupportTicket $ticket): RedirectResponse
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId();

        if (!$user || !$accountId || $ticket->account_id !== $accountId) {
            abort(403);
        }

        $validated = $request->validate([
            'body' => 'required_without:attachments|string|max:4000',
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
            'user_id' => $user->id,
            'body' => $body,
            'is_internal' => false,
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
                    'user_id' => $user->id,
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

        ActivityLog::record($user, $ticket, 'support_ticket.message_added', [
            'message_id' => $message->id,
            'is_internal' => false,
        ]);

        $this->notificationService->notifyNewMessage($message, $ticket->load('creator', 'assignedTo', 'account'), $user);

        return redirect()->back()->with('success', 'Message sent.');
    }
}
