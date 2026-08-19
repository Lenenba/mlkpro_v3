const HEX_COLOR_PATTERN = /^#[0-9A-F]{6}$/;

export const DEFAULT_COMPANY_PRIMARY_COLOR = '#16A34A';

export const COMPANY_BRAND_CSS_VARIABLES = Object.freeze([
    '--app-primary',
    '--app-primary-hover',
    '--app-primary-focus',
    '--app-primary-foreground',
    '--app-primary-checked',
    '--app-primary-soft-light',
    '--app-primary-soft-dark',
    '--app-primary-soft-foreground-light',
    '--app-primary-soft-foreground-dark',
    '--app-primary-readable-light',
    '--app-primary-readable-dark',
    '--app-primary-line-light',
    '--app-primary-line-dark',
]);

const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

export const normalizeCompanyPrimaryColor = (value) => {
    if (typeof value !== 'string') {
        return '';
    }

    const candidate = value.trim().toUpperCase();

    return HEX_COLOR_PATTERN.test(candidate) ? candidate : '';
};

const hexToRgb = (value) => {
    const color = normalizeCompanyPrimaryColor(value);

    if (!color) {
        return null;
    }

    return {
        red: Number.parseInt(color.slice(1, 3), 16),
        green: Number.parseInt(color.slice(3, 5), 16),
        blue: Number.parseInt(color.slice(5, 7), 16),
    };
};

const rgbToHex = ({ red, green, blue }) => {
    const channel = (value) => Math.round(clamp(value, 0, 255))
        .toString(16)
        .padStart(2, '0')
        .toUpperCase();

    return `#${channel(red)}${channel(green)}${channel(blue)}`;
};

export const mixHexColors = (source, target, targetWeight = 0.5) => {
    const sourceRgb = hexToRgb(source);
    const targetRgb = hexToRgb(target);

    if (!sourceRgb || !targetRgb) {
        return '';
    }

    const weight = clamp(Number(targetWeight) || 0, 0, 1);

    return rgbToHex({
        red: sourceRgb.red + ((targetRgb.red - sourceRgb.red) * weight),
        green: sourceRgb.green + ((targetRgb.green - sourceRgb.green) * weight),
        blue: sourceRgb.blue + ((targetRgb.blue - sourceRgb.blue) * weight),
    });
};

const linearChannel = (value) => {
    const normalized = value / 255;

    return normalized <= 0.04045
        ? normalized / 12.92
        : ((normalized + 0.055) / 1.055) ** 2.4;
};

export const relativeLuminance = (value) => {
    const rgb = hexToRgb(value);

    if (!rgb) {
        return null;
    }

    return (0.2126 * linearChannel(rgb.red))
        + (0.7152 * linearChannel(rgb.green))
        + (0.0722 * linearChannel(rgb.blue));
};

export const contrastRatio = (first, second) => {
    const firstLuminance = relativeLuminance(first);
    const secondLuminance = relativeLuminance(second);

    if (firstLuminance === null || secondLuminance === null) {
        return 0;
    }

    const lighter = Math.max(firstLuminance, secondLuminance);
    const darker = Math.min(firstLuminance, secondLuminance);

    return (lighter + 0.05) / (darker + 0.05);
};

export const mostReadableForeground = (background) => {
    const white = '#FFFFFF';
    const dark = '#111827';
    const whiteContrast = contrastRatio(background, white);
    const darkContrast = contrastRatio(background, dark);

    if (whiteContrast >= darkContrast && whiteContrast >= 4.5) {
        return white;
    }

    return darkContrast >= 4.5 ? dark : '#000000';
};

const ensureContrast = (color, background, minimumRatio) => {
    const normalizedColor = normalizeCompanyPrimaryColor(color);
    const normalizedBackground = normalizeCompanyPrimaryColor(background);

    if (!normalizedColor || !normalizedBackground) {
        return '';
    }

    if (contrastRatio(normalizedColor, normalizedBackground) >= minimumRatio) {
        return normalizedColor;
    }

    const candidates = ['#000000', '#FFFFFF']
        .filter((target) => contrastRatio(target, normalizedBackground) >= minimumRatio)
        .map((target) => {
            let lower = 0;
            let upper = 1;

            for (let iteration = 0; iteration < 16; iteration += 1) {
                const weight = (lower + upper) / 2;
                const mixed = mixHexColors(normalizedColor, target, weight);

                if (contrastRatio(mixed, normalizedBackground) >= minimumRatio) {
                    upper = weight;
                } else {
                    lower = weight;
                }
            }

            return {
                color: mixHexColors(normalizedColor, target, upper),
                weight: upper,
            };
        })
        .sort((first, second) => first.weight - second.weight);

    return candidates[0]?.color || mostReadableForeground(normalizedBackground);
};

