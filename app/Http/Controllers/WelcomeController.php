<?php

namespace App\Http\Controllers;

use App\Services\MegaMenus\MegaMenuRenderer;
use App\Services\PlatformPageContentService;
use App\Services\PlatformWelcomePageService;
use App\Services\PublicFooterSectionResolver;
use App\Services\PublicLeadFormUrlService;
use App\Services\TrackingService;
use App\Services\WelcomePageContentResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->user()) {
            return Inertia::location(route('dashboard'));
        }

        app(TrackingService::class)->record('site_visit');

        $welcomePage = app(PlatformWelcomePageService::class)->ensurePageExists();
        $locale = app()->getLocale();

        return Inertia::render('Welcome', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('onboarding.index'),
            'welcomeContent' => app(WelcomePageContentResolver::class)->resolve($welcomePage, $locale),
            'pageTheme' => app(PlatformPageContentService::class)->resolveTheme($welcomePage),
            'leadFormUrl' => app(PublicLeadFormUrlService::class)->resolve((int) config('app.lead_intake_user_id')),
            'megaMenu' => app(MegaMenuRenderer::class)->resolveForLocation('header', 'welcome'),
            'footerMenu' => app(MegaMenuRenderer::class)->resolveForLocation('footer', 'welcome'),
            'footerSection' => app(PublicFooterSectionResolver::class)->resolve($locale),
        ]);
    }
}
