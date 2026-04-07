<?php

use App\Models\PlatformSection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PlatformSection::query()
            ->where('type', 'footer')
            ->get()
            ->each(function (PlatformSection $section): void {
                $content = is_array($section->content) ? $section->content : [];
                $locales = is_array($content['locales'] ?? null) ? $content['locales'] : [];
                $changed = false;

                foreach ($locales as $locale => $localeContent) {
                    if (! is_array($localeContent)) {
                        continue;
                    }

                    $groups = is_array($localeContent['footer_groups'] ?? null)
                        ? $localeContent['footer_groups']
                        : [];

                    $cleaned = $this->cleanFooterGroups($groups);

                    if ($cleaned === $groups) {
                        continue;
                    }

                    $localeContent['footer_groups'] = $cleaned;
                    $locales[$locale] = $localeContent;
                    $changed = true;
                }

                if (! $changed) {
                    return;
                }

                $content['locales'] = $locales;
                $content['updated_at'] = now()->toIso8601String();

                $section->forceFill(['content' => $content])->save();
            });
    }

    public function down(): void
    {
        // Forward-only footer cleanup.
    }

    /**
     * @param  array<int, mixed>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function cleanFooterGroups(array $groups): array
    {
        $cleaned = [];

        foreach (array_values($groups) as $group) {
            if (! is_array($group)) {
                continue;
            }

            $id = strtolower(trim((string) ($group['id'] ?? '')));
            $title = strtolower(trim((string) ($group['title'] ?? '')));
            $links = is_array($group['links'] ?? null) ? $group['links'] : [];

            if ($id === 'solutions' || $title === 'solutions') {
                continue;
            }

            $group['links'] = array_values(array_filter($links, function ($link): bool {
                if (! is_array($link)) {
                    return false;
                }

                $id = strtolower(trim((string) ($link['id'] ?? '')));
                $href = strtolower(trim((string) ($link['href'] ?? '')));

                return ! str_starts_with($id, 'solutions')
                    && ! str_starts_with($href, '/pages/solution-');
            }));

            if ($group['links'] === [] && str_starts_with($id, 'solutions')) {
                continue;
            }

            $cleaned[] = $group;
        }

        return array_values($cleaned);
    }
};
