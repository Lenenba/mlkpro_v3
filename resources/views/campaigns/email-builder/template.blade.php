@php
    $previewText = (string) ($content['previewText'] ?? '');
    $primary = e((string) ($schema['primary_color'] ?? '{brandPrimaryColor}'));
    $secondary = e((string) ($schema['secondary_color'] ?? '{brandSecondaryColor}'));
    $accent = e((string) ($schema['accent_color'] ?? '{brandAccentColor}'));
    $surface = e((string) ($schema['surface_color'] ?? '{brandSurfaceColor}'));
    $heroBackground = e((string) ($schema['hero_background_color'] ?? '{brandHeroBackgroundColor}'));
    $footerBackground = e((string) ($schema['footer_background_color'] ?? '{brandFooterBackgroundColor}'));
    $textColor = e((string) ($schema['text_color'] ?? '{brandTextColor}'));
    $mutedColor = e((string) ($schema['muted_color'] ?? '{brandMutedColor}'));

    $sectionMap = collect($schema['sections'] ?? [])
        ->filter(fn ($section) => is_array($section) && trim((string) ($section['key'] ?? '')) !== '')
        ->keyBy(fn (array $section) => (string) $section['key']);

    $isBlockEmpty = function (?array $block): bool {
        if (! is_array($block)) {
            return true;
        }

        return trim(implode('', [
            (string) ($block['kicker'] ?? ''),
            (string) ($block['title'] ?? ''),
            (string) ($block['body'] ?? ''),
            (string) ($block['image_url'] ?? ''),
            (string) ($block['button_label'] ?? ''),
            (string) ($block['button_url'] ?? ''),
        ])) === '';
    };

    $sectionBlocks = function (string $key) use ($sectionMap, $isBlockEmpty) {
        $section = $sectionMap->get($key, []);
        if (is_array($section) && array_key_exists('enabled', $section) && ! $section['enabled']) {
            return [
                'key' => $key,
                'count' => 1,
                'blocks' => collect(),
            ];
        }

        $blocks = collect($section['columns'] ?? [])
            ->filter(fn ($block) => is_array($block) && ! $isBlockEmpty($block))
            ->take(3)
            ->values();

        return [
            'key' => $key,
            'count' => max(1, $blocks->count()),
            'blocks' => $blocks,
        ];
    };

    $headerSection = $sectionBlocks('header');
    $bodySection = $sectionBlocks('body');
    $footerSection = $sectionBlocks('footer');
    $contentSections = collect([$headerSection, $bodySection, $footerSection])
        ->filter(fn (array $section) => $section['blocks']->count() > 0)
        ->values();

    $button = function (string $label, string $url) use ($primary): string {
        if (trim($label) === '') {
            return '';
        }

        $safeUrl = trim($url) !== '' ? $url : '#';

        return '<a href="'.e($safeUrl).'" style="display:inline-block; margin-top:14px; padding:12px 18px; border-radius:8px; background:'.$primary.'; color:#FFFFFF; text-decoration:none; font-size:13px; line-height:16px; font-weight:700;">'.e($label).'</a>';
    };

    $renderBlock = function (array $block) use ($button, $primary, $secondary, $mutedColor): string {
        $cardBackground = '#FFFFFF';
        $cardBorder = '1px solid #e7e5e4';
        $titleColor = $secondary;
        $copyColor = $mutedColor;
        $kickerColor = $primary;

        $kicker = trim((string) ($block['kicker'] ?? '')) !== ''
            ? '<div style="padding-bottom:10px; font-size:11px; line-height:16px; font-weight:700; text-transform:uppercase; letter-spacing:0.12em; color:'.$kickerColor.';">'.e((string) $block['kicker']).'</div>'
            : '';

        $title = trim((string) ($block['title'] ?? '')) !== ''
            ? '<div style="font-size:20px; line-height:26px; font-weight:800; letter-spacing:-0.02em; color:'.$titleColor.';">'.e((string) $block['title']).'</div>'
            : '';

        $copy = trim((string) ($block['body'] ?? '')) !== ''
            ? '<div style="padding-top:12px; font-size:15px; line-height:24px; color:'.$copyColor.';">'.nl2br(e((string) $block['body'])).'</div>'
            : '';

        $image = trim((string) ($block['image_url'] ?? '')) !== ''
            ? '<div style="padding-bottom:16px;"><img src="'.e((string) $block['image_url']).'" alt="" style="display:block; width:100%; border-radius:8px; max-width:100%;"></div>'
            : '';

        $buttonHtml = $button((string) ($block['button_label'] ?? ''), (string) ($block['button_url'] ?? ''));

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:'.$cardBackground.'; border:'.$cardBorder.'; border-radius:8px;"><tr><td style="padding:20px;">'
            .$image
            .$kicker
            .$title
            .$copy
            .$buttonHtml
            .'</td></tr></table>';
    };

    $renderColumns = function (array $section) use ($renderBlock): string {
        /** @var \Illuminate\Support\Collection<int, array<string, mixed>> $blocks */
        $blocks = $section['blocks'];
        if ($blocks->count() === 0) {
            return '';
        }

        $width = (string) floor(100 / max(1, $blocks->count()));
        $lastIndex = $blocks->count() - 1;
        $columns = $blocks
            ->values()
            ->map(function (array $block, int $index) use ($renderBlock, $width, $lastIndex): string {
                $paddingRight = $index === $lastIndex ? '0' : '10px';

                return '<td class="stack-column" width="'.$width.'%" valign="top" style="padding:0 '.$paddingRight.' 16px 0;">'.$renderBlock($block).'</td>';
            })
            ->implode('');

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>'.$columns.'</tr></table>';
    };
