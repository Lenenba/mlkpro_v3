<script setup>
import {
    computed,
    ref,
    watch,
} from 'vue';
import axios from 'axios';
import { Link, router, useForm } from '@inertiajs/vue3';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminPaginationLinks from '@/Components/DataTable/AdminPaginationLinks.vue';
import AdminDataTableBulkBar from '@/Components/DataTable/AdminDataTableBulkBar.vue';
import AdminDataTableBulkActionMenu from '@/Components/DataTable/AdminDataTableBulkActionMenu.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import SavedSegmentBar from '@/Components/CRM/SavedSegmentBar.vue';
import CustomerActionsMenu from '@/Pages/Customer/UI/CustomerActionsMenu.vue';
import CustomerBulkContactModal from '@/Pages/Customer/UI/CustomerBulkContactModal.vue';
import CustomerEmptyState from '@/Pages/Customer/UI/CustomerEmptyState.vue';
import { humanizeDate } from '@/utils/date';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import { useDataTableSelection } from '@/Composables/useDataTableSelection';
import Checkbox from '@/Components/Checkbox.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { crmButtonClass, crmSegmentedControlButtonClass, crmSegmentedControlClass } from '@/utils/crmButtonStyles';
import { useI18n } from 'vue-i18n';
import {
    createBulkActionFailureResult,
    dispatchBulkActionToast,
    extractBulkActionErrorMessages,
    normalizeBulkActionResult,
    resolveBulkActionErrorMessage,
} from '@/utils/bulkActions';
import { useAccountFeatures } from '@/Composables/useAccountFeatures';

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
    bulkActions: {
        type: Object,
        default: () => ({}),
    },
    canEdit: {
        type: Boolean,
        default: false,
    },
    savedSegments: {
        type: Array,
        default: () => [],
    },
    canManageSavedSegments: {
        type: Boolean,
        default: false,
    },
});

const { t } = useI18n();
const { hasFeature } = useAccountFeatures();

const canEdit = computed(() => Boolean(props.canEdit));
const campaignsFeatureEnabled = computed(() => {
    const capability = props.bulkActions?.capabilities?.contact_enabled;

    if (capability !== undefined) {
        return Boolean(capability);
    }

    return canEdit.value && hasFeature('campaigns');
});

const filterForm = useForm({
    name: props.filters?.name ?? '',
    city: props.filters?.city ?? '',
    country: props.filters?.country ?? '',
    has_quotes: props.filters?.has_quotes ?? '',
    has_works: props.filters?.has_works ?? '',
    status: props.filters?.status ?? '',
    created_from: props.filters?.created_from ?? '',
    created_to: props.filters?.created_to ?? '',
    has_active_package: props.filters?.has_active_package ?? '',
    package_status: props.filters?.package_status ?? '',
    package_remaining_lte: props.filters?.package_remaining_lte ?? '',
    package_expires_within_days: props.filters?.package_expires_within_days ?? '',
    package_is_recurring: props.filters?.package_is_recurring ?? '',
    package_recurrence_status: props.filters?.package_recurrence_status ?? '',
    sort: props.filters?.sort ?? 'created_at',
    direction: props.filters?.direction ?? 'desc',
});

