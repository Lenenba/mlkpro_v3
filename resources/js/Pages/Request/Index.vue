<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import RequestStats from '@/Components/UI/RequestStats.vue';
import RequestTable from '@/Pages/Request/UI/RequestTable.vue';
import RequestAnalytics from '@/Pages/Request/UI/RequestAnalytics.vue';

const props = defineProps({
    requests: Object,
    filters: Object,
    stats: Object,
    analytics: Object,
    lead_intake: Object,
    customers: Array,
    statuses: Array,
    assignees: Array,
});
</script>

<template>
    <Head :title="$t('requests.title')" />
    <AuthenticatedLayout>
        <RequestStats :stats="stats" />
        <div class="mt-3">
            <RequestAnalytics :analytics="analytics" />
        </div>
        <div class="mt-3">
            <RequestTable
                :requests="requests"
                :filters="filters"
                :lead-intake="lead_intake"
                :customers="customers"
                :statuses="statuses"
                :assignees="assignees"
            />
        </div>
    </AuthenticatedLayout>
</template>
