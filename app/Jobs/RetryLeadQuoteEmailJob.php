<?php

namespace App\Jobs;

use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Notifications\LeadFormOwnerNotification;
use App\Notifications\SendQuoteNotification;
use App\Services\CRM\OutgoingEmailLogService;
use App\Support\NotificationDispatcher;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RetryLeadQuoteEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_ATTEMPTS = 3;

    public int $tries = 2;

    public function __construct(
        public int $quoteId,
        public int $leadId,
        public int $attempt = 1
    ) {
        $this->onQueue(QueueWorkload::queue('leads'));
    }

    public function backoff(): array
    {
        return QueueWorkload::backoff('leads', [300, 900, 1800]);
    }

    public function handle(): void
    {
        $quote = Quote::query()
            ->with(['customer.user', 'request'])
            ->find($this->quoteId);
        if (! $quote || ! $quote->customer || empty($quote->customer->email)) {
            return;
        }

        $lead = $quote->request ?: LeadRequest::query()->find($this->leadId);

        $emailQueued = NotificationDispatcher::send($quote->customer, new SendQuoteNotification($quote), [
            'quote_id' => $quote->id,
            'customer_id' => $quote->customer_id,
            'email' => $quote->customer->email,
            'source' => 'lead_form_retry',
            'retry_attempt' => $this->attempt,
        ]);

        $emailLogger = app(OutgoingEmailLogService::class);
        if ($emailQueued) {
            $emailLogger->logSent(null, $quote, [
                'email' => $quote->customer->email,
                'source' => 'lead_form_retry',
                'retry_attempt' => $this->attempt,
                'notification' => SendQuoteNotification::class,
            ], 'Quote email retry sent');

            return;
        }

        $emailLogger->logFailed(null, $quote, [
            'email' => $quote->customer->email,
            'source' => 'lead_form_retry',
            'retry_attempt' => $this->attempt,
            'notification' => SendQuoteNotification::class,
        ], 'Quote email retry failed');

        if ($lead) {
            $emailLogger->logFailed(null, $lead, [
                'quote_id' => $quote->id,
                'customer_id' => $quote->customer_id,
                'email' => $quote->customer->email,
                'source' => 'lead_form_retry',
                'retry_attempt' => $this->attempt,
                'notification' => SendQuoteNotification::class,
            ], 'Quote email retry failed');
        }

        if ($this->attempt < self::MAX_ATTEMPTS) {
            $nextAttempt = $this->attempt + 1;
            $delayMinutes = self::delayMinutesForAttempt($nextAttempt);

            self::dispatch($quote->id, $lead?->id ?? $this->leadId, $nextAttempt)
                ->delay(now()->addMinutes($delayMinutes));

            if ($lead) {
                $emailLogger->logRetryScheduled(null, $lead, [
                    'quote_id' => $quote->id,
                    'customer_id' => $quote->customer_id,
                    'attempt' => $nextAttempt,
                    'delay_minutes' => $delayMinutes,
                    'source' => 'lead_form_retry',
                ], 'Quote email retry scheduled');
            }

            return;
        }

        if ($lead && $lead->user) {
            NotificationDispatcher::send($lead->user, new LeadFormOwnerNotification(
                event: 'lead_email_failed',
                lead: $lead,
                quote: $quote,
                sendMail: false
            ), [
                'request_id' => $lead->id,
                'quote_id' => $quote->id,
                'source' => 'lead_form_retry',
                'retry_attempt' => $this->attempt,
            ]);
        }
    }

    public static function delayMinutesForAttempt(int $attempt): int
    {
        return match ($attempt) {
            1 => 5,
            2 => 15,
            default => 30,
        };
    }
}
