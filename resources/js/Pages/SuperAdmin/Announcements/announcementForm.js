export const emptyAnnouncementTranslation = () => ({
    title: '',
    body: '',
    link_label: '',
});

export const createAnnouncementTranslations = (locales = []) => Object.fromEntries(
    locales.map((locale) => [locale, emptyAnnouncementTranslation()]),
);

export const normalizeAnnouncementTranslations = ({
    locales = [],
    defaultLocale,
    translations = {},
    legacy = {},
}) => {
    const normalized = createAnnouncementTranslations(locales);

    locales.forEach((locale) => {
        const localized = translations?.[locale] || {};
        normalized[locale] = {
            title: localized.title || '',
            body: localized.body || '',
            link_label: localized.link_label || '',
        };
    });

    const fallback = normalized[defaultLocale];

    // Materialize each legacy field only when no locale translates that
    // particular field. This makes the effective fallback visible/editable
    // and prevents a no-op save from silently deleting mixed legacy content.
    if (fallback) {
        ['title', 'body', 'link_label'].forEach((field) => {
            const hasTranslatedValue = Object.values(normalized).some(
                (localized) => Boolean(localized[field]),
            );

            if (!hasTranslatedValue && legacy[field]) {
                fallback[field] = legacy[field];
            }
        });
    }

    return normalized;
};
