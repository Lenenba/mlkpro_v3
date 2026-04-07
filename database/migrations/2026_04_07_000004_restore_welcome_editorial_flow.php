<?php

use App\Models\PlatformPage;
use App\Models\PlatformSection;
use App\Support\WelcomeEditorialSections;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $page = PlatformPage::query()->where('slug', 'welcome')->first();

        if (! $page) {
            return;
        }

        $content = is_array($page->content) ? $page->content : [];
        $locales = is_array($content['locales'] ?? null) ? $content['locales'] : [];
        $sourceTypes = $this->sourceTypesForLocales($locales);
        $changed = false;

        foreach (['fr', 'en'] as $locale) {
            $localeContent = is_array($locales[$locale] ?? null) ? $locales[$locale] : [];
            $sections = array_values(array_filter(
                is_array($localeContent['sections'] ?? null) ? $localeContent['sections'] : [],
                fn ($section) => is_array($section)
            ));

            $rebuilt = array_values(array_filter([
                $this->sourceStub('welcome-section-1', $this->firstSourceIdForType($sections, $sourceTypes, 'welcome_hero'), 'split'),
                $this->sourceStub('welcome-section-2', $this->firstSourceIdForType($sections, $sourceTypes, 'welcome_trust'), 'stack'),
                $this->sourceStub('welcome-section-3', $this->firstSourceIdForType($sections, $sourceTypes, 'feature_tabs'), 'feature_tabs'),
                $this->sourceStub('welcome-section-4', $this->firstSourceIdForType($sections, $sourceTypes, 'welcome_features'), 'stack'),
                ...WelcomeEditorialSections::genericSections($locale),
            ]));

            if ($rebuilt !== $sections) {
                $changed = true;
            }

            $localeContent['sections'] = $rebuilt;
            $locales[$locale] = $localeContent;
        }

        if (! $changed) {
            return;
        }

        $content['locales'] = $locales;
        $content['updated_at'] = now()->toIso8601String();
        $page->forceFill(['content' => $content])->save();
    }

    public function down(): void
    {
        // Forward-only welcome reset.
    }

    /**
     * @param  array<string, mixed>  $locales
     * @return array<int, string>
     */
    private function sourceTypesForLocales(array $locales): array
    {
        $ids = collect($locales)
            ->flatMap(function ($localeContent) {
                $sections = is_array($localeContent['sections'] ?? null) ? $localeContent['sections'] : [];

                return collect($sections)
                    ->pluck('source_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id);
            })
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return PlatformSection::query()
            ->whereIn('id', $ids)
            ->pluck('type', 'id')
            ->map(fn ($type) => (string) $type)
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<int, string>  $sourceTypes
     */
    private function firstSourceIdForType(array $sections, array $sourceTypes, string $type): ?int
    {
        foreach ($sections as $section) {
            $sourceId = (int) ($section['source_id'] ?? 0);
            if ($sourceId > 0 && ($sourceTypes[$sourceId] ?? null) === $type) {
                return $sourceId;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sourceStub(string $id, ?int $sourceId, string $layout): ?array
    {
        if (! $sourceId) {
            return null;
        }

        return [
            'id' => $id,
            'enabled' => true,
            'source_id' => $sourceId,
            'use_source' => true,
            'layout' => $layout,
        ];
    }
};
