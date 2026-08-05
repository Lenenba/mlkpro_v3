import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, extname, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';

import { getDomainsForPage } from '../resources/js/i18n/domains.js';

const scriptDirectory = dirname(fileURLToPath(import.meta.url));
const projectRoot = resolve(scriptDirectory, '..');
const defaultManifestPath = resolve(projectRoot, 'public/build/manifest.json');
const defaultConfigPath = resolve(projectRoot, 'config/frontend-budgets.json');
const defaultDecisionsPath = resolve(
    projectRoot,
    'docs/audits/mlkpro-benchmark-2026-07-16/execution/DECISIONS.md',
);
const appEntry = 'resources/js/app.js';
const metricFamilies = ['javascript', 'css', 'i18n'];
const metricNames = ['raw_bytes', 'gzip_bytes'];
const stockImageScope = 'repository-local-stock-assets-only';

const readJson = (path) => JSON.parse(readFileSync(path, 'utf8'));

const readRequiredJson = (path, label) => {
    if (! existsSync(path)) {
        throw new Error(`${label} introuvable : ${path}`);
    }

    return readJson(path);
};

const assertObject = (value, label) => {
    if (! value || Array.isArray(value) || typeof value !== 'object') {
        throw new Error(`${label} doit être un objet.`);
    }
};

const assertNonNegativeInteger = (value, label) => {
    if (! Number.isSafeInteger(value) || value < 0) {
        throw new Error(`${label} doit être un entier positif ou nul.`);
    }
};

const assetType = (asset) => {
    const extension = extname(asset).toLowerCase();

    if (extension === '.js') {
        return 'javascript';
    }

    if (extension === '.css') {
        return 'css';
    }

    return null;
};

const safeAssetPath = (directory, asset) => {
    const path = resolve(directory, asset);
    const directoryWithSeparator = `${directory}${sep}`;

    if (path !== directory && ! path.startsWith(directoryWithSeparator)) {
        throw new Error(`Asset hors du répertoire de build refusé : ${asset}`);
    }

    return path;
};

const sortedUnique = (values) => [...new Set(values)].sort();

export const collectManifestClosure = (manifest, entryKeys) => {
    assertObject(manifest, 'Le manifest Vite');

    const visitedEntries = new Set();
    const assets = new Set();

    const visit = (entryKey) => {
        if (visitedEntries.has(entryKey)) {
            return;
        }

        const entry = manifest[entryKey];
        if (! entry?.file) {
            throw new Error(`Entrée Vite absente ou invalide : ${entryKey}`);
        }

        visitedEntries.add(entryKey);
        assets.add(entry.file);
        (entry.css || []).forEach((asset) => assets.add(asset));

        // Les imports dynamiques ne font volontairement pas partie d'un budget
        // de route initiale. Seuls les imports statiques déclarés par Vite sont
        // suivis ici.
        (entry.imports || []).forEach(visit);
    };

    entryKeys.forEach(visit);

    return {
        entry_keys: sortedUnique(visitedEntries),
        assets: sortedUnique(assets),
    };
};

export const measureAssets = (assets, buildDirectory) => {
    const measurements = assets
        .filter((asset) => assetType(asset))
        .map((asset) => {
            const contents = readFileSync(safeAssetPath(buildDirectory, asset));

            return {
                asset,
                type: assetType(asset),
                raw_bytes: contents.length,
                gzip_bytes: gzipSync(contents).length,
            };
        });

    return metricFamilies.reduce((metrics, family) => {
        const familyMeasurements = measurements.filter((measurement) => measurement.type === family);

        metrics[family] = {
            raw_bytes: familyMeasurements.reduce((total, measurement) => total + measurement.raw_bytes, 0),
            gzip_bytes: familyMeasurements.reduce((total, measurement) => total + measurement.gzip_bytes, 0),
            asset_count: familyMeasurements.length,
            assets: familyMeasurements,
        };

        return metrics;
    }, {});
};

