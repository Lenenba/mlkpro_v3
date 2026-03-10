<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Models\User;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActionEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public string $title;

    public ?string $intro;

    public array $details;

    public ?string $actionUrl;

    public ?string $actionLabel;

    public ?string $subject;

    public ?string $note;

    public function __construct(
        string $title,
        ?string $intro = null,
        array $details = [],
        ?string $actionUrl = null,
        ?string $actionLabel = null,
        ?string $subject = null,
        ?string $note = null
    ) {
        $this->title = $title;
        $this->intro = $intro;
        $this->details = $details;
        $this->actionUrl = $actionUrl;
        $this->actionLabel = $actionLabel;
        $this->subject = $subject;
        $this->note = $note;
        $this->onQueue(QueueWorkload::queue('notifications'));
    }

    public function backoff(): array
    {
        return QueueWorkload::backoff('notifications', [60, 300, 900]);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $companyUser = null;
        if ($notifiable instanceof Customer) {
            $companyUser = $notifiable->user;
        } elseif ($notifiable instanceof User) {
            $companyUser = User::find($notifiable->accountOwnerId());
        }

        $companyName = $companyUser?->company_name ?: config('app.name');
        $companyLogo = $companyUser?->company_logo_url;

        return (new MailMessage)
            ->subject($this->subject ?? $this->title)
            ->view('emails.notifications.action', [
                'title' => $this->title,
                'intro' => $this->intro,
                'details' => $this->details,
                'actionUrl' => $this->actionUrl,
                'actionLabel' => $this->actionLabel,
                'note' => $this->note,
                'companyName' => $companyName,
                'companyLogo' => $companyLogo,
            ]);
    }
}
