<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyProgram;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoyaltySettingsController extends Controller
{
    public function edit(Request $request)
    {
        $accountId = $this->ownerAccountIdOrFail($request);
        $program = $this->programForAccount($accountId);

        return $this->inertiaOrJson('Settings/Loyalty', [
            'loyaltyProgram' => $this->serializeProgram($program),
        ]);
    }

    public function update(Request $request)
    {
        $accountId = $this->ownerAccountIdOrFail($request);

        $validated = $request->validate([
            'is_enabled' => 'nullable|boolean',
            'points_per_currency_unit' => 'nullable|numeric|min:0.0001|max:1000',
            'minimum_spend' => 'nullable|numeric|min:0|max:1000000',
            'rounding_mode' => ['nullable', Rule::in([
                LoyaltyProgram::ROUND_FLOOR,
                LoyaltyProgram::ROUND_ROUND,
                LoyaltyProgram::ROUND_CEIL,
            ])],
            'points_label' => 'nullable|string|max:40',
        ]);

        $program = $this->programForAccount($accountId);

        $program->is_enabled = array_key_exists('is_enabled', $validated)
            ? (bool) $validated['is_enabled']
            : (bool) $program->is_enabled;

        $program->points_per_currency_unit = array_key_exists('points_per_currency_unit', $validated)
            ? (float) $validated['points_per_currency_unit']
            : (float) $program->points_per_currency_unit;

        $program->minimum_spend = array_key_exists('minimum_spend', $validated)
            ? (float) $validated['minimum_spend']
            : (float) $program->minimum_spend;

        $program->rounding_mode = array_key_exists('rounding_mode', $validated)
            ? (string) $validated['rounding_mode']
            : (string) $program->rounding_mode;

        $pointsLabel = array_key_exists('points_label', $validated)
            ? trim((string) $validated['points_label'])
            : trim((string) $program->points_label);
        $program->points_label = $pointsLabel !== '' ? $pointsLabel : 'points';

        if ($program->points_per_currency_unit <= 0) {
            $program->points_per_currency_unit = 1;
        }

        if ($program->minimum_spend < 0) {
            $program->minimum_spend = 0;
        }

        if (!in_array($program->rounding_mode, [
            LoyaltyProgram::ROUND_FLOOR,
            LoyaltyProgram::ROUND_ROUND,
            LoyaltyProgram::ROUND_CEIL,
        ], true)) {
            $program->rounding_mode = LoyaltyProgram::ROUND_FLOOR;
        }

        $program->save();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Loyalty settings updated.',
                'loyaltyProgram' => $this->serializeProgram($program),
            ]);
        }

        return redirect()->back()->with('success', 'Parametres fidelite enregistres.');
    }

    private function ownerAccountIdOrFail(Request $request): int
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $accountId = $user->accountOwnerId();
        if ((int) $user->id !== (int) $accountId) {
            abort(403);
        }

        return (int) $accountId;
    }

    private function programForAccount(int $accountId): LoyaltyProgram
    {
        return LoyaltyProgram::query()->firstOrCreate(
            ['user_id' => $accountId],
            [
                'is_enabled' => true,
                'points_per_currency_unit' => 1,
                'minimum_spend' => 0,
                'rounding_mode' => LoyaltyProgram::ROUND_FLOOR,
                'points_label' => 'points',
            ]
        );
    }

    private function serializeProgram(LoyaltyProgram $program): array
    {
        return [
            'is_enabled' => (bool) $program->is_enabled,
            'points_per_currency_unit' => (float) $program->points_per_currency_unit,
            'minimum_spend' => (float) $program->minimum_spend,
            'rounding_mode' => (string) $program->rounding_mode,
            'points_label' => (string) ($program->points_label ?: 'points'),
        ];
    }
}
