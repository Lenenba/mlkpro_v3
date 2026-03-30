@extends('emails.layouts.base')

@section('title', __('mail.auth.two_factor.title'))
@section('preheader', __('mail.auth.two_factor.preheader'))

@section('content')
    @php
        $heroIntro = trim(sprintf(
            '%s',
            __('mail.auth.two_factor.hero_intro', [
                'name' => $recipientName ?: __('mail.common.preview_recipient'),
            ])
        ));
    @endphp
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding-bottom:12px;">
                @include('emails.partials.structured-hero', [
                    'heroEyebrow' => __('mail.auth.two_factor.hero_eyebrow'),
                    'heroTitle' => __('mail.auth.two_factor.hero_title'),
                    'heroIntro' => $heroIntro,
                    'heroCaption' => __('mail.auth.two_factor.hero_caption'),
                    'heroSideTitle' => 'Code snapshot',
                    'heroSideRows' => [
                        ['label' => __('mail.common.platform'), 'value' => $companyName ?? 'Malikia Pro'],
                        ['label' => __('mail.auth.two_factor.code_title'), 'value' => $code],
                        ['label' => __('mail.common.expires'), 'value' => !empty($expiresInMinutes) ? $expiresInMinutes.' '.__('mail.common.minutes') : null],
                    ],
                    'heroMetrics' => [
                        ['value' => (string) strlen((string) $code), 'label' => app()->isLocale('fr') ? 'Chiffres' : 'Digits'],
                        ['value' => !empty($expiresInMinutes) ? (string) $expiresInMinutes : (app()->isLocale('fr') ? 'Maintenant' : 'Now'), 'label' => __('mail.common.minutes')],
                        ['value' => '2FA', 'label' => __('mail.common.secure')],
                    ],
                ])
            </td>
        </tr>

        <tr>
            <td style="padding-bottom:12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e2e8f0; border-radius:3px;">
                    <tr>
                        <td style="padding:18px 16px; text-align:center;">
                            <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                {{ __('mail.auth.two_factor.code_title') }}
                            </div>
                            <div style="margin-top:12px; display:inline-block; min-width:220px; padding:14px 18px; background-color:#f5f5f4; border:1px solid #e2e8f0; border-radius:3px; font-size:30px; font-weight:700; letter-spacing:0.28em; color:#0f172a; text-align:center;">
                                {{ $code }}
                            </div>
                            @if (!empty($expiresInMinutes))
                                <div style="margin-top:12px; font-size:13px; color:#57534e; line-height:1.7;">
                                    {!! str_replace(
                                        (string) $expiresInMinutes.' '.__('mail.common.minutes'),
                                        '<strong style="color:#292524;">'.$expiresInMinutes.' '.__('mail.common.minutes').'</strong>',
                                        e(__('mail.auth.two_factor.code_expires', ['minutes' => $expiresInMinutes]))
                                    ) !!}
                                </div>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr>
            <td style="font-size:12px; color:#57534e; line-height:1.7;">
                {{ __('mail.auth.two_factor.footer_notice') }}
            </td>
        </tr>
    </table>
@endsection
