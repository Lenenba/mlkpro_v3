<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import ProductTable from '@/Pages/Product/UI/ProductTable.vue';
import Stat from '@/Components/UI/Stat.vue';
import TrafficStat from '@/Components/UI/TrafficStat.vue';
const props = defineProps({
    filters: {
        type: Object,
        required: true,
    },
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
    <Head title="Product" />
    <AuthenticatedLayout>
        <Stat />
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-2 md:gap-3 lg:gap-5 ">
            <div class="col-span-1 lg:col-span-3">
                <ProductTable :products="products" :count="count" :categories="categories" :filters="filters" />
            </div>
            <TrafficStat />
        </div>
    </AuthenticatedLayout>
</template>
