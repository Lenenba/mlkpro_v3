<?php

namespace App\Services\CRM;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\Work;
use Illuminate\Database\Eloquent\Model;

class CrmActivityContextResolver
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, int|null>
     */
    public function resolve(Model $subject, array $context = []): array
    {
        $customerId = $this->resolveNullableInt($context['customer_id'] ?? null);
        $requestId = $this->resolveNullableInt($context['request_id'] ?? null);
        $quoteId = $this->resolveNullableInt($context['quote_id'] ?? null);

        if ($subject instanceof Customer) {
            $customerId ??= (int) $subject->id;
        }

        if ($subject instanceof LeadRequest) {
            $requestId ??= (int) $subject->id;
            $customerId ??= $this->resolveNullableInt($subject->customer_id);
        }

        if ($subject instanceof Quote) {
            $quoteId ??= (int) $subject->id;
            $requestId ??= $this->resolveNullableInt($subject->request_id);
            $customerId ??= $this->resolveNullableInt($subject->customer_id);
        }

        if ($subject instanceof Work) {
            $customerId ??= $this->resolveNullableInt($subject->customer_id);
            $quoteId ??= $this->resolveNullableInt($subject->quote_id);

            if ($requestId === null && $quoteId !== null) {
                $subject->loadMissing('quote:id,request_id');
                $requestId = $this->resolveNullableInt($subject->quote?->request_id);
            }
        }

        if ($subject instanceof Task) {
            $customerId ??= $this->resolveNullableInt($subject->customer_id);
            $requestId ??= $this->resolveNullableInt($subject->request_id);

            if ($quoteId === null && $subject->work_id) {
                $subject->loadMissing('work:id,quote_id');
                $quoteId = $this->resolveNullableInt($subject->work?->quote_id);
            }
        }

        if ($subject instanceof Invoice) {
            $customerId ??= $this->resolveNullableInt($subject->customer_id);
            $subject->loadMissing('work.quote:id,request_id');
            $quoteId ??= $this->resolveNullableInt($subject->work?->quote_id);
            $requestId ??= $this->resolveNullableInt($subject->work?->quote?->request_id);
        }

        return [
            'customer_id' => $customerId,
            'request_id' => $requestId,
            'quote_id' => $quoteId,
        ];
    }

    private function resolveNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
