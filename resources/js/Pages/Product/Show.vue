<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ProductForm from './UI/ProductForm.vue';

defineProps({
    product: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        required: true,
    },
});
</script>

<template>
    <Head title="Product" />
    <AuthenticatedLayout>
        <ProductForm :product="product" :categories="categories" />
    </AuthenticatedLayout>
</template>
