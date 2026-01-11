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

const displayAmount = (invoice) => invoice.balance_due ?? invoice.total;
</script>

<template>
    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
        <div
            v-for="invoice in invoices"
            :key="invoice.id"
            class="overflow-hidden rounded-sm border border-stone-200 bg-white shadow-sm dark:bg-neutral-900 dark:border-neutral-700"
        >
            <div class="flex items-center justify-between gap-3 border-b border-stone-200 bg-stone-50/60 px-4 py-3 dark:border-neutral-700 dark:bg-neutral-900/40">
                <div class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-sm bg-cyan-500 text-[11px] font-semibold text-white">
                        IV
                    </span>
                    <div>
                        <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ invoice.number || `Invoice #${invoice.id}` }}
                        </div>
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            #{{ invoice.id }}
                        </div>
                    </div>
                </div>

                <div class="hs-dropdown [--placement:bottom-right] relative inline-flex">
                    <button :id="`invoice-actions-${invoice.id}`" type="button"
                        class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-500 shadow-sm hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                        aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                            height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="1" />
                            <circle cx="12" cy="5" r="1" />
                            <circle cx="12" cy="19" r="1" />
                        </svg>
                    </button>

                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                        role="menu" aria-orientation="vertical" :aria-labelledby="`invoice-actions-${invoice.id}`">
                        <div class="p-1">
                            <Link
                                :href="route('invoice.show', invoice.id)"
                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-stone-800 hover:bg-stone-100 focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                            >
                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" />
                                    <path d="M14 2v4a2 2 0 0 0 2 2h4" />
                                    <path d="M10 9H8" />
                                    <path d="M16 13H8" />
                                    <path d="M16 17H8" />
                                </svg>
                                View invoice
                            </Link>
                        </div>
                    </div>
                </div>
            </div>

            <div class="divide-y divide-stone-200 px-4 py-2 text-xs text-stone-500 dark:divide-neutral-700 dark:text-neutral-400">
                <div class="flex items-center justify-between py-2">
                    <span>Issued</span>
                    <span class="text-sm font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatDate(invoice.created_at) }}
                    </span>
                </div>
                <div class="flex items-center justify-between py-2">
                    <span>Amount</span>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-stone-800 dark:text-neutral-200">
                            {{ formatCurrency(displayAmount(invoice)) }}
                        </span>
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusPillClass(invoice.status)">
                            {{ formatStatus(invoice.status) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
