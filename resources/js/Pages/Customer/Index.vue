<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import ModuleKpiSection from '@/Components/Dashboard/ModuleKpiSection.vue';
import CustomerStats from '@/Components/UI/CustomerStats.vue';
import CustomerActivityStat from '@/Components/UI/CustomerActivityStat.vue';
import CustomerTable from './UI/CustomerTable.vue';
import { computed, defineAsyncComponent, ref } from 'vue';
import { useAccountFeatures } from '@/Composables/useAccountFeatures';

const CustomerGrowthTrend = defineAsyncComponent(() => import('@/Components/UI/CustomerGrowthTrend.vue'));

const props = defineProps({
    customers: Object,
    filters: Object,
    count: Number,
    stats: Object,
    kpis: {
        type: Object,
        default: () => ({}),
    },
    filterMeta: {
        type: Object,
        default: () => ({}),
    },
    filterOptions: {
        type: Object,
        default: () => ({}),
    },
    topCustomers: Array,
    customerGrowthTrend: {
        type: Object,
        default: () => ({}),
    },
    bulkActions: {
        type: Object,
        default: () => ({}),
    },
    canEdit: {
        type: Boolean,
        default: false,
    },
    savedSegments: {
        type: Array,
        default: () => [],
    },
    canManageSavedSegments: {
        type: Boolean,
        default: false,
    },
    customerIndexContext: {
        type: Object,
        default: () => ({
            profile: 'generic',
            sector: null,
            capabilities: {},
            actions: {},
        }),
    },
});

const { hasFeature } = useAccountFeatures();
const customerTableRef = ref(null);
const showOperationalActivity = computed(() => (
    props.customerIndexContext?.profile !== 'appointment'
    && (hasFeature('quotes') || hasFeature('jobs'))
));
const activateKpiFilter = (action) => customerTableRef.value?.applyKpiFilter?.(action);
</script>
<template>

    <Head :title="$t('customers.title')" />
    <AuthenticatedLayout>
        <ModuleKpiSection module-key="customers">
            <CustomerStats
                :kpis="kpis"
                :stats="stats"
                :filters="filters"
                :filter-meta="filterMeta"
                :customer-index-context="customerIndexContext"
                @activate-filter="activateKpiFilter"
            />
        </ModuleKpiSection>
        <div class="grid gap-2 md:gap-3 xl:gap-5">
            <Suspense>
                <CustomerGrowthTrend :trend="customerGrowthTrend" />
                <template #fallback>
                    <div
                        class="flex min-h-80 items-center justify-center rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                        role="status"
                        aria-live="polite"
                    >
                        <span class="sr-only">{{ $t('charts.loading') }}</span>
                        <span class="h-64 w-full rounded-sm bg-stone-100 motion-safe:animate-pulse dark:bg-neutral-800" aria-hidden="true"></span>
                    </div>
                </template>
            </Suspense>

            <div class="grid grid-cols-1 gap-2 md:gap-3 xl:grid-cols-4 xl:gap-5">
                <div class="order-2 col-span-1 xl:order-1" :class="showOperationalActivity ? 'xl:col-span-3' : 'xl:col-span-4'">
                    <CustomerTable
                        ref="customerTableRef"
                        :customers="customers"
                        :filters="filters"
                        :count="count"
                        :filter-meta="filterMeta"
                        :filter-options="filterOptions"
                        :bulk-actions="bulkActions"
                        :can-edit="canEdit"
                        :saved-segments="savedSegments"
                        :can-manage-saved-segments="canManageSavedSegments"
                        :customer-index-context="customerIndexContext"
                    />
                </div>
                <CustomerActivityStat
                    v-if="showOperationalActivity"
                    class="order-1 xl:order-2"
                    :items="topCustomers"
                />
            </div>
        </div>

    </AuthenticatedLayout>
</template>
