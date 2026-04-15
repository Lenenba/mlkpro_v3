<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use App\Notifications\FinanceApprovalRequestedNotification;
use App\Support\NotificationDispatcher;

class FinanceApprovalNotificationService
{
    public function notifyInvoicePendingApproval(Invoice $invoice): bool
    {
        if (! in_array((string) $invoice->approval_status, [
            FinanceApprovalService::APPROVAL_STATUS_SUBMITTED,
            FinanceApprovalService::APPROVAL_STATUS_PENDING_APPROVAL,
        ], true)) {
            return false;
        }

        if (! $invoice->current_approver_role_key) {
            return false;
        }

        $owner = User::query()->find((int) $invoice->user_id);
        if (! $owner) {
            return false;
        }

        $recipients = app(FinanceApprovalService::class)->approverRecipientsForDocument(
            $owner,
            'invoice',
            (string) $invoice->current_approver_role_key,
            $invoice->current_approval_level,
            $invoice->created_by_user_id ? (int) $invoice->created_by_user_id : null,
        );

        if ($recipients->isEmpty()) {
            return false;
        }

        $invoice->loadMissing(['customer:id,first_name,last_name,company_name']);

        return NotificationDispatcher::send(
            $recipients,
            new FinanceApprovalRequestedNotification($invoice),
            [
                'invoice_id' => $invoice->id,
                'account_id' => $owner->id,
                'finance_role' => $invoice->current_approver_role_key,
            ]
        );
    }
}
