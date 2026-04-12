<?php

namespace App\Http\Middleware;

use App\Support\LocalePreference;
use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = LocalePreference::forRequest($request);

        app()->setLocale($locale);
        $request->session()->put('locale', $locale);

        return $next($request);
    }
}
