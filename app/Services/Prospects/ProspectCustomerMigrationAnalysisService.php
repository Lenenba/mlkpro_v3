<?php

namespace App\Services\Prospects;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Request as LeadRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProspectCustomerMigrationAnalysisService
{
    private const REAL_REQUEST_STATUSES = [
        LeadRequest::STATUS_CONVERTED,
        LeadRequest::STATUS_WON,
    ];

    /**
     * @return array{
     *     account_id:int|null,
     *     scanned:int,
     *     real_count:int,
     *     eligible_count:int,
     *     ambiguous_count:int,
     *     reason_counts:array<string,int>,
     *     ambiguous_samples:array<int,array<string,mixed>>
     * }
     */
    public function analyze(?int $accountId = null, int $sampleLimit = 10): array
    {
        $summary = [
            'account_id' => $accountId,
            'scanned' => 0,
            'real_count' => 0,
            'eligible_count' => 0,
            'ambiguous_count' => 0,
            'reason_counts' => [],
            'ambiguous_samples' => [],
        ];

        $this->baseQuery($accountId)
            ->orderBy('customers.id')
            ->chunkById(200, function (Collection $customers) use (&$summary, $sampleLimit) {
                foreach ($customers as $customer) {
                    $analysis = $this->classify($customer);

                    $summary['scanned']++;
                    $summary[$analysis['bucket'].'_count']++;
                    $summary['reason_counts'][$analysis['reason']] = (int) ($summary['reason_counts'][$analysis['reason']] ?? 0) + 1;

                    if ($analysis['bucket'] === 'ambiguous' && count($summary['ambiguous_samples']) < $sampleLimit) {
                        $summary['ambiguous_samples'][] = [
                            'id' => (int) $customer->id,
                            'name' => $this->displayName($customer),
                            'reason' => $analysis['reason'],
                            'signals' => $analysis['signals'],
                        ];
                    }
                }
            }, 'customers.id', 'id');

        ksort($summary['reason_counts']);

        return $summary;
    }

    public function query(?int $accountId = null): Builder
    {
        return $this->baseQuery($accountId);
    }

    /**
     * @return array<int, string>
     */
    public function realRequestStatuses(): array
    {
        return self::REAL_REQUEST_STATUSES;
    }

    /**
     * @return array{
     *     bucket:'real'|'eligible'|'ambiguous',
     *     reason:string,
     *     signals:array<string,int|bool>
     * }
     */
    public function classifyCustomer(Customer $customer): array
    {
        return $this->classify($customer);
    }

    public function displayNameForCustomer(Customer $customer): string
    {
        return $this->displayName($customer);
    }

    private function baseQuery(?int $accountId = null): Builder
    {
        return Customer::query()
            ->when($accountId, fn (Builder $query) => $query->where('customers.user_id', $accountId))
            ->select([
                'customers.id',
                'customers.user_id',
                'customers.portal_access',
                'customers.portal_user_id',
                'customers.company_name',
                'customers.first_name',
                'customers.last_name',
                'customers.email',
            ])
            ->withCount([
                'prospects as prospect_requests_count' => fn (Builder $query) => $query
                    ->whereNotIn('status', self::REAL_REQUEST_STATUSES),
                'prospects as converted_requests_count' => fn (Builder $query) => $query
                    ->whereIn('status', self::REAL_REQUEST_STATUSES),
                'quotes as open_quotes_count' => fn (Builder $query) => $query
                    ->where(function (Builder $quoteQuery) {
                        $quoteQuery->whereNull('accepted_at')
                            ->where('status', '!=', 'accepted');
                    }),
                'quotes as accepted_quotes_count' => fn (Builder $query) => $query
                    ->where(function (Builder $quoteQuery) {
                        $quoteQuery->whereNotNull('accepted_at')
                            ->orWhere('status', 'accepted');
                    }),
                'sales',
                'works',
                'invoices',
                'reservations',
            ])
            ->selectSub(
                Payment::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('payments.customer_id', 'customers.id')
                    ->whereIn('payments.status', Payment::settledStatuses()),
                'settled_payments_count'
            );
    }

    /**
     * @return array{
     *     bucket:'real'|'eligible'|'ambiguous',
     *     reason:string,
     *     signals:array<string,int|bool>
     * }
     */
    private function classify(Customer $customer): array
    {
        $signals = [
            'portal_access' => (bool) $customer->portal_access,
            'portal_user_linked' => $customer->portal_user_id !== null,
            'prospect_requests' => (int) ($customer->prospect_requests_count ?? 0),
            'converted_requests' => (int) ($customer->converted_requests_count ?? 0),
            'open_quotes' => (int) ($customer->open_quotes_count ?? 0),
            'accepted_quotes' => (int) ($customer->accepted_quotes_count ?? 0),
            'sales' => (int) ($customer->sales_count ?? 0),
            'works' => (int) ($customer->works_count ?? 0),
            'invoices' => (int) ($customer->invoices_count ?? 0),
            'reservations' => (int) ($customer->reservations_count ?? 0),
            'settled_payments' => (int) ($customer->settled_payments_count ?? 0),
        ];

        $realReason = $this->realReason($signals);
        if ($realReason !== null) {
            return [
                'bucket' => 'real',
                'reason' => $realReason,
                'signals' => $signals,
            ];
        }

        if ($signals['portal_user_linked']) {
            return [
                'bucket' => 'ambiguous',
                'reason' => 'ambiguous.portal_user_linked',
                'signals' => $signals,
            ];
        }

        if ($signals['prospect_requests'] > 0 && $signals['open_quotes'] > 0) {
            return [
                'bucket' => 'eligible',
                'reason' => 'eligible.requests_and_open_quotes',
                'signals' => $signals,
            ];
        }

        if ($signals['prospect_requests'] > 0) {
            return [
                'bucket' => 'eligible',
                'reason' => 'eligible.requests_only',
                'signals' => $signals,
            ];
        }

        if ($signals['open_quotes'] > 0) {
            return [
                'bucket' => 'eligible',
                'reason' => 'eligible.open_quotes_only',
                'signals' => $signals,
            ];
        }

        return [
            'bucket' => 'ambiguous',
            'reason' => 'ambiguous.no_presales_activity',
            'signals' => $signals,
        ];
    }

    /**
     * @param  array<string,int|bool>  $signals
     */
    private function realReason(array $signals): ?string
    {
        return match (true) {
            (int) $signals['settled_payments'] > 0 => 'real.settled_payments',
            (int) $signals['sales'] > 0 => 'real.sales',
            (int) $signals['works'] > 0 => 'real.works',
            (int) $signals['accepted_quotes'] > 0 => 'real.accepted_quotes',
            (int) $signals['invoices'] > 0 => 'real.invoices',
            (int) $signals['reservations'] > 0 => 'real.reservations',
            (int) $signals['converted_requests'] > 0 => 'real.converted_requests',
            default => null,
        };
    }

    private function displayName(Customer $customer): string
    {
        $companyName = trim((string) ($customer->company_name ?? ''));
        if ($companyName !== '') {
            return $companyName;
        }

        $name = trim(implode(' ', array_filter([
            trim((string) ($customer->first_name ?? '')),
            trim((string) ($customer->last_name ?? '')),
        ])));

        if ($name !== '') {
            return $name;
        }

        $email = trim((string) ($customer->email ?? ''));

        return $email !== '' ? $email : 'Customer #'.$customer->id;
    }
}
