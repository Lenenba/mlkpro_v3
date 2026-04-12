@extends('emails.layouts.base')

@section('title', __('mail.platform_admin_digest.title', ['frequency' => $frequency ?? __('mail.platform_admin_digest.daily')]))
@section('preheader', __('mail.platform_admin_digest.preheader', ['frequency' => strtolower($frequency ?? __('mail.platform_admin_digest.daily'))]))

@section('content')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding-bottom:12px;">
                @include('emails.partials.structured-hero', [
                    'heroEyebrow' => __('mail.platform_admin_digest.eyebrow'),
                    'heroTitle' => __('mail.platform_admin_digest.title', ['frequency' => $frequency ?? __('mail.platform_admin_digest.daily')]),
                    'heroIntro' => __('mail.platform_admin_digest.intro', ['datetime' => $generatedAt?->toDateTimeString() ?? now()->toDateTimeString()]),
                    'heroCaption' => __('mail.platform_admin_digest.caption'),
                    'heroSideTitle' => __('mail.platform_admin_digest.snapshot'),
                    'heroSideRows' => [
                        ['label' => __('mail.common.frequency'), 'value' => $frequency ?? __('mail.platform_admin_digest.daily')],
                        ['label' => __('mail.common.generated'), 'value' => $generatedAt?->toDateTimeString() ?? now()->toDateTimeString()],
                        ['label' => __('mail.common.support'), 'value' => $supportEmail ?? 'support'],
                    ],
                    'heroMetrics' => [
                        ['value' => (string) count($items ?? []), 'label' => __('mail.common.update')],
                        ['value' => strtoupper(substr((string) ($frequency ?? __('mail.platform_admin_digest.daily')), 0, 1)), 'label' => __('mail.common.cycle')],
                        ['value' => __('mail.common.admin'), 'label' => __('mail.common.scope')],
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
                                {{ __('mail.platform_admin_digest.updates_heading', ['count' => count($items ?? [])]) }}
                            </div>
                            @foreach (($items ?? []) as $item)
                                <div style="margin-top:12px;{{ $loop->first ? '' : ' padding-top:12px; border-top:1px solid #e7e5e4;' }}">
                                    <div style="font-size:14px; font-weight:600; color:#292524;">
                                        {{ $item['title'] ?? __('mail.common.update') }}
                                    </div>
                                    <div style="margin-top:4px;">
                                        <span style="display:inline-block; background-color:#f5f5f4; border:1px solid #e7e5e4; border-radius:3px; padding:4px 8px; font-size:10px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:#57534e;">
                                            {{ strtoupper($item['category'] ?? 'general') }}
                                        </span>
                                        @if (!empty($item['created_at']))
                                            <span style="padding-left:8px; font-size:11px; color:#78716c;">
                                                {{ \Illuminate\Support\Carbon::parse($item['created_at'])->toDateTimeString() }}
                                            </span>
                                        @endif
                                    </div>
                                    @if (!empty($item['intro']))
                                        <div style="margin-top:6px; font-size:13px; color:#57534e; line-height:1.7;">
                                            {{ $item['intro'] }}
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr>
            <td style="font-size:12px; color:#57534e; line-height:1.7;">
                {{ __('mail.platform_admin_digest.help', ['support' => $supportEmail ?? 'support']) }}
            </td>
        </tr>
    </table>
@endsection