const i18nEntryKeysFor = (component, locales) => {
    const domains = getDomainsForPage(component);

    return {
        domains,
        keys: locales.flatMap((locale) => (
            domains.map((domain) => `resources/js/i18n/modules/${locale}/${domain}.json`)
        )),
    };
};

export const collectRouteProfile = ({ manifest, buildDirectory, route }) => {
    const locales = sortedUnique([route.locale, route.fallback_locale]);
    const routeClosure = collectManifestClosure(manifest, [appEntry, route.entry]);
    const { domains, keys: i18nEntryKeys } = i18nEntryKeysFor(route.component, locales);
    const i18nClosure = collectManifestClosure(manifest, i18nEntryKeys);
    const routeAssets = new Set(routeClosure.assets);
    const i18nAssets = i18nClosure.assets.filter((asset) => ! routeAssets.has(asset));
    const routeMeasurements = measureAssets(routeClosure.assets, buildDirectory);
    const i18nMeasurements = measureAssets(i18nAssets, buildDirectory).javascript;

    return {
        id: route.id,
        component: route.component,
        locales,
        domains,
        route_entry_keys: routeClosure.entry_keys,
        i18n_entry_keys: i18nClosure.entry_keys,
        measured: {
            javascript: {
                raw_bytes: routeMeasurements.javascript.raw_bytes,
                gzip_bytes: routeMeasurements.javascript.gzip_bytes,
                asset_count: routeMeasurements.javascript.asset_count,
                assets: routeMeasurements.javascript.assets,
            },
            css: {
                raw_bytes: routeMeasurements.css.raw_bytes,
                gzip_bytes: routeMeasurements.css.gzip_bytes,
                asset_count: routeMeasurements.css.asset_count,
                assets: routeMeasurements.css.assets,
            },
            i18n: {
                raw_bytes: i18nMeasurements.raw_bytes,
                gzip_bytes: i18nMeasurements.gzip_bytes,
                asset_count: i18nMeasurements.asset_count,
                assets: i18nMeasurements.assets,
            },
        },
    };
};

const stockSourceDirectory = resolve(projectRoot, 'public/images/landing/stock');
const stockOptimizedDirectory = resolve(stockSourceDirectory, 'optimized');

const stockSourceKeys = () => readdirSync(stockSourceDirectory)
    .filter((file) => file.endsWith('.jpg'))
    .map((file) => file.replace(/\.jpg$/, ''))
    .sort();

export const collectStockImageProfile = (profile) => {
    if (profile.format !== 'avif' && profile.format !== 'webp') {
        throw new Error(`Format image stock non pris en charge : ${profile.format}`);
    }

    assertNonNegativeInteger(profile.width, `La largeur du profil ${profile.id}`);
    const sources = stockSourceKeys();
    const assets = sources.map((source) => `${source}-${profile.width}w.${profile.format}`);
    const measurements = assets.map((asset) => {
        const path = resolve(stockOptimizedDirectory, asset);

        if (! existsSync(path) || ! statSync(path).isFile()) {
            throw new Error(`Variante d'image stock manquante : ${relative(projectRoot, path)}`);
        }

        const encodedBytes = statSync(path).size;
        if (encodedBytes === 0) {
            throw new Error(`Variante d'image stock vide : ${relative(projectRoot, path)}`);
        }

        return { asset, encoded_bytes: encodedBytes };
    });

    return {
        id: profile.id,
        format: profile.format,
        width: profile.width,
        scope: stockImageScope,
        source_asset_count: sources.length,
        encoded_bytes: measurements.reduce((total, measurement) => total + measurement.encoded_bytes, 0),
        asset_count: measurements.length,
        assets: measurements,
    };
};

const assertMetricBudget = (budget, label) => {
    assertObject(budget, label);

    metricFamilies.forEach((family) => {
        assertObject(budget[family], `${label}.${family}`);
        metricNames.forEach((metric) => assertNonNegativeInteger(
            budget[family][metric],
            `${label}.${family}.${metric}`,
        ));
    });
};

