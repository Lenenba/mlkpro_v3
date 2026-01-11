<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { isFeatureEnabled } from '@/utils/features';

const props = defineProps({
    customer: Object,
    activeWorks: {
        type: Array,
        default: () => [],
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const featureFlags = computed(() => page.props.auth?.account?.features || {});
const hasFeature = (key) => isFeatureEnabled(featureFlags.value, key);
const canJobs = computed(() => hasFeature('jobs'));
const canRequests = computed(() => hasFeature('requests'));
const canQuotes = computed(() => hasFeature('quotes'));
const canInvoices = computed(() => hasFeature('invoices'));

const tabOrder = computed(() => {
    const tabs = [];
    if (canJobs.value) {
        tabs.push('active_works');
    }
    if (canRequests.value) {
        tabs.push('requests');
    }
    if (canQuotes.value) {
        tabs.push('quotes');
    }
    if (canJobs.value) {
        tabs.push('jobs');
    }
    if (canInvoices.value) {
        tabs.push('invoices');
    }
    return tabs;
});

const isDefault = (key) => tabOrder.value[0] === key;

const stat = (key, fallback = 0) => props.stats?.[key] ?? fallback;
</script>

<template>
    <nav
        class="relative z-0 grid grid-cols-2 gap-2 border-b border-stone-200 bg-stone-50 p-3 md:grid-cols-3 xl:grid-cols-5 dark:border-neutral-700 dark:bg-neutral-900/40"
        aria-label="Tabs"
        role="tablist"
        aria-orientation="horizontal"
    >
        <button v-if="canJobs" type="button"
            class="group relative flex items-center gap-3 rounded-sm border border-stone-200 bg-white px-3 py-2 text-left shadow-sm transition hover:border-stone-300 focus:outline-none focus:ring-2 focus:ring-stone-200 hs-tab-active:border-stone-900 hs-tab-active:ring-1 hs-tab-active:ring-stone-200 dark:bg-neutral-900 dark:border-neutral-700 dark:hover:border-neutral-600 dark:focus:ring-neutral-700 dark:hs-tab-active:border-neutral-300 dark:hs-tab-active:ring-neutral-700"
            :class="{ active: isDefault('active_works') }"
            id="bar-with-underline-item-1"
            :aria-selected="isDefault('active_works')"
            data-hs-tab="#bar-with-underline-1"
            aria-controls="bar-with-underline-1"
            role="tab"
        >
            <span class="flex size-9 items-center justify-center rounded-sm bg-rose-500 text-[11px] font-semibold text-white">
                AW
            </span>
            <span class="flex flex-col leading-tight">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-stone-600 dark:text-neutral-300">
                    Active works
                </span>
                <span class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ stat('active_works', activeWorks.length) }} items
                </span>
            </span>
        </button>

        <button v-if="canRequests" type="button"
            class="group relative flex items-center gap-3 rounded-sm border border-stone-200 bg-white px-3 py-2 text-left shadow-sm transition hover:border-stone-300 focus:outline-none focus:ring-2 focus:ring-stone-200 hs-tab-active:border-stone-900 hs-tab-active:ring-1 hs-tab-active:ring-stone-200 dark:bg-neutral-900 dark:border-neutral-700 dark:hover:border-neutral-600 dark:focus:ring-neutral-700 dark:hs-tab-active:border-neutral-300 dark:hs-tab-active:ring-neutral-700"
            :class="{ active: isDefault('requests') }"
            id="bar-with-underline-item-2"
            :aria-selected="isDefault('requests')"
            data-hs-tab="#bar-with-underline-2"
            aria-controls="bar-with-underline-2"
            role="tab"
        >
            <span class="flex size-9 items-center justify-center rounded-sm bg-amber-500 text-[11px] font-semibold text-white">
                RQ
            </span>
            <span class="flex flex-col leading-tight">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-stone-600 dark:text-neutral-300">
                    Requests
                </span>
                <span class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ stat('requests', customer?.requests?.length ?? 0) }} items
                </span>
            </span>
        </button>

        <button v-if="canQuotes" type="button"
            class="group relative flex items-center gap-3 rounded-sm border border-stone-200 bg-white px-3 py-2 text-left shadow-sm transition hover:border-stone-300 focus:outline-none focus:ring-2 focus:ring-stone-200 hs-tab-active:border-stone-900 hs-tab-active:ring-1 hs-tab-active:ring-stone-200 dark:bg-neutral-900 dark:border-neutral-700 dark:hover:border-neutral-600 dark:focus:ring-neutral-700 dark:hs-tab-active:border-neutral-300 dark:hs-tab-active:ring-neutral-700"
            :class="{ active: isDefault('quotes') }"
            id="bar-with-underline-item-3"
            :aria-selected="isDefault('quotes')"
            data-hs-tab="#bar-with-underline-3"
            aria-controls="bar-with-underline-3"
            role="tab"
        >
            <span class="flex size-9 items-center justify-center rounded-sm bg-sky-500 text-[11px] font-semibold text-white">
                QT
            </span>
            <span class="flex flex-col leading-tight">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-stone-600 dark:text-neutral-300">
                    Quotes
                </span>
                <span class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ stat('quotes', customer?.quotes?.length ?? 0) }} items
                </span>
            </span>
        </button>

        <button v-if="canJobs" type="button"
            class="group relative flex items-center gap-3 rounded-sm border border-stone-200 bg-white px-3 py-2 text-left shadow-sm transition hover:border-stone-300 focus:outline-none focus:ring-2 focus:ring-stone-200 hs-tab-active:border-stone-900 hs-tab-active:ring-1 hs-tab-active:ring-stone-200 dark:bg-neutral-900 dark:border-neutral-700 dark:hover:border-neutral-600 dark:focus:ring-neutral-700 dark:hs-tab-active:border-neutral-300 dark:hs-tab-active:ring-neutral-700"
            :class="{ active: isDefault('jobs') }"
            id="bar-with-underline-item-4"
            :aria-selected="isDefault('jobs')"
            data-hs-tab="#bar-with-underline-4"
            aria-controls="bar-with-underline-4"
            role="tab"
        >
            <span class="flex size-9 items-center justify-center rounded-sm bg-emerald-500 text-[11px] font-semibold text-white">
                JB
            </span>
            <span class="flex flex-col leading-tight">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-stone-600 dark:text-neutral-300">
                    Jobs
                </span>
                <span class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ stat('jobs', customer?.works?.length ?? 0) }} items
                </span>
            </span>
        </button>

        <button v-if="canInvoices" type="button"
            class="group relative flex items-center gap-3 rounded-sm border border-stone-200 bg-white px-3 py-2 text-left shadow-sm transition hover:border-stone-300 focus:outline-none focus:ring-2 focus:ring-stone-200 hs-tab-active:border-stone-900 hs-tab-active:ring-1 hs-tab-active:ring-stone-200 dark:bg-neutral-900 dark:border-neutral-700 dark:hover:border-neutral-600 dark:focus:ring-neutral-700 dark:hs-tab-active:border-neutral-300 dark:hs-tab-active:ring-neutral-700"
            :class="{ active: isDefault('invoices') }"
            id="bar-with-underline-item-5"
            :aria-selected="isDefault('invoices')"
            data-hs-tab="#bar-with-underline-5"
            aria-controls="bar-with-underline-5"
            role="tab"
        >
            <span class="flex size-9 items-center justify-center rounded-sm bg-cyan-500 text-[11px] font-semibold text-white">
                IV
            </span>
            <span class="flex flex-col leading-tight">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-stone-600 dark:text-neutral-300">
                    Invoices
                </span>
                <span class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ stat('invoices', customer?.invoices?.length ?? 0) }} items
                </span>
            </span>
        </button>
    </nav>
</template>

