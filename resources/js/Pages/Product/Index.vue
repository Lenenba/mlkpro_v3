<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import ProductTable from '@/Pages/Product/UI/ProductTable.vue';
import Stat from '@/Components/UI/Stat.vue';
const props = defineProps({
    products: {
        type: Object,
        required: true,
    },
    count: {
        type: Number,
        required: true,
    },
    categories: {
        type: Array,
        required: true,
    },
});

</script>
<template>

    <Head title="Dashboard" />

    <AuthenticatedLayout >
        <Stat />
        <ProductTable :products="products" :count="count" :categories="categories" />
    </AuthenticatedLayout>
</template>
