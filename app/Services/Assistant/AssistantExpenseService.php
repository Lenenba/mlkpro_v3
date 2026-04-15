<?php

namespace App\Services\Assistant;

use App\Models\Expense;
use App\Models\User;
use App\Services\ExpenseAiDraftService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AssistantExpenseService
{
    public function __construct(private readonly ExpenseAiDraftService $draftService) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function matchesAttachmentIntent(User $user, UploadedFile $attachment, string $message, array $context): bool
    {
        $normalizedMessage = Str::lower(trim($message));
        $filename = Str::lower((string) pathinfo($attachment->getClientOriginalName(), PATHINFO_FILENAME));
        $pageComponent = Str::lower((string) data_get($context, 'page.component', ''));
        $pageUrl = Str::lower((string) data_get($context, 'page.url', ''));
        $haystack = trim($normalizedMessage.' '.$filename);

        if (str_starts_with($pageComponent, 'expense/') || Str::contains($pageUrl, '/expenses')) {
            return true;
        }

        if ((int) data_get($context, 'current_expense.id') > 0) {
            return true;
        }

        if ($haystack !== '' && preg_match(
            '/\b(depense|expense|facture|invoice|receipt|recu|reçu|supplier|vendor|achat|purchase|bill|note de frais|expense report)\b/u',
            $haystack
        ) === 1) {
            return true;
        }

        return trim($message) === '' && ! $user->hasCompanyFeature('plan_scans');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function handle(User $user, UploadedFile $attachment, string $message, array $context): array
    {
        $analysis = $this->draftService->analyzeDocument($user, $attachment, [
            'source' => 'assistant_chat',
            'assistant_message' => $message,
        ]);

        if ($this->requiresConfirmation($analysis)) {
            $pendingAction = $this->buildPendingAction($attachment, $analysis, $message, $context);
            $payload = $this->presentPendingChoice($pendingAction);
            $payload['usage'] = data_get($analysis, 'extraction.usage');

            return $payload;
        }

        $draft = $this->draftService->createFromAnalysis($user, $attachment, $analysis, [
            'source' => 'assistant_chat',
            'assistant_message' => $message,
        ]);

        return $this->buildCreatedPayload($draft, $context);
    }

    /**
     * @param  array<string, mixed>  $pendingAction
     * @return array<string, mixed>
     */
    public function executePending(array $pendingAction, User $user): array
    {
        $payload = is_array($pendingAction['payload'] ?? null) ? $pendingAction['payload'] : [];
        $stagedAttachment = is_array($payload['staged_attachment'] ?? null) ? $payload['staged_attachment'] : [];
        $attachment = $this->restoreStagedAttachment($stagedAttachment);

        if (! $attachment) {
            return [
                'status' => 'error',
                'message' => 'Le document joint n est plus disponible. Reimporte la facture pour continuer.',
                'context' => [
                    'pending_action' => null,
                ],
            ];
        }

        try {
            $analysis = is_array($payload['analysis'] ?? null) ? $payload['analysis'] : [];
            $draft = $this->draftService->createFromAnalysis($user, $attachment, $analysis, [
                'source' => 'assistant_chat',
                'assistant_message' => (string) ($payload['assistant_message'] ?? ''),
            ]);
        } finally {
            $this->deleteStagedAttachment($stagedAttachment);
        }

        $baseContext = is_array($payload['base_context'] ?? null) ? $payload['base_context'] : [];

        return $this->buildCreatedPayload($draft, $baseContext);
    }

    public function wantsOpenExisting(string $message, array $pendingAction): bool
    {
        if (! $this->pendingActionHasDuplicateChoice($pendingAction)) {
            return false;
        }

        $normalized = Str::lower(trim($message));

        return preg_match('/\b(ouvrir|open|voir|show|go)\b/u', $normalized) === 1
            || preg_match('/\b(depense existante|existing expense|doublon|duplicate)\b/u', $normalized) === 1;
    }

    public function wantsCreateAnyway(string $message): bool
    {
        $normalized = Str::lower(trim($message));

        return preg_match('/\b(cree|creer|create|enregistre|save|ajoute|add)\b/u', $normalized) === 1
            || preg_match('/\b(quand meme|anyway|despite|force)\b/u', $normalized) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function openExisting(array $pendingAction, User $user): array
    {
        $match = $this->bestPendingDuplicateMatch($pendingAction);
        $expenseId = (int) ($match['expense_id'] ?? 0);
        $expense = Expense::query()
            ->whereKey($expenseId)
            ->where('user_id', (int) ($user->accountOwnerId() ?? $user->id))
            ->first();

        if (! $expense) {
            return [
                'status' => 'error',
                'message' => 'La depense existante n est plus disponible. Reimporte le document si tu veux creer un nouveau brouillon.',
                'context' => [
                    'pending_action' => null,
                ],
            ];
        }

        $baseContext = is_array(data_get($pendingAction, 'payload.base_context')) ? data_get($pendingAction, 'payload.base_context') : [];
        $this->cancelPending($pendingAction);

        return [
            'status' => 'expense_existing_opened',
            'message' => 'Ouverture de la depense existante la plus proche.',
            'expense' => $this->presentExpense($expense),
            'action' => [
                'type' => 'open_expense',
                'expense_id' => $expense->id,
            ],
            'context' => array_merge($baseContext, [
                'pending_action' => null,
                'current_expense' => [
                    'id' => $expense->id,
                    'title' => $expense->title,
                    'status' => $expense->status,
                    'supplier_name' => $expense->supplier_name,
                    'total' => (float) ($expense->total ?? 0),
                    'currency_code' => $expense->currency_code,
                ],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $pendingAction
     */
    public function cancelPending(array $pendingAction): void
    {
        $payload = is_array($pendingAction['payload'] ?? null) ? $pendingAction['payload'] : [];
        $stagedAttachment = is_array($payload['staged_attachment'] ?? null) ? $payload['staged_attachment'] : [];

        $this->deleteStagedAttachment($stagedAttachment);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentPendingChoice(array $pendingAction): array
    {
        $summary = (string) ($pendingAction['summary'] ?? 'Une action est en attente.');
        $choice = $this->buildExpenseChoiceMeta($pendingAction);
        $suffix = $choice
            ? "\nChoisis une action ci-dessous ou reponds \"ouvrir\", \"creer quand meme\" ou \"annuler\"."
            : "\nConfirmer ? (oui/non)";

        return [
            'status' => 'needs_confirmation',
            'message' => $summary.$suffix,
            'expense_choice' => $choice,
            'context' => [
                'pending_action' => $pendingAction,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildCreatedPayload(array $draft, array $context): array
    {
        /** @var Expense $expense */
        $expense = $draft['expense'];
        $reviewRequired = (bool) data_get($expense->meta, 'ai_intake.review_required', true);

        return [
            'status' => 'expense_created',
            'message' => $this->buildAssistantMessage($expense, $reviewRequired),
            'expense' => $this->presentExpense($expense),
            'action' => [
                'type' => 'expense_created',
                'expense_id' => $expense->id,
            ],
            'context' => array_merge($context, [
                'pending_action' => null,
                'current_expense' => [
                    'id' => $expense->id,
                    'title' => $expense->title,
                    'status' => $expense->status,
                    'supplier_name' => $expense->supplier_name,
                    'total' => (float) ($expense->total ?? 0),
                    'currency_code' => $expense->currency_code,
                ],
            ]),
            'usage' => $draft['usage'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function requiresConfirmation(array $analysis): bool
    {
        return (bool) ($analysis['review_required'] ?? true);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildPendingAction(UploadedFile $attachment, array $analysis, string $message, array $context): array
    {
        $stagedAttachment = $this->stageAttachment($attachment);
        $summary = $this->buildClarificationSummary($analysis);

        return [
            'type' => 'create_expense_from_attachment',
            'summary' => $summary,
            'payload' => [
                'assistant_message' => $message,
                'analysis' => $this->compactAnalysis($analysis),
                'staged_attachment' => $stagedAttachment,
                'base_context' => array_merge($context, [
                    'pending_action' => null,
                ]),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function compactAnalysis(array $analysis): array
    {
        $extraction = is_array($analysis['extraction'] ?? null) ? $analysis['extraction'] : [];

        return [
            'account_id' => $analysis['account_id'] ?? null,
            'normalized' => $analysis['normalized'] ?? [],
            'duplicate_detection' => $analysis['duplicate_detection'] ?? [],
            'review_required' => (bool) ($analysis['review_required'] ?? true),
            'status' => $analysis['status'] ?? Expense::STATUS_REVIEW_REQUIRED,
            'extraction' => [
                'status' => $extraction['status'] ?? null,
                'model' => $extraction['model'] ?? null,
                'usage' => $extraction['usage'] ?? null,
                'normalized' => $analysis['normalized'] ?? [],
                'review_required' => (bool) ($analysis['review_required'] ?? true),
                'error_message' => $extraction['error_message'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function buildClarificationSummary(array $analysis): string
    {
        $normalized = is_array($analysis['normalized'] ?? null) ? $analysis['normalized'] : [];
        $duplicateDetection = is_array($analysis['duplicate_detection'] ?? null) ? $analysis['duplicate_detection'] : [];
        $reviewFlags = collect(data_get($analysis, 'extraction.normalized.review_flags', []))
            ->filter(fn ($flag) => is_string($flag) && trim($flag) !== '')
            ->values();

        $lines = ['J ai analyse le document, mais je prefere confirmer avant de creer la depense.'];

        if (filled($normalized['supplier_name'] ?? null)) {
            $lines[] = 'Fournisseur detecte: '.$normalized['supplier_name'].'.';
        }

        if (($normalized['total'] ?? null) !== null) {
            $lines[] = sprintf(
                'Montant detecte: %.2f %s.',
                (float) $normalized['total'],
                strtoupper((string) ($normalized['currency_code'] ?? ''))
            );
        }

        if ((bool) ($duplicateDetection['has_matches'] ?? false)) {
            $count = (int) ($duplicateDetection['match_count'] ?? count($duplicateDetection['matches'] ?? []));
            $lines[] = $duplicateDetection['exact_match_found'] ?? false
                ? "Cette facture semble deja exister sur le compte ({$count} doublon(s) probable(s))."
                : "J ai trouve {$count} depense(s) qui ressemblent deja a cette facture.";

            $firstMatch = $duplicateDetection['matches'][0] ?? null;
            if (is_array($firstMatch)) {
                $preview = collect([
                    $firstMatch['supplier_name'] ?? null,
                    $firstMatch['reference_number'] ?? null,
                    isset($firstMatch['total']) ? number_format((float) $firstMatch['total'], 2, '.', ' ') : null,
                ])->filter()->implode(' / ');

                if ($preview !== '') {
                    $lines[] = 'Exemple deja present: '.$preview.'.';
                }
            }
        }

        if ($reviewFlags->isNotEmpty()) {
            $lines[] = 'Points a verifier: '.$reviewFlags->take(2)->implode(' ');
        } else {
            $fieldIssues = collect(data_get($analysis, 'extraction.normalized.field_flags', []))
                ->filter(fn ($flag) => is_array($flag) && ($flag['status'] ?? null) !== 'ok')
                ->pluck('label')
                ->filter()
                ->values();

            if ($fieldIssues->isNotEmpty()) {
                $lines[] = 'Champs a revoir: '.$fieldIssues->take(3)->implode(', ').'.';
            }
        }

        $lines[] = 'Si tu confirmes, je cree un brouillon avec le document source attache.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $pendingAction
     * @return array<string, mixed>|null
     */
    private function buildExpenseChoiceMeta(array $pendingAction): ?array
    {
        $match = $this->bestPendingDuplicateMatch($pendingAction);
        if (! $match) {
            return null;
        }

        return [
            'mode' => 'duplicate_resolution',
            'title' => 'Facture similaire detectee',
            'subtitle' => 'Ouvre la depense existante ou cree quand meme un nouveau brouillon.',
            'existing_expense' => [
                'id' => $match['expense_id'],
                'title' => $match['title'] ?? null,
                'supplier_name' => $match['supplier_name'] ?? null,
                'reference_number' => $match['reference_number'] ?? null,
                'status' => $match['status'] ?? null,
                'total' => $match['total'] ?? null,
                'currency_code' => $match['currency_code'] ?? null,
                'reasons' => $match['reasons'] ?? [],
                'score' => $match['score'] ?? null,
            ],
            'choices' => [
                [
                    'type' => 'open_existing',
                    'label' => 'Ouvrir la depense existante',
                    'command' => 'ouvrir la depense existante',
                    'variant' => 'secondary',
                ],
                [
                    'type' => 'create_anyway',
                    'label' => 'Creer quand meme',
                    'command' => 'creer quand meme',
                    'variant' => 'primary',
                ],
                [
                    'type' => 'cancel',
                    'label' => 'Annuler',
                    'command' => 'annuler',
                    'variant' => 'ghost',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stageAttachment(UploadedFile $attachment): array
    {
        $path = $attachment->store('assistant-expense-staging', 'local');

        return [
            'disk' => 'local',
            'path' => $path,
            'original_name' => $attachment->getClientOriginalName(),
            'mime' => $attachment->getClientMimeType() ?? $attachment->getMimeType(),
        ];
    }

    /**
     * @param  array<string, mixed>  $stagedAttachment
     */
    private function restoreStagedAttachment(array $stagedAttachment): ?UploadedFile
    {
        $disk = (string) ($stagedAttachment['disk'] ?? 'local');
        $path = (string) ($stagedAttachment['path'] ?? '');
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $fullPath = Storage::disk($disk)->path($path);
        if (! is_file($fullPath)) {
            return null;
        }

        return new UploadedFile(
            $fullPath,
            (string) ($stagedAttachment['original_name'] ?? basename($path)),
            (string) ($stagedAttachment['mime'] ?? mime_content_type($fullPath)),
            null,
            true
        );
    }

    /**
     * @param  array<string, mixed>  $stagedAttachment
     */
    private function deleteStagedAttachment(array $stagedAttachment): void
    {
        $disk = (string) ($stagedAttachment['disk'] ?? 'local');
        $path = (string) ($stagedAttachment['path'] ?? '');
        if ($path !== '' && Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }

    /**
     * @param  array<string, mixed>  $pendingAction
     * @return array<string, mixed>|null
     */
    private function bestPendingDuplicateMatch(array $pendingAction): ?array
    {
        $matches = data_get($pendingAction, 'payload.analysis.duplicate_detection.matches', []);
        if (! is_array($matches)) {
            return null;
        }

        foreach ($matches as $match) {
            if (is_array($match) && (int) ($match['expense_id'] ?? 0) > 0) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $pendingAction
     */
    private function pendingActionHasDuplicateChoice(array $pendingAction): bool
    {
        return $this->bestPendingDuplicateMatch($pendingAction) !== null;
    }

    private function buildAssistantMessage(Expense $expense, bool $reviewRequired): string
    {
        $lines = ['Justificatif recu. Une depense brouillon a ete creee.'];

        if (filled($expense->supplier_name)) {
            $lines[] = 'Fournisseur: '.$expense->supplier_name.'.';
        }

        if ((float) $expense->total > 0) {
            $lines[] = sprintf(
                'Montant detecte: %.2f %s.',
                (float) $expense->total,
                strtoupper((string) $expense->currency_code)
            );
        }

        if (filled($expense->reference_number)) {
            $lines[] = 'Reference: '.$expense->reference_number.'.';
        }

        $lines[] = $reviewRequired
            ? 'Verifie la categorie, la date et les montants avant approbation.'
            : 'Les principaux champs ont ete pre-remplis automatiquement.';

        if ((bool) data_get($expense->meta, 'ai_intake.duplicate_detection.has_matches', false)) {
            $lines[] = 'Un risque de doublon a aussi ete detecte sur ce compte.';
        }

        $lines[] = 'La piece jointe source a ete ajoutee a la depense.';

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentExpense(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'title' => $expense->title,
            'status' => $expense->status,
            'supplier_name' => $expense->supplier_name,
            'reference_number' => $expense->reference_number,
            'currency_code' => $expense->currency_code,
            'total' => (float) ($expense->total ?? 0),
            'ai_review_required' => (bool) data_get($expense->meta, 'ai_intake.review_required', false),
            'urls' => [
                'show' => route('expense.show', $expense),
            ],
        ];
    }
}