export const validateBudgetConfig = (config) => {
    assertObject(config, 'La configuration de budgets frontend');
    assertNonNegativeInteger(config.version, 'La version de configuration');
    if (config.version < 1) {
        throw new Error('La version de configuration doit être supérieure ou égale à 1.');
    }

    if (! Array.isArray(config.routes) || config.routes.length === 0) {
        throw new Error('La configuration doit définir au moins une route surveillée.');
    }

    if (! Array.isArray(config.stock_image_profiles) || config.stock_image_profiles.length === 0) {
        throw new Error('La configuration doit définir les profils d’images stock.');
    }

    const routeIds = new Set();
    config.routes.forEach((route) => {
        assertObject(route, 'Une route surveillée');
        ['id', 'component', 'entry', 'locale', 'fallback_locale'].forEach((key) => {
            if (typeof route[key] !== 'string' || route[key].trim() === '') {
                throw new Error(`La route surveillée doit définir ${key}.`);
            }
        });
        if (routeIds.has(route.id)) {
            throw new Error(`Identifiant de route dupliqué : ${route.id}`);
        }
        routeIds.add(route.id);
        assertMetricBudget(route.baseline, `routes.${route.id}.baseline`);
        assertMetricBudget(route.maximum, `routes.${route.id}.maximum`);

        metricFamilies.forEach((family) => {
            metricNames.forEach((metric) => {
                if (route.baseline[family][metric] > route.maximum[family][metric]) {
                    throw new Error(`Le baseline ${route.id}.${family}.${metric} dépasse son plafond.`);
                }
            });
        });
    });

    const profileIds = new Set();
    const profileKeys = new Set();
    config.stock_image_profiles.forEach((profile) => {
        assertObject(profile, 'Un profil d’image stock');
        ['id', 'format'].forEach((key) => {
            if (typeof profile[key] !== 'string' || profile[key].trim() === '') {
                throw new Error(`Le profil d’image stock doit définir ${key}.`);
            }
        });
        assertNonNegativeInteger(profile.width, `La largeur du profil ${profile.id}`);
        assertNonNegativeInteger(profile.expected_asset_count, `Le nombre d’assets du profil ${profile.id}`);
        assertObject(profile.baseline, `stock_image_profiles.${profile.id}.baseline`);
        assertObject(profile.maximum, `stock_image_profiles.${profile.id}.maximum`);
        assertNonNegativeInteger(profile.baseline.encoded_bytes, `Le baseline ${profile.id}.encoded_bytes`);
        assertNonNegativeInteger(profile.maximum.encoded_bytes, `Le plafond ${profile.id}.encoded_bytes`);

        if (profile.baseline.encoded_bytes > profile.maximum.encoded_bytes) {
            throw new Error(`Le baseline image ${profile.id} dépasse son plafond.`);
        }
        if (profileIds.has(profile.id) || profileKeys.has(`${profile.format}:${profile.width}`)) {
            throw new Error(`Profil d’image stock dupliqué : ${profile.id}`);
        }
        profileIds.add(profile.id);
        profileKeys.add(`${profile.format}:${profile.width}`);
    });

    const expectedProfiles = new Set(['avif:640', 'avif:1280', 'webp:640', 'webp:1280']);
    if (profileKeys.size !== expectedProfiles.size || [...profileKeys].some((key) => ! expectedProfiles.has(key))) {
        throw new Error('Les profils d’images doivent couvrir exactement AVIF/WebP en 640w et 1280w.');
    }

    return config;
};

const metricValue = (profile, family, metric) => profile.measured[family][metric];

const budgetEvaluation = (measured, baseline, maximum) => ({
    measured,
    baseline,
    maximum,
    status: measured <= maximum ? 'pass' : 'fail',
});

const summarizeMeasuredAssets = (measured) => metricFamilies.reduce((summary, family) => {
    summary[family] = {
        raw_bytes: measured[family].raw_bytes,
        gzip_bytes: measured[family].gzip_bytes,
        asset_count: measured[family].asset_count,
    };

    return summary;
}, {});

