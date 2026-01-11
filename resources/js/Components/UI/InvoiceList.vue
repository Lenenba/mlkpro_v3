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

const formatStatus = (status) => (status || 'draft').replace(/_/g, ' ');

const statusPillClass = (status) => {
    switch (status) {
        case 'paid':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'partial':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'overdue':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-400';
        case 'sent':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-400';
        case 'void':
            return 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
        default:
            return 'bg-stone-100 text-stone-800 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const statusAccentClass = (status) => {
    switch (status) {
        case 'paid':
            return 'border-l-emerald-400';
        case 'partial':
            return 'border-l-amber-400';
        case 'overdue':
            return 'border-l-rose-400';
        case 'sent':
            return 'border-l-sky-400';
        case 'void':
            return 'border-l-stone-400';
        default:
            return 'border-l-stone-300 dark:border-l-neutral-600';
    }
};

const displayAmount = (invoice) => invoice.balance_due ?? invoice.total;
</script>

<template>
    <div class="space-y-3">
        <div
            v-for="invoice in invoices"
            :key="invoice.id"
            class="rounded-sm border border-stone-200 border-l-4 bg-white p-4 shadow-sm dark:bg-neutral-900 dark:border-neutral-700"
            :class="statusAccentClass(invoice.status)"
        >
            <div class="flex items-start justify-between gap-3">
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

                <div class="flex flex-col items-end gap-2">
                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatCurrency(displayAmount(invoice)) }}
                    </div>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusPillClass(invoice.status)">
                        {{ formatStatus(invoice.status) }}
                    </span>
                </div>
            </div>

            <div class="mt-3 flex items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                <div class="flex items-center gap-2">
                    <span v-if="invoice.payments_sum_amount">Paid {{ formatCurrency(invoice.payments_sum_amount) }}</span>
                    <span v-else>Total {{ formatCurrency(invoice.total) }}</span>
                </div>
                <Link
                    :href="route('invoice.show', invoice.id)"
                    class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                >
                    View invoice
                </Link>
            </div>
        </div>
    </div>
</template>
