<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    modelValue: {
        type: String,
        required: true,
    },
    tabs: {
        type: Array,
        required: true,
    },
    ariaLabel: {
        type: String,
        default: 'Sections',
    },
    idPrefix: {
        type: String,
        default: 'settings',
    },
});

const emit = defineEmits(['update:modelValue']);

const selectTab = (tab) => {
    if (tab.disabled || tab.id === props.modelValue) {
        return;
    }
    emit('update:modelValue', tab.id);
};

const tabId = (id) => `${props.idPrefix}-tab-${id}`;
const panelId = (id) => `${props.idPrefix}-panel-${id}`;
const isNavigation = computed(() => props.tabs.some((tab) => tab.href));
</script>

<template>
    <div class="rounded-sm border border-stone-200 bg-stone-50 p-1 shadow-sm dark:border-neutral-700 dark:bg-neutral-900/60">
        <div class="flex gap-2 overflow-x-auto" :role="isNavigation ? 'navigation' : 'tablist'" :aria-label="ariaLabel">
            <component
                :is="tab.href && !tab.disabled ? Link : 'button'"
                v-for="tab in tabs"
                :key="tab.id"
                :href="tab.href && !tab.disabled ? tab.href : null"
                :type="tab.href ? null : 'button'"
                :disabled="tab.disabled && !tab.href"
                :role="isNavigation ? null : 'tab'"
                :id="tabId(tab.id)"
                :aria-selected="isNavigation ? null : modelValue === tab.id"
                :aria-controls="isNavigation ? null : panelId(tab.id)"
                :aria-current="isNavigation && modelValue === tab.id ? 'page' : null"
                :aria-disabled="tab.disabled ? 'true' : null"
                class="flex min-w-[150px] flex-1 flex-col items-start gap-1 rounded-sm border px-3 py-2 text-left text-xs transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-1 focus-visible:ring-offset-stone-50 dark:focus-visible:ring-offset-neutral-900"
                :class="tab.disabled
                    ? 'cursor-not-allowed border-transparent text-stone-400 dark:text-neutral-500'
                    : modelValue === tab.id
                        ? 'border-emerald-200 bg-white text-stone-900 shadow-sm dark:border-emerald-500/40 dark:bg-neutral-900 dark:text-neutral-100'
                        : 'border-transparent text-stone-600 hover:bg-white/70 hover:text-stone-800 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                @click="isNavigation ? null : selectTab(tab)"
            >
                <div class="flex w-full items-center justify-between gap-2">
                    <span class="text-sm font-semibold">{{ tab.label }}</span>
                    <span
                        v-if="tab.badge"
                        class="rounded-full border border-stone-200 bg-white px-2 py-0.5 text-[9px] font-semibold uppercase tracking-[0.12em] text-stone-500 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-400"
                    >
                        {{ tab.badge }}
                    </span>
                </div>
                <span v-if="tab.description" class="text-[11px]" :class="modelValue === tab.id ? 'text-emerald-700/80 dark:text-emerald-200/70' : 'text-stone-500 dark:text-neutral-400'">
                    {{ tab.description }}
                </span>
            </component>
        </div>
    </div>
</template>
