<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import Modal from '@/Components/UI/Modal.vue';
import ServiceForm from '@/Pages/Service/UI/ServiceForm.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { humanizeDate } from '@/utils/date';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import { useI18n } from 'vue-i18n';
import { useCurrencyFormatter } from '@/utils/currency';

const props = defineProps({
    filters: Object,
    services: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        required: true,
    },
    materialProducts: {
        type: Array,
        default: () => [],
    },
    count: {
        type: Number,
        required: true,
    },
    tenantCurrencyCode: {
        type: String,
        default: 'CAD',
    },
    pulse: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
const canOpenPulseComposer = computed(() => Boolean(props.pulse?.can_open));

const filterForm = useForm({
    name: props.filters?.name ?? '',
    category_id: props.filters?.category_id ?? '',
    status: props.filters?.status ?? '',
    price_min: props.filters?.price_min ?? '',
    price_max: props.filters?.price_max ?? '',
    created_from: props.filters?.created_from ?? '',
    created_to: props.filters?.created_to ?? '',
    sort: props.filters?.sort ?? 'created_at',
    direction: props.filters?.direction ?? 'desc',
});

const showAdvanced = ref(false);
const isLoading = ref(false);
const categoryOptions = computed(() =>
    (props.categories || []).map((category) => ({
        value: String(category.id),
        label: category.name,
    }))
);
const statusOptions = computed(() => ([
    { value: 'active', label: t('services.status.active') },
    { value: 'archived', label: t('services.status.archived') },
]));

const filterPayload = () => {
    const payload = {
        name: filterForm.name,
        category_id: filterForm.category_id,
        status: filterForm.status,
        price_min: filterForm.price_min,
        price_max: filterForm.price_max,
        created_from: filterForm.created_from,
        created_to: filterForm.created_to,
        sort: filterForm.sort,
        direction: filterForm.direction,
        per_page: currentPerPage.value,
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
        router.get(route('service.index'), filterPayload(), {
            only: ['services', 'filters', 'stats', 'count'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                isLoading.value = false;
            },
        });
    }, 300);
};

watch(() => [
    filterForm.name,
    filterForm.category_id,
    filterForm.status,
    filterForm.price_min,
    filterForm.price_max,
    filterForm.created_from,
    filterForm.created_to,
    filterForm.sort,
    filterForm.direction,
], () => {
    autoFilter();
});

const clearFilters = () => {
    filterForm.name = '';
    filterForm.category_id = '';
    filterForm.status = '';
    filterForm.price_min = '';
    filterForm.price_max = '';
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

const { formatCurrency } = useCurrencyFormatter();

const formatDate = (value) => humanizeDate(value);

const editingService = ref(null);
const activeCategories = computed(() =>
    (props.categories || []).filter((category) => !category.archived_at)
);
const selectableCategories = computed(() => {
    const base = activeCategories.value;
    const currentId = editingService.value?.category_id;
    if (!currentId) {
        return base;
    }
    if (base.some((category) => category.id === currentId)) {
        return base;
    }
    const current = (props.categories || []).find((category) => category.id === currentId);
    return current ? [...base, current] : base;
});
const serviceRows = computed(() => (Array.isArray(props.services?.data) ? props.services.data : []));
const serviceTableRows = computed(() => (isLoading.value
    ? Array.from({ length: 6 }, (_, index) => ({ id: `service-skeleton-${index}`, __skeleton: true }))
    : serviceRows.value));
const serviceLinks = computed(() => props.services?.links || []);
const currentPerPage = computed(() => resolveDataTablePerPage(props.services?.per_page, props.filters?.per_page));
const serviceResultsLabel = computed(() => `${props.count} ${t('services.pagination.results')}`);

const openCreate = () => {
    editingService.value = null;
};

const openEdit = (service) => {
    editingService.value = service;
    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-service-upsert');
    }
};

