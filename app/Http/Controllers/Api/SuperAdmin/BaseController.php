<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use App\Support\PlatformPermissions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BaseController extends Controller
{
    protected function authorizePermission(Request $request, string $permission): void
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }

        if ($user->isSuperadmin()) {
            return;
        }

        if (!$user->hasPlatformPermission($permission)) {
            abort(403);
        }
    }

    protected function jsonResponse(array $data, int $status = Response::HTTP_OK): Response
    {
        return response()->json($data, $status);
    }

    protected function logAudit(Request $request, string $action, array $metadata = []): void
    {
        PlatformAuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
        ]);
    }
}
