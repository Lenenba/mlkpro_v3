<?php

namespace App\Notifications;

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
        $companyUser = $this->quote?->customer?->user;
        $companyName = $companyUser?->company_name ?: config('app.name');
        $companyLogo = $companyUser?->company_logo_url;
        $customer = $this->quote?->customer;
        $usePublicLink = !(bool) ($customer?->portal_access ?? true) || !$customer?->portal_user_id;
        $actionUrl = route('dashboard');
        $actionLabel = 'Open dashboard';
        $actionMessage = 'Log in to your portal to review and validate the quote.';
        if ($usePublicLink) {
            $expiresAt = now()->addDays(7);
            $actionUrl = URL::temporarySignedRoute(
                'public.quotes.show',
                $expiresAt,
                ['quote' => $this->quote->id]
            );
            $actionLabel = 'Review quote';
            $actionMessage = 'Use the secure link below to review and validate the quote.';
        }

        return (new MailMessage)
        ->subject('Your Quote from ' . $companyName)
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
