<?php

use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\SocialPublicationCompletedNotification;
use App\Services\Social\SocialDeliveryAggregateService;
use App\Services\Social\SocialPostRevisionService;
use App\Services\Social\SocialPublicationNotificationService;
use App\Support\Notifications\UserNotificationCenter;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/** @return array{owner:User,post:SocialPost,targets:Illuminate\Database\Eloquent\Collection} */
function publicationNotificationFixture(array $statuses): array
{
    $owner = User::factory()->create([
        'role_id' => Role::query()->firstOrCreate(['name' => 'owner'])->id,
        'locale' => 'fr',
    ]);
    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'status' => SocialPost::STATUS_DRAFT,
        'content_payload' => ['text' => 'Notre nouvelle vidéo'],
        'media_payload' => [],
        'metadata' => ['publish_requested_at' => '2026-09-05T10:00:00Z'],
    ]);
    foreach ($statuses as $index => $status) {
        $platform = ['facebook', 'instagram', 'linkedin'][$index % 3];
        $connection = SocialAccountConnection::query()->create([
            'user_id' => $owner->id,
            'platform' => $platform,
            'label' => 'Compte '.($index + 1),
            'status' => 'connected',
            'is_active' => true,
        ]);
        SocialPostTarget::query()->create([
            'social_post_id' => $post->id,
            'social_account_connection_id' => $connection->id,
            'status' => 'pending',
        ]);
    }
    $revision = app(SocialPostRevisionService::class)->approveDirectly($post, $owner, now());
    $targets = $post->targets()->orderBy('id')->get();
    foreach ($targets as $index => $target) {
        $target->forceFill([
            'last_submitted_revision_id' => $revision->id,
            'delivery_status' => $statuses[$index],
            'sync_status' => 'synced',
            'published_at' => $statuses[$index] === 'published' ? now() : null,
        ])->save();
    }

    return ['owner' => $owner, 'post' => $post->fresh(), 'targets' => $targets];
}

it('reports the final outcome once with a result for every account', function (array $statuses, string $outcome) {
    Notification::fake();
    $fixture = publicationNotificationFixture($statuses);
    $service = app(SocialDeliveryAggregateService::class);

    $service->refreshForTenant($fixture['owner']->id, $fixture['post']->id);
    $service->refreshForTenant($fixture['owner']->id, $fixture['post']->id);

    Notification::assertSentToTimes($fixture['owner'], SocialPublicationCompletedNotification::class, 1);
    Notification::assertSentTo($fixture['owner'], SocialPublicationCompletedNotification::class,
        function ($notification, $channels) use ($fixture, $outcome, $statuses) {
            $data = $notification->toArray($fixture['owner']);
            expect($channels)->toBe(['database']);
            expect($data['outcome'])->toBe($outcome);
            expect($data['publication_summary']['results'])->toHaveCount(count($statuses));
            expect($data['message'])->toContain('Notre nouvelle vidéo', 'Facebook (Compte 1)');
            expect($data['action_url'])->toBe(route('social.history'));

            return true;
        });
})->with([
    'all published' => [['published', 'published'], 'success'],
    'partially published' => [['published', 'failed'], 'partial'],
    'all failed' => [['failed', 'failed'], 'failed'],
    'cancellation is not full success' => [['published', 'canceled'], 'partial'],
    'all canceled' => [['canceled', 'canceled'], 'canceled'],
    'unconfirmed result' => [['published', 'unknown'], 'attention'],
]);

it('waits for actual completion while an account is still being processed', function (string $pendingStatus) {
    Notification::fake();
    $fixture = publicationNotificationFixture(['failed', $pendingStatus]);

    app(SocialDeliveryAggregateService::class)->refreshForTenant($fixture['owner']->id, $fixture['post']->id);

    Notification::assertNothingSent();
})->with(['not_submitted', 'queued', 'sending', 'submitted', 'scheduled', 'remote_approval_required']);

