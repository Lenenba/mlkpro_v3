<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Jobs\PrepareSocialVideoJob;
use App\Jobs\RenderSocialVideoClipJob;
use App\Models\Role;
use App\Models\SocialVideoClip;
use App\Models\SocialVideoProject;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function socialVideoOwner(): User
{
    return User::factory()->create([
        'role_id' => Role::query()->firstOrCreate(['name' => 'owner'], ['description' => 'Owner'])->id,
        'onboarding_completed_at' => now(), 'company_features' => ['social' => true],
    ]);
}

function socialVideoSettings(array $overrides = []): array
{
    return [...[
        'mode' => 'duration', 'segment_seconds' => 60, 'format' => 'portrait',
        'framing' => 'crop', 'focal_x' => 25, 'focal_y' => 50,
    ], ...$overrides];
}

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('stores a private original and queues preparation before returning the project', function () {
    Storage::fake('local');
    Queue::fake([PrepareSocialVideoJob::class]);
    $owner = socialVideoOwner();

    $response = $this->actingAs($owner)->postJson(route('social.videos.store'), [
        'video_file' => UploadedFile::fake()->create('Présentation.mp4', 100, 'video/mp4'),
    ])->assertCreated()->assertJsonPath('project.status', 'pending')
        ->assertJsonPath('project.preview_url', null)->assertJsonMissingPath('project.source_path');

    $project = SocialVideoProject::query()->findOrFail($response->json('project.id'));
    expect($project->user_id)->toBe($owner->id);
    Storage::disk('local')->assertExists($project->source_path);
    Queue::assertPushed(PrepareSocialVideoJob::class, fn ($job) => $job->projectId === $project->id && $job->queue === 'social-video');
    $this->actingAs($owner)->getJson(route('social.videos.index'))
        ->assertOk()->assertJsonCount(1, 'projects')->assertJsonPath('access.can_manage_posts', true);
});

it('assembles a large upload in ordered idempotent chunks and queues it only once', function () {
    Storage::fake('local');
    Queue::fake([PrepareSocialVideoJob::class]);
    $owner = socialVideoOwner();
    $started = $this->actingAs($owner)->postJson(route('social.videos.uploads.store'), [
        'name' => 'video.mp4', 'size' => 2500000,
    ])->assertCreated()->assertJsonPath('project.status', 'uploading');
    $project = SocialVideoProject::query()->findOrFail($started->json('project.id'));
    $chunkUrl = route('social.videos.uploads.append', $project);
    $chunk = fn (string $content) => UploadedFile::fake()->createWithContent('chunk.bin', $content);

    $this->postJson($chunkUrl, ['offset' => 1000000, 'chunk' => $chunk('wrong-order')])->assertUnprocessable();
    $this->postJson($chunkUrl, ['offset' => 0, 'chunk' => $chunk(str_repeat('a', 1000000))])->assertOk();
    $this->postJson($chunkUrl, ['offset' => 0, 'chunk' => $chunk(str_repeat('a', 1000000))])->assertOk();
    $this->postJson($chunkUrl, ['offset' => 0, 'chunk' => $chunk('different')])->assertUnprocessable();
    $this->postJson($chunkUrl, ['offset' => 1000000, 'chunk' => $chunk(str_repeat('b', 1000000))])->assertOk();
    $this->postJson($chunkUrl, ['offset' => 2000000, 'chunk' => $chunk(str_repeat('c', 500001))])->assertUnprocessable();
    $this->postJson($chunkUrl, ['offset' => 2000000, 'chunk' => $chunk(str_repeat('c', 500000))])
        ->assertOk()->assertJsonPath('project.status', 'pending');
    $this->postJson($chunkUrl, ['offset' => 2000000, 'chunk' => $chunk(str_repeat('c', 500000))])->assertOk();

    expect(Storage::disk('local')->get($project->source_path))->toBe(str_repeat('a', 1000000).str_repeat('b', 1000000).str_repeat('c', 500000));
    Queue::assertPushed(PrepareSocialVideoJob::class, 1);
    $this->actingAs(socialVideoOwner())->postJson($chunkUrl, ['offset' => 0, 'chunk' => $chunk('data')])->assertNotFound();
});

