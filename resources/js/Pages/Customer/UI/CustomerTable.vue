<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    filters: Object,
    customers: {
        type: Object,
        required: true,
    },
    count: {
        type: Number,
        required: true,
    },
});

const filterForm = useForm({
    name: props.filters?.name ?? '',
    city: props.filters?.city ?? '',
    country: props.filters?.country ?? '',
    has_quotes: props.filters?.has_quotes ?? '',
    has_works: props.filters?.has_works ?? '',
    created_from: props.filters?.created_from ?? '',
    created_to: props.filters?.created_to ?? '',
    sort: props.filters?.sort ?? 'created_at',
    direction: props.filters?.direction ?? 'desc',
});

const showAdvanced = ref(false);

const filterPayload = () => {
    const payload = {
        name: filterForm.name,
        city: filterForm.city,
        country: filterForm.country,
        has_quotes: filterForm.has_quotes,
        has_works: filterForm.has_works,
        created_from: filterForm.created_from,
        created_to: filterForm.created_to,
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
        router.get(route('customer.index'), filterPayload(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
};

watch(() => filterForm.name, () => {
    autoFilter();
});

watch(() => [
    filterForm.city,
    filterForm.country,
    filterForm.has_quotes,
    filterForm.has_works,
    filterForm.created_from,
    filterForm.created_to,
    filterForm.sort,
    filterForm.direction,
], () => {
    autoFilter();
});

const clearFilters = () => {
    filterForm.name = '';
    filterForm.city = '';
    filterForm.country = '';
    filterForm.has_quotes = '';
    filterForm.has_works = '';
    filterForm.created_from = '';
    filterForm.created_to = '';
    filterForm.sort = 'created_at';
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

const destroyCustomer = (customer) => {
    const label = customer.company_name || `${customer.first_name} ${customer.last_name}`;
    if (!confirm(`Delete "${label}"?`)) {
        return;
    }

    router.delete(route('customer.destroy', customer.id), {
        preserveScroll: true,
    });
};

const getPrimaryProperty = (customer) => {
    if (!customer.properties || !customer.properties.length) {
        return null;
    }
    return customer.properties.find((property) => property.is_default) || customer.properties[0];
};

const getCity = (customer) => {
    const property = getPrimaryProperty(customer);
    return property ? property.city : '';
};

const formatDate = (value) => humanizeDate(value);
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
                        <input type="text" v-model="filterForm.name"
                            class="py-[7px] ps-10 pe-8 block w-full bg-stone-100 border-transparent rounded-lg text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:border-transparent dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                            placeholder="Search name, company, email, or phone">
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 justify-end">
                    <button type="button" @click="showAdvanced = !showAdvanced"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-100 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700">
                        Filters
                    </button>
                    <button type="button" @click="clearFilters"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-100 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700">
                        Clear
                    </button>
                    <Link :href="route('customer.create')"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                        <svg class="hidden sm:block shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                            height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14" />
                            <path d="M12 5v14" />
                        </svg>
                        Add customer
                    </Link>
                </div>
            </div>

            <div v-if="showAdvanced" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-2">
                <input type="text" v-model="filterForm.city"
                    class="py-2 px-3 bg-stone-100 border-transparent rounded-lg text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200"
                    placeholder="City">
                <input type="text" v-model="filterForm.country"
                    class="py-2 px-3 bg-stone-100 border-transparent rounded-lg text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200"
                    placeholder="Country">
                <select v-model="filterForm.has_quotes"
                    class="py-2 ps-3 pe-8 bg-stone-100 border-transparent rounded-lg text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200">
                    <option value="">Quotes</option>
                    <option value="1">With quotes</option>
                    <option value="0">No quotes</option>
                </select>
                <select v-model="filterForm.has_works"
                    class="py-2 ps-3 pe-8 bg-stone-100 border-transparent rounded-lg text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200">
                    <option value="">Jobs</option>
                    <option value="1">With jobs</option>
                    <option value="0">No jobs</option>
                </select>
                <input type="date" v-model="filterForm.created_from"
                    class="py-2 px-3 bg-stone-100 border-transparent rounded-lg text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200"
                    placeholder="Created from">
                <input type="date" v-model="filterForm.created_to"
                    class="py-2 px-3 bg-stone-100 border-transparent rounded-lg text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200"
                    placeholder="Created to">
            </div>
        </div>

        <div
            class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
            <div class="min-w-full inline-block align-middle">
                <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                    <thead>
                        <tr>
                            <th scope="col" class="min-w-[240px]">
                                <button type="button" @click="toggleSort('company_name')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    Company
                                    <svg v-if="filterForm.sort === 'company_name'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="min-w-40">
                                <button type="button" @click="toggleSort('first_name')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    Contact
                                    <svg v-if="filterForm.sort === 'first_name'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="min-w-40">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Phone
                                </div>
                            </th>
                            <th scope="col" class="min-w-36">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    City
                                </div>
                            </th>
                            <th scope="col" class="min-w-28">
                                <button type="button" @click="toggleSort('quotes_count')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    Quotes
                                    <svg v-if="filterForm.sort === 'quotes_count'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="min-w-28">
                                <button type="button" @click="toggleSort('works_count')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    Jobs
                                    <svg v-if="filterForm.sort === 'works_count'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="min-w-32">
                                <button type="button" @click="toggleSort('created_at')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    Created
                                    <svg v-if="filterForm.sort === 'created_at'" class="size-3" xmlns="http://www.w3.org/2000/svg"
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
                        <tr v-for="customer in customers.data" :key="customer.id">
                            <td class="size-px whitespace-nowrap px-4 py-2 text-start">
                                <Link :href="route('customer.show', customer)">
                                    <div class="w-full flex items-center gap-x-3">
                                        <img class="shrink-0 size-10 rounded-md" :src="customer.logo_url || customer.logo"
                                            alt="Customer logo">
                                        <div class="flex flex-col">
                                            <span class="text-sm text-stone-600 dark:text-neutral-300">
                                                {{ customer.company_name || `${customer.first_name} ${customer.last_name}` }}
                                            </span>
                                            <span class="text-xs text-stone-500 dark:text-neutral-500">
                                                {{ customer.number }}
                                            </span>
                                        </div>
                                    </div>
                                </Link>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <div class="flex flex-col">
                                    <span class="text-sm text-stone-600 dark:text-neutral-300">
                                        {{ customer.first_name }} {{ customer.last_name }}
                                    </span>
                                    <span class="text-xs text-stone-500 dark:text-neutral-500">
                                        {{ customer.email }}
                                    </span>
                                </div>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ customer.phone || '-' }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ getCity(customer) || '-' }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span
                                    class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200">
                                    {{ customer.quotes_count ?? 0 }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span
                                    class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200">
                                    {{ customer.works_count ?? 0 }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ formatDate(customer.created_at) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-end">
                                <div class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
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

                                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-28 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                        role="menu" aria-orientation="vertical">
                                        <div class="p-1">
                                            <Link :href="route('customer.show', customer)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                View
                                            </Link>
                                            <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                            <button type="button" @click="destroyCustomer(customer)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800">
                                                Delete
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

        <div v-if="customers.data.length > 0" class="mt-5 flex flex-wrap justify-between items-center gap-2">
            <p class="text-sm text-stone-800 dark:text-neutral-200">
                <span class="font-medium"> {{ count }} </span>
                <span class="text-stone-500 dark:text-neutral-500"> results</span>
            </p>

            <nav class="flex justify-end items-center gap-x-1" aria-label="Pagination">
                <Link :href="customers.prev_page_url" v-if="customers.prev_page_url">
                <button type="button"
                    class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-lg text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                    aria-label="Previous">
                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="m15 18-6-6 6-6" />
                    </svg>
                    <span class="sr-only">Previous</span>
                </button>
                </Link>
                <div class="flex items-center gap-x-1">
                    <span
                        class="min-h-[38px] min-w-[38px] flex justify-center items-center bg-stone-100 text-stone-800 py-2 px-3 text-sm rounded-sm disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:text-white"
                        aria-current="page">{{ customers.from }}</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">of</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">{{
                            customers.to }}</span>
                </div>

                <Link :href="customers.next_page_url" v-if="customers.next_page_url">
                <button type="button"
                    class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                    aria-label="Next">
                    <span class="sr-only">Next</span>
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
