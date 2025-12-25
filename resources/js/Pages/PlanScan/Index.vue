<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import PlanScanStats from '@/Components/UI/PlanScanStats.vue';
import PlanScanTable from '@/Pages/PlanScan/UI/PlanScanTable.vue';

const props = defineProps({
    scans: Object,
    stats: Object,
});
</script>

<template>
    <Head title="Plan scans" />
    <AuthenticatedLayout>
        <PlanScanStats :stats="stats" />
        <div class="mt-3">
            <PlanScanTable :scans="scans" />
        </div>
    </AuthenticatedLayout>
</template>
