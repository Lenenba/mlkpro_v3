<script setup>
import {
    computed,
    nextTick,
    onUnmounted,
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
import CustomerAdvancedFiltersDialog from '@/Components/Customer/CustomerAdvancedFiltersDialog.vue';
import CustomerFilterSummary from '@/Components/Customer/CustomerFilterSummary.vue';
import { humanizeDate } from '@/utils/date';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import { useDataTableSelection } from '@/Composables/useDataTableSelection';
import Checkbox from '@/Components/Checkbox.vue';
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
import { useCurrencyFormatter } from '@/utils/currency';
import dayjs from 'dayjs';
import 'dayjs/locale/fr';
import 'dayjs/locale/es';
import {
    compactCustomerFilterPayload,
    countActiveCustomerAdvancedFilters,
    createCustomerAdvancedFilters,
    initialCustomerQuickFilters,
    isCustomerFilterValueActive,
    normalizeAvailableCustomerFilters,
    normalizeCustomerQuickFilterMode,
    toggleCustomerQuickFilter,
} from '@/utils/customerFilters';

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
    filterMeta: {
        type: Object,
        default: () => ({}),
    },
    filterOptions: {
        type: Object,
        default: () => ({}),
    },
    bulkActions: {
        type: Object,
        default: () => ({}),
    },
    canEdit: {
        type: Boolean,
        default: false,
    },
    canDelete: {
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
    customerIndexContext: {
        type: Object,
        default: () => ({
            profile: 'generic',
            sector: null,
            capabilities: {},
            actions: {},
        }),
    },
});

const { t, locale } = useI18n();
const { hasFeature } = useAccountFeatures();
const { formatCurrency } = useCurrencyFormatter();
const appointmentProfile = computed(() => props.customerIndexContext?.profile === 'appointment');
const contextCapabilities = computed(() => props.customerIndexContext?.capabilities || {});
const contextActions = computed(() => props.customerIndexContext?.actions || {});
const vipCapabilityEnabled = computed(() => Boolean(contextCapabilities.value.campaigns));
const quotesFeatureEnabled = computed(() => !appointmentProfile.value && hasFeature('quotes'));
const jobsFeatureEnabled = computed(() => !appointmentProfile.value && hasFeature('jobs'));
const customerSearchPlaceholder = computed(() => t(
    appointmentProfile.value
        ? 'customers.appointment.search_placeholder'
        : 'customers.filters.search_placeholder'
));
const reservationsCapabilityEnabled = computed(() => (
    appointmentProfile.value && Boolean(contextCapabilities.value.reservations)
));
const teamMembersCapabilityEnabled = computed(() => (
    appointmentProfile.value && Boolean(contextCapabilities.value.team_members)
));
const loyaltyCapabilityEnabled = computed(() => (
    appointmentProfile.value && Boolean(contextCapabilities.value.loyalty)
));
const packagesCapabilityEnabled = computed(() => Boolean(contextCapabilities.value.packages));
const invoicesCapabilityEnabled = computed(() => (
    appointmentProfile.value && Boolean(contextCapabilities.value.invoices)
));
const salesCapabilityEnabled = computed(() => (
    appointmentProfile.value && Boolean(contextCapabilities.value.sales)
));
const campaignsCapabilityEnabled = computed(() => (
    appointmentProfile.value && Boolean(contextCapabilities.value.campaigns)
));
const birthdaysCapabilityEnabled = computed(() => (
    appointmentProfile.value && Boolean(contextCapabilities.value.birthdays)
));
const loyaltyOrPackagesEnabled = computed(() => (
    loyaltyCapabilityEnabled.value || packagesCapabilityEnabled.value
));
const customerValueCapabilityEnabled = computed(() => (
    invoicesCapabilityEnabled.value || salesCapabilityEnabled.value
));
const dayjsLocale = computed(() => {
    const value = String(locale.value || '').toLowerCase();

    if (value.startsWith('fr')) {
        return 'fr';
    }

    if (value.startsWith('es')) {
        return 'es';
    }

    return 'en';
});

const featureSortIsAvailable = (sort) => !(
    (sort === 'quotes_count' && !quotesFeatureEnabled.value)
    || (sort === 'works_count' && !jobsFeatureEnabled.value)
);
const initialSort = featureSortIsAvailable(props.filters?.sort)
    ? (props.filters?.sort ?? 'created_at')
    : 'created_at';

const canEdit = computed(() => Boolean(props.canEdit));
const canDelete = computed(() => Boolean(props.canDelete));
const canManageBulk = computed(() => Boolean(props.bulkActions?.enabled));
const canCreateCustomer = computed(() => contextActions.value.can_create_customer !== false);
const campaignsFeatureEnabled = computed(() => {
    const capability = props.bulkActions?.capabilities?.contact_enabled;

    if (capability !== undefined) {
        return Boolean(capability);
    }

    return canEdit.value && hasFeature('campaigns');
});

const availableFilterKeys = computed(() => normalizeAvailableCustomerFilters(
    props.filterMeta?.available_filters
));
const filterForm = useForm({
    ...createCustomerAdvancedFilters(props.filters),
    name: props.filters?.name ?? '',
    quick_filters: initialCustomerQuickFilters(props.filters, availableFilterKeys.value),
    quick_filter_mode: normalizeCustomerQuickFilterMode(
        props.filters?.quick_filter_mode ?? props.filterMeta?.quick_filter_mode
    ),
    sort: initialSort,
    direction: props.filters?.direction ?? 'desc',
});

