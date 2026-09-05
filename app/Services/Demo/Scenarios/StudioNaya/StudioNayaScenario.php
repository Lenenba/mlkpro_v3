<?php

namespace App\Services\Demo\Scenarios\StudioNaya;

use App\Enums\DemoDataVolume;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Customer;
use App\Models\CustomerBehaviorEvent;
use App\Models\CustomerPackage;
use App\Models\CustomerPackageUsage;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LoyaltyPointLedger;
use App\Models\MailingList;
use App\Models\OfferPackage;
use App\Models\OfferPackageItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\Quote;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\SocialAccountConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTemplate;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\Transaction;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiKnowledgeItem;
use App\Modules\AiAssistant\Models\AiMessage;
use App\Services\Demo\Contracts\DemoScenario;
use App\Services\Demo\DemoScenarioContext;
use App\Services\Demo\DemoScenarioFingerprint;
use App\Services\Demo\DemoScenarioInvariantValidator;
use App\Services\Demo\DemoScenarioModuleEvidence;
use App\Services\Demo\Generators\DemoCommerceGenerator;
use App\Services\Demo\Generators\DemoCustomerGenerator;
use App\Services\Demo\Generators\DemoEngagementGenerator;
use App\Services\Demo\Generators\DemoReservationGenerator;
use App\Services\Demo\Generators\DemoTeamCatalogGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class StudioNayaScenario implements DemoScenario
{
    public function __construct(
        private readonly DemoTeamCatalogGenerator $teamCatalogGenerator,
        private readonly DemoCustomerGenerator $customerGenerator,
        private readonly DemoReservationGenerator $reservationGenerator,
        private readonly DemoCommerceGenerator $commerceGenerator,
        private readonly DemoEngagementGenerator $engagementGenerator,
        private readonly DemoScenarioInvariantValidator $invariantValidator,
        private readonly DemoScenarioModuleEvidence $moduleEvidence,
        private readonly DemoScenarioFingerprint $fingerprint,
    ) {}

    public function key(): string
    {
        return StudioNayaBlueprint::KEY;
    }

    public function version(): int
    {
        return 1;
    }

    public function defaultVolume(): DemoDataVolume
    {
        return DemoDataVolume::Medium;
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(DemoScenarioContext $context): array
    {
        $blueprint = StudioNayaBlueprint::definition();
        $targets = $this->targets($context->dataVolume);

        return DB::transaction(function () use ($context, $blueprint, $targets): array {
            $resources = $this->teamCatalogGenerator->generate($context, $blueprint, $targets);
            $customers = $this->customerGenerator->generate(
                $context,
                $blueprint,
                (int) $targets['customers'],
            );
            $reservations = $this->reservationGenerator->generate(
                $context,
                $blueprint,
                $customers['customers'],
                $customers['customers_by_story'],
                $resources['team_members'],
                $resources['services'],
                (int) $targets['reservations'],
            );
            $commerce = $this->commerceGenerator->generate(
                $context,
                $blueprint,
                $targets,
                $customers['customers'],
                $customers['customers_by_story'],
                $resources['team_members'],
                $resources['services'],
                $resources['products'],
                $reservations['reservations'],
            );
            $customerIds = $customers['customers']->pluck('id')->map(fn (mixed $id): int => (int) $id)->values();
            $serviceIds = $resources['services']->pluck('id')->map(fn (mixed $id): int => (int) $id)->values();
            $productIds = $resources['products']->pluck('id')->map(fn (mixed $id): int => (int) $id)->values();
            $reservationIds = $reservations['reservations']->map(fn (mixed $id): int => (int) $id)->values();
            $publicBookingLinks = (int) $reservations['public_booking_links'];
            $refunds = (int) $commerce['refunds'];
            $deposits = (int) $commerce['deposits'];

            // Engagement generation only needs stable identifiers. Release the
            // large operational model graphs before loading its own bounded
            // projections so MEDIUM and LARGE remain worker-safe.
            unset($resources, $customers, $reservations, $commerce);
            gc_collect_cycles();

            $engagement = $this->engagementGenerator->generate(
                $context,
                $blueprint,
                $targets,
                $customerIds,
                $serviceIds,
                $productIds,
                $reservationIds,
            );
            $engagementInvariantReport = $engagement['invariant_report'];

            // MEDIUM and LARGE intentionally create thousands of records. Drop
            // generation graphs before the validator reloads a clean tenant
            // snapshot so queued provisioning stays within a modest worker
            // memory budget.
            unset($customerIds, $serviceIds, $productIds, $reservationIds, $engagement);
            gc_collect_cycles();

            $counts = $this->databaseCounts($context);
            $this->assertTargets($targets, $counts);
            $owner = $context->owner->fresh();
            if (! $owner) {
                throw new RuntimeException('Studio Naya owner disappeared before final validation.');
            }
            $invariants = $this->invariantValidator->validateOrFail(
                $owner,
                $context->referenceDate,
            );
            $moduleEvidence = $this->moduleEvidence->validateOrFail(
                $owner,
                array_values((array) $context->workspace->selected_modules),
            );
            $datasetFingerprint = $this->fingerprint->forOwner($owner);

            return [
                'scenario_key' => $this->key(),
                'scenario_version' => $this->version(),
                'data_volume' => $context->dataVolume->value,
                'reference_date' => $context->referenceDate->toDateString(),
                'random_seed' => $context->randomSeed,
                'timezone' => $context->timezone,
                ...$counts,
                'public_booking_links' => $publicBookingLinks,
                'refunds' => $refunds,
                'deposits' => $deposits,
                'engagement_invariant_report' => $engagementInvariantReport,
                'invariant_report' => $invariants['summary'],
                'module_evidence' => $moduleEvidence,
                'dataset_fingerprint' => $datasetFingerprint,
            ];
        });
    }

    /**
     * @return array<string, int>
     */
    private function targets(DemoDataVolume $volume): array
    {
        $targets = config('demo_scenarios.volumes.'.$volume->value);
        if (! is_array($targets)) {
            throw new RuntimeException('Missing demo scenario volume configuration for ['.$volume->value.'].');
        }

        // A queue worker can keep the previous Laravel configuration in memory
        // while loading this updated scenario class for the first time. Merge
        // version-one additions as fallbacks so that such a reset remains
        // deterministic until the worker is restarted.
        $targets = array_replace(
            StudioNayaBlueprint::immersiveTargetsForVolume($volume->value),
            $targets,
        );

        return collect($targets)
            ->map(fn (mixed $value): int => (int) $value)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function databaseCounts(DemoScenarioContext $context): array
    {
        $ownerId = (int) $context->owner->id;

        return [
            'team_members' => TeamMember::query()->forAccount($ownerId)->count(),
            'services' => Product::query()->byUser($ownerId)->services()->count(),
            'products' => Product::query()->byUser($ownerId)->products()->count(),
            'customers' => Customer::query()->byUser($ownerId)->count(),
            'reservations' => Reservation::query()->forAccount($ownerId)->count(),
            'invoices' => Invoice::query()->byUser($ownerId)->count(),
            'payments' => Payment::query()->where('user_id', $ownerId)->count(),
            'quotes' => Quote::query()->byUserWithArchived($ownerId)->count(),
            'sales' => Sale::query()->where('user_id', $ownerId)->count(),
            'expenses' => Expense::query()->byAccount($ownerId)->count(),
            'inventory_movements' => ProductStockMovement::query()
                ->whereHas('product', fn ($query) => $query->where('user_id', $ownerId))
                ->count(),
            'notifications' => $context->owner->notifications()->count(),
            'tasks' => Task::query()->forAccount($ownerId)->count(),
            'transactions' => Transaction::query()->where('user_id', $ownerId)->count(),
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
            'offer_packages' => OfferPackage::query()->forAccount($ownerId)->count(),
            'offer_packs' => OfferPackage::query()
                ->forAccount($ownerId)
                ->where('type', OfferPackage::TYPE_PACK)
                ->count(),
            'offer_forfaits' => OfferPackage::query()
                ->forAccount($ownerId)
                ->where('type', OfferPackage::TYPE_FORFAIT)
                ->count(),
            'offer_package_items' => OfferPackageItem::query()
                ->whereHas('offerPackage', fn ($query) => $query->where('user_id', $ownerId))
                ->count(),
            'pack_invoice_lines' => InvoiceItem::query()
                ->whereHas('invoice', fn ($query) => $query->where('user_id', $ownerId))
                ->get(['meta'])
                ->filter(fn (InvoiceItem $item): bool => data_get($item->meta, 'offer_package_type') === OfferPackage::TYPE_PACK)
                ->count(),
            'customer_packages' => CustomerPackage::query()->forAccount($ownerId)->count(),
            'customer_packages_active' => CustomerPackage::query()
                ->forAccount($ownerId)
                ->where('status', CustomerPackage::STATUS_ACTIVE)
                ->count(),
            'customer_packages_consumed' => CustomerPackage::query()
                ->forAccount($ownerId)
                ->where('status', CustomerPackage::STATUS_CONSUMED)
                ->count(),
            'customer_packages_expired' => CustomerPackage::query()
                ->forAccount($ownerId)
                ->where('status', CustomerPackage::STATUS_EXPIRED)
                ->count(),
            'customer_packages_recurring' => CustomerPackage::query()
                ->forAccount($ownerId)
                ->where('is_recurring', true)
                ->count(),
            'customer_package_usages' => CustomerPackageUsage::query()->forAccount($ownerId)->count(),
            'customer_package_usages_reversed' => CustomerPackageUsage::query()
                ->forAccount($ownerId)
                ->whereNotNull('reversed_at')
                ->count(),
            'customer_package_usages_linked_reservations' => CustomerPackageUsage::query()
                ->forAccount($ownerId)
                ->whereNotNull('reservation_id')
                ->count(),
            'package_behavior_events' => CustomerBehaviorEvent::query()
                ->byUser($ownerId)
                ->whereIn('event_type', [
                    'customer_package_purchased',
                    'customer_package_low_balance',
                    'customer_package_expired',
                ])
                ->count(),
            'loyalty_point_ledgers' => LoyaltyPointLedger::query()->where('user_id', $ownerId)->count(),
            'loyalty_story_events' => LoyaltyPointLedger::query()
                ->where('user_id', $ownerId)
                ->whereNull('payment_id')
                ->whereIn('event', [
                    LoyaltyPointLedger::EVENT_REDEMPTION,
                    LoyaltyPointLedger::EVENT_REDEMPTION_REVERSAL,
                ])
                ->count(),
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
        $mapping = [
            'employees' => 'team_members',
            'services' => 'services',
            'products' => 'products',
            'customers' => 'customers',
            'reservations' => 'reservations',
            'invoices' => 'invoices',
            'payments' => 'payments',
            'quotes' => 'quotes',
            'sales' => 'sales',
            'expenses' => 'expenses',
            'inventory_movements' => 'inventory_movements',
            'notifications' => 'notifications',
            'mailing_lists' => 'mailing_lists',
            'mailing_list_memberships' => 'mailing_list_memberships',
            'campaigns' => 'campaigns',
            'campaign_channels' => 'campaign_channels',
            'campaign_audiences' => 'campaign_audiences',
            'campaign_runs' => 'campaign_runs',
            'campaign_recipients' => 'campaign_recipients',
            'campaign_messages' => 'campaign_messages',
            'campaign_events' => 'campaign_events',
            'promotions' => 'promotions',
            'promotion_usages' => 'promotion_usages',
            'offer_packages' => 'offer_packages',
            'offer_package_items' => 'offer_package_items',
            'pack_invoice_lines' => 'pack_invoice_lines',
            'customer_packages' => 'customer_packages',
            'customer_package_usages' => 'customer_package_usages',
            'package_behavior_events' => 'package_behavior_events',
            'loyalty_story_events' => 'loyalty_story_events',
            'assistant_settings' => 'assistant_settings',
            'assistant_knowledge_items' => 'assistant_knowledge_items',
            'assistant_conversations' => 'assistant_conversations',
            'assistant_messages' => 'assistant_messages',
            'assistant_actions' => 'assistant_actions',
            'social_accounts' => 'social_accounts',
            'social_templates' => 'social_templates',
            'social_posts' => 'social_posts',
            'social_targets' => 'social_targets',
        ];

        foreach ($mapping as $targetKey => $countKey) {
            if ((int) ($targets[$targetKey] ?? -1) === (int) ($counts[$countKey] ?? -2)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Studio Naya target mismatch for %s: expected %d, generated %d.',
                $targetKey,
                (int) ($targets[$targetKey] ?? -1),
                (int) ($counts[$countKey] ?? -2),
            ));
        }
    }
}
