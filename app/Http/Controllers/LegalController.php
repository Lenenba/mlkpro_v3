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
        $pricingPayload = app(PublicPricingCatalogService::class)->webPayload(
            CurrencyCode::default(),
            (string) $request->input('audience', '')
        );

        return Inertia::render('Pricing', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('onboarding.index'),
            ...$pricingPayload,
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
}
