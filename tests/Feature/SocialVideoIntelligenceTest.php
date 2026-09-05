<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Jobs\ProcessSocialVideoIntelligenceJob;
use App\Models\Role;
use App\Models\SocialVideoProject;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Social\SocialVideoIntelligenceService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function videoIntelligenceOwner(): User
{
    return User::factory()->create(['role_id' => Role::query()->firstOrCreate(['name' => 'owner'])->id,
        'onboarding_completed_at' => now(), 'company_features' => ['social' => true], 'locale' => 'fr']);
}

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
    config(['services.openai.key' => 'test-key']);
    Http::preventStrayRequests();
});

it('queues transcription with an exclusive run and blocks concurrent edits', function () {
    Queue::fake([ProcessSocialVideoIntelligenceJob::class]);
    $owner = videoIntelligenceOwner();
    $project = SocialVideoProject::factory()->for($owner)->create();
    $this->actingAs($owner)->postJson(route('social.videos.intelligence', $project), ['task' => 'transcribe'])
        ->assertAccepted()->assertJsonPath('project.intelligence_status', 'pending');
    $this->postJson(route('social.videos.intelligence', $project), ['task' => 'transcribe'])->assertUnprocessable();
    $this->deleteJson(route('social.videos.destroy', $project))->assertUnprocessable();
    Queue::assertPushed(ProcessSocialVideoIntelligenceJob::class, fn ($job) => $job->runId === $project->refresh()->intelligence_run_id && $job->task === 'transcribe');
    Queue::assertPushed(ProcessSocialVideoIntelligenceJob::class, 1);
    Http::assertNothingSent();
});

it('transcribes privately and persists timed captions without repeating a completed job', function () {
    Storage::fake('local');
    $project = SocialVideoProject::factory()->for(videoIntelligenceOwner())->create([
        'duration_ms' => 5000, 'intelligence_status' => 'pending', 'intelligence_run_id' => (string) Str::uuid(),
    ]);
    Storage::disk('local')->makeDirectory(dirname($project->source_path));
    Process::fake(function ($process) {
        file_put_contents(end($process->command), 'test audio');

        return Process::result();
    });
    $validMultipart = false;
    Http::fake(['https://api.openai.com/v1/audio/transcriptions' => function ($request) use (&$validMultipart) {
        $validMultipart = str_contains($request->body(), 'whisper-1') && str_contains($request->body(), 'timestamp_granularities[]');

        return Http::response(['words' => [
            ['start' => 0.5, 'end' => 1, 'word' => 'Été'], ['start' => 1, 'end' => 2, 'word' => 'ensoleillé.'],
            ['start' => 3, 'end' => 4.5, 'word' => 'Bonjour.'],
        ]]);
    }]);
    $job = new ProcessSocialVideoIntelligenceJob($project->id, 'transcribe', $project->intelligence_run_id);
    $job->handle(app(SocialVideoIntelligenceService::class));
    $job->handle(app(SocialVideoIntelligenceService::class));

    expect($project->refresh()->intelligence_status)->toBe('ready');
    expect($project->intelligence['captions'])->toBe([
        ['start_ms' => 500, 'end_ms' => 2000, 'text' => 'Été ensoleillé.'], ['start_ms' => 3000, 'end_ms' => 4500, 'text' => 'Bonjour.'],
    ]);
    Http::assertSentCount(1);
    expect($validMultipart)->toBeTrue();
    expect(Storage::disk('local')->files(dirname($project->source_path)))->toBe([]);
    Process::assertRanTimes(fn ($process) => in_array('0:a:0', $process->command, true), 1);
});

