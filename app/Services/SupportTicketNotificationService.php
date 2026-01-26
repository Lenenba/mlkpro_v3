<?php

namespace App\Services;

use App\Models\PlatformSupportTicket;
use App\Models\PlatformSupportTicketMessage;
use App\Models\User;
use App\Support\NotificationDispatcher;
use App\Notifications\ActionEmailNotification;
use Illuminate\Support\Facades\URL;

class SupportTicketNotificationService
{
    public function __construct(private SupportAssignmentService $assignmentService)
    {
    }

    public function notifyAssignment(PlatformSupportTicket $ticket, User $assignee): void
    {
        $creator = $ticket->creator;
        if (!$creator) {
            return;
        }

        NotificationDispatcher::send($creator, new ActionEmailNotification(
            'Support request assigned',
            "{$assignee->name} is now assigned to your request.",
            [
                ['label' => 'Ticket', 'value' => "#{$ticket->id} - {$ticket->title}"],
                ['label' => 'Status', 'value' => ucfirst($ticket->status)],
                ['label' => 'Assigned to', 'value' => $assignee->name],
            ],
            URL::route('settings.support.show', $ticket->id),
            'View support request'
        ), [
            'ticket_id' => $ticket->id,
            'assigned_to' => $assignee->id,
        ]);
    }

    public function notifyNewMessage(PlatformSupportTicketMessage $message, PlatformSupportTicket $ticket, User $author): void
    {
        if ($message->is_internal) {
            return;
        }

        if ($author->isSuperadmin() || $author->isPlatformAdmin()) {
            $creator = $ticket->creator;
            if (!$creator) {
                return;
            }

            NotificationDispatcher::send($creator, new ActionEmailNotification(
                'New response on your support request',
                $author->name . ' replied to your request.',
                [
                    ['label' => 'Ticket', 'value' => "#{$ticket->id} - {$ticket->title}"],
                ],
                URL::route('settings.support.show', $ticket->id),
                'View support request'
            ), [
                'ticket_id' => $ticket->id,
                'message_id' => $message->id,
            ]);

            return;
        }

        $assignee = $ticket->assignedTo;
        if ($assignee) {
            NotificationDispatcher::send($assignee, new ActionEmailNotification(
                'New client message',
                $author->name . ' replied to a support request.',
                [
                    ['label' => 'Ticket', 'value' => "#{$ticket->id} - {$ticket->title}"],
                    ['label' => 'Company', 'value' => $ticket->account?->company_name ?? $ticket->account?->email],
                ],
                URL::route('superadmin.support.show', $ticket->id),
                'View support request'
            ), [
                'ticket_id' => $ticket->id,
                'message_id' => $message->id,
            ]);

            return;
        }

        $agents = $this->assignmentService->agents();
        foreach ($agents as $agent) {
            NotificationDispatcher::send($agent, new ActionEmailNotification(
                'New client message',
                $author->name . ' replied to a support request.',
                [
                    ['label' => 'Ticket', 'value' => "#{$ticket->id} - {$ticket->title}"],
                    ['label' => 'Company', 'value' => $ticket->account?->company_name ?? $ticket->account?->email],
                ],
                URL::route('superadmin.support.show', $ticket->id),
                'View support request'
            ), [
                'ticket_id' => $ticket->id,
                'message_id' => $message->id,
                'broadcast' => true,
            ]);
        }
    }
}