const evaluateRoute = (route, profile) => ({
    id: profile.id,
    component: profile.component,
    locales: profile.locales,
    domains: profile.domains,
    route_entry_count: profile.route_entry_keys.length,
    i18n_entry_count: profile.i18n_entry_keys.length,
    measured: summarizeMeasuredAssets(profile.measured),
    budgets: metricFamilies.reduce((families, family) => {
        families[family] = metricNames.reduce((metrics, metric) => {
            metrics[metric] = budgetEvaluation(
                metricValue(profile, family, metric),
                route.baseline[family][metric],
                route.maximum[family][metric],
            );

            return metrics;
        }, {});

        return families;
    }, {}),
});

const evaluateStockProfile = (profile, measured) => ({
    id: measured.id,
    format: measured.format,
    width: measured.width,
    scope: measured.scope,
    source_asset_count: measured.source_asset_count,
    encoded_bytes: measured.encoded_bytes,
    asset_count: measured.asset_count,
    expected_asset_count: profile.expected_asset_count,
    budget: budgetEvaluation(
        measured.encoded_bytes,
        profile.baseline.encoded_bytes,
        profile.maximum.encoded_bytes,
    ),
    status: measured.asset_count === profile.expected_asset_count ? 'pass' : 'fail',
});

const flattenPolicy = (config) => {
    const values = new Map();
    const identities = new Map();

    config.routes.forEach((route) => {
        identities.set(`route:${route.id}`, JSON.stringify({
            component: route.component,
            entry: route.entry,
            locale: route.locale,
            fallback_locale: route.fallback_locale,
        }));

        ['baseline', 'maximum'].forEach((kind) => {
            metricFamilies.forEach((family) => {
                metricNames.forEach((metric) => {
                    values.set(
                        `route:${route.id}:${kind}:${family}:${metric}`,
                        route[kind][family][metric],
                    );
                });
            });
        });
    });

    config.stock_image_profiles.forEach((profile) => {
        identities.set(`stock:${profile.id}`, JSON.stringify({
            format: profile.format,
            width: profile.width,
            expected_asset_count: profile.expected_asset_count,
        }));
        ['baseline', 'maximum'].forEach((kind) => {
            values.set(`stock:${profile.id}:${kind}:encoded_bytes`, profile[kind].encoded_bytes);
        });
    });

    return { values, identities };
};

export const compareBudgetPolicy = (currentConfig, baseConfig) => {
    const current = flattenPolicy(currentConfig);
    const base = flattenPolicy(baseConfig);
    const increases = [];
    const removals = [];
    const identityChanges = [];

    base.values.forEach((baseValue, key) => {
        if (! current.values.has(key)) {
            removals.push(key);

            return;
        }
        const currentValue = current.values.get(key);
        if (currentValue > baseValue) {
            increases.push({ key, base: baseValue, current: currentValue });
        }
    });

    base.identities.forEach((baseIdentity, key) => {
        if (! current.identities.has(key)) {
            removals.push(key);

            return;
        }
        const currentIdentity = current.identities.get(key);
        if (currentIdentity !== baseIdentity) {
            identityChanges.push(key);
        }
    });

    return {
        version_changed: currentConfig.version !== baseConfig.version,
        increases,
        removals: sortedUnique(removals),
        identity_changes: sortedUnique(identityChanges),
        requires_exception: currentConfig.version !== baseConfig.version
            || increases.length > 0
            || removals.length > 0
            || identityChanges.length > 0,
    };
};

const decisionSection = (contents, decisionId) => {
    // L'identifiant est validé par validateDecisionException avant d'arriver
    // ici (MLK-DEC-XXX), il ne peut donc pas modifier l'expression.
    const title = new RegExp(`^## ${decisionId}\\b.*$`, 'm');
    const match = title.exec(contents);
    if (! match) {
        return null;
    }

    const start = match.index;
    const nextHeading = contents.indexOf('\n## ', start + match[0].length);

    return contents.slice(start, nextHeading === -1 ? undefined : nextHeading);
};

const normalizeDate = (value, label) => {
    if (! /^\d{4}-\d{2}-\d{2}$/.test(value)) {
        throw new Error(`${label} doit respecter YYYY-MM-DD.`);
    }

    return value;
};

