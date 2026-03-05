<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\MessageTemplate;
use App\Models\Product;
use App\Models\User;
use App\Services\Campaigns\TemplateLibraryService;
use App\Services\Campaigns\TemplateRenderer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MarketingTemplateController extends Controller
{
    public function __construct(
        private readonly TemplateLibraryService $templateLibraryService,
        private readonly TemplateRenderer $templateRenderer,
    ) {
    }

    public function index(Request $request)
    {
        [$owner, $canView] = $this->resolveAccess($request->user());
        if (!$canView) {
            abort(403);
        }

        $validated = $request->validate([
            'channel' => ['nullable', Rule::in(Campaign::allowedChannels())],
            'campaign_type' => ['nullable', Rule::in(Campaign::allowedTypes())],
            'language' => 'nullable|string|max:10',
            'search' => 'nullable|string|max:120',
        ]);

        $templates = $this->templateLibraryService->list($owner, $validated);

        return response()->json([
            'templates' => $templates,
        ]);
    }

    public function show(Request $request, MessageTemplate $template)
    {
        [$owner, $canView] = $this->resolveAccess($request->user());
        if (!$canView) {
            abort(403);
        }

        if ((int) $template->user_id !== (int) $owner->id) {
            abort(404);
        }

        return response()->json([
            'template' => $template,
        ]);
    }

    public function store(Request $request)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        $validated = $this->validatedPayload($request);
        $template = $this->templateLibraryService->save($owner, $request->user(), $validated);

        return response()->json([
            'message' => 'Template created.',
            'template' => $template,
        ], 201);
    }

    public function update(Request $request, MessageTemplate $template)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        if ((int) $template->user_id !== (int) $owner->id) {
            abort(404);
        }

        $validated = $this->validatedPayload($request);
        $updated = $this->templateLibraryService->save($owner, $request->user(), $validated, $template);

        return response()->json([
            'message' => 'Template updated.',
            'template' => $updated,
        ]);
    }

    public function destroy(Request $request, MessageTemplate $template)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        if ((int) $template->user_id !== (int) $owner->id) {
            abort(404);
        }

        $this->templateLibraryService->delete($owner, $template);

        return response()->json([
            'message' => 'Template deleted.',
        ]);
    }

    public function preview(Request $request)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        $validated = $request->validate([
            'channel' => ['required', Rule::in(Campaign::allowedChannels())],
            'content' => 'required|array',
            'customer_id' => 'nullable|integer',
            'offer_id' => 'nullable|integer',
        ]);

        [$context, $sample] = $this->buildPreviewContext(
            $owner,
            $validated['customer_id'] ?? null,
            $validated['offer_id'] ?? null
        );

        $preview = $this->templateLibraryService->preview(
            (string) $validated['channel'],
            (array) $validated['content'],
            $context,
            $this->templateRenderer
        );

        return response()->json([
            'preview' => $preview,
            'sample' => $sample,
        ]);
    }

    public function previewTemplate(Request $request, MessageTemplate $template)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        if ((int) $template->user_id !== (int) $owner->id) {
            abort(404);
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|integer',
            'offer_id' => 'nullable|integer',
            'content' => 'nullable|array',
        ]);

        [$context, $sample] = $this->buildPreviewContext(
            $owner,
            $validated['customer_id'] ?? null,
            $validated['offer_id'] ?? null
        );

        $content = is_array($validated['content'] ?? null)
            ? $validated['content']
            : (array) ($template->content ?? []);

        $preview = $this->templateLibraryService->preview(
            (string) $template->channel,
            $content,
            $context,
            $this->templateRenderer
        );

        return response()->json([
            'preview' => $preview,
            'template' => $template,
            'sample' => $sample,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'channel' => ['required', Rule::in(Campaign::allowedChannels())],
            'campaign_type' => ['nullable', Rule::in(Campaign::allowedTypes())],
            'language' => 'nullable|string|max:10',
            'is_default' => 'nullable|boolean',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:60',
            'content' => 'required|array',
        ]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function buildPreviewContext(User $owner, ?int $customerId = null, ?int $offerId = null): array
    {
        $customer = null;
        if ($customerId) {
            $customer = Customer::query()
                ->where('user_id', $owner->id)
                ->whereKey($customerId)
                ->with(['defaultProperty', 'portalUser'])
                ->first();
        }
        if (!$customer) {
            $customer = Customer::query()
                ->where('user_id', $owner->id)
                ->with(['defaultProperty', 'portalUser'])
                ->inRandomOrder()
                ->first();
        }

        $offer = null;
        if ($offerId) {
            $offer = Product::query()
                ->where('user_id', $owner->id)
                ->whereKey($offerId)
                ->first();
        }
        if (!$offer) {
            $offer = Product::query()
                ->where('user_id', $owner->id)
                ->where('is_active', true)
                ->inRandomOrder()
                ->first();
        }

        $campaign = new Campaign([
            'user_id' => $owner->id,
            'name' => 'Template preview',
            'campaign_type' => Campaign::TYPE_ANNOUNCEMENT,
            'type' => Campaign::TYPE_ANNOUNCEMENT,
            'offer_mode' => Campaign::OFFER_MODE_MIXED,
            'locale' => $customer?->portalUser?->locale ?: $owner->locale,
        ]);
        $campaign->setRelation('user', $owner);

        $context = $this->templateRenderer->buildContext($campaign, $customer, $offer);

        return [
            $context,
            [
                'customer_id' => $customer?->id,
                'offer_id' => $offer?->id,
            ],
        ];
    }

    private function resolveAccess(?User $user): array
    {
        if (!$user) {
            abort(401);
        }

        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->select(['id'])->find($ownerId);
        if (!$owner) {
            abort(403);
        }

        if ($user->id === $owner->id) {
            return [$owner, true, true];
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        $canManage = (bool) (
            $membership?->hasPermission('campaigns.manage')
            || $membership?->hasPermission('sales.manage')
        );
        $canView = $canManage
            || (bool) $membership?->hasPermission('campaigns.view')
            || (bool) $membership?->hasPermission('campaigns.send');

        return [$owner, $canView, $canManage];
    }
}

