@extends('emails.layouts.base')

@section('title', __('mail.auth.invite.title', ['company' => $companyName ?? config('app.name')]))
@section('preheader', __('mail.auth.invite.preheader'))

@section('content')
    @php
        $heroIntro = trim(sprintf(
            '%s',
            __('mail.auth.invite.hero_intro', [
                'name' => $recipientName ?: __('mail.common.preview_recipient'),
                'role' => $roleLabel ?? __('mail.auth.invite.role_team_member'),
                'company' => $companyName ?? config('app.name'),
            ])
        ));
    @endphp
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding-bottom:12px;">
                @include('emails.partials.structured-hero', [
                    'heroEyebrow' => __('mail.auth.invite.hero_eyebrow'),
                    'heroTitle' => __('mail.auth.invite.hero_title'),
                    'heroIntro' => $heroIntro,
                    'heroActionUrl' => $actionUrl,
                    'heroActionLabel' => __('mail.auth.invite.hero_action'),
                    'heroCaption' => __('mail.auth.invite.hero_caption'),
                    'heroSideTitle' => app()->isLocale('fr') ? 'Acces snapshot' : 'Access snapshot',
                    'heroSideLogo' => $companyLogo ?? null,
                    'heroSideRows' => [
                        ['label' => __('mail.common.company'), 'value' => $companyName ?? config('app.name')],
                        ['label' => app()->isLocale('fr') ? 'Role' : 'Role', 'value' => ucfirst($roleLabel ?? __('mail.auth.invite.role_team_member'))],
                        ['label' => __('mail.common.expires'), 'value' => ($expires ?? 60).' '.__('mail.common.minutes')],
                    ],
                    'heroMetrics' => [
                        ['value' => '1', 'label' => __('mail.common.access')],
                        ['value' => (string) ($expires ?? 60), 'label' => __('mail.common.minutes')],
                        ['value' => __('mail.common.setup'), 'label' => __('mail.common.secure')],
                    ],
                ])
            </td>
        </tr>

        <tr>
            <td style="padding-bottom:12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e7e5e4; border-radius:3px;">
                    <tr>
                        <td style="padding:16px;">
                            <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                {{ __('mail.auth.invite.activation_title') }}
                            </div>
                            <div style="margin-top:8px; font-size:13px; color:#57534e; line-height:1.7;">
                                {!! str_replace(
                                    (string) ($expires ?? 60).' '.__('mail.common.minutes'),
                                    '<strong style="color:#292524;">'.($expires ?? 60).' '.__('mail.common.minutes').'</strong>',
                                    e(__('mail.auth.invite.activation_body', ['minutes' => $expires ?? 60]))
                                ) !!}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr>
            <td style="font-size:12px; color:#57534e; line-height:1.7;">
                {{ __('mail.auth.invite.footer_help') }}
            </td>
        </tr>
    </table>
@endsection
