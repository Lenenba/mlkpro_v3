import { expect } from '@playwright/test';

const HEX_COLOR_PATTERN = /^#([\da-f]{3,4}|[\da-f]{6}|[\da-f]{8})$/i;
const RGB_COLOR_PATTERN = /^rgba?\((.+)\)$/i;

export const COMPANY_BRANDING_E2E_SCENARIOS = Object.freeze([
    Object.freeze({
        id: 'M00',
        label: 'socle de vérification des couleurs de marque',
        fixtureKey: 'tenantBranding',
        pathKey: 'publicRequestPath',
        primarySelector: 'button[type="submit"].bg-primary',
        focusSelector: 'input[role="combobox"]',
    }),
]);

const clamp = (value, minimum, maximum) => Math.min(Math.max(value, minimum), maximum);

const channelToHex = (value) => Math.round(clamp(value, 0, 255))
    .toString(16)
    .padStart(2, '0')
    .toUpperCase();

const sortedUnique = (values) => [...new Set(values)].sort();

export function validateCompanyBrandingE2ERegistry(
    manifest,
    scenarios = COMPANY_BRANDING_E2E_SCENARIOS,
) {
    const modules = Array.isArray(manifest?.modules) ? manifest.modules : [];
    const scenarioList = Array.isArray(scenarios) ? scenarios : [];
    const verifiedModuleIds = sortedUnique(modules
        .filter(({ status }) => status === 'verified')
        .map(({ key }) => key));
    const scenarioIds = scenarioList.map(({ id }) => id);
    const uniqueScenarioIds = sortedUnique(scenarioIds);
    const duplicateScenarioIds = uniqueScenarioIds.filter((id) => (
        scenarioIds.filter((candidate) => candidate === id).length > 1
    ));
    const invalidScenarioIds = uniqueScenarioIds.filter((id) => !/^M\d{2}$/.test(id));
    const missingScenarioIds = verifiedModuleIds.filter((id) => !uniqueScenarioIds.includes(id));
    const unverifiedScenarioIds = uniqueScenarioIds.filter((id) => !verifiedModuleIds.includes(id));
    const errors = [];

    if (!Array.isArray(manifest?.modules)) {
        errors.push('Le manifeste doit exposer une liste modules.');
    }
    if (!Array.isArray(scenarios)) {
        errors.push('Le registre E2E doit être une liste.');
    }
    if (duplicateScenarioIds.length) {
        errors.push(`Scénarios E2E dupliqués: ${duplicateScenarioIds.join(', ')}.`);
    }
    if (invalidScenarioIds.length) {
        errors.push(`Identifiants de scénario E2E invalides: ${invalidScenarioIds.join(', ')}.`);
    }
    if (missingScenarioIds.length) {
        errors.push(`Modules vérifiés sans scénario E2E: ${missingScenarioIds.join(', ')}.`);
    }
    if (unverifiedScenarioIds.length) {
        errors.push(`Scénarios E2E associés à un module non vérifié: ${unverifiedScenarioIds.join(', ')}.`);
    }

    return {
        ok: errors.length === 0,
        verifiedModuleIds,
        scenarioIds: uniqueScenarioIds,
        errors,
    };
}

export function assertCompanyBrandingE2ERegistry(
    manifest,
    scenarios = COMPANY_BRANDING_E2E_SCENARIOS,
) {
    const result = validateCompanyBrandingE2ERegistry(manifest, scenarios);

    if (!result.ok) {
        throw new Error(`Registre E2E des couleurs incohérent:\n- ${result.errors.join('\n- ')}`);
    }

    return result;
}

const parseRgbChannel = (value) => {
    if (value.endsWith('%')) {
        return clamp(Number.parseFloat(value) * 2.55, 0, 255);
    }

    return clamp(Number.parseFloat(value), 0, 255);
};

const parseAlphaChannel = (value = '1') => {
    if (value.endsWith('%')) {
        return clamp(Number.parseFloat(value) / 100, 0, 1);
    }

    return clamp(Number.parseFloat(value), 0, 1);
};

