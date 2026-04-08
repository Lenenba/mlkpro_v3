<?php

namespace App\Http\Controllers\Api;

use App\Enums\CurrencyCode;
use App\Http\Controllers\Controller;
use App\Services\PublicPricingCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PublicPricingController extends Controller
{
    public function __construct(
        private readonly PublicPricingCatalogService $publicPricingCatalogService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $currency = $this->resolveCurrency($request);
        $audience = $this->resolveAudience($request, $currency);

        return response()->json(
            $this->publicPricingCatalogService->apiPayload(
                $currency,
                $audience,
                $this->shouldIncludeComparisonSections($request)
            )
        );
    }

    private function resolveCurrency(Request $request): CurrencyCode
    {
        $requestedCurrency = (string) $request->query('currency', '');

        if ($requestedCurrency === '') {
            return CurrencyCode::default();
        }

        $currency = CurrencyCode::tryFromMixed($requestedCurrency);
        if ($currency instanceof CurrencyCode) {
            return $currency;
        }

        throw ValidationException::withMessages([
            'currency' => ['The selected currency is invalid.'],
        ]);
    }

    private function resolveAudience(Request $request, CurrencyCode $currency): ?string
    {
        $audience = trim((string) $request->query('audience', ''));

        if ($audience === '') {
            return null;
        }

        if ($this->publicPricingCatalogService->audienceExists($currency, $audience)) {
            return $audience;
        }

        throw ValidationException::withMessages([
            'audience' => ['The selected audience is invalid.'],
        ]);
    }

    private function shouldIncludeComparisonSections(Request $request): bool
    {
        $include = trim((string) $request->query('include', ''));

        if ($include === '') {
            return false;
        }

        return collect(explode(',', $include))
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->contains('comparison_sections');
    }
}