it('sanitizes errors and reports reconnection without claiming a confirmed failure', function () {
    Notification::fake();
    $fixture = publicationNotificationFixture(['published', 'scheduled']);
    $fixture['targets'][1]->forceFill([
        'sync_status' => 'reconnect_required',
        'provider_error_message' => 'Session expirée access_token=secret-value',
    ])->save();

    app(SocialDeliveryAggregateService::class)->refreshForTenant($fixture['owner']->id, $fixture['post']->id);

    Notification::assertSentTo($fixture['owner'], SocialPublicationCompletedNotification::class, function ($notification) use ($fixture) {
        $data = $notification->toArray($fixture['owner']);
        expect($data['outcome'])->toBe('attention');
        expect($data['message'])->toContain('Compte à reconnecter', 'Session expirée', '[redacted]')
            ->not->toContain('secret-value');
        expect($data['publication_summary']['counts']['failed'])->toBe(0);

        return true;
    });
});

it('announces recovery after an error without changing the original report', function () {
    Notification::fake();
    $fixture = publicationNotificationFixture(['published', 'failed']);
    $service = app(SocialDeliveryAggregateService::class);
    $service->refreshForTenant($fixture['owner']->id, $fixture['post']->id);
    $first = Notification::sent($fixture['owner'], SocialPublicationCompletedNotification::class)->first();
    $fixture['targets'][1]->forceFill(['delivery_status' => 'published', 'published_at' => now()])->save();

    $service->refreshForTenant($fixture['owner']->id, $fixture['post']->id);
    $service->refreshForTenant($fixture['owner']->id, $fixture['post']->id);

    Notification::assertSentToTimes($fixture['owner'], SocialPublicationCompletedNotification::class, 2);
    expect($first->snapshot['outcome'])->toBe('partial');
    expect(Notification::sent($fixture['owner'], SocialPublicationCompletedNotification::class)->last()->snapshot['outcome'])->toBe('success');
});

it('reports a new failed attempt even when its outcome is unchanged', function () {
    Notification::fake();
    $fixture = publicationNotificationFixture(['failed']);
    $service = app(SocialDeliveryAggregateService::class);
    $service->refreshForTenant($fixture['owner']->id, $fixture['post']->id);
    $post = $fixture['post']->fresh();
    $post->update(['metadata' => array_merge($post->metadata, ['retry_requested_at' => '2026-09-05T11:00:00Z'])]);

    $service->refreshForTenant($fixture['owner']->id, $fixture['post']->id);

    Notification::assertSentToTimes($fixture['owner'], SocialPublicationCompletedNotification::class, 2);
});

it('does not send a tenant report to an unrelated publication actor', function () {
    Notification::fake();
    $fixture = publicationNotificationFixture(['published']);
    $outsider = User::factory()->create();
    $fixture['post']->update(['metadata' => ['publish_requested_by_user_id' => $outsider->id]]);
    $service = app(SocialPublicationNotificationService::class);

    $service->notifyForTenant($outsider->id, $fixture['post']->id);
    $service->notifyForTenant($fixture['owner']->id, $fixture['post']->id);

    Notification::assertNotSentTo($outsider, SocialPublicationCompletedNotification::class);
    Notification::assertSentToTimes($fixture['owner'], SocialPublicationCompletedNotification::class, 1);
});

it('also informs an authorized active team member who requested publication', function () {
    Notification::fake();
    $fixture = publicationNotificationFixture(['published']);
    $member = User::factory()->create();
    TeamMember::query()->create([
        'account_id' => $fixture['owner']->id, 'user_id' => $member->id,
        'role' => 'member', 'permissions' => ['social.view'], 'is_active' => true,
    ]);
    $fixture['post']->update(['metadata' => ['publish_requested_by_user_id' => $member->id]]);

    app(SocialPublicationNotificationService::class)->notifyForTenant($fixture['owner']->id, $fixture['post']->id);

    Notification::assertSentTo([$fixture['owner'], $member], SocialPublicationCompletedNotification::class);
});

