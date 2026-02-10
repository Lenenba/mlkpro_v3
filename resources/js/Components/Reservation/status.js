const DEFAULT_STATUS_STYLE = {
    badge: 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200',
    dot: ['bg-stone-500', 'dark:bg-neutral-300'],
    event: [
        'bg-stone-50',
        'text-stone-700',
        'border-stone-500',
        'dark:bg-neutral-700/40',
        'dark:text-neutral-200',
        'dark:border-neutral-400',
    ],
};

const RESERVATION_STATUS_STYLE_MAP = {
    confirmed: {
        badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        dot: ['bg-emerald-500', 'dark:bg-emerald-300'],
        event: [
            'bg-emerald-50',
            'text-emerald-700',
            'border-emerald-500',
            'dark:bg-emerald-500/10',
            'dark:text-emerald-200',
            'dark:border-emerald-400',
        ],
    },
    pending: {
        badge: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
        dot: ['bg-amber-500', 'dark:bg-amber-300'],
        event: [
            'bg-amber-50',
            'text-amber-700',
            'border-amber-500',
            'dark:bg-amber-500/10',
            'dark:text-amber-200',
            'dark:border-amber-400',
        ],
    },
    cancelled: {
        badge: 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        dot: ['bg-rose-500', 'dark:bg-rose-300'],
        event: [
            'bg-rose-50',
            'text-rose-700',
            'border-rose-500',
            'dark:bg-rose-500/10',
            'dark:text-rose-200',
            'dark:border-rose-400',
        ],
    },
    completed: {
        badge: 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        dot: ['bg-sky-500', 'dark:bg-sky-300'],
        event: [
            'bg-sky-50',
            'text-sky-700',
            'border-sky-500',
            'dark:bg-sky-500/10',
            'dark:text-sky-200',
            'dark:border-sky-400',
        ],
    },
    rescheduled: {
        badge: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300',
        dot: ['bg-indigo-500', 'dark:bg-indigo-300'],
        event: [
            'bg-indigo-50',
            'text-indigo-700',
            'border-indigo-500',
            'dark:bg-indigo-500/10',
            'dark:text-indigo-200',
            'dark:border-indigo-400',
        ],
    },
    no_show: {
        badge: 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200',
        dot: ['bg-stone-500', 'dark:bg-neutral-300'],
        event: [
            'bg-stone-100',
            'text-stone-700',
            'border-stone-500',
            'dark:bg-neutral-700/60',
            'dark:text-neutral-200',
            'dark:border-neutral-400',
        ],
    },
};

const getReservationStatusStyle = (status) => RESERVATION_STATUS_STYLE_MAP[String(status || '')] || DEFAULT_STATUS_STYLE;

export const reservationStatusBadgeClass = (status) => getReservationStatusStyle(status).badge;

export const reservationStatusDotClasses = (status) => getReservationStatusStyle(status).dot;

export const reservationStatusEventClasses = (status, options = {}) => {
    const style = getReservationStatusStyle(status);
    const classes = [
        'rounded-md',
        'border-l-4',
        'px-2.5',
        'py-1.5',
        ...style.event,
    ];

    if (options.selected) {
        classes.push('ring-2', 'ring-emerald-400/70', 'dark:ring-emerald-500/70');
    }

    return classes;
};
