<?php

namespace App\Services;

use App\Models\PlanScan;
use App\Services\Assistant\OpenAiClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PlanScanAiExtractor
{
    public function __construct(private readonly OpenAiClient $client) {}

    public function extract(PlanScan $scan, array $manualMetrics = [], array $options = []): array
    {
        if (! config('services.openai.key')) {
            return $this->fallbackPayload(
                $scan,
                $manualMetrics,
                'skipped',
                'OpenAI is not configured for plan scan extraction.',
                $options
            );
        }

        try {
            $model = $this->resolveModel($options);
            $response = $this->client->chat(
                $this->buildMessages($scan, $manualMetrics),
                [
                    'model' => $model,
                    'temperature' => 0.1,
                    'timeout' => (int) config('services.openai.plan_scan_timeout', 90),
                ]
            );

            $content = $this->client->extractMessage($response);
            $decoded = $this->decodeJson($content);
            $normalized = $this->normalizePayload($scan, $decoded, $manualMetrics, $options);
            $usage = $this->client->extractUsage($response);

            return [
                'status' => 'completed',
                'model' => $usage['model'] ?? $model,
                'usage' => $usage,
                'raw' => [
                    'payload' => $decoded,
                    'response_model' => $usage['model'] ?? null,
                    'requested_mode' => $options['mode'] ?? 'initial',
                ],
                'normalized' => $normalized,
                'review_required' => $this->reviewRequired($normalized),
                'error_message' => null,
            ];
        } catch (\Throwable $exception) {
            return $this->fallbackPayload($scan, $manualMetrics, 'failed', $exception->getMessage(), $options);
        }
    }

    private function buildMessages(PlanScan $scan, array $manualMetrics): array
    {
        $filename = (string) ($scan->plan_file_name ?: 'plan-upload');
        $mimeType = $this->detectMimeType($filename, $scan->plan_file_path);
        $content = [
            [
                'type' => 'text',
                'text' => $this->buildUserPrompt($scan, $manualMetrics),
            ],
        ];

        $filePayload = $this->filePayload($scan->plan_file_path, $filename, $mimeType);

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
You are an AI plan intake assistant for a business estimating platform.
Read the uploaded plan or image and return JSON only.
Do not include markdown or explanatory prose outside the JSON object.

You are performing an early extraction phase for plan intake.
Your goals are:
- infer the most likely trade
- estimate visible surface in square meters when possible
- estimate room count when possible
- detect a first set of editable estimate lines when visible
- list assumptions
- list review flags
- provide confidence values from 0 to 100

Rules:
- if a value cannot be estimated from the document, return null
- never invent precise measurements you cannot support visually
- be conservative on confidence
- prefer simple review flags over false certainty
- use trade ids from this allowed list when possible:
  plumbing, carpentry, electricity, painting, masonry, general

Return a JSON object with this exact shape:
{
  "document_type": "plan|elevation|photo|other",
  "trade_guess": "plumbing|carpentry|electricity|painting|masonry|general|null",
  "metrics": {
    "surface_m2_estimate": null,
    "room_count_estimate": null
  },
  "assumptions": [],
  "review_flags": [],
  "detected_lines": [
    {
      "name": "",
      "quantity": 1,
      "unit": "u",
      "line_type": "material|service|unknown",
      "is_labor": false,
      "confidence": 0,
      "notes": ""
    }
  ],
  "detected_elements": [],
  "confidence": {
    "overall": 0,
    "trade": 0,
    "surface": 0,
    "rooms": 0
  }
}
PROMPT;
    }

    private function buildUserPrompt(PlanScan $scan, array $manualMetrics): string
    {
        $trade = $scan->trade_type ?: 'general';
        $manualSurface = Arr::get($manualMetrics, 'surface_m2');
        $manualRooms = Arr::get($manualMetrics, 'rooms');
        $priority = Arr::get($manualMetrics, 'priority', 'balanced');

        return <<<PROMPT
Analyze the attached plan for an estimating workflow.

Context:
- selected trade: {$trade}
- project title: {$scan->job_title}
- manual surface hint: {$manualSurface}
- manual rooms hint: {$manualRooms}
- pricing priority: {$priority}

Focus on:
- likely trade
- approximate surface in square meters
- approximate room count
- visible elements worth reviewing for an estimate
- first editable estimate lines when they can be inferred safely
- review flags and assumptions
PROMPT;
    }

    private function normalizePayload(PlanScan $scan, array $decoded, array $manualMetrics, array $options = []): array
    {
        $tradeGuess = $decoded['trade_guess'] ?? null;
        if (! is_string($tradeGuess) || $tradeGuess === '') {
            $tradeGuess = $scan->trade_type ?: 'general';
        }

        $surfaceEstimate = $this->normalizeNullableFloat(data_get($decoded, 'metrics.surface_m2_estimate'));
        $roomsEstimate = $this->normalizeNullableInt(data_get($decoded, 'metrics.room_count_estimate'));
        $confidence = [
            'overall' => $this->normalizeConfidence(data_get($decoded, 'confidence.overall')),
            'trade' => $this->normalizeConfidence(data_get($decoded, 'confidence.trade')),
            'surface' => $this->normalizeConfidence(data_get($decoded, 'confidence.surface')),
            'rooms' => $this->normalizeConfidence(data_get($decoded, 'confidence.rooms')),
        ];

        $detectedLines = $this->normalizeDetectedLines($decoded['detected_lines'] ?? []);
        $normalized = [
            'document_type' => $this->normalizeString($decoded['document_type'] ?? null) ?: 'other',
            'trade_guess' => $tradeGuess,
            'manual_context' => [
                'selected_trade' => $scan->trade_type,
                'surface_m2' => $this->normalizeNullableFloat($manualMetrics['surface_m2'] ?? null),
                'rooms' => $this->normalizeNullableInt($manualMetrics['rooms'] ?? null),
                'priority' => $manualMetrics['priority'] ?? 'balanced',
            ],
            'metrics' => [
                'surface_m2_estimate' => $surfaceEstimate,
                'room_count_estimate' => $roomsEstimate,
            ],
            'detected_lines' => $detectedLines,
            'detected_elements' => $this->normalizeStringList($decoded['detected_elements'] ?? []),
            'assumptions' => $this->normalizeStringList($decoded['assumptions'] ?? []),
            'review_flags' => $this->normalizeStringList($decoded['review_flags'] ?? []),
            'confidence' => $confidence,
        ];

        $normalized['field_flags'] = $this->buildFieldFlags($normalized);
        $normalized['recommended_action'] = $this->recommendedAction($normalized, $options);

        return $normalized;
    }

    private function fallbackPayload(PlanScan $scan, array $manualMetrics, string $status, string $error, array $options = []): array
    {
        $surface = $this->normalizeNullableFloat($manualMetrics['surface_m2'] ?? null);
        $rooms = $this->normalizeNullableInt($manualMetrics['rooms'] ?? null);
        $normalized = [
            'document_type' => 'other',
            'trade_guess' => $scan->trade_type ?: 'general',
            'manual_context' => [
                'selected_trade' => $scan->trade_type,
                'surface_m2' => $surface,
                'rooms' => $rooms,
                'priority' => $manualMetrics['priority'] ?? 'balanced',
            ],
            'metrics' => [
                'surface_m2_estimate' => $surface,
                'room_count_estimate' => $rooms,
            ],
            'detected_lines' => [],
            'detected_elements' => [],
            'assumptions' => [
                'AI extraction unavailable; using manual or fallback metrics only.',
            ],
            'review_flags' => [
                $status === 'failed'
                    ? 'AI extraction failed and should be reviewed manually.'
                    : 'AI extraction skipped because the service is not configured.',
            ],
            'confidence' => [
                'overall' => ($surface !== null || $rooms !== null) ? 55 : 35,
                'trade' => 70,
                'surface' => $surface !== null ? 60 : 20,
                'rooms' => $rooms !== null ? 60 : 20,
            ],
        ];
        $normalized['field_flags'] = $this->buildFieldFlags($normalized);
        $normalized['recommended_action'] = $this->recommendedAction($normalized, $options, $status);

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

    private function reviewRequired(array $normalized): bool
    {
        if (($normalized['confidence']['overall'] ?? 0) < 75) {
            return true;
        }

        foreach (($normalized['field_flags'] ?? []) as $flag) {
            if (($flag['status'] ?? null) !== 'ok') {
                return true;
            }
        }

        return ! empty($normalized['review_flags']);
    }

    private function buildFieldFlags(array $normalized): array
    {
        $surface = data_get($normalized, 'metrics.surface_m2_estimate');
        $rooms = data_get($normalized, 'metrics.room_count_estimate');
        $trade = $normalized['trade_guess'] ?? null;
        $lines = is_array($normalized['detected_lines'] ?? null) ? $normalized['detected_lines'] : [];
        $lineConfidence = $lines === []
            ? 0
            : (int) round(collect($lines)->avg(fn (array $line) => (int) ($line['confidence'] ?? 0)) ?? 0);

        return [
            $this->buildFieldFlag(
                'trade_guess',
                'Trade',
                $trade ?: 'Missing',
                (int) data_get($normalized, 'confidence.trade', 0),
                $trade === null ? 'Trade could not be inferred from the uploaded file.' : 'Trade guess extracted from the plan.'
            ),
            $this->buildFieldFlag(
                'surface_m2_estimate',
                'Surface',
                $surface !== null ? $surface.' m2' : 'Missing',
                (int) data_get($normalized, 'confidence.surface', 0),
                $surface === null ? 'Surface estimate is missing and should be reviewed manually.' : 'Surface estimate extracted from the plan.'
            ),
            $this->buildFieldFlag(
                'room_count_estimate',
                'Rooms',
                $rooms !== null ? (string) $rooms : 'Missing',
                (int) data_get($normalized, 'confidence.rooms', 0),
                $rooms === null ? 'Room count is missing and should be reviewed manually.' : 'Room count extracted from the plan.'
            ),
            $this->buildFieldFlag(
                'detected_lines',
                'Detected lines',
                $lines === [] ? 'No lines detected' : count($lines).' line(s)',
                $lineConfidence,
                $lines === [] ? 'No estimate lines were detected from the plan.' : 'Detected estimate lines need a quick validation before quoting.'
            ),
        ];
    }

    private function buildFieldFlag(string $field, string $label, string $value, int $confidence, string $message): array
    {
        $status = $this->confidenceState($confidence, $value);

        return [
            'field' => $field,
            'label' => $label,
            'value' => $value,
            'confidence' => $confidence,
            'status' => $status,
            'message' => $message,
        ];
    }

    private function confidenceState(int $confidence, string $value): string
    {
        if (in_array($value, ['Missing', 'No lines detected'], true)) {
            return 'missing';
        }

        if ($confidence >= 80) {
            return 'ok';
        }

        return $confidence >= 55 ? 'review' : 'missing';
    }

    private function recommendedAction(array $normalized, array $options = [], ?string $status = null): string
    {
        if ($status === 'failed') {
            return ($options['mode'] ?? null) === 'escalate' ? 'manual_review' : 'escalate';
        }

        if ($status === 'skipped') {
            return 'manual_review';
        }

        if (($normalized['confidence']['overall'] ?? 0) < 55) {
            return ($options['mode'] ?? null) === 'escalate' ? 'manual_review' : 'escalate';
        }

        foreach (($normalized['field_flags'] ?? []) as $flag) {
            if (($flag['status'] ?? null) === 'missing') {
                return ($options['mode'] ?? null) === 'escalate' ? 'manual_review' : 'escalate';
            }
        }

        if ($this->reviewRequired($normalized)) {
            return 'review';
        }

        return 'ready';
    }

    private function resolveModel(array $options): string
    {
        $requestedModel = $this->normalizeString($options['model'] ?? null);
        if ($requestedModel !== null) {
            return $requestedModel;
        }

        if (($options['mode'] ?? null) === 'escalate') {
            return (string) config('services.openai.plan_scan_fallback_model', config('services.openai.plan_scan_model'));
        }

        return (string) config('services.openai.plan_scan_model', config('services.openai.model', 'gpt-4o-mini'));
    }

    private function decodeJson(string $content): array
    {
        $trimmed = trim($content);
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed) ?? $trimmed;
        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI returned invalid JSON for plan scan extraction.');
        }

        return $decoded;
    }

    private function filePayload(string $path, string $filename, string $mimeType): string
    {
        $contents = Storage::disk('public')->get($path);
        $encoded = base64_encode($contents);

        return sprintf('data:%s;base64,%s', $mimeType, $encoded);
    }

    private function detectMimeType(string $filename, ?string $path): string
    {
        $mimeType = $path ? Storage::disk('public')->mimeType($path) : null;
        if (is_string($mimeType) && $mimeType !== '') {
            return $mimeType;
        }

        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($item) {
            return $this->normalizeString($item);
        }, $value)));
    }

    private function normalizeDetectedLines(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $lines = [];
        foreach ($value as $line) {
            if (! is_array($line)) {
                continue;
            }

            $name = $this->normalizeString($line['name'] ?? null);
            if ($name === null) {
                continue;
            }

            $quantity = $this->normalizeNullableFloat($line['quantity'] ?? null) ?? 1.0;
            $confidence = $this->normalizeConfidence($line['confidence'] ?? null);

            $lines[] = [
                'name' => $name,
                'quantity' => max(0.1, $quantity),
                'unit' => $this->normalizeString($line['unit'] ?? null) ?: 'u',
                'line_type' => $this->normalizeString($line['line_type'] ?? null) ?: 'unknown',
                'is_labor' => (bool) ($line['is_labor'] ?? false),
                'confidence' => $confidence,
                'notes' => $this->normalizeString($line['notes'] ?? null),
            ];
        }

        return $lines;
    }

    private function normalizeNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return max(0, (int) round((float) $value));
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
}
