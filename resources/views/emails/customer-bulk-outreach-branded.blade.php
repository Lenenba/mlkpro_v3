@extends('emails.layouts.base')

@section('title', $emailTitle ?? config('app.name'))
@section('preheader', $emailPreheader ?? ($emailTitle ?? config('app.name')))

@section('content')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding-bottom:12px;">
                @include('emails.partials.structured-hero', [
                    'heroEyebrow' => $heroEyebrow ?? null,
                    'heroTitle' => $heroTitle ?? '',
                    'heroIntro' => $heroIntro ?? null,
                    'heroActionUrl' => $heroActionUrl ?? null,
                    'heroActionLabel' => $heroActionLabel ?? null,
                    'heroCaption' => $heroCaption ?? null,
                    'heroSideTitle' => $heroSideTitle ?? 'Summary',
                    'heroSideLogo' => $heroSideLogo ?? null,
                    'heroSideRows' => $heroSideRows ?? [],
                    'heroMetrics' => $heroMetrics ?? [],
                ])
            </td>
        </tr>

        @if (!empty($bodyHtml))
            <tr>
                <td style="padding-bottom:12px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e7e5e4; border-radius:3px;">
                        <tr>
                            <td style="padding:18px 20px;">
                                <div style="font-size:11px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:#78716c;">
                                    {{ $messageHeading ?? 'Message' }}
                                </div>
                                <div style="margin-top:12px; font-size:15px; line-height:1.8; color:#292524;">
                                    {!! $bodyHtml !!}
                                </div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endif

        @if (!empty($summaryRows))
            <tr>
                <td>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e7e5e4; border-radius:3px;">
                        <tr>
                            <td style="padding:16px 20px;">
                                <div style="font-size:11px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:#78716c;">
                                    {{ $summaryHeading ?? 'Summary' }}
                                </div>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px; border-collapse:collapse;">
                                    @foreach ($summaryRows as $row)
                                        <tr>
                                            <td style="padding:11px 0; border-top:{{ $loop->first ? '0' : '1px solid #e7e5e4' }}; font-size:12px; color:#78716c;">
                                                {{ $row['label'] ?? 'Detail' }}
                                            </td>
                                            <td align="right" style="padding:11px 0; border-top:{{ $loop->first ? '0' : '1px solid #e7e5e4' }}; font-size:13px; font-weight:700; color:#1c1917;">
                                                {{ $row['value'] ?? '-' }}
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
    </table>
@endsection
