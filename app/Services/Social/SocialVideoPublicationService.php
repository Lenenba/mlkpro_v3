<?php

namespace App\Services\Social;

use App\Models\SocialAccountConnection;
use App\Models\SocialVideoProject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SocialVideoPublicationService
{
    public function __construct(
        private readonly SocialPostService $posts,
        private readonly SocialScheduledTimeResolver $times,
        private readonly SocialMediaAssetService $media,
        private readonly SocialPublishingService $publishing,
    ) {}

    /** @param list<int> $ids @return Collection<int, SocialAccountConnection> */
    public function connections(User $owner, array $ids): Collection
    {
        $connections = SocialAccountConnection::query()->where('user_id', $owner->id)->whereIn('id', $ids)
            ->where('is_active', true)->where('status', SocialAccountConnection::STATUS_CONNECTED)->get();
        if ($ids === [] || $connections->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['connection_ids' => __('social_video.invalid_connections')]);
        }

        return $connections;
    }

    /** @param array<string, mixed> $input @return list<array<string, mixed>> */
    public function preview(SocialVideoProject $project, array $input): array
    {
        $this->assertReady($project, $input['clip_ids']);
        $connections = $this->connections($project->user, $input['connection_ids']);
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $input['start_date'], 'UTC');
        $rows = [];
        foreach ($project->clips as $index => $clip) {
            $date = $day->addDays($index * $input['interval_days'])->format('Y-m-d');
            $scheduled = $this->times->resolve($project->user, $date.'T'.$input['time']);
            if ($scheduled === null || $scheduled->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['start_date' => __('social_video.future_schedule')]);
            }
            foreach ($connections as $connection) {
                $rows[] = [
                    'clip_id' => $clip->id, 'position' => $clip->position, 'connection_id' => $connection->id,
                    'platform' => $connection->platform, 'account' => $connection->label,
                    'scheduled_for' => $scheduled->toIso8601String(), 'local_time' => $date.' '.$input['time'],
                    'text' => $project->intelligence['texts'][$clip->id][$connection->id] ?? '',
                ];
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $input */
    public function create(SocialVideoProject $project, User $actor, array $input): SocialVideoProject
    {
        $assets = [];
        try {
            DB::transaction(function () use ($project, $actor, $input, &$assets): void {
                $locked = SocialVideoProject::query()->with(['user', 'clips'])->lockForUpdate()->findOrFail($project->id);
                $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
                if ($locked->clips->contains(fn ($clip) => $clip->publication_ids !== null)) {
                    if (($locked->settings['publication_request_hash'] ?? null) === $hash) {
                        return;
                    }
                    throw ValidationException::withMessages(['video' => __('social_video.already_planned')]);
                }
                $rows = $this->preview($locked, $input);
                $submitted = collect($input['rows'])->keyBy(fn ($row) => $row['clip_id'].':'.$row['connection_id']);
                if ($submitted->count() !== count($rows) || count($input['rows']) !== count($rows)) {
                    throw ValidationException::withMessages(['rows' => __('social_video.invalid_publications')]);
                }
                foreach ($rows as &$row) {
                    $text = $submitted->get($row['clip_id'].':'.$row['connection_id'])['text'] ?? null;
                    if (! is_string($text) || trim($text) === '' || mb_strlen($text) > ($row['platform'] === 'x' ? 280 : 4000)) {
                        throw ValidationException::withMessages(['rows' => __('social_video.invalid_publications')]);
                    }
                    $row['text'] = trim($text);
                }
                unset($row);
                foreach ($locked->clips as $clip) {
                    if (! $clip->path || ! Storage::disk('local')->exists($clip->path)) {
                        throw ValidationException::withMessages(['video' => __('social_video.clips_not_ready')]);
                    }
                    $asset = $this->media->storeUploadedMedia($locked->user,
                        new UploadedFile(Storage::disk('local')->path($clip->path), 'clip-'.$clip->position.'.mp4', 'video/mp4', null, true), 'posts');
                    $assets[] = $asset;
                    $ids = [];
                    foreach (array_filter($rows, fn ($row) => $row['clip_id'] === $clip->id) as $row) {
                        $post = $this->posts->createDraft($locked->user, $actor, [
                            'text' => $row['text'], 'media_uploads' => [$asset],
                            'scheduled_for' => $row['scheduled_for'], 'target_connection_ids' => [$row['connection_id']],
                            'metadata' => ['origin' => 'social_video', 'social_video_project_id' => $locked->id,
                                'social_video_clip_id' => $clip->id, 'video_series_position' => $clip->position],
                        ]);
                        if ($input['mode'] === 'schedule') {
                            $post = $this->publishing->schedule($locked->user, $actor, $post);
                            if ($post->targets->contains(fn ($target) => $target->status === 'failed')) {
                                throw ValidationException::withMessages(['video' => __('social_video.scheduling_failed')]);
                            }
                        }
                        $ids[] = ['post_id' => $post->id, 'connection_id' => $row['connection_id']];
                    }
                    $clip->update(['publication_ids' => $ids]);
                }
                $locked->update(['settings' => [...($locked->settings ?? []), 'publication_request_hash' => $hash]]);
            });
        } catch (Throwable $exception) {
            $this->media->deleteRemovedUploads($project->user, $assets);
            throw $exception;
        }

        return $project->refresh();
    }

    /** @param list<int> $ids */
    private function assertReady(SocialVideoProject $project, array $ids): void
    {
        $project->loadMissing(['clips', 'user']);
        if ($project->status !== 'ready' || in_array($project->intelligence_status, ['pending', 'processing'], true)
            || $project->clips->isEmpty() || $project->clips->contains(fn ($clip) => $clip->status !== 'ready')
            || $project->clips->modelKeys() !== array_map('intval', $ids)) {
            throw ValidationException::withMessages(['video' => __('social_video.clips_not_ready')]);
        }
    }
}
