<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Jobs\ProcessSocialDeliveryOutboxJob;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialDeliveryOutbox;
use App\Models\SocialPost;
use App\Models\SocialVideoClip;
use App\Models\SocialVideoProject;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function videoPublicationFixture(): array
{
    $owner = User::factory()->create(['role_id' => Role::query()->firstOrCreate(['name' => 'owner'])->id,
        'onboarding_completed_at' => now(), 'company_features' => ['social' => true], 'company_timezone' => 'America/Toronto']);
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id, 'platform' => 'facebook', 'label' => 'Video account', 'external_account_id' => 'video-test',
        'status' => 'connected', 'is_active' => true, 'credentials' => ['access_token' => 'test'],
        ...pulseDirectTransportIdentity($owner, 'facebook', 'video-test'),
    ]);
    $project = SocialVideoProject::factory()->for($owner)->create(['duration_ms' => 10000]);
    foreach ([1, 2] as $position) {
        $clip = SocialVideoClip::factory()->for($project, 'project')->create([
            'status' => 'ready', 'position' => $position, 'start_ms' => ($position - 1) * 5000, 'end_ms' => $position * 5000,
            'path' => dirname($project->source_path).'/clip-'.$position.'.mp4',
        ]);
        Storage::disk('local')->put($clip->path, pack('N', 24).'ftypisom'.str_repeat("\0", 4).'isommp42');
    }
    $clips = $project->clips()->get();
    $input = ['start_date' => '2027-03-13', 'time' => '09:00', 'interval_days' => 1,
        'connection_ids' => [$connection->id], 'clip_ids' => $clips->modelKeys(), 'mode' => 'drafts', 'request_id' => (string) Str::uuid(),
        'rows' => $clips->map(fn ($clip) => ['clip_id' => $clip->id, 'connection_id' => $connection->id, 'text' => 'Extrait '.$clip->position])->all()];

    return [$owner, $connection, $project, $input];
}

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2027-03-01 12:00:00', 'UTC'));
    config(['services.buffer.delivery.enabled' => false]);
    Http::preventStrayRequests();
});

it('previews daily local times across daylight saving changes without writing posts', function () {
    Storage::fake('local');
    [$owner, , $project, $input] = videoPublicationFixture();
    $this->actingAs($owner)->postJson(route('social.videos.publications.preview', $project), $input)
        ->assertOk()->assertJsonCount(2, 'rows')->assertJsonPath('rows.0.scheduled_for', '2027-03-13T14:00:00+00:00')
        ->assertJsonPath('rows.1.scheduled_for', '2027-03-14T13:00:00+00:00');
    expect(SocialPost::query()->count())->toBe(0);
});

it('creates calendar posts exactly once with independent video copies and preserves them after project deletion', function () {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);
    [$owner, , $project, $input] = videoPublicationFixture();
    $url = route('social.videos.publications.store', $project);
    $this->actingAs($owner)->postJson($url, $input)->assertCreated()->assertJsonCount(1, 'project.clips.0.publication_ids');
    $this->postJson($url, $input)->assertCreated();
    $posts = SocialPost::query()->orderBy('id')->get();
    expect($posts)->toHaveCount(2);
    expect($posts[0]->content_payload['text'])->toBe('Extrait 1');
    expect($posts[1]->scheduled_for->toIso8601String())->toBe('2027-03-14T13:00:00+00:00');
    expect($posts[0]->media_payload[0]['type'])->toBe('video');
    foreach ($posts as $post) {
        Storage::disk('public')->assertExists($post->media_payload[0]['path']);
        expect($post->metadata['social_video_project_id'])->toBe($project->id);
        expect($post->approved_revision_id)->toBeNull();
    }
    expect(Storage::disk('public')->allFiles())->toHaveCount(2);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    $this->postJson($url, [...$input, 'time' => '10:00'])->assertUnprocessable();
    $this->deleteJson(route('social.videos.destroy', $project))->assertNoContent();
    foreach ($posts as $post) {
        $this->assertModelExists($post);
        Storage::disk('public')->assertExists($post->media_payload[0]['path']);
    }
});

it('schedules a series through the existing outbox without sending during the request', function () {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);
    [$owner, , $project, $input] = videoPublicationFixture();
    $input['mode'] = 'schedule';
    $this->actingAs($owner)->postJson(route('social.videos.publications.store', $project), $input)->assertCreated();
    expect(SocialDeliveryOutbox::query()->count())->toBe(2);
    expect(SocialPost::query()->whereNotNull('approved_revision_id')->count())->toBe(2);
    Queue::assertPushed(ProcessSocialDeliveryOutboxJob::class, 2);
    Http::assertNothingSent();
});

it('refuses invalid dates or stale clip plans with no partial calendar', function (array $change, string $field) {
    Storage::fake('local');
    Storage::fake('public');
    [$owner, , $project, $input] = videoPublicationFixture();
    $this->actingAs($owner)->postJson(route('social.videos.publications.store', $project), [...$input, ...$change])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(SocialPost::query()->count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'past date' => [['start_date' => '2027-02-01'], 'start_date'],
    'nonexistent DST time' => [['start_date' => '2027-03-14', 'time' => '02:30'], 'scheduled_for'],
    'ambiguous DST time' => [['start_date' => '2027-11-07', 'time' => '01:30'], 'scheduled_for'],
    'stale clips' => [['clip_ids' => [999999]], 'video'],
    'missing copy' => [['rows' => []], 'rows'],
]);

it('enforces publication permission and rejects destinations from another tenant', function () {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake([ProcessSocialDeliveryOutboxJob::class]);
    [$owner, $connection, $project, $input] = videoPublicationFixture();
    $member = User::factory()->create(['onboarding_completed_at' => now()]);
    TeamMember::query()->create(['account_id' => $owner->id, 'user_id' => $member->id, 'role' => 'member', 'is_active' => true, 'permissions' => ['social.manage', 'social.publish']]);
    $this->actingAs($member)->postJson(route('social.videos.publications.store', $project), [...$input, 'mode' => 'schedule'])->assertForbidden();
    $other = User::factory()->create(['role_id' => $owner->role_id, 'onboarding_completed_at' => now(), 'company_features' => ['social' => true]]);
    $this->actingAs($other)->postJson(route('social.videos.publications.store', $project), $input)->assertNotFound();
    $foreign = SocialAccountConnection::query()->create(['user_id' => $other->id, 'platform' => 'facebook', 'label' => 'Other',
        'external_account_id' => 'other-video', 'status' => 'connected', 'is_active' => true,
        ...pulseDirectTransportIdentity($other, 'facebook', 'other-video')]);
    $this->actingAs($owner)->postJson(route('social.videos.publications.preview', $project), [...$input, 'connection_ids' => [$foreign->id]])->assertUnprocessable();
    expect(SocialPost::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rolls back all posts and public copies if a later clip is missing', function () {
    Storage::fake('local');
    Storage::fake('public');
    config(['queue.default' => 'database']);
    [$owner, , $project, $input] = videoPublicationFixture();
    Storage::disk('local')->delete($project->clips()->get()->last()->path);
    $this->actingAs($owner)->postJson(route('social.videos.publications.store', $project), [...$input, 'mode' => 'schedule'])->assertUnprocessable();
    expect(SocialPost::query()->count())->toBe(0);
    expect(SocialDeliveryOutbox::query()->count())->toBe(0);
    expect($project->clips()->whereNotNull('publication_ids')->count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBe([]);
    $this->assertDatabaseCount('jobs', 0);
});
