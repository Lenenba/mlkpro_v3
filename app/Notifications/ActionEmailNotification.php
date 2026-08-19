<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Models\User;
use App\Services\TenantBrandingResolver;
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

    private ?int $accountOwnerId = null;

    private bool $platformBranding = false;

    public function __construct(
        string $title,
        ?string $intro = null,
        array $details = [],
        ?string $actionUrl = null,
        ?string $actionLabel = null,
        ?string $subject = null,
        ?string $note = null,
        ?int $accountOwnerId = null,
        bool $platformBranding = false,
    ) {
        $this->title = $title;
        $this->intro = $intro;
        $this->details = $details;
        $this->actionUrl = $actionUrl;
        $this->actionLabel = $actionLabel;
        $this->subject = $subject;
        $this->note = $note;
        $this->accountOwnerId = $accountOwnerId;
        $this->platformBranding = $platformBranding;
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
        $companyUser = ! $this->platformBranding && $this->accountOwnerId
            ? User::query()->find($this->accountOwnerId)
            : null;

        if (! $this->platformBranding && ! $companyUser && $notifiable instanceof Customer) {
            $companyUser = $notifiable->user;
        } elseif (! $this->platformBranding && ! $companyUser && $notifiable instanceof User) {
            $companyUser = ! $notifiable->isSuperadmin() && ! $notifiable->isPlatformAdmin()
                ? app(TenantBrandingResolver::class)->resolveAccountOwner($notifiable)
                : null;
        }

        $branding = app(TenantBrandingResolver::class)->forAccountOwner($companyUser);

        return (new MailMessage)
            ->subject($this->subject ?? $this->title)
            ->view('emails.notifications.action', [
                'title' => $this->title,
                'intro' => $this->intro,
                'details' => $this->details,
                'actionUrl' => $this->actionUrl,
                'actionLabel' => $this->actionLabel,
                'note' => $this->note,
                'companyName' => $branding['name'],
                'companyLogo' => $branding['custom_logo_url'],
                'showPoweredBy' => $companyUser !== null,
            ]);
    }
}
