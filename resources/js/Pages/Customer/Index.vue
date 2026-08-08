<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import CustomerStats from '@/Components/UI/CustomerStats.vue';
import CustomerActivityStat from '@/Components/UI/CustomerActivityStat.vue';
import CustomerTable from './UI/CustomerTable.vue';
import { computed } from 'vue';
import { useAccountFeatures } from '@/Composables/useAccountFeatures';

const props = defineProps({
    customers: Object,
    filters: Object,
    count: Number,
    stats: Object,
    topCustomers: Array,
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
const showOperationalActivity = computed(() => (
    props.customerIndexContext?.profile !== 'appointment'
    && (hasFeature('quotes') || hasFeature('jobs'))
));
</script>
<template>

    <Head :title="$t('customers.title')" />
    <AuthenticatedLayout>
        <CustomerStats :stats="stats" :customer-index-context="customerIndexContext" />
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-2 md:gap-3 lg:gap-5 ">
            <div class="col-span-1" :class="showOperationalActivity ? 'lg:col-span-3' : 'lg:col-span-4'">
                <CustomerTable
                    :customers="customers"
                    :filters="filters"
                    :count="count"
                    :bulk-actions="bulkActions"
                    :can-edit="canEdit"
                    :saved-segments="savedSegments"
                    :can-manage-saved-segments="canManageSavedSegments"
                    :customer-index-context="customerIndexContext"
                />
            </div>
            <CustomerActivityStat v-if="showOperationalActivity" :items="topCustomers" />
        </div>

    </AuthenticatedLayout>
</template>
