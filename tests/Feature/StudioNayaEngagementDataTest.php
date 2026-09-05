<?php

use App\Enums\PromotionStatus;
use App\Jobs\DispatchCampaignRunJob;
use App\Jobs\ProcessSocialDeliveryOutboxJob;
use App\Models\AssistantCreditTransaction;
use App\Models\AssistantUsage;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CustomerConsent;
use App\Models\DemoWorkspace;
use App\Models\MailingList;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Modules\AiAssistant\Jobs\ExecuteAiActionJob;
use App\Modules\AiAssistant\Jobs\ProcessAiMessageJob;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Services\Demo\DemoWorkspaceCatalog;
use App\Services\Demo\DemoWorkspaceProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

/**
 * @return array<string, mixed>
 */
function studioNayaEngagementPayload(): array
{
    $catalog = app(DemoWorkspaceCatalog::class);
    $preset = collect($catalog->presets())->firstWhere('key', 'studio_naya_coiffure');

    return array_replace($catalog->defaults(), [
        'prospect_name' => $preset['prospect_name'],
        'prospect_email' => null,
        'prospect_company' => $preset['prospect_company'],
        'company_name' => $preset['company_name'],
        'company_type' => $preset['company_type'],
        'company_sector' => $preset['company_sector'],
        'seed_profile' => $preset['seed_profile'],
        'scenario_key' => $preset['scenario_key'],
        'data_volume' => 'small',
        'reference_date' => '2026-08-20',
        'random_seed' => 12345,
        'scenario_version' => 1,
        'team_size' => $preset['team_size'],
        'locale' => $preset['locale'],
        'timezone' => $preset['timezone'],
        'desired_outcome' => $preset['desired_outcome'],
        'selected_modules' => $preset['modules'],
        'scenario_packs' => $preset['scenario_packs'],
        'branding_profile' => $preset['branding_profile'],
        'extra_access_roles' => $preset['extra_access_roles'],
        'suggested_flow' => $preset['suggested_flow'],
        'expires_at' => '2026-09-03',
    ]);
}

function provisionStudioNayaEngagementWorkspace(): DemoWorkspace
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'superadmin'],
        ['description' => 'Superadmin role'],
    );
    $admin = User::query()->create([
        'name' => 'Engagement Scenario Admin',
        'email' => 'engagement-scenario-admin@example.test',
        'password' => 'password',
        'role_id' => $role->id,
        'onboarding_completed_at' => now(),
    ]);

    return app(DemoWorkspaceProvisioner::class)->create(
        studioNayaEngagementPayload(),
        $admin,
    );
}

