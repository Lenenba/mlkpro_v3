<?php

namespace App\Notifications;

use App\Models\Request as LeadRequest;
use App\Services\NotificationPreferenceService;
use App\Support\LocalePreference;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ProspectStaleReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public LeadRequest $lead,
        public int $daysWithoutActivity
    ) {
        $this->onQueue(QueueWorkload::queue('notifications'));
    }

    public function backoff(): array
    {
        return QueueWorkload::backoff('notifications', [60, 300, 900]);
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $owner = $this->lead->user;
        $locale = LocalePreference::forNotifiable($notifiable, $owner);
        $isFr = str_starts_with($locale, 'fr');

        return [
            'title' => $isFr ? 'Prospect sans activite' : 'Prospect without activity',
            'message' => $this->message($isFr),
            'action_url' => route('prospects.show', ['lead' => $this->lead->id]),
            'category' => NotificationPreferenceService::CATEGORY_CRM,
            'type' => 'stale',
            'lead_id' => $this->lead->id,
            'days_without_activity' => $this->daysWithoutActivity,
        ];
    }

    private function message(bool $isFr = false): string
    {
        $label = $this->leadLabel();

        return $isFr
            ? "Le prospect {$label} n a eu aucune activite depuis {$this->daysWithoutActivity} jours."
            : "Prospect {$label} has had no activity for {$this->daysWithoutActivity} days.";
    }

    private function leadLabel(): string
    {
        $label = trim((string) ($this->lead->title ?: $this->lead->contact_name ?: $this->lead->service_type ?: 'Prospect #'.$this->lead->id));

        return $label !== '' ? $label : 'Prospect #'.$this->lead->id;
    }
}
