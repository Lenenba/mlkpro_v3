<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { humanizeDate } from '@/utils/date';
import { crmButtonClass } from '@/utils/crmButtonStyles';

const props = defineProps({
    filters: {
        type: Object,
        default: () => ({}),
    },
    offers: {
        type: Object,
        required: true,
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
    catalogItems: {
        type: Array,
        default: () => [],
    },
    options: {
        type: Object,
        default: () => ({}),
    },
    tenantCurrencyCode: {
        type: String,
        default: 'CAD',
    },
});

const editingOfferId = ref(null);
const editorOpen = ref(false);
const showAdvancedFilters = ref(false);
const isFiltering = ref(false);

const filterForm = reactive({
    search: props.filters.search || '',
    type: props.filters.type || '',
    status: props.filters.status || '',
    is_public: props.filters.is_public ?? '',
    sort: props.filters.sort || 'created_at',
    direction: props.filters.direction || 'desc',
});

const defaultItem = () => ({
    product_id: props.catalogItems[0]?.id || '',
    quantity: 1,
    unit_price: props.catalogItems[0]?.price || 0,
    is_optional: false,
});

const form = useForm({
    name: '',
    type: 'pack',
    status: 'draft',
    description: '',
    image_path: '',
    price: 0,
    currency_code: props.tenantCurrencyCode,
    validity_days: '',
    included_quantity: 1,
    unit_type: 'session',
    is_public: false,
    is_recurring: false,
    recurrence_frequency: 'monthly',
    renewal_notice_days: 7,
    items: [defaultItem()],
});

const isEditing = computed(() => editingOfferId.value !== null);
const pageOffers = computed(() => props.offers?.data || []);
const currentPerPage = computed(() => resolveDataTablePerPage(props.offers?.per_page, props.filters?.per_page));
const offerTableRows = computed(() => (isFiltering.value
    ? Array.from({ length: 5 }, (_, index) => ({ id: `offer-package-skeleton-${index}`, __skeleton: true }))
    : pageOffers.value));
const offerLinks = computed(() => (Array.isArray(props.offers?.links) ? props.offers.links : []));
const resultLabel = computed(() => {
    const total = Number(props.offers?.total || 0);
    const page = Number(props.offers?.current_page || 1);

    return `Page ${page} - ${total} offre${total > 1 ? 's' : ''}`;
});

const typeLabels = {
    pack: 'Pack',
    forfait: 'Forfait',
};

const statusLabels = {
    draft: 'Brouillon',
    active: 'Actif',
    archived: 'Archive',
};

const unitLabels = {
    session: 'Seance',
    hour: 'Heure',
    visit: 'Visite',
    credit: 'Credit',
    month: 'Mois',
};

const recurrenceLabels = {
    monthly: 'Mensuel',
    quarterly: 'Trimestriel',
    yearly: 'Annuel',
};

const statLabels = {
    total: 'Total',
    active: 'Actifs',
    packs: 'Packs',
    forfaits: 'Forfaits',
    public: 'Publics',
};

const typeOptions = computed(() => [
    { value: 'pack', label: typeLabel('pack') },
    { value: 'forfait', label: typeLabel('forfait') },
]);
const typeFilterOptions = computed(() => [
    { value: '', label: 'Tous les types' },
    ...typeOptions.value,
]);
const statusOptions = computed(() => (props.options.statuses || ['draft', 'active', 'archived']).map((status) => ({
    value: status,
    label: statusLabel(status),
})));
const statusFilterOptions = computed(() => [
    { value: '', label: 'Tous les statuts' },
    ...statusOptions.value,
]);
const publicFilterOptions = computed(() => [
    { value: '', label: 'Toute visibilite' },
    { value: '1', label: 'Public' },
    { value: '0', label: 'Interne' },
]);
const unitTypeOptions = computed(() => (props.options.unit_types || ['session', 'hour', 'visit', 'credit', 'month']).map((unitType) => ({
    value: unitType,
    label: unitTypeLabel(unitType),
})));
const recurrenceOptions = computed(() => (props.options.recurrence_frequencies || ['monthly', 'quarterly', 'yearly']).map((frequency) => ({
    value: frequency,
    label: recurrenceLabel(frequency),
})));
const currencyOptions = computed(() => (props.options.currencies || [props.tenantCurrencyCode]).map((currency) => ({
    value: currency,
    label: currency,
})));
const catalogItemOptions = computed(() => props.catalogItems.map((item) => ({
    value: item.id,
    label: `${item.name} - ${item.item_type}`,
    search: [item.name, item.item_type, item.unit].filter(Boolean).join(' '),
})));

function typeLabel(value) {
    return typeLabels[value] || String(value || '-');
}

function statusLabel(value) {
    return statusLabels[value] || String(value || '-');
}

function unitTypeLabel(value) {
    return unitLabels[value] || String(value || '-');
}

function recurrenceLabel(value) {
    return recurrenceLabels[value] || String(value || '-');
}

const money = (value, currency = props.tenantCurrencyCode) => {
    const amount = Number(value || 0);

    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency || props.tenantCurrencyCode,
    }).format(amount);
};

