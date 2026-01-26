<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\TrackingService;

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
        ]);
    }
}
