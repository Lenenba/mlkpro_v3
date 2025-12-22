<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TeamStats from '@/Components/UI/TeamStats.vue';
import TeamTable from '@/Pages/Team/UI/TeamTable.vue';

const props = defineProps({
    teamMembers: Array,
    availablePermissions: Array,
    stats: Object,
});
</script>

<template>
    <Head title="Team" />
    <AuthenticatedLayout>
        <TeamStats :stats="stats" />
        <div class="mt-3">
            <TeamTable :teamMembers="teamMembers" :availablePermissions="availablePermissions" />
        </div>
    </AuthenticatedLayout>
</template>

