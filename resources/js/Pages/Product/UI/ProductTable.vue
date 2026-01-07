<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';
import ProductForm from './ProductForm.vue';
import Modal from '@/Components/UI/Modal.vue';
import Checkbox from '@/Components/Checkbox.vue';

const props = defineProps({
    filters: Object,
    products: {
        type: Object,
        required: true,
    },
    product: {
        type: Object,
        default: null,
    },
    categories: {
        type: Array,
        required: true,
    },
    count: {
        type: Number,
        required: true,
    },
    warehouses: {
        type: Array,
        default: () => [],
    },
    defaultWarehouseId: {
        type: Number,
        default: null,
    },
});

const normalizeArray = (value) => {
    if (Array.isArray(value)) {
        return value;
    }
    if (value === null || value === undefined || value === '') {
        return [];
    }
    return [value];
};

const filterForm = useForm({
    name: props.filters?.name ?? '',
    category_ids: normalizeArray(props.filters?.category_ids ?? props.filters?.category_id ?? []),
    stock_status: props.filters?.stock_status ?? '',
    price_min: props.filters?.price_min ?? '',
    price_max: props.filters?.price_max ?? '',
    stock_min: props.filters?.stock_min ?? '',
    stock_max: props.filters?.stock_max ?? '',
    supplier_name: props.filters?.supplier_name ?? '',
    has_image: props.filters?.has_image ?? '',
    has_barcode: props.filters?.has_barcode ?? '',
    created_from: props.filters?.created_from ?? '',
    created_to: props.filters?.created_to ?? '',
    status: props.filters?.status ?? '',
    tracking_type: props.filters?.tracking_type ?? '',
    warehouse_id: props.filters?.warehouse_id ?? '',
    alert: props.filters?.alert ?? '',
    sort: props.filters?.sort ?? 'created_at',
    direction: props.filters?.direction ?? 'desc',
});

const showAdvanced = ref(false);

