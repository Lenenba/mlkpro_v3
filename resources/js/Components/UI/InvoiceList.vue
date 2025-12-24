<script setup>
import { Link } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    invoices: {
        type: Array,
        default: () => [],
    },
});

const formatDate = (value) => humanizeDate(value);

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const statusClass = (status) => {
    switch (status) {
        case 'paid':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'partial':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'overdue':
            return 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400';
        case 'sent':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
        case 'void':
            return 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
        default:
            return 'bg-stone-100 text-stone-800 dark:bg-neutral-700 dark:text-neutral-200';
    }
};
</script>

<template>
    <div class="space-y-3">
        <div
            v-for="invoice in invoices"
            :key="invoice.id"
            class="flex items-center justify-between gap-3 rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:bg-neutral-900 dark:border-neutral-700"
        >
            <div>
                <Link
                    :href="route('invoice.show', invoice.id)"
                    class="text-sm font-semibold text-stone-800 hover:underline dark:text-neutral-200"
                >
                    {{ invoice.number || `Invoice #${invoice.id}` }}
                </Link>
                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                    Created {{ formatDate(invoice.created_at) }}
                </div>
            </div>

            <div class="text-right">
                <div class="text-sm font-semibold text-stone-800 dark:text-neutral-200">
                    {{ formatCurrency(invoice.balance_due ?? invoice.total) }}
                </div>
                <span class="mt-1 inline-flex items-center rounded-sm px-2 py-0.5 text-xs font-medium" :class="statusClass(invoice.status)">
                    {{ invoice.status || 'draft' }}
                </span>
            </div>
        </div>
    </div>
</template>

