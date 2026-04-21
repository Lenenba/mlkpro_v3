<?php

namespace App\Support\CRM;

use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Work;

final class CrmOpportunityLinking
{
    /**
     * @return array<string, mixed>
     */
    public static function present(
        ?LeadRequest $request,
        ?Quote $quote,
        ?Work $job = null,
        ?Invoice $invoice = null
    ): array {
        $customer = self::buildReference(
            'customer',
            $quote?->customer_id
                ?? $request?->customer_id
                ?? $job?->customer_id
                ?? $invoice?->customer_id
        );
        $requestReference = self::buildReference('request', $request?->id);
        $quoteReference = self::buildReference('quote', $quote?->id);
        $jobReference = self::buildReference('job', $job?->id);
        $invoiceReference = self::buildReference('invoice', $invoice?->id);

        $subject = self::subjectReference($quoteReference ?? $requestReference ?? $jobReference ?? $invoiceReference ?? $customer);
        $primary = self::primaryReference($requestReference ?? $quoteReference ?? $customer ?? $jobReference ?? $invoiceReference, $subject);

        return [
            'subject' => $subject,
            'primary' => $primary,
            'customer' => self::mergeReference($customer, $subject),
            'request' => self::mergeReference($requestReference, $subject),
            'quote' => self::mergeReference($quoteReference, $subject),
            'job' => self::mergeReference($jobReference, $subject),
            'invoice' => self::mergeReference($invoiceReference, $subject),
            'anchors' => self::anchors($subject, $primary, $requestReference, $quoteReference, $customer, $jobReference, $invoiceReference),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buildReference(string $type, mixed $id): ?array
    {
        $normalizedId = self::resolveNullableInt($id);

        if ($normalizedId === null) {
            return null;
        }

        return [
            'type' => $type,
            'id' => $normalizedId,
            'role' => 'related',
            'origin' => 'projection',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $reference
     * @return array<string, mixed>|null
     */
    private static function subjectReference(?array $reference): ?array
    {
        if ($reference === null) {
            return null;
        }

        return [
            'type' => $reference['type'],
            'id' => $reference['id'],
            'role' => 'subject',
            'origin' => 'subject',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $reference
     * @param  array<string, mixed>|null  $subject
     * @return array<string, mixed>|null
     */
    private static function primaryReference(?array $reference, ?array $subject): ?array
    {
        return self::mergeReference($reference, $subject);
    }

    /**
     * @param  array<string, mixed>|null  $reference
     * @param  array<string, mixed>|null  $subject
     * @return array<string, mixed>|null
     */
    private static function mergeReference(?array $reference, ?array $subject): ?array
    {
        if ($reference === null) {
            return null;
        }

        if ($subject === null) {
            return $reference;
        }

        if (($reference['type'] ?? null) !== ($subject['type'] ?? null) || ($reference['id'] ?? null) !== ($subject['id'] ?? null)) {
            return $reference;
        }

        return [
            'type' => $subject['type'],
            'id' => $subject['id'],
            'role' => 'subject',
            'origin' => 'subject_and_projection',
        ];
    }

    /**
     * @param  array<string, mixed>|null  ...$references
     * @return array<int, array<string, mixed>>
     */
    private static function anchors(?array ...$references): array
    {
        $anchors = [];

        foreach ($references as $reference) {
            if ($reference === null) {
                continue;
            }

            $key = sprintf('%s:%s', $reference['type'] ?? 'unknown', $reference['id'] ?? '0');

            if (array_key_exists($key, $anchors)) {
                continue;
            }

            $anchors[$key] = $reference;
        }

        return array_values($anchors);
    }

    private static function resolveNullableInt(mixed $value): ?int
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
