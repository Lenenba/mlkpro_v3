<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\TeamMember;
use App\Models\ActivityLog;
use App\Notifications\SendQuoteNotification;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Facades\Auth;

class QuoteEmaillingController extends Controller
{
    public function __invoke(Quote $quote)
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }

        $accountId = $user->accountOwnerId() ?? $user->id;
        if ($quote->user_id !== $accountId) {
            abort(403);
        }

        if ($user->id !== $accountId) {
            $membership = $user->relationLoaded('teamMembership')
                ? $user->teamMembership
                : $user->teamMembership()->first();
            if (!$membership || !$this->canSendQuote($membership)) {
                abort(403);
            }
        }

        if ($quote->isArchived()) {
            if ($this->shouldReturnJson()) {
                return response()->json([
                    'message' => 'Archived quotes cannot be sent.',
                    'errors' => [
                        'status' => ['Archived quotes cannot be sent.'],
                    ],
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => 'Archived quotes cannot be sent.',
            ]);
        }

        $quote->load(['customer.user', 'property', 'products', 'taxes.tax']);

        if (!$quote->customer || !$quote->customer->email) {
            if ($this->shouldReturnJson()) {
                return response()->json([
                    'message' => 'Customer email address is not available.',
                ], 422);
            }

            return redirect()->back()->with('error', 'Customer email address is not available.');
        }

        $emailQueued = NotificationDispatcher::send($quote->customer, new SendQuoteNotification($quote), [
            'quote_id' => $quote->id,
            'customer_id' => $quote->customer->id,
            'email' => $quote->customer->email,
        ]);

        if ($emailQueued) {
            ActivityLog::record(Auth::user(), $quote, 'email_sent', [
                'email' => $quote->customer->email,
            ], 'Quote email sent');
        } else {
            ActivityLog::record(Auth::user(), $quote, 'email_failed', [
                'email' => $quote->customer->email,
            ], 'Quote email failed');
        }

        if ($quote->status === 'draft') {
            $previousStatus = $quote->status;
            $quote->update(['status' => 'sent']);
            ActivityLog::record(Auth::user(), $quote, 'status_changed', [
                'from' => $previousStatus,
                'to' => 'sent',
            ], 'Quote status updated');
        }

        $quote->syncRequestStatusFromQuote();

        if ($this->shouldReturnJson()) {
            if (!$emailQueued) {
                return response()->json([
                    'message' => 'Quote email could not be sent right now.',
                    'warning' => true,
                    'quote' => $quote->fresh(),
                ]);
            }

            return response()->json([
                'message' => 'Quote sent successfully to ' . $quote->customer->email,
                'quote' => $quote->fresh(),
            ]);
        }

        if (!$emailQueued) {
            return redirect()->back()->with('warning', 'Quote email could not be sent right now.');
        }

        return redirect()->back()->with('success', 'Quote sent successfully to ' . $quote->customer->email);
    }

    private function canSendQuote(?TeamMember $membership): bool
    {
        if (!$membership) {
            return false;
        }

        return $membership->hasPermission('quotes.send') || $membership->hasPermission('quotes.edit');
    }
}
