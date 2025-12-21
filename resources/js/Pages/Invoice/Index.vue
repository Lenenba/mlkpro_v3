<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import InvoiceStats from '@/Components/UI/InvoiceStats.vue';
import InvoiceTable from '@/Pages/Invoice/UI/InvoiceTable.vue';

const props = defineProps({
    invoices: Object,
    filters: Object,
    stats: Object,
    count: Number,
    customers: Array,
});
</script>

<template>
    <Head title="Invoices" />
    <AuthenticatedLayout>
        <InvoiceStats :stats="stats" />
        <div class="mt-3">
            <InvoiceTable :invoices="invoices" :filters="filters" :customers="customers" />
        </div>
    </AuthenticatedLayout>
</template>
