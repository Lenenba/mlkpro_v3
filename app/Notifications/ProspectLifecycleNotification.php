<?php

namespace App\Notifications;

use App\Models\Request as LeadRequest;
use App\Services\NotificationPreferenceService;
use App\Support\LocalePreference;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ProspectLifecycleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const EVENT_ASSIGNED = 'assigned';

    public const EVENT_CONVERTED = 'converted';

    public const EVENT_CREATED = 'created';

    public const EVENT_LOST = 'lost';

    public function __construct(
        public LeadRequest $lead,
        public string $event,
        public ?string $actorName = null,
        public ?string $customerName = null
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
            'title' => $this->title($isFr),
            'message' => $this->message($isFr),
            'action_url' => route('prospects.show', ['lead' => $this->lead->id]),
            'category' => NotificationPreferenceService::CATEGORY_CRM,
            'event' => $this->event,
            'lead_id' => $this->lead->id,
            'status' => $this->lead->status,
            'customer_name' => $this->customerName,
            'actor_name' => $this->actorName,
        ];
    }

    private function title(bool $isFr = false): string
    {
        return match ($this->event) {
            self::EVENT_ASSIGNED => $isFr ? 'Prospect assigne' : 'Prospect assigned',
            self::EVENT_CONVERTED => $isFr ? 'Prospect converti' : 'Prospect converted',
            self::EVENT_LOST => $isFr ? 'Prospect perdu' : 'Prospect lost',
            default => $isFr ? 'Nouveau prospect' : 'New prospect',
        };
    }

    private function message(bool $isFr = false): string
    {
        $label = $this->leadLabel();
        $actorSuffix = $this->actorName
            ? ($isFr ? ' par '.$this->actorName : ' by '.$this->actorName)
            : '';

        return match ($this->event) {
            self::EVENT_ASSIGNED => $isFr
                ? "Le prospect {$label} vous a ete assigne{$actorSuffix}."
                : "Prospect {$label} was assigned to you{$actorSuffix}.",
            self::EVENT_CONVERTED => $isFr
                ? "Le prospect {$label} a ete converti en client{$this->customerSuffix($isFr)}{$actorSuffix}."
                : "Prospect {$label} was converted to a customer{$this->customerSuffix($isFr)}{$actorSuffix}.",
            self::EVENT_LOST => $isFr
                ? "Le prospect {$label} a ete marque comme perdu{$actorSuffix}."
                : "Prospect {$label} was marked as lost{$actorSuffix}.",
            default => $isFr
                ? "Un nouveau prospect {$label} attend une qualification{$actorSuffix}."
                : "A new prospect {$label} is waiting for qualification{$actorSuffix}.",
        };
    }

    private function customerSuffix(bool $isFr = false): string
    {
        if (! $this->customerName) {
            return '';
        }

        return $isFr
            ? ' (client '.$this->customerName.')'
            : ' (customer '.$this->customerName.')';
    }

    private function leadLabel(): string
    {
        $label = trim((string) ($this->lead->title ?: $this->lead->contact_name ?: $this->lead->service_type ?: 'Prospect #'.$this->lead->id));

        return $label !== '' ? $label : 'Prospect #'.$this->lead->id;
    }
}
