<?php

use App\Models\PlatformPage;
use App\Support\PublicIndustryPageSections;
use App\Support\PublicPageStockImages;
use App\Support\PublicProductPageNarratives;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->syncManagedPages(applyBackfill: true);
        $this->syncWelcomeAiStoryCard(applyBackfill: true);
    }

    public function down(): void
    {
        $this->syncManagedPages(applyBackfill: false);
        $this->syncWelcomeAiStoryCard(applyBackfill: false);
    }

    private function syncManagedPages(bool $applyBackfill): void
    {
        foreach (['sales-crm', 'reservations'] as $slug) {
            $this->syncPage(
                $slug,
                fn (string $locale): array => PublicProductPageNarratives::sections($slug, $locale),
                $applyBackfill,
            );
        }

        foreach (PublicIndustryPageSections::managedSlugs() as $slug) {
            $this->syncPage(
                $slug,
                fn (string $locale): array => PublicIndustryPageSections::sections($slug, $locale),
                $applyBackfill,
            );
        }
    }

    private function syncPage(string $slug, callable $canonicalResolver, bool $applyBackfill): void
    {
        $page = PlatformPage::query()->where('slug', $slug)->first();

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
            $canonicalSections = array_values($canonicalResolver($locale));
            $sections = is_array($localeContent['sections'] ?? null) ? $localeContent['sections'] : [];

            $updatedSections = $this->syncSections($sections, $canonicalSections, $applyBackfill, $changed);
            if ($updatedSections !== $sections) {
                $locales[$localeKey]['sections'] = $updatedSections;
            }

            $updatedSharedSections = $this->syncSections($sharedSections, $canonicalSections, $applyBackfill, $changed);
            if ($updatedSharedSections !== $sharedSections) {
                $sharedSections = $updatedSharedSections;
            }
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
     * @param  array<int, mixed>  $sections
     * @param  array<int, array<string, mixed>>  $canonicalSections
     * @return array<int, mixed>
     */
    private function syncSections(array $sections, array $canonicalSections, bool $applyBackfill, bool &$changed): array
    {
        $canonicalById = [];

        foreach ($canonicalSections as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $canonicalById[strtolower(trim((string) ($section['id'] ?? '')))] = $section;
            $canonicalSections[$index] = $section;
        }

        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $sectionId = strtolower(trim((string) ($section['id'] ?? '')));
            $canonical = is_array($canonicalById[$sectionId] ?? null)
                ? $canonicalById[$sectionId]
                : (is_array($canonicalSections[$index] ?? null) ? $canonicalSections[$index] : null);

            if (! is_array($canonical)) {
                continue;
            }

            $updatedSection = $applyBackfill
                ? $this->fillMissingMedia($section, $canonical)
                : $this->clearBackfilledMedia($section, $canonical);

            if ($updatedSection !== $section) {
                $sections[$index] = $updatedSection;
                $changed = true;
            }
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $canonical
     * @return array<string, mixed>
     */
    private function fillMissingMedia(array $actual, array $canonical): array
    {
        foreach ([['image_url', 'image_alt'], ['aside_image_url', 'aside_image_alt']] as [$urlKey, $altKey]) {
            $actualUrl = trim((string) ($actual[$urlKey] ?? ''));
            $canonicalUrl = trim((string) ($canonical[$urlKey] ?? ''));

            if ($actualUrl === '' && $canonicalUrl !== '') {
                $actual[$urlKey] = $canonicalUrl;

                if (trim((string) ($actual[$altKey] ?? '')) === '' && trim((string) ($canonical[$altKey] ?? '')) !== '') {
                    $actual[$altKey] = $canonical[$altKey];
                }
            } elseif (
                $actualUrl !== ''
                && $actualUrl === $canonicalUrl
                && trim((string) ($actual[$altKey] ?? '')) === ''
                && trim((string) ($canonical[$altKey] ?? '')) !== ''
            ) {
                $actual[$altKey] = $canonical[$altKey];
            }
        }

        foreach ($actual as $key => $value) {
            $canonicalValue = $canonical[$key] ?? null;

            if (! is_array($value) || ! is_array($canonicalValue)) {
                continue;
            }

            if (array_is_list($value) && array_is_list($canonicalValue)) {
                foreach ($value as $index => $item) {
                    if (! is_array($item) || ! is_array($canonicalValue[$index] ?? null)) {
                        continue;
                    }

                    $value[$index] = $this->fillMissingMedia($item, $canonicalValue[$index]);
                }

                $actual[$key] = $value;

                continue;
            }

            $actual[$key] = $this->fillMissingMedia($value, $canonicalValue);
        }

        return $actual;
    }

    /**
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $canonical
     * @return array<string, mixed>
     */
    private function clearBackfilledMedia(array $actual, array $canonical): array
    {
        foreach ([['image_url', 'image_alt'], ['aside_image_url', 'aside_image_alt']] as [$urlKey, $altKey]) {
            $canonicalUrl = trim((string) ($canonical[$urlKey] ?? ''));

            if ($canonicalUrl !== '' && (($actual[$urlKey] ?? null) === $canonicalUrl)) {
                $actual[$urlKey] = '';

                if (($actual[$altKey] ?? null) === ($canonical[$altKey] ?? null)) {
                    $actual[$altKey] = '';
                }
            }
        }

        foreach ($actual as $key => $value) {
            $canonicalValue = $canonical[$key] ?? null;

            if (! is_array($value) || ! is_array($canonicalValue)) {
                continue;
            }

            if (array_is_list($value) && array_is_list($canonicalValue)) {
                foreach ($value as $index => $item) {
                    if (! is_array($item) || ! is_array($canonicalValue[$index] ?? null)) {
                        continue;
                    }

                    $value[$index] = $this->clearBackfilledMedia($item, $canonicalValue[$index]);
                }

                $actual[$key] = $value;

                continue;
            }

            $actual[$key] = $this->clearBackfilledMedia($value, $canonicalValue);
        }

        return $actual;
    }

    private function syncWelcomeAiStoryCard(bool $applyBackfill): void
    {
        $page = PlatformPage::query()->where('slug', 'welcome')->first();

        if (! $page) {
            return;
        }

        $content = is_array($page->content) ? $page->content : [];
        $locales = is_array($content['locales'] ?? null) ? $content['locales'] : [];
        $changed = false;

        foreach ($locales as $localeKey => $localeContent) {
            if (! is_array($localeContent)) {
                continue;
            }

            $locale = PublicPageStockImages::normalizeLocale((string) $localeKey);
            $visual = PublicPageStockImages::visual('office-collaboration', $locale);
            $sections = is_array($localeContent['sections'] ?? null) ? $localeContent['sections'] : [];

            foreach ($sections as $sectionIndex => $section) {
                if (! is_array($section) || ($section['layout'] ?? null) !== 'story_grid') {
                    continue;
                }

                $storyCards = is_array($section['story_cards'] ?? null) ? $section['story_cards'] : [];

                foreach ($storyCards as $cardIndex => $card) {
                    if (! is_array($card) || ($card['id'] ?? null) !== 'story-grid-card-3') {
                        continue;
                    }

                    $updatedCard = $applyBackfill
                        ? $this->fillMissingMedia($card, ['image_url' => $visual['image_url'], 'image_alt' => $visual['image_alt']])
                        : $this->clearBackfilledMedia($card, ['image_url' => $visual['image_url'], 'image_alt' => $visual['image_alt']]);

                    if ($updatedCard !== $card) {
                        $storyCards[$cardIndex] = $updatedCard;
                        $sections[$sectionIndex]['story_cards'] = $storyCards;
                        $locales[$localeKey]['sections'] = $sections;
                        $changed = true;
                    }
                }
            }
        }

        if (! $changed) {
            return;
        }

        $content['locales'] = $locales;
        $content['updated_at'] = now()->toIso8601String();

        $page->forceFill(['content' => $content])->save();
    }
};
