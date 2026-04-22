<?php

namespace App\Http\Controllers\E2E;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Work;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class FixtureResetController extends Controller
{
    public function quoteRecovery(): JsonResponse
    {
        abort_unless(app()->environment('e2e'), 404);

        $fixtures = $this->loadFixtures();
        $recovery = $fixtures['quoteRecovery'] ?? [];

        $dueQuoteId = (int) ($recovery['dueQuoteId'] ?? 0);
        $archiveQuoteId = (int) ($recovery['archiveQuoteId'] ?? 0);
        $acceptQuoteId = (int) ($recovery['acceptQuoteId'] ?? 0);
        $requestId = (int) ($recovery['requestId'] ?? 0);

        abort_unless(
            $dueQuoteId > 0
            && $archiveQuoteId > 0
            && $acceptQuoteId > 0
            && $requestId > 0,
            500,
            'Missing quote recovery fixture identifiers.'
        );

        DB::transaction(function () use ($dueQuoteId, $archiveQuoteId, $acceptQuoteId, $requestId): void {
            $dueQuote = Quote::query()->findOrFail($dueQuoteId);
            $archiveQuote = Quote::query()->findOrFail($archiveQuoteId);
            $acceptQuote = Quote::query()->findOrFail($acceptQuoteId);
            $lead = LeadRequest::query()->findOrFail($requestId);

            $existingWorkId = Work::query()
                ->where('quote_id', $acceptQuote->id)
                ->value('id');

            $dueQuote->update([
                'status' => 'sent',
                'accepted_at' => null,
                'signed_at' => null,
                'archived_at' => null,
                'next_follow_up_at' => now()->addHours(6),
                'follow_up_state' => 'due',
                'work_id' => null,
            ]);

            $archiveQuote->update([
                'status' => 'sent',
                'accepted_at' => null,
                'signed_at' => null,
                'archived_at' => null,
                'work_id' => null,
            ]);

            $acceptQuote->update([
                'status' => 'sent',
                'accepted_at' => null,
                'signed_at' => null,
                'archived_at' => null,
                'work_id' => $existingWorkId,
            ]);

            $lead->update([
                'status' => LeadRequest::STATUS_QUOTE_SENT,
                'status_updated_at' => now(),
                'lost_reason' => null,
            ]);
        });

        return response()->json([
            'message' => 'Quote recovery fixtures reset.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixtures(): array
    {
        $fixturePath = storage_path('app/e2e-fixtures.json');

        abort_unless(File::exists($fixturePath), 500, 'Missing E2E fixtures file.');

        $fixtures = json_decode((string) File::get($fixturePath), true);

        abort_unless(is_array($fixtures), 500, 'Invalid E2E fixtures file.');

        return $fixtures;
    }
}
