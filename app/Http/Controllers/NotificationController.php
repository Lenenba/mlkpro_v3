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
use App\Support\Notifications\UserNotificationCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
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