const showAdvanced = ref(false);
const isLoading = ref(false);
const customerTableColumnCount = computed(() => {
    if (!appointmentProfile.value) {
        return 7 + Number(quotesFeatureEnabled.value) + Number(jobsFeatureEnabled.value);
    }

    return 4
        + (reservationsCapabilityEnabled.value ? 2 : 0)
        + (reservationsCapabilityEnabled.value && teamMembersCapabilityEnabled.value ? 1 : 0)
        + (loyaltyOrPackagesEnabled.value ? 1 : 0)
        + (customerValueCapabilityEnabled.value ? 1 : 0);
});
const compactObject = compactCustomerFilterPayload;
const segmentFilterValue = (value) => (value === null || value === undefined ? '' : String(value));
const operationalQuickFilters = computed(() => {
    const available = new Set(availableFilterKeys.value);
    const candidates = [
        { value: 'vip', capability: vipCapabilityEnabled.value },
        { value: 'new', capability: true },
        { value: 'new_this_month', capability: true },
        { value: 'inactive', capability: true },
        { value: 'no_next_appointment', capability: reservationsCapabilityEnabled.value },
        { value: 'upcoming_appointment', capability: reservationsCapabilityEnabled.value },
        { value: 'follow_up_90', capability: reservationsCapabilityEnabled.value },
        { value: 'package_low', capability: packagesCapabilityEnabled.value },
        { value: 'outstanding_balance', capability: invoicesCapabilityEnabled.value },
        { value: 'birthday_upcoming', capability: birthdaysCapabilityEnabled.value },
    ];

    return candidates
        .filter(({ value, capability }) => available.size ? available.has(value) : capability)
        .map(({ value }) => ({
            value,
            label: t(`customers.appointment.quick_filters.${value}`),
        }));
});
const isViewSwitching = ref(false);
const allowedViews = ['table', 'cards'];
const viewMode = ref('table');
const isBusy = computed(() => isLoading.value || isViewSwitching.value);
const shouldShowSavedSegments = computed(() =>
    Boolean(props.canManageSavedSegments) || (Array.isArray(props.savedSegments) && props.savedSegments.length > 0)
);
const savedSegmentFilters = computed(() => compactObject({
    ...createCustomerAdvancedFilters(filterForm),
    quick_filters: filterForm.quick_filters,
    quick_filter_mode: filterForm.quick_filter_mode,
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
    const advanced = createCustomerAdvancedFilters(filterForm);
    const payload = compactObject({
        ...advanced,
        name: filterForm.name,
        quick_filters: filterForm.quick_filters,
        quick_filter_mode: filterForm.quick_filter_mode === 'any' ? 'any' : '',
        sort: filterForm.sort,
        direction: filterForm.direction,
        per_page: currentPerPage.value,
    });

    return payload;
};

let filterTimeout;
let latestFilterVisit = 0;
let synchronizingFilters = false;
const filterError = ref(false);
const stopFilterExceptionListener = router.on('exception', () => {
    if (isLoading.value) {
        filterError.value = true;
    }
});
const stopInvalidResponseListener = router.on('invalid', () => {
    if (isLoading.value) {
        filterError.value = true;
    }
});

onUnmounted(() => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }
    if (viewSwitchTimeout) {
        clearTimeout(viewSwitchTimeout);
    }
    stopFilterExceptionListener();
    stopInvalidResponseListener();
});

const visitFilters = ({ replace = false, debounce = 0 } = {}) => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }

    const visit = () => {
        const visitId = ++latestFilterVisit;
        isLoading.value = true;
        filterError.value = false;
        router.get(route('customer.index'), filterPayload(), {
            only: ['customers', 'filters', 'count', 'filterMeta', 'topCustomers'],
            preserveState: true,
            preserveScroll: true,
            replace,
            onError: () => {
                if (visitId === latestFilterVisit) {
                    filterError.value = true;
                }
            },
            onFinish: () => {
                if (visitId === latestFilterVisit) {
                    isLoading.value = false;
                }
            },
        });
    };

    if (debounce > 0) {
        filterTimeout = setTimeout(visit, debounce);
        return;
    }

    visit();
};

const autoFilter = () => visitFilters({ replace: true, debounce: 300 });
const updateFiltersExplicitly = (callback) => {
    synchronizingFilters = true;
    callback();
    visitFilters({ replace: false });
    nextTick(() => {
        synchronizingFilters = false;
    });
};

watch(() => filterForm.name, () => {
    if (!synchronizingFilters) {
        autoFilter();
    }
});

watch(() => props.filters, async (filters) => {
    synchronizingFilters = true;
    Object.assign(filterForm, createCustomerAdvancedFilters(filters), {
        name: filters?.name ?? '',
        quick_filters: initialCustomerQuickFilters(filters, availableFilterKeys.value),
        quick_filter_mode: normalizeCustomerQuickFilterMode(
            filters?.quick_filter_mode ?? props.filterMeta?.quick_filter_mode
        ),
        sort: featureSortIsAvailable(filters?.sort) ? (filters?.sort ?? 'created_at') : 'created_at',
        direction: filters?.direction ?? 'desc',
    });
    await nextTick();
    synchronizingFilters = false;
}, { deep: true });

const clearFilters = () => {
    updateFiltersExplicitly(() => {
        Object.assign(filterForm, createCustomerAdvancedFilters(), {
            name: '',
            quick_filters: [],
            quick_filter_mode: 'all',
            sort: 'created_at',
            direction: 'desc',
        });
    });
};

const applySavedSegment = (segment) => {
    const filters = segment?.filters && typeof segment.filters === 'object' ? segment.filters : {};
    const sort = segment?.sort && typeof segment.sort === 'object' ? segment.sort : {};
    const requestedSort = segmentFilterValue(sort.sort) || 'created_at';
    const requestedSortIsAvailable = featureSortIsAvailable(requestedSort);

    updateFiltersExplicitly(() => {
        Object.assign(filterForm, createCustomerAdvancedFilters(filters), {
            name: String(segment?.search_term || ''),
            quick_filters: initialCustomerQuickFilters(filters, availableFilterKeys.value),
            quick_filter_mode: normalizeCustomerQuickFilterMode(filters.quick_filter_mode),
            sort: requestedSortIsAvailable ? requestedSort : 'created_at',
            direction: requestedSortIsAvailable
                ? (segmentFilterValue(sort.direction) || 'desc')
                : 'desc',
        });
    });
};

