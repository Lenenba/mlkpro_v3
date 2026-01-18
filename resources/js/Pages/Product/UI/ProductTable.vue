<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';
import ProductForm from './ProductForm.vue';
import Modal from '@/Components/UI/Modal.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { useI18n } from 'vue-i18n';

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
    canEdit: {
        type: Boolean,
        default: false,
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

const { t } = useI18n();

const canEdit = computed(() => Boolean(props.canEdit));

const showAdvanced = ref(false);
const isLoading = ref(false);

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
        isLoading.value = true;
        router.get(route('product.index'), filterPayload(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                isLoading.value = false;
            },
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
        return t('products.tracking.lot');
    }
    if (product.tracking_type === 'serial') {
        return t('products.tracking.serial');
    }
    return t('products.tracking.standard');
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
        alerts.push({ key: 'out', label: t('products.alerts.out_of_stock'), tone: 'danger' });
    } else if (isLowStock(product)) {
        alerts.push({ key: 'low', label: t('products.alerts.low_stock'), tone: 'warning' });
    }

    if (damaged > 0) {
        alerts.push({ key: 'damaged', label: t('products.alerts.damaged_count', { count: formatNumber(damaged) }), tone: 'danger' });
    }
    if (reserved > 0) {
        alerts.push({ key: 'reserved', label: t('products.alerts.reserved_count', { count: formatNumber(reserved) }), tone: 'info' });
    }
    if (expiredLots > 0) {
        alerts.push({ key: 'expired', label: t('products.alerts.expired_lots_count', { count: formatNumber(expiredLots) }), tone: 'danger' });
    }
    if (expiringLots > 0) {
        alerts.push({ key: 'expiring', label: t('products.alerts.expiring_lots_count', { count: formatNumber(expiringLots) }), tone: 'warning' });
    }

    return alerts;
};

const alertTypeLabels = computed(() => ({
    out: t('products.alerts.out_of_stock'),
    low: t('products.alerts.low_stock'),
    damaged: t('products.alerts.damaged_items'),
    reserved: t('products.alerts.reserved_orders'),
    expired: t('products.alerts.expired_lots'),
    expiring: t('products.alerts.expiring_lots'),
}));

const orderStatusLabels = computed(() => ({
    pending: t('products.orders.status.pending'),
    draft: t('products.orders.status.draft'),
    paid: t('products.orders.status.paid'),
    canceled: t('products.orders.status.canceled'),
}));

const fulfillmentStatusLabels = computed(() => ({
    pending: t('products.orders.fulfillment.pending'),
    preparing: t('products.orders.fulfillment.preparing'),
    out_for_delivery: t('products.orders.fulfillment.out_for_delivery'),
    ready_for_pickup: t('products.orders.fulfillment.ready_for_pickup'),
    completed: t('products.orders.fulfillment.completed'),
    confirmed: t('products.orders.fulfillment.confirmed'),
}));

const alertDetailsProduct = ref(null);
const alertDetailsType = ref('');
const alertDetailsTitle = computed(() => {
    if (!alertDetailsProduct.value) {
        return t('products.alerts.details_title');
    }
    const label = alertTypeLabels.value[alertDetailsType.value] || t('products.alerts.details_title');
    return `${alertDetailsProduct.value.name} - ${label}`;
});

const openAlertDetails = (product, alertType) => {
    alertDetailsProduct.value = product;
    const badges = getAlertBadges(product);
    alertDetailsType.value = alertType || badges[0]?.key || '';
    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-pro-alert-details');
    }
};

const getReservedOrders = (product) => (
    Array.isArray(product?.reserved_orders) ? product.reserved_orders : []
);

const getDamagedInventories = (product) => (
    Array.isArray(product?.inventories)
        ? product.inventories.filter((inventory) => Number(inventory.damaged) > 0)
        : []
);

const getDamageMovements = (product) => (
    Array.isArray(product?.stock_movements)
        ? product.stock_movements.filter((movement) => ['damage', 'spoilage'].includes(movement.type))
        : []
);

const formatOrderStatus = (value, labels) => labels[value] || value || '--';

const parseAlertDate = (value) => {
    if (!value) {
        return null;
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return null;
    }
    return date;
};

const getAlertLots = (product, type) => {
    if (!Array.isArray(product?.lots)) {
        return [];
    }
    const now = new Date();
    const soon = new Date();
    soon.setDate(soon.getDate() + 30);
    return product.lots.filter((lot) => {
        const expiresAt = parseAlertDate(lot.expires_at);
        if (!expiresAt) {
            return false;
        }
        if (type === 'expired') {
            return expiresAt < now;
        }
        if (type === 'expiring') {
            return expiresAt >= now && expiresAt <= soon;
        }
        return false;
    });
};

