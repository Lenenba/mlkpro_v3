<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SalesStats from '@/Components/UI/SalesStats.vue';
import SalesTable from '@/Pages/Sales/UI/SalesTable.vue';

const props = defineProps({
    sales: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    customers: {
        type: Array,
        default: () => [],
    },
    stats: {
        type: Object,
        required: true,
    },
});

const { t } = useI18n();

const statusOptions = computed(() => [
    { value: '', label: t('sales.index.filters.all_sales') },
    { value: 'paid', label: t('sales.status.paid') },
]);
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="$t('sales.index.title')" />

        <div class="space-y-4">
            <SalesStats :stats="stats" />

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('sales.index.title') }}
                    </h1>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('sales.index.subtitle') }}
                    </p>
                </div>
                <Link
                    :href="route('sales.create')"
                    class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700"
                >
                    {{ $t('sales.index.new_sale') }}
                </Link>
            </div>

            <SalesTable
                :sales="sales"
                :filters="filters"
                :customers="customers"
                :status-options="statusOptions"
            />
        </div>
    </AuthenticatedLayout>
</template>