it('recovers the final chunk after the file was persisted but the transaction was interrupted', function () {
    Storage::fake('local');
    Queue::fake([PrepareSocialVideoJob::class]);
    $owner = socialVideoOwner();
    $project = SocialVideoProject::factory()->for($owner)->create(['status' => 'uploading', 'size' => 5]);
    Storage::disk('local')->put($project->source_path, 'video');
    $this->actingAs($owner)->postJson(route('social.videos.uploads.append', $project), [
        'offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'video'),
    ])->assertOk()->assertJsonPath('project.status', 'pending');
    Queue::assertPushed(PrepareSocialVideoJob::class, 1);
});

it('rejects unsupported or oversized uploads without writing a project', function (string $name, string $mime, int $size) {
    Queue::fake();
    $this->actingAs(socialVideoOwner())->postJson(route('social.videos.store'), [
        'video_file' => UploadedFile::fake()->create($name, $size, $mime),
    ])->assertUnprocessable()->assertJsonValidationErrors('video_file');
    $this->assertDatabaseCount('social_video_projects', 0);
    Queue::assertNothingPushed();
})->with([
    'image' => ['image.jpg', 'image/jpeg', 10],
    'disguised text' => ['movie.mp4', 'text/plain', 10],
    'too large' => ['movie.mp4', 'video/mp4', 262145],
]);

it('splits the entire video by duration and preserves the final short clip', function () {
    Queue::fake([RenderSocialVideoClipJob::class]);
    $owner = socialVideoOwner();
    $project = SocialVideoProject::factory()->for($owner)->create();

    $this->actingAs($owner)->postJson(route('social.videos.render', $project), socialVideoSettings())
        ->assertAccepted()->assertJsonCount(3, 'project.clips')
        ->assertJsonPath('project.clips.2.start_ms', 120000)
        ->assertJsonPath('project.clips.2.end_ms', 125000);

    expect($project->clips()->get()->map->only(['start_ms', 'end_ms'])->all())->toBe([
        ['start_ms' => 0, 'end_ms' => 60000], ['start_ms' => 60000, 'end_ms' => 120000],
        ['start_ms' => 120000, 'end_ms' => 125000],
    ]);
    Queue::assertPushed(RenderSocialVideoClipJob::class, 3);
    $this->actingAs($owner)->postJson(route('social.videos.render', $project), socialVideoSettings())
        ->assertUnprocessable()->assertJsonValidationErrors('video');
    Queue::assertPushed(RenderSocialVideoClipJob::class, 3);
});

it('saves manual cuts and reframes a new render while keeping the original', function () {
    Queue::fake([RenderSocialVideoClipJob::class]);
    Storage::fake('local');
    $owner = socialVideoOwner();
    $project = SocialVideoProject::factory()->for($owner)->create();
    Storage::disk('local')->put($project->source_path, 'original');
    $old = SocialVideoClip::factory()->for($project, 'project')->create(['status' => 'ready', 'path' => 'old.mp4']);
    Storage::disk('local')->put('old.mp4', 'old');

    $this->actingAs($owner)->postJson(route('social.videos.render', $project), socialVideoSettings([
        'mode' => 'manual', 'format' => 'landscape', 'framing' => 'blur',
        'segments' => [['start_ms' => 1500, 'end_ms' => 12000], ['start_ms' => 30000, 'end_ms' => 42000]],
    ]))->assertAccepted()->assertJsonPath('project.clips.0.start_ms', 1500)
        ->assertJsonPath('project.clips.0.format', 'landscape')->assertJsonPath('project.clips.0.framing', 'blur');

    $this->assertModelMissing($old);
    Storage::disk('local')->assertExists($project->source_path);
    Storage::disk('local')->assertMissing('old.mp4');
    Queue::assertPushed(RenderSocialVideoClipJob::class, 2);
});

it('rejects invalid plans without deleting previous renders', function (array $changes) {
    Queue::fake();
    $owner = socialVideoOwner();
    $project = SocialVideoProject::factory()->for($owner)->create();
    $clip = SocialVideoClip::factory()->for($project, 'project')->create(['status' => 'ready']);
    $this->actingAs($owner)->postJson(route('social.videos.render', $project), socialVideoSettings($changes))
        ->assertUnprocessable();
    $this->assertModelExists($clip);
    Queue::assertNothingPushed();
})->with([
    'zero duration' => [['segment_seconds' => 0]],
    'too many clips' => [['segment_seconds' => 1]],
    'invalid format' => [['format' => 'square']],
    'invalid focal point' => [['focal_x' => 101]],
    'empty cuts' => [['mode' => 'manual', 'segments' => []]],
    'negative start' => [['mode' => 'manual', 'segments' => [['start_ms' => -1, 'end_ms' => 5000]]]],
    'reversed cut' => [['mode' => 'manual', 'segments' => [['start_ms' => 9000, 'end_ms' => 5000]]]],
    'past the end' => [['mode' => 'manual', 'segments' => [['start_ms' => 0, 'end_ms' => 125001]]]],
    'overlap' => [['mode' => 'manual', 'segments' => [['start_ms' => 0, 'end_ms' => 9000], ['start_ms' => 8000, 'end_ms' => 12000]]]],
]);

