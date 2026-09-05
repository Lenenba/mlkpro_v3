import { deepMerge } from './locales/merge.js';

export const supportedLocales = Object.freeze(['fr', 'en', 'es']);

export const normalizeLocale = (locale) => (
    supportedLocales.includes(locale) ? locale : 'fr'
);

export const isDomainLoadingEnabled = (value) => (
    String(value ?? 'true').toLowerCase() !== 'false'
);

const uniqueDomains = (domains, availableDomains) => [...new Set(
    (Array.isArray(domains) ? domains : [domains])
        .filter((domain) => availableDomains.has(domain)),
)];

export const createDomainMessageLoader = ({ domains, loadModule }) => {
    if (typeof loadModule !== 'function') {
        throw new TypeError('loadModule must be a function.');
    }

    const availableDomains = new Set(domains);
    const loadedDomains = new Map();
    const pendingDomains = new Map();
    const messages = {};

    const loadedForLocale = (locale) => {
        if (! loadedDomains.has(locale)) {
            loadedDomains.set(locale, new Set());
        }

        return loadedDomains.get(locale);
    };

    const loadLocaleDomain = async (locale, domain) => {
        const normalizedLocale = normalizeLocale(locale);
        const loaded = loadedForLocale(normalizedLocale);

        if (! availableDomains.has(domain) || loaded.has(domain)) {
            return messages[normalizedLocale] || {};
        }

        const cacheKey = `${normalizedLocale}:${domain}`;
        if (pendingDomains.has(cacheKey)) {
            return pendingDomains.get(cacheKey);
        }

        const pending = Promise.resolve(loadModule(normalizedLocale, domain))
            .then((module) => {
                const moduleMessages = module?.default || module || {};
                messages[normalizedLocale] = deepMerge(messages[normalizedLocale] || {}, moduleMessages);
                loaded.add(domain);

                return messages[normalizedLocale];
            })
            .finally(() => {
                pendingDomains.delete(cacheKey);
            });

        pendingDomains.set(cacheKey, pending);

        return pending;
    };

    const loadLocaleDomains = async (locale, domainsToLoad) => {
        const normalizedLocale = normalizeLocale(locale);
        const resolvedDomains = uniqueDomains(domainsToLoad, availableDomains);

        await Promise.all(resolvedDomains.map((domain) => loadLocaleDomain(normalizedLocale, domain)));

        return messages[normalizedLocale] || {};
    };

    return {
        messages,
        loadLocaleDomain,
        loadLocaleDomains,
        hasLoadedDomain: (locale, domain) => loadedForLocale(normalizeLocale(locale)).has(domain),
    };
};
