<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Support\CampaignTemplateLanguage;
use Illuminate\Support\Str;

class EmailTemplateComposer
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function blockLibrary(): array
    {
        return [
            [
                'type' => 'simple_block',
                'label' => 'Simple block',
                'description' => 'One block per column with kicker, title, text, image, and CTA.',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function presetCatalog(): array
    {
        return collect(CampaignTemplateLanguage::supported())
            ->flatMap(fn (string $language) => $this->presetsForLanguage($language))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function normalizeContent(array $content): array
    {
        $editorMode = strtolower(trim((string) ($content['editorMode'] ?? $content['editor_mode'] ?? '')));
        if (! in_array($editorMode, ['builder', 'html'], true)) {
            $editorMode = is_array($content['schema'] ?? null) ? 'builder' : 'html';
        }

        return [
            'subject' => trim((string) ($content['subject'] ?? '')),
            'previewText' => trim((string) ($content['previewText'] ?? $content['preview_text'] ?? '')),
            'editorMode' => $editorMode,
            'templateKey' => trim((string) ($content['templateKey'] ?? $content['template_key'] ?? '')),
            'schema' => $this->normalizeSchema(is_array($content['schema'] ?? null) ? $content['schema'] : []),
            'html' => (string) ($content['html'] ?? $content['body'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public function compile(array $content): string
    {
        $normalized = $this->normalizeContent($content);
        if (($normalized['editorMode'] ?? 'html') !== 'builder') {
            return (string) ($normalized['html'] ?? '');
        }

        return (string) view('campaigns.email-builder.template', [
            'content' => $normalized,
            'schema' => is_array($normalized['schema'] ?? null) ? $normalized['schema'] : [],
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function normalizeSchema(array $schema): array
    {
        $rawSections = is_array($schema['sections'] ?? null) ? $schema['sections'] : [];
        $sections = $this->looksLikeLegacySections($rawSections)
            ? $this->normalizeLegacySections($rawSections)
            : $this->normalizeSimpleSections($rawSections);

        return [
            'primary_color' => (string) ($schema['primary_color'] ?? '{brandPrimaryColor}'),
            'secondary_color' => (string) ($schema['secondary_color'] ?? '{brandSecondaryColor}'),
            'accent_color' => (string) ($schema['accent_color'] ?? '{brandAccentColor}'),
            'surface_color' => (string) ($schema['surface_color'] ?? '{brandSurfaceColor}'),
            'hero_background_color' => (string) ($schema['hero_background_color'] ?? '{brandHeroBackgroundColor}'),
            'footer_background_color' => (string) ($schema['footer_background_color'] ?? '{brandFooterBackgroundColor}'),
            'text_color' => (string) ($schema['text_color'] ?? '{brandTextColor}'),
            'muted_color' => (string) ($schema['muted_color'] ?? '{brandMutedColor}'),
            'sections' => $sections,
        ];
    }

    private function normalizeSectionBackgroundMode(mixed $value): string
    {
        $mode = strtolower(trim((string) $value));

        return in_array($mode, ['white', 'soft', 'highlight'], true) ? $mode : 'white';
    }

    private function normalizeSectionTextAlign(mixed $value): string
    {
        $align = strtolower(trim((string) $value));

        return in_array($align, ['left', 'center'], true) ? $align : 'left';
    }

    private function normalizeSectionSpacing(mixed $value): string
    {
        $spacing = strtolower(trim((string) $value));

        return in_array($spacing, ['compact', 'normal', 'spacious'], true) ? $spacing : 'normal';
    }

    private function normalizeSectionCtaStyle(mixed $value): string
    {
        $style = strtolower(trim((string) $value));

        return in_array($style, ['solid', 'outline', 'soft'], true) ? $style : 'solid';
    }

    /**
     * @param  array<int, mixed>  $sections
     */
    private function looksLikeLegacySections(array $sections): bool
    {
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            if (array_key_exists('type', $section) || array_key_exists('placement', $section)) {
                return true;
            }

            if (! array_key_exists('key', $section)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $sections
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSimpleSections(array $sections): array
    {
        $indexed = collect($sections)
            ->filter(fn ($section) => is_array($section))
            ->keyBy(function (array $section, int $index): string {
                $key = strtolower(trim((string) ($section['key'] ?? '')));

                return $key !== '' ? $key : $this->sectionDefaults()[$index]['key'];
            });

        return collect($this->sectionDefaults())
            ->map(function (array $default) use ($indexed): array {
                /** @var array<string, mixed> $section */
                $section = is_array($indexed->get($default['key'])) ? $indexed->get($default['key']) : [];

                return $this->normalizeSimpleSection($section, $default);
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $sections
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLegacySections(array $sections): array
    {
        $grouped = [
            'header' => [],
            'body' => [],
            'footer' => [],
        ];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $block = $this->legacySectionToSimpleBlock($section);
            if ($this->isBlockEmpty($block)) {
                continue;
            }

            $this->appendLegacyBlock($grouped[$this->legacySectionZone($section)], $block);
        }

        return collect($this->sectionDefaults())
            ->map(function (array $default) use ($grouped): array {
                $columns = $grouped[$default['key']] ?? [];
                $count = max(1, min(3, count($columns)));

                return $this->normalizeSimpleSection([
                    'key' => $default['key'],
                    'column_count' => $count,
                    'columns' => $columns,
                ], $default);
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    private function normalizeSimpleSection(array $section, array $default): array
    {
        $count = $this->clampColumnCount(
            $section['column_count'] ?? $section['columns_count'] ?? count(is_array($section['columns'] ?? null) ? $section['columns'] : []) ?: $default['column_count']
        );

        $rawColumns = is_array($section['columns'] ?? null) ? array_values($section['columns']) : [];
        $columns = [];

        for ($index = 0; $index < $count; $index++) {
            $columns[] = $this->normalizeSimpleBlock(
                is_array($rawColumns[$index] ?? null) ? $rawColumns[$index] : [],
                $default['key'],
                $index
            );
        }

        return [
            'key' => $default['key'],
            'label' => $default['label'],
            'enabled' => array_key_exists('enabled', $section) ? (bool) $section['enabled'] : (bool) ($default['enabled'] ?? true),
            'background_mode' => $this->normalizeSectionBackgroundMode($section['background_mode'] ?? $default['background_mode'] ?? 'white'),
            'text_align' => $this->normalizeSectionTextAlign($section['text_align'] ?? $default['text_align'] ?? 'left'),
            'spacing_top' => $this->normalizeSectionSpacing($section['spacing_top'] ?? $default['spacing_top'] ?? 'normal'),
            'spacing_bottom' => $this->normalizeSectionSpacing($section['spacing_bottom'] ?? $default['spacing_bottom'] ?? 'normal'),
            'cta_style' => $this->normalizeSectionCtaStyle($section['cta_style'] ?? $default['cta_style'] ?? 'solid'),
            'column_count' => $count,
            'columns' => $columns,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, string>
     */
    private function normalizeSimpleBlock(array $block, string $sectionKey, int $index): array
    {
        return [
            'id' => trim((string) ($block['id'] ?? $sectionKey.'-block-'.($index + 1).'-'.Str::uuid())),
            'kicker' => trim((string) ($block['kicker'] ?? $block['eyebrow'] ?? $block['badge'] ?? '')),
            'title' => trim((string) ($block['title'] ?? '')),
            'body' => trim((string) ($block['body'] ?? $block['text'] ?? '')),
            'image_url' => trim((string) ($block['image_url'] ?? $block['image'] ?? '')),
            'button_label' => trim((string) ($block['button_label'] ?? $block['primary_label'] ?? '')),
            'button_url' => trim((string) ($block['button_url'] ?? $block['button_href'] ?? $block['primary_href'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function legacySectionZone(array $section): string
    {
        $type = strtolower(trim((string) ($section['type'] ?? '')));
        $placement = strtolower(trim((string) ($section['placement'] ?? 'content')));

        if (in_array($type, ['cta_banner', 'social_proof'], true)) {
            return 'footer';
        }

        if ($placement === 'hero' || in_array($type, ['hero', 'metrics'], true)) {
            return 'header';
        }

        return 'body';
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, string>
     */
    private function legacySectionToSimpleBlock(array $section): array
    {
        $type = strtolower(trim((string) ($section['type'] ?? 'rich_text')));
        $title = trim((string) ($section['title'] ?? ''));
        $bodyParts = collect([
            trim((string) ($section['text'] ?? '')),
            trim((string) ($section['note'] ?? '')),
        ]);

        if ($type === 'rich_text' && trim((string) ($section['html'] ?? '')) !== '') {
            $bodyParts->push($this->plainTextFromHtml((string) $section['html']));
        }

        foreach ($this->legacyItemSummaryLines($type, is_array($section['items'] ?? null) ? $section['items'] : []) as $line) {
            $bodyParts->push($line);
        }

        foreach ([
            $section['price_label'] ?? null,
            $section['discount_label'] ?? null,
            $section['deadline'] ?? null,
            $section['date_label'] ?? null,
            $section['time_label'] ?? null,
            $section['location_label'] ?? null,
        ] as $metaValue) {
            $value = trim((string) $metaValue);
            if ($value !== '') {
                $bodyParts->push($value);
            }
        }

        if ($type === 'social_proof' && trim((string) ($section['quote'] ?? '')) !== '') {
            $title = $title !== '' ? $title : 'Testimonial';
            $bodyParts->push('"'.trim((string) ($section['quote'] ?? '')).'"');

            $author = collect([
                trim((string) ($section['quote_author'] ?? '')),
                trim((string) ($section['quote_role'] ?? '')),
            ])->filter()->implode(' - ');

            if ($author !== '') {
                $bodyParts->push($author);
            }
        }

        $body = $bodyParts
            ->filter(fn (string $value) => $value !== '')
            ->implode("\n\n");

        $imageUrl = trim((string) ($section['image_url'] ?? ''));
        if ($imageUrl === '' && in_array($type, ['featured_grid', 'hero'], true)) {
            $imageUrl = trim((string) data_get($section, 'items.0.image_url', ''));
        }

        return [
            'id' => trim((string) ($section['id'] ?? Str::uuid())),
            'kicker' => trim((string) ($section['eyebrow'] ?? $section['badge'] ?? '')),
            'title' => $title,
            'body' => $body,
            'image_url' => $imageUrl,
            'button_label' => trim((string) ($section['button_label'] ?? data_get($section, 'items.0.button_label', ''))),
            'button_url' => trim((string) ($section['button_url'] ?? data_get($section, 'items.0.button_url', ''))),
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, string>
     */
    private function legacyItemSummaryLines(string $type, array $items): array
    {
        return collect($items)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) use ($type): string {
                $title = trim((string) ($item['title'] ?? ''));
                $text = trim((string) ($item['text'] ?? ''));
                $value = trim((string) ($item['value'] ?? ''));
                $label = trim((string) ($item['label'] ?? ''));
                $price = trim((string) ($item['price'] ?? ''));

                if ($type === 'metrics') {
                    return trim(implode(' - ', array_filter([$value, $label])));
                }

                return trim(implode(' - ', array_filter([$title, $text, $price])));
            })
            ->filter(fn (string $line) => $line !== '')
            ->take(6)
            ->values()
            ->all();
    }

    private function plainTextFromHtml(string $html): string
    {
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\/p>/i', "\n\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  array<int, array<string, string>>  $blocks
     * @param  array<string, string>  $block
     */
    private function appendLegacyBlock(array &$blocks, array $block): void
    {
        if (count($blocks) < 3) {
            $blocks[] = $block;

            return;
        }

        $lastIndex = count($blocks) - 1;
        $blocks[$lastIndex] = $this->mergeBlocks($blocks[$lastIndex], $block);
    }

    /**
     * @param  array<string, string>  $current
     * @param  array<string, string>  $incoming
     * @return array<string, string>
     */
    private function mergeBlocks(array $current, array $incoming): array
    {
        $title = $current['title'] !== '' ? $current['title'] : $incoming['title'];
        $bodyParts = array_values(array_filter([
            trim((string) ($current['body'] ?? '')),
            trim((string) ($incoming['title'] ?? '')),
            trim((string) ($incoming['body'] ?? '')),
        ]));

        return [
            'id' => $current['id'] ?: $incoming['id'],
            'kicker' => $current['kicker'] !== '' ? $current['kicker'] : $incoming['kicker'],
            'title' => $title,
            'body' => implode("\n\n", $bodyParts),
            'image_url' => $current['image_url'] !== '' ? $current['image_url'] : $incoming['image_url'],
            'button_label' => $current['button_label'] !== '' ? $current['button_label'] : $incoming['button_label'],
            'button_url' => $current['button_url'] !== '' ? $current['button_url'] : $incoming['button_url'],
        ];
    }

    /**
     * @param  array<string, string>  $block
     */
    private function isBlockEmpty(array $block): bool
    {
        return trim(implode('', [
            $block['kicker'] ?? '',
            $block['title'] ?? '',
            $block['body'] ?? '',
            $block['image_url'] ?? '',
            $block['button_label'] ?? '',
            $block['button_url'] ?? '',
        ])) === '';
    }

    private function clampColumnCount(mixed $value): int
    {
        $count = (int) $value;

        return max(1, min(3, $count));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sectionDefaults(): array
    {
        return [
            ['key' => 'header', 'label' => 'Header', 'enabled' => true, 'background_mode' => 'white', 'text_align' => 'left', 'spacing_top' => 'normal', 'spacing_bottom' => 'normal', 'cta_style' => 'solid', 'column_count' => 1],
            ['key' => 'body', 'label' => 'Body', 'enabled' => true, 'background_mode' => 'white', 'text_align' => 'left', 'spacing_top' => 'normal', 'spacing_bottom' => 'normal', 'cta_style' => 'solid', 'column_count' => 1],
            ['key' => 'footer', 'label' => 'Footer', 'enabled' => true, 'background_mode' => 'white', 'text_align' => 'left', 'spacing_top' => 'normal', 'spacing_bottom' => 'normal', 'cta_style' => 'solid', 'column_count' => 1],
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $headerBlocks
     * @param  array<int, array<string, string>>  $bodyBlocks
     * @param  array<int, array<string, string>>  $footerBlocks
     * @return array<int, array<string, mixed>>
     */
    private function simpleSections(array $headerBlocks, array $bodyBlocks, array $footerBlocks): array
    {
        return [
            [
                'key' => 'header',
                'enabled' => true,
                'background_mode' => 'white',
                'text_align' => 'left',
                'spacing_top' => 'normal',
                'spacing_bottom' => 'normal',
                'cta_style' => 'solid',
                'column_count' => max(1, min(3, count($headerBlocks))),
                'columns' => $headerBlocks,
            ],
            [
                'key' => 'body',
                'enabled' => true,
                'background_mode' => 'white',
                'text_align' => 'left',
                'spacing_top' => 'normal',
                'spacing_bottom' => 'normal',
                'cta_style' => 'solid',
                'column_count' => max(1, min(3, count($bodyBlocks))),
                'columns' => $bodyBlocks,
            ],
            [
                'key' => 'footer',
                'enabled' => true,
                'background_mode' => 'white',
                'text_align' => 'left',
                'spacing_top' => 'normal',
                'spacing_bottom' => 'normal',
                'cta_style' => 'solid',
                'column_count' => max(1, min(3, count($footerBlocks))),
                'columns' => $footerBlocks,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function simpleBlock(
        string $title = '',
        string $body = '',
        string $kicker = '',
        string $buttonLabel = '',
        string $buttonUrl = '',
        string $imageUrl = ''
    ): array {
        return [
            'id' => (string) Str::uuid(),
            'kicker' => $kicker,
            'title' => $title,
            'body' => $body,
            'image_url' => $imageUrl,
            'button_label' => $buttonLabel,
            'button_url' => $buttonUrl,
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $headerBlocks
     * @param  array<int, array<string, string>>  $bodyBlocks
     * @param  array<int, array<string, string>>  $footerBlocks
     * @param  array<int, string>  $tags
     * @return array<string, mixed>
     */
    private function preset(
        string $key,
        string $language,
        string $name,
        string $description,
        string $campaignType,
        array $tags,
        string $subject,
        string $previewText,
        array $headerBlocks,
        array $bodyBlocks,
        array $footerBlocks
    ): array {
        return [
            'key' => $key,
            'channel' => Campaign::CHANNEL_EMAIL,
            'language' => strtoupper($language),
            'name' => $name,
            'description' => $description,
            'campaign_type' => $campaignType,
            'tags' => $tags,
            'content' => [
                'subject' => $subject,
                'previewText' => $previewText,
                'editorMode' => 'builder',
                'templateKey' => $key,
                'schema' => [
                    'sections' => $this->simpleSections($headerBlocks, $bodyBlocks, $footerBlocks),
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presetsForLanguage(string $language): array
    {
        $language = CampaignTemplateLanguage::normalize($language);

        if ($language === 'ES') {
            return $this->spanishPresets();
        }

        $isFrench = strtoupper($language) === 'FR';

        return [
            $this->preset(
                key: 'promotion-premium',
                language: $language,
                name: $isFrench ? 'Promotion premium' : 'Premium promotion',
                description: $isFrench ? 'Hero simple, argumentaire et CTA clair.' : 'Simple hero, key arguments, and a clear CTA.',
                campaignType: Campaign::TYPE_PROMOTION,
                tags: ['promotion', 'premium'],
                subject: $isFrench ? '{firstName}, profitez de {promoPercent}% sur {offerName}' : '{firstName}, enjoy {promoPercent}% off {offerName}',
                previewText: $isFrench ? 'Une promo lisible, branding et orientee conversion.' : 'A readable branded promotion built for conversion.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? '{promoPercent}% sur {offerName}' : '{promoPercent}% off {offerName}',
                        body: $isFrench ? 'Mettez en avant votre offre avec un message net, une image forte et un bouton bien visible.' : 'Highlight your offer with a sharp message, strong image, and visible CTA.',
                        kicker: $isFrench ? 'Offre du moment' : 'Offer of the moment',
                        buttonLabel: $isFrench ? 'Voir l offre' : 'View offer',
                        buttonUrl: '{trackedCtaUrl}',
                        imageUrl: '{offerImageUrl}'
                    ),
                    $this->simpleBlock(
                        title: '{offerPrice}',
                        body: $isFrench ? 'Code {promoCode}\nJusqu au {promoEndDate}' : 'Code {promoCode}\nUntil {promoEndDate}',
                        kicker: $isFrench ? 'Conditions' : 'Offer details'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Pourquoi cliquer' : 'Why it matters',
                        body: $isFrench ? "Une offre claire\nUn benefice immediat\nUne action simple" : "A clear offer\nAn immediate benefit\nA simple next step"
                    ),
                    $this->simpleBlock(
                        title: $isFrench ? 'Ce que vous mettez en avant' : 'What you highlight',
                        body: $isFrench ? '{offerName}\n{offerAvailability}\nUne presentation propre sur mobile et desktop.' : '{offerName}\n{offerAvailability}\nClean presentation on mobile and desktop.'
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Passez a l action maintenant' : 'Move now while the offer is live',
                        body: $isFrench ? 'Capitalisez sur l attention de vos clients pendant que l offre est active.' : 'Use the customer attention while the offer is still active.',
                        buttonLabel: $isFrench ? 'J en profite' : 'Claim the offer',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'relance-client',
                language: $language,
                name: $isFrench ? 'Relance client' : 'Client follow-up',
                description: $isFrench ? 'Relance simple et premium pour reengager.' : 'Simple premium follow-up to re-engage customers.',
                campaignType: Campaign::TYPE_WINBACK,
                tags: ['winback', 'follow-up'],
                subject: $isFrench ? '{firstName}, nous avons quelque chose pour vous' : '{firstName}, we have something for you',
                previewText: $isFrench ? 'Une relance elegante et plus humaine.' : 'A more elegant and human follow-up.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Nous pensions a vous' : 'We thought of you',
                        body: $isFrench ? 'Depuis votre dernier passage le {lastOrderDate}, notre offre a evolue.' : 'Since your last order on {lastOrderDate}, our offer has evolved.',
                        kicker: $isFrench ? 'Relance' : 'Follow-up',
                        buttonLabel: $isFrench ? 'Decouvrir' : 'Discover',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Ce qui change' : 'What is new',
                        body: $isFrench ? "Un message plus clair\nUne offre mieux presentee\nUn contact plus direct avec votre equipe" : "A clearer message\nA better structured offer\nA more direct relationship with your team"
                    ),
                    $this->simpleBlock(
                        title: $isFrench ? 'Pourquoi revenir' : 'Why come back',
                        body: $isFrench ? 'Redonnez une vraie raison de revenir avec un angle plus premium.' : 'Give customers a real reason to come back with a more premium angle.'
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'On en parle ?' : 'Want to talk about it?',
                        body: $isFrench ? 'Vous pouvez cliquer ou simplement repondre a cet email.' : 'You can click below or simply reply to this email.',
                        buttonLabel: $isFrench ? 'Revenir maintenant' : 'Come back now',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'nouveaute-lancement',
                language: $language,
                name: $isFrench ? 'Annonce de nouveaute' : 'New announcement',
                description: $isFrench ? 'Annonce simple pour lancement ou nouveaute.' : 'Simple launch template for a new announcement.',
                campaignType: Campaign::TYPE_ANNOUNCEMENT,
                tags: ['announcement', 'launch'],
                subject: $isFrench ? 'Nouveau chez {brandName}: {offerName}' : 'New from {brandName}: {offerName}',
                previewText: $isFrench ? 'Transformez une annonce en moment de marque.' : 'Turn an announcement into a real brand moment.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Voici {offerName}' : 'Meet {offerName}',
                        body: $isFrench ? 'Une nouveaute presentee dans un format plus propre, plus clair et plus convaincant.' : 'A new release presented in a cleaner, clearer, more convincing format.',
                        kicker: $isFrench ? 'Nouveaute' : 'New',
                        buttonLabel: $isFrench ? 'Explorer' : 'Explore',
                        buttonUrl: '{trackedCtaUrl}',
                        imageUrl: '{offerImageUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Ce que vous annoncez' : 'What you announce',
                        body: '{offerName}'."\n".'{offerPrice}'."\n".'{offerAvailability}'
                    ),
                    $this->simpleBlock(
                        title: $isFrench ? 'Pourquoi c est important' : 'Why it matters',
                        body: $isFrench ? 'Une annonce plus qualitative renforce la credibilite de votre marque.' : 'A better crafted announcement increases brand credibility.'
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Voir la nouveaute' : 'See what is new',
                        body: '{brandDescription}',
                        buttonLabel: $isFrench ? 'Acceder a la nouveaute' : 'Access the release',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'offre-speciale',
                language: $language,
                name: $isFrench ? 'Offre speciale' : 'Special offer',
                description: $isFrench ? 'Format court et impactant.' : 'Short and high-impact offer format.',
                campaignType: Campaign::TYPE_PROMOTION,
                tags: ['offer', 'flash'],
                subject: $isFrench ? 'Offre speciale: {promoPercent}% jusqu au {promoEndDate}' : 'Special offer: {promoPercent}% until {promoEndDate}',
                previewText: $isFrench ? 'Court, lisible et oriente action.' : 'Short, readable, and action-oriented.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? '{promoPercent}% sur {offerName}' : '{promoPercent}% off {offerName}',
                        body: $isFrench ? 'Un format simple pour pousser une campagne flash sans surcharge visuelle.' : 'A simple layout for a flash campaign without visual overload.',
                        kicker: $isFrench ? 'Edition limitee' : 'Limited time',
                        buttonLabel: $isFrench ? 'Activer maintenant' : 'Activate now',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Rappel rapide' : 'Quick reminder',
                        body: $isFrench ? 'Code {promoCode}\nExpire le {promoEndDate}' : 'Code {promoCode}\nExpires on {promoEndDate}'
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Ne laissez pas passer cette offre' : 'Do not miss this offer',
                        body: $isFrench ? 'Une relance finale simple pour provoquer le clic.' : 'A clean final push designed to earn the click.',
                        buttonLabel: $isFrench ? 'J en profite' : 'Get the offer',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'fidelisation-premium',
                language: $language,
                name: $isFrench ? 'Fidelisation premium' : 'Loyalty premium',
                description: $isFrench ? 'Template relationnel pour remercier et fideliser.' : 'Relationship-driven template to thank and retain customers.',
                campaignType: Campaign::TYPE_CROSS_SELL,
                tags: ['loyalty', 'retention'],
                subject: $isFrench ? 'Merci {firstName}, un avantage vous attend' : 'Thank you {firstName}, an exclusive perk is waiting',
                previewText: $isFrench ? 'Un email plus valorisant pour vos meilleurs clients.' : 'A more valuable email for your best customers.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Merci pour votre confiance' : 'Thank you for your trust',
                        body: $isFrench ? 'Vos meilleurs clients meritent un message plus premium et plus personnel.' : 'Your best customers deserve a more premium and personal message.',
                        kicker: $isFrench ? 'Fidelite' : 'Loyalty',
                        buttonLabel: $isFrench ? 'Voir mon avantage' : 'See my benefit',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Vos avantages' : 'Your benefits',
                        body: $isFrench ? "Priorite de traitement\nOffres reservees\nRelation directe avec votre equipe" : "Priority handling\nExclusive offers\nDirect access to your team"
                    ),
                    $this->simpleBlock(
                        title: $isFrench ? 'Pourquoi cela compte' : 'Why it matters',
                        body: $isFrench ? 'Le bon email de fidelisation doit faire sentir au client qu il compte vraiment.' : 'A good loyalty email should make customers feel genuinely valued.'
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Profitez-en des maintenant' : 'Use it now',
                        body: '{brandFooterNote}',
                        buttonLabel: $isFrench ? 'Acceder a mon avantage' : 'Access my perk',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'rappel-rendez-vous',
                language: $language,
                name: $isFrench ? 'Rappel de rendez-vous' : 'Appointment reminder',
                description: $isFrench ? 'Rappel clair pour rendez-vous ou intervention.' : 'Clear reminder for appointments or service visits.',
                campaignType: Campaign::TYPE_ANNOUNCEMENT,
                tags: ['reminder', 'appointment'],
                subject: $isFrench ? 'Rappel: votre rendez-vous approche' : 'Reminder: your appointment is coming up',
                previewText: $isFrench ? 'Date, heure et action dans un seul format simple.' : 'Date, time, and next action in one simple format.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Votre rendez-vous approche' : 'Your appointment is coming up',
                        body: $isFrench ? 'Retrouvez les informations utiles et confirmez en un clic.' : 'Find the key details and confirm in one click.',
                        kicker: $isFrench ? 'Rappel' : 'Reminder',
                        buttonLabel: $isFrench ? 'Confirmer' : 'Confirm',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Informations utiles' : 'Useful details',
                        body: $isFrench ? 'Date: {appointmentDate}\nHeure: {appointmentTime}\nLieu: {appointmentLocation}' : 'Date: {appointmentDate}\nTime: {appointmentTime}\nLocation: {appointmentLocation}'
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Besoin de modifier ?' : 'Need to reschedule?',
                        body: $isFrench ? 'Contactez-nous si vous devez changer le rendez-vous.' : 'Contact us if you need to change the appointment.',
                        buttonLabel: $isFrench ? 'Nous contacter' : 'Contact us',
                        buttonUrl: '{brandContactUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'campagne-services',
                language: $language,
                name: $isFrench ? 'Campagne services' : 'Service campaign',
                description: $isFrench ? 'Mise en avant simple de vos prestations.' : 'Simple layout for presenting your services.',
                campaignType: Campaign::TYPE_NEW_OFFER,
                tags: ['services', 'campaign'],
                subject: $isFrench ? '{offerName}: une prestation a reserver' : '{offerName}: a service ready to book',
                previewText: $isFrench ? 'Un format simple pour mieux vendre vos services.' : 'A simple format to better sell your services.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Mettez votre service en avant' : 'Put your service in the spotlight',
                        body: $isFrench ? 'Un hero clair, un argumentaire lisible et un CTA visible.' : 'A clear hero, readable message, and visible CTA.',
                        kicker: $isFrench ? 'Service a la une' : 'Featured service',
                        buttonLabel: $isFrench ? 'Reserver' : 'Book now',
                        buttonUrl: '{trackedCtaUrl}',
                        imageUrl: '{offerImageUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Ce que vous proposez' : 'What you offer',
                        body: '{serviceName}'."\n".'{serviceCategory}'."\n".'{offerPrice}'
                    ),
                    $this->simpleBlock(
                        title: $isFrench ? 'Pourquoi choisir votre equipe' : 'Why choose your team',
                        body: $isFrench ? "Expertise\nDisponibilite\nPresentation propre sur mobile" : "Expertise\nAvailability\nClean presentation on mobile"
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Planifier maintenant' : 'Schedule now',
                        body: $isFrench ? 'Dirigez vos clients vers la reservation ou la prise de contact.' : 'Send customers to booking or direct contact.',
                        buttonLabel: $isFrench ? 'Prendre rendez-vous' : 'Book now',
                        buttonUrl: '{brandBookingUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'campagne-produits',
                language: $language,
                name: $isFrench ? 'Campagne produits' : 'Product campaign',
                description: $isFrench ? 'Format catalogue simple pour produits.' : 'Simple catalog format for products.',
                campaignType: Campaign::TYPE_NEW_OFFER,
                tags: ['products', 'catalog'],
                subject: $isFrench ? '{offerName} est a l affiche cette semaine' : '{offerName} is in the spotlight this week',
                previewText: $isFrench ? 'Une presentation plus nette pour vos produits.' : 'A cleaner presentation for your products.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? '{offerName} est a l affiche' : '{offerName} is in the spotlight',
                        body: $isFrench ? 'Mettez un produit en avant avec une image, un message court et un CTA.' : 'Highlight a product with an image, a short message, and a CTA.',
                        kicker: $isFrench ? 'Selection' : 'Selection',
                        buttonLabel: $isFrench ? 'Voir le produit' : 'View product',
                        buttonUrl: '{trackedCtaUrl}',
                        imageUrl: '{offerImageUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Produit principal' : 'Main product',
                        body: '{productName}'."\n".'{productPrice}'."\n".'{offerAvailability}'
                    ),
                    $this->simpleBlock(
                        title: $isFrench ? 'Pourquoi il attire' : 'Why it stands out',
                        body: $isFrench ? "Visuel fort\nPrix visible\nAction immediate" : "Strong visual\nVisible price\nImmediate action"
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Continuer la decouverte' : 'Keep exploring',
                        body: $isFrench ? 'Ajoutez un lien vers votre boutique, catalogue ou collection.' : 'Add a link to your store, catalog, or collection.',
                        buttonLabel: $isFrench ? 'Voir la boutique' : 'Visit the store',
                        buttonUrl: '{brandWebsiteUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'institutionnel',
                language: $language,
                name: $isFrench ? 'Message institutionnel' : 'Institutional update',
                description: $isFrench ? 'Format corporate simple et credible.' : 'Simple and credible corporate format.',
                campaignType: Campaign::TYPE_ANNOUNCEMENT,
                tags: ['institutional', 'brand'],
                subject: $isFrench ? 'Actualite de {brandName}' : 'An update from {brandName}',
                previewText: $isFrench ? 'Une communication corporate plus claire.' : 'A cleaner corporate communication.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Un message de {brandName}' : 'A message from {brandName}',
                        body: $isFrench ? 'Diffusez une information importante dans un format plus propre et plus structure.' : 'Share important information in a cleaner and more structured format.',
                        kicker: $isFrench ? 'Actualite entreprise' : 'Company update',
                        buttonLabel: $isFrench ? 'En savoir plus' : 'Learn more',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Le message' : 'The message',
                        body: '{brandDescription}'
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Restez connecte a {brandName}' : 'Stay connected to {brandName}',
                        body: $isFrench ? 'Ajoutez ici la conclusion ou la prochaine action attendue.' : 'Use this area for the conclusion or the next expected action.',
                        buttonLabel: $isFrench ? 'Visiter le site' : 'Visit the site',
                        buttonUrl: '{brandWebsiteUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'offre-croisee',
                language: $language,
                name: $isFrench ? 'Offre croisee / cross-sell' : 'Cross-sell offer',
                description: $isFrench ? 'Format simple pour produit ou service complementaire.' : 'Simple format for complementary offers.',
                campaignType: Campaign::TYPE_CROSS_SELL,
                tags: ['cross-sell', 'upsell'],
                subject: $isFrench ? 'Une suggestion complementaire pour vous, {firstName}' : 'A complementary suggestion for you, {firstName}',
                previewText: $isFrench ? 'Recommandez plus simplement, avec plus de clarte.' : 'Recommend more clearly with a simpler layout.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Une suggestion qui complete votre besoin' : 'A suggestion that completes the need',
                        body: $isFrench ? 'Proposez un produit ou service complementaire dans un format plus lisible.' : 'Present a complementary product or service in a clearer format.',
                        kicker: $isFrench ? 'Suggestion' : 'Suggestion',
                        buttonLabel: $isFrench ? 'Decouvrir' : 'Discover',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Pourquoi cette recommandation' : 'Why this recommendation',
                        body: $isFrench ? "Pertinente\nClaire\nDirectement actionnable" : "Relevant\nClear\nEasy to act on"
                    ),
                    $this->simpleBlock(
                        title: $isFrench ? 'Offre recommandee' : 'Recommended offer',
                        body: '{offerName}'."\n".'{offerPrice}',
                        buttonLabel: $isFrench ? 'Voir la recommandation' : 'See recommendation',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: $isFrench ? 'Besoin d aide ?' : 'Need help?',
                        body: $isFrench ? 'Ajoutez un dernier point de contact avant le footer entreprise.' : 'Add a last contact point before the business footer.',
                        buttonLabel: $isFrench ? 'Contacter l equipe' : 'Contact the team',
                        buttonUrl: '{brandContactUrl}'
                    ),
                ]
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function spanishPresets(): array
    {
        return [
            $this->preset(
                key: 'promotion-premium',
                language: 'ES',
                name: 'Promocion premium',
                description: 'Hero simple, argumentos clave y CTA claro.',
                campaignType: Campaign::TYPE_PROMOTION,
                tags: ['promotion', 'premium'],
                subject: '{firstName}, aprovecha {promoPercent}% de descuento en {offerName}',
                previewText: 'Una promocion clara y orientada a la conversion.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: '{promoPercent}% de descuento en {offerName}',
                        body: 'Destaca tu oferta con un mensaje directo, una imagen fuerte y un boton bien visible.',
                        kicker: 'Oferta del momento',
                        buttonLabel: 'Ver oferta',
                        buttonUrl: '{trackedCtaUrl}',
                        imageUrl: '{offerImageUrl}'
                    ),
                    $this->simpleBlock(
                        title: '{offerPrice}',
                        body: "Codigo {promoCode}\nHasta {promoEndDate}",
                        kicker: 'Detalles de la oferta'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: 'Por que hacer clic',
                        body: "Una oferta clara\nUn beneficio inmediato\nUna accion simple"
                    ),
                    $this->simpleBlock(
                        title: 'Lo que destacas',
                        body: "{offerName}\n{offerAvailability}\nPresentacion limpia en movil y escritorio."
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: 'Actua ahora mientras la oferta sigue activa',
                        body: 'Aprovecha la atencion de tus clientes mientras la oferta sigue disponible.',
                        buttonLabel: 'Quiero aprovecharla',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'relance-client',
                language: 'ES',
                name: 'Seguimiento de cliente',
                description: 'Seguimiento simple y premium para reactivar.',
                campaignType: Campaign::TYPE_WINBACK,
                tags: ['winback', 'follow-up'],
                subject: '{firstName}, tenemos algo para ti',
                previewText: 'Un seguimiento mas elegante y humano.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: 'Pensamos en ti',
                        body: 'Desde tu ultima compra el {lastOrderDate}, nuestra oferta ha evolucionado.',
                        kicker: 'Seguimiento',
                        buttonLabel: 'Descubrir',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: 'Lo que cambia',
                        body: "Un mensaje mas claro\nUna oferta mejor presentada\nUna relacion mas directa con tu equipo"
                    ),
                    $this->simpleBlock(
                        title: 'Por que volver',
                        body: 'Devuelve a tus clientes una razon real para regresar con un enfoque mas premium.'
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: 'Lo hablamos?',
                        body: 'Puedes hacer clic o simplemente responder a este correo.',
                        buttonLabel: 'Volver ahora',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'nouveaute-lancement',
                language: 'ES',
                name: 'Anuncio de novedad',
                description: 'Plantilla simple para lanzamiento o novedad.',
                campaignType: Campaign::TYPE_ANNOUNCEMENT,
                tags: ['announcement', 'launch'],
                subject: 'Nuevo de {brandName}: {offerName}',
                previewText: 'Convierte un anuncio en un verdadero momento de marca.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: 'Conoce {offerName}',
                        body: 'Una novedad presentada en un formato mas limpio, claro y convincente.',
                        kicker: 'Novedad',
                        buttonLabel: 'Explorar',
                        buttonUrl: '{trackedCtaUrl}',
                        imageUrl: '{offerImageUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: 'Lo que anuncias',
                        body: "{offerName}\n{offerPrice}\n{offerAvailability}"
                    ),
                    $this->simpleBlock(
                        title: 'Por que importa',
                        body: 'Un anuncio mejor trabajado refuerza la credibilidad de tu marca.'
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: 'Descubre la novedad',
                        body: '{brandDescription}',
                        buttonLabel: 'Acceder a la novedad',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'offre-speciale',
                language: 'ES',
                name: 'Oferta especial',
                description: 'Formato corto y de alto impacto.',
                campaignType: Campaign::TYPE_PROMOTION,
                tags: ['offer', 'flash'],
                subject: 'Oferta especial: {promoPercent}% hasta {promoEndDate}',
                previewText: 'Corto, legible y orientado a la accion.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: '{promoPercent}% en {offerName}',
                        body: 'Un formato simple para impulsar una campaña flash sin sobrecarga visual.',
                        kicker: 'Tiempo limitado',
                        buttonLabel: 'Activar ahora',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: 'Recordatorio rapido',
                        body: "Codigo {promoCode}\nCaduca el {promoEndDate}"
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: 'No dejes pasar esta oferta',
                        body: 'Un ultimo empuje limpio pensado para provocar el clic.',
                        buttonLabel: 'Quiero la oferta',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'fidelisation-premium',
                language: 'ES',
                name: 'Fidelizacion premium',
                description: 'Plantilla relacional para agradecer y fidelizar.',
                campaignType: Campaign::TYPE_CROSS_SELL,
                tags: ['loyalty', 'retention'],
                subject: 'Gracias {firstName}, te espera una ventaja exclusiva',
                previewText: 'Un correo mas valioso para tus mejores clientes.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: 'Gracias por tu confianza',
                        body: 'Tus mejores clientes merecen un mensaje mas premium y personal.',
                        kicker: 'Fidelidad',
                        buttonLabel: 'Ver mi ventaja',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: 'Tus ventajas',
                        body: "Atencion prioritaria\nOfertas exclusivas\nRelacion directa con tu equipo"
                    ),
                    $this->simpleBlock(
                        title: 'Por que importa',
                        body: 'Un buen correo de fidelizacion debe hacer sentir al cliente que realmente importa.'
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: 'Aprovechalo ahora',
                        body: '{brandFooterNote}',
                        buttonLabel: 'Acceder a mi ventaja',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'rappel-rendez-vous',
                language: 'ES',
                name: 'Recordatorio de cita',
                description: 'Recordatorio claro para citas o intervenciones.',
                campaignType: Campaign::TYPE_ANNOUNCEMENT,
                tags: ['reminder', 'appointment'],
                subject: 'Recordatorio: tu cita se acerca',
                previewText: 'Fecha, hora y accion en un formato simple.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: 'Tu cita se acerca',
                        body: 'Consulta los datos utiles y confirma con un clic.',
                        kicker: 'Recordatorio',
                        buttonLabel: 'Confirmar',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: 'Informacion util',
                        body: "Fecha: {appointmentDate}\nHora: {appointmentTime}\nLugar: {appointmentLocation}"
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: 'Necesitas cambiarla?',
                        body: 'Contactanos si necesitas modificar la cita.',
                        buttonLabel: 'Contactarnos',
                        buttonUrl: '{brandContactUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'campagne-services',
                language: 'ES',
                name: 'Campaña de servicios',
                description: 'Formato simple para destacar tus servicios.',
                campaignType: Campaign::TYPE_NEW_OFFER,
                tags: ['services', 'campaign'],
                subject: '{offerName}: un servicio listo para reservar',
                previewText: 'Un formato simple para vender mejor tus servicios.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: 'Pon tu servicio en primer plano',
                        body: 'Un hero claro, un mensaje legible y un CTA visible.',
                        kicker: 'Servicio destacado',
                        buttonLabel: 'Reservar',
                        buttonUrl: '{trackedCtaUrl}',
                        imageUrl: '{offerImageUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: 'Lo que ofreces',
                        body: "{serviceName}\n{serviceCategory}\n{offerPrice}"
                    ),
                    $this->simpleBlock(
                        title: 'Por que elegir a tu equipo',
                        body: "Experiencia\nDisponibilidad\nPresentacion limpia en movil"
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: 'Planifica ahora',
                        body: 'Dirige a tus clientes hacia la reserva o el contacto directo.',
                        buttonLabel: 'Reservar cita',
                        buttonUrl: '{brandBookingUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'campagne-produits',
                language: 'ES',
                name: 'Campaña de productos',
                description: 'Formato de catalogo simple para productos.',
                campaignType: Campaign::TYPE_NEW_OFFER,
                tags: ['products', 'catalog'],
                subject: '{offerName} es el producto destacado de esta semana',
                previewText: 'Una presentacion mas limpia para tus productos.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: '{offerName} esta en primer plano',
                        body: 'Destaca un producto con imagen, mensaje corto y CTA.',
                        kicker: 'Seleccion',
                        buttonLabel: 'Ver producto',
                        buttonUrl: '{trackedCtaUrl}',
                        imageUrl: '{offerImageUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: 'Producto principal',
                        body: "{productName}\n{productPrice}\n{offerAvailability}"
                    ),
                    $this->simpleBlock(
                        title: 'Por que destaca',
                        body: "Visual potente\nPrecio visible\nAccion inmediata"
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: 'Seguir descubriendo',
                        body: 'Agrega un enlace a tu tienda, catalogo o coleccion.',
                        buttonLabel: 'Ver tienda',
                        buttonUrl: '{brandWebsiteUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'institutionnel',
                language: 'ES',
                name: 'Mensaje institucional',
                description: 'Formato corporativo simple y creible.',
                campaignType: Campaign::TYPE_ANNOUNCEMENT,
                tags: ['institutional', 'brand'],
                subject: 'Novedades de {brandName}',
                previewText: 'Una comunicacion corporativa mas clara.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: 'Un mensaje de {brandName}',
                        body: 'Comparte informacion importante en un formato mas limpio y estructurado.',
                        kicker: 'Actualidad de la empresa',
                        buttonLabel: 'Saber mas',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: 'El mensaje',
                        body: '{brandDescription}'
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: 'Sigue conectado con {brandName}',
                        body: 'Usa este espacio para la conclusion o la siguiente accion esperada.',
                        buttonLabel: 'Visitar el sitio',
                        buttonUrl: '{brandWebsiteUrl}'
                    ),
                ]
            ),
            $this->preset(
                key: 'offre-croisee',
                language: 'ES',
                name: 'Oferta cruzada',
                description: 'Formato simple para productos o servicios complementarios.',
                campaignType: Campaign::TYPE_CROSS_SELL,
                tags: ['cross-sell', 'upsell'],
                subject: 'Una sugerencia complementaria para ti, {firstName}',
                previewText: 'Recomienda con mas claridad y simplicidad.',
                headerBlocks: [
                    $this->simpleBlock(
                        title: 'Una sugerencia que completa la necesidad',
                        body: 'Presenta un producto o servicio complementario en un formato mas claro.',
                        kicker: 'Sugerencia',
                        buttonLabel: 'Descubrir',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ],
                bodyBlocks: [
                    $this->simpleBlock(
                        title: 'Por que esta recomendacion',
                        body: "Relevante\nClara\nFacil de accionar"
                    ),
                    $this->simpleBlock(
                        title: 'Oferta recomendada',
                        body: "{offerName}\n{offerPrice}",
                        buttonLabel: 'Ver recomendacion',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ],
                footerBlocks: [
                    $this->simpleBlock(
                        title: 'Necesitas ayuda?',
                        body: 'Agrega un ultimo punto de contacto antes del pie de empresa.',
                        buttonLabel: 'Contactar al equipo',
                        buttonUrl: '{brandContactUrl}'
                    ),
                ]
            ),
        ];
    }
}
