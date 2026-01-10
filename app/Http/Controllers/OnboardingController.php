<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\ProductCategory;
use App\Notifications\WelcomeEmailNotification;
use App\Services\PlatformAdminNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OnboardingController extends Controller
{
    private const SECTOR_CATEGORIES = [
        'menuiserie' => ['Fabrication', 'Installation', 'Reparation', 'Finition', 'Sur mesure'],
        'plomberie' => ['Installation', 'Reparation', 'Debouchage', 'Entretien', 'Urgence'],
        'electricite' => ['Installation', 'Maintenance', 'Mise aux normes', 'Depannage', 'Domotique'],
        'peinture' => ['Interieur', 'Exterieur', 'Preparation', 'Finition', 'Retouches'],
        'toiture' => ['Inspection', 'Reparation', 'Entretien', 'Nettoyage', 'Isolation'],
        'renovation' => ['Demolition', 'Gros oeuvre', 'Finitions', 'Amenagement', 'Suivi chantier'],
        'paysagisme' => ['Entretien', 'Plantation', 'Tonte', 'Arrosage', 'Amenagement'],
        'climatisation' => ['Installation', 'Maintenance', 'Reparation', 'Nettoyage', 'Mise en service'],
        'nettoyage' => ['Residentiel', 'Commercial', 'Post-chantier', 'Desinfection', 'Vitres'],
        'autre' => ['Installation', 'Entretien', 'Reparation', 'Conseil', 'Autres'],
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->inertiaOrJson('Onboarding/Index', [
                'preset' => (object) [],
            ]);
        }

        if (!$user->isAccountOwner()) {
            return $this->inertiaOrJson('Onboarding/PendingOwner', []);
        }

        $companyLogo = null;
        if ($user->company_logo) {
            $path = $user->company_logo;
            $companyLogo = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
                ? $path
                : Storage::disk('public')->url($path);
        }

        return $this->inertiaOrJson('Onboarding/Index', [
            'preset' => [
                'company_name' => $user->company_name,
                'company_logo' => $companyLogo,
                'company_description' => $user->company_description,
                'company_country' => $user->company_country,
                'company_province' => $user->company_province,
                'company_city' => $user->company_city,
                'company_type' => $user->company_type,
                'company_sector' => $user->company_sector,
                'onboarding_completed_at' => $user->onboarding_completed_at,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $creator = $request->user();
        if (!$creator) {
            abort(401);
        }

        if (!$creator->isAccountOwner()) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Only the account owner can complete onboarding.',
                ], 403);
            }

            return redirect()->route('dashboard')->with('error', 'Only the account owner can complete onboarding.');
        }

        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'company_logo' => 'nullable|image|max:2048',
            'company_description' => 'nullable|string|max:2000',
            'company_country' => 'nullable|string|max:255',
            'company_province' => 'nullable|string|max:255',
            'company_city' => 'nullable|string|max:255',
            'company_type' => 'required|string|in:services,products',
            'company_sector' => 'required|string|max:255',

            'invites' => 'nullable|array|max:20',
            'invites.*.name' => 'required|string|max:255',
            'invites.*.email' => 'required|string|lowercase|email|max:255|distinct|unique:users,email',
            'invites.*.role' => 'required|string|in:admin,member',

            'accept_terms' => 'accepted',
        ]);

        $ownerRoleId = Role::query()->firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Account owner role']
        )->id;

        $employeeRoleId = Role::query()->firstOrCreate(
            ['name' => 'employee'],
            ['description' => 'Employee role']
        )->id;

        $accountOwner = $creator;
        if ($creator->role_id !== $ownerRoleId) {
            $creator->update(['role_id' => $ownerRoleId]);
        }

        $companyLogoPath = $accountOwner->company_logo;
        if ($request->hasFile('company_logo')) {
            $companyLogoPath = $request->file('company_logo')->store('company/logos', 'public');
        }

        $wasOnboarded = (bool) $accountOwner->onboarding_completed_at;

        $accountOwner->update([
            'company_name' => $validated['company_name'],
            'company_logo' => $companyLogoPath,
            'company_description' => $validated['company_description'] ?? null,
            'company_country' => $validated['company_country'] ?? null,
            'company_province' => $validated['company_province'] ?? null,
            'company_city' => $validated['company_city'] ?? null,
            'company_type' => $validated['company_type'],
            'company_sector' => $validated['company_sector'],
            'onboarding_completed_at' => now(),
        ]);

        if ($validated['company_type'] === 'services') {
            $this->seedSectorCategories($accountOwner, $creator, $validated['company_sector'] ?? null);
        }

        if ($validated['company_type'] === 'products') {
            $features = (array) ($accountOwner->company_features ?? []);
            if (!array_key_exists('sales', $features)) {
                $features['sales'] = true;
            }
            $accountOwner->update([
                'company_features' => $features,
            ]);
        }

        $invitePasswords = [];
        foreach (($validated['invites'] ?? []) as $invite) {
            $plainPassword = Str::random(14);
            $memberUser = User::create([
                'name' => $invite['name'],
                'email' => $invite['email'],
                'password' => Hash::make($plainPassword),
                'role_id' => $employeeRoleId,
                'email_verified_at' => now(),
            ]);

            TeamMember::create([
                'account_id' => $accountOwner->id,
                'user_id' => $memberUser->id,
                'role' => $invite['role'],
                'permissions' => $this->defaultPermissionsForRole($invite['role']),
                'is_active' => true,
            ]);

            $invitePasswords[] = $invite['email'] . '=' . $plainPassword;
        }

        $messageParts = ['Onboarding completed.'];
        if ($invitePasswords) {
            $messageParts[] = 'Team passwords: ' . implode(', ', $invitePasswords);
        }

        if (!$wasOnboarded && $accountOwner->email) {
            try {
                $accountOwner->notify(new WelcomeEmailNotification($accountOwner));
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        if (!$wasOnboarded) {
            try {
                $notifier = app(PlatformAdminNotifier::class);
                $inviteCount = count($validated['invites'] ?? []);

                $notifier->notify('onboarding_completed', 'Onboarding completed', [
                    'intro' => ($accountOwner->company_name ?: $accountOwner->email) . ' finished onboarding.',
                    'details' => [
                        ['label' => 'Company', 'value' => $accountOwner->company_name ?: 'Not set'],
                        ['label' => 'Owner', 'value' => $accountOwner->email ?: 'Unknown'],
                        ['label' => 'Type', 'value' => $accountOwner->company_type ?: 'Not set'],
                        ['label' => 'Sector', 'value' => $accountOwner->company_sector ?: 'Not set'],
                        ['label' => 'Team invites', 'value' => (string) $inviteCount],
                    ],
                    'actionUrl' => route('superadmin.tenants.show', $accountOwner->id),
                    'actionLabel' => 'View tenant',
                    'reference' => 'onboarding:' . $accountOwner->id,
                    'severity' => 'success',
                ]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => implode(' ', $messageParts),
                'user' => $accountOwner->fresh(),
            ]);
        }

        return redirect()->route('dashboard')->with('success', implode(' ', $messageParts));
    }

    private function seedSectorCategories(User $accountOwner, User $creator, ?string $sector): void
    {
        $normalized = Str::of((string) $sector)->lower()->trim()->toString();
        $categories = self::SECTOR_CATEGORIES[$normalized] ?? null;

        if (!$categories) {
            $remoteCategories = $this->discoverSectorCategories((string) $sector);
            $base = self::SECTOR_CATEGORIES['autre'];
            $label = preg_replace('/\s+/', ' ', trim((string) $sector));
            $categories = array_merge($remoteCategories, $label !== '' ? [$label] : [], $base);
            $categories = array_values(array_unique($categories));
        }

        foreach ($categories as $name) {
            $clean = preg_replace('/\s+/', ' ', trim((string) $name));
            if ($clean === '') {
                continue;
            }

            $category = ProductCategory::resolveForAccount($accountOwner->id, $creator->id, $clean);
            if ($category && $category->user_id === $accountOwner->id && $category->archived_at) {
                $category->update(['archived_at' => null]);
            }
        }
    }

    private function discoverSectorCategories(string $sector): array
    {
        $query = preg_replace('/\s+/', ' ', trim($sector));
        if ($query === '') {
            return [];
        }

        $titles = $this->fetchWikipediaTitles($query);
        if (!$titles) {
            $titles = $this->fetchWikipediaTitles($query . ' services');
        }

        $categories = [];
        foreach ($titles as $title) {
            $clean = preg_replace('/\s+/', ' ', trim((string) $title));
            $clean = preg_replace('/\s+\(.*\)$/', '', $clean);
            if ($clean !== '') {
                $categories[] = $clean;
            }
        }

        $categories = array_values(array_unique($categories));
        return array_slice($categories, 0, 3);
    }

    private function fetchWikipediaTitles(string $query): array
    {
        try {
            $response = Http::timeout(5)->acceptJson()->get('https://fr.wikipedia.org/w/api.php', [
                'action' => 'opensearch',
                'search' => $query,
                'limit' => 5,
                'namespace' => 0,
                'format' => 'json',
            ]);

            if (!$response->ok()) {
                return [];
            }

            $data = $response->json();
            $titles = is_array($data) && isset($data[1]) && is_array($data[1]) ? $data[1] : [];

            return array_values(array_filter($titles, fn($title) => trim((string) $title) !== ''));
        } catch (\Throwable $exception) {
            return [];
        }
    }

    private function defaultPermissionsForRole(string $role): array
    {
        return match ($role) {
            'admin' => [
                'jobs.view',
                'jobs.edit',
                'tasks.view',
                'tasks.create',
                'tasks.edit',
                'tasks.delete',
                'quotes.view',
                'quotes.create',
                'quotes.edit',
                'quotes.send',
            ],
            'member' => [
                'jobs.view',
                'tasks.view',
                'tasks.edit',
            ],
            default => [
                'jobs.view',
            ],
        };
    }
}
