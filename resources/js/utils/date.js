const rtfCache = new Map();

const getLocale = () => {
    if (typeof document !== 'undefined') {
        const lang = document.documentElement?.lang;
        if (lang) {
            return lang;
        }
    }

    if (typeof navigator !== 'undefined' && navigator.language) {
        return navigator.language;
    }

    return 'fr';
};

const getRtf = (locale) => {
    if (!rtfCache.has(locale)) {
        rtfCache.set(locale, new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }));
    }
    return rtfCache.get(locale);
};

export const humanizeDate = (value, options = {}) => {
    if (!value) {
        return '';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const now = options.now ? new Date(options.now) : new Date();
    const diffSeconds = Math.round((date.getTime() - now.getTime()) / 1000);
    const absSeconds = Math.abs(diffSeconds);
    const rtf = getRtf(options.locale || getLocale());

    if (absSeconds < 60) {
        return rtf.format(diffSeconds, 'second');
    }
    if (absSeconds < 60 * 60) {
        return rtf.format(Math.round(diffSeconds / 60), 'minute');
    }
    if (absSeconds < 60 * 60 * 24) {
        return rtf.format(Math.round(diffSeconds / 3600), 'hour');
    }
    if (absSeconds < 60 * 60 * 24 * 7) {
        return rtf.format(Math.round(diffSeconds / 86400), 'day');
    }
    if (absSeconds < 60 * 60 * 24 * 30) {
        return rtf.format(Math.round(diffSeconds / 604800), 'week');
    }
    if (absSeconds < 60 * 60 * 24 * 365) {
        return rtf.format(Math.round(diffSeconds / 2629800), 'month');
    }

    return rtf.format(Math.round(diffSeconds / 31557600), 'year');
};
