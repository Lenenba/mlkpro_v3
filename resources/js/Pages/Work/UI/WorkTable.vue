<script setup>
import { ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import StarRating from '@/Components/UI/StarRating.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    works: {
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
});

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
    customer_id: props.filters?.customer_id ?? '',
    start_from: props.filters?.start_from ?? '',
    start_to: props.filters?.start_to ?? '',
    sort: props.filters?.sort ?? 'start_date',
    direction: props.filters?.direction ?? 'desc',
});

const showAdvanced = ref(false);

const statusOptions = [
    { value: '', label: 'Tous les statuts' },
    { value: 'to_schedule', label: 'A planifier' },
    { value: 'scheduled', label: 'Planifie' },
    { value: 'en_route', label: 'En route' },
    { value: 'in_progress', label: 'En cours' },
    { value: 'tech_complete', label: 'Tech termine' },
    { value: 'pending_review', label: 'En attente de validation' },
    { value: 'validated', label: 'Valide' },
    { value: 'auto_validated', label: 'Auto valide' },
    { value: 'dispute', label: 'Litige' },
    { value: 'closed', label: 'Cloture' },
    { value: 'cancelled', label: 'Annule' },
    { value: 'completed', label: 'Termine (ancien)' },
];

const statusLabels = {
    to_schedule: 'A planifier',
    scheduled: 'Planifie',
    en_route: 'En route',
    in_progress: 'En cours',
    tech_complete: 'Tech termine',
    pending_review: 'En attente de validation',
    validated: 'Valide',
    auto_validated: 'Auto valide',
    dispute: 'Litige',
    closed: 'Cloture',
    cancelled: 'Annule',
    completed: 'Termine (ancien)',
};

const formatStatus = (status) => statusLabels[status] || status || 'Planifie';

