<?php

namespace App\Services\Assistant;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Property;
use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkChecklistItem;
use App\Models\Transaction;
use App\Notifications\SendQuoteNotification;
use App\Notifications\InviteUserNotification;
use App\Services\TaskBillingService;
use App\Services\UsageLimitService;
use App\Services\WorkBillingService;
use App\Services\WorkScheduleService;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AssistantWorkflowService
{
    private const TEAM_PERMISSION_KEYS = [
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
        'sales.manage',
        'sales.pos',
    ];

    public function handle(array $interpretation, User $user, array $context = []): array
    {
        $intent = $interpretation['intent'] ?? 'unknown';

        return match ($intent) {
            'create_work' => $this->handleCreateWork($interpretation, $user, $context),
            'create_invoice' => $this->handleCreateInvoice($interpretation, $user, $context),
            'send_quote' => $this->handleSendQuote($interpretation, $user, $context),
            'accept_quote' => $this->handleAcceptQuote($interpretation, $user, $context),
            'convert_quote' => $this->handleConvertQuote($interpretation, $user, $context),
            'mark_invoice_paid' => $this->handleMarkInvoicePaid($interpretation, $user, $context),
            'update_work_status' => $this->handleUpdateWorkStatus($interpretation, $user, $context),
            'create_customer' => $this->handleCreateCustomer($interpretation, $user, $context),
            'create_property' => $this->handleCreateProperty($interpretation, $user, $context),
            'create_category' => $this->handleCreateCategory($interpretation, $user, $context),
            'create_product', 'create_service' => $this->handleCreateProduct($interpretation, $user, $context),
            'create_team_member' => $this->handleCreateTeamMember($interpretation, $user, $context),
            default => [
                'status' => 'unknown',
                'message' => 'Je peux creer et gerer des devis, factures et jobs, ainsi que creer des clients, proprietes, categories, produits/services et membres d equipe.',
            ],
        };
    }

    public function execute(array $pendingAction, User $user): array
    {
        $type = $pendingAction['type'] ?? '';

        return match ($type) {
            'create_work' => $this->executeCreateWork($pendingAction['payload'] ?? [], $user),
            'create_invoice' => $this->executeCreateInvoice($pendingAction['payload'] ?? [], $user),
            'send_quote' => $this->executeSendQuote($pendingAction['payload'] ?? [], $user),
            'accept_quote' => $this->executeAcceptQuote($pendingAction['payload'] ?? [], $user),
            'convert_quote' => $this->executeConvertQuote($pendingAction['payload'] ?? [], $user),
            'mark_invoice_paid' => $this->executeMarkInvoicePaid($pendingAction['payload'] ?? [], $user),
            'update_work_status' => $this->executeUpdateWorkStatus($pendingAction['payload'] ?? [], $user),
            'create_team_member' => $this->executeCreateTeamMember($pendingAction['payload'] ?? [], $user),
            default => [
                'status' => 'unknown',
                'message' => 'Action inconnue.',
            ],
        };
    }

    private function handleCreateCustomer(array $interpretation, User $user, array $context): array
    {
        $draft = is_array($context['draft'] ?? null) ? $context['draft'] : [];
        $draft = $this->mergeCustomerDraft($draft, $interpretation['customer'] ?? []);
        $draft = $this->applyAnswerToCustomerDraft($draft, $context);

        $questions = [];
        if ($draft['first_name'] === '') {
            $questions[] = 'Quel est le prenom du client ?';
        }
        if ($draft['last_name'] === '') {
            $questions[] = 'Quel est le nom du client ?';
        }
        if ($draft['email'] === '') {
            $questions[] = 'Quel est son email ?';
        }

        if ($questions) {
            return $this->needsInput('create_customer', $draft, $questions);
        }

        $accountId = $user->accountOwnerId() ?? $user->id;
        $existing = $this->resolveCustomer($accountId, $draft);
        if ($existing) {
            return [
                'status' => 'created',
                'message' => 'Client deja existant.',
                'action' => [
                    'type' => 'customer_created',
                    'customer_id' => $existing->id,
                ],
                'context' => [
                    'intent' => null,
                    'draft' => null,
                ],
            ];
        }

        $emailConflict = Customer::query()->where('email', $draft['email'])->first();
        if ($emailConflict) {
            return $this->needsInput('create_customer', $draft, [
                'Cet email est deja utilise. Merci de donner un autre email.',
            ]);
        }

        $customer = $this->createCustomer($accountId, $draft);

        return [
            'status' => 'created',
            'message' => 'Client cree.',
            'action' => [
                'type' => 'customer_created',
                'customer_id' => $customer->id,
            ],
            'context' => [
                'intent' => null,
                'draft' => null,
            ],
        ];
    }

    private function handleCreateProperty(array $interpretation, User $user, array $context): array
    {
        $draft = is_array($context['draft'] ?? null) ? $context['draft'] : [];
        $draft = $this->mergePropertyDraft($draft, $interpretation['property'] ?? []);
        $draft = $this->applyAnswerToPropertyDraft($draft, $context);

        $accountId = $user->accountOwnerId() ?? $user->id;
        $customerDraft = $draft['customer'] ?? [];
        $customer = $this->resolveCustomer($accountId, $customerDraft);
        if (!$customer) {
            $customer = $this->resolveContextCustomer($accountId, $context);
        }
        $questions = [];

        if (!$customer) {
            if (($customerDraft['first_name'] ?? '') === '') {
                $questions[] = 'Quel est le prenom du client ?';
            }
            if (($customerDraft['last_name'] ?? '') === '') {
                $questions[] = 'Quel est le nom du client ?';
            }
            if (($customerDraft['email'] ?? '') === '') {
                $questions[] = 'Quel est l email du client ?';
            }
        }

        if (($draft['city'] ?? '') === '') {
            $questions[] = 'Quelle est la ville de la propriete ?';
        }

        if ($questions) {
            return $this->needsInput('create_property', $draft, $questions);
        }

        if (!$customer) {
            $customer = $this->createCustomer($accountId, $customerDraft);
        }

        if ($user->id !== $customer->user_id) {
            return [
                'status' => 'not_allowed',
                'message' => 'Vous ne pouvez pas modifier ce client.',
            ];
        }

        $type = $draft['type'] !== '' ? $draft['type'] : 'physical';
        $makeDefault = (bool) ($draft['is_default'] ?? false);

        $property = null;
        DB::transaction(function () use ($customer, $draft, $type, $makeDefault, &$property) {
            $hasExisting = $customer->properties()->exists();
            $shouldBeDefault = $makeDefault || !$hasExisting;

            if ($shouldBeDefault) {
                $customer->properties()->update(['is_default' => false]);
            }

            $property = $customer->properties()->create([
                'type' => $type,
                'is_default' => $shouldBeDefault,
                'street1' => $draft['street1'] ?? null,
                'street2' => $draft['street2'] ?? null,
                'city' => $draft['city'],
                'state' => $draft['state'] ?? null,
                'zip' => $draft['zip'] ?? null,
                'country' => $draft['country'] ?? null,
            ]);
        });

        return [
            'status' => 'created',
            'message' => 'Propriete creee.',
            'action' => [
                'type' => 'property_created',
                'property_id' => $property?->id,
                'customer_id' => $customer->id,
            ],
            'context' => [
                'intent' => null,
                'draft' => null,
            ],
        ];
    }

    private function handleCreateCategory(array $interpretation, User $user, array $context): array
    {
        $draft = is_array($context['draft'] ?? null) ? $context['draft'] : [];
        $draft = $this->mergeCategoryDraft($draft, $interpretation['category'] ?? []);

        $questions = [];
        if (($draft['name'] ?? '') === '') {
            $questions[] = 'Quel est le nom de la categorie ?';
        }
        if (($draft['item_type'] ?? '') === '') {
            $questions[] = 'Categorie pour produits ou services ?';
        }

        if ($questions) {
            return $this->needsInput('create_category', $draft, $questions);
        }

        $accountId = $user->accountOwnerId() ?? $user->id;
        $itemType = $this->normalizeItemType($draft['item_type'] ?? '', $user);
        $category = ProductCategory::resolveForAccount(
            $accountId,
            $user->id,
            $draft['name']
        );

        return [
            'status' => 'created',
            'message' => 'Categorie creee.',
            'action' => [
                'type' => 'category_created',
                'category_id' => $category?->id,
                'item_type' => $itemType,
            ],
            'context' => [
                'intent' => null,
                'draft' => null,
            ],
        ];
    }

    private function handleCreateProduct(array $interpretation, User $user, array $context): array
    {
        $intent = $interpretation['intent'] ?? '';
        $draft = is_array($context['draft'] ?? null) ? $context['draft'] : [];
        $draft = $this->mergeProductDraft($draft, $interpretation['product'] ?? []);

        $questions = [];
        if (($draft['name'] ?? '') === '') {
            $questions[] = 'Quel est le nom du produit ou service ?';
        }
        if (!isset($draft['price'])) {
            $questions[] = 'Quel est le prix ?';
        }

        if ($questions) {
            return $this->needsInput($intent ?: 'create_product', $draft, $questions);
        }

        $accountId = $user->accountOwnerId() ?? $user->id;
        $itemType = $intent === 'create_service'
            ? 'service'
            : $this->normalizeItemType($draft['item_type'] ?? '', $user);

        $existing = Product::byUser($accountId)
            ->where('item_type', $itemType)
            ->whereRaw('LOWER(name) = ?', [strtolower($draft['name'])])
            ->first();

        if ($existing) {
            return [
                'status' => 'created',
                'message' => 'Produit ou service deja existant.',
                'action' => [
                    'type' => $itemType === 'service' ? 'service_created' : 'product_created',
                    'product_id' => $existing->id,
                    'item_type' => $itemType,
                ],
                'context' => [
                    'intent' => null,
                    'draft' => null,
                ],
            ];
        }

        $categoryName = trim((string) ($draft['category'] ?? ''));
        $category = ProductCategory::resolveForAccount(
            $accountId,
            $user->id,
            $categoryName !== '' ? $categoryName : ($itemType === 'service' ? 'Services' : 'Products')
        );

        $product = Product::create([
            'user_id' => $accountId,
            'name' => $draft['name'],
            'description' => $draft['description'] ?: 'Auto-generated from assistant.',
            'category_id' => $category?->id,
            'price' => (float) ($draft['price'] ?? 0),
            'stock' => 0,
            'minimum_stock' => 0,
            'unit' => $draft['unit'] ?? null,
            'is_active' => true,
            'item_type' => $itemType,
        ]);

        return [
            'status' => 'created',
            'message' => $itemType === 'service' ? 'Service cree.' : 'Produit cree.',
            'action' => [
                'type' => $itemType === 'service' ? 'service_created' : 'product_created',
                'product_id' => $product->id,
                'item_type' => $itemType,
            ],
            'context' => [
                'intent' => null,
                'draft' => null,
            ],
        ];
    }

    private function handleCreateTeamMember(array $interpretation, User $user, array $context): array
    {
        if (!$user->isAccountOwner()) {
            return [
                'status' => 'not_allowed',
                'message' => 'Seul le proprietaire du compte peut creer des membres.',
            ];
        }

        $draft = is_array($context['draft'] ?? null) ? $context['draft'] : [];
        $draft = $this->mergeTeamMemberDraft($draft, $interpretation['team_member'] ?? []);
        $draft = $this->applyAnswerToTeamMemberDraft($draft, $context);

        $questions = [];
        if (($draft['name'] ?? '') === '') {
            $questions[] = 'Quel est le nom du membre ?';
        }
        if (($draft['email'] ?? '') === '') {
            $questions[] = 'Quel est son email ?';
        }

        if ($questions) {
            return $this->needsInput('create_team_member', $draft, $questions);
        }

        $email = strtolower(trim((string) ($draft['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $draft['email'] = $email;
            return $this->needsInput('create_team_member', $draft, [
                'Merci de donner un email valide.',
            ]);
        }
        $draft['email'] = $email;

        if (User::query()->where('email', $email)->exists()) {
            return $this->needsInput('create_team_member', $draft, [
                'Cet email est deja utilise. Merci de donner un autre email.',
            ]);
        }

        $role = $this->normalizeTeamRole($draft['role'] ?? '');
        $permissions = $this->resolveTeamPermissions($draft, $context);
        if (!$permissions) {
            $permissions = $this->defaultTeamPermissions($role);
        }

        $summary = $this->buildTeamMemberSummary($draft['name'], $email, $role, $permissions);
        $pendingAction = [
            'type' => 'create_team_member',
            'payload' => [
                'name' => $draft['name'],
                'email' => $email,
                'role' => $role,
                'title' => $draft['title'] ?? null,
                'phone' => $draft['phone'] ?? null,
                'permissions' => $permissions,
            ],
            'summary' => $summary,
        ];

        return $this->needsConfirmation($summary, $pendingAction);
    }

    private function handleCreateWork(array $interpretation, User $user, array $context): array
    {
        $draft = is_array($context['draft'] ?? null) ? $context['draft'] : [];
        $draft = $this->mergeWorkDraft($draft, $interpretation['work'] ?? []);

        $accountId = $user->accountOwnerId() ?? $user->id;
        $customerDraft = $draft['customer'] ?? [];
        $customer = $this->resolveCustomer($accountId, $customerDraft);
        if (!$customer) {
            $customer = $this->resolveContextCustomer($accountId, $context);
        }

        $questions = [];
        if (!$customer) {
            if (($customerDraft['first_name'] ?? '') === '') {
                $questions[] = 'Quel est le prenom du client ?';
            }
            if (($customerDraft['last_name'] ?? '') === '') {
                $questions[] = 'Quel est le nom du client ?';
            }
            if (($customerDraft['email'] ?? '') === '') {
                $questions[] = 'Quel est son email ?';
            }
        }

        if (($draft['job_title'] ?? '') === '') {
            $questions[] = 'Quel est le titre du job ?';
        }

        $startDate = trim((string) ($draft['start_date'] ?? ''));
        if ($startDate === '') {
            $questions[] = 'Quelle est la date de debut du job ?';
        } elseif (!$this->isValidDate($startDate)) {
            $questions[] = 'Quelle est la date de debut (format YYYY-MM-DD) ?';
        }

        $status = $this->normalizeWorkStatus($draft['status'] ?? '');
        if (($draft['status'] ?? '') !== '' && !$status) {
            $questions[] = 'Quel statut pour le job ?';
        }

        $items = $this->normalizeItems($draft['items'] ?? []);
        $resolvedItems = [];
        foreach ($items as $item) {
            if (($item['name'] ?? '') === '') {
                continue;
            }
            $resolved = $this->resolveWorkItem($accountId, $user, $item);
            $resolvedItems[] = $resolved['item'];
            $questions = array_merge($questions, $resolved['questions']);
        }

        if ($questions) {
            $draft['items'] = $resolvedItems;
            return $this->needsInput('create_work', $draft, $questions);
        }

        $summary = $this->buildWorkSummary($customer, $customerDraft, $draft, $resolvedItems, $status ?: Work::STATUS_SCHEDULED);
        $pendingAction = [
            'type' => 'create_work',
            'payload' => [
                'customer_id' => $customer?->id,
                'customer' => $customerDraft,
                'job_title' => $draft['job_title'] ?? '',
                'instructions' => $draft['instructions'] ?? '',
                'start_date' => $draft['start_date'] ?? '',
                'end_date' => $draft['end_date'] ?? '',
                'start_time' => $draft['start_time'] ?? '',
                'end_time' => $draft['end_time'] ?? '',
                'status' => $status ?: Work::STATUS_SCHEDULED,
                'type' => $draft['type'] ?? '',
                'category' => $draft['category'] ?? '',
                'items' => $resolvedItems,
            ],
            'summary' => $summary,
        ];

        return $this->needsConfirmation($summary, $pendingAction);
    }

    private function handleCreateInvoice(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $work = $this->resolveWork($accountId, $context, $targets);

        if (!$work) {
            return $this->needsInput('create_invoice', [], [
                'Pour quel job faut-il creer la facture ?',
            ]);
        }

        if ($work->invoice) {
            return [
                'status' => 'created',
                'message' => 'Une facture existe deja pour ce job.',
                'action' => [
                    'type' => 'invoice_created',
                    'invoice_id' => $work->invoice->id,
                ],
                'context' => [
                    'intent' => null,
                    'draft' => null,
                ],
            ];
        }

        $summary = $this->buildInvoiceSummary($work);
        $pendingAction = [
            'type' => 'create_invoice',
            'payload' => [
                'work_id' => $work->id,
            ],
            'summary' => $summary,
        ];

        return $this->needsConfirmation($summary, $pendingAction);
    }

    private function handleSendQuote(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $quote = $this->resolveQuote($accountId, $context, $targets);

        if (!$quote) {
            return $this->needsInput('send_quote', [], [
                'Quel devis faut-il envoyer ?',
            ]);
        }

        if ($quote->isArchived()) {
            return [
                'status' => 'not_allowed',
                'message' => 'Ce devis est archive.',
            ];
        }

        $quote->loadMissing('customer');
        if (!$quote->customer || !$quote->customer->email) {
            return [
                'status' => 'needs_input',
                'message' => 'Email client manquant pour envoyer le devis.',
                'questions' => ['Ajoutez un email client puis reessayez.'],
            ];
        }

        $summary = 'Envoyer le devis ' . ($quote->number ?? $quote->id)
            . ' a ' . $quote->customer->email . '.';
        $pendingAction = [
            'type' => 'send_quote',
            'payload' => [
                'quote_id' => $quote->id,
            ],
            'summary' => $summary,
        ];

        return $this->needsConfirmation($summary, $pendingAction);
    }

    private function handleAcceptQuote(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $quote = $this->resolveQuote($accountId, $context, $targets);

        if (!$quote) {
            return $this->needsInput('accept_quote', [], [
                'Quel devis faut-il accepter ?',
            ]);
        }

        if ($quote->isArchived()) {
            return [
                'status' => 'not_allowed',
                'message' => 'Ce devis est archive.',
            ];
        }

        if ($quote->status === 'accepted') {
            return [
                'status' => 'created',
                'message' => 'Devis deja accepte.',
                'action' => [
                    'type' => 'quote_accepted',
                    'quote_id' => $quote->id,
                ],
                'context' => [
                    'intent' => null,
                    'draft' => null,
                ],
            ];
        }

        if ($quote->status === 'declined') {
            return [
                'status' => 'not_allowed',
                'message' => 'Ce devis est refuse.',
            ];
        }

        $deposit = (float) ($quote->initial_deposit ?? 0);
        $summary = 'Accepter le devis ' . ($quote->number ?? $quote->id)
            . ' et creer le job. Depot: ' . $this->formatMoney($deposit) . '.';
        $pendingAction = [
            'type' => 'accept_quote',
            'payload' => [
                'quote_id' => $quote->id,
                'deposit_amount' => $deposit,
                'method' => 'assistant',
            ],
            'summary' => $summary,
        ];

        return $this->needsConfirmation($summary, $pendingAction);
    }

    private function handleConvertQuote(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $quote = $this->resolveQuote($accountId, $context, $targets);

        if (!$quote) {
            return $this->needsInput('convert_quote', [], [
                'Quel devis faut-il convertir en job ?',
            ]);
        }

        if ($quote->isArchived()) {
            return [
                'status' => 'not_allowed',
                'message' => 'Ce devis est archive.',
            ];
        }

        $existingWork = Work::query()->where('quote_id', $quote->id)->first();
        if ($existingWork) {
            return [
                'status' => 'created',
                'message' => 'Job deja cree pour ce devis.',
                'action' => [
                    'type' => 'work_created',
                    'work_id' => $existingWork->id,
                ],
                'context' => [
                    'intent' => null,
                    'draft' => null,
                ],
            ];
        }

        $summary = 'Convertir le devis ' . ($quote->number ?? $quote->id) . ' en job.';
        $pendingAction = [
            'type' => 'convert_quote',
            'payload' => [
                'quote_id' => $quote->id,
            ],
            'summary' => $summary,
        ];

        return $this->needsConfirmation($summary, $pendingAction);
    }

    private function handleMarkInvoicePaid(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $invoice = $this->resolveInvoice($accountId, $context, $targets);

        if (!$invoice) {
            return $this->needsInput('mark_invoice_paid', [], [
                'Quelle facture faut-il regler ?',
            ]);
        }

        if ($invoice->status === 'paid') {
            return [
                'status' => 'created',
                'message' => 'Facture deja payee.',
                'action' => [
                    'type' => 'invoice_paid',
                    'invoice_id' => $invoice->id,
                ],
                'context' => [
                    'intent' => null,
                    'draft' => null,
                ],
            ];
        }

        $amount = $interpretation['invoice']['amount'] ?? null;
        if ($amount === null) {
            $amount = (float) $invoice->balance_due;
        }

        $summary = 'Enregistrer un paiement de ' . $this->formatMoney((float) $amount)
            . ' pour la facture ' . ($invoice->number ?? $invoice->id) . '.';
        $pendingAction = [
            'type' => 'mark_invoice_paid',
            'payload' => [
                'invoice_id' => $invoice->id,
                'amount' => (float) $amount,
                'method' => 'assistant',
            ],
            'summary' => $summary,
        ];

        return $this->needsConfirmation($summary, $pendingAction);
    }

    private function handleUpdateWorkStatus(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $work = $this->resolveWork($accountId, $context, $targets);

        if (!$work) {
            return $this->needsInput('update_work_status', [], [
                'Quel job faut-il mettre a jour ?',
            ]);
        }

        $status = $this->normalizeWorkStatus($interpretation['work']['status'] ?? '');
        if (!$status) {
            return $this->needsInput('update_work_status', [], [
                'Quel statut souhaitez-vous appliquer ?',
            ]);
        }

        $summary = 'Changer le statut du job ' . ($work->number ?? $work->id)
            . ' de ' . $work->status . ' vers ' . $status . '.';
        $pendingAction = [
            'type' => 'update_work_status',
            'payload' => [
                'work_id' => $work->id,
                'status' => $status,
            ],
            'summary' => $summary,
        ];

        return $this->needsConfirmation($summary, $pendingAction);
    }

    private function executeCreateWork(array $payload, User $user): array
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

        $startDate = $this->parseDate($payload['start_date'] ?? null);
        if (!$startDate) {
            return [
                'status' => 'error',
                'message' => 'Date de debut invalide.',
            ];
        }

        $endDate = $this->parseDate($payload['end_date'] ?? null);
        $startTime = $this->parseTime($payload['start_time'] ?? null);
        $endTime = $this->parseTime($payload['end_time'] ?? null);

        $status = $this->normalizeWorkStatus($payload['status'] ?? '') ?: Work::STATUS_SCHEDULED;

        $items = $this->normalizeItems($payload['items'] ?? []);
        $lines = $this->ensureProducts($accountId, $user, $items);
        $subtotal = $lines ? collect($lines)->sum('total') : null;
        $total = $subtotal;

        app(UsageLimitService::class)->enforceLimit($user, 'jobs');

        $work = DB::transaction(function () use ($customer, $payload, $startDate, $endDate, $startTime, $endTime, $status, $lines, $subtotal, $total) {
            $work = Work::create([
                'user_id' => $customer->user_id,
                'customer_id' => $customer->id,
                'job_title' => $payload['job_title'] ?? 'Job',
                'instructions' => $payload['instructions'] ?? '',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'status' => $status,
                'type' => $payload['type'] ?? null,
                'category' => $payload['category'] ?? null,
                'subtotal' => $subtotal,
                'total' => $total,
                'billing_mode' => $customer->billing_mode ?? null,
                'billing_cycle' => $customer->billing_cycle ?? null,
                'billing_grouping' => $customer->billing_grouping ?? null,
                'billing_delay_days' => $customer->billing_delay_days ?? null,
                'billing_date_rule' => $customer->billing_date_rule ?? null,
            ]);

            if ($lines) {
                $pivotData = collect($lines)->mapWithKeys(function (array $line) {
                    return [
                        $line['product_id'] => [
                            'quantity' => $line['quantity'],
                            'price' => $line['price'],
                            'total' => $line['total'],
                        ],
                    ];
                });
                $work->products()->sync($pivotData->toArray());
            }

            return $work;
        });

        ActivityLog::record($user, $work, 'created', [
            'status' => $work->status,
            'total' => $work->total,
            'assistant' => true,
        ], 'Job created by assistant');

        $this->autoScheduleTasksForWork($work, $user);

        return [
            'status' => 'created',
            'message' => 'Job cree. Ouverture du job.',
            'action' => [
                'type' => 'work_created',
                'work_id' => $work->id,
            ],
            'context' => [
                'pending_action' => null,
            ],
        ];
    }

    private function executeCreateInvoice(array $payload, User $user): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $workId = $payload['work_id'] ?? null;
        if (!$workId) {
            return [
                'status' => 'error',
                'message' => 'Job manquant pour la facture.',
            ];
        }

        $work = Work::byUser($accountId)->with('invoice')->find($workId);
        if (!$work) {
            return [
                'status' => 'error',
                'message' => 'Job introuvable.',
            ];
        }

        if ($work->invoice) {
            return [
                'status' => 'created',
                'message' => 'Une facture existe deja pour ce job.',
                'action' => [
                    'type' => 'invoice_created',
                    'invoice_id' => $work->invoice->id,
                ],
                'context' => [
                    'pending_action' => null,
                ],
            ];
        }

        $invoice = app(WorkBillingService::class)->createInvoiceFromWork($work, $user);

        return [
            'status' => 'created',
            'message' => 'Facture creee. Ouverture de la facture.',
            'action' => [
                'type' => 'invoice_created',
                'invoice_id' => $invoice->id,
            ],
            'context' => [
                'pending_action' => null,
            ],
        ];
    }

    private function executeCreateTeamMember(array $payload, User $user): array
    {
        if (!$user->isAccountOwner()) {
            return [
                'status' => 'not_allowed',
                'message' => 'Seul le proprietaire du compte peut creer des membres.',
            ];
        }

        app(UsageLimitService::class)->enforceLimit($user, 'team_members');

        $name = trim((string) ($payload['name'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'status' => 'error',
                'message' => 'Nom ou email invalide pour le membre.',
            ];
        }

        if (User::query()->where('email', $email)->exists()) {
            return [
                'status' => 'error',
                'message' => 'Cet email est deja utilise.',
            ];
        }

        $role = $this->normalizeTeamRole($payload['role'] ?? '');
        $permissions = $this->filterTeamPermissions(Arr::wrap($payload['permissions'] ?? []));
        if (!$permissions) {
            $permissions = $this->defaultTeamPermissions($role);
        }

        $roleId = Role::query()->where('name', 'employee')->value('id');
        if (!$roleId) {
            $roleId = Role::create([
                'name' => 'employee',
                'description' => 'Employee role',
            ])->id;
        }

        $memberUser = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(Str::random(32)),
            'role_id' => $roleId,
            'email_verified_at' => now(),
            'must_change_password' => true,
        ]);

        $teamMember = TeamMember::create([
            'account_id' => $user->id,
            'user_id' => $memberUser->id,
            'role' => $role,
            'title' => $payload['title'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'permissions' => $permissions,
            'is_active' => true,
        ]);

        $token = Password::broker()->createToken($memberUser);
        $memberUser->notify(new InviteUserNotification(
            $token,
            $user->company_name ?: config('app.name'),
            $user->company_logo_url,
            'team'
        ));

        return [
            'status' => 'created',
            'message' => 'Membre cree. Invitation envoyee par email.',
            'action' => [
                'type' => 'team_member_created',
                'team_member_id' => $teamMember->id,
                'user_id' => $memberUser->id,
            ],
            'context' => [
                'pending_action' => null,
            ],
        ];
    }

    private function executeSendQuote(array $payload, User $user): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $quoteId = $payload['quote_id'] ?? null;
        if (!$quoteId) {
            return [
                'status' => 'error',
                'message' => 'Devis manquant.',
            ];
        }

        $quote = Quote::byUserWithArchived($accountId)->with('customer')->find($quoteId);
        if (!$quote) {
            return [
                'status' => 'error',
                'message' => 'Devis introuvable.',
            ];
        }

        if ($quote->isArchived()) {
            return [
                'status' => 'not_allowed',
                'message' => 'Ce devis est archive.',
            ];
        }

        if (!$quote->customer || !$quote->customer->email) {
            return [
                'status' => 'error',
                'message' => 'Email client manquant.',
            ];
        }

        $quote->loadMissing(['customer.user', 'property', 'products', 'taxes.tax']);
        $quote->customer->notify(new SendQuoteNotification($quote));

        ActivityLog::record($user, $quote, 'email_sent', [
            'email' => $quote->customer->email,
            'assistant' => true,
        ], 'Quote email sent by assistant');

        if ($quote->status === 'draft') {
            $previousStatus = $quote->status;
            $quote->update(['status' => 'sent']);
            ActivityLog::record($user, $quote, 'status_changed', [
                'from' => $previousStatus,
                'to' => 'sent',
                'assistant' => true,
            ], 'Quote status updated by assistant');
        }

        return [
            'status' => 'created',
            'message' => 'Devis envoye a ' . $quote->customer->email . '.',
            'context' => [
                'pending_action' => null,
            ],
        ];
    }

    private function executeAcceptQuote(array $payload, User $user): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $quoteId = $payload['quote_id'] ?? null;
        if (!$quoteId) {
            return [
                'status' => 'error',
                'message' => 'Devis manquant.',
            ];
        }

        $quote = Quote::byUserWithArchived($accountId)->with(['products', 'customer'])->find($quoteId);
        if (!$quote) {
            return [
                'status' => 'error',
                'message' => 'Devis introuvable.',
            ];
        }

        if ($quote->isArchived()) {
            return [
                'status' => 'not_allowed',
                'message' => 'Ce devis est archive.',
            ];
        }

        if ($quote->status === 'declined') {
            return [
                'status' => 'not_allowed',
                'message' => 'Ce devis est refuse.',
            ];
        }

        if ($quote->status === 'accepted') {
            return [
                'status' => 'created',
                'message' => 'Devis deja accepte.',
                'action' => [
                    'type' => 'quote_accepted',
                    'quote_id' => $quote->id,
                ],
                'context' => [
                    'pending_action' => null,
                ],
            ];
        }

        $requiredDeposit = (float) ($quote->initial_deposit ?? 0);
        $depositAmount = (float) ($payload['deposit_amount'] ?? $requiredDeposit);
        if ($requiredDeposit > 0 && $depositAmount < $requiredDeposit) {
            return [
                'status' => 'error',
                'message' => 'Le depot est inferieur au depot requis.',
            ];
        }

        $existingWork = Work::where('quote_id', $quote->id)->first();
        if (!$existingWork) {
            app(UsageLimitService::class)->enforceLimit($user, 'jobs');
        }

        $work = null;
        DB::transaction(function () use ($quote, $payload, $depositAmount, $existingWork, &$work) {
            $work = $existingWork;
            if (!$work) {
                $work = Work::create([
                    'user_id' => $quote->user_id,
                    'customer_id' => $quote->customer_id,
                    'quote_id' => $quote->id,
                    'job_title' => $quote->job_title,
                    'instructions' => $quote->notes ?: ($quote->messages ?: ''),
                    'status' => Work::STATUS_TO_SCHEDULE,
                    'subtotal' => $quote->subtotal,
                    'total' => $quote->total,
                ]);
            } else {
                $work->update([
                    'job_title' => $quote->job_title,
                    'instructions' => $quote->notes ?: ($quote->messages ?: ''),
                    'subtotal' => $quote->subtotal,
                    'total' => $quote->total,
                ]);
            }

            $quote->update([
                'status' => 'accepted',
                'accepted_at' => now(),
                'signed_at' => $payload['signed_at'] ?? now(),
                'work_id' => $work->id,
            ]);

            if ($depositAmount > 0) {
                $hasDeposit = Transaction::where('quote_id', $quote->id)
                    ->where('type', 'deposit')
                    ->where('status', 'completed')
                    ->exists();

                if (!$hasDeposit) {
                    Transaction::create([
                        'quote_id' => $quote->id,
                        'work_id' => $work->id,
                        'customer_id' => $quote->customer_id,
                        'user_id' => $quote->user_id,
                        'amount' => $depositAmount,
                        'type' => 'deposit',
                        'method' => $payload['method'] ?? null,
                        'status' => 'completed',
                        'reference' => $payload['reference'] ?? null,
                        'paid_at' => now(),
                    ]);
                }
            }

            $this->syncWorkProductsFromQuote($quote, $work);
            $this->syncChecklistFromQuote($quote, $work);
        });

        return [
            'status' => 'created',
            'message' => 'Devis accepte et job cree.',
            'action' => [
                'type' => 'work_created',
                'work_id' => $work?->id,
            ],
            'context' => [
                'pending_action' => null,
            ],
        ];
    }

    private function executeConvertQuote(array $payload, User $user): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $quoteId = $payload['quote_id'] ?? null;
        if (!$quoteId) {
            return [
                'status' => 'error',
                'message' => 'Devis manquant.',
            ];
        }

        $quote = Quote::byUserWithArchived($accountId)->with(['products', 'customer'])->find($quoteId);
        if (!$quote) {
            return [
                'status' => 'error',
                'message' => 'Devis introuvable.',
            ];
        }

        if ($quote->isArchived()) {
            return [
                'status' => 'not_allowed',
                'message' => 'Ce devis est archive.',
            ];
        }

        $existingWork = Work::where('quote_id', $quote->id)->first();
        if ($existingWork) {
            return [
                'status' => 'created',
                'message' => 'Job deja cree pour ce devis.',
                'action' => [
                    'type' => 'work_created',
                    'work_id' => $existingWork->id,
                ],
                'context' => [
                    'pending_action' => null,
                ],
            ];
        }

        app(UsageLimitService::class)->enforceLimit($user, 'jobs');

        $work = DB::transaction(function () use ($quote) {
            $work = Work::create([
                'user_id' => $quote->user_id,
                'customer_id' => $quote->customer_id,
                'quote_id' => $quote->id,
                'job_title' => $quote->job_title,
                'instructions' => $quote->notes ?: ($quote->messages ?: ''),
                'start_date' => now()->toDateString(),
                'status' => Work::STATUS_TO_SCHEDULE,
                'subtotal' => $quote->subtotal,
                'total' => $quote->total,
            ]);

            $this->syncWorkProductsFromQuote($quote, $work);

            if (in_array($quote->status, ['draft', 'sent'], true)) {
                $quote->update([
                    'status' => 'accepted',
                    'accepted_at' => now(),
                    'work_id' => $work->id,
                ]);
            } elseif (!$quote->work_id) {
                $quote->update(['work_id' => $work->id]);
            }

            $this->syncChecklistFromQuote($quote, $work);

            return $work;
        });

        ActivityLog::record($user, $work, 'created', [
            'from_quote_id' => $quote->id,
            'total' => $work->total,
            'assistant' => true,
        ], 'Job created from quote by assistant');

        ActivityLog::record($user, $quote, 'converted', [
            'work_id' => $work->id,
            'assistant' => true,
        ], 'Quote converted to job by assistant');

        return [
            'status' => 'created',
            'message' => 'Job cree depuis le devis.',
            'action' => [
                'type' => 'work_created',
                'work_id' => $work->id,
            ],
            'context' => [
                'pending_action' => null,
            ],
        ];
    }

    private function executeMarkInvoicePaid(array $payload, User $user): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $invoiceId = $payload['invoice_id'] ?? null;
        if (!$invoiceId) {
            return [
                'status' => 'error',
                'message' => 'Facture manquante.',
            ];
        }

        $invoice = Invoice::byUser($accountId)->with('work')->find($invoiceId);
        if (!$invoice) {
            return [
                'status' => 'error',
                'message' => 'Facture introuvable.',
            ];
        }

        if ($invoice->status === 'paid') {
            return [
                'status' => 'created',
                'message' => 'Facture deja payee.',
                'context' => [
                    'pending_action' => null,
                ],
            ];
        }

        $amount = (float) ($payload['amount'] ?? $invoice->balance_due);
        if ($amount <= 0) {
            return [
                'status' => 'error',
                'message' => 'Montant de paiement invalide.',
            ];
        }

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'user_id' => $user->id,
            'amount' => $amount,
            'method' => $payload['method'] ?? null,
            'status' => 'completed',
            'reference' => $payload['reference'] ?? null,
            'notes' => 'Assistant payment',
            'paid_at' => now(),
        ]);

        $previousStatus = $invoice->status;
        $invoice->refreshPaymentStatus();

        ActivityLog::record($user, $payment, 'created', [
            'invoice_id' => $invoice->id,
            'amount' => $payment->amount,
            'assistant' => true,
        ], 'Payment recorded by assistant');

        if ($previousStatus !== $invoice->status) {
            ActivityLog::record($user, $invoice, 'status_changed', [
                'from' => $previousStatus,
                'to' => $invoice->status,
                'assistant' => true,
            ], 'Invoice status updated by assistant');
        }

        if ($invoice->status === 'paid' && $invoice->work) {
            $invoice->work->status = Work::STATUS_CLOSED;
            $invoice->work->save();
        }

        return [
            'status' => 'created',
            'message' => 'Paiement enregistre.',
            'context' => [
                'pending_action' => null,
            ],
        ];
    }

    private function executeUpdateWorkStatus(array $payload, User $user): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $workId = $payload['work_id'] ?? null;
        $status = $payload['status'] ?? null;
        if (!$workId || !$status) {
            return [
                'status' => 'error',
                'message' => 'Job ou statut manquant.',
            ];
        }

        $work = Work::byUser($accountId)->with(['media', 'checklistItems', 'customer'])->find($workId);
        if (!$work) {
            return [
                'status' => 'error',
                'message' => 'Job introuvable.',
            ];
        }

        if (!in_array($status, Work::STATUSES, true)) {
            return [
                'status' => 'error',
                'message' => 'Statut invalide.',
            ];
        }

        $validation = $this->applyWorkStatus($work, $status, $user);
        if ($validation['status'] !== 'updated') {
            return $validation;
        }

        return [
            'status' => 'created',
            'message' => 'Statut du job mis a jour.',
            'action' => [
                'type' => 'work_updated',
                'work_id' => $work->id,
            ],
            'context' => [
                'pending_action' => null,
            ],
        ];
    }

    private function mergeCustomerDraft(array $base, array $updates): array
    {
        $updates = is_array($updates) ? $updates : [];
        $merged = $base;
        foreach (['name', 'first_name', 'last_name', 'company_name', 'email', 'phone'] as $key) {
            $value = $updates[$key] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '' || $value === null) {
                continue;
            }
            $merged[$key] = $value;
        }

        if (($merged['name'] ?? '') !== '' && (($merged['first_name'] ?? '') === '' || ($merged['last_name'] ?? '') === '')) {
            $parts = preg_split('/\s+/', $merged['name'], 2);
            $merged['first_name'] = $merged['first_name'] ?: ($parts[0] ?? '');
            $merged['last_name'] = $merged['last_name'] ?: ($parts[1] ?? '');
        }

        foreach (['name', 'first_name', 'last_name', 'company_name', 'email', 'phone'] as $key) {
            if (!isset($merged[$key])) {
                $merged[$key] = '';
            }
        }

        return $merged;
    }

    private function mergePropertyDraft(array $base, array $updates): array
    {
        $updates = is_array($updates) ? $updates : [];
        $merged = $base;
        foreach (['type', 'street1', 'street2', 'city', 'state', 'zip', 'country'] as $key) {
            $value = $updates[$key] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '' || $value === null) {
                continue;
            }
            $merged[$key] = $value;
        }

        if (array_key_exists('is_default', $updates)) {
            $merged['is_default'] = (bool) $updates['is_default'];
        }

        $customerDraft = is_array($updates['customer'] ?? null) ? $updates['customer'] : [];
        $merged['customer'] = $this->mergeCustomerDraft($merged['customer'] ?? [], $customerDraft);

        return $merged;
    }

    private function mergeCategoryDraft(array $base, array $updates): array
    {
        $updates = is_array($updates) ? $updates : [];
        $merged = $base;
        foreach (['name', 'item_type'] as $key) {
            $value = $updates[$key] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '' || $value === null) {
                continue;
            }
            $merged[$key] = $value;
        }

        return $merged;
    }

    private function mergeProductDraft(array $base, array $updates): array
    {
        $updates = is_array($updates) ? $updates : [];
        $merged = $base;
        foreach (['name', 'item_type', 'category', 'unit', 'description'] as $key) {
            $value = $updates[$key] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '' || $value === null) {
                continue;
            }
            $merged[$key] = $value;
        }

        if (array_key_exists('price', $updates) && $updates['price'] !== null && $updates['price'] !== '') {
            $merged['price'] = (float) $updates['price'];
        }

        return $merged;
    }

    private function mergeTeamMemberDraft(array $base, array $updates): array
    {
        $updates = is_array($updates) ? $updates : [];
        $merged = $base;
        foreach (['name', 'email', 'role', 'title', 'phone'] as $key) {
            $value = $updates[$key] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '' || $value === null) {
                continue;
            }
            $merged[$key] = $value;
        }

        if (array_key_exists('permissions', $updates)) {
            $basePermissions = Arr::wrap($merged['permissions'] ?? []);
            $updatePermissions = Arr::wrap($updates['permissions']);
            $permissions = array_filter(array_map(function ($permission) {
                return is_string($permission) ? trim($permission) : '';
            }, array_merge($basePermissions, $updatePermissions)));
            $merged['permissions'] = array_values(array_unique($permissions));
        }

        return $merged;
    }

    private function applyAnswerToCustomerDraft(array $draft, array $context): array
    {
        [$answer, $questions] = $this->extractAnswerAndQuestions($context);
        if ($answer === '' || !$questions) {
            return $draft;
        }

        $normalized = $this->normalizeAnswerValue($answer);
        $email = filter_var($answer, FILTER_VALIDATE_EMAIL) ? $answer : null;

        foreach ($questions as $question) {
            $lower = strtolower($question);
            if (str_contains($lower, 'email') && ($draft['email'] ?? '') === '' && $email) {
                $draft['email'] = $email;
                continue;
            }

            if (str_contains($lower, 'prenom') && ($draft['first_name'] ?? '') === '') {
                $draft['first_name'] = $normalized;
                continue;
            }

            if (str_contains($lower, 'nom') && !str_contains($lower, 'prenom') && ($draft['last_name'] ?? '') === '') {
                $draft['last_name'] = $normalized;
            }
        }

        if (($draft['first_name'] ?? '') === '' && ($draft['last_name'] ?? '') === '' && count($questions) === 1) {
            $parts = preg_split('/\s+/', $normalized, 2);
            if (count($parts) === 2) {
                $draft['first_name'] = $parts[0];
                $draft['last_name'] = $parts[1];
            }
        }

        return $draft;
    }

    private function applyAnswerToTeamMemberDraft(array $draft, array $context): array
    {
        [$answer, $questions] = $this->extractAnswerAndQuestions($context);
        if ($answer === '' || !$questions) {
            return $draft;
        }

        $normalized = $this->normalizeAnswerValue($answer);
        $email = filter_var($answer, FILTER_VALIDATE_EMAIL) ? strtolower($answer) : null;

        foreach ($questions as $question) {
            $lower = strtolower($question);
            if (str_contains($lower, 'email') && ($draft['email'] ?? '') === '' && $email) {
                $draft['email'] = $email;
                continue;
            }

            if (str_contains($lower, 'nom') && ($draft['name'] ?? '') === '') {
                $draft['name'] = $normalized;
            }
        }

        return $draft;
    }

    private function applyAnswerToPropertyDraft(array $draft, array $context): array
    {
        [$answer, $questions] = $this->extractAnswerAndQuestions($context);
        if ($answer === '' || !$questions) {
            return $draft;
        }

        $normalized = $this->normalizeAnswerValue($answer);
        $draft['customer'] = $this->applyAnswerToCustomerDraft($draft['customer'] ?? [], $context);

        foreach ($questions as $question) {
            $lower = strtolower($question);
            if (str_contains($lower, 'ville') && ($draft['city'] ?? '') === '') {
                $draft['city'] = $normalized;
                continue;
            }

            if ((str_contains($lower, 'adresse') || str_contains($lower, 'address') || str_contains($lower, 'rue'))
                && ($draft['street1'] ?? '') === '') {
                $draft['street1'] = $normalized;
                continue;
            }

            if ((str_contains($lower, 'code postal') || str_contains($lower, 'zip'))
                && ($draft['zip'] ?? '') === '') {
                $draft['zip'] = $normalized;
                continue;
            }

            if ((str_contains($lower, 'province') || str_contains($lower, 'etat') || str_contains($lower, 'state'))
                && ($draft['state'] ?? '') === '') {
                $draft['state'] = $normalized;
                continue;
            }

            if (str_contains($lower, 'pays') && ($draft['country'] ?? '') === '') {
                $draft['country'] = $normalized;
            }
        }

        return $draft;
    }

    private function extractAnswerAndQuestions(array $context): array
    {
        $answer = trim((string) ($context['last_message'] ?? ''));
        $questions = $context['questions'] ?? [];
        if (!is_array($questions)) {
            $questions = [];
        }

        $questions = array_values(array_filter(array_map(function ($question) {
            return is_string($question) ? trim($question) : '';
        }, $questions)));

        return [$answer, $questions];
    }

    private function normalizeAnswerValue(string $answer): string
    {
        $trimmed = trim($answer);
        $trimmed = preg_replace('/^(ville|city|adresse|address|code postal|zip|state|province)\s*[:=-]?\s*/i', '', $trimmed);

        return trim((string) $trimmed);
    }

    private function mergeWorkDraft(array $base, array $updates): array
    {
        $updates = is_array($updates) ? $updates : [];
        $merged = $base;
        foreach (['job_title', 'instructions', 'start_date', 'end_date', 'start_time', 'end_time', 'status', 'type', 'category'] as $key) {
            $value = $updates[$key] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '' || $value === null) {
                continue;
            }
            $merged[$key] = $value;
        }

        $customerDraft = is_array($updates['customer'] ?? null) ? $updates['customer'] : [];
        $merged['customer'] = $this->mergeCustomerDraft($merged['customer'] ?? [], $customerDraft);

        $baseItems = is_array($merged['items'] ?? null) ? $merged['items'] : [];
        $updateItems = is_array($updates['items'] ?? null) ? $updates['items'] : [];
        $merged['items'] = $this->mergeItems($baseItems, $updateItems);

        return $merged;
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
            $price = $item['price'];
            $price = $price === null ? null : (float) $price;
            $itemType = strtolower((string) ($item['item_type'] ?? ''));
            $unit = trim((string) ($item['unit'] ?? ''));

            $normalized[] = [
                'name' => $name,
                'quantity' => $quantity,
                'price' => $price,
                'item_type' => $itemType,
                'unit' => $unit,
            ];
        }

        return $normalized;
    }

    private function resolveWorkItem(int $accountId, User $user, array $item): array
    {
        $questions = [];
        $name = trim((string) ($item['name'] ?? ''));
        $itemType = strtolower((string) ($item['item_type'] ?? ''));
        $itemType = $itemType === 'product' ? 'product' : ($itemType === 'service' ? 'service' : $this->normalizeItemType('', $user));

        $product = Product::byUser($accountId)
            ->where('item_type', $itemType)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        if ($product && $item['price'] === null) {
            $item['price'] = (float) $product->price;
        }

        if ($product && ($item['unit'] ?? '') === '' && $product->unit) {
            $item['unit'] = $product->unit;
        }

        if (!$product && $item['price'] === null) {
            $questions[] = 'Quel est le prix pour "' . $name . '" ?';
        }

        $item['item_type'] = $itemType;

        return [
            'item' => $item,
            'questions' => $questions,
        ];
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
            $itemType = $itemType === 'product' ? 'product' : ($itemType === 'service' ? 'service' : $this->normalizeItemType('', $user));

            $product = Product::byUser($accountId)
                ->where('item_type', $itemType)
                ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                ->first();

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

    private function buildTeamMemberSummary(string $name, string $email, string $role, array $permissions): string
    {
        $summary = [];
        $summary[] = 'Resume du membre:';
        $summary[] = 'Nom: ' . ($name !== '' ? $name : 'Membre');
        $summary[] = 'Email: ' . $email;
        $summary[] = 'Role: ' . $role;
        if ($permissions) {
            $summary[] = 'Permissions: ' . implode(', ', $permissions);
        }

        return implode("\n", $summary);
    }

    private function buildWorkSummary(?Customer $customer, array $draftCustomer, array $draft, array $items, string $status): string
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
        $summary[] = 'Resume du job:';
        $summary[] = 'Client: ' . ($label ?: 'Client');
        $summary[] = 'Titre: ' . ($draft['job_title'] ?? 'Job');

        $dateLine = 'Date: ' . ($draft['start_date'] ?? '');
        $startTime = trim((string) ($draft['start_time'] ?? ''));
        $endTime = trim((string) ($draft['end_time'] ?? ''));
        if ($startTime !== '' || $endTime !== '') {
            $dateLine .= ' ' . trim($startTime . ' - ' . $endTime);
        }
        $summary[] = $dateLine;
        $summary[] = 'Statut: ' . $status;

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
        }

        if ($items) {
            $summary[] = 'Total estime: ' . $this->formatMoney($subtotal);
        }

        return implode("\n", $summary);
    }

    private function buildInvoiceSummary(Work $work): string
    {
        $work->loadMissing('customer');
        $label = $this->formatCustomerLabel(
            $work->customer?->first_name ?? '',
            $work->customer?->last_name ?? '',
            $work->customer?->company_name ?? '',
            $work->customer?->email ?? ''
        );

        $summary = [];
        $summary[] = 'Resume de la facture:';
        $summary[] = 'Job: ' . ($work->job_title ?: ($work->number ?? $work->id));
        if ($label !== '') {
            $summary[] = 'Client: ' . $label;
        }
        $summary[] = 'Montant base: ' . $this->formatMoney((float) ($work->total ?? 0));
        $summary[] = 'La facture sera creee et envoyee au client.';

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

    private function resolveCustomer(int $accountId, array $draft): ?Customer
    {
        $email = trim((string) ($draft['email'] ?? ''));
        $companyName = trim((string) ($draft['company_name'] ?? ''));
        $firstName = trim((string) ($draft['first_name'] ?? ''));
        $lastName = trim((string) ($draft['last_name'] ?? ''));

        if ($email !== '') {
            return Customer::byUser($accountId)->where('email', $email)->first();
        }

        if ($companyName !== '') {
            return Customer::byUser($accountId)
                ->whereRaw('LOWER(company_name) = ?', [strtolower($companyName)])
                ->first();
        }

        if ($firstName !== '' && $lastName !== '') {
            return Customer::byUser($accountId)
                ->whereRaw('LOWER(first_name) = ?', [strtolower($firstName)])
                ->whereRaw('LOWER(last_name) = ?', [strtolower($lastName)])
                ->first();
        }

        return null;
    }

    private function resolveQuote(int $accountId, array $context, array $targets): ?Quote
    {
        $current = $context['current_quote'] ?? null;
        if (is_array($current)) {
            $id = $current['id'] ?? null;
            if ($id) {
                return Quote::byUserWithArchived($accountId)->whereKey($id)->first();
            }

            $number = trim((string) ($current['number'] ?? ''));
            if ($number !== '') {
                return Quote::byUserWithArchived($accountId)->where('number', $number)->first();
            }
        }

        if (is_numeric($current)) {
            return Quote::byUserWithArchived($accountId)->whereKey((int) $current)->first();
        }

        if (!empty($targets['quote_id'])) {
            return Quote::byUserWithArchived($accountId)->whereKey((int) $targets['quote_id'])->first();
        }

        if (!empty($targets['quote_number'])) {
            return Quote::byUserWithArchived($accountId)->where('number', $targets['quote_number'])->first();
        }

        return null;
    }

    private function resolveWork(int $accountId, array $context, array $targets): ?Work
    {
        $current = $context['current_work'] ?? null;
        if (is_array($current)) {
            $id = $current['id'] ?? null;
            if ($id) {
                return Work::byUser($accountId)->whereKey($id)->first();
            }

            $number = trim((string) ($current['number'] ?? ''));
            if ($number !== '') {
                return Work::byUser($accountId)->where('number', $number)->first();
            }
        }

        if (is_numeric($current)) {
            return Work::byUser($accountId)->whereKey((int) $current)->first();
        }

        $currentQuote = $context['current_quote'] ?? null;
        if (is_array($currentQuote)) {
            $workId = $currentQuote['work_id'] ?? null;
            if ($workId) {
                $work = Work::byUser($accountId)->whereKey((int) $workId)->first();
                if ($work) {
                    return $work;
                }
            }

            $quoteId = $currentQuote['id'] ?? null;
            if ($quoteId) {
                $work = Work::byUser($accountId)->where('quote_id', (int) $quoteId)->first();
                if ($work) {
                    return $work;
                }
            }
        }

        if (!empty($targets['work_id'])) {
            return Work::byUser($accountId)->whereKey((int) $targets['work_id'])->first();
        }

        if (!empty($targets['work_number'])) {
            return Work::byUser($accountId)->where('number', $targets['work_number'])->first();
        }

        if (!empty($targets['quote_id'])) {
            return Work::byUser($accountId)->where('quote_id', (int) $targets['quote_id'])->first();
        }

        return null;
    }

    private function resolveInvoice(int $accountId, array $context, array $targets): ?Invoice
    {
        $current = $context['current_invoice'] ?? null;
        if (is_array($current)) {
            $id = $current['id'] ?? null;
            if ($id) {
                return Invoice::byUser($accountId)->whereKey($id)->first();
            }

            $number = trim((string) ($current['number'] ?? ''));
            if ($number !== '') {
                return Invoice::byUser($accountId)->where('number', $number)->first();
            }
        }

        if (is_numeric($current)) {
            return Invoice::byUser($accountId)->whereKey((int) $current)->first();
        }

        $currentWork = $context['current_work'] ?? null;
        if (is_array($currentWork)) {
            $workId = $currentWork['id'] ?? null;
            if ($workId) {
                $invoice = Invoice::byUser($accountId)->where('work_id', (int) $workId)->first();
                if ($invoice) {
                    return $invoice;
                }
            }
        }

        if (!empty($targets['invoice_id'])) {
            return Invoice::byUser($accountId)->whereKey((int) $targets['invoice_id'])->first();
        }

        if (!empty($targets['invoice_number'])) {
            return Invoice::byUser($accountId)->where('number', $targets['invoice_number'])->first();
        }

        if (!empty($targets['work_id'])) {
            return Invoice::byUser($accountId)->where('work_id', (int) $targets['work_id'])->first();
        }

        return null;
    }

    private function normalizeWorkStatus(string $status): ?string
    {
        $value = strtolower(trim($status));
        if ($value === '') {
            return null;
        }

        $map = [
            'scheduled' => Work::STATUS_SCHEDULED,
            'planifie' => Work::STATUS_SCHEDULED,
            'to_schedule' => Work::STATUS_TO_SCHEDULE,
            'a_planifier' => Work::STATUS_TO_SCHEDULE,
            'en_route' => Work::STATUS_EN_ROUTE,
            'in_progress' => Work::STATUS_IN_PROGRESS,
            'tech_complete' => Work::STATUS_TECH_COMPLETE,
            'pending_review' => Work::STATUS_PENDING_REVIEW,
            'validated' => Work::STATUS_VALIDATED,
            'auto_validated' => Work::STATUS_AUTO_VALIDATED,
            'dispute' => Work::STATUS_DISPUTE,
            'closed' => Work::STATUS_CLOSED,
            'cancelled' => Work::STATUS_CANCELLED,
            'completed' => Work::STATUS_COMPLETED,
        ];

        if (isset($map[$value])) {
            return $map[$value];
        }

        if (in_array($value, Work::STATUSES, true)) {
            return $value;
        }

        return null;
    }

    private function isValidDate(string $value): bool
    {
        try {
            Carbon::parse($value);
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    private function parseDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function parseTime(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('H:i:s');
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function createCustomer(int $accountId, array $draft): Customer
    {
        return Customer::create([
            'user_id' => $accountId,
            'first_name' => trim((string) ($draft['first_name'] ?? '')),
            'last_name' => trim((string) ($draft['last_name'] ?? '')),
            'company_name' => trim((string) ($draft['company_name'] ?? '')) ?: null,
            'email' => trim((string) ($draft['email'] ?? '')),
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

    private function normalizeItemType(string $value, User $user): string
    {
        $value = strtolower(trim($value));
        if ($value === 'service' || $value === 'services') {
            return 'service';
        }
        if ($value === 'product' || $value === 'produit' || $value === 'products' || $value === 'produits') {
            return 'product';
        }

        return $user->company_type === 'products' ? 'product' : 'service';
    }

    private function normalizeTeamRole(?string $role): string
    {
        $value = strtolower(trim((string) $role));
        if ($value === 'admin') {
            return 'admin';
        }

        if (in_array($value, ['seller', 'sales', 'vendeur'], true)) {
            return 'seller';
        }

        return 'member';
    }

    private function resolveTeamPermissions(array $draft, array $context): array
    {
        $permissions = Arr::wrap($draft['permissions'] ?? []);
        $resolved = [];

        foreach ($permissions as $permission) {
            $permission = strtolower(trim((string) $permission));
            if ($permission === '') {
                continue;
            }

            if (in_array($permission, self::TEAM_PERMISSION_KEYS, true)) {
                $resolved[] = $permission;
                continue;
            }

            if (in_array($permission, ['quote_write', 'quotes.write', 'quote_edit', 'devis_ecriture'], true)) {
                $resolved = array_merge($resolved, ['quotes.view', 'quotes.create', 'quotes.edit']);
                continue;
            }

            if (in_array($permission, ['quote_view', 'quotes.view', 'devis_view'], true)) {
                $resolved[] = 'quotes.view';
                continue;
            }

            if (in_array($permission, ['quote_send', 'quotes.send', 'devis_envoyer'], true)) {
                $resolved[] = 'quotes.send';
            }
        }

        if (!$resolved) {
            $message = strtolower(trim((string) ($context['last_message'] ?? '')));
            if ($message !== '') {
                $hasQuotes = str_contains($message, 'devis') || str_contains($message, 'quote');
                $hasWrite = preg_match('/\b(ecrit|ecriture|ecritures|write|edit|modifier|creation|creer|create)\b/', $message) === 1;
                $hasSend = preg_match('/\b(envoy|send|email)\b/', $message) === 1;
                $hasView = preg_match('/\b(voir|view|lire)\b/', $message) === 1;

                if ($hasQuotes) {
                    $resolved[] = 'quotes.view';
                    if ($hasWrite) {
                        $resolved[] = 'quotes.create';
                        $resolved[] = 'quotes.edit';
                    } elseif ($hasView) {
                        $resolved[] = 'quotes.view';
                    }

                    if ($hasSend) {
                        $resolved[] = 'quotes.send';
                    }
                }
            }
        }

        return $this->filterTeamPermissions($resolved);
    }

    private function filterTeamPermissions(array $permissions): array
    {
        $filtered = [];
        foreach ($permissions as $permission) {
            if (in_array($permission, self::TEAM_PERMISSION_KEYS, true)) {
                $filtered[] = $permission;
            }
        }

        return array_values(array_unique($filtered));
    }

    private function defaultTeamPermissions(string $role): array
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
                'sales.manage',
            ],
            'seller' => [
                'sales.pos',
            ],
            default => [
                'jobs.view',
                'tasks.view',
                'tasks.edit',
            ],
        };
    }

    private function needsConfirmation(string $summary, array $pendingAction): array
    {
        return [
            'status' => 'needs_confirmation',
            'message' => $summary . "\nConfirmer ? (oui/non)",
            'context' => [
                'pending_action' => $pendingAction,
            ],
        ];
    }

    private function applyWorkStatus(Work $work, string $nextStatus, User $actor): array
    {
        $beforeCount = $work->media()->where('type', 'before')->count();
        $afterCount = $work->media()->where('type', 'after')->count();

        if ($nextStatus === Work::STATUS_IN_PROGRESS && $beforeCount < 3) {
            return [
                'status' => 'error',
                'message' => 'Ajoutez au moins 3 photos avant de demarrer le job.',
            ];
        }

        if ($nextStatus === Work::STATUS_TECH_COMPLETE) {
            $pendingChecklist = $work->checklistItems()->where('status', '!=', 'done')->count();
            if ($pendingChecklist > 0) {
                return [
                    'status' => 'error',
                    'message' => 'Terminez tous les elements de checklist avant de finir le job.',
                ];
            }

            if ($afterCount < 3) {
                return [
                    'status' => 'error',
                    'message' => 'Ajoutez au moins 3 photos apres avant de finir le job.',
                ];
            }
        }

        $autoValidateJobs = (bool) ($work->customer?->auto_validate_jobs ?? false);
        if ($autoValidateJobs && in_array($nextStatus, [Work::STATUS_TECH_COMPLETE, Work::STATUS_PENDING_REVIEW], true)) {
            $nextStatus = Work::STATUS_AUTO_VALIDATED;
        }

        $previousStatus = $work->status;
        $work->status = $nextStatus;
        $work->save();

        ActivityLog::record($actor, $work, 'status_changed', [
            'from' => $previousStatus,
            'to' => $nextStatus,
            'assistant' => true,
        ], 'Job status updated by assistant');

        if (in_array($nextStatus, [Work::STATUS_VALIDATED, Work::STATUS_AUTO_VALIDATED], true)) {
            $billingResolver = app(TaskBillingService::class);
            if ($billingResolver->shouldInvoiceOnWorkValidation($work)) {
                app(WorkBillingService::class)->createInvoiceFromWork($work, $actor);
            }
        }

        return [
            'status' => 'updated',
        ];
    }

    private function autoScheduleTasksForWork(Work $work, User $actor): void
    {
        if ($work->status !== Work::STATUS_SCHEDULED) {
            return;
        }

        $scheduleService = app(WorkScheduleService::class);
        $pendingDates = $scheduleService->pendingDateStrings($work);
        if (!$pendingDates) {
            return;
        }

        app(UsageLimitService::class)->enforceLimit($actor, 'tasks', count($pendingDates));
        $scheduleService->generateTasksForDates($work, $pendingDates, $actor->id);
    }

    private function syncWorkProductsFromQuote(Quote $quote, Work $work): void
    {
        $quote->loadMissing('products');

        $pivotData = $quote->products->mapWithKeys(function ($product) use ($quote) {
            return [
                $product->id => [
                    'quote_id' => $quote->id,
                    'quantity' => (int) $product->pivot->quantity,
                    'price' => (float) $product->pivot->price,
                    'description' => $product->pivot->description,
                    'total' => (float) $product->pivot->total,
                ],
            ];
        });

        $work->products()->sync($pivotData->toArray());
    }

    private function syncChecklistFromQuote(Quote $quote, Work $work): void
    {
        $items = QuoteProduct::query()
            ->where('quote_id', $quote->id)
            ->with('product')
            ->orderBy('id')
            ->get();

        foreach ($items as $index => $item) {
            WorkChecklistItem::firstOrCreate(
                [
                    'work_id' => $work->id,
                    'quote_product_id' => $item->id,
                ],
                [
                    'quote_id' => $quote->id,
                    'title' => $item->product?->name ?? 'Line item',
                    'description' => $item->description ?: $item->product?->description,
                    'status' => 'pending',
                    'sort_order' => $index,
                ]
            );
        }
    }

    private function needsInput(string $intent, array $draft, array $questions): array
    {
        return [
            'status' => 'needs_input',
            'message' => 'J ai besoin de quelques infos pour continuer.',
            'questions' => array_values(array_unique($questions)),
            'context' => [
                'intent' => $intent,
                'draft' => $draft,
                'questions' => array_values(array_unique($questions)),
            ],
        ];
    }
}