it('ignores stale intelligence jobs and retains prior results on failure', function () {
    $project = SocialVideoProject::factory()->create(['intelligence_status' => 'pending',
        'intelligence_run_id' => (string) Str::uuid(), 'intelligence' => ['captions' => [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Kept']]]]);
    $stale = new ProcessSocialVideoIntelligenceJob($project->id, 'transcribe', (string) Str::uuid());
    $stale->handle(app(SocialVideoIntelligenceService::class));
    $stale->failed(new RuntimeException('timeout'));
    expect($project->refresh()->intelligence_status)->toBe('pending');
    $job = new ProcessSocialVideoIntelligenceJob($project->id, 'transcribe', $project->intelligence_run_id);
    $job->failed(new RuntimeException('private api error'));
    expect($project->refresh()->intelligence_status)->toBe('failed');
    expect($project->intelligence_error_code)->toBe('intelligence_failed');
    expect($project->intelligence['captions'][0]['text'])->toBe('Kept');
    Http::assertNothingSent();
});

it('proposes excerpts using actual transcript bounds and rejects invalid AI output', function () {
    $project = SocialVideoProject::factory()->for(videoIntelligenceOwner())->create(['duration_ms' => 10000, 'intelligence' => ['captions' => [
        ['start_ms' => 500, 'end_ms' => 2000, 'text' => 'Une idée.'], ['start_ms' => 2500, 'end_ms' => 5000, 'text' => 'La suite.'],
    ]]]);
    Http::fake(['https://api.openai.com/v1/chat/completions' => Http::sequence()
        ->push(['choices' => [['message' => ['content' => json_encode(['clips' => [['first_caption' => 0, 'last_caption' => 1, 'title' => 'Une idée', 'reason' => 'Explication complète']]])]]]])
        ->push(['choices' => [['message' => ['content' => '{"clips":[{"first_caption":999}]}']]]])]);
    expect(app(SocialVideoIntelligenceService::class)->suggest($project, 60))->toBe([
        ['start_ms' => 500, 'end_ms' => 5000, 'title' => 'Une idée', 'reason' => 'Explication complète'],
    ]);
    expect(fn () => app(SocialVideoIntelligenceService::class)->suggest($project, 60))->toThrow(RuntimeException::class, 'invalid_ai_response');
    Http::assertSentCount(2);
});

it('protects intelligence actions across tenants and permissions and refuses a missing key', function () {
    Queue::fake([ProcessSocialVideoIntelligenceJob::class]);
    $owner = videoIntelligenceOwner();
    $project = SocialVideoProject::factory()->for($owner)->create();
    $this->postJson(route('social.videos.intelligence', $project), ['task' => 'transcribe'])->assertUnauthorized();
    $this->actingAs(videoIntelligenceOwner())->postJson(route('social.videos.intelligence', $project), ['task' => 'transcribe'])->assertNotFound();
    $member = User::factory()->create(['onboarding_completed_at' => now()]);
    TeamMember::query()->create(['account_id' => $owner->id, 'user_id' => $member->id, 'role' => 'member', 'is_active' => true, 'permissions' => ['social.view']]);
    $this->actingAs($member)->postJson(route('social.videos.intelligence', $project), ['task' => 'transcribe'])->assertForbidden();
    config(['services.openai.key' => null]);
    $this->actingAs($owner)->postJson(route('social.videos.intelligence', $project), ['task' => 'transcribe'])->assertUnprocessable();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('adapts publication text to each owned destination using only the excerpt transcript', function () {
    $owner = videoIntelligenceOwner();
    $connection = \App\Models\SocialAccountConnection::query()->create([
        'user_id' => $owner->id, 'platform' => 'facebook', 'label' => 'Facebook', 'external_account_id' => 'ai-video',
        'status' => 'connected', 'is_active' => true, ...pulseDirectTransportIdentity($owner, 'facebook', 'ai-video'),
    ]);
    $project = SocialVideoProject::factory()->for($owner)->create(['intelligence' => ['captions' => [
        ['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Outside excerpt'], ['start_ms' => 2000, 'end_ms' => 4000, 'text' => 'Inside excerpt'],
    ]]]);
    $clip = \App\Models\SocialVideoClip::factory()->for($project, 'project')->create(['status' => 'ready', 'start_ms' => 2000, 'end_ms' => 4000]);
    Http::fake(['https://api.openai.com/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => json_encode(['texts' => [['connection_id' => $connection->id, 'text' => 'Une présentation claire.']]])]]],
    ])]);
    expect(app(SocialVideoIntelligenceService::class)->texts($project, [$connection->id]))->toBe([$clip->id => [$connection->id => 'Une présentation claire.']]);
    Http::assertSent(fn ($request) => str_contains($request->body(), 'Inside excerpt') && ! str_contains($request->body(), 'Outside excerpt'));
});

it('converts visual subject coordinates into crop points and rejects uncertain detections', function () {
    Storage::fake('local');
    $project = SocialVideoProject::factory()->for(videoIntelligenceOwner())->create(['duration_ms' => 2000, 'width' => 320, 'height' => 180]);
    Process::fake(function ($process) {
        file_put_contents(end($process->command), 'frame');

        return Process::result();
    });
    $frames = [['index' => 0, 'x' => 10, 'y' => 50, 'confidence' => 0.95], ['index' => 1, 'x' => 90, 'y' => 50, 'confidence' => 0.9]];
    Http::fake(['https://api.openai.com/v1/chat/completions' => Http::sequence()
        ->push(['choices' => [['message' => ['content' => json_encode(['frames' => $frames])]]]])
        ->push(['choices' => [['message' => ['content' => json_encode(['frames' => [['index' => 0, 'x' => 50, 'y' => 50, 'confidence' => 0], $frames[1]]])]]]])]);
    expect(app(SocialVideoIntelligenceService::class)->framing($project, 'portrait', 'The presenter'))->toBe([
        ['time_ms' => 0, 'x' => 0, 'y' => 50], ['time_ms' => 1900, 'x' => 100, 'y' => 50],
    ]);
    expect(fn () => app(SocialVideoIntelligenceService::class)->framing($project, 'portrait', 'The presenter'))
        ->toThrow(RuntimeException::class, 'uncertain_subject');
    expect(Storage::disk('local')->directories(dirname($project->source_path)))->toBe([]);
    Http::assertSentCount(2);
    Process::assertRanTimes(fn ($process) => in_array('-frames:v', $process->command, true), 4);
});

it('does not overwrite a newer run when an old API response arrives late', function () {
    $owner = videoIntelligenceOwner();
    $project = SocialVideoProject::factory()->for($owner)->create(['intelligence_status' => 'pending',
        'intelligence_run_id' => (string) Str::uuid(), 'intelligence' => ['captions' => [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Keep']]]]);
    $oldRun = $project->intelligence_run_id;
    $newRun = (string) Str::uuid();
    Http::fake(['https://api.openai.com/v1/chat/completions' => function () use ($project, $newRun) {
        SocialVideoProject::query()->whereKey($project->id)->update(['intelligence_run_id' => $newRun, 'intelligence_status' => 'pending']);

        return Http::response(['choices' => [['message' => ['content' => '{"clips":[{"first_caption":0,"last_caption":0,"title":"Old","reason":"Old result"}]}']]]]);
    }]);
    (new ProcessSocialVideoIntelligenceJob($project->id, 'suggest', $oldRun, ['seconds' => 60]))->handle(app(SocialVideoIntelligenceService::class));
    expect($project->refresh()->intelligence_run_id)->toBe($newRun);
    expect($project->intelligence_status)->toBe('pending');
    expect($project->intelligence)->not->toHaveKey('suggestions');
    Http::assertSentCount(1);
});
