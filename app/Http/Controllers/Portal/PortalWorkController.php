<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Jobs\GenerateWorkTasks;
use App\Services\WorkBillingService;
use App\Services\TaskBillingService;
use App\Services\WorkScheduleService;
use App\Services\UsageLimitService;
use App\Notifications\ActionEmailNotification;
use App\Support\NotificationDispatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PortalWorkController extends Controller
{
    private function portalCustomer(Request $request): Customer
    {
        $customer = $request->user()?->customerProfile;
        if (!$customer) {
            abort(403);
        }

        return $customer;
    }

    public function validateWork(Request $request, Work $work, WorkBillingService $billingService)
    {
        $customer = $this->portalCustomer($request);
        if ($work->customer_id !== $customer->id) {
            abort(403);
        }

        if (in_array($work->status, [Work::STATUS_VALIDATED, Work::STATUS_AUTO_VALIDATED], true)) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Job already validated.',
                    'work' => [
                        'id' => $work->id,
                        'status' => $work->status,
                    ],
                ]);
            }

            return redirect()->back()->with('success', 'Job already validated.');
        }

        $allowed = [Work::STATUS_PENDING_REVIEW, Work::STATUS_TECH_COMPLETE];
        if (!in_array($work->status, $allowed, true)) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'This job is not ready for validation.',
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => 'This job is not ready for validation.',
            ]);
        }

        $previousStatus = $work->status;
        $work->status = Work::STATUS_VALIDATED;
        $work->save();

        ActivityLog::record($request->user(), $work, 'status_changed', [
            'from' => $previousStatus,
            'to' => $work->status,
        ], 'Job validated by client');

        $billingResolver = app(TaskBillingService::class);
        if ($billingResolver->shouldInvoiceOnWorkValidation($work)) {
            $billingService->createInvoiceFromWork($work, $request->user());
        }

        $owner = User::find($work->user_id);
        if ($owner && $owner->email) {
            $customerLabel = $customer->company_name
                ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));

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

        if ($this->shouldReturnJson($request)) {
            $work->refresh();

            return response()->json([
                'message' => 'Job validated.',
                'work' => [
                    'id' => $work->id,
                    'status' => $work->status,
                ],
            ]);
        }

        return redirect()->back()->with('success', 'Job validated.');
    }

    public function dispute(Request $request, Work $work)
    {
        $customer = $this->portalCustomer($request);
        if ($work->customer_id !== $customer->id) {
            abort(403);
        }

        if ($work->status === Work::STATUS_DISPUTE) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Job already marked as dispute.',
                    'work' => [
                        'id' => $work->id,
                        'status' => $work->status,
                    ],
                ]);
            }

            return redirect()->back()->with('success', 'Job already marked as dispute.');
        }

        $allowed = [Work::STATUS_PENDING_REVIEW, Work::STATUS_TECH_COMPLETE];
        if (!in_array($work->status, $allowed, true)) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'This job cannot be disputed right now.',
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => 'This job cannot be disputed right now.',
            ]);
        }

        $previousStatus = $work->status;
        $work->status = Work::STATUS_DISPUTE;
        $work->save();

        ActivityLog::record($request->user(), $work, 'status_changed', [
            'from' => $previousStatus,
            'to' => $work->status,
        ], 'Job disputed by client');

        $owner = User::find($work->user_id);
        if ($owner && $owner->email) {
            $customerLabel = $customer->company_name
                ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));

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

        if ($this->shouldReturnJson($request)) {
            $work->refresh();

            return response()->json([
                'message' => 'Job marked as dispute.',
                'work' => [
                    'id' => $work->id,
                    'status' => $work->status,
                ],
            ]);
        }

        return redirect()->back()->with('success', 'Job marked as dispute.');
    }

    public function confirmSchedule(Request $request, Work $work, WorkScheduleService $scheduleService)
    {
        $customer = $this->portalCustomer($request);
        if ($work->customer_id !== $customer->id) {
            abort(403);
        }

        if ($work->status === Work::STATUS_CANCELLED) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'This job cannot be scheduled.',
                ], 422);
            }

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
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Add at least one team member before confirming the schedule.',
                ], 422);
            }

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
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Planning deja confirme.',
                    'tasks_created' => 0,
                ]);
            }

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
                GenerateWorkTasks::dispatch($work->id, $request->user()?->id, $chunk);
            }

            ActivityLog::record($request->user(), $work, 'schedule_queued', [
                'tasks_planned' => $pendingCount,
            ], 'Schedule queued by client');

            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Planning en cours. Les taches seront creees sous peu.',
                    'queued' => true,
                    'tasks_planned' => $pendingCount,
                ]);
            }

            return redirect()->back()->with('success', 'Planning en cours. Les taches seront creees sous peu.');
        }

        try {
            $createdCount = $scheduleService->generateTasksForDates($work, $pendingDates, $request->user()?->id);
        } catch (ValidationException $exception) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Validation error.',
                    'errors' => $exception->errors(),
                ], 422);
            }

            return redirect()->back()->withErrors($exception->errors());
        }

        ActivityLog::record($request->user(), $work, 'schedule_confirmed', [
            'tasks_created' => $createdCount,
        ], 'Schedule confirmed by client');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $createdCount > 0
                    ? 'Planning confirme, les taches ont ete creees.'
                    : 'Planning deja confirme.',
                'tasks_created' => $createdCount,
            ]);
        }

        return redirect()->back()->with('success', $createdCount > 0
            ? 'Planning confirme, les taches ont ete creees.'
            : 'Planning deja confirme.');
    }

    public function rejectSchedule(Request $request, Work $work)
    {
        $customer = $this->portalCustomer($request);
        if ($work->customer_id !== $customer->id) {
            abort(403);
        }

        if ($work->status === Work::STATUS_CANCELLED) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'This job cannot be updated right now.',
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => 'This job cannot be updated right now.',
            ]);
        }

        if ($work->tasks()->exists()) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'This schedule has already been confirmed.',
                ], 422);
            }

            return redirect()->back()->withErrors([
                'schedule' => 'This schedule has already been confirmed.',
            ]);
        }

        $previousStatus = $work->status;
        $work->status = Work::STATUS_TO_SCHEDULE;
        $work->save();

        ActivityLog::record($request->user(), $work, 'schedule_rejected', [
            'from' => $previousStatus,
            'to' => $work->status,
        ], 'Schedule rejected by client');

        $owner = User::find($work->user_id);
        if ($owner && $owner->email) {
            $customerLabel = $customer->company_name
                ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));

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

        if ($this->shouldReturnJson($request)) {
            $work->refresh();

            return response()->json([
                'message' => 'Schedule sent back for updates.',
                'work' => [
                    'id' => $work->id,
                    'status' => $work->status,
                ],
            ]);
        }

        return redirect()->back()->with('success', 'Schedule sent back for updates.');
    }
}