const destroyService = (service) => {
    if (!confirm(t('services.actions.delete_confirm', { name: service.name }))) {
        return;
    }

    router.delete(route('service.destroy', service.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-emerald-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
        <AdminDataTableToolbar
            :show-filters="showAdvanced"
            :show-apply="false"
            :busy="isLoading"
            :filters-label="$t('services.actions.filters')"
            :clear-label="$t('services.actions.clear')"
            @toggle-filters="showAdvanced = !showAdvanced"
            @apply="autoFilter"
            @clear="clearFilters"
        >
            <template #search>
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                        <svg class="shrink-0 size-4 text-stone-500 dark:text-neutral-400"
                            xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.3-4.3" />
                        </svg>
                    </div>
                    <input type="text" v-model="filterForm.name" data-testid="demo-service-search"
                        class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400"
                        :placeholder="$t('services.filters.search_placeholder')">
                </div>
            </template>

            <template #filters>
                <FloatingSelect
                    v-model="filterForm.category_id"
                    :label="$t('services.filters.category')"
                    :options="categoryOptions"
                    :placeholder="$t('services.filters.category')"
                    dense
                />
                <FloatingSelect
                    v-model="filterForm.status"
                    :label="$t('services.filters.status')"
                    :options="statusOptions"
                    :placeholder="$t('services.filters.status')"
                    dense
                />
                <input type="number" step="0.01" v-model="filterForm.price_min"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('services.filters.price_min')">
                <input type="number" step="0.01" v-model="filterForm.price_max"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('services.filters.price_max')">
                <DatePicker v-model="filterForm.created_from" :label="$t('services.filters.created_from')" />
                <DatePicker v-model="filterForm.created_to" :label="$t('services.filters.created_to')" />
            </template>

            <template #actions>
                <button type="button" data-hs-overlay="#hs-service-upsert" @click="openCreate"
                    class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                    <svg class="hidden sm:block shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                        height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14" />
                        <path d="M12 5v14" />
                    </svg>
                    {{ $t('services.actions.add_service') }}
                </button>
            </template>
        </AdminDataTableToolbar>

        <AdminDataTable
            embedded
            :rows="serviceTableRows"
            :links="serviceLinks"
            :show-pagination="serviceRows.length > 0"
            show-per-page
            :per-page="currentPerPage"
        >
            <template #empty>
                <div class="px-5 py-10 text-center text-sm text-stone-500 dark:text-neutral-500">
                    {{ $t('services.empty') }}
                </div>
            </template>

            <template #head>
                <tr>
                    <th scope="col" class="min-w-[260px]">
                        <button type="button" @click="toggleSort('name')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('services.table.service') }}
                            <svg v-if="filterForm.sort === 'name'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-[140px]">
                        <button type="button" @click="toggleSort('price')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('services.table.price') }}
                            <svg v-if="filterForm.sort === 'price'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-[180px]">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('services.table.category') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-[120px]">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('services.table.status') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-[120px]">
                        <button type="button" @click="toggleSort('created_at')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('services.table.created') }}
                            <svg v-if="filterForm.sort === 'created_at'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-[60px]"></th>
                </tr>
            </template>

            <template #body="{ rows }">
                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                    <tr v-for="service in rows" :key="service.id">
                        <template v-if="service.__skeleton">
                            <td colspan="6" class="px-4 py-3">
                                <div class="grid grid-cols-5 gap-4 animate-pulse">
                                    <div class="h-3 w-32 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                </div>
                            </td>
                        </template>
                        <template v-else>
                            <td class="size-px whitespace-nowrap px-5 py-2">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-sm border border-stone-200 bg-stone-100 text-sm font-semibold text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                        <img
                                            v-if="service.image_url"
                                            :src="service.image_url"
                                            :alt="service.name"
                                            class="h-full w-full object-cover"
                                        >
                                        <span v-else>{{ service.name?.charAt(0) || '?' }}</span>
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-sm text-stone-700 dark:text-neutral-200">{{ service.name }}</span>
                                        <span v-if="service.unit" class="text-xs text-stone-500 dark:text-neutral-500">{{ service.unit }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="size-px whitespace-nowrap px-5 py-2">
                                <span class="text-sm text-stone-700 dark:text-neutral-200">{{ formatCurrency(service.price) }}</span>
                            </td>
                            <td class="size-px whitespace-nowrap px-5 py-2">
                                <span class="text-sm text-stone-600 dark:text-neutral-400">
                                    {{ service.category?.name || $t('services.labels.uncategorized') }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-5 py-2">
                                <span v-if="service.is_active"
                                    class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-emerald-100 text-emerald-800 rounded-full dark:bg-emerald-500/10 dark:text-emerald-400">
                                    {{ $t('services.status.active') }}
                                </span>
                                <span v-else
                                    class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-stone-200 text-stone-700 rounded-full dark:bg-neutral-700 dark:text-neutral-300">
                                    {{ $t('services.status.archived') }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-5 py-2">
                                <span class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ formatDate(service.created_at) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-5 py-2 text-end">
                                <AdminDataTableActions :label="$t('services.aria.dropdown')" menu-width-class="w-56">
                                    <button type="button" @click="openEdit(service)"
                                        class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-start text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                        {{ $t('services.actions.edit') }}
                                    </button>
                                    <Link
                                        v-if="canOpenPulseComposer"
                                        :href="route('social.composer', { source_type: 'service', source_id: service.id })"
                                        class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-start text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                    >
                                        {{ $t('social.composer_manager.actions.publish_with_pulse') }}
                                    </Link>
                                    <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                    <button type="button" @click="destroyService(service)"
                                        class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-start text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800">
                                        {{ $t('services.actions.delete') }}
                                    </button>
                                </AdminDataTableActions>
                            </td>
                        </template>
                    </tr>
                </tbody>
            </template>

            <template #pagination_prefix>
                <p class="text-sm text-stone-800 dark:text-neutral-200">
                    {{ serviceResultsLabel }}
                </p>
            </template>
        </AdminDataTable>

        <Modal :title="editingService ? $t('services.actions.edit_service') : $t('services.actions.new_service')" :id="'hs-service-upsert'">
            <ServiceForm
                :key="editingService?.id || 'new'"
                :id="'hs-service-upsert'"
                :categories="selectableCategories"
                :materialProducts="materialProducts"
                :service="editingService"
                :tenant-currency-code="tenantCurrencyCode"
                @submitted="editingService = null"
            />
        </Modal>
    </div>
</template>
