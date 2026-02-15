<?php

namespace App\Notifications;

use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Services\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeadFormOwnerNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $event,
        public LeadRequest $lead,
        public ?Quote $quote = null,
        public bool $sendMail = true
    ) {
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($this->sendMail && !empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->title();
        $owner = $this->lead->user;
        $companyName = $owner?->company_name ?: config('app.name');
        $companyLogo = $owner?->company_logo_url;

        return (new MailMessage)
            ->subject($title)
            ->view('emails.notifications.action', [
                'title' => $title,
                'intro' => $this->message(),
                'details' => $this->details(),
                'actionUrl' => $this->actionUrl(),
                'actionLabel' => 'Open lead',
                'note' => $this->note(),
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
            'event' => $this->event,
            'lead_id' => $this->lead->id,
            'quote_id' => $this->quote?->id,
        ];
    }

    private function title(): string
    {
        if ($this->event === 'lead_call_requested') {
            return 'New call request from lead form';
        }

        if ($this->event === 'lead_email_failed') {
            return 'Quote email failed';
        }

        return 'New quote created from lead form';
    }

    private function message(): string
    {
        $leadLabel = $this->leadLabel();
        if ($this->event === 'lead_call_requested') {
            return "Lead {$leadLabel} requested a call and needs qualification.";
        }

        if ($this->event === 'lead_email_failed') {
            $quoteLabel = $this->quoteLabel();
            return "Quote {$quoteLabel} was created for {$leadLabel}, but customer email delivery failed.";
        }

        $quoteLabel = $this->quoteLabel();
        return "Lead {$leadLabel} generated quote {$quoteLabel}.";
    }

    private function details(): array
    {
        $details = [
            ['label' => 'Lead', 'value' => $this->leadLabel()],
            ['label' => 'Contact', 'value' => $this->lead->contact_name ?: '-'],
            ['label' => 'Email', 'value' => $this->lead->contact_email ?: '-'],
            ['label' => 'Phone', 'value' => $this->lead->contact_phone ?: '-'],
            ['label' => 'Status', 'value' => $this->lead->status ?: '-'],
        ];

        if ($this->quote) {
            $details[] = ['label' => 'Quote', 'value' => $this->quoteLabel()];
            $details[] = ['label' => 'Total', 'value' => '$' . number_format((float) ($this->quote->total ?? 0), 2)];
        }

        return $details;
    }

    private function note(): ?string
    {
        if ($this->event === 'lead_email_failed') {
            return 'The quote remains created. Retry email delivery from the quote screen.';
        }

        if ($this->event === 'lead_call_requested') {
            return 'A follow-up task has been created to qualify the lead and schedule a call.';
        }

        return null;
    }

    private function actionUrl(): string
    {
        return route('request.show', ['lead' => $this->lead->id]);
    }

    private function leadLabel(): string
    {
        $label = trim((string) ($this->lead->title ?: $this->lead->service_type ?: 'Lead #' . $this->lead->id));

        return $label !== '' ? $label : 'Lead #' . $this->lead->id;
    }

    private function quoteLabel(): string
    {
        if (!$this->quote) {
            return 'N/A';
        }

        if (!empty($this->quote->number)) {
            return (string) $this->quote->number;
        }

        return '#' . $this->quote->id;
    }
}