export const validateDecisionException = ({ decisions, decisionId, today }) => {
    if (! decisionId) {
        return { valid: false, reason: 'Aucun FRONTEND_BUDGET_EXCEPTION n’est fourni.' };
    }

    if (! /^MLK-DEC-\d{3}$/.test(decisionId)) {
        return { valid: false, reason: `Identifiant de décision invalide : ${decisionId}` };
    }

    const section = decisionSection(decisions, decisionId);
    if (! section) {
        return { valid: false, reason: `Décision introuvable : ${decisionId}` };
    }

    const accepted = /^-\s*Statut\s*:\s*\*{0,2}acceptée\*{0,2}\.?\s*$/imu.test(section);
    const exception = /\b(?:dérogation|exception)\b/iu.test(section);
    const referencesP1005 = /\bP1-005\b/iu.test(section);
    const refersToFrontendBudget = /\b(?:front[ -]?end|budget(?:s)?|plafond(?:s)?)\b/iu.test(section);
    const expiryMatch = /^-\s*(?:Échéance|Expiration)[^:]*:\s*.*?(\d{4}-\d{2}-\d{2})/imu.exec(section);

    if (! accepted) {
        return { valid: false, reason: `${decisionId} n’est pas acceptée.` };
    }
    if (! exception) {
        return { valid: false, reason: `${decisionId} n’est pas une exception explicite.` };
    }
    if (! referencesP1005 || ! refersToFrontendBudget) {
        return { valid: false, reason: `${decisionId} doit couvrir explicitement P1-005 et les budgets frontend.` };
    }
    if (! expiryMatch) {
        return { valid: false, reason: `${decisionId} doit comporter une échéance ou expiration YYYY-MM-DD.` };
    }

    const normalizedToday = normalizeDate(today, 'La date de contrôle');
    const expiry = normalizeDate(expiryMatch[1], `L’échéance de ${decisionId}`);
    if (normalizedToday > expiry) {
        return { valid: false, reason: `${decisionId} a expiré le ${expiry}.` };
    }

    return { valid: true, decision_id: decisionId, expiry };
};

const baseConfigAt = (baseRef, configPath) => {
    if (! baseRef || /^0+$/.test(baseRef)) {
        return null;
    }

    const relativeConfigPath = relative(projectRoot, configPath).replace(/\\/g, '/');
    try {
        return JSON.parse(execFileSync('git', ['show', `${baseRef}:${relativeConfigPath}`], {
            cwd: projectRoot,
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'pipe'],
        }));
    } catch (error) {
        const stderr = String(error.stderr || '');
        if (/does not exist in|exists on disk, but not in/i.test(stderr)) {
            return null;
        }

        throw new Error(`Impossible de lire le baseline frontend à ${baseRef} : ${stderr || error.message}`);
    }
};

const failuresFor = (routeReports, stockReports) => {
    const failures = [];

    routeReports.forEach((route) => {
        metricFamilies.forEach((family) => {
            metricNames.forEach((metric) => {
                const budget = route.budgets[family][metric];
                if (budget.status === 'fail') {
                    failures.push(`${route.id}.${family}.${metric} (${budget.measured} > ${budget.maximum})`);
                }
            });
        });
    });
    stockReports.forEach((profile) => {
        if (profile.status === 'fail') {
            failures.push(`${profile.id}.asset_count (${profile.asset_count} !== ${profile.expected_asset_count})`);
        }
        if (profile.budget.status === 'fail') {
            failures.push(`${profile.id}.encoded_bytes (${profile.budget.measured} > ${profile.budget.maximum})`);
        }
    });

    return failures;
};

