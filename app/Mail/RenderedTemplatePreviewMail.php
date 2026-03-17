<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RenderedTemplatePreviewMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $htmlBody,
    ) {}

    public function build(): self
    {
        return $this->subject($this->subjectLine)
            ->html($this->htmlBody);
    }
}
