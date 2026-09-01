const KPI_CHART_TONE_ROOTS = Object.freeze({
    amber: 'amber',
    blue: 'blue',
    cyan: 'blue',
    emerald: 'emerald',
    fuchsia: 'violet',
    green: 'emerald',
    indigo: 'violet',
    lime: 'emerald',
    orange: 'amber',
    red: 'rose',
    rose: 'rose',
    sky: 'blue',
    slate: 'neutral',
    stone: 'neutral',
    teal: 'emerald',
    violet: 'violet',
});

export const resolveKpiChartTone = (tone) => {
    const normalizedTone = typeof tone === 'string' ? tone.trim().toLowerCase() : '';

    return KPI_CHART_TONE_ROOTS[normalizedTone] ?? 'neutral';
};
