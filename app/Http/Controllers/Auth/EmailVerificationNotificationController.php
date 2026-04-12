<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\LocalePreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        $resolvedLocale = LocalePreference::forRequest($request, $request->user());
        if (! LocalePreference::isSupported($request->user()->locale)) {
            $request->user()->forceFill([
                'locale' => $resolvedLocale,
            ])->save();
        }

        app()->setLocale($resolvedLocale);
        $request->session()->put('locale', $resolvedLocale);

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }
}
