<?php

use App\Models\PlatformPage;
use App\Support\PublicIndustryPageSections;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PlatformPage::query()
            ->whereIn('slug', PublicIndustryPageSections::managedSlugs())
            ->get()
            ->each(function (PlatformPage $page): void {
                $content = is_array($page->content) ? $page->content : [];
                $locales = is_array($content['locales'] ?? null) ? $content['locales'] : [];
                $changed = false;

                foreach (['fr', 'en'] as $locale) {
                    $localeContent = is_array($locales[$locale] ?? null) ? $locales[$locale] : [];
                    $sections = array_values(array_filter(
                        is_array($localeContent['sections'] ?? null) ? $localeContent['sections'] : [],
                        fn ($section) => is_array($section)
                    ));

                    if (! $this->usesLegacyIndustryLayout($sections)) {
                        continue;
                    }

                    $localeContent['sections'] = PublicIndustryPageSections::sections($page->slug, $locale);
                    $locales[$locale] = $localeContent;
                    $changed = true;
                }

                if (! $changed) {
                    return;
                }

                $content['locales'] = $locales;
                $content['updated_at'] = now()->toIso8601String();

                $page->forceFill(['content' => $content])->save();
            });
    }

    public function down(): void
    {
        // Forward-only content migration.
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     */
    private function usesLegacyIndustryLayout(array $sections): bool
    {
        if (count($sections) !== 2) {
            return false;
        }

        return collect($sections)
            ->pluck('layout')
            ->every(fn ($layout) => trim((string) $layout) === 'split');
    }
};