export function parseCssColor(value) {
    if (typeof value !== 'string') {
        return null;
    }

    const candidate = value.trim();

    if (!candidate) {
        return null;
    }

    if (candidate.toLowerCase() === 'transparent') {
        return { red: 0, green: 0, blue: 0, alpha: 0 };
    }

    const hexMatch = candidate.match(HEX_COLOR_PATTERN);

    if (hexMatch) {
        let channels = hexMatch[1];

        if (channels.length === 3 || channels.length === 4) {
            channels = [...channels].map((channel) => `${channel}${channel}`).join('');
        }

        return {
            red: Number.parseInt(channels.slice(0, 2), 16),
            green: Number.parseInt(channels.slice(2, 4), 16),
            blue: Number.parseInt(channels.slice(4, 6), 16),
            alpha: channels.length === 8
                ? Number.parseInt(channels.slice(6, 8), 16) / 255
                : 1,
        };
    }

    const rgbMatch = candidate.match(RGB_COLOR_PATTERN);

    if (!rgbMatch) {
        return null;
    }

    const normalizedBody = rgbMatch[1]
        .replaceAll(',', ' ')
        .replace('/', ' / ')
        .trim();
    const tokens = normalizedBody.split(/\s+/).filter(Boolean);
    const slashIndex = tokens.indexOf('/');
    const channels = slashIndex === -1 ? tokens.slice(0, 3) : tokens.slice(0, slashIndex);
    const alphaToken = slashIndex === -1 ? tokens[3] : tokens[slashIndex + 1];

    if (channels.length !== 3 || channels.some((channel) => !Number.isFinite(parseRgbChannel(channel)))) {
        return null;
    }

    const alpha = parseAlphaChannel(alphaToken);

    if (!Number.isFinite(alpha)) {
        return null;
    }

    return {
        red: parseRgbChannel(channels[0]),
        green: parseRgbChannel(channels[1]),
        blue: parseRgbChannel(channels[2]),
        alpha,
    };
}

export function normalizeCssColor(value) {
    const color = parseCssColor(value);

    if (!color) {
        return null;
    }

    if (color.alpha < 1) {
        const alpha = Number(color.alpha.toFixed(3));

        return `rgba(${Math.round(color.red)}, ${Math.round(color.green)}, ${Math.round(color.blue)}, ${alpha})`;
    }

    return `#${channelToHex(color.red)}${channelToHex(color.green)}${channelToHex(color.blue)}`;
}

const opaqueColor = (value, label) => {
    const parsed = parseCssColor(value);

    if (!parsed || parsed.alpha < 1) {
        throw new Error(`${label} must be an opaque CSS hex or rgb color; received "${value}".`);
    }

    return parsed;
};

const linearChannel = (value) => {
    const normalized = value / 255;

    return normalized <= 0.04045
        ? normalized / 12.92
        : ((normalized + 0.055) / 1.055) ** 2.4;
};

export function relativeLuminance(value) {
    const color = opaqueColor(value, 'Color');

    return (0.2126 * linearChannel(color.red))
        + (0.7152 * linearChannel(color.green))
        + (0.0722 * linearChannel(color.blue));
}

export function contrastRatio(foreground, background) {
    const foregroundLuminance = relativeLuminance(foreground);
    const backgroundLuminance = relativeLuminance(background);
    const lighter = Math.max(foregroundLuminance, backgroundLuminance);
    const darker = Math.min(foregroundLuminance, backgroundLuminance);

    return (lighter + 0.05) / (darker + 0.05);
}