const formatDate = (value) => humanizeDate(value) || '-';

const statusBadgeClass = (status) => {
    if (status === 'active') {
        return 'bg-green-100 text-green-800 dark:bg-green-500/10 dark:text-green-300';
    }
    if (status === 'archived') {
        return 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
    }

    return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
};

const catalogItem = (id) => props.catalogItems.find((item) => Number(item.id) === Number(id));

const itemSummary = (offer) => {
    const items = Array.isArray(offer?.items) ? offer.items : [];
    if (!items.length) {
        return 'Aucun element';
    }

    return items
        .slice(0, 2)
        .map((item) => item.name_snapshot || item.product?.name || 'Element')
        .join(', ')
        + (items.length > 2 ? ` +${items.length - 2}` : '');
};

const packageMeta = (offer) => {
    if (offer?.type === 'forfait') {
        return `${Number(offer.included_quantity || 0)} ${unitTypeLabel(offer.unit_type)}`;
    }

    const count = Number(offer?.items_count ?? offer?.items?.length ?? 0);

    return `${count} element${count > 1 ? 's' : ''}`;
};

const itemError = (index, field) => form.errors[`items.${index}.${field}`];

const resetForm = () => {
    editingOfferId.value = null;
    form.reset();
    form.clearErrors();
    form.currency_code = props.tenantCurrencyCode;
    form.items = [defaultItem()];
};

const openCreate = () => {
    resetForm();
    editorOpen.value = true;
};

const closeEditor = () => {
    editorOpen.value = false;
    resetForm();
};

const startEdit = (offer) => {
    editingOfferId.value = offer.id;
    form.clearErrors();
    form.name = offer.name || '';
    form.type = offer.type || 'pack';
    form.status = offer.status || 'draft';
    form.description = offer.description || '';
    form.image_path = offer.image_path || '';
    form.price = Number(offer.price || 0);
    form.currency_code = offer.currency_code || props.tenantCurrencyCode;
    form.validity_days = offer.validity_days || '';
    form.included_quantity = offer.included_quantity || 1;
    form.unit_type = offer.unit_type || 'session';
    form.is_public = Boolean(offer.is_public);
    form.is_recurring = Boolean(offer.is_recurring);
    form.recurrence_frequency = offer.recurrence_frequency || 'monthly';
    form.renewal_notice_days = offer.renewal_notice_days || 7;
    form.items = (offer.items || []).length
        ? offer.items.map((item) => ({
            product_id: item.product_id,
            quantity: Number(item.quantity || 1),
            unit_price: Number(item.unit_price || 0),
            sort_order: item.sort_order || 0,
            is_optional: false,
        }))
        : [defaultItem()];
    editorOpen.value = true;
};

const addItem = () => {
    form.items.push({
        ...defaultItem(),
        sort_order: form.items.length,
    });
};

const removeItem = (index) => {
    if (form.items.length === 1) {
        return;
    }

    form.items.splice(index, 1);
};

const updateItemPrice = (item) => {
    const selected = catalogItem(item.product_id);
    if (selected) {
        item.unit_price = Number(selected.price || 0);
    }
};

