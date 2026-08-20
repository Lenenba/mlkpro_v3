<?php

namespace App\Services\Demo\Generators;

use App\Enums\PromotionDiscountType;
use App\Enums\PromotionStatus;
use App\Enums\PromotionTargetType;
use App\Models\Campaign;
use App\Models\CampaignAudience;
use App\Models\CampaignChannel;
use App\Models\CampaignEvent;
use App\Models\CampaignMessage;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\MailingList;
use App\Models\MailingListCustomer;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\SocialAccountConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialPostTemplate;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiKnowledgeItem;
use App\Modules\AiAssistant\Models\AiMessage;
use App\Services\Demo\DemoScenarioContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DemoEngagementGenerator
{
    /**
     * @var array<int, string>
     */
    private const TARGET_KEYS = [
        'mailing_lists',
        'mailing_list_memberships',
        'campaigns',
        'campaign_channels',
        'campaign_audiences',
        'campaign_runs',
        'campaign_recipients',
        'campaign_messages',
        'campaign_events',
        'promotions',
        'promotion_usages',
        'assistant_settings',
        'assistant_knowledge_items',
        'assistant_conversations',
        'assistant_messages',
        'assistant_actions',
        'social_accounts',
        'social_templates',
        'social_posts',
        'social_targets',
    ];

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  array<string, int>  $targets
     * @param  Collection<int, int>  $customerIds
     * @param  Collection<int, int>  $serviceIds
     * @param  Collection<int, int>  $productIds
     * @param  Collection<int, int>  $reservationIds
     * @return array<string, mixed>
     */
    public function generate(
        DemoScenarioContext $context,
        array $blueprint,
        array $targets,
        Collection $customerIds,
        Collection $serviceIds,
        Collection $productIds,
        Collection $reservationIds,
    ): array {
        return Model::withoutEvents(function () use (
            $context,
            $blueprint,
            $targets,
            $customerIds,
            $serviceIds,
            $productIds,
            $reservationIds,
        ): array {
            $reference = CarbonImmutable::parse(
                $context->referenceDate->toDateString(),
                $context->timezone,
            )->startOfDay();
            $ownerId = (int) $context->owner->id;
            $marketingCustomerTarget = max(
                (int) ($targets['mailing_list_memberships'] ?? 0),
                (int) ($targets['campaign_recipients'] ?? 0),
            );
            $assistantCustomerTarget = (int) ($targets['assistant_conversations'] ?? 0);
            $marketingCustomers = Customer::query()
                ->join('customer_consents', function ($join) use ($ownerId): void {
                    $join
                        ->on('customer_consents.customer_id', '=', 'customers.id')
                        ->where('customer_consents.user_id', '=', $ownerId)
                        ->where('customer_consents.channel', '=', strtolower(Campaign::CHANNEL_EMAIL))
                        ->where('customer_consents.status', '=', CustomerConsent::STATUS_GRANTED);
                })
                ->where('customers.user_id', $ownerId)
                ->whereIn('customers.id', $customerIds)
                ->whereNotNull('customers.email')
                ->orderBy('customers.created_at')
                ->orderBy('customers.first_name')
                ->orderBy('customers.last_name')
                ->limit($marketingCustomerTarget)
                ->get([
                    'customers.id',
                    'customers.user_id',
                    'customers.first_name',
                    'customers.last_name',
                    'customers.email',
                    'customers.phone',
                    'customers.created_at',
                ]);
            $assistantCustomers = Customer::query()
                ->where('user_id', $ownerId)
                ->whereIn('id', $customerIds)
                ->orderBy('created_at')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->limit($assistantCustomerTarget)
                ->get([
                    'id',
                    'user_id',
                    'first_name',
                    'last_name',
                    'email',
                    'phone',
                    'created_at',
                ]);
            $services = Product::query()
                ->where('user_id', $ownerId)
                ->whereIn('id', $serviceIds)
                ->get(['id', 'user_id', 'item_type']);
            $products = Product::query()
                ->where('user_id', $ownerId)
                ->whereIn('id', $productIds)
                ->get(['id', 'user_id', 'item_type']);
            $relevantCustomerIds = collect(array_values(array_unique([
                ...$marketingCustomers
                    ->take((int) ($targets['campaign_recipients'] ?? 0))
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all(),
                ...$assistantCustomers
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all(),
            ])));
            $reservations = Reservation::query()
                ->forAccount($ownerId)
                ->whereIn('id', $reservationIds->all())
                ->whereIn('client_id', $relevantCustomerIds)
                ->orderBy('starts_at')
                ->get(['id', 'account_id', 'client_id', 'starts_at']);

            if ($marketingCustomers->count() < $marketingCustomerTarget) {
                throw new RuntimeException(sprintf(
                    'Studio Naya needs %d marketing-consented customers, but only %d are available.',
                    $marketingCustomerTarget,
                    $marketingCustomers->count(),
                ));
            }

            $mailingLists = $this->createMailingLists(
                $context,
                $targets,
                $marketingCustomers,
                $reference,
            );
            $promotions = $this->createPromotions(
                $context,
                $targets,
                $services,
                $products,
                $assistantCustomers,
                $reference,
            );
            $this->createCampaigns(
                $context,
                $blueprint,
                $targets,
                $mailingLists,
                $promotions,
                $marketingCustomers,
                $services,
                $products,
                $reservations,
                $reference,
            );
            $this->createAssistantHistory(
                $context,
                $blueprint,
                $targets,
                $assistantCustomers,
                $reservations,
                $reference,
            );
            $this->createSocialHistory(
                $context,
                $targets,
                $reference,
            );

            $counts = $this->databaseCounts($ownerId);
            $this->assertTargets($targets, $counts);
            $invariants = $this->validateDataset($ownerId, $targets, $counts, $reference);

            return [
                ...$counts,
                'invariant_report' => $invariants,
            ];
        });
    }

    /**
     * @param  array<string, int>  $targets
     * @param  Collection<int, Customer>  $customers
     * @return Collection<int, MailingList>
     */
    private function createMailingLists(
        DemoScenarioContext $context,
        array $targets,
        Collection $customers,
        CarbonImmutable $reference,
    ): Collection {
        $definitions = [
            [
                'name' => 'Clientes fidèles Studio Naya',
                'description' => 'Clientes régulières ayant accepté les communications du salon.',
                'tags' => ['fidélité', 'rétention', 'studio-naya'],
            ],
            [
                'name' => 'Textures, tresses et soins',
                'description' => 'Clientes intéressées par les coiffures protectrices et les soins texturés.',
                'tags' => ['textures', 'tresses', 'soins'],
            ],
            [
                'name' => 'Couleur et transformations',
                'description' => 'Clientes couleur, balayage et transformations saisonnières.',
                'tags' => ['couleur', 'balayage', 'transformation'],
            ],
            [
                'name' => 'Barbier et entretien',
                'description' => 'Clientèle coupe, barbe et entretien récurrent.',
                'tags' => ['barbier', 'entretien', 'récurrence'],
            ],
        ];
        $listTarget = (int) ($targets['mailing_lists'] ?? 0);
        $membershipTarget = (int) ($targets['mailing_list_memberships'] ?? 0);
        $lists = collect();

        for ($index = 0; $index < $listTarget; $index++) {
            $definition = $definitions[$index % count($definitions)];
            $cycle = intdiv($index, count($definitions));
            $createdAt = $reference->subDays(240 - ($index * 13))->utc();
            $list = $this->persist(MailingList::class, [
                'user_id' => $context->owner->id,
                'created_by_user_id' => $context->owner->id,
                'updated_by_user_id' => $context->owner->id,
                'name' => $definition['name'].($cycle > 0 ? ' '.($cycle + 1) : ''),
                'description' => $definition['description'],
                'tags' => $definition['tags'],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $lists->push($list);
        }

        if ($membershipTarget > 0 && $lists->isEmpty()) {
            throw new RuntimeException('Studio Naya cannot create mailing-list memberships without a mailing list.');
        }

        foreach ($customers->take($membershipTarget)->values() as $index => $customer) {
            $list = $lists[$index % $lists->count()];
            $addedAt = $reference->subDays(180 - ($index % 120))->utc();

            $this->persist(MailingListCustomer::class, [
                'mailing_list_id' => $list->id,
                'customer_id' => $customer->id,
                'added_by_user_id' => $context->owner->id,
                'added_at' => $addedAt,
                'created_at' => $addedAt,
                'updated_at' => $addedAt,
            ]);
        }

        return $lists;
    }

    /**
     * @param  array<string, int>  $targets
     * @param  Collection<string, Product>  $services
     * @param  Collection<string, Product>  $products
     * @param  Collection<int, Customer>  $customers
     * @return Collection<int, Promotion>
     */
    private function createPromotions(
        DemoScenarioContext $context,
        array $targets,
        Collection $services,
        Collection $products,
        Collection $customers,
        CarbonImmutable $reference,
    ): Collection {
        $promotionTarget = (int) ($targets['promotions'] ?? 0);
        $usageTarget = (int) ($targets['promotion_usages'] ?? 0);
        $promotions = collect();

        for ($index = 0; $index < $promotionTarget; $index++) {
            $isActive = $index % 2 === 0;
            $cycle = intdiv($index, 2);
            if ($isActive) {
                $startDate = $reference->subDays(210 + ($cycle * 20));
                $endDate = $reference->addDays(60 + ($cycle * 15));
            } else {
                $startDate = match ($cycle % 3) {
                    0 => $reference->subDays(390),
                    1 => $reference->subDays(540),
                    default => $reference->subDays(240),
                };
                $endDate = $startDate->addDays(209);
            }

            [$targetType, $targetId] = match ($index % 3) {
                0 => [PromotionTargetType::GLOBAL, null],
                1 => [PromotionTargetType::SERVICE, $services->values()[$index % $services->count()]?->id],
                default => [PromotionTargetType::PRODUCT, $products->values()[$index % $products->count()]?->id],
            };
            $discountType = $isActive
                ? PromotionDiscountType::PERCENTAGE
                : PromotionDiscountType::FIXED;
            $createdAt = $startDate->subDays(14)->utc();
            $promotion = $this->persist(Promotion::class, [
                'user_id' => $context->owner->id,
                'created_by_user_id' => $context->owner->id,
                'name' => $isActive
                    ? 'Hydratation saisonnière '.($cycle + 1)
                    : 'Retour au salon '.($cycle + 1),
                'code' => ($isActive ? 'NAYA-HYDRATE-' : 'NAYA-RETOUR-').($cycle + 1),
                'target_type' => $targetType,
                'target_id' => $targetId,
                'discount_type' => $discountType,
                'discount_value' => $isActive ? 15.00 : 10.00,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'status' => $isActive ? PromotionStatus::ACTIVE : PromotionStatus::INACTIVE,
                'usage_limit' => max(25, (int) ceil($usageTarget / max(1, $promotionTarget)) + 10),
                'minimum_order_amount' => $isActive ? 45.00 : 30.00,
                'rules' => [
                    'demo_generated' => true,
                    'external_side_effects' => false,
                    'customer_segment' => $customers->isNotEmpty() ? 'studio_naya_clients' : null,
                ],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $promotions->push($promotion);
        }

        if ($usageTarget > 0 && $promotions->isEmpty()) {
            throw new RuntimeException('Studio Naya cannot create promotion usages without promotions.');
        }

        $sales = Sale::query()
            ->where('user_id', $context->owner->id)
            ->where('status', Sale::STATUS_PAID)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $usedSaleIds = [];
        $remainingUsageCount = $usageTarget;

        foreach ($promotions as $promotionIndex => $promotion) {
            $promotionsLeft = $promotions->count() - $promotionIndex;
            $usageCount = $promotionsLeft > 0
                ? (int) ceil($remainingUsageCount / $promotionsLeft)
                : 0;
            $windowStart = CarbonImmutable::instance($promotion->start_date)->startOfDay()->utc();
            $windowEnd = CarbonImmutable::instance($promotion->end_date)->endOfDay()->utc();
            $eligibleSales = $sales
                ->filter(function (Sale $sale) use ($usedSaleIds, $windowStart, $windowEnd): bool {
                    return ! isset($usedSaleIds[(int) $sale->id])
                        && $sale->created_at !== null
                        && $sale->created_at->betweenIncluded($windowStart, $windowEnd);
                })
                ->take($usageCount)
                ->values();

            if ($eligibleSales->count() !== $usageCount) {
                throw new RuntimeException(sprintf(
                    'Studio Naya promotion [%s] needs %d eligible sales, but only %d are available.',
                    $promotion->code,
                    $usageCount,
                    $eligibleSales->count(),
                ));
            }

            foreach ($eligibleSales as $sale) {
                $usedSaleIds[(int) $sale->id] = true;
                $discountTotal = $promotion->discount_type === PromotionDiscountType::PERCENTAGE
                    ? min(30.00, round((float) $sale->subtotal * ((float) $promotion->discount_value / 100), 2))
                    : min((float) $promotion->discount_value, (float) $sale->subtotal);
                $usedAt = CarbonImmutable::instance($sale->paid_at ?? $sale->created_at)->utc();

                $this->persist(PromotionUsage::class, [
                    'promotion_id' => $promotion->id,
                    'sale_id' => $sale->id,
                    'user_id' => $context->owner->id,
                    'customer_id' => $sale->customer_id,
                    'code' => $promotion->code,
                    'discount_total' => $discountTotal,
                    'snapshot' => [
                        'demo_generated' => true,
                        'promotion_name' => $promotion->name,
                        'discount_type' => $promotion->discount_type->value,
                        'discount_value' => (float) $promotion->discount_value,
                        'target_type' => $promotion->target_type->value,
                        'sale_total_before_recorded_discount' => (float) $sale->total,
                        'financial_totals_immutable' => true,
                    ],
                    'used_at' => $usedAt,
                    'created_at' => $usedAt,
                    'updated_at' => $usedAt,
                ]);
            }

            $remainingUsageCount -= $usageCount;
        }

        return $promotions;
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  array<string, int>  $targets
     * @param  Collection<int, MailingList>  $mailingLists
     * @param  Collection<int, Promotion>  $promotions
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<string, Product>  $services
     * @param  Collection<string, Product>  $products
     * @param  Collection<int, Reservation>  $reservations
     */
    private function createCampaigns(
        DemoScenarioContext $context,
        array $blueprint,
        array $targets,
        Collection $mailingLists,
        Collection $promotions,
        Collection $customers,
        Collection $services,
        Collection $products,
        Collection $reservations,
        CarbonImmutable $reference,
    ): void {
        $campaignTarget = (int) ($targets['campaigns'] ?? 0);
        $runTarget = (int) ($targets['campaign_runs'] ?? 0);
        $recipientTarget = (int) ($targets['campaign_recipients'] ?? 0);
        $expectedRuns = intdiv($campaignTarget, 3);

        if ($campaignTarget % 3 !== 0 || $runTarget !== $expectedRuns) {
            throw new RuntimeException('Studio Naya campaign targets must define one completed run per lifecycle trio.');
        }

        if ($campaignTarget > 0 && $mailingLists->isEmpty()) {
            throw new RuntimeException('Studio Naya campaigns require at least one mailing list.');
        }

        $definitions = [
            [
                'name' => 'Diagnostic boucles — contenu éducatif',
                'type' => Campaign::TYPE_ANNOUNCEMENT,
                'subject' => 'Comprendre votre texture avant le prochain rendez-vous',
                'body' => 'Découvrez les conseils de Studio Naya pour préparer votre diagnostic capillaire.',
            ],
            [
                'name' => 'Rentrée texture et hydratation',
                'type' => Campaign::TYPE_PROMOTION,
                'subject' => 'Votre rituel hydratation de rentrée',
                'body' => 'Réservez un soin sur mesure et retrouvez des cheveux souples et hydratés.',
            ],
            [
                'name' => 'Retour au salon — clientes fidèles',
                'type' => Campaign::TYPE_WINBACK,
                'subject' => 'Studio Naya pense à vous',
                'body' => 'Votre prochaine visite peut déjà être planifiée avec votre spécialiste habituelle.',
            ],
        ];
        $runs = collect();
        $campaignProducts = $services->values()->concat($products->values())->values();

        for ($index = 0; $index < $campaignTarget; $index++) {
            $lifecycleIndex = $index % 3;
            $cycle = intdiv($index, 3);
            $definition = $definitions[$lifecycleIndex];
            $status = match ($lifecycleIndex) {
                0 => Campaign::STATUS_DRAFT,
                1 => Campaign::STATUS_SCHEDULED,
                default => Campaign::STATUS_COMPLETED,
            };
            $scheduledAt = $status === Campaign::STATUS_SCHEDULED
                ? $reference->addDays(5 + ($cycle * 9))->setTime(10, 0)->utc()
                : null;
            $startedAt = $status === Campaign::STATUS_COMPLETED
                ? $reference->subDays(90 + ($cycle * 120))->setTime(9, 0)->utc()
                : null;
            $completedAt = $startedAt?->addHours(6);
            $createdAt = match ($status) {
                Campaign::STATUS_COMPLETED => $startedAt?->subDays(14),
                Campaign::STATUS_SCHEDULED => $reference->subDays(12 + ($cycle * 3))->utc(),
                default => $reference->subDays(8 + ($cycle * 3))->utc(),
            };
            $mailingList = $mailingLists[$index % $mailingLists->count()];
            $promotion = $promotions->isNotEmpty()
                ? $promotions[$index % $promotions->count()]
                : null;
            $campaign = $this->persist(Campaign::class, [
                'user_id' => $context->owner->id,
                'created_by_user_id' => $context->owner->id,
                'updated_by_user_id' => $context->owner->id,
                'name' => $definition['name'].($cycle > 0 ? ' '.($cycle + 1) : ''),
                'campaign_type' => $definition['type'],
                'campaign_direction' => Campaign::DIRECTION_CUSTOMER_MARKETING,
                'prospecting_enabled' => false,
                'offer_mode' => Campaign::OFFER_MODE_MIXED,
                'language_mode' => Campaign::LANGUAGE_MODE_FR,
                'type' => $definition['type'],
                'status' => $status,
                'schedule_type' => $status === Campaign::STATUS_DRAFT
                    ? Campaign::SCHEDULE_MANUAL
                    : Campaign::SCHEDULE_SCHEDULED,
                'scheduled_at' => $scheduledAt,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'locale' => 'fr_CA',
                'cta_url' => '/book/studio-naya',
                'is_marketing' => true,
                'last_run_at' => $completedAt,
                'settings' => [
                    'demo_generated' => true,
                    'delivery_mode' => 'synthetic_history',
                    'external_delivery' => false,
                    'mailing_lists' => [$mailingList->id],
                    'promotion_id' => $promotion?->id,
                    'promotion_code' => $promotion?->code,
                    'scenario_key' => data_get($blueprint, 'identity.name', 'Studio Naya Coiffure'),
                ],
                'created_at' => $createdAt,
                'updated_at' => $completedAt ?? $scheduledAt ?? $createdAt,
            ]);

            $this->persist(CampaignChannel::class, [
                'campaign_id' => $campaign->id,
                'channel' => Campaign::CHANNEL_EMAIL,
                'is_enabled' => true,
                'subject_template' => $definition['subject'],
                'body_template' => $definition['body'].' {{first_name}}',
                'content_override' => [
                    'locale' => 'fr_CA',
                    'demo_generated' => true,
                ],
                'metadata' => [
                    'external_delivery' => false,
                    'provider' => 'synthetic_demo',
                ],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $this->persist(CampaignAudience::class, [
                'campaign_id' => $campaign->id,
                'smart_filters' => ['marketing_consent' => true],
                'exclusion_filters' => ['opted_out' => true],
                'manual_customer_ids' => null,
                'include_mailing_list_ids' => [$mailingList->id],
                'exclude_mailing_list_ids' => [],
                'source_logic' => 'UNION',
                'source_summary' => [
                    'mailing_list_name' => $mailingList->name,
                    'demo_generated' => true,
                ],
                'manual_contacts' => null,
                'estimated_counts' => [
                    'email' => $mailingList->listCustomers()->count(),
                ],
                'resolved_at' => $status === Campaign::STATUS_COMPLETED ? $startedAt?->subMinutes(15) : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            if ($campaignProducts->isNotEmpty()) {
                DB::table('campaign_product')->insert([
                    'campaign_id' => $campaign->id,
                    'product_id' => $campaignProducts[$index % $campaignProducts->count()]->id,
                    'metadata' => json_encode([
                        'demo_generated' => true,
                        'promotion_code' => $promotion?->code,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }

            if ($status !== Campaign::STATUS_COMPLETED) {
                continue;
            }

            $run = $this->persist(CampaignRun::class, [
                'campaign_id' => $campaign->id,
                'user_id' => $context->owner->id,
                'triggered_by_user_id' => $context->owner->id,
                'trigger_type' => CampaignRun::TRIGGER_SCHEDULED,
                'status' => CampaignRun::STATUS_COMPLETED,
                'idempotency_key' => sprintf(
                    'demo-naya-%d-%d-%s',
                    $context->owner->id,
                    $cycle + 1,
                    substr(hash('sha256', (string) $context->randomSeed), 0, 16),
                ),
                'scheduled_for' => $startedAt,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'audience_snapshot' => [
                    'mailing_list_name' => $mailingList->name,
                    'channel' => Campaign::CHANNEL_EMAIL,
                    'synthetic' => true,
                ],
                'summary' => null,
                'created_at' => $startedAt,
                'updated_at' => $completedAt,
            ]);
            $runs->push($run);
        }

        if ($recipientTarget > 0 && $runs->isEmpty()) {
            throw new RuntimeException('Studio Naya cannot create campaign recipients without a completed run.');
        }

        $recipientCustomers = $customers->take($recipientTarget)->values();
        $customerOffset = 0;
        $basePerRun = $runs->isEmpty() ? 0 : intdiv($recipientTarget, $runs->count());
        $remainder = $runs->isEmpty() ? 0 : $recipientTarget % $runs->count();
        $reservationByCustomer = $reservations
            ->groupBy('client_id')
            ->map(fn (Collection $items): ?Reservation => $items->sortByDesc('starts_at')->first());

        foreach ($runs as $runIndex => $run) {
            $runRecipientCount = $basePerRun + ($runIndex < $remainder ? 1 : 0);
            $statusCounts = [];

            for ($localIndex = 0; $localIndex < $runRecipientCount; $localIndex++) {
                $globalIndex = $customerOffset + $localIndex;
                $customer = $recipientCustomers[$globalIndex];
                $status = [
                    CampaignRecipient::STATUS_DELIVERED,
                    CampaignRecipient::STATUS_OPENED,
                    CampaignRecipient::STATUS_CLICKED,
                    CampaignRecipient::STATUS_CONVERTED,
                ][$globalIndex % 4];
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
                $queuedAt = CarbonImmutable::instance($run->started_at)->addMinutes($localIndex * 3);
                $sentAt = $queuedAt->addMinute();
                $deliveredAt = $sentAt->addMinute();
                $openedAt = in_array($status, [
                    CampaignRecipient::STATUS_OPENED,
                    CampaignRecipient::STATUS_CLICKED,
                    CampaignRecipient::STATUS_CONVERTED,
                ], true) ? $deliveredAt->addMinute() : null;
                $clickedAt = in_array($status, [
                    CampaignRecipient::STATUS_CLICKED,
                    CampaignRecipient::STATUS_CONVERTED,
                ], true) ? $openedAt?->addMinute() : null;
                $convertedAt = $status === CampaignRecipient::STATUS_CONVERTED
                    ? $clickedAt?->addMinute()
                    : null;
                $providerMessageId = sprintf('demo-%d-%d-%03d', $context->owner->id, $runIndex + 1, $localIndex + 1);
                $recipient = $this->persist(CampaignRecipient::class, [
                    'campaign_run_id' => $run->id,
                    'campaign_id' => $run->campaign_id,
                    'user_id' => $context->owner->id,
                    'customer_id' => $customer->id,
                    'channel' => Campaign::CHANNEL_EMAIL,
                    'destination' => $customer->email,
                    'destination_hash' => CampaignRecipient::destinationHash($customer->email),
                    'dedupe_key' => sprintf('demo:%d:%d:%d', $context->owner->id, $run->id, $customer->id),
                    'status' => $status,
                    'provider' => 'synthetic_demo',
                    'provider_message_id' => $providerMessageId,
                    'tracking_token' => hash('sha256', 'track|'.$context->owner->id.'|'.$run->id.'|'.$customer->id),
                    'unsubscribe_token' => hash('sha256', 'unsubscribe|'.$context->owner->id.'|'.$run->id.'|'.$customer->id),
                    'queued_at' => $queuedAt,
                    'sent_at' => $sentAt,
                    'delivered_at' => $deliveredAt,
                    'opened_at' => $openedAt,
                    'clicked_at' => $clickedAt,
                    'converted_at' => $convertedAt,
                    'metadata' => [
                        'synthetic' => true,
                        'external_delivery' => false,
                    ],
                    'created_at' => $queuedAt,
                    'updated_at' => $convertedAt ?? $clickedAt ?? $openedAt ?? $deliveredAt,
                ]);

                $this->persist(CampaignMessage::class, [
                    'campaign_recipient_id' => $recipient->id,
                    'campaign_run_id' => $run->id,
                    'channel' => Campaign::CHANNEL_EMAIL,
                    'subject_rendered' => $run->campaign->channels()->value('subject_template'),
                    'body_rendered' => sprintf(
                        'Bonjour %s, cette communication Studio Naya fait partie de votre historique de démonstration.',
                        $customer->first_name,
                    ),
                    'cta_url' => '/book/studio-naya',
                    'tracked_cta_url' => '/book/studio-naya?demo=1',
                    'payload' => [
                        'synthetic' => true,
                        'external_delivery' => false,
                    ],
                    'created_at' => $sentAt,
                    'updated_at' => $sentAt,
                ]);

                $eventTimeline = [
                    [CampaignEvent::EVENT_QUEUED, $queuedAt],
                    [CampaignEvent::EVENT_SENT, $sentAt],
                    [CampaignEvent::EVENT_DELIVERED, $deliveredAt],
                ];
                if ($openedAt) {
                    $eventTimeline[] = [CampaignEvent::EVENT_OPENED, $openedAt];
                }
                if ($clickedAt) {
                    $eventTimeline[] = [CampaignEvent::EVENT_CLICKED, $clickedAt];
                }
                if ($convertedAt) {
                    $eventTimeline[] = [CampaignEvent::EVENT_CONVERTED, $convertedAt];
                }

                foreach ($eventTimeline as [$eventType, $occurredAt]) {
                    $conversionReservation = $reservationByCustomer->get($customer->id);
                    $this->persist(CampaignEvent::class, [
                        'campaign_id' => $run->campaign_id,
                        'campaign_run_id' => $run->id,
                        'campaign_recipient_id' => $recipient->id,
                        'user_id' => $context->owner->id,
                        'customer_id' => $customer->id,
                        'channel' => Campaign::CHANNEL_EMAIL,
                        'event_type' => $eventType,
                        'provider_message_id' => $providerMessageId,
                        'conversion_type' => $eventType === CampaignEvent::EVENT_CONVERTED ? 'reservation' : null,
                        'conversion_id' => $eventType === CampaignEvent::EVENT_CONVERTED
                            ? $conversionReservation?->id
                            : null,
                        'occurred_at' => $occurredAt,
                        'metadata' => [
                            'synthetic' => true,
                            'external_delivery' => false,
                        ],
                        'created_at' => $occurredAt,
                        'updated_at' => $occurredAt,
                    ]);
                }
            }

            $run->forceFill([
                'summary' => [
                    'recipient_count' => $runRecipientCount,
                    'statuses' => $statusCounts,
                    'synthetic' => true,
                    'external_delivery' => false,
                ],
                'updated_at' => $run->completed_at,
            ])->save();
            $customerOffset += $runRecipientCount;
        }
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  array<string, int>  $targets
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, Reservation>  $reservations
     */
    private function createAssistantHistory(
        DemoScenarioContext $context,
        array $blueprint,
        array $targets,
        Collection $customers,
        Collection $reservations,
        CarbonImmutable $reference,
    ): void {
        $settingTarget = (int) ($targets['assistant_settings'] ?? 0);
        if ($settingTarget !== 1) {
            throw new RuntimeException('Studio Naya requires exactly one assistant setting.');
        }

        $settingCreatedAt = $reference->subDays(420)->utc();
        $this->persist(AiAssistantSetting::class, [
            'tenant_id' => $context->owner->id,
            'assistant_name' => 'Naya, assistante du salon',
            'enabled' => true,
            'default_language' => AiAssistantSetting::LANGUAGE_FR,
            'supported_languages' => [AiAssistantSetting::LANGUAGE_FR, AiAssistantSetting::LANGUAGE_EN],
            'tone' => AiAssistantSetting::TONE_WARM,
            'greeting_message' => 'Bonjour, je suis Naya. Je peux vous aider à choisir un service ou préparer une demande de rendez-vous.',
            'fallback_message' => 'Je transmets votre demande à l’équipe de Studio Naya pour une validation humaine.',
            'allow_create_prospect' => true,
            'allow_create_client' => false,
            'allow_create_reservation' => true,
            'allow_reschedule_reservation' => true,
            'allow_create_task' => false,
            'require_human_validation' => true,
            'enable_proactive_suggestions' => true,
            'enable_upsell_suggestions' => true,
            'enable_client_history_recommendations' => true,
            'max_suggestions_per_response' => 3,
            'require_confirmation_before_ai_action' => true,
            'allow_ai_to_choose_earliest_slot' => true,
            'allow_ai_to_recommend_staff' => true,
            'allow_ai_to_recommend_services' => true,
            'business_context' => data_get($blueprint, 'identity.description', 'Salon de coiffure inclusif à Montréal.'),
            'service_area_rules' => [
                'location' => 'Montréal, QC',
                'in_salon_only' => true,
            ],
            'working_hours_rules' => [
                'monday' => 'closed',
                'tuesday_wednesday' => '09:00-18:00',
                'thursday_friday' => '09:00-20:00',
                'saturday' => '08:00-17:00',
                'sunday' => 'closed',
                'timezone' => $context->timezone,
            ],
            'created_at' => $settingCreatedAt,
            'updated_at' => $settingCreatedAt,
        ]);

        $knowledgeDefinitions = [
            ['Horaires et jours d’ouverture', 'horaires', 'Studio Naya est ouvert du mardi au samedi et fermé le dimanche et le lundi.'],
            ['Réservation et confirmation', 'reservations', 'Une demande de rendez-vous doit être confirmée avant d’être considérée comme réservée.'],
            ['Annulation et retard', 'policies', 'Une annulation au moins 24 heures avant le rendez-vous évite les frais tardifs.'],
            ['Cheveux texturés et consultation', 'services', 'Une consultation aide à choisir la technique, la durée et la spécialiste adaptées à chaque texture.'],
            ['Coiffures protectrices', 'services', 'Les tresses et coiffures protectrices demandent une préparation et une durée variables selon le style.'],
            ['Coloration et test préalable', 'services', 'Une consultation et un test peuvent être requis avant une transformation couleur importante.'],
            ['Produits et entretien à domicile', 'retail', 'L’équipe recommande uniquement les produits adaptés au service et à la routine de la cliente.'],
            ['Paiements et pourboires', 'payments', 'Le salon accepte les cartes, le débit, les paiements en ligne et l’argent comptant.'],
        ];
        $knowledgeTarget = (int) ($targets['assistant_knowledge_items'] ?? 0);
        for ($index = 0; $index < $knowledgeTarget; $index++) {
            [$title, $category, $content] = $knowledgeDefinitions[$index % count($knowledgeDefinitions)];
            $createdAt = $reference->subDays(390 - ($index * 7))->utc();
            $this->persist(AiKnowledgeItem::class, [
                'tenant_id' => $context->owner->id,
                'title' => $title,
                'content' => $content,
                'category' => $category,
                'is_active' => true,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        $conversationTarget = (int) ($targets['assistant_conversations'] ?? 0);
        $messageTarget = (int) ($targets['assistant_messages'] ?? 0);
        $actionTarget = (int) ($targets['assistant_actions'] ?? 0);
        if ($messageTarget !== $conversationTarget * 3 || $actionTarget > $conversationTarget) {
            throw new RuntimeException('Studio Naya assistant targets require three messages per conversation and at most one action.');
        }

        if ($customers->count() < $conversationTarget) {
            throw new RuntimeException('Studio Naya does not have enough customers for the assistant history target.');
        }

        $pastReservations = $reservations
            ->filter(fn (Reservation $reservation): bool => $reservation->starts_at?->lt($reference->endOfDay()->utc()) ?? false)
            ->sortByDesc('starts_at')
            ->groupBy('client_id')
            ->map(fn (Collection $items): ?Reservation => $items->first());
        $conversations = collect();
        $intents = [
            AiConversation::INTENT_RESERVATION,
            AiConversation::INTENT_GENERAL,
            AiConversation::INTENT_RESCHEDULE,
            AiConversation::INTENT_HUMAN_REVIEW,
        ];
        $statuses = [
            AiConversation::STATUS_RESOLVED,
            AiConversation::STATUS_RESOLVED,
            AiConversation::STATUS_WAITING_HUMAN,
            AiConversation::STATUS_ABANDONED,
        ];
        $channels = [
            AiConversation::CHANNEL_WEB_CHAT,
            AiConversation::CHANNEL_PUBLIC_RESERVATION,
            AiConversation::CHANNEL_SMS,
            AiConversation::CHANNEL_EMAIL,
        ];

        foreach ($customers->take($conversationTarget)->values() as $index => $customer) {
            $reservation = $pastReservations->get($customer->id);
            $candidateCreatedAt = $reference->subDays(12 + ($index * 7))->setTime(11, $index % 60)->utc();
            if ($reservation?->starts_at) {
                $candidateCreatedAt = CarbonImmutable::instance($reservation->starts_at)->subDays(2);
            }
            $customerReadyAt = CarbonImmutable::instance($customer->created_at)->addHour();
            $createdAt = $candidateCreatedAt->greaterThan($customerReadyAt)
                ? $candidateCreatedAt
                : $customerReadyAt;
            $intent = $intents[$index % count($intents)];
            $status = $statuses[$index % count($statuses)];
            $conversation = $this->persist(AiConversation::class, [
                'tenant_id' => $context->owner->id,
                'public_uuid' => $this->deterministicUuid(
                    'assistant|'.$context->owner->id.'|'.$context->randomSeed.'|'.$index,
                ),
                'channel' => $channels[$index % count($channels)],
                'status' => $status,
                'visitor_name' => trim($customer->first_name.' '.$customer->last_name),
                'visitor_email' => $customer->email,
                'visitor_phone' => $customer->phone,
                'client_id' => $customer->id,
                'prospect_id' => null,
                'reservation_id' => $reservation?->id,
                'detected_language' => $index % 5 === 0 ? 'en' : 'fr',
                'intent' => $intent,
                'confidence_score' => 82 + ($index % 16),
                'summary' => $this->assistantSummary($intent, $status),
                'metadata' => [
                    'demo_generated' => true,
                    'model_call' => false,
                    'source' => 'deterministic_fixture',
                ],
                'created_at' => $createdAt,
                'updated_at' => $createdAt->addMinutes(3),
            ]);
            $conversations->push($conversation);

            $messages = $this->assistantMessages($intent, $status, $customer->first_name);
            foreach ($messages as $messageIndex => [$sender, $content]) {
                $messageAt = $createdAt->addMinutes($messageIndex);
                $this->persist(AiMessage::class, [
                    'conversation_id' => $conversation->id,
                    'sender_type' => $sender,
                    'content' => $content,
                    'payload' => [
                        'demo_generated' => true,
                        'model_call' => false,
                        'sequence' => $messageIndex + 1,
                    ],
                    'created_at' => $messageAt,
                    'updated_at' => $messageAt,
                ]);
            }
        }

        foreach ($conversations->take($actionTarget)->values() as $index => $conversation) {
            $executed = $index % 3 !== 2;
            $actionType = [
                AiAction::TYPE_CREATE_RESERVATION,
                AiAction::TYPE_RESCHEDULE_RESERVATION,
                AiAction::TYPE_REQUEST_HUMAN_REVIEW,
            ][$index % 3];
            $actionAt = CarbonImmutable::instance($conversation->created_at)->addMinutes(3);
            $this->persist(AiAction::class, [
                'tenant_id' => $context->owner->id,
                'conversation_id' => $conversation->id,
                'action_type' => $actionType,
                'status' => $executed ? AiAction::STATUS_EXECUTED : AiAction::STATUS_REJECTED,
                'input_payload' => [
                    'demo_generated' => true,
                    'client_name' => $conversation->visitor_name,
                    'requires_confirmation' => true,
                ],
                'output_payload' => $executed
                    ? ['synthetic' => true, 'external_side_effects' => false, 'result' => 'historical_demo_action']
                    : [
                        'synthetic' => true,
                        'external_side_effects' => false,
                        'reason' => 'human_validation_declined',
                    ],
                'error_message' => null,
                'executed_at' => $executed ? $actionAt : null,
                'created_at' => $actionAt,
                'updated_at' => $actionAt,
            ]);
        }
    }

    /**
     * @param  array<string, int>  $targets
     */
    private function createSocialHistory(
        DemoScenarioContext $context,
        array $targets,
        CarbonImmutable $reference,
    ): void {
        if ((int) ($targets['social_accounts'] ?? 0) !== 1) {
            throw new RuntimeException('Studio Naya requires exactly one non-publishable social account.');
        }

        $accountCreatedAt = $reference->subDays(360)->utc();
        $account = $this->persist(SocialAccountConnection::class, [
            'user_id' => $context->owner->id,
            'platform' => SocialAccountConnection::PLATFORM_INSTAGRAM,
            'label' => 'Instagram Studio Naya — démonstration',
            'display_name' => 'Studio Naya Coiffure',
            'account_handle' => '@studionaya.demo',
            'external_account_id' => 'demo-studio-naya-'.$context->owner->id,
            'auth_method' => SocialAccountConnection::AUTH_METHOD_MANUAL,
            'credentials' => null,
            'permissions' => [],
            'status' => SocialAccountConnection::STATUS_DISCONNECTED,
            'is_active' => false,
            'connected_at' => null,
            'last_synced_at' => null,
            'token_expires_at' => null,
            'last_error' => null,
            'metadata' => [
                'demo_generated' => true,
                'publishable' => false,
                'external_delivery' => false,
            ],
            'created_at' => $accountCreatedAt,
            'updated_at' => $accountCreatedAt,
        ]);

        $templateDefinitions = [
            ['Avant/après couleur', 'Une transformation sur mesure, pensée pour votre texture et votre quotidien. ✨', ['StudioNaya', 'CouleurMontreal']],
            ['Conseil entretien', 'Le bon soin commence par un diagnostic et une routine simple à maintenir à la maison.', ['SoinCapillaire', 'CheveuxTexturés']],
            ['Disponibilités de la semaine', 'Quelques rendez-vous sont encore disponibles cette semaine au Studio Naya.', ['CoiffureMontreal', 'Reservation']],
            ['Portrait équipe', 'Derrière chaque rendez-vous, une spécialiste qui écoute vos besoins et respecte votre style.', ['EquipeNaya', 'SalonMontreal']],
            ['Produit vedette', 'Notre sélection boutique prolonge les résultats du salon sans compliquer votre routine.', ['RoutineCapillaire', 'StudioNaya']],
        ];
        $templateTarget = (int) ($targets['social_templates'] ?? 0);
        for ($index = 0; $index < $templateTarget; $index++) {
            [$name, $text, $hashtags] = $templateDefinitions[$index % count($templateDefinitions)];
            $createdAt = $reference->subDays(300 - ($index * 17))->utc();
            $this->persist(SocialPostTemplate::class, [
                'user_id' => $context->owner->id,
                'created_by_user_id' => $context->owner->id,
                'updated_by_user_id' => $context->owner->id,
                'name' => $name,
                'content_payload' => [
                    'text' => $text,
                    'locale' => 'fr_CA',
                    'hashtags' => $hashtags,
                ],
                'media_payload' => [
                    'brief' => 'Visuel synthétique Studio Naya, sans média externe.',
                ],
                'link_url' => '/book/studio-naya',
                'metadata' => [
                    'demo_generated' => true,
                    'external_assets' => false,
                ],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        $postTarget = (int) ($targets['social_posts'] ?? 0);
        if ((int) ($targets['social_targets'] ?? 0) !== $postTarget || $postTarget % 3 !== 0) {
            throw new RuntimeException('Studio Naya social targets require one target per post and balanced lifecycle statuses.');
        }

        $postDefinitions = [
            ['Balayage lumineux', 'Des reflets sur mesure et une transition douce pour illuminer la chevelure.'],
            ['Routine hydratation', 'Trois gestes simples pour préserver l’hydratation entre deux visites.'],
            ['Coiffure protectrice', 'Une préparation soignée pour une coiffure protectrice confortable et durable.'],
            ['Coupe et barbe', 'Une finition nette, adaptée au style et au rythme de chaque client.'],
            ['Équipe Studio Naya', 'Notre équipe réunit des spécialités complémentaires pour mieux vous conseiller.'],
            ['Rendez-vous de rentrée', 'Planifiez votre prochaine visite et retrouvez votre spécialiste habituelle.'],
        ];

        for ($index = 0; $index < $postTarget; $index++) {
            [$title, $text] = $postDefinitions[$index % count($postDefinitions)];
            $status = [
                SocialPost::STATUS_DRAFT,
                SocialPost::STATUS_SCHEDULED,
                SocialPost::STATUS_PUBLISHED,
            ][$index % 3];
            $cycle = intdiv($index, 3);
            $createdAt = match ($status) {
                SocialPost::STATUS_PUBLISHED => $reference->subDays(180 - ($cycle * 12))->utc(),
                SocialPost::STATUS_SCHEDULED => $reference->subDays(3 + ($cycle % 5))->utc(),
                default => $reference->subDays(2 + ($cycle % 4))->utc(),
            };
            $scheduledFor = $status === SocialPost::STATUS_SCHEDULED
                ? $reference->addDays(3 + $cycle)->setTime(18, 30)->utc()
                : null;
            $publishedAt = $status === SocialPost::STATUS_PUBLISHED
                ? $createdAt->addDays(2)->setTime(18, 30)
                : null;
            $post = $this->persist(SocialPost::class, [
                'user_id' => $context->owner->id,
                'created_by_user_id' => $context->owner->id,
                'updated_by_user_id' => $context->owner->id,
                'source_type' => 'demo_scenario',
                'source_id' => null,
                'content_payload' => [
                    'title' => $title,
                    'text' => $text,
                    'locale' => 'fr_CA',
                    'hashtags' => ['StudioNaya', 'CoiffureMontreal', 'DemoLocale'],
                ],
                'media_payload' => [
                    'brief' => 'Contenu synthétique sans fichier ni publication externe.',
                ],
                'link_url' => '/book/studio-naya',
                'status' => $status,
                'scheduled_for' => $scheduledFor,
                'published_at' => $publishedAt,
                'failed_at' => null,
                'failure_reason' => null,
                'metadata' => [
                    'demo_generated' => true,
                    'synthetic_status' => true,
                    'external_delivery' => false,
                    'publishing_disabled' => true,
                ],
                'created_at' => $createdAt,
                'updated_at' => $publishedAt ?? $scheduledFor ?? $createdAt,
            ]);
            $targetStatus = match ($status) {
                SocialPost::STATUS_SCHEDULED => SocialPostTarget::STATUS_SCHEDULED,
                SocialPost::STATUS_PUBLISHED => SocialPostTarget::STATUS_PUBLISHED,
                default => SocialPostTarget::STATUS_PENDING,
            };
            $this->persist(SocialPostTarget::class, [
                'social_post_id' => $post->id,
                'social_account_connection_id' => $account->id,
                'status' => $targetStatus,
                'published_at' => $publishedAt,
                'failed_at' => null,
                'failure_reason' => null,
                'metadata' => [
                    'demo_generated' => true,
                    'dry_run' => true,
                    'external_delivery' => false,
                ],
                'created_at' => $createdAt,
                'updated_at' => $publishedAt ?? $scheduledFor ?? $createdAt,
            ]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function databaseCounts(int $ownerId): array
    {
        return [
            'mailing_lists' => MailingList::query()->forAccount($ownerId)->count(),
            'mailing_list_memberships' => DB::table('mailing_list_customers')
                ->join('mailing_lists', 'mailing_lists.id', '=', 'mailing_list_customers.mailing_list_id')
                ->where('mailing_lists.user_id', $ownerId)
                ->count(),
            'campaigns' => Campaign::query()->byUser($ownerId)->count(),
            'campaign_channels' => DB::table('campaign_channels')
                ->join('campaigns', 'campaigns.id', '=', 'campaign_channels.campaign_id')
                ->where('campaigns.user_id', $ownerId)
                ->count(),
            'campaign_audiences' => DB::table('campaign_audiences')
                ->join('campaigns', 'campaigns.id', '=', 'campaign_audiences.campaign_id')
                ->where('campaigns.user_id', $ownerId)
                ->count(),
            'campaign_runs' => CampaignRun::query()->byUser($ownerId)->count(),
            'campaign_recipients' => CampaignRecipient::query()->byUser($ownerId)->count(),
            'campaign_messages' => DB::table('campaign_messages')
                ->join('campaign_runs', 'campaign_runs.id', '=', 'campaign_messages.campaign_run_id')
                ->where('campaign_runs.user_id', $ownerId)
                ->count(),
            'campaign_events' => CampaignEvent::query()->where('user_id', $ownerId)->count(),
            'promotions' => Promotion::query()->forAccount($ownerId)->count(),
            'promotion_usages' => PromotionUsage::query()->where('user_id', $ownerId)->count(),
            'assistant_settings' => AiAssistantSetting::query()->forTenant($ownerId)->count(),
            'assistant_knowledge_items' => AiKnowledgeItem::query()->forTenant($ownerId)->count(),
            'assistant_conversations' => AiConversation::query()->forTenant($ownerId)->count(),
            'assistant_messages' => AiMessage::query()->forTenant($ownerId)->count(),
            'assistant_actions' => AiAction::query()->forTenant($ownerId)->count(),
            'social_accounts' => SocialAccountConnection::query()->byUser($ownerId)->count(),
            'social_templates' => SocialPostTemplate::query()->byUser($ownerId)->count(),
            'social_posts' => SocialPost::query()->byUser($ownerId)->count(),
            'social_targets' => DB::table('social_post_targets')
                ->join('social_posts', 'social_posts.id', '=', 'social_post_targets.social_post_id')
                ->where('social_posts.user_id', $ownerId)
                ->count(),
        ];
    }

    /**
     * @param  array<string, int>  $targets
     * @param  array<string, int>  $counts
     */
    private function assertTargets(array $targets, array $counts): void
    {
        foreach (self::TARGET_KEYS as $key) {
            $expected = (int) ($targets[$key] ?? -1);
            $actual = (int) ($counts[$key] ?? -2);

            if ($expected === $actual) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Studio Naya engagement target mismatch for %s: expected %d, generated %d.',
                $key,
                $expected,
                $actual,
            ));
        }
    }

    /**
     * @param  array<string, int>  $targets
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function validateDataset(
        int $ownerId,
        array $targets,
        array $counts,
        CarbonImmutable $reference,
    ): array {
        $campaignStatuses = Campaign::query()
            ->byUser($ownerId)
            ->pluck('status')
            ->unique()
            ->all();
        $campaignRuns = CampaignRun::query()
            ->byUser($ownerId)
            ->with(['campaign', 'recipients.customer', 'recipients.message', 'recipients.events'])
            ->get();
        $campaignGraphValid = $campaignRuns->every(function (CampaignRun $run) use ($ownerId): bool {
            return $run->status === CampaignRun::STATUS_COMPLETED
                && $run->campaign?->status === Campaign::STATUS_COMPLETED
                && (int) $run->campaign?->user_id === $ownerId
                && $run->recipients->isNotEmpty()
                && $run->recipients->every(function (CampaignRecipient $recipient) use ($run, $ownerId): bool {
                    return (int) $recipient->user_id === $ownerId
                        && (int) $recipient->campaign_id === (int) $run->campaign_id
                        && (int) $recipient->customer?->user_id === $ownerId
                        && $recipient->message !== null
                        && $recipient->events->count() >= 3
                        && $recipient->events->every(fn (CampaignEvent $event): bool => (int) $event->user_id === $ownerId
                            && (int) $event->campaign_id === (int) $run->campaign_id
                            && (int) $event->campaign_run_id === (int) $run->id
                            && (int) $event->customer_id === (int) $recipient->customer_id
                        );
                });
        });
        $promotionStatuses = Promotion::query()
            ->forAccount($ownerId)
            ->pluck('status')
            ->map(fn (PromotionStatus|string $status): string => $status instanceof PromotionStatus ? $status->value : $status)
            ->unique()
            ->all();
        $promotionUsages = PromotionUsage::query()
            ->where('user_id', $ownerId)
            ->with(['promotion', 'sale', 'customer'])
            ->get();
        $promotionGraphValid = $promotionUsages->every(function (PromotionUsage $usage) use ($ownerId): bool {
            if ((int) $usage->promotion?->user_id !== $ownerId
                || (int) $usage->sale?->user_id !== $ownerId
                || (int) $usage->sale_id === 0
                || $usage->used_at === null) {
                return false;
            }

            if ($usage->customer_id && (int) $usage->customer?->user_id !== $ownerId) {
                return false;
            }

            $start = CarbonImmutable::instance($usage->promotion->start_date)->startOfDay();
            $end = CarbonImmutable::instance($usage->promotion->end_date)->endOfDay();

            return CarbonImmutable::instance($usage->used_at)->betweenIncluded($start, $end);
        });
        $conversations = AiConversation::query()
            ->forTenant($ownerId)
            ->with(['client', 'reservation', 'messages', 'actions'])
            ->get();
        $assistantGraphValid = $conversations->every(function (AiConversation $conversation) use ($ownerId): bool {
            return (int) $conversation->client?->user_id === $ownerId
                && ($conversation->reservation === null || (int) $conversation->reservation->account_id === $ownerId)
                && $conversation->messages->count() === 3
                && $conversation->messages->every(fn (AiMessage $message): bool => data_get($message->payload, 'model_call') === false
                )
                && $conversation->actions->every(fn (AiAction $action): bool => (int) $action->tenant_id === $ownerId
                    && $action->status !== AiAction::STATUS_PENDING
                );
        });
        $socialAccount = SocialAccountConnection::query()->byUser($ownerId)->first();
        $socialStatuses = SocialPost::query()->byUser($ownerId)->pluck('status')->unique()->all();
        $socialPosts = SocialPost::query()
            ->byUser($ownerId)
            ->with('targets.socialAccountConnection')
            ->get();
        $socialGraphValid = $socialAccount !== null
            && ! $socialAccount->is_active
            && $socialAccount->status === SocialAccountConnection::STATUS_DISCONNECTED
            && $socialAccount->credentials === null
            && data_get($socialAccount->metadata, 'publishable') === false
            && $socialPosts->every(function (SocialPost $post) use ($ownerId): bool {
                return $post->targets->count() === 1
                    && $post->targets->every(fn (SocialPostTarget $target): bool => (int) $target->socialAccountConnection?->user_id === $ownerId
                        && data_get($target->metadata, 'external_delivery') === false
                    );
            });
        $targetCountsValid = collect(self::TARGET_KEYS)->every(
            fn (string $key): bool => (int) ($targets[$key] ?? -1) === (int) ($counts[$key] ?? -2),
        );
        $checks = [
            'target_counts_match' => $targetCountsValid,
            'campaign_lifecycle_present' => array_diff([
                Campaign::STATUS_DRAFT,
                Campaign::STATUS_SCHEDULED,
                Campaign::STATUS_COMPLETED,
            ], $campaignStatuses) === [],
            'campaign_delivery_graph_is_coherent' => $campaignGraphValid,
            'promotion_lifecycle_present' => array_diff([
                PromotionStatus::ACTIVE->value,
                PromotionStatus::INACTIVE->value,
            ], $promotionStatuses) === [],
            'promotion_usage_graph_is_coherent' => $promotionGraphValid,
            'assistant_history_is_db_only' => $assistantGraphValid
                && AiAssistantSetting::query()->forTenant($ownerId)->count() === 1,
            'social_lifecycle_present' => array_diff([
                SocialPost::STATUS_DRAFT,
                SocialPost::STATUS_SCHEDULED,
                SocialPost::STATUS_PUBLISHED,
            ], $socialStatuses) === [],
            'social_history_is_non_publishable' => $socialGraphValid,
            'future_synthetic_content_is_bounded' => SocialPost::query()
                ->byUser($ownerId)
                ->where('status', SocialPost::STATUS_SCHEDULED)
                ->where('scheduled_for', '<=', $reference->addMonths(2)->endOfDay()->utc())
                ->count() === intdiv((int) $targets['social_posts'], 3),
        ];
        $failedChecks = collect($checks)
            ->reject(fn (bool $valid): bool => $valid)
            ->keys()
            ->values()
            ->all();

        if ($failedChecks !== []) {
            throw new RuntimeException('Studio Naya engagement invariants failed: '.implode(', ', $failedChecks).'.');
        }

        return [
            'check_count' => count($checks),
            'passed_check_count' => count($checks),
            'failed_check_count' => 0,
            'failed_checks' => [],
            'violation_count' => 0,
        ];
    }

    /**
     * @return array<int, array{0:string,1:string}>
     */
    private function assistantMessages(string $intent, string $status, string $firstName): array
    {
        $question = match ($intent) {
            AiConversation::INTENT_RESERVATION => 'J’aimerais réserver un soin adapté à mes cheveux. Que me conseillez-vous?',
            AiConversation::INTENT_RESCHEDULE => 'Puis-je déplacer mon prochain rendez-vous à une autre journée?',
            AiConversation::INTENT_HUMAN_REVIEW => 'J’ai une demande particulière et je préfère parler à une personne.',
            default => 'Combien de temps dois-je prévoir pour une consultation?',
        };
        $answer = match ($intent) {
            AiConversation::INTENT_RESERVATION => "Bonjour {$firstName}, je peux préparer une demande et proposer les services adaptés avant validation par l’équipe.",
            AiConversation::INTENT_RESCHEDULE => "Bonjour {$firstName}, je peux préparer le déplacement; l’équipe confirmera le nouveau créneau.",
            AiConversation::INTENT_HUMAN_REVIEW => "Bonjour {$firstName}, votre demande a été transmise à l’équipe pour un suivi humain.",
            default => "Bonjour {$firstName}, une consultation dure généralement de 20 à 30 minutes selon le besoin.",
        };
        $closing = $status === AiConversation::STATUS_WAITING_HUMAN
            ? [AiMessage::SENDER_HUMAN, 'L’équipe a reçu la demande et fera le suivi pendant les heures d’ouverture.']
            : [AiMessage::SENDER_SYSTEM, 'Conversation de démonstration classée dans l’historique Studio Naya.'];

        return [
            [AiMessage::SENDER_USER, $question],
            [AiMessage::SENDER_ASSISTANT, $answer],
            $closing,
        ];
    }

    private function assistantSummary(string $intent, string $status): string
    {
        return match ($intent) {
            AiConversation::INTENT_RESERVATION => 'Demande de recommandation et de réservation préparée pour validation.',
            AiConversation::INTENT_RESCHEDULE => 'Demande de déplacement de rendez-vous enregistrée dans l’historique.',
            AiConversation::INTENT_HUMAN_REVIEW => 'Demande transférée à l’équipe pour suivi humain.',
            default => $status === AiConversation::STATUS_ABANDONED
                ? 'Question générale sans action, conversation terminée par la cliente.'
                : 'Question générale résolue avec les informations du salon.',
        };
    }

    private function deterministicUuid(string $value): string
    {
        $hex = hash('sha256', $value);
        $hex[12] = '4';
        $hex[16] = ['8', '9', 'a', 'b'][hexdec($hex[16]) % 4];

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    private function persist(string $modelClass, array $attributes): Model
    {
        $model = new $modelClass;
        $model->forceFill($attributes);
        $model->save();

        return $model;
    }
}
