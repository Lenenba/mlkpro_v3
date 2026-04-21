<?php

namespace App\Support\CRM;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\Work;

final class CrmActivityLinking
{
    private const CORE_REFERENCE_PRIORITY = [
        'request',
        'quote',
        'customer',
    ];

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function present(?string $subjectType, mixed $subjectId, array $properties = []): array
    {
        $subject = self::buildSubjectReference($subjectType, $subjectId);
        $customer = self::buildCoreReference('customer', $properties['customer_id'] ?? null);
        $request = self::buildCoreReference('request', $properties['request_id'] ?? null);
        $quote = self::buildCoreReference('quote', $properties['quote_id'] ?? null);

        if ($subject !== null) {
            match ($subject['type']) {
                'customer' => $customer = self::mergeReference($customer, $subject),
                'request' => $request = self::mergeReference($request, $subject),
                'quote' => $quote = self::mergeReference($quote, $subject),
                default => null,
            };
        }

        return [
            'subject' => $subject,
            'primary' => self::resolvePrimary($subject, $request, $quote, $customer),
            'customer' => $customer,
            'request' => $request,
            'quote' => $quote,
            'anchors' => self::anchors($subject, $request, $quote, $customer),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buildSubjectReference(?string $subjectType, mixed $subjectId): ?array
    {
        $type = self::subjectAlias($subjectType);
        $id = self::resolveNullableInt($subjectId);

        if ($type === null || $id === null) {
            return null;
        }

        return [
            'type' => $type,
            'id' => $id,
            'role' => 'subject',
            'origin' => 'subject',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buildCoreReference(string $type, mixed $id): ?array
    {
        $normalizedId = self::resolveNullableInt($id);

        if ($normalizedId === null) {
            return null;
        }

        return [
            'type' => $type,
            'id' => $normalizedId,
            'role' => 'related',
            'origin' => 'property',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>|null  $incoming
     * @return array<string, mixed>|null
     */
    private static function mergeReference(?array $existing, ?array $incoming): ?array
    {
        if ($incoming === null) {
            return $existing;
        }

        if ($existing === null) {
            return $incoming;
        }

        if (($existing['type'] ?? null) !== ($incoming['type'] ?? null) || ($existing['id'] ?? null) !== ($incoming['id'] ?? null)) {
            return $existing['role'] === 'subject' ? $existing : $incoming;
        }

        $existingOrigin = (string) ($existing['origin'] ?? 'property');
        $incomingOrigin = (string) ($incoming['origin'] ?? 'property');

        return [
            'type' => $existing['type'],
            'id' => $existing['id'],
            'role' => ($existing['role'] ?? null) === 'subject' || ($incoming['role'] ?? null) === 'subject'
                ? 'subject'
                : 'related',
            'origin' => $existingOrigin === $incomingOrigin
                ? $existingOrigin
                : 'subject_and_property',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $subject
     * @param  array<string, mixed>|null  $request
     * @param  array<string, mixed>|null  $quote
     * @param  array<string, mixed>|null  $customer
     * @return array<string, mixed>|null
     */
    private static function resolvePrimary(?array $subject, ?array $request, ?array $quote, ?array $customer): ?array
    {
        if ($subject !== null && in_array($subject['type'], self::CORE_REFERENCE_PRIORITY, true)) {
            return match ($subject['type']) {
                'request' => $request,
                'quote' => $quote,
                'customer' => $customer,
                default => $subject,
            };
        }

        $coreLinks = [
            'request' => $request,
            'quote' => $quote,
            'customer' => $customer,
        ];

        foreach (self::CORE_REFERENCE_PRIORITY as $type) {
            if ($coreLinks[$type] !== null) {
                return $coreLinks[$type];
            }
        }

        return $subject;
    }

    /**
     * @param  array<string, mixed>|null  $subject
     * @param  array<string, mixed>|null  $request
     * @param  array<string, mixed>|null  $quote
     * @param  array<string, mixed>|null  $customer
     * @return array<int, array<string, mixed>>
     */
    private static function anchors(?array $subject, ?array $request, ?array $quote, ?array $customer): array
    {
        $anchors = [];

        if ($subject !== null) {
            $anchors[] = $subject;
        }

        foreach ([$request, $quote, $customer] as $reference) {
            if ($reference === null) {
                continue;
            }

            if ($subject !== null
                && ($subject['type'] ?? null) === ($reference['type'] ?? null)
                && ($subject['id'] ?? null) === ($reference['id'] ?? null)) {
                continue;
            }

            $anchors[] = $reference;
        }

        return $anchors;
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

    private static function subjectAlias(?string $subjectType): ?string
    {
        return match ($subjectType) {
            Customer::class => 'customer',
            LeadRequest::class => 'request',
            Quote::class => 'quote',
            Task::class => 'task',
            Work::class => 'job',
            Invoice::class => 'invoice',
            Payment::class => 'payment',
            default => null,
        };
    }
}
