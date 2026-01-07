<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import ProductTable from '@/Pages/Product/UI/ProductTable.vue';
import ProductStats from '@/Components/UI/ProductStats.vue';
import ProductUsageStat from '@/Components/UI/ProductUsageStat.vue';
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
    stats: {
        type: Object,
        required: true,
    },
    topProducts: {
        type: Array,
        required: true,
    },
    warehouses: {
        type: Array,
        default: () => [],
    },
    defaultWarehouseId: {
        type: Number,
        default: null,
    },
});

</script>
<template>
    <Head title="Product" />
    <AuthenticatedLayout>
        <ProductStats :stats="stats" />
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-2 md:gap-3 lg:gap-5 ">
            <div class="col-span-1 lg:col-span-3">
                <ProductTable
                    :products="products"
                    :count="count"
                    :categories="categories"
                    :filters="filters"
                    :warehouses="warehouses"
                    :defaultWarehouseId="defaultWarehouseId"
                />
            </div>
            <ProductUsageStat :items="topProducts" />
        </div>
    </AuthenticatedLayout>
</template>
