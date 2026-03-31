@extends('emails.layouts.base')

@section('title', __('mail.auth.reset_password.title'))
@section('preheader', __('mail.auth.reset_password.preheader'))

@section('content')
    @php
        $heroIntro = trim(sprintf(
            '%s',
            __('mail.auth.reset_password.hero_intro', [
                'name' => $recipientName ?: __('mail.common.preview_recipient'),
            ])
        ));
    @endphp
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding-bottom:12px;">
                @include('emails.partials.structured-hero', [
                    'heroEyebrow' => __('mail.auth.reset_password.hero_eyebrow'),
                    'heroTitle' => __('mail.auth.reset_password.hero_title'),
                    'heroIntro' => $heroIntro,
                    'heroActionUrl' => $resetUrl,
                    'heroActionLabel' => __('mail.auth.reset_password.hero_action'),
                    'heroCaption' => __('mail.auth.reset_password.hero_caption'),
                    'heroSideTitle' => __('mail.auth.reset_password.title'),
                    'heroSideRows' => [
                        ['label' => __('mail.common.platform'), 'value' => $companyName ?? 'Malikia Pro'],
                        ['label' => __('mail.common.expires'), 'value' => $expiresInMinutes.' '.__('mail.common.minutes')],
                        ['label' => app()->isLocale('fr') ? 'Lien' : 'Link', 'value' => app()->isLocale('fr') ? 'Usage unique' : 'Single use'],
                    ],
                    'heroMetrics' => [
                        ['value' => (string) $expiresInMinutes, 'label' => __('mail.common.minutes')],
                        ['value' => '1', 'label' => app()->isLocale('fr') ? 'Lien' : 'Link'],
                        ['value' => __('mail.common.safe'), 'label' => __('mail.common.access')],
                    ],
                ])
            </td>
        </tr>

        <tr>
            <td style="padding-bottom:12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e2e8f0; border-radius:3px;">
                    <tr>
                        <td style="padding:16px;">
                            <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                {{ __('mail.auth.reset_password.secure_link_title') }}
                            </div>
                            <div style="margin-top:8px; font-size:13px; color:#57534e; line-height:1.7;">
                                {!! str_replace(
                                    (string) $expiresInMinutes.' '.__('mail.common.minutes'),
                                    '<strong style="color:#292524;">'.$expiresInMinutes.' '.__('mail.common.minutes').'</strong>',
                                    e(__('mail.auth.reset_password.secure_link_body', ['minutes' => $expiresInMinutes]))
                                ) !!}
                            </div>
                            <div style="margin-top:10px; padding-top:10px; border-top:1px solid #e2e8f0; font-size:12px; color:#57534e; line-height:1.7; word-break:break-all;">
                                {{ $resetUrl }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr>
            <td style="font-size:12px; color:#57534e; line-height:1.7;">
                {{ __('mail.auth.reset_password.fallback_notice') }}
            </td>
        </tr>
    </table>
@endsection
