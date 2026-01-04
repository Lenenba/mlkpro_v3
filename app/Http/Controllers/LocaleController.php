<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function update(Request $request)
    {
        $validated = $request->validate([
            'locale' => ['required', Rule::in(['fr', 'en'])],
        ]);

        $locale = $validated['locale'];

        if ($request->user()) {
            $request->user()->update(['locale' => $locale]);
        }

        app()->setLocale($locale);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'locale' => $locale,
            ]);
        }

        $request->session()->put('locale', $locale);

        return redirect()->back();
    }
}
