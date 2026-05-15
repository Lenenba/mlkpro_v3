<script setup>
const props = defineProps({
    tabs: {
        type: Array,
        default: () => [],
    },
    modelValue: {
        type: String,
        default: '',
    },
    ariaLabel: {
        type: String,
        default: 'Tabs',
    },
    gridClass: {
        type: String,
        default: 'grid-cols-1 sm:grid-cols-2 xl:grid-cols-4',
    },
});

const emit = defineEmits(['update:modelValue']);

const toneClasses = {
    rose: {
        active: 'border-rose-500 bg-rose-50/70 ring-1 ring-rose-200 dark:border-rose-300 dark:bg-rose-500/10 dark:ring-rose-500/20',
        icon: 'bg-rose-500 text-white',
    },
    amber: {
        active: 'border-amber-500 bg-amber-50/70 ring-1 ring-amber-200 dark:border-amber-300 dark:bg-amber-500/10 dark:ring-amber-500/20',
        icon: 'bg-amber-500 text-white',
    },
    sky: {
        active: 'border-sky-500 bg-sky-50/70 ring-1 ring-sky-200 dark:border-sky-300 dark:bg-sky-500/10 dark:ring-sky-500/20',
        icon: 'bg-sky-500 text-white',
    },
    emerald: {
        active: 'border-emerald-500 bg-emerald-50/70 ring-1 ring-emerald-200 dark:border-emerald-300 dark:bg-emerald-500/10 dark:ring-emerald-500/20',
        icon: 'bg-emerald-500 text-white',
    },
    cyan: {
        active: 'border-cyan-500 bg-cyan-50/70 ring-1 ring-cyan-200 dark:border-cyan-300 dark:bg-cyan-500/10 dark:ring-cyan-500/20',
        icon: 'bg-cyan-500 text-white',
    },
    violet: {
        active: 'border-violet-500 bg-violet-50/70 ring-1 ring-violet-200 dark:border-violet-300 dark:bg-violet-500/10 dark:ring-violet-500/20',
        icon: 'bg-violet-500 text-white',
    },
    stone: {
        active: 'border-stone-500 bg-stone-100 ring-1 ring-stone-200 dark:border-neutral-400 dark:bg-neutral-800 dark:ring-neutral-600',
        icon: 'bg-stone-700 text-white dark:bg-neutral-200 dark:text-neutral-900',
    },
};

const fallbackInitials = (label) => String(label || '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0))
    .join('')
    .toUpperCase() || '--';

const classesFor = (tab) => {
    const tone = toneClasses[tab?.tone] || toneClasses.emerald;
    const active = props.modelValue === tab.id;

    return [
        'group relative flex min-h-[54px] items-center gap-3 rounded-sm border border-stone-200 bg-white px-3 py-2 text-left shadow-sm transition hover:border-stone-300 focus:outline-none focus:ring-2 focus:ring-stone-200 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-neutral-600 dark:focus:ring-neutral-700',
        active ? tone.active : '',
    ];
};

const iconClassesFor = (tab) => {
    const tone = toneClasses[tab?.tone] || toneClasses.emerald;

    return [
        'flex size-9 shrink-0 items-center justify-center rounded-sm text-[11px] font-semibold',
        tone.icon,
    ];
};
</script>

<template>
    <nav
        class="relative z-0 grid gap-2 border-b border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-900/40"
        :class="gridClass"
        :aria-label="ariaLabel"
        role="tablist"
        aria-orientation="horizontal"
    >
        <button
            v-for="tab in tabs"
            :key="tab.id"
            type="button"
            :class="classesFor(tab)"
            :aria-selected="modelValue === tab.id"
            role="tab"
            @click="emit('update:modelValue', tab.id)"
        >
            <span :class="iconClassesFor(tab)">
                {{ tab.initials || fallbackInitials(tab.label) }}
            </span>
            <span class="flex min-w-0 flex-col leading-tight">
                <span class="truncate text-[11px] font-semibold uppercase text-stone-600 dark:text-neutral-300">
                    {{ tab.label }}
                </span>
                <span v-if="tab.meta" class="truncate text-xs text-stone-500 dark:text-neutral-400">
                    {{ tab.meta }}
                </span>
            </span>
        </button>
    </nav>
</template>
