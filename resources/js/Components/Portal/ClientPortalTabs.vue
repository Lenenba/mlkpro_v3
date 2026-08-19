<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

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
        default: 'Client portal sections',
    },
    columns: {
        type: Number,
        default: 2,
    },
});

const emit = defineEmits(['update:modelValue']);

const activeTabId = computed(() => {
    if (props.modelValue) {
        return props.modelValue;
    }

    const explicit = props.tabs.find((tab) => tab.active);
    if (explicit) {
        return explicit.id;
    }

    return props.tabs[0]?.id || null;
});

const gridClass = computed(() => {
    if (props.columns >= 4) {
        return 'grid-cols-1 md:grid-cols-2 xl:grid-cols-4';
    }
    if (props.columns === 3) {
        return 'grid-cols-1 md:grid-cols-3';
    }
    return 'grid-cols-1 md:grid-cols-2';
});

const panelTone = (tone, active) => {
    if (!active) {
        return 'border-stone-200/80 bg-white/90 text-stone-700 hover:-translate-y-0.5 hover:border-stone-300 hover:shadow-md dark:border-neutral-700 dark:bg-neutral-900/90 dark:text-neutral-200 dark:hover:border-neutral-600';
    }

    if (tone === 'orange') {
        return 'border-orange-300/60 bg-gradient-to-br from-orange-500 via-amber-400 to-orange-300 text-white shadow-[0_20px_48px_-30px_rgba(249,115,22,0.65)]';
    }
    if (tone === 'indigo') {
        return 'border-indigo-300/60 bg-gradient-to-br from-indigo-600 via-violet-500 to-indigo-400 text-white shadow-[0_20px_48px_-30px_rgba(79,70,229,0.65)]';
    }

    return 'border-emerald-300/60 bg-gradient-to-br from-emerald-600 via-teal-500 to-emerald-400 text-white shadow-[0_20px_48px_-30px_rgba(16,185,129,0.65)]';
};

const badgeTone = (active) => (
    active
        ? 'border-white/20 bg-white/15 text-white'
        : 'border-stone-200 bg-stone-50 text-stone-500 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-400'
);

const descriptionTone = (active) => (
    active
        ? 'text-white/80'
        : 'text-stone-500 dark:text-neutral-400'
);

const handleClick = (tab) => {
    if (tab.href || tab.disabled || tab.id === activeTabId.value) {
        return;
    }

    emit('update:modelValue', tab.id);
};

const isActive = (tab) => activeTabId.value === tab.id;
</script>

<template>
    <div class="rounded-[1.75rem] border border-stone-200/80 bg-white/80 p-2 shadow-[0_24px_60px_-42px_rgba(15,23,42,0.45)] backdrop-blur dark:border-neutral-800 dark:bg-neutral-900/70">
        <div class="grid gap-2" :class="gridClass" role="tablist" :aria-label="ariaLabel">
            <component
                :is="tab.href && !tab.disabled ? Link : 'button'"
                v-for="tab in tabs"
                :key="tab.id"
                :href="tab.href && !tab.disabled ? tab.href : null"
                :type="tab.href ? null : 'button'"
                class="group rounded-[1.35rem] border px-4 py-3 text-left transition duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 focus-visible:ring-offset-stone-50 disabled:cursor-not-allowed disabled:opacity-60 dark:focus-visible:ring-offset-neutral-950"
                :class="panelTone(tab.tone, isActive(tab))"
                :disabled="tab.disabled && !tab.href"
                :aria-current="tab.href && isActive(tab) ? 'page' : null"
                @click="handleClick(tab)"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold leading-tight">
                            {{ tab.label }}
                        </p>
                        <p
                            v-if="tab.description"
                            class="mt-1 text-xs leading-5"
                            :class="descriptionTone(isActive(tab))"
                        >
                            {{ tab.description }}
                        </p>
                    </div>
                    <span
                        v-if="tab.badge !== undefined && tab.badge !== null && `${tab.badge}` !== ''"
                        class="shrink-0 rounded-full border px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.14em]"
                        :class="badgeTone(isActive(tab))"
                    >
                        {{ tab.badge }}
                    </span>
                </div>
            </component>
        </div>
    </div>
</template>
