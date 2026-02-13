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
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskMaterial;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkChecklistItem;
use App\Models\Transaction;
use App\Notifications\ActionEmailNotification;
use App\Notifications\SendQuoteNotification;
use App\Notifications\InviteUserNotification;
use App\Support\NotificationDispatcher;
use App\Services\InventoryService;
use App\Services\TaskBillingService;
use App\Services\TaskStatusHistoryService;
use App\Services\TaskTimingService;
use App\Services\TemplateService;
use App\Services\UsageLimitService;
use App\Services\WorkBillingService;
use App\Services\WorkScheduleService;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AssistantWorkflowService
{
    private const MATCH_CONFIDENT_THRESHOLD = 0.84;
    private const MATCH_MIN_THRESHOLD = 0.68;

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
            'read_notifications' => $this->handleReadNotifications($interpretation, $user),
            'list_quotes' => $this->handleListQuotes($interpretation, $user, $context),
            'list_works' => $this->handleListWorks($interpretation, $user, $context),
            'list_invoices' => $this->handleListInvoices($interpretation, $user, $context),
            'list_customers' => $this->handleListCustomers($interpretation, $user, $context),
            'show_quote' => $this->handleShowQuote($interpretation, $user, $context),
            'show_work' => $this->handleShowWork($interpretation, $user, $context),
            'show_invoice' => $this->handleShowInvoice($interpretation, $user, $context),
            'show_customer' => $this->handleShowCustomer($interpretation, $user, $context),
            'create_task' => $this->handleCreateTask($interpretation, $user, $context),
            'update_task_status' => $this->handleUpdateTaskStatus($interpretation, $user, $context),
            'assign_task' => $this->handleAssignTask($interpretation, $user, $context),
            'update_checklist_item' => $this->handleUpdateChecklistItem($interpretation, $user, $context),
            'create_request' => $this->handleCreateRequest($interpretation, $user, $context),
            'convert_request' => $this->handleConvertRequest($interpretation, $user, $context),
            'send_invoice' => $this->handleSendInvoice($interpretation, $user, $context),
            'remind_invoice' => $this->handleRemindInvoice($interpretation, $user, $context),
            'schedule_work' => $this->handleScheduleWork($interpretation, $user, $context),
            'assign_work_team' => $this->handleAssignWorkTeam($interpretation, $user, $context),
            default => [
                'status' => 'unknown',
                'message' => 'Je peux creer et gerer des devis, factures et jobs, creer des clients/proprietes/categories/produits/services/membres, lire des listes et details, gerer des tasks/checklists, creer/convertir des requests, envoyer/relancer des factures et lire les notifications.',
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
            'update_task_status' => $this->executeUpdateTaskStatus($pendingAction['payload'] ?? [], $user),
            'assign_task' => $this->executeAssignTask($pendingAction['payload'] ?? [], $user),
            'convert_request' => $this->executeConvertRequest($pendingAction['payload'] ?? [], $user),
            'send_invoice' => $this->executeSendInvoice($pendingAction['payload'] ?? [], $user),
            'remind_invoice' => $this->executeRemindInvoice($pendingAction['payload'] ?? [], $user),
            'schedule_work' => $this->executeScheduleWork($pendingAction['payload'] ?? [], $user),
            'assign_work_team' => $this->executeAssignWorkTeam($pendingAction['payload'] ?? [], $user),
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

    private function handleReadNotifications(array $interpretation, User $user): array
    {
        $limit = $this->resolveListLimit($interpretation['filters'] ?? []);
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
        $lines[] = 'Vous avez ' . $unreadCount . ' notification(s) non lue(s).';
        $lines[] = 'Dernieres notifications:';

        foreach ($notifications as $notification) {
            $title = (string) ($notification->data['title'] ?? 'Notification');
            $message = (string) ($notification->data['message'] ?? '');
            $dateLabel = $notification->created_at ? $notification->created_at->toDateString() : '';

            $line = '- ' . $title;
            if ($message !== '') {
                $line .= ' - ' . $message;
            }
            if ($dateLabel !== '') {
                $line .= ' (' . $dateLabel . ')';
            }
            $lines[] = $line;
        }

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    private function handleListQuotes(array $interpretation, User $user, array $context): array
    {
        if (!Gate::forUser($user)->allows('viewAny', Quote::class)) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse aux devis.',
            ];
        }

        $accountId = $user->accountOwnerId() ?? $user->id;
        $filters = $this->normalizeListFilters($interpretation);
        $status = $this->normalizeQuoteStatus($interpretation['quote']['status'] ?? $filters['status']);
        $archivedOnly = $status === 'archived';
        if ($status && $status !== 'archived') {
            $filters['status'] = $status;
        }

        $customer = $this->resolveCustomer($accountId, $interpretation['customer'] ?? []);
        if ($customer) {
            $filters['customer_id'] = $customer->id;
        }

        $limit = $this->resolveListLimit($interpretation['filters'] ?? []);
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
            $line = '- ' . $label . ' | ' . $quote->status;
            $line .= ' | ' . $this->formatMoney((float) ($quote->total ?? 0));
            if ($customerLabel !== '') {
                $line .= ' | ' . $customerLabel;
            }
            if ($quote->job_title) {
                $line .= ' | ' . $quote->job_title;
            }
            $lines[] = $line;
        }

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    private function handleListWorks(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $membership = $user->id !== $accountId
            ? TeamMember::query()->forAccount($accountId)->active()->where('user_id', $user->id)->first()
            : null;

        if ($user->id !== $accountId) {
            if (!$membership || (!$membership->hasPermission('jobs.view') && !$membership->hasPermission('jobs.edit'))) {
                return [
                    'status' => 'not_allowed',
                    'message' => 'Acces refuse aux jobs.',
                ];
            }
        }

        $filters = $this->normalizeListFilters($interpretation);
        $status = $this->normalizeWorkStatus($interpretation['work']['status'] ?? $filters['status']);
        if ($status) {
            $filters['status'] = $status;
        }

        $customer = $this->resolveCustomer($accountId, $interpretation['customer'] ?? []);
        if ($customer) {
            $filters['customer_id'] = $customer->id;
        }

        $limit = $this->resolveListLimit($interpretation['filters'] ?? []);
        $query = Work::byUser($accountId)->filter($filters);
        if ($membership) {
            $query->whereHas('teamMembers', fn($sub) => $sub->whereKey($membership->id));
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
            $line = '- ' . $label . ' | ' . $work->status;
            if ($dateLabel !== '') {
                $line .= ' | ' . $dateLabel;
            }
            if ($customerLabel !== '') {
                $line .= ' | ' . $customerLabel;
            }
            if ($work->job_title) {
                $line .= ' | ' . $work->job_title;
            }
            $lines[] = $line;
        }

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    private function handleListInvoices(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        if ($user->id !== $accountId) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse aux factures.',
            ];
        }

        $filters = $this->normalizeListFilters($interpretation);
        $status = $this->normalizeInvoiceStatus($interpretation['invoice']['status'] ?? $filters['status']);
        if ($status) {
            $filters['status'] = $status;
        }

        $customer = $this->resolveCustomer($accountId, $interpretation['customer'] ?? []);
        if ($customer) {
            $filters['customer_id'] = $customer->id;
        }

        $limit = $this->resolveListLimit($interpretation['filters'] ?? []);
        $invoices = Invoice::byUser($accountId)
            ->filter($filters)
            ->with('customer')
            ->withSum(['payments as payments_sum_amount' => fn($query) => $query->whereIn('status', Payment::settledStatuses())], 'amount')
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

            $line = '- ' . $label . ' | ' . $invoice->status;
            $line .= ' | total ' . $this->formatMoney($total);
            $line .= ' | reste ' . $this->formatMoney($balance);
            if ($customerLabel !== '') {
                $line .= ' | ' . $customerLabel;
            }
            $lines[] = $line;
        }

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    private function handleListCustomers(array $interpretation, User $user, array $context): array
    {
        [, $accountId] = $this->resolveCustomerAccount($user);
        if (!$accountId) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse aux clients.',
            ];
        }

        $filters = $this->normalizeListFilters($interpretation);
        if ($filters['search'] !== '') {
            $filters['name'] = $filters['search'];
        }

        $customerFilter = $interpretation['customer'] ?? [];
        if (($filters['name'] ?? '') === '' && is_array($customerFilter)) {
            $name = trim((string) ($customerFilter['company_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($customerFilter['first_name'] ?? '') . ' ' . (string) ($customerFilter['last_name'] ?? ''));
            }
            if ($name !== '') {
                $filters['name'] = $name;
            }
        }

        $limit = $this->resolveListLimit($interpretation['filters'] ?? []);
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
            $line = '- ' . $label;
            if ($customerLabel !== '') {
                $line .= ' | ' . $customerLabel;
            }
            if ($customer->phone) {
                $line .= ' | ' . $customer->phone;
            }
            $lines[] = $line;
        }

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    private function handleShowQuote(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $quote = $this->resolveQuote($accountId, $context, $targets);

        if (!$quote) {
            return $this->needsInput('show_quote', [], [
                'Quel devis souhaitez-vous consulter ?',
            ]);
        }

        if (!Gate::forUser($user)->allows('show', $quote)) {
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
        $lines[] = 'Devis ' . $label . ':';
        $lines[] = 'Statut: ' . $quote->status;
        $lines[] = 'Total: ' . $this->formatMoney((float) ($quote->total ?? 0));
        if ($customerLabel !== '') {
            $lines[] = 'Client: ' . $customerLabel;
        }
        if ($quote->job_title) {
            $lines[] = 'Job: ' . $quote->job_title;
        }
        $lines[] = 'Lignes: ' . $quote->products->count();

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    private function handleShowWork(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $work = $this->resolveWork($accountId, $context, $targets);

        if (!$work) {
            return $this->needsInput('show_work', [], [
                'Quel job souhaitez-vous consulter ?',
            ]);
        }

        if (!Gate::forUser($user)->allows('view', $work)) {
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
        $lines[] = 'Job ' . $label . ':';
        $lines[] = 'Statut: ' . $work->status;
        if ($work->start_date) {
            $lines[] = 'Date: ' . Carbon::parse($work->start_date)->toDateString();
        }
        if ($work->job_title) {
            $lines[] = 'Titre: ' . $work->job_title;
        }
        if ($customerLabel !== '') {
            $lines[] = 'Client: ' . $customerLabel;
        }
        if ($work->invoice) {
            $lines[] = 'Facture: ' . ($work->invoice->number ?? $work->invoice->id);
        }

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    private function handleShowInvoice(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        if ($user->id !== $accountId) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse aux factures.',
            ];
        }

        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $invoice = $this->resolveInvoice($accountId, $context, $targets);
        if (!$invoice) {
            return $this->needsInput('show_invoice', [], [
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
        $lines[] = 'Facture ' . $label . ':';
        $lines[] = 'Statut: ' . $invoice->status;
        $lines[] = 'Total: ' . $this->formatMoney($total);
        $lines[] = 'Paye: ' . $this->formatMoney($paid);
        $lines[] = 'Reste: ' . $this->formatMoney($balance);
        if ($customerLabel !== '') {
            $lines[] = 'Client: ' . $customerLabel;
        }

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    private function handleShowCustomer(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $customer = $this->resolveCustomer($accountId, $interpretation['customer'] ?? []);
        if (!$customer && !empty($context['current_customer']['id'])) {
            $customer = Customer::byUser($accountId)->whereKey((int) $context['current_customer']['id'])->first();
        }

        if (!$customer) {
            return $this->needsInput('show_customer', [], [
                'Quel client souhaitez-vous consulter ?',
            ]);
        }

        if (!Gate::forUser($user)->allows('view', $customer)) {
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
        $lines[] = 'Client ' . $label . ':';
        if ($customerLabel !== '') {
            $lines[] = $customerLabel;
        }
        if ($customer->phone) {
            $lines[] = 'Tel: ' . $customer->phone;
        }
        $lines[] = 'Devis: ' . $customer->quotes_count;
        $lines[] = 'Jobs: ' . $customer->works_count;
        $lines[] = 'Factures: ' . $customer->invoices_count;

        return [
            'status' => 'ok',
            'message' => implode("\n", $lines),
        ];
    }

    private function handleCreateTask(array $interpretation, User $user, array $context): array
    {
        if (!Gate::forUser($user)->allows('create', Task::class)) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse pour creer une tache.',
            ];
        }

        $draft = is_array($context['draft'] ?? null) ? $context['draft'] : [];
        $draft = $this->mergeTaskDraft($draft, $interpretation['task'] ?? []);
        $draft = $this->applyAnswerToTaskDraft($draft, $context);

        $questions = [];
        if (($draft['title'] ?? '') === '') {
            $questions[] = 'Quel est le titre de la tache ?';
        }
        if (($draft['status'] ?? '') !== '' && !$this->normalizeTaskStatus($draft['status'] ?? '')) {
            $questions[] = 'Quel statut pour la tache ?';
        }
        if (($draft['due_date'] ?? '') !== '' && !$this->isValidDate($draft['due_date'] ?? '')) {
            $questions[] = 'Quelle est la date d echeance (YYYY-MM-DD) ?';
        }

        if ($questions) {
            return $this->needsInput('create_task', $draft, $questions);
        }

        $accountId = $user->accountOwnerId() ?? $user->id;
        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $work = $this->resolveWork($accountId, $context, $targets);

        $assigneeInterpretation = $interpretation;
        if (!is_array($assigneeInterpretation['task'] ?? null)) {
            $assigneeInterpretation['task'] = [];
        }
        if (!empty($draft['assignee']) && empty($assigneeInterpretation['task']['assignee'])) {
            $assigneeInterpretation['task']['assignee'] = $draft['assignee'];
        }
        $assigneeId = $this->resolveSingleTeamMemberId($accountId, $assigneeInterpretation);
        $status = $this->normalizeTaskStatus($draft['status'] ?? '') ?: 'todo';
        $dueDate = $this->parseDate($draft['due_date'] ?? null);
        $timezone = TaskTimingService::resolveTimezoneForAccountId($accountId);
        $dueDateValue = $dueDate ? Carbon::parse($dueDate, $timezone)->startOfDay() : null;

        app(UsageLimitService::class)->enforceLimit($user, 'tasks');

        if ($status === 'in_progress' && $dueDateValue && TaskTimingService::isDueDateInFuture($dueDateValue, Carbon::now($timezone))) {
            return [
                'status' => 'error',
                'message' => 'Cette tache ne peut pas etre en cours avant sa date planifiee.',
            ];
        }

        $completionReason = $draft['completion_reason'] ?? null;
        $completedAt = null;
        if ($status === 'done') {
            $completedAt = TaskTimingService::normalizeCompletedAt($draft['completed_at'] ?? null, $timezone) ?? now();
            if ($dueDateValue && TaskTimingService::shouldRequireCompletionReason($dueDateValue, $completedAt)
                && !TaskTimingService::isValidCompletionReason($completionReason)) {
                return [
                    'status' => 'error',
                    'message' => 'Merci de fournir une raison de completion (liste fermee) lorsque la date differe.',
                ];
            }
        }

        $task = Task::create([
            'account_id' => $accountId,
            'created_by_user_id' => $user->id,
            'assigned_team_member_id' => $assigneeId,
            'customer_id' => $work?->customer_id,
            'work_id' => $work?->id,
            'title' => $draft['title'],
            'description' => $draft['description'] ?: null,
            'status' => $status,
            'due_date' => $dueDate,
            'completed_at' => $status === 'done' ? $completedAt : null,
            'completion_reason' => $status === 'done' ? $completionReason : null,
            'delay_started_at' => $status !== 'done' && $dueDateValue
                && $dueDateValue->lt(Carbon::now($timezone)->startOfDay())
                ? now()
                : null,
        ]);

        app(TaskStatusHistoryService::class)->record($task, $user, [
            'from_status' => null,
            'to_status' => $task->status,
            'action' => 'created',
        ]);

        if ($status === 'done') {
            app(TaskBillingService::class)->handleTaskCompleted($task, $user);
        }

        return [
            'status' => 'created',
            'message' => 'Tache creee.',
            'action' => [
                'type' => 'task_created',
                'task_id' => $task->id,
            ],
            'context' => [
                'intent' => null,
                'draft' => null,
            ],
        ];
    }

    private function handleUpdateTaskStatus(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $taskData = $interpretation['task'] ?? [];

        $task = $this->resolveTask($accountId, $context, $targets, $taskData);
        if (!$task) {
            return $this->needsInput('update_task_status', [], [
                'Quelle tache faut-il mettre a jour ?',
            ]);
        }

        $status = $this->normalizeTaskStatus($taskData['status'] ?? '');
        if (!$status) {
            return $this->needsInput('update_task_status', [], [
                'Quel statut souhaitez-vous appliquer a la tache ?',
            ]);
        }

        if ($task->status === 'done') {
            return [
                'status' => 'not_allowed',
                'message' => 'Cette tache est verrouillee apres completion.',
            ];
        }

        $summary = 'Changer le statut de la tache ' . ($task->title ?: $task->id)
            . ' de ' . $task->status . ' vers ' . $status . '.';
        $pendingAction = [
            'type' => 'update_task_status',
            'payload' => [
                'task_id' => $task->id,
                'status' => $status,
            ],
            'summary' => $summary,
        ];

        return $this->needsConfirmation($summary, $pendingAction);
    }

    private function handleAssignTask(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $taskData = $interpretation['task'] ?? [];

        $task = $this->resolveTask($accountId, $context, $targets, $taskData);
        if (!$task) {
            return $this->needsInput('assign_task', [], [
                'Quelle tache faut-il assigner ?',
            ]);
        }

        $assigneeId = $this->resolveSingleTeamMemberId($accountId, $interpretation);
        if (!$assigneeId) {
            return $this->needsInput('assign_task', [], [
                'A quel membre faut-il assigner la tache ?',
            ]);
        }

        if ($task->status === 'done') {
            return [
                'status' => 'not_allowed',
                'message' => 'Cette tache est verrouillee apres completion.',
            ];
        }

        $summary = 'Assigner la tache ' . ($task->title ?: $task->id) . ' au membre ' . $assigneeId . '.';
        $pendingAction = [
            'type' => 'assign_task',
            'payload' => [
                'task_id' => $task->id,
                'assignee_id' => $assigneeId,
            ],
            'summary' => $summary,
        ];

        return $this->needsConfirmation($summary, $pendingAction);
    }

    private function handleUpdateChecklistItem(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $work = $this->resolveWork($accountId, $context, $targets);

        if (!$work) {
            return $this->needsInput('update_checklist_item', [], [
                'Pour quel job faut-il mettre a jour la checklist ?',
            ]);
        }

        if (!Gate::forUser($user)->allows('update', $work)) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse a ce job.',
            ];
        }

        $item = $this->resolveChecklistItem($work, $targets, $interpretation['checklist_item'] ?? []);
        if (!$item) {
            return $this->needsInput('update_checklist_item', [], [
                'Quel item de checklist faut-il mettre a jour ?',
            ]);
        }

        $status = $this->normalizeChecklistStatus($interpretation['checklist_item']['status'] ?? '');
        if (!$status) {
            return $this->needsInput('update_checklist_item', [], [
                'Quel statut appliquer a la checklist (pending/done) ?',
            ]);
        }

        $item->status = $status;
        $item->completed_at = $status === 'done' ? now() : null;
        $item->save();

        return [
            'status' => 'created',
            'message' => 'Checklist mise a jour.',
            'context' => [
                'intent' => null,
                'draft' => null,
            ],
        ];
    }

    private function handleCreateRequest(array $interpretation, User $user, array $context): array
    {
        $draft = is_array($context['draft'] ?? null) ? $context['draft'] : [];
        $draft = $this->mergeRequestDraft($draft, $interpretation['request'] ?? []);
        $draft = $this->applyAnswerToRequestDraft($draft, $context);

        $questions = [];
        if (($draft['title'] ?? '') === '' && ($draft['service_type'] ?? '') === '' && ($draft['contact_name'] ?? '') === '') {
            $questions[] = 'Quel est le titre ou le type de service de la request ?';
        }
        if (($draft['contact_email'] ?? '') !== '' && !filter_var($draft['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $questions[] = 'Merci de donner un email de contact valide.';
        }

        if ($questions) {
            return $this->needsInput('create_request', $draft, $questions);
        }

        $accountId = $user->accountOwnerId() ?? $user->id;
        app(UsageLimitService::class)->enforceLimit($user, 'requests');

        $customer = $this->resolveCustomer($accountId, $interpretation['customer'] ?? []);
        $lead = LeadRequest::create([
            'user_id' => $accountId,
            'customer_id' => $customer?->id,
            'external_customer_id' => $draft['external_customer_id'] ?? null,
            'channel' => $draft['channel'] ?? null,
            'status' => LeadRequest::STATUS_NEW,
            'service_type' => $draft['service_type'] ?? null,
            'urgency' => $draft['urgency'] ?? null,
            'title' => $draft['title'] ?? null,
            'description' => $draft['description'] ?? null,
            'contact_name' => $draft['contact_name'] ?? null,
            'contact_email' => $draft['contact_email'] ?? null,
            'contact_phone' => $draft['contact_phone'] ?? null,
            'country' => $draft['country'] ?? null,
            'state' => $draft['state'] ?? null,
            'city' => $draft['city'] ?? null,
            'street1' => $draft['street1'] ?? null,
            'street2' => $draft['street2'] ?? null,
            'postal_code' => $draft['postal_code'] ?? null,
        ]);

        ActivityLog::record($user, $lead, 'created', [
            'customer_id' => $lead->customer_id,
            'title' => $lead->title,
            'service_type' => $lead->service_type,
        ], 'Request created by assistant');

        return [
            'status' => 'created',
            'message' => 'Request creee.',
            'action' => [
                'type' => 'request_created',
                'request_id' => $lead->id,
            ],
            'context' => [
                'intent' => null,
                'draft' => null,
            ],
        ];
    }

    private function handleConvertRequest(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $request = $this->resolveRequest($accountId, $targets, $interpretation['request'] ?? [], $interpretation['filters'] ?? []);

        if (!$request) {
            return $this->needsInput('convert_request', [], [
                'Quelle request faut-il convertir en devis ?',
            ]);
        }

        $customer = null;
        if ($request->customer_id) {
            $customer = Customer::byUser($accountId)->whereKey($request->customer_id)->first();
        }
        if (!$customer) {
            $customer = $this->resolveCustomer($accountId, $interpretation['customer'] ?? []);
        }

        if (!$customer) {
            return $this->needsInput('convert_request', [], [
                'Pour quel client faut-il creer le devis ?',
            ]);
        }

        $summary = 'Convertir la request ' . $request->id . ' en devis pour ' . ($customer->company_name ?: $customer->email) . '.';
        $pendingAction = [
            'type' => 'convert_request',
            'payload' => [
                'request_id' => $request->id,
                'customer_id' => $customer->id,
                'job_title' => $interpretation['work']['job_title'] ?? ($request->title ?? $request->service_type ?? 'New Quote'),
            ],
            'summary' => $summary,
        ];

        return $this->needsConfirmation($summary, $pendingAction);
    }

    private function handleSendInvoice(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        if ($user->id !== $accountId) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse aux factures.',
            ];
        }

        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $invoice = $this->resolveInvoice($accountId, $context, $targets);
        if (!$invoice) {
            return $this->needsInput('send_invoice', [], [
                'Quelle facture faut-il envoyer ?',
            ]);
        }

        $invoice->loadMissing('customer');
        if (!$invoice->customer || !$invoice->customer->email) {
            return [
                'status' => 'needs_input',
                'message' => 'Email client manquant pour envoyer la facture.',
                'questions' => ['Ajoutez un email client puis reessayez.'],
            ];
        }

        if ($invoice->status === 'void') {
            return [
                'status' => 'not_allowed',
                'message' => 'Cette facture est annulee.',
            ];
        }

        $summary = 'Envoyer la facture ' . ($invoice->number ?? $invoice->id) . ' a ' . $invoice->customer->email . '.';
        $pendingAction = [
            'type' => 'send_invoice',
            'payload' => [
                'invoice_id' => $invoice->id,
            ],
            'summary' => $summary,
        ];

        return $this->needsConfirmation($summary, $pendingAction);
    }

    private function handleRemindInvoice(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        if ($user->id !== $accountId) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse aux factures.',
            ];
        }

        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $invoice = $this->resolveInvoice($accountId, $context, $targets);
        if (!$invoice) {
            return $this->needsInput('remind_invoice', [], [
                'Quelle facture faut-il relancer ?',
            ]);
        }

        $invoice->loadMissing('customer');
        if (!$invoice->customer || !$invoice->customer->email) {
            return [
                'status' => 'needs_input',
                'message' => 'Email client manquant pour relancer la facture.',
                'questions' => ['Ajoutez un email client puis reessayez.'],
            ];
        }

        if ($invoice->balance_due <= 0) {
            return [
                'status' => 'created',
                'message' => 'Facture deja payee.',
            ];
        }

        $summary = 'Relancer la facture ' . ($invoice->number ?? $invoice->id) . ' a ' . $invoice->customer->email . '.';
        $pendingAction = [
            'type' => 'remind_invoice',
            'payload' => [
                'invoice_id' => $invoice->id,
            ],
            'summary' => $summary,
        ];

        return $this->needsConfirmation($summary, $pendingAction);
    }

    private function handleScheduleWork(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $work = $this->resolveWork($accountId, $context, $targets);

        if (!$work) {
            return $this->needsInput('schedule_work', [], [
                'Quel job faut-il planifier ?',
            ]);
        }

        if (!Gate::forUser($user)->allows('update', $work)) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse a ce job.',
            ];
        }

        $draft = $interpretation['work'] ?? [];
        $startDate = trim((string) ($draft['start_date'] ?? ''));
        $endDate = trim((string) ($draft['end_date'] ?? ''));
        $startTime = trim((string) ($draft['start_time'] ?? ''));
        $endTime = trim((string) ($draft['end_time'] ?? ''));
        $status = $this->normalizeWorkStatus($draft['status'] ?? '');

        $questions = [];
        if ($startDate === '') {
            $questions[] = 'Quelle est la date de debut du job ?';
        } elseif (!$this->isValidDate($startDate)) {
            $questions[] = 'Quelle est la date de debut (format YYYY-MM-DD) ?';
        }
        if ($endDate !== '' && !$this->isValidDate($endDate)) {
            $questions[] = 'Quelle est la date de fin (format YYYY-MM-DD) ?';
        }
        if ($startTime !== '' && !$this->parseTime($startTime)) {
            $questions[] = 'Quelle est l heure de debut (format HH:MM) ?';
        }
        if ($endTime !== '' && !$this->parseTime($endTime)) {
            $questions[] = 'Quelle est l heure de fin (format HH:MM) ?';
        }
        if (($draft['status'] ?? '') !== '' && !$status) {
            $questions[] = 'Quel statut pour le job ?';
        }

        if ($questions) {
            return $this->needsInput('schedule_work', $draft, $questions);
        }

        $teamMemberIds = $this->resolveTeamMemberIds($accountId, $interpretation);
        $summary = 'Planifier le job ' . ($work->number ?? $work->id) . ' le ' . $startDate;
        if ($startTime !== '') {
            $summary .= ' a ' . $startTime;
        }
        if ($endTime !== '') {
            $summary .= ' -> ' . $endTime;
        }
        if ($teamMemberIds) {
            $summary .= '. Equipe: ' . implode(', ', $teamMemberIds) . '.';
        }

        $pendingAction = [
            'type' => 'schedule_work',
            'payload' => [
                'work_id' => $work->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'status' => $status,
                'team_member_ids' => $teamMemberIds,
            ],
            'summary' => $summary,
        ];

        return $this->needsConfirmation($summary, $pendingAction);
    }

    private function handleAssignWorkTeam(array $interpretation, User $user, array $context): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $targets = is_array($interpretation['targets'] ?? null) ? $interpretation['targets'] : [];
        $work = $this->resolveWork($accountId, $context, $targets);

        if (!$work) {
            return $this->needsInput('assign_work_team', [], [
                'Quel job faut-il assigner ?',
            ]);
        }

        if (!Gate::forUser($user)->allows('update', $work)) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse a ce job.',
            ];
        }

        $teamMemberIds = $this->resolveTeamMemberIds($accountId, $interpretation);
        if (!$teamMemberIds) {
            return $this->needsInput('assign_work_team', [], [
                'Quel(s) membre(s) faut-il assigner ?',
            ]);
        }

        $summary = 'Assigner le job ' . ($work->number ?? $work->id) . ' aux membres ' . implode(', ', $teamMemberIds) . '.';
        $pendingAction = [
            'type' => 'assign_work_team',
            'payload' => [
                'work_id' => $work->id,
                'team_member_ids' => $teamMemberIds,
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
        NotificationDispatcher::send($memberUser, new InviteUserNotification(
            $token,
            $user->company_name ?: config('app.name'),
            $user->company_logo_url,
            'team'
        ), [
            'team_member_id' => $teamMember->id,
        ]);

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
        NotificationDispatcher::send($quote->customer, new SendQuoteNotification($quote), [
            'quote_id' => $quote->id,
            'customer_id' => $quote->customer->id,
        ]);

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

    private function executeUpdateTaskStatus(array $payload, User $user): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $taskId = $payload['task_id'] ?? null;
        $status = $payload['status'] ?? null;

        if (!$taskId || !$status) {
            return [
                'status' => 'error',
                'message' => 'Tache ou statut manquant.',
            ];
        }

        $task = Task::forAccount($accountId)->with('materials')->find($taskId);
        if (!$task) {
            return [
                'status' => 'error',
                'message' => 'Tache introuvable.',
            ];
        }

        if (!Gate::forUser($user)->allows('update', $task)) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse a cette tache.',
            ];
        }

        if ($task->status === 'done') {
            return [
                'status' => 'not_allowed',
                'message' => 'Cette tache est verrouillee apres completion.',
            ];
        }

        $normalizedStatus = $this->normalizeTaskStatus((string) $status);
        if (!$normalizedStatus) {
            return [
                'status' => 'error',
                'message' => 'Statut de tache invalide.',
            ];
        }

        $wasInProgress = $task->status === 'in_progress';
        $wasDone = $task->status === 'done';
        $isDone = $normalizedStatus === 'done';

        $timezone = TaskTimingService::resolveTimezoneForAccountId($accountId);
        $dueDateValue = $task->due_date
            ? Carbon::parse($task->due_date, $timezone)->startOfDay()
            : null;

        if ($normalizedStatus === 'in_progress' && $dueDateValue
            && TaskTimingService::isDueDateInFuture($dueDateValue, Carbon::now($timezone))) {
            return [
                'status' => 'error',
                'message' => 'Cette tache ne peut pas etre en cours avant sa date planifiee.',
            ];
        }

        $completionReason = $payload['completion_reason'] ?? null;
        $completedAt = TaskTimingService::normalizeCompletedAt($payload['completed_at'] ?? null, $timezone);
        if ($isDone && !$completedAt) {
            $completedAt = now();
        }

        if ($isDone && $dueDateValue && TaskTimingService::shouldRequireCompletionReason($dueDateValue, $completedAt)
            && !TaskTimingService::isValidCompletionReason($completionReason)) {
            return [
                'status' => 'error',
                'message' => 'Merci de fournir une raison de completion (liste fermee) lorsque la date differe.',
            ];
        }

        $previousStatus = $task->status;
        $previousCompletedAt = $task->completed_at?->toDateTimeString();
        $previousCompletionReason = $task->completion_reason;

        $task->status = $normalizedStatus;
        if ($isDone) {
            $task->completed_at = $completedAt;
            $task->completion_reason = $completionReason;
            $task->delay_started_at = null;
        } else {
            $task->completed_at = null;
            $task->completion_reason = null;
            if ($dueDateValue && $dueDateValue->lt(Carbon::now($timezone)->startOfDay())) {
                $task->delay_started_at = $task->delay_started_at ?? now();
            } else {
                $task->delay_started_at = null;
            }
        }
        $task->save();

        if (!$wasInProgress && $normalizedStatus === 'in_progress') {
            $this->applyTaskMaterialStock($task, $user);
        }

        if (!$wasDone && $isDone) {
            app(TaskBillingService::class)->handleTaskCompleted($task, $user);
        }

        $statusChanged = $previousStatus !== $task->status;
        $completedAtChanged = $previousCompletedAt !== ($task->completed_at?->toDateTimeString() ?? null);
        $completionReasonChanged = $previousCompletionReason !== $task->completion_reason;

        if ($statusChanged || $completedAtChanged || $completionReasonChanged) {
            app(TaskStatusHistoryService::class)->record($task, $user, [
                'from_status' => $previousStatus,
                'to_status' => $task->status,
                'action' => 'manual',
            ]);
        }

        return [
            'status' => 'created',
            'message' => 'Tache mise a jour.',
            'action' => [
                'type' => 'task_updated',
                'task_id' => $task->id,
            ],
            'context' => [
                'pending_action' => null,
            ],
        ];
    }

    private function executeAssignTask(array $payload, User $user): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $taskId = $payload['task_id'] ?? null;
        $assigneeId = $payload['assignee_id'] ?? null;

        if (!$taskId || !$assigneeId) {
            return [
                'status' => 'error',
                'message' => 'Tache ou membre manquant.',
            ];
        }

        $task = Task::forAccount($accountId)->find($taskId);
        if (!$task) {
            return [
                'status' => 'error',
                'message' => 'Tache introuvable.',
            ];
        }

        if (!Gate::forUser($user)->allows('update', $task)) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse a cette tache.',
            ];
        }

        if ($task->status === 'done') {
            return [
                'status' => 'not_allowed',
                'message' => 'Cette tache est verrouillee apres completion.',
            ];
        }

        $assignee = TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->whereKey((int) $assigneeId)
            ->first();

        if (!$assignee) {
            return [
                'status' => 'error',
                'message' => 'Membre introuvable.',
            ];
        }

        $dueDate = $task->due_date ? $task->due_date->toDateString() : null;
        $conflictTask = $this->findTaskScheduleConflict(
            $accountId,
            $assignee->id,
            $dueDate,
            $task->start_time,
            $task->end_time,
            $task->id
        );
        if ($conflictTask) {
            $label = $conflictTask->title ?: $conflictTask->id;
            return [
                'status' => 'error',
                'message' => 'Ce membre est deja occupe sur la tache ' . $label . ' a ce moment.',
            ];
        }

        $task->assigned_team_member_id = $assignee->id;
        $task->save();

        return [
            'status' => 'created',
            'message' => 'Tache assignee.',
            'action' => [
                'type' => 'task_updated',
                'task_id' => $task->id,
            ],
            'context' => [
                'pending_action' => null,
            ],
        ];
    }

    private function executeConvertRequest(array $payload, User $user): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $requestId = $payload['request_id'] ?? null;
        $customerId = $payload['customer_id'] ?? null;
        $jobTitle = $payload['job_title'] ?? null;

        if (!$requestId || !$customerId) {
            return [
                'status' => 'error',
                'message' => 'Request ou client manquant.',
            ];
        }

        $request = LeadRequest::query()->where('user_id', $accountId)->find($requestId);
        if (!$request) {
            return [
                'status' => 'error',
                'message' => 'Request introuvable.',
            ];
        }

        if ($request->quote) {
            return [
                'status' => 'created',
                'message' => 'Request deja convertie.',
                'action' => [
                    'type' => 'quote_created',
                    'quote_id' => $request->quote->id,
                ],
                'context' => [
                    'pending_action' => null,
                ],
            ];
        }

        if (in_array($request->status, [LeadRequest::STATUS_WON, LeadRequest::STATUS_LOST], true)) {
            return [
                'status' => 'error',
                'message' => 'La request est deja fermee.',
            ];
        }

        $customer = Customer::byUser($accountId)->find($customerId);
        if (!$customer) {
            return [
                'status' => 'error',
                'message' => 'Client introuvable.',
            ];
        }

        app(UsageLimitService::class)->enforceLimit($user, 'quotes');

        $propertyId = $customer->properties()->value('id');
        $title = $jobTitle ?: ($request->title ?? $request->service_type ?? 'New Quote');

        $quote = Quote::create([
            'user_id' => $accountId,
            'customer_id' => $customer->id,
            'property_id' => $propertyId,
            'job_title' => $title,
            'status' => 'draft',
            'request_id' => $request->id,
            'notes' => $request->description,
        ]);

        $request->update([
            'customer_id' => $customer->id,
            'status' => LeadRequest::STATUS_QUALIFIED,
            'status_updated_at' => now(),
            'converted_at' => now(),
        ]);

        ActivityLog::record($user, $request, 'converted', [
            'quote_id' => $quote->id,
            'customer_id' => $quote->customer_id,
        ], 'Request converted to quote by assistant');

        ActivityLog::record($user, $quote, 'created', [
            'request_id' => $request->id,
            'customer_id' => $quote->customer_id,
            'assistant' => true,
        ], 'Quote created from request by assistant');

        return [
            'status' => 'created',
            'message' => 'Request convertie en devis.',
            'action' => [
                'type' => 'quote_created',
                'quote_id' => $quote->id,
            ],
            'context' => [
                'pending_action' => null,
            ],
        ];
    }

    private function executeSendInvoice(array $payload, User $user): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $invoiceId = $payload['invoice_id'] ?? null;

        if (!$invoiceId) {
            return [
                'status' => 'error',
                'message' => 'Facture manquante.',
            ];
        }

        $invoice = Invoice::byUser($accountId)->with('customer')->find($invoiceId);
        if (!$invoice) {
            return [
                'status' => 'error',
                'message' => 'Facture introuvable.',
            ];
        }

        if ($invoice->status === 'void') {
            return [
                'status' => 'error',
                'message' => 'Cette facture est annulee.',
            ];
        }

        $this->sendInvoiceNotification($invoice, 'New invoice available', 'A new invoice is available.');

        if ($invoice->status === 'draft') {
            $invoice->status = 'sent';
            $invoice->save();
        }

        ActivityLog::record($user, $invoice, 'sent', [
            'assistant' => true,
        ], 'Invoice sent by assistant');

        return [
            'status' => 'created',
            'message' => 'Facture envoyee.',
            'context' => [
                'pending_action' => null,
            ],
        ];
    }

    private function executeRemindInvoice(array $payload, User $user): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $invoiceId = $payload['invoice_id'] ?? null;

        if (!$invoiceId) {
            return [
                'status' => 'error',
                'message' => 'Facture manquante.',
            ];
        }

        $invoice = Invoice::byUser($accountId)->with('customer')->find($invoiceId);
        if (!$invoice) {
            return [
                'status' => 'error',
                'message' => 'Facture introuvable.',
            ];
        }

        if ($invoice->balance_due <= 0) {
            return [
                'status' => 'created',
                'message' => 'Facture deja payee.',
                'context' => [
                    'pending_action' => null,
                ],
            ];
        }

        $this->sendInvoiceNotification($invoice, 'Invoice reminder', 'This is a reminder for your invoice.');

        ActivityLog::record($user, $invoice, 'reminded', [
            'assistant' => true,
        ], 'Invoice reminder sent by assistant');

        return [
            'status' => 'created',
            'message' => 'Relance envoyee.',
            'context' => [
                'pending_action' => null,
            ],
        ];
    }

    private function executeScheduleWork(array $payload, User $user): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $workId = $payload['work_id'] ?? null;

        if (!$workId) {
            return [
                'status' => 'error',
                'message' => 'Job manquant.',
            ];
        }

        $work = Work::byUser($accountId)->find($workId);
        if (!$work) {
            return [
                'status' => 'error',
                'message' => 'Job introuvable.',
            ];
        }

        if (!Gate::forUser($user)->allows('update', $work)) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse a ce job.',
            ];
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
        $status = $this->normalizeWorkStatus((string) ($payload['status'] ?? ''));

        $work->start_date = $startDate;
        $work->end_date = $endDate;
        $work->start_time = $startTime;
        $work->end_time = $endTime;
        if ($status) {
            $work->status = $status;
        } elseif ($work->status === Work::STATUS_TO_SCHEDULE) {
            $work->status = Work::STATUS_SCHEDULED;
        }
        $work->save();

        $teamMemberIds = Arr::wrap($payload['team_member_ids'] ?? []);
        $teamMemberIds = collect($teamMemberIds)->map(fn($id) => (int) $id)->filter()->unique()->values()->all();
        if ($teamMemberIds) {
            $validIds = TeamMember::query()
                ->forAccount($accountId)
                ->whereIn('id', $teamMemberIds)
                ->pluck('id')
                ->all();
            if ($validIds) {
                $work->teamMembers()->syncWithoutDetaching($validIds);
            }
        }

        ActivityLog::record($user, $work, 'updated', [
            'assistant' => true,
            'start_date' => $work->start_date,
        ], 'Job scheduled by assistant');

        $this->autoScheduleTasksForWork($work, $user);

        return [
            'status' => 'created',
            'message' => 'Job planifie.',
            'action' => [
                'type' => 'work_updated',
                'work_id' => $work->id,
            ],
            'context' => [
                'pending_action' => null,
            ],
        ];
    }

    private function executeAssignWorkTeam(array $payload, User $user): array
    {
        $accountId = $user->accountOwnerId() ?? $user->id;
        $workId = $payload['work_id'] ?? null;
        $teamMemberIds = Arr::wrap($payload['team_member_ids'] ?? []);

        if (!$workId) {
            return [
                'status' => 'error',
                'message' => 'Job manquant.',
            ];
        }

        $work = Work::byUser($accountId)->find($workId);
        if (!$work) {
            return [
                'status' => 'error',
                'message' => 'Job introuvable.',
            ];
        }

        if (!Gate::forUser($user)->allows('update', $work)) {
            return [
                'status' => 'not_allowed',
                'message' => 'Acces refuse a ce job.',
            ];
        }

        $teamMemberIds = collect($teamMemberIds)->map(fn($id) => (int) $id)->filter()->unique()->values()->all();
        if (!$teamMemberIds) {
            return [
                'status' => 'error',
                'message' => 'Aucun membre fourni.',
            ];
        }

        $validIds = TeamMember::query()
            ->forAccount($accountId)
            ->whereIn('id', $teamMemberIds)
            ->pluck('id')
            ->all();

        if (!$validIds) {
            return [
                'status' => 'error',
                'message' => 'Aucun membre valide.',
            ];
        }

        $work->teamMembers()->syncWithoutDetaching($validIds);

        ActivityLog::record($user, $work, 'updated', [
            'assistant' => true,
            'team_members' => $validIds,
        ], 'Job assigned by assistant');

        return [
            'status' => 'created',
            'message' => 'Equipe assignee.',
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

    private function mergeTaskDraft(array $base, array $updates): array
    {
        $updates = is_array($updates) ? $updates : [];
        $merged = $base;
        foreach (['title', 'description', 'status', 'due_date', 'completion_reason', 'completed_at'] as $key) {
            $value = $updates[$key] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '' || $value === null) {
                continue;
            }
            $merged[$key] = $value;
        }

        $assignee = is_array($updates['assignee'] ?? null) ? $updates['assignee'] : [];
        if ($assignee) {
            $merged['assignee'] = is_array($merged['assignee'] ?? null) ? $merged['assignee'] : [];
            foreach (['name', 'email'] as $key) {
                $value = $assignee[$key] ?? null;
                $value = is_string($value) ? trim($value) : $value;
                if ($value === '' || $value === null) {
                    continue;
                }
                $merged['assignee'][$key] = $value;
            }
        }

        return $merged;
    }

    private function mergeRequestDraft(array $base, array $updates): array
    {
        $updates = is_array($updates) ? $updates : [];
        $merged = $base;
        foreach ([
            'title',
            'service_type',
            'description',
            'channel',
            'urgency',
            'contact_name',
            'contact_email',
            'contact_phone',
            'country',
            'state',
            'city',
            'street1',
            'street2',
            'postal_code',
            'external_customer_id',
        ] as $key) {
            $value = $updates[$key] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '' || $value === null) {
                continue;
            }
            $merged[$key] = $value;
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

    private function applyAnswerToTaskDraft(array $draft, array $context): array
    {
        [$answer, $questions] = $this->extractAnswerAndQuestions($context);
        if ($answer === '' || !$questions) {
            return $draft;
        }

        $normalized = $this->normalizeAnswerValue($answer);

        foreach ($questions as $question) {
            $lower = strtolower($question);
            if ((str_contains($lower, 'titre') || str_contains($lower, 'title')) && ($draft['title'] ?? '') === '') {
                $draft['title'] = $normalized;
                continue;
            }

            if ((str_contains($lower, 'statut') || str_contains($lower, 'status')) && ($draft['status'] ?? '') === '') {
                $draft['status'] = $normalized;
                continue;
            }

            if ((str_contains($lower, 'echeance') || str_contains($lower, 'due') || str_contains($lower, 'date'))
                && ($draft['due_date'] ?? '') === '') {
                $draft['due_date'] = $normalized;
                continue;
            }

            if (str_contains($lower, 'description') && ($draft['description'] ?? '') === '') {
                $draft['description'] = $normalized;
            }

            if (str_contains($lower, 'raison') && ($draft['completion_reason'] ?? '') === '') {
                $draft['completion_reason'] = $normalized;
            }
        }

        return $draft;
    }

    private function applyAnswerToRequestDraft(array $draft, array $context): array
    {
        [$answer, $questions] = $this->extractAnswerAndQuestions($context);
        if ($answer === '' || !$questions) {
            return $draft;
        }

        $normalized = $this->normalizeAnswerValue($answer);
        $email = filter_var($answer, FILTER_VALIDATE_EMAIL) ? strtolower($answer) : null;

        foreach ($questions as $question) {
            $lower = strtolower($question);
            if (str_contains($lower, 'email') && ($draft['contact_email'] ?? '') === '' && $email) {
                $draft['contact_email'] = $email;
                continue;
            }

            if ((str_contains($lower, 'telephone') || str_contains($lower, 'phone')) && ($draft['contact_phone'] ?? '') === '') {
                $draft['contact_phone'] = $normalized;
                continue;
            }

            if ((str_contains($lower, 'contact') && str_contains($lower, 'nom')) || str_contains($lower, 'name')) {
                if (($draft['contact_name'] ?? '') === '') {
                    $draft['contact_name'] = $normalized;
                    continue;
                }
            }

            if (str_contains($lower, 'titre') || str_contains($lower, 'service') || str_contains($lower, 'type')) {
                if (($draft['title'] ?? '') === '') {
                    $draft['title'] = $normalized;
                } elseif (($draft['service_type'] ?? '') === '') {
                    $draft['service_type'] = $normalized;
                }
                continue;
            }

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
                && ($draft['postal_code'] ?? '') === '') {
                $draft['postal_code'] = $normalized;
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

    private function resolveWorkItem(int $accountId, User $user, array $item): array
    {
        $questions = [];
        $name = trim((string) ($item['name'] ?? ''));
        $itemTypeRaw = strtolower((string) ($item['item_type'] ?? ''));
        $itemTypeExplicit = in_array($itemTypeRaw, ['product', 'service'], true);
        $itemType = $itemTypeRaw === 'product' ? 'product' : ($itemTypeRaw === 'service' ? 'service' : $this->normalizeItemType('', $user));

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

    private function normalizeListFilters(array $interpretation): array
    {
        $filters = is_array($interpretation['filters'] ?? null) ? $interpretation['filters'] : [];
        $search = trim((string) ($filters['search'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));

        return [
            'search' => $search,
            'status' => $status,
        ];
    }

    private function resolveListLimit(array $filters, int $default = 10): int
    {
        $limit = $filters['limit'] ?? null;
        $limit = is_numeric($limit) ? (int) $limit : null;

        if (!$limit || $limit <= 0) {
            return $default;
        }

        return min($limit, 25);
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

    private function resolveCustomerAccount(User $user, bool $allowPos = false): array
    {
        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()
                ->select(['id', 'company_type', 'company_name', 'company_logo'])
                ->find($ownerId);

        if (!$owner) {
            return [null, null];
        }

        $accountId = $user->id;
        if ($owner->company_type === 'products') {
            if ($user->id !== $owner->id) {
                $membership = $user->relationLoaded('teamMembership')
                    ? $user->teamMembership
                    : $user->teamMembership()->first();
                $canManage = $membership?->hasPermission('sales.manage') ?? false;
                $canPos = $allowPos ? ($membership?->hasPermission('sales.pos') ?? false) : false;
                if (!$membership || (!$canManage && !$canPos)) {
                    return [$owner, null];
                }
            }
            $accountId = $owner->id;
        }

        return [$owner, $accountId];
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

    private function resolveTask(int $accountId, array $context, array $targets, array $taskData): ?Task
    {
        $query = Task::forAccount($accountId);

        $current = $context['current_task'] ?? null;
        if (is_array($current)) {
            $id = $current['id'] ?? null;
            if ($id) {
                return $query->whereKey((int) $id)->first();
            }
        }

        if (is_numeric($current)) {
            return $query->whereKey((int) $current)->first();
        }

        if (!empty($targets['task_id'])) {
            return $query->whereKey((int) $targets['task_id'])->first();
        }

        $work = $this->resolveWork($accountId, $context, $targets);
        if ($work) {
            $query->where('work_id', $work->id);
        }

        $title = trim((string) ($taskData['title'] ?? ''));
        if ($title !== '') {
            $exact = (clone $query)
                ->whereRaw('LOWER(title) = ?', [strtolower($title)])
                ->first();
            if ($exact) {
                return $exact;
            }

            return (clone $query)
                ->where('title', 'like', '%' . $title . '%')
                ->orderByDesc('id')
                ->first();
        }

        if ($work) {
            return (clone $query)->orderByDesc('id')->first();
        }

        return null;
    }

    private function resolveRequest(int $accountId, array $targets, array $requestData, array $filters): ?LeadRequest
    {
        if (!empty($targets['request_id'])) {
            return LeadRequest::query()->where('user_id', $accountId)->find((int) $targets['request_id']);
        }

        $query = LeadRequest::query()->where('user_id', $accountId);
        $search = trim((string) ($filters['search'] ?? ''));
        $email = trim((string) ($requestData['contact_email'] ?? ''));
        $title = trim((string) ($requestData['title'] ?? ''));
        $serviceType = trim((string) ($requestData['service_type'] ?? ''));
        $contactName = trim((string) ($requestData['contact_name'] ?? ''));

        if ($email !== '') {
            return (clone $query)
                ->where('contact_email', $email)
                ->orderByDesc('id')
                ->first();
        }

        if ($title !== '') {
            $exact = (clone $query)
                ->whereRaw('LOWER(title) = ?', [strtolower($title)])
                ->first();
            if ($exact) {
                return $exact;
            }

            return (clone $query)
                ->where('title', 'like', '%' . $title . '%')
                ->orderByDesc('id')
                ->first();
        }

        if ($serviceType !== '') {
            return (clone $query)
                ->where('service_type', 'like', '%' . $serviceType . '%')
                ->orderByDesc('id')
                ->first();
        }

        if ($contactName !== '') {
            return (clone $query)
                ->where('contact_name', 'like', '%' . $contactName . '%')
                ->orderByDesc('id')
                ->first();
        }

        if ($search !== '') {
            return (clone $query)
                ->where(function ($sub) use ($search) {
                    $sub->where('title', 'like', '%' . $search . '%')
                        ->orWhere('service_type', 'like', '%' . $search . '%')
                        ->orWhere('contact_name', 'like', '%' . $search . '%')
                        ->orWhere('contact_email', 'like', '%' . $search . '%')
                        ->orWhere('contact_phone', 'like', '%' . $search . '%');
                })
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }

    private function resolveChecklistItem(Work $work, array $targets, array $itemData): ?WorkChecklistItem
    {
        $query = $work->checklistItems()->orderBy('sort_order');

        if (!empty($targets['checklist_item_id'])) {
            return (clone $query)->whereKey((int) $targets['checklist_item_id'])->first();
        }

        $title = trim((string) ($itemData['title'] ?? ''));
        if ($title !== '') {
            $exact = (clone $query)
                ->whereRaw('LOWER(title) = ?', [strtolower($title)])
                ->first();
            if ($exact) {
                return $exact;
            }

            return (clone $query)
                ->where('title', 'like', '%' . $title . '%')
                ->first();
        }

        $items = (clone $query)->get();
        if ($items->count() === 1) {
            return $items->first();
        }

        $pending = $items->filter(fn($item) => $item->status !== 'done')->values();
        if ($pending->count() === 1) {
            return $pending->first();
        }

        return null;
    }

    private function resolveTeamMemberIds(int $accountId, array $interpretation): array
    {
        $members = Arr::wrap($interpretation['team_members'] ?? []);
        $task = is_array($interpretation['task'] ?? null) ? $interpretation['task'] : [];
        $assignee = is_array($task['assignee'] ?? null) ? $task['assignee'] : [];
        if ($assignee) {
            $members[] = $assignee;
        }

        $ids = [];
        $baseQuery = TeamMember::query()->forAccount($accountId)->active();

        foreach ($members as $member) {
            if (is_numeric($member)) {
                $ids[] = (int) $member;
                continue;
            }

            if (is_string($member)) {
                $member = ['name' => $member];
            }

            if (!is_array($member)) {
                continue;
            }

            $email = trim((string) ($member['email'] ?? ''));
            $name = trim((string) ($member['name'] ?? ''));

            if ($email !== '') {
                $resolved = (clone $baseQuery)
                    ->whereHas('user', fn($query) => $query->where('email', $email))
                    ->value('id');
                if ($resolved) {
                    $ids[] = (int) $resolved;
                    continue;
                }
            }

            if ($name !== '') {
                if (is_numeric($name)) {
                    $ids[] = (int) $name;
                    continue;
                }

                $resolved = (clone $baseQuery)
                    ->whereHas('user', fn($query) => $query->where('name', 'like', '%' . $name . '%'))
                    ->value('id');
                if ($resolved) {
                    $ids[] = (int) $resolved;
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function resolveSingleTeamMemberId(int $accountId, array $interpretation): ?int
    {
        $ids = $this->resolveTeamMemberIds($accountId, $interpretation);

        return $ids[0] ?? null;
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

    private function normalizeQuoteStatus(?string $status): ?string
    {
        $value = Str::ascii(strtolower(trim((string) $status)));
        if ($value === '') {
            return null;
        }

        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        $map = [
            'draft' => 'draft',
            'brouillon' => 'draft',
            'sent' => 'sent',
            'envoye' => 'sent',
            'envoyee' => 'sent',
            'accepted' => 'accepted',
            'accepte' => 'accepted',
            'acceptee' => 'accepted',
            'declined' => 'declined',
            'refuse' => 'declined',
            'refusee' => 'declined',
            'rejected' => 'declined',
            'rejet' => 'declined',
            'archived' => 'archived',
            'archive' => 'archived',
        ];

        if (isset($map[$value])) {
            return $map[$value];
        }

        if (in_array($value, ['draft', 'sent', 'accepted', 'declined'], true)) {
            return $value;
        }

        return null;
    }

    private function normalizeInvoiceStatus(?string $status): ?string
    {
        $value = Str::ascii(strtolower(trim((string) $status)));
        if ($value === '') {
            return null;
        }

        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        $map = [
            'draft' => 'draft',
            'brouillon' => 'draft',
            'sent' => 'sent',
            'envoye' => 'sent',
            'envoyee' => 'sent',
            'paid' => 'paid',
            'paye' => 'paid',
            'payee' => 'paid',
            'regle' => 'paid',
            'reglee' => 'paid',
            'partial' => 'partial',
            'partiel' => 'partial',
            'partielle' => 'partial',
            'overdue' => 'overdue',
            'en retard' => 'overdue',
            'retard' => 'overdue',
            'void' => 'void',
            'annule' => 'void',
            'annulee' => 'void',
            'awaiting acceptance' => 'awaiting_acceptance',
            'en attente' => 'awaiting_acceptance',
            'accepted' => 'accepted',
            'rejected' => 'rejected',
            'refuse' => 'rejected',
            'refusee' => 'rejected',
        ];

        if (isset($map[$value])) {
            return $map[$value];
        }

        if (in_array($value, Invoice::STATUSES, true)) {
            return $value;
        }

        return null;
    }

    private function normalizeTaskStatus(?string $status): ?string
    {
        $value = Str::ascii(strtolower(trim((string) $status)));
        if ($value === '') {
            return null;
        }

        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        $map = [
            'todo' => 'todo',
            'to do' => 'todo',
            'a faire' => 'todo',
            'pending' => 'todo',
            'backlog' => 'todo',
            'in progress' => 'in_progress',
            'en cours' => 'in_progress',
            'progress' => 'in_progress',
            'done' => 'done',
            'termine' => 'done',
            'terminee' => 'done',
            'complete' => 'done',
            'completed' => 'done',
            'fait' => 'done',
            'finished' => 'done',
        ];

        if (isset($map[$value])) {
            return $map[$value];
        }

        if (in_array($value, Task::STATUSES, true)) {
            return $value;
        }

        return null;
    }

    private function normalizeChecklistStatus(?string $status): ?string
    {
        $value = Str::ascii(strtolower(trim((string) $status)));
        if ($value === '') {
            return null;
        }

        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        $map = [
            'pending' => 'pending',
            'todo' => 'pending',
            'to do' => 'pending',
            'a faire' => 'pending',
            'en cours' => 'pending',
            'in progress' => 'pending',
            'done' => 'done',
            'termine' => 'done',
            'terminee' => 'done',
            'complete' => 'done',
            'completed' => 'done',
            'fait' => 'done',
        ];

        if (isset($map[$value])) {
            return $map[$value];
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

    private function findTaskScheduleConflict(
        int $accountId,
        int $assigneeId,
        ?string $dueDate,
        ?string $startTime,
        ?string $endTime,
        ?int $ignoreTaskId = null
    ): ?Task {
        if (!$assigneeId || !$dueDate || !$startTime) {
            return null;
        }

        $date = $this->parseDate($dueDate);
        $start = $this->parseTime($startTime);
        $end = $this->parseTime($endTime) ?: $start;
        if (!$date || !$start) {
            return null;
        }

        $existingTasks = Task::query()
            ->forAccount($accountId)
            ->where('assigned_team_member_id', $assigneeId)
            ->whereDate('due_date', $date)
            ->whereNotNull('start_time')
            ->when($ignoreTaskId, fn($query) => $query->where('id', '!=', $ignoreTaskId))
            ->get(['id', 'title', 'start_time', 'end_time']);

        $newStart = $this->timeToMinutes($start);
        $newEnd = $this->timeToMinutes($end);
        if ($newStart === null || $newEnd === null) {
            return null;
        }

        foreach ($existingTasks as $task) {
            $taskStart = $this->parseTime($task->start_time);
            if (!$taskStart) {
                continue;
            }
            $taskEnd = $this->parseTime($task->end_time) ?: $taskStart;
            $taskStartMin = $this->timeToMinutes($taskStart);
            $taskEndMin = $this->timeToMinutes($taskEnd);

            if ($taskStartMin === null || $taskEndMin === null) {
                continue;
            }

            $overlaps = $newStart <= $taskEndMin && $newEnd >= $taskStartMin;
            if ($overlaps) {
                return $task;
            }
        }

        return null;
    }

    private function timeToMinutes(string $time): ?int
    {
        $parts = explode(':', $time);
        if (count($parts) < 2) {
            return null;
        }

        $hours = (int) $parts[0];
        $minutes = (int) $parts[1];

        return ($hours * 60) + $minutes;
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

        if (in_array($value, ['sales_manager', 'sales manager', 'manager sales', 'responsable vente', 'responsable de rayon'], true)) {
            return 'sales_manager';
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
            'sales_manager' => [
                'sales.manage',
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

    private function applyTaskMaterialStock(Task $task, ?User $actor = null): void
    {
        $task->loadMissing('materials');

        $materials = $task->materials
            ->filter(fn($material) => $material->product_id && !$material->stock_moved_at)
            ->values();

        if ($materials->isEmpty()) {
            return;
        }

        $productIds = $materials->pluck('product_id')->unique()->values();
        $productMap = Product::query()
            ->products()
            ->byUser($task->account_id)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $inventoryService = app(InventoryService::class);
        $defaultWarehouse = $inventoryService->resolveDefaultWarehouse($task->account_id);
        $movedIds = [];

        foreach ($materials as $material) {
            $product = $productMap->get($material->product_id);
            if (!$product) {
                continue;
            }

            $quantity = (int) round((float) $material->quantity);
            if ($quantity <= 0) {
                continue;
            }

            $warehouseId = $material->warehouse_id ?: $defaultWarehouse->id;

            $inventoryService->adjust($product, $quantity, 'out', [
                'actor_id' => $actor?->id,
                'warehouse_id' => $warehouseId,
                'account_id' => $task->account_id,
                'reason' => 'task_usage',
                'note' => 'Task usage',
                'reference_type' => Task::class,
                'reference_id' => $task->id,
            ]);

            $movedIds[] = $material->id;
        }

        if ($movedIds) {
            TaskMaterial::query()
                ->whereIn('id', $movedIds)
                ->update(['stock_moved_at' => now()]);

            TaskMaterial::query()
                ->whereIn('id', $movedIds)
                ->whereNull('warehouse_id')
                ->update(['warehouse_id' => $defaultWarehouse->id]);
        }
    }

    private function sendInvoiceNotification(Invoice $invoice, string $subject, string $message): void
    {
        $invoice->loadMissing('customer', 'work');
        $customer = $invoice->customer;

        if (!$customer || !$customer->email) {
            return;
        }

        $accountOwner = User::find($invoice->user_id);
        $note = $accountOwner
            ? app(TemplateService::class)->resolveInvoiceNote($accountOwner)
            : null;

        $usePublicLink = !(bool) ($customer->portal_access ?? true) || !$customer->portal_user_id;
        $actionUrl = route('dashboard');
        $actionLabel = 'Open dashboard';
        if ($usePublicLink) {
            $expiresAt = now()->addDays(7);
            $actionUrl = URL::temporarySignedRoute(
                'public.invoices.show',
                $expiresAt,
                ['invoice' => $invoice->id]
            );
            $actionLabel = 'Pay invoice';
        }

        $details = [
            ['label' => 'Invoice', 'value' => $invoice->number ?? $invoice->id],
            ['label' => 'Total', 'value' => '$' . number_format((float) $invoice->total, 2)],
        ];

        if ($invoice->work) {
            $details[] = [
                'label' => 'Job',
                'value' => $invoice->work->job_title ?? $invoice->work->number ?? $invoice->work->id,
            ];
        }

        NotificationDispatcher::send($customer, new ActionEmailNotification(
            $subject,
            $message,
            $details,
            $actionUrl,
            $actionLabel,
            $subject,
            $note
        ), [
            'invoice_id' => $invoice->id,
        ]);
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
