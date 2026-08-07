<?php

namespace App\Jobs;

use App\Exceptions\ReceiptDeliveryInProgressException;
use App\Services\QueueInvoiceReceiptService;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DeliverQueueInvoiceReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public function __construct(
        public int $invoiceId,
        public ?int $actorUserId = null,
        public ?string $channel = null
    ) {
        $this->onQueue(QueueWorkload::queue('notifications'));
        $this->timeout = QueueWorkload::timeout('notifications');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return QueueWorkload::backoff('notifications', [60, 300, 900]);
    }

    public function handle(QueueInvoiceReceiptService $receiptService): void
    {
        try {
            $receiptService->deliverQueued($this->invoiceId, $this->actorUserId, $this->channel);
        } catch (Throwable $exception) {
            if ($exception instanceof ReceiptDeliveryInProgressException
                && config('queue.default', 'sync') === 'sync') {
                return;
            }

            if (config('queue.default', 'sync') === 'sync') {
                $receiptService->markDeliveryFailed(
                    $this->invoiceId,
                    $this->actorUserId,
                    $this->channel,
                    $exception
                );

                return;
            }

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        app(QueueInvoiceReceiptService::class)->markDeliveryFailed(
            $this->invoiceId,
            $this->actorUserId,
            $this->channel,
            $exception
        );
    }
}
