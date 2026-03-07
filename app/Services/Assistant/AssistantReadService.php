<?php

namespace App\Services\Assistant;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;

class AssistantReadService
{
    public function __construct(
        private readonly AssistantEntityResolver $entityResolver
    ) {}

    public function readNotifications(array $interpretation, User $user): array
    {
        $limit = $this->entityResolver->resolveListLimit($interpretation['filters'] ?? []);
        $notifications = $user->notifications()
            ->latest()
            ->limit($limit)
            ->get();
        $unreadCount = $user->unreadNotifications()->count();

        if ($notifications->isEmpty()) {
            return [
                'status' => 'ok',
                'message' => 'Aucune notification pour le moment.',
            ];
        }

        $lines = [];
        $lines[] = 'Vous avez '.$unreadCount.' notification(s) non lue(s).';
        $lines[] = 'Dernieres notifications:';

        foreach ($notifications as $notification) {
            $title = (string) ($notification->data['title'] ?? 'Notification');
            $message = (string) ($notification->data['message'] ?? '');
            $dateLabel = $notification->created_at ? $notification->created_at->toDateString() : '';

            $line = '- '.$title;
            if ($message !== '') {
                $line .= ' - '.$message;
            }
            if ($dateLabel !== '') {
                $line .= ' ('.$dateLabel.')';
            }
            $lines[] = $line;
        }

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    public function listQuotes(array $interpretation, User $user, array $context): array
    {
        if (! Gate::forUser($user)->allows('viewAny', Quote::class)) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse aux devis.',
            ];
        }

        $accountId = $user->accountOwnerId() ?? $user->id;
        $filters = $this->entityResolver->normalizeListFilters($interpretation);
        $status = $this->entityResolver->normalizeQuoteStatus($interpretation['quote']['status'] ?? $filters['status']);
        $archivedOnly = $status === 'archived';
        if ($status && $status !== 'archived') {
            $filters['status'] = $status;
        }

        $customer = $this->entityResolver->resolveCustomer($accountId, $interpretation['customer'] ?? []);
        if ($customer) {
            $filters['customer_id'] = $customer->id;
        }

        $limit = $this->entityResolver->resolveListLimit($interpretation['filters'] ?? []);
        $query = $archivedOnly
            ? Quote::byUserWithArchived($accountId)->whereNotNull('archived_at')
            : Quote::byUser($accountId);

        $quotes = $query
            ->filter($filters)
            ->with('customer')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        if ($quotes->isEmpty()) {
            return [
                'status' => 'ok',
                'message' => 'Aucun devis trouve.',
            ];
        }

        $lines = [];
        $lines[] = 'Devis:';
        foreach ($quotes as $quote) {
            $label = $quote->number ?? $quote->id;
            $customerLabel = $this->formatCustomerLabel(
                $quote->customer?->first_name ?? '',
                $quote->customer?->last_name ?? '',
                $quote->customer?->company_name ?? '',
                $quote->customer?->email ?? ''
            );
            $line = '- '.$label.' | '.$quote->status;
            $line .= ' | '.$this->formatMoney((float) ($quote->total ?? 0));
            if ($customerLabel !== '') {
                $line .= ' | '.$customerLabel;
            }
            if ($quote->job_title) {
                $line .= ' | '.$quote->job_title;
            }
            $lines[] = $line;
        }

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    public function listWorks(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $membership = $user->id !== $accountId
            ? TeamMember::query()->forAccount($accountId)->active()->where('user_id', $user->id)->first()
            : null;

        if ($user->id !== $accountId) {
            if (! $membership || (! $membership->hasPermission('jobs.view') && ! $membership->hasPermission('jobs.edit'))) {
                return [
                    'status' => 'not_allowed',
                    'message' => 'Acces refuse aux jobs.',
                ];
            }
        }

        $filters = $this->entityResolver->normalizeListFilters($interpretation);
        $status = $this->entityResolver->normalizeWorkStatus($interpretation['work']['status'] ?? $filters['status']);
        if ($status) {
            $filters['status'] = $status;
        }

        $customer = $this->entityResolver->resolveCustomer($accountId, $interpretation['customer'] ?? []);
        if ($customer) {
            $filters['customer_id'] = $customer->id;
        }

        $limit = $this->entityResolver->resolveListLimit($interpretation['filters'] ?? []);
        $query = Work::byUser($accountId)->filter($filters);
        if ($membership) {
            $query->whereHas('teamMembers', fn ($sub) => $sub->whereKey($membership->id));
        }

        $works = $query
            ->with('customer')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        if ($works->isEmpty()) {
            return [
                'status' => 'ok',
                'message' => 'Aucun job trouve.',
            ];
        }

        $lines = [];
        $lines[] = 'Jobs:';
        foreach ($works as $work) {
            $label = $work->number ?? $work->id;
            $customerLabel = $this->formatCustomerLabel(
                $work->customer?->first_name ?? '',
                $work->customer?->last_name ?? '',
                $work->customer?->company_name ?? '',
                $work->customer?->email ?? ''
            );
            $dateLabel = $work->start_date ? Carbon::parse($work->start_date)->toDateString() : '';
            $line = '- '.$label.' | '.$work->status;
            if ($dateLabel !== '') {
                $line .= ' | '.$dateLabel;
            }
            if ($customerLabel !== '') {
                $line .= ' | '.$customerLabel;
            }
            if ($work->job_title) {
                $line .= ' | '.$work->job_title;
            }
            $lines[] = $line;
        }

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    public function listInvoices(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        if ($user->id !== $accountId) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse aux factures.',
            ];
        }

