<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\OrderReview;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\ReviewModerationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PortalReviewController extends Controller
{
    private function resolvePortalSale(Request $request, Sale $sale): array
    {
        $customer = $request->user()?->customerProfile;
        if (!$customer) {
            abort(403);
        }

        $owner = User::query()
            ->select(['id', 'company_type'])
            ->find($customer->user_id);

        if (!$owner || $owner->company_type !== 'products') {
            abort(403);
        }

        if ($sale->user_id !== $owner->id || $sale->customer_id !== $customer->id) {
            abort(404);
        }

        return [$customer, $owner, $sale];
    }

    private function ensurePaid(Sale $sale): void
    {
        $sale->loadSum(['payments as payments_sum_amount' => fn($query) => $query->where('status', 'completed')], 'amount');
        if ($sale->payment_status !== Sale::STATUS_PAID) {
            throw ValidationException::withMessages([
                'payment' => 'Vous pouvez laisser un avis uniquement apres paiement.',
            ]);
        }
    }

    private function moderationPayload(ReviewModerationService $moderation, array $fields): array
    {
        $text = collect($fields)->filter()->implode(' ');
        [$blocked, $term] = $moderation->check($text);

        return [
            'is_approved' => !$blocked,
            'blocked_reason' => $blocked ? 'blocked_terms' : null,
            'blocked_term' => $term,
        ];
    }

    public function storeOrder(Request $request, Sale $sale, ReviewModerationService $moderation)
    {
        [$customer, , $sale] = $this->resolvePortalSale($request, $sale);
        $this->ensurePaid($sale);

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        $payload = $this->moderationPayload($moderation, [$validated['comment'] ?? null]);

        $review = OrderReview::updateOrCreate(
            [
                'sale_id' => $sale->id,
                'customer_id' => $customer->id,
            ],
            [
                'rating' => $validated['rating'],
                'comment' => $validated['comment'] ?? null,
                'is_approved' => $payload['is_approved'],
                'blocked_reason' => $payload['blocked_reason'],
            ]
        );

        $message = $payload['is_approved']
            ? 'Merci ! Votre avis a ete publie.'
            : 'Merci ! Votre avis a ete bloque pour moderation.';

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'review' => [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                    'is_approved' => $review->is_approved,
                    'blocked_reason' => $review->blocked_reason,
                ],
            ], 201);
        }

        return redirect()->back()->with('success', $message);
    }

    public function storeProduct(Request $request, Sale $sale, Product $product, ReviewModerationService $moderation)
    {
        [$customer, $owner, $sale] = $this->resolvePortalSale($request, $sale);
        $this->ensurePaid($sale);

        if ($product->user_id !== $owner->id || $product->item_type !== Product::ITEM_TYPE_PRODUCT) {
            abort(404);
        }

        $hasProduct = SaleItem::query()
            ->where('sale_id', $sale->id)
            ->where('product_id', $product->id)
            ->exists();

        if (!$hasProduct) {
            abort(403);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:160',
            'comment' => 'nullable|string|max:2000',
        ]);

        $payload = $this->moderationPayload($moderation, [$validated['title'] ?? null, $validated['comment'] ?? null]);

        $review = ProductReview::updateOrCreate(
            [
                'product_id' => $product->id,
                'customer_id' => $customer->id,
            ],
            [
                'sale_id' => $sale->id,
                'rating' => $validated['rating'],
                'title' => $validated['title'] ?? null,
                'comment' => $validated['comment'] ?? null,
                'is_approved' => $payload['is_approved'],
                'blocked_reason' => $payload['blocked_reason'],
            ]
        );

        $message = $payload['is_approved']
            ? 'Merci ! Votre avis a ete publie.'
            : 'Merci ! Votre avis a ete bloque pour moderation.';

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'review' => [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'title' => $review->title,
                    'comment' => $review->comment,
                    'is_approved' => $review->is_approved,
                    'blocked_reason' => $review->blocked_reason,
                ],
            ], 201);
        }

        return redirect()->back()->with('success', $message);
    }
}
