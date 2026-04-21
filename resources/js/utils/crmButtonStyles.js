const baseClass = [
    'inline-flex',
    'items-center',
    'justify-center',
    'rounded-sm',
    'border',
    'transition',
    'disabled:pointer-events-none',
    'disabled:opacity-50',
    'focus:outline-none',
    'focus:ring-2',
].join(' ');

const sizeClasses = {
    toolbar: 'gap-x-1.5 px-2.5 py-2 text-xs font-medium',
    dialog: 'gap-x-1.5 px-3.5 py-2.5 text-xs font-semibold',
    compact: 'gap-x-1.5 px-3 py-1.5 text-xs font-semibold',
};

const variantClasses = {
    primary: 'border-transparent bg-green-600 text-white hover:bg-green-700 focus:ring-green-500',
    secondary: 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:ring-stone-300 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800 dark:focus:ring-neutral-600',
    danger: 'border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 focus:ring-rose-400 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20 dark:focus:ring-rose-500/40',
};

export const crmButtonClass = (variant = 'secondary', size = 'toolbar') => {
    const resolvedSize = sizeClasses[size] || sizeClasses.toolbar;
    const resolvedVariant = variantClasses[variant] || variantClasses.secondary;

    return [baseClass, resolvedSize, resolvedVariant].join(' ');
};

export const crmSegmentedControlClass = () => [
    'inline-flex',
    'items-center',
    'rounded-sm',
    'border',
    'border-stone-200',
    'bg-white',
    'p-0.5',
    'text-xs',
    'font-semibold',
    'text-stone-600',
    'shadow-sm',
    'dark:border-neutral-700',
    'dark:bg-neutral-900',
    'dark:text-neutral-300',
].join(' ');

export const crmSegmentedControlButtonClass = (active = false) => {
    const base = 'inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5 transition';

    if (active) {
        return `${base} bg-green-600 text-white shadow-sm dark:bg-white dark:text-stone-900`;
    }

    return `${base} text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100`;
};
