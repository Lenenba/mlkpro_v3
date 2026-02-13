<?php

namespace App\Services;

use App\Support\TenantPaymentMethodsResolver;

class TenantPaymentMethodGuardService
{
    public const ERROR_CODE = 'payment_method_not_allowed';
    public const ERROR_MESSAGE = 'Cette entreprise n accepte pas ce mode de paiement.';

    public function evaluate(int $accountId, mixed $requestedMethod = null, ?string $context = null): array
    {
        $settings = TenantPaymentMethodsResolver::forAccountId($accountId);
        $allowedInternal = $settings['enabled_methods_internal'] ?? [];

        $requestedRaw = is_string($requestedMethod)
            ? strtolower(trim($requestedMethod))
            : null;
        $requestedProvided = $requestedRaw !== null && $requestedRaw !== '';

        $recognized = true;
        $canonicalMethod = null;

        if ($requestedProvided) {
            $canonicalMethod = TenantPaymentMethodsResolver::normalizeInternalMethod($requestedRaw);

            if (!$canonicalMethod) {
                $businessCandidates = TenantPaymentMethodsResolver::internalMethodsForBusiness($requestedRaw);
                if (!empty($businessCandidates)) {
                    $canonicalMethod = $this->resolveBestCandidate($businessCandidates, $allowedInternal);
                } else {
                    $recognized = false;
                }
            }
        } else {
            $canonicalMethod = $settings['default_method_internal'] ?? null;
        }

        $allowed = $recognized
            && is_string($canonicalMethod)
            && in_array($canonicalMethod, $allowedInternal, true);

        return [
            'allowed' => (bool) $allowed,
            'context' => $context,
            'account_id' => $accountId,
            'requested_method' => $requestedRaw,
            'requested_method_provided' => $requestedProvided,
            'canonical_method' => $canonicalMethod,
            'normalized_business_method' => TenantPaymentMethodsResolver::businessMethodForInternal($canonicalMethod),
            'allowed_methods' => $settings['enabled_methods'] ?? [],
            'allowed_methods_internal' => $allowedInternal,
            'default_method' => $settings['default_method'] ?? null,
            'default_method_internal' => $settings['default_method_internal'] ?? null,
            'cash_allowed_contexts' => $settings['cash_allowed_contexts'] ?? [],
            'error_code' => $allowed ? null : self::ERROR_CODE,
            'error_message' => $allowed ? null : self::ERROR_MESSAGE,
        ];
    }

    private function resolveBestCandidate(array $candidates, array $allowedInternal): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $allowedInternal, true)) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }
}

