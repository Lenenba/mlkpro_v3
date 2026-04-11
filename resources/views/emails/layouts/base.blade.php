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
    $platformName = 'Malikia Pro';
    $resolveEmailImage = static function (?string $path): ?string {
        if (blank($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '//')) {
            return 'https:'.$path;
        }

        return url($path);
    };
    $hasCustomCompanyLogo = is_string($companyLogo) && $companyLogo !== '' && ! str_contains($companyLogo, 'customers/customer.png');
    $resolvedCompanyLogo = $hasCustomCompanyLogo ? $resolveEmailImage($companyLogo) : null;
    $platformLogo = $resolveEmailImage('/2.svg');
    $platformMarkLogo = $resolveEmailImage('/brand/bimi-logo.svg');
@endphp
<body style="margin:0; padding:0; background-color:#f5f5f4;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; mso-hide:all;">
        @yield('preheader', __('mail.layout.preheader', ['company' => $companyName, 'platform' => $platformName]))
    </div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef2f7;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:640px; max-width:640px; font-family:Arial, sans-serif; color:#292524;">
                    <tr>
                        <td style="padding-bottom:12px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e2e8f0; border-radius:3px;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td valign="middle">
                                                    @if ($resolvedCompanyLogo)
                                                        <table role="presentation" cellpadding="0" cellspacing="0">
                                                            <tr>
                                                                <td style="padding:0;">
                                                                    <img src="{{ $resolvedCompanyLogo }}" alt="{{ $companyName }} logo" style="max-height:36px; max-width:160px; width:auto; display:block;">
                                                                </td>
                                                            </tr>
                                                        </table>
                                                    @else
                                                        <table role="presentation" cellpadding="0" cellspacing="0">
                                                            <tr>
                                                                <td style="padding:0;">
                                                                    <img src="{{ $platformLogo }}" alt="{{ $platformName }} logo" style="max-height:36px; max-width:160px; width:auto; display:block;">
                                                                </td>
                                                            </tr>
                                                        </table>
                                                    @endif
                                                </td>
                                                <td align="right" valign="middle">
                                                    <img src="{{ $platformMarkLogo }}" alt="{{ $platformName }} mark" style="height:22px; width:22px; display:block; margin-left:auto;">
                                                    <div style="font-size:11px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:#78716c;">
                                                        {{ $platformName }}
                                                    </div>
                                                    <div style="margin-top:4px; font-size:12px; line-height:1.5; color:#57534e;">
                                                        {{ __('mail.layout.platform_tagline') }}
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-top:12px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e2e8f0; border-radius:3px;">
                                <tr>
                                    <td style="padding:14px 16px 16px;">
                                        <div style="font-size:11px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:#78716c;">
                                            {{ __('mail.layout.powered_by', ['platform' => $platformName]) }}
                                        </div>
                                        <div style="margin-top:6px; font-size:12px; line-height:1.6; color:#57534e;">
                                            {{ __('mail.layout.footer_blurb', ['company' => $companyName, 'platform' => $platformName]) }}
                                        </div>
                                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:12px;">
                                            <tr>
                                                <td style="background-color:#f5f5f4; border:1px solid #e2e8f0; border-radius:3px; padding:5px 8px; font-size:11px; font-weight:600; color:#44403c;">
                                                    {{ __('mail.layout.pill_sales') }}
                                                </td>
                                                <td style="width:6px;">&nbsp;</td>
                                                <td style="background-color:#f5f5f4; border:1px solid #e2e8f0; border-radius:3px; padding:5px 8px; font-size:11px; font-weight:600; color:#44403c;">
                                                    {{ __('mail.layout.pill_operations') }}
                                                </td>
                                                <td style="width:6px;">&nbsp;</td>
                                                <td style="background-color:#f5f5f4; border:1px solid #e2e8f0; border-radius:3px; padding:5px 8px; font-size:11px; font-weight:600; color:#44403c;">
                                                    {{ __('mail.layout.pill_client_experience') }}
                                                </td>
                                            </tr>
                                        </table>
                                        <div style="margin-top:12px; font-size:12px; color:#78716c;">
                                            {{ __('mail.layout.all_rights_reserved', ['company' => $companyName, 'year' => date('Y')]) }}
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
