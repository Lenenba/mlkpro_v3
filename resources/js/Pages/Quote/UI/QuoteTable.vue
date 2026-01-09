<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import StarRating from '@/Components/UI/StarRating.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    quotes: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    count: {
        type: Number,
        required: true,
    },
    customers: {
        type: Array,
        default: () => [],
    },
});

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
    customer_id: props.filters?.customer_id ?? '',
    total_min: props.filters?.total_min ?? '',
    total_max: props.filters?.total_max ?? '',
    created_from: props.filters?.created_from ?? '',
    created_to: props.filters?.created_to ?? '',
    has_deposit: props.filters?.has_deposit ?? '',
    has_tax: props.filters?.has_tax ?? '',
    sort: props.filters?.sort ?? 'created_at',
    direction: props.filters?.direction ?? 'desc',
});

const showAdvanced = ref(false);
const newQuoteCustomerId = ref('');
const isLoading = ref(false);

const filterPayload = () => {
    const payload = {
        search: filterForm.search,
        status: filterForm.status,
        customer_id: filterForm.customer_id,
        total_min: filterForm.total_min,
        total_max: filterForm.total_max,
        created_from: filterForm.created_from,
        created_to: filterForm.created_to,
        has_deposit: filterForm.has_deposit,
        has_tax: filterForm.has_tax,
        sort: filterForm.sort,
        direction: filterForm.direction,
    };

    Object.keys(payload).forEach((key) => {
        const value = payload[key];
        if (value === '' || value === null || value === undefined) {
            delete payload[key];
        }
    });

    return payload;
};

let filterTimeout;
const autoFilter = () => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }
    filterTimeout = setTimeout(() => {
        isLoading.value = true;
        router.get(route('quote.index'), filterPayload(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                isLoading.value = false;
            },
        });
    }, 300);
};

watch(() => filterForm.search, autoFilter);
watch(() => [
    filterForm.status,
    filterForm.customer_id,
    filterForm.total_min,
    filterForm.total_max,
    filterForm.created_from,
    filterForm.created_to,
    filterForm.has_deposit,
    filterForm.has_tax,
    filterForm.sort,
    filterForm.direction,
], autoFilter);

const clearFilters = () => {
    filterForm.search = '';
    filterForm.status = '';
    filterForm.customer_id = '';
    filterForm.total_min = '';
    filterForm.total_max = '';
    filterForm.created_from = '';
    filterForm.created_to = '';
    filterForm.has_deposit = '';
    filterForm.has_tax = '';
    filterForm.sort = 'created_at';
    filterForm.direction = 'desc';
    autoFilter();
};

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const formatDate = (value) => humanizeDate(value);

