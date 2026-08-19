#!/usr/bin/env node

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { extname, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const PROJECT_ROOT = resolve(fileURLToPath(new URL('..', import.meta.url)));
const DEFAULT_CONFIG = resolve(PROJECT_ROOT, 'config/company-branding-color-audit.json');
const UTILITIES = ['bg', 'text', 'border', 'ring', 'outline', 'from', 'via', 'to', 'accent', 'caret', 'decoration', 'shadow'];
const STATUSES = new Set(['verified', 'in_progress', 'pending', 'excluded']);
const CANONICAL_MODULE_KEYS = Array.from({ length: 36 }, (_, index) => `M${String(index).padStart(2, '0')}`);
const INVENTORY_EXCLUDED_PATHS = ['.git', 'node_modules', 'vendor', 'storage', 'public/build'];
const GREEN_CSS_COLOR_NAMES = new Set([
    'aquamarine',
    'chartreuse',
    'darkcyan',
    'darkgreen',
    'darkolivegreen',
    'darkseagreen',
    'forestgreen',
    'green',
    'greenyellow',
    'lawngreen',
    'lightgreen',
    'lightseagreen',
    'lime',
    'limegreen',
    'mediumaquamarine',
    'mediumseagreen',
    'mediumspringgreen',
    'mediumturquoise',
    'olivedrab',
    'palegreen',
    'paleturquoise',
    'seagreen',
    'springgreen',
    'teal',
    'turquoise',
    'yellowgreen',
]);
const ALLOWED_CLASSIFICATIONS = new Set([
    'statut',
    'graphique',
    'marque_externe',
    'palette_specifique',
    'plateforme',
    'code_dormant',
    'central',
    'status',
    'chart',
    'external_brand',
    'specific_palette',
    'platform',
    'dormant',
]);

const isObject = (value) => value !== null && typeof value === 'object' && ! Array.isArray(value);
const sortedUnique = (values) => [...new Set(values)].sort();
const normalizePath = (path) => path.replaceAll('\\', '/').replace(/^\.\//, '');
const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const assert = (condition, message) => {
    if (! condition) {
        throw new Error(message);
    }
};

const assertExactKeys = (value, allowed, label) => {
    const unexpected = Object.keys(value).filter((key) => ! allowed.includes(key));
    assert(unexpected.length === 0, `${label}: clé(s) inconnue(s): ${unexpected.join(', ')}`);
};

const assertRelativePattern = (value, label) => {
    assert(typeof value === 'string' && value.trim() !== '', `${label} doit être une chaîne non vide.`);
    const normalized = normalizePath(value);
    assert(! normalized.startsWith('/') && ! normalized.split('/').includes('..'), `${label} doit rester relatif au dépôt.`);
};

const assertCount = (value, label) => {
    assert(Number.isSafeInteger(value) && value >= 0, `${label} doit être un entier positif ou nul.`);
};

export const validateBrandingColorAuditConfig = (config, { canonical = false } = {}) => {
    assert(isObject(config), 'Le manifeste de couleurs doit être un objet.');
    assertExactKeys(config, ['version', 'scan', 'modules'], 'Manifeste');
    assert(config.version === 1, 'La version du manifeste doit être 1.');
    assert(isObject(config.scan), 'scan doit être un objet.');
    assertExactKeys(config.scan, ['roots', 'extensions', 'tokenFamilies', 'brandHexColors'], 'scan');

    for (const key of ['roots', 'extensions', 'tokenFamilies', 'brandHexColors']) {
        assert(Array.isArray(config.scan[key]) && config.scan[key].length > 0, `scan.${key} doit être une liste non vide.`);
        assert(config.scan[key].length === new Set(config.scan[key]).size, `scan.${key} contient un doublon.`);
    }
    config.scan.roots.forEach((root, index) => assertRelativePattern(root, `scan.roots[${index}]`));
    config.scan.extensions.forEach((extension, index) => {
        assert(/^\.[a-z0-9]+$/i.test(extension), `scan.extensions[${index}] doit commencer par un point.`);
    });
    config.scan.tokenFamilies.forEach((family, index) => {
        assert(/^[a-z][a-z0-9-]*$/.test(family), `scan.tokenFamilies[${index}] est invalide.`);
    });
    config.scan.brandHexColors.forEach((color, index) => {
        assert(/^#[0-9a-f]{6}$/i.test(color), `scan.brandHexColors[${index}] doit être au format #RRGGBB.`);
    });

    assert(Array.isArray(config.modules) && config.modules.length > 0, 'modules doit être une liste non vide.');
    const moduleKeys = new Set();
    config.modules.forEach((module, moduleIndex) => {
        const label = `modules[${moduleIndex}]`;
        assert(isObject(module), `${label} doit être un objet.`);
        assertExactKeys(module, ['key', 'label', 'status', 'includes', 'excludes', 'baseline', 'exceptions'], label);
        assert(typeof module.key === 'string' && /^M\d{2}$/.test(module.key), `${label}.key doit ressembler à M00.`);
        assert(! moduleKeys.has(module.key), `Clé module dupliquée: ${module.key}`);
        moduleKeys.add(module.key);
        assert(typeof module.label === 'string' && module.label.trim() !== '', `${label}.label est obligatoire.`);
        assert(STATUSES.has(module.status), `${label}.status est invalide.`);
        assert(Array.isArray(module.includes) && module.includes.length > 0, `${label}.includes doit être non vide.`);
        assert(Array.isArray(module.excludes), `${label}.excludes doit être une liste.`);
        module.includes.forEach((pattern, index) => assertRelativePattern(pattern, `${label}.includes[${index}]`));
        module.excludes.forEach((pattern, index) => assertRelativePattern(pattern, `${label}.excludes[${index}]`));

        assert(isObject(module.baseline), `${label}.baseline doit être un objet.`);
        assertExactKeys(module.baseline, ['candidates', 'highRisk', 'files'], `${label}.baseline`);
        assertCount(module.baseline.candidates, `${label}.baseline.candidates`);
        assertCount(module.baseline.highRisk, `${label}.baseline.highRisk`);
        assert(module.baseline.highRisk <= module.baseline.candidates, `${label}: highRisk dépasse candidates.`);
        assert(isObject(module.baseline.files), `${label}.baseline.files doit être un objet.`);
        let baselineCandidates = 0;
        let baselineHighRisk = 0;
        Object.entries(module.baseline.files).forEach(([path, counts]) => {
            assertRelativePattern(path, `${label}.baseline.files path`);
            assert(isObject(counts), `${label}.baseline.files.${path} doit être un objet.`);
            assertExactKeys(counts, ['candidates', 'highRisk'], `${label}.baseline.files.${path}`);
            assertCount(counts.candidates, `${label}.baseline.files.${path}.candidates`);
            assertCount(counts.highRisk, `${label}.baseline.files.${path}.highRisk`);
            assert(counts.candidates > 0, `${label}.baseline.files.${path}.candidates doit être supérieur à zéro.`);
            assert(counts.highRisk <= counts.candidates, `${label}.baseline.files.${path}: highRisk dépasse candidates.`);
            baselineCandidates += counts.candidates;
            baselineHighRisk += counts.highRisk;
        });
        assert(baselineCandidates === module.baseline.candidates, `${label}: le total candidates ne correspond pas aux fichiers.`);
        assert(baselineHighRisk === module.baseline.highRisk, `${label}: le total highRisk ne correspond pas aux fichiers.`);

        assert(Array.isArray(module.exceptions), `${label}.exceptions doit être une liste.`);
        module.exceptions.forEach((exception, exceptionIndex) => {
            const exceptionLabel = `${label}.exceptions[${exceptionIndex}]`;
            assert(isObject(exception), `${exceptionLabel} doit être un objet.`);
            assertExactKeys(
                exception,
                ['path', 'anchor', 'tokens', 'expected_matches', 'classification', 'reason'],
                exceptionLabel,
            );
            assertRelativePattern(exception.path, `${exceptionLabel}.path`);
            assert(typeof exception.anchor === 'string' && exception.anchor.trim() !== '', `${exceptionLabel}.anchor est obligatoire.`);
            assert(Array.isArray(exception.tokens) && exception.tokens.length > 0, `${exceptionLabel}.tokens doit être non vide.`);
            assert(exception.tokens.every((token) => typeof token === 'string' && token !== ''), `${exceptionLabel}.tokens est invalide.`);
            assert(exception.tokens.length === new Set(exception.tokens).size, `${exceptionLabel}.tokens contient un doublon.`);
            assertCount(exception.expected_matches, `${exceptionLabel}.expected_matches`);
            assert(exception.expected_matches > 0, `${exceptionLabel}.expected_matches doit être supérieur à zéro.`);
            assert(ALLOWED_CLASSIFICATIONS.has(exception.classification), `${exceptionLabel}.classification est invalide.`);
            assert(typeof exception.reason === 'string' && exception.reason.trim().length >= 10, `${exceptionLabel}.reason doit être explicite.`);
        });
    });

    if (canonical) {
        const actualKeys = [...moduleKeys].sort();
        assert(
            JSON.stringify(actualKeys) === JSON.stringify(CANONICAL_MODULE_KEYS),
            `Le manifeste canonique doit contenir exactement M00 à M35 (reçu: ${actualKeys.join(', ')}).`,
        );
    }

    return config;
};

export const globToRegExp = (pattern) => {
    const normalized = normalizePath(pattern);
    let expression = '^';

    for (let index = 0; index < normalized.length; index += 1) {
        const character = normalized[index];
        if (character === '*' && normalized[index + 1] === '*') {
            index += 1;
            if (normalized[index + 1] === '/') {
                index += 1;
                expression += '(?:.*/)?';
            } else {
                expression += '.*';
            }
        } else if (character === '*') {
            expression += '[^/]*';
        } else if (character === '?') {
            expression += '[^/]';
        } else {
            expression += escapeRegExp(character);
        }
    }

    return new RegExp(`${expression}$`);
};

export const pathMatchesPattern = (path, pattern) => globToRegExp(pattern).test(normalizePath(path));

const moduleOwnsPath = (module, path) => (
    module.includes.some((pattern) => pathMatchesPattern(path, pattern))
    && ! module.excludes.some((pattern) => pathMatchesPattern(path, pattern))
);

const repositoryManifestErrors = (config, knownPaths) => {
    if (! knownPaths) {
        return [];
    }
    const paths = sortedUnique([...knownPaths].map(normalizePath));
    const pathSet = new Set(paths);
    const errors = [];

    for (const module of config.modules) {
        for (const pattern of module.includes) {
            if (! paths.some((path) => pathMatchesPattern(path, pattern))) {
                errors.push(`${module.key}: include sans chemin réel: ${pattern}.`);
            }
        }
        for (const baselinePath of Object.keys(module.baseline.files)) {
            const path = normalizePath(baselinePath);
            if (! pathSet.has(path)) {
                errors.push(`${module.key}: baseline stale, fichier absent: ${path}.`);
                continue;
            }
            const owners = config.modules.filter((candidate) => moduleOwnsPath(candidate, path)).map(({ key }) => key);
            if (! moduleOwnsPath(module, path)) {
                errors.push(`${module.key}: baseline non possédée: ${path}.`);
            }
            if (owners.length !== 1) {
                errors.push(`${module.key}: baseline ${path} a ${owners.length} owner(s): ${owners.join(', ') || 'aucun'}.`);
            }
        }
    }

    return errors;
};

const STATUS_RANKS = { pending: 0, in_progress: 1, excluded: 1, verified: 2 };

export const compareBrandingColorAuditConfigs = (currentConfig, baseConfig) => {
    if (baseConfig === null || baseConfig === undefined) {
        return { ok: true, bootstrap: true, violations: [] };
    }
    validateBrandingColorAuditConfig(currentConfig);
    validateBrandingColorAuditConfig(baseConfig);
    const currentModules = new Map(currentConfig.modules.map((module) => [module.key, module]));
    const baseModules = new Map(baseConfig.modules.map((module) => [module.key, module]));
    const violations = [];

    for (const [key, baseModule] of baseModules) {
        const currentModule = currentModules.get(key);
        if (! currentModule) {
            violations.push(`${key}: module supprimé depuis le manifeste de base.`);
            continue;
        }
        if (STATUS_RANKS[currentModule.status] < STATUS_RANKS[baseModule.status]
            || (baseModule.status === 'verified' && currentModule.status !== 'verified')) {
            violations.push(`${key}: régression de statut ${baseModule.status} -> ${currentModule.status}.`);
        }
        for (const metric of ['candidates', 'highRisk']) {
            if (currentModule.baseline[metric] > baseModule.baseline[metric]) {
                violations.push(
                    `${key}: baseline totale ${metric} en hausse (${currentModule.baseline[metric]} > ${baseModule.baseline[metric]}).`,
                );
            }
        }
        for (const [path, currentCounts] of Object.entries(currentModule.baseline.files)) {
            const baseCounts = baseModule.baseline.files[path];
            if (! baseCounts) {
                violations.push(`${key}: nouveau fichier baseline interdit: ${path}.`);
                continue;
            }
            for (const metric of ['candidates', 'highRisk']) {
                if (currentCounts[metric] > baseCounts[metric]) {
                    violations.push(
                        `${key}: baseline ${path}.${metric} en hausse (${currentCounts[metric]} > ${baseCounts[metric]}).`,
                    );
                }
            }
        }
        if (baseModule.status === 'verified'
            && JSON.stringify(currentModule.exceptions) !== JSON.stringify(baseModule.exceptions)) {
            violations.push(`${key}: exceptions modifiées après vérification du module.`);
        }
    }
    for (const key of currentModules.keys()) {
        if (! baseModules.has(key)) {
            violations.push(`${key}: nouveau module absent du manifeste de base.`);
        }
    }

    return { ok: violations.length === 0, bootstrap: false, violations };
};

const lineDetails = (source, offset) => {
    const start = source.lastIndexOf('\n', offset - 1) + 1;
    const endIndex = source.indexOf('\n', offset);
    const end = endIndex === -1 ? source.length : endIndex;
    const prefix = source.slice(0, start);

    return {
        line: prefix.split('\n').length,
        column: offset - start + 1,
        excerpt: source.slice(start, end).trim(),
        lineSource: source.slice(start, end),
    };
};

const elementContextAt = (source, offset, lineSource) => {
    const open = source.lastIndexOf('<', offset);
    const close = source.indexOf('>', offset);
    if (open >= 0 && close >= offset && close - open <= 6000 && ! /^<\//.test(source.slice(open, open + 2))) {
        return source.slice(open, close + 1);
    }

    return lineSource;
};

const tailwindMatcher = (scan) => {
    const utilities = UTILITIES.map(escapeRegExp).join('|');
    const families = scan.tokenFamilies.map(escapeRegExp).join('|');
    const variants = String.raw`(?:[A-Za-z0-9_!@&>.*+~\/()\[\]=-]+:)*`;
    const suffix = String.raw`(?:-[A-Za-z0-9_.\/\[\]%-]+)?`;

    return new RegExp(`${variants}!?(?:${utilities})-(?:${families})${suffix}`, 'g');
};

const parseTailwindToken = (token, scan) => {
    const utilities = UTILITIES.map(escapeRegExp).join('|');
    const families = scan.tokenFamilies.map(escapeRegExp).join('|');
    const utilityMatch = token.match(new RegExp(`!?(${utilities})-(${families})(?:-|$)`));

    return {
        utility: utilityMatch?.[1] || '',
        family: utilityMatch?.[2] || '',
        variants: utilityMatch ? token.slice(0, utilityMatch.index) : '',
    };
};

const byteToHex = (value) => Math.round(value).toString(16).padStart(2, '0').toUpperCase();

const parseRgbChannel = (value) => {
    const channel = value.trim();
    if (/^-?(?:\d+\.?\d*|\.\d+)%$/.test(channel)) {
        return Math.min(255, Math.max(0, Number.parseFloat(channel) * 2.55));
    }
    if (/^-?(?:\d+\.?\d*|\.\d+)$/.test(channel)) {
        return Math.min(255, Math.max(0, Number.parseFloat(channel)));
    }

    return null;
};

const parseHue = (value) => {
    const match = value.trim().match(/^(-?(?:\d+\.?\d*|\.\d+))(deg|grad|rad|turn)?$/i);
    if (! match) {
        return null;
    }
    const amount = Number.parseFloat(match[1]);
    const unit = (match[2] || 'deg').toLowerCase();
    const degrees = unit === 'turn'
        ? amount * 360
        : unit === 'grad'
            ? amount * 0.9
            : unit === 'rad'
                ? amount * 180 / Math.PI
                : amount;

    return ((degrees % 360) + 360) % 360;
};

const parsePercentage = (value) => {
    const match = value.trim().match(/^(-?(?:\d+\.?\d*|\.\d+))%$/);

    return match ? Math.min(1, Math.max(0, Number.parseFloat(match[1]) / 100)) : null;
};

const hslToRgb = (hue, saturation, lightness) => {
    const chroma = (1 - Math.abs(2 * lightness - 1)) * saturation;
    const section = hue / 60;
    const secondary = chroma * (1 - Math.abs((section % 2) - 1));
    const [red, green, blue] = section < 1
        ? [chroma, secondary, 0]
        : section < 2
            ? [secondary, chroma, 0]
            : section < 3
                ? [0, chroma, secondary]
                : section < 4
                    ? [0, secondary, chroma]
                    : section < 5
                        ? [secondary, 0, chroma]
                        : [chroma, 0, secondary];
    const offset = lightness - chroma / 2;

    return [(red + offset) * 255, (green + offset) * 255, (blue + offset) * 255];
};

const parseOklabAxis = (value) => {
    const axis = value.trim();
    if (/^-?(?:\d+\.?\d*|\.\d+)%$/.test(axis)) {
        return Number.parseFloat(axis) * 0.004;
    }
    if (/^-?(?:\d+\.?\d*|\.\d+)$/.test(axis)) {
        return Number.parseFloat(axis);
    }

    return null;
};

const parseLightness = (value) => {
    const lightness = value.trim();
    if (/^-?(?:\d+\.?\d*|\.\d+)%$/.test(lightness)) {
        return Math.min(1, Math.max(0, Number.parseFloat(lightness) / 100));
    }
    if (/^-?(?:\d+\.?\d*|\.\d+)$/.test(lightness)) {
        return Math.min(1, Math.max(0, Number.parseFloat(lightness)));
    }

    return null;
};

const linearToSrgb = (channel) => {
    const encoded = channel <= 0.0031308
        ? 12.92 * channel
        : 1.055 * Math.pow(channel, 1 / 2.4) - 0.055;

    return Math.min(1, Math.max(0, encoded)) * 255;
};

const oklabToRgb = (lightness, axisA, axisB) => {
    const lightRoot = lightness + 0.3963377774 * axisA + 0.2158037573 * axisB;
    const mediumRoot = lightness - 0.1055613458 * axisA - 0.0638541728 * axisB;
    const shortRoot = lightness - 0.0894841775 * axisA - 1.291485548 * axisB;
    const light = lightRoot ** 3;
    const medium = mediumRoot ** 3;
    const short = shortRoot ** 3;

    return [
        linearToSrgb(4.0767416621 * light - 3.3077115913 * medium + 0.2309699292 * short),
        linearToSrgb(-1.2684380046 * light + 2.6097574011 * medium - 0.3413193965 * short),
        linearToSrgb(-0.0041960863 * light - 0.7034186147 * medium + 1.707614701 * short),
    ];
};

const hexToRgb = (value) => {
    const hex = value.replace(/^#/, '');
    const opaque = hex.length === 3 || hex.length === 4
        ? hex.slice(0, 3).split('').map((digit) => `${digit}${digit}`).join('')
        : hex.slice(0, 6);
    if (! /^[0-9a-f]{6}$/i.test(opaque)) {
        return null;
    }

    return [0, 2, 4].map((offset) => Number.parseInt(opaque.slice(offset, offset + 2), 16));
};

const rgbToHsl = ([redByte, greenByte, blueByte]) => {
    const red = redByte / 255;
    const green = greenByte / 255;
    const blue = blueByte / 255;
    const maximum = Math.max(red, green, blue);
    const minimum = Math.min(red, green, blue);
    const difference = maximum - minimum;
    const lightness = (maximum + minimum) / 2;
    let hue = 0;

    if (difference !== 0) {
        if (maximum === red) {
            hue = 60 * (((green - blue) / difference) % 6);
        } else if (maximum === green) {
            hue = 60 * ((blue - red) / difference + 2);
        } else {
            hue = 60 * ((red - green) / difference + 4);
        }
    }
    if (hue < 0) {
        hue += 360;
    }
    const saturation = difference === 0 ? 0 : difference / (1 - Math.abs(2 * lightness - 1));

    return { hue, saturation, lightness };
};

const isGreenRangeRgb = (rgb) => {
    const { hue, saturation } = rgbToHsl(rgb);

    return hue >= 75 && hue <= 190 && saturation >= 0.25;
};

export const normalizeCssFunctionalColor = (value) => {
    if (typeof value !== 'string') {
        return null;
    }
    const match = value.trim().match(/^(rgb|rgba|hsl|hsla|oklch|oklab)\(\s*([^()]*)\s*\)$/i);
    if (! match) {
        return null;
    }
    const functionName = match[1].toLowerCase();
    const contents = match[2].trim();
    const commaSyntax = contents.includes(',');
    const components = commaSyntax
        ? contents.split(',').map((component) => component.trim())
        : contents.split('/')[0].trim().split(/\s+/);
    if (components.length < 3) {
        return null;
    }

    let rgb;
    if (functionName.startsWith('rgb')) {
        rgb = components.slice(0, 3).map(parseRgbChannel);
    } else if (functionName.startsWith('hsl')) {
        const hue = parseHue(components[0]);
        const saturation = parsePercentage(components[1]);
        const lightness = parsePercentage(components[2]);
        if (hue === null || saturation === null || lightness === null) {
            return null;
        }
        rgb = hslToRgb(hue, saturation, lightness);
    } else {
        const lightness = parseLightness(components[0]);
        let axisA;
        let axisB;
        if (functionName === 'oklch') {
            const chroma = parseOklabAxis(components[1]);
            const hue = parseHue(components[2]);
            if (chroma === null || hue === null) {
                return null;
            }
            axisA = chroma * Math.cos(hue * Math.PI / 180);
            axisB = chroma * Math.sin(hue * Math.PI / 180);
        } else {
            axisA = parseOklabAxis(components[1]);
            axisB = parseOklabAxis(components[2]);
        }
        if (lightness === null || axisA === null || axisB === null) {
            return null;
        }
        rgb = oklabToRgb(lightness, axisA, axisB);
    }
    if (rgb.some((channel) => channel === null || ! Number.isFinite(channel))) {
        return null;
    }

    return `#${rgb.map(byteToHex).join('')}`;
};

const tailwindRisks = ({ token, utility, variants, context, tokenFamilies }) => {
    const reasons = [];
    const families = tokenFamilies.map(escapeRegExp).join('|');
    const coloredBackground = new RegExp(`(?:^|:)!?bg-(?:${families})(?:-|\\b)`).test(token);
    const hasColoredHover = new RegExp(`hover:!?bg-(?:${families})(?:-|\\b)`).test(context);
    const nativeInteractive = /<(?:button|a|input|select|textarea|summary)\b/i.test(context);
    const vueInteractive = /<(?:Link|RouterLink|[A-Z][A-Za-z0-9_.-]*(?:Button|Link)[A-Za-z0-9_.-]*)\b/.test(context);
    const interactive = nativeInteractive
        || vueInteractive
        || /\bhref\s*=|\brole\s*=\s*["']button["']|@click\b|\btype\s*=\s*["']submit["']/i.test(context);
    const whiteForeground = /(?:^|[\s"'])text-white(?:\/\d+)?(?:$|[\s"'])/.test(context);

    if (coloredBackground && hasColoredHover) {
        reasons.push('brand_cta_with_hover');
    }
    if (utility === 'ring'
        || utility === 'outline'
        || /(?:^|:)(?:(?:group|peer)-)?focus(?:-visible|-within)?:/.test(variants)) {
        reasons.push('brand_focus_or_ring');
    }
    if (/(?:checked|selected|active|aria-\[current|state=(?:checked|active))/.test(variants)) {
        reasons.push('brand_checked_or_selected');
    }
    if (coloredBackground && interactive) {
        reasons.push('brand_interactive_background');
    }
    if (coloredBackground && interactive && whiteForeground) {
        reasons.push('brand_interactive_white_text');
    }

    return sortedUnique(reasons);
};

export const scanBrandingColorSource = ({ path, source, scan }) => {
    const findings = [];
    const occupiedRanges = new Set();
    const rangeFindings = new Map();
    const tokenRegex = tailwindMatcher(scan);
    const tailwindTokens = [];
    let match;

    while ((match = tokenRegex.exec(source)) !== null) {
        const before = source[match.index - 1] || '';
        const after = source[match.index + match[0].length] || '';
        if (/[A-Za-z0-9_-]/.test(before) || /[A-Za-z0-9_\/%-]/.test(after)) {
            continue;
        }
        tailwindTokens.push({ token: match[0], start: match.index, end: match.index + match[0].length });
    }

    // Le baseline historique compte chaque famille chromatique, y compris les
    // alias de palette sans utilitaire Tailwind. Lorsqu'elle appartient à un
    // utilitaire surveillé, on conserve néanmoins la classe complète afin de
    // pouvoir classifier précisément les risques et les exceptions.
    const families = scan.tokenFamilies.map(escapeRegExp).join('|');
    const familyRegex = new RegExp(`\\b(?:${families})(?:-[0-9]+)?\\b`, 'gi');
    let tokenCursor = 0;
    while ((match = familyRegex.exec(source)) !== null) {
        while (tailwindTokens[tokenCursor]?.end <= match.index) {
            tokenCursor += 1;
        }
        const containing = tailwindTokens[tokenCursor]?.start <= match.index
            && tailwindTokens[tokenCursor]?.end >= match.index + match[0].length
            ? tailwindTokens[tokenCursor]
            : null;
        const token = containing?.token || match[0];
        const details = lineDetails(source, match.index);
        const parsed = containing
            ? parseTailwindToken(token, scan)
            : { utility: '', family: match[0].split('-')[0].toLowerCase(), variants: '' };
        const riskReasons = tailwindRisks({
            token,
            ...parsed,
            context: elementContextAt(source, match.index, details.lineSource),
            tokenFamilies: scan.tokenFamilies,
        });
        const finding = {
            path: normalizePath(path),
            line: details.line,
            column: details.column,
            excerpt: details.excerpt,
            kind: containing ? 'tailwind' : 'color_family',
            token,
            utility: parsed.utility || null,
            family: parsed.family,
            highRisk: riskReasons.length > 0,
            riskReasons,
        };
        const range = `${match.index}:${match.index + match[0].length}`;
        findings.push(finding);
        occupiedRanges.add(range);
        rangeFindings.set(range, finding);
    }

    const configuredColors = new Set(scan.brandHexColors.map((color) => color.toUpperCase()));
    const hexRegex = /#[0-9a-f]{8}(?![0-9a-f])|#[0-9a-f]{6}(?![0-9a-f])|#[0-9a-f]{4}(?![0-9a-f])|#[0-9a-f]{3}(?![0-9a-f])/gi;
    while ((match = hexRegex.exec(source)) !== null) {
        const before = source[match.index - 1] || '';
        const rgb = hexToRgb(match[0]);
        const opaqueHex = rgb ? `#${rgb.map(byteToHex).join('')}` : null;
        if (/[0-9A-Fa-f]/.test(before)
            || ! rgb
            || (! configuredColors.has(opaqueHex) && ! isGreenRangeRgb(rgb))) {
            continue;
        }
        const details = lineDetails(source, match.index);
        findings.push({
            path: normalizePath(path),
            line: details.line,
            column: details.column,
            excerpt: details.excerpt,
            kind: 'raw_hex',
            token: match[0].toUpperCase(),
            utility: null,
            family: null,
            highRisk: true,
            riskReasons: ['raw_brand_color'],
        });
    }

    const functionalRegex = /\b(?:rgb|rgba|hsl|hsla|oklch|oklab)\(\s*[^()]*\)/gi;
    while ((match = functionalRegex.exec(source)) !== null) {
        const normalizedColor = normalizeCssFunctionalColor(match[0]);
        const rgb = normalizedColor ? hexToRgb(normalizedColor) : null;
        if (! rgb || (! configuredColors.has(normalizedColor) && ! isGreenRangeRgb(rgb))) {
            continue;
        }
        const details = lineDetails(source, match.index);
        findings.push({
            path: normalizePath(path),
            line: details.line,
            column: details.column,
            excerpt: details.excerpt,
            kind: 'raw_function',
            token: match[0],
            utility: null,
            family: null,
            highRisk: true,
            riskReasons: ['raw_brand_color'],
        });
    }

    const namedColorRegex = new RegExp(`(?<![A-Za-z0-9_-])(?:${[...GREEN_CSS_COLOR_NAMES]
        .sort((left, right) => right.length - left.length)
        .map(escapeRegExp)
        .join('|')})(?![A-Za-z0-9_-])`, 'gi');
    while ((match = namedColorRegex.exec(source)) !== null) {
        const range = `${match.index}:${match.index + match[0].length}`;
        if (occupiedRanges.has(range)) {
            const existing = rangeFindings.get(range);
            if (existing?.kind === 'color_family') {
                existing.kind = 'css_named_color';
                existing.highRisk = true;
                existing.riskReasons = sortedUnique([...existing.riskReasons, 'raw_brand_color']);
            }
            continue;
        }
        const details = lineDetails(source, match.index);
        findings.push({
            path: normalizePath(path),
            line: details.line,
            column: details.column,
            excerpt: details.excerpt,
            kind: 'css_named_color',
            token: match[0].toLowerCase(),
            utility: null,
            family: null,
            highRisk: true,
            riskReasons: ['raw_brand_color'],
        });
    }

    return findings.sort((left, right) => left.line - right.line || left.column - right.column);
};

const normalizeSources = (sources) => {
    const entries = sources instanceof Map ? [...sources.entries()] : Object.entries(sources);

    return new Map(entries.map(([path, source]) => [normalizePath(path), String(source)]));
};

const exceptionResults = (module, findings) => {
    const covered = new Set();
    const issues = [];
    const results = module.exceptions.map((exception, exceptionIndex) => {
        const tokenHits = new Map(exception.tokens.map((token) => [token, 0]));
        const matches = [];

        findings.forEach((finding, findingIndex) => {
            if (finding.path !== normalizePath(exception.path)
                || ! finding.excerpt.includes(exception.anchor)
                || ! tokenHits.has(finding.token)) {
                return;
            }
            matches.push(findingIndex);
            tokenHits.set(finding.token, tokenHits.get(finding.token) + 1);
        });

        const id = `${module.key}.exceptions[${exceptionIndex}]`;
        if (matches.length !== exception.expected_matches) {
            issues.push(`${id}: ${matches.length} match(s), ${exception.expected_matches} attendu(s).`);
        }
        for (const [token, hits] of tokenHits) {
            if (hits === 0) {
                issues.push(`${id}: token orphelin ${token}.`);
            }
        }
        for (const findingIndex of matches) {
            if (covered.has(findingIndex)) {
                issues.push(`${id}: recouvre une occurrence déjà exceptée.`);
            }
            covered.add(findingIndex);
        }

        return {
            ...exception,
            actual_matches: matches.length,
            valid: matches.length === exception.expected_matches && [...tokenHits.values()].every((hits) => hits > 0),
        };
    });

    return { covered, issues, results };
};

const baselineViolations = (module, files, totals) => {
    const violations = [];
    const baselineFiles = module.baseline.files;

    if (totals.candidates > module.baseline.candidates) {
        violations.push(`Total candidates en hausse: ${totals.candidates} > ${module.baseline.candidates}.`);
    }
    if (totals.highRisk > module.baseline.highRisk) {
        violations.push(`Total highRisk en hausse: ${totals.highRisk} > ${module.baseline.highRisk}.`);
    }
    for (const file of files.filter(({ candidates }) => candidates > 0)) {
        const baseline = baselineFiles[file.path];
        if (! baseline) {
            violations.push(`Nouveau fichier candidat non baseliné: ${file.path}.`);
            continue;
        }
        if (file.candidates > baseline.candidates) {
            violations.push(`${file.path}: candidates en hausse (${file.candidates} > ${baseline.candidates}).`);
        }
        if (file.highRisk > baseline.highRisk) {
            violations.push(`${file.path}: highRisk en hausse (${file.highRisk} > ${baseline.highRisk}).`);
        }
    }

    return violations;
};

export const auditBrandingColorSources = ({
    config,
    sources,
    moduleKey = null,
    strict = false,
    knownPaths = null,
    canonical = false,
    baseConfig = undefined,
}) => {
    validateBrandingColorAuditConfig(config, { canonical });
    const sourceMap = normalizeSources(sources);
    const allFindings = new Map([...sourceMap].map(([path, source]) => [
        path,
        scanBrandingColorSource({ path, source, scan: config.scan }),
    ]));
    const relevantPaths = sortedUnique([...sourceMap.keys()].filter((path) => (
        /^resources\/js\/Pages\/.*\.vue$/.test(path) || (allFindings.get(path)?.length || 0) > 0
    )));
    const manifestErrors = repositoryManifestErrors(config, knownPaths);
    const coverageErrors = [];
    const assignments = {};

    for (const path of relevantPaths) {
        const owners = config.modules.filter((module) => moduleOwnsPath(module, path)).map(({ key }) => key);
        assignments[path] = owners;
        if (owners.length === 0) {
            coverageErrors.push(`Fichier sans module: ${path}.`);
        } else if (owners.length > 1) {
            coverageErrors.push(`Fichier assigné à plusieurs modules (${owners.join(', ')}): ${path}.`);
        }
    }

    const selectedModules = moduleKey
        ? config.modules.filter((module) => module.key === moduleKey)
        : config.modules;
    assert(! moduleKey || selectedModules.length === 1, `Module inconnu: ${moduleKey}`);

    const modules = selectedModules.map((module) => {
        const paths = sortedUnique([...sourceMap.keys()].filter((path) => moduleOwnsPath(module, path)));
        const findings = paths.flatMap((path) => allFindings.get(path) || []);
        const exceptions = exceptionResults(module, findings);
        const findingIndex = new Map(findings.map((finding, index) => [finding, index]));
        const files = paths.map((path) => {
            const fileFindings = allFindings.get(path) || [];
            const excepted = fileFindings.filter((finding) => exceptions.covered.has(findingIndex.get(finding))).length;
            const highRisk = fileFindings.filter(({ highRisk: isHighRisk }) => isHighRisk).length;

            return {
                path,
                candidates: fileFindings.length,
                highRisk,
                excepted,
                unknown: fileFindings.length - excepted,
            };
        }).filter(({ candidates }) => candidates > 0);
        const totals = {
            candidates: findings.length,
            highRisk: findings.filter(({ highRisk }) => highRisk).length,
            excepted: exceptions.covered.size,
            unknown: findings.length - exceptions.covered.size,
            files: files.length,
        };
        const policyViolations = [];

        if (['pending', 'in_progress', 'excluded'].includes(module.status)) {
            policyViolations.push(...baselineViolations(module, files, totals));
        } else if (module.status === 'verified' && totals.unknown > 0) {
            policyViolations.push(`Module vérifié avec ${totals.unknown} occurrence(s) non exceptée(s).`);
        }

        return {
            key: module.key,
            label: module.label,
            status: module.status,
            totals,
            files,
            findings,
            exceptions: exceptions.results,
            exceptionErrors: exceptions.issues,
            policyViolations,
            ok: exceptions.issues.length === 0 && (! strict || policyViolations.length === 0),
        };
    });

    const exceptionErrors = modules.flatMap((module) => module.exceptionErrors.map((error) => `${module.key}: ${error}`));
    const policyViolations = modules.flatMap((module) => module.policyViolations.map((error) => `${module.key}: ${error}`));
    const baseComparison = baseConfig === undefined
        ? { enabled: false, ok: true, bootstrap: false, violations: [] }
        : { enabled: true, ...compareBrandingColorAuditConfigs(config, baseConfig) };
    const baseErrors = baseComparison.violations.map((violation) => `BASE: ${violation}`);
    const errors = [
        ...manifestErrors,
        ...coverageErrors,
        ...exceptionErrors,
        ...(strict ? policyViolations : []),
        ...baseErrors,
    ];
    const totals = modules.reduce((summary, module) => ({
        candidates: summary.candidates + module.totals.candidates,
        highRisk: summary.highRisk + module.totals.highRisk,
        excepted: summary.excepted + module.totals.excepted,
        unknown: summary.unknown + module.totals.unknown,
    }), { candidates: 0, highRisk: 0, excepted: 0, unknown: 0 });

    return {
        ok: errors.length === 0,
        strict,
        selectedModule: moduleKey,
        totals,
        coverage: {
            ok: coverageErrors.length === 0,
            vuePages: relevantPaths.filter((path) => /^resources\/js\/Pages\/.*\.vue$/.test(path)).length,
            candidateFiles: relevantPaths.filter((path) => (allFindings.get(path)?.length || 0) > 0).length,
            assignments,
            errors: coverageErrors,
        },
        manifest: {
            ok: manifestErrors.length === 0,
            errors: manifestErrors,
        },
        baseComparison,
        modules,
        errors,
    };
};

const safeRootPath = (projectRoot, relativePath) => {
    const path = resolve(projectRoot, relativePath);
    const rootWithSeparator = `${resolve(projectRoot)}${sep}`;
    assert(path === resolve(projectRoot) || path.startsWith(rootWithSeparator), `Chemin hors dépôt refusé: ${relativePath}`);

    return path;
};

const collectPath = (absolutePath, projectRoot, extensions, sources) => {
    if (! existsSync(absolutePath)) {
        throw new Error(`Racine de scan introuvable: ${normalizePath(relative(projectRoot, absolutePath))}`);
    }
    if (statSync(absolutePath).isDirectory()) {
        for (const entry of readdirSync(absolutePath, { withFileTypes: true })) {
            if (entry.isSymbolicLink()) {
                continue;
            }
            collectPath(resolve(absolutePath, entry.name), projectRoot, extensions, sources);
        }
        return;
    }
    if (extensions.has(extname(absolutePath))) {
        sources.set(normalizePath(relative(projectRoot, absolutePath)), readFileSync(absolutePath, 'utf8'));
    }
};

export const collectBrandingColorSources = ({ projectRoot, config }) => {
    const sources = new Map();
    const extensions = new Set(config.scan.extensions);
    for (const root of config.scan.roots) {
        collectPath(safeRootPath(projectRoot, root), projectRoot, extensions, sources);
    }

    const pagesRoot = resolve(projectRoot, 'resources/js/Pages');
    if (existsSync(pagesRoot)) {
        collectPath(pagesRoot, projectRoot, new Set(['.vue']), sources);
    }

    return sources;
};

export const collectRepositoryPaths = ({ projectRoot }) => {
    const paths = [];
    const visit = (directory) => {
        for (const entry of readdirSync(directory, { withFileTypes: true })) {
            if (entry.isSymbolicLink()) {
                continue;
            }
            const absolutePath = resolve(directory, entry.name);
            const path = normalizePath(relative(projectRoot, absolutePath));
            if (INVENTORY_EXCLUDED_PATHS.some((excluded) => path === excluded || path.startsWith(`${excluded}/`))) {
                continue;
            }
            if (entry.isDirectory()) {
                visit(absolutePath);
            } else if (entry.isFile()) {
                paths.push(path);
            }
        }
    };

    visit(resolve(projectRoot));

    return sortedUnique(paths);
};

export const auditBrandingColorRepository = ({
    projectRoot = PROJECT_ROOT,
    config,
    moduleKey = null,
    strict = false,
    baseConfig = undefined,
}) => auditBrandingColorSources({
    config,
    sources: collectBrandingColorSources({ projectRoot, config }),
    knownPaths: collectRepositoryPaths({ projectRoot }),
    canonical: true,
    moduleKey,
    strict,
    baseConfig,
});

export const formatBrandingColorAudit = (report) => {
    const lines = [
        `Audit couleurs entreprise${report.strict ? ' (strict)' : ''}`,
        `Couverture: ${report.coverage.vuePages} pages Vue, ${report.coverage.candidateFiles} fichiers candidats`,
    ];
    for (const module of report.modules) {
        lines.push(
            `${module.key} [${module.status}] — ${module.totals.candidates} candidats, ${module.totals.highRisk} risques forts, ${module.totals.unknown} non classés`,
        );
        module.exceptionErrors.forEach((error) => lines.push(`  EXCEPTION: ${error}`));
        if (report.strict) {
            module.policyViolations.forEach((error) => lines.push(`  POLITIQUE: ${error}`));
        }
    }
    report.manifest.errors.forEach((error) => lines.push(`MANIFESTE: ${error}`));
    report.coverage.errors.forEach((error) => lines.push(`COUVERTURE: ${error}`));
    report.baseComparison.violations.forEach((error) => lines.push(`BASE: ${error}`));
    lines.push(report.ok ? 'Résultat: OK' : `Résultat: ÉCHEC (${report.errors.length} erreur(s))`);

    return lines.join('\n');
};

export const loadBrandingColorAuditBaseConfig = ({ projectRoot, baseRef }) => {
    assert(
        typeof baseRef === 'string'
            && /^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$/.test(baseRef)
            && ! baseRef.includes('..'),
        'La référence Git de base est invalide.',
    );
    try {
        const contents = execFileSync(
            'git',
            ['show', `${baseRef}:config/company-branding-color-audit.json`],
            { cwd: projectRoot, encoding: 'utf8', maxBuffer: 10 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'] },
        );

        return JSON.parse(contents);
    } catch (error) {
        const stderr = String(error?.stderr || '');
        if (/does not exist|exists on disk, but not in|path .* not in/i.test(stderr)) {
            return null;
        }
        throw new Error(`Impossible de lire le manifeste au base ref ${baseRef}: ${stderr.trim() || error.message}`);
    }
};

export const parseBrandingColorAuditArgs = (argv, environment = process.env) => {
    const options = {
        moduleKey: null,
        json: false,
        strict: false,
        configPath: DEFAULT_CONFIG,
        projectRoot: PROJECT_ROOT,
        baseRef: environment.BRANDING_COLOR_BASE_REF || null,
    };
    for (const argument of argv) {
        if (argument === '--json') {
            options.json = true;
        } else if (argument === '--strict') {
            options.strict = true;
        } else if (argument.startsWith('--module=')) {
            options.moduleKey = argument.slice('--module='.length);
        } else if (argument.startsWith('--config=')) {
            options.configPath = resolve(argument.slice('--config='.length));
        } else if (argument.startsWith('--root=')) {
            options.projectRoot = resolve(argument.slice('--root='.length));
        } else if (argument.startsWith('--base-ref=')) {
            options.baseRef = argument.slice('--base-ref='.length);
        } else {
            throw new Error(`Argument inconnu: ${argument}`);
        }
    }
    assert(! options.moduleKey || /^M\d{2}$/.test(options.moduleKey), '--module doit ressembler à M00.');
    if (options.baseRef) {
        assert(
            /^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$/.test(options.baseRef) && ! options.baseRef.includes('..'),
            '--base-ref est invalide.',
        );
    }

    return options;
};

export const runBrandingColorAuditCli = (argv = process.argv.slice(2)) => {
    let options = { json: argv.includes('--json') };
    try {
        options = parseBrandingColorAuditArgs(argv);
        assert(existsSync(options.configPath), `Configuration introuvable: ${options.configPath}`);
        const config = JSON.parse(readFileSync(options.configPath, 'utf8'));
        const baseConfig = options.baseRef
            ? loadBrandingColorAuditBaseConfig({ projectRoot: options.projectRoot, baseRef: options.baseRef })
            : undefined;
        const report = auditBrandingColorRepository({
            projectRoot: options.projectRoot,
            config,
            moduleKey: options.moduleKey,
            strict: options.strict,
            baseConfig,
        });
        process.stdout.write(`${options.json ? JSON.stringify(report, null, 2) : formatBrandingColorAudit(report)}\n`);

        return report.ok ? 0 : 1;
    } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        process.stderr.write(`${options.json ? JSON.stringify({ ok: false, error: message }) : `Erreur: ${message}`}\n`);

        return 2;
    }
};

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    process.exitCode = runBrandingColorAuditCli();
}
