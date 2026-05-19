import { createI18n } from 'vue-i18n';
import {
    loadInitialLocaleMessages,
    loadLocaleMessages,
    normalizeLocale,
    supportedLocales,
} from './catalog';

export const createI18nInstance = async (locale) => {
    const normalizedLocale = normalizeLocale(locale);
    const messages = await loadInitialLocaleMessages(normalizedLocale);

    return createI18n({
        legacy: false,
        globalInjection: true,
        locale: normalizedLocale,
        fallbackLocale: 'en',
        messages,
    });
};

export const ensureI18nLocale = async (i18n, locale) => {
    if (! i18n) {
        return normalizeLocale(locale);
    }

    const normalizedLocale = normalizeLocale(locale);

    if (! i18n.global.availableLocales.includes(normalizedLocale)) {
        i18n.global.setLocaleMessage(normalizedLocale, await loadLocaleMessages(normalizedLocale));
    }

    i18n.global.locale.value = normalizedLocale;

    return normalizedLocale;
};
