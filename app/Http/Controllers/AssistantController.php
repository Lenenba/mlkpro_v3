<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\User;
use App\Services\Assistant\AssistantCampaignService;
use App\Services\Assistant\AssistantInterpreter;
use App\Services\Assistant\AssistantPlanScanService;
use App\Services\Assistant\AssistantQuoteService;
use App\Services\Assistant\AssistantWorkflowService;
use App\Services\Assistant\CampaignAssistantContextService;
use App\Services\Assistant\OpenAiRequestException;
use App\Services\AssistantCreditService;
use App\Services\AssistantUsageService;
use App\Services\BillingSubscriptionService;
use App\Services\CompanyFeatureService;
use App\Services\PlanScanQuoteService;
use App\Services\PlanScanService;
use App\Services\UsageLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AssistantController extends Controller
{
    public function message(
        Request $request,
        AssistantInterpreter $interpreter,
        AssistantCampaignService $campaignService,
        CampaignAssistantContextService $campaignContextService,
        AssistantQuoteService $quoteService,
        AssistantWorkflowService $workflowService,
        AssistantUsageService $usageService,
        PlanScanService $planScanService,
        PlanScanQuoteService $planScanQuoteService,
        AssistantPlanScanService $assistantPlanScanService
    ): JsonResponse {
        $this->normalizeMultipartPayload($request);

        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $validated = $request->validate([
            'message' => 'nullable|string|max:4000|required_without:attachment',
            'attachment' => 'nullable|file|max:5120|mimes:pdf,png,jpg,jpeg,webp|required_without:message',
            'context' => 'nullable|array',
        ]);

        $context = $validated['context'] ?? [];
        $message = trim((string) ($validated['message'] ?? ''));
        $attachment = $request->file('attachment');

        if (! empty($context['pending_action'])) {
            $pendingAction = $context['pending_action'];

            if ($this->isConfirmation($message)) {
                $type = $pendingAction['type'] ?? '';
                if ($type === 'create_quote') {
                    return response()->json(
                        $quoteService->execute($pendingAction['payload'] ?? [], $user)
                    );
                }

                return response()->json(
                    $workflowService->execute($pendingAction, $user)
                );
            }

            if ($this->isRejection($message)) {
                return response()->json([
                    'status' => 'cancelled',
                    'message' => 'Action annulee.',
                    'context' => [
                        'pending_action' => null,
                    ],
                ]);
            }

            $summary = $pendingAction['summary'] ?? 'Une action est en attente.';

            return response()->json([
                'status' => 'needs_confirmation',
                'message' => $summary."\nConfirmer ? (oui/non)",
                'context' => [
                    'pending_action' => $pendingAction,
                ],
            ]);
        }

        $context['last_message'] = $message !== '' ? $message : ($attachment?->getClientOriginalName() ?? '');

        if (! config('services.openai.key')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Assistant non configure. Contactez un administrateur.',
            ], 422);
        }

        if ($this->isStructureChangeRequest($validated['message'])) {
            return response()->json([
                'status' => 'not_allowed',
                'message' => 'Assistant cannot change the app structure. It can only create or read workflow data.',
            ], 422);
        }

        $creditService = app(AssistantCreditService::class);
        $creditConsumed = false;

        try {
            $accountOwner = $this->resolveAccountOwner($user);
            $planModules = app(CompanyFeatureService::class)->resolvePlanModules();
            $billingService = app(BillingSubscriptionService::class);
            $planKey = $billingService->resolvePlanKey($accountOwner, $planModules);
            $assistantIncluded = $planKey ? (bool) ($planModules[$planKey]['assistant'] ?? false) : false;
            $assistantAddonEnabled = $user->hasCompanyFeature('assistant') && ! $assistantIncluded;
            $assistantAvailable = $assistantIncluded || $assistantAddonEnabled;
            if (! $assistantAvailable) {
                return response()->json([
                    'status' => 'not_allowed',
                    'message' => 'Assistant IA indisponible pour votre plan.',
                ], 403);
            }

            $creditModeEnabled = $assistantAddonEnabled
                && $billingService->isStripe()
                && (bool) config('services.stripe.ai_credit_price')
                && (int) config('services.stripe.ai_credit_pack', 0) > 0;
            $meteredEnabled = $assistantAddonEnabled
                && ! $creditModeEnabled
                && $billingService->isStripe()
                && (bool) config('services.stripe.ai_usage_price');

            if ($creditModeEnabled) {
                $creditConsumed = $creditService->consume($user, 1);
                if (! $creditConsumed) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Credits IA epuises. Achetez un pack pour continuer.',
                    ], 429);
                }
            } elseif (! $meteredEnabled) {
                app(UsageLimitService::class)->enforceLimit($user, 'assistant_requests', 1);
            }

            if ($attachment instanceof UploadedFile) {
                if (! $user->hasCompanyFeature('plan_scans')) {
                    if ($creditConsumed) {
                        $creditService->refund($user, 1, ['meta' => ['reason' => 'plan_scans_unavailable']]);
                    }

                    return response()->json([
                        'status' => 'not_allowed',
                        'message' => 'Le module scan de plans n est pas actif sur ce compte.',
                    ], 403);
                }

                $payload = $this->handlePlanScanAttachment(
                    $user,
                    $attachment,
                    $message,
                    $context,
                    $planScanService,
                    $planScanQuoteService
                );

                $usageService->record($user, [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                ], 'assistant-plan-scan-upload');

                return response()->json($payload);
            }

            if ($user->hasCompanyFeature('plan_scans')) {
                $planScanPayload = $assistantPlanScanService->handle($user, $message, $context);
                if (is_array($planScanPayload)) {
                    $usagePayload = $planScanPayload['usage'] ?? null;
                    unset($planScanPayload['usage']);

                    if (is_array($usagePayload)) {
                        $usageService->record(
                            $user,
                            $usagePayload,
                            isset($usagePayload['model']) ? (string) $usagePayload['model'] : null
                        );
                    }

                    $planScan = $planScanPayload['scan'] ?? null;
                    if ($planScan instanceof \App\Models\PlanScan) {
                        $planScanPayload['plan_scan'] = $this->presentPlanScanForAssistant(
                            $planScan,
                            $planScanPayload['assistant_state_label'] ?? null
                        );
                        unset($planScanPayload['scan']);
                    }

                    $quote = $planScanPayload['quote'] ?? null;
                    if ($quote instanceof \App\Models\Quote) {
                        $planScanPayload['quote'] = $this->presentQuoteForAssistant($quote);
                        $planScanPayload['action'] = [
                            'type' => 'plan_scan_quote_created',
                            'plan_scan_id' => $planScan?->id,
                            'quote_id' => $quote->id,
                        ];
                    } elseif ($planScan instanceof \App\Models\PlanScan) {
                        $planScanPayload['action'] = [
                            'type' => 'plan_scan_updated',
                            'plan_scan_id' => $planScan->id,
                        ];
                    }

                    unset($planScanPayload['assistant_state_label']);

                    return response()->json($planScanPayload);
                }
            }

            $context['campaign_context'] = $campaignContextService->build($user);
            $interpretation = $interpreter->interpret($message, $context);
        } catch (ValidationException $exception) {
            if ($creditConsumed) {
                $creditService->refund($user, 1, ['meta' => ['reason' => 'validation_failed']]);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Limite mensuelle de l\'Assistant IA atteinte. Activez l\'option IA ou passez au plan Scale.',
            ], 429);
        } catch (OpenAiRequestException $exception) {
            if ($creditConsumed) {
                $creditService->refund($user, 1, ['meta' => ['reason' => 'openai_failed']]);
            }
            logger()->warning('Assistant OpenAI error.', [
                'user_id' => $user->id,
                'message_preview' => Str::limit($message, 160),
                'status' => $exception->status(),
                'type' => $exception->type(),
                'api_message' => $exception->apiMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $exception->userMessage(),
            ], 422);
        } catch (RuntimeException $exception) {
            if ($creditConsumed) {
                $creditService->refund($user, 1, ['meta' => ['reason' => 'runtime_failed']]);
            }
            logger()->error('Assistant interpretation failed.', [
                'user_id' => $user->id,
                'message_preview' => Str::limit($message, 160),
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Assistant indisponible. Reessayez plus tard.',
            ], 500);
        } catch (Throwable $exception) {
            if ($creditConsumed) {
                $creditService->refund($user, 1, ['meta' => ['reason' => 'unknown_failed']]);
            }
            logger()->error('Assistant failed.', [
                'user_id' => $user->id,
                'message_preview' => Str::limit($message, 160),
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Assistant indisponible. Reessayez plus tard.',
            ], 500);
        }

        $usagePayload = $interpretation['usage'] ?? null;
        unset($interpretation['usage']);

        if (is_array($usagePayload) && $user) {
            $usageService->record(
                $user,
                $usagePayload,
                isset($usagePayload['model']) ? (string) $usagePayload['model'] : null
            );
        }

        $contextIntent = $context['intent'] ?? null;
        if ($contextIntent && $interpretation['intent'] === 'unknown') {
            $interpretation['intent'] = $contextIntent;
        }

        if ($interpretation['intent'] === 'draft_campaign') {
            return response()->json(
                $campaignService->handle($interpretation, $user, $context)
            );
        }

        if ($interpretation['intent'] === 'create_quote') {
            return response()->json(
                $quoteService->handle($interpretation, $user, $context)
            );
        }

        if (in_array($interpretation['intent'], [
            'create_work',
            'create_invoice',
            'send_quote',
            'accept_quote',
            'convert_quote',
            'mark_invoice_paid',
            'update_work_status',
            'create_customer',
            'create_property',
            'create_category',
            'create_product',
            'create_service',
            'create_team_member',
            'read_notifications',
            'list_quotes',
            'list_works',
            'list_invoices',
            'list_customers',
            'show_quote',
            'show_work',
            'show_invoice',
            'show_customer',
            'create_task',
            'update_task_status',
            'assign_task',
            'update_checklist_item',
            'create_request',
            'convert_request',
            'send_invoice',
            'remind_invoice',
            'schedule_work',
            'assign_work_team',
        ], true)) {
            return response()->json(
                $workflowService->handle($interpretation, $user, $context)
            );
        }

        return response()->json([
            'status' => 'unknown',
            'message' => 'Je peux creer et gerer des devis, factures et jobs, creer des clients/proprietes/categories/produits/services/membres, lire des listes et details, gerer des tasks/checklists, creer/convertir des requests, envoyer/relancer des factures, lire les notifications et preparer le contexte d une campagne marketing.',
        ]);
    }

    private function resolveAccountOwner(User $user): User
    {
        $ownerId = $user->accountOwnerId();
        if ($ownerId === $user->id) {
            return $user;
        }

        return User::query()->find($ownerId) ?? $user;
    }

    private function isStructureChangeRequest(string $message): bool
    {
        $normalized = Str::lower($message);

        return preg_match(
            '/\b(champ|colonne|schema|migration|interface|ui|layout|design|formulaire|composant|component|bouton|menu|sidebar|onglet|tab|fenetre|window)\b/u',
            $normalized
        ) === 1;
    }

    private function isConfirmation(string $message): bool
    {
        $normalized = Str::lower(trim($message));

        return preg_match('/\b(oui|ok|okay|confirm|confirme|valide|daccord|yes|y|go)\b/u', $normalized) === 1;
    }

    private function isRejection(string $message): bool
    {
        $normalized = Str::lower(trim($message));

        return preg_match('/\b(non|no|annule|annuler|cancel|stop)\b/u', $normalized) === 1;
    }

    private function normalizeMultipartPayload(Request $request): void
    {
        $context = $request->input('context');
        if (! is_string($context) || trim($context) === '') {
            return;
        }

        $decoded = json_decode($context, true);
        if (is_array($decoded)) {
            $request->merge([
                'context' => $decoded,
            ]);
        }
    }

    private function handlePlanScanAttachment(
        User $user,
        UploadedFile $attachment,
        string $message,
        array $context,
        PlanScanService $planScanService,
        PlanScanQuoteService $planScanQuoteService
    ): array {
        $customerContext = $this->resolveAssistantCustomerContext($user, $context);
        $scan = $planScanService->submit(
            $user,
            $attachment,
            [
                'customer_id' => $customerContext['customer_id'],
                'property_id' => $customerContext['property_id'],
                'job_title' => $this->resolvePlanScanJobTitle($attachment, $message),
                'trade_type' => $this->guessPlanTradeType($message),
            ],
            [
                'priority' => $this->guessPlanPriority($message),
            ]
        );

        $scan->loadMissing('customer');

        $quote = null;
        $quoteNote = null;
        $quoteRequested = $this->shouldCreatePlanQuote($message);

        if ($quoteRequested) {
            if (! $user->hasCompanyFeature('quotes')) {
                $quoteNote = 'Le scan est cree, mais le module devis n est pas actif sur ce compte.';
            } elseif (! $customerContext['customer_id']) {
                $quoteNote = 'Le scan est cree. Choisissez un client dans la fiche scan avant de generer le devis.';
            } elseif ($scan->status !== PlanScanService::STATUS_READY) {
                $quoteNote = 'Le scan est en cours d analyse. Le devis pourra etre cree des que le resultat sera pret.';
            } else {
                try {
                    $quote = $planScanQuoteService->createQuoteFromScan(
                        $scan,
                        $user,
                        $this->preferredPlanVariantKey($scan, $message),
                        $customerContext['customer_id'],
                        $customerContext['property_id']
                    );
                } catch (ValidationException $exception) {
                    $quoteNote = collect($exception->errors())->flatten()->first() ?: 'Le devis n a pas pu etre cree automatiquement.';
                }
            }
        }

        return [
            'status' => $quote
                ? 'plan_scan_quote_created'
                : ($scan->status === PlanScanService::STATUS_READY ? 'plan_scan_ready' : 'plan_scan_processing'),
            'message' => $this->buildPlanScanAssistantMessage($scan, $attachment, $quoteRequested, $quote !== null, $quoteNote),
            'plan_scan' => $this->presentPlanScanForAssistant($scan),
            'quote' => $quote ? $this->presentQuoteForAssistant($quote) : null,
            'action' => $quote
                ? [
                    'type' => 'plan_scan_quote_created',
                    'plan_scan_id' => $scan->id,
                    'quote_id' => $quote->id,
                ]
                : [
                    'type' => 'plan_scan_created',
                    'plan_scan_id' => $scan->id,
                ],
            'context' => array_merge($context, [
                'pending_action' => null,
                'current_plan_scan' => [
                    'id' => $scan->id,
                    'status' => $scan->status,
                    'trade_type' => $scan->trade_type,
                ],
                'current_quote' => $quote ? [
                    'id' => $quote->id,
                    'number' => $quote->number ?? '',
                    'status' => $quote->status,
                    'customer_id' => $quote->customer_id,
                    'property_id' => $quote->property_id,
                ] : ($context['current_quote'] ?? null),
            ]),
        ];
    }

    private function resolveAssistantCustomerContext(User $user, array $context): array
    {
        $accountId = $user->accountOwnerId();
        $customerId = (int) (
            data_get($context, 'current_customer.id')
            ?: data_get($context, 'current_quote.customer_id')
            ?: data_get($context, 'current_work.customer_id')
            ?: data_get($context, 'current_invoice.customer_id')
        );
        $propertyId = (int) (
            data_get($context, 'current_quote.property_id')
            ?: data_get($context, 'current_work.property_id')
            ?: data_get($context, 'current_invoice.property_id')
        );

        if ($customerId <= 0) {
            return [
                'customer_id' => null,
                'property_id' => null,
                'customer_name' => null,
            ];
        }

        $customer = Customer::byUser($accountId)
            ->with('defaultProperty')
            ->find($customerId);

        if (! $customer) {
            return [
                'customer_id' => null,
                'property_id' => null,
                'customer_name' => null,
            ];
        }

        $resolvedPropertyId = $propertyId > 0 && $customer->properties()->whereKey($propertyId)->exists()
            ? $propertyId
            : $customer->defaultProperty?->id;

        return [
            'customer_id' => $customer->id,
            'property_id' => $resolvedPropertyId,
            'customer_name' => $customer->company_name ?: trim($customer->first_name.' '.$customer->last_name),
        ];
    }

    private function resolvePlanScanJobTitle(UploadedFile $attachment, string $message): string
    {
        $normalizedMessage = trim($message);
        if ($normalizedMessage !== '') {
            return Str::limit($normalizedMessage, 80, '');
        }

        return preg_replace('/\.[^.]+$/', '', $attachment->getClientOriginalName()) ?: 'Assistant plan scan';
    }

    private function guessPlanTradeType(string $message): string
    {
        $normalized = Str::lower($message);
        $tradeKeywords = [
            'plumbing' => ['plomberie', 'plumbing', 'sink', 'lavabo', 'toilet', 'toilette', 'bathroom', 'salle de bain', 'water'],
            'electricity' => ['electricite', 'electricity', 'electrical', 'wiring', 'luminaire', 'breaker', 'outlet', 'prise'],
            'painting' => ['painting', 'peinture', 'paint', 'wall finish'],
            'carpentry' => ['menuiserie', 'carpentry', 'woodwork', 'cabinet', 'millwork', 'door', 'porte'],
            'masonry' => ['maconnerie', 'masonry', 'concrete', 'tile', 'carrelage', 'brick', 'brique'],
        ];

        foreach ($tradeKeywords as $trade => $keywords) {
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && Str::contains($normalized, $keyword)) {
                    return $trade;
                }
            }
        }

        return 'general';
    }

    private function guessPlanPriority(string $message): string
    {
        $normalized = Str::lower($message);

        if (preg_match('/\b(budget|cheap|economique|economy|low cost|moins cher|eco)\b/u', $normalized) === 1) {
            return 'cost';
        }

        if (preg_match('/\b(premium|haut de gamme|quality|qualite|top quality)\b/u', $normalized) === 1) {
            return 'quality';
        }

        return 'balanced';
    }

    private function shouldCreatePlanQuote(string $message): bool
    {
        if (trim($message) === '') {
            return false;
        }

        return preg_match('/\b(devis|quote|estimate|estimation|pricing|price|chiffrage|costing)\b/u', Str::lower($message)) === 1;
    }

    private function preferredPlanVariantKey($scan, string $message): string
    {
        $available = collect($scan->variants ?? [])->pluck('key')->filter()->values();
        if ($available->isEmpty()) {
            return 'standard';
        }

        $normalized = Str::lower($message);
        if (preg_match('/\b(eco|budget|economique|cheap|low cost)\b/u', $normalized) === 1 && $available->contains('eco')) {
            return 'eco';
        }

        if (preg_match('/\b(premium|haut de gamme|luxury|quality)\b/u', $normalized) === 1 && $available->contains('premium')) {
            return 'premium';
        }

        return $available->contains('standard') ? 'standard' : (string) $available->first();
    }

    private function buildPlanScanAssistantMessage($scan, UploadedFile $attachment, bool $quoteRequested, bool $quoteCreated, ?string $quoteNote): string
    {
        $lines = [];
        $lines[] = $scan->status === PlanScanService::STATUS_READY
            ? 'Plan recu. Le scan IA est pret.'
            : 'Plan recu. Le scan IA a ete cree et l analyse a demarre.';
        $lines[] = 'Fichier: '.$attachment->getClientOriginalName();

        if ($scan->trade_type) {
            $lines[] = 'Metier detecte: '.Str::headline($scan->trade_type).'.';
        }

        if ($quoteCreated) {
            $lines[] = 'Le devis brouillon a aussi ete cree automatiquement.';
        } elseif ($quoteRequested && $quoteNote) {
            $lines[] = $quoteNote;
        } else {
            $lines[] = 'Ouvrez le scan pour revoir les lignes detectees et valider le chiffrage.';
        }

        return implode("\n", $lines);
    }

    private function presentPlanScanForAssistant($scan, ?string $assistantStateLabel = null): array
    {
        return [
            'id' => $scan->id,
            'job_title' => $scan->job_title,
            'trade_type' => $scan->trade_type,
            'status' => $scan->status,
            'status_label' => match ($scan->status) {
                PlanScanService::STATUS_READY => 'Ready',
                PlanScanService::STATUS_FAILED => 'Failed',
                default => 'Processing',
            },
            'confidence_score' => $scan->confidence_score,
            'customer_name' => $scan->customer?->company_name ?: trim(($scan->customer?->first_name ?? '').' '.($scan->customer?->last_name ?? '')),
            'file_name' => $scan->plan_file_name,
            'review_required' => (bool) $scan->ai_review_required,
            'assistant_state_label' => $assistantStateLabel,
            'urls' => [
                'show' => route('plan-scans.show', $scan),
            ],
        ];
    }

    private function presentQuoteForAssistant($quote): array
    {
        return [
            'id' => $quote->id,
            'number' => $quote->number,
            'status' => $quote->status,
            'job_title' => $quote->job_title,
            'total' => (float) ($quote->total ?? 0),
            'urls' => [
                'edit' => route('customer.quote.edit', $quote),
            ],
        ];
    }
}
