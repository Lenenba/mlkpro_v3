<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import QuoteStats from '@/Components/UI/QuoteStats.vue';
import QuoteValueStat from '@/Components/UI/QuoteValueStat.vue';
import QuoteTable from './UI/QuoteTable.vue';

const props = defineProps({
    quotes: Object,
    filters: Object,
    count: Number,
    stats: Object,
    topQuotes: Array,
    customers: Array,
});
</script>

<template>
    <Head :title="$t('quotes.title')" />
    <AuthenticatedLayout>
        <QuoteStats :stats="stats" />
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-2 md:gap-3 lg:gap-5">
            <div class="col-span-1 lg:col-span-3">
                <QuoteTable :quotes="quotes" :filters="filters" :count="count" :customers="customers" />
            </div>
            <QuoteValueStat :items="topQuotes" />
        </div>
    </AuthenticatedLayout>
</template>
