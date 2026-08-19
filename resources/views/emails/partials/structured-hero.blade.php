@php
    $heroEyebrow = $heroEyebrow ?? null;
    $heroTitle = $heroTitle ?? '';
    $heroIntro = $heroIntro ?? null;
    $heroActionUrl = $heroActionUrl ?? null;
    $heroActionLabel = $heroActionLabel ?? null;
    $heroCaption = $heroCaption ?? null;
    $heroSideTitle = $heroSideTitle ?? 'Snapshot';
    $heroSideLogo = $heroSideLogo ?? null;
    $heroSideRows = array_values(array_filter($heroSideRows ?? [], static fn ($row) => filled($row['value'] ?? null)));
    $heroMetrics = array_values(array_filter($heroMetrics ?? [], static fn ($metric) => filled($metric['value'] ?? null) || filled($metric['label'] ?? null)));

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

    $hasHeroSideLogo = is_string($heroSideLogo) && $heroSideLogo !== '' && ! str_contains($heroSideLogo, 'customers/customer.png');
    $resolvedHeroSideLogo = $hasHeroSideLogo ? $resolveEmailImage($heroSideLogo) : null;
@endphp

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;">
    <tr>
        <td valign="top" width="62%" style="background-color:#0d3137; background-image:radial-gradient(circle at top center, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0) 24%), linear-gradient(135deg, #0d1d35 0%, #0d3137 48%, #0d5a46 100%); padding:24px 22px; border-radius:3px 0 0 3px;">
            @if ($heroEyebrow)
                <div style="font-size:12px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:#a7f3d0;">
                    {{ $heroEyebrow }}
                </div>
            @endif
            <div style="margin-top:12px; font-size:32px; font-weight:700; line-height:0.96; letter-spacing:-0.04em; color:#f8fafc; max-width:320px;">
                {{ $heroTitle }}
            </div>
            <div style="margin-top:14px; width:92px; height:4px; background-color:#2dd4bf;">&nbsp;</div>
            @if ($heroIntro)
                <div style="margin-top:14px; font-size:14px; line-height:1.8; color:rgba(236, 253, 245, 0.88);">
                    {{ $heroIntro }}
                </div>
            @endif
            @if ($heroActionUrl && $heroActionLabel)
                <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:18px;">
                    <tr>
                        <td bgcolor="#1f2937" style="border-radius:3px;">
                            <a href="{{ $heroActionUrl }}" style="display:inline-block; padding:10px 16px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none;">
                                {{ $heroActionLabel }}
                            </a>
                        </td>
                    </tr>
                </table>
            @endif
            @if ($heroCaption)
                <div style="margin-top:18px; font-size:12px; color:rgba(226, 232, 240, 0.72); line-height:1.7;">
                    {{ $heroCaption }}
                </div>
            @endif
        </td>
        <td valign="top" width="38%" style="background-color:#eef2f7; padding:18px; border-radius:0 3px 3px 0; border:1px solid #dbe4ee; border-left:0;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #dbe4ee; border-radius:3px;">
                <tr>
                    <td style="padding:14px 14px 12px;">
                        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                            {{ $heroSideTitle }}
                        </div>
                        @if ($resolvedHeroSideLogo)
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:12px;">
                                <tr>
                                    <td style="padding:0;">
                                        <img src="{{ $resolvedHeroSideLogo }}" alt="Company logo" style="max-height:40px; max-width:170px; width:auto; display:block;">
                                    </td>
                                </tr>
                            </table>
                        @endif
                        @foreach ($heroSideRows as $row)
                            <div style="margin-top:{{ $loop->first ? '12px' : '10px' }};{{ $loop->first ? '' : ' padding-top:10px; border-top:1px solid #e2e8f0;' }} font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                {{ $row['label'] ?? 'Detail' }}
                            </div>
                            <div style="margin-top:4px; font-size:13px; color:#292524; line-height:1.5; word-break:break-word;">
                                {{ $row['value'] ?? '-' }}
                            </div>
                        @endforeach
                        @if (empty($heroSideRows) && ! $resolvedHeroSideLogo)
                            <div style="margin-top:12px; font-size:13px; color:#57534e; line-height:1.7;">
                                Votre espace reste synchronise avec Malikia Pro.
                            </div>
                        @endif
                    </td>
                </tr>
            </table>

            @if (!empty($heroMetrics))
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;">
                    <tr>
                        @foreach ($heroMetrics as $metric)
                            <td width="{{ number_format(100 / max(count($heroMetrics), 1), 2) }}%" style="padding-left:{{ $loop->first ? '0' : '4px' }}; padding-right:{{ $loop->last ? '0' : '4px' }};">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:rgba(255,255,255,0.78); border:1px solid #dbe4ee; border-radius:3px;">
                                    <tr>
                                        <td style="padding:12px 8px; text-align:center;">
                                            <div style="font-size:18px; font-weight:700; color:#0f172a;">{{ $metric['value'] ?? '-' }}</div>
                                            <div style="margin-top:4px; font-size:10px; color:#57534e; line-height:1.4;">{{ $metric['label'] ?? '' }}</div>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        @endforeach
                    </tr>
                </table>
            @endif
        </td>
    </tr>
</table>
