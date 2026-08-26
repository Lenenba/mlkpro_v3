<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import ModuleKpiSection from '@/Components/Dashboard/ModuleKpiSection.vue';
import TaskStats from '@/Components/UI/TaskStats.vue';
import TaskTable from '@/Pages/Task/UI/TaskTable.vue';

const props = defineProps({
    tasks: Object,
    filters: Object,
    statuses: Array,
    priorities: Array,
    teamMembers: Array,
    prospects: {
        type: Array,
        default: () => [],
    },
    works: {
        type: Array,
        default: () => [],
    },
    stats: Object,
    count: Number,
    taskWindow: {
        type: Object,
        default: () => ({}),
    },
    canCreate: Boolean,
    canManage: Boolean,
    canDelete: Boolean,
    canEditStatus: Boolean,
    canViewTeam: Boolean,
    materialProducts: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <Head :title="$t('tasks.title')" />
    <AuthenticatedLayout>
        <ModuleKpiSection module-key="tasks">
            <TaskStats :stats="stats" />
        </ModuleKpiSection>
        <div class="mt-3">
            <TaskTable
                :tasks="tasks"
                :filters="filters"
                :statuses="statuses"
                :priorities="priorities"
                :teamMembers="teamMembers"
                :prospects="prospects"
                :works="works"
                :count="count"
                :taskWindow="taskWindow"
                :canCreate="canCreate"
                :canManage="canManage"
                :canDelete="canDelete"
                :canEditStatus="canEditStatus"
                :canViewTeam="canViewTeam"
                :materialProducts="materialProducts"
            />
        </div>
    </AuthenticatedLayout>
</template>
