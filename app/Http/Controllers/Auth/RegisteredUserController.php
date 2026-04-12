<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\LocalePreference;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $locale = LocalePreference::forRequest($request);

        $roleId = Role::where('name', 'owner')->value('id');
        if (!$roleId) {
            $roleId = Role::create([
                'name' => 'owner',
                'description' => 'Account owner role',
            ])->id;
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'locale' => $locale,
            'password' => Hash::make($request->password),
            'role_id' => $roleId,
        ]);

        event(new Registered($user));

        Auth::login($user);
        app()->setLocale($locale);
        $request->session()->put('locale', $locale);

        return redirect(route('onboarding.index', absolute: false));
    }
}
