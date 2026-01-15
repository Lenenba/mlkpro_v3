<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateWorkTasks;
use App\Models\ActivityLog;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ActionEmailNotification;
use App\Support\NotificationDispatcher;
use App\Services\TaskBillingService;
use App\Services\UsageLimitService;
use App\Services\WorkBillingService;
use App\Services\WorkScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PublicWorkController extends Controller
{
    private const LINK_TTL_DAYS = 7;

    public function show(Request $request, Work $work): Response
    {
        $work->load([
            'customer:id,company_name,first_name,last_name,email,phone,auto_validate_jobs,auto_validate_tasks',
            'teamMembers.user:id,name',
        ])->loadCount('tasks');

        $customer = $work->customer;
        $owner = User::find($work->user_id);

        $allowValidation = in_array($work->status, [Work::STATUS_PENDING_REVIEW, Work::STATUS_TECH_COMPLETE], true);
        $allowDispute = in_array($work->status, [Work::STATUS_PENDING_REVIEW, Work::STATUS_TECH_COMPLETE], true);
        if ($customer && $customer->auto_validate_jobs) {
            $allowValidation = false;
            $allowDispute = false;
        }
        $allowSchedule = $work->status === Work::STATUS_SCHEDULED && (int) $work->tasks_count === 0;

        $expiresAt = $this->resolveExpiry($request);
        $validateUrl = URL::temporarySignedRoute(
            'public.works.validate',
            $expiresAt,
            ['work' => $work->id]
        );
        $disputeUrl = URL::temporarySignedRoute(
            'public.works.dispute',
            $expiresAt,
            ['work' => $work->id]
        );
        $confirmScheduleUrl = URL::temporarySignedRoute(
            'public.works.schedule.confirm',
            $expiresAt,
            ['work' => $work->id]
        );
        $rejectScheduleUrl = URL::temporarySignedRoute(
            'public.works.schedule.reject',
            $expiresAt,
            ['work' => $work->id]
        );
        $proofsUrl = URL::temporarySignedRoute(
            'public.works.proofs',
            $expiresAt,
            ['work' => $work->id]
        );

        return Inertia::render('Public/WorkAction', [
            'work' => [
                'id' => $work->id,
                'number' => $work->number,
                'job_title' => $work->job_title,
                'status' => $work->status,
                'start_date' => $work->start_date,
                'end_date' => $work->end_date,
                'start_time' => $work->start_time,
                'end_time' => $work->end_time,
                'frequency' => $work->frequency,
                'repeatsOn' => $work->repeatsOn,
                'totalVisits' => $work->totalVisits,
                'tasks_count' => (int) $work->tasks_count,
                'customer' => $customer ? [
                    'company_name' => $customer->company_name,
                    'first_name' => $customer->first_name,
                    'last_name' => $customer->last_name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                ] : null,
                'team_members' => $work->teamMembers->map(function ($member) {
                    return [
                        'id' => $member->id,
                        'name' => $member->user?->name ?? 'Team member',
                    ];
                })->values(),
            ],
            'company' => [
                'name' => $owner?->company_name ?: config('app.name'),
                'logo_url' => $owner?->company_logo_url,
            ],
            'allow' => [
                'validate' => $allowValidation,
                'dispute' => $allowDispute,
                'schedule' => $allowSchedule,
                'proofs' => !(bool) ($customer?->auto_validate_tasks ?? false),
            ],
            'actions' => [
                'validateUrl' => $validateUrl,
                'disputeUrl' => $disputeUrl,
                'confirmScheduleUrl' => $confirmScheduleUrl,
                'rejectScheduleUrl' => $rejectScheduleUrl,
                'proofsUrl' => $proofsUrl,
            ],
        ]);
    }

    public function validateWork(Request $request, Work $work, WorkBillingService $billingService)
    {
        $customer = $work->customer;
        if ($customer && $customer->auto_validate_jobs) {
            return redirect()->back()->withErrors([
                'status' => 'Job actions are handled by the company.',
            ]);
        }

        if (in_array($work->status, [Work::STATUS_VALIDATED, Work::STATUS_AUTO_VALIDATED], true)) {
            return redirect()->back()->with('success', 'Job already validated.');
        }

        $allowed = [Work::STATUS_PENDING_REVIEW, Work::STATUS_TECH_COMPLETE];
        if (!in_array($work->status, $allowed, true)) {
            return redirect()->back()->withErrors([
                'status' => 'This job is not ready for validation.',
            ]);
        }

        $previousStatus = $work->status;
        $work->status = Work::STATUS_VALIDATED;
        $work->save();

        ActivityLog::record(null, $work, 'status_changed', [
            'from' => $previousStatus,
            'to' => $work->status,
        ], 'Job validated by client (public link)');

        $billingResolver = app(TaskBillingService::class);
        if ($billingResolver->shouldInvoiceOnWorkValidation($work)) {
            $billingService->createInvoiceFromWork($work, null);
        }

        $owner = User::find($work->user_id);
        if ($owner && $owner->email) {
            $customerLabel = $customer?->company_name
                ?: trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? ''));

            NotificationDispatcher::send($owner, new ActionEmailNotification(
                'Job validated by client',
                $customerLabel ? $customerLabel . ' validated a job.' : 'A client validated a job.',
                [
                    ['label' => 'Job', 'value' => $work->job_title ?? $work->number ?? $work->id],
                    ['label' => 'Customer', 'value' => $customerLabel ?: 'Client'],
                    ['label' => 'Status', 'value' => $work->status],
                ],
                route('work.show', $work->id),
                'View job',
                'Job validated by client'
            ), [
                'work_id' => $work->id,
            ]);
        }

        return redirect()->back()->with('success', 'Job validated.');
    }

    public function dispute(Request $request, Work $work)
    {
        $customer = $work->customer;
        if ($customer && $customer->auto_validate_jobs) {
            return redirect()->back()->withErrors([
                'status' => 'Job actions are handled by the company.',
            ]);
        }

        if ($work->status === Work::STATUS_DISPUTE) {
            return redirect()->back()->with('success', 'Job already marked as dispute.');
        }

        $allowed = [Work::STATUS_PENDING_REVIEW, Work::STATUS_TECH_COMPLETE];
        if (!in_array($work->status, $allowed, true)) {
            return redirect()->back()->withErrors([
                'status' => 'This job cannot be disputed right now.',
            ]);
        }

        $previousStatus = $work->status;
        $work->status = Work::STATUS_DISPUTE;
        $work->save();

        ActivityLog::record(null, $work, 'status_changed', [
            'from' => $previousStatus,
            'to' => $work->status,
        ], 'Job disputed by client (public link)');

        $owner = User::find($work->user_id);
        if ($owner && $owner->email) {
            $customerLabel = $customer?->company_name
                ?: trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? ''));

            NotificationDispatcher::send($owner, new ActionEmailNotification(
                'Job disputed by client',
                $customerLabel ? $customerLabel . ' disputed a job.' : 'A client disputed a job.',
                [
                    ['label' => 'Job', 'value' => $work->job_title ?? $work->number ?? $work->id],
                    ['label' => 'Customer', 'value' => $customerLabel ?: 'Client'],
                    ['label' => 'Status', 'value' => $work->status],
                ],
                route('work.show', $work->id),
                'View job',
                'Job disputed by client'
            ), [
                'work_id' => $work->id,
            ]);
        }

        return redirect()->back()->with('success', 'Job marked as dispute.');
    }

    public function confirmSchedule(Request $request, Work $work, WorkScheduleService $scheduleService)
    {
        if ($work->status === Work::STATUS_CANCELLED) {
            return redirect()->back()->withErrors([
                'status' => 'This job cannot be scheduled.',
            ]);
        }

        $assigneeIds = $work->teamMembers()->pluck('team_members.id')->all();
        if (!$assigneeIds) {
            $assigneeIds = TeamMember::query()
                ->forAccount($work->user_id)
                ->active()
                ->pluck('id')
                ->all();
        }

        if (!$assigneeIds) {
            return redirect()->back()->withErrors([
                'schedule' => 'Add at least one team member before confirming the schedule.',
            ]);
        }

        $pendingDates = $scheduleService->pendingDateStrings($work);
        $pendingCount = count($pendingDates);
        if ($pendingCount > 0) {
            $owner = User::find($work->user_id);
            if ($owner) {
                app(UsageLimitService::class)->enforceLimit($owner, 'tasks', $pendingCount);
            }
        }

        if ($pendingCount === 0) {
            return redirect()->back()->with('success', 'Planning deja confirme.');
        }

        if ($work->status !== Work::STATUS_SCHEDULED) {
            $work->status = Work::STATUS_SCHEDULED;
            $work->save();
        }

        $queueDriver = config('queue.default');
        if ($queueDriver && $queueDriver !== 'sync') {
            $chunks = array_chunk($pendingDates, 100);
            foreach ($chunks as $chunk) {
                GenerateWorkTasks::dispatch($work->id, null, $chunk);
            }

            ActivityLog::record(null, $work, 'schedule_queued', [
                'tasks_planned' => $pendingCount,
            ], 'Schedule queued by client (public link)');

            return redirect()->back()->with('success', 'Planning en cours. Les taches seront creees sous peu.');
        }

        try {
            $createdCount = $scheduleService->generateTasksForDates($work, $pendingDates, null);
        } catch (ValidationException $exception) {
            return redirect()->back()->withErrors($exception->errors());
        }

        ActivityLog::record(null, $work, 'schedule_confirmed', [
            'tasks_created' => $createdCount,
        ], 'Schedule confirmed by client (public link)');

        return redirect()->back()->with('success', $createdCount > 0
            ? 'Planning confirme, les taches ont ete creees.'
            : 'Planning deja confirme.');
    }

    public function rejectSchedule(Request $request, Work $work)
    {
        if ($work->status === Work::STATUS_CANCELLED) {
            return redirect()->back()->withErrors([
                'status' => 'This job cannot be updated right now.',
            ]);
        }

        if ($work->tasks()->exists()) {
            return redirect()->back()->withErrors([
                'schedule' => 'This schedule has already been confirmed.',
            ]);
        }

        $previousStatus = $work->status;
        $work->status = Work::STATUS_TO_SCHEDULE;
        $work->save();

        ActivityLog::record(null, $work, 'schedule_rejected', [
            'from' => $previousStatus,
            'to' => $work->status,
        ], 'Schedule rejected by client (public link)');

        $owner = User::find($work->user_id);
        if ($owner && $owner->email) {
            $customer = $work->customer;
            $customerLabel = $customer?->company_name
                ?: trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? ''));

            NotificationDispatcher::send($owner, new ActionEmailNotification(
                'Schedule rejected by client',
                $customerLabel ? $customerLabel . ' rejected a schedule.' : 'A client rejected a schedule.',
                [
                    ['label' => 'Job', 'value' => $work->job_title ?? $work->number ?? $work->id],
                    ['label' => 'Customer', 'value' => $customerLabel ?: 'Client'],
                    ['label' => 'Status', 'value' => $work->status],
                ],
                route('work.edit', ['work' => $work->id, 'tab' => 'planning']),
                'Review schedule',
                'Schedule rejected by client'
            ), [
                'work_id' => $work->id,
            ]);
        }

        return redirect()->back()->with('success', 'Schedule sent back for updates.');
    }

    private function resolveExpiry(Request $request): Carbon
    {
        $expires = $request->query('expires');
        if (is_numeric($expires)) {
            return Carbon::createFromTimestamp((int) $expires);
        }

        return now()->addDays(self::LINK_TTL_DAYS);
    }
}
