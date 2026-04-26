<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\LocalePreference;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class SendQuoteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $quote;

    /**
     * Create a new notification instance.
     *
     * @param  mixed  $quote
     * @return void
     */
    public function __construct($quote)
    {
        $this->quote = $quote;
        $this->onQueue(QueueWorkload::queue('notifications'));
    }

    public function backoff(): array
    {
        return QueueWorkload::backoff('notifications', [60, 300, 900]);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $companyUser = $this->quote?->customer?->user
            ?: $this->quote?->prospect?->user
            ?: ($this->quote?->user_id ? User::query()->find($this->quote->user_id) : null);
        $locale = LocalePreference::forNotifiable($notifiable, $companyUser);
        $companyName = $companyUser?->company_name ?: config('app.name');
        $companyLogo = $companyUser?->company_logo_url;
        $customer = $this->quote?->customer;
        $usePublicLink = ! (bool) ($customer?->portal_access ?? true) || ! $customer?->portal_user_id;
        $actionUrl = route('dashboard');
        $actionLabel = LocalePreference::trans('mail.quote.action_open_dashboard', locale: $locale);
        $actionMessage = LocalePreference::trans('mail.quote.action_message_dashboard', locale: $locale);
        if ($usePublicLink) {
            $expiresAt = now()->addDays(7);
            $actionUrl = URL::temporarySignedRoute(
                'public.quotes.show',
                $expiresAt,
                ['quote' => $this->quote->id]
            );
            $actionLabel = LocalePreference::trans('mail.quote.action_review_quote', locale: $locale);
            $actionMessage = LocalePreference::trans('mail.quote.action_message_public', locale: $locale);
        }

        return (new MailMessage)
            ->subject(LocalePreference::trans('mail.quote.subject', ['company' => $companyName], $locale))
            ->view('emails.quotes.send', [
                'quote' => $this->quote,
                'companyName' => $companyName,
                'companyLogo' => $companyLogo,
                'actionUrl' => $actionUrl,
                'actionLabel' => $actionLabel,
                'actionMessage' => $actionMessage,
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