it('creates coherent DB-only Studio Naya engagement histories', function () {
    Bus::fake([
        DispatchCampaignRunJob::class,
        ProcessSocialDeliveryOutboxJob::class,
        ProcessAiMessageJob::class,
        ExecuteAiActionJob::class,
    ]);

    $workspace = provisionStudioNayaEngagementWorkspace();
    $ownerId = (int) $workspace->owner_user_id;
    $reference = CarbonImmutable::parse('2026-08-20', (string) $workspace->timezone);
    $summary = $workspace->seed_summary;
    $campaigns = Campaign::query()
        ->byUser($ownerId)
        ->with(['audience', 'channels', 'runs.recipients.message', 'runs.recipients.events'])
        ->get();
    $completedCampaign = $campaigns->firstWhere('status', Campaign::STATUS_COMPLETED);
    $run = $completedCampaign?->runs->first();
    $promotions = Promotion::query()->forAccount($ownerId)->with('usages.sale')->get();
    $conversations = AiConversation::query()
        ->forTenant($ownerId)
        ->with(['client', 'reservation', 'messages', 'actions'])
        ->get();
    $socialAccount = SocialAccountConnection::query()->byUser($ownerId)->firstOrFail();
    $socialPosts = SocialPost::query()
        ->byUser($ownerId)
        ->with('targets.socialAccountConnection')
        ->get();

    expect(data_get($summary, 'mailing_lists'))->toBe(2)
        ->and(data_get($summary, 'mailing_list_memberships'))->toBe(24)
        ->and(data_get($summary, 'campaigns'))->toBe(3)
        ->and(data_get($summary, 'campaign_runs'))->toBe(1)
        ->and(data_get($summary, 'campaign_recipients'))->toBe(12)
        ->and(data_get($summary, 'campaign_messages'))->toBe(12)
        ->and(data_get($summary, 'campaign_events'))->toBe(54)
        ->and(data_get($summary, 'promotions'))->toBe(2)
        ->and(data_get($summary, 'promotion_usages'))->toBe(6)
        ->and(data_get($summary, 'assistant_settings'))->toBe(1)
        ->and(data_get($summary, 'assistant_knowledge_items'))->toBe(4)
        ->and(data_get($summary, 'assistant_conversations'))->toBe(6)
        ->and(data_get($summary, 'assistant_messages'))->toBe(18)
        ->and(data_get($summary, 'assistant_actions'))->toBe(3)
        ->and(data_get($summary, 'social_accounts'))->toBe(1)
        ->and(data_get($summary, 'social_templates'))->toBe(3)
        ->and(data_get($summary, 'social_posts'))->toBe(6)
        ->and(data_get($summary, 'social_targets'))->toBe(6)
        ->and(data_get($summary, 'engagement_invariant_report.violation_count'))->toBe(0)
        ->and(MailingList::query()->forAccount($ownerId)->count())->toBe(2)
        ->and($campaigns->pluck('status')->sort()->values()->all())->toBe([
            Campaign::STATUS_COMPLETED,
            Campaign::STATUS_DRAFT,
            Campaign::STATUS_SCHEDULED,
        ])
        ->and($campaigns->every(fn (Campaign $campaign): bool => $campaign->audience !== null && $campaign->channels->count() === 1
        ))->toBeTrue()
        ->and($run)->toBeInstanceOf(CampaignRun::class)
        ->and($run?->status)->toBe(CampaignRun::STATUS_COMPLETED)
        ->and(data_get($run?->summary, 'recipient_count'))->toBe(12)
        ->and($run?->recipients)->toHaveCount(12)
        ->and($run?->recipients->every(fn (CampaignRecipient $recipient): bool => $recipient->message !== null
            && $recipient->events->count() >= 3
            && data_get($recipient->metadata, 'external_delivery') === false
        ))->toBeTrue()
        ->and(CustomerConsent::query()
            ->where('user_id', $ownerId)
            ->whereIn('customer_id', $run?->recipients->pluck('customer_id') ?? [])
            ->where('channel', 'email')
            ->where('status', CustomerConsent::STATUS_GRANTED)
            ->count())->toBe(12)
        ->and($promotions->pluck('status')->map(
            fn (PromotionStatus $status): string => $status->value,
        )->sort()->values()->all())->toBe([
            PromotionStatus::ACTIVE->value,
            PromotionStatus::INACTIVE->value,
        ])
        ->and($promotions->flatMap->usages)->toHaveCount(6)
        ->and($promotions->flatMap->usages->every(function (PromotionUsage $usage) use ($ownerId): bool {
            return (int) $usage->user_id === $ownerId
                && (int) $usage->sale?->user_id === $ownerId
                && $usage->discount_total > 0
                && $usage->used_at?->betweenIncluded(
                    $usage->promotion->start_date->startOfDay(),
                    $usage->promotion->end_date->endOfDay(),
                );
        }))->toBeTrue();

    $assistantSetting = AiAssistantSetting::query()->forTenant($ownerId)->firstOrFail();
    expect($assistantSetting->enabled)->toBeTrue()
        ->and($assistantSetting->require_human_validation)->toBeTrue()
        ->and($conversations)->toHaveCount(6)
        ->and($conversations->every(fn (AiConversation $conversation): bool => (int) $conversation->client?->user_id === $ownerId
            && $conversation->messages->count() === 3
            && $conversation->messages->every(fn ($message): bool => data_get($message->payload, 'model_call') === false
            )
            && $conversation->actions->every(fn (AiAction $action): bool => $action->status !== AiAction::STATUS_PENDING
                && data_get($action->output_payload, 'external_side_effects') === false
            )
        ))->toBeTrue()
        ->and(AssistantUsage::query()->where('user_id', $ownerId)->count())->toBe(0)
        ->and(AssistantCreditTransaction::query()->where('user_id', $ownerId)->count())->toBe(0);

    expect($socialAccount->status)->toBe(SocialAccountConnection::STATUS_DISCONNECTED)
        ->and($socialAccount->is_active)->toBeFalse()
        ->and($socialAccount->credentials)->toBeNull()
        ->and(data_get($socialAccount->metadata, 'publishable'))->toBeFalse()
        ->and($socialPosts->pluck('status')->unique()->sort()->values()->all())->toBe([
            SocialPost::STATUS_DRAFT,
            SocialPost::STATUS_PUBLISHED,
            SocialPost::STATUS_SCHEDULED,
        ])
        ->and($socialPosts->every(fn (SocialPost $post): bool => $post->targets->count() === 1
            && $post->targets->every(fn (SocialPostTarget $target): bool => (int) $target->socialAccountConnection?->user_id === $ownerId
                && data_get($target->metadata, 'external_delivery') === false
            )
        ))->toBeTrue()
        ->and($socialPosts->where('status', SocialPost::STATUS_SCHEDULED)->every(
            fn (SocialPost $post): bool => $post->scheduled_for?->greaterThan($reference) ?? false,
        ))->toBeTrue();

    Bus::assertNotDispatched(DispatchCampaignRunJob::class);
    Bus::assertNotDispatched(ProcessSocialDeliveryOutboxJob::class);
    Bus::assertNotDispatched(ProcessAiMessageJob::class);
    Bus::assertNotDispatched(ExecuteAiActionJob::class);
});
