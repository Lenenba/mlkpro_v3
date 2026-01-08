<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WarehouseController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $accountId = $user->accountOwnerId();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:30',
            'country' => 'nullable|string|max:255',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $isDefault = (bool) ($data['is_default'] ?? false);
        if ($isDefault) {
            Warehouse::query()->forAccount($accountId)->update(['is_default' => false]);
        }

        $warehouse = Warehouse::create([
            'user_id' => $accountId,
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'country' => $data['country'] ?? null,
            'is_default' => $isDefault,
            'is_active' => $data['is_active'] ?? true,
        ]);

        if (!$isDefault && Warehouse::query()->forAccount($accountId)->where('is_default', true)->doesntExist()) {
            $warehouse->update(['is_default' => true]);
        }

        return response()->json([
            'warehouse' => $warehouse->fresh(),
        ], 201);
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $accountId = $user->accountOwnerId();
        if ((int) $warehouse->user_id !== (int) $accountId) {
            abort(403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:30',
            'country' => 'nullable|string|max:255',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $isDefault = (bool) ($data['is_default'] ?? $warehouse->is_default);
        if ($isDefault) {
            Warehouse::query()->forAccount($accountId)->update(['is_default' => false]);
        }

        $warehouse->update([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'country' => $data['country'] ?? null,
            'is_default' => $isDefault,
            'is_active' => $data['is_active'] ?? $warehouse->is_active,
        ]);

        if (Warehouse::query()->forAccount($accountId)->where('is_default', true)->doesntExist()) {
            $warehouse->update(['is_default' => true]);
        }

        return response()->json([
            'warehouse' => $warehouse->fresh(),
        ]);
    }

    public function destroy(Request $request, Warehouse $warehouse)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $accountId = $user->accountOwnerId();
        if ((int) $warehouse->user_id !== (int) $accountId) {
            abort(403);
        }

        $hasInventory = ProductInventory::query()
            ->where('warehouse_id', $warehouse->id)
            ->exists();

        if ($hasInventory) {
            throw ValidationException::withMessages([
                'warehouse' => ['Move inventory before deleting this warehouse.'],
            ]);
        }

        $wasDefault = $warehouse->is_default;
        $warehouse->delete();

        if ($wasDefault) {
            $replacement = Warehouse::query()->forAccount($accountId)->first();
            if ($replacement) {
                $replacement->update(['is_default' => true]);
            }
        }

        return response()->json([
            'message' => 'Warehouse deleted.',
        ]);
    }

    public function setDefault(Request $request, Warehouse $warehouse)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $accountId = $user->accountOwnerId();
        if ((int) $warehouse->user_id !== (int) $accountId) {
            abort(403);
        }

        Warehouse::query()->forAccount($accountId)->update(['is_default' => false]);
        $warehouse->update(['is_default' => true]);

        return response()->json([
            'warehouse' => $warehouse->fresh(),
        ]);
    }
}
