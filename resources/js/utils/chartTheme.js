const DEFAULT_FONT_FAMILY = 'var(--app-font-body)';

const LIGHT_PALETTE = ['#2563eb', '#7c3aed', '#0f766e', '#d97706', '#db2777'];
const DARK_PALETTE = ['#60a5fa', '#a78bfa', '#2dd4bf', '#fbbf24', '#f472b6'];
const HIGH_CONTRAST_LIGHT_PALETTE = ['#1d4ed8', '#6d28d9', '#0f766e', '#92400e', '#9d174d'];
const HIGH_CONTRAST_DARK_PALETTE = ['#93c5fd', '#c4b5fd', '#5eead4', '#fde68a', '#f9a8d4'];
const SERIES_TONE_INDEX = {
    blue: 0,
    violet: 1,
    emerald: 2,
    amber: 3,
    rose: 4,
};
const ACCESSIBLE_PATTERN_STYLES = [
    'circles',
    'slantedLines',
    'verticalLines',
    'horizontalLines',
    'squares',
];

const isPlainObject = (value) => Object.prototype.toString.call(value) === '[object Object]';

const cloneOptionValue = (value) => {
    if (Array.isArray(value)) {
        return value.map(cloneOptionValue);
    }

    if (isPlainObject(value)) {
        return Object.fromEntries(
            Object.entries(value).map(([key, nestedValue]) => [key, cloneOptionValue(nestedValue)]),
        );
    }

    return value;
};

export const mergeChartOptions = (...sources) => sources.reduce((result, source) => {
    if (!isPlainObject(source)) {
        return result;
    }

    for (const [key, value] of Object.entries(source)) {
        result[key] = isPlainObject(value) && isPlainObject(result[key])
            ? mergeChartOptions(result[key], value)
            : cloneOptionValue(value);
    }

    return result;
}, {});

export const buildAccessibleSeriesFill = (seriesCount) => {
    if (!Number.isInteger(seriesCount) || seriesCount < 2) {
        return {};
    }

    return {
        fill: {
            type: Array.from(
                { length: seriesCount },
                (_, index) => (index === 0 ? 'solid' : 'pattern'),
            ),
            opacity: 1,
            pattern: {
                style: Array.from(
                    { length: seriesCount },
                    (_, index) => ACCESSIBLE_PATTERN_STYLES[index % ACCESSIBLE_PATTERN_STYLES.length],
                ),
                width: 6,
                height: 6,
                strokeWidth: 2,
            },
        },
    };
};

const cssValue = (styles, property, fallback) => {
    const value = styles?.getPropertyValue(property)?.trim();

    return value || fallback;
};

const rootHasClass = (root, className) => Boolean(root?.classList?.contains(className));

const reducedMotionRequested = (root) => {
    if (root?.dataset?.reduceMotion === 'true' || rootHasClass(root, 'a11y-reduce-motion')) {
        return true;
    }

    return typeof window !== 'undefined'
        && typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
};

const highContrastRequested = (root) => root?.dataset?.contrast === 'high'
    || rootHasClass(root, 'a11y-high-contrast');

export const resolveChartTheme = (root = typeof document !== 'undefined'
    ? document.documentElement
    : null) => {
    const isDark = rootHasClass(root, 'dark');
    const isHighContrast = highContrastRequested(root);
    const isReducedMotion = reducedMotionRequested(root);
    const styles = root && typeof window !== 'undefined'
        ? window.getComputedStyle(root)
        : null;
    const fallbackPalette = isHighContrast
        ? (isDark ? HIGH_CONTRAST_DARK_PALETTE : HIGH_CONTRAST_LIGHT_PALETTE)
        : (isDark ? DARK_PALETTE : LIGHT_PALETTE);
    return {
        isDark,
        isHighContrast,
        isReducedMotion,
        fontFamily: cssValue(styles, '--app-font-body', DEFAULT_FONT_FAMILY),
        foreground: cssValue(
            styles,
            '--chart-label',
            cssValue(styles, '--app-foreground', isDark ? '#f8fafc' : '#0f172a'),
        ),
        mutedForeground: cssValue(
            styles,
            '--chart-axis',
            cssValue(styles, '--app-muted-foreground-1', isDark ? '#94a3b8' : '#64748b'),
        ),
        grid: cssValue(
            styles,
            '--chart-grid',
            cssValue(styles, '--app-line-1', isDark ? '#334155' : '#e2e8f0'),
        ),
        surface: cssValue(
            styles,
            '--chart-surface',
            cssValue(styles, '--app-layer', isDark ? '#111827' : '#ffffff'),
        ),
        tooltipBackground: cssValue(styles, '--app-tooltip', isDark ? '#0b0f14' : '#0f172a'),
        tooltipForeground: cssValue(styles, '--app-tooltip-foreground', '#f8fafc'),
        palette: [
            cssValue(styles, '--chart-series-blue', fallbackPalette[0]),
            cssValue(styles, '--chart-series-violet', fallbackPalette[1]),
            cssValue(styles, '--chart-series-emerald', fallbackPalette[2]),
            cssValue(styles, '--chart-series-amber', fallbackPalette[3]),
            cssValue(styles, '--chart-series-rose', fallbackPalette[4]),
        ],
    };
};

