<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExpenseDuplicateDetectionService
{
    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    public function detect(int $accountId, UploadedFile $file, array $normalized): array
    {
        $fileHash = $this->hashUploadedFile($file);
        $matches = collect();

        $exactMatches = $this->findExactFileMatches($accountId, $file, $fileHash);
        if ($exactMatches->isNotEmpty()) {
            $matches = $matches->concat($exactMatches);
        }

        $heuristicMatches = $this->findHeuristicMatches($accountId, $normalized)
            ->reject(fn (array $match) => $matches->contains(fn (array $existing) => (int) $existing['expense_id'] === (int) $match['expense_id']));

        $matches = $matches
            ->concat($heuristicMatches)
            ->sortByDesc('score')
            ->values()
            ->take(5)
            ->map(function (array $match) {
                $expense = $match['expense'];

                return [
                    'expense_id' => $expense->id,
                    'title' => $expense->title,
                    'supplier_name' => $expense->supplier_name,
                    'reference_number' => $expense->reference_number,
                    'status' => $expense->status,
                    'total' => (float) ($expense->total ?? 0),
                    'currency_code' => $expense->currency_code,
                    'expense_date' => optional($expense->expense_date)->toDateString(),
                    'created_at' => optional($expense->created_at)->toIso8601String(),
                    'score' => (int) ($match['score'] ?? 0),
                    'exact' => (bool) ($match['exact'] ?? false),
                    'reasons' => array_values(array_unique(array_filter($match['reasons'] ?? []))),
                ];
            })
            ->all();

        return [
            'file_hash_sha256' => $fileHash,
            'has_matches' => ! empty($matches),
            'exact_match_found' => collect($matches)->contains(fn (array $match) => (bool) ($match['exact'] ?? false)),
            'match_count' => count($matches),
            'matches' => $matches,
        ];
    }

    private function findExactFileMatches(int $accountId, UploadedFile $file, ?string $fileHash): Collection
    {
        if (! $fileHash) {
            return collect();
        }

        $candidates = ExpenseAttachment::query()
            ->where('size', $file->getSize())
            ->whereHas('expense', fn ($builder) => $builder->where('user_id', $accountId))
            ->with('expense')
            ->latest('id')
            ->get();

        return $candidates
            ->map(function (ExpenseAttachment $attachment) use ($fileHash) {
                $storedHash = $this->resolveAttachmentHash($attachment);
                if (! $storedHash || ! hash_equals($fileHash, $storedHash)) {
                    return null;
                }

                return [
                    'expense_id' => $attachment->expense_id,
                    'expense' => $attachment->expense,
                    'score' => 100,
                    'exact' => true,
                    'reasons' => ['same_file'],
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function findHeuristicMatches(int $accountId, array $normalized): Collection
    {
        $reference = $this->normalizeReference($normalized['reference_number'] ?? null);
        $supplier = $this->normalizeString($normalized['supplier_name'] ?? null);
        $total = isset($normalized['total']) && $normalized['total'] !== null ? round((float) $normalized['total'], 2) : null;
        $expenseDate = isset($normalized['expense_date']) ? (string) $normalized['expense_date'] : null;

        if (! $reference && ! $supplier && $total === null) {
            return collect();
        }

        $candidates = Expense::query()
            ->byAccount($accountId)
            ->latest('id')
            ->limit(150)
            ->get();

        return $candidates
            ->map(function (Expense $expense) use ($reference, $supplier, $total, $expenseDate) {
                $score = 0;
                $reasons = [];

                $expenseReference = $this->normalizeReference($expense->reference_number);
                $expenseSupplier = $this->normalizeString($expense->supplier_name);
                $expenseTotal = $expense->total !== null ? round((float) $expense->total, 2) : null;
                $expenseExpenseDate = optional($expense->expense_date)->toDateString();

                if ($reference && $expenseReference && $reference === $expenseReference) {
                    $score += 70;
                    $reasons[] = 'same_reference';
                }

                if ($supplier && $expenseSupplier && $supplier === $expenseSupplier) {
                    $score += 20;
                    $reasons[] = 'same_supplier';
                }

                if ($total !== null && $expenseTotal !== null && abs($total - $expenseTotal) < 0.01) {
                    $score += 25;
                    $reasons[] = 'same_total';
                }

                if ($expenseDate && $expenseExpenseDate && $expenseDate === $expenseExpenseDate) {
                    $score += 15;
                    $reasons[] = 'same_expense_date';
                }

                if ($score < 60) {
                    return null;
                }

                $exact = in_array('same_reference', $reasons, true)
                    && in_array('same_total', $reasons, true);

                return [
                    'expense_id' => $expense->id,
                    'expense' => $expense,
                    'score' => $exact ? max($score, 95) : $score,
                    'exact' => $exact,
                    'reasons' => $reasons,
                ];
            })
            ->filter()
            ->values();
    }

    private function resolveAttachmentHash(ExpenseAttachment $attachment): ?string
    {
        $storedHash = data_get($attachment->meta, 'hash_sha256');
        if (is_string($storedHash) && $storedHash !== '') {
            return $storedHash;
        }

        if (! $attachment->path || ! Storage::disk('public')->exists($attachment->path)) {
            return null;
        }

        $path = Storage::disk('public')->path($attachment->path);
        if (! is_file($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path) ?: null;
        if (! $hash) {
            return null;
        }

        $meta = is_array($attachment->meta) ? $attachment->meta : [];
        $meta['hash_sha256'] = $hash;
        $attachment->forceFill(['meta' => $meta])->save();

        return $hash;
    }

    private function hashUploadedFile(UploadedFile $file): ?string
    {
        $realPath = $file->getRealPath();
        if (! $realPath || ! is_file($realPath)) {
            return null;
        }

        return hash_file('sha256', $realPath) ?: null;
    }

    private function normalizeReference(mixed $value): ?string
    {
        $string = $this->normalizeString($value);
        if (! $string) {
            return null;
        }

        $normalized = preg_replace('/[^a-z0-9]/i', '', $string);

        return $normalized ? Str::upper($normalized) : null;
    }

    private function normalizeString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? Str::lower(Str::squish($string)) : null;
    }
}
