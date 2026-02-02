<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ShiftTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HrSettingsController extends Controller
{
    private const WEEK_DAYS = ['su', 'mo', 'tu', 'we', 'th', 'fr', 'sa'];

    public function edit(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $accountId = $user->id;
        $templates = ShiftTemplate::query()
            ->where(function ($query) use ($accountId) {
                $query->whereNull('account_id')
                    ->orWhere('account_id', $accountId);
            })
            ->orderBy('position_title')
            ->get();

        $payload = $templates->map(fn (ShiftTemplate $template) => $this->formatTemplatePayload($template, $accountId));

        return $this->inertiaOrJson('Settings/Hr', [
            'templates' => $payload,
            'default_template' => $this->defaultTemplate(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $payload = $this->validateTemplate($request);
        $accountId = $user->id;

        $this->ensureUniquePosition($accountId, $payload['position_title']);

        $template = ShiftTemplate::create([
            'account_id' => $accountId,
            'created_by_user_id' => $user->id,
            'position_title' => $payload['position_title'],
            'start_time' => $payload['start_time'],
            'end_time' => $payload['end_time'],
            'break_minutes' => $payload['break_minutes'],
            'breaks' => $payload['breaks'],
            'days_of_week' => $payload['days_of_week'],
            'is_active' => $payload['is_active'],
        ]);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Shift template created.',
                'template' => $this->formatTemplatePayload($template, $accountId),
            ], 201);
        }

        return redirect()->back()->with('success', 'Shift template created.');
    }

    public function update(Request $request, ShiftTemplate $template)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $accountId = $user->id;
        if ($template->account_id !== $accountId) {
            abort(403);
        }

        $payload = $this->validateTemplate($request);
        $this->ensureUniquePosition($accountId, $payload['position_title'], $template->id);

        $template->update([
            'position_title' => $payload['position_title'],
            'start_time' => $payload['start_time'],
            'end_time' => $payload['end_time'],
            'break_minutes' => $payload['break_minutes'],
            'breaks' => $payload['breaks'],
            'days_of_week' => $payload['days_of_week'],
            'is_active' => $payload['is_active'],
        ]);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Shift template updated.',
                'template' => $this->formatTemplatePayload($template->fresh(), $accountId),
            ]);
        }

        return redirect()->back()->with('success', 'Shift template updated.');
    }

    public function destroy(Request $request, ShiftTemplate $template)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $accountId = $user->id;
        if ($template->account_id !== $accountId) {
            abort(403);
        }

        $template->delete();

        if ($this->shouldReturnJson($request)) {
            return response()->json(['deleted' => true]);
        }

        return redirect()->back()->with('success', 'Shift template deleted.');
    }

    private function validateTemplate(Request $request): array
    {
        $validated = $request->validate([
            'position_title' => 'required|string|max:120',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'breaks' => 'nullable|array',
            'breaks.*' => 'nullable|integer|min:1|max:60',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => ['string', Rule::in(self::WEEK_DAYS)],
            'is_active' => 'nullable|boolean',
        ]);

        $positionTitle = trim($validated['position_title']);
        $start = Carbon::createFromFormat('H:i', $validated['start_time']);
        $end = Carbon::createFromFormat('H:i', $validated['end_time']);
        if (!$start || !$end || $end->lte($start)) {
            throw ValidationException::withMessages([
                'end_time' => ['L heure de fin doit etre apres l heure de debut.'],
            ]);
        }

        $breaks = $this->normalizeBreaks($validated['breaks'] ?? null);
        $breakTotal = array_sum($breaks);
        if ($breakTotal > 60) {
            throw ValidationException::withMessages([
                'breaks' => ['La pause totale ne peut pas depasser 60 minutes.'],
            ]);
        }

        $days = $this->normalizeDays($validated['days_of_week'] ?? null);

        return [
            'position_title' => $positionTitle,
            'start_time' => $start->format('H:i:s'),
            'end_time' => $end->format('H:i:s'),
            'breaks' => $breaks ?: null,
            'break_minutes' => $breakTotal,
            'days_of_week' => $days ?: null,
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
        ];
    }

    private function ensureUniquePosition(int $accountId, string $positionTitle, ?int $ignoreId = null): void
    {
        $query = ShiftTemplate::query()
            ->where('account_id', $accountId)
            ->whereRaw('LOWER(position_title) = ?', [strtolower($positionTitle)]);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'position_title' => ['Un template existe deja pour ce poste.'],
            ]);
        }
    }

    private function normalizeBreaks(?array $breaks): array
    {
        if (!is_array($breaks)) {
            return [];
        }

        return collect($breaks)
            ->map(fn ($value) => (int) $value)
            ->filter(fn ($value) => $value > 0)
            ->values()
            ->all();
    }

    private function normalizeDays(?array $days): array
    {
        if (!is_array($days)) {
            return [];
        }

        return collect($days)
            ->map(fn ($day) => strtolower(trim((string) $day)))
            ->filter(fn ($day) => in_array($day, self::WEEK_DAYS, true))
            ->unique()
            ->values()
            ->all();
    }

    private function formatTemplatePayload(ShiftTemplate $template, int $accountId): array
    {
        $breaks = is_array($template->breaks) ? $template->breaks : [];
        $breakTotal = (int) ($template->break_minutes ?? 0);
        if ($breakTotal <= 0 && $breaks) {
            $breakTotal = array_sum($breaks);
        }
        if (!$breaks && $breakTotal > 0) {
            $breaks = [$breakTotal];
        }

        return [
            'id' => $template->id,
            'position_title' => $template->position_title,
            'start_time' => substr((string) $template->start_time, 0, 5),
            'end_time' => substr((string) $template->end_time, 0, 5),
            'breaks' => $breaks,
            'break_minutes' => $breakTotal,
            'days_of_week' => $template->days_of_week ?? [],
            'is_active' => (bool) $template->is_active,
            'is_global' => $template->account_id === null,
            'is_override' => $template->account_id === $accountId,
        ];
    }

    private function defaultTemplate(): array
    {
        return [
            'start_time' => '09:00',
            'end_time' => '17:00',
            'break_minutes' => 60,
            'days_of_week' => [],
        ];
    }
}
