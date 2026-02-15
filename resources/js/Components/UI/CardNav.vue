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
import FloatingSelect from '@/Components/FloatingSelect.vue';
import { computed, reactive, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { isFeatureEnabled } from '@/utils/features';
import { useI18n } from 'vue-i18n';

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
const { t } = useI18n();
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

const filterOptions = computed(() => ({
    active_works: [
        { value: 'all', label: t('customers.tabs.filters.all') },
        { value: 'to_schedule', label: t('jobs.status.to_schedule') },
        { value: 'scheduled', label: t('jobs.status.scheduled') },
        { value: 'en_route', label: t('jobs.status.en_route') },
        { value: 'in_progress', label: t('jobs.status.in_progress') },
        { value: 'tech_complete', label: t('jobs.status.tech_complete') },
        { value: 'pending_review', label: t('jobs.status.pending_review') },
        { value: 'validated', label: t('jobs.status.validated') },
        { value: 'auto_validated', label: t('jobs.status.auto_validated') },
        { value: 'dispute', label: t('jobs.status.dispute') },
        { value: 'closed', label: t('jobs.status.closed') },
        { value: 'cancelled', label: t('jobs.status.cancelled') },
        { value: 'completed', label: t('jobs.status.completed') },
    ],
    requests: [
        { value: 'all', label: t('customers.tabs.filters.all') },
        { value: 'REQ_NEW', label: t('customers.tabs.requests.status.new') },
        { value: 'REQ_CALL_REQUESTED', label: t('customers.tabs.requests.status.call_requested') },
        { value: 'REQ_CONTACTED', label: t('customers.tabs.requests.status.contacted') },
        { value: 'REQ_QUALIFIED', label: t('customers.tabs.requests.status.qualified') },
        { value: 'REQ_QUOTE_SENT', label: t('customers.tabs.requests.status.quote_sent') },
        { value: 'REQ_WON', label: t('customers.tabs.requests.status.won') },
        { value: 'REQ_LOST', label: t('customers.tabs.requests.status.lost') },
    ],
    quotes: [
        { value: 'all', label: t('customers.tabs.filters.all') },
        { value: 'draft', label: t('quotes.status.draft') },
        { value: 'sent', label: t('quotes.status.sent') },
        { value: 'accepted', label: t('quotes.status.accepted') },
        { value: 'declined', label: t('quotes.status.declined') },
        { value: 'archived', label: t('quotes.status.archived') },
    ],
    jobs: [
        { value: 'all', label: t('customers.tabs.filters.all') },
        { value: 'to_schedule', label: t('jobs.status.to_schedule') },
        { value: 'scheduled', label: t('jobs.status.scheduled') },
        { value: 'en_route', label: t('jobs.status.en_route') },
        { value: 'in_progress', label: t('jobs.status.in_progress') },
        { value: 'tech_complete', label: t('jobs.status.tech_complete') },
        { value: 'pending_review', label: t('jobs.status.pending_review') },
        { value: 'validated', label: t('jobs.status.validated') },
        { value: 'auto_validated', label: t('jobs.status.auto_validated') },
        { value: 'dispute', label: t('jobs.status.dispute') },
        { value: 'closed', label: t('jobs.status.closed') },
        { value: 'cancelled', label: t('jobs.status.cancelled') },
        { value: 'completed', label: t('jobs.status.completed') },
    ],
    invoices: [
        { value: 'all', label: t('customers.tabs.filters.all') },
        { value: 'draft', label: t('dashboard.status.invoice.draft') },
        { value: 'sent', label: t('dashboard.status.invoice.sent') },
        { value: 'partial', label: t('dashboard.status.invoice.partial') },
        { value: 'paid', label: t('dashboard.status.invoice.paid') },
        { value: 'overdue', label: t('dashboard.status.invoice.overdue') },
        { value: 'void', label: t('dashboard.status.invoice.void') },
    ],
}));

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
                {{ t('customers.tabs.overview') }}
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
                    {{ t('customers.tabs.empty_modules') }}
                </div>
                <template v-else>
                    <!-- Tab Content Item -->
                    <div v-if="canJobs" id="bar-with-underline-1" role="tabpanel" aria-labelledby="bar-with-underline-item-1"
                        :class="{ hidden: !isDefault('active_works') }">
                        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                {{ t('customers.tabs.active_works') }}
                            </div>
                            <div class="flex w-full items-center gap-2 sm:w-auto">
                                <FloatingSelect
                                    v-model="filters.active_works"
                                    :label="t('customers.tabs.status_label')"
                                    :options="filterOptions.active_works"
                                    dense
                                    class="min-w-[160px]"
                                />
                            </div>
                        </div>
                        <OverviewSkeletonList v-if="isFiltering" />
                        <template v-else>
                            <div v-if="!filteredActiveWorks.length && isFiltered('active_works')"
                                class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                                {{ t('customers.tabs.empty_filtered', { type: t('customers.tabs.active_works') }) }}
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
                                {{ t('customers.tabs.requests.label') }}
                            </div>
                            <div class="flex w-full items-center gap-2 sm:w-auto">
                                <FloatingSelect
                                    v-model="filters.requests"
                                    :label="t('customers.tabs.status_label')"
                                    :options="filterOptions.requests"
                                    dense
                                    class="min-w-[160px]"
                                />
                            </div>
                        </div>
                        <OverviewSkeletonList v-if="isFiltering" />
                        <template v-else>
                            <div v-if="!filteredRequests.length && isFiltered('requests')"
                                class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                                {{ t('customers.tabs.empty_filtered', { type: t('customers.tabs.requests.label') }) }}
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
                                {{ t('customers.tabs.quotes') }}
                            </div>
                            <div class="flex w-full items-center gap-2 sm:w-auto">
                                <FloatingSelect
                                    v-model="filters.quotes"
                                    :label="t('customers.tabs.status_label')"
                                    :options="filterOptions.quotes"
                                    dense
                                    class="min-w-[160px]"
                                />
                            </div>
                        </div>
                        <OverviewSkeletonList v-if="isFiltering" />
                        <template v-else>
                            <div v-if="!filteredQuotes.length && isFiltered('quotes')"
                                class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                                {{ t('customers.tabs.empty_filtered', { type: t('customers.tabs.quotes') }) }}
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
                                {{ t('customers.tabs.jobs') }}
                            </div>
                            <div class="flex w-full items-center gap-2 sm:w-auto">
                                <FloatingSelect
                                    v-model="filters.jobs"
                                    :label="t('customers.tabs.status_label')"
                                    :options="filterOptions.jobs"
                                    dense
                                    class="min-w-[160px]"
                                />
                            </div>
                        </div>
                        <OverviewSkeletonList v-if="isFiltering" />
                        <template v-else>
                            <div v-if="!filteredJobs.length && isFiltered('jobs')"
                                class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                                {{ t('customers.tabs.empty_filtered', { type: t('customers.tabs.jobs') }) }}
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
                                {{ t('customers.tabs.invoices') }}
                            </div>
                            <div class="flex w-full items-center gap-2 sm:w-auto">
                                <FloatingSelect
                                    v-model="filters.invoices"
                                    :label="t('customers.tabs.status_label')"
                                    :options="filterOptions.invoices"
                                    dense
                                    class="min-w-[160px]"
                                />
                            </div>
                        </div>
                        <OverviewSkeletonList v-if="isFiltering" />
                        <template v-else>
                            <div v-if="!filteredInvoices.length && isFiltered('invoices')"
                                class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                                {{ t('customers.tabs.empty_filtered', { type: t('customers.tabs.invoices') }) }}
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
