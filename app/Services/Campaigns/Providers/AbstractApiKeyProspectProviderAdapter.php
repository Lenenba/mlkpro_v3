<?php

namespace App\Services\Campaigns\Providers;

use App\Models\CampaignProspect;
use App\Models\CampaignProspectProviderConnection;
use App\Services\Campaigns\Providers\Contracts\ProspectProviderAdapter;
use Illuminate\Support\Str;

abstract class AbstractApiKeyProspectProviderAdapter implements ProspectProviderAdapter
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function credentialFields(): array
    {
        return [
            [
                'key' => 'api_key',
                'label' => 'API key',
                'type' => 'password',
                'required' => true,
                'placeholder' => 'Paste your provider API key',
                'help' => 'Stored encrypted for this tenant only.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, status: string, message: string, errors?: array<string, string>}
     */
    public function validateCredentials(array $credentials): array
    {
        $apiKey = trim((string) ($credentials['api_key'] ?? ''));

        if ($apiKey === '') {
            return [
                'ok' => false,
                'status' => CampaignProspectProviderConnection::STATUS_INVALID,
                'message' => 'API key is required.',
                'errors' => [
                    'api_key' => 'API key is required.',
                ],
            ];
        }

        if (mb_strlen($apiKey) < 8) {
            return [
                'ok' => false,
                'status' => CampaignProspectProviderConnection::STATUS_INVALID,
                'message' => 'API key looks incomplete.',
                'errors' => [
                    'api_key' => 'API key looks incomplete.',
                ],
            ];
        }

        return [
            'ok' => true,
            'status' => CampaignProspectProviderConnection::STATUS_CONNECTED,
            'message' => 'Connection validated.',
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $queryContext
     * @return array<int, array<string, mixed>>
     */
    public function fetchPreview(array $credentials, array $queryContext, int $limit = 25): array
    {
        $normalizedLimit = max(1, min(50, $limit));
        $query = trim((string) ($queryContext['query'] ?? ''));
        $queryLabel = trim((string) ($queryContext['query_label'] ?? ''));
        $seed = abs((int) crc32($this->key().'|'.$query.'|'.$queryLabel.'|'.trim((string) ($credentials['api_key'] ?? ''))));
        $tokens = $this->queryTokens($query);

        $firstNames = ['Mia', 'Noah', 'Emma', 'Lucas', 'Ava', 'Liam', 'Zoe', 'Mason', 'Ella', 'Nathan'];
        $lastNames = ['Martin', 'Stone', 'Chen', 'Roy', 'Lopez', 'Carter', 'Nguyen', 'Bell', 'Grant', 'Dupont'];
        $cities = ['Toronto', 'Montreal', 'Vancouver', 'Calgary', 'Ottawa', 'Quebec City', 'Halifax', 'Waterloo'];
        $states = ['Ontario', 'Quebec', 'British Columbia', 'Alberta', 'Nova Scotia'];
        $countries = ['Canada', 'United States'];
        $industries = ['Retail', 'Construction', 'Manufacturing', 'Professional Services', 'Healthcare', 'Logistics', 'Technology'];
        $sizes = ['1-10', '11-50', '51-200', '201-500', '500+'];
        $companyPrefixes = ['North', 'Prime', 'Atlas', 'Cedar', 'Summit', 'Bright', 'Core', 'Nova'];
        $companySuffixes = ['Group', 'Partners', 'Labs', 'Works', 'Supply', 'Studios', 'Solutions', 'Collective'];

        $rows = [];

        for ($index = 0; $index < $normalizedLimit; $index++) {
            $token = $tokens[$index % max(count($tokens), 1)] ?? $this->label();
            $seedOffset = $seed + ($index * 17);
            $companyCore = Str::title(Str::lower(preg_replace('/[^a-z0-9]+/i', ' ', $token) ?: $this->label()));
            $companyName = sprintf(
                '%s %s %s',
                $companyPrefixes[$seedOffset % count($companyPrefixes)],
                trim($companyCore),
                $companySuffixes[($seedOffset + 3) % count($companySuffixes)]
            );

            $firstName = $firstNames[$seedOffset % count($firstNames)];
            $lastName = $lastNames[($seedOffset + 5) % count($lastNames)];
            $city = $cities[$seedOffset % count($cities)];
            $state = $states[$seedOffset % count($states)];
            $country = $countries[$seedOffset % count($countries)];
            $industry = $industries[$seedOffset % count($industries)];
            $companySize = $sizes[$seedOffset % count($sizes)];
            $domainRoot = Str::slug($companyName);
            $website = ($index % 5 === 4) ? null : sprintf('https://%s.example', $domainRoot);
            $email = ($index % 6 === 5) ? null : sprintf('%s.%s@%s.example', Str::lower($firstName), Str::lower($lastName), $domainRoot);
            $phone = ($index % 4 === 3) ? null : sprintf('+1 416 555 %04d', 1000 + (($seedOffset * 13) % 9000));

            $rows[] = [
                'external_ref' => strtoupper($this->key()).'-'.substr(md5($query.'|'.$companyName.'|'.$index), 0, 12),
                'company_name' => trim($companyName),
                'contact_name' => trim($firstName.' '.$lastName),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'website' => $website,
                'city' => $city,
                'state' => $state,
                'country' => $country,
                'industry' => $industry,
                'company_size' => $companySize,
                'tags' => array_values(array_filter([
                    $this->label(),
                    $queryLabel !== '' ? $queryLabel : null,
                    Str::title($industry),
                ])),
                'metadata' => [
                    'provider_preview' => true,
                    'provider_rank' => $index + 1,
                    'provider_query' => $query,
                    'provider_query_label' => $queryLabel !== '' ? $queryLabel : null,
                    'fit_signal' => 60 + ($seedOffset % 35),
                ],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function normalizePreviewRows(array $rows, array $context = []): array
    {
        $providerKey = trim((string) ($context['provider_key'] ?? $this->key()));
        $providerLabel = trim((string) ($context['provider_label'] ?? $this->label()));
        $sourceReference = trim((string) (
            $context['source_reference']
                ?? $context['provider_connection_label']
                ?? $providerLabel
        ));
        $query = trim((string) ($context['query'] ?? ''));
        $queryLabel = trim((string) ($context['query_label'] ?? ''));

        return collect($rows)
            ->map(function (array $row, int $index) use ($providerKey, $providerLabel, $sourceReference, $query, $queryLabel): array {
                $firstName = trim((string) ($row['first_name'] ?? ''));
                $lastName = trim((string) ($row['last_name'] ?? ''));
                $contactName = trim((string) ($row['contact_name'] ?? trim($firstName.' '.$lastName)));
                $companyName = trim((string) ($row['company_name'] ?? ''));
                $email = trim(Str::lower((string) ($row['email'] ?? '')));
                $phone = trim((string) ($row['phone'] ?? ''));
                $website = $this->normalizeWebsite((string) ($row['website'] ?? ''));
                $websiteDomain = $this->extractWebsiteDomain($website);
                $city = trim((string) ($row['city'] ?? ''));
                $state = trim((string) ($row['state'] ?? ''));
                $country = trim((string) ($row['country'] ?? ''));
                $industry = trim((string) ($row['industry'] ?? ''));
                $companySize = trim((string) ($row['company_size'] ?? ''));
                $externalRef = trim((string) ($row['external_ref'] ?? ''));
                $previewRef = $externalRef !== ''
                    ? $externalRef
                    : strtoupper($providerKey).'-'.substr(md5($companyName.'|'.$contactName.'|'.$index), 0, 12);
                $tags = collect($row['tags'] ?? [])
                    ->map(fn ($value) => trim((string) $value))
                    ->filter(fn (string $value) => $value !== '')
                    ->unique()
                    ->values()
                    ->all();
                $missingFields = [];

                if ($companyName === '') {
                    $missingFields[] = 'company_name';
                }
                if ($contactName === '' && $firstName === '' && $lastName === '') {
                    $missingFields[] = 'contact_name';
                }
                if ($email === '') {
                    $missingFields[] = 'email';
                }
                if ($phone === '') {
                    $missingFields[] = 'phone';
                }
                if ($website === '') {
                    $missingFields[] = 'website';
                }

                return [
                    'preview_ref' => $previewRef,
                    'provider_key' => $providerKey,
                    'provider_label' => $providerLabel,
                    'source_type' => CampaignProspect::SOURCE_CONNECTOR,
                    'source_reference' => $sourceReference,
                    'external_ref' => $externalRef !== '' ? $externalRef : $previewRef,
                    'company_name' => $companyName,
                    'contact_name' => $contactName,
                    'first_name' => $firstName !== '' ? $firstName : null,
                    'last_name' => $lastName !== '' ? $lastName : null,
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'website' => $website !== '' ? $website : null,
                    'website_domain' => $websiteDomain !== '' ? $websiteDomain : null,
                    'city' => $city !== '' ? $city : null,
                    'state' => $state !== '' ? $state : null,
                    'country' => $country !== '' ? $country : null,
                    'industry' => $industry !== '' ? $industry : null,
                    'company_size' => $companySize !== '' ? $companySize : null,
                    'tags' => $tags,
                    'missing_fields' => $missingFields,
                    'metadata' => [
                        ...((array) ($row['metadata'] ?? [])),
                        'provider_preview' => true,
                        'provider_key' => $providerKey,
                        'provider_label' => $providerLabel,
                        'source_reference' => $sourceReference !== '' ? $sourceReference : null,
                        'provider_query' => $query !== '' ? $query : null,
                        'provider_query_label' => $queryLabel !== '' ? $queryLabel : null,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function queryTokens(string $query): array
    {
        $tokens = collect(preg_split('/[\s,;|]+/', Str::lower($query)) ?: [])
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => mb_strlen($value) >= 3)
            ->unique()
            ->values()
            ->all();

        return $tokens !== [] ? $tokens : [Str::lower($this->label())];
    }

    protected function normalizeWebsite(string $website): string
    {
        $normalized = trim($website);
        if ($normalized === '') {
            return '';
        }

        if (! str_starts_with(Str::lower($normalized), 'http://') && ! str_starts_with(Str::lower($normalized), 'https://')) {
            $normalized = 'https://'.$normalized;
        }

        return $normalized;
    }

    protected function extractWebsiteDomain(string $website): string
    {
        if ($website === '') {
            return '';
        }

        $host = parse_url($website, PHP_URL_HOST);
        if (! is_string($host)) {
            return '';
        }

        return trim(Str::lower($host));
    }
}
