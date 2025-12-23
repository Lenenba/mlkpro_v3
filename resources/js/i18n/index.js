import { createI18n } from 'vue-i18n';
import en from './en.json';
import fr from './fr.json';

export const supportedLocales = ['fr', 'en'];

const normalizeLocale = (locale) =>
    supportedLocales.includes(locale) ? locale : 'fr';

export const createI18nInstance = (locale) =>
    createI18n({
        legacy: false,
        globalInjection: true,
        locale: normalizeLocale(locale),
        fallbackLocale: 'en',
        messages: { fr, en },
    });
