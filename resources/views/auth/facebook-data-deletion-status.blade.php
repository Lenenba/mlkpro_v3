<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Facebook Data Deletion</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f7f7f5; color: #222; margin: 0; }
        .wrap { max-width: 680px; margin: 60px auto; background: #fff; border: 1px solid #ddd; padding: 28px; border-radius: 8px; }
        .pill { display: inline-block; padding: 6px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
        .pill-completed { background: #e7f7ee; color: #13653b; }
        .pill-failed { background: #fdecec; color: #a33a3a; }
        .pill-pending { background: #f3f0e6; color: #7a5b12; }
        h1 { margin: 0 0 12px; font-size: 24px; }
        p { margin: 0 0 10px; line-height: 1.55; }
        dl { margin: 20px 0 0; }
        dt { font-weight: 700; margin-top: 14px; }
        dd { margin: 4px 0 0; color: #4a4a4a; }
        code { font-family: Consolas, monospace; font-size: 13px; }
    </style>
</head>
<body>
    @php
        $status = (string) $deletionRequest->status;
        $pillClass = match ($status) {
            'completed' => 'pill pill-completed',
            'failed' => 'pill pill-failed',
            default => 'pill pill-pending',
        };
        $summary = is_array($deletionRequest->summary) ? $deletionRequest->summary : [];
    @endphp

    <main class="wrap">
        <span class="{{ $pillClass }}">{{ $status }}</span>
        <h1>Facebook data deletion request</h1>
        <p>This page confirms the status of the Facebook data deletion request linked to this confirmation code.</p>

        <dl>
            <dt>Confirmation code</dt>
            <dd><code>{{ $deletionRequest->confirmation_code }}</code></dd>

            <dt>Requested at</dt>
            <dd>{{ $deletionRequest->requested_at?->toDayDateTimeString() ?? 'Unavailable' }}</dd>

            <dt>Completed at</dt>
            <dd>{{ $deletionRequest->completed_at?->toDayDateTimeString() ?? 'Still processing' }}</dd>

            <dt>Facebook app-scoped user id</dt>
            <dd><code>{{ $deletionRequest->provider_user_id ?? 'Unavailable' }}</code></dd>

            <dt>Local account deletion</dt>
            <dd>{{ $deletionRequest->delete_local_account ? 'Enabled for this request' : 'Facebook data only' }}</dd>

            <dt>Deleted Facebook social login rows</dt>
            <dd>{{ (int) ($summary['deleted_facebook_social_accounts'] ?? 0) }}</dd>

            <dt>Deleted Facebook Pulse connections</dt>
            <dd>{{ (int) ($summary['deleted_facebook_social_connections'] ?? 0) }}</dd>

            @if (! empty($summary['deleted_local_account']))
                <dt>Deleted local account</dt>
                <dd>Yes{{ ! empty($summary['deleted_local_account_mode']) ? ' ('.$summary['deleted_local_account_mode'].')' : '' }}</dd>
            @endif

            @if ($status === 'failed' && ! empty($deletionRequest->failure_reason))
                <dt>Failure reason</dt>
                <dd>{{ $deletionRequest->failure_reason }}</dd>
            @endif
        </dl>
    </main>
</body>
</html>
