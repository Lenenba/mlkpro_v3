@extends('emails.layouts.base')

@section('title', __('mail.demo_access.title'))
@section('preheader', __('mail.demo_access.preheader'))

@section('content')
    @php
        $moduleCount = count($moduleLabels ?? []);
        $scenarioCount = count($scenarioLabels ?? []);
        $roleCount = 1 + count($extraCredentials ?? []);
    @endphp
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding-bottom:12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;">
                    <tr>
                        <td valign="top" width="62%" style="background-color:#0d3137; background-image:radial-gradient(circle at top center, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0) 24%), linear-gradient(135deg, #0d1d35 0%, #0d3137 48%, #0d5a46 100%); padding:24px 22px; border-radius:3px 0 0 3px;">
                            <div style="font-size:12px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:#a7f3d0;">
                                {{ __('mail.common.demo_workspace') }}
                            </div>
                            <div style="margin-top:12px; font-size:32px; font-weight:700; line-height:0.96; letter-spacing:-0.04em; color:#f8fafc; max-width:260px;">
                                {{ $workspaceName }}
                            </div>
                            <div style="margin-top:14px; width:92px; height:4px; background-color:#2dd4bf;">&nbsp;</div>
                            <div style="margin-top:14px; font-size:14px; color:rgba(236, 253, 245, 0.88); line-height:1.7;">
                                {{ !empty($prospectCompany)
                                    ? __('mail.demo_access.hero_intro', [
                                        'name' => $recipientName ?: __('mail.common.preview_recipient'),
                                        'company' => $prospectCompany,
                                    ])
                                    : __('mail.demo_access.hero_intro_no_company', [
                                        'name' => $recipientName ?: __('mail.common.preview_recipient'),
                                    ]) }}
                            </div>
                            @if (!empty($tagline))
                                <div style="margin-top:10px; font-size:13px; color:rgba(236, 253, 245, 0.72); line-height:1.7;">
                                    {{ $tagline }}
                                </div>
                            @endif
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:18px;">
                                <tr>
                                    <td bgcolor="#1f2937" style="border-radius:3px;">
                                        <a href="{{ $loginUrl }}" style="display:inline-block; padding:10px 16px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none;">
                                            {{ __('mail.demo_access.hero_action') }}
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <div style="margin-top:18px; font-size:12px; color:rgba(226, 232, 240, 0.72); line-height:1.6;">
                                {{ __('mail.demo_access.hero_caption') }}
                            </div>
                        </td>
                        <td valign="top" width="38%" style="background-color:#eef2f7; padding:18px; border-radius:0 3px 3px 0; border:1px solid #dbe4ee; border-left:0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #dbe4ee; border-radius:3px;">
                                <tr>
                                    <td style="padding:14px 14px 12px;">
                                        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                            {{ __('mail.common.access_snapshot') }}
                                        </div>
                                        @if (!empty($prospectCompany))
                                            <div style="margin-top:10px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                                {{ __('mail.common.company') }}
                                            </div>
                                            <div style="margin-top:4px; font-size:13px; color:#292524; line-height:1.5;">
                                                {{ $prospectCompany }}
                                            </div>
                                        @endif
                                        @if (!empty($templateName))
                                            <div style="margin-top:10px; padding-top:10px; border-top:1px solid #e2e8f0; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                                {{ __('mail.common.template') }}
                                            </div>
                                            <div style="margin-top:4px; font-size:13px; color:#292524; line-height:1.5;">
                                                {{ $templateName }}
                                            </div>
                                        @endif
                                        @if (!empty($expiresAt))
                                            <div style="margin-top:10px; padding-top:10px; border-top:1px solid #e2e8f0; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                                {{ __('mail.common.expires') }}
                                            </div>
                                            <div style="margin-top:4px; font-size:13px; color:#292524; line-height:1.5;">
                                                {{ $expiresAt }}
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;">
                                <tr>
                                    <td width="33.33%" style="padding-right:4px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:rgba(255,255,255,0.78); border:1px solid #dbe4ee; border-radius:3px;">
                                            <tr>
                                                <td style="padding:12px 8px; text-align:center;">
                                                    <div style="font-size:18px; font-weight:700; color:#0f172a;">{{ $moduleCount }}</div>
                                                    <div style="margin-top:4px; font-size:10px; color:#57534e; line-height:1.4;">{{ __('mail.common.modules') }}</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td width="33.33%" style="padding-left:2px; padding-right:2px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:rgba(255,255,255,0.78); border:1px solid #dbe4ee; border-radius:3px;">
                                            <tr>
                                                <td style="padding:12px 8px; text-align:center;">
                                                    <div style="font-size:18px; font-weight:700; color:#0f172a;">{{ $scenarioCount }}</div>
                                                    <div style="margin-top:4px; font-size:10px; color:#57534e; line-height:1.4;">{{ __('mail.common.scenarios') }}</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td width="33.33%" style="padding-left:4px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:rgba(255,255,255,0.78); border:1px solid #dbe4ee; border-radius:3px;">
                                            <tr>
                                                <td style="padding:12px 8px; text-align:center;">
                                                    <div style="font-size:18px; font-weight:700; color:#0f172a;">{{ $roleCount }}</div>
                                                    <div style="margin-top:4px; font-size:10px; color:#57534e; line-height:1.4;">{{ __('mail.common.logins') }}</div>
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
                        <td valign="top" width="50%" style="padding-right:6px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e7e5e4; border-radius:3px;">
                                <tr>
                                    <td style="padding:16px;">
                                        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                            {{ __('mail.demo_access.primary_access') }}
                                        </div>

                                        <div style="margin-top:12px; padding-top:0; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                            {{ __('mail.demo_access.login_url') }}
                                        </div>
                                        <div style="margin-top:4px; font-size:13px; color:#292524; line-height:1.6; word-break:break-all;">
                                            {{ $loginUrl }}
                                        </div>

                                        <div style="margin-top:12px; padding-top:12px; border-top:1px solid #e7e5e4; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                            {{ __('mail.common.email') }}
                                        </div>
                                        <div style="margin-top:4px; font-size:13px; color:#292524; line-height:1.6; word-break:break-all;">
                                            {{ $accessEmail }}
                                        </div>

                                        <div style="margin-top:12px; padding-top:12px; border-top:1px solid #e7e5e4; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                            {{ __('mail.demo_access.password') }}
                                        </div>
                                        <div style="margin-top:4px; font-size:13px; color:#292524; line-height:1.6;">
                                            {{ $accessPassword }}
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                        <td valign="top" width="50%" style="padding-left:6px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e7e5e4; border-radius:3px;">
                                <tr>
                                    <td style="padding:16px;">
                                        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                            {{ __('mail.demo_access.workspace_details') }}
                                        </div>

                                        @if (!empty($prospectCompany))
                                            <div style="margin-top:12px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                                {{ __('mail.common.company') }}
                                            </div>
                                            <div style="margin-top:4px; font-size:13px; color:#292524; line-height:1.6;">
                                                {{ $prospectCompany }}
                                            </div>
                                        @endif

                                        @if (!empty($templateName))
                                            <div style="margin-top:12px; padding-top:12px; border-top:1px solid #e7e5e4; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                                {{ __('mail.common.template') }}
                                            </div>
                                            <div style="margin-top:4px; font-size:13px; color:#292524; line-height:1.6;">
                                                {{ $templateName }}
                                            </div>
                                        @endif

                                        @if (!empty($expiresAt))
                                            <div style="margin-top:12px; padding-top:12px; border-top:1px solid #e7e5e4; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                                {{ __('mail.common.expires') }}
                                            </div>
                                            <div style="margin-top:4px; font-size:13px; color:#292524; line-height:1.6;">
                                                {{ $expiresAt }}
                                            </div>
                                        @endif

                                        @if (empty($prospectCompany) && empty($templateName) && empty($expiresAt))
                                            <div style="margin-top:12px; font-size:13px; color:#57534e; line-height:1.7;">
                                                {{ __('mail.demo_access.default_setup') }}
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        @if (!empty($moduleLabels) || !empty($scenarioLabels))
            <tr>
                <td style="padding-bottom:12px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e7e5e4; border-radius:3px;">
                        <tr>
                            <td style="padding:16px;">
                                <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                    {{ __('mail.demo_access.scope_title') }}
                                </div>
                                @if (!empty($moduleLabels))
                                    <div style="margin-top:10px; font-size:12px; font-weight:600; color:#78716c;">
                                        {{ __('mail.common.modules') }}
                                    </div>
                                    <div style="margin-top:4px; font-size:13px; color:#57534e; line-height:1.7;">
                                        {{ implode(', ', $moduleLabels) }}
                                    </div>
                                @endif
                                @if (!empty($scenarioLabels))
                                    <div style="margin-top:10px; padding-top:10px; border-top:1px solid #e7e5e4; font-size:12px; font-weight:600; color:#78716c;">
                                        {{ __('mail.common.scenarios') }}
                                    </div>
                                    <div style="margin-top:4px; font-size:13px; color:#57534e; line-height:1.7;">
                                        {{ implode(', ', $scenarioLabels) }}
                                    </div>
                                @endif
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endif

        @if (!empty($extraCredentials))
            <tr>
                <td style="padding-bottom:12px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e7e5e4; border-radius:3px;">
                        <tr>
                            <td style="padding:16px;">
                                <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                    {{ __('mail.demo_access.extra_logins') }}
                                </div>
                                @foreach ($extraCredentials as $credential)
                                    <div style="margin-top:12px;{{ $loop->first ? '' : ' padding-top:12px; border-top:1px solid #e7e5e4;' }}">
                                        <div style="font-size:13px; font-weight:600; color:#292524;">
                                            {{ $credential['role_label'] ?? __('mail.common.access') }}
                                        </div>
                                        <div style="margin-top:4px; font-size:13px; color:#57534e; line-height:1.7; word-break:break-all;">
                                            {{ $credential['email'] ?? '' }}
                                        </div>
                                        <div style="margin-top:2px; font-size:13px; color:#57534e; line-height:1.7;">
                                            {{ $credential['password'] ?? '' }}
                                        </div>
                                    </div>
                                @endforeach
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endif

        @if (!empty($suggestedFlow))
            <tr>
                <td style="padding-bottom:12px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f5f4; border:1px solid #e7e5e4; border-top:3px solid #16a34a; border-radius:3px;">
                        <tr>
                            <td style="padding:16px;">
                                <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                    {{ __('mail.demo_access.testing_path') }}
                                </div>
                                <div style="margin-top:8px; font-size:13px; color:#57534e; line-height:1.8; white-space:pre-line;">
                                    {{ $suggestedFlow }}
                                </div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endif

        <tr>
            <td style="font-size:12px; color:#57534e; line-height:1.7;">
                {{ __('mail.demo_access.footer_copy') }}
            </td>
        </tr>
    </table>
@endsection
