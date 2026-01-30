<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: String,
    svg: String,
    label: String,
    compact: {
        type: Boolean,
        default: true,
    },
    active: {
        type: Boolean,
    },
    tone: {
        type: String,
        default: '',
    },
});

const tonePalette = {
    dashboard: { accent: '#0ea5e9', glow: 'rgba(14,165,233,0.35)', text: 'text-sky-600 dark:text-sky-400' },
    admin: { accent: '#6366f1', glow: 'rgba(99,102,241,0.35)', text: 'text-indigo-600 dark:text-indigo-400' },
    tenants: { accent: '#14b8a6', glow: 'rgba(20,184,166,0.35)', text: 'text-teal-600 dark:text-teal-400' },
    support: { accent: '#f59e0b', glow: 'rgba(245,158,11,0.35)', text: 'text-amber-600 dark:text-amber-400' },
    admins: { accent: '#a855f7', glow: 'rgba(168,85,247,0.35)', text: 'text-purple-600 dark:text-purple-400' },
    notifications: { accent: '#f97316', glow: 'rgba(249,115,22,0.35)', text: 'text-orange-600 dark:text-orange-400' },
    announcements: { accent: '#ec4899', glow: 'rgba(236,72,153,0.35)', text: 'text-pink-600 dark:text-pink-400' },
    welcome_builder: { accent: '#22c55e', glow: 'rgba(34,197,94,0.35)', text: 'text-green-600 dark:text-green-400' },
    pages: { accent: '#0ea5e9', glow: 'rgba(14,165,233,0.35)', text: 'text-sky-600 dark:text-sky-400' },
    sections: { accent: '#14b8a6', glow: 'rgba(20,184,166,0.35)', text: 'text-teal-600 dark:text-teal-400' },
    assets: { accent: '#38bdf8', glow: 'rgba(56,189,248,0.35)', text: 'text-sky-500 dark:text-sky-300' },
    settings: { accent: '#64748b', glow: 'rgba(100,116,139,0.35)', text: 'text-slate-600 dark:text-slate-300' },
    customers: { accent: '#8b5cf6', glow: 'rgba(139,92,246,0.35)', text: 'text-violet-600 dark:text-violet-400' },
    products: { accent: '#3b82f6', glow: 'rgba(59,130,246,0.35)', text: 'text-blue-600 dark:text-blue-400' },
    orders: { accent: '#d946ef', glow: 'rgba(217,70,239,0.35)', text: 'text-fuchsia-600 dark:text-fuchsia-400' },
    sales: { accent: '#f97316', glow: 'rgba(249,115,22,0.35)', text: 'text-orange-600 dark:text-orange-400' },
    services: { accent: '#10b981', glow: 'rgba(16,185,129,0.35)', text: 'text-emerald-600 dark:text-emerald-400' },
    categories: { accent: '#38bdf8', glow: 'rgba(56,189,248,0.35)', text: 'text-sky-600 dark:text-sky-400' },
    quotes: { accent: '#f59e0b', glow: 'rgba(245,158,11,0.35)', text: 'text-amber-600 dark:text-amber-400' },
    plan_scans: { accent: '#64748b', glow: 'rgba(100,116,139,0.35)', text: 'text-slate-600 dark:text-slate-300' },
    requests: { accent: '#06b6d4', glow: 'rgba(6,182,212,0.35)', text: 'text-cyan-600 dark:text-cyan-400' },
    jobs: { accent: '#6366f1', glow: 'rgba(99,102,241,0.35)', text: 'text-indigo-600 dark:text-indigo-400' },
    tasks: { accent: '#14b8a6', glow: 'rgba(20,184,166,0.35)', text: 'text-teal-600 dark:text-teal-400' },
    team: { accent: '#84cc16', glow: 'rgba(132,204,22,0.35)', text: 'text-lime-600 dark:text-lime-400' },
    invoices: { accent: '#f43f5e', glow: 'rgba(244,63,94,0.35)', text: 'text-rose-600 dark:text-rose-400' },
    default: { accent: '#10b981', glow: 'rgba(16,185,129,0.25)', text: 'text-emerald-600 dark:text-emerald-400' },
};

const toneStyle = computed(() => {
    const tone = tonePalette[props.tone] || tonePalette.default;
    return {
        '--icon-accent': tone.accent,
        '--icon-glow': props.active ? tone.glow : 'rgba(0,0,0,0)',
        '--icon-ring': props.active ? tone.glow : 'rgba(0,0,0,0)',
    };
});

const toneTextClass = computed(() => {
    if (!props.active) {
        return '';
    }
    const tone = tonePalette[props.tone] || tonePalette.default;
    return tone.text;
});