const showAdvanced = ref(false);
const isLoading = ref(false);
const compactObject = (payload) => Object.fromEntries(
    Object.entries(payload || {}).filter(([, value]) => value !== '' && value !== null && value !== undefined)
);
const segmentFilterValue = (value) => (value === null || value === undefined ? '' : String(value));
const quoteFilterOptions = computed(() => ([
    { value: '', label: t('customers.filters.quotes') },
    { value: '1', label: t('customers.filters.with_quotes') },
    { value: '0', label: t('customers.filters.no_quotes') },
]));
const jobFilterOptions = computed(() => ([
    { value: '', label: t('customers.filters.jobs') },
    { value: '1', label: t('customers.filters.with_jobs') },
    { value: '0', label: t('customers.filters.no_jobs') },
]));
const statusFilterOptions = computed(() => ([
    { value: '', label: t('customers.filters.status') },
    { value: 'active', label: t('customers.status.active') },
    { value: 'archived', label: t('customers.status.archived') },
]));
const packagePresenceOptions = computed(() => ([
    { value: '', label: t('customers.filters.active_package') },
    { value: '1', label: t('customers.filters.with_active_package') },
    { value: '0', label: t('customers.filters.no_active_package') },
]));
const packageStatusOptions = computed(() => ([
    { value: '', label: t('customers.filters.package_status') },
    { value: 'active', label: t('customers.details.customer_packages.statuses.active') },
    { value: 'consumed', label: t('customers.details.customer_packages.statuses.consumed') },
    { value: 'expired', label: t('customers.details.customer_packages.statuses.expired') },
    { value: 'cancelled', label: t('customers.details.customer_packages.statuses.cancelled') },
]));
const packageRecurringOptions = computed(() => ([
    { value: '', label: t('customers.filters.package_recurrence') },
    { value: '1', label: t('customers.filters.package_recurring') },
    { value: '0', label: t('customers.filters.package_non_recurring') },
]));
const packageRecurrenceStatusOptions = computed(() => ([
    { value: '', label: t('customers.filters.package_recurrence_status') },
    { value: 'active', label: t('customers.details.customer_packages.recurrence_statuses.active') },
    { value: 'payment_due', label: t('customers.details.customer_packages.recurrence_statuses.payment_due') },
    { value: 'suspended', label: t('customers.details.customer_packages.recurrence_statuses.suspended') },
    { value: 'cancelled', label: t('customers.details.customer_packages.recurrence_statuses.cancelled') },
]));
const isViewSwitching = ref(false);
const allowedViews = ['table', 'cards'];
const viewMode = ref('table');
const isBusy = computed(() => isLoading.value || isViewSwitching.value);
const shouldShowSavedSegments = computed(() =>
    Boolean(props.canManageSavedSegments) || (Array.isArray(props.savedSegments) && props.savedSegments.length > 0)
);
const savedSegmentFilters = computed(() => compactObject({
    city: filterForm.city,
    country: filterForm.country,
    has_quotes: filterForm.has_quotes,
    has_works: filterForm.has_works,
    status: filterForm.status,
    created_from: filterForm.created_from,
    created_to: filterForm.created_to,
    has_active_package: filterForm.has_active_package,
    package_status: filterForm.package_status,
    package_remaining_lte: filterForm.package_remaining_lte,
    package_expires_within_days: filterForm.package_expires_within_days,
    package_is_recurring: filterForm.package_is_recurring,
    package_recurrence_status: filterForm.package_recurrence_status,
}));
const savedSegmentSort = computed(() => compactObject({
    sort: filterForm.sort,
    direction: filterForm.direction,
}));
const savedSegmentSearchTerm = computed(() => String(filterForm.name || '').trim());
let viewSwitchTimeout;

if (typeof window !== 'undefined') {
    const storedView = window.localStorage.getItem('customer_view_mode');
    if (allowedViews.includes(storedView)) {
        viewMode.value = storedView;
    }
}

const setViewMode = (mode) => {
    if (!allowedViews.includes(mode) || viewMode.value === mode) {
        return;
    }
    viewMode.value = mode;
    if (typeof window !== 'undefined') {
        window.localStorage.setItem('customer_view_mode', mode);
    }
    isViewSwitching.value = true;
    if (viewSwitchTimeout) {
        clearTimeout(viewSwitchTimeout);
    }
    viewSwitchTimeout = setTimeout(() => {
        isViewSwitching.value = false;
    }, 220);
};

