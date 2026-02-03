<?php

namespace App\Notifications;

use App\Models\Request as LeadRequest;
use App\Services\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeadFollowUpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public LeadRequest $lead,
        public string $type = 'follow_up_overdue',
        public int $hours = 24
    ) {
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (!empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->title();
        $message = $this->message();
        $owner = $this->lead->user;
        $companyName = $owner?->company_name ?: config('app.name');
        $companyLogo = $owner?->company_logo_url;

        return (new MailMessage)
            ->subject($title)
            ->view('emails.notifications.action', [
                'title' => $title,
                'intro' => $message,
                'details' => [
                    ['label' => 'Lead', 'value' => $this->leadLabel()],
                    ['label' => 'Status', 'value' => $this->lead->status ?? '-'],
                ],
                'actionUrl' => $this->actionUrl(),
                'actionLabel' => 'Open lead',
                'note' => null,
                'companyName' => $companyName,
                'companyLogo' => $companyLogo,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'message' => $this->message(),
            'action_url' => $this->actionUrl(),
            'category' => NotificationPreferenceService::CATEGORY_CRM,
            'lead_id' => $this->lead->id,
            'type' => $this->type,
            'hours' => $this->hours,
        ];
    }

    private function title(): string
    {
        if ($this->type === 'unassigned') {
            return 'Lead unassigned';
        }

        return 'Lead follow-up overdue';
    }

    private function message(): string
    {
        $label = $this->leadLabel();

        if ($this->type === 'unassigned') {
            return "This lead is still unassigned after {$this->hours}h: {$label}.";
        }

        return "Follow-up is overdue by {$this->hours}h for lead {$label}.";
    }

    private function leadLabel(): string
    {
        return $this->lead->title
            ?: ($this->lead->service_type ?: "Request #{$this->lead->id}");
    }

    private function actionUrl(): string
    {
        return route('pipeline.timeline', [
            'entityType' => 'request',
            'entityId' => $this->lead->id,
        ]);
    }
}