export const resolveCompanyPrimaryColor = (company) => {
    if (!company || typeof company !== 'object') {
        return '';
    }

    return normalizeCompanyPrimaryColor(company.primary_color)
        || normalizeCompanyPrimaryColor(company.branding_settings?.primary_color);
};

const resolveCompanyPaletteColor = (company, key) => (
    normalizeCompanyPrimaryColor(company?.[key])
    || normalizeCompanyPrimaryColor(company?.branding_settings?.[key])
);

export const buildCompanyBrandPalette = (company) => {
    const primary = resolveCompanyPrimaryColor(company);

    if (!primary) {
        return null;
    }

    const configuredForeground = resolveCompanyPaletteColor(company, 'primary_foreground_color');
    const foreground = configuredForeground && contrastRatio(primary, configuredForeground) >= 4.5
        ? configuredForeground
        : mostReadableForeground(primary);
    const interactionTarget = foreground === '#FFFFFF' ? '#000000' : '#FFFFFF';
    const configuredHover = resolveCompanyPaletteColor(company, 'primary_hover_color');
    const configuredFocus = resolveCompanyPaletteColor(company, 'primary_focus_color');
    const hover = configuredHover && contrastRatio(configuredHover, foreground) >= 4.5
        ? configuredHover
        : mixHexColors(primary, interactionTarget, 0.12);
    const focus = configuredFocus && contrastRatio(configuredFocus, foreground) >= 4.5
        ? configuredFocus
        : mixHexColors(primary, interactionTarget, 0.22);
    const softLight = mixHexColors(primary, '#FFFFFF', 0.88);
    const softDark = mixHexColors(primary, '#0F172A', 0.82);
    const lineLight = ensureContrast(primary, '#FFFFFF', 3);
    const lineDark = ensureContrast(primary, '#0F172A', 3);

    return {
        primary,
        hover,
        focus,
        foreground,
        softLight,
        softDark,
        softForegroundLight: ensureContrast(primary, softLight, 4.5),
        softForegroundDark: ensureContrast(primary, softDark, 4.5),
        readableLight: ensureContrast(primary, '#FFFFFF', 4.5),
        readableDark: ensureContrast(primary, '#0F172A', 4.5),
        checked: lineLight,
        lineLight,
        lineDark,
    };
};

export const resolvePageBrandCompany = (page) => {
    const props = page?.props || {};
    const component = String(page?.component || '');
    const account = props.auth?.account || null;
    const explicitCompany = props.company && typeof props.company === 'object'
        ? props.company
        : null;
    const isTenantPublicSurface = component.startsWith('Public/') && explicitCompany;

    if (isTenantPublicSurface) {
        return explicitCompany;
    }

    const isPlatformContext = Boolean(account?.is_superadmin || account?.is_platform_admin);
    const isImpersonating = Boolean(props.auth?.impersonator);

    if (isPlatformContext && !isImpersonating) {
        return null;
    }

    if (component.startsWith('Auth/') && explicitCompany) {
        return explicitCompany;
    }

    return account?.company || (component.startsWith('Portal/') ? explicitCompany : null);
};

export const clearCompanyBrandTheme = (root) => {
    if (!root?.style) {
        return;
    }

    COMPANY_BRAND_CSS_VARIABLES.forEach((property) => root.style.removeProperty(property));

    if (root.dataset) {
        delete root.dataset.tenantBrandTheme;
    }
};

export const applyCompanyBrandTheme = (page, root = globalThis.document?.documentElement) => {
    if (!root?.style) {
        return null;
    }

    clearCompanyBrandTheme(root);

    const palette = buildCompanyBrandPalette(resolvePageBrandCompany(page));

    if (!palette) {
        return null;
    }

    const properties = {
        '--app-primary': palette.primary,
        '--app-primary-hover': palette.hover,
        '--app-primary-focus': palette.focus,
        '--app-primary-foreground': palette.foreground,
        '--app-primary-checked': palette.checked,
        '--app-primary-soft-light': palette.softLight,
        '--app-primary-soft-dark': palette.softDark,
        '--app-primary-soft-foreground-light': palette.softForegroundLight,
        '--app-primary-soft-foreground-dark': palette.softForegroundDark,
        '--app-primary-readable-light': palette.readableLight,
        '--app-primary-readable-dark': palette.readableDark,
        '--app-primary-line-light': palette.lineLight,
        '--app-primary-line-dark': palette.lineDark,
    };

    Object.entries(properties).forEach(([property, value]) => {
        root.style.setProperty(property, value);
    });

    if (root.dataset) {
        root.dataset.tenantBrandTheme = 'true';
    }

    return palette;
};
