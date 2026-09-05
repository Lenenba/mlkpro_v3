<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import ModuleKpiSection from '@/Components/Dashboard/ModuleKpiSection.vue';
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
        <ModuleKpiSection module-key="plan-scans">
            <PlanScanStats :stats="stats" />
        </ModuleKpiSection>
        <div class="mt-3">
            <PlanScanTable :scans="scans" />
        </div>
    </AuthenticatedLayout>
</template>
