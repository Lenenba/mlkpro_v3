<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ProductForm from './UI/ProductForm.vue';

const props = defineProps({
    categories: {
        type: Array,
        required: true,
    },
    product: {
        type: Object,
        default: null,
    },
});


</script>

<template>

    <Head :title="$t('products.single')" />
    <AuthenticatedLayout >
        <ProductForm :product="product" :categories="categories" />
    </AuthenticatedLayout>
</template>
