<?php

namespace App\Services\Assistant;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quote;
use App\Models\QuoteTax;
use App\Models\Tax;
use App\Models\User;
use App\Services\UsageLimitService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssistantQuoteService
{
    private const MATCH_CONFIDENT_THRESHOLD = 0.84;
    private const MATCH_MIN_THRESHOLD = 0.68;

    public function handle(array $interpretation, User $user, array $context = []): array
    {
        $draft = $context['draft'] ?? [];
        $draft = $this->mergeDraft($draft, $interpretation['quote'] ?? []);

        $accountId = $user->accountOwnerId() ?? $user->id;
        $questions = [];

        $customerResult = $this->resolveCustomer($accountId, $draft['customer'] ?? []);
        $customer = $customerResult['customer'];
        if (!$customer) {
            $questions = array_merge($questions, $customerResult['questions']);
        }

        if (!$customer) {
            $contextCustomer = $this->resolveContextCustomer($accountId, $context);
            if ($contextCustomer) {
                $customer = $contextCustomer;
                $questions = [];
            }
        }

        $items = $this->normalizeItems($draft['items'] ?? []);
        if (!$items) {
            $questions[] = 'Quel service ou produit faut-il ajouter au devis ?';
        }

        $resolvedItems = [];
        foreach ($items as $item) {
            if ($item['name'] === '') {
                continue;
            }

            $resolved = $this->resolveItem($accountId, $user, $item);
            $resolvedItems[] = $resolved['item'];
            $questions = array_merge($questions, $resolved['questions']);
        }

        $resolvedTaxes = $this->resolveTaxes($draft['taxes'] ?? []);
        $questions = array_merge($questions, $resolvedTaxes['questions']);

        if ($questions) {
            return [
                'status' => 'needs_input',
                'message' => 'J ai besoin de quelques infos pour creer le devis.',
                'questions' => array_values(array_unique($questions)),
                'context' => [
                    'intent' => 'create_quote',
                    'draft' => [
                        'customer' => $draft['customer'] ?? [],
                        'items' => $resolvedItems,
                        'taxes' => $draft['taxes'] ?? [],
                        'notes' => $draft['notes'] ?? '',
                        'messages' => $draft['messages'] ?? '',
                    ],
                ],
            ];
        }

        $summary = $this->buildQuoteSummary(
            $customer,
            $draft['customer'] ?? [],
            $resolvedItems,
            $resolvedTaxes['taxes'] ?? []
        );
        $pendingAction = [
            'type' => 'create_quote',
            'payload' => [
                'customer_id' => $customer?->id,
                'customer' => $draft['customer'] ?? [],
                'items' => $resolvedItems,
                'taxes' => $draft['taxes'] ?? [],
                'notes' => $draft['notes'] ?? '',
                'messages' => $draft['messages'] ?? '',
            ],
            'summary' => $summary,
        ];

        return [
            'status' => 'needs_confirmation',
            'message' => $summary . "\nConfirmer ? (oui/non)",
            'context' => [
                'pending_action' => $pendingAction,
            ],
        ];
    }

    public function execute(array $payload, User $user): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $customer = null;
        $customerId = $payload['customer_id'] ?? null;
        if ($customerId) {
            $customer = Customer::byUser($accountId)->whereKey((int) $customerId)->first();
        }

        if (!$customer) {
            $customer = $this->createCustomer($accountId, $payload['customer'] ?? []);
        }

        $items = $this->normalizeItems($payload['items'] ?? []);
        $resolvedTaxes = $this->resolveTaxes($payload['taxes'] ?? []);

        app(UsageLimitService::class)->enforceLimit($user, 'quotes');

        $quote = DB::transaction(function () use ($accountId, $user, $customer, $items, $resolvedTaxes, $payload) {
            $itemsPayload = $this->ensureProducts($accountId, $user, $items);
            $subtotal = collect($itemsPayload)->sum('total');

            $taxLines = collect($resolvedTaxes['taxes'] ?? [])
                ->map(function (Tax $tax) use ($subtotal) {
                    $amount = round($subtotal * ((float) $tax->rate / 100), 2);
                    return [
                        'tax_id' => $tax->id,
                        'rate' => (float) $tax->rate,
                        'amount' => $amount,
                    ];
                })
                ->values()
                ->all();

            $taxTotal = collect($taxLines)->sum('amount');
            $total = round($subtotal + $taxTotal, 2);

            $jobTitle = $this->buildJobTitle($customer, $itemsPayload);

            $quote = Quote::create([
                'user_id' => $accountId,
                'customer_id' => $customer->id,
                'property_id' => $customer->properties()->value('id'),
                'job_title' => $jobTitle,
                'status' => 'draft',
                'subtotal' => $subtotal,
                'total' => $total,
                'notes' => $payload['notes'] ?? null,
                'messages' => $payload['messages'] ?? null,
                'initial_deposit' => 0,
                'is_fixed' => false,
            ]);

            $pivotData = collect($itemsPayload)
                ->mapWithKeys(function (array $item) {
                    return [
                        $item['product_id'] => [
                            'quantity' => $item['quantity'],
                            'price' => $item['price'],
                            'description' => $item['description'],
                            'total' => $item['total'],
                            'source_details' => null,
                        ],
                    ];
                })
                ->toArray();
            $quote->products()->sync($pivotData);

            foreach ($taxLines as $taxLine) {
                QuoteTax::create([
                    'quote_id' => $quote->id,
                    'tax_id' => $taxLine['tax_id'],
                    'rate' => $taxLine['rate'],
                    'amount' => $taxLine['amount'],
                ]);
            }

            return $quote;
        });

        ActivityLog::record($user, $quote, 'created', [
            'status' => $quote->status,
            'total' => $quote->total,
            'assistant' => true,
        ], 'Quote created by assistant');

        return [
            'status' => 'created',
            'message' => 'Devis brouillon cree. Ouverture du devis.',
            'action' => [
                'type' => 'quote_created',
                'quote_id' => $quote->id,
            ],
            'context' => [
                'pending_action' => null,
            ],
        ];
    }

    private function mergeDraft(array $base, array $updates): array
    {
        $baseCustomer = $base['customer'] ?? [];
        $updateCustomer = $updates['customer'] ?? [];
        $mergedCustomer = $baseCustomer;
        foreach ($updateCustomer as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '' || $value === null) {
                continue;
            }
            $mergedCustomer[$key] = $value;
        }

        $baseItems = $base['items'] ?? [];
        $updateItems = $updates['items'] ?? [];
        $mergedItems = $this->mergeItems($baseItems, $updateItems);

        $taxes = array_values(array_filter(array_unique(array_merge(
            Arr::wrap($base['taxes'] ?? []),
            Arr::wrap($updates['taxes'] ?? [])
        ))));

        return [
            'customer' => $mergedCustomer,
            'items' => $mergedItems,
            'taxes' => $taxes,
            'notes' => $updates['notes'] ?? ($base['notes'] ?? ''),
            'messages' => $updates['messages'] ?? ($base['messages'] ?? ''),
        ];
    }

    private function mergeItems(array $baseItems, array $updateItems): array
    {
        $indexed = [];
        foreach ($baseItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = strtolower(trim((string) ($item['name'] ?? '')));
            if ($name === '') {
                continue;
            }
            $indexed[$name] = $item;
        }

        foreach ($updateItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = strtolower(trim((string) ($item['name'] ?? '')));
            if ($name === '') {
                continue;
            }
            $existing = $indexed[$name] ?? [];
            $merged = $existing;
            foreach ($item as $key => $value) {
                $value = is_string($value) ? trim($value) : $value;
                if ($value === '' || $value === null) {
                    continue;
                }
                $merged[$key] = $value;
            }
            $indexed[$name] = $merged;
        }

        return array_values($indexed);
    }

    private function normalizeItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            $quantity = (int) ($item['quantity'] ?? 1);
            $quantity = $quantity > 0 ? $quantity : 1;
            $price = $item['price'] ?? null;
            $price = $price === null ? null : (float) $price;
            $itemType = strtolower((string) ($item['item_type'] ?? ''));
            $unit = trim((string) ($item['unit'] ?? ''));

            $normalized[] = [
                'name' => $name,
                'quantity' => $quantity,
                'price' => $price,
                'item_type' => $itemType,
                'unit' => $unit,
                'product_id' => $item['product_id'] ?? null,
            ];
        }

        return $normalized;
    }

    private function resolveContextCustomer(int $accountId, array $context): ?Customer
    {
        $current = $context['current_customer'] ?? null;
        if (is_array($current)) {
            $id = $current['id'] ?? null;
            if ($id) {
                return Customer::byUser($accountId)->whereKey($id)->first();
            }

            $email = trim((string) ($current['email'] ?? ''));
            if ($email !== '') {
                return Customer::byUser($accountId)->where('email', $email)->first();
            }
        }

        if (is_numeric($current)) {
            return Customer::byUser($accountId)->whereKey((int) $current)->first();
        }

        return null;
    }

    private function resolveCustomer(int $accountId, array $customerDraft): array
    {
        $questions = [];
        $email = trim((string) ($customerDraft['email'] ?? ''));
        $companyName = trim((string) ($customerDraft['company_name'] ?? ''));
        $firstName = trim((string) ($customerDraft['first_name'] ?? ''));
        $lastName = trim((string) ($customerDraft['last_name'] ?? ''));
        $fullName = trim((string) ($customerDraft['name'] ?? ''));

        if ($fullName !== '' && (!$firstName || !$lastName)) {
            $parts = preg_split('/\s+/', $fullName, 2);
            $firstName = $firstName ?: ($parts[0] ?? '');
            $lastName = $lastName ?: ($parts[1] ?? '');
        }

        $customer = null;
        if ($email !== '') {
            $customer = Customer::byUser($accountId)->where('email', $email)->first();
        }

        if (!$customer && $companyName !== '') {
            $customer = Customer::byUser($accountId)
                ->whereRaw('LOWER(company_name) = ?', [strtolower($companyName)])
                ->first();
        }

        if (!$customer && $firstName !== '' && $lastName !== '') {
            $customer = Customer::byUser($accountId)
                ->whereRaw('LOWER(first_name) = ?', [strtolower($firstName)])
                ->whereRaw('LOWER(last_name) = ?', [strtolower($lastName)])
                ->first();
        }

        if (!$customer) {
            if ($firstName === '') {
                $questions[] = 'Quel est le prenom du client ?';
            }
            if ($lastName === '') {
                $questions[] = 'Quel est le nom du client ?';
            }
            if ($email === '') {
                $questions[] = 'Quel est son email ?';
            }
        }

        return [
            'customer' => $customer,
            'questions' => $questions,
        ];
    }

    private function resolveItem(int $accountId, User $user, array $item): array
    {
        $questions = [];
        $name = trim((string) ($item['name'] ?? ''));
        $itemTypeRaw = strtolower((string) ($item['item_type'] ?? ''));
        $itemTypeExplicit = in_array($itemTypeRaw, ['product', 'service'], true);
        $itemType = $itemTypeRaw === 'product' ? 'product' : ($itemTypeRaw === 'service' ? 'service' : $this->defaultItemType($user));

        $match = $this->findProductByName($accountId, $name, $itemType);
        $product = $match['product'] ?? null;
        $score = (float) ($match['score'] ?? 0);
        $alternates = $match['alternates'] ?? [];
        if (!$product && !$itemTypeExplicit) {
            $fallbackType = $itemType === 'product' ? 'service' : 'product';
            $match = $this->findProductByName($accountId, $name, $fallbackType);
            $product = $match['product'] ?? null;
            $score = (float) ($match['score'] ?? 0);
            $alternates = $match['alternates'] ?? [];
            if ($product) {
                $itemType = $fallbackType;
            }
        }

        $isConfident = $product && $score >= self::MATCH_CONFIDENT_THRESHOLD;
        $isAmbiguous = $product && !$isConfident;

        if ($isConfident && $item['price'] === null) {
            $item['price'] = (float) $product->price;
        }

        if ($isConfident && ($item['unit'] ?? '') === '' && $product->unit) {
            $item['unit'] = $product->unit;
        }

        if ($isConfident) {
            $item['product_id'] = $product->id;
        }

        if ($isAmbiguous) {
            $candidates = array_values(array_filter(array_unique($alternates)));
            if ($candidates) {
                $questions[] = 'Je ne suis pas certain du service. Voulez-vous dire: ' . implode(', ', array_slice($candidates, 0, 3)) . ' ?';
            } else {
                $questions[] = 'Je ne suis pas certain du service. Pouvez-vous confirmer le nom exact ?';
            }
        } elseif (!$product && $item['price'] === null) {
            $questions[] = 'Quel est le prix pour "' . $name . '" ?';
        }

        $item['item_type'] = $itemType;

        return [
            'item' => $item,
            'questions' => $questions,
        ];
    }

    private function resolveTaxes(array $taxNames): array
    {
        $questions = [];
        $names = array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            Arr::wrap($taxNames)
        )));

        if (!$names) {
            return [
                'taxes' => [],
                'questions' => [],
            ];
        }

        $taxes = Tax::query()
            ->whereIn(DB::raw('LOWER(name)'), array_map('strtolower', $names))
            ->get();

        $foundNames = $taxes->map(fn (Tax $tax) => strtolower($tax->name))->all();
        foreach ($names as $name) {
            if (!in_array(strtolower($name), $foundNames, true)) {
                $questions[] = 'Quelle taxe faut-il appliquer pour "' . $name . '" ?';
            }
        }

        return [
            'taxes' => $taxes->all(),
            'questions' => $questions,
        ];
    }

    private function createCustomer(int $accountId, array $draft): Customer
    {
        $firstName = trim((string) ($draft['first_name'] ?? ''));
        $lastName = trim((string) ($draft['last_name'] ?? ''));
        $email = trim((string) ($draft['email'] ?? ''));

        return Customer::create([
            'user_id' => $accountId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company_name' => trim((string) ($draft['company_name'] ?? '')) ?: null,
            'email' => $email,
            'phone' => trim((string) ($draft['phone'] ?? '')) ?: null,
            'portal_access' => false,
            'billing_same_as_physical' => true,
            'billing_mode' => 'end_of_job',
            'billing_grouping' => 'single',
            'discount_rate' => 0,
            'auto_accept_quotes' => false,
            'auto_validate_jobs' => false,
            'auto_validate_tasks' => false,
            'auto_validate_invoices' => false,
            'salutation' => 'Mr',
        ]);
    }

    private function ensureProducts(int $accountId, User $user, array $items): array
    {
        $payload = [];
        foreach ($items as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $itemType = strtolower((string) ($item['item_type'] ?? ''));
            $itemType = $itemType === 'product' ? 'product' : ($itemType === 'service' ? 'service' : $this->defaultItemType($user));

            $product = null;
            if (!empty($item['product_id'])) {
                $product = Product::byUser($accountId)->whereKey((int) $item['product_id'])->first();
            }
            if (!$product) {
                $match = $this->findProductByName($accountId, $name, $itemType);
                $product = $match['product'] ?? null;
            }
            if ($product) {
                $itemType = $product->item_type;
            }

            if (!$product) {
                $category = ProductCategory::resolveForAccount(
                    $accountId,
                    $user->id,
                    $itemType === 'product' ? 'Products' : 'Services'
                );

                $product = Product::create([
                    'user_id' => $accountId,
                    'name' => $name,
                    'description' => 'Auto-generated from assistant.',
                    'category_id' => $category->id,
                    'price' => (float) ($item['price'] ?? 0),
                    'stock' => 0,
                    'minimum_stock' => 0,
                    'unit' => $item['unit'] ?? null,
                    'is_active' => true,
                    'item_type' => $itemType,
                ]);
            }

            $quantity = (int) ($item['quantity'] ?? 1);
            $quantity = $quantity > 0 ? $quantity : 1;
            $price = $item['price'] === null ? (float) $product->price : (float) $item['price'];
            $total = round($quantity * $price, 2);

            $payload[] = [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'price' => $price,
                'total' => $total,
                'description' => $product->description,
            ];
        }

        return $payload;
    }

    private function findProductByName(int $accountId, string $name, string $itemType): array
    {
        $normalized = $this->normalizeMatchValue($name);
        if ($normalized === '') {
            return ['product' => null, 'score' => 0.0, 'alternates' => []];
        }

        $baseQuery = Product::byUser($accountId)->where('item_type', $itemType);

        $exact = (clone $baseQuery)
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->first();
        if ($exact) {
            return ['product' => $exact, 'score' => 1.0, 'alternates' => [$exact->name]];
        }

        $candidates = $this->loadCandidateProducts($baseQuery, $normalized);
        if ($candidates->isEmpty()) {
            return ['product' => null, 'score' => 0.0, 'alternates' => []];
        }

        $scored = [];
        foreach ($candidates as $candidate) {
            $candidateName = $this->normalizeMatchValue((string) $candidate->name);
            $score = $this->stringSimilarity($normalized, $candidateName);
            $scored[] = [
                'product' => $candidate,
                'score' => $score,
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $best = $scored[0] ?? null;
        if (!$best || $best['score'] < self::MATCH_MIN_THRESHOLD) {
            return ['product' => null, 'score' => $best['score'] ?? 0.0, 'alternates' => []];
        }

        $alternates = [];
        foreach (array_slice($scored, 0, 3) as $entry) {
            $alternates[] = $entry['product']->name;
        }

        return [
            'product' => $best['product'],
            'score' => (float) $best['score'],
            'alternates' => $alternates,
        ];
    }

    private function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function normalizeMatchValue(string $value): string
    {
        $value = Str::ascii($value);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value);
        $value = trim((string) preg_replace('/\s+/', ' ', (string) $value));

        return $value;
    }

    private function loadCandidateProducts($baseQuery, string $normalized)
    {
        $tokens = array_values(array_filter(explode(' ', $normalized), fn ($token) => strlen($token) >= 3));
        $seed = $tokens ? $this->longestToken($tokens) : $normalized;
        $like = '%' . $this->escapeLikeValue($seed) . '%';

        $candidates = (clone $baseQuery)
            ->whereRaw('LOWER(name) LIKE ?', [$like])
            ->limit(50)
            ->get(['id', 'name', 'price', 'unit', 'item_type']);

        if ($candidates->isEmpty() && count($tokens) > 1) {
            $fallbackLike = '%' . $this->escapeLikeValue($normalized) . '%';
            $candidates = (clone $baseQuery)
                ->whereRaw('LOWER(name) LIKE ?', [$fallbackLike])
                ->limit(50)
                ->get(['id', 'name', 'price', 'unit', 'item_type']);
        }

        if ($candidates->isEmpty()) {
            $candidates = (clone $baseQuery)
                ->limit(50)
                ->get(['id', 'name', 'price', 'unit', 'item_type']);
        }

        return $candidates;
    }

    private function longestToken(array $tokens): string
    {
        usort($tokens, fn ($a, $b) => strlen($b) <=> strlen($a));
        return (string) ($tokens[0] ?? '');
    }

    private function stringSimilarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }

        $maxLen = max(strlen($a), strlen($b));
        if ($maxLen === 0) {
            return 0.0;
        }

        $distance = levenshtein($a, $b);
        $score = 1 - ($distance / $maxLen);
        if (str_contains($a, $b) || str_contains($b, $a)) {
            $score = max($score, 0.85);
        }

        return max(0.0, min(1.0, $score));
    }

    private function buildJobTitle(Customer $customer, array $itemsPayload): string
    {
        $base = $customer->company_name
            ?: trim($customer->first_name . ' ' . $customer->last_name);

        $firstItem = $itemsPayload[0]['description'] ?? null;
        $firstItem = $firstItem ?: ($itemsPayload[0]['product_id'] ?? null);

        return $base !== '' ? "Devis - {$base}" : 'Devis';
    }

    private function defaultItemType(User $user): string
    {
        return $user->company_type === 'products' ? 'product' : 'service';
    }

    private function buildQuoteSummary(?Customer $customer, array $draftCustomer, array $items, array $taxes): string
    {
        $label = $customer
            ? $this->formatCustomerLabel($customer->first_name, $customer->last_name, $customer->company_name, $customer->email)
            : $this->formatCustomerLabel(
                $draftCustomer['first_name'] ?? '',
                $draftCustomer['last_name'] ?? '',
                $draftCustomer['company_name'] ?? '',
                $draftCustomer['email'] ?? ''
            );

        $summary = [];
        $summary[] = 'Resume du devis:';
        $summary[] = ($customer ? 'Client existant: ' : 'Nouveau client: ') . ($label ?: 'Client');

        $subtotal = 0.0;
        if ($items) {
            $summary[] = 'Articles:';
            foreach ($items as $item) {
                $name = $item['name'] ?: 'Ligne';
                $quantity = (int) ($item['quantity'] ?? 1);
                $price = (float) ($item['price'] ?? 0);
                $lineTotal = round($quantity * $price, 2);
                $subtotal += $lineTotal;
                $summary[] = '- ' . $name . ' x' . $quantity . ' @ ' . $this->formatMoney($price) . ' = ' . $this->formatMoney($lineTotal);
            }
        } else {
            $summary[] = 'Articles: aucun';
        }

        $taxTotal = 0.0;
        $taxLabels = [];
        foreach ($taxes as $tax) {
            $rate = (float) ($tax->rate ?? 0);
            $taxLabels[] = $tax->name . ' (' . $rate . '%)';
            $taxTotal += round($subtotal * ($rate / 100), 2);
        }

        $summary[] = 'Sous-total: ' . $this->formatMoney($subtotal);
        if ($taxLabels) {
            $summary[] = 'Taxes: ' . implode(', ', $taxLabels) . ' = ' . $this->formatMoney($taxTotal);
        }

        $total = round($subtotal + $taxTotal, 2);
        $summary[] = 'Total estime: ' . $this->formatMoney($total);

        return implode("\n", $summary);
    }

    private function formatMoney(float $value): string
    {
        return '$' . number_format($value, 2, '.', '');
    }

    private function formatCustomerLabel(string $firstName, string $lastName, string $companyName, string $email): string
    {
        $parts = [];
        $companyName = trim($companyName);
        $name = trim($firstName . ' ' . $lastName);
        if ($companyName !== '') {
            $parts[] = $companyName;
        }
        if ($name !== '') {
            $parts[] = $name;
        }
        if ($email !== '') {
            $parts[] = $email;
        }

        return implode(' - ', $parts);
    }
}