const displayCustomer = (customer) =>
    customer?.company_name ||
    `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim() ||
    'Unknown';

const statusClasses = (status) => {
    switch (status) {
        case 'archived':
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
        case 'accepted':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'declined':
            return 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400';
        case 'sent':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
        default:
            return 'bg-stone-100 text-stone-800 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const isArchived = (quote) => Boolean(quote?.archived_at);
const displayStatus = (quote) => (isArchived(quote) ? 'archived' : (quote?.status || 'draft'));

const toggleSort = (column) => {
    if (filterForm.sort === column) {
        filterForm.direction = filterForm.direction === 'asc' ? 'desc' : 'asc';
        return;
    }
    filterForm.sort = column;
    filterForm.direction = 'asc';
};

const sendEmail = (quote) => {
    router.post(route('quote.send.email', quote), {}, { preserveScroll: true });
};

const archiveQuote = (quote) => {
    if (!confirm(`Archive quote ${quote.number || ''}?`)) {
        return;
    }
    router.delete(route('customer.quote.destroy', quote), { preserveScroll: true });
};

const restoreQuote = (quote) => {
    router.post(route('customer.quote.restore', quote), {}, { preserveScroll: true });
};

const convertToJob = (quote) => {
    router.post(route('quote.convert', quote), {}, { preserveScroll: true });
};

const startQuote = () => {
    if (!newQuoteCustomerId.value) {
        return;
    }
    router.get(route('customer.quote.create', newQuoteCustomerId.value));
};
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-sky-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
        <div class="space-y-3">
            <div class="flex flex-col lg:flex-row lg:items-center gap-2">
                <div class="flex-1">
                    <div class="relative">
                        <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                            <svg class="shrink-0 size-4 text-stone-500 dark:text-neutral-400"
                                xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8" />
                                <path d="m21 21-4.3-4.3" />
                            </svg>
                        </div>
                        <input type="text" v-model="filterForm.search"
                            class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                            placeholder="Search quotes, customer, or notes">
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 justify-end">
                    <select v-model="filterForm.status"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:focus:ring-neutral-600">
                        <option value="">All status</option>
                        <option value="draft">Draft</option>
                        <option value="sent">Sent</option>
                        <option value="accepted">Accepted</option>
                        <option value="declined">Declined</option>
                        <option value="archived">Archived</option>
                    </select>

                    <select v-model="filterForm.customer_id"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:focus:ring-neutral-600">
                        <option value="">All customers</option>
                        <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                            {{ customer.company_name || `${customer.first_name || ''} ${customer.last_name || ''}`.trim() }}
                        </option>
                    </select>

                    <button type="button" @click="showAdvanced = !showAdvanced"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                        Filters
                    </button>
                    <button type="button" @click="clearFilters"
                        class="py-2 px-3 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                        Clear
                    </button>

                    <div class="flex items-center gap-2">
                        <select v-model="newQuoteCustomerId"
                            class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:focus:ring-neutral-600">
                            <option value="">New quote for...</option>
                            <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                                {{ customer.company_name || `${customer.first_name || ''} ${customer.last_name || ''}`.trim() }}
                            </option>
                        </select>
                        <button type="button" @click="startQuote" :disabled="!newQuoteCustomerId"
                            class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                            New quote
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="showAdvanced" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-2">
                <input type="number" step="0.01" v-model="filterForm.total_min"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    placeholder="Total min">
                <input type="number" step="0.01" v-model="filterForm.total_max"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    placeholder="Total max">
                <input type="date" v-model="filterForm.created_from"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    placeholder="Created from">
                <input type="date" v-model="filterForm.created_to"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    placeholder="Created to">
                <select v-model="filterForm.has_deposit"
                    class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:focus:ring-neutral-600">
                    <option value="">Deposit</option>
                    <option value="1">With deposit</option>
                    <option value="0">No deposit</option>
                </select>
                <select v-model="filterForm.has_tax"
                    class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:focus:ring-neutral-600">
                    <option value="">Tax</option>
                    <option value="1">With tax</option>
                    <option value="0">No tax</option>
                </select>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-start">
                            <button type="button" @click="toggleSort('number')"
                                class="text-sm font-medium text-stone-800 dark:text-neutral-200 flex items-center gap-x-1">
                                Quote
                            </button>
                        </th>
                        <th class="px-4 py-3 text-start">
                            <button type="button" @click="toggleSort('job_title')"
                                class="text-sm font-medium text-stone-800 dark:text-neutral-200 flex items-center gap-x-1">
                                Job
                            </button>
                        </th>
                        <th class="px-4 py-3 text-start">
                            <span class="text-sm font-medium text-stone-800 dark:text-neutral-200">Customer</span>
                        </th>
                        <th class="px-4 py-3 text-start">
                            <button type="button" @click="toggleSort('status')"
                                class="text-sm font-medium text-stone-800 dark:text-neutral-200 flex items-center gap-x-1">
                                Status
                            </button>
                        </th>
                        <th class="px-4 py-3 text-start">
                            <span class="text-sm font-medium text-stone-800 dark:text-neutral-200">Rating</span>
                        </th>
                        <th class="px-4 py-3 text-start">
                            <button type="button" @click="toggleSort('total')"
                                class="text-sm font-medium text-stone-800 dark:text-neutral-200 flex items-center gap-x-1">
                                Total
                            </button>
                        </th>
                        <th class="px-4 py-3 text-start">
                            <button type="button" @click="toggleSort('created_at')"
                                class="text-sm font-medium text-stone-800 dark:text-neutral-200 flex items-center gap-x-1">
                                Created
                            </button>
                        </th>
                        <th class="px-4 py-3 text-end"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                    <template v-if="isLoading">
                        <tr v-for="row in 6" :key="`skeleton-${row}`">
                            <td colspan="9" class="px-4 py-3">
                                <div class="grid grid-cols-6 gap-4 animate-pulse">
                                    <div class="h-3 w-32 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-28 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <template v-else>
                    <tr v-for="quote in quotes.data" :key="quote.id">
                        <td class="px-4 py-3">
                            <Link :href="route('customer.quote.show', quote)"
                                class="text-sm font-semibold text-stone-800 hover:underline dark:text-neutral-200">
                                {{ quote.number || 'Quote' }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 text-sm text-stone-600 dark:text-neutral-300">
                            {{ quote.job_title }}
                        </td>
                        <td class="px-4 py-3 text-sm text-stone-600 dark:text-neutral-300">
                            {{ displayCustomer(quote.customer) }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="py-1.5 px-2 inline-flex items-center text-xs font-medium rounded-full"
                                :class="statusClasses(displayStatus(quote))">
                                {{ displayStatus(quote) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <StarRating :value="quote.ratings_avg_rating" icon-class="h-3.5 w-3.5" empty-label="-" />
                        </td>
                        <td class="px-4 py-3 text-sm text-stone-600 dark:text-neutral-300">
                            {{ formatCurrency(quote.total) }}
                        </td>
                        <td class="px-4 py-3 text-sm text-stone-600 dark:text-neutral-300">
                            {{ formatDate(quote.created_at) }}
                        </td>
                        <td class="px-4 py-3 text-end">
                            <div
                                class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                                <button type="button"
                                    class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                    aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                        height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="1" />
                                        <circle cx="12" cy="5" r="1" />
                                        <circle cx="12" cy="19" r="1" />
                                    </svg>
                                </button>

                                <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-44 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                    role="menu" aria-orientation="vertical">
                                    <div class="p-1">
                                        <Link :href="route('customer.quote.show', quote)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                            View
                                        </Link>
                                        <Link v-if="!isArchived(quote) && quote.status !== 'accepted'"
                                            :href="route('customer.quote.edit', quote)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                            Edit
                                        </Link>
                                        <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                        <button v-if="!isArchived(quote)" type="button" @click="sendEmail(quote)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-neutral-800">
                                            Send email
                                        </button>
                                        <button v-if="!isArchived(quote) && quote.status !== 'accepted'" type="button" @click="convertToJob(quote)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800">
                                            Create job
                                        </button>
                                        <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                        <button v-if="!isArchived(quote)" type="button" @click="archiveQuote(quote)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800">
                                            Archive
                                        </button>
                                        <button v-else type="button" @click="restoreQuote(quote)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800">
                                            Restore
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="mt-5 flex flex-wrap justify-between items-center gap-2">
            <p class="text-sm text-stone-800 dark:text-neutral-200">
                <span class="font-medium">{{ count }}</span>
                <span class="text-stone-500 dark:text-neutral-500">results</span>
            </p>

            <nav class="flex justify-end items-center gap-x-1" aria-label="Pagination">
                <Link :href="quotes.prev_page_url" v-if="quotes.prev_page_url">
                    <button type="button"
                        class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                        aria-label="Previous">
                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="m15 18-6-6 6-6" />
                        </svg>
                        <span class="sr-only">Previous</span>
                    </button>
                </Link>
                <div class="flex items-center gap-x-1">
                    <span
                        class="min-h-[38px] min-w-[38px] flex justify-center items-center bg-stone-100 text-stone-800 py-2 px-3 text-sm rounded-sm disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:text-white"
                        aria-current="page">{{ quotes.from }}</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">of</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">{{
                            quotes.to }}</span>
                </div>

                <Link :href="quotes.next_page_url" v-if="quotes.next_page_url">
                    <button type="button"
                        class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                        aria-label="Next">
                        <span class="sr-only">Next</span>
                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="m9 18 6-6-6-6" />
                        </svg>
                    </button>
                </Link>
            </nav>
        </div>
    </div>
</template>

