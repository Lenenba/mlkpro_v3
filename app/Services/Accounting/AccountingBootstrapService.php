<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingMapping;

class AccountingBootstrapService
{
    public function ensureForAccount(int $accountId): void
    {
        $this->ensureAccounts($accountId);
        $this->ensureMappings($accountId);
    }

    public function ensureAccounts(int $accountId): void
    {
        foreach (array_values(config('accounting.system_accounts', [])) as $index => $definition) {
            AccountingAccount::query()->updateOrCreate(
                [
                    'user_id' => $accountId,
                    'key' => $definition['key'],
                ],
                [
                    'code' => $definition['code'],
                    'name' => $definition['name'],
                    'type' => $definition['type'],
                    'description' => $definition['description'] ?? null,
                    'is_system' => true,
                    'is_active' => true,
                    'sort_order' => $index + 1,
                    'meta' => [
                        'seeded_from' => 'config.accounting.system_accounts',
                    ],
                ]
            );
        }
    }

    public function ensureMappings(int $accountId): void
    {
        $accountsByKey = AccountingAccount::query()
            ->forUser($accountId)
            ->get()
            ->keyBy('key');

        foreach (array_values(config('accounting.default_mappings', [])) as $definition) {
            AccountingMapping::query()->updateOrCreate(
                [
                    'user_id' => $accountId,
                    'source_domain' => $definition['source_domain'],
                    'source_key' => $definition['source_key'],
                ],
                [
                    'debit_account_id' => $accountsByKey->get($definition['debit_account_key'])?->id,
                    'credit_account_id' => $accountsByKey->get($definition['credit_account_key'])?->id,
                    'tax_account_id' => $definition['tax_account_key']
                        ? $accountsByKey->get($definition['tax_account_key'])?->id
                        : null,
                    'is_system' => true,
                    'is_active' => true,
                    'meta' => [
                        'description' => $definition['description'] ?? null,
                        'debit_account_key' => $definition['debit_account_key'] ?? null,
                        'credit_account_key' => $definition['credit_account_key'] ?? null,
                        'tax_account_key' => $definition['tax_account_key'] ?? null,
                        'seeded_from' => 'config.accounting.default_mappings',
                    ],
                ]
            );
        }
    }
}
