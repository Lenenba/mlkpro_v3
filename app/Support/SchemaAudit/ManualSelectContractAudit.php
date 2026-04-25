<?php

namespace App\Support\SchemaAudit;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LoyaltyPointLedger;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Sale;
use App\Models\ServiceRequest;
use App\Models\Task;
use App\Models\User;
use App\Models\Work;
use App\Queries\Customers\CustomerReadSelects;
use App\Support\Database\UserSelects;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ManualSelectContractAudit
{
    public function contractCount(): int
    {
        return count($this->contracts());
    }

    public function run(?string $connection = null): array
    {
        $schema = DB::connection($connection)->getSchemaBuilder();
        $failures = [];

        foreach ($this->contracts() as $contract) {
            $table = $contract['table'];
            $columns = $this->normalizeColumns($contract['columns']);
            $name = $contract['name'];

            if (! $schema->hasTable($table)) {
                $failures[] = [
                    'contract' => $name,
                    'table' => $table,
                    'missing' => ['__table_missing__'],
                ];

                continue;
            }

            $tableColumns = $schema->getColumnListing($table);
            $missing = array_values(array_diff($columns, $tableColumns));

            if ($missing !== []) {
                $failures[] = [
                    'contract' => $name,
                    'table' => $table,
                    'missing' => $missing,
                ];
            }
        }

        return $failures;
    }

    private function contracts(): array
    {
        return [
            [
                'name' => 'customer_detail.quotes',
                'table' => (new Quote)->getTable(),
                'columns' => CustomerReadSelects::detailQuoteColumns(),
            ],
            [
                'name' => 'customer_detail.works',
                'table' => (new Work)->getTable(),
                'columns' => CustomerReadSelects::detailWorkColumns(),
            ],
            [
                'name' => 'customer_detail.requests',
                'table' => (new LeadRequest)->getTable(),
                'columns' => CustomerReadSelects::detailRequestColumns(),
            ],
            [
                'name' => 'customer_detail.service_requests',
                'table' => (new ServiceRequest)->getTable(),
                'columns' => CustomerReadSelects::detailServiceRequestColumns(),
            ],
            [
                'name' => 'customer_detail.invoices',
                'table' => (new Invoice)->getTable(),
                'columns' => CustomerReadSelects::detailInvoiceColumns(),
            ],
            [
                'name' => 'customer_detail.tasks',
                'table' => (new Task)->getTable(),
                'columns' => CustomerReadSelects::detailTaskColumns(),
            ],
            [
                'name' => 'customer_detail.upcoming_works',
                'table' => (new Work)->getTable(),
                'columns' => CustomerReadSelects::detailUpcomingWorkColumns(),
            ],
            [
                'name' => 'customer_detail.payments',
                'table' => (new Payment)->getTable(),
                'columns' => CustomerReadSelects::detailPaymentColumns(),
            ],
            [
                'name' => 'customer_detail.activity_logs',
                'table' => (new ActivityLog)->getTable(),
                'columns' => CustomerReadSelects::detailActivityColumns(),
            ],
            [
                'name' => 'customer_detail.loyalty_ledgers',
                'table' => (new LoyaltyPointLedger)->getTable(),
                'columns' => CustomerReadSelects::detailLoyaltyLedgerColumns(),
            ],
            [
                'name' => 'customer_detail.sales',
                'table' => (new Sale)->getTable(),
                'columns' => CustomerReadSelects::detailSalesColumns(),
            ],
            [
                'name' => 'customer_options.customers.audience',
                'table' => (new Customer)->getTable(),
                'columns' => CustomerReadSelects::optionCustomerColumns('audience'),
            ],
            [
                'name' => 'customer_options.customers.quote',
                'table' => (new Customer)->getTable(),
                'columns' => CustomerReadSelects::optionCustomerColumns('quote'),
            ],
            [
                'name' => 'customer_options.customers.full',
                'table' => (new Customer)->getTable(),
                'columns' => CustomerReadSelects::optionCustomerColumns('full'),
            ],
            [
                'name' => 'customer_options.properties.quote',
                'table' => (new Property)->getTable(),
                'columns' => CustomerReadSelects::optionPropertyColumns('quote'),
            ],
            [
                'name' => 'customer_options.properties.full',
                'table' => (new Property)->getTable(),
                'columns' => CustomerReadSelects::optionPropertyColumns('full'),
            ],
            [
                'name' => 'shared_auth.users.company_feature_context',
                'table' => (new User)->getTable(),
                'columns' => UserSelects::companyFeatureContext(),
            ],
            [
                'name' => 'shared_auth.users.company_summary',
                'table' => (new User)->getTable(),
                'columns' => UserSelects::companySummary(),
            ],
            [
                'name' => 'shared_auth.users.portal_company_context',
                'table' => (new User)->getTable(),
                'columns' => UserSelects::portalCompanyContext(),
            ],
            [
                'name' => 'shared_auth.users.identity',
                'table' => (new User)->getTable(),
                'columns' => UserSelects::identity(),
            ],
        ];
    }

    private function normalizeColumns(array $columns): array
    {
        return collect($columns)
            ->filter(fn ($column) => is_string($column) && trim($column) !== '')
            ->map(fn (string $column) => Str::contains($column, '.') ? Str::afterLast($column, '.') : $column)
            ->values()
            ->all();
    }
}
