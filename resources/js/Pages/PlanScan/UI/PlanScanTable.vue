<script setup>
import { Link } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    scans: {
        type: Object,
        required: true,
    },
});

const formatDate = (value) => humanizeDate(value);

const displayCustomer = (customer) =>
    customer?.company_name ||
    `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim() ||
    'Unknown';

const statusLabel = (status) => {
    switch (status) {
        case 'ready':
            return 'Ready';
        case 'processing':
            return 'Processing';
        case 'failed':
            return 'Failed';
        case 'new':
            return 'New';
        default:
            return status || 'Unknown';
    }
};

const statusClass = (status) => {
    switch (status) {
        case 'ready':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'processing':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'failed':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-400';
        case 'new':
            return 'bg-stone-100 text-stone-800 dark:bg-neutral-700 dark:text-neutral-200';
        default:
            return 'bg-stone-100 text-stone-800 dark:bg-neutral-700 dark:text-neutral-200';
    }
};
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700"
    >
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            <div class="space-y-1">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Plan scans</h2>
                <p class="text-xs text-stone-500 dark:text-neutral-400">
                    Scan a plan and generate quote variants with benchmarks.
                </p>
            </div>
            <Link
                :href="route('plan-scans.create')"
                class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700"
            >
                New scan
            </Link>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                <thead>
                    <tr>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            Project
                        </th>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            Customer
                        </th>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            Trade
                        </th>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            Status
                        </th>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            Confidence
                        </th>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            Updated
                        </th>
                        <th class="px-5 py-2.5 text-end text-sm font-normal text-stone-500 dark:text-neutral-500">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                    <tr v-for="scan in scans.data" :key="scan.id">
                        <td class="px-5 py-3">
                            <div class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                                {{ scan.job_title || `Plan scan #${scan.id}` }}
                            </div>
                            <div v-if="scan.plan_file_name" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ scan.plan_file_name }}
                            </div>
                        </td>
                        <td class="px-5 py-3 text-sm text-stone-700 dark:text-neutral-300">
                            <div v-if="scan.customer">
                                {{ displayCustomer(scan.customer) }}
                            </div>
                            <div v-else class="text-xs text-stone-500 dark:text-neutral-400">
                                Unassigned
                            </div>
                        </td>
                        <td class="px-5 py-3 text-sm text-stone-700 dark:text-neutral-300">
                            {{ scan.trade_type || '-' }}
                        </td>
                        <td class="px-5 py-3">
                            <span class="inline-flex items-center rounded-sm px-2 py-0.5 text-xs font-medium" :class="statusClass(scan.status)">
                                {{ statusLabel(scan.status) }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-sm text-stone-700 dark:text-neutral-300">
                            {{ scan.confidence_score ? `${scan.confidence_score}%` : '--' }}
                        </td>
                        <td class="px-5 py-3 text-sm text-stone-700 dark:text-neutral-300">
                            {{ formatDate(scan.updated_at) }}
                        </td>
                        <td class="px-5 py-3 text-end">
                            <Link
                                :href="route('plan-scans.show', scan.id)"
                                class="text-sm text-green-700 hover:underline dark:text-green-400"
                            >
                                View
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="!scans.data.length">
                        <td colspan="7" class="px-5 py-6 text-center text-sm text-stone-500 dark:text-neutral-400">
                            No plan scans found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="scans.next_page_url || scans.prev_page_url" class="flex items-center justify-between gap-3">
            <Link
                v-if="scans.prev_page_url"
                :href="scans.prev_page_url"
                class="py-2 px-3 rounded-sm border border-stone-200 bg-white text-sm text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
            >
                Previous
            </Link>
            <span class="text-xs text-stone-500 dark:text-neutral-400">
                Showing {{ scans.from || 0 }}-{{ scans.to || 0 }}
            </span>
            <Link
                v-if="scans.next_page_url"
                :href="scans.next_page_url"
                class="py-2 px-3 rounded-sm border border-stone-200 bg-white text-sm text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
            >
                Next
            </Link>
        </div>
    </div>
</template>
