<?php

namespace App\Http\Controllers;

use App\Models\Request as LeadRequest;
use App\Support\DataTablePagination;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Validation\ValidationException;

abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    protected function dataTablePerPageOptions(): array
    {
        return DataTablePagination::options();
    }

    protected function defaultDataTablePerPage(): int
    {
        return DataTablePagination::defaultPerPage();
    }

    protected function resolveDataTablePerPage(mixed $requestOrValue = null, ?int $default = null): int
    {
        $default ??= $this->defaultDataTablePerPage();

        if ($requestOrValue instanceof Request || $requestOrValue === null) {
            return DataTablePagination::fromRequest($requestOrValue, $default);
        }

        return DataTablePagination::resolve($requestOrValue, $default);
    }

    protected function inertiaOrJson(string $component, array $props)
    {
        if ($this->shouldReturnJson()) {
            return response()->json($props);
        }

        return inertia($component, $props);
    }

    protected function shouldReturnJson(?Request $request = null): bool
    {
        $request = $request ?? request();

        if ($request->is('api/*')) {
            return true;
        }

        return $request->expectsJson() && ! $request->headers->has('X-Inertia');
    }

    /**
     * @param  array<int, mixed>  $selectedIds
     * @param  array<int, mixed>  $processedIds
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function bulkActionResult(
        string $message,
        array $selectedIds,
        array $processedIds,
        array $extra = []
    ): array {
        $selected = collect($selectedIds)
            ->map(fn (mixed $id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $processed = collect($processedIds)
            ->map(fn (mixed $id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $failedCount = max(0, (int) ($extra['failed_count'] ?? 0));
        $successCount = array_key_exists('success_count', $extra)
            ? max(0, (int) $extra['success_count'])
            : $processed->count();
        $skippedCount = array_key_exists('skipped_count', $extra)
            ? max(0, (int) $extra['skipped_count'])
            : max(0, $selected->count() - $processed->count() - $failedCount);
        $errors = $extra['errors'] ?? [];

        unset(
            $extra['success_count'],
            $extra['failed_count'],
            $extra['skipped_count'],
            $extra['errors']
        );

        return array_merge([
            'message' => $message,
            'ids' => $selected->all(),
            'processed_ids' => $processed->all(),
            'selected_count' => $selected->count(),
            'processed_count' => $processed->count(),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'skipped_count' => $skippedCount,
            'errors' => is_array($errors) ? array_values($errors) : [],
        ], $extra);
    }

    protected function ensureLeadIsMutable(
        LeadRequest $lead,
        string $field = 'lead',
        ?string $message = null
    ): void {
        if (! $lead->isArchived()) {
            return;
        }

        throw ValidationException::withMessages([
            $field => [$message ?? 'Archived prospects must be restored before they can be updated.'],
        ]);
    }
}
