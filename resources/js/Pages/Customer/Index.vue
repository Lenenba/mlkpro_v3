<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import Stat from '@/Components/UI/Stat.vue';
import TrafficStat from '@/Components/UI/TrafficStat.vue';
import CustomerTable from './UI/CustomerTable.vue';

const props = defineProps({
    customers: Object,
    filters: Object,
    count: Number,
});
</script>
<template>

    <Head title="Customers" />
    <AuthenticatedLayout>
        <Stat />
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-2 md:gap-3 lg:gap-5 ">
            <div class="col-span-1 lg:col-span-3">
                <CustomerTable :customers="customers"  :filters="filters" :count="count" />
            </div>
            <TrafficStat />
        </div>

    </AuthenticatedLayout>
</template>
