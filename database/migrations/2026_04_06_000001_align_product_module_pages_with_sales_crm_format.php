<?php

use App\Models\PlatformPage;
use App\Support\PublicProductPageNarratives;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PlatformPage::query()
            ->whereIn('slug', PublicProductPageNarratives::slugs())
            ->get()
            ->each(function (PlatformPage $page): void {
                $content = is_array($page->content) ? $page->content : [];
                $locales = is_array($content['locales'] ?? null) ? $content['locales'] : [];
                $changed = false;

                foreach (['fr', 'en'] as $locale) {
                    $localeContent = is_array($locales[$locale] ?? null) ? $locales[$locale] : [];
                    $sections = PublicProductPageNarratives::sections($page->slug, $locale);

                    if (($localeContent['sections'] ?? null) === $sections) {
                        continue;
                    }

                    $localeContent['sections'] = $sections;
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
        // Narrative content backfill is forward-only.
    }
};