const toCssProperty = (property) => property.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`);

export async function readComputedStyleValue(locator, property) {
    const cssProperty = toCssProperty(property);

    return locator.evaluate((element, name) => getComputedStyle(element).getPropertyValue(name).trim(), cssProperty);
}

export async function readComputedColor(locator, property = 'color') {
    const rawColor = await readComputedStyleValue(locator, property);
    const normalized = normalizeCssColor(rawColor);

    if (!normalized) {
        throw new Error(`Computed ${property} is not a supported CSS color: "${rawColor}".`);
    }

    return normalized;
}

const colorsFromCompositeValue = (value) => {
    const candidates = value.match(/#[\da-f]{3,8}\b|rgba?\([^)]*\)/gi) || [];

    return candidates.map(normalizeCssColor).filter(Boolean);
};

export async function expectComputedColor(locator, {
    expected,
    property = 'color',
    match = 'exact',
    description = `${property} color`,
} = {}) {
    const normalizedExpected = normalizeCssColor(expected);

    if (!normalizedExpected) {
        throw new Error(`Expected ${description} is not a supported CSS color: "${expected}".`);
    }

    if (match === 'contains') {
        await expect.poll(async () => {
            const rawValue = await readComputedStyleValue(locator, property);

            return colorsFromCompositeValue(rawValue).includes(normalizedExpected);
        }, { message: `${description} should contain ${normalizedExpected}` }).toBe(true);

        return;
    }

    await expect.poll(
        () => readComputedColor(locator, property),
        { message: `${description} should be ${normalizedExpected}` },
    ).toBe(normalizedExpected);
}

export async function expectHoverColor(locator, options = {}) {
    await locator.hover();
    await expectComputedColor(locator, {
        property: 'background-color',
        description: 'hover color',
        ...options,
    });
}

export async function expectFocusColor(locator, options = {}) {
    await locator.focus();
    await expectComputedColor(locator, {
        property: 'border-color',
        description: 'focus color',
        ...options,
    });
}

export async function readRootBrandVariable(page, variable = '--app-primary') {
    const rawColor = await page.evaluate((property) => {
        const declaredValue = getComputedStyle(document.documentElement).getPropertyValue(property).trim();

        if (!declaredValue) {
            return '';
        }

        const probe = document.createElement('span');
        probe.style.color = `var(${property})`;
        probe.style.display = 'none';
        document.documentElement.append(probe);

        const resolvedColor = getComputedStyle(probe).color;
        probe.remove();

        return resolvedColor;
    }, variable);
    const normalized = normalizeCssColor(rawColor);

    if (!normalized) {
        throw new Error(`Root variable ${variable} does not resolve to a supported CSS color: "${rawColor}".`);
    }

    return normalized;
}

export async function expectRootBrandVariable(page, {
    expected,
    variable = '--app-primary',
} = {}) {
    const normalizedExpected = normalizeCssColor(expected);

    if (!normalizedExpected) {
        throw new Error(`Expected root variable ${variable} is not a supported CSS color: "${expected}".`);
    }

    await expect.poll(
        () => readRootBrandVariable(page, variable),
        { message: `${variable} should resolve to ${normalizedExpected}` },
    ).toBe(normalizedExpected);
}

export async function expectElementContrast(locator, {
    foregroundProperty = 'color',
    backgroundProperty = 'background-color',
    minimum = 4.5,
} = {}) {
    const foreground = await readComputedColor(locator, foregroundProperty);
    const background = await readComputedColor(locator, backgroundProperty);
    const ratio = contrastRatio(foreground, background);

    expect(
        ratio,
        `${foregroundProperty} ${foreground} on ${backgroundProperty} ${background} should have a contrast ratio of at least ${minimum}:1`,
    ).toBeGreaterThanOrEqual(minimum);

    return ratio;
}

export async function expectSemanticStatusColor(locator, {
    expected,
    property = 'background-color',
    tenantPrimary,
} = {}) {
    await expectComputedColor(locator, {
        expected,
        property,
        description: 'semantic status color',
    });

    if (tenantPrimary) {
        const normalizedStatus = normalizeCssColor(expected);
        const normalizedPrimary = normalizeCssColor(tenantPrimary);

        expect(
            normalizedStatus,
            'A semantic status color should remain independent from the tenant primary color.',
        ).not.toBe(normalizedPrimary);
    }
}
