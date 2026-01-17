<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import WorkStats from '@/Components/UI/WorkStats.vue';
import WorkTable from '@/Pages/Work/UI/WorkTable.vue';

const props = defineProps({
    works: Object,
    filters: Object,
    stats: Object,
    customers: Array,
});
</script>

<template>
    <Head :title="$t('jobs.title')" />
    <AuthenticatedLayout>
        <WorkStats :stats="stats" />
        <div class="mt-3">
            <WorkTable :works="works" :filters="filters" :customers="customers" />
        </div>
    </AuthenticatedLayout>
</template>