const toggleSort = (column) => {
    if (!featureSortIsAvailable(column)) {
        return;
    }

    updateFiltersExplicitly(() => {
        if (filterForm.sort === column) {
            filterForm.direction = filterForm.direction === 'asc' ? 'desc' : 'asc';
            return;
        }
        filterForm.sort = column;
        filterForm.direction = 'asc';
    });
};
const ariaSort = (column) => (
    filterForm.sort === column
        ? (filterForm.direction === 'asc' ? 'ascending' : 'descending')
        : 'none'
);

const operationalQuickFilterClass = (value) => (
    filterForm.quick_filters.includes(value)
        ? 'border-transparent bg-green-600 text-white dark:bg-green-500 dark:text-white'
        : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800'
);

const setOperationalQuickFilter = (value) => {
    updateFiltersExplicitly(() => {
        filterForm.quick_filters = toggleCustomerQuickFilter(filterForm.quick_filters, value);
    });
};

const clearQuickFilters = () => {
    if (!filterForm.quick_filters.length) {
        return;
    }

    updateFiltersExplicitly(() => {
        filterForm.quick_filters = [];
    });
};

const setQuickFilterMode = (mode) => {
    const normalized = normalizeCustomerQuickFilterMode(mode);
    if (filterForm.quick_filter_mode === normalized) {
        return;
    }

    updateFiltersExplicitly(() => {
        filterForm.quick_filter_mode = normalized;
    });
};

const applyAdvancedFilters = (filters) => {
    updateFiltersExplicitly(() => {
        Object.assign(filterForm, createCustomerAdvancedFilters(filters));
        showAdvanced.value = false;
    });
};

const applyKpiFilter = (action) => {
    if (!action || typeof action !== 'object') {
        return;
    }

    if (action.type === 'quick') {
        setOperationalQuickFilter(action.key);
        return;
    }

    if (action.type === 'advanced' && action.key in createCustomerAdvancedFilters()) {
        updateFiltersExplicitly(() => {
            filterForm[action.key] = String(filterForm[action.key] ?? '') === String(action.value ?? '')
                ? ''
                : action.value;
        });
    }
};

defineExpose({ applyKpiFilter });

const formatFilterValue = (key, value) => {
    if (Array.isArray(value)) {
        return value.join(', ');
    }

    if (value === '1') {
        return t('customers.advanced_filters.options.yes');
    }

    if (value === '0') {
        return t('customers.advanced_filters.options.no');
    }

    if (key === 'status' && ['active', 'archived'].includes(value)) {
        return t(`customers.status.${value}`);
    }

    if (key === 'client_type' && ['individual', 'company'].includes(value)) {
        return t(`customers.form.client_types.${value}`);
    }

    return String(value);
};

const activeFilterBadges = computed(() => {
    const badges = [];
    const search = String(filterForm.name || '').trim();

    if (search) {
        badges.push({
            id: 'search',
            kind: 'search',
            key: 'name',
            label: t('customers.filter_summary.search_badge', { value: search }),
        });
    }

    filterForm.quick_filters.forEach((key) => {
        const definition = operationalQuickFilters.value.find((filter) => filter.value === key);
        if (definition) {
            badges.push({
                id: `quick:${key}`,
                kind: 'quick',
                key,
                label: definition.label,
            });
        }
    });

    const advanced = createCustomerAdvancedFilters(filterForm);
    Object.entries(advanced).forEach(([key, value]) => {
        if (!isCustomerFilterValueActive(value)) {
            return;
        }

        badges.push({
            id: `advanced:${key}`,
            kind: 'advanced',
            key,
            label: t('customers.filter_summary.filter_badge', {
                label: t(`customers.advanced_filters.fields.${key}`),
                value: formatFilterValue(key, value),
            }),
        });
    });

    return badges;
});

const removeActiveFilter = (filter) => {
    if (!filter) {
        return;
    }

    updateFiltersExplicitly(() => {
        if (filter.kind === 'search') {
            filterForm.name = '';
            return;
        }

        if (filter.kind === 'quick') {
            filterForm.quick_filters = filterForm.quick_filters.filter((key) => key !== filter.key);
            return;
        }

        const defaults = createCustomerAdvancedFilters();
        if (filter.key in defaults) {
            filterForm[filter.key] = defaults[filter.key];
        }
    });
};

const matchingCount = computed(() => Number(
    props.filterMeta?.matching_count ?? props.count ?? props.customers?.total ?? 0
));
const advancedFilterCount = computed(() => countActiveCustomerAdvancedFilters(filterForm));
const hasAppliedFilters = computed(() => activeFilterBadges.value.length > 0);
const emptyStateVariant = computed(() => {
    if (filterError.value) {
        return 'error';
    }

    return hasAppliedFilters.value ? 'no-results' : 'empty';
});
const retryFilters = () => visitFilters({ replace: true });

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
        only: ['customers', 'filters', 'kpis', 'stats', 'count', 'filterMeta', 'topCustomers'],
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

const customerTags = (customer) => {
    if (Array.isArray(customer?.tags)) {
        return customer.tags.map((tag) => String(tag || '').trim()).filter(Boolean);
    }

    if (typeof customer?.tags === 'string') {
        return customer.tags.split(',').map((tag) => tag.trim()).filter(Boolean);
    }

    return [];
};
const visibleCustomerTags = (customer) => customerTags(customer).slice(0, 2);
const hiddenCustomerTagCount = (customer) => Math.max(0, customerTags(customer).length - 2);

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

const operationalSummary = (customer) => customer?.operational_summary || {};

const lifecycleStatus = (customer) => {
    const status = String(operationalSummary(customer).lifecycle_status || '');

    if (['new', 'active', 'follow_up', 'inactive'].includes(status)) {
        return status;
    }

    return customer?.is_active ? 'active' : 'inactive';
};

const lifecycleStatusClass = (customer) => {
    const classes = {
        new: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-200',
        active: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200',
        follow_up: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-200',
        inactive: 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300',
    };

    return classes[lifecycleStatus(customer)] || classes.inactive;
};

