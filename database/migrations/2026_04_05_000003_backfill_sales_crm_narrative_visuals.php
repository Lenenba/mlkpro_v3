<?php

use App\Models\PlatformPage;
use App\Support\PublicPageStockImages;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->syncSalesCrmVisuals(applyBackfill: true);
    }

    public function down(): void
    {
        $this->syncSalesCrmVisuals(applyBackfill: false);
    }

    private function syncSalesCrmVisuals(bool $applyBackfill): void
    {
        $page = PlatformPage::query()->where('slug', 'sales-crm')->first();

        if (! $page) {
            return;
        }

        $content = is_array($page->content) ? $page->content : [];
        $locales = is_array($content['locales'] ?? null) ? $content['locales'] : [];
        $sharedMedia = is_array($content['shared_media'] ?? null) ? $content['shared_media'] : [];
        $sharedSections = is_array($sharedMedia['sections'] ?? null) ? $sharedMedia['sections'] : [];
        $changed = false;

        foreach ($locales as $localeKey => $localeContent) {
            if (! is_array($localeContent)) {
                continue;
            }

            $locale = PublicPageStockImages::normalizeLocale((string) $localeKey);
            $overviewVisual = PublicPageStockImages::slot('sales-crm', 'overview', $locale);
            $ctaVisual = PublicPageStockImages::slot('sales-crm', 'overview', $locale);
            $ctaAsideVisual = PublicPageStockImages::slot('command-center', 'header', $locale);
            $storyVisuals = [
                PublicPageStockImages::slot('sales-crm', 'header', $locale),
                PublicPageStockImages::slot('sales-crm', 'workflow', $locale),
                PublicPageStockImages::slot('sales-crm', 'pages', $locale),
            ];

            $sections = is_array($localeContent['sections'] ?? null) ? $localeContent['sections'] : [];

            foreach ($sections as $sectionIndex => $section) {
                if (! is_array($section)) {
                    continue;
                }

                $sectionId = strtolower(trim((string) ($section['id'] ?? '')));

                if ($sectionId === 'sales-crm-overview') {
                    $updatedSection = $applyBackfill
                        ? $this->fillImageIfEmpty($section, 'image_url', 'image_alt', $overviewVisual)
                        : $this->clearBackfilledImage($section, 'image_url', 'image_alt', $overviewVisual);
                    $sharedSection = is_array($sharedSections[$sectionIndex] ?? null) ? $sharedSections[$sectionIndex] : [];
                    $updatedSharedSection = $applyBackfill
                        ? $this->fillSharedImageIfEmpty($sharedSection, 'image_url', $overviewVisual)
                        : $this->clearBackfilledSharedImage($sharedSection, 'image_url', $overviewVisual);

                    if ($updatedSection !== $section) {
                        $sections[$sectionIndex] = $updatedSection;
                        $changed = true;
                    }

                    if ($updatedSharedSection !== $sharedSection) {
                        $sharedSections[$sectionIndex] = $updatedSharedSection;
                        $changed = true;
                    }

                    continue;
                }

                if ($sectionId === 'sales-crm-proof') {
                    $updatedSection = $section;
                    $storyCards = is_array($section['story_cards'] ?? null) ? $section['story_cards'] : [];
                    $sharedStoryCards = is_array($sharedSections[$sectionIndex]['story_cards'] ?? null)
                        ? $sharedSections[$sectionIndex]['story_cards']
                        : [];

                    foreach ($storyVisuals as $storyIndex => $storyVisual) {
                        $card = is_array($storyCards[$storyIndex] ?? null) ? $storyCards[$storyIndex] : [];
                        $updatedCard = $applyBackfill
                            ? $this->fillImageIfEmpty($card, 'image_url', 'image_alt', $storyVisual)
                            : $this->clearBackfilledImage($card, 'image_url', 'image_alt', $storyVisual);
                        $sharedStoryCard = is_array($sharedStoryCards[$storyIndex] ?? null) ? $sharedStoryCards[$storyIndex] : [];
                        $updatedSharedStoryCard = $applyBackfill
                            ? $this->fillSharedImageIfEmpty($sharedStoryCard, 'image_url', $storyVisual)
                            : $this->clearBackfilledSharedImage($sharedStoryCard, 'image_url', $storyVisual);

                        if ($updatedCard !== $card) {
                            $storyCards[$storyIndex] = $updatedCard;
                            $changed = true;
                        }

                        if ($updatedSharedStoryCard !== $sharedStoryCard) {
                            $sharedStoryCards[$storyIndex] = $updatedSharedStoryCard;
                            $changed = true;
                        }
                    }

                    $updatedSection['story_cards'] = $storyCards;
                    if ($updatedSection !== $section) {
                        $sections[$sectionIndex] = $updatedSection;
                    }

                    $sharedSection = is_array($sharedSections[$sectionIndex] ?? null) ? $sharedSections[$sectionIndex] : [];
                    $updatedSharedSection = [
                        ...$sharedSection,
                        'story_cards' => $sharedStoryCards,
                    ];

                    if ($updatedSharedSection !== $sharedSection) {
                        $sharedSections[$sectionIndex] = $updatedSharedSection;
                        $changed = true;
                    }

                    continue;
                }

                if ($sectionId === 'sales-crm-cta') {
                    $updatedSection = $applyBackfill
                        ? $this->fillImageIfEmpty($section, 'image_url', 'image_alt', $ctaVisual)
                        : $this->clearBackfilledImage($section, 'image_url', 'image_alt', $ctaVisual);

                    $updatedSection = $applyBackfill
                        ? $this->fillImageIfEmpty($updatedSection, 'aside_image_url', 'aside_image_alt', $ctaAsideVisual)
                        : $this->clearBackfilledImage($updatedSection, 'aside_image_url', 'aside_image_alt', $ctaAsideVisual);
                    $sharedSection = is_array($sharedSections[$sectionIndex] ?? null) ? $sharedSections[$sectionIndex] : [];
                    $updatedSharedSection = $applyBackfill
                        ? $this->fillSharedImageIfEmpty($sharedSection, 'image_url', $ctaVisual)
                        : $this->clearBackfilledSharedImage($sharedSection, 'image_url', $ctaVisual);
                    $updatedSharedSection = $applyBackfill
                        ? $this->fillSharedImageIfEmpty($updatedSharedSection, 'aside_image_url', $ctaAsideVisual)
                        : $this->clearBackfilledSharedImage($updatedSharedSection, 'aside_image_url', $ctaAsideVisual);

                    if ($updatedSection !== $section) {
                        $sections[$sectionIndex] = $updatedSection;
                        $changed = true;
                    }

                    if ($updatedSharedSection !== $sharedSection) {
                        $sharedSections[$sectionIndex] = $updatedSharedSection;
                        $changed = true;
                    }
                }
            }

            $locales[$localeKey]['sections'] = $sections;
        }

        if (! $changed) {
            return;
        }

        $sharedMedia['sections'] = $sharedSections;
        $content['locales'] = $locales;
        $content['shared_media'] = $sharedMedia;
        $content['updated_at'] = now()->toIso8601String();

        $page->forceFill(['content' => $content])->save();
    }

    /**
     * @param  array{image_alt:string,image_url:string}  $visual
     * @return array<string, mixed>
     */
    private function fillImageIfEmpty(array $target, string $urlKey, string $altKey, array $visual): array
    {
        if (trim((string) ($target[$urlKey] ?? '')) !== '') {
            return $target;
        }

        $target[$urlKey] = $visual['image_url'];
        $target[$altKey] = $visual['image_alt'];

        return $target;
    }

    /**
     * @param  array{image_alt:string,image_url:string}  $visual
     * @return array<string, mixed>
     */
    private function clearBackfilledImage(array $target, string $urlKey, string $altKey, array $visual): array
    {
        if (($target[$urlKey] ?? null) !== $visual['image_url']) {
            return $target;
        }

        $target[$urlKey] = '';

        if (($target[$altKey] ?? null) === $visual['image_alt']) {
            $target[$altKey] = '';
        }

        return $target;
    }

    /**
     * @param  array{image_alt:string,image_url:string}  $visual
     * @return array<string, mixed>
     */
    private function fillSharedImageIfEmpty(array $target, string $urlKey, array $visual): array
    {
        if (trim((string) ($target[$urlKey] ?? '')) !== '') {
            return $target;
        }

        $target[$urlKey] = $visual['image_url'];

        return $target;
    }

    /**
     * @param  array{image_alt:string,image_url:string}  $visual
     * @return array<string, mixed>
     */
    private function clearBackfilledSharedImage(array $target, string $urlKey, array $visual): array
    {
        if (($target[$urlKey] ?? null) !== $visual['image_url']) {
            return $target;
        }

        $target[$urlKey] = '';

        return $target;
    }
};
