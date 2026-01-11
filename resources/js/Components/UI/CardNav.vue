<script setup>
import CardNavLink from './CardNavLink.vue';
import dayjs from 'dayjs';
import isSameOrAfter from 'dayjs/plugin/isSameOrAfter';
import QuoteList from './QuoteList.vue';
import WorkList from './WorkList.vue';
import TabEmptyState from './TabEmptyState.vue';
import RequestList from './RequestList.vue';
import InvoiceList from './InvoiceList.vue';
import OverviewSkeletonList from './OverviewSkeletonList.vue';
import { computed, reactive, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { isFeatureEnabled } from '@/utils/features';

dayjs.extend(isSameOrAfter);

const props = defineProps({
    customer: Object,
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
const hasTabs = computed(() => tabOrder.value.length > 0);

const findActiveWorks = (works) => {
    return works.filter((work) => dayjs(work.end_date).isSameOrAfter(new Date(), "day"));
};

const activeWorks = computed(() => findActiveWorks(props.customer?.works || []));

const filters = reactive({
    active_works: 'all',
    requests: 'all',
    quotes: 'all',
    jobs: 'all',
    invoices: 'all',
});

const filterOptions = {
    active_works: [
        { value: 'all', label: 'All statuses' },
        { value: 'to_schedule', label: 'To schedule' },
        { value: 'scheduled', label: 'Scheduled' },
        { value: 'en_route', label: 'En route' },
        { value: 'in_progress', label: 'In progress' },
        { value: 'tech_complete', label: 'Tech complete' },
        { value: 'pending_review', label: 'Pending review' },
        { value: 'validated', label: 'Validated' },
        { value: 'auto_validated', label: 'Auto validated' },
        { value: 'dispute', label: 'Dispute' },
        { value: 'closed', label: 'Closed' },
        { value: 'cancelled', label: 'Cancelled' },
        { value: 'completed', label: 'Completed' },
    ],
    requests: [
        { value: 'all', label: 'All statuses' },
        { value: 'REQ_NEW', label: 'New' },
        { value: 'REQ_CONVERTED', label: 'Converted' },
    ],
    quotes: [
        { value: 'all', label: 'All statuses' },
        { value: 'draft', label: 'Draft' },
        { value: 'sent', label: 'Sent' },
        { value: 'accepted', label: 'Accepted' },
        { value: 'declined', label: 'Declined' },
        { value: 'archived', label: 'Archived' },
    ],
    jobs: [
        { value: 'all', label: 'All statuses' },
        { value: 'to_schedule', label: 'To schedule' },
        { value: 'scheduled', label: 'Scheduled' },
        { value: 'en_route', label: 'En route' },
        { value: 'in_progress', label: 'In progress' },
        { value: 'tech_complete', label: 'Tech complete' },
        { value: 'pending_review', label: 'Pending review' },
        { value: 'validated', label: 'Validated' },
        { value: 'auto_validated', label: 'Auto validated' },
        { value: 'dispute', label: 'Dispute' },
        { value: 'closed', label: 'Closed' },
        { value: 'cancelled', label: 'Cancelled' },
        { value: 'completed', label: 'Completed' },
    ],
    invoices: [
        { value: 'all', label: 'All statuses' },
        { value: 'draft', label: 'Draft' },
        { value: 'sent', label: 'Sent' },
        { value: 'partial', label: 'Partial' },
        { value: 'paid', label: 'Paid' },
        { value: 'overdue', label: 'Overdue' },
        { value: 'void', label: 'Void' },
    ],
};

const safeArray = (items) => (Array.isArray(items) ? items : []);

const filterList = (items, key) => {
    const selected = filters[key];
    if (!selected || selected === 'all') {
        return items;
    }
    return items.filter((item) => (item?.status || '') === selected);
};

const filteredActiveWorks = computed(() => filterList(activeWorks.value, 'active_works'));
const filteredRequests = computed(() => filterList(safeArray(props.customer?.requests), 'requests'));
const filteredQuotes = computed(() => filterList(safeArray(props.customer?.quotes), 'quotes'));
const filteredJobs = computed(() => filterList(safeArray(props.customer?.works), 'jobs'));
const filteredInvoices = computed(() => filterList(safeArray(props.customer?.invoices), 'invoices'));

const isFiltered = (key) => filters[key] !== 'all';

const isFiltering = ref(false);
let filterTimeout;
const triggerFiltering = () => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }
    isFiltering.value = true;
    filterTimeout = setTimeout(() => {
        isFiltering.value = false;
    }, 220);
};

watch(filters, () => {
    triggerFiltering();
}, { deep: true });

const defaultPropertyId = computed(() => {
    const properties = props.customer?.properties || [];
    const match = properties.find((property) => property.is_default) || properties[0];
    return match?.id ?? null;
});

</script>

<template>
    <div
        class="flex flex-col bg-white border shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70">
        <div
            class="bg-white border-b border-stone-200 rounded-t-sm py-3 px-4 md:py-4 md:px-5 dark:bg-neutral-900 dark:border-neutral-700">
            <p class="mt-1 text-sm text-stone-700 dark:text-neutral-200">
                Overview
            </p>
        </div>
        <!-- Audience -->
        <div class="flex flex-col bg-white rounded-sm overflow-hidden dark:bg-neutral-900 dark:border-neutral-700">
            <!-- Tab Nav -->
            <CardNavLink v-if="hasTabs" :customer="customer" :activeWorks="activeWorks" :stats="stats" />
            <!-- End Tab Nav -->

            <!-- Tab Content -->
            <div class="p-4 sm:p-5">
                <div v-if="!hasTabs"
                    class="rounded-sm border border-stone-200 bg-white p-4 text-sm text-stone-500 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-400">
                    No modules are enabled for this plan.
                </div>
                <template v-else>
                    <!-- Tab Content Item -->
                    <div v-if="canJobs" id="bar-with-underline-1" role="tabpanel" aria-labelledby="bar-with-underline-item-1"
                        :class="{ hidden: !isDefault('active_works') }">
                        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                Active works
                            </div>
                            <div class="flex w-full items-center gap-2 sm:w-auto">
                                <span class="text-xs text-stone-500 dark:text-neutral-400">Status</span>
                                <select v-model="filters.active_works"
                                    class="w-full rounded-sm border border-stone-200 bg-white py-1.5 ps-2.5 pe-8 text-xs text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 sm:w-auto">
                                    <option v-for="option in filterOptions.active_works" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <OverviewSkeletonList v-if="isFiltering" />
                        <template v-else>
                            <div v-if="!filteredActiveWorks.length && isFiltered('active_works')"
                                class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                                No active works match this filter.
                            </div>
                            <TabEmptyState v-else-if="!filteredActiveWorks.length" :type="'works'" :customer="customer" />
                            <WorkList v-else :works="filteredActiveWorks" />
                        </template>

                    </div>
                    <!-- End Tab Content Item -->

                    <!-- Tab Content Item -->
                    <div v-if="canRequests" id="bar-with-underline-2" role="tabpanel"
                        aria-labelledby="bar-with-underline-item-2" :class="{ hidden: !isDefault('requests') }">
                        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                Requests
                            </div>
                            <div class="flex w-full items-center gap-2 sm:w-auto">
                                <span class="text-xs text-stone-500 dark:text-neutral-400">Status</span>
                                <select v-model="filters.requests"
                                    class="w-full rounded-sm border border-stone-200 bg-white py-1.5 ps-2.5 pe-8 text-xs text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 sm:w-auto">
                                    <option v-for="option in filterOptions.requests" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <OverviewSkeletonList v-if="isFiltering" />
                        <template v-else>
                            <div v-if="!filteredRequests.length && isFiltered('requests')"
                                class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                                No requests match this filter.
                            </div>
                            <TabEmptyState v-else-if="!filteredRequests.length" :type="'requests'" :customer="customer" />
                            <RequestList
                                v-else
                                :requests="filteredRequests"
                                :customer="customer"
                                :defaultPropertyId="defaultPropertyId"
                            />
                        </template>
                    </div>
                    <!-- End Tab Content Item -->

                    <!-- Tab Content Item -->
                    <div v-if="canQuotes" id="bar-with-underline-3" role="tabpanel"
                        aria-labelledby="bar-with-underline-item-3" :class="{ hidden: !isDefault('quotes') }">
                        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                Quotes
                            </div>
                            <div class="flex w-full items-center gap-2 sm:w-auto">
                                <span class="text-xs text-stone-500 dark:text-neutral-400">Status</span>
                                <select v-model="filters.quotes"
                                    class="w-full rounded-sm border border-stone-200 bg-white py-1.5 ps-2.5 pe-8 text-xs text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 sm:w-auto">
                                    <option v-for="option in filterOptions.quotes" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <OverviewSkeletonList v-if="isFiltering" />
                        <template v-else>
                            <div v-if="!filteredQuotes.length && isFiltered('quotes')"
                                class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                                No quotes match this filter.
                            </div>
                            <TabEmptyState v-else-if="!filteredQuotes.length" :type="'quotes'" :customer="customer" />
                            <QuoteList v-else :quotes="filteredQuotes" />
                        </template>
                    </div>
                    <!-- End Tab Content Item -->

                    <!-- Tab Content Item -->
                    <div v-if="canJobs" id="bar-with-underline-4" role="tabpanel"
                        aria-labelledby="bar-with-underline-item-4" :class="{ hidden: !isDefault('jobs') }">
                        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                Jobs
                            </div>
                            <div class="flex w-full items-center gap-2 sm:w-auto">
                                <span class="text-xs text-stone-500 dark:text-neutral-400">Status</span>
                                <select v-model="filters.jobs"
                                    class="w-full rounded-sm border border-stone-200 bg-white py-1.5 ps-2.5 pe-8 text-xs text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 sm:w-auto">
                                    <option v-for="option in filterOptions.jobs" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <OverviewSkeletonList v-if="isFiltering" />
                        <template v-else>
                            <div v-if="!filteredJobs.length && isFiltered('jobs')"
                                class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                                No jobs match this filter.
                            </div>
                            <TabEmptyState v-else-if="!filteredJobs.length" :type="'jobs'" :customer="customer" />
                            <WorkList v-else :works="filteredJobs" />
                        </template>
                    </div>
                    <!-- End Tab Content Item -->

                    <!-- Tab Content Item -->
                    <div v-if="canInvoices" id="bar-with-underline-5" role="tabpanel"
                        aria-labelledby="bar-with-underline-item-5" :class="{ hidden: !isDefault('invoices') }">
                        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                Invoices
                            </div>
                            <div class="flex w-full items-center gap-2 sm:w-auto">
                                <span class="text-xs text-stone-500 dark:text-neutral-400">Status</span>
                                <select v-model="filters.invoices"
                                    class="w-full rounded-sm border border-stone-200 bg-white py-1.5 ps-2.5 pe-8 text-xs text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 sm:w-auto">
                                    <option v-for="option in filterOptions.invoices" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <OverviewSkeletonList v-if="isFiltering" />
                        <template v-else>
                            <div v-if="!filteredInvoices.length && isFiltered('invoices')"
                                class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                                No invoices match this filter.
                            </div>
                            <TabEmptyState v-else-if="!filteredInvoices.length" :type="'invoices'" :customer="customer" />
                            <InvoiceList v-else :invoices="filteredInvoices" />
                        </template>
                    </div>
                    <!-- End Tab Content Item -->
                </template>
            </div>
            <!-- End Tab Content -->
        </div>
        <!-- End Audience -->
    </div>

</template>
