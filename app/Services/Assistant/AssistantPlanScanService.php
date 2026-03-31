<?php

namespace App\Services\Assistant;

use App\Models\PlanScan;
use App\Models\Quote;
use App\Models\User;
use App\Services\PlanScanQuoteService;
use App\Services\PlanScanReviewService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssistantPlanScanService
{
    public function __construct(
        private readonly OpenAiClient $client,
        private readonly PlanScanReviewService $reviewService,
        private readonly PlanScanQuoteService $quoteService
    ) {}

    public function canHandle(string $message, array $context): bool
    {
        if ((int) data_get($context, 'current_plan_scan.id') <= 0) {
            return false;
        }

        if (trim($message) === '') {
            return false;
        }

        return preg_match(
            '/\b(plan|scan|devis|quote|estimate|pricing|chiffrage|surface|piece|pieces|room|rooms|salle|salles|bath|lavabo|sink|baignoire|toilet|retire|remove|ajoute|add|passe|switch|change|modifie|update|premium|standard|eco|budget|quality|qualite|plomberie|electricite|peinture|menuiserie|maconnerie)\b/u',
            Str::lower($message)
        ) === 1;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function handle(User $user, string $message, array $context): ?array
    {
        if (! $this->canHandle($message, $context)) {
            return null;
        }

        $scan = $this->resolveCurrentScan($user, $context);
        if (! $scan) {
            return [
                'status' => 'error',
                'message' => 'Le scan lie a cette conversation est introuvable. Relance un plan ou ouvre une fiche scan.',
                'context' => array_merge($context, [
                    'current_plan_scan' => null,
                ]),
            ];
        }

        if ($scan->status !== \App\Services\PlanScanService::STATUS_READY) {
            return [
                'status' => 'plan_scan_processing',
                'message' => 'Ce scan est encore en cours d analyse. Attendez la fin du traitement avant de le reprendre dans le chat.',
                'scan' => $scan,
                'context' => $this->mergeScanContext($context, $scan),
            ];
        }

        $interpretation = $this->interpret($scan, $message);
        $usage = $this->client->extractUsage($interpretation['response']);
        $instruction = $interpretation['instruction'];

        if (($instruction['intent'] ?? 'unknown') === 'unknown') {
            return null;
        }

        $quote = null;
        $assistantStateLabel = 'AI suggestion';
        $status = 'plan_scan_review_updated';
        $summaryLine = trim((string) ($instruction['summary'] ?? ''));
        $variantPreference = $this->normalizeVariantPreference($instruction['variant_preference'] ?? null);

        if (in_array($instruction['intent'], ['review_scan', 'review_and_create_quote'], true)) {
            $reviewedPayload = $this->applyInstructionToPayload($scan, $instruction, $user, $message);
            $scan = $this->reviewService->applyReviewedPayload($scan, $user, $reviewedPayload, [
                'source' => 'assistant_chat',
                'activity' => 'reviewed',
                'activity_message' => 'Plan scan updated from assistant chat',
                'summary' => 'Estimation mise a jour a partir des instructions envoyees dans le chat.',
                'assistant_instruction' => $message,
                'ai_review_required' => false,
            ]);
            $assistantStateLabel = 'Reviewed in chat';
        }

        if (in_array($instruction['intent'], ['create_quote', 'review_and_create_quote'], true)) {
            try {
                $quote = $this->quoteService->createQuoteFromScan(
                    $scan,
                    $user,
                    $variantPreference ?? 'standard'
                );
                $status = 'plan_scan_quote_created';
                $assistantStateLabel = 'Quote draft created';
            } catch (ValidationException $exception) {
                return [
                    'status' => 'plan_scan_review_updated',
                    'message' => collect($exception->errors())->flatten()->first() ?: 'Le devis n a pas pu etre cree.',
                    'scan' => $scan,
                    'usage' => $usage,
                    'context' => $this->mergeScanContext($context, $scan),
                ];
            }
        }

        $messageLines = [];
        $messageLines[] = $summaryLine !== '' ? $summaryLine : $this->defaultAssistantMessage($instruction['intent'], $scan, $quote);
        if ($quote === null) {
            $messageLines[] = 'Le scan standard a ete mis a jour et reste visible dans le module Plan Scan.';
        }

        return [
            'status' => $status,
            'message' => implode("\n", array_filter($messageLines)),
            'scan' => $scan,
            'quote' => $quote,
            'assistant_state_label' => $assistantStateLabel,
            'usage' => $usage,
            'context' => $this->mergeScanContext($context, $scan, $quote),
        ];
    }

    /**
     * @return array{response: array<string, mixed>, instruction: array<string, mixed>}
     */
    private function interpret(PlanScan $scan, string $message): array
    {
        $response = $this->client->chat([
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $this->buildUserPrompt($scan, $message),
            ],
        ], [
            'model' => config('services.openai.model', 'gpt-4o-mini'),
            'temperature' => 0.1,
        ]);

        $content = trim($this->client->extractMessage($response));
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
        $decoded = json_decode(trim($content), true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'assistant' => ['Assistant returned invalid plan scan refinement JSON.'],
            ]);
        }

        return [
            'response' => $response,
            'instruction' => $this->normalizeInstruction($decoded),
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a structured assistant for reviewing a plan scan draft.
Return JSON only. Do not include markdown or prose outside the JSON object.

Allowed intents:
- review_scan
- create_quote
- review_and_create_quote
- unknown

You are editing the reviewed scan payload, not the final commercial quote.
Only update fields the user explicitly asks to change.
If the user asks to create a quote from the current scan, set intent to create_quote or review_and_create_quote.
If the user instruction is unrelated to the current plan scan, set intent to unknown.

Allowed trade_type values:
plumbing, carpentry, electricity, painting, masonry, general

Allowed priority values:
cost, balanced, quality

Allowed variant_preference values:
eco, standard, premium

Return this exact JSON shape:
{
  "intent": "review_scan|create_quote|review_and_create_quote|unknown",
  "summary": "",
  "trade_type": null,
  "surface_m2": null,
  "rooms": null,
  "priority": null,
  "variant_preference": null,
  "remove_line_names": [],
  "upsert_lines": [
    {
      "match_name": "",
      "name": "",
      "quantity": null,
      "unit": "",
      "base_cost": null,
      "is_labor": null,
      "description": "",
      "notes": ""
    }
  ]
}
PROMPT;
    }

    private function buildUserPrompt(PlanScan $scan, string $message): string
    {
        $payload = [
            'scan' => [
                'id' => $scan->id,
                'status' => $scan->status,
                'trade_type' => $scan->trade_type,
                'ai_review_required' => (bool) $scan->ai_review_required,
                'metrics' => $scan->metrics ?? [],
                'analysis_ai' => data_get($scan->analysis, 'ai', []),
                'variants' => collect($scan->variants ?? [])->map(fn (array $variant) => [
                    'key' => $variant['key'] ?? null,
                    'label' => $variant['label'] ?? null,
                    'total' => $variant['total'] ?? null,
                ])->values()->all(),
                'reviewed_payload' => $scan->ai_reviewed_payload ?? [],
            ],
            'user_instruction' => $message,
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function resolveCurrentScan(User $user, array $context): ?PlanScan
    {
        $scanId = (int) data_get($context, 'current_plan_scan.id');
        if ($scanId <= 0) {
            return null;
        }

        return PlanScan::query()
            ->with(['customer', 'property'])
            ->whereKey($scanId)
            ->where('user_id', $user->accountOwnerId())
            ->first();
    }

    /**
     * @param  array<string, mixed>  $instruction
     * @return array<string, mixed>
     */
    private function applyInstructionToPayload(PlanScan $scan, array $instruction, User $user, string $message): array
    {
        $payload = $this->baseReviewedPayload($scan);

        if ($instruction['trade_type'] !== null) {
            $payload['trade_type'] = $instruction['trade_type'];
        }

        if ($instruction['surface_m2'] !== null) {
            $payload['metrics']['surface_m2'] = $instruction['surface_m2'];
        }

        if ($instruction['rooms'] !== null) {
            $payload['metrics']['rooms'] = $instruction['rooms'];
        }

        if ($instruction['priority'] !== null) {
            $payload['metrics']['priority'] = $instruction['priority'];
        }

        $lines = collect($payload['line_items'] ?? []);
        foreach ($instruction['remove_line_names'] as $removeName) {
            $lines = $lines->reject(function (array $line) use ($removeName) {
                return $this->lineMatchesInstruction($line, $removeName);
            });
        }

        foreach ($instruction['upsert_lines'] as $incomingLine) {
            $matchedIndex = $lines->search(function (array $line) use ($incomingLine) {
                $needle = $incomingLine['match_name'] ?: $incomingLine['name'];

                return $needle !== null && $this->lineMatchesInstruction($line, $needle);
            });

            $normalizedLine = $this->mergeLine($matchedIndex !== false ? (array) $lines->get($matchedIndex) : [], $incomingLine);

            if ($matchedIndex !== false) {
                $lines->put($matchedIndex, $normalizedLine);
            } else {
                $lines->push($normalizedLine);
            }
        }

        $payload['line_items'] = $lines->values()->all();
        $payload['reviewed_at'] = now()->toIso8601String();
        $payload['updated_by_user_id'] = $user->id;
        $payload['assistant_last_instruction'] = $message;
        $payload['review_source'] = 'assistant_chat';

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeInstruction(array $data): array
    {
        $intent = (string) ($data['intent'] ?? 'unknown');
        $allowedIntents = ['review_scan', 'create_quote', 'review_and_create_quote', 'unknown'];
        if (! in_array($intent, $allowedIntents, true)) {
            $intent = 'unknown';
        }

        return [
            'intent' => $intent,
            'summary' => trim((string) ($data['summary'] ?? '')),
            'trade_type' => $this->normalizeTrade($data['trade_type'] ?? null),
            'surface_m2' => $this->normalizeFloat($data['surface_m2'] ?? null),
            'rooms' => $this->normalizeInt($data['rooms'] ?? null),
            'priority' => $this->normalizePriority($data['priority'] ?? null),
            'variant_preference' => $this->normalizeVariantPreference($data['variant_preference'] ?? null),
            'remove_line_names' => collect($data['remove_line_names'] ?? [])
                ->filter(fn ($item) => is_string($item) && trim($item) !== '')
                ->map(fn ($item) => trim((string) $item))
                ->values()
                ->all(),
            'upsert_lines' => collect($data['upsert_lines'] ?? [])
                ->filter(fn ($item) => is_array($item))
                ->map(function (array $line) {
                    return [
                        'match_name' => $this->normalizeFreeText($line['match_name'] ?? null),
                        'name' => $this->normalizeFreeText($line['name'] ?? null),
                        'quantity' => $this->normalizeFloat($line['quantity'] ?? null),
                        'unit' => $this->normalizeFreeText($line['unit'] ?? null),
                        'base_cost' => $this->normalizeFloat($line['base_cost'] ?? null),
                        'is_labor' => is_bool($line['is_labor'] ?? null) ? $line['is_labor'] : null,
                        'description' => $this->normalizeFreeText($line['description'] ?? null),
                        'notes' => $this->normalizeFreeText($line['notes'] ?? null),
                    ];
                })
                ->filter(fn (array $line) => $line['name'] !== null || $line['match_name'] !== null)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseReviewedPayload(PlanScan $scan): array
    {
        if (is_array($scan->ai_reviewed_payload) && $scan->ai_reviewed_payload !== []) {
            return $scan->ai_reviewed_payload;
        }

        return [
            'trade_type' => $scan->trade_type ?: data_get($scan->ai_extraction_normalized, 'trade_guess') ?: 'general',
            'metrics' => [
                'surface_m2' => data_get($scan->metrics, 'surface_m2'),
                'rooms' => data_get($scan->metrics, 'rooms'),
                'priority' => data_get($scan->metrics, 'priority', 'balanced'),
            ],
            'line_items' => is_array(data_get($scan->ai_extraction_normalized, 'detected_lines'))
                ? data_get($scan->ai_extraction_normalized, 'detected_lines')
                : [],
            'assumptions' => is_array(data_get($scan->ai_extraction_normalized, 'assumptions'))
                ? data_get($scan->ai_extraction_normalized, 'assumptions')
                : [],
            'review_flags' => is_array(data_get($scan->ai_extraction_normalized, 'review_flags'))
                ? data_get($scan->ai_extraction_normalized, 'review_flags')
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeLine(array $existing, array $incoming): array
    {
        return [
            'name' => $incoming['name'] ?? ($existing['name'] ?? 'Assistant line'),
            'quantity' => $incoming['quantity'] ?? ($existing['quantity'] ?? 1),
            'unit' => $incoming['unit'] ?? ($existing['unit'] ?? 'u'),
            'description' => $incoming['description'] ?? ($existing['description'] ?? $existing['notes'] ?? null),
            'base_cost' => $incoming['base_cost'] ?? ($existing['base_cost'] ?? null),
            'is_labor' => $incoming['is_labor'] ?? ($existing['is_labor'] ?? false),
            'confidence' => $existing['confidence'] ?? 95,
            'notes' => $incoming['notes'] ?? ($existing['notes'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function lineMatchesInstruction(array $line, string $needle): bool
    {
        $haystacks = [
            Str::lower((string) ($line['name'] ?? '')),
            Str::lower((string) ($line['description'] ?? '')),
            Str::lower((string) ($line['notes'] ?? '')),
        ];
        $normalizedNeedle = Str::lower(trim($needle));

        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && Str::contains($haystack, $normalizedNeedle)) {
                return true;
            }
        }

        return false;
    }

    private function defaultAssistantMessage(string $intent, PlanScan $scan, ?Quote $quote): string
    {
        return match ($intent) {
            'review_scan' => 'Le scan a ete mis a jour dans le chat et les variantes ont ete regenerees.',
            'review_and_create_quote' => $quote
                ? 'Le scan a ete mis a jour puis un devis brouillon a ete cree.'
                : 'Le scan a ete mis a jour dans le chat.',
            'create_quote' => $quote
                ? 'Le devis brouillon a ete cree a partir du scan courant.'
                : 'Le scan est pret, mais le devis n a pas pu etre cree.',
            default => 'Le scan a ete mis a jour.',
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function mergeScanContext(array $context, PlanScan $scan, ?Quote $quote = null): array
    {
        return array_merge($context, [
            'pending_action' => null,
            'current_plan_scan' => [
                'id' => $scan->id,
                'status' => $scan->status,
                'trade_type' => $scan->trade_type,
                'review_required' => (bool) $scan->ai_review_required,
            ],
            'current_quote' => $quote ? [
                'id' => $quote->id,
                'number' => $quote->number ?? '',
                'status' => $quote->status,
                'customer_id' => $quote->customer_id,
                'property_id' => $quote->property_id,
            ] : ($context['current_quote'] ?? null),
        ]);
    }

    private function normalizeTrade(mixed $value): ?string
    {
        $allowed = ['plumbing', 'carpentry', 'electricity', 'painting', 'masonry', 'general'];
        $normalized = $this->normalizeString($value);

        return in_array($normalized, $allowed, true) ? $normalized : null;
    }

    private function normalizePriority(mixed $value): ?string
    {
        $allowed = ['cost', 'balanced', 'quality'];
        $normalized = $this->normalizeString($value);

        return in_array($normalized, $allowed, true) ? $normalized : null;
    }

    private function normalizeVariantPreference(mixed $value): ?string
    {
        $allowed = ['eco', 'standard', 'premium'];
        $normalized = $this->normalizeString($value);

        return in_array($normalized, $allowed, true) ? $normalized : null;
    }

    private function normalizeFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function normalizeInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? Str::lower($normalized) : null;
    }

    private function normalizeFreeText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }
}
