<?php

namespace App\Services\Demo\Generators;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\Property;
use App\Services\Demo\DemoScenarioContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DemoCustomerGenerator
{
    private const FIRST_NAMES = [
        'Amélie', 'Anaïs', 'Béatrice', 'Camille', 'Caroline', 'Catherine', 'Céleste', 'Daphné',
        'Élodie', 'Fatou', 'Gabrielle', 'Inès', 'Isabelle', 'Jade', 'Joanie', 'Julie', 'Karine',
        'Leïla', 'Lina', 'Louise', 'Maëlle', 'Marie', 'Mélanie', 'Mireille', 'Naomi', 'Noémie',
        'Océane', 'Roxane', 'Sophie', 'Yasmine', 'Alexandre', 'Benoît', 'David', 'Étienne',
        'François', 'Hugo', 'Ibrahim', 'Jean', 'Louis', 'Malik', 'Mathieu', 'Olivier', 'Samuel', 'Thomas',
    ];

    private const LAST_NAMES = [
        'Baptiste', 'Beauchamp', 'Bélanger', 'Benali', 'Bernard', 'Bouchard', 'Camara', 'Caron',
        'Charron', 'Cissé', 'Cloutier', 'Côté', 'Desjardins', 'Diallo', 'Dion', 'Dubois', 'Fournier',
        'Fortin', 'Gagné', 'Gauthier', 'Girard', 'Joseph', 'Kouyaté', 'Lacroix', 'Lafleur', 'Lavoie',
        'Leblanc', 'Lefebvre', 'Lemieux', 'Martel', 'Mercier', 'Moreau', 'Morin', 'Nguyen', 'Nsimba',
        'Ouellet', 'Pelletier', 'Pierre', 'Roy', 'Simard', 'Sow', 'Tremblay', 'Turcotte', 'Williams',
    ];

    private const MONTREAL_STREETS = [
        'avenue du Mont-Royal', 'rue Saint-Denis', 'boulevard Saint-Laurent', 'rue Masson',
        'rue Beaubien', 'avenue Papineau', 'rue Jean-Talon', 'avenue du Parc', 'rue Ontario',
        'rue Wellington', 'rue Rachel', 'rue Jarry',
    ];

    /**
     * @param  array<string, mixed>  $blueprint
     * @return array{customers: Collection<int, Customer>, customers_by_story: Collection<string, Customer>}
     */
    public function generate(
        DemoScenarioContext $context,
        array $blueprint,
        int $customerTarget,
    ): array {
        $target = max(count((array) $blueprint['client_stories']), $customerTarget);
        if ($target > count(self::FIRST_NAMES) * count(self::LAST_NAMES)) {
            throw new \RuntimeException('Customer name pool cannot provide unique demo identities.');
        }

        $customers = collect();
        $stories = collect();

        foreach ((array) $blueprint['client_stories'] as $index => $story) {
            [$firstName, $lastName] = $this->splitName((string) $story['name']);
            $createdAt = $context->referenceDate->subDays(abs((int) data_get($story, 'timeline.0.offset_days', -180)));
            $customer = $this->createCustomer(
                $context,
                $index,
                $firstName,
                $lastName,
                $createdAt,
                (array) $story['profile'],
                (string) $story['key'],
            );
            $customers->push($customer);
            $stories->put((string) $story['key'], $customer);
            $this->recordStoryOpening($context, $customer, $story, $createdAt);
        }

        $storyCount = $customers->count();
        $generatedCustomerCount = max(1, $target - $storyCount);

        for ($index = $storyCount; $index < $target; $index++) {
            $firstName = self::FIRST_NAMES[$index % count(self::FIRST_NAMES)];
            $lastName = self::LAST_NAMES[intdiv($index, count(self::FIRST_NAMES)) % count(self::LAST_NAMES)];
            $createdAt = $this->customerCreatedAt(
                $context,
                $index - $storyCount,
                $generatedCustomerCount,
            );
            $segment = $this->segmentForIndex($index);
            $customer = $this->createCustomer(
                $context,
                $index,
                $firstName,
                $lastName,
                $createdAt,
                [
                    'tags' => $segment['tags'],
                    'internal_note' => $segment['note'],
                    'marketing_consent' => $index % 7 !== 0,
                ],
                null,
            );
            $customers->push($customer);
        }

        return [
            'customers' => $customers->values(),
            'customers_by_story' => $stories,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function createCustomer(
        DemoScenarioContext $context,
        int $index,
        string $firstName,
        string $lastName,
        CarbonImmutable $createdAt,
        array $profile,
        ?string $storyKey,
    ): Customer {
        $owner = $context->owner;
        $isVip = in_array('vip', (array) ($profile['tags'] ?? []), true) || $index % 17 === 0;
        $emailIdentity = Str::slug($firstName.'.'.$lastName).'-'.$index.'-'.$context->workspace->id;

        $customer = Customer::query()->create([
            'user_id' => $owner->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $emailIdentity.'@clients.studio-naya.example',
            'phone' => sprintf('+1 514 %03d %04d', 200 + ($index % 700), 1000 + ($index % 8999)),
            'description' => Str::limit((string) ($profile['internal_note'] ?? 'Clientèle locale de Studio Naya.'), 250, ''),
            'tags' => array_values(array_unique([
                ...(array) ($profile['tags'] ?? []),
                'scenario:studio_naya',
                $storyKey ? 'story:'.$storyKey : 'segment:'.$this->segmentForIndex($index)['key'],
            ])),
            'is_vip' => $isVip,
            'vip_tier_code' => $isVip ? 'naya_signature' : null,
            'vip_since_at' => $isVip ? $createdAt->addMonths(2) : null,
            'logo' => '/images/presets/avatar-'.(($index % 4) + 1).'.svg',
            'refer_by' => $index % 4 === 0 ? 'Recommandation client' : ($index % 4 === 1 ? 'Instagram' : 'Passage en salon'),
            'salutation' => $index % 5 === 0 ? 'Mr' : 'Mrs',
            'billing_same_as_physical' => true,
            'billing_mode' => 'per_job',
            'billing_delay_days' => 0,
            'discount_rate' => $isVip ? 5 : 0,
            'loyalty_points_balance' => ($index * 37) % 780,
            'is_active' => true,
        ]);

        DB::table('customers')->where('id', $customer->id)->update([
            'created_at' => $createdAt->utc(),
            'updated_at' => $createdAt->utc(),
        ]);
        $customer->setRawAttributes([
            ...$customer->getAttributes(),
            'created_at' => $createdAt->utc(),
            'updated_at' => $createdAt->utc(),
        ], true);

        $property = Property::query()->create([
            'customer_id' => $customer->id,
            'type' => 'physical',
            'is_default' => true,
            'country' => 'Canada',
            'street1' => (100 + ($index * 13) % 8900).', '.self::MONTREAL_STREETS[$index % count(self::MONTREAL_STREETS)],
            'city' => 'Montréal',
            'state' => 'QC',
            'zip' => sprintf('H%d%s %d%s%d', 1 + ($index % 4), chr(65 + ($index % 20)), $index % 9, chr(65 + (($index + 7) % 20)), ($index + 3) % 9),
        ]);
        DB::table($property->getTable())->where('id', $property->id)->update([
            'created_at' => $createdAt->utc(),
            'updated_at' => $createdAt->utc(),
        ]);

        $marketingGranted = (bool) ($profile['marketing_consent'] ?? true);
        foreach (['email', 'sms'] as $channel) {
            $consent = CustomerConsent::query()->create([
                'user_id' => $owner->id,
                'customer_id' => $customer->id,
                'channel' => $channel,
                'status' => $marketingGranted ? CustomerConsent::STATUS_GRANTED : CustomerConsent::STATUS_REVOKED,
                'source' => 'studio_naya_scenario',
                'granted_at' => $marketingGranted ? $createdAt->addDay() : null,
                'revoked_at' => $marketingGranted ? null : $createdAt->addDays(2),
                'metadata' => ['scenario_key' => 'studio_naya_coiffure'],
            ]);
            DB::table($consent->getTable())->where('id', $consent->id)->update([
                'created_at' => $createdAt->utc(),
                'updated_at' => $createdAt->addDays($marketingGranted ? 1 : 2)->utc(),
            ]);
        }

        return $customer->fresh();
    }

    /**
     * @param  array<string, mixed>  $story
     */
    private function recordStoryOpening(
        DemoScenarioContext $context,
        Customer $customer,
        array $story,
        CarbonImmutable $createdAt,
    ): void {
        $log = ActivityLog::record(
            $context->owner,
            $customer,
            'demo_story_started',
            [
                'scenario_key' => 'studio_naya_coiffure',
                'story_key' => $story['key'],
                'archetype' => $story['archetype'],
            ],
            'Parcours client narratif initialisé pour Studio Naya.',
        );

        DB::table('activity_logs')->where('id', $log->id)->update([
            'created_at' => $createdAt->utc(),
            'updated_at' => $createdAt->utc(),
        ]);
    }

    private function customerCreatedAt(
        DemoScenarioContext $context,
        int $index,
        int $target,
    ): CarbonImmutable {
        $historyStart = $context->referenceDate->subMonths(18)->startOfDay();
        $historyEnd = $context->referenceDate->subDays(3)->startOfDay();
        $historyDays = $historyStart->diffInDays($historyEnd);
        $spread = (int) floor(($index / max(1, $target - 1)) * $historyDays);
        $jitter = $index === 0 ? 0 : $context->randomizer('customers')->getInt(0, 12);
        $date = $historyStart->addDays(min($historyDays, $spread + $jitter));

        return $index === 0
            ? $date->startOfDay()
            : $date->setTime(10 + ($index % 7), ($index * 7) % 60);
    }

    /**
     * @return array{key:string, tags:array<int, string>, note:string}
     */
    private function segmentForIndex(int $index): array
    {
        return match ($index % 8) {
            0 => ['key' => 'loyal', 'tags' => ['fidele', 'rappels_actifs'], 'note' => 'Préfère conserver la même personne pour chaque visite.'],
            1 => ['key' => 'color', 'tags' => ['coloration', 'entretien'], 'note' => 'Historique de coloration à vérifier avant le service.'],
            2 => ['key' => 'protective', 'tags' => ['tresses', 'coiffure_protectrice'], 'note' => 'Préférence pour les coiffures protectrices longue durée.'],
            3 => ['key' => 'barber', 'tags' => ['barbier', 'recurrence'], 'note' => 'Coupe courte avec entretien régulier.'],
            4 => ['key' => 'family', 'tags' => ['famille', 'enfant'], 'note' => 'Réserve parfois pour plusieurs membres de la famille.'],
            5 => ['key' => 'care', 'tags' => ['soin', 'hydratation'], 'note' => 'Priorité aux soins hydratants et réparateurs.'],
            6 => ['key' => 'occasional', 'tags' => ['occasionnel'], 'note' => 'Client occasionnel, surtout avant un événement.'],
            default => ['key' => 'new', 'tags' => ['nouveau'], 'note' => 'Nouveau client acquis par recommandation locale.'],
        };
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $first = array_shift($parts) ?: 'Client';

        return [$first, implode(' ', $parts) ?: 'Studio Naya'];
    }
}