const submit = () => {
    if (form.type !== 'forfait' || !form.is_recurring) {
        form.is_recurring = false;
        form.recurrence_frequency = '';
        form.renewal_notice_days = '';
    } else {
        form.recurrence_frequency = form.recurrence_frequency || 'monthly';
        form.renewal_notice_days = form.renewal_notice_days || 7;
    }

    const payload = {
        preserveScroll: true,
        onSuccess: () => closeEditor(),
        onError: () => {
            editorOpen.value = true;
        },
    };

    if (isEditing.value) {
        form.put(route('offer-packages.update', editingOfferId.value), payload);

        return;
    }

    form.post(route('offer-packages.store'), payload);
};

const filterPayload = () => {
    const payload = {
        search: filterForm.search,
        type: filterForm.type,
        status: filterForm.status,
        is_public: filterForm.is_public,
        sort: filterForm.sort,
        direction: filterForm.direction,
        per_page: currentPerPage.value,
    };

    Object.keys(payload).forEach((key) => {
        if (payload[key] === '' || payload[key] === null || payload[key] === undefined) {
            delete payload[key];
        }
    });

    return payload;
};

const applyFilters = () => {
    isFiltering.value = true;
    router.get(route('offer-packages.index'), filterPayload(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
            isFiltering.value = false;
        },
    });
};

const clearFilters = () => {
    filterForm.search = '';
    filterForm.type = '';
    filterForm.status = '';
    filterForm.is_public = '';
    filterForm.sort = 'created_at';
    filterForm.direction = 'desc';
    applyFilters();
};

const toggleSort = (column) => {
    if (filterForm.sort === column) {
        filterForm.direction = filterForm.direction === 'asc' ? 'desc' : 'asc';
    } else {
        filterForm.sort = column;
        filterForm.direction = 'asc';
    }

    applyFilters();
};

const duplicateOffer = (offer) => {
    router.post(route('offer-packages.duplicate', offer.id), {}, {
        preserveScroll: true,
    });
};

const archiveOffer = (offer) => {
    router.delete(route('offer-packages.destroy', offer.id), {
        preserveScroll: true,
    });
};