const filterPayload = () => {
    const payload = {
        search: filterForm.search,
        status: filterForm.status,
        customer_id: filterForm.customer_id,
        start_from: filterForm.start_from,
        start_to: filterForm.start_to,
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
        router.get(route('work.index'), filterPayload(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
};

watch(() => filterForm.search, () => {
    autoFilter();
});

watch(() => [
    filterForm.status,
    filterForm.customer_id,
    filterForm.start_from,
    filterForm.start_to,
    filterForm.sort,
    filterForm.direction,
], () => {
    autoFilter();
});

const clearFilters = () => {
    filterForm.search = '';
    filterForm.status = '';
    filterForm.customer_id = '';
    filterForm.start_from = '';
    filterForm.start_to = '';
    filterForm.sort = 'start_date';
    filterForm.direction = 'desc';
    autoFilter();
};

const toggleSort = (column) => {
    if (filterForm.sort === column) {
        filterForm.direction = filterForm.direction === 'asc' ? 'desc' : 'asc';
        return;
    }
    filterForm.sort = column;
    filterForm.direction = 'asc';
};

const formatDate = (value) => humanizeDate(value);

const getCustomerName = (work) => {
    const customer = work.customer;
    if (!customer) {
        return '-';
    }
    return customer.company_name || `${customer.first_name} ${customer.last_name}`;
};

const createInvoice = (work) => {
    router.post(route('invoice.store-from-work', work.id), {}, {
        preserveScroll: true,
    });
};
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
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
                            class="py-[7px] ps-10 pe-8 block w-full bg-stone-100 border-transparent rounded-lg text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:border-transparent dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                            placeholder="Rechercher des jobs, instructions ou types">
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 justify-end">
                    <button type="button" @click="showAdvanced = !showAdvanced"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-100 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700">
                        Filtres
                    </button>
                    <button type="button" @click="clearFilters"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-100 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700">
                        Reinitialiser
                    </button>
                </div>
            </div>

            <div v-if="showAdvanced" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-2">
                <select v-model="filterForm.status"
                    class="py-2 ps-3 pe-8 bg-stone-100 border-transparent rounded-lg text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200">
                    <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
                <select v-model="filterForm.customer_id"
                    class="py-2 ps-3 pe-8 bg-stone-100 border-transparent rounded-lg text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200">
                    <option value="">Tous les clients</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                        {{ customer.company_name || `${customer.first_name} ${customer.last_name}` }}
                    </option>
                </select>
                <input type="date" v-model="filterForm.start_from"
                    class="py-2 px-3 bg-stone-100 border-transparent rounded-lg text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200"
                    placeholder="A partir du">
                <input type="date" v-model="filterForm.start_to"
                    class="py-2 px-3 bg-stone-100 border-transparent rounded-lg text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200"
                    placeholder="Jusqua">
            </div>
        </div>

        <div
            class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
            <div class="min-w-full inline-block align-middle">
                <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                    <thead>
                        <tr>
                            <th scope="col" class="min-w-[240px]">
                                <button type="button" @click="toggleSort('job_title')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    Job
                                    <svg v-if="filterForm.sort === 'job_title'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="min-w-40">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Client
                                </div>
                            </th>
                            <th scope="col" class="min-w-32">
                                <button type="button" @click="toggleSort('status')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    Statut
                                    <svg v-if="filterForm.sort === 'status'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="min-w-32">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Note
                                </div>
                            </th>
                            <th scope="col" class="min-w-32">
                                <button type="button" @click="toggleSort('total')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    Total
                                    <svg v-if="filterForm.sort === 'total'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="min-w-32">
                                <button type="button" @click="toggleSort('start_date')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    Date de debut
                                    <svg v-if="filterForm.sort === 'start_date'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col"></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                        <tr v-for="work in works.data" :key="work.id">
                            <td class="size-px whitespace-nowrap px-4 py-2 text-start">
                                <Link :href="route('work.show', work.id)" class="flex flex-col hover:underline">
                                    <span class="text-sm text-stone-600 dark:text-neutral-300">
                                        {{ work.job_title }}
                                    </span>
                                    <span class="text-xs text-stone-500 dark:text-neutral-500">
                                        {{ work.number }}
                                    </span>
                                </Link>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ getCustomerName(work) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span
                                    class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-full"
                                    :class="{
                                        'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200': ['to_schedule', 'scheduled'].includes(work.status || 'scheduled'),
                                        'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200': ['en_route', 'in_progress'].includes(work.status),
                                        'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/20 dark:text-indigo-200': ['tech_complete', 'pending_review'].includes(work.status),
                                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200': ['validated', 'auto_validated', 'closed', 'completed'].includes(work.status),
                                        'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200': work.status === 'dispute',
                                        'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300': work.status === 'cancelled',
                                    }">
                                    {{ formatStatus(work.status) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <StarRating :value="work.ratings_avg_rating" icon-class="h-3.5 w-3.5" empty-label="-" />
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                    ${{ Number(work.total || 0).toFixed(2) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ formatDate(work.start_date) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-end">
                                <div
                                    class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                                    <button type="button"
                                        class="size-7 inline-flex justify-center items-center gap-x-2 rounded-lg border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                        aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                            height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="1" />
                                            <circle cx="12" cy="5" r="1" />
                                            <circle cx="12" cy="19" r="1" />
                                        </svg>
                                    </button>

                                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                        role="menu" aria-orientation="vertical">
                                        <div class="p-1">
                                            <Link :href="route('work.show', work.id)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                Voir
                                            </Link>
                                            <Link :href="route('work.edit', work.id)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                Modifier
                                            </Link>
                                            <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                            <Link v-if="work.invoice" :href="route('invoice.show', work.invoice.id)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                Voir la facture
                                            </Link>
                                            <button v-else type="button" @click="createInvoice(work)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800">
                                                Creer une facture
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="works.data.length > 0" class="mt-5 flex flex-wrap justify-between items-center gap-2">
            <p class="text-sm text-stone-800 dark:text-neutral-200">
                <span class="font-medium"> {{ works.total ?? works.data.length }} </span>
                <span class="text-stone-500 dark:text-neutral-500"> resultats</span>
            </p>

            <nav class="flex justify-end items-center gap-x-1" aria-label="Pagination">
                <Link :href="works.prev_page_url" v-if="works.prev_page_url">
                <button type="button"
                    class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-lg text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                    aria-label="Precedent">
                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="m15 18-6-6 6-6" />
                    </svg>
                    <span class="sr-only">Precedent</span>
                </button>
                </Link>
                <div class="flex items-center gap-x-1">
                    <span
                        class="min-h-[38px] min-w-[38px] flex justify-center items-center bg-stone-100 text-stone-800 py-2 px-3 text-sm rounded-sm disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:text-white"
                        aria-current="page">{{ works.from }}</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">sur</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">{{ works.to }}</span>
                </div>

                <Link :href="works.next_page_url" v-if="works.next_page_url">
                <button type="button"
                    class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                    aria-label="Suivant">
                    <span class="sr-only">Suivant</span>
                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="m9 18 6-6-6-6" />
                    </svg>
                </button>
                </Link>
            </nav>
        </div>
    </div>
</template>
