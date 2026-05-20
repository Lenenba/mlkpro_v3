export const supportedLocales = ['fr', 'en', 'es'];

export const normalizeLocale = (locale) =>
    supportedLocales.includes(locale) ? locale : 'fr';

const localeLoaders = {
    fr: () => import('./locales/fr'),
    en: () => import('./locales/en'),
    es: () => import('./locales/es'),
};

export const localeMessages = {};

export const loadLocaleMessages = async (locale) => {
    const normalizedLocale = normalizeLocale(locale);

    if (! localeMessages[normalizedLocale]) {
        const module = await localeLoaders[normalizedLocale]();
        localeMessages[normalizedLocale] = module.default || {};
    }

    return localeMessages[normalizedLocale];
};

export const loadInitialLocaleMessages = async (locale, fallbackLocale = 'en') => {
    const initialLocale = normalizeLocale(locale);
    const locales = [...new Set([initialLocale, normalizeLocale(fallbackLocale)])];
    await Promise.all(locales.map((localeKey) => loadLocaleMessages(localeKey)));

    return Object.fromEntries(
        locales.map((localeKey) => [localeKey, localeMessages[localeKey]]),
    );
};

export const getLocaleMessages = loadLocaleMessages;
