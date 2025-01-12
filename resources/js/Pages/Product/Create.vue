<script setup>
import { Head} from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ProductForm from './UI/ProductForm.vue';

Dropzone.autoDiscover = false;

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

    <Head title="Product" />
    <AuthenticatedLayout >
        <ProductForm :product="product" :categories="categories" id="''"/>
    </AuthenticatedLayout>
</template>
