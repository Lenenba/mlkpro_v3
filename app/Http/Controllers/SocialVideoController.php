<?php

namespace App\Http\Controllers;

use App\Jobs\PrepareSocialVideoJob;
use App\Jobs\ProcessSocialVideoIntelligenceJob;
use App\Models\SocialVideoClip;
use App\Models\SocialVideoProject;
use App\Models\User;
use App\Services\Social\SocialPostService;
use App\Services\Social\SocialVideoPublicationService;
use App\Services\Social\SocialVideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SocialVideoController extends Controller
{
    public function __construct(private readonly SocialVideoService $videos) {}

    public function index(Request $request): \Inertia\Response|JsonResponse
    {
        [$owner, $canManage] = $this->access($request->user());

        return $this->inertiaOrJson('Social/Videos', [
            'projects' => SocialVideoProject::query()->where('user_id', $owner->id)->with('clips')
                ->latest('id')->get()->map(fn ($project) => $this->videos->payload($project))->all(),
            'access' => ['can_manage_posts' => $canManage, 'can_publish' => $this->canPublish($request->user())],
            'connected_accounts' => $request->expectsJson() ? [] : app(SocialPostService::class)->connectedAccountOptions($owner),
            'timezone' => $owner->company_timezone ?: config('app.timezone', 'UTC'),
            'ai_available' => trim((string) config('services.openai.key')) !== '',
            'limits' => [
                'max_upload_kb' => (int) config('social_video.max_upload_kb'),
                'chunk_bytes' => min(1048576, $this->maxUploadKb() * 1024),
                'max_duration_ms' => (int) config('social_video.max_duration_ms'),
                'max_clips' => (int) config('social_video.max_clips'),
                'max_clip_duration_ms' => (int) config('social_video.max_clip_duration_ms'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        [$owner] = $this->access($request->user(), true);
        $request->validate([
            'video_file' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm', 'max:'.$this->maxUploadKb()],
        ]);
        if (SocialVideoProject::query()->where('user_id', $owner->id)->count() >= 50) {
            throw ValidationException::withMessages(['video_file' => __('social_video.project_limit')]);
        }
        $project = $this->videos->store($owner, $request->user(), $request->file('video_file'));

        return response()->json(['project' => $this->videos->payload($project)], 201);
    }

    public function show(Request $request, SocialVideoProject $project): JsonResponse
    {
        $this->authorizeProject($request, $project);

        return response()->json(['project' => $this->videos->payload($project)]);
    }

    public function beginUpload(Request $request): JsonResponse
    {
        [$owner] = $this->access($request->user(), true);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/\.(mp4|mov|webm)$/i'],
            'size' => ['required', 'integer', 'min:1', 'max:'.((int) config('social_video.max_upload_kb') * 1024)],
        ]);
        if (SocialVideoProject::query()->where('user_id', $owner->id)->count() >= 50) {
            throw ValidationException::withMessages(['video_file' => __('social_video.project_limit')]);
        }
        $project = $this->videos->beginUpload($owner, $request->user(), $data['name'], (int) $data['size']);

        return response()->json(['project' => $this->videos->payload($project)], 201);
    }

    public function appendUpload(Request $request, SocialVideoProject $project): JsonResponse
    {
        $this->authorizeProject($request, $project, true);
        $data = $request->validate([
            'chunk' => ['required', 'file', 'max:1024'],
            'offset' => ['required', 'integer', 'min:0'],
        ]);
        $updated = $this->videos->appendUpload($project, $request->file('chunk'), (int) $data['offset']);

        return response()->json(['project' => $this->videos->payload($updated)]);
    }

    public function render(Request $request, SocialVideoProject $project): JsonResponse
    {
        $this->authorizeProject($request, $project, true);
        $settings = $request->validate([
            'mode' => ['required', 'in:duration,manual'],
            'segment_seconds' => ['exclude_unless:mode,duration', 'required', 'integer', 'min:1', 'max:300'],
            'segments' => ['exclude_unless:mode,manual', 'required', 'array', 'min:1', 'max:'.config('social_video.max_clips')],
            'segments.*' => ['array:start_ms,end_ms'],
            'segments.*.start_ms' => ['required', 'integer', 'min:0'],
            'segments.*.end_ms' => ['required', 'integer', 'min:1'],
            'format' => ['required', 'in:portrait,landscape'],
            'framing' => ['required', 'in:crop,blur'],
            'focal_x' => ['required', 'integer', 'between:0,100'],
            'focal_y' => ['required', 'integer', 'between:0,100'],
            'captions_enabled' => ['sometimes', 'boolean'],
            'caption_style' => ['sometimes', 'in:white,yellow'],
            'caption_position' => ['sometimes', 'in:bottom,top'],
            'captions' => ['sometimes', 'array', 'max:1000'],
            'captions.*' => ['array:start_ms,end_ms,text'],
            'captions.*.start_ms' => ['required', 'integer', 'min:0'],
            'captions.*.end_ms' => ['required', 'integer', 'min:1'],
            'captions.*.text' => ['required', 'string', 'max:160', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F]/u'],
            'crop_points' => ['sometimes', 'array', 'max:60'],
            'crop_points.*' => ['array:time_ms,x,y'],
            'crop_points.*.time_ms' => ['required', 'integer', 'min:0'],
            'crop_points.*.x' => ['required', 'integer', 'between:0,100'],
            'crop_points.*.y' => ['required', 'integer', 'between:0,100'],
        ]);

        return response()->json(['project' => $this->videos->payload($this->videos->render($project, $settings))], 202);
    }

    public function retry(Request $request, SocialVideoProject $project): JsonResponse
    {
        $this->authorizeProject($request, $project, true);
        $claimed = SocialVideoProject::query()->whereKey($project->id)->where('status', 'failed')
            ->update(['status' => 'pending', 'error_code' => null]);
        if (! $claimed) {
            throw ValidationException::withMessages(['video' => __('social_video.busy')]);
        }
        try {
            PrepareSocialVideoJob::dispatch($project->id)->afterCommit();
        } catch (Throwable $exception) {
            $project->update(['status' => 'failed', 'error_code' => 'processing_failed']);
            report($exception);
        }

        return response()->json(['project' => $this->videos->payload($project->refresh())], 202);
    }

    public function intelligence(Request $request, SocialVideoProject $project): JsonResponse
    {
        $this->authorizeProject($request, $project, true);
        $data = $request->validate([
            'task' => ['required', 'in:transcribe,suggest,framing,texts'],
            'seconds' => ['required_if:task,suggest', 'integer', 'between:10,300'],
            'format' => ['required_if:task,framing', 'in:portrait,landscape'],
            'subject' => ['required_if:task,framing', 'string', 'max:120'],
            'connection_ids' => ['required_if:task,texts', 'array', 'min:1', 'max:5'],
            'connection_ids.*' => ['integer', 'distinct'],
        ]);
        if (trim((string) config('services.openai.key')) === '') {
            throw ValidationException::withMessages(['video' => __('social_video.ai_unavailable')]);
        }
        $runId = (string) Str::uuid();
        DB::transaction(function () use ($project, $data, $runId): void {
            $locked = SocialVideoProject::query()->with('user')->lockForUpdate()->findOrFail($project->id);
            if ($locked->status !== 'ready' || in_array($locked->intelligence_status, ['pending', 'processing'], true)
                || $locked->clips()->whereIn('status', ['pending', 'processing'])->exists()) {
                throw ValidationException::withMessages(['video' => __('social_video.busy')]);
            }
            if ($data['task'] === 'texts') {
                app(SocialVideoPublicationService::class)->connections($locked->user, $data['connection_ids']);
                if (! $locked->clips()->exists() || $locked->clips()->where('status', '!=', 'ready')->exists()) {
                    throw ValidationException::withMessages(['video' => __('social_video.clips_not_ready')]);
                }
            }
            $locked->update(['intelligence_status' => 'pending', 'intelligence_run_id' => $runId, 'intelligence_error_code' => null]);
        });
        $job = new ProcessSocialVideoIntelligenceJob($project->id, $data['task'], $runId, $data);
        try {
            dispatch($job)->afterCommit();
        } catch (Throwable $exception) {
            $job->failed($exception);
            report($exception);
        }

        return response()->json(['project' => $this->videos->payload($project->refresh())], 202);
    }

    public function publicationPreview(Request $request, SocialVideoProject $project): JsonResponse
    {
        $this->authorizeProject($request, $project, true);

        return response()->json(['rows' => app(SocialVideoPublicationService::class)->preview($project, $this->publicationInput($request))]);
    }

    public function publications(Request $request, SocialVideoProject $project): JsonResponse
    {
        $this->authorizeProject($request, $project, true);
        $data = $this->publicationInput($request, true);
        abort_unless($data['mode'] !== 'schedule' || $this->canPublish($request->user()), 403);
        $updated = app(SocialVideoPublicationService::class)->create($project, $request->user(), $data);

        return response()->json(['project' => $this->videos->payload($updated)], 201);
    }

    /** @return array<string, mixed> */
    private function publicationInput(Request $request, bool $create = false): array
    {
        return $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'interval_days' => ['required', 'integer', 'between:1,30'],
            'connection_ids' => ['required', 'array', 'min:1', 'max:5'],
            'connection_ids.*' => ['required', 'integer', 'distinct'],
            'clip_ids' => ['required', 'array', 'min:1', 'max:30'],
            'clip_ids.*' => ['required', 'integer', 'distinct'],
            ...($create ? [
                'mode' => ['required', 'in:drafts,schedule'],
                'request_id' => ['required', 'uuid'],
                'rows' => ['required', 'array', 'min:1', 'max:150'],
                'rows.*' => ['array:clip_id,connection_id,text'],
                'rows.*.clip_id' => ['required', 'integer'],
                'rows.*.connection_id' => ['required', 'integer'],
                'rows.*.text' => ['required', 'string', 'max:4000'],
            ] : []),
        ]);
    }

    private function canPublish(User $user): bool
    {
        return ($user->accountOwnerId() === $user->id && $user->isAccountOwner())
            || ($user->teamMembership?->hasPermission('social.publish') && $user->teamMembership?->hasPermission('social.approve'));
    }

    public function preview(Request $request, SocialVideoProject $project): BinaryFileResponse
    {
        $this->authorizeProject($request, $project);
        abort_unless($project->status === 'ready' && $project->preview_path, 404);

        return $this->videoResponse($project->preview_path);
    }

    public function clipPreview(Request $request, SocialVideoProject $project, SocialVideoClip $clip): BinaryFileResponse
    {
        $this->authorizeProject($request, $project);
        abort_unless($clip->social_video_project_id === $project->id && $clip->status === 'ready' && $clip->path, 404);

        return $this->videoResponse($clip->path);
    }

    public function destroy(Request $request, SocialVideoProject $project): Response
    {
        $this->authorizeProject($request, $project, true);
        DB::transaction(function () use ($project): void {
            $locked = SocialVideoProject::query()->lockForUpdate()->findOrFail($project->id);
            if (in_array($locked->status, ['pending', 'processing'], true)
                || in_array($locked->intelligence_status, ['pending', 'processing'], true)
                || $locked->clips()->whereIn('status', ['pending', 'processing'])->exists()) {
                throw ValidationException::withMessages(['video' => __('social_video.busy')]);
            }
            $locked->delete();
        });
        Storage::disk('local')->deleteDirectory(dirname($project->source_path));

        return response()->noContent();
    }

    private function videoResponse(string $path): BinaryFileResponse
    {
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'video/mp4', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate();
    }

    private function authorizeProject(Request $request, SocialVideoProject $project, bool $manage = false): void
    {
        [$owner] = $this->access($request->user(), $manage);
        abort_unless((int) $project->user_id === (int) $owner->id, 404);
    }

    /** @return array{0: User, 1: bool} */
    private function access(?User $user, bool $manage = false): array
    {
        abort_unless($user, 401);
        $ownerId = $user->accountOwnerId();
        if ($ownerId === $user->id && $user->isAccountOwner()) {
            return [$user, true];
        }
        $membership = $user->teamMembership;
        $canManage = (bool) ($membership?->hasPermission('social.manage') || $membership?->hasPermission('social.publish'));
        $canView = $canManage || $membership?->hasPermission('social.view') || $membership?->hasPermission('social.approve');
        abort_unless($manage ? $canManage : $canView, 403);

        return [User::query()->findOrFail($ownerId), $canManage];
    }

    private function maxUploadKb(): int
    {
        $limits = [(int) config('social_video.max_upload_kb')];
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = ini_parse_quantity((string) ini_get($setting));
            if ($bytes > 0) {
                $limits[] = max(1, (int) floor(($bytes - ($setting === 'post_max_size' ? 65536 : 0)) / 1024));
            }
        }

        return min($limits);
    }
}
