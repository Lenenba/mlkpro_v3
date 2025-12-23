<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TaskStats from '@/Components/UI/TaskStats.vue';
import TaskTable from '@/Pages/Task/UI/TaskTable.vue';

const props = defineProps({
    tasks: Object,
    filters: Object,
    statuses: Array,
    teamMembers: Array,
    stats: Object,
    count: Number,
    canCreate: Boolean,
    canManage: Boolean,
    canDelete: Boolean,
    canEditStatus: Boolean,
    materialProducts: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <Head title="Tasks" />
    <AuthenticatedLayout>
        <TaskStats :stats="stats" />
        <div class="mt-3">
            <TaskTable
                :tasks="tasks"
                :filters="filters"
                :statuses="statuses"
                :teamMembers="teamMembers"
                :count="count"
                :canCreate="canCreate"
                :canManage="canManage"
                :canDelete="canDelete"
                :canEditStatus="canEditStatus"
                :materialProducts="materialProducts"
            />
        </div>
    </AuthenticatedLayout>
</template>
