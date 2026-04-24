<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Facebook Data Deletion Callback</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f7f7f5; color: #222; margin: 0; }
        .wrap { max-width: 700px; margin: 60px auto; background: #fff; border: 1px solid #ddd; padding: 28px; border-radius: 8px; }
        .pill { display: inline-block; padding: 6px 10px; border-radius: 999px; background: #edf4ff; color: #2457a6; font-size: 12px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
        h1 { margin: 14px 0 12px; font-size: 24px; }
        p { margin: 0 0 10px; line-height: 1.55; }
        dl { margin: 20px 0 0; }
        dt { font-weight: 700; margin-top: 14px; }
        dd { margin: 4px 0 0; color: #4a4a4a; }
        code { font-family: Consolas, monospace; font-size: 13px; }
        a { color: #2457a6; }
    </style>
</head>
<body>
    <main class="wrap">
        <span class="pill">Facebook Callback</span>
        <h1>Facebook data deletion endpoint</h1>
        <p>{{ $message }}</p>
        <p>If you open this URL in a browser, a normal <code>GET</code> request is used. Meta sends a <code>POST</code> request with a <code>signed_request</code> payload when a real deletion request happens.</p>

        <dl>
            <dt>Expected method</dt>
            <dd><code>{{ $expected_method }}</code></dd>

            <dt>Expected parameter</dt>
            <dd><code>{{ $expected_parameter }}</code></dd>

            <dt>Status URL pattern</dt>
            <dd><code>{{ $status_url_pattern }}</code></dd>

            <dt>Privacy policy URL</dt>
            <dd><a href="{{ $privacy_policy_url }}">{{ $privacy_policy_url }}</a></dd>
        </dl>
    </main>
</body>
</html>
