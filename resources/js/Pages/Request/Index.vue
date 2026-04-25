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
    bulkActions: {
        type: Object,
        default: () => ({}),
    },
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
    <Head :title="$t('requests.title')" />
    <AuthenticatedLayout>
        <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                {{ $t('requests.workspace.title') }}
            </h1>
            <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                {{ $t('requests.workspace.subtitle') }}
            </p>
        </div>
        <div class="mt-3">
            <RequestStats :stats="stats" />
        </div>
        <div class="mt-3">
            <RequestAnalytics :analytics="analytics" />
        </div>
        <div class="mt-3">
            <RequestTable
                :requests="requests"
                :filters="filters"
                :stats="stats"
                :lead-intake="lead_intake"
                :customers="customers"
                :statuses="statuses"
                :assignees="assignees"
                :bulk-actions="bulkActions"
                :saved-segments="savedSegments"
                :can-manage-saved-segments="canManageSavedSegments"
            />
        </div>
    </AuthenticatedLayout>
</template>
