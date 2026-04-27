<?php

namespace App\Notifications;

use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Services\NotificationPreferenceService;
use App\Support\LocalePreference;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProspectFollowUpReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE_DUE_TODAY = 'due_today';

    public const TYPE_OVERDUE = 'overdue';

    public function __construct(
        public Task $task,
        public string $type = self::TYPE_DUE_TODAY
    ) {
        $this->onQueue(QueueWorkload::queue('notifications'));
    }

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
        $lead = $this->lead();
        $owner = $lead?->user ?: $this->task->account;
        $locale = LocalePreference::forNotifiable($notifiable, $owner);
        $isFr = str_starts_with($locale, 'fr');
        $title = $this->title($isFr);
        $companyName = $owner?->company_name ?: config('app.name');
        $companyLogo = $owner?->company_logo_url;

        return (new MailMessage)
            ->subject($title)
            ->view('emails.notifications.action', [
                'title' => $title,
                'intro' => $this->message($isFr),
                'details' => $this->details($isFr),
                'actionUrl' => $this->actionUrl(),
                'actionLabel' => $isFr ? 'Ouvrir le prospect' : 'Open prospect',
                'note' => $this->note($isFr),
                'companyName' => $companyName,
                'companyLogo' => $companyLogo,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        $lead = $this->lead();
        $owner = $lead?->user ?: $this->task->account;
        $locale = LocalePreference::forNotifiable($notifiable, $owner);
        $isFr = str_starts_with($locale, 'fr');

        return [
            'title' => $this->title($isFr),
            'message' => $this->message($isFr),
            'action_url' => $this->actionUrl(),
            'category' => NotificationPreferenceService::CATEGORY_CRM,
            'type' => $this->type,
            'task_id' => $this->task->id,
            'lead_id' => $lead?->id,
            'due_date' => $this->task->due_date?->toDateString(),
        ];
    }

    private function title(bool $isFr = false): string
    {
        if ($this->type === self::TYPE_OVERDUE) {
            return $isFr ? 'Relance prospect en retard' : 'Prospect follow-up overdue';
        }

        return $isFr ? 'Relance prospect a faire aujourd hui' : 'Prospect follow-up due today';
    }

    private function message(bool $isFr = false): string
    {
        $taskLabel = $this->taskLabel();
        $leadLabel = $this->leadLabel();

        if ($this->type === self::TYPE_OVERDUE) {
            return $isFr
                ? "La relance \"{$taskLabel}\" est en retard pour le prospect {$leadLabel}."
                : "Follow-up task \"{$taskLabel}\" is overdue for prospect {$leadLabel}.";
        }

        return $isFr
            ? "La relance \"{$taskLabel}\" est prevue aujourd hui pour le prospect {$leadLabel}."
            : "Follow-up task \"{$taskLabel}\" is scheduled today for prospect {$leadLabel}.";
    }

    private function details(bool $isFr = false): array
    {
        $lead = $this->lead();
        $details = [
            ['label' => $isFr ? 'Prospect' : 'Prospect', 'value' => $this->leadLabel()],
            ['label' => $isFr ? 'Relance' : 'Follow-up task', 'value' => $this->taskLabel()],
            ['label' => $isFr ? 'Statut' : 'Status', 'value' => $this->task->status ?: '-'],
            ['label' => $isFr ? 'Priorite' : 'Priority', 'value' => $this->task->priority ?: '-'],
        ];

        if ($this->task->due_date) {
            $details[] = [
                'label' => $isFr ? 'Date prevue' : 'Planned date',
                'value' => $this->task->due_date->toDateString(),
            ];
        }

        if ($lead?->contact_name) {
            $details[] = [
                'label' => $isFr ? 'Contact' : 'Contact',
                'value' => $lead->contact_name,
            ];
        }

        return $details;
    }

    private function note(bool $isFr = false): string
    {
        if ($this->type === self::TYPE_OVERDUE) {
            return $isFr
                ? 'Ouvrez le prospect pour replanifier, assigner ou cloturer la relance.'
                : 'Open the prospect to reschedule, assign, or complete the follow-up.';
        }

        return $isFr
            ? 'Ouvrez le prospect pour preparer la prochaine action du jour.'
            : 'Open the prospect to prepare the next action for today.';
    }

    private function actionUrl(): string
    {
        $lead = $this->lead();

        return $lead
            ? route('prospects.show', ['lead' => $lead->id])
            : route('task.show', $this->task);
    }

    private function lead(): ?LeadRequest
    {
        return $this->task->relationLoaded('request')
            ? $this->task->request
            : $this->task->request()->first();
    }

    private function leadLabel(): string
    {
        $lead = $this->lead();
        if (! $lead) {
            return 'Prospect #'.$this->task->request_id;
        }

        $label = trim((string) ($lead->title ?: $lead->contact_name ?: $lead->service_type ?: 'Prospect #'.$lead->id));

        return $label !== '' ? $label : 'Prospect #'.$lead->id;
    }

    private function taskLabel(): string
    {
        $label = trim((string) ($this->task->title ?: 'Task #'.$this->task->id));

        return $label !== '' ? $label : 'Task #'.$this->task->id;
    }
}