@endphp
<!DOCTYPE html>
<html lang="{preferredLanguage}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title>{campaignName}</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; font-family:Arial, Helvetica, sans-serif; }
        table, td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
        img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; }
        table { border-collapse:collapse !important; }
        body { margin:0 !important; padding:0 !important; width:100% !important; height:100% !important; background:#f5f5f4; }
        @media screen and (max-width: 680px) {
            .stack-column { display:block !important; width:100% !important; }
            .mobile-pad { padding-left:24px !important; padding-right:24px !important; }
        }
    </style>
</head>
<body>
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; mso-hide:all;">{{ $previewText }}</div>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f5f5f4;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="680" style="width:100%; max-width:680px; background:#ffffff; border:1px solid #e7e5e4; border-radius:10px; overflow:hidden;">
                    <tr>
                        <td style="background:#ffffff; border-top:4px solid {{ $primary }};">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr>
                                    <td class="mobile-pad" style="padding:24px 32px 18px 32px; border-bottom:1px solid #e7e5e4;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                            <tr>
                                                <td valign="middle">
                                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                                        <tr>
                                                            <td style="padding-right:12px;">
                                                                <img src="{brandLogoUrl}" alt="{brandName}" style="display:block; height:40px; width:auto; max-width:150px;">
                                                            </td>
                                                            <td valign="middle">
                                                                <div style="font-size:15px; line-height:20px; font-weight:700; color:{{ $secondary }};">{brandName}</div>
                                                                <div style="padding-top:4px; font-size:12px; line-height:16px; color:{{ $mutedColor }};">{brandTagline}</div>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                                <td align="right" valign="middle" style="font-size:12px; line-height:18px; color:{{ $mutedColor }};">
                                                    <div>{brandWebsiteUrl}</div>
                                                    <div>{brandContactEmail}</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="background:#FFFFFF; color:{{ $textColor }};">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                @foreach($contentSections as $index => $section)
                                    <tr>
                                        <td
                                            class="mobile-pad"
                                            style="padding:{{ $index === 0 ? '24px 32px 12px 32px' : '12px 32px 12px 32px' }};{{ $index > 0 ? ' border-top:1px solid #f0ece7;' : '' }}"
                                        >
                                            {!! $renderColumns($section) !!}
                                        </td>
                                    </tr>
                                @endforeach

                                <tr>
                                    <td style="background:#FFFFFF; border-top:1px solid #e7e5e4;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                            <tr>
                                                <td class="mobile-pad" style="padding:24px 32px 32px 32px;">
                                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                                        <tr>
                                                            <td class="stack-column" width="50%" valign="top" style="padding:0 12px 12px 0;">
                                                                <div style="font-size:16px; line-height:22px; font-weight:700; color:{{ $secondary }};">{brandName}</div>
                                                                <div style="padding-top:8px; font-size:13px; line-height:20px; color:{{ $mutedColor }};">{brandDescription}</div>
                                                                <div style="padding-top:14px; font-size:12px; line-height:19px; color:{{ $mutedColor }};">{brandAddress}</div>
                                                            </td>
                                                            <td class="stack-column" width="50%" valign="top" style="padding:0 0 12px 0;">
                                                                <div style="font-size:13px; line-height:20px; color:{{ $mutedColor }};">Email: {brandContactEmail}</div>
                                                                <div style="font-size:13px; line-height:20px; color:{{ $mutedColor }};">Phone: {brandPhone}</div>
                                                                <div style="padding-top:10px; font-size:12px; line-height:18px; color:{{ $mutedColor }};">{brandFooterNote}</div>
                                                                <div style="padding-top:14px;">
                                                                    <a href="{brandWebsiteUrl}" style="display:inline-block; margin:0 10px 8px 0; color:{{ $primary }}; text-decoration:none; font-size:12px; line-height:16px; font-weight:600;">Website</a>
                                                                    <a href="{brandInstagramUrl}" style="display:inline-block; margin:0 10px 8px 0; color:{{ $primary }}; text-decoration:none; font-size:12px; line-height:16px; font-weight:600;">Instagram</a>
                                                                    <a href="{brandFacebookUrl}" style="display:inline-block; margin:0 10px 8px 0; color:{{ $primary }}; text-decoration:none; font-size:12px; line-height:16px; font-weight:600;">Facebook</a>
                                                                    <a href="{brandLinkedinUrl}" style="display:inline-block; margin:0 10px 8px 0; color:{{ $primary }}; text-decoration:none; font-size:12px; line-height:16px; font-weight:600;">LinkedIn</a>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2" style="padding-top:18px; border-top:1px solid #e7e5e4; font-size:11px; line-height:18px; color:{{ $mutedColor }};">
                                                                You are receiving this message from {brandName}. Use the unsubscribe link below if you no longer want to receive campaign emails.
                                                                <div style="padding-top:8px;">
                                                                    <a href="{unsubscribeUrl}" style="color:{{ $primary }}; text-decoration:underline;">Unsubscribe</a>
                                                                </div>
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
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
