<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ModuleKpiSection from '@/Components/Dashboard/ModuleKpiSection.vue';
import TeamStats from '@/Components/UI/TeamStats.vue';
import TeamTable from '@/Pages/Team/UI/TeamTable.vue';

const props = defineProps({
    teamMembers: Object,
    filters: {
        type: Object,
        default: () => ({}),
    },
    availablePermissions: Array,
    companyRoles: {
        type: Array,
        default: () => [],
    },
    stats: Object,
});

const { t } = useI18n();
</script>

<template>
    <Head :title="t('team.page_title')" />
    <AuthenticatedLayout>
        <ModuleKpiSection module-key="team">
            <TeamStats :stats="stats" />
        </ModuleKpiSection>
        <div class="mt-3">
            <TeamTable
                :teamMembers="teamMembers"
                :filters="filters"
                :availablePermissions="availablePermissions"
                :companyRoles="companyRoles"
            />
        </div>
    </AuthenticatedLayout>
</template>
