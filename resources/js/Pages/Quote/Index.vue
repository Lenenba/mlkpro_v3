<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import ModuleKpiSection from '@/Components/Dashboard/ModuleKpiSection.vue';
import QuoteStats from '@/Components/UI/QuoteStats.vue';
import QuoteValueStat from '@/Components/UI/QuoteValueStat.vue';
import QuoteTable from './UI/QuoteTable.vue';

const props = defineProps({
    quotes: Object,
    filters: Object,
    count: Number,
    stats: Object,
    topQuotes: Array,
    quoteValueMeta: {
        type: Object,
        default: () => ({}),
    },
    tenantCurrencyCode: {
        type: String,
        default: 'CAD',
    },
    customers: Array,
    savedSegments: {
        type: Array,
        default: () => [],
    },
    canManageSavedSegments: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <Head :title="$t('quotes.title')" />
    <AuthenticatedLayout>
        <ModuleKpiSection module-key="quotes">
            <QuoteStats
                :stats="stats"
                :currency-meta="quoteValueMeta"
                :currency-code="tenantCurrencyCode"
            />
        </ModuleKpiSection>
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-2 md:gap-3 lg:gap-5">
            <div class="col-span-1 lg:col-span-3">
                <QuoteTable
                    :quotes="quotes"
                    :filters="filters"
                    :count="count"
                    :stats="stats"
                    :customers="customers"
                    :saved-segments="savedSegments"
                    :can-manage-saved-segments="canManageSavedSegments"
                />
            </div>
            <QuoteValueStat
                :items="topQuotes"
                :filtered-count="count"
                :filtered-total="Number(stats?.total_value || 0)"
                :filtered-currency-codes="quoteValueMeta?.currency_codes || []"
                :currency-code="tenantCurrencyCode"
            />
        </div>
    </AuthenticatedLayout>
</template>
