import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { gzipSync } from 'node:zlib';

import { getDomainsForPage } from '../resources/js/i18n/domains.js';

const projectRoot = resolve(import.meta.dirname, '..');
const manifestPath = resolve(projectRoot, 'public/build/manifest.json');

const argumentValue = (name, fallback) => {
    const index = process.argv.indexOf(name);

    return index === -1 ? fallback : (process.argv[index + 1] || fallback);
};

const locale = argumentValue('--locale', 'fr');
const pageComponent = argumentValue('--page', 'Dashboard');
const fallbackLocale = argumentValue('--fallback', 'en');
const mode = argumentValue('--mode', null);

if (! ['domain', 'legacy-full-catalog'].includes(mode)) {
    throw new Error('Le paramètre --mode doit être "domain" ou "legacy-full-catalog".');
}

if (! existsSync(manifestPath)) {
    throw new Error('Manifest Vite introuvable. Exécutez d’abord npm run qa:build.');
}

const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
const locales = [...new Set([locale, fallbackLocale])];
const domains = getDomainsForPage(pageComponent);
const moduleKeys = locales.flatMap((localeKey) => (
    domains.map((domain) => `resources/js/i18n/modules/${localeKey}/${domain}.json`)
));
const entryKeys = mode === 'domain'
    ? moduleKeys
    : locales.map((localeKey) => `resources/js/i18n/locales/${localeKey}.js`);
const visited = new Set();

const collectAsset = (entryKey) => {
    // Le bundle applicatif est déjà chargé avant l’import dynamique du catalogue.
    // Vite le référence dans le cycle d’import du catalogue complet, mais il ne
    // représente pas un coût additionnel du chargement i18n.
    if (entryKey === 'resources/js/app.js') {
        return [];
    }

    if (visited.has(entryKey)) {
        return [];
    }

    visited.add(entryKey);

    const entry = manifest[entryKey];
    if (! entry?.file) {
        throw new Error(`Entrée Vite i18n absente : ${entryKey}`);
    }

    return [entry.file, ...(entry.imports || []).flatMap(collectAsset)];
};

const assets = [...new Set(entryKeys.flatMap(collectAsset))];
const measurements = assets.map((asset) => {
    const contents = readFileSync(resolve(projectRoot, 'public/build', asset));

    return {
        asset,
        raw_bytes: contents.length,
        gzip_bytes: gzipSync(contents).length,
    };
});

const sum = (key) => measurements.reduce((total, measurement) => total + measurement[key], 0);

console.log(JSON.stringify({
    mode,
    component: pageComponent,
    locale,
    fallback_locale: fallbackLocale,
    locales,
    domains: mode === 'domain' ? domains : ['all'],
    asset_count: measurements.length,
    raw_bytes: sum('raw_bytes'),
    gzip_bytes: sum('gzip_bytes'),
    assets: measurements,
}, null, 2));