const restoreOffer = (offer) => {
    router.post(route('offer-packages.restore', offer.id), {}, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Packs et forfaits" />

    <AuthenticatedLayout>
        <div class="space-y-4">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-green-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="inline-flex items-center gap-2 text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            <span class="inline-flex size-9 items-center justify-center rounded-sm bg-green-100 text-green-700 dark:bg-green-500/10 dark:text-green-300">
                                <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z" />
                                    <path d="m3.3 7 8.7 5 8.7-5" />
                                    <path d="M12 22V12" />
                                </svg>
                            </span>
                            <span>Packs et forfaits</span>
                        </h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            Offres commerciales, forfaits prepayes et packs vendables.
                        </p>
                    </div>

                    <button
                        type="button"
                        :class="crmButtonClass('primary', 'dialog')"
                        :disabled="!catalogItems.length"
                        @click="openCreate"
                    >
                        <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14" />
                            <path d="M12 5v14" />
                        </svg>
                        <span>Nouvelle offre</span>
                    </button>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                    <div
                        v-for="(value, key) in stats"
                        :key="key"
                        class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800"
                    >
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ statLabels[key] || key }}
                        </div>
                        <div class="mt-1 text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ value }}
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <AdminDataTableToolbar
                    :show-filters="showAdvancedFilters"
                    :busy="isFiltering"
                    filters-label="Filtres"
                    clear-label="Reinitialiser"
                    apply-label="Filtrer"
                    @toggle-filters="showAdvancedFilters = !showAdvancedFilters"
                    @apply="applyFilters"
                    @clear="clearFilters"
                >
                    <template #search>
                        <FloatingInput
                            v-model="filterForm.search"
                            label="Rechercher"
                            type="search"
                            @keyup.enter="applyFilters"
                        />
                    </template>

                    <template #filters>
                        <FloatingSelect
                            v-model="filterForm.type"
                            label="Type"
                            :options="typeFilterOptions"
                            option-value="value"
                            option-label="label"
                        />
                        <FloatingSelect
                            v-model="filterForm.status"
                            label="Statut"
                            :options="statusFilterOptions"
                            option-value="value"
                            option-label="label"
                        />
                        <FloatingSelect
                            v-model="filterForm.is_public"
                            label="Visibilite"
                            :options="publicFilterOptions"
                            option-value="value"
                            option-label="label"
                        />
                    </template>
                </AdminDataTableToolbar>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <AdminDataTable
                    embedded
                    :rows="offerTableRows"
                    :links="offerLinks"
                    :show-pagination="pageOffers.length > 0"
                    show-per-page
                    :per-page="currentPerPage"
                    :result-label="resultLabel"
                >
                    <template #head>
                        <tr class="border-t border-stone-200 dark:border-neutral-700">
                            <th scope="col" class="min-w-[260px]">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-x-1 px-5 py-2.5 text-start text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300"
                                    @click="toggleSort('name')"
                                >
                                    Offre
                                    <svg v-if="filterForm.sort === 'name'" class="size-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="min-w-28 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                Type
                            </th>
                            <th scope="col" class="min-w-36">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-x-1 px-5 py-2.5 text-start text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300"
                                    @click="toggleSort('price')"
                                >
                                    Prix
                                    <svg v-if="filterForm.sort === 'price'" class="size-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="min-w-48 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                Contenu
                            </th>
                            <th scope="col" class="min-w-36 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                Statut
                            </th>
                            <th scope="col" class="min-w-36">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-x-1 px-5 py-2.5 text-start text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300"
                                    @click="toggleSort('created_at')"
                                >
                                    Cree le
                                    <svg v-if="filterForm.sort === 'created_at'" class="size-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="min-w-48 px-5 py-2.5 text-right text-sm font-normal text-stone-500 dark:text-neutral-500">
                                Actions
                            </th>
                        </tr>
                    </template>

                    <template #row="{ row: offer }">
                        <tr class="text-stone-700 dark:text-neutral-200">
                            <template v-if="offer.__skeleton">
                                <td v-for="column in 7" :key="`${offer.id}-${column}`" class="px-5 py-3">
                                    <div class="h-3 w-full animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                </td>
                            </template>
                            <template v-else>
                                <td class="px-5 py-3">
                                    <Link
                                        :href="route('offer-packages.show', offer.id)"
                                        class="font-semibold text-stone-800 transition hover:text-green-700 dark:text-neutral-100 dark:hover:text-green-300"
                                    >
                                        {{ offer.name }}
                                    </Link>
                                    <div class="mt-1 max-w-xs truncate text-xs text-stone-500 dark:text-neutral-400">
                                        {{ offer.description || 'Aucune description' }}
                                    </div>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex rounded-full bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-700 dark:bg-neutral-700 dark:text-neutral-300">
                                        {{ typeLabel(offer.type) }}
                                    </span>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                                        {{ money(offer.price, offer.currency_code) }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ offer.currency_code || tenantCurrencyCode }}
                                    </div>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                                        {{ packageMeta(offer) }}
                                    </div>
                                    <div class="mt-1 max-w-xs truncate text-xs text-stone-500 dark:text-neutral-400">
                                        {{ itemSummary(offer) }}
                                    </div>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex flex-wrap gap-1.5">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusBadgeClass(offer.status)">
                                            {{ statusLabel(offer.status) }}
                                        </span>
                                        <span v-if="offer.is_public" class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800 dark:bg-green-500/10 dark:text-green-300">
                                            Public
                                        </span>
                                        <span v-if="offer.is_recurring" class="inline-flex rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-800 dark:bg-sky-500/10 dark:text-sky-300">
                                            {{ recurrenceLabel(offer.recurrence_frequency) }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-sm text-stone-600 dark:text-neutral-300">
                                    {{ formatDate(offer.created_at) }}
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex justify-end">
                                        <AdminDataTableActions label="Actions" menu-width-class="w-44">
                                        <Link
                                            :href="route('offer-packages.show', offer.id)"
                                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                        >
                                            Voir
                                        </Link>
                                        <button
                                            type="button"
                                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-start text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                            @click="startEdit(offer)"
                                        >
                                            Modifier
                                        </button>
                                        <button
                                            type="button"
                                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-start text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                            @click="duplicateOffer(offer)"
                                        >
                                            Dupliquer
                                        </button>
                                        <template v-if="offer.status === 'archived'">
                                            <button
                                                type="button"
                                                class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-start text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                                @click="restoreOffer(offer)"
                                            >
                                                Reactiver
                                            </button>
                                        </template>
                                        <template v-else>
                                            <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                            <button
                                                type="button"
                                                class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-start text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800"
                                                @click="archiveOffer(offer)"
                                            >
                                                Archiver
                                            </button>
                                        </template>
                                        </AdminDataTableActions>
                                    </div>
                                </td>
                            </template>
                        </tr>
                    </template>

                    <template #empty>
                        <div class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-10 text-center text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                            <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
                                Aucune offre trouvee.
                            </div>
                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                Ajustez les filtres ou creez une nouvelle offre.
                            </div>
                            <button
                                v-if="catalogItems.length"
                                type="button"
                                :class="[crmButtonClass('primary', 'compact'), 'mt-3']"
                                @click="openCreate"
                            >
                                Nouvelle offre
                            </button>
                        </div>
                    </template>
                </AdminDataTable>
            </section>
        </div>

        <Modal :show="editorOpen" max-width="5xl" position="center" @close="closeEditor">
            <form class="flex max-h-[calc(100vh-4rem)] flex-col" @submit.prevent="submit">
                <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4 dark:border-neutral-700">
                    <div>
                        <h2 class="text-lg font-semibold text-stone-900 dark:text-neutral-50">
                            {{ isEditing ? 'Modifier l offre' : 'Nouvelle offre' }}
                        </h2>
                        <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                            Prix fixe, contenu inclus, statut et visibilite publique.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex size-8 items-center justify-center rounded-sm border border-stone-200 bg-white text-stone-500 transition hover:bg-stone-50 hover:text-stone-800 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
                        aria-label="Fermer"
                        @click="closeEditor"
                    >
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg>
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5">
                    <div v-if="!catalogItems.length" class="rounded-sm border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
                        Ajoutez au moins un produit ou service avant de creer un pack ou forfait.
                    </div>

                    <div v-else class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(280px,340px)]">
                        <div class="space-y-4">
                            <div class="grid gap-3 md:grid-cols-2">
                                <div class="md:col-span-2">
                                    <FloatingInput v-model="form.name" label="Nom" required />
                                    <InputError class="mt-1" :message="form.errors.name" />
                                </div>
                                <div>
                                    <FloatingSelect
                                        v-model="form.type"
                                        label="Type"
                                        :options="typeOptions"
                                        option-value="value"
                                        option-label="label"
                                        required
                                    />
                                    <InputError class="mt-1" :message="form.errors.type" />
                                </div>
                                <div>
                                    <FloatingSelect
                                        v-model="form.status"
                                        label="Statut"
                                        :options="statusOptions"
                                        option-value="value"
                                        option-label="label"
                                    />
                                    <InputError class="mt-1" :message="form.errors.status" />
                                </div>
                                <div class="md:col-span-2">
                                    <FloatingTextarea v-model="form.description" label="Description" />
                                    <InputError class="mt-1" :message="form.errors.description" />
                                </div>
                                <div>
                                    <FloatingInput v-model="form.price" type="number" min="0" step="0.01" label="Prix fixe" required />
                                    <InputError class="mt-1" :message="form.errors.price" />
                                </div>
                                <div>
                                    <FloatingSelect
                                        v-model="form.currency_code"
                                        label="Devise"
                                        :options="currencyOptions"
                                        option-value="value"
                                        option-label="label"
                                    />
                                    <InputError class="mt-1" :message="form.errors.currency_code" />
                                </div>
                            </div>

                            <div v-if="form.type === 'forfait'" class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950">
                                <div class="mb-3 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    Parametres forfait
                                </div>
                                <div class="grid gap-3 md:grid-cols-3">
                                    <div>
                                        <FloatingInput v-model="form.included_quantity" type="number" min="1" step="1" label="Quantite" />
                                        <InputError class="mt-1" :message="form.errors.included_quantity" />
                                    </div>
                                    <div>
                                        <FloatingSelect
                                            v-model="form.unit_type"
                                            label="Unite"
                                            :options="unitTypeOptions"
                                            option-value="value"
                                            option-label="label"
                                        />
                                        <InputError class="mt-1" :message="form.errors.unit_type" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="form.validity_days" type="number" min="1" step="1" label="Validite en jours" />
                                        <InputError class="mt-1" :message="form.errors.validity_days" />
                                    </div>
                                </div>
                            </div>

                            <div v-if="form.type === 'forfait'" class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950">
                                <label class="inline-flex items-center gap-2 text-sm font-medium text-stone-700 dark:text-neutral-200">
                                    <input
                                        v-model="form.is_recurring"
                                        type="checkbox"
                                        class="rounded border-stone-300 text-green-600 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900"
                                    >
                                    <span>Forfait recurrent</span>
                                </label>
                                <div v-if="form.is_recurring" class="mt-3 grid gap-3 md:grid-cols-2">
                                    <div>
                                        <FloatingSelect
                                            v-model="form.recurrence_frequency"
                                            label="Frequence"
                                            :options="recurrenceOptions"
                                            option-value="value"
                                            option-label="label"
                                        />
                                        <InputError class="mt-1" :message="form.errors.recurrence_frequency" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="form.renewal_notice_days" type="number" min="1" max="365" step="1" label="Rappel avant renouvellement" />
                                        <InputError class="mt-1" :message="form.errors.renewal_notice_days" />
                                    </div>
                                </div>
                                <InputError class="mt-1" :message="form.errors.is_recurring" />
                            </div>

                            <label class="inline-flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                <input
                                    v-model="form.is_public"
                                    type="checkbox"
                                    class="rounded border-stone-300 text-green-600 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                <span>Page publique activable</span>
                            </label>
                            <InputError class="mt-1" :message="form.errors.is_public" />
                        </div>

                        <aside class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        Elements inclus
                                    </h3>
                                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        Les prix sont snapshots a la vente.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    :class="crmButtonClass('secondary', 'compact')"
                                    @click="addItem"
                                >
                                    Ajouter
                                </button>
                            </div>

                            <div class="mt-4 space-y-3">
                                <div
                                    v-for="(item, index) in form.items"
                                    :key="index"
                                    class="space-y-2 rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    <FloatingSelect
                                        v-model="item.product_id"
                                        label="Produit ou service"
                                        :options="catalogItemOptions"
                                        option-value="value"
                                        option-label="label"
                                        filterable
                                        @update:model-value="updateItemPrice(item)"
                                    />
                                    <InputError class="mt-1" :message="itemError(index, 'product_id')" />

                                    <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] gap-2">
                                        <div>
                                            <FloatingInput v-model="item.quantity" type="number" min="0.01" step="0.01" label="Quantite" />
                                            <InputError class="mt-1" :message="itemError(index, 'quantity')" />
                                        </div>
                                        <div>
                                            <FloatingInput v-model="item.unit_price" type="number" min="0" step="0.01" label="Prix" />
                                            <InputError class="mt-1" :message="itemError(index, 'unit_price')" />
                                        </div>
                                        <button
                                            type="button"
                                            class="mt-0.5 inline-flex h-[54px] items-center justify-center rounded-sm border border-stone-200 bg-white px-3 text-xs font-semibold text-stone-700 transition hover:bg-stone-50 disabled:opacity-40 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                            :disabled="form.items.length === 1"
                                            aria-label="Retirer l element"
                                            @click="removeItem(index)"
                                        >
                                            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M5 12h14" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <InputError class="mt-2" :message="form.errors.items" />
                        </aside>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-stone-200 px-5 py-4 dark:border-neutral-700">
                    <button
                        type="button"
                        :class="crmButtonClass('secondary', 'dialog')"
                        @click="closeEditor"
                    >
                        Annuler
                    </button>
                    <button
                        type="submit"
                        :class="crmButtonClass('primary', 'dialog')"
                        :disabled="form.processing || !catalogItems.length"
                    >
                        {{ isEditing ? 'Mettre a jour' : 'Creer l offre' }}
                    </button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