const filterPayload = () => {
    const payload = {
        name: filterForm.name,
        category_ids: filterForm.category_ids,
        stock_status: filterForm.stock_status,
        price_min: filterForm.price_min,
        price_max: filterForm.price_max,
        stock_min: filterForm.stock_min,
        stock_max: filterForm.stock_max,
        supplier_name: filterForm.supplier_name,
        has_image: filterForm.has_image,
        has_barcode: filterForm.has_barcode,
        created_from: filterForm.created_from,
        created_to: filterForm.created_to,
        status: filterForm.status,
        tracking_type: filterForm.tracking_type,
        warehouse_id: filterForm.warehouse_id,
        alert: filterForm.alert,
        sort: filterForm.sort,
        direction: filterForm.direction,
    };

    Object.keys(payload).forEach((key) => {
        const value = payload[key];
        if (value === '' || value === null || value === undefined) {
            delete payload[key];
            return;
        }
        if (Array.isArray(value) && value.length === 0) {
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
        router.get(route('product.index'), filterPayload(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
};

watch(() => filterForm.name, () => {
    autoFilter();
});

watch(() => filterForm.category_ids, () => {
    autoFilter();
}, { deep: true });

watch(() => [
    filterForm.stock_status,
    filterForm.price_min,
    filterForm.price_max,
    filterForm.stock_min,
    filterForm.stock_max,
    filterForm.supplier_name,
    filterForm.has_image,
    filterForm.has_barcode,
    filterForm.created_from,
    filterForm.created_to,
    filterForm.status,
    filterForm.tracking_type,
    filterForm.warehouse_id,
    filterForm.alert,
    filterForm.sort,
    filterForm.direction,
], () => {
    autoFilter();
});

const clearFilters = () => {
    filterForm.name = '';
    filterForm.category_ids = [];
    filterForm.stock_status = '';
    filterForm.price_min = '';
    filterForm.price_max = '';
    filterForm.stock_min = '';
    filterForm.stock_max = '';
    filterForm.supplier_name = '';
    filterForm.has_image = '';
    filterForm.has_barcode = '';
    filterForm.created_from = '';
    filterForm.created_to = '';
    filterForm.status = '';
    filterForm.tracking_type = '';
    filterForm.warehouse_id = '';
    filterForm.alert = '';
    filterForm.sort = 'created_at';
    filterForm.direction = 'desc';
    autoFilter();
};

const exportUrl = computed(() => route('product.export', filterPayload()));

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const formatPercent = (value) =>
    `${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}%`;

const activeWarehouses = computed(() =>
    (props.warehouses || []).filter((warehouse) => warehouse.is_active !== false)
);

const getAvailableStock = (product) =>
    Number(product.stock_available ?? product.stock ?? 0);

const getReservedStock = (product) =>
    Number(product.stock_reserved ?? 0);

const getDamagedStock = (product) =>
    Number(product.stock_damaged ?? 0);

const getStockValue = (product) =>
    Number(product.stock_value ?? 0);

const getTrackingLabel = (product) => {
    if (product.tracking_type === 'lot') {
        return 'Lot';
    }
    if (product.tracking_type === 'serial') {
        return 'Serial';
    }
    return 'Standard';
};

const getLotCount = (product) =>
    Number(product.lots_count ?? 0);

const getExpiringLotCount = (product) =>
    Number(product.expiring_lot_count ?? 0);

const getExpiredLotCount = (product) =>
    Number(product.expired_lot_count ?? 0);

const getNextExpiry = (product) =>
    product?.next_expiry_at || null;

const getPrimaryInventory = (product) => {
    if (Array.isArray(product?.inventories) && product.inventories.length) {
        return product.inventories[0];
    }
    return null;
};

const isLowStock = (product) =>
    getAvailableStock(product) > 0 && getAvailableStock(product) <= product.minimum_stock;

const isOutOfStock = (product) =>
    getAvailableStock(product) <= 0;

const alertBadgeClasses = {
    danger: 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400',
    warning: 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400',
    info: 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300',
};

const getAlertBadges = (product) => {
    const alerts = [];
    const damaged = getDamagedStock(product);
    const reserved = getReservedStock(product);
    const expiredLots = getExpiredLotCount(product);
    const expiringLots = getExpiringLotCount(product);

    if (isOutOfStock(product)) {
        alerts.push({ label: 'Out of stock', tone: 'danger' });
    } else if (isLowStock(product)) {
        alerts.push({ label: 'Low stock', tone: 'warning' });
    }

    if (damaged > 0) {
        alerts.push({ label: `Damaged ${formatNumber(damaged)}`, tone: 'danger' });
    }
    if (reserved > 0) {
        alerts.push({ label: `Reserved ${formatNumber(reserved)}`, tone: 'info' });
    }
    if (expiredLots > 0) {
        alerts.push({ label: `Expired ${formatNumber(expiredLots)}`, tone: 'danger' });
    }
    if (expiringLots > 0) {
        alerts.push({ label: `Expiring ${formatNumber(expiringLots)}`, tone: 'warning' });
    }

    return alerts;
};

const toggleSort = (column) => {
    if (filterForm.sort === column) {
        filterForm.direction = filterForm.direction === 'asc' ? 'desc' : 'asc';
        return;
    }
    filterForm.sort = column;
    filterForm.direction = 'asc';
};

const selected = ref([]);
const selectAllRef = ref(null);
const allSelected = computed(() =>
    props.products.data.length > 0 && selected.value.length === props.products.data.length
);
const someSelected = computed(() =>
    selected.value.length > 0 && !allSelected.value
);

watch(() => props.products.data, () => {
    selected.value = [];
}, { deep: true });

watch([allSelected, someSelected], () => {
    if (selectAllRef.value) {
        selectAllRef.value.indeterminate = someSelected.value;
    }
});

const toggleAll = (event) => {
    selected.value = event.target.checked
        ? props.products.data.map((product) => product.id)
        : [];
};

const bulkForm = useForm({
    action: '',
    ids: [],
});

const runBulk = (action) => {
    if (!selected.value.length) {
        return;
    }
    if (action === 'delete' && !confirm('Delete selected products?')) {
        return;
    }
    bulkForm.action = action;
    bulkForm.ids = selected.value;
    bulkForm.post(route('product.bulk'), {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = [];
        },
    });
};

const editingId = ref(null);
const inlineForm = useForm({
    price: 0,
    stock: 0,
    minimum_stock: 0,
});

const startInlineEdit = (product) => {
    editingId.value = product.id;
    inlineForm.price = product.price ?? 0;
    inlineForm.stock = getAvailableStock(product);
    inlineForm.minimum_stock = product.minimum_stock ?? 0;
};

const cancelInlineEdit = () => {
    editingId.value = null;
    inlineForm.reset();
};

const saveInlineEdit = () => {
    if (!editingId.value) {
        return;
    }
    inlineForm.put(route('product.quick-update', editingId.value), {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
        },
    });
};

const destroyProduct = (product) => {
    if (!confirm(`Delete "${product.name}"?`)) {
        return;
    }

    router.delete(route('product.destroy', product.id), {
        preserveScroll: true,
    });
};

const toggleArchive = (product) => {
    const nextState = !product.is_active;
    const label = nextState ? 'Restore' : 'Archive';
    if (!confirm(`${label} "${product.name}"?`)) {
        return;
    }
    router.put(route('product.quick-update', product.id), {
        is_active: nextState,
    }, {
        preserveScroll: true,
    });
};

const duplicateProduct = (product) => {
    router.post(route('product.duplicate', product.id), {}, {
        preserveScroll: true,
    });
};

const activeProduct = ref(null);
const reasonOptions = [
    { value: 'manual', label: 'Manual' },
    { value: 'purchase', label: 'Purchase' },
    { value: 'sale', label: 'Sale' },
    { value: 'return', label: 'Return' },
    { value: 'audit', label: 'Audit' },
    { value: 'transfer', label: 'Transfer' },
];
const adjustForm = useForm({
    type: 'in',
    quantity: 1,
    note: '',
    reason: 'manual',
    warehouse_id: props.defaultWarehouseId ?? '',
    lot_number: '',
    serial_number: '',
    expires_at: '',
    received_at: '',
    unit_cost: '',
});

const openAdjust = (product) => {
    activeProduct.value = product;
    adjustForm.reset();
    adjustForm.type = 'in';
    adjustForm.reason = 'manual';
    adjustForm.warehouse_id = getPrimaryInventory(product)?.warehouse_id ?? props.defaultWarehouseId ?? '';
    adjustForm.lot_number = '';
    adjustForm.serial_number = '';
    adjustForm.expires_at = '';
    adjustForm.received_at = '';
    adjustForm.unit_cost = '';
    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-pro-stock-adjust');
    }
};

const submitAdjust = () => {
    if (!activeProduct.value) {
        return;
    }
    adjustForm.post(route('product.adjust-stock', activeProduct.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            if (window.HSOverlay) {
                window.HSOverlay.close('#hs-pro-stock-adjust');
            }
        },
    });
};

const formatDate = (value) => humanizeDate(value);

const importForm = useForm({
    file: null,
});

const submitImport = () => {
    if (!importForm.file) {
        return;
    }
    importForm.post(route('product.import'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            importForm.reset();
            if (window.HSOverlay) {
                window.HSOverlay.close('#hs-pro-import');
            }
        },
    });
};
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-green-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700">

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
                            class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400"
                            placeholder="Search products, SKU, or barcode">
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 justify-end">
                    <button type="button" @click="showAdvanced = !showAdvanced"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                        Filters
                    </button>
                    <a :href="exportUrl"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                        Export CSV
                    </a>
                    <button type="button" data-hs-overlay="#hs-pro-import"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                        Import CSV
                    </button>
                    <button type="button"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500"
                        data-hs-overlay="#hs-pro-dasadpm">
                        <svg class="hidden sm:block shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                            height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14" />
                            <path d="M12 5v14" />
                        </svg>
                        Add product
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <select v-model="filterForm.stock_status"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">All stock</option>
                        <option value="in">In stock</option>
                        <option value="low">Low stock</option>
                        <option value="out">Out of stock</option>
                    </select>

                    <select v-model="filterForm.alert"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">All alerts</option>
                        <option value="damaged">Damaged</option>
                        <option value="reserved">Reserved</option>
                        <option value="expiring">Expiring soon</option>
                        <option value="expired">Expired</option>
                        <option value="reorder">Reorder point</option>
                    </select>

                    <select v-model="filterForm.tracking_type"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">All tracking</option>
                        <option value="none">Standard</option>
                        <option value="lot">Lot tracked</option>
                        <option value="serial">Serial tracked</option>
                    </select>

                    <select v-model="filterForm.warehouse_id"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 disabled:opacity-60 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        :disabled="!warehouses.length">
                        <option value="">All warehouses</option>
                        <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">
                            {{ warehouse.name }}{{ warehouse.is_default ? ' (Default)' : '' }}
                        </option>
                    </select>

                    <select v-model="filterForm.status"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">All status</option>
                        <option value="active">Active</option>
                        <option value="archived">Archived</option>
                    </select>

                    <select v-model="filterForm.has_image"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">All media</option>
                        <option value="1">With image</option>
                        <option value="0">Without image</option>
                    </select>

                    <button type="button" @click="clearFilters"
                        class="py-2 px-3 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                        Clear
                    </button>
                </div>

                <div v-if="selected.length" class="flex items-center gap-2">
                    <span class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ selected.length }} selected
                    </span>
                    <div class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                        <button type="button"
                            class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                            aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                            Bulk actions
                        </button>
                        <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-36 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                            role="menu" aria-orientation="vertical">
                            <div class="p-1">
                                <button type="button" @click="runBulk('archive')"
                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                    Archive
                                </button>
                                <button type="button" @click="runBulk('restore')"
                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                    Restore
                                </button>
                                <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                <button type="button" @click="runBulk('delete')"
                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800">
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="showAdvanced" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-2">
                <input type="number" step="0.01" v-model="filterForm.price_min"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    placeholder="Price min">
                <input type="number" step="0.01" v-model="filterForm.price_max"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    placeholder="Price max">
                <input type="number" step="1" v-model="filterForm.stock_min"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    placeholder="Stock min">
                <input type="number" step="1" v-model="filterForm.stock_max"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    placeholder="Stock max">
                <input type="text" v-model="filterForm.supplier_name"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    placeholder="Supplier name">
                <select v-model="filterForm.has_barcode"
                    class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                    <option value="">All barcodes</option>
                    <option value="1">With barcode</option>
                    <option value="0">Without barcode</option>
                </select>
                <input type="date" v-model="filterForm.created_from"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    placeholder="Created from">
                <input type="date" v-model="filterForm.created_to"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    placeholder="Created to">
                <div class="md:col-span-2 lg:col-span-6">
                    <select multiple v-model="filterForm.category_ids"
                        class="w-full py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option v-for="category in categories" :key="category.id" :value="category.id">
                            {{ category.name }}
                        </option>
                    </select>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
            <thead>
                <tr class="border-t border-stone-200 dark:border-neutral-700">
                    <th scope="col" class="px-4 py-2 w-10">
                        <input ref="selectAllRef" type="checkbox" :checked="allSelected" @change="toggleAll"
                            class="rounded border-stone-300 text-green-600 shadow-sm focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-green-400 dark:focus:ring-green-400" />
                    </th>
                    <th scope="col" class="min-w-[230px]">
                        <button type="button" @click="toggleSort('name')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            Name
                            <svg v-if="filterForm.sort === 'name'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-32">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            State
                        </div>
                    </th>
                    <th scope="col" class="min-w-36">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            Stock status
                        </div>
                    </th>
                    <th scope="col" class="min-w-48">
                        <button type="button" @click="toggleSort('price')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            Pricing
                            <svg v-if="filterForm.sort === 'price'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-[220px]">
                        <button type="button" @click="toggleSort('stock')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            Inventory
                            <svg v-if="filterForm.sort === 'stock'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-[180px]">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            Tracking / Lots
                        </div>
                    </th>
                    <th scope="col" class="min-w-[180px]">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            Alerts
                        </div>
                    </th>
                    <th scope="col" class="min-w-[200px]">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            Supplier / Location
                        </div>
                    </th>
                    <th scope="col" class="min-w-[165px]">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            Category
                        </div>
                    </th>
                    <th scope="col"></th>
                </tr>
            </thead>

            <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                <tr v-for="product in products.data" :key="product.id"
                    :class="{
                        'bg-amber-50/40 dark:bg-amber-500/5': isLowStock(product),
                        'bg-red-50/40 dark:bg-red-500/5': isOutOfStock(product),
                    }">
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <Checkbox v-model:checked="selected" :value="product.id" />
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <div class="w-full flex items-center gap-x-3">
                            <img class="shrink-0 size-10 rounded-sm" :src="product.image_url || product.image"
                                alt="Product Image">
                            <div class="flex flex-col gap-1">
                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ product.name }}
                                </span>
                                <div class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ product.sku || product.number || 'No SKU' }}
                                    <span v-if="product.barcode" class="ml-2">
                                        - Barcode {{ product.barcode }}
                                    </span>
                                </div>
                                <div class="flex flex-wrap items-center gap-1">
                                    <span
                                        class="py-1 px-2 rounded-full text-[10px] font-medium bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200">
                                        {{ getTrackingLabel(product) }}
                                    </span>
                                    <span v-if="product.unit"
                                        class="py-1 px-2 rounded-full text-[10px] font-medium bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200">
                                        Unit {{ product.unit }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <span v-if="product.is_active"
                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-emerald-100 text-emerald-800 rounded-full dark:bg-emerald-500/10 dark:text-emerald-400">
                            Active
                        </span>
                        <span v-else
                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-stone-200 text-stone-700 rounded-full dark:bg-neutral-700 dark:text-neutral-300">
                            Archived
                        </span>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <span v-if="isOutOfStock(product)"
                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-red-100 text-red-800 rounded-full dark:bg-red-500/10 dark:text-red-500">
                            Out of stock
                        </span>
                        <span v-else-if="!isLowStock(product)"
                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-green-100 text-green-800 rounded-full dark:bg-green-500/10 dark:text-green-500">
                            In stock
                        </span>
                        <span v-else
                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-amber-100 text-amber-800 rounded-full dark:bg-amber-500/10 dark:text-amber-400">
                            Low stock
                        </span>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <div v-if="editingId === product.id" class="space-y-1">
                            <input type="number" step="0.01" v-model="inlineForm.price"
                                class="w-28 py-1.5 px-2 bg-white border border-stone-200 rounded-sm text-xs text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <div class="text-xs text-stone-500 dark:text-neutral-500">
                                Cost {{ formatCurrency(product.cost_price) }}
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-500">
                                Margin {{ formatPercent(product.margin_percent) }}
                            </div>
                        </div>
                        <div v-else class="space-y-1">
                            <div class="text-sm text-stone-600 dark:text-neutral-400">
                                {{ formatCurrency(product.price) }}
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-500">
                                Cost {{ formatCurrency(product.cost_price) }}
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-500">
                                Margin {{ formatPercent(product.margin_percent) }}
                            </div>
                        </div>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <div v-if="editingId === product.id" class="space-y-2">
                            <input type="number" step="1" v-model="inlineForm.stock"
                                class="w-24 py-1.5 px-2 bg-white border border-stone-200 rounded-sm text-xs text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                placeholder="Available">
                            <input type="number" step="1" v-model="inlineForm.minimum_stock"
                                class="w-24 py-1.5 px-2 bg-white border border-stone-200 rounded-sm text-xs text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                placeholder="Min">
                        </div>
                        <div v-else class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200">
                                    Avail {{ formatNumber(getAvailableStock(product)) }}
                                </span>
                                <span class="text-xs text-stone-500 dark:text-neutral-500">
                                    Min {{ formatNumber(product.minimum_stock) }}
                                </span>
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-500">
                                Reserved {{ formatNumber(getReservedStock(product)) }} - Damaged {{ formatNumber(getDamagedStock(product)) }}
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-500">
                                Value {{ formatCurrency(getStockValue(product)) }} - {{ formatNumber(product.warehouse_count || 0) }} wh
                            </div>
                        </div>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <div class="space-y-1">
                            <div class="text-sm text-stone-600 dark:text-neutral-300">
                                {{ getTrackingLabel(product) }}
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-500">
                                <span v-if="product.tracking_type === 'lot'">
                                    Lots {{ formatNumber(getLotCount(product)) }}
                                </span>
                                <span v-else-if="product.tracking_type === 'serial'">
                                    Serials {{ formatNumber(getLotCount(product)) }}
                                </span>
                                <span v-else>
                                    No lots
                                </span>
                            </div>
                            <div v-if="getNextExpiry(product)" class="text-xs text-stone-500 dark:text-neutral-500">
                                Next exp {{ formatDate(getNextExpiry(product)) }}
                            </div>
                        </div>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <div v-if="getAlertBadges(product).length" class="flex flex-wrap gap-1">
                            <span v-for="alert in getAlertBadges(product)" :key="alert.label"
                                class="py-1 px-2 rounded-full text-[10px] font-medium"
                                :class="alertBadgeClasses[alert.tone]">
                                {{ alert.label }}
                            </span>
                        </div>
                        <div v-else class="text-xs text-stone-400 dark:text-neutral-500">
                            No alerts
                        </div>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <div class="space-y-1">
                            <div class="text-sm text-stone-600 dark:text-neutral-300">
                                {{ product.supplier_name || 'No supplier' }}
                            </div>
                            <div v-if="getPrimaryInventory(product)" class="text-xs text-stone-500 dark:text-neutral-500">
                                {{ getPrimaryInventory(product)?.warehouse?.name || 'Main warehouse' }}
                                <span v-if="getPrimaryInventory(product)?.bin_location">
                                    - Bin {{ getPrimaryInventory(product)?.bin_location }}
                                </span>
                            </div>
                            <div v-else class="text-xs text-stone-400 dark:text-neutral-500">
                                No location set
                            </div>
                        </div>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <span class="inline-flex items-center gap-x-1 text-sm text-stone-600 dark:text-neutral-400">
                            {{ product.category ? product.category.name : 'Uncategorized' }}
                        </span>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2 text-end">
                        <div v-if="editingId === product.id" class="flex items-center justify-end gap-2">
                            <button type="button" @click="saveInlineEdit"
                                class="py-1.5 px-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                Save
                            </button>
                            <button type="button" @click="cancelInlineEdit"
                                class="py-1.5 px-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                Cancel
                            </button>
                        </div>
                        <div v-else class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                            <button type="button"
                                class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="1" />
                                    <circle cx="12" cy="5" r="1" />
                                    <circle cx="12" cy="19" r="1" />
                                </svg>
                            </button>

                            <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-32 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                role="menu" aria-orientation="vertical">
                                <div class="p-1">
                                    <button type="button" @click="startInlineEdit(product)"
                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                        Quick edit
                                    </button>
                                    <button type="button" @click="openAdjust(product)"
                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                        Adjust stock
                                    </button>
                                    <button type="button" @click="duplicateProduct(product)"
                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                        Duplicate
                                    </button>
                                    <button type="button" @click="toggleArchive(product)"
                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                        {{ product.is_active ? 'Archive' : 'Restore' }}
                                    </button>
                                    <button type="button" :data-hs-overlay="'#hs-pro-edit' + product.id"
                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                        Edit
                                    </button>
                                    <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                    <button type="button" @click="destroyProduct(product)"
                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800">
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </td>

                    <Modal :title="'Edit product'" :id="'hs-pro-edit' + product.id">
                        <ProductForm :product="product" :categories="categories" :id="'hs-pro-edit' + product.id" />
                    </Modal>
                </tr>
            </tbody>
        </table>
        </div>

        <div class="mt-5 flex flex-wrap justify-between items-center gap-2">
            <p class="text-sm text-stone-800 dark:text-neutral-200">
                <span class="font-medium"> {{ count }} </span>
                <span class="text-stone-500 dark:text-neutral-500"> results</span>
            </p>

            <nav class="flex justify-end items-center gap-x-1" aria-label="Pagination">
                <Link :href="products.prev_page_url" v-if="products.prev_page_url">
                <button type="button"
                    class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
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
                        aria-current="page">{{ products.from }}</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">of</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">{{
                            products.to }}</span>
                </div>

                <Link :href="products.next_page_url" v-if="products.next_page_url">
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

    <Modal :title="'Add product'" :id="'hs-pro-dasadpm'">
        <ProductForm :product="product" :categories="categories" :id="'hs-pro-dasadpm'" />
    </Modal>

    <Modal :title="'Import products'" :id="'hs-pro-import'">
        <form @submit.prevent="submitImport" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-stone-700 dark:text-neutral-300">CSV file</label>
                <input type="file" accept=".csv,text/csv" @change="importForm.file = $event.target.files[0]"
                    class="mt-2 block w-full text-sm text-stone-600 file:me-4 file:py-2 file:px-4 file:rounded-sm file:border-0 file:text-sm file:font-medium file:bg-stone-100 file:text-stone-700 hover:file:bg-stone-200 dark:text-neutral-400 dark:file:bg-neutral-700 dark:file:text-neutral-200 dark:hover:file:bg-neutral-600">
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" data-hs-overlay="#hs-pro-import"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                    Cancel
                </button>
                <button type="submit"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                    Import
                </button>
            </div>
        </form>
    </Modal>

    <Modal :title="'Adjust stock'" :id="'hs-pro-stock-adjust'">
        <div v-if="activeProduct" class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">Product</div>
                    <div class="text-sm font-medium text-stone-800 dark:text-neutral-200">{{ activeProduct.name }}</div>
                </div>
                <div class="text-sm text-stone-500 dark:text-neutral-400">
                    <span class="font-medium text-stone-800 dark:text-neutral-200">
                        Avail {{ formatNumber(getAvailableStock(activeProduct)) }}
                    </span>
                    <span class="ml-2 text-xs text-stone-500 dark:text-neutral-400">
                        Reserved {{ formatNumber(getReservedStock(activeProduct)) }}
                    </span>
                    <span class="ml-2 text-xs text-stone-500 dark:text-neutral-400">
                        Damaged {{ formatNumber(getDamagedStock(activeProduct)) }}
                    </span>
                </div>
            </div>

            <div v-if="getAvailableStock(activeProduct) <= activeProduct.minimum_stock" class="text-xs text-amber-600">
                Low stock alert. Minimum is {{ activeProduct.minimum_stock }}.
            </div>

            <form @submit.prevent="submitAdjust" class="space-y-3">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <select v-model="adjustForm.type"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="in">Stock in</option>
                        <option value="out">Stock out</option>
                        <option value="adjust">Adjust</option>
                        <option value="damage">Damage</option>
                        <option value="spoilage">Spoilage</option>
                    </select>
                    <input type="number" step="1" v-model="adjustForm.quantity"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        :placeholder="adjustForm.type === 'adjust' ? 'Quantity change' : 'Quantity'">
                    <select v-model="adjustForm.warehouse_id"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 disabled:opacity-60 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        :disabled="!activeWarehouses.length">
                        <option value="">Select warehouse</option>
                        <option v-for="warehouse in activeWarehouses" :key="warehouse.id" :value="warehouse.id">
                            {{ warehouse.name }}{{ warehouse.is_default ? ' (Default)' : '' }}
                        </option>
                    </select>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <select v-model="adjustForm.reason"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option v-for="reason in reasonOptions" :key="reason.value" :value="reason.value">
                            {{ reason.label }}
                        </option>
                    </select>
                    <input type="number" step="0.01" v-model="adjustForm.unit_cost"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        placeholder="Unit cost (optional)">
                    <input type="text" v-model="adjustForm.note"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        placeholder="Note (optional)">
                </div>
                <div v-if="activeProduct.tracking_type === 'lot'" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <input type="text" v-model="adjustForm.lot_number"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        placeholder="Lot number">
                    <input type="date" v-model="adjustForm.expires_at"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        placeholder="Expires at">
                    <input type="date" v-model="adjustForm.received_at"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        placeholder="Received at">
                </div>
                <div v-else-if="activeProduct.tracking_type === 'serial'" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <input type="text" v-model="adjustForm.serial_number"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        placeholder="Serial number">
                    <div class="md:col-span-2 text-xs text-stone-500 dark:text-neutral-400 flex items-center">
                        Serial-tracked items are adjusted one at a time.
                    </div>
                </div>
                <div v-if="!activeWarehouses.length" class="text-xs text-amber-600">
                    Add a warehouse in settings before adjusting stock.
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" data-hs-overlay="#hs-pro-stock-adjust"
                        class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        Cancel
                    </button>
                    <button type="submit"
                        class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                        Save
                    </button>
                </div>
            </form>

            <div class="space-y-2">
                <div class="text-sm font-medium text-stone-700 dark:text-neutral-300">Recent movements</div>
                <div v-if="!activeProduct.stock_movements || !activeProduct.stock_movements.length"
                    class="text-sm text-stone-500 dark:text-neutral-400">
                    No movements yet.
                </div>
                <div v-else class="space-y-2">
                    <div v-for="movement in activeProduct.stock_movements" :key="movement.id"
                        class="flex items-center justify-between rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700">
                        <div>
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                {{ movement.type }}
                                <span v-if="movement.warehouse"> - {{ movement.warehouse.name }}</span>
                                <span v-if="movement.lot?.lot_number"> - Lot {{ movement.lot.lot_number }}</span>
                                <span v-else-if="movement.lot?.serial_number"> - SN {{ movement.lot.serial_number }}</span>
                                - {{ formatDate(movement.created_at) }}
                            </div>
                            <div class="text-sm text-stone-700 dark:text-neutral-300">
                                {{ movement.reason || movement.note || 'No note' }}
                            </div>
                        </div>
                        <div
                            :class="movement.quantity > 0 ? 'text-emerald-600' : 'text-red-600'"
                            class="text-sm font-medium">
                            {{ movement.quantity > 0 ? '+' : '' }}{{ movement.quantity }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div v-else class="text-sm text-stone-500 dark:text-neutral-400">
            Select a product to adjust stock.
        </div>
    </Modal>
</template>
