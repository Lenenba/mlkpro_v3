export const BACKGROUND_PRESET_WELCOME_HERO = 'welcome-hero';

export const backgroundPresetMap = {
    [BACKGROUND_PRESET_WELCOME_HERO]: [
        'radial-gradient(circle at top center, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0) 24%)',
        'linear-gradient(135deg, #0d1d35 0%, #0d3137 48%, #0d5a46 100%)',
    ].join(', '),
};

export const backgroundPresetKeys = Object.keys(backgroundPresetMap);

export const resolveBackgroundPreset = (value) => (
    backgroundPresetMap[String(value || '').trim()] || ''
);

export const resolveBackgroundValue = (source = {}) => {
    const presetValue = resolveBackgroundPreset(source?.background_preset);
    if (presetValue) {
        return presetValue;
    }

    return String(source?.background_color || '').trim();
};

export const buildBackgroundStyle = (source = {}) => {
    const background = resolveBackgroundValue(source);

    return background ? { background } : {};
};