        $filters = $this->entityResolver->normalizeListFilters($interpretation);
        $status = $this->entityResolver->normalizeInvoiceStatus($interpretation['invoice']['status'] ?? $filters['status']);
        if ($status) {
            $filters['status'] = $status;
        }

        $customer = $this->entityResolver->resolveCustomer($accountId, $interpretation['customer'] ?? []);
        if ($customer) {
            $filters['customer_id'] = $customer->id;
        }

        $limit = $this->entityResolver->resolveListLimit($interpretation['filters'] ?? []);
        $invoices = Invoice::byUser($accountId)
            ->filter($filters)
            ->with('customer')
            ->withSum(['payments as payments_sum_amount' => fn ($query) => $query->whereIn('status', Payment::settledStatuses())], 'amount')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        if ($invoices->isEmpty()) {
            return [
                'status' => 'ok',
                'message' => 'Aucune facture trouvee.',
            ];
        }

        $lines = [];
        $lines[] = 'Factures:';
        foreach ($invoices as $invoice) {
            $label = $invoice->number ?? $invoice->id;
            $customerLabel = $this->formatCustomerLabel(
                $invoice->customer?->first_name ?? '',
                $invoice->customer?->last_name ?? '',
                $invoice->customer?->company_name ?? '',
                $invoice->customer?->email ?? ''
            );
            $total = (float) ($invoice->total ?? 0);
            $paid = (float) ($invoice->amount_paid ?? 0);
            $balance = max(0, round($total - $paid, 2));

            $line = '- '.$label.' | '.$invoice->status;
            $line .= ' | total '.$this->formatMoney($total);
            $line .= ' | reste '.$this->formatMoney($balance);
            if ($customerLabel !== '') {
                $line .= ' | '.$customerLabel;
            }
            $lines[] = $line;
        }

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    public function listCustomers(array $interpretation, User $user, array $context): array
    {
        [, $accountId] = $this->entityResolver->resolveCustomerAccount($user);
        if (! $accountId) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse aux clients.',
            ];
        }

        $filters = $this->entityResolver->normalizeListFilters($interpretation);
        if ($filters['search'] !== '') {
            $filters['name'] = $filters['search'];
        }

        $customerFilter = $interpretation['customer'] ?? [];
        if (($filters['name'] ?? '') === '' && is_array($customerFilter)) {
            $name = trim((string) ($customerFilter['company_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($customerFilter['first_name'] ?? '').' '.(string) ($customerFilter['last_name'] ?? ''));
            }
            if ($name !== '') {
                $filters['name'] = $name;
            }
        }

        $limit = $this->entityResolver->resolveListLimit($interpretation['filters'] ?? []);
        $customers = Customer::byUser($accountId)
            ->filter($filters)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        if ($customers->isEmpty()) {
            return [
                'status' => 'ok',
                'message' => 'Aucun client trouve.',
            ];
        }

        $lines = [];
        $lines[] = 'Clients:';
        foreach ($customers as $customer) {
            $label = $customer->number ?? $customer->id;
            $customerLabel = $this->formatCustomerLabel(
                $customer->first_name ?? '',
                $customer->last_name ?? '',
                $customer->company_name ?? '',
                $customer->email ?? ''
            );
            $line = '- '.$label;
            if ($customerLabel !== '') {
                $line .= ' | '.$customerLabel;
            }
            if ($customer->phone) {
                $line .= ' | '.$customer->phone;
            }
            $lines[] = $line;
        }

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    public function showQuote(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $quote = $this->entityResolver->resolveQuote($accountId, $context, $targets);

        if (! $quote) {
            return $this->needsInput('show_quote', [
                'Quel devis souhaitez-vous consulter ?',
            ]);
        }

        if (! Gate::forUser($user)->allows('show', $quote)) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse a ce devis.',
            ];
        }

        $quote->loadMissing('customer', 'products');
        $customerLabel = $this->formatCustomerLabel(
            $quote->customer?->first_name ?? '',
            $quote->customer?->last_name ?? '',
            $quote->customer?->company_name ?? '',
            $quote->customer?->email ?? ''
        );
        $label = $quote->number ?? $quote->id;

        $lines = [];
        $lines[] = 'Devis '.$label.':';
        $lines[] = 'Statut: '.$quote->status;
        $lines[] = 'Total: '.$this->formatMoney((float) ($quote->total ?? 0));
        if ($customerLabel !== '') {
            $lines[] = 'Client: '.$customerLabel;
        }
        if ($quote->job_title) {
            $lines[] = 'Job: '.$quote->job_title;
        }
        $lines[] = 'Lignes: '.$quote->products->count();

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    public function showWork(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $work = $this->entityResolver->resolveWork($accountId, $context, $targets);

        if (! $work) {
            return $this->needsInput('show_work', [
                'Quel job souhaitez-vous consulter ?',
            ]);
        }

        if (! Gate::forUser($user)->allows('view', $work)) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse a ce job.',
            ];
        }

        $work->loadMissing('customer', 'invoice');
        $customerLabel = $this->formatCustomerLabel(
            $work->customer?->first_name ?? '',
            $work->customer?->last_name ?? '',
            $work->customer?->company_name ?? '',
            $work->customer?->email ?? ''
        );
        $label = $work->number ?? $work->id;

        $lines = [];
        $lines[] = 'Job '.$label.':';
        $lines[] = 'Statut: '.$work->status;
        if ($work->start_date) {
            $lines[] = 'Date: '.Carbon::parse($work->start_date)->toDateString();
        }
        if ($work->job_title) {
            $lines[] = 'Titre: '.$work->job_title;
        }
        if ($customerLabel !== '') {
            $lines[] = 'Client: '.$customerLabel;
        }
        if ($work->invoice) {
            $lines[] = 'Facture: '.($work->invoice->number ?? $work->invoice->id);
        }

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    public function showInvoice(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        if ($user->id !== $accountId) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse aux factures.',
            ];
        }

        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $invoice = $this->entityResolver->resolveInvoice($accountId, $context, $targets);
        if (! $invoice) {
            return $this->needsInput('show_invoice', [
                'Quelle facture souhaitez-vous consulter ?',
            ]);
        }

        $invoice->loadMissing('customer', 'payments');
        $label = $invoice->number ?? $invoice->id;
        $customerLabel = $this->formatCustomerLabel(
            $invoice->customer?->first_name ?? '',
            $invoice->customer?->last_name ?? '',
            $invoice->customer?->company_name ?? '',
            $invoice->customer?->email ?? ''
        );
        $total = (float) ($invoice->total ?? 0);
        $paid = (float) $invoice->payments
            ->whereIn('status', Payment::settledStatuses())
            ->sum('amount');
        $balance = max(0, round($total - $paid, 2));

        $lines = [];
        $lines[] = 'Facture '.$label.':';
        $lines[] = 'Statut: '.$invoice->status;
        $lines[] = 'Total: '.$this->formatMoney($total);
        $lines[] = 'Paye: '.$this->formatMoney($paid);
        $lines[] = 'Reste: '.$this->formatMoney($balance);
        if ($customerLabel !== '') {
            $lines[] = 'Client: '.$customerLabel;
        }

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    public function showCustomer(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $customer = $this->entityResolver->resolveCustomer($accountId, $interpretation['customer'] ?? []);
        if (! $customer && ! empty($context['current_customer']['id'])) {
            $customer = Customer::byUser($accountId)->whereKey((int) $context['current_customer']['id'])->first();
        }

        if (! $customer) {
            return $this->needsInput('show_customer', [
                'Quel client souhaitez-vous consulter ?',
            ]);
        }

        if (! Gate::forUser($user)->allows('view', $customer)) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse a ce client.',
            ];
        }

        $customer->loadCount(['quotes', 'works', 'invoices']);
        $label = $customer->number ?? $customer->id;
        $customerLabel = $this->formatCustomerLabel(
            $customer->first_name ?? '',
            $customer->last_name ?? '',
            $customer->company_name ?? '',
            $customer->email ?? ''
        );

        $lines = [];
        $lines[] = 'Client '.$label.':';
        if ($customerLabel !== '') {
            $lines[] = $customerLabel;
        }
        if ($customer->phone) {
            $lines[] = 'Tel: '.$customer->phone;
        }
        $lines[] = 'Devis: '.$customer->quotes_count;
        $lines[] = 'Jobs: '.$customer->works_count;
        $lines[] = 'Factures: '.$customer->invoices_count;

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    private function needsInput(string $intent, array $questions): array
    {
        return [
            'status' => 'needs_input',
            'message' => implode(' ', $questions),
            'questions' => $questions,
            'context' => [
                'intent' => $intent,
            ],
        ];
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 2, '.', ' ').' EUR';
    }

    private function formatCustomerLabel(string $firstName, string $lastName, string $companyName, string $email): string
    {
        $parts = array_filter([
            trim($firstName.' '.$lastName),
            trim($companyName),
            trim($email),
        ]);

        return implode(' | ', $parts);
    }
}