const parseArguments = (argumentsList) => {
    const settings = {
        manifestPath: defaultManifestPath,
        configPath: defaultConfigPath,
        decisionsPath: defaultDecisionsPath,
        baseRef: process.env.FRONTEND_BUDGET_BASE_REF || null,
        today: process.env.FRONTEND_BUDGET_TODAY || new Date().toISOString().slice(0, 10),
        measureOnly: false,
    };

    for (let index = 0; index < argumentsList.length; index += 1) {
        const argument = argumentsList[index];
        if (argument === '--measure') {
            settings.measureOnly = true;
            continue;
        }
        if (! ['--manifest', '--config', '--decisions', '--base-ref', '--today'].includes(argument)) {
            throw new Error(`Argument inconnu : ${argument}`);
        }
        const value = argumentsList[index + 1];
        if (! value || value.startsWith('--')) {
            throw new Error(`Valeur manquante pour ${argument}`);
        }
        index += 1;

        if (argument === '--manifest') {
            settings.manifestPath = resolve(projectRoot, value);
        } else if (argument === '--config') {
            settings.configPath = resolve(projectRoot, value);
        } else if (argument === '--decisions') {
            settings.decisionsPath = resolve(projectRoot, value);
        } else if (argument === '--base-ref') {
            settings.baseRef = value;
        } else if (argument === '--today') {
            settings.today = value;
        }
    }

    return settings;
};

export const runFrontendBudgetCheck = ({
    manifestPath = defaultManifestPath,
    configPath = defaultConfigPath,
    decisionsPath = defaultDecisionsPath,
    baseRef = null,
    today = new Date().toISOString().slice(0, 10),
    measureOnly = false,
    decisionId = process.env.FRONTEND_BUDGET_EXCEPTION || null,
} = {}) => {
    const config = validateBudgetConfig(readRequiredJson(configPath, 'Configuration de budgets frontend'));
    const manifest = readRequiredJson(manifestPath, 'Manifest Vite');
    const buildDirectory = dirname(manifestPath);
    const routeReports = config.routes.map((route) => evaluateRoute(
        route,
        collectRouteProfile({ manifest, buildDirectory, route }),
    ));
    const stockReports = config.stock_image_profiles.map((profile) => evaluateStockProfile(
        profile,
        collectStockImageProfile(profile),
    ));
    const failures = failuresFor(routeReports, stockReports);

    if (failures.length > 0 && ! measureOnly) {
        throw new Error(`Budgets frontend dépassés : ${failures.join(', ')}`);
    }

    let policy = { status: 'not_compared', base_ref: baseRef || null };
    if (! measureOnly && baseRef) {
        const baseConfig = baseConfigAt(baseRef, configPath);
        if (! baseConfig) {
            policy = { status: 'base_config_absent', base_ref: baseRef };
        } else {
            validateBudgetConfig(baseConfig);
            const comparison = compareBudgetPolicy(config, baseConfig);
            if (comparison.requires_exception) {
                if (! existsSync(decisionsPath)) {
                    throw new Error(`Décisions introuvables pour valider l’exception : ${decisionsPath}`);
                }
                const exception = validateDecisionException({
                    decisions: readFileSync(decisionsPath, 'utf8'),
                    decisionId,
                    today,
                });
                if (! exception.valid) {
                    throw new Error(
                        `Changement de baseline/plafond frontend interdit sans exception MLK-DEC acceptée et active : ${exception.reason}`,
                    );
                }
                policy = { status: 'exception_accepted', base_ref: baseRef, comparison, exception };
            } else {
                policy = { status: 'unchanged_or_stricter', base_ref: baseRef, comparison };
            }
        }
    }

    return {
        version: config.version,
        scope: {
            route_assets: 'Imports statiques Vite uniquement : entrée app + entrée de page; imports dynamiques exclus.',
            i18n: 'Domaines de traduction de la route, locale et fallback; assets déjà comptés par app/page exclus.',
            stock_images: 'Uniquement public/images/landing/stock/optimized (AVIF/WebP); aucune image tenant, upload utilisateur ou CDN.',
        },
        routes: routeReports,
        stock_images: stockReports,
        policy,
    };
};

const isMainModule = process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url);

if (isMainModule) {
    try {
        const settings = parseArguments(process.argv.slice(2));
        const report = runFrontendBudgetCheck(settings);

        console.log(JSON.stringify(report, null, 2));
    } catch (error) {
        console.error(error.message);
        process.exitCode = 1;
    }
}
