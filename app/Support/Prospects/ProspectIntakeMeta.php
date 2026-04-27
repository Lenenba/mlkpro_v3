<?php

namespace App\Support\Prospects;

class ProspectIntakeMeta
{
    public static function merge(
        ?array $meta,
        ?string $source = null,
        ?string $requestType = null,
        ?bool $contactConsent = null,
        ?bool $marketingConsent = null
    ): ?array {
        $payload = array_filter(
            $meta ?? [],
            static fn ($value) => $value !== null && $value !== '' && $value !== []
        );

        if ($source !== null && $source !== '') {
            $payload['intake_source'] = $source;
        }

        if ($requestType !== null && $requestType !== '') {
            $payload['request_type'] = $requestType;
        }

        if ($contactConsent !== null) {
            $payload['contact_consent'] = $contactConsent;
        }

        if ($marketingConsent !== null) {
            $payload['marketing_consent'] = $marketingConsent;
        }

        return $payload !== [] ? $payload : null;
    }
}
