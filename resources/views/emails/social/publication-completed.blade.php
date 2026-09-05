@extends('emails.layouts.base')

@section('title', $subject)
@section('preheader', $summary)

@section('content')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding-bottom:12px;">
                @include('emails.partials.structured-hero', [
                    'heroEyebrow' => 'Malikia Pulse',
                    'heroTitle' => $subject,
                    'heroIntro' => $summary,
                    'heroActionUrl' => $actionUrl,
                    'heroActionLabel' => $actionLabel,
                    'heroSideTitle' => $publicationLabel.' #'.$snapshot['social_post_id'],
                    'heroSideLogo' => $companyLogo ?? null,
                    'heroSideRows' => [
                        ['label' => $publicationLabel, 'value' => $snapshot['excerpt']],
                    ],
                    'heroMetrics' => $metrics,
                ])
            </td>
        </tr>

        <tr>
            <td style="padding-bottom:12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e7e5e4; border-radius:3px;">
                    <tr>
                        <td style="padding:16px;">
                            <div style="font-size:11px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:#78716c;">
                                {{ $detailsLabel }}
                            </div>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px; border-collapse:collapse;">
                                @foreach ($results as $result)
                                    <tr>
                                        <td style="padding:12px 0; border-top:{{ $loop->first ? '0' : '1px solid #e7e5e4' }};">
                                            <div style="font-size:14px; font-weight:600; color:#292524;">
                                                {{ $result['platform_label'] }}
                                            </div>
                                            <div style="margin-top:4px; font-size:12px; color:#78716c; overflow-wrap:anywhere;">
                                                {{ $result['account'] }}
                                            </div>
                                            <div style="margin-top:6px; font-size:13px; font-weight:600; color:{{ $result['status'] === 'published' ? '#15803d' : ($result['status'] === 'failed' ? '#b91c1c' : '#a16207') }};">
                                                {{ $result['status_label'] }}
                                            </div>
                                            @if ($result['error'])
                                                <div style="margin-top:6px; font-size:13px; line-height:1.7; color:#57534e; overflow-wrap:anywhere;">
                                                    {{ $result['error'] }}
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        @if ($nextSteps)
            <tr>
                <td style="padding-bottom:12px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f5f4; border:1px solid #e7e5e4; border-radius:3px;">
                        <tr>
                            <td style="padding:14px 16px; font-size:13px; color:#57534e; line-height:1.7;">
                                {{ $nextSteps }}
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endif

        <tr>
            <td align="left">
                <table role="presentation" cellpadding="0" cellspacing="0">
                    <tr>
                        <td bgcolor="#16a34a" style="border-radius:3px;">
                            <a href="{{ $actionUrl }}" style="display:inline-block; padding:10px 16px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
                                {{ $actionLabel }}
                            </a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
@endsection