it('protects project details and videos across accounts and against unauthorized team writes', function () {
    Storage::fake('local');
    Queue::fake();
    $owner = socialVideoOwner();
    $project = SocialVideoProject::factory()->for($owner)->create(['preview_path' => 'preview.mp4']);
    $clip = SocialVideoClip::factory()->for($project, 'project')->create(['status' => 'ready', 'path' => 'clip.mp4']);
    Storage::disk('local')->put('preview.mp4', 'video');
    Storage::disk('local')->put('clip.mp4', 'clip');
    $this->getJson(route('social.videos.show', $project))->assertUnauthorized();
    $other = socialVideoOwner();
    foreach (['social.videos.show', 'social.videos.preview'] as $route) {
        $this->actingAs($other)->getJson(route($route, $project))->assertNotFound();
    }
    $this->actingAs($other)->getJson(route('social.videos.clips.preview', [$project, $clip]))->assertNotFound();
    $this->actingAs($other)->postJson(route('social.videos.render', $project), socialVideoSettings())->assertNotFound();
    $this->actingAs($other)->deleteJson(route('social.videos.destroy', $project))->assertNotFound();

    $member = User::factory()->create(['onboarding_completed_at' => now()]);
    $membership = TeamMember::query()->create([
        'account_id' => $owner->id, 'user_id' => $member->id, 'role' => 'member',
        'permissions' => ['social.view'], 'is_active' => true,
    ]);
    $this->actingAs($member)->getJson(route('social.videos.show', $project))->assertOk();
    $this->actingAs($member)->postJson(route('social.videos.render', $project), socialVideoSettings())->assertForbidden();
    $this->actingAs($member)->postJson(route('social.videos.store'))->assertForbidden();
    $membership->update(['permissions' => ['social.manage']]);
    $member->unsetRelation('teamMembership');
    $this->actingAs($member)->postJson(route('social.videos.render', $project), socialVideoSettings())->assertAccepted();
    Queue::assertPushed(RenderSocialVideoClipJob::class, 3);
});

it('serves private seekable previews and rejects a clip belonging to another project', function () {
    Storage::fake('local');
    $owner = socialVideoOwner();
    $project = SocialVideoProject::factory()->for($owner)->create(['preview_path' => 'preview.mp4']);
    Storage::disk('local')->put('preview.mp4', '0123456789');
    $this->actingAs($owner)->get(route('social.videos.preview', $project), ['Range' => 'bytes=2-5'])
        ->assertStatus(206)->assertHeader('Content-Range', 'bytes 2-5/10')
        ->assertHeader('Content-Type', 'video/mp4')->assertHeader('Cache-Control', 'no-store, private');
    $otherClip = SocialVideoClip::factory()->create(['status' => 'ready', 'path' => 'preview.mp4']);
    $this->actingAs($owner)->getJson(route('social.videos.clips.preview', [$project, $otherClip]))->assertNotFound();
});

it('retries a failed original and prevents duplicate preparation', function () {
    Queue::fake([PrepareSocialVideoJob::class]);
    $owner = socialVideoOwner();
    $project = SocialVideoProject::factory()->for($owner)->create(['status' => 'failed', 'error_code' => 'processing_failed']);
    $this->actingAs($owner)->postJson(route('social.videos.retry', $project))->assertAccepted()
        ->assertJsonPath('project.status', 'pending')->assertJsonPath('project.error_code', null);
    $this->actingAs($owner)->postJson(route('social.videos.retry', $project))->assertUnprocessable();
    Queue::assertPushed(PrepareSocialVideoJob::class, 1);
});

