<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SendQuoteNotification extends Notification
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

        return (new MailMessage)
        ->subject('Your Quote from ' . $companyName)
        ->view('emails.quotes.send', [
            'quote' => $this->quote,
            'companyName' => $companyName,
            'companyLogo' => $companyLogo,
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
