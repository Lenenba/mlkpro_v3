<?php

namespace App\Mail;

use App\Support\LocalePreference;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DemoWorkspaceAccessMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, string>  $moduleLabels
     * @param  array<int, string>  $scenarioLabels
     * @param  array<int, array<string, mixed>>  $extraCredentials
     */
    public function __construct(
        public readonly string $companyName,
        public readonly ?string $companyLogo,
        public readonly string $recipientName,
        public readonly string $prospectCompany,
        public readonly string $workspaceName,
        public readonly string $tagline,
        public readonly string $loginUrl,
        public readonly string $accessEmail,
        public readonly string $accessPassword,
        public readonly ?string $expiresAt,
        public readonly string $templateName,
        public readonly array $moduleLabels,
        public readonly array $scenarioLabels,
        public readonly array $extraCredentials,
        public readonly string $suggestedFlow,
        public readonly ?string $replyToAddress = null,
        public readonly ?string $locale = null,
    ) {
        if ($this->locale) {
            $this->locale(LocalePreference::normalize($this->locale));
        }
    }

    public function build(): self
    {
        $mail = $this->subject(__('mail.demo_access.subject', ['company' => $this->companyName]))
            ->view('emails.demo_workspaces.access', [
                'companyName' => $this->companyName,
                'companyLogo' => $this->companyLogo,
                'recipientName' => $this->recipientName,
                'prospectCompany' => $this->prospectCompany,
                'workspaceName' => $this->workspaceName,
                'tagline' => $this->tagline,
                'loginUrl' => $this->loginUrl,
                'accessEmail' => $this->accessEmail,
                'accessPassword' => $this->accessPassword,
                'expiresAt' => $this->expiresAt,
                'templateName' => $this->templateName,
                'moduleLabels' => $this->moduleLabels,
                'scenarioLabels' => $this->scenarioLabels,
                'extraCredentials' => $this->extraCredentials,
                'suggestedFlow' => $this->suggestedFlow,
            ]);

        if ($this->replyToAddress) {
            $mail->replyTo($this->replyToAddress);
        }

        return $mail;
    }
}
