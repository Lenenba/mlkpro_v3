<?php

namespace App\Http\Controllers;

use App\Enums\CurrencyCode;
use App\Services\MegaMenus\MegaMenuRenderer;
use App\Services\PublicFooterSectionResolver;
use App\Services\PublicPricingCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class LegalController extends Controller
{
    private const PUBLIC_PRICING_CURRENCY_SESSION_KEY = 'public_pricing_currency';

    public function terms(): Response
    {
        return Inertia::render('Terms', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('onboarding.index'),
            ...$this->publicChrome('terms'),
        ]);
    }

    public function privacy(): Response
    {
        return Inertia::render('Privacy', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('onboarding.index'),
            ...$this->publicChrome('privacy'),
        ]);
    }

    public function refund(): Response
    {
        return Inertia::render('Refund', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('onboarding.index'),
            ...$this->publicChrome('refund'),
        ]);
    }

    public function pricing(Request $request): Response
    {
        $currency = $this->resolvePublicPricingCurrency($request);
        $pricingPayload = app(PublicPricingCatalogService::class)->webPayload(
            $currency,
            (string) $request->input('audience', '')
        );

        return Inertia::render('Pricing', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('onboarding.index'),
            ...$pricingPayload,
            'supportedCurrencies' => CurrencyCode::values(),
            'selectedCurrencyCode' => $currency->value,
            'megaMenu' => app(MegaMenuRenderer::class)->resolveForLocation('header', 'pricing'),
            'footerMenu' => app(MegaMenuRenderer::class)->resolveForLocation('footer', 'pricing'),
            'footerSection' => app(PublicFooterSectionResolver::class)->resolve(app()->getLocale()),
        ]);
    }

    private function publicChrome(string $zone): array
    {
        return [
            'megaMenu' => app(MegaMenuRenderer::class)->resolveForLocation('header', $zone),
            'footerMenu' => app(MegaMenuRenderer::class)->resolveForLocation('footer', $zone),
            'footerSection' => app(PublicFooterSectionResolver::class)->resolve(app()->getLocale()),
        ];
    }

    private function resolvePublicPricingCurrency(Request $request): CurrencyCode
    {
        $requestedCurrency = CurrencyCode::tryFromMixed($request->query('currency'));

        if ($requestedCurrency instanceof CurrencyCode) {
            if ($request->hasSession()) {
                $request->session()->put(self::PUBLIC_PRICING_CURRENCY_SESSION_KEY, $requestedCurrency->value);
            }

            return $requestedCurrency;
        }

        $sessionCurrency = $request->hasSession()
            ? CurrencyCode::tryFromMixed($request->session()->get(self::PUBLIC_PRICING_CURRENCY_SESSION_KEY))
            : null;

        return $sessionCurrency ?? CurrencyCode::default();
    }
}
