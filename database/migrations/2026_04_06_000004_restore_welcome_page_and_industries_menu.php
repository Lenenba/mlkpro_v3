<?php

use App\Models\MegaMenuBlock;
use App\Models\MegaMenuColumn;
use App\Models\MegaMenuItem;
use App\Models\PlatformPage;
use App\Models\PlatformSection;
use App\Support\MegaMenuOptions;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->removeWelcomeIndustrySection();
        $this->retireWelcomeIndustryLibrarySections();
        $this->restoreIndustriesHeaderItem();
    }

    public function down(): void
    {
        // Forward-only content alignment.
    }

    private function removeWelcomeIndustrySection(): void
    {
        $page = PlatformPage::query()->where('slug', 'welcome')->first();

        if (! $page) {
            return;
        }

        $content = is_array($page->content) ? $page->content : [];
        $locales = is_array($content['locales'] ?? null) ? $content['locales'] : [];
        $industrySourceIds = PlatformSection::query()
            ->where('type', 'industry_grid')
            ->where('name', 'Welcome Industries')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $changed = false;

        foreach (['fr', 'en'] as $locale) {
            $localeContent = is_array($locales[$locale] ?? null) ? $locales[$locale] : [];
            $sections = array_values(array_filter(
                is_array($localeContent['sections'] ?? null) ? $localeContent['sections'] : [],
                fn ($section) => is_array($section)
            ));
            $filteredSections = array_values(array_filter(
                $sections,
                fn (array $section) => ! $this->isWelcomeIndustrySection($section, $industrySourceIds)
            ));

            if ($filteredSections !== $sections) {
                $changed = true;
            }

            $localeContent['sections'] = $filteredSections;
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
     * @param  array<int, int>  $industrySourceIds
     * @param  array<string, mixed>  $section
     */
    private function isWelcomeIndustrySection(array $section, array $industrySourceIds): bool
    {
        $sectionId = trim((string) ($section['id'] ?? ''));
        $sourceId = (int) ($section['source_id'] ?? 0);

        return $sectionId === 'industries'
            || in_array($sourceId, $industrySourceIds, true);
    }

    private function retireWelcomeIndustryLibrarySections(): void
    {
        PlatformSection::query()
            ->where('type', 'industry_grid')
            ->where('name', 'Welcome Industries')
            ->update([
                'is_active' => false,
            ]);
    }

    private function restoreIndustriesHeaderItem(): void
    {
        $item = MegaMenuItem::query()
            ->whereNull('parent_id')
            ->where('label', 'Industries')
            ->whereHas('menu', fn ($query) => $query->where('slug', 'main-header-menu'))
            ->first();

        if (! $item) {
            return;
        }

        $settings = is_array($item->settings) ? $item->settings : [];
        $translations = is_array($settings['translations'] ?? null) ? $settings['translations'] : [];
        $translations['en'] = [
            'label' => 'Industries',
            'description' => 'Choose an industry to open its dedicated page.',
            'eyebrow' => 'Industries',
            'note' => 'Choose an industry to open its dedicated page.',
        ];
        $settings['eyebrow'] = 'Industries';
        $settings['note'] = 'Choisissez un secteur pour ouvrir sa page dédiée.';
        $settings['translations'] = $translations;

        $item->forceFill([
            'description' => 'Choisissez un secteur pour ouvrir sa page dédiée.',
            'link_type' => MegaMenuOptions::LINK_NONE,
            'link_value' => null,
            'link_target' => MegaMenuOptions::TARGET_SELF,
            'panel_type' => MegaMenuOptions::PANEL_CLASSIC,
            'settings' => $settings,
        ])->save();

        $this->clearColumns($item);
        $item->children()->delete();

        foreach ($this->industryMenuChildren() as $index => $child) {
            $item->menu()->firstOrFail()->allItems()->create([
                'parent_id' => $item->id,
                'label' => $child['label'],
                'description' => $child['description'],
                'link_type' => MegaMenuOptions::LINK_INTERNAL_PAGE,
                'link_value' => $child['link_value'],
                'link_target' => MegaMenuOptions::TARGET_SELF,
                'panel_type' => MegaMenuOptions::PANEL_LINK,
                'is_visible' => true,
                'settings' => [
                    'translations' => [
                        'en' => [
                            'label' => $child['english_label'],
                            'description' => $child['english_description'],
                        ],
                    ],
                ],
                'sort_order' => $index,
            ]);
        }
    }

    private function clearColumns(MegaMenuItem $item): void
    {
        MegaMenuBlock::query()
            ->whereIn(
                'mega_menu_column_id',
                MegaMenuColumn::query()
                    ->select('id')
                    ->where('mega_menu_item_id', $item->id)
            )
            ->delete();

        MegaMenuColumn::query()
            ->where('mega_menu_item_id', $item->id)
            ->delete();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function industryMenuChildren(): array
    {
        return [
            [
                'label' => 'Plomberie',
                'description' => 'Flux pour equipes plomberie et service.',
                'link_value' => '/pages/industry-plumbing',
                'english_label' => 'Plumbing',
                'english_description' => 'Flows for plumbing and service teams.',
            ],
            [
                'label' => 'HVAC',
                'description' => 'Operations HVAC, maintenance et interventions.',
                'link_value' => '/pages/industry-hvac',
                'english_label' => 'HVAC',
                'english_description' => 'HVAC maintenance and service operations.',
            ],
            [
                'label' => 'Électricité',
                'description' => 'Devis, chantiers et interventions électriques.',
                'link_value' => '/pages/industry-electrical',
                'english_label' => 'Electrical',
                'english_description' => 'Quotes, projects, and electrical jobs.',
            ],
            [
                'label' => 'Nettoyage',
                'description' => 'Sites recurrents, equipes et qualite de service.',
                'link_value' => '/pages/industry-cleaning',
                'english_label' => 'Cleaning',
                'english_description' => 'Recurring sites, teams, and service quality.',
            ],
            [
                'label' => 'Salon & beauté',
                'description' => 'Réservations, rappels et fidélisation.',
                'link_value' => '/pages/industry-salon-beauty',
                'english_label' => 'Salon & Beauty',
                'english_description' => 'Bookings, reminders, and retention.',
            ],
            [
                'label' => 'Restaurant',
                'description' => 'Reservations, attente et accueil en salle.',
                'link_value' => '/pages/industry-restaurant',
                'english_label' => 'Restaurant',
                'english_description' => 'Bookings, waiting flow, and front-of-house.',
            ],
        ];
    }
};
