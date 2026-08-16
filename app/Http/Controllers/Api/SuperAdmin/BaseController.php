<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BaseController extends Controller
{
    protected function authorizeSuperadmin(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $user->isSuperadmin()) {
            abort(403);
        }
    }

    protected function authorizePermission(Request $request, string $permission): void
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        if ($user->isSuperadmin()) {
            return;
        }

        if (! $user->hasPlatformPermission($permission)) {
            abort(403);
        }
    }

    protected function jsonResponse(array $data, int $status = Response::HTTP_OK): Response
    {
        return response()->json($data, $status);
    }

    protected function logAudit(
        Request $request,
        string $action,
        Model|array|null $subject = null,
        array $metadata = [],
    ): void {
        if (is_array($subject)) {
            $metadata = $subject;
            $subject = null;
        }

        PlatformAuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
        ]);
    }
}
