@extends('emails.layouts.base')

@section('title', $title ?? 'Notification')

@section('content')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding-bottom:16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                    <tr>
                        <td style="padding:16px;">
                            <div style="font-size:18px; font-weight:700; color:#0f172a;">
                                {{ $title ?? 'Notification' }}
                            </div>
                            @if (!empty($intro))
                                <div style="margin-top:6px; font-size:13px; color:#475569; line-height:1.5;">
                                    {{ $intro }}
                                </div>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        @if (!empty($details))
            <tr>
                <td style="padding-bottom:16px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e2e8f0; border-radius:6px;">
                        <tr>
                            <td style="padding:12px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                    @foreach ($details as $detail)
                                        <tr style="border-top:1px solid #e2e8f0;">
                                            <td style="padding:8px; font-size:12px; color:#64748b;">
                                                {{ $detail['label'] ?? 'Detail' }}
                                            </td>
                                            <td align="right" style="padding:8px; font-size:12px; color:#0f172a; font-weight:600;">
                                                {{ $detail['value'] ?? '-' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endif

        @if (!empty($note))
            <tr>
                <td style="padding-bottom:16px; font-size:12px; color:#64748b;">
                    {{ $note }}
                </td>
            </tr>
        @endif

        @if (!empty($actionUrl))
            <tr>
                <td>
                    <table role="presentation" cellpadding="0" cellspacing="0">
                        <tr>
                            <td bgcolor="#16a34a" style="border-radius:6px;">
                                <a href="{{ $actionUrl }}" style="display:inline-block; padding:10px 16px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
                                    {{ $actionLabel ?? 'Open' }}
                                </a>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endif
    </table>
@endsection