const lifecycleStatusLabel = (customer) => t(`customers.appointment.lifecycle.${lifecycleStatus(customer)}`);

const formatOperationalDate = (value) => (
    value
        ? dayjs(value).locale(dayjsLocale.value).format('DD MMM YYYY · HH:mm')
        : ''
);

const customerCurrency = (customer) => operationalSummary(customer).currency_code || null;
const formatCustomerCurrency = (customer, value) => formatCurrency(value, customerCurrency(customer));
const hasNumericSummaryValue = (customer, key) => {
    const value = operationalSummary(customer)[key];

    return value !== null && value !== undefined && Number.isFinite(Number(value));
};

const remainingPackageLabel = (customer) => {
    const remaining = Number(operationalSummary(customer).active_package?.remaining_quantity ?? 0);

    return t('customers.appointment.labels.package_remaining', { count: remaining });
};

const customerLinks = computed(() => props.customers?.links || []);
const currentPerPage = computed(() => resolveDataTablePerPage(props.customers?.per_page, props.filters?.per_page));
const customerResultsLabel = computed(() => t('customers.filter_summary.results', { count: matchingCount.value }));
</script>

<template>
    <div
        class="p-4 sm:p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700"
        :aria-busy="String(isBusy)"
    >
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
                filters-available
                filters-controls="customer-advanced-filters-dialog"
                :filters-label="advancedFilterCount
                    ? $t('customers.advanced_filters.trigger_active', { count: advancedFilterCount })
                    : $t('customers.actions.filters')"
                :clear-label="$t('customers.actions.clear')"
                @toggle-filters="showAdvanced = !showAdvanced"
                @apply="autoFilter"
                @clear="clearFilters"
            >
                <template #search>
                    <div class="relative">
                        <label for="customer-index-search" class="sr-only">
                            {{ customerSearchPlaceholder }}
                        </label>
                        <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                            <svg class="shrink-0 size-4 text-stone-500 dark:text-neutral-400"
                                xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8" />
                                <path d="m21 21-4.3-4.3" />
                            </svg>
                        </div>
                        <input id="customer-index-search" type="search" v-model="filterForm.name" data-testid="demo-customer-search"
                            class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                            :placeholder="customerSearchPlaceholder"
                            :disabled="isBusy">
                    </div>
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
                    <Link v-if="canCreateCustomer" :href="route('customer.create')" data-testid="demo-add-customer"
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

            <div
                v-if="operationalQuickFilters.length"
                class="flex flex-wrap gap-2"
                data-testid="customer-operational-filters"
                role="group"
                :aria-label="$t('customers.filter_summary.quick_filters_label')"
            >
                <button
                    type="button"
                    class="inline-flex min-h-11 items-center rounded-full border px-3 py-2 text-xs font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600"
                    :class="!filterForm.quick_filters.length
                        ? 'border-transparent bg-green-600 text-white dark:bg-green-500'
                        : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                    :aria-pressed="String(!filterForm.quick_filters.length)"
                    :disabled="isBusy"
                    @click="clearQuickFilters"
                >
                    {{ $t('customers.appointment.quick_filters.all') }}
                </button>
                <button
                    v-for="filter in operationalQuickFilters"
                    :key="filter.value"
                    type="button"
                    class="inline-flex min-h-11 items-center rounded-full border px-3 py-2 text-xs font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600"
                    :class="operationalQuickFilterClass(filter.value)"
                    :aria-pressed="String(filterForm.quick_filters.includes(filter.value))"
                    :disabled="isBusy"
                    @click="setOperationalQuickFilter(filter.value)"
                >
                    {{ filter.label }}
                </button>
            </div>

            <CustomerFilterSummary
                :matching-count="matchingCount"
                :active-filters="activeFilterBadges"
                :quick-filter-mode="filterForm.quick_filter_mode"
                :quick-filter-count="filterForm.quick_filters.length"
                :busy="isBusy"
                @update:quick-filter-mode="setQuickFilterMode"
                @remove="removeActiveFilter"
                @clear="clearFilters"
            />

            <CustomerAdvancedFiltersDialog
                id="customer-advanced-filters-dialog"
                :show="showAdvanced"
                :filters="filterForm"
                :matching-count="matchingCount"
                :capabilities="contextCapabilities"
                :available-filters="filterMeta?.available_filters"
                :filter-options="filterOptions"
                :show-quote-filters="quotesFeatureEnabled"
                :show-job-filters="jobsFeatureEnabled"
                @close="showAdvanced = false"
                @apply="applyAdvancedFilters"
            />

            <AdminDataTableBulkBar
                v-if="canManageBulk"
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

        <div
            v-if="filterError && customerRows.length"
            class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-800/70 dark:bg-rose-500/10 dark:text-rose-200"
            role="alert"
        >
            <span>{{ $t('customers.states.error.description') }}</span>
            <button type="button" :class="crmButtonClass('secondary', 'compact')" @click="retryFilters">
                {{ $t('customers.states.error.action') }}
            </button>
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
                    <CustomerEmptyState
                        :variant="emptyStateVariant"
                        :can-create="canCreateCustomer"
                        @clear="clearFilters"
                        @retry="retryFilters"
                    />
                </div>
            </template>

            <template #head>
                <tr v-if="appointmentProfile">
                    <th scope="col" class="w-10 px-4 py-2">
                        <input v-if="canManageBulk" ref="selectAllRef" type="checkbox" :checked="allSelected" @change="toggleAll"
                            :aria-label="$t('customers.accessibility.select_all')"
                            class="rounded border-stone-300 text-green-600 shadow-sm focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-green-400 dark:focus:ring-green-400" />
                    </th>
                    <th scope="col" class="min-w-[290px]" :aria-sort="ariaSort('first_name')">
                        <button type="button" @click="toggleSort('first_name')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('customers.appointment.table.client') }}
                            <svg v-if="filterForm.sort === 'first_name'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-32 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                        {{ $t('customers.appointment.table.status') }}
                    </th>
                    <th v-if="reservationsCapabilityEnabled" scope="col" class="min-w-[190px] px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                        {{ $t('customers.appointment.table.last_visit') }}
                    </th>
                    <th v-if="reservationsCapabilityEnabled" scope="col" class="min-w-[210px] px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                        {{ $t('customers.appointment.table.next_appointment') }}
                    </th>
                    <th v-if="reservationsCapabilityEnabled && teamMembersCapabilityEnabled" scope="col" class="min-w-40 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                        {{ $t('customers.appointment.table.usual_team_member') }}
                    </th>
                    <th v-if="loyaltyOrPackagesEnabled" scope="col" class="min-w-[190px] px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                        {{ $t('customers.appointment.table.loyalty_package') }}
                    </th>
                    <th v-if="customerValueCapabilityEnabled" scope="col" class="min-w-[180px] px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                        {{ $t('customers.appointment.table.customer_value') }}
                    </th>
                    <th scope="col" class="min-w-16 px-5 py-2.5 text-end text-sm font-normal text-stone-500 dark:text-neutral-500">
                        {{ $t('customers.appointment.table.actions') }}
                    </th>
                </tr>
                <tr v-else-if="!appointmentProfile">
                            <th scope="col" class="w-10 px-4 py-2">
                                <input v-if="canManageBulk" ref="selectAllRef" type="checkbox" :checked="allSelected" @change="toggleAll"
                                    :aria-label="$t('customers.accessibility.select_all')"
                                    class="rounded border-stone-300 text-green-600 shadow-sm focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-green-400 dark:focus:ring-green-400" />
                            </th>
                            <th scope="col" class="min-w-[240px]" :aria-sort="ariaSort('company_name')">
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
                            <th scope="col" class="min-w-40" :aria-sort="ariaSort('first_name')">
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
                            <th v-if="quotesFeatureEnabled" scope="col" class="min-w-28" :aria-sort="ariaSort('quotes_count')">
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
                            <th v-if="jobsFeatureEnabled" scope="col" class="min-w-28" :aria-sort="ariaSort('works_count')">
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
                            <th scope="col" class="min-w-32" :aria-sort="ariaSort('created_at')">
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
                    <td :colspan="customerTableColumnCount" class="px-4 py-3">
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
                <tr v-else-if="!appointmentProfile">
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <Checkbox
                                    v-if="canManageBulk"
                                    :checked="isSelected(customer)"
                                    :aria-label="$t('customers.accessibility.select_customer', { name: customer.company_name || `${customer.first_name} ${customer.last_name}` })"
                                    @update:checked="toggleSelection(customer.id, $event)"
                                />
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-start">
                                <Link :href="route('customer.show', customer)">
                                    <div class="w-full flex items-center gap-x-3">
                                        <img class="shrink-0 size-10 object-cover" :src="customer.logo_url || customer.logo"
                                            :class="customer.client_type === 'individual' ? 'rounded-full' : 'rounded-sm'"
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
                                                <span v-if="vipCapabilityEnabled && customer.is_vip"
                                                    class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold uppercase text-amber-700 dark:bg-amber-500/20 dark:text-amber-200">
                                                    {{ $t('customers.appointment.labels.vip') }}
                                                </span>
                                            </div>
                                            <span class="text-xs text-stone-500 dark:text-neutral-500">
                                                {{ customer.number }}
                                            </span>
                                            <span v-if="customerTags(customer).length" class="mt-1 flex flex-wrap gap-1">
                                                <span v-for="tag in visibleCustomerTags(customer)" :key="tag"
                                                    class="rounded-full bg-stone-100 px-2 py-0.5 text-[10px] text-stone-600 dark:bg-neutral-700 dark:text-neutral-300">
                                                    {{ tag }}
                                                </span>
                                                <span v-if="hiddenCustomerTagCount(customer)" class="text-[10px] text-stone-400 dark:text-neutral-500">
                                                    +{{ hiddenCustomerTagCount(customer) }}
                                                </span>
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
                            <td v-if="quotesFeatureEnabled" class="size-px whitespace-nowrap px-4 py-2">
                                <span
                                    class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200">
                                    {{ customer.quotes_count ?? 0 }}
                                </span>
                            </td>
                            <td v-if="jobsFeatureEnabled" class="size-px whitespace-nowrap px-4 py-2">
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
                                    :can-delete="canDelete"
                                    :customer-index-context="customerIndexContext"
                                    @toggle-archive="toggleArchive(customer)"
                                    @delete="destroyCustomer(customer)"
                                />
                            </td>
                        </tr>
                <tr v-else>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <Checkbox
                            v-if="canManageBulk"
                            :checked="isSelected(customer)"
                            :aria-label="$t('customers.accessibility.select_customer', { name: customer.company_name || `${customer.first_name} ${customer.last_name}` })"
                            @update:checked="toggleSelection(customer.id, $event)"
                        />
                    </td>
                    <td class="px-4 py-3 text-start align-top">
                        <div class="flex min-w-[260px] items-start gap-x-3">
                            <div class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-full border border-stone-200 bg-stone-100 text-sm font-semibold text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                                <img
                                    v-if="hasCustomerLogo(customer)"
                                    class="size-11 rounded-full object-cover"
                                    :src="customer.logo_url || customer.logo"
                                    :alt="$t('customers.labels.logo_alt')"
                                    loading="lazy"
                                    decoding="async"
                                >
                                <span v-else>{{ getCustomerInitials(customer) }}</span>
                            </div>
                            <div class="min-w-0">
                                <Link
                                    :href="route('customer.show', customer)"
                                    class="block truncate text-sm font-semibold text-stone-800 hover:text-emerald-700 dark:text-neutral-100 dark:hover:text-emerald-300"
                                >
                                    {{ customer.company_name || `${customer.first_name} ${customer.last_name}` }}
                                </Link>
                                <div class="mt-0.5 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ customer.number || $t('customers.labels.customer_fallback') }}
                                </div>
                                <a v-if="customer.email" :href="`mailto:${customer.email}`"
                                    class="mt-1 block max-w-[230px] truncate text-xs text-stone-500 hover:text-emerald-700 dark:text-neutral-400 dark:hover:text-emerald-300">
                                    {{ customer.email }}
                                </a>
                                <a v-if="customer.phone" :href="`tel:${customer.phone}`"
                                    class="mt-0.5 block text-xs text-stone-500 hover:text-emerald-700 dark:text-neutral-400 dark:hover:text-emerald-300">
                                    {{ customer.phone }}
                                </a>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 align-top">
                        <div class="flex max-w-36 flex-wrap gap-1.5">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                :class="lifecycleStatusClass(customer)">
                                {{ lifecycleStatusLabel(customer) }}
                            </span>
                            <span v-if="vipCapabilityEnabled && customer.is_vip"
                                class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-amber-700 dark:bg-amber-500/20 dark:text-amber-200">
                                {{ $t('customers.appointment.labels.vip') }}
                            </span>
                            <span v-for="tag in visibleCustomerTags(customer)" :key="tag"
                                class="inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-[11px] text-stone-600 dark:bg-neutral-700 dark:text-neutral-300">
                                {{ tag }}
                            </span>
                            <span v-if="hiddenCustomerTagCount(customer)" class="text-[11px] text-stone-400 dark:text-neutral-500">
                                +{{ hiddenCustomerTagCount(customer) }}
                            </span>
                        </div>
                    </td>
                    <td v-if="reservationsCapabilityEnabled" class="px-4 py-3 align-top">
                        <template v-if="operationalSummary(customer).last_visit">
                            <div class="text-sm font-medium text-stone-700 dark:text-neutral-200">
                                {{ formatOperationalDate(operationalSummary(customer).last_visit.starts_at) }}
                            </div>
                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ operationalSummary(customer).last_visit.service_name || $t('customers.appointment.labels.service_unknown') }}
                            </div>
                        </template>
                        <span v-else class="text-xs text-stone-400 dark:text-neutral-500">
                            {{ $t('customers.appointment.labels.no_visit') }}
                        </span>
                    </td>
                    <td v-if="reservationsCapabilityEnabled" class="px-4 py-3 align-top">
                        <template v-if="operationalSummary(customer).next_appointment">
                            <div class="text-sm font-medium text-stone-700 dark:text-neutral-200">
                                {{ formatOperationalDate(operationalSummary(customer).next_appointment.starts_at) }}
                            </div>
                            <div v-if="teamMembersCapabilityEnabled" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ operationalSummary(customer).next_appointment.team_member_name || $t('customers.appointment.labels.unassigned') }}
                            </div>
                        </template>
                        <span v-else class="text-xs font-medium text-amber-600 dark:text-amber-300">
                            {{ $t('customers.appointment.labels.no_next_appointment') }}
                        </span>
                    </td>
                    <td v-if="reservationsCapabilityEnabled && teamMembersCapabilityEnabled" class="px-4 py-3 align-top">
                        <span class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ operationalSummary(customer).usual_team_member?.name || $t('customers.appointment.labels.no_usual_team_member') }}
                        </span>
                    </td>
                    <td v-if="loyaltyOrPackagesEnabled" class="px-4 py-3 align-top">
                        <div class="space-y-1.5">
                            <div v-if="loyaltyCapabilityEnabled && operationalSummary(customer).loyalty_points !== null"
                                class="text-sm font-medium text-stone-700 dark:text-neutral-200">
                                {{ $t('customers.appointment.labels.loyalty_points', { count: operationalSummary(customer).loyalty_points }) }}
                            </div>
                            <div v-if="packagesCapabilityEnabled && operationalSummary(customer).active_package"
                                class="text-xs text-stone-500 dark:text-neutral-400">
                                <div class="font-medium text-stone-700 dark:text-neutral-200">
                                    {{ operationalSummary(customer).active_package.name }}
                                </div>
                                <div>{{ remainingPackageLabel(customer) }}</div>
                            </div>
                            <span v-if="(!loyaltyCapabilityEnabled || operationalSummary(customer).loyalty_points === null)
                                && (!packagesCapabilityEnabled || !operationalSummary(customer).active_package)"
                                class="text-xs text-stone-400 dark:text-neutral-500">-</span>
                        </div>
                    </td>
                    <td v-if="customerValueCapabilityEnabled" class="px-4 py-3 align-top">
                        <div class="space-y-1 text-xs">
                            <div v-if="hasNumericSummaryValue(customer, 'total_spent')" class="text-stone-600 dark:text-neutral-300">
                                <span class="text-stone-400 dark:text-neutral-500">{{ $t('customers.appointment.labels.total_spent') }}</span>
                                <span class="ms-1 font-semibold">{{ formatCustomerCurrency(customer, operationalSummary(customer).total_spent) }}</span>
                            </div>
                            <div v-if="hasNumericSummaryValue(customer, 'tip_total') && Number(operationalSummary(customer).tip_total) > 0"
                                class="text-stone-500 dark:text-neutral-400">
                                {{ $t('customers.appointment.labels.tip_total') }}
                                {{ formatCustomerCurrency(customer, operationalSummary(customer).tip_total) }}
                            </div>
                            <div v-if="invoicesCapabilityEnabled && hasNumericSummaryValue(customer, 'unpaid_balance') && Number(operationalSummary(customer).unpaid_balance) > 0"
                                class="font-semibold text-rose-600 dark:text-rose-300">
                                {{ $t('customers.appointment.labels.unpaid_balance') }}
                                {{ formatCustomerCurrency(customer, operationalSummary(customer).unpaid_balance) }}
                            </div>
                            <div v-else-if="invoicesCapabilityEnabled && hasNumericSummaryValue(customer, 'unpaid_balance')"
                                class="text-stone-400 dark:text-neutral-500">
                                {{ $t('customers.appointment.labels.no_unpaid_balance') }}
                            </div>
                            <span v-if="!hasNumericSummaryValue(customer, 'total_spent') && (!invoicesCapabilityEnabled || !hasNumericSummaryValue(customer, 'unpaid_balance'))"
                                class="text-stone-400 dark:text-neutral-500">-</span>
                        </div>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-3 text-end align-top">
                        <CustomerActionsMenu
                            :customer="customer"
                            :can-edit="canEdit"
                            :can-delete="canDelete"
                            :customer-index-context="customerIndexContext"
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
                <CustomerEmptyState
                    :variant="emptyStateVariant"
                    :can-create="canCreateCustomer"
                    @clear="clearFilters"
                    @retry="retryFilters"
                />
            </div>
            <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="customer in customerRows"
                    :key="customer.id"
                    class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md dark:border-neutral-700 dark:bg-neutral-800"
                >
                    <template v-if="appointmentProfile">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <div class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-full border border-stone-200 bg-stone-100 text-sm font-semibold text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                                    <img
                                        v-if="hasCustomerLogo(customer)"
                                        class="size-11 rounded-full object-cover"
                                        :src="customer.logo_url || customer.logo"
                                        :alt="$t('customers.labels.logo_alt')"
                                        loading="lazy"
                                        decoding="async"
                                    >
                                    <span v-else>{{ getCustomerInitials(customer) }}</span>
                                </div>
                                <div class="min-w-0">
                                    <Link
                                        :href="route('customer.show', customer)"
                                        class="line-clamp-1 text-sm font-semibold text-stone-800 hover:text-emerald-700 dark:text-neutral-100 dark:hover:text-emerald-300"
                                    >
                                        {{ customer.company_name || `${customer.first_name} ${customer.last_name}` }}
                                    </Link>
                                    <div class="mt-0.5 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ customer.number || $t('customers.labels.customer_fallback') }}
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                            :class="lifecycleStatusClass(customer)"
                                        >
                                            {{ lifecycleStatusLabel(customer) }}
                                        </span>
                                        <span
                                            v-if="vipCapabilityEnabled && customer.is_vip"
                                            class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-amber-700 dark:bg-amber-500/20 dark:text-amber-200"
                                        >
                                            {{ $t('customers.appointment.labels.vip') }}
                                        </span>
                                        <span v-for="tag in visibleCustomerTags(customer)" :key="tag"
                                            class="inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-[11px] text-stone-600 dark:bg-neutral-700 dark:text-neutral-300">
                                            {{ tag }}
                                        </span>
                                        <span v-if="hiddenCustomerTagCount(customer)" class="text-[11px] text-stone-400 dark:text-neutral-500">
                                            +{{ hiddenCustomerTagCount(customer) }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <Checkbox
                                    v-if="canManageBulk"
                                    :checked="isSelected(customer)"
                                    :aria-label="$t('customers.accessibility.select_customer', { name: customer.company_name || `${customer.first_name} ${customer.last_name}` })"
                                    @update:checked="toggleSelection(customer.id, $event)"
                                />
                                <CustomerActionsMenu
                                    :customer="customer"
                                    :can-edit="canEdit"
                                    :can-delete="canDelete"
                                    :customer-index-context="customerIndexContext"
                                    @toggle-archive="toggleArchive(customer)"
                                    @delete="destroyCustomer(customer)"
                                />
                            </div>
                        </div>

                        <div v-if="customer.email || customer.phone" class="mt-4 flex flex-wrap gap-x-4 gap-y-1 border-t border-stone-100 pt-3 text-xs dark:border-neutral-700">
                            <a v-if="customer.email" :href="`mailto:${customer.email}`"
                                class="max-w-full truncate text-stone-500 hover:text-emerald-700 dark:text-neutral-400 dark:hover:text-emerald-300">
                                {{ customer.email }}
                            </a>
                            <a v-if="customer.phone" :href="`tel:${customer.phone}`"
                                class="text-stone-500 hover:text-emerald-700 dark:text-neutral-400 dark:hover:text-emerald-300">
                                {{ customer.phone }}
                            </a>
                        </div>

                        <div class="mt-4 grid gap-3 text-xs sm:grid-cols-2">
                            <div v-if="reservationsCapabilityEnabled" class="rounded-sm bg-stone-50 p-3 dark:bg-neutral-900/60">
                                <div class="font-medium text-stone-400 dark:text-neutral-500">
                                    {{ $t('customers.appointment.table.last_visit') }}
                                </div>
                                <template v-if="operationalSummary(customer).last_visit">
                                    <div class="mt-1 font-semibold text-stone-700 dark:text-neutral-200">
                                        {{ formatOperationalDate(operationalSummary(customer).last_visit.starts_at) }}
                                    </div>
                                    <div class="mt-0.5 text-stone-500 dark:text-neutral-400">
                                        {{ operationalSummary(customer).last_visit.service_name || $t('customers.appointment.labels.service_unknown') }}
                                    </div>
                                </template>
                                <div v-else class="mt-1 text-stone-400 dark:text-neutral-500">
                                    {{ $t('customers.appointment.labels.no_visit') }}
                                </div>
                            </div>
                            <div v-if="reservationsCapabilityEnabled" class="rounded-sm bg-stone-50 p-3 dark:bg-neutral-900/60">
                                <div class="font-medium text-stone-400 dark:text-neutral-500">
                                    {{ $t('customers.appointment.table.next_appointment') }}
                                </div>
                                <template v-if="operationalSummary(customer).next_appointment">
                                    <div class="mt-1 font-semibold text-stone-700 dark:text-neutral-200">
                                        {{ formatOperationalDate(operationalSummary(customer).next_appointment.starts_at) }}
                                    </div>
                                    <div v-if="teamMembersCapabilityEnabled" class="mt-0.5 text-stone-500 dark:text-neutral-400">
                                        {{ operationalSummary(customer).next_appointment.team_member_name || $t('customers.appointment.labels.unassigned') }}
                                    </div>
                                </template>
                                <div v-else class="mt-1 font-medium text-amber-600 dark:text-amber-300">
                                    {{ $t('customers.appointment.labels.no_next_appointment') }}
                                </div>
                            </div>
                            <div v-if="reservationsCapabilityEnabled && teamMembersCapabilityEnabled" class="rounded-sm bg-stone-50 p-3 dark:bg-neutral-900/60">
                                <div class="font-medium text-stone-400 dark:text-neutral-500">
                                    {{ $t('customers.appointment.table.usual_team_member') }}
                                </div>
                                <div class="mt-1 font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ operationalSummary(customer).usual_team_member?.name || $t('customers.appointment.labels.no_usual_team_member') }}
                                </div>
                            </div>
                            <div v-if="loyaltyOrPackagesEnabled" class="rounded-sm bg-stone-50 p-3 dark:bg-neutral-900/60">
                                <div class="font-medium text-stone-400 dark:text-neutral-500">
                                    {{ $t('customers.appointment.table.loyalty_package') }}
                                </div>
                                <div v-if="loyaltyCapabilityEnabled && operationalSummary(customer).loyalty_points !== null"
                                    class="mt-1 font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ $t('customers.appointment.labels.loyalty_points', { count: operationalSummary(customer).loyalty_points }) }}
                                </div>
                                <div v-if="packagesCapabilityEnabled && operationalSummary(customer).active_package" class="mt-1 text-stone-600 dark:text-neutral-300">
                                    {{ operationalSummary(customer).active_package.name }} · {{ remainingPackageLabel(customer) }}
                                </div>
                                <div v-if="(!loyaltyCapabilityEnabled || operationalSummary(customer).loyalty_points === null)
                                    && (!packagesCapabilityEnabled || !operationalSummary(customer).active_package)"
                                    class="mt-1 text-stone-400 dark:text-neutral-500">-</div>
                            </div>
                            <div v-if="customerValueCapabilityEnabled" class="rounded-sm bg-stone-50 p-3 dark:bg-neutral-900/60">
                                <div class="font-medium text-stone-400 dark:text-neutral-500">
                                    {{ $t('customers.appointment.table.customer_value') }}
                                </div>
                                <div v-if="hasNumericSummaryValue(customer, 'total_spent')" class="mt-1 font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ $t('customers.appointment.labels.total_spent') }}
                                    {{ formatCustomerCurrency(customer, operationalSummary(customer).total_spent) }}
                                </div>
                                <div v-if="hasNumericSummaryValue(customer, 'tip_total') && Number(operationalSummary(customer).tip_total) > 0"
                                    class="mt-0.5 text-stone-500 dark:text-neutral-400">
                                    {{ $t('customers.appointment.labels.tip_total') }}
                                    {{ formatCustomerCurrency(customer, operationalSummary(customer).tip_total) }}
                                </div>
                                <div v-if="invoicesCapabilityEnabled && hasNumericSummaryValue(customer, 'unpaid_balance') && Number(operationalSummary(customer).unpaid_balance) > 0"
                                    class="mt-0.5 font-semibold text-rose-600 dark:text-rose-300">
                                    {{ $t('customers.appointment.labels.unpaid_balance') }}
                                    {{ formatCustomerCurrency(customer, operationalSummary(customer).unpaid_balance) }}
                                </div>
                                <div v-else-if="invoicesCapabilityEnabled && hasNumericSummaryValue(customer, 'unpaid_balance')"
                                    class="mt-0.5 text-stone-400 dark:text-neutral-500">
                                    {{ $t('customers.appointment.labels.no_unpaid_balance') }}
                                </div>
                                <div v-if="!hasNumericSummaryValue(customer, 'total_spent') && (!invoicesCapabilityEnabled || !hasNumericSummaryValue(customer, 'unpaid_balance'))"
                                    class="mt-1 text-stone-400 dark:text-neutral-500">-</div>
                            </div>
                        </div>
                    </template>
                    <template v-else>
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-3 min-w-0">
                            <div class="size-11 rounded-sm border border-stone-200 bg-stone-100 text-stone-600 flex items-center justify-center text-sm font-semibold dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                                <img
                                    v-if="hasCustomerLogo(customer)"
                                    class="size-11 object-cover"
                                    :class="customer.client_type === 'individual' ? 'rounded-full' : 'rounded-sm'"
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
                                <div v-if="customerTags(customer).length" class="mt-2 flex flex-wrap gap-1">
                                    <span v-for="tag in visibleCustomerTags(customer)" :key="tag"
                                        class="rounded-full bg-stone-100 px-2 py-0.5 text-[10px] text-stone-600 dark:bg-neutral-700 dark:text-neutral-300">
                                        {{ tag }}
                                    </span>
                                    <span v-if="hiddenCustomerTagCount(customer)" class="text-[10px] text-stone-400 dark:text-neutral-500">
                                        +{{ hiddenCustomerTagCount(customer) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <Checkbox
                                v-if="canManageBulk"
                                :checked="isSelected(customer)"
                                :aria-label="$t('customers.accessibility.select_customer', { name: customer.company_name || `${customer.first_name} ${customer.last_name}` })"
                                @update:checked="toggleSelection(customer.id, $event)"
                            />
                            <CustomerActionsMenu
                                :customer="customer"
                                :can-edit="canEdit"
                                :can-delete="canDelete"
                                :customer-index-context="customerIndexContext"
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
                        <span v-if="quotesFeatureEnabled"
                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 font-medium bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200">
                            {{ $t('customers.labels.quotes') }} {{ customer.quotes_count ?? 0 }}
                        </span>
                        <span v-if="jobsFeatureEnabled"
                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 font-medium bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200">
                            {{ $t('customers.labels.jobs') }} {{ customer.works_count ?? 0 }}
                        </span>
                        <span class="text-[11px]">
                            {{ $t('customers.labels.created') }} {{ formatDate(customer.created_at) }}
                        </span>
                    </div>
                    </template>
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