it('removes an idle project and its private files but refuses deletion during rendering', function () {
    Storage::fake('local');
    $owner = socialVideoOwner();
    $project = SocialVideoProject::factory()->for($owner)->create();
    $clip = SocialVideoClip::factory()->for($project, 'project')->create();
    Storage::disk('local')->put($project->source_path, 'original');
    $this->actingAs($owner)->deleteJson(route('social.videos.destroy', $project))->assertUnprocessable();
    $clip->update(['status' => 'failed']);
    $this->actingAs($owner)->deleteJson(route('social.videos.destroy', $project))->assertNoContent();
    Storage::disk('local')->assertMissing($project->source_path);
    $this->assertModelMissing($project);
    $this->assertModelMissing($clip);
});

it('saves captions and moving crop points when generating clips', function () {
    Storage::fake('local');
    Queue::fake([RenderSocialVideoClipJob::class]);
    $owner = socialVideoOwner();
    $project = SocialVideoProject::factory()->for($owner)->create(['duration_ms' => 10000]);
    $captions = [['start_ms' => 500, 'end_ms' => 2500, 'text' => 'Été à Montréal']];
    $points = [['time_ms' => 0, 'x' => 0, 'y' => 50], ['time_ms' => 10000, 'x' => 100, 'y' => 50]];

    $this->actingAs($owner)->postJson(route('social.videos.render', $project), socialVideoSettings([
        'captions' => $captions, 'captions_enabled' => true, 'caption_style' => 'yellow',
        'caption_position' => 'top', 'crop_points' => $points,
    ]))->assertAccepted()->assertJsonPath('project.settings.captions', $captions)
        ->assertJsonPath('project.settings.crop_points', $points);

    expect($project->refresh()->settings['captions'])->toBe($captions);
    expect($project->settings['caption_style'])->toBe('yellow');
    Queue::assertPushed(RenderSocialVideoClipJob::class, 1);
});

it('rejects invalid editing data with 422 and preserves existing renders', function (array $editing, string $field) {
    Queue::fake([RenderSocialVideoClipJob::class]);
    $owner = socialVideoOwner();
    $project = SocialVideoProject::factory()->for($owner)->create(['duration_ms' => 10000]);
    $clip = SocialVideoClip::factory()->for($project, 'project')->create(['status' => 'ready']);

    $this->actingAs($owner)->postJson(route('social.videos.render', $project), socialVideoSettings($editing))
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertModelExists($clip);
    Queue::assertNothingPushed();
})->with([
    'overlapping captions' => [['captions' => [['start_ms' => 0, 'end_ms' => 5000, 'text' => 'Hello'], ['start_ms' => 4000, 'end_ms' => 6000, 'text' => 'World']]], 'captions'],
    'caption outside video' => [['captions' => [['start_ms' => 0, 'end_ms' => 10001, 'text' => 'Hello']]], 'captions'],
    'empty caption' => [['captions' => [['start_ms' => 0, 'end_ms' => 1000, 'text' => ' ']]], 'captions.0.text'],
    'control character' => [['captions' => [['start_ms' => 0, 'end_ms' => 1000, 'text' => "Hello\0World"]]], 'captions.0.text'],
    'unknown style' => [['caption_style' => "yellow';movie=https://example.com"], 'caption_style'],
    'duplicated crop time' => [['crop_points' => [['time_ms' => 0, 'x' => 0, 'y' => 0], ['time_ms' => 0, 'x' => 50, 'y' => 0]]], 'crop_points'],
    'crop outside video' => [['crop_points' => [['time_ms' => 10001, 'x' => 50, 'y' => 0]]], 'crop_points'],
    'crop outside frame' => [['crop_points' => [['time_ms' => 0, 'x' => 101, 'y' => 0]]], 'crop_points.0.x'],
]);

it('rejects unavailable caption rendering before replacing clips', function () {
    config(['social_video.caption_font' => '/missing/font.ttf']);
    Queue::fake([RenderSocialVideoClipJob::class]);
    $owner = socialVideoOwner();
    $project = SocialVideoProject::factory()->for($owner)->create();
    $clip = SocialVideoClip::factory()->for($project, 'project')->create(['status' => 'ready']);

    $this->actingAs($owner)->postJson(route('social.videos.render', $project), socialVideoSettings([
        'captions_enabled' => true, 'captions' => [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello']],
    ]))->assertUnprocessable()->assertJsonPath('errors.captions.0', __('social_video.captions_unavailable'));

    $this->assertModelExists($clip);
    Queue::assertNothingPushed();
});
