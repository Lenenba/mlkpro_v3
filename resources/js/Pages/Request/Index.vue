<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import RequestStats from '@/Components/UI/RequestStats.vue';
import RequestTable from '@/Pages/Request/UI/RequestTable.vue';

const props = defineProps({
    requests: Object,
    filters: Object,
    stats: Object,
    customers: Array,
    statuses: Array,
});
</script>

<template>
    <Head title="Requests" />
    <AuthenticatedLayout>
        <RequestStats :stats="stats" />
        <div class="mt-3">
            <RequestTable :requests="requests" :filters="filters" :customers="customers" :statuses="statuses" />
        </div>
    </AuthenticatedLayout>
</template>

