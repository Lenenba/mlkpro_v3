<?php

namespace App\Notifications;

use App\Models\Request as LeadRequest;
use App\Services\NotificationPreferenceService;
use App\Services\TenantBrandingResolver;
use App\Support\LocalePreference;
use App\Support\QueueWorkload;
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
        $this->onQueue(QueueWorkload::queue('notifications'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return QueueWorkload::backoff('notifications', [60, 300, 900]);
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (! empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $owner = $this->lead->user;
        $brandingResolver = app(TenantBrandingResolver::class);
        $accountOwner = $owner
            ? ($brandingResolver->resolveAccountOwner($owner) ?: $owner)
            : null;
        $branding = $brandingResolver->forAccountOwner($accountOwner);
        $locale = LocalePreference::forNotifiable($notifiable, $accountOwner);
        $isFr = str_starts_with($locale, 'fr');
        $title = $this->title($isFr);
        $message = $this->message($isFr);

        return (new MailMessage)
            ->subject($title)
            ->view('emails.notifications.action', [
                'title' => $title,
                'intro' => $message,
                'details' => [
                    ['label' => $isFr ? 'Lead' : 'Lead', 'value' => $this->leadLabel()],
                    ['label' => $isFr ? 'Statut' : 'Status', 'value' => $this->lead->status ?? '-'],
                ],
                'actionUrl' => $this->actionUrl(),
                'actionLabel' => $isFr ? 'Ouvrir le lead' : 'Open lead',
                'note' => null,
                'companyName' => $branding['name'],
                'companyLogo' => $branding['custom_logo_url'],
            ]);
    }

    public function toArray(object $notifiable): array
    {
        $owner = $this->lead->user;
        $locale = LocalePreference::forNotifiable($notifiable, $owner);
        $isFr = str_starts_with($locale, 'fr');

        return [
            'title' => $this->title($isFr),
            'message' => $this->message($isFr),
            'action_url' => $this->actionUrl(),
            'category' => NotificationPreferenceService::CATEGORY_CRM,
            'lead_id' => $this->lead->id,
            'type' => $this->type,
            'hours' => $this->hours,
        ];
    }

    private function title(bool $isFr = false): string
    {
        if ($this->type === 'unassigned') {
            return $isFr ? 'Lead non assigne' : 'Lead unassigned';
        }

        return $isFr ? 'Suivi lead en retard' : 'Lead follow-up overdue';
    }

    private function message(bool $isFr = false): string
    {
        $label = $this->leadLabel();

        if ($this->type === 'unassigned') {
            return $isFr
                ? "Ce lead n est toujours pas assigne apres {$this->hours} h : {$label}."
                : "This lead is still unassigned after {$this->hours}h: {$label}.";
        }

        return $isFr
            ? "Le suivi est en retard de {$this->hours} h pour le lead {$label}."
            : "Follow-up is overdue by {$this->hours}h for lead {$label}.";
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