const getLotLabel = (lot) => {
    if (lot?.serial_number) {
        return t('products.lots.serial_short', { number: lot.serial_number });
    }
    if (lot?.lot_number) {
        return t('products.lots.lot_label', { number: lot.lot_number });
    }
    return t('products.lots.lot_fallback');
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
    if (action === 'delete' && !confirm(t('products.bulk.delete_confirm'))) {
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
    if (!confirm(t('products.actions.delete_confirm', { name: product.name }))) {
        return;
    }

    router.delete(route('product.destroy', product.id), {
        preserveScroll: true,
    });
};

const toggleArchive = (product) => {
    const nextState = !product.is_active;
    const label = nextState ? t('products.actions.restore') : t('products.actions.archive');
    if (!confirm(t('products.actions.archive_confirm', { action: label, name: product.name }))) {
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
const reasonOptions = computed(() => ([
    { value: 'manual', label: t('products.adjust.reasons.manual') },
    { value: 'purchase', label: t('products.adjust.reasons.purchase') },
    { value: 'sale', label: t('products.adjust.reasons.sale') },
    { value: 'return', label: t('products.adjust.reasons.return') },
    { value: 'audit', label: t('products.adjust.reasons.audit') },
    { value: 'transfer', label: t('products.adjust.reasons.transfer') },
]));
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
                        <input type="text" v-model="filterForm.name" data-testid="demo-product-search"
                            class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400"
                            :placeholder="$t('products.filters.search_placeholder')">
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 justify-end">
                    <button type="button" @click="showAdvanced = !showAdvanced"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                        {{ $t('products.actions.filters') }}
                    </button>
                    <template v-if="canEdit">
                        <a :href="exportUrl"
                            class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                            {{ $t('products.actions.export_csv') }}
                        </a>
                        <button type="button" data-hs-overlay="#hs-pro-import"
                            class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                            {{ $t('products.actions.import_csv') }}
                        </button>
                        <button type="button"
                            class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500 action-feedback"
                            data-hs-overlay="#hs-pro-dasadpm">
                            <svg class="hidden sm:block shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5 12h14" />
                                <path d="M12 5v14" />
                            </svg>
                            {{ $t('products.actions.add_product') }}
                        </button>
                    </template>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <select v-model="filterForm.stock_status"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">{{ $t('products.filters.stock.all') }}</option>
                        <option value="in">{{ $t('products.stock_status.in_stock') }}</option>
                        <option value="low">{{ $t('products.stock_status.low_stock') }}</option>
                        <option value="out">{{ $t('products.stock_status.out_of_stock') }}</option>
                    </select>

                    <select v-model="filterForm.alert"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">{{ $t('products.filters.alerts.all') }}</option>
                        <option value="damaged">{{ $t('products.alerts.damaged') }}</option>
                        <option value="reserved">{{ $t('products.alerts.reserved') }}</option>
                        <option value="expiring">{{ $t('products.alerts.expiring') }}</option>
                        <option value="expired">{{ $t('products.alerts.expired') }}</option>
                        <option value="reorder">{{ $t('products.alerts.reorder') }}</option>
                    </select>

                    <select v-model="filterForm.tracking_type"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">{{ $t('products.filters.tracking.all') }}</option>
                        <option value="none">{{ $t('products.tracking.standard') }}</option>
                        <option value="lot">{{ $t('products.tracking.lot_tracked') }}</option>
                        <option value="serial">{{ $t('products.tracking.serial_tracked') }}</option>
                    </select>

                    <select v-model="filterForm.warehouse_id"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 disabled:opacity-60 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        :disabled="!warehouses.length">
                        <option value="">{{ $t('products.filters.warehouse.all') }}</option>
                        <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">
                            {{ warehouse.name }}{{ warehouse.is_default ? ' (' + $t('products.filters.warehouse.default') + ')' : '' }}
                        </option>
                    </select>

                    <select v-model="filterForm.status"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">{{ $t('products.filters.status.all') }}</option>
                        <option value="active">{{ $t('products.status.active') }}</option>
                        <option value="archived">{{ $t('products.status.archived') }}</option>
                    </select>

                    <select v-model="filterForm.has_image"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">{{ $t('products.filters.media.all') }}</option>
                        <option value="1">{{ $t('products.filters.media.with_image') }}</option>
                        <option value="0">{{ $t('products.filters.media.without_image') }}</option>
                    </select>

                    <button type="button" @click="clearFilters"
                        class="py-2 px-3 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                        {{ $t('products.actions.clear') }}
                    </button>
                </div>

                <div v-if="canEdit && selected.length" class="flex items-center gap-2">
                    <span class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('products.bulk.selected', { count: selected.length }) }}
                    </span>
                    <div class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                        <button type="button"
                            class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700 action-feedback"
                            aria-haspopup="menu" aria-expanded="false" :aria-label="$t('products.aria.dropdown')">
                            {{ $t('products.bulk.actions') }}
                        </button>
                        <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-36 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                            role="menu" aria-orientation="vertical">
                            <div class="p-1">
                                <button type="button" @click="runBulk('archive')"
                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback" data-tone="warning">
                                    {{ $t('products.actions.archive') }}
                                </button>
                                <button type="button" @click="runBulk('restore')"
                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                    {{ $t('products.actions.restore') }}
                                </button>
                                <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                <button type="button" @click="runBulk('delete')"
                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800 action-feedback" data-tone="danger">
                                    {{ $t('products.actions.delete') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="showAdvanced" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-2">
                <input type="number" step="0.01" v-model="filterForm.price_min"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('products.filters.price_min')">
                <input type="number" step="0.01" v-model="filterForm.price_max"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('products.filters.price_max')">
                <input type="number" step="1" v-model="filterForm.stock_min"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('products.filters.stock_min')">
                <input type="number" step="1" v-model="filterForm.stock_max"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('products.filters.stock_max')">
                <input type="text" v-model="filterForm.supplier_name"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('products.filters.supplier_name')">
                <select v-model="filterForm.has_barcode"
                    class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                    <option value="">{{ $t('products.filters.barcodes.all') }}</option>
                    <option value="1">{{ $t('products.filters.barcodes.with') }}</option>
                    <option value="0">{{ $t('products.filters.barcodes.without') }}</option>
                </select>
                <input type="date" v-model="filterForm.created_from"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('products.filters.created_from')">
                <input type="date" v-model="filterForm.created_to"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('products.filters.created_to')">
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
                        <input v-if="canEdit" ref="selectAllRef" type="checkbox" :checked="allSelected" @change="toggleAll"
                            class="rounded border-stone-300 text-green-600 shadow-sm focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-green-400 dark:focus:ring-green-400" />
                    </th>
                    <th scope="col" class="min-w-[230px]">
                        <button type="button" @click="toggleSort('name')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('products.table.name') }}
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
                            {{ $t('products.table.state') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-36">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('products.table.stock_status') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-48">
                        <button type="button" @click="toggleSort('price')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('products.table.pricing') }}
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
                            {{ $t('products.table.inventory') }}
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
                            {{ $t('products.table.tracking') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-[180px]">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('products.table.alerts') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-[200px]">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('products.table.supplier_location') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-[165px]">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('products.table.category') }}
                        </div>
                    </th>
                    <th scope="col"></th>
                </tr>
            </thead>

            <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                <template v-if="isLoading">
                    <tr v-for="row in 6" :key="`skeleton-${row}`">
                        <td colspan="11" class="px-4 py-3">
                            <div class="grid grid-cols-7 gap-4 animate-pulse">
                                <div class="h-3 w-10 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="h-3 w-40 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="h-3 w-28 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="h-3 w-28 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            </div>
                        </td>
                    </tr>
                </template>
                <template v-else>
                    <tr v-if="!products.data.length">
                        <td colspan="11" class="px-4 py-10 text-center text-stone-600 dark:text-neutral-300">
                            <div class="space-y-2">
                                <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ $t('products.empty.title') }}
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('products.empty.subtitle') }}
                                </div>
                                <div v-if="canEdit" class="flex flex-wrap justify-center gap-2 pt-2">
                                    <button
                                        type="button"
                                        data-hs-overlay="#hs-pro-dasadpm"
                                        class="inline-flex items-center rounded-sm border border-green-600 bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700"
                                    >
                                        {{ $t('products.empty.add_action') }}
                                    </button>
                                    <button
                                        type="button"
                                        data-hs-overlay="#hs-pro-import"
                                        class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    >
                                        {{ $t('products.actions.import_csv') }}
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr v-for="product in products.data" :key="product.id"
                        :class="{
                            'bg-amber-50/40 dark:bg-amber-500/5': isLowStock(product),
                            'bg-red-50/40 dark:bg-red-500/5': isOutOfStock(product),
                        }">
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <Checkbox v-if="canEdit" v-model:checked="selected" :value="product.id" />
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <div class="w-full flex items-center gap-x-3">
                            <img class="shrink-0 size-10 rounded-sm" :src="product.image_url || product.image"
                                :alt="$t('products.labels.product_image_alt')">
                            <div class="flex flex-col gap-1">
                                <Link
                                    :href="route('product.show', product.id)"
                                    class="text-sm font-medium text-stone-700 hover:underline dark:text-neutral-200"
                                >
                                    {{ product.name }}
                                </Link>
                                <div class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ product.sku || product.number || $t('products.labels.no_sku') }}
                                    <span v-if="product.barcode" class="ml-2">
                                        - {{ $t('products.labels.barcode') }} {{ product.barcode }}
                                    </span>
                                </div>
                                <div class="flex flex-wrap items-center gap-1">
                                    <span
                                        class="py-1 px-2 rounded-full text-[10px] font-medium bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200">
                                        {{ getTrackingLabel(product) }}
                                    </span>
                                    <span v-if="product.unit"
                                        class="py-1 px-2 rounded-full text-[10px] font-medium bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200">
                                        {{ $t('products.labels.unit') }} {{ product.unit }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <span v-if="product.is_active"
                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-emerald-100 text-emerald-800 rounded-full dark:bg-emerald-500/10 dark:text-emerald-400">
                            {{ $t('products.status.active') }}
                        </span>
                        <span v-else
                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-stone-200 text-stone-700 rounded-full dark:bg-neutral-700 dark:text-neutral-300">
                            {{ $t('products.status.archived') }}
                        </span>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <span v-if="isOutOfStock(product)"
                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-red-100 text-red-800 rounded-full dark:bg-red-500/10 dark:text-red-500">
                            {{ $t('products.stock_status.out_of_stock') }}
                        </span>
                        <span v-else-if="!isLowStock(product)"
                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-green-100 text-green-800 rounded-full dark:bg-green-500/10 dark:text-green-500">
                            {{ $t('products.stock_status.in_stock') }}
                        </span>
                        <span v-else
                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-amber-100 text-amber-800 rounded-full dark:bg-amber-500/10 dark:text-amber-400">
                            {{ $t('products.stock_status.low_stock') }}
                        </span>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <div v-if="editingId === product.id" class="space-y-1">
                            <input type="number" step="0.01" v-model="inlineForm.price"
                                class="w-28 py-1.5 px-2 bg-white border border-stone-200 rounded-sm text-xs text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <div class="text-xs text-stone-500 dark:text-neutral-500">
                                {{ $t('products.labels.cost') }} {{ formatCurrency(product.cost_price) }}
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-500">
                                {{ $t('products.labels.margin') }} {{ formatPercent(product.margin_percent) }}
                            </div>
                        </div>
                        <div v-else class="space-y-1">
                            <div class="text-sm text-stone-600 dark:text-neutral-400">
                                {{ formatCurrency(product.price) }}
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-500">
                                {{ $t('products.labels.cost') }} {{ formatCurrency(product.cost_price) }}
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-500">
                                {{ $t('products.labels.margin') }} {{ formatPercent(product.margin_percent) }}
                            </div>
                        </div>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <div v-if="editingId === product.id" class="space-y-2">
                            <input type="number" step="1" v-model="inlineForm.stock"
                                class="w-24 py-1.5 px-2 bg-white border border-stone-200 rounded-sm text-xs text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                :placeholder="$t('products.labels.available')">
                            <input type="number" step="1" v-model="inlineForm.minimum_stock"
                                class="w-24 py-1.5 px-2 bg-white border border-stone-200 rounded-sm text-xs text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                :placeholder="$t('products.labels.minimum_short')">
                        </div>
                        <div v-else class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200">
                                    {{ $t('products.labels.available') }} {{ formatNumber(getAvailableStock(product)) }}
                                </span>
                                <span class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ $t('products.labels.minimum_short') }} {{ formatNumber(product.minimum_stock) }}
                                </span>
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-500">
                                {{ $t('products.labels.reserved') }} {{ formatNumber(getReservedStock(product)) }}
                                - {{ $t('products.labels.damaged') }} {{ formatNumber(getDamagedStock(product)) }}
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-500">
                                {{ $t('products.labels.value') }} {{ formatCurrency(getStockValue(product)) }}
                                - {{ formatNumber(product.warehouse_count || 0) }} {{ $t('products.labels.warehouse_short') }}
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
                                    {{ $t('products.tracking.lots') }} {{ formatNumber(getLotCount(product)) }}
                                </span>
                                <span v-else-if="product.tracking_type === 'serial'">
                                    {{ $t('products.tracking.serials') }} {{ formatNumber(getLotCount(product)) }}
                                </span>
                                <span v-else>
                                    {{ $t('products.tracking.none') }}
                                </span>
                            </div>
                            <div v-if="getNextExpiry(product)" class="text-xs text-stone-500 dark:text-neutral-500">
                                {{ $t('products.labels.next_expiry') }} {{ formatDate(getNextExpiry(product)) }}
                            </div>
                        </div>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <div v-if="getAlertBadges(product).length" class="flex flex-wrap gap-1">
                            <button v-for="alert in getAlertBadges(product)" :key="alert.key"
                                type="button"
                                class="py-1 px-2 rounded-full text-[10px] font-medium transition hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                                :class="alertBadgeClasses[alert.tone]"
                                :aria-label="t('products.alerts.open_details', { label: alert.label })"
                                @click="openAlertDetails(product, alert.key)">
                                {{ alert.label }}
                            </button>
                        </div>
                        <div v-else class="text-xs text-stone-400 dark:text-neutral-500">
                            {{ $t('products.alerts.none') }}
                        </div>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <div class="space-y-1">
                            <div class="text-sm text-stone-600 dark:text-neutral-300">
                                {{ product.supplier_name || $t('products.labels.no_supplier') }}
                            </div>
                            <div v-if="getPrimaryInventory(product)" class="text-xs text-stone-500 dark:text-neutral-500">
                                {{ getPrimaryInventory(product)?.warehouse?.name || $t('products.labels.main_warehouse') }}
                                <span v-if="getPrimaryInventory(product)?.bin_location">
                                    - {{ $t('products.labels.bin') }} {{ getPrimaryInventory(product)?.bin_location }}
                                </span>
                            </div>
                            <div v-else class="text-xs text-stone-400 dark:text-neutral-500">
                                {{ $t('products.labels.no_location') }}
                            </div>
                        </div>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <span class="inline-flex items-center gap-x-1 text-sm text-stone-600 dark:text-neutral-400">
                            {{ product.category ? product.category.name : $t('products.labels.uncategorized') }}
                        </span>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2 text-end">
                        <div v-if="editingId === product.id && canEdit" class="flex items-center justify-end gap-2">
                            <button type="button" @click="saveInlineEdit"
                                class="py-1.5 px-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 action-feedback">
                                {{ $t('products.actions.save') }}
                            </button>
                            <button type="button" @click="cancelInlineEdit"
                                class="py-1.5 px-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 action-feedback">
                                {{ $t('products.actions.cancel') }}
                            </button>
                        </div>
                        <div v-else>
                            <div v-if="canEdit" class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                                <button type="button"
                                    class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                    aria-haspopup="menu" aria-expanded="false" :aria-label="$t('products.aria.dropdown')">
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
                                        <Link
                                            :href="route('product.show', product.id)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                        >
                                            {{ $t('products.actions.view') }}
                                        </Link>
                                        <button type="button" @click="startInlineEdit(product)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                            {{ $t('products.actions.quick_edit') }}
                                        </button>
                                        <button type="button" @click="openAdjust(product)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                            {{ $t('products.actions.adjust_stock') }}
                                        </button>
                                        <button type="button" @click="duplicateProduct(product)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                            {{ $t('products.actions.duplicate') }}
                                        </button>
                                        <button type="button" @click="toggleArchive(product)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback" data-tone="warning">
                                            {{ product.is_active ? $t('products.actions.archive') : $t('products.actions.restore') }}
                                        </button>
                                        <button type="button" :data-hs-overlay="'#hs-pro-edit' + product.id"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                            {{ $t('products.actions.edit') }}
                                        </button>
                                        <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                        <button type="button" @click="destroyProduct(product)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800 action-feedback" data-tone="danger">
                                            {{ $t('products.actions.delete') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div v-else>
                                <Link
                                    :href="route('product.show', product.id)"
                                    class="text-xs font-semibold text-green-700 hover:underline dark:text-green-400"
                                >
                                    {{ $t('products.actions.view') }}
                                </Link>
                            </div>
                        </div>
                    </td>

                    <Modal v-if="canEdit" :title="$t('products.actions.edit_product')" :id="'hs-pro-edit' + product.id">
                        <ProductForm :product="product" :categories="categories" :id="'hs-pro-edit' + product.id" />
                    </Modal>
                </tr>
                </template>
            </tbody>
        </table>
        </div>

        <div class="mt-5 flex flex-wrap justify-between items-center gap-2">
            <p class="text-sm text-stone-800 dark:text-neutral-200">
                <span class="font-medium"> {{ count }} </span>
                <span class="text-stone-500 dark:text-neutral-500"> {{ $t('products.pagination.results') }}</span>
            </p>

            <nav class="flex justify-end items-center gap-x-1" :aria-label="$t('products.pagination.label')">
                <Link :href="products.prev_page_url" v-if="products.prev_page_url">
                <button type="button"
                    class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                    :aria-label="$t('products.pagination.previous')">
                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="m15 18-6-6 6-6" />
                    </svg>
                    <span class="sr-only">{{ $t('products.pagination.previous') }}</span>
                </button>
                </Link>
                <div class="flex items-center gap-x-1">
                    <span
                        class="min-h-[38px] min-w-[38px] flex justify-center items-center bg-stone-100 text-stone-800 py-2 px-3 text-sm rounded-sm disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:text-white"
                        aria-current="page">{{ products.from }}</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">{{ $t('products.pagination.of') }}</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">{{
                            products.to }}</span>
                </div>

                <Link :href="products.next_page_url" v-if="products.next_page_url">
                <button type="button"
                    class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                    :aria-label="$t('products.pagination.next')">
                    <span class="sr-only">{{ $t('products.pagination.next') }}</span>
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

    <Modal :title="alertDetailsTitle" :id="'hs-pro-alert-details'">
        <div v-if="alertDetailsProduct" class="space-y-4">
            <div
                class="rounded-sm border border-stone-200 bg-stone-50/60 p-3 dark:border-neutral-700 dark:bg-neutral-900/40">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div class="flex items-center gap-3">
                        <img class="size-12 rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                            :src="alertDetailsProduct.image_url || alertDetailsProduct.image" :alt="$t('products.labels.product_image_alt')">
                        <div class="space-y-1">
                            <Link :href="route('product.show', alertDetailsProduct.id)"
                                class="text-sm font-semibold text-stone-800 hover:underline dark:text-neutral-100">
                                {{ alertDetailsProduct.name }}
                            </Link>
                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ alertDetailsProduct.sku || alertDetailsProduct.number || $t('products.labels.no_sku') }}
                                <span v-if="alertDetailsProduct.barcode" class="ml-2">
                                    - {{ $t('products.labels.barcode') }} {{ alertDetailsProduct.barcode }}
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-1">
                                <span
                                    class="py-1 px-2 rounded-full text-[10px] font-medium bg-white text-stone-700 border border-stone-200 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                                    {{ getTrackingLabel(alertDetailsProduct) }}
                                </span>
                                <span v-if="alertDetailsProduct.unit"
                                    class="py-1 px-2 rounded-full text-[10px] font-medium bg-white text-stone-700 border border-stone-200 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                                    {{ $t('products.labels.unit') }} {{ alertDetailsProduct.unit }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs text-stone-500 dark:text-neutral-400">
                        <span class="font-semibold text-stone-700 dark:text-neutral-200">
                            {{ $t('products.labels.available') }} {{ formatNumber(getAvailableStock(alertDetailsProduct)) }}
                        </span>
                        <span>{{ $t('products.labels.reserved') }} {{ formatNumber(getReservedStock(alertDetailsProduct)) }}</span>
                        <span>{{ $t('products.labels.damaged') }} {{ formatNumber(getDamagedStock(alertDetailsProduct)) }}</span>
                        <span>{{ $t('products.labels.minimum_short') }} {{ formatNumber(alertDetailsProduct.minimum_stock) }}</span>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <button v-for="alert in getAlertBadges(alertDetailsProduct)" :key="alert.key" type="button"
                    class="py-1 px-2 rounded-full text-[10px] font-medium transition focus:outline-none focus:ring-2 focus:ring-green-500"
                    :class="[
                        alertBadgeClasses[alert.tone],
                        alert.key === alertDetailsType ? 'ring-1 ring-green-500 shadow-sm' : 'hover:shadow-sm'
                    ]"
                    @click="alertDetailsType = alert.key">
                    {{ alert.label }}
                </button>
            </div>

            <div class="rounded-sm border border-stone-200 p-4 dark:border-neutral-700">
                <template v-if="alertDetailsType === 'out' || alertDetailsType === 'low'">
                    <div class="grid gap-3 md:grid-cols-4">
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('products.labels.available') }}</div>
                            <div class="text-lg font-semibold text-stone-800 dark:text-neutral-200">
                                {{ formatNumber(getAvailableStock(alertDetailsProduct)) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('products.labels.minimum') }}</div>
                            <div class="text-lg font-semibold text-stone-800 dark:text-neutral-200">
                                {{ formatNumber(alertDetailsProduct.minimum_stock) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('products.labels.reserved') }}</div>
                            <div class="text-lg font-semibold text-stone-800 dark:text-neutral-200">
                                {{ formatNumber(getReservedStock(alertDetailsProduct)) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('products.labels.damaged') }}</div>
                            <div class="text-lg font-semibold text-stone-800 dark:text-neutral-200">
                                {{ formatNumber(getDamagedStock(alertDetailsProduct)) }}
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                        <span v-if="getAvailableStock(alertDetailsProduct) <= 0" class="text-red-600 dark:text-red-400">
                            {{ $t('products.alerts.stock_depleted') }}
                        </span>
                        <span v-else-if="getAvailableStock(alertDetailsProduct) <= alertDetailsProduct.minimum_stock"
                            class="text-amber-600 dark:text-amber-400">
                            {{ $t('products.alerts.stock_below_min') }}
                        </span>
                        <span v-if="getNextExpiry(alertDetailsProduct)">
                            {{ $t('products.labels.next_expiry') }} {{ formatDate(getNextExpiry(alertDetailsProduct)) }}
                        </span>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button v-if="canEdit" type="button"
                            class="inline-flex items-center rounded-sm border border-green-600 bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700"
                            @click="openAdjust(alertDetailsProduct)">
                            {{ $t('products.actions.adjust_stock') }}
                        </button>
                        <Link :href="route('product.show', alertDetailsProduct.id)"
                            class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ $t('products.actions.view_product') }}
                        </Link>
                    </div>
                </template>

                <template v-else-if="alertDetailsType === 'damaged'">
                    <div class="space-y-4">
                        <div>
                            <div class="text-sm font-medium text-stone-700 dark:text-neutral-200">{{ $t('products.alerts.damaged_stock') }}</div>
                            <div v-if="!getDamagedInventories(alertDetailsProduct).length"
                                class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('products.alerts.no_damaged_stock') }}
                            </div>
                            <div v-else class="space-y-2 pt-2">
                                <div v-for="inventory in getDamagedInventories(alertDetailsProduct)" :key="inventory.id"
                                    class="flex items-center justify-between rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700">
                                    <div>
                                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                            {{ inventory.warehouse?.name || $t('products.labels.warehouse') }}
                                        </div>
                                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                                            {{ $t('products.labels.damaged') }} {{ formatNumber(inventory.damaged) }}
                                        </div>
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('products.labels.on_hand') }} {{ formatNumber(inventory.on_hand) }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-stone-700 dark:text-neutral-200">{{ $t('products.alerts.recent_damage_movements') }}</div>
                            <div v-if="!getDamageMovements(alertDetailsProduct).length"
                                class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('products.alerts.no_damage_movements') }}
                            </div>
                            <div v-else class="space-y-2 pt-2">
                                <div v-for="movement in getDamageMovements(alertDetailsProduct)" :key="movement.id"
                                    class="flex items-center justify-between rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700">
                                    <div>
                                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                            {{ movement.type }}
                                            <span v-if="movement.warehouse"> - {{ movement.warehouse.name }}</span>
                                            <span v-if="movement.lot?.lot_number"> - {{ $t('products.lots.lot_short', { number: movement.lot.lot_number }) }}</span>
                                            <span v-else-if="movement.lot?.serial_number"> - {{ $t('products.lots.serial_short', { number: movement.lot.serial_number }) }}</span>
                                        </div>
                                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                                            {{ movement.reason || movement.note || $t('products.labels.no_note') }}
                                        </div>
                                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ formatDate(movement.created_at) }}
                                        </div>
                                    </div>
                                    <div class="text-sm font-medium text-red-600">
                                        {{ Math.abs(movement.quantity || 0) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <template v-else-if="alertDetailsType === 'reserved'">
                    <div class="space-y-3">
                        <div v-if="!getReservedOrders(alertDetailsProduct).length"
                            class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('products.alerts.no_reserved_orders') }}
                        </div>
                        <div v-else class="space-y-2">
                            <div v-for="order in getReservedOrders(alertDetailsProduct)" :key="order.id"
                                class="rounded-sm border border-stone-200 px-3 py-3 text-sm dark:border-neutral-700">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <Link :href="route('sales.show', order.id)"
                                        class="text-sm font-semibold text-green-700 hover:underline dark:text-green-400">
                                        {{ $t('products.orders.order_label', { number: order.number || `#${order.id}` }) }}
                                    </Link>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('products.labels.quantity') }} {{ formatNumber(order.quantity) }}
                                    </div>
                                </div>
                                <div class="pt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ order.customer_name || $t('products.orders.customer_fallback') }}
                                </div>
                                <div class="flex flex-wrap gap-2 pt-2 text-xs text-stone-500 dark:text-neutral-400">
                                    <span>{{ $t('products.labels.status') }} {{ formatOrderStatus(order.status, orderStatusLabels.value) }}</span>
                                    <span v-if="order.fulfillment_status">
                                        {{ $t('products.orders.fulfillment_label') }} {{ formatOrderStatus(order.fulfillment_status, fulfillmentStatusLabels.value) }}
                                    </span>
                                    <span v-if="order.fulfillment_method">
                                        {{ $t('products.orders.method') }} {{ order.fulfillment_method.replace('_', ' ') }}
                                    </span>
                                </div>
                                <div class="flex flex-wrap gap-2 pt-2 text-xs text-stone-500 dark:text-neutral-400">
                                    <span v-if="order.scheduled_for">{{ $t('products.orders.scheduled') }} {{ formatDate(order.scheduled_for) }}</span>
                                    <span>{{ $t('products.labels.created') }} {{ formatDate(order.created_at) }}</span>
                                </div>
                                <div v-if="order.notes || order.delivery_notes || order.pickup_notes"
                                    class="pt-2 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ order.notes || order.delivery_notes || order.pickup_notes }}
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <template v-else-if="alertDetailsType === 'expired' || alertDetailsType === 'expiring'">
                    <div class="space-y-3">
                        <div v-if="!getAlertLots(alertDetailsProduct, alertDetailsType).length"
                            class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('products.alerts.no_lots_for_alert') }}
                        </div>
                        <div v-else class="space-y-2">
                            <div v-for="lot in getAlertLots(alertDetailsProduct, alertDetailsType)" :key="lot.id"
                                class="flex flex-wrap items-center justify-between gap-2 rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700">
                                <div>
                                    <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                        {{ getLotLabel(lot) }}
                                        <span v-if="lot.warehouse"> - {{ lot.warehouse.name }}</span>
                                    </div>
                                    <div class="text-sm text-stone-700 dark:text-neutral-200">
                                        {{ $t('products.labels.quantity') }} {{ formatNumber(lot.quantity) }}
                                    </div>
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('products.labels.expires') }} {{ formatDate(lot.expires_at) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <template v-else>
                    <div class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('products.alerts.select_alert') }}
                    </div>
                </template>
            </div>
        </div>
        <div v-else class="text-sm text-stone-500 dark:text-neutral-400">
            {{ $t('products.alerts.select_alert_badge') }}
        </div>
    </Modal>

    <Modal v-if="canEdit" :title="$t('products.actions.add_product')" :id="'hs-pro-dasadpm'">
        <ProductForm :product="product" :categories="categories" :id="'hs-pro-dasadpm'" />
    </Modal>

    <Modal v-if="canEdit" :title="$t('products.import.title')" :id="'hs-pro-import'">
        <form @submit.prevent="submitImport" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-stone-700 dark:text-neutral-300">{{ $t('products.import.csv_file') }}</label>
                <input type="file" accept=".csv,text/csv" @change="importForm.file = $event.target.files[0]"
                    class="mt-2 block w-full text-sm text-stone-600 file:me-4 file:py-2 file:px-4 file:rounded-sm file:border-0 file:text-sm file:font-medium file:bg-stone-100 file:text-stone-700 hover:file:bg-stone-200 dark:text-neutral-400 dark:file:bg-neutral-700 dark:file:text-neutral-200 dark:hover:file:bg-neutral-600">
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" data-hs-overlay="#hs-pro-import"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 action-feedback">
                    {{ $t('products.actions.cancel') }}
                </button>
                <button type="submit"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 action-feedback">
                    {{ $t('products.actions.import') }}
                </button>
            </div>
        </form>
    </Modal>

    <Modal v-if="canEdit" :title="$t('products.adjust.title')" :id="'hs-pro-stock-adjust'">
        <div v-if="activeProduct" class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('products.adjust.product') }}</div>
                    <div class="text-sm font-medium text-stone-800 dark:text-neutral-200">{{ activeProduct.name }}</div>
                </div>
                <div class="text-sm text-stone-500 dark:text-neutral-400">
                    <span class="font-medium text-stone-800 dark:text-neutral-200">
                        {{ $t('products.labels.available') }} {{ formatNumber(getAvailableStock(activeProduct)) }}
                    </span>
                    <span class="ml-2 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('products.labels.reserved') }} {{ formatNumber(getReservedStock(activeProduct)) }}
                    </span>
                    <span class="ml-2 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('products.labels.damaged') }} {{ formatNumber(getDamagedStock(activeProduct)) }}
                    </span>
                </div>
            </div>

            <div v-if="getAvailableStock(activeProduct) <= activeProduct.minimum_stock" class="text-xs text-amber-600">
                {{ $t('products.adjust.low_stock_alert', { min: activeProduct.minimum_stock }) }}
            </div>

            <form @submit.prevent="submitAdjust" class="space-y-3">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <select v-model="adjustForm.type"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="in">{{ $t('products.adjust.types.in') }}</option>
                        <option value="out">{{ $t('products.adjust.types.out') }}</option>
                        <option value="adjust">{{ $t('products.adjust.types.adjust') }}</option>
                        <option value="damage">{{ $t('products.adjust.types.damage') }}</option>
                        <option value="spoilage">{{ $t('products.adjust.types.spoilage') }}</option>
                    </select>
                    <input type="number" step="1" v-model="adjustForm.quantity"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        :placeholder="adjustForm.type === 'adjust' ? $t('products.adjust.quantity_change') : $t('products.labels.quantity')">
                    <select v-model="adjustForm.warehouse_id"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 disabled:opacity-60 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        :disabled="!activeWarehouses.length">
                        <option value="">{{ $t('products.adjust.select_warehouse') }}</option>
                        <option v-for="warehouse in activeWarehouses" :key="warehouse.id" :value="warehouse.id">
                            {{ warehouse.name }}{{ warehouse.is_default ? ' (' + $t('products.filters.warehouse.default') + ')' : '' }}
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
                        :placeholder="$t('products.adjust.unit_cost_optional')">
                    <input type="text" v-model="adjustForm.note"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        :placeholder="$t('products.adjust.note_optional')">
                </div>
                <div v-if="activeProduct.tracking_type === 'lot'" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <input type="text" v-model="adjustForm.lot_number"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        :placeholder="$t('products.adjust.lot_number')">
                    <input type="date" v-model="adjustForm.expires_at"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        :placeholder="$t('products.labels.expires')">
                    <input type="date" v-model="adjustForm.received_at"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        :placeholder="$t('products.labels.received')">
                </div>
                <div v-else-if="activeProduct.tracking_type === 'serial'" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <input type="text" v-model="adjustForm.serial_number"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        :placeholder="$t('products.adjust.serial_number')">
                    <div class="md:col-span-2 text-xs text-stone-500 dark:text-neutral-400 flex items-center">
                        {{ $t('products.adjust.serial_note') }}
                    </div>
                </div>
                <div v-if="!activeWarehouses.length" class="text-xs text-amber-600">
                    {{ $t('products.adjust.no_warehouse_hint') }}
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" data-hs-overlay="#hs-pro-stock-adjust"
                        class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 action-feedback">
                        {{ $t('products.actions.cancel') }}
                    </button>
                    <button type="submit"
                        class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 action-feedback">
                        {{ $t('products.actions.save') }}
                    </button>
                </div>
            </form>

            <div class="space-y-2">
                <div class="text-sm font-medium text-stone-700 dark:text-neutral-300">{{ $t('products.adjust.recent_movements') }}</div>
                <div v-if="!activeProduct.stock_movements || !activeProduct.stock_movements.length"
                    class="text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('products.adjust.no_movements') }}
                </div>
                <div v-else class="space-y-2">
                    <div v-for="movement in activeProduct.stock_movements" :key="movement.id"
                        class="flex items-center justify-between rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700">
                        <div>
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                {{ movement.type }}
                                <span v-if="movement.warehouse"> - {{ movement.warehouse.name }}</span>
                                <span v-if="movement.lot?.lot_number"> - {{ $t('products.lots.lot_short', { number: movement.lot.lot_number }) }}</span>
                                <span v-else-if="movement.lot?.serial_number"> - {{ $t('products.lots.serial_short', { number: movement.lot.serial_number }) }}</span>
                                - {{ formatDate(movement.created_at) }}
                            </div>
                            <div class="text-sm text-stone-700 dark:text-neutral-300">
                                {{ movement.reason || movement.note || $t('products.labels.no_note') }}
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
            {{ $t('products.adjust.select_product') }}
        </div>
    </Modal>
</template>
