<?php

namespace App\Services;

use App\Enums\CurrencyCode;
use App\Services\Assistant\OpenAiClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ExpenseAiExtractor
{
    public function __construct(private readonly OpenAiClient $client) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function extract(UploadedFile $file, array $context = []): array
    {
        if (! config('services.openai.key')) {
            return $this->fallbackPayload(
                $file,
                'skipped',
                'OpenAI is not configured for expense extraction.',
                $context
            );
        }

        try {
            $model = (string) config('services.openai.expense_scan_model', config('services.openai.model', 'gpt-4o-mini'));
            $response = $this->client->chat(
                $this->buildMessages($file, $context),
                [
                    'model' => $model,
                    'temperature' => 0.1,
                    'timeout' => (int) config('services.openai.expense_scan_timeout', 90),
                ]
            );

            $content = $this->client->extractMessage($response);
            $decoded = $this->decodeJson($content);
            $normalized = $this->normalizePayload($file, $decoded, $context);
            $usage = $this->client->extractUsage($response);

            return [
                'status' => 'completed',
                'model' => $usage['model'] ?? $model,
                'usage' => $usage,
                'raw' => [
                    'payload' => $decoded,
                    'source_file' => [
                        'name' => $file->getClientOriginalName(),
                        'mime' => $this->detectMimeType($file),
                        'size' => $file->getSize(),
                    ],
                ],
                'normalized' => $normalized,
                'review_required' => $this->reviewRequired($normalized),
                'error_message' => null,
            ];
        } catch (\Throwable $exception) {
            return $this->fallbackPayload($file, 'failed', $exception->getMessage(), $context);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function buildMessages(UploadedFile $file, array $context): array
    {
        $filename = (string) ($file->getClientOriginalName() ?: 'expense-document');
        $mimeType = $this->detectMimeType($file);
        $content = [
            [
                'type' => 'text',
                'text' => $this->buildUserPrompt($context),
            ],
        ];

        $filePayload = $this->filePayload($file, $mimeType);

        if (str_starts_with($mimeType, 'image/')) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $filePayload,
                ],
            ];
        } else {
            $content[] = [
                'type' => 'file',
                'file' => [
                    'filename' => $filename,
                    'file_data' => $filePayload,
                ],
            ];
        }

        return [
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $content,
            ],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are an AI assistant that extracts structured finance data from a supplier invoice or receipt.
Return JSON only. Do not include markdown or explanatory prose outside the JSON object.

Be conservative. If a field is not visible or not reliable, return null and add a review flag.
Never invent totals or dates you cannot support from the document.
Map the suggested category to one of these allowed values when possible:
inventory, materials, travel, fuel, software, marketing, rent, utilities, professional_services, taxes_fees, reimbursement, equipment, other

Return a JSON object with this exact shape:
{
  "document_type": "invoice|receipt|other",
  "title": "",
  "supplier_name": null,
  "reference_number": null,
  "expense_date": null,
  "due_date": null,
  "currency_code": null,
  "subtotal": null,
  "tax_amount": null,
  "total": null,
  "suggested_category": null,
  "description": null,
  "assumptions": [],
  "review_flags": [],
  "confidence": {
    "overall": 0,
    "supplier": 0,
    "amounts": 0,
    "dates": 0,
    "category": 0
  }
}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function buildUserPrompt(array $context): string
    {
        $tenantCurrency = strtoupper((string) ($context['tenant_currency_code'] ?? CurrencyCode::default()->value));
        $note = trim((string) ($context['note'] ?? ''));

        return <<<PROMPT
Analyze the attached supplier invoice or receipt and extract expense fields.

Context:
- tenant business currency: {$tenantCurrency}
- optional user note: {$note}

Focus on:
- supplier or merchant name
- invoice or receipt number
- expense date
- due date if present
- currency
- subtotal
- tax amount
- total amount
- best matching expense category
- a short useful title for the expense record
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalizePayload(UploadedFile $file, array $decoded, array $context): array
    {
        $title = $this->normalizeString($decoded['title'] ?? null)
            ?: $this->fallbackTitle($file, $decoded['supplier_name'] ?? null);
        $supplierName = $this->normalizeString($decoded['supplier_name'] ?? null);
        $referenceNumber = $this->normalizeString($decoded['reference_number'] ?? null);
        $expenseDate = $this->normalizeDate($decoded['expense_date'] ?? null);
        $dueDate = $this->normalizeDate($decoded['due_date'] ?? null);
        $subtotal = $this->normalizeNullableFloat($decoded['subtotal'] ?? null);
        $taxAmount = $this->normalizeNullableFloat($decoded['tax_amount'] ?? null) ?? 0.0;
        $total = $this->normalizeNullableFloat($decoded['total'] ?? null);

        if ($total === null && $subtotal !== null) {
            $total = round($subtotal + $taxAmount, 2);
        }

        if ($subtotal === null && $total !== null) {
            $subtotal = round(max(0, $total - $taxAmount), 2);
        }

        $normalized = [
            'document_type' => $this->normalizeDocumentType($decoded['document_type'] ?? null),
            'title' => $title,
            'supplier_name' => $supplierName,
            'reference_number' => $referenceNumber,
            'expense_date' => $expenseDate,
            'due_date' => $dueDate,
            'currency_code' => $this->normalizeCurrencyCode(
                $decoded['currency_code'] ?? null,
                (string) ($context['tenant_currency_code'] ?? CurrencyCode::default()->value)
            ),
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'category_key' => $this->normalizeCategory(
                $decoded['suggested_category'] ?? null,
                $supplierName,
                $title,
                $this->normalizeString($decoded['description'] ?? null)
            ),
            'description' => $this->normalizeString($decoded['description'] ?? null),
            'assumptions' => $this->normalizeStringList($decoded['assumptions'] ?? []),
            'review_flags' => $this->normalizeStringList($decoded['review_flags'] ?? []),
            'confidence' => [
                'overall' => $this->normalizeConfidence(data_get($decoded, 'confidence.overall')),
                'supplier' => $this->normalizeConfidence(data_get($decoded, 'confidence.supplier')),
                'amounts' => $this->normalizeConfidence(data_get($decoded, 'confidence.amounts')),
                'dates' => $this->normalizeConfidence(data_get($decoded, 'confidence.dates')),
                'category' => $this->normalizeConfidence(data_get($decoded, 'confidence.category')),
            ],
        ];

        $normalized['field_flags'] = $this->buildFieldFlags($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function fallbackPayload(UploadedFile $file, string $status, string $error, array $context): array
    {
        $normalized = [
            'document_type' => 'other',
            'title' => $this->fallbackTitle($file, null),
            'supplier_name' => null,
            'reference_number' => null,
            'expense_date' => now()->toDateString(),
            'due_date' => null,
            'currency_code' => strtoupper((string) ($context['tenant_currency_code'] ?? CurrencyCode::default()->value)),
            'subtotal' => null,
            'tax_amount' => 0.0,
            'total' => null,
            'category_key' => 'other',
            'description' => $this->normalizeString($context['note'] ?? null),
            'assumptions' => [
                'AI extraction was unavailable, so the draft was created with a conservative fallback.',
            ],
            'review_flags' => [
                $status === 'failed'
                    ? 'AI extraction failed and this expense draft needs manual review.'
                    : 'AI extraction is not configured, so this expense draft needs manual review.',
            ],
            'confidence' => [
                'overall' => 25,
                'supplier' => 0,
                'amounts' => 0,
                'dates' => 20,
                'category' => 35,
            ],
        ];

        $normalized['field_flags'] = $this->buildFieldFlags($normalized);

        return [
            'status' => $status,
            'model' => null,
            'usage' => null,
            'raw' => null,
            'normalized' => $normalized,
            'review_required' => true,
            'error_message' => $error,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function reviewRequired(array $normalized): bool
    {
        if (($normalized['confidence']['overall'] ?? 0) < 80) {
            return true;
        }

        foreach (($normalized['field_flags'] ?? []) as $flag) {
            if (($flag['status'] ?? null) !== 'ok') {
                return true;
            }
        }

        return ! empty($normalized['review_flags']);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<int, array<string, mixed>>
     */
    private function buildFieldFlags(array $normalized): array
    {
        return [
            $this->buildFieldFlag(
                'supplier_name',
                'Supplier',
                $normalized['supplier_name'] ?? null,
                (int) data_get($normalized, 'confidence.supplier', 0),
                'Supplier name extracted from the document.'
            ),
            $this->buildFieldFlag(
                'total',
                'Total',
                isset($normalized['total']) && $normalized['total'] !== null ? (string) $normalized['total'] : null,
                (int) data_get($normalized, 'confidence.amounts', 0),
                'Total amount extracted from the document.'
            ),
            $this->buildFieldFlag(
                'expense_date',
                'Expense date',
                $normalized['expense_date'] ?? null,
                (int) data_get($normalized, 'confidence.dates', 0),
                'Expense date extracted from the document.'
            ),
            $this->buildFieldFlag(
                'category_key',
                'Category',
                $normalized['category_key'] ?? null,
                (int) data_get($normalized, 'confidence.category', 0),
                'Expense category suggested from the document context.'
            ),
        ];
    }

    private function buildFieldFlag(string $field, string $label, ?string $value, int $confidence, string $message): array
    {
        $status = 'review';

        if ($value === null || $value === '') {
            $status = 'missing';
        } elseif ($confidence >= 85) {
            $status = 'ok';
        }

        return [
            'field' => $field,
            'label' => $label,
            'value' => $value,
            'confidence' => $confidence,
            'status' => $status,
            'message' => $message,
        ];
    }

    private function decodeJson(string $content): array
    {
        $trimmed = trim($content);
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed) ?? $trimmed;
        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function filePayload(UploadedFile $file, string $mimeType): string
    {
        $contents = file_get_contents($file->getRealPath());
        $encoded = base64_encode($contents === false ? '' : $contents);

        return sprintf('data:%s;base64,%s', $mimeType, $encoded);
    }

    private function detectMimeType(UploadedFile $file): string
    {
        $mimeType = $file->getClientMimeType() ?: $file->getMimeType();
        if (is_string($mimeType) && $mimeType !== '') {
            return $mimeType;
        }

        return match (strtolower($file->getClientOriginalExtension())) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    private function fallbackTitle(UploadedFile $file, mixed $supplierName): string
    {
        $supplier = $this->normalizeString($supplierName);
        if ($supplier !== null) {
            return $supplier.' expense';
        }

        $base = pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME);
        $base = trim((string) $base);

        return $base !== '' ? Str::headline($base) : 'Scanned expense';
    }

    private function normalizeDocumentType(mixed $value): string
    {
        $normalized = $this->normalizeString($value);
        if (! in_array($normalized, ['invoice', 'receipt', 'other'], true)) {
            return 'other';
        }

        return $normalized;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeCurrencyCode(mixed $value, string $fallback): string
    {
        $normalized = CurrencyCode::tryFromMixed($value)?->value;
        if ($normalized !== null) {
            return $normalized;
        }

        return CurrencyCode::tryFromMixed($fallback)?->value
            ?? CurrencyCode::default()->value;
    }

    private function normalizeCategory(mixed $value, ?string $supplier, ?string $title, ?string $description): string
    {
        $allowed = collect(config('expenses.categories', []))
            ->pluck('key')
            ->filter(fn ($item) => is_string($item) && $item !== '')
            ->values()
            ->all();

        $normalized = $this->normalizeString($value);
        if (is_string($normalized) && in_array($normalized, $allowed, true)) {
            return $normalized;
        }

        $haystack = Str::lower(implode(' ', array_filter([
            $normalized,
            $supplier,
            $title,
            $description,
        ])));

        $aliases = [
            'fuel' => ['fuel', 'gas', 'petrol', 'station', 'diesel'],
            'software' => ['software', 'saas', 'subscription', 'hosting', 'domain', 'license'],
            'rent' => ['rent', 'lease'],
            'utilities' => ['utility', 'internet', 'phone', 'electricity', 'water', 'hydro'],
            'marketing' => ['marketing', 'ads', 'facebook', 'instagram', 'google ads', 'campaign'],
            'inventory' => ['inventory', 'stock', 'resale'],
            'materials' => ['material', 'supplies', 'construction', 'hardware store'],
            'travel' => ['travel', 'hotel', 'flight', 'taxi', 'uber'],
            'professional_services' => ['consulting', 'legal', 'accounting', 'bookkeeping', 'agency'],
            'taxes_fees' => ['tax', 'fee', 'duty', 'permit'],
            'reimbursement' => ['reimbursement', 'refund', 'mileage'],
            'equipment' => ['equipment', 'laptop', 'printer', 'phone purchase', 'hardware'],
        ];

        foreach ($aliases as $category => $terms) {
            foreach ($terms as $term) {
                if ($haystack !== '' && Str::contains($haystack, Str::lower($term))) {
                    return $category;
                }
            }
        }

        return 'other';
    }

    private function normalizeNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function normalizeConfidence(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 0;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($item) {
            return $this->normalizeString($item);
        }, $value)));
    }
}
