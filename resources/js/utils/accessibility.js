const STORAGE_KEY = 'mlkpro.accessibility';

export const DEFAULT_ACCESSIBILITY = {
    textSize: 'md',
    contrast: 'normal',
    reduceMotion: false,
};

const sanitizeAccessibility = (raw) => {
    if (!raw || typeof raw !== 'object') {
        return { ...DEFAULT_ACCESSIBILITY };
    }

    const textSize = raw.textSize === 'sm' || raw.textSize === 'lg' ? raw.textSize : 'md';
    const contrast = raw.contrast === 'high' ? 'high' : 'normal';
    const reduceMotion = Boolean(raw.reduceMotion);

    return {
        textSize,
        contrast,
        reduceMotion,
    };
};

export const readAccessibilityPreferences = () => {
    if (typeof window === 'undefined') {
        return { ...DEFAULT_ACCESSIBILITY };
    }
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        if (!raw) {
            return { ...DEFAULT_ACCESSIBILITY };
        }
        return sanitizeAccessibility(JSON.parse(raw));
    } catch {
        return { ...DEFAULT_ACCESSIBILITY };
    }
};

export const writeAccessibilityPreferences = (prefs) => {
    if (typeof window === 'undefined') {
        return;
    }
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
    } catch {
        // ignore storage errors
    }
};

export const applyAccessibilityPreferences = (prefs) => {
    if (typeof document === 'undefined') {
        return;
    }
    const safePrefs = sanitizeAccessibility(prefs);
    const root = document.documentElement;
    root.dataset.textSize = safePrefs.textSize;
    root.dataset.contrast = safePrefs.contrast;
    root.dataset.reduceMotion = safePrefs.reduceMotion ? 'true' : 'false';
    root.classList.toggle('a11y-high-contrast', safePrefs.contrast === 'high');
    root.classList.toggle('a11y-reduce-motion', safePrefs.reduceMotion);
};
