<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
// import Barchart from '@/components/UI/Barchart.vue';
// import Stat from '@/components/UI/Stat.vue';
// import Singlegrid from '@/components/UI/Singlegrid.vue';

const props = defineProps({
    user: Object,
    users: Array,
}
);
const seriesData = [
    {
        name: 'In-store',
        data: [200, 300, 290, 350, 150, 350, 300, 100, 125, 220, 200, 300]
    }, {
        name: 'Online',
        data: [150, 230, 382, 204, 169, 290, 300, 100, 300, 225, 120, 150]
    }
];

const categoriesData = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December'
];
</script>

<template>

    <Head title="Dashboard" />

    <AuthenticatedLayout >
        <!-- <div class="grid grid-cols-2 lg:grid-cols-4 gap-2 md:gap-3 lg:gap-5 ">
            <Singlegrid borderColor="border-t-4 border-t-cyan-600">
                <template #image>
                    <img class="bg-gray-100 w-full h-48 object-cover rounded-sm dark:bg-neutral-800"
                        src="https://images.unsplash.com/photo-1526745925052-dd824d27b9ab?q=80&w=560&auto=format&fit=crop&ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D"
                        alt="Store Image">
                </template>
                Video descriptive de l'application
            </Singlegrid >
            <Singlegrid borderColor="border-t-4 border-t-green-600">Create Quotes </Singlegrid>
            <Singlegrid borderColor="border-t-4 border-t-slate-600">Create Clients </Singlegrid>
            <Singlegrid borderColor="border-t-4 border-t-red-600">Create Jobs </Singlegrid>
        </div> -->
        <!-- Stats Grid -->
        <!-- <Stat /> -->
        <!-- End Stats Grid -->

        <!-- Utilisation du composant AreaChart -->
        <div class="mt-6">
            <!-- <Barchart :series="seriesData" :categories="categoriesData" /> -->
        </div>
    </AuthenticatedLayout>
</template>
