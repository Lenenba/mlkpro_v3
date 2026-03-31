@extends('emails.layouts.base')

@section('title', __('mail.welcome.title', ['company' => $companyName ?? config('app.name')]))
@section('preheader', __('mail.welcome.preheader'))

@section('content')
    @php
        $quickStepCount = count($quickSteps ?? []);
        $highlightCount = count($highlights ?? []);
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
        $hasCustomCompanyLogo = is_string($companyLogo ?? null) && $companyLogo !== '' && ! str_contains($companyLogo, 'customers/customer.png');
        $resolvedCompanyLogo = $hasCustomCompanyLogo ? $resolveEmailImage($companyLogo) : null;
        $workspaceMode = ($companyTypeLabel ?? 'services') === 'produits'
            ? __('mail.welcome.mode_products')
            : __('mail.welcome.mode_services');
    @endphp
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding-bottom:12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;">
                    <tr>
                        <td valign="top" width="62%" style="background-color:#0d3137; background-image:radial-gradient(circle at top center, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0) 24%), linear-gradient(135deg, #0d1d35 0%, #0d3137 48%, #0d5a46 100%); padding:24px 22px; border-radius:3px 0 0 3px;">
                            <div style="font-size:12px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:#a7f3d0;">
                                {{ __('mail.welcome.hero_eyebrow') }}
                            </div>
                            <div style="margin-top:12px; font-size:32px; font-weight:700; line-height:0.96; letter-spacing:-0.04em; color:#f8fafc; max-width:320px;">
                                {!! nl2br(e(__('mail.welcome.hero_title', ['company' => $companyName ?? config('app.name')]))) !!}
                            </div>
                            <div style="margin-top:14px; width:92px; height:4px; background-color:#2dd4bf;">&nbsp;</div>
                            <div style="margin-top:14px; font-size:14px; line-height:1.8; color:rgba(236, 253, 245, 0.88);">
                                {{ __('mail.welcome.hero_intro', [
                                    'name' => $userName ?? __('mail.common.preview_recipient'),
                                    'company' => $companyName ?? config('app.name'),
                                ]) }}
                            </div>
                            <div style="margin-top:10px; font-size:13px; line-height:1.7; color:rgba(236, 253, 245, 0.72);">
                                {{ ($companyTypeLabel ?? 'services') === 'produits'
                                    ? __('mail.welcome.hero_caption_products')
                                    : __('mail.welcome.hero_caption_services') }}
                            </div>
                            @if (!empty($actionUrl))
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:18px;">
                                    <tr>
                                        <td bgcolor="#1f2937" style="border-radius:3px;">
                                            <a href="{{ $actionUrl }}" style="display:inline-block; padding:10px 16px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none;">
                                                {{ __('mail.welcome.hero_action') }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            @endif
                            <div style="margin-top:18px; font-size:12px; color:rgba(226, 232, 240, 0.72); line-height:1.7;">
                                {{ __('mail.welcome.hero_note') }}
                            </div>
                        </td>
                        <td valign="top" width="38%" style="background-color:#eef2f7; padding:18px; border-radius:0 3px 3px 0; border:1px solid #dbe4ee; border-left:0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #dbe4ee; border-radius:3px;">
                                <tr>
                                    <td style="padding:14px 14px 12px;">
                                        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                            {{ __('mail.welcome.profile_title') }}
                                        </div>
                                        @if ($resolvedCompanyLogo)
                                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:12px;">
                                                <tr>
                                                    <td style="padding:0;">
                                                        <img src="{{ $resolvedCompanyLogo }}" alt="{{ $companyName }} logo" style="max-height:40px; max-width:170px; width:auto; display:block;">
                                                    </td>
                                                </tr>
                                            </table>
                                        @endif
                                        <div style="margin-top:12px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                            {{ __('mail.common.company') }}
                                        </div>
                                        <div style="margin-top:4px; font-size:13px; color:#292524; line-height:1.5;">
                                            {{ $companyName ?? config('app.name') }}
                                        </div>
                                        <div style="margin-top:10px; padding-top:10px; border-top:1px solid #e2e8f0; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                            {{ __('mail.welcome.mode_label') }}
                                        </div>
                                        <div style="margin-top:4px; font-size:13px; color:#292524; line-height:1.5;">
                                            {{ $workspaceMode }}
                                        </div>
                                        <div style="margin-top:10px; padding-top:10px; border-top:1px solid #e2e8f0; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                            {{ __('mail.welcome.support_label') }}
                                        </div>
                                        <div style="margin-top:4px; font-size:13px; color:#292524; line-height:1.5; word-break:break-all;">
                                            {{ $supportEmail ?? 'support' }}
                                        </div>
                                    </td>
                                </tr>
                            </table>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;">
                                <tr>
                                    <td width="33.33%" style="padding-right:4px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:rgba(255,255,255,0.78); border:1px solid #dbe4ee; border-radius:3px;">
                                            <tr>
                                                <td style="padding:12px 8px; text-align:center;">
                                                    <div style="font-size:18px; font-weight:700; color:#0f172a;">{{ max($quickStepCount, 3) }}</div>
                                                    <div style="margin-top:4px; font-size:10px; color:#57534e; line-height:1.4;">{{ __('mail.common.actions') }}</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td width="33.33%" style="padding-left:2px; padding-right:2px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:rgba(255,255,255,0.78); border:1px solid #dbe4ee; border-radius:3px;">
                                            <tr>
                                                <td style="padding:12px 8px; text-align:center;">
                                                    <div style="font-size:18px; font-weight:700; color:#0f172a;">1</div>
                                                    <div style="margin-top:4px; font-size:10px; color:#57534e; line-height:1.4;">{{ __('mail.common.workspace') }}</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td width="33.33%" style="padding-left:4px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:rgba(255,255,255,0.78); border:1px solid #dbe4ee; border-radius:3px;">
                                            <tr>
                                                <td style="padding:12px 8px; text-align:center;">
                                                    <div style="font-size:18px; font-weight:700; color:#0f172a;">24/7</div>
                                                    <div style="margin-top:4px; font-size:10px; color:#57534e; line-height:1.4;">{{ __('mail.common.access') }}</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr>
            <td style="padding-bottom:12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td valign="top" width="58%" style="padding-right:6px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e7e5e4; border-radius:3px;">
                                <tr>
                                    <td style="padding:16px 16px 8px; font-size:16px; font-weight:600; color:#292524;">
                                        {{ __('mail.welcome.quick_start_title') }}
                                    </td>
                                </tr>
                                @foreach (($quickSteps ?? []) as $step)
                                    <tr>
                                        <td style="padding:0 16px 10px;">
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:3px;">
                                                <tr>
                                                    <td style="padding:12px;">
                                                        <div style="font-size:11px; letter-spacing:0.08em; text-transform:uppercase; color:#78716c; font-weight:600;">
                                                            {{ __('mail.welcome.step_label', ['number' => $loop->iteration]) }}
                                                        </div>
                                                        <div style="margin-top:6px; font-size:14px; font-weight:600; color:#292524; line-height:1.5;">
                                                            {{ $step }}
                                                        </div>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endforeach
                                @if (empty($quickSteps))
                                    <tr>
                                        <td style="padding:0 16px 16px; font-size:13px; color:#57534e; line-height:1.7;">
                                            {{ __('mail.welcome.quick_start_fallback') }}
                                        </td>
                                    </tr>
                                @endif
                            </table>
                        </td>
                        <td valign="top" width="42%" style="padding-left:6px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e7e5e4; border-radius:3px;">
                                <tr>
                                    <td style="padding:16px;">
                                        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                            {{ __('mail.welcome.highlights_title') }}
                                        </div>
                                        @foreach (($highlights ?? []) as $highlight)
                                            <div style="margin-top:{{ $loop->first ? '12px' : '10px' }}; font-size:13px; color:#57534e; line-height:1.7;">
                                                <span style="color:#16a34a; font-weight:700;">&#8226;</span>
                                                <span style="padding-left:6px;">{{ $highlight }}</span>
                                            </div>
                                        @endforeach
                                        @if (empty($highlights))
                                            <div style="margin-top:12px; font-size:13px; color:#57534e; line-height:1.7;">
                                                {{ __('mail.welcome.highlights_fallback') }}
                                            </div>
                                        @endif
                                        <div style="margin-top:14px; padding-top:14px; border-top:1px solid #e7e5e4; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                            {{ __('mail.welcome.support_label') }}
                                        </div>
                                        <div style="margin-top:6px; font-size:13px; color:#57534e; line-height:1.7;">
                                            {{ __('mail.welcome.support_copy') }}
                                        </div>
                                        <div style="margin-top:10px; font-size:13px; font-weight:600; color:#0f172a; line-height:1.6; word-break:break-all;">
                                            {{ $supportEmail ?? 'support' }}
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px; background-color:#f5f5f4; border:1px solid #e7e5e4; border-top:3px solid #16a34a; border-radius:3px;">
                                <tr>
                                    <td style="padding:14px 16px;">
                                        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                            {{ __('mail.welcome.activation_title') }}
                                        </div>
                                        <div style="margin-top:8px; font-size:13px; color:#57534e; line-height:1.7;">
                                            {{ __('mail.welcome.activation_copy', [
                                                'steps' => max($quickStepCount, 3),
                                                'highlights' => max($highlightCount, 3),
                                            ]) }}
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
            <td style="font-size:12px; color:#57534e; line-height:1.7;">
                {{ __('mail.welcome.footer_help', ['support' => $supportEmail ?? 'support']) }}
            </td>
        </tr>
    </table>
@endsection
