<?php

namespace App\Modules\AiAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiKnowledgeItem;
use App\Modules\AiAssistant\Requests\StoreAiKnowledgeItemRequest;
use Illuminate\Http\Request;

class AiKnowledgeItemController extends Controller
{
    public function index(Request $request)
    {
        $account = $this->resolveAccount($request);
        $this->authorize('manage', AiAssistantSetting::class);

        $items = AiKnowledgeItem::query()
            ->forTenant((int) $account->id)
            ->latest()
            ->paginate($this->resolveDataTablePerPage($request))
            ->withQueryString();

        return $this->inertiaOrJson('AiAssistant/Knowledge/Index', [
            'items' => $items,
        ]);
    }

    public function store(StoreAiKnowledgeItemRequest $request)
    {
        $account = $this->resolveAccount($request);
        $this->authorize('manage', AiAssistantSetting::class);

        $item = AiKnowledgeItem::query()->create([
            ...$request->validated(),
            'tenant_id' => (int) $account->id,
        ]);

        return response()->json([
            'message' => 'Knowledge item saved.',
            'item' => $item,
        ], 201);
    }

    public function update(StoreAiKnowledgeItemRequest $request, AiKnowledgeItem $item)
    {
        $account = $this->resolveAccount($request);
        $this->authorize('manage', AiAssistantSetting::class);
        abort_unless((int) $item->tenant_id === (int) $account->id, 404);

        $item->update($request->validated());

        return response()->json([
            'message' => 'Knowledge item updated.',
            'item' => $item->fresh(),
        ]);
    }

    public function destroy(Request $request, AiKnowledgeItem $item)
    {
        $account = $this->resolveAccount($request);
        $this->authorize('manage', AiAssistantSetting::class);
        abort_unless((int) $item->tenant_id === (int) $account->id, 404);

        $item->delete();

        return response()->json([
            'message' => 'Knowledge item deleted.',
        ]);
    }

    private function resolveAccount(Request $request): User
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $accountId = (int) $user->accountOwnerId();
        $account = $accountId === (int) $user->id
            ? $user
            : User::query()->find($accountId);

        if (! $account) {
            abort(404);
        }

        return $account;
    }
}
