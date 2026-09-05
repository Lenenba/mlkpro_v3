<?php

namespace App\Http\Controllers\Concerns;

use App\Models\PlatformAnnouncement;
use Illuminate\Validation\ValidationException;

trait HandlesPlatformAnnouncementContent
{
    /**
     * @return array<string, array<int, string>>
     */
    private function announcementContentRules(): array
    {
        $rules = [
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'link_label' => ['nullable', 'string', 'max:120'],
            'translations' => ['nullable', 'array:fr,en,es'],
        ];

        foreach (PlatformAnnouncement::CONTENT_LOCALES as $locale) {
            $rules["translations.{$locale}"] = ['nullable', 'array:title,body,link_label'];
            $rules["translations.{$locale}.title"] = ['nullable', 'string', 'max:255'];
            $rules["translations.{$locale}.body"] = ['nullable', 'string', 'max:5000'];
            $rules["translations.{$locale}.link_label"] = ['nullable', 'string', 'max:120'];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{title: string, body: ?string, link_label: ?string, translations: array<string, array<string, string>>|null}
     *
     * @throws ValidationException
     */
    private function resolveAnnouncementContent(
        array $validated,
        ?PlatformAnnouncement $announcement = null,
    ): array {
        $hasTranslations = array_key_exists('translations', $validated);
        $hasLegacyContent = collect(PlatformAnnouncement::CONTENT_FIELDS)
            ->contains(fn (string $field) => array_key_exists($field, $validated));
        $translations = match (true) {
            $hasTranslations => PlatformAnnouncement::normalizeTranslations($validated['translations'] ?? []),
            $hasLegacyContent => [],
            default => PlatformAnnouncement::normalizeTranslations($announcement?->translations),
        };

        $title = $this->announcementLegacyValue(
            $validated,
            $translations,
            'title',
            $announcement,
            $hasTranslations,
        ) ?? PlatformAnnouncement::firstTranslatedValue($translations, 'title');

        if (! filled($title)) {
            throw ValidationException::withMessages([
                'title' => __('validation.required', ['attribute' => 'title']),
            ]);
        }

        return [
            'title' => $title,
            'body' => $this->announcementLegacyValue(
                $validated,
                $translations,
                'body',
                $announcement,
                $hasTranslations,
            ),
            'link_label' => $this->announcementLegacyValue(
                $validated,
                $translations,
                'link_label',
                $announcement,
                $hasTranslations,
            ),
            'translations' => $translations !== [] ? $translations : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, array<string, string>>  $translations
     */
    private function announcementLegacyValue(
        array $validated,
        array $translations,
        string $field,
        ?PlatformAnnouncement $announcement,
        bool $hasTranslations,
    ): ?string {
        if (array_key_exists($field, $validated)) {
            $value = $validated[$field];

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        if ($hasTranslations) {
            $translatedValue = PlatformAnnouncement::firstTranslatedValue($translations, $field);
            if (filled($translatedValue)) {
                return $translatedValue;
            }

            // A partial API translation payload must not erase a legacy
            // fallback field it did not address. Explicit blank translated
            // fields still clear that value, as the multilingual form expects.
            if ($this->announcementTranslationFieldWasProvided(
                $validated['translations'] ?? null,
                $field,
            )) {
                return null;
            }
        }

        $legacyValue = $announcement?->getRawOriginal($field);

        return is_string($legacyValue) && trim($legacyValue) !== '' ? trim($legacyValue) : null;
    }

    private function announcementTranslationFieldWasProvided(mixed $translations, string $field): bool
    {
        if (! is_array($translations)) {
            return false;
        }

        foreach (PlatformAnnouncement::CONTENT_LOCALES as $locale) {
            $localized = $translations[$locale] ?? null;
            if (is_array($localized) && array_key_exists($field, $localized)) {
                return true;
            }
        }

        return false;
    }
}
