<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', config('app.name'))</title>
</head>
@php
    $companyName = $companyName ?? config('app.name');
    $companyLogo = $companyLogo ?? null;
@endphp
<body style="margin:0; padding:0; background-color:#f1f5f9;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;">
        <tr>
            <td align="center" style="padding:24px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:600px; background-color:#ffffff; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; font-family:Arial, sans-serif; color:#0f172a;">
                    <tr>
                        <td style="padding:20px 24px; border-bottom:1px solid #e2e8f0; background-color:#ffffff;">
                            @if ($companyLogo)
                                <img src="{{ $companyLogo }}" alt="{{ $companyName }} logo" style="height:32px; width:auto; display:block;">
                            @else
                                <div style="font-size:16px; font-weight:700; color:#0f172a;">
                                    {{ $companyName }}
                                </div>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px; background-color:#f8fafc; font-size:12px; color:#64748b;">
                            {{ $companyName }} - {{ date('Y') }}. Tous droits reserves.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
