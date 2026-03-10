<?php

namespace App\Services\Assistant;

use Illuminate\Support\Arr;

class AssistantDraftService
{
    public function mergeCustomerDraft(array $base, array $updates): array
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
            if (! isset($merged[$key])) {
                $merged[$key] = '';
            }
        }

        return $merged;
    }

    public function mergePropertyDraft(array $base, array $updates): array
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

    public function mergeCategoryDraft(array $base, array $updates): array
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

    public function mergeProductDraft(array $base, array $updates): array
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

    public function mergeTeamMemberDraft(array $base, array $updates): array
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

    public function mergeTaskDraft(array $base, array $updates): array
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

    public function mergeRequestDraft(array $base, array $updates): array
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

    public function applyAnswerToCustomerDraft(array $draft, array $context): array
    {
        [$answer, $questions] = $this->extractAnswerAndQuestions($context);
        if ($answer === '' || ! $questions) {
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

            if (str_contains($lower, 'nom') && ! str_contains($lower, 'prenom') && ($draft['last_name'] ?? '') === '') {
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

    public function applyAnswerToTeamMemberDraft(array $draft, array $context): array
    {
        [$answer, $questions] = $this->extractAnswerAndQuestions($context);
        if ($answer === '' || ! $questions) {
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

    public function applyAnswerToPropertyDraft(array $draft, array $context): array
    {
        [$answer, $questions] = $this->extractAnswerAndQuestions($context);
        if ($answer === '' || ! $questions) {
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

    public function applyAnswerToTaskDraft(array $draft, array $context): array
    {
        [$answer, $questions] = $this->extractAnswerAndQuestions($context);
        if ($answer === '' || ! $questions) {
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

    public function applyAnswerToRequestDraft(array $draft, array $context): array
    {
        [$answer, $questions] = $this->extractAnswerAndQuestions($context);
        if ($answer === '' || ! $questions) {
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

    public function extractAnswerAndQuestions(array $context): array
    {
        $answer = trim((string) ($context['last_message'] ?? ''));
        $questions = $context['questions'] ?? [];
        if (! is_array($questions)) {
            $questions = [];
        }

        $questions = array_values(array_filter(array_map(function ($question) {
            return is_string($question) ? trim($question) : '';
        }, $questions)));

        return [$answer, $questions];
    }

    public function normalizeAnswerValue(string $answer): string
    {
        $trimmed = trim($answer);
        $trimmed = preg_replace('/^(ville|city|adresse|address|code postal|zip|state|province)\s*[:=-]?\s*/i', '', $trimmed);

        return trim((string) $trimmed);
    }

    public function mergeWorkDraft(array $base, array $updates): array
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

    public function mergeItems(array $baseItems, array $updateItems): array
    {
        $indexed = [];
        foreach ($baseItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = strtolower(trim((string) ($item['name'] ?? '')));
            if ($name === '') {
                continue;
            }
            $indexed[$name] = $item;
        }

        foreach ($updateItems as $item) {
            if (! is_array($item)) {
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

    public function normalizeItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
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
}
