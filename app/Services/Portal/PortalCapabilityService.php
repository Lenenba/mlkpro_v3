<?php

namespace App\Services\Portal;

use App\Models\Customer;
use App\Models\User;
use App\Services\CompanyFeatureService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PortalCapabilityService
{
    private const VERSION = 1;

    private const SERVICE_EXPERIENCE_CAPABILITIES = [
        'reservations.view',
        'quotes.view',
        'works.view',
        'tasks.view',
    ];

    private const ACTIONS = [
        'orders' => ['view', 'history', 'create', 'update', 'pay', 'confirm', 'cancel', 'reorder', 'review'],
        'reservations' => ['view', 'book', 'manage', 'review'],
        'quotes' => ['view', 'history', 'accept', 'decline', 'rate'],
        'works' => ['view', 'validate', 'dispute', 'schedule', 'proofs', 'rate'],
        'tasks' => ['view', 'upload'],
        'invoices' => ['view', 'history', 'create', 'pay'],
        'packages' => ['view', 'manage'],
        'loyalty' => ['view'],
        'notifications' => ['view', 'manage'],
    ];

    public function __construct(
        private readonly PortalAccessService $portalAccessService,
        private readonly CompanyFeatureService $companyFeatureService,
    ) {}

    /**
     * @return array<string, int|array<string, bool>>
     */
    public function forUser(User $user): array
    {
        $user->loadMissing(['role', 'customerProfile']);

        if (! $user->isClient() || ! $this->portalAccessService->clientHasPortalAccess($user)) {
            return $this->unavailableCapabilities();
        }

        $customer = $user->customerProfile;

        return $customer instanceof Customer
            ? $this->forCustomer($customer)
            : $this->unavailableCapabilities();
    }

    /**
     * @return array<string, int|array<string, bool>>
     */
    public function forRequest(Request $request): array
    {
        $user = $request->user();

        return $user instanceof User
            ? $this->forUser($user)
            : $this->unavailableCapabilities();
    }

    /**
     * @return array<string, int|array<string, bool>>
     */
    public function forCustomer(Customer $customer): array
    {
        if (! $customer->portal_access) {
            return $this->unavailableCapabilities();
        }

        try {
            $owner = $this->portalAccessService->ownerForCustomer($customer);
        } catch (HttpExceptionInterface) {
            return $this->unavailableCapabilities();
        }

        return $this->forOwner($owner, $customer);
    }

    /**
     * @return array<string, int|array<string, bool>>
     */
    public function forOwner(User $owner, ?Customer $customer = null): array
    {
        $features = $this->companyFeatureService->resolveEffectiveFeatures($owner);
        $hasFeature = static fn (string $feature): bool => (bool) ($features[$feature] ?? false);

        $ordersEnabled = $hasFeature('products') && $hasFeature('sales');
        $reservationsEnabled = $hasFeature('reservations');
        $quotesEnabled = $hasFeature('quotes');
        $jobsEnabled = $hasFeature('jobs');
        $tasksEnabled = $jobsEnabled && $hasFeature('tasks');
        $invoicesEnabled = $hasFeature('invoices');
        $packagesEnabled = $hasFeature('products') || $hasFeature('services') || $hasFeature('sales');

        $autoAcceptQuotes = (bool) ($customer?->auto_accept_quotes ?? false);
        $autoValidateJobs = (bool) ($customer?->auto_validate_jobs ?? false);
        $autoValidateTasks = (bool) ($customer?->auto_validate_tasks ?? false);
        $autoValidateInvoices = (bool) ($customer?->auto_validate_invoices ?? false);

        return [
            'version' => self::VERSION,
            'orders' => [
                'view' => $ordersEnabled,
                'history' => $ordersEnabled,
                'create' => $ordersEnabled,
                'update' => $ordersEnabled,
                'pay' => $ordersEnabled,
                'confirm' => $ordersEnabled,
                'cancel' => $ordersEnabled,
                'reorder' => $ordersEnabled,
                'review' => $ordersEnabled,
            ],
            'reservations' => [
                'view' => $reservationsEnabled,
                'book' => $reservationsEnabled,
                'manage' => $reservationsEnabled,
                'review' => $reservationsEnabled,
            ],
            'quotes' => [
                'view' => $quotesEnabled,
                'history' => true,
                'accept' => $quotesEnabled && $jobsEnabled && ! $autoAcceptQuotes,
                'decline' => $quotesEnabled && ! $autoAcceptQuotes,
                'rate' => $quotesEnabled,
            ],
            'works' => [
                'view' => $jobsEnabled,
                'validate' => $jobsEnabled && ! $autoValidateJobs,
                'dispute' => $jobsEnabled && ! $autoValidateJobs,
                'schedule' => $tasksEnabled,
                'proofs' => $tasksEnabled,
                'rate' => $jobsEnabled,
            ],
            'tasks' => [
                'view' => $tasksEnabled,
                'upload' => $tasksEnabled && ! $autoValidateTasks,
            ],
            'invoices' => [
                'view' => $invoicesEnabled,
                'history' => true,
                'create' => $invoicesEnabled,
                'pay' => $invoicesEnabled && ! $autoValidateInvoices,
            ],
            'packages' => [
                'view' => $packagesEnabled,
                'manage' => $packagesEnabled,
            ],
            'loyalty' => [
                'view' => $hasFeature('loyalty'),
            ],
            'notifications' => [
                'view' => true,
                'manage' => true,
            ],
        ];
    }

    public function allows(User $user, string $capability): bool
    {
        return data_get($this->forUser($user), $capability) === true;
    }

    public function customerAllows(Customer $customer, string $capability): bool
    {
        return data_get($this->forCustomer($customer), $capability) === true;
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @return array{mode: 'service'|'product'|'hybrid'|'minimal', has_service: bool, has_product: bool}
     */
    public function context(array $capabilities): array
    {
        $hasServiceExperience = collect(self::SERVICE_EXPERIENCE_CAPABILITIES)
            ->contains(static fn (string $capability): bool => data_get($capabilities, $capability) === true);
        $hasProductExperience = data_get($capabilities, 'orders.view') === true;

        $mode = match (true) {
            $hasServiceExperience && $hasProductExperience => 'hybrid',
            $hasServiceExperience => 'service',
            $hasProductExperience => 'product',
            default => 'minimal',
        };

        return [
            'mode' => $mode,
            'has_service' => $hasServiceExperience,
            'has_product' => $hasProductExperience,
        ];
    }

    /**
     * @param  array<string, mixed>  $capabilities
     */
    public function fingerprint(array $capabilities): string
    {
        return hash('sha256', json_encode(
            $this->sortRecursively($capabilities),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
    }

    /**
     * @return array<string, int|array<string, bool>>
     */
    private function unavailableCapabilities(): array
    {
        $capabilities = ['version' => self::VERSION];

        foreach (self::ACTIONS as $domain => $actions) {
            $capabilities[$domain] = array_fill_keys($actions, false);
        }

        return $capabilities;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function sortRecursively(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->sortRecursively($value);
            }
        }

        if (! array_is_list($values)) {
            ksort($values);
        }

        return $values;
    }
}
