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
    <!-- Tab Nav -->
    <nav class="relative z-0 flex border-b border-stone-200 dark:border-neutral-700 bg-white" aria-label="Tabs"
        role="tablist" aria-orientation="horizontal">
        <!-- Nav Item -->
        <button v-if="canJobs" type="button"
            class="hs-tab-active:border-t-neutral-600 relative flex-1 first:border-s-0 border-s border-t-[3px] md:border-t-4 border-t-transparent hover:border-t-stone-300 focus:outline-none focus:border-t-stone-300 p-3.5 xl:px-6 text-start focus:z-10 dark:hs-tab-active:border-t-neutral-500 dark:border-t-transparent dark:border-neutral-700 dark:hover:border-t-neutral-600 dark:focus:border-t-neutral-600"
            :class="{ active: isDefault('active_works') }"
            id="bar-with-underline-item-1" :aria-selected="isDefault('active_works')" data-hs-tab="#bar-with-underline-1"
            aria-controls="bar-with-underline-1" role="tab">
            <span class="flex gap-x-4">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-briefcase">
                    <path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16" />
                    <rect width="20" height="14" x="2" y="6" rx="2" />
                </svg>
                <span class="grow text-center md:text-start">
                    <span class="block text-xs md:text-sm text-stone-500 dark:text-neutral-500">
                        Active works
                    </span>
                    <span class="hidden xl:mt-1 md:flex md:justify-between md:items-center md:gap-x-2">
                        <span class="block text-lg lg:text-xl xl:text-2xl text-stone-800 dark:text-neutral-200">
                           {{ stat('active_works', activeWorks.length) }}
                        </span>
                    </span>
                </span>
            </span>
        </button>
        <!-- End Nav Item -->

        <!-- Nav Item -->
        <button v-if="canRequests" type="button"
            class="hs-tab-active:border-t-neutral-600 relative flex-1 first:border-s-0 border-s border-t-[3px] md:border-t-4 border-t-transparent hover:border-t-stone-300 focus:outline-none focus:border-t-stone-300 p-3.5 xl:px-6 text-start focus:z-10 dark:hs-tab-active:border-t-neutral-500 dark:border-t-transparent dark:border-neutral-700 dark:hover:border-t-neutral-600 dark:focus:border-t-neutral-600"
            :class="{ active: isDefault('requests') }"
            id="bar-with-underline-item-2" :aria-selected="isDefault('requests')" data-hs-tab="#bar-with-underline-2"
            aria-controls="bar-with-underline-2" role="tab">
            <span class="flex gap-x-4">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-git-pull-request">
                    <circle cx="18" cy="18" r="3" />
                    <circle cx="6" cy="6" r="3" />
                    <path d="M13 6h3a2 2 0 0 1 2 2v7" />
                    <line x1="6" x2="6" y1="9" y2="21" />
                </svg>
                <span class="grow text-center md:text-start">
                    <span class="block text-xs md:text-sm text-stone-500 dark:text-neutral-500">
                        Requests
                    </span>
                    <span class="hidden xl:mt-1 md:flex md:justify-between md:items-center md:gap-x-2">
                        <span class="block text-lg lg:text-xl xl:text-2xl text-stone-800 dark:text-neutral-200">
                            {{ stat('requests', customer?.requests?.length ?? 0) }}
                        </span>
                    </span>
                </span>
            </span>
        </button>
        <!-- End Nav Item -->

        <!-- Nav Item -->
        <button v-if="canQuotes" type="button"
            class="hs-tab-active:border-t-neutral-600 relative flex-1 first:border-s-0 border-s border-t-[3px] md:border-t-4 border-t-transparent hover:border-t-stone-300 focus:outline-none focus:border-t-stone-300 p-3.5 xl:px-6 text-start focus:z-10 dark:hs-tab-active:border-t-neutral-500 dark:border-t-transparent dark:border-neutral-700 dark:hover:border-t-neutral-600 dark:focus:border-t-neutral-600"
            :class="{ active: isDefault('quotes') }"
            id="bar-with-underline-item-3" :aria-selected="isDefault('quotes')" data-hs-tab="#bar-with-underline-3"
            aria-controls="bar-with-underline-3" role="tab">
            <span class="flex gap-x-4">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-text-quote">
                    <path d="M17 6H3" />
                    <path d="M21 12H8" />
                    <path d="M21 18H8" />
                    <path d="M3 12v6" />
                </svg>
                <span class="grow text-center md:text-start">
                    <span class="block text-xs md:text-sm text-stone-500 dark:text-neutral-500">
                        Quotes
                    </span>
                    <span class="hidden xl:mt-1 md:flex md:justify-between md:items-center md:gap-x-2">
                        <span class="block text-lg lg:text-xl xl:text-2xl text-stone-800 dark:text-neutral-200">
                            {{ stat('quotes', customer?.quotes?.length ?? 0) }}
                        </span>
                    </span>
                </span>
            </span>
        </button>
        <!-- End Nav Item -->

        <!-- Nav Item -->
        <button v-if="canJobs" type="button"
            class="hs-tab-active:border-t-neutral-600 relative flex-1 first:border-s-0 border-s border-t-[3px] md:border-t-4 border-t-transparent hover:border-t-stone-300 focus:outline-none focus:border-t-stone-300 p-3.5 xl:px-6 text-start focus:z-10 dark:hs-tab-active:border-t-neutral-500 dark:border-t-transparent dark:border-neutral-700 dark:hover:border-t-neutral-600 dark:focus:border-t-neutral-600"
            :class="{ active: isDefault('jobs') }"
            id="bar-with-underline-item-4" :aria-selected="isDefault('jobs')" data-hs-tab="#bar-with-underline-4"
            aria-controls="bar-with-underline-4" role="tab">
            <span class="flex gap-x-4">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-monitor-cog">
                    <path d="M12 17v4" />
                    <path d="m15.2 4.9-.9-.4" />
                    <path d="m15.2 7.1-.9.4" />
                    <path d="m16.9 3.2-.4-.9" />
                    <path d="m16.9 8.8-.4.9" />
                    <path d="m19.5 2.3-.4.9" />
                    <path d="m19.5 9.7-.4-.9" />
                    <path d="m21.7 4.5-.9.4" />
                    <path d="m21.7 7.5-.9-.4" />
                    <path d="M22 13v2a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7" />
                    <path d="M8 21h8" />
                    <circle cx="18" cy="6" r="3" />
                </svg>
                <span class="grow text-center md:text-start">
                    <span class="block text-xs md:text-sm text-stone-500 dark:text-neutral-500">
                        Jobs
                    </span>
                    <span class="hidden xl:mt-1 md:flex md:justify-between md:items-center md:gap-x-2">
                        <span class="block text-lg lg:text-xl xl:text-2xl text-stone-800 dark:text-neutral-200">
                           {{ stat('jobs', customer?.works?.length ?? 0) }}
                        </span>
                    </span>
                </span>
            </span>
        </button>
        <!-- End Nav Item -->

        <!-- Nav Item -->
        <button v-if="canInvoices" type="button"
            class="hs-tab-active:border-t-neutral-600 relative flex-1 first:border-s-0 border-s border-t-[3px] md:border-t-4 border-t-transparent hover:border-t-stone-300 focus:outline-none focus:border-t-stone-300 p-3.5 xl:px-6 text-start focus:z-10 dark:hs-tab-active:border-t-neutral-500 dark:border-t-transparent dark:border-neutral-700 dark:hover:border-t-neutral-600 dark:focus:border-t-neutral-600"
            :class="{ active: isDefault('invoices') }"
            id="bar-with-underline-item-5" :aria-selected="isDefault('invoices')" data-hs-tab="#bar-with-underline-5"
            aria-controls="bar-with-underline-5" role="tab">
            <span class="flex gap-x-4">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-file-text">
                    <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" />
                    <path d="M14 2v4a2 2 0 0 0 2 2h4" />
                    <path d="M10 9H8" />
                    <path d="M16 13H8" />
                    <path d="M16 17H8" />
                </svg>
                <span class="grow text-center md:text-start">
                    <span class="block text-xs md:text-sm text-stone-500 dark:text-neutral-500">
                        Invoices
                    </span>
                    <span class="hidden xl:mt-1 md:flex md:justify-between md:items-center md:gap-x-2">
                        <span class="block text-lg lg:text-xl xl:text-2xl text-stone-800 dark:text-neutral-200">
                            {{ stat('invoices', customer?.invoices?.length ?? 0) }}
                        </span>
                    </span>
                </span>
            </span>
        </button>
        <!-- End Nav Item -->
    </nav>
    <!-- End Tab Nav -->
</template>
