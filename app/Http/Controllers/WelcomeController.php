<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\TrackingService;
use App\Services\WelcomeContentService;
use App\Services\CompanyFeatureService;
use App\Models\User;

class WelcomeController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->user()) {
            return Inertia::location(route('dashboard'));
        }

        app(TrackingService::class)->record('site_visit');

        return Inertia::render('Welcome', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('onboarding.index'),
            'welcomeContent' => app(WelcomeContentService::class)->resolveForLocale(app()->getLocale()),
            'leadFormUrl' => $this->resolveLeadFormUrl(),
        ]);
    }

    private function resolveLeadFormUrl(): ?string
    {
        $userId = (int) config('app.lead_intake_user_id');
        if ($userId <= 0) {
            return null;
        }

        $user = User::query()->find($userId);
        if (!$user || $user->isSuspended()) {
            return null;
        }

        if (!app(CompanyFeatureService::class)->hasFeature($user, 'requests')) {
            return null;
        }

        return URL::signedRoute('public.requests.form', ['user' => $user->id]);
    }
}
