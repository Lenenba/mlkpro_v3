<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\ActivityLog;
use App\Notifications\SendQuoteNotification;
use Illuminate\Support\Facades\Auth;

class QuoteEmaillingController extends Controller
{
    public function __invoke(Quote $quote)
    {
        if ($quote->user_id !== Auth::id()) {
            abort(403);
        }

        if ($quote->isArchived()) {
            return redirect()->back()->withErrors([
                'status' => 'Archived quotes cannot be sent.',
            ]);
        }

        $quote->load(['customer.user', 'property', 'products', 'taxes.tax']);

        if (!$quote->customer || !$quote->customer->email) {
            return redirect()->back()->with('error', 'Customer email address is not available.');
        }

        $quote->customer->notify(new SendQuoteNotification($quote));

        ActivityLog::record(Auth::user(), $quote, 'email_sent', [
            'email' => $quote->customer->email,
        ], 'Quote email sent');

        if ($quote->status === 'draft') {
            $previousStatus = $quote->status;
            $quote->update(['status' => 'sent']);
            ActivityLog::record(Auth::user(), $quote, 'status_changed', [
                'from' => $previousStatus,
                'to' => 'sent',
            ], 'Quote status updated');
        }

        return redirect()->back()->with('success', 'Quote sent successfully to ' . $quote->customer->email);
    }
}
