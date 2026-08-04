import { createI18n } from 'vue-i18n';
import {
    loadInitialLocaleMessages,
    loadPageLocaleMessages,
    normalizeLocale,
    supportedLocales,
} from './catalog';

export { normalizeLocale, supportedLocales };

export const createI18nInstance = async (locale, pageComponent = null) => {
    const normalizedLocale = normalizeLocale(locale);
    const messages = await loadInitialLocaleMessages(normalizedLocale, 'en', pageComponent);

    return createI18n({
        legacy: false,
        globalInjection: true,
        locale: normalizedLocale,
        fallbackLocale: 'en',
        messages,
    });
};

export const ensureI18nDomains = async (i18n, locale, pageComponent = null) => {
    if (! i18n) {
        return normalizeLocale(locale);
    }

    const normalizedLocale = normalizeLocale(locale);
    const messagesByLocale = await loadPageLocaleMessages(normalizedLocale, pageComponent);

    Object.entries(messagesByLocale).forEach(([localeKey, messages]) => {
        i18n.global.mergeLocaleMessage(localeKey, messages);
    });

    return normalizedLocale;
};

export const ensureI18nLocale = async (i18n, locale, pageComponent = null) => {
    const normalizedLocale = await ensureI18nDomains(i18n, locale, pageComponent);

    if (! i18n) {
        return normalizedLocale;
    }

    i18n.global.locale.value = normalizedLocale;

    return normalizedLocale;
};

export const preloadI18nPageMessages = (locale, pageComponent) => (
    loadPageLocaleMessages(locale, pageComponent)
);
