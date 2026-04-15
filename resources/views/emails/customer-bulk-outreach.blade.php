<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $brandName }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f4;color:#292524;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:640px;margin:0 auto;padding:24px 16px;">
        <div style="background:#ffffff;border:1px solid #e7e5e4;border-radius:6px;overflow:hidden;">
            <div style="padding:24px 24px 12px 24px;border-bottom:1px solid #e7e5e4;">
                <div style="font-size:20px;font-weight:700;color:#1c1917;">{{ $brandName }}</div>
            </div>

            <div style="padding:24px;">
                <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#292524;">
                    Hello {{ $customerName }},
                </p>

                <div style="font-size:15px;line-height:1.7;color:#292524;">
                    {!! nl2br(e($body)) !!}
                </div>
            </div>

            <div style="padding:16px 24px 24px 24px;border-top:1px solid #e7e5e4;font-size:13px;line-height:1.6;color:#57534e;background:#fafaf9;">
                <div style="font-weight:600;color:#292524;">{{ $brandName }}</div>
                @if (!empty($replyToEmail))
                    <div>Email: {{ $replyToEmail }}</div>
                @endif
                @if (!empty($brandPhone))
                    <div>Phone: {{ $brandPhone }}</div>
                @endif
            </div>
        </div>
    </div>
</body>
</html>
