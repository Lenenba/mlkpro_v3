<?php

use App\Models\MegaMenu;
use App\Models\MegaMenuItem;
use App\Models\PlatformPage;
use App\Models\PlatformSection;
use App\Support\MegaMenuOptions;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $industrySection = $this->ensureWelcomeIndustrySection();

        $this->syncWelcomePage($industrySection);
        $this->flattenIndustriesHeaderItem();
    }

    public function down(): void
    {
        // Forward-only content alignment.
    }

    private function ensureWelcomeIndustrySection(): PlatformSection
    {
        $section = PlatformSection::query()
            ->where('type', 'industry_grid')
            ->where('name', 'Welcome Industries')
            ->latest('id')
            ->first();

        if ($section) {
            return $section;
        }

        return PlatformSection::query()->create([
            'name' => 'Welcome Industries',
            'type' => 'industry_grid',
            'is_active' => true,
            'content' => [
                'locales' => [
                    'fr' => $this->defaultIndustryGridSection('fr'),
                    'en' => $this->defaultIndustryGridSection('en'),
                ],
                'updated_at' => now()->toIso8601String(),
                'updated_by' => null,
            ],
        ]);
    }

    private function syncWelcomePage(PlatformSection $industrySection): void
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

            $existingIndex = $this->existingIndustrySectionIndex($sections, $industrySection->id);
            $sectionStub = [
                'id' => 'industries',
                'enabled' => true,
                'source_id' => $industrySection->id,
                'use_source' => true,
                'layout' => 'industry_grid',
            ];

            if ($existingIndex !== null) {
                if (($sections[$existingIndex] ?? null) !== $sectionStub) {
                    $sections[$existingIndex] = $sectionStub;
                    $changed = true;
                }
            } else {
                array_splice(
                    $sections,
                    $this->industryInsertIndex($sections, $sourceTypes),
                    0,
                    [$sectionStub]
                );
                $changed = true;
            }

            $localeContent['sections'] = $sections;
            $locales[$locale] = $localeContent;
        }

        if (! $changed) {
            return;
        }

        $content['locales'] = $locales;
        $content['updated_at'] = now()->toIso8601String();
        $page->forceFill(['content' => $content])->save();
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
     */
    private function existingIndustrySectionIndex(array $sections, int $industrySectionId): ?int
    {
        foreach ($sections as $index => $section) {
            $sectionId = trim((string) ($section['id'] ?? ''));
            $layout = trim((string) ($section['layout'] ?? ''));
            $sourceId = (int) ($section['source_id'] ?? 0);

            if ($sectionId === 'industries' || $sourceId === $industrySectionId || $layout === 'industry_grid') {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<int, string>  $sourceTypes
     */
    private function industryInsertIndex(array $sections, array $sourceTypes): int
    {
        foreach ($sections as $index => $section) {
            $sourceId = (int) ($section['source_id'] ?? 0);
            $type = $sourceTypes[$sourceId] ?? trim((string) ($section['layout'] ?? ''));

            if ($type === 'feature_tabs') {
                return $index + 1;
            }
        }

        foreach ($sections as $index => $section) {
            $sourceId = (int) ($section['source_id'] ?? 0);
            $type = $sourceTypes[$sourceId] ?? trim((string) ($section['layout'] ?? ''));

            if ($type === 'welcome_features' || $type === 'split') {
                return $index;
            }
        }

        return count($sections);
    }

    private function flattenIndustriesHeaderItem(): void
    {
        $menu = MegaMenu::query()
            ->with('items')
            ->where('slug', 'main-header-menu')
            ->first();

        if (! $menu) {
            return;
        }

        /** @var MegaMenuItem|null $item */
        $item = $menu->items->first(function (MegaMenuItem $candidate) {
            return trim((string) $candidate->label) === 'Industries';
        });

        if (! $item) {
            return;
        }

        $settings = is_array($item->settings) ? $item->settings : [];
        $translations = is_array($settings['translations'] ?? null) ? $settings['translations'] : [];
        $translations['en'] = array_merge($translations['en'] ?? [], [
            'label' => 'Industries',
            'description' => 'Browse the industries we support.',
            'eyebrow' => 'Industries',
            'note' => 'Browse the industries we support.',
        ]);
        $settings['eyebrow'] = 'Industries';
        $settings['note'] = 'Parcourez les industries desservies.';
        $settings['translations'] = $translations;

        $item->forceFill([
            'description' => 'Parcourez les industries desservies.',
            'link_type' => MegaMenuOptions::LINK_INTERNAL_PAGE,
            'link_value' => '/#industries',
            'link_target' => MegaMenuOptions::TARGET_SELF,
            'panel_type' => MegaMenuOptions::PANEL_LINK,
            'settings' => $settings,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultIndustryGridSection(string $locale): array
    {
        if ($locale === 'fr') {
            return [
                'layout' => 'industry_grid',
                'background_color' => '#f7f2e8',
                'alignment' => 'center',
                'density' => 'normal',
                'tone' => 'default',
                'title' => 'Fier partenaire des pros du service dans plus de 50 industries.',
                'industry_cards' => [
                    ['id' => 'industry-arborists', 'label' => 'Arboristes', 'href' => '', 'icon' => 'tree-pine'],
                    ['id' => 'industry-commercial-cleaning', 'label' => 'Nettoyage commercial', 'href' => '/pages/industry-cleaning', 'icon' => 'brush-cleaning'],
                    ['id' => 'industry-construction', 'label' => 'Construction & entrepreneurs', 'href' => '', 'icon' => 'construction'],
                    ['id' => 'industry-electrical', 'label' => 'Entrepreneur electrique', 'href' => '/pages/industry-electrical', 'icon' => 'plug-zap'],
                    ['id' => 'industry-hvac', 'label' => 'HVAC', 'href' => '/pages/industry-hvac', 'icon' => 'fan'],
                    ['id' => 'industry-handyman', 'label' => 'Homme a tout faire', 'href' => '', 'icon' => 'wrench'],
                    ['id' => 'industry-landscaping', 'label' => 'Amenagement paysager', 'href' => '', 'icon' => 'shovel'],
                    ['id' => 'industry-lawn-care', 'label' => 'Entretien de pelouse', 'href' => '', 'icon' => 'leaf'],
                    ['id' => 'industry-painting', 'label' => 'Peinture', 'href' => '', 'icon' => 'paint-roller'],
                    ['id' => 'industry-plumbing', 'label' => 'Plomberie', 'href' => '/pages/industry-plumbing', 'icon' => 'shower-head'],
                    ['id' => 'industry-residential-cleaning', 'label' => 'Nettoyage residentiel', 'href' => '/pages/industry-cleaning', 'icon' => 'sparkles'],
                    ['id' => 'industry-roofing', 'label' => 'Toiture', 'href' => '', 'icon' => 'house'],
                ],
            ];
        }

        return [
            'layout' => 'industry_grid',
            'background_color' => '#f7f2e8',
            'alignment' => 'center',
            'density' => 'normal',
            'tone' => 'default',
            'title' => 'Proud partner to service pros in over 50 industries.',
            'industry_cards' => [
                ['id' => 'industry-arborists', 'label' => 'Arborists', 'href' => '', 'icon' => 'tree-pine'],
                ['id' => 'industry-commercial-cleaning', 'label' => 'Commercial Cleaning', 'href' => '/pages/industry-cleaning', 'icon' => 'brush-cleaning'],
                ['id' => 'industry-construction', 'label' => 'Construction & Contractors', 'href' => '', 'icon' => 'construction'],
                ['id' => 'industry-electrical', 'label' => 'Electrical Contractor', 'href' => '/pages/industry-electrical', 'icon' => 'plug-zap'],
                ['id' => 'industry-hvac', 'label' => 'HVAC', 'href' => '/pages/industry-hvac', 'icon' => 'fan'],
                ['id' => 'industry-handyman', 'label' => 'Handyman', 'href' => '', 'icon' => 'wrench'],
                ['id' => 'industry-landscaping', 'label' => 'Landscaping', 'href' => '', 'icon' => 'shovel'],
                ['id' => 'industry-lawn-care', 'label' => 'Lawn Care', 'href' => '', 'icon' => 'leaf'],
                ['id' => 'industry-painting', 'label' => 'Painting', 'href' => '', 'icon' => 'paint-roller'],
                ['id' => 'industry-plumbing', 'label' => 'Plumbing', 'href' => '/pages/industry-plumbing', 'icon' => 'shower-head'],
                ['id' => 'industry-residential-cleaning', 'label' => 'Residential Cleaning', 'href' => '/pages/industry-cleaning', 'icon' => 'sparkles'],
                ['id' => 'industry-roofing', 'label' => 'Roofing', 'href' => '', 'icon' => 'house'],
            ],
        ];
    }
};