export const resolveChartSeriesColors = (tones, palette) => {
    if (!Array.isArray(tones) || !tones.length || !Array.isArray(palette) || !palette.length) {
        return [];
    }

    return tones.map((tone, index) => {
        const paletteIndex = SERIES_TONE_INDEX[tone];

        return palette[paletteIndex] ?? palette[index % palette.length];
    });
};

export const buildChartThemeOptions = ({
    type = 'line',
    height = 300,
    theme = resolveChartTheme(),
} = {}) => ({
    chart: {
        type,
        height,
        width: '100%',
        background: 'transparent',
        foreColor: theme.mutedForeground,
        fontFamily: theme.fontFamily,
        redrawOnParentResize: true,
        redrawOnWindowResize: false,
        toolbar: {
            show: false,
        },
        zoom: {
            enabled: false,
        },
        animations: {
            enabled: !theme.isReducedMotion,
            easing: 'easeinout',
            speed: theme.isReducedMotion ? 0 : 350,
            animateGradually: {
                enabled: false,
            },
            dynamicAnimation: {
                enabled: !theme.isReducedMotion,
                speed: theme.isReducedMotion ? 0 : 250,
            },
        },
    },
    theme: {
        mode: theme.isDark ? 'dark' : 'light',
    },
    colors: theme.palette,
    dataLabels: {
        enabled: false,
    },
    stroke: {
        curve: 'straight',
        lineCap: 'round',
        width: theme.isHighContrast ? 3 : 2,
    },
    markers: {
        size: 0,
        hover: {
            sizeOffset: 3,
        },
    },
    grid: {
        borderColor: theme.grid,
        strokeDashArray: theme.isHighContrast ? 0 : 3,
    },
    legend: {
        fontFamily: theme.fontFamily,
        labels: {
            colors: theme.foreground,
        },
    },
    tooltip: {
        theme: theme.isDark ? 'dark' : 'light',
    },
    xaxis: {
        axisBorder: {
            color: theme.grid,
        },
        axisTicks: {
            color: theme.grid,
        },
        labels: {
            style: {
                colors: theme.mutedForeground,
                fontFamily: theme.fontFamily,
            },
        },
    },
    yaxis: {
        labels: {
            style: {
                colors: theme.mutedForeground,
                fontFamily: theme.fontFamily,
            },
        },
    },
    states: {
        hover: {
            filter: {
                type: 'none',
            },
        },
        active: {
            filter: {
                type: 'none',
            },
        },
    },
});

const pointValue = (point) => {
    if (Array.isArray(point)) {
        return point.length > 1 ? point[1] : point[0];
    }

    if (isPlainObject(point)) {
        return point.y ?? point.value;
    }

    return point;
};

const isFiniteChartValue = (value) => value !== null
    && value !== undefined
    && value !== ''
    && Number.isFinite(Number(value));

const isMeaningfulChartValue = (value) => isFiniteChartValue(value)
    && Number(value) !== 0;

export const hasChartData = (series) => {
    if (!Array.isArray(series) || !series.length) {
        return false;
    }

    const dataSets = series.every((item) => isPlainObject(item) && Array.isArray(item.data))
        ? series.map((item) => item.data)
        : [series];

    return dataSets.some((data) => data.some((point) => isMeaningfulChartValue(pointValue(point))));
};

export const normalizeDonutSeries = (series) => {
    if (!Array.isArray(series) || !series.length) {
        return [];
    }

    const values = series.map((value) => (
        typeof value === 'number' && Number.isFinite(value) && value >= 0
            ? value
            : null
    ));

    if (values.some((value) => value === null)) {
        return [];
    }

    return values.reduce((total, value) => total + value, 0) > 0
        ? values
        : [];
};
