<?php

namespace App\Services;

use App\Models\PlatformSection;

class PublicFooterSectionResolver
{
    public function resolve(?string $locale = null): array
    {
        $contentService = app(PlatformSectionContentService::class);
        $resolvedLocale = $this->normalizeLocale($locale, $contentService);

        $section = PlatformSection::query()
            ->where('type', 'footer')
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if (! $section) {
            return $contentService->defaultContent($resolvedLocale, 'footer');
        }

        return $contentService->resolveForLocale($section, $resolvedLocale);
    }

    private function normalizeLocale(?string $locale, PlatformSectionContentService $contentService): string
    {
        $value = strtolower(trim((string) ($locale ?: app()->getLocale())));

        return in_array($value, $contentService->locales(), true)
            ? $value
            : $contentService->locales()[0];
    }
}
