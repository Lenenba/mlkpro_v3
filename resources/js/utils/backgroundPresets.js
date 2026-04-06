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

const parseHexColor = (value) => {
    const raw = String(value || '').trim();
    if (!raw.startsWith('#')) {
        return null;
    }

    let hex = raw.slice(1);
    if (hex.length === 3 || hex.length === 4) {
        hex = hex
            .slice(0, 3)
            .split('')
            .map((char) => char + char)
            .join('');
    } else if (hex.length === 6 || hex.length === 8) {
        hex = hex.slice(0, 6);
    } else {
        return null;
    }

    const channels = hex.match(/.{2}/g)?.map((channel) => Number.parseInt(channel, 16));
    if (!channels || channels.length !== 3 || channels.some((channel) => Number.isNaN(channel))) {
        return null;
    }

    return channels;
};

const parseRgbColor = (value) => {
    const match = String(value || '').trim().match(/^rgba?\(([^)]+)\)$/i);
    if (!match) {
        return null;
    }

    const channels = match[1]
        .split(',')
        .slice(0, 3)
        .map((channel) => Number.parseFloat(channel.trim()));

    if (channels.length !== 3 || channels.some((channel) => !Number.isFinite(channel))) {
        return null;
    }

    return channels.map((channel) => Math.max(0, Math.min(255, channel)));
};

const relativeLuminance = ([red, green, blue]) => {
    const normalized = [red, green, blue].map((channel) => channel / 255);
    const linear = normalized.map((channel) => (
        channel <= 0.03928
            ? channel / 12.92
            : ((channel + 0.055) / 1.055) ** 2.4
    ));

    return 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2];
};

export const resolveBackgroundTone = (source = {}) => {
    if (resolveBackgroundPreset(source?.background_preset)) {
        return 'dark';
    }

    const color = String(source?.background_color || '').trim();
    const channels = parseHexColor(color) || parseRgbColor(color);
    if (!channels) {
        return null;
    }

    return relativeLuminance(channels) > 0.58 ? 'light' : 'dark';
};

export const buildBackgroundToneStyle = (source = {}) => {
    const tone = resolveBackgroundTone(source);
    if (!tone) {
        return {};
    }

    if (tone === 'dark') {
        return {
            '--showcase-copy-text': '#f8fafc',
            '--showcase-copy-muted': 'rgba(226, 232, 240, 0.82)',
            '--showcase-copy-soft': 'rgba(248, 250, 252, 0.10)',
            '--showcase-copy-soft-border': 'rgba(248, 250, 252, 0.14)',
            '--showcase-copy-kicker-text': '#f8fafc',
            '--showcase-copy-link': '#f8fafc',
            '--showcase-copy-link-muted': 'rgba(226, 232, 240, 0.82)',
            '--showcase-media-tag-text': '#ffffff',
        };
    }

    return {
        '--showcase-copy-text': 'var(--page-text, #0f172a)',
        '--showcase-copy-muted': 'var(--page-muted, #64748b)',
        '--showcase-copy-soft': 'rgba(15, 23, 42, 0.05)',
        '--showcase-copy-soft-border': 'rgba(15, 23, 42, 0.08)',
        '--showcase-copy-kicker-text': 'var(--page-primary, #16a34a)',
        '--showcase-copy-link': 'var(--page-primary, #16a34a)',
        '--showcase-copy-link-muted': 'var(--page-muted, #64748b)',
        '--showcase-media-tag-text': '#ffffff',
    };
};

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
