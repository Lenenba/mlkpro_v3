<?php

namespace App\Services\Accounting;

use App\Models\AccountingEntry;
use App\Models\AccountingExport;
use App\Models\ActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AccountingExportService
{
    public function __construct(
        private readonly AccountingReadService $readService
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function generateCsv(User $actor, int $accountId, array $filters): AccountingExport
    {
        $periodKey = $this->normalizePeriodKey($filters['period'] ?? null);
        [$startDate, $endDate] = $this->periodRange($periodKey);
        $timestamp = now();
        $filename = $this->filename($accountId, $periodKey, $timestamp);
        $path = 'accounting/exports/account-'.$accountId.'/'.$filename;

        $handle = fopen('php://temp', 'w+');
        $rowCount = 0;
        $batchIds = [];

        fputcsv($handle, [
            'entry_id',
            'batch_id',
            'entry_date',
            'currency_code',
            'account_code',
            'account_name',
            'account_type',
            'direction',
            'amount',
            'tax_amount',
            'description',
            'review_status',
            'batch_status',
            'source_type',
            'source_event_key',
            'source_reference',
            'source_id',
            'source_url',
        ]);

        $this->readService->query($accountId, $filters)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->chunk(200, function ($entries) use ($handle, &$rowCount, &$batchIds): void {
                foreach ($entries as $entry) {
                    $batchIds[] = $entry->batch_id;
                    $rowCount++;

                    fputcsv($handle, $this->csvRow($entry));
                }
            });

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        Storage::disk('local')->put($path, $contents ?: '');

        $export = AccountingExport::query()->create([
            'user_id' => $accountId,
            'period_key' => $periodKey,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'format' => AccountingExport::FORMAT_CSV,
            'status' => AccountingExport::STATUS_GENERATED,
            'path' => $path,
            'generated_by' => $actor->id,
            'generated_at' => $timestamp,
            'meta' => [
                'filename' => $filename,
                'row_count' => $rowCount,
                'batch_count' => collect($batchIds)->filter()->unique()->count(),
                'filters' => $this->normalizedFilters($filters),
            ],
        ]);

        ActivityLog::record(
            $actor,
            $export,
            'accounting.export.generated',
            [
                'format' => AccountingExport::FORMAT_CSV,
                'period_key' => $periodKey,
                'row_count' => $rowCount,
            ],
            'Accounting export generated'
        );

        return $export->fresh(['generatedBy']);
    }

    /**
     * @return array<int, scalar|null>
     */
    private function csvRow(AccountingEntry $entry): array
    {
        return [
            $entry->id,
            $entry->batch_id,
            optional($entry->entry_date)->toDateString(),
            $entry->currency_code,
            $entry->account?->code,
            $entry->account?->name,
            $entry->account?->type,
            $entry->direction,
            (float) $entry->amount,
            (float) $entry->tax_amount,
            $entry->description,
            $entry->review_status,
            $entry->batch?->status,
            $entry->batch?->source_type,
            $entry->batch?->source_event_key,
            $entry->batch?->source_reference,
            $entry->batch?->source_id,
            data_get($entry->batch?->meta, 'source_url'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizedFilters(array $filters): array
    {
        return collect($filters)
            ->except(['per_page'])
            ->filter(fn (mixed $value): bool => filled($value))
            ->all();
    }

    private function normalizePeriodKey(mixed $period): ?string
    {
        return is_string($period) && preg_match('/^\d{4}-\d{2}$/', $period)
            ? $period
            : null;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function periodRange(?string $periodKey): array
    {
        if (! $periodKey) {
            return [null, null];
        }

        $start = Carbon::parse($periodKey.'-01')->startOfMonth();
        $end = (clone $start)->endOfMonth();

        return [$start->toDateString(), $end->toDateString()];
    }

    private function filename(int $accountId, ?string $periodKey, Carbon $timestamp): string
    {
        $periodSegment = $periodKey ? Str::slug($periodKey) : 'all-periods';

        return 'accounting-export-'.$accountId.'-'.$periodSegment.'-'.$timestamp->format('Ymd-His').'.csv';
    }
}
