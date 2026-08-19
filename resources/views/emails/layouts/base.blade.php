<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', config('app.name'))</title>
</head>
@php
    $companyName = trim((string) ($companyName ?? '')) ?: config('app.name');
    $companyLogo = is_string($companyLogo ?? null) ? trim($companyLogo) : null;
    $showPoweredBy = (bool) ($showPoweredBy ?? true);
    $platformName = 'Malikia Pro';
    $rawCompanyPrimaryColor = strtoupper(trim((string) ($companyPrimaryColor ?? '')));
    $rawCompanyPrimaryForegroundColor = strtoupper(trim((string) ($companyPrimaryForegroundColor ?? '')));
    $hasCompanyPrimaryColor = preg_match('/^#[0-9A-F]{6}$/', $rawCompanyPrimaryColor) === 1;
    $companyPrimaryColor = $hasCompanyPrimaryColor ? $rawCompanyPrimaryColor : '#16A34A';
    $companyPrimaryForegroundColor = preg_match('/^#[0-9A-F]{6}$/', $rawCompanyPrimaryForegroundColor) === 1
        ? $rawCompanyPrimaryForegroundColor
        : '#111827';
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
    $hasCustomCompanyLogo = filled($companyLogo) && ! str_contains($companyLogo, 'customers/customer.png');
    $resolvedCompanyLogo = $hasCustomCompanyLogo ? $resolveEmailImage($companyLogo) : null;
    $platformLogo = $resolveEmailImage('/brand/bimi-logo.svg');
    $headerLogo = $resolvedCompanyLogo ?: $platformLogo;
    $headerLogoStyle = $resolvedCompanyLogo
        ? 'max-height:44px; max-width:180px; width:auto; height:auto;'
        : 'height:36px; width:36px;';
    $headerLogoAlt = $resolvedCompanyLogo
        ? __('mail.layout.company_logo_alt', ['company' => $companyName])
        : __('mail.layout.platform_logo_alt', ['platform' => $platformName]);
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
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e2e8f0;{{ $hasCompanyPrimaryColor ? ' border-top:4px solid '.$companyPrimaryColor.';' : '' }} border-radius:3px;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td valign="middle" style="padding:0 14px 0 0;">
                                                    <img src="{{ $headerLogo }}" alt="{{ $headerLogoAlt }}" style="{{ $headerLogoStyle }} display:block;">
                                                </td>
                                                <td valign="middle">
                                                    <div style="font-size:18px; font-weight:700; line-height:1.35; color:#292524;">
                                                        {{ $companyName }}
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
                                        @if ($showPoweredBy)
                                            <div style="font-size:11px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:#78716c;">
                                                {{ __('mail.layout.powered_by', ['platform' => $platformName]) }}
                                            </div>
                                        @endif
                                        <div style="{{ $showPoweredBy ? 'margin-top:6px; ' : '' }}font-size:12px; color:#78716c;">
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
