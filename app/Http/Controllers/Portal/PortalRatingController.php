<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteRating;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkRating;
use App\Notifications\ActionEmailNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PortalRatingController extends Controller
{
    private function portalCustomer(Request $request): Customer
    {
        $customer = $request->user()?->customerProfile;
        if (!$customer) {
            abort(403);
        }

        return $customer;
    }

    public function storeQuote(Request $request, Quote $quote)
    {
        $customer = $this->portalCustomer($request);
        if ($quote->customer_id !== $customer->id) {
            abort(403);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'feedback' => 'nullable|string|max:2000',
        ]);

        $rating = QuoteRating::updateOrCreate(
            [
                'quote_id' => $quote->id,
                'user_id' => $request->user()->id,
            ],
            [
                'rating' => $validated['rating'],
                'feedback' => $validated['feedback'] ?? null,
            ]
        );

        ActivityLog::record($request->user(), $rating, 'created', [
            'quote_id' => $quote->id,
            'rating' => $rating->rating,
        ], 'Quote rated by client');

        $owner = User::find($quote->user_id);
        if ($owner && $owner->email) {
            $customerLabel = $customer->company_name
                ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
            $feedback = $rating->feedback ? Str::limit($rating->feedback, 160) : null;

            $owner->notify(new ActionEmailNotification(
                'Quote rating received',
                $customerLabel ? $customerLabel . ' submitted a quote rating.' : 'A client submitted a quote rating.',
                [
                    ['label' => 'Quote', 'value' => $quote->number ?? $quote->id],
                    ['label' => 'Rating', 'value' => $rating->rating . ' / 5'],
                    ['label' => 'Feedback', 'value' => $feedback ?: 'No feedback provided'],
                ],
                route('customer.quote.show', $quote->id),
                'View quote',
                'Quote rating received'
            ));
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Quote rated successfully.',
                'rating' => [
                    'id' => $rating->id,
                    'rating' => $rating->rating,
                    'feedback' => $rating->feedback,
                ],
            ], 201);
        }

        return redirect()->back()->with('success', 'Quote rated successfully.');
    }

    public function storeWork(Request $request, Work $work)
    {
        $customer = $this->portalCustomer($request);
        if ($work->customer_id !== $customer->id) {
            abort(403);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'feedback' => 'nullable|string|max:2000',
        ]);

        $rating = WorkRating::updateOrCreate(
            [
                'work_id' => $work->id,
                'user_id' => $request->user()->id,
            ],
            [
                'rating' => $validated['rating'],
                'feedback' => $validated['feedback'] ?? null,
            ]
        );

        ActivityLog::record($request->user(), $rating, 'created', [
            'work_id' => $work->id,
            'rating' => $rating->rating,
        ], 'Job rated by client');

        $owner = User::find($work->user_id);
        if ($owner && $owner->email) {
            $customerLabel = $customer->company_name
                ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
            $feedback = $rating->feedback ? Str::limit($rating->feedback, 160) : null;

            $owner->notify(new ActionEmailNotification(
                'Job rating received',
                $customerLabel ? $customerLabel . ' submitted a job rating.' : 'A client submitted a job rating.',
                [
                    ['label' => 'Job', 'value' => $work->job_title ?? $work->number ?? $work->id],
                    ['label' => 'Rating', 'value' => $rating->rating . ' / 5'],
                    ['label' => 'Feedback', 'value' => $feedback ?: 'No feedback provided'],
                ],
                route('work.show', $work->id),
                'View job',
                'Job rating received'
            ));
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Job rated successfully.',
                'rating' => [
                    'id' => $rating->id,
                    'rating' => $rating->rating,
                    'feedback' => $rating->feedback,
                ],
            ], 201);
        }

        return redirect()->back()->with('success', 'Job rated successfully.');
    }
}
