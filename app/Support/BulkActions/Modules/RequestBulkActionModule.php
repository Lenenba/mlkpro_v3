<?php

namespace App\Support\BulkActions\Modules;

use App\Models\Request as LeadRequest;
use App\Support\BulkActions\BulkActionModule;
use Illuminate\Support\Collection;

class RequestBulkActionModule implements BulkActionModule
{
    public function key(): string
    {
        return 'request';
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function definition(array $context = []): array
    {
        return [
            'module' => $this->key(),
            'enabled' => true,
            'endpoint' => route('request.bulk'),
            'method' => 'patch',
            'selection_label_key' => 'requests.bulk.selected',
            'controls' => [
                'status' => [
                    'key' => 'status',
                    'kind' => 'select_submit',
                    'payload_key' => 'status',
                    'label_key' => 'requests.bulk.status_label',
                    'placeholder_key' => 'requests.bulk.status_placeholder',
                    'submit_label_key' => 'requests.bulk.apply_status',
                    'lost_reason_key' => 'lost_reason',
                    'lost_reason_trigger_value' => LeadRequest::STATUS_LOST,
                    'lost_reason_placeholder_key' => 'requests.bulk.lost_reason',
                    'lost_reason_prompt_key' => 'requests.bulk.lost_reason_prompt',
                    'options' => $this->normalizeOptions($context['statuses'] ?? []),
                ],
                'assign' => [
                    'key' => 'assign',
                    'kind' => 'select_submit',
                    'payload_key' => 'assigned_team_member_id',
                    'label_key' => 'requests.bulk.assign_label',
                    'placeholder_key' => 'requests.bulk.assign_placeholder',
                    'submit_label_key' => 'requests.bulk.apply_assign',
                    'options' => $this->normalizeOptions($context['assignees'] ?? []),
                ],
            ],
        ];
    }

    /**
     * @param  iterable<mixed>  $rows
     * @return array<int, array{value: string, label: string}>
     */
    private function normalizeOptions(iterable $rows): array
    {
        return Collection::make($rows)
            ->map(function (mixed $row): ?array {
                if (! is_array($row)) {
                    return null;
                }

                $value = $row['id'] ?? $row['value'] ?? null;
                $label = $row['name'] ?? $row['label'] ?? $value;

                if ($value === null || $value === '') {
                    return null;
                }

                return [
                    'value' => (string) $value,
                    'label' => (string) $label,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
