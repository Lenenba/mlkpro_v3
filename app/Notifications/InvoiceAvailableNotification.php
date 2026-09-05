<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceDocumentService;
use App\Services\TenantBrandingResolver;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class InvoiceAvailableNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public string $title;

    public ?string $intro;

    public array $details;

    public ?string $actionUrl;

    public ?string $actionLabel;

    public ?string $subject;

    public ?string $note;

    public function __construct(
        public Invoice $invoice,
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
        $invoiceDocumentService = app(InvoiceDocumentService::class);
        $invoice = $invoiceDocumentService->prepareInvoice($this->invoice);
        $companyUser = $this->resolveCompanyUser($notifiable, $invoice);
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
            ])
            ->attachData(
                $invoiceDocumentService->renderPdfContent($invoice, $companyUser),
                $invoiceDocumentService->filename($invoice),
                ['mime' => 'application/pdf']
            );
    }

    private function resolveCompanyUser(object $notifiable, Invoice $invoice): ?User
    {
        $invoiceOwner = $invoice->relationLoaded('user')
            ? $invoice->user
            : User::query()->find($invoice->user_id);

        if ($invoiceOwner) {
            return $invoiceOwner;
        }

        if ($notifiable instanceof Customer && $notifiable->user) {
            return $notifiable->user;
        }

        if ($notifiable instanceof User
            && ! $notifiable->isSuperadmin()
            && ! $notifiable->isPlatformAdmin()) {
            return app(TenantBrandingResolver::class)->resolveAccountOwner($notifiable);
        }

        return null;
    }
}
