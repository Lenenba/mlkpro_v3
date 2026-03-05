<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unsubscribed</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f7f7f5; color: #222; margin: 0; }
        .wrap { max-width: 640px; margin: 60px auto; background: #fff; border: 1px solid #ddd; padding: 28px; border-radius: 8px; }
        h1 { margin: 0 0 12px; font-size: 24px; }
        p { margin: 0 0 10px; line-height: 1.5; }
    </style>
</head>
<body>
    <main class="wrap">
        <h1>You are unsubscribed</h1>
        <p>Your email address was removed from this marketing campaign list.</p>
        @if(!empty($campaign?->name))
            <p><strong>Campaign:</strong> {{ $campaign->name }}</p>
        @endif
        <p>You can contact the business directly if this was a mistake.</p>
    </main>
</body>
</html>
