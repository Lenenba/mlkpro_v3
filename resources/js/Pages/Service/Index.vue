<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import ServiceStats from '@/Components/UI/ServiceStats.vue';
import ServiceTable from '@/Pages/Service/UI/ServiceTable.vue';

defineProps({
    services: Object,
    filters: Object,
    count: Number,
    stats: Object,
    categories: Array,
    materialProducts: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <Head :title="$t('services.title')" />
    <AuthenticatedLayout>
        <ServiceStats :stats="stats" />
        <div class="grid grid-cols-1 gap-5">
            <ServiceTable
                :services="services"
                :filters="filters"
                :count="count"
                :categories="categories"
                :materialProducts="materialProducts"
            />
        </div>
    </AuthenticatedLayout>
</template>