const filterPayload = () => {
    const payload = {
        name: filterForm.name,
        city: filterForm.city,
        country: filterForm.country,
        has_quotes: filterForm.has_quotes,
        has_works: filterForm.has_works,
        status: filterForm.status,
        created_from: filterForm.created_from,
        created_to: filterForm.created_to,
        has_active_package: filterForm.has_active_package,
        package_status: filterForm.package_status,
        package_remaining_lte: filterForm.package_remaining_lte,
        package_expires_within_days: filterForm.package_expires_within_days,
        package_is_recurring: filterForm.package_is_recurring,
        package_recurrence_status: filterForm.package_recurrence_status,
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
        router.get(route('customer.index'), filterPayload(), {
            only: ['customers', 'filters', 'stats', 'count', 'topCustomers'],
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

watch(() => [
    filterForm.city,
    filterForm.country,
    filterForm.has_quotes,
    filterForm.has_works,
    filterForm.status,
    filterForm.created_from,
    filterForm.created_to,
    filterForm.has_active_package,
    filterForm.package_status,
    filterForm.package_remaining_lte,
    filterForm.package_expires_within_days,
    filterForm.package_is_recurring,
    filterForm.package_recurrence_status,
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
    filterForm.status = '';
    filterForm.created_from = '';
    filterForm.created_to = '';
    filterForm.has_active_package = '';
    filterForm.package_status = '';
    filterForm.package_remaining_lte = '';
    filterForm.package_expires_within_days = '';
    filterForm.package_is_recurring = '';
    filterForm.package_recurrence_status = '';
    filterForm.sort = 'created_at';
    filterForm.direction = 'desc';
    autoFilter();
};

const applySavedSegment = (segment) => {
    const filters = segment?.filters && typeof segment.filters === 'object' ? segment.filters : {};
    const sort = segment?.sort && typeof segment.sort === 'object' ? segment.sort : {};

    filterForm.name = String(segment?.search_term || '');
    filterForm.city = segmentFilterValue(filters.city);
    filterForm.country = segmentFilterValue(filters.country);
    filterForm.has_quotes = segmentFilterValue(filters.has_quotes);
    filterForm.has_works = segmentFilterValue(filters.has_works);
    filterForm.status = segmentFilterValue(filters.status);
    filterForm.created_from = segmentFilterValue(filters.created_from);
    filterForm.created_to = segmentFilterValue(filters.created_to);
    filterForm.has_active_package = segmentFilterValue(filters.has_active_package);
    filterForm.package_status = segmentFilterValue(filters.package_status);
    filterForm.package_remaining_lte = segmentFilterValue(filters.package_remaining_lte);
    filterForm.package_expires_within_days = segmentFilterValue(filters.package_expires_within_days);
    filterForm.package_is_recurring = segmentFilterValue(filters.package_is_recurring);
    filterForm.package_recurrence_status = segmentFilterValue(filters.package_recurrence_status);
    filterForm.sort = segmentFilterValue(sort.sort) || 'created_at';
    filterForm.direction = segmentFilterValue(sort.direction) || 'desc';
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

const customerRows = computed(() => (Array.isArray(props.customers?.data) ? props.customers.data : []));
const customerTableRows = computed(() => (isBusy.value
    ? Array.from({ length: 6 }, (_, index) => ({ id: `customer-skeleton-${index}`, __skeleton: true }))
    : customerRows.value));
const {
    selected,
    selectedCount,
    selectAllRef,
    allSelected,
    toggleAll,
    toggleSelection,
    clearSelection,
    isSelected,
} = useDataTableSelection(customerRows);
const bulkContactModalRef = ref(null);
const bulkResult = ref(null);
const bulkProcessing = ref(false);
const fallbackBulkActions = computed(() => ([
    campaignsFeatureEnabled.value ? {
        key: 'contact_selected',
        kind: 'client',
        client_handler: 'openBulkContact',
        label_key: 'customers.bulk_contact.action',
        tone: 'info',
    } : null,
    {
        key: 'portal_enable',
        kind: 'submit',
        action: 'portal_enable',
        label_key: 'customers.bulk.enable_portal',
        tone: 'success',
        divider_before: true,
    },
    {
        key: 'portal_disable',
        kind: 'submit',
        action: 'portal_disable',
        label_key: 'customers.bulk.disable_portal',
        tone: 'warning',
    },
    {
        key: 'archive',
        kind: 'submit',
        action: 'archive',
        label_key: 'customers.actions.archive',
        tone: 'neutral',
    },
    {
        key: 'restore',
        kind: 'submit',
        action: 'restore',
        label_key: 'customers.actions.restore',
        tone: 'success',
    },
    {
        key: 'delete',
        kind: 'submit',
        action: 'delete',
        label_key: 'customers.actions.delete',
        tone: 'danger',
        divider_before: true,
        confirm_key: 'customers.bulk.delete_confirm',
    },
].filter(Boolean)));

const bulkMenuLabelKey = computed(() => props.bulkActions?.menu_label_key || 'customers.bulk.title');
const bulkSelectionLabelKey = computed(() => props.bulkActions?.selection_label_key || 'customers.labels.selected');
const bulkMenuActions = computed(() => (
    Array.isArray(props.bulkActions?.actions) && props.bulkActions.actions.length
        ? props.bulkActions.actions
        : fallbackBulkActions.value
));

const clearBulkResult = () => {
    bulkResult.value = null;
};

const setBulkResult = (payload) => {
    bulkResult.value = normalizeBulkActionResult(payload);

    return bulkResult.value;
};

watch(selectedCount, (count, previousCount) => {
    if (count > 0 && count !== previousCount) {
        clearBulkResult();
    }
});

const reloadBulkContext = () => new Promise((resolve) => {
    router.reload({
        only: ['customers', 'filters', 'stats', 'count', 'topCustomers'],
        preserveScroll: true,
        preserveState: true,
        onFinish: () => resolve(),
    });
});

const runBulk = async (action, confirmKey = null) => {
    if (!selected.value.length || bulkProcessing.value) {
        return;
    }
    if (confirmKey && !confirm(t(confirmKey))) {
        return;
    }

    clearBulkResult();
    bulkProcessing.value = true;

    try {
        const { data } = await axios.post(route('customer.bulk'), {
            action,
            ids: selected.value,
        }, {
            headers: {
                Accept: 'application/json',
            },
        });

        const result = setBulkResult(data);
        clearSelection();
        dispatchBulkActionToast(result, t);
        await reloadBulkContext();
    } catch (error) {
        const errors = extractBulkActionErrorMessages(error);
        const message = resolveBulkActionErrorMessage(error, t);
        const result = createBulkActionFailureResult({
            message,
            errors: errors.length ? errors : [message],
            selectedCount: selected.value.length,
        });

        bulkResult.value = result;
        dispatchBulkActionToast(result, t);
    } finally {
        bulkProcessing.value = false;
    }
};

const openBulkContact = () => {
    if (!campaignsFeatureEnabled.value) {
        return;
    }

    clearBulkResult();
    bulkContactModalRef.value?.open();
};

const handleBulkAction = (definition) => {
    if (!definition || typeof definition !== 'object') {
        return;
    }

    if (definition.kind === 'client' && definition.client_handler === 'openBulkContact') {
        openBulkContact();

        return;
    }

    runBulk(
        String(definition.action || definition.key || ''),
        definition.confirm_key || null
    );
};

const toggleArchive = (customer) => {
    if (!customer) {
        return;
    }
    const actionLabel = customer.is_active ? t('customers.actions.archive') : t('customers.actions.restore');
    const name = customer.company_name || `${customer.first_name} ${customer.last_name}`.trim() || t('customers.labels.customer_fallback');
    if (!confirm(t('customers.actions.archive_confirm', { action: actionLabel, name }))) {
        return;
    }
    const action = customer.is_active ? 'archive' : 'restore';
    router.post(route('customer.bulk'), { action, ids: [customer.id] }, { preserveScroll: true });
};

const destroyCustomer = (customer) => {
    const label = customer.company_name || `${customer.first_name} ${customer.last_name}`;
    if (!confirm(t('customers.actions.delete_confirm', { name: label }))) {
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

const hasCustomerLogo = (customer) => Boolean(customer?.logo_url || customer?.logo);

const getCustomerInitials = (customer) => {
    const name = customer?.company_name
        || `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim();
    if (!name) {
        return t('customers.labels.customer_initial');
    }
    const parts = name.split(' ').filter(Boolean);
    const first = parts[0]?.[0] || '';
    const second = parts[1]?.[0] || '';
    return `${first}${second}`.toUpperCase();
};

const customerLinks = computed(() => props.customers?.links || []);
const currentPerPage = computed(() => resolveDataTablePerPage(props.customers?.per_page, props.filters?.per_page));
const customerResultsLabel = computed(() => `${props.count} ${t('customers.pagination.results')}`);
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
        <div class="space-y-3">
            <SavedSegmentBar
                v-if="shouldShowSavedSegments"
                module="customer"
                :segments="savedSegments"
                :can-manage="canManageSavedSegments"
                :current-filters="savedSegmentFilters"
                :current-sort="savedSegmentSort"
                :current-search-term="savedSegmentSearchTerm"
                :history-href="route('crm.playbook-runs.index', { module: 'customer' })"
                :history-label="t('marketing.playbook_runs.actions.open_history')"
                i18n-prefix="customers"
                @apply="applySavedSegment"
            />
            <AdminDataTableToolbar
                :show-filters="showAdvanced"
                :show-apply="false"
                :busy="isBusy"
                :filters-label="$t('customers.actions.filters')"
                :clear-label="$t('customers.actions.clear')"
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
                        <input type="text" v-model="filterForm.name" data-testid="demo-customer-search"
                            class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                            :placeholder="$t('customers.filters.search_placeholder')">
                    </div>
                </template>

                <template #filters>
                    <input type="text" v-model="filterForm.city"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        :placeholder="$t('customers.filters.city')">
                    <input type="text" v-model="filterForm.country"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        :placeholder="$t('customers.filters.country')">
                    <FloatingSelect
                        v-model="filterForm.has_quotes"
                        :label="$t('customers.filters.quotes')"
                        :options="quoteFilterOptions"
                        dense
                    />
                    <FloatingSelect
                        v-model="filterForm.has_works"
                        :label="$t('customers.filters.jobs')"
                        :options="jobFilterOptions"
                        dense
                    />
                    <FloatingSelect
                        v-model="filterForm.status"
                        :label="$t('customers.filters.status')"
                        :options="statusFilterOptions"
                        dense
                    />
                    <FloatingSelect
                        v-model="filterForm.has_active_package"
                        :label="$t('customers.filters.active_package')"
                        :options="packagePresenceOptions"
                        dense
                    />
                    <FloatingSelect
                        v-model="filterForm.package_status"
                        :label="$t('customers.filters.package_status')"
                        :options="packageStatusOptions"
                        dense
                    />
                    <input type="number" min="0" step="1" v-model="filterForm.package_remaining_lte"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        :placeholder="$t('customers.filters.package_remaining_lte')">
                    <input type="number" min="0" step="1" v-model="filterForm.package_expires_within_days"
                        class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                        :placeholder="$t('customers.filters.package_expires_within_days')">
                    <FloatingSelect
                        v-model="filterForm.package_is_recurring"
                        :label="$t('customers.filters.package_recurrence')"
                        :options="packageRecurringOptions"
                        dense
                    />
                    <FloatingSelect
                        v-model="filterForm.package_recurrence_status"
                        :label="$t('customers.filters.package_recurrence_status')"
                        :options="packageRecurrenceStatusOptions"
                        dense
                    />
                    <DatePicker v-model="filterForm.created_from" :label="$t('customers.filters.created_from')" />
                    <DatePicker v-model="filterForm.created_to" :label="$t('customers.filters.created_to')" />
                </template>

                <template #actions>
                    <div :class="crmSegmentedControlClass()">
                        <button
                            type="button"
                            @click="setViewMode('table')"
                            :class="crmSegmentedControlButtonClass(viewMode === 'table')"
                        >
                            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 3h18v6H3z" />
                                <path d="M3 13h18v8H3z" />
                            </svg>
                            {{ $t('customers.view.table') }}
                        </button>
                        <button
                            type="button"
                            @click="setViewMode('cards')"
                            :class="crmSegmentedControlButtonClass(viewMode === 'cards')"
                        >
                            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="7" height="7" rx="1" />
                                <rect x="14" y="3" width="7" height="7" rx="1" />
                                <rect x="3" y="14" width="7" height="7" rx="1" />
                                <rect x="14" y="14" width="7" height="7" rx="1" />
                            </svg>
                            {{ $t('customers.view.cards') }}
                        </button>
                    </div>
                    <Link :href="route('customer.create')" data-testid="demo-add-customer"
                        :class="crmButtonClass('primary', 'toolbar')">
                        <svg class="hidden sm:block shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                            height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14" />
                            <path d="M12 5v14" />
                        </svg>
                        {{ $t('customers.actions.add_customer') }}
                    </Link>
                </template>
            </AdminDataTableToolbar>

            <AdminDataTableBulkBar
                v-if="canEdit"
                :count="selectedCount"
                :label="$t(bulkSelectionLabelKey, { count: selectedCount })"
                :result="bulkResult"
            >
                <template #summary>
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="inline-flex size-9 shrink-0 items-center justify-center rounded-sm bg-emerald-600 text-sm font-bold text-white shadow-sm dark:bg-emerald-500">
                            {{ selectedCount }}
                        </div>
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t(bulkMenuLabelKey) }}
                            </div>
                            <div class="text-xs font-medium text-stone-500 dark:text-neutral-400">
                                {{ $t(bulkSelectionLabelKey, { count: selectedCount }) }}
                            </div>
                        </div>
                    </div>
                </template>

                <button
                    type="button"
                    class="inline-flex items-center gap-x-1.5 rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 shadow-sm hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    :disabled="bulkProcessing"
                    @click="clearSelection"
                >
                    {{ $t('customers.actions.clear') }}
                </button>

                <AdminDataTableBulkActionMenu
                    :actions="bulkMenuActions"
                    :disabled="bulkProcessing || !selectedCount"
                    :menu-label-key="bulkMenuLabelKey"
                    button-variant="primary"
                    @select="handleBulkAction"
                />
            </AdminDataTableBulkBar>
        </div>

        <AdminDataTable
            v-if="viewMode === 'table'"
            embedded
            :rows="customerTableRows"
            :links="customerLinks"
            :show-pagination="customerRows.length > 0"
            show-per-page
            :per-page="currentPerPage"
        >
            <template #empty>
                <div class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-10 text-center text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                    <CustomerEmptyState />
                </div>
            </template>

            <template #head>
                <tr>
                            <th scope="col" class="w-10 px-4 py-2">
                                <input v-if="canEdit" ref="selectAllRef" type="checkbox" :checked="allSelected" @change="toggleAll"
                                    class="rounded border-stone-300 text-green-600 shadow-sm focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-green-400 dark:focus:ring-green-400" />
                            </th>
                            <th scope="col" class="min-w-[240px]">
                                <button type="button" @click="toggleSort('company_name')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    {{ $t('customers.table.company') }}
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
                                    {{ $t('customers.table.contact') }}
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
                                    {{ $t('customers.table.phone') }}
                                </div>
                            </th>
                            <th scope="col" class="min-w-36">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    {{ $t('customers.table.city') }}
                                </div>
                            </th>
                            <th scope="col" class="min-w-28">
                                <button type="button" @click="toggleSort('quotes_count')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    {{ $t('customers.table.quotes') }}
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
                                    {{ $t('customers.table.jobs') }}
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
                                    {{ $t('customers.table.created') }}
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
            </template>

            <template #row="{ row: customer }">
                <tr v-if="customer.__skeleton">
                    <td colspan="9" class="px-4 py-3">
                        <div class="grid grid-cols-7 gap-4 animate-pulse">
                            <div class="h-3 w-32 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-28 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                        </div>
                    </td>
                </tr>
                <tr v-else>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <Checkbox
                                    v-if="canEdit"
                                    :checked="isSelected(customer)"
                                    @update:checked="toggleSelection(customer.id, $event)"
                                />
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-start">
                                <Link :href="route('customer.show', customer)">
                                    <div class="w-full flex items-center gap-x-3">
                                        <img class="shrink-0 size-10 rounded-sm" :src="customer.logo_url || customer.logo"
                                            :alt="$t('customers.labels.logo_alt')" loading="lazy" decoding="async">
                                        <div class="flex flex-col">
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                                    {{ customer.company_name || `${customer.first_name} ${customer.last_name}` }}
                                                </span>
                                                <span v-if="!customer.is_active"
                                                    class="inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-semibold text-stone-600 dark:bg-neutral-700 dark:text-neutral-300">
                                                    {{ $t('customers.status.archived') }}
                                                </span>
                                            </div>
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
                                <CustomerActionsMenu
                                    :customer="customer"
                                    :can-edit="canEdit"
                                    @toggle-archive="toggleArchive(customer)"
                                    @delete="destroyCustomer(customer)"
                                />
                            </td>
                        </tr>
            </template>

            <template #pagination_prefix>
                <p class="text-sm text-stone-800 dark:text-neutral-200">{{ customerResultsLabel }}</p>
            </template>
        </AdminDataTable>

        <div v-else class="space-y-3">
            <div v-if="isBusy" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div v-for="row in 6" :key="`card-skeleton-${row}`"
                    class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="space-y-4 animate-pulse">
                        <div class="flex items-center gap-3">
                            <div class="size-11 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="flex-1 space-y-2">
                                <div class="h-3 w-3/4 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="h-3 w-1/2 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="h-3 w-full rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-full rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-full rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-full rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <div class="h-5 w-20 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-5 w-16 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else-if="!customerRows.length"
                class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-10 text-center text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                <CustomerEmptyState />
            </div>
            <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="customer in customerRows"
                    :key="customer.id"
                    class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md dark:border-neutral-700 dark:bg-neutral-800"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-3 min-w-0">
                            <div class="size-11 rounded-sm border border-stone-200 bg-stone-100 text-stone-600 flex items-center justify-center text-sm font-semibold dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                                <img
                                    v-if="hasCustomerLogo(customer)"
                                    class="size-11 rounded-sm object-cover"
                                    :src="customer.logo_url || customer.logo"
                                    :alt="$t('customers.labels.logo_alt')"
                                    loading="lazy"
                                    decoding="async"
                                >
                                <span v-else>{{ getCustomerInitials(customer) }}</span>
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <Link
                                        :href="route('customer.show', customer)"
                                        class="text-sm font-semibold text-stone-800 hover:text-emerald-700 dark:text-neutral-100 dark:hover:text-emerald-300 line-clamp-1"
                                    >
                                        {{ customer.company_name || `${customer.first_name} ${customer.last_name}` }}
                                    </Link>
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                        :class="customer.is_active
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200'
                                            : 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300'"
                                    >
                                        {{ customer.is_active ? $t('customers.status.active') : $t('customers.status.archived') }}
                                    </span>
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ customer.number || $t('customers.labels.customer_fallback') }}
                                </div>
                                <div class="mt-1 text-[11px] text-stone-400 dark:text-neutral-500">
                                    {{ getCity(customer) || $t('customers.labels.unknown_city') }}
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <Checkbox
                                v-if="canEdit"
                                :checked="isSelected(customer)"
                                @update:checked="toggleSelection(customer.id, $event)"
                            />
                            <CustomerActionsMenu
                                :customer="customer"
                                :can-edit="canEdit"
                                @toggle-archive="toggleArchive(customer)"
                                @delete="destroyCustomer(customer)"
                            />
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 md:grid-cols-2 text-xs text-stone-500 dark:text-neutral-400">
                        <div class="flex items-center gap-2">
                            <svg class="size-3.5 text-stone-400 dark:text-neutral-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                <circle cx="12" cy="7" r="4" />
                            </svg>
                            <span class="text-stone-700 dark:text-neutral-200">
                                {{ customer.first_name }} {{ customer.last_name }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="size-3.5 text-stone-400 dark:text-neutral-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 4h16v16H4z" />
                                <path d="m22 6-10 7L2 6" />
                            </svg>
                            <span class="text-stone-700 dark:text-neutral-200 truncate">
                                {{ customer.email || '-' }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="size-3.5 text-stone-400 dark:text-neutral-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.86 19.86 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.81.3 1.6.54 2.37a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.71-1.11a2 2 0 0 1 2.11-.45c.77.24 1.56.42 2.37.54a2 2 0 0 1 1.72 2.03z" />
                            </svg>
                            <span class="text-stone-700 dark:text-neutral-200">
                                {{ customer.phone || '-' }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="size-3.5 text-stone-400 dark:text-neutral-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22s8-4 8-10a8 8 0 1 0-16 0c0 6 8 10 8 10z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                            <span class="text-stone-700 dark:text-neutral-200">
                                {{ getCity(customer) || '-' }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                        <span
                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 font-medium bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200">
                            {{ $t('customers.labels.quotes') }} {{ customer.quotes_count ?? 0 }}
                        </span>
                        <span
                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 font-medium bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200">
                            {{ $t('customers.labels.jobs') }} {{ customer.works_count ?? 0 }}
                        </span>
                        <span class="text-[11px]">
                            {{ $t('customers.labels.created') }} {{ formatDate(customer.created_at) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="viewMode !== 'table' && customerRows.length > 0" class="mt-5 flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-stone-800 dark:text-neutral-200">{{ customerResultsLabel }}</p>

            <AdminPaginationLinks :links="customerLinks" />
        </div>

        <CustomerBulkContactModal
            ref="bulkContactModalRef"
            :selected-ids="selected"
            :selected-count="selectedCount"
            :campaigns-enabled="campaignsFeatureEnabled"
            @sent="clearSelection"
        />
    </div>
</template>
