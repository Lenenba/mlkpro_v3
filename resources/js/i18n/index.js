import { createI18n } from 'vue-i18n';
import { localeMessages, normalizeLocale, supportedLocales } from './catalog';

export const createI18nInstance = (locale) =>
    createI18n({
        legacy: false,
        globalInjection: true,
        locale: normalizeLocale(locale),
        fallbackLocale: 'en',
        messages: localeMessages,
    });
