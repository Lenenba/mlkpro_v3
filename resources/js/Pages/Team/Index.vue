<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import TeamStats from '@/Components/UI/TeamStats.vue';
import TeamTable from '@/Pages/Team/UI/TeamTable.vue';

const props = defineProps({
    teamMembers: Object,
    filters: {
        type: Object,
        default: () => ({}),
    },
    availablePermissions: Array,
    stats: Object,
});

const { t } = useI18n();
</script>

<template>
    <Head :title="t('team.page_title')" />
    <AuthenticatedLayout>
        <TeamStats :stats="stats" />
        <div class="mt-3">
            <TeamTable
                :teamMembers="teamMembers"
                :filters="filters"
                :availablePermissions="availablePermissions"
            />
        </div>
    </AuthenticatedLayout>
</template>
