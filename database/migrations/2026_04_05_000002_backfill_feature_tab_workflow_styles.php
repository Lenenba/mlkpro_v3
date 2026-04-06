<?php

use App\Models\PlatformPage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        PlatformPage::query()->each(function (PlatformPage $page): void {
            $content = is_array($page->content) ? $page->content : [];
            $locales = is_array($content['locales'] ?? null) ? $content['locales'] : [];
            $changed = false;

            foreach ($locales as $locale => $payload) {
                $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];

                foreach ($sections as $index => $section) {
                    if (! is_array($section) || ($section['layout'] ?? '') !== 'feature_tabs') {
                        continue;
                    }

                    $sectionId = Str::lower(trim((string) ($section['id'] ?? '')));
                    if ($sectionId === '' || (! Str::contains($sectionId, 'flow') && ! Str::contains($sectionId, 'workflow'))) {
                        continue;
                    }

                    $currentStyle = Str::lower(trim((string) ($section['feature_tabs_style'] ?? '')));
                    if ($currentStyle !== '') {
                        continue;
                    }

                    $sections[$index]['feature_tabs_style'] = 'workflow';
                    $changed = true;
                }

                $locales[$locale]['sections'] = $sections;
            }

            if (! $changed) {
                return;
            }

            $content['locales'] = $locales;
            $page->forceFill(['content' => $content])->save();
        });
    }

    public function down(): void
    {
        PlatformPage::query()->each(function (PlatformPage $page): void {
            $content = is_array($page->content) ? $page->content : [];
            $locales = is_array($content['locales'] ?? null) ? $content['locales'] : [];
            $changed = false;

            foreach ($locales as $locale => $payload) {
                $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];

                foreach ($sections as $index => $section) {
                    if (! is_array($section) || ($section['layout'] ?? '') !== 'feature_tabs') {
                        continue;
                    }

                    $sectionId = Str::lower(trim((string) ($section['id'] ?? '')));
                    if ($sectionId === '' || (! Str::contains($sectionId, 'flow') && ! Str::contains($sectionId, 'workflow'))) {
                        continue;
                    }

                    if (($section['feature_tabs_style'] ?? null) !== 'workflow') {
                        continue;
                    }

                    unset($sections[$index]['feature_tabs_style']);
                    $changed = true;
                }

                $locales[$locale]['sections'] = $sections;
            }

            if (! $changed) {
                return;
            }

            $content['locales'] = $locales;
            $page->forceFill(['content' => $content])->save();
        });
    }
};