const classes = computed(() =>
    props.active
        ? 'relative flex justify-center items-center gap-x-3 size-9 rounded-sm group-hover:bg-stone-200 group-focus:bg-stone-200 dark:group-hover:bg-neutral-800 dark:group-focus:bg-neutral-800 transition bg-stone-200 dark:bg-neutral-800'
        : 'relative flex justify-center items-center gap-x-3 size-9 rounded-sm group-hover:bg-stone-100 group-focus:bg-stone-100 dark:group-hover:bg-neutral-800 dark:group-focus:bg-neutral-800 transition',
);

const anchorRef = ref(null);
const anchorEl = ref(null);
const tooltipVisible = ref(false);
const tooltipStyle = ref({});
let listenersBound = false;

const getAnchorElement = () => {
    if (!anchorRef.value) {
        return null;
    }
    return anchorRef.value.$el ? anchorRef.value.$el : anchorRef.value;
};

const updateTooltipPosition = () => {
    const el = anchorEl.value || getAnchorElement();
    if (!el) {
        return;
    }
    const rect = el.getBoundingClientRect();
    tooltipStyle.value = {
        left: `${rect.right + 10}px`,
        top: `${rect.top + rect.height / 2}px`,
    };
};

const addListeners = () => {
    if (listenersBound) {
        return;
    }
    window.addEventListener('resize', updateTooltipPosition);
    window.addEventListener('scroll', updateTooltipPosition, true);
    listenersBound = true;
};

const removeListeners = () => {
    if (!listenersBound) {
        return;
    }
    window.removeEventListener('resize', updateTooltipPosition);
    window.removeEventListener('scroll', updateTooltipPosition, true);
    listenersBound = false;
};

const showTooltip = (event) => {
    if (!props.compact) {
        return;
    }
    anchorEl.value = event?.currentTarget || getAnchorElement();
    tooltipVisible.value = true;
    updateTooltipPosition();
    addListeners();
};

const hideTooltip = () => {
    tooltipVisible.value = false;
    anchorEl.value = null;
    removeListeners();
};

onBeforeUnmount(() => {
    removeListeners();
});
</script>
<template>
    <li>
        <Link
            ref="anchorRef"
            :href="route(href)"
            class="relative group flex flex-col justify-center items-center gap-y-1 text-[11px] text-stone-600 dark:text-neutral-200 disabled:opacity-50 disabled:pointer-events-none focus:outline-none"
            :class="toneTextClass"
            :aria-label="label"
            :title="label"
            @mouseenter="showTooltip"
            @mouseleave="hideTooltip"
            @focus="showTooltip"
            @blur="hideTooltip">
            <span
                :class="[classes, 'nav-icon-wrap', props.active ? 'nav-icon-active' : '']"
                :data-tone="props.tone || null"
                :style="toneStyle">
                <slot name="icon"/>
            </span>
            <span v-if="!compact">{{ label }}</span>
            <span v-else class="sr-only">{{ label }}</span>
        </Link>
        <Teleport to="body">
            <div
                v-if="compact && tooltipVisible"
                class="fixed z-[9999] -translate-y-1/2 whitespace-nowrap rounded-sm bg-stone-900 px-2 py-1 text-[11px] text-white shadow-lg pointer-events-none dark:bg-neutral-800"
                :style="tooltipStyle"
                role="tooltip"
            >
                {{ label }}
            </div>
        </Teleport>
    </li>
</template>

<style scoped>
:deep(.nav-icon-wrap svg) {
    color: var(--icon-accent, currentColor);
    transition: color 200ms ease, transform 200ms ease, filter 200ms ease;
}

:deep(.nav-icon-active) {
    box-shadow: 0 0 0 1px var(--icon-ring, transparent);
}

:deep(.nav-icon-active svg) {
    color: var(--icon-accent, currentColor);
    filter: drop-shadow(0 0 6px var(--icon-glow, rgba(0, 0, 0, 0)));
    animation: navPulse 1.6s ease-in-out infinite;
}

:deep(.nav-icon-active[data-tone="services"] svg) {
    animation: navPulse 1.6s ease-in-out infinite, navWiggle 1.4s ease-in-out infinite;
    transform-origin: 50% 50%;
}

@keyframes navPulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.06);
    }
}

@keyframes navWiggle {
    0%, 100% {
        transform: rotate(0deg);
    }
    25% {
        transform: rotate(-6deg);
    }
    50% {
        transform: rotate(6deg);
    }
    75% {
        transform: rotate(-3deg);
    }
}

@media (prefers-reduced-motion: reduce) {
    :deep(.nav-icon-active svg),
    :deep(.nav-icon-active[data-tone="services"] svg) {
        animation: none;
    }
}
</style>