it('respects disabled in-app notifications', function () {
    Notification::fake();
    $fixture = publicationNotificationFixture(['published']);
    $fixture['owner']->update(['notification_settings' => ['channels' => ['in_app' => false]]]);

    app(SocialDeliveryAggregateService::class)->refreshForTenant($fixture['owner']->id, $fixture['post']->id);

    Notification::assertNothingSent();
});

it('stores the complete report in the notification center and ignores a repeated delivery', function () {
    $notificationManager = Notification::getFacadeRoot();
    Notification::fake();
    $fixture = publicationNotificationFixture(['published', 'failed']);
    app(SocialDeliveryAggregateService::class)->refreshForTenant($fixture['owner']->id, $fixture['post']->id);
    $notification = Notification::sent($fixture['owner'], SocialPublicationCompletedNotification::class)->first();
    Notification::assertSentToTimes($fixture['owner'], SocialPublicationCompletedNotification::class, 1);
    Notification::swap($notificationManager);
    Event::fake([NotificationSent::class]);
    $notification->id = (string) Str::uuid();

    Notification::sendNow($fixture['owner'], $notification);
    Notification::sendNow($fixture['owner'], $notification);

    expect($fixture['owner']->notifications()->count())->toBe(1);
    $stored = $fixture['owner']->notifications()->first();
    expect(app(UserNotificationCenter::class)->present($stored, $fixture['owner'])['message'])
        ->toContain('1/2 comptes publiés', "\nInstagram (Compte 2) : Échec");
    Event::assertDispatchedTimes(NotificationSent::class, 1);
});

it('does not retain a report or its marker when the surrounding transaction rolls back', function () {
    $fixture = publicationNotificationFixture(['published']);
    try {
        DB::transaction(function () use ($fixture) {
            app(SocialDeliveryAggregateService::class)->refreshForTenant($fixture['owner']->id, $fixture['post']->id);
            throw new RuntimeException('Rollback publication');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Rollback publication');
    }

    expect($fixture['owner']->notifications()->count())->toBe(0);
    expect(data_get($fixture['post']->fresh()->metadata, 'publication_notification'))->toBeNull();
});

it('renders the email recap with branding, platform errors and escaped content', function (string $locale, string $detailsLabel) {
    $renderedBrandViews = [];
    View::composer(['emails.layouts.base', 'emails.partials.structured-hero'], function (Illuminate\View\View $view) use (&$renderedBrandViews): void {
        $renderedBrandViews[] = $view->name();
    });
    Notification::fake();
    $fixture = publicationNotificationFixture(['published', 'failed']);
    $fixture['owner']->update(['locale' => $locale, 'company_name' => 'Atelier Horizon']);
    $fixture['targets'][1]->update(['provider_error_message' => 'Unsupported video format.']);
    app(SocialDeliveryAggregateService::class)->refreshForTenant($fixture['owner']->id, $fixture['post']->id);
    Notification::assertSentToTimes($fixture['owner'], SocialPublicationCompletedNotification::class, 1);
    $notification = Notification::sent($fixture['owner'], SocialPublicationCompletedNotification::class)->first();
    $notification->snapshot['excerpt'] = '<script>alert("test")</script>';

    $mail = $notification->toMail($fixture['owner']->fresh());
    $html = (string) $mail->render();
    $text = view($mail->view['text'], $mail->viewData)->render();

    expect($html)->toContain('Atelier Horizon', $detailsLabel, 'Facebook', 'Instagram', 'Unsupported video format.', '&lt;script&gt;')
        ->not->toContain('<script>');
    expect($text)->toContain('Facebook', 'Instagram', 'Unsupported video format.', route('social.history'));
    expect($renderedBrandViews)->toEqualCanonicalizing(['emails.layouts.base', 'emails.partials.structured-hero']);
})->with([
    'French' => ['fr', 'Résultat par plateforme'],
    'English' => ['en', 'Results by platform'],
    'Spanish' => ['es', 'Resultado por plataforma'],
]);
