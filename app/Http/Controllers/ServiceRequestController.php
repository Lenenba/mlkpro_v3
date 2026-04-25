<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceRequests\StoreServiceRequestRequest;
use App\Services\Prospects\ProspectDuplicateAlertService;
use App\Services\ServiceRequests\ServiceRequestIntakeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ServiceRequestController extends Controller
{
    public function store(
        StoreServiceRequestRequest $request,
        ServiceRequestIntakeService $serviceRequestIntakeService
    ) {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $accountId = (int) ($user->accountOwnerId() ?? Auth::id());
        $this->ensureServiceRequestWriteAccess($user, $accountId);

        $validated = $request->validated();
        $ignoreDuplicates = (bool) ($validated['ignore_duplicates'] ?? false);

        if (
            ($validated['relation_mode'] ?? null) === StoreServiceRequestRequest::RELATION_MODE_NEW_PROSPECT
            && $this->shouldReturnJson($request)
            && ! $ignoreDuplicates
        ) {
            $duplicateAlert = app(ProspectDuplicateAlertService::class)->forAttributes(
                accountId: $accountId,
                attributes: $serviceRequestIntakeService->prospectAttributesForDuplicateCheck($validated),
                context: 'service_request_create',
            );

            if ($duplicateAlert) {
                return response()->json([
                    'message' => 'A similar prospect may already exist. Review the warning before continuing.',
                    'duplicate_alert' => $duplicateAlert,
                ], 409);
            }
        }

        $result = $serviceRequestIntakeService->createManual($user, $validated);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Service request created successfully.',
                'service_request' => $result['service_request'],
                'customer' => $result['customer'],
                'prospect' => $result['prospect'],
            ], 201);
        }

        return redirect()->back()->with('success', 'Service request created successfully.');
    }

    private function ensureServiceRequestWriteAccess($user, int $accountId): void
    {
        if (! $user) {
            abort(403);
        }

        if ((int) $user->id === $accountId) {
            return;
        }

        if (! $this->teamMemberCanManageProspects($user, $accountId)) {
            abort(403);
        }
    }
}
