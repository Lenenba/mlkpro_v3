<?php

namespace App\Actions\Works;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ActionEmailNotification;
use App\Services\TaskBillingService;
use App\Services\WorkBillingService;
use App\Support\LocalePreference;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class UpdateWorkStatusAction
{
    public function execute(Work $work, string $nextStatus, User $actor, WorkBillingService $billingService): Work
    {
        $beforeCount = $work->media()->where('type', 'before')->count();
        $afterCount = $work->media()->where('type', 'after')->count();

        if ($nextStatus === Work::STATUS_IN_PROGRESS && $beforeCount < 3) {
            throw ValidationException::withMessages([
                'status' => 'Upload at least 3 before photos before starting the job.',
            ]);
        }

        if ($nextStatus === Work::STATUS_TECH_COMPLETE) {
            $pendingChecklist = $work->checklistItems()->where('status', '!=', 'done')->count();
            if ($pendingChecklist > 0) {
                throw ValidationException::withMessages([
                    'status' => 'Complete all checklist items before finishing the job.',
                ]);
            }

            if ($afterCount < 3) {
                throw ValidationException::withMessages([
                    'status' => 'Upload at least 3 after photos before finishing the job.',
                ]);
            }
        }

        $autoValidateJobs = (bool) ($work->customer?->auto_validate_jobs ?? false);
        if ($autoValidateJobs && in_array($nextStatus, [Work::STATUS_TECH_COMPLETE, Work::STATUS_PENDING_REVIEW], true)) {
            $nextStatus = Work::STATUS_AUTO_VALIDATED;
        }

        $previousStatus = $work->status;
        $work->status = $nextStatus;
        $work->save();

        ActivityLog::record($actor, $work, 'status_changed', [
            'from' => $previousStatus,
            'to' => $nextStatus,
        ], 'Job status updated');

        $notifyStatuses = [Work::STATUS_TECH_COMPLETE, Work::STATUS_PENDING_REVIEW];
        if (! in_array($previousStatus, $notifyStatuses, true) && in_array($nextStatus, $notifyStatuses, true)) {
            $customer = $work->customer;
            if ($customer && $customer->email) {
                $customerLabel = $customer->company_name
                    ?: trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''));
                $locale = LocalePreference::forCustomer($customer, $actor);
                $isFr = str_starts_with($locale, 'fr');
                $usePublicLink = ! (bool) ($customer->portal_access ?? true) || ! $customer->portal_user_id;
                $actionUrl = route('dashboard');
                $actionLabel = $isFr ? 'Ouvrir le tableau de bord' : 'Open dashboard';
                if ($usePublicLink) {
                    $actionUrl = URL::temporarySignedRoute(
                        'public.works.show',
                        now()->addDays(7),
                        ['work' => $work->id]
                    );
                    $actionLabel = $isFr ? 'Verifier l intervention' : 'Review job';
                }

                NotificationDispatcher::send($customer, new ActionEmailNotification(
                    $isFr ? 'Intervention prete pour validation' : 'Job ready for validation',
                    $isFr ? 'Une intervention est prete pour votre validation.' : 'A job is ready for your validation.',
                    [
                        ['label' => $isFr ? 'Intervention' : 'Job', 'value' => $work->job_title ?? $work->number ?? $work->id],
                        ['label' => $isFr ? 'Statut' : 'Status', 'value' => $nextStatus],
                        ['label' => $isFr ? 'Client' : 'Customer', 'value' => $customerLabel ?: ($isFr ? 'Client' : 'Customer')],
                    ],
                    $actionUrl,
                    $actionLabel,
                    $isFr ? 'Intervention prete pour validation' : 'Job ready for validation'
                ), [
                    'work_id' => $work->id,
                ]);
            }
        }

        if (in_array($nextStatus, [Work::STATUS_VALIDATED, Work::STATUS_AUTO_VALIDATED], true)
            && app(TaskBillingService::class)->shouldInvoiceOnWorkValidation($work)) {
            $billingService->createInvoiceFromWork($work, $actor);
        }

        return $work;
    }
}
