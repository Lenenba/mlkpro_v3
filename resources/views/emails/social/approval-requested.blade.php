<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? 'Pulse: post a valider' }}</title>
</head>
<body style="margin:0; padding:0; background:#f3f4f6; font-family:Arial, sans-serif; color:#1f2937;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        {{ $preheader ?? 'Apercu du post avant validation.' }}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:640px; max-width:100%;">
                    <tr>
                        <td style="padding:18px 20px; background:#ffffff; border:1px solid #e5e7eb;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="font-size:12px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#6b7280;">
                                        Pulse
                                    </td>
                                    <td align="right" style="font-size:12px; color:#6b7280;">
                                        {{ $companyName ?? config('app.name') }}
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2" style="padding-top:10px;">
                                        <div style="font-size:22px; line-height:1.25; font-weight:700; color:#111827;">
                                            Post a valider
                                        </div>
                                        <div style="margin-top:6px; font-size:14px; line-height:1.6; color:#4b5563;">
                                            @if (!empty($requestedBy))
                                                Envoye par {{ $requestedBy }}@if(!empty($requestedAt)), {{ $requestedAt }}@endif.
                                            @else
                                                Un post Pulse attend une decision.
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2" style="padding-top:14px;">
                                        <a href="{{ $approvalUrl }}" style="display:inline-block; padding:10px 14px; background:#111827; color:#ffffff; text-decoration:none; font-size:14px; font-weight:700;">
                                            Ouvrir la validation
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    @if (!empty($sourceLabel) || !empty($ruleName))
                        <tr>
                            <td style="padding-top:10px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff; border:1px solid #e5e7eb;">
                                    <tr>
                                        @if (!empty($sourceLabel))
                                            <td style="padding:12px 14px; font-size:12px; color:#6b7280;">
                                                Source<br>
                                                <span style="font-size:14px; font-weight:700; color:#111827;">{{ $sourceLabel }}</span>
                                            </td>
                                        @endif
                                        @if (!empty($ruleName))
                                            <td style="padding:12px 14px; font-size:12px; color:#6b7280; border-left:1px solid #e5e7eb;">
                                                Regle<br>
                                                <span style="font-size:14px; font-weight:700; color:#111827;">{{ $ruleName }}</span>
                                            </td>
                                        @endif
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    @foreach (($previews ?? []) as $preview)
                        <tr>
                            <td style="padding-top:12px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff; border:1px solid #d1d5db;">
                                    <tr>
                                        <td style="height:4px; background:{{ $preview['accent'] ?? '#111827' }}; line-height:4px; font-size:0;">&nbsp;</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:12px 14px; border-bottom:1px solid #e5e7eb;">
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                                <tr>
                                                    <td width="38" valign="top">
                                                        <div style="width:32px; height:32px; border-radius:50%; background:#111827; color:#ffffff; text-align:center; line-height:32px; font-size:13px; font-weight:700;">
                                                            {{ $preview['avatar_initial'] ?? 'P' }}
                                                        </div>
                                                    </td>
                                                    <td valign="top">
                                                        <div style="font-size:14px; font-weight:700; color:#111827;">
                                                            {{ $preview['account_name'] ?? $companyName }}
                                                        </div>
                                                        <div style="font-size:12px; color:#6b7280;">
                                                            {{ $preview['platform_label'] ?? 'Pulse' }} · {{ $preview['account_meta'] ?? '' }}
                                                        </div>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>

                                    @if (($preview['layout'] ?? '') === 'instagram' && !empty($preview['image_url']))
                                        <tr>
                                            <td>
                                                <img src="{{ $preview['image_url'] }}" alt="" style="display:block; width:100%; max-width:640px; height:auto; border:0;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding:14px; font-size:14px; line-height:1.55; color:#111827;">
                                                {!! nl2br(e($preview['text'] ?? '')) !!}
                                            </td>
                                        </tr>
                                    @else
                                        <tr>
                                            <td style="padding:14px; font-size:14px; line-height:1.55; color:#111827;">
                                                {!! nl2br(e($preview['text'] ?? '')) !!}
                                            </td>
                                        </tr>
                                        @if (!empty($preview['image_url']))
                                            <tr>
                                                <td style="padding:0 14px 14px;">
                                                    <img src="{{ $preview['image_url'] }}" alt="" style="display:block; width:100%; max-width:612px; height:auto; border:1px solid #e5e7eb;">
                                                </td>
                                            </tr>
                                        @endif
                                    @endif

                                    @if (!empty($preview['link_url']))
                                        <tr>
                                            <td style="padding:0 14px 14px;">
                                                <a href="{{ $preview['link_url'] }}" style="font-size:13px; color:#2563eb; text-decoration:none; word-break:break-all;">
                                                    {{ $preview['link_url'] }}
                                                </a>
                                            </td>
                                        </tr>
                                    @endif
                                </table>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
