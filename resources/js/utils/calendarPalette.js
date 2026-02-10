export const calendarPalettePool = [
    {
        bg: 'bg-emerald-50',
        text: 'text-emerald-700',
        border: 'border-emerald-500',
        dot: 'bg-emerald-500',
        darkBg: 'dark:bg-emerald-500/10',
        darkText: 'dark:text-emerald-200',
        darkBorder: 'dark:border-emerald-400',
        darkDot: 'dark:bg-emerald-300',
    },
    {
        bg: 'bg-sky-50',
        text: 'text-sky-700',
        border: 'border-sky-500',
        dot: 'bg-sky-500',
        darkBg: 'dark:bg-sky-500/10',
        darkText: 'dark:text-sky-200',
        darkBorder: 'dark:border-sky-400',
        darkDot: 'dark:bg-sky-300',
    },
    {
        bg: 'bg-rose-50',
        text: 'text-rose-700',
        border: 'border-rose-500',
        dot: 'bg-rose-500',
        darkBg: 'dark:bg-rose-500/10',
        darkText: 'dark:text-rose-200',
        darkBorder: 'dark:border-rose-400',
        darkDot: 'dark:bg-rose-300',
    },
    {
        bg: 'bg-amber-50',
        text: 'text-amber-700',
        border: 'border-amber-500',
        dot: 'bg-amber-500',
        darkBg: 'dark:bg-amber-500/10',
        darkText: 'dark:text-amber-200',
        darkBorder: 'dark:border-amber-400',
        darkDot: 'dark:bg-amber-300',
    },
    {
        bg: 'bg-purple-50',
        text: 'text-purple-700',
        border: 'border-purple-500',
        dot: 'bg-purple-500',
        darkBg: 'dark:bg-purple-500/10',
        darkText: 'dark:text-purple-200',
        darkBorder: 'dark:border-purple-400',
        darkDot: 'dark:bg-purple-300',
    },
];

export const createMemberPaletteMap = (memberIds = []) => {
    const map = {};

    (memberIds || []).forEach((rawId, index) => {
        const normalizedId = Number(rawId);
        if (!Number.isFinite(normalizedId)) {
            return;
        }

        map[normalizedId] = calendarPalettePool[index % calendarPalettePool.length];
    });

    return map;
};

export const resolveMemberPalette = (paletteMap, memberId) => {
    const normalizedId = Number(memberId);
    if (Number.isFinite(normalizedId) && paletteMap?.[normalizedId]) {
        return paletteMap[normalizedId];
    }

    return calendarPalettePool[0];
};

export const resolveEventPalette = (paletteMap, event) =>
    resolveMemberPalette(paletteMap, event?.extendedProps?.team_member_id);

export const paletteDotClasses = (palette) => {
    const item = palette || calendarPalettePool[0];
    return [item.dot, item.darkDot];
};

export const paletteEventClasses = (palette, options = {}) => {
    const item = palette || calendarPalettePool[0];
    const classes = [
        'rounded-md border-l-4 px-2.5 py-1.5',
        item.bg,
        item.text,
        item.border,
        item.darkBg,
        item.darkText,
        item.darkBorder,
    ];

    if (options.selected) {
        classes.push('ring-2', 'ring-emerald-400/70', 'dark:ring-emerald-500/70');
    }

    return classes;
};
