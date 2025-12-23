<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    /**
     * @var array<int, string>
     */
    private array $supportedLocales = ['fr', 'en'];

    public function handle(Request $request, Closure $next)
    {
        $locale = null;

        $userLocale = $request->user()?->locale;
        if ($userLocale) {
            $locale = $userLocale;
        }

        if (!$locale) {
            $locale = $request->session()->get('locale');
        }

        if (!$locale) {
            $locale = $request->getPreferredLanguage($this->supportedLocales);
        }

        if (!$locale || !in_array($locale, $this->supportedLocales, true)) {
            $locale = config('app.locale', 'fr');
        }

        app()->setLocale($locale);
        $request->session()->put('locale', $locale);

        return $next($request);
    }
}
