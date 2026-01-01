<script setup>
import CardNavLink from './CardNavLink.vue';
import dayjs from 'dayjs';
import isSameOrAfter from 'dayjs/plugin/isSameOrAfter';
import QuoteList from './QuoteList.vue';
import WorkList from './WorkList.vue';
import TabEmptyState from './TabEmptyState.vue';
import RequestList from './RequestList.vue';
import InvoiceList from './InvoiceList.vue';
import { computed } from 'vue';
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
            <div class="p-5">
                <div v-if="!hasTabs"
                    class="rounded-sm border border-stone-200 bg-white p-4 text-sm text-stone-500 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-400">
                    No modules are enabled for this plan.
                </div>
                <template v-else>
                    <!-- Tab Content Item -->
                    <div v-if="canJobs" id="bar-with-underline-1" role="tabpanel" aria-labelledby="bar-with-underline-item-1"
                        :class="{ hidden: !isDefault('active_works') }">
                        <!-- Empty State -->
                        <TabEmptyState :type="'works'" v-if="activeWorks.length === 0" :customer="customer" />
                        <!-- End Empty State -->

                        <WorkList v-else :works="activeWorks" />

                    </div>
                    <!-- End Tab Content Item -->

                    <!-- Tab Content Item -->
                    <div v-if="canRequests" id="bar-with-underline-2" role="tabpanel"
                        aria-labelledby="bar-with-underline-item-2" :class="{ hidden: !isDefault('requests') }">
                        <!-- Empty State -->
                        <TabEmptyState :type="'requests'" v-if="!customer?.requests?.length" :customer="customer" />

                        <!-- End Empty State -->
                        <RequestList
                            v-else
                            :requests="customer.requests"
                            :customer="customer"
                            :defaultPropertyId="defaultPropertyId"
                        />
                    </div>
                    <!-- End Tab Content Item -->

                    <!-- Tab Content Item -->
                    <div v-if="canQuotes" id="bar-with-underline-3" role="tabpanel"
                        aria-labelledby="bar-with-underline-item-3" :class="{ hidden: !isDefault('quotes') }">
                        <!-- Empty State -->
                        <TabEmptyState :type="'quotes'" v-if="!customer?.quotes?.length" :customer="customer" />
                        <!-- End Empty State -->
                        <QuoteList v-else :quotes="customer.quotes" />
                    </div>
                    <!-- End Tab Content Item -->

                    <!-- Tab Content Item -->
                    <div v-if="canJobs" id="bar-with-underline-4" role="tabpanel"
                        aria-labelledby="bar-with-underline-item-4" :class="{ hidden: !isDefault('jobs') }">
                        <TabEmptyState :type="'jobs'" v-if="!customer?.works?.length" :customer="customer" />

                        <WorkList v-else :works="customer.works" />
                    </div>
                    <!-- End Tab Content Item -->

                    <!-- Tab Content Item -->
                    <div v-if="canInvoices" id="bar-with-underline-5" role="tabpanel"
                        aria-labelledby="bar-with-underline-item-5" :class="{ hidden: !isDefault('invoices') }">
                        <TabEmptyState :type="'invoices'" v-if="!customer?.invoices?.length" :customer="customer" />
                        <InvoiceList v-else :invoices="customer.invoices" />
                    </div>
                    <!-- End Tab Content Item -->
                </template>
            </div>
            <!-- End Tab Content -->
        </div>
        <!-- End Audience -->
    </div>

</template>
