<?php

use App\Support\WelcomeStockImages;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_HERO = '/images/landing/hero-dashboard.svg';

    private const OLD_WORKFLOW = '/images/landing/workflow-board.svg';

    private const OLD_FIELD = '/images/landing/mobile-field.svg';

    private const OLD_COMMERCE = '/images/mega-menu/commerce-suite.svg';

    public function up(): void
    {
        $this->refreshWelcomeImages(applyNewImages: true);
    }

    public function down(): void
    {
        $this->refreshWelcomeImages(applyNewImages: false);
    }

    private function refreshWelcomeImages(bool $applyNewImages): void
    {
        $sections = DB::table('platform_sections')
            ->whereIn('type', ['welcome_hero', 'feature_tabs', 'welcome_workflow', 'welcome_field'])
            ->get(['id', 'type', 'content']);

        foreach ($sections as $section) {
            $content = is_array($section->content)
                ? $section->content
                : json_decode((string) $section->content, true);

            if (! is_array($content) || ! is_array($content['locales'] ?? null)) {
                continue;
            }

            $updatedLocales = $content['locales'];
            $changed = false;

            foreach (array_keys($updatedLocales) as $locale) {
                if (! is_array($updatedLocales[$locale] ?? null)) {
                    continue;
                }

                [$updatedLocale, $localeChanged] = match ($section->type) {
                    'welcome_hero' => $this->updateHeroLocale($updatedLocales[$locale], $locale, $applyNewImages),
                    'feature_tabs' => $this->updateShowcaseLocale($updatedLocales[$locale], $locale, $applyNewImages),
                    'welcome_workflow' => $this->updateWorkflowLocale($updatedLocales[$locale], $locale, $applyNewImages),
                    'welcome_field' => $this->updateFieldLocale($updatedLocales[$locale], $locale, $applyNewImages),
                    default => [$updatedLocales[$locale], false],
                };

                if ($localeChanged) {
                    $updatedLocales[$locale] = $updatedLocale;
                    $changed = true;
                }
            }

            if (! $changed) {
                continue;
            }

            $content['locales'] = $updatedLocales;
            $content['updated_at'] = now()->toIso8601String();

            DB::table('platform_sections')
                ->where('id', $section->id)
                ->update([
                    'content' => json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        }
    }

    private function updateHeroLocale(array $localeContent, string $locale, bool $applyNewImages): array
    {
        $normalizedLocale = WelcomeStockImages::normalizeLocale($locale);
        $targetHeroImage = $applyNewImages
            ? WelcomeStockImages::heroImage($normalizedLocale)
            : $this->oldHeroImage($normalizedLocale);
        $targetHeroSlides = $applyNewImages
            ? WelcomeStockImages::heroSlides($normalizedLocale)
            : [$this->oldHeroImage($normalizedLocale)];
        $acceptedHeroUrls = [
            self::OLD_HERO,
            WelcomeStockImages::HERO_TEAM,
            WelcomeStockImages::HERO_TABLET,
            WelcomeStockImages::WORKFLOW_PLAN,
        ];

        [$localeContent, $changed] = $this->updateImageBlock(
            $localeContent,
            $acceptedHeroUrls,
            $targetHeroImage['image_url'],
            $targetHeroImage['image_alt']
        );

        $heroImages = is_array($localeContent['hero_images'] ?? null) ? $localeContent['hero_images'] : [];
        if ($heroImages === [] || $this->allSlideUrlsAreKnown($heroImages, $acceptedHeroUrls)) {
            if ($heroImages !== $targetHeroSlides) {
                $localeContent['hero_images'] = $targetHeroSlides;
                $changed = true;
            }
        }

        return [$localeContent, $changed];
    }

    private function updateShowcaseLocale(array $localeContent, string $locale, bool $applyNewImages): array
    {
        if (! is_array($localeContent['feature_tabs'] ?? null)) {
            return [$localeContent, false];
        }

        $normalizedLocale = WelcomeStockImages::normalizeLocale($locale);
        $targets = $this->showcaseTargets($normalizedLocale, $applyNewImages);
        $changed = false;

        foreach ($localeContent['feature_tabs'] as $index => $tab) {
            if (! is_array($tab)) {
                continue;
            }

            $tabId = (string) ($tab['id'] ?? '');
            if (! isset($targets[$tabId])) {
                continue;
            }

            [$updatedTab, $tabChanged] = $this->updateImageBlock(
                $tab,
                $targets[$tabId]['accepted_urls'],
                $targets[$tabId]['image_url'],
                $targets[$tabId]['image_alt']
            );

            if ($tabChanged) {
                $localeContent['feature_tabs'][$index] = $updatedTab;
                $changed = true;
            }
        }

        if ($this->isManagedWelcomeShowcase($localeContent['feature_tabs'])) {
            $currentStyle = trim((string) ($localeContent['feature_tabs_style'] ?? ''));

            if ($applyNewImages && $currentStyle !== 'workflow') {
                $localeContent['feature_tabs_style'] = 'workflow';
                $changed = true;
            }

            if (! $applyNewImages && $currentStyle === 'workflow') {
                unset($localeContent['feature_tabs_style']);
                $changed = true;
            }
        }

        return [$localeContent, $changed];
    }

    private function updateWorkflowLocale(array $localeContent, string $locale, bool $applyNewImages): array
    {
        $normalizedLocale = WelcomeStockImages::normalizeLocale($locale);
        $target = $applyNewImages
            ? WelcomeStockImages::workflowImage($normalizedLocale)
            : $this->oldWorkflowImage($normalizedLocale);

        return $this->updateImageBlock(
            $localeContent,
            [self::OLD_WORKFLOW, WelcomeStockImages::WORKFLOW_PLAN],
            $target['image_url'],
            $target['image_alt']
        );
    }

    private function updateFieldLocale(array $localeContent, string $locale, bool $applyNewImages): array
    {
        $normalizedLocale = WelcomeStockImages::normalizeLocale($locale);
        $target = $applyNewImages
            ? WelcomeStockImages::fieldImage($normalizedLocale)
            : $this->oldFieldImage($normalizedLocale);

        return $this->updateImageBlock(
            $localeContent,
            [self::OLD_FIELD, WelcomeStockImages::FIELD_CHECKLIST],
            $target['image_url'],
            $target['image_alt']
        );
    }

    private function updateImageBlock(array $payload, array $acceptedUrls, string $targetUrl, string $targetAlt): array
    {
        $currentUrl = trim((string) ($payload['image_url'] ?? ''));
        if ($currentUrl !== '' && ! in_array($currentUrl, $acceptedUrls, true)) {
            return [$payload, false];
        }

        $changed = false;

        if ($currentUrl !== $targetUrl) {
            $payload['image_url'] = $targetUrl;
            $changed = true;
        }

        if (($payload['image_alt'] ?? null) !== $targetAlt) {
            $payload['image_alt'] = $targetAlt;
            $changed = true;
        }

        return [$payload, $changed];
    }

    private function allSlideUrlsAreKnown(array $slides, array $acceptedUrls): bool
    {
        foreach ($slides as $slide) {
            if (! is_array($slide)) {
                return false;
            }

            $slideUrl = trim((string) ($slide['image_url'] ?? ''));
            if ($slideUrl === '' || ! in_array($slideUrl, $acceptedUrls, true)) {
                return false;
            }
        }

        return true;
    }

    private function isManagedWelcomeShowcase(array $tabs): bool
    {
        foreach ($tabs as $tab) {
            if (! is_array($tab)) {
                continue;
            }

            $id = trim((string) ($tab['id'] ?? ''));
            if ($id !== '' && str_starts_with($id, 'welcome-showcase-')) {
                return true;
            }
        }

        return false;
    }

    private function showcaseTargets(string $locale, bool $applyNewImages): array
    {
        if ($applyNewImages) {
            $getNoticed = WelcomeStockImages::showcaseImage('get_noticed', $locale);
            $winJobs = WelcomeStockImages::showcaseImage('win_jobs', $locale);
            $workSmarter = WelcomeStockImages::showcaseImage('work_smarter', $locale);
            $boostProfits = WelcomeStockImages::showcaseImage('boost_profits', $locale);

            return [
                $locale === 'fr' ? 'welcome-showcase-fr-1' : 'welcome-showcase-en-1' => [
                    ...$getNoticed,
                    'accepted_urls' => [self::OLD_HERO, WelcomeStockImages::MARKETING_DESK],
                ],
                $locale === 'fr' ? 'welcome-showcase-fr-2' : 'welcome-showcase-en-2' => [
                    ...$winJobs,
                    'accepted_urls' => [self::OLD_WORKFLOW, WelcomeStockImages::WORKFLOW_PLAN],
                ],
                $locale === 'fr' ? 'welcome-showcase-fr-3' : 'welcome-showcase-en-3' => [
                    ...$workSmarter,
                    'accepted_urls' => [self::OLD_FIELD, WelcomeStockImages::FIELD_CHECKLIST],
                ],
                $locale === 'fr' ? 'welcome-showcase-fr-4' : 'welcome-showcase-en-4' => [
                    ...$boostProfits,
                    'accepted_urls' => [self::OLD_COMMERCE, WelcomeStockImages::PAYMENTS_TERMINAL],
                ],
            ];
        }

        return [
            $locale === 'fr' ? 'welcome-showcase-fr-1' : 'welcome-showcase-en-1' => [
                ...$this->oldShowcaseImage($locale, 'get_noticed'),
                'accepted_urls' => [self::OLD_HERO, WelcomeStockImages::MARKETING_DESK],
            ],
            $locale === 'fr' ? 'welcome-showcase-fr-2' : 'welcome-showcase-en-2' => [
                ...$this->oldShowcaseImage($locale, 'win_jobs'),
                'accepted_urls' => [self::OLD_WORKFLOW, WelcomeStockImages::WORKFLOW_PLAN],
            ],
            $locale === 'fr' ? 'welcome-showcase-fr-3' : 'welcome-showcase-en-3' => [
                ...$this->oldShowcaseImage($locale, 'work_smarter'),
                'accepted_urls' => [self::OLD_FIELD, WelcomeStockImages::FIELD_CHECKLIST],
            ],
            $locale === 'fr' ? 'welcome-showcase-fr-4' : 'welcome-showcase-en-4' => [
                ...$this->oldShowcaseImage($locale, 'boost_profits'),
                'accepted_urls' => [self::OLD_COMMERCE, WelcomeStockImages::PAYMENTS_TERMINAL],
            ],
        ];
    }

    private function oldHeroImage(string $locale): array
    {
        return [
            'image_url' => self::OLD_HERO,
            'image_alt' => $locale === 'fr' ? 'Apercu du tableau de bord' : 'Dashboard preview',
        ];
    }

    private function oldWorkflowImage(string $locale): array
    {
        return [
            'image_url' => self::OLD_WORKFLOW,
            'image_alt' => $locale === 'fr' ? 'Apercu du workflow' : 'Workflow board preview',
        ];
    }

    private function oldFieldImage(string $locale): array
    {
        return [
            'image_url' => self::OLD_FIELD,
            'image_alt' => $locale === 'fr' ? "Apercu d'une checklist mobile" : 'Mobile checklist preview',
        ];
    }

    private function oldShowcaseImage(string $locale, string $key): array
    {
        return match ($key) {
            'get_noticed' => [
                'image_url' => self::OLD_HERO,
                'image_alt' => $locale === 'fr' ? 'Apercu marketing' : 'Marketing preview',
            ],
            'win_jobs' => [
                'image_url' => self::OLD_WORKFLOW,
                'image_alt' => $locale === 'fr' ? 'Apercu pipeline commercial' : 'Sales workflow preview',
            ],
            'work_smarter' => [
                'image_url' => self::OLD_FIELD,
                'image_alt' => $locale === 'fr' ? 'Apercu mobile terrain' : 'Field mobile preview',
            ],
            'boost_profits' => [
                'image_url' => self::OLD_COMMERCE,
                'image_alt' => $locale === 'fr' ? 'Apercu commerce et paiements' : 'Commerce and payments preview',
            ],
            default => [
                'image_url' => self::OLD_HERO,
                'image_alt' => $locale === 'fr' ? 'Apercu marketing' : 'Marketing preview',
            ],
        };
    }
};
