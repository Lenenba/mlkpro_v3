<?php

namespace App\Services\Customers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;

final class CustomerActivityAudit
{
    private const PROFILE_FIELDS = [
        'client_type',
        'company_name',
        'registration_number',
        'industry',
        'first_name',
        'last_name',
        'email',
        'phone',
        'description',
        'refer_by',
        'tags',
        'is_active',
        'portal_access',
        'discount_rate',
        'billing_mode',
        'billing_grouping',
        'billing_same_as_physical',
        'auto_accept_quotes',
        'auto_validate_jobs',
        'auto_validate_tasks',
        'auto_validate_invoices',
        'is_vip',
        'vip_tier_id',
        'vip_tier_code',
        'vip_since_at',
    ];

    /** @return array<string, mixed> */
    public function profileSnapshot(Customer $customer): array
    {
        return collect(self::PROFILE_FIELDS)
            ->mapWithKeys(fn (string $field): array => [
                $field => $this->normalize($customer->getAttribute($field)),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $context
     */
    public function recordChanges(
        ?User $actor,
        Customer $customer,
        string $action,
        array $before,
        array $after,
        string $description,
        array $context = []
    ): ?ActivityLog {
        $before = $this->normalizeArray($before);
        $after = $this->normalizeArray($after);
        $changes = [];

        foreach (array_values(array_unique(array_merge(array_keys($before), array_keys($after)))) as $field) {
            $previous = $before[$field] ?? null;
            $current = $after[$field] ?? null;
            if ($previous === $current) {
                continue;
            }

            $changes[$field] = [
                'before' => $previous,
                'after' => $current,
            ];
        }

        if ($changes === []) {
            return null;
        }

        $changedFields = array_keys($changes);

        return ActivityLog::record($actor, $customer, $action, array_merge($context, [
            'before' => array_intersect_key($before, array_flip($changedFields)),
            'after' => array_intersect_key($after, array_flip($changedFields)),
            'changes' => $changes,
        ]), $description);
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function normalizeArray(array $values): array
    {
        return collect($values)
            ->map(fn (mixed $value): mixed => $this->normalize($value))
            ->all();
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_array($value)) {
            $normalized = array_map(fn (mixed $item): mixed => $this->normalize($item), $value);

            return array_is_list($normalized) ? array_values($normalized) : $normalized;
        }

        if (is_float($value)) {
            return round($value, 4);
        }

        return $value;
    }
}
