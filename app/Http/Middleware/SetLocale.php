<?php

namespace App\Http\Middleware;

use App\Support\LocalePreference;
use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $supportedLocales = LocalePreference::supported();
        $locale = null;

        $userLocale = $request->user()?->locale;
        if ($userLocale) {
            $locale = $userLocale;
        }

        if (!$locale) {
            $locale = $request->session()->get('locale');
        }

        if (!$locale) {
            $locale = $request->getPreferredLanguage($supportedLocales);
        }

        if (!$locale || !in_array($locale, $supportedLocales, true)) {
            $locale = LocalePreference::default();
        }

        app()->setLocale($locale);
        $request->session()->put('locale', $locale);

        return $next($request);
    }
}
