<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\ServiceRequest;
use App\Models\Task;
use App\Models\User;
use App\Models\Work;
use App\Services\Portal\PortalCapabilityService;
use App\Support\Notifications\UserNotificationCenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function __construct(
        private readonly PortalCapabilityService $portalCapabilityService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $payload = app(UserNotificationCenter::class)->pagePayload(
            $user,
            $request->only(['status', 'type', 'per_page'])
        );

        return $this->inertiaOrJson('Notifications/Index', [
            'notification_history' => $payload['notifications'],
            'history_filters' => $payload['filters'],
            'history_stats' => $payload['stats'],
            'history_type_options' => $payload['type_options'],
            'history_per_page_options' => $payload['per_page_options'],
        ]);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user) {
            app(UserNotificationCenter::class)->markAllHeaderReadAndArchive($user);
        }

        return redirect()->back();
    }

    public function markRead(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! app(UserNotificationCenter::class)->belongsTo($user, $notification)) {
            abort(404);
        }

        app(UserNotificationCenter::class)->markRead($notification);

        return redirect()->back();
    }

    public function archive(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! app(UserNotificationCenter::class)->belongsTo($user, $notification)) {
            abort(404);
        }

        app(UserNotificationCenter::class)->archive($notification);

        return redirect()->back();
    }

    public function restore(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! app(UserNotificationCenter::class)->belongsTo($user, $notification)) {
            abort(404);
        }

        app(UserNotificationCenter::class)->restore($notification);

        return redirect()->back();
    }

    public function open(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! app(UserNotificationCenter::class)->belongsTo($user, $notification)) {
            abort(404);
        }

        $source = $request->query('source') === 'header' ? 'header' : 'history';
        if ($source === 'header') {
            app(UserNotificationCenter::class)->markReadAndArchive($notification);
        } else {
            app(UserNotificationCenter::class)->markRead($notification);
        }

        $destinationUrl = $this->resolveDestinationUrl($notification, $user);
        if (blank($destinationUrl)) {
            return redirect()
                ->route('notifications.index')
                ->with('warning', 'L element lie a cette notification n est plus disponible.');
        }

        return redirect()->to($destinationUrl);
    }

    private function resolveDestinationUrl(DatabaseNotification $notification, User $user): ?string
    {
        $data = is_array($notification->data) ? $notification->data : [];

        if ($user->isClient()) {
            return $this->resolveClientDestinationUrl($data, $user);
        }

        $entityUrl = $this->resolveEntityUrl($data, (int) $user->accountOwnerId());

        if (filled($entityUrl)) {
            return $entityUrl;
        }

        if ($this->hasEntityReference($data)) {
            return null;
        }

        $actionUrl = data_get($data, 'action_url');

        return filled($actionUrl) ? (string) $actionUrl : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveClientDestinationUrl(array $data, User $user): string
    {
        $dashboardUrl = route('dashboard');
        $capabilities = $this->portalCapabilityService->forUser($user);
        $customer = $user->customerProfile;

        if (! $customer instanceof Customer) {
            return $dashboardUrl;
        }

        $invoiceReference = $this->firstFilled($data, ['invoice_id']);
        if ($invoiceReference !== null) {
            if (data_get($capabilities, 'invoices.history') !== true) {
                return $dashboardUrl;
            }

            $invoice = $this->findClientInvoice($invoiceReference, $customer);

            return $invoice instanceof Invoice
                ? route('portal.invoices.show', $invoice)
                : $dashboardUrl;
        }

        $saleReference = $this->firstFilled($data, ['sale_id', 'order_id']);
        if ($saleReference !== null) {
            if (data_get($capabilities, 'orders.history') !== true) {
                return $dashboardUrl;
            }

            $sale = $this->findClientSale($saleReference, $customer);

            return $sale instanceof Sale
                ? route('portal.orders.show', $sale)
                : $dashboardUrl;
        }

        $reservationReference = $this->firstFilled($data, ['reservation_id']);
        if ($reservationReference !== null) {
            if (data_get($capabilities, 'reservations.view') !== true) {
                return $dashboardUrl;
            }

            $reservation = $this->findClientReservation($reservationReference, $customer, $user);

            return $reservation instanceof Reservation
                ? route('client.reservations.index')
                : $dashboardUrl;
        }

        if ($this->hasEntityReference($data)) {
            return $dashboardUrl;
        }

        return $this->resolveSafeClientActionUrl(data_get($data, 'action_url'), $capabilities)
            ?? $dashboardUrl;
    }

    private function findClientInvoice(mixed $id, Customer $customer): ?Invoice
    {
        $id = $this->normalizeId($id);
        if ($id === null) {
            return null;
        }

        return Invoice::query()
            ->whereKey($id)
            ->where('customer_id', $customer->id)
            ->where('user_id', $customer->user_id)
            ->first();
    }

    private function findClientSale(mixed $id, Customer $customer): ?Sale
    {
        $id = $this->normalizeId($id);
        if ($id === null) {
            return null;
        }

        return Sale::query()
            ->whereKey($id)
            ->where('customer_id', $customer->id)
            ->where('user_id', $customer->user_id)
            ->first();
    }

    private function findClientReservation(mixed $id, Customer $customer, User $user): ?Reservation
    {
        $id = $this->normalizeId($id);
        if ($id === null) {
            return null;
        }

        return Reservation::query()
            ->whereKey($id)
            ->where('account_id', $customer->user_id)
            ->where(function (Builder $query) use ($customer, $user): void {
                $query
                    ->where('client_user_id', $user->id)
                    ->orWhere('client_id', $customer->id);
            })
            ->first();
    }

    /**
     * @param  array<string, mixed>  $capabilities
     */
    private function resolveSafeClientActionUrl(mixed $actionUrl, array $capabilities): ?string
    {
        if (! is_string($actionUrl) || blank($actionUrl)) {
            return null;
        }

        $actionUrl = trim($actionUrl);
        $path = $this->sameOriginPath($actionUrl);

        if ($path === null || ! $this->isSafeClientPath($path, $capabilities)) {
            return null;
        }

        return $actionUrl;
    }

    private function sameOriginPath(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $path = $parts['path'] ?? null;
        if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        $decodedPath = rawurldecode($path);
        if (str_contains($decodedPath, '\\')
            || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $decodedPath) === 1) {
            return null;
        }

        $hasExplicitOrigin = isset($parts['scheme']) || isset($parts['host']) || isset($parts['port']);
        if (! $hasExplicitOrigin) {
            return $path;
        }

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $applicationParts = parse_url(url('/'));
        if ($applicationParts === false || ! isset($applicationParts['scheme'], $applicationParts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $applicationScheme = strtolower($applicationParts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)
            || $scheme !== $applicationScheme
            || strtolower($parts['host']) !== strtolower($applicationParts['host'])
            || $this->originPort($parts) !== $this->originPort($applicationParts)) {
            return null;
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function originPort(array $parts): ?int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return match (strtolower((string) ($parts['scheme'] ?? ''))) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $capabilities
     */
    private function isSafeClientPath(string $path, array $capabilities): bool
    {
        if ($this->pathMatches($path, '/dashboard') || $this->pathMatches($path, '/profile')) {
            return true;
        }

        $requiredCapability = match (true) {
            $this->pathMatches($path, '/notifications') => 'notifications.view',
            $this->pathMatches($path, '/client/reservations') => 'reservations.view',
            $this->pathMatches($path, '/portal/orders') => 'orders.history',
            $this->pathMatches($path, '/portal/invoices') => 'invoices.history',
            $this->pathMatches($path, '/portal/packages') => 'packages.view',
            $this->pathMatches($path, '/portal/loyalty') => 'loyalty.view',
            $this->pathMatches($path, '/portal/quotes') => 'quotes.view',
            $this->pathMatches($path, '/portal/works') => 'works.view',
            $this->pathMatches($path, '/portal/tasks') => 'tasks.view',
            default => null,
        };

        return $requiredCapability !== null
            && data_get($capabilities, $requiredCapability) === true;
    }

    private function pathMatches(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix.'/');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveEntityUrl(array $data, int $accountId): ?string
    {
        if ($task = $this->findOwnedByAccount(Task::class, $this->firstFilled($data, ['task_id']), $accountId)) {
            return route('task.show', $task);
        }

        if ($invoice = $this->findOwnedByUser(Invoice::class, $this->firstFilled($data, ['invoice_id']), $accountId)) {
            return route('invoice.show', $invoice);
        }

        if ($expense = $this->findOwnedByUser(Expense::class, $this->firstFilled($data, ['expense_id']), $accountId)) {
            return route('expense.show', $expense);
        }

        if ($reservation = $this->findOwnedByAccount(Reservation::class, $this->firstFilled($data, ['reservation_id']), $accountId)) {
            return route('reservation.index', [
                'date_from' => $reservation->starts_at?->toDateString(),
                'date_to' => $reservation->starts_at?->toDateString(),
                'per_page' => 50,
                'view_mode' => 'list',
                'reservation_id' => $reservation->id,
            ]);
        }

        if ($quote = $this->findOwnedByUser(Quote::class, $this->firstFilled($data, ['quote_id']), $accountId)) {
            return route('customer.quote.show', $quote);
        }

        if ($sale = $this->findOwnedByUser(Sale::class, $this->firstFilled($data, ['sale_id', 'order_id']), $accountId)) {
            return route('sales.show', $sale);
        }

        if ($work = $this->findOwnedByUser(Work::class, $this->firstFilled($data, ['work_id']), $accountId)) {
            return route('work.show', $work);
        }

        if ($product = $this->findOwnedByUser(Product::class, $this->firstFilled($data, ['product_id']), $accountId)) {
            return route('product.show', $product);
        }

        if ($serviceRequest = $this->findOwnedByUser(ServiceRequest::class, $this->firstFilled($data, ['service_request_id']), $accountId)) {
            return route('service-requests.show', $serviceRequest);
        }

        if ($campaignProspect = $this->findCampaignProspect($data, $accountId)) {
            return route('campaigns.prospects.show', [$campaignProspect->campaign_id, $campaignProspect]);
        }

        if ($campaign = $this->findOwnedByUser(Campaign::class, $this->firstFilled($data, ['campaign_id']), $accountId)) {
            return route('campaigns.show', $campaign);
        }

        if ($lead = $this->findOwnedByUser(LeadRequest::class, $this->firstFilled($data, ['lead_id', 'prospect_id']), $accountId)) {
            return route('prospects.show', $lead);
        }

        if ($leadRequest = $this->findOwnedByUser(LeadRequest::class, $this->firstFilled($data, ['request_id', 'legacy_request_id']), $accountId)) {
            return route('request.show', $leadRequest);
        }

        if ($customer = $this->findOwnedByUser(Customer::class, $this->firstFilled($data, ['customer_id', 'client_id']), $accountId)) {
            return route('customer.show', $customer);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasEntityReference(array $data): bool
    {
        return $this->firstFilled($data, [
            'task_id',
            'invoice_id',
            'expense_id',
            'reservation_id',
            'quote_id',
            'sale_id',
            'order_id',
            'work_id',
            'product_id',
            'service_request_id',
            'campaign_prospect_id',
            'campaign_id',
            'lead_id',
            'prospect_id',
            'request_id',
            'legacy_request_id',
            'customer_id',
            'client_id',
        ]) !== null;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function findOwnedByUser(string $modelClass, mixed $id, int $accountId): ?Model
    {
        $id = $this->normalizeId($id);
        if ($id === null) {
            return null;
        }

        return $modelClass::query()
            ->whereKey($id)
            ->where('user_id', $accountId)
            ->first();
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function findOwnedByAccount(string $modelClass, mixed $id, int $accountId): ?Model
    {
        $id = $this->normalizeId($id);
        if ($id === null) {
            return null;
        }

        return $modelClass::query()
            ->whereKey($id)
            ->where('account_id', $accountId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findCampaignProspect(array $data, int $accountId): ?CampaignProspect
    {
        $id = $this->normalizeId($this->firstFilled($data, ['campaign_prospect_id']));
        if ($id === null) {
            return null;
        }

        return CampaignProspect::query()
            ->whereKey($id)
            ->where('user_id', $accountId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     */
    private function firstFilled(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = data_get($data, $key);

            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeId(mixed $id): ?int
    {
        if (is_string($id)) {
            $id = trim($id);
        }

        if ($id === null || $id === '' || ! is_numeric($id)) {
            return null;
        }

        $id = (int) $id;

        return $id > 0 ? $id : null;
    }
}
