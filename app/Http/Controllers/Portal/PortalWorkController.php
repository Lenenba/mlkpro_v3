<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Models\Work;
use App\Services\WorkBillingService;
use App\Notifications\ActionEmailNotification;
use Illuminate\Http\Request;

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

        ActivityLog::record($request->user(), $work, 'status_changed', [
            'from' => $previousStatus,
            'to' => $work->status,
        ], 'Job validated by client');

        $billingService->createInvoiceFromWork($work, $request->user());

        $owner = User::find($work->user_id);
        if ($owner && $owner->email) {
            $customerLabel = $customer->company_name
                ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));

            $owner->notify(new ActionEmailNotification(
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
            ));
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

        ActivityLog::record($request->user(), $work, 'status_changed', [
            'from' => $previousStatus,
            'to' => $work->status,
        ], 'Job disputed by client');

        $owner = User::find($work->user_id);
        if ($owner && $owner->email) {
            $customerLabel = $customer->company_name
                ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));

            $owner->notify(new ActionEmailNotification(
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
            ));
        }

        return redirect()->back()->with('success', 'Job marked as dispute.');
    }
}
