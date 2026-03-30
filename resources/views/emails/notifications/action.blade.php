@extends('emails.layouts.base')

@section('title', $title ?? __('mail.action.default_title'))
@section('preheader', $intro ?? $title ?? __('mail.action.default_preheader'))

@section('content')
    @php
        $normalizedDetails = collect($details ?? [])
            ->map(function ($detail, $key) {
                if (is_array($detail) && array_key_exists('label', $detail)) {
                    return [
                        'label' => $detail['label'] ?? __('mail.common.detail'),
                        'value' => $detail['value'] ?? '-',
                    ];
                }

                return [
                    'label' => is_string($key) && $key !== '' ? $key : __('mail.common.detail'),
                    'value' => is_scalar($detail) || $detail === null ? ($detail ?? '-') : json_encode($detail),
                ];
            })
            ->values()
            ->all();
        $heroRows = [];
        foreach (array_slice($normalizedDetails, 0, 3) as $detail) {
            if (! filled($detail['value'] ?? null)) {
                continue;
            }

            $heroRows[] = [
                'label' => $detail['label'] ?? __('mail.common.detail'),
                'value' => $detail['value'],
            ];
        }

        if (empty($heroRows)) {
            $heroRows[] = [
                'label' => __('mail.action.platform_row'),
                'value' => $companyName ?? config('app.name'),
            ];
        }
    @endphp
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding-bottom:12px;">
                @include('emails.partials.structured-hero', [
                    'heroEyebrow' => __('mail.action.eyebrow'),
                    'heroTitle' => $title ?? __('mail.action.default_title'),
                    'heroIntro' => $intro ?? __('mail.action.default_intro'),
                    'heroActionUrl' => $actionUrl ?? null,
                    'heroActionLabel' => $actionLabel ?? null,
                    'heroCaption' => __('mail.action.caption'),
                    'heroSideTitle' => __('mail.action.side_title'),
                    'heroSideLogo' => $companyLogo ?? null,
                    'heroSideRows' => $heroRows,
                    'heroMetrics' => [
                        ['value' => (string) count($normalizedDetails), 'label' => __('mail.action.detail_metric')],
                        ['value' => !empty($actionUrl) ? '1' : '0', 'label' => __('mail.action.cta_metric')],
                        ['value' => __('mail.action.live_value'), 'label' => __('mail.action.update_metric')],
                    ],
                ])
            </td>
        </tr>

        @if (!empty($normalizedDetails))
            <tr>
                <td style="padding-bottom:12px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e7e5e4; border-radius:3px;">
                        <tr>
                            <td style="padding:16px;">
                                <div style="font-size:11px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:#78716c;">
                                    {{ __('mail.action.summary_heading') }}
                                </div>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px; border-collapse:collapse;">
                                    @foreach ($normalizedDetails as $detail)
                                        <tr>
                                            <td style="padding:10px 0; border-top:{{ $loop->first ? '0' : '1px solid #e7e5e4' }}; font-size:12px; color:#78716c;">
                                                {{ $detail['label'] ?? __('mail.common.detail') }}
                                            </td>
                                            <td align="right" style="padding:10px 0; border-top:{{ $loop->first ? '0' : '1px solid #e7e5e4' }}; font-size:13px; font-weight:600; color:#292524;">
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
                <td style="padding-bottom:12px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f5f4; border:1px solid #e7e5e4; border-radius:3px;">
                        <tr>
                            <td style="padding:14px 16px; font-size:13px; color:#57534e; line-height:1.7;">
                                {{ $note }}
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endif

        @if (!empty($actionUrl))
            <tr>
                <td align="left">
                    <table role="presentation" cellpadding="0" cellspacing="0">
                        <tr>
                            <td bgcolor="#16a34a" style="border-radius:3px;">
                                <a href="{{ $actionUrl }}" style="display:inline-block; padding:10px 16px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
                                    {{ $actionLabel ?? __('mail.action.open_cta') }}
                                </a>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endif
    </table>
@endsection
