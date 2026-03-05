import { createI18n } from 'vue-i18n';
import en from './en.json';
import fr from './fr.json';
import marketingEn from './marketing.en.json';
import marketingFr from './marketing.fr.json';

export const supportedLocales = ['fr', 'en'];

const normalizeLocale = (locale) =>
    supportedLocales.includes(locale) ? locale : 'fr';

const isPlainObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

const deepMerge = (target, source) => {
    if (!isPlainObject(target) || !isPlainObject(source)) {
        return source;
    }

    const output = { ...target };
    Object.keys(source).forEach((key) => {
        const sourceValue = source[key];
        const targetValue = output[key];

        if (isPlainObject(sourceValue) && isPlainObject(targetValue)) {
            output[key] = deepMerge(targetValue, sourceValue);
            return;
        }

        output[key] = sourceValue;
    });

    return output;
};

export const createI18nInstance = (locale) =>
    createI18n({
        legacy: false,
        globalInjection: true,
        locale: normalizeLocale(locale),
        fallbackLocale: 'en',
        messages: {
            fr: deepMerge(fr, marketingFr),
            en: deepMerge(en, marketingEn),
        },
    });
