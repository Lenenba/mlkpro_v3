<?php

namespace App\Services;

use App\Enums\CurrencyCode;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\User;
use App\Utils\FileHandler;
use Illuminate\Http\UploadedFile;

class ExpenseAiDraftService
{
    public function __construct(
        private readonly ExpenseAiExtractor $extractor,
        private readonly ExpenseDuplicateDetectionService $duplicateDetectionService
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function analyzeDocument(User $user, UploadedFile $document, array $options = []): array
    {
        $accountId = (int) ($user->accountOwnerId() ?? $user->id);
        $accountOwner = (int) $user->id === $accountId
            ? $user
            : User::query()->find($accountId);

        $extraction = $this->extractor->extract($document, [
            'tenant_currency_code' => $accountOwner?->businessCurrencyCode() ?? CurrencyCode::default()->value,
            'note' => $options['note'] ?? null,
        ]);

        $normalized = is_array($extraction['normalized'] ?? null) ? $extraction['normalized'] : [];
        $duplicateDetection = $this->duplicateDetectionService->detect($accountId, $document, $normalized);
        $reviewRequired = (bool) ($extraction['review_required'] ?? true)
            || (bool) ($duplicateDetection['has_matches'] ?? false);
        $status = $reviewRequired
            ? Expense::STATUS_REVIEW_REQUIRED
            : Expense::STATUS_DRAFT;

        return [
            'account_id' => $accountId,
            'normalized' => $normalized,
            'extraction' => $extraction,
            'duplicate_detection' => $duplicateDetection,
            'review_required' => $reviewRequired,
            'status' => $status,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{expense: Expense, extraction: array<string, mixed>, message: string, usage: array<string, mixed>|null}
     */
    public function createFromDocument(User $user, UploadedFile $document, array $options = []): array
    {
        $analysis = $this->analyzeDocument($user, $document, $options);

        return $this->createFromAnalysis($user, $document, $analysis, $options);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $options
     * @return array{expense: Expense, extraction: array<string, mixed>, message: string, usage: array<string, mixed>|null}
     */
    public function createFromAnalysis(User $user, UploadedFile $document, array $analysis, array $options = []): array
    {
        $accountId = (int) ($analysis['account_id'] ?? ($user->accountOwnerId() ?? $user->id));
        $accountOwner = (int) $user->id === $accountId
            ? $user
            : User::query()->find($accountId);
        $normalized = is_array($analysis['normalized'] ?? null) ? $analysis['normalized'] : [];
        $extraction = is_array($analysis['extraction'] ?? null) ? $analysis['extraction'] : [];
        $duplicateDetection = is_array($analysis['duplicate_detection'] ?? null) ? $analysis['duplicate_detection'] : [];
        $reviewRequired = (bool) ($analysis['review_required'] ?? ($extraction['review_required'] ?? true));
        $status = (string) ($analysis['status'] ?? ($reviewRequired ? Expense::STATUS_REVIEW_REQUIRED : Expense::STATUS_DRAFT));

        $expense = Expense::query()->create($this->buildPayload(
            $accountId,
            (int) $user->id,
            $document,
            $normalized,
            $extraction,
            $duplicateDetection,
            $status,
            $accountOwner,
            $options
        ));

        $this->storeAttachmentFile($document, $expense, (int) $user->id);

        $expense->load([
            'creator:id,name',
            'approver:id,name',
            'payer:id,name',
            'attachments.user:id,name',
        ]);

        $message = $reviewRequired
            ? 'AI draft created. Review the extracted fields before approval.'
            : 'AI draft created successfully.';

        if ((bool) ($duplicateDetection['has_matches'] ?? false)) {
            $message .= ' Potential duplicate detected on this account.';
        }

        return [
            'expense' => $expense,
            'extraction' => $extraction,
            'message' => $message,
            'usage' => is_array($extraction['usage'] ?? null) ? $extraction['usage'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $extraction
     * @param  array<string, mixed>  $duplicateDetection
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildPayload(
        int $accountId,
        int $actorId,
        UploadedFile $document,
        array $normalized,
        array $extraction,
        array $duplicateDetection,
        string $status,
        ?User $accountOwner,
        array $options
    ): array {
        $total = round((float) ($normalized['total'] ?? 0), 2);
        $taxAmount = round((float) ($normalized['tax_amount'] ?? 0), 2);
        $subtotal = array_key_exists('subtotal', $normalized)
            && $normalized['subtotal'] !== null
            && $normalized['subtotal'] !== ''
                ? round((float) $normalized['subtotal'], 2)
                : round(max(0, $total - $taxAmount), 2);

        $aiIntake = [
            'status' => $extraction['status'] ?? null,
            'model' => $extraction['model'] ?? null,
            'usage' => $extraction['usage'] ?? null,
            'raw' => $extraction['raw'] ?? null,
            'normalized' => $normalized,
            'review_required' => $status === Expense::STATUS_REVIEW_REQUIRED,
            'error_message' => $extraction['error_message'] ?? null,
            'duplicate_detection' => $duplicateDetection,
            'source_file_name' => $document->getClientOriginalName(),
            'source_mime' => $document->getClientMimeType() ?? $document->getMimeType(),
            'source' => $options['source'] ?? 'expense_scan',
            'scanned_at' => now()->toIso8601String(),
        ];

        $assistantMessage = trim((string) ($options['assistant_message'] ?? ''));
        if ($assistantMessage !== '') {
            $aiIntake['assistant_message'] = $assistantMessage;
        }

        return [
            'user_id' => $accountId,
            'created_by_user_id' => $actorId,
            'approved_by_user_id' => null,
            'paid_by_user_id' => null,
            'title' => trim((string) ($normalized['title'] ?? 'Scanned expense')),
            'category_key' => $normalized['category_key'] ?? null,
            'supplier_name' => $normalized['supplier_name'] ?? null,
            'reference_number' => $normalized['reference_number'] ?? null,
            'currency_code' => CurrencyCode::tryFromMixed($normalized['currency_code'] ?? null)?->value
                ?? ($accountOwner?->businessCurrencyCode() ?? CurrencyCode::default()->value),
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'expense_date' => $normalized['expense_date'] ?? now()->toDateString(),
            'due_date' => $normalized['due_date'] ?? null,
            'paid_date' => null,
            'approved_at' => null,
            'payment_method' => null,
            'status' => $status,
            'reimbursable' => false,
            'is_recurring' => false,
            'description' => $normalized['description'] ?? null,
            'notes' => $options['note'] ?? null,
            'meta' => $this->appendWorkflowHistory([
                'ai_intake' => $aiIntake,
            ], 'created', null, $status, $actorId),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function appendWorkflowHistory(
        array $meta,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        int $actorId,
        ?string $comment = null
    ): array {
        $history = collect($meta['workflow_history'] ?? [])
            ->filter(fn ($entry) => is_array($entry))
            ->values()
            ->all();

        $history[] = array_filter([
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_id' => $actorId,
            'actor_name' => User::query()->whereKey($actorId)->value('name'),
            'comment' => $comment ? trim($comment) : null,
            'at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null && $value !== '');

        $meta['workflow_history'] = array_values($history);

        return $meta;
    }

    private function storeAttachmentFile(UploadedFile $file, Expense $expense, int $userId): void
    {
        $mime = $file->getClientMimeType() ?? $file->getMimeType();
        $hash = $this->hashUploadedFile($file);
        $path = str_starts_with((string) $mime, 'image/')
            ? FileHandler::storeFile('expense-attachments', $file)
            : $file->store('expense-attachments', 'public');

        ExpenseAttachment::query()->create([
            'expense_id' => $expense->id,
            'user_id' => $userId,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $mime,
            'size' => $file->getSize(),
            'meta' => array_filter([
                'hash_sha256' => $hash,
            ]),
        ]);
    }

    private function hashUploadedFile(UploadedFile $file): ?string
    {
        $realPath = $file->getRealPath();
        if (! $realPath || ! is_file($realPath)) {
            return null;
        }

        return hash_file('sha256', $realPath) ?: null;
    }
}
