export const BACKGROUND_PRESET_WELCOME_HERO = 'welcome-hero';
export const BACKGROUND_PRESET_GRAPHITE_CRIMSON = 'graphite-crimson';
export const BACKGROUND_PRESET_OBSIDIAN_AMBER = 'obsidian-amber';
export const BACKGROUND_PRESET_MIDNIGHT_COBALT = 'midnight-cobalt';
export const BACKGROUND_PRESET_DEEP_OCEAN = 'deep-ocean';

export const backgroundPresetMap = {
    [BACKGROUND_PRESET_WELCOME_HERO]: [
        'radial-gradient(circle at top center, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0) 24%)',
        'linear-gradient(135deg, #0d1d35 0%, #0d3137 48%, #0d5a46 100%)',
    ].join(', '),
    [BACKGROUND_PRESET_GRAPHITE_CRIMSON]: [
        'radial-gradient(circle at top center, rgba(239, 68, 68, 0.18), rgba(239, 68, 68, 0) 26%)',
        'linear-gradient(135deg, #09090b 0%, #1f1020 44%, #5f1720 100%)',
    ].join(', '),
    [BACKGROUND_PRESET_OBSIDIAN_AMBER]: [
        'radial-gradient(circle at top center, rgba(251, 191, 36, 0.16), rgba(251, 191, 36, 0) 28%)',
        'linear-gradient(135deg, #0a0a0a 0%, #1c1611 42%, #7c2d12 100%)',
    ].join(', '),
    [BACKGROUND_PRESET_MIDNIGHT_COBALT]: [
        'radial-gradient(circle at top center, rgba(96, 165, 250, 0.16), rgba(96, 165, 250, 0) 26%)',
        'linear-gradient(135deg, #08111f 0%, #0f2742 46%, #1d4ed8 100%)',
    ].join(', '),
    [BACKGROUND_PRESET_DEEP_OCEAN]: [
        'radial-gradient(circle at top center, rgba(45, 212, 191, 0.16), rgba(45, 212, 191, 0) 26%)',
        'linear-gradient(135deg, #07141d 0%, #0c2f37 46%, #155e75 100%)',
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
