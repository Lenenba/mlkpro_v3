<?php

use App\Models\MegaMenuBlock;
use App\Models\PlatformPage;
use App\Support\PublicPageStockImages;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $legacy = PublicPageStockImages::legacyIllustrationUrls();

        PlatformPage::query()
            ->whereIn('slug', PublicPageStockImages::managedPageSlugs())
            ->get()
            ->each(function (PlatformPage $page) use ($legacy) {
                $content = is_array($page->content) ? $page->content : [];
                $locales = is_array($content['locales'] ?? null) ? $content['locales'] : [];
                $changed = false;

                foreach ($locales as $localeKey => $localeContent) {
                    if (! is_array($localeContent)) {
                        continue;
                    }

                    $normalizedLocale = PublicPageStockImages::normalizeLocale((string) $localeKey);
                    $updatedLocale = $this->refreshLocaleContent($page->slug, $localeContent, $normalizedLocale, $legacy);

                    if ($updatedLocale !== $localeContent) {
                        $locales[$localeKey] = $updatedLocale;
                        $changed = true;
                    }
                }

                if (! $changed) {
                    return;
                }

                $content['locales'] = $locales;
                $content['updated_at'] = now()->toIso8601String();
                $page->content = $content;
                $page->save();
            });

        MegaMenuBlock::query()
            ->where('type', 'product_showcase')
            ->get()
            ->each(function (MegaMenuBlock $block) {
                $payload = is_array($block->payload) ? $block->payload : [];
                $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
                $changed = false;

                foreach ($items as $index => $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $slug = $this->slugFromHref($item['href'] ?? '');
                    if ($slug === '' || ! in_array($slug, PublicPageStockImages::managedPageSlugs(), true)) {
                        continue;
                    }

                    $visual = PublicPageStockImages::slot($slug, 'header', 'en');
                    $items[$index]['image_url'] = $visual['image_url'];
                    $items[$index]['image_alt'] = $visual['image_alt'];
                    $changed = true;
                }

                if (! $changed) {
                    return;
                }

                $payload['items'] = $items;
                $block->payload = $payload;
                $block->save();
            });
    }

    public function down(): void
    {
        // Intentionally left blank: previous illustration assets were deprecated.
    }

    /**
     * @param  array<int, string>  $legacy
     * @return array<string, mixed>
     */
    private function refreshLocaleContent(string $slug, array $content, string $locale, array $legacy): array
    {
        $used = [];
        $header = is_array($content['header'] ?? null) ? $content['header'] : [];
        $headerVisual = PublicPageStockImages::slot($slug, 'header', $locale);
        $header['background_type'] = trim((string) ($header['background_type'] ?? '')) ?: 'image';
        $header['alignment'] = trim((string) ($header['alignment'] ?? 'center')) ?: 'center';
        $header['background_color'] = trim((string) ($header['background_color'] ?? ''));
        $header = $this->refreshImageField(
            $header,
            'background_image_url',
            'background_image_alt',
            $headerVisual,
            $used,
            $legacy
        );
        $content['header'] = $header;

        $sections = is_array($content['sections'] ?? null) ? $content['sections'] : [];
        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $slot = $this->sectionSlot($slug, (string) ($section['id'] ?? ''));
            if ($slot === null) {
                continue;
            }

            $sections[$index] = $this->refreshImageField(
                $section,
                'image_url',
                'image_alt',
                PublicPageStockImages::slot($slug, $slot, $locale),
                $used,
                $legacy
            );
        }

        $content['sections'] = $sections;

        return $content;
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array{image_alt:string,image_url:string}  $visual
     * @param  array<string, bool>  $used
     * @param  array<int, string>  $legacy
     * @return array<string, mixed>
     */
    private function refreshImageField(
        array $target,
        string $urlKey,
        string $altKey,
        array $visual,
        array &$used,
        array $legacy
    ): array {
        $current = $this->normalizeImagePath($target[$urlKey] ?? '');
        $mustReplace = $current === ''
            || in_array($current, $legacy, true)
            || preg_match('#^/images/(landing|mega-menu)/.+\.svg$#i', $current) === 1
            || isset($used[$current]);

        if ($mustReplace) {
            $target[$urlKey] = $visual['image_url'];
            $target[$altKey] = $visual['image_alt'];
            $used[$this->normalizeImagePath($visual['image_url'])] = true;

            return $target;
        }

        $used[$current] = true;
        $target[$urlKey] = trim((string) ($target[$urlKey] ?? ''));
        $target[$altKey] = trim((string) ($target[$altKey] ?? ''));

        return $target;
    }

    private function normalizeImagePath($value): string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);

        return strtolower(trim(is_string($path) && $path !== '' ? $path : $url));
    }

    private function slugFromHref($value): string
    {
        $href = trim((string) $value);
        if ($href === '') {
            return '';
        }

        $path = parse_url($href, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            $path = $href;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        return $segments === [] ? '' : (string) end($segments);
    }

    private function sectionSlot(string $slug, string $sectionId): ?string
    {
        $sectionId = trim($sectionId);
        if ($sectionId === '') {
            return null;
        }

        if ($slug === 'contact-us') {
            return match ($sectionId) {
                'contact-overview' => 'overview',
                'contact-details' => 'details',
                default => null,
            };
        }

        if ($slug === 'partners') {
            return $sectionId === 'partners-overview' ? 'overview' : null;
        }

        if (str_starts_with($slug, 'solution-')) {
            if (str_contains($sectionId, 'workflow')) {
                return 'workflow';
            }

            if (str_contains($sectionId, 'modules')) {
                return 'modules';
            }

            return 'overview';
        }

        if (str_starts_with($slug, 'industry-')) {
            return str_contains($sectionId, 'workflow') ? 'workflow' : 'overview';
        }

        return match ($sectionId) {
            'overview' => 'overview',
            'workflow' => 'workflow',
            'pages' => 'pages',
            default => null,
        };
    }
};
