<?php

namespace App\Http\Controllers\Auth;

use App\Enums\BillingPeriod;
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
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Register', [
            'authContext' => $this->authContext($request),
        ]);
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
        if (! $roleId) {
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

        $context = $this->authContext($request);

        return redirect()->route('onboarding.index', array_filter([
            'plan' => $context['source'] === 'onboarding' ? $context['plan'] : null,
            'billing_period' => $context['source'] === 'onboarding' ? $context['billing_period'] : null,
        ], static fn (?string $value): bool => $value !== null && $value !== ''));
    }

    /**
     * @return array{source: string, plan: ?string, billing_period: ?string}
     */
    private function authContext(Request $request): array
    {
        $source = trim((string) ($request->input('source') ?? $request->query('source') ?? 'register'));
        if (! in_array($source, ['login', 'register', 'onboarding'], true)) {
            $source = 'register';
        }

        return [
            'source' => $source,
            'plan' => $source === 'onboarding'
                ? $this->normalizeOptionalString($request->input('plan') ?? $request->query('plan'))
                : null,
            'billing_period' => $source === 'onboarding'
                ? BillingPeriod::tryFromMixed($request->input('billing_period') ?? $request->query('billing_period'))?->value
                : null,
        ];
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
