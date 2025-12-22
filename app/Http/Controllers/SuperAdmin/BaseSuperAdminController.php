<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class BaseSuperAdminController extends Controller
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

    protected function logAudit(Request $request, string $action, ?Model $subject = null, array $metadata = []): void
    {
        $user = $request->user();
        if (!$user) {
            return;
        }

        PlatformAuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
        ]);
    }
}
