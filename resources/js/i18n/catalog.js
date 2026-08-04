import {
    createDomainMessageLoader,
    isDomainLoadingEnabled as resolveDomainLoadingEnabled,
    normalizeLocale,
    supportedLocales,
} from './domain-loader';
import { getDomainsForPage, translationModules } from './domains';

export { normalizeLocale, supportedLocales };

const legacyLocaleLoaders = {
    fr: () => import('./locales/fr'),
    en: () => import('./locales/en'),
    es: () => import('./locales/es'),
};

const moduleLoaders = import.meta.glob('./modules/*/*.json', {
    import: 'default',
});

const domainMessageLoader = createDomainMessageLoader({
    domains: translationModules,
    loadModule: (locale, domain) => {
        const path = `./modules/${locale}/${domain}.json`;
        const loader = moduleLoaders[path];

        if (! loader) {
            throw new Error(`Missing i18n module: ${path}`);
        }

        return loader();
    },
});

const fullLocaleLoads = new Map();
const fullyLoadedLocales = new Set();

export const localeMessages = domainMessageLoader.messages;
export const isDomainLoadingEnabled = resolveDomainLoadingEnabled(import.meta.env.VITE_I18N_DOMAIN_LOADING);

const loadFullLocaleMessages = async (locale) => {
    const normalizedLocale = normalizeLocale(locale);

    if (fullyLoadedLocales.has(normalizedLocale)) {
        return localeMessages[normalizedLocale] || {};
    }

    if (fullLocaleLoads.has(normalizedLocale)) {
        return fullLocaleLoads.get(normalizedLocale);
    }

    const pending = legacyLocaleLoaders[normalizedLocale]()
        .then((module) => {
            localeMessages[normalizedLocale] = module.default || module || {};
            fullyLoadedLocales.add(normalizedLocale);

            return localeMessages[normalizedLocale];
        })
        .finally(() => {
            fullLocaleLoads.delete(normalizedLocale);
        });

    fullLocaleLoads.set(normalizedLocale, pending);

    return pending;
};

export const loadLocaleDomains = async (locale, domains) => {
    const normalizedLocale = normalizeLocale(locale);

    if (! isDomainLoadingEnabled) {
        return loadFullLocaleMessages(normalizedLocale);
    }

    return domainMessageLoader.loadLocaleDomains(normalizedLocale, domains);
};

// Cette API reste complète pour les consommateurs externes historiques. Le
// démarrage de l’application utilise loadPageLocaleMessages ci-dessous.
export const loadLocaleMessages = (locale) => loadFullLocaleMessages(locale);

export const loadPageLocaleMessages = async (locale, pageComponent, fallbackLocale = 'en') => {
    const initialLocale = normalizeLocale(locale);
    const locales = [...new Set([initialLocale, normalizeLocale(fallbackLocale)])];
    const domains = getDomainsForPage(pageComponent);

    await Promise.all(locales.map((localeKey) => loadLocaleDomains(localeKey, domains)));

    return Object.fromEntries(
        locales.map((localeKey) => [localeKey, localeMessages[localeKey] || {}]),
    );
};

export const loadInitialLocaleMessages = async (locale, fallbackLocale = 'en', pageComponent = null) => {
    if (pageComponent) {
        return loadPageLocaleMessages(locale, pageComponent, fallbackLocale);
    }

    const initialLocale = normalizeLocale(locale);
    const locales = [...new Set([initialLocale, normalizeLocale(fallbackLocale)])];
    await Promise.all(locales.map((localeKey) => loadLocaleMessages(localeKey)));

    return Object.fromEntries(
        locales.map((localeKey) => [localeKey, localeMessages[localeKey] || {}]),
    );
};

export const preloadPageLocaleMessages = loadPageLocaleMessages;
export const getLocaleMessages = loadLocaleMessages;
