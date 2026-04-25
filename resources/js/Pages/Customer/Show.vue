<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import Header from './UI/Header.vue';
import Card from '@/Components/UI/Card.vue';
import DescriptionList from '@/Components/UI/DescriptionList.vue';
import CardNav from '@/Components/UI/CardNav.vue';
import Modal from '@/Components/Modal.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import SalesActivityPanel from '@/Components/CRM/SalesActivityPanel.vue';
import { humanizeDate } from '@/utils/date';
import { useI18n } from 'vue-i18n';
import CustomerPreviewCard from './UI/CustomerPreviewCard.vue';
import { useCurrencyFormatter } from '@/utils/currency';
import { useAccountFeatures } from '@/Composables/useAccountFeatures';
import {
    assignGeoapifyAddress,
    buildAddressSearchLabel,
    useGeoapifyAddressAutocomplete,
} from '@/Composables/useGeoapifyAddressAutocomplete';

const props = defineProps({
    customer: Object,
    canEdit: {
        type: Boolean,
        default: false,
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
    schedule: {
        type: Object,
        default: () => ({ tasks: [], upcomingJobs: [] }),
    },
    billing: {
        type: Object,
        default: () => ({ summary: {}, recentPayments: [] }),
    },
    loyalty: {
        type: Object,
        default: () => ({
            enabled: true,
            label: 'points',
            balance: 0,
            rate: 1,
            minimum_spend: 0,
            rounding_mode: 'floor',
            recent: [],
        }),
    },
    activity: {
        type: Array,
        default: () => [],
    },
    lastInteraction: {
        type: Object,
        default: null,
    },
    sales: {
        type: Array,
        default: () => [],
    },
    salesSummary: {
        type: Object,
        default: null,
    },
    salesInsights: {
        type: Object,
        default: null,
    },
    topProducts: {
        type: Array,
        default: () => [],
    },
    vipTiers: {
        type: Array,
        default: () => [],
    },
    campaignsFeatureEnabled: {
        type: Boolean,
        default: false,
    },
    canManageMailingLists: {
        type: Boolean,
        default: false,
    },
    canLogSalesActivity: {
        type: Boolean,
        default: false,
    },
    salesActivityQuickActions: {
        type: Array,
        default: () => [],
    },
    salesActivityManualActions: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();

const page = usePage();
const { visibleFeaturePayload, hasFeature } = useAccountFeatures();
const companyType = computed(() => page.props.auth?.account?.company?.type ?? null);
const showSales = computed(() => companyType.value === 'products');
const showServiceOps = computed(() => companyType.value !== 'products');
const loyalty = computed(() => visibleFeaturePayload('loyalty', props.loyalty));
const quotesFeatureEnabled = computed(() => hasFeature('quotes'));
const requestsFeatureEnabled = computed(() => hasFeature('requests'));
const jobsFeatureEnabled = computed(() => hasFeature('jobs'));
const tasksFeatureEnabled = computed(() => hasFeature('tasks'));
const invoicesFeatureEnabled = computed(() => hasFeature('invoices'));
const campaignsFeatureEnabled = computed(() => Boolean(props.campaignsFeatureEnabled) && hasFeature('campaigns'));
const loyaltyFeatureEnabled = computed(() => hasFeature('loyalty') && Boolean(loyalty.value));

const properties = computed(() => props.customer?.properties || []);
const tags = computed(() => props.customer?.tags || []);
const vipTiers = computed(() => props.vipTiers || []);
const latestQuote = computed(() => (props.customer?.quotes || [])[0] || null);
const latestWork = computed(() => (props.customer?.works || [])[0] || null);
const latestInvoice = computed(() => (props.customer?.invoices || [])[0] || null);
const showBillingHistory = computed(() => showServiceOps.value && invoicesFeatureEnabled.value);

const formatDate = (value) => humanizeDate(value);
const { formatCurrency } = useCurrencyFormatter();
const formatNumber = (value, fractionDigits = 0) =>
    Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: fractionDigits,
        maximumFractionDigits: fractionDigits,
    });
const formatStatus = (status, keyPrefix = '') => {
    if (!status) {
        return t('customers.labels.unknown_status');
    }
    if (keyPrefix) {
        const key = `${keyPrefix}.${status}`;
        const translated = t(key);
        if (translated && translated !== key) {
            return translated;
        }
    }
    return String(status).replace(/_/g, ' ');
};
const hasValue = (value) => value !== null && value !== undefined;
const topProducts = computed(() => props.topProducts || []);
const loyaltyPointLabel = computed(() => loyalty.value?.label || t('customers.details.loyalty.points_unit'));
const loyaltyRecent = computed(() => loyalty.value?.recent || []);
const loyaltyRoundingLabel = computed(() => {
    const mode = loyalty.value?.rounding_mode || 'floor';
    const key = `customers.details.loyalty.rounding_modes.${mode}`;
    const translated = t(key);

    return translated && translated !== key ? translated : mode;
});
const formatSignedPoints = (value) => {
    const points = Number(value || 0);
    const prefix = points > 0 ? '+' : '';

    return `${prefix}${formatNumber(points)}`;
};
const loyaltyEventLabel = (event) => {
    const key = `customers.details.loyalty.event_${event || 'accrual'}`;
    const translated = t(key);
    if (translated && translated !== key) {
        return translated;
    }

    return String(event || 'accrual');
};
const purchaseCards = computed(() => {
    const insights = props.salesInsights || {};
    const numberLabel = (value, fractionDigits = 0) => {
        if (!hasValue(value)) {
            return '-';
        }
        return Number(value).toLocaleString(undefined, {
            minimumFractionDigits: fractionDigits,
            maximumFractionDigits: fractionDigits,
        });
    };
    const preferred = [insights.preferred_day, insights.preferred_period].filter(Boolean).join(' - ');

    return [
        {
            label: t('customers.details.purchase.last_purchase'),
            value: insights.last_purchase_at ? formatDate(insights.last_purchase_at) : t('customers.labels.none'),
        },
        {
            label: t('customers.details.purchase.days_since'),
            value: hasValue(insights.days_since_last_purchase)
                ? t('customers.details.days_label', { count: numberLabel(insights.days_since_last_purchase) })
                : t('customers.labels.none'),
        },
        {
            label: t('customers.details.purchase.average_order'),
            value: formatCurrency(insights.average_order_value || 0),
        },
        {
            label: t('customers.details.purchase.average_items'),
            value: hasValue(insights.average_items) ? numberLabel(insights.average_items, 1) : t('customers.labels.none'),
        },
        {
            label: t('customers.details.purchase.frequency'),
            value: hasValue(insights.purchase_frequency_days)
                ? t('customers.details.days_label', { count: numberLabel(insights.purchase_frequency_days, 1) })
                : t('customers.labels.none'),
        },
        {
            label: t('customers.details.purchase.recent_30'),
            value: numberLabel(insights.recent_30_count || 0),
        },
        {
            label: t('customers.details.purchase.preference'),
            value: preferred || t('customers.labels.none'),
        },
    ];
});

const propertyTypes = computed(() => [
    { id: 'physical', name: t('customers.properties.types.physical') },
    { id: 'billing', name: t('customers.properties.types.billing') },
    { id: 'other', name: t('customers.properties.types.other') },
]);

const propertyTypeLabel = (type) => propertyTypes.value.find((option) => option.id === type)?.name || type;

const propertyHeading = (property) => {
    const chunks = [propertyTypeLabel(property.type), property.country].filter(Boolean);
    return chunks.join(' - ') || t('customers.properties.fallback');
};

const editingTags = ref(false);
const tagsForm = useForm({
    tags: (props.customer?.tags || []).join(', '),
});

const startEditTags = () => {
    tagsForm.tags = (props.customer?.tags || []).join(', ');
    tagsForm.clearErrors();
    editingTags.value = true;
};

const cancelEditTags = () => {
    tagsForm.clearErrors();
    editingTags.value = false;
};

const submitTags = () => {
    if (tagsForm.processing) {
        return;
    }

    tagsForm.patch(route('customer.tags.update', props.customer.id), {
        preserveScroll: true,
        onSuccess: () => cancelEditTags(),
    });
};

const editingNotes = ref(false);
const notesForm = useForm({
    description: props.customer?.description || '',
});

const startEditNotes = () => {
    notesForm.description = props.customer?.description || '';
    notesForm.clearErrors();
    editingNotes.value = true;
};

const cancelEditNotes = () => {
    notesForm.clearErrors();
    editingNotes.value = false;
};

const submitNotes = () => {
    if (notesForm.processing) {
        return;
    }

    notesForm.patch(route('customer.notes.update', props.customer.id), {
        preserveScroll: true,
        onSuccess: () => cancelEditNotes(),
    });
};

const autoValidationForm = useForm({
    auto_accept_quotes: props.customer?.auto_accept_quotes ?? false,
    auto_validate_jobs: props.customer?.auto_validate_jobs ?? false,
    auto_validate_tasks: props.customer?.auto_validate_tasks ?? false,
    auto_validate_invoices: props.customer?.auto_validate_invoices ?? false,
});

const autoValidationOptions = computed(() => {
    const options = [];

    if (quotesFeatureEnabled.value) {
        options.push({
            id: 'customer-auto-accept-quotes',
            field: 'auto_accept_quotes',
            label: t('customers.details.auto_validation.quotes'),
        });
    }

    if (jobsFeatureEnabled.value) {
        options.push({
            id: 'customer-auto-validate-jobs',
            field: 'auto_validate_jobs',
            label: t('customers.details.auto_validation.jobs'),
        });
    }

    if (tasksFeatureEnabled.value) {
        options.push({
            id: 'customer-auto-validate-tasks',
            field: 'auto_validate_tasks',
            label: t('customers.details.auto_validation.tasks'),
        });
    }

    if (invoicesFeatureEnabled.value) {
        options.push({
            id: 'customer-auto-validate-invoices',
            field: 'auto_validate_invoices',
            label: t('customers.details.auto_validation.invoices'),
        });
    }

    return options;
});
const showAutomationRail = computed(() => showServiceOps.value && autoValidationOptions.value.length > 0);

const submitAutoValidation = () => {
    if (autoValidationForm.processing) {
        return;
    }

    autoValidationForm.patch(route('customer.auto-validation.update', props.customer.id), {
        preserveScroll: true,
    });
};

const editingVip = ref(false);
const vipForm = useForm({
    is_vip: Boolean(props.customer?.is_vip ?? false),
    vip_tier_id: props.customer?.vip_tier_id || '',
});

const startEditVip = () => {
    vipForm.is_vip = Boolean(props.customer?.is_vip ?? false);
    vipForm.vip_tier_id = props.customer?.vip_tier_id || '';
    vipForm.clearErrors();
    editingVip.value = true;
};

const cancelEditVip = () => {
    vipForm.clearErrors();
    editingVip.value = false;
};

const submitVip = () => {
    if (vipForm.processing) {
        return;
    }

    vipForm.transform((data) => ({
        ...data,
        vip_tier_id: data.is_vip && data.vip_tier_id ? Number(data.vip_tier_id) : null,
    })).patch(route('marketing.vip.customer.update', props.customer.id), {
        preserveScroll: true,
        onSuccess: () => cancelEditVip(),
    });
};

const mailingListDialogOpen = ref(false);
const mailingListRows = ref([]);
const mailingListLoading = ref(false);
const mailingListBusy = ref(false);
const mailingListError = ref('');
const mailingListInfo = ref('');
const selectedMailingListId = ref('');

const canManageMailingLists = computed(() => Boolean(props.canManageMailingLists) && campaignsFeatureEnabled.value);
const showMarketingRail = computed(() => campaignsFeatureEnabled.value || canManageMailingLists.value);
const selectedMailingListLabel = computed(() => {
    const id = Number(selectedMailingListId.value || 0);
    const list = mailingListRows.value.find((row) => Number(row?.id || 0) === id);
    return list?.name || '';
});
const mailingListOptions = computed(() => ([
    { id: '', name: t('customers.details.mailing_lists.select_placeholder') },
    ...mailingListRows.value.map((list) => ({
        id: String(list.id),
        name: `${list.name} (${list.customers_count || 0})`,
    })),
]));

const loadMailingLists = async () => {
    mailingListLoading.value = true;
    mailingListError.value = '';
    try {
        const response = await axios.get(route('marketing.mailing-lists.index'));
        mailingListRows.value = Array.isArray(response.data?.mailing_lists) ? response.data.mailing_lists : [];
    } catch (error) {
        mailingListError.value = error?.response?.data?.message || error?.message || t('customers.details.mailing_lists.error_load');
    } finally {
        mailingListLoading.value = false;
    }
};

const openMailingListDialog = async () => {
    if (!canManageMailingLists.value) {
        return;
    }

    mailingListDialogOpen.value = true;
    mailingListError.value = '';
    await loadMailingLists();
};

const closeMailingListDialog = () => {
    mailingListDialogOpen.value = false;
    mailingListError.value = '';
};

const addCustomerToMailingList = async () => {
    const listId = Number(selectedMailingListId.value || 0);
    const customerId = Number(props.customer?.id || 0);
    if (!listId || !customerId || mailingListBusy.value) {
        return;
    }

    mailingListBusy.value = true;
    mailingListError.value = '';
    mailingListInfo.value = '';
    try {
        const response = await axios.post(route('marketing.mailing-lists.import', listId), {
            customer_ids: [customerId],
        });
        const added = Number(response.data?.result?.added || 0);
        const alreadyPresent = Number(response.data?.result?.already_present || 0);
        const total = Number(response.data?.result?.total || 0);
        mailingListInfo.value = t('customers.details.mailing_lists.info_added', {
            list: selectedMailingListLabel.value || `#${listId}`,
            added,
            alreadyPresent,
            total,
        });
        closeMailingListDialog();
    } catch (error) {
        mailingListError.value = error?.response?.data?.message || error?.message || t('customers.details.mailing_lists.error_add');
    } finally {
        mailingListBusy.value = false;
    }
};

const rightRailTabs = computed(() => {
    const tabs = [
        { id: 'profile', label: t('customers.details.sidebar.tabs.profile') },
    ];

    if (showMarketingRail.value) {
        tabs.push({ id: 'marketing', label: t('customers.details.sidebar.tabs.marketing') });
    }

    if (showAutomationRail.value) {
        tabs.push({ id: 'automation', label: t('customers.details.sidebar.tabs.automation') });
    }

    if (showBillingHistory.value || loyaltyFeatureEnabled.value) {
        tabs.push({ id: 'finance', label: t('customers.details.sidebar.tabs.finance') });
    }

    return tabs;
});
const activeRightRailTab = ref('profile');

watch(rightRailTabs, (tabs) => {
    if (!tabs.some((tab) => tab.id === activeRightRailTab.value)) {
        activeRightRailTab.value = tabs[0]?.id || 'profile';
    }
}, { immediate: true });

const showPlanningWorkspace = computed(() => showServiceOps.value && (jobsFeatureEnabled.value || tasksFeatureEnabled.value));
const activityWorkspaceTabs = computed(() => {
    const tabs = [];

    if (showPlanningWorkspace.value) {
        tabs.push({ id: 'planning', label: t('customers.details.schedule.title') });
    }

    tabs.push({ id: 'crm', label: t('customers.details.sales_activity.title') });

    return tabs;
});
const activeActivityWorkspaceTab = ref('planning');

watch(activityWorkspaceTabs, (tabs) => {
    if (!tabs.some((tab) => tab.id === activeActivityWorkspaceTab.value)) {
        activeActivityWorkspaceTab.value = tabs[0]?.id || 'crm';
    }
}, { immediate: true });

const planningTabs = computed(() => {
    const tabs = [];

    if (jobsFeatureEnabled.value) {
        tabs.push({
            id: 'upcoming_jobs',
            label: t('customers.details.schedule.upcoming_jobs'),
            count: (props.schedule?.upcomingJobs || []).length,
        });
    }

    if (tasksFeatureEnabled.value) {
        tabs.push({
            id: 'tasks',
            label: t('customers.details.schedule.tasks'),
            count: (props.schedule?.tasks || []).length,
        });
    }

    return tabs;
});
const activePlanningTab = ref('upcoming_jobs');

watch(planningTabs, (tabs) => {
    if (!tabs.some((tab) => tab.id === activePlanningTab.value)) {
        activePlanningTab.value = tabs[0]?.id || 'upcoming_jobs';
    }
}, { immediate: true });

const activityHref = (log) => {
    const type = log?.subject_type || '';
    const id = log?.subject_id;

    if (!id) {
        return null;
    }

    if (type.endsWith('Request')) {
        return route('prospects.show', id);
    }

    if (type.endsWith('Quote')) {
        return route('customer.quote.show', id);
    }

    if (type.endsWith('Invoice')) {
        return route('invoice.show', id);
    }

    if (type.endsWith('Work')) {
        return route('work.show', id);
    }

    if (type.endsWith('Customer')) {
        return route('customer.show', props.customer.id);
    }

    return null;
};

const showAddProperty = ref(false);
const editingPropertyId = ref(null);

const newPropertyForm = useForm({
    type: 'physical',
    is_default: false,
    street1: '',
    street2: '',
    city: '',
    state: '',
    zip: '',
    country: '',
});

const editPropertyForm = useForm({
    type: 'physical',
    street1: '',
    street2: '',
    city: '',
    state: '',
    zip: '',
    country: '',
});

const {
    query: newPropertyAddressQuery,
    suggestions: newPropertyAddressSuggestions,
    searchAddress: searchNewPropertyAddress,
    selectAddress: selectNewPropertyAddress,
    resetSearch: resetNewPropertyAddressSearch,
} = useGeoapifyAddressAutocomplete({
    onSelect: (details) => {
        assignGeoapifyAddress(newPropertyForm, details);
    },
});

const {
    query: editPropertyAddressQuery,
    suggestions: editPropertyAddressSuggestions,
    searchAddress: searchEditPropertyAddress,
    selectAddress: selectEditPropertyAddress,
    resetSearch: resetEditPropertyAddressSearch,
} = useGeoapifyAddressAutocomplete({
    onSelect: (details) => {
        assignGeoapifyAddress(editPropertyForm, details);
    },
});

const resetNewPropertyForm = () => {
    newPropertyForm.reset();
    newPropertyForm.type = 'physical';
    newPropertyForm.is_default = false;
    newPropertyForm.clearErrors();
    resetNewPropertyAddressSearch();
};

const cancelEditProperty = () => {
    editingPropertyId.value = null;
    editPropertyForm.reset();
    editPropertyForm.clearErrors();
    resetEditPropertyAddressSearch();
};

const startAddProperty = () => {
    cancelEditProperty();
    resetNewPropertyForm();
    showAddProperty.value = true;
};

const cancelAddProperty = () => {
    showAddProperty.value = false;
    resetNewPropertyForm();
};

const submitNewProperty = () => {
    if (newPropertyForm.processing) {
        return;
    }

    newPropertyForm.post(route('customer.properties.store', { customer: props.customer.id }), {
        preserveScroll: true,
        onSuccess: () => cancelAddProperty(),
    });
};

const startEditProperty = (property) => {
    showAddProperty.value = false;
    resetNewPropertyForm();

    editingPropertyId.value = property.id;
    editPropertyForm.clearErrors();
    editPropertyForm.type = property.type || 'physical';
    editPropertyForm.street1 = property.street1 || '';
    editPropertyForm.street2 = property.street2 || '';
    editPropertyForm.city = property.city || '';
    editPropertyForm.state = property.state || '';
    editPropertyForm.zip = property.zip || '';
    editPropertyForm.country = property.country || '';
    resetEditPropertyAddressSearch(buildAddressSearchLabel(property));
};

const submitEditProperty = () => {
    if (!editingPropertyId.value || editPropertyForm.processing) {
        return;
    }

    editPropertyForm.put(
        route('customer.properties.update', {
            customer: props.customer.id,
            property: editingPropertyId.value,
        }),
        {
            preserveScroll: true,
            onSuccess: () => cancelEditProperty(),
        }
    );
};

const setDefaultProperty = (property) => {
    if (property.is_default) {
        return;
    }

    router.put(
        route('customer.properties.default', {
            customer: props.customer.id,
            property: property.id,
        }),
        {},
        { preserveScroll: true }
    );
};

const deleteProperty = (property) => {
    if (!confirm(t('customers.properties.delete_confirm'))) {
        return;
    }

    router.delete(
        route('customer.properties.destroy', {
            customer: props.customer.id,
            property: property.id,
        }),
        { preserveScroll: true }
    );
};
</script>

<template>
    <Head :title="customer.company_name || `${customer.first_name || ''} ${customer.last_name || ''}`.trim() || $t('customers.labels.customer_fallback')" />
    <AuthenticatedLayout>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
            <div class="md:col-span-2 rise-stagger">
                <Header :customer="customer" :can-edit="canEdit" />

                <Card v-if="showSales" class="mt-5">
                    <template #title>
                        <div class="flex items-center justify-between gap-3">
                            <span>{{ $t('customers.details.sales.title') }}</span>
                            <Link
                                :href="route('sales.index')"
                                class="text-xs font-semibold text-green-700 hover:underline dark:text-green-400"
                            >
                                {{ $t('customers.details.sales.view_all') }}
                            </Link>
                        </div>
                    </template>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            <div class="text-xs uppercase text-stone-400">{{ $t('customers.details.sales.count') }}</div>
                            <div class="mt-1 text-lg font-semibold">{{ salesSummary?.count || 0 }}</div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            <div class="text-xs uppercase text-stone-400">{{ $t('customers.details.sales.paid') }}</div>
                            <div class="mt-1 text-lg font-semibold">{{ formatCurrency(salesSummary?.paid || 0) }}</div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            <div class="text-xs uppercase text-stone-400">{{ $t('customers.details.sales.total') }}</div>
                            <div class="mt-1 text-lg font-semibold">{{ formatCurrency(salesSummary?.total || 0) }}</div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <div v-if="!sales.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('customers.details.sales.empty') }}
                        </div>
                        <div v-else class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <div v-for="sale in sales" :key="sale.id" class="flex items-center justify-between gap-3 py-3 text-sm">
                                <div>
                                    <Link
                                        :href="route('sales.show', sale.id)"
                                        class="font-semibold text-stone-800 hover:underline dark:text-neutral-200"
                                    >
                                        {{ sale.number || $t('customers.details.sales.sale_fallback', { id: sale.id }) }}
                                    </Link>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatDate(sale.created_at) }}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="font-semibold text-stone-800 dark:text-neutral-200">
                                        {{ formatCurrency(sale.total) }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatStatus(sale.status) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>

                <Card v-if="showSales" class="mt-5">
                    <template #title>{{ $t('customers.details.purchase.title') }}</template>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <div
                            v-for="card in purchaseCards"
                            :key="card.label"
                            class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        >
                            <div class="text-xs uppercase text-stone-400">{{ card.label }}</div>
                            <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ card.value }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-5">
                        <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-200">{{ $t('customers.details.top_products.title') }}</h3>

                        <div v-if="topProducts.length" class="mt-3 space-y-2">
                            <div
                                v-for="product in topProducts"
                                :key="product.id"
                                class="flex items-center justify-between gap-3 rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700"
                            >
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex h-12 w-12 items-center justify-center overflow-hidden rounded-sm border border-stone-200 bg-white text-xs font-semibold text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                                    >
                                        <img
                                            v-if="product.image"
                                            :src="product.image"
                                            :alt="product.name || $t('customers.details.top_products.product_fallback')"
                                            class="h-full w-full object-cover"
                                            loading="lazy"
                                            decoding="async"
                                        />
                                        <span v-else>{{ (product.name || $t('customers.details.top_products.initial_fallback')).charAt(0).toUpperCase() }}</span>
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                                            {{ product.name || $t('customers.details.top_products.product_fallback') }}
                                        </div>
                                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ product.sku || $t('customers.details.top_products.sku_fallback') }}
                                        </div>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-200">
                                        {{ formatNumber(product.quantity) }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatCurrency(product.total) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="mt-2 text-sm text-stone-500 dark:text-neutral-400">{{ $t('customers.details.top_products.empty') }}</div>
                    </div>
                </Card>

                <Card class="mt-5">
                    <template #title>
                        <div class="flex items-center justify-between gap-3">
                            <span>{{ $t('customers.properties.title') }}</span>
                            <button
                                type="button"
                                @click="showAddProperty ? cancelAddProperty() : startAddProperty()"
                                class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500"
                            >
                                {{ $t('customers.properties.add') }}
                            </button>
                        </div>
                    </template>

                    <div
                        v-if="showAddProperty"
                        class="mb-6 rounded-sm border border-stone-200 bg-stone-50 p-4 dark:bg-neutral-900 dark:border-neutral-700"
                    >
                        <form class="space-y-3" @submit.prevent="submitNewProperty">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <FloatingSelect v-model="newPropertyForm.type" :label="$t('customers.properties.fields.type')" :options="propertyTypes" />
                                    <InputError class="mt-1" :message="newPropertyForm.errors.type" />
                                </div>
                                <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                    <input
                                        type="checkbox"
                                        v-model="newPropertyForm.is_default"
                                        class="size-3.5 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700"
                                    />
                                    {{ $t('customers.properties.set_default') }}
                                </label>
                                <div class="md:col-span-2">
                                    <div class="relative">
                                        <div class="relative">
                                            <div class="absolute inset-y-0 start-0 z-20 flex items-center pointer-events-none ps-3.5">
                                                <svg
                                                    class="size-4 shrink-0 text-stone-400 dark:text-white/60"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    width="24"
                                                    height="24"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="2"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                >
                                                    <circle cx="11" cy="11" r="8"></circle>
                                                    <path d="m21 21-4.3-4.3"></path>
                                                </svg>
                                            </div>
                                            <input
                                                v-model="newPropertyAddressQuery"
                                                @input="searchNewPropertyAddress"
                                                class="block w-full rounded-sm border-stone-200 py-3 pe-4 ps-10 text-sm focus:border-green-600 focus:ring-green-600"
                                                type="text"
                                                role="combobox"
                                                aria-expanded="false"
                                                :placeholder="$t('customers.form.fields.search_address')"
                                            />
                                        </div>

                                        <div
                                            v-if="newPropertyAddressSuggestions.length"
                                            class="absolute z-50 w-full rounded-sm bg-white shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:bg-neutral-800"
                                        >
                                            <div
                                                class="max-h-[300px] overflow-hidden overflow-y-auto p-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-stone-300 [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar]:w-2 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500 dark:[&::-webkit-scrollbar-track]:bg-neutral-700"
                                            >
                                                <div
                                                    v-for="suggestion in newPropertyAddressSuggestions"
                                                    :key="suggestion.id"
                                                    class="flex cursor-pointer items-center gap-x-3 rounded-sm px-3 py-2 hover:bg-stone-100 dark:hover:bg-neutral-700"
                                                    @click="selectNewPropertyAddress(suggestion.details)"
                                                >
                                                    <span class="text-sm text-stone-800 dark:text-neutral-200">
                                                        {{ suggestion.label }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.street1" :label="$t('customers.properties.fields.street1')" />
                                        <InputError class="mt-1" :message="newPropertyForm.errors.street1" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.street2" :label="$t('customers.properties.fields.street2')" />
                                        <InputError class="mt-1" :message="newPropertyForm.errors.street2" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.city" :label="$t('customers.properties.fields.city')" />
                                        <InputError class="mt-1" :message="newPropertyForm.errors.city" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.state" :label="$t('customers.properties.fields.state')" />
                                        <InputError class="mt-1" :message="newPropertyForm.errors.state" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.zip" :label="$t('customers.properties.fields.zip')" />
                                        <InputError class="mt-1" :message="newPropertyForm.errors.zip" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.country" :label="$t('customers.properties.fields.country')" />
                                        <InputError class="mt-1" :message="newPropertyForm.errors.country" />
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    @click="cancelAddProperty"
                                    class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700"
                                >
                                    {{ $t('customers.actions.cancel') }}
                                </button>
                                <button
                                    type="submit"
                                    :disabled="newPropertyForm.processing"
                                    class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                                >
                                    {{ $t('customers.actions.save') }}
                                </button>
                            </div>
                        </form>
                    </div>

                    <div v-if="!properties.length" class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('customers.properties.empty') }}
                    </div>

                    <ul v-else class="flex flex-col divide-y divide-stone-200 dark:divide-neutral-700">
                        <li v-for="property in properties" :key="property.id" class="py-4 first:pt-0 last:pb-0">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex gap-x-3">
                                    <div class="py-2.5 px-3 border rounded-sm dark:border-neutral-700">
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            width="24"
                                            height="24"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            class="lucide lucide-house"
                                        >
                                            <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" />
                                            <path
                                                d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"
                                            />
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <p class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                                                {{ propertyHeading(property) }}
                                            </p>
                                            <span
                                                v-if="property.is_default"
                                                class="inline-flex items-center rounded-sm bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-500/10 dark:text-green-400"
                                            >
                                                {{ $t('customers.properties.default_label') }}
                                            </span>
                                        </div>
                                        <p class="text-xs text-stone-500 dark:text-neutral-500">
                                            {{ property.street1
                                            }}<span v-if="property.street2">, {{ property.street2 }}</span>
                                        </p>
                                        <p class="text-xs text-stone-500 dark:text-neutral-500">
                                            {{ property.city }}<span v-if="property.state"> - {{ property.state }}</span
                                            ><span v-if="property.zip"> - {{ property.zip }}</span>
                                        </p>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-2 justify-end">
                                    <button
                                        type="button"
                                        :disabled="property.is_default"
                                        @click="setDefaultProperty(property)"
                                        class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700"
                                    >
                                        {{ $t('customers.properties.set_default') }}
                                    </button>
                                    <button
                                        type="button"
                                        @click="startEditProperty(property)"
                                        class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-stone-200 text-stone-800 hover:bg-stone-300 focus:outline-none focus:bg-stone-300 dark:bg-neutral-600 dark:text-neutral-200 dark:hover:bg-neutral-500"
                                    >
                                        {{ $t('customers.actions.edit') }}
                                    </button>
                                    <button
                                        type="button"
                                        @click="deleteProperty(property)"
                                        class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-red-100 text-red-700 hover:bg-red-200 focus:outline-none focus:bg-red-200 dark:bg-red-500/10 dark:text-red-400 dark:hover:bg-red-500/20"
                                    >
                                        {{ $t('customers.actions.delete') }}
                                    </button>
                                </div>
                            </div>

                            <div
                                v-if="editingPropertyId === property.id"
                                class="mt-4 rounded-sm border border-stone-200 bg-stone-50 p-4 dark:bg-neutral-900 dark:border-neutral-700"
                            >
                                <form class="space-y-3" @submit.prevent="submitEditProperty">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <div>
                                            <FloatingSelect v-model="editPropertyForm.type" :label="$t('customers.properties.fields.type')" :options="propertyTypes" />
                                            <InputError class="mt-1" :message="editPropertyForm.errors.type" />
                                        </div>
                                        <div></div>
                                        <div class="md:col-span-2">
                                            <div class="relative">
                                                <div class="relative">
                                                    <div class="absolute inset-y-0 start-0 z-20 flex items-center pointer-events-none ps-3.5">
                                                        <svg
                                                            class="size-4 shrink-0 text-stone-400 dark:text-white/60"
                                                            xmlns="http://www.w3.org/2000/svg"
                                                            width="24"
                                                            height="24"
                                                            viewBox="0 0 24 24"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            stroke-width="2"
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                        >
                                                            <circle cx="11" cy="11" r="8"></circle>
                                                            <path d="m21 21-4.3-4.3"></path>
                                                        </svg>
                                                    </div>
                                                    <input
                                                        v-model="editPropertyAddressQuery"
                                                        @input="searchEditPropertyAddress"
                                                        class="block w-full rounded-sm border-stone-200 py-3 pe-4 ps-10 text-sm focus:border-green-600 focus:ring-green-600"
                                                        type="text"
                                                        role="combobox"
                                                        aria-expanded="false"
                                                        :placeholder="$t('customers.form.fields.search_address')"
                                                    />
                                                </div>

                                                <div
                                                    v-if="editPropertyAddressSuggestions.length"
                                                    class="absolute z-50 w-full rounded-sm bg-white shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:bg-neutral-800"
                                                >
                                                    <div
                                                        class="max-h-[300px] overflow-hidden overflow-y-auto p-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-stone-300 [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar]:w-2 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500 dark:[&::-webkit-scrollbar-track]:bg-neutral-700"
                                                    >
                                                        <div
                                                            v-for="suggestion in editPropertyAddressSuggestions"
                                                            :key="suggestion.id"
                                                            class="flex cursor-pointer items-center gap-x-3 rounded-sm px-3 py-2 hover:bg-stone-100 dark:hover:bg-neutral-700"
                                                            @click="selectEditPropertyAddress(suggestion.details)"
                                                        >
                                                            <span class="text-sm text-stone-800 dark:text-neutral-200">
                                                                {{ suggestion.label }}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-3">
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.street1" :label="$t('customers.properties.fields.street1')" />
                                                <InputError class="mt-1" :message="editPropertyForm.errors.street1" />
                                            </div>
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.street2" :label="$t('customers.properties.fields.street2')" />
                                                <InputError class="mt-1" :message="editPropertyForm.errors.street2" />
                                            </div>
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.city" :label="$t('customers.properties.fields.city')" />
                                                <InputError class="mt-1" :message="editPropertyForm.errors.city" />
                                            </div>
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.state" :label="$t('customers.properties.fields.state')" />
                                                <InputError class="mt-1" :message="editPropertyForm.errors.state" />
                                            </div>
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.zip" :label="$t('customers.properties.fields.zip')" />
                                                <InputError class="mt-1" :message="editPropertyForm.errors.zip" />
                                            </div>
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.country" :label="$t('customers.properties.fields.country')" />
                                                <InputError class="mt-1" :message="editPropertyForm.errors.country" />
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            @click="cancelEditProperty"
                                            class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700"
                                        >
                                            {{ $t('customers.actions.cancel') }}
                                        </button>
                                        <button
                                            type="submit"
                                            :disabled="editPropertyForm.processing"
                                            class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                                        >
                                            {{ $t('customers.actions.save_changes') }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </li>
                    </ul>
                </Card>

                <CardNav v-if="showServiceOps" class="mt-5" :customer="customer" />

                <Card class="mt-5">
                    <template #title>{{ $t('customers.details.workspace.title') }}</template>

                    <div v-if="activityWorkspaceTabs.length > 1" class="mb-4 flex gap-2 overflow-x-auto pb-1">
                        <button
                            v-for="tab in activityWorkspaceTabs"
                            :key="tab.id"
                            type="button"
                            @click="activeActivityWorkspaceTab = tab.id"
                            class="inline-flex whitespace-nowrap rounded-full border px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus:ring-2 focus:ring-green-500"
                            :class="activeActivityWorkspaceTab === tab.id
                                ? 'border-green-600 bg-green-600 text-white shadow-sm'
                                : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                        >
                            {{ tab.label }}
                        </button>
                    </div>

                    <template v-if="activeActivityWorkspaceTab === 'planning'">
                        <div v-if="planningTabs.length > 1" class="mb-4 flex gap-2 overflow-x-auto pb-1">
                            <button
                                v-for="tab in planningTabs"
                                :key="tab.id"
                                type="button"
                                @click="activePlanningTab = tab.id"
                                class="inline-flex items-center gap-2 whitespace-nowrap rounded-full border px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus:ring-2 focus:ring-green-500"
                                :class="activePlanningTab === tab.id
                                    ? 'border-stone-900 bg-stone-900 text-white shadow-sm dark:border-white dark:bg-white dark:text-stone-900'
                                    : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                            >
                                <span>{{ tab.label }}</span>
                                <span
                                    class="inline-flex min-w-5 items-center justify-center rounded-full px-1.5 py-0.5 text-[11px]"
                                    :class="activePlanningTab === tab.id
                                        ? 'bg-white/20 text-white dark:bg-stone-900/10 dark:text-stone-900'
                                        : 'bg-stone-100 text-stone-600 dark:bg-neutral-800 dark:text-neutral-300'"
                                >
                                    {{ tab.count }}
                                </span>
                            </button>
                        </div>

                        <div
                            v-if="activePlanningTab === 'upcoming_jobs'"
                            class="rounded-sm border border-stone-200 bg-stone-50/70 p-4 dark:border-neutral-700 dark:bg-neutral-900/60"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-200">{{ $t('customers.details.schedule.upcoming_jobs') }}</h3>
                                <Link
                                    :href="route('jobs.index')"
                                    class="text-xs font-medium text-green-700 hover:underline dark:text-green-400"
                                >
                                    {{ $t('customers.details.schedule.view_all') }}
                                </Link>
                            </div>
                            <div class="mt-3 space-y-2">
                                <div
                                    v-for="work in schedule?.upcomingJobs || []"
                                    :key="work.id"
                                    class="flex items-center justify-between gap-3 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    <div>
                                        <Link
                                            :href="route('work.show', work.id)"
                                            class="font-medium text-stone-800 hover:underline dark:text-neutral-200"
                                        >
                                            {{ work.job_title }}
                                        </Link>
                                        <div class="mt-0.5 text-xs text-stone-500 dark:text-neutral-400">
                                            {{ $t('customers.details.schedule.starts') }} {{ formatDate(work.start_date || work.created_at) }}
                                        </div>
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatStatus(work.status, 'jobs.status') }}
                                    </div>
                                </div>
                                <div
                                    v-if="!(schedule?.upcomingJobs || []).length"
                                    class="text-sm text-stone-500 dark:text-neutral-400"
                                >
                                    {{ $t('customers.details.schedule.no_upcoming_jobs') }}
                                </div>
                            </div>
                        </div>

                        <div
                            v-else-if="activePlanningTab === 'tasks'"
                            class="rounded-sm border border-stone-200 bg-stone-50/70 p-4 dark:border-neutral-700 dark:bg-neutral-900/60"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-200">{{ $t('customers.details.schedule.tasks') }}</h3>
                                <Link
                                    :href="route('task.index')"
                                    class="text-xs font-medium text-green-700 hover:underline dark:text-green-400"
                                >
                                    {{ $t('customers.details.schedule.view_all') }}
                                </Link>
                            </div>
                            <div class="mt-3 space-y-2">
                                <div
                                    v-for="task in schedule?.tasks || []"
                                    :key="task.id"
                                    class="flex items-start justify-between gap-3 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    <div>
                                        <div class="font-medium text-stone-800 dark:text-neutral-200">
                                            {{ task.title }}
                                        </div>
                                        <div class="mt-0.5 text-xs text-stone-500 dark:text-neutral-400">
                                            <span v-if="task.due_date">{{ $t('customers.details.schedule.due') }} {{ formatDate(task.due_date) }}</span>
                                            <span v-else>{{ $t('customers.details.schedule.no_due_date') }}</span>
                                        </div>
                                    </div>
                                    <div class="text-right text-xs text-stone-500 dark:text-neutral-400">
                                        <div class="capitalize">{{ formatStatus(task.status, 'tasks.status') }}</div>
                                        <div v-if="task.assignee">{{ task.assignee }}</div>
                                    </div>
                                </div>
                                <div v-if="!(schedule?.tasks || []).length" class="text-sm text-stone-500 dark:text-neutral-400">
                                    {{ $t('customers.details.schedule.no_tasks') }}
                                </div>
                            </div>
                        </div>
                    </template>

                    <div v-else-if="activeActivityWorkspaceTab === 'crm'">
                        <SalesActivityPanel
                            :items="activity"
                            :can-log="canLogSalesActivity"
                            :quick-actions="salesActivityQuickActions"
                            :manual-actions="salesActivityManualActions"
                            :store-route="route('crm.sales-activities.customers.store', customer.id)"
                            :resolve-href="activityHref"
                            :show-subject="true"
                            i18n-prefix="customers.details.sales_activity"
                            dialog-id="customer-sales-activity-modal"
                            :embedded="true"
                        />
                    </div>
                </Card>
            </div>
            <div class="rise-stagger">
                <CustomerPreviewCard
                    v-if="showServiceOps"
                    :stats="stats"
                    :billing="billing"
                    :latest-quote="latestQuote"
                    :latest-work="latestWork"
                    :latest-invoice="latestInvoice"
                />
                <Card :class="showServiceOps ? 'mt-5' : ''">
                    <template #title>{{ $t('customers.details.sidebar.title') }}</template>

                    <div v-if="rightRailTabs.length > 1" class="mb-4 flex gap-2 overflow-x-auto pb-1">
                        <button
                            v-for="tab in rightRailTabs"
                            :key="tab.id"
                            type="button"
                            @click="activeRightRailTab = tab.id"
                            class="inline-flex whitespace-nowrap rounded-full border px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus:ring-2 focus:ring-green-500"
                            :class="activeRightRailTab === tab.id
                                ? 'border-green-600 bg-green-600 text-white shadow-sm'
                                : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                        >
                            {{ tab.label }}
                        </button>
                    </div>

                    <div class="space-y-4">
                        <template v-if="activeRightRailTab === 'profile'">
                            <div class="rounded-sm border border-stone-200 bg-stone-50/70 p-4 dark:border-neutral-700 dark:bg-neutral-900/60">
                                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('customers.details.contact.title') }}
                                </div>
                                <div class="mt-3">
                                    <DescriptionList :item="customer" />
                                </div>
                            </div>

                            <div class="rounded-sm border border-stone-200 bg-stone-50/70 p-4 dark:border-neutral-700 dark:bg-neutral-900/60">
                                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('customers.details.tags.title') }}
                                </div>

                                <div v-if="!editingTags" class="mt-3 space-y-3">
                                    <div v-if="tags.length" class="flex flex-wrap gap-2">
                                        <span
                                            v-for="tag in tags"
                                            :key="tag"
                                            class="inline-flex items-center rounded-sm bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-800 dark:bg-neutral-700 dark:text-neutral-200"
                                        >
                                            {{ tag }}
                                        </span>
                                    </div>
                                    <div v-else class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('customers.details.tags.empty') }}</div>

                                    <div class="flex justify-end">
                                        <button
                                            type="button"
                                            @click="startEditTags"
                                            class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                        >
                                            {{ $t('customers.actions.edit') }}
                                        </button>
                                    </div>
                                </div>

                                <form v-else class="mt-3 space-y-3" @submit.prevent="submitTags">
                                    <div>
                                        <FloatingInput v-model="tagsForm.tags" :label="$t('customers.details.tags.field')" />
                                        <InputError class="mt-1" :message="tagsForm.errors.tags" />
                                    </div>
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            @click="cancelEditTags"
                                            class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                        >
                                            {{ $t('customers.actions.cancel') }}
                                        </button>
                                        <button
                                            type="submit"
                                            :disabled="tagsForm.processing"
                                            class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                                        >
                                            {{ $t('customers.actions.save') }}
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <div class="rounded-sm border border-stone-200 bg-stone-50/70 p-4 dark:border-neutral-700 dark:bg-neutral-900/60">
                                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('customers.details.notes.title') }}
                                </div>

                                <div v-if="!editingNotes" class="mt-3 space-y-3">
                                    <p class="text-sm whitespace-pre-wrap text-stone-700 dark:text-neutral-200">
                                        {{ customer.description || $t('customers.details.notes.empty') }}
                                    </p>
                                    <div class="flex justify-end">
                                        <button
                                            type="button"
                                            @click="startEditNotes"
                                            class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                        >
                                            {{ $t('customers.actions.edit') }}
                                        </button>
                                    </div>
                                </div>

                                <form v-else class="mt-3 space-y-3" @submit.prevent="submitNotes">
                                    <div>
                                        <FloatingTextarea v-model="notesForm.description" :label="$t('customers.details.notes.field')" />
                                        <InputError class="mt-1" :message="notesForm.errors.description" />
                                    </div>
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            @click="cancelEditNotes"
                                            class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                        >
                                            {{ $t('customers.actions.cancel') }}
                                        </button>
                                        <button
                                            type="submit"
                                            :disabled="notesForm.processing"
                                            class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                                        >
                                            {{ $t('customers.actions.save') }}
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <div class="rounded-sm border border-stone-200 bg-stone-50/70 p-4 dark:border-neutral-700 dark:bg-neutral-900/60">
                                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('customers.details.last_interaction.title') }}
                                </div>

                                <div v-if="lastInteraction" class="mt-3 space-y-1 text-sm">
                                    <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                        {{ lastInteraction.subject }} • {{ formatDate(lastInteraction.created_at) }}
                                    </div>
                                    <div class="text-sm text-stone-800 dark:text-neutral-200">
                                        {{ lastInteraction.description || lastInteraction.action }}
                                    </div>
                                </div>
                                <div v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                                    {{ $t('customers.details.last_interaction.empty') }}
                                </div>
                            </div>
                        </template>

                        <template v-else-if="activeRightRailTab === 'marketing'">
                            <div
                                v-if="campaignsFeatureEnabled"
                                class="rounded-sm border border-stone-200 bg-stone-50/70 p-4 dark:border-neutral-700 dark:bg-neutral-900/60"
                            >
                                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    VIP
                                </div>

                                <div v-if="!editingVip" class="mt-3 space-y-3">
                                    <div class="flex items-center gap-2 text-sm">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="customer?.is_vip ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-200' : 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200'"
                                        >
                                            {{ customer?.is_vip ? 'VIP' : 'Standard' }}
                                        </span>
                                        <span v-if="customer?.is_vip" class="text-stone-600 dark:text-neutral-300">
                                            {{ customer?.vip_tier?.name || customer?.vip_tier_code || '-' }}
                                        </span>
                                    </div>

                                    <div class="flex justify-end">
                                        <button
                                            type="button"
                                            @click="startEditVip"
                                            class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                        >
                                            {{ $t('customers.actions.edit') }}
                                        </button>
                                    </div>
                                </div>

                                <form v-else class="mt-3 space-y-3" @submit.prevent="submitVip">
                                    <label class="flex items-center justify-between gap-3 text-sm text-stone-700 dark:text-neutral-200">
                                        <span>VIP customer</span>
                                        <input
                                            type="checkbox"
                                            v-model="vipForm.is_vip"
                                            class="relative w-11 h-6 p-px bg-stone-100 border-transparent text-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none checked:bg-none checked:text-green-600 checked:border-green-600 focus:checked:border-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-900

                                            before:inline-block before:size-5 before:bg-white checked:before:bg-white before:translate-x-0 checked:before:translate-x-full before:rounded-full before:shadow before:transform before:ring-0 before:transition before:ease-in-out before:duration-200 dark:before:bg-neutral-400 dark:checked:before:bg-white"
                                        />
                                    </label>

                                    <div v-if="vipForm.is_vip">
                                        <FloatingSelect
                                            v-model="vipForm.vip_tier_id"
                                            :label="'VIP tier'"
                                            :options="vipTiers.map((tier) => ({ id: tier.id, name: `${tier.name} (${tier.code})` }))"
                                        />
                                        <InputError class="mt-1" :message="vipForm.errors.vip_tier_id" />
                                    </div>

                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            @click="cancelEditVip"
                                            class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                        >
                                            {{ $t('customers.actions.cancel') }}
                                        </button>
                                        <button
                                            type="submit"
                                            :disabled="vipForm.processing"
                                            class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                                        >
                                            {{ $t('customers.actions.save') }}
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <div
                                v-if="canManageMailingLists"
                                class="rounded-sm border border-stone-200 bg-stone-50/70 p-4 dark:border-neutral-700 dark:bg-neutral-900/60"
                            >
                                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('customers.details.mailing_lists.title') }}
                                </div>

                                <p class="mt-3 text-sm text-stone-600 dark:text-neutral-300">
                                    {{ $t('customers.details.mailing_lists.description') }}
                                </p>

                                <div class="mt-3 flex justify-end">
                                    <button
                                        type="button"
                                        @click="openMailingListDialog"
                                        class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                                    >
                                        {{ $t('customers.details.mailing_lists.add_action') }}
                                    </button>
                                </div>

                                <p v-if="mailingListInfo" class="mt-2 text-xs text-emerald-700 dark:text-emerald-300">
                                    {{ mailingListInfo }}
                                </p>
                                <p v-if="mailingListError" class="mt-2 text-xs text-rose-600">
                                    {{ mailingListError }}
                                </p>
                            </div>
                        </template>

                        <template v-else-if="activeRightRailTab === 'automation'">
                            <div class="rounded-sm border border-stone-200 bg-stone-50/70 p-4 dark:border-neutral-700 dark:bg-neutral-900/60">
                                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('customers.details.auto_validation.title') }}
                                </div>

                                <form class="mt-3 space-y-3" @submit.prevent="submitAutoValidation">
                                    <div class="space-y-2">
                                        <label
                                            v-for="option in autoValidationOptions"
                                            :key="option.id"
                                            :for="option.id"
                                            class="flex items-center justify-between gap-3 text-sm text-stone-700 dark:text-neutral-200"
                                        >
                                            <span>{{ option.label }}</span>
                                            <input
                                                :id="option.id"
                                                type="checkbox"
                                                v-model="autoValidationForm[option.field]"
                                                class="relative w-11 h-6 p-px bg-stone-100 border-transparent text-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none checked:bg-none checked:text-green-600 checked:border-green-600 focus:checked:border-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-900

                                                before:inline-block before:size-5 before:bg-white checked:before:bg-white before:translate-x-0 checked:before:translate-x-full before:rounded-full before:shadow before:transform before:ring-0 before:transition before:ease-in-out before:duration-200 dark:before:bg-neutral-400 dark:checked:before:bg-white"
                                            />
                                        </label>
                                    </div>

                                    <div class="flex justify-end">
                                        <button
                                            type="submit"
                                            :disabled="autoValidationForm.processing"
                                            class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                                        >
                                            {{ $t('customers.actions.save') }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </template>

                        <template v-else-if="activeRightRailTab === 'finance'">
                            <div
                                v-if="showBillingHistory"
                                class="rounded-sm border border-stone-200 bg-stone-50/70 p-4 dark:border-neutral-700 dark:bg-neutral-900/60"
                            >
                                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('customers.details.billing_history.title') }}
                                </div>

                                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.billing_history.invoiced') }}</div>
                                        <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-200">
                                            {{ formatCurrency(billing?.summary?.total_invoiced) }}
                                        </div>
                                    </div>
                                    <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.billing_history.paid') }}</div>
                                        <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-200">
                                            {{ formatCurrency(billing?.summary?.total_paid) }}
                                        </div>
                                    </div>
                                    <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.billing_history.balance_due') }}</div>
                                        <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-200">
                                            {{ formatCurrency(billing?.summary?.balance_due) }}
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-5">
                                    <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-200">{{ $t('customers.details.billing_history.recent_payments') }}</h3>
                                    <div class="mt-3 space-y-2 text-sm">
                                        <div
                                            v-for="payment in billing?.recentPayments || []"
                                            :key="payment.id"
                                            class="flex items-start justify-between gap-3 rounded-sm border border-stone-200 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-900"
                                        >
                                            <div>
                                                <Link
                                                    v-if="payment.invoice"
                                                    :href="route('invoice.show', payment.invoice.id)"
                                                    class="font-medium text-stone-800 hover:underline dark:text-neutral-200"
                                                >
                                                    {{ payment.invoice.number || $t('customers.details.billing_history.invoice_fallback') }}
                                                </Link>
                                                <div v-else class="font-medium text-stone-800 dark:text-neutral-200">{{ $t('customers.details.billing_history.payment_fallback') }}</div>
                                                <div class="mt-0.5 text-xs text-stone-500 dark:text-neutral-400">
                                                    {{ $t('customers.details.billing_history.paid_on') }} {{ formatDate(payment.paid_at || payment.created_at) }}
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <div class="text-sm font-semibold text-stone-800 dark:text-neutral-200">
                                                    {{ formatCurrency(payment.amount) }}
                                                </div>
                                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                                    {{ payment.method || payment.status || '' }}
                                                </div>
                                            </div>
                                        </div>
                                        <div
                                            v-if="!(billing?.recentPayments || []).length"
                                            class="text-sm text-stone-500 dark:text-neutral-400"
                                        >
                                            {{ $t('customers.details.billing_history.empty') }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-if="loyaltyFeatureEnabled"
                                class="rounded-sm border border-stone-200 bg-stone-50/70 p-4 dark:border-neutral-700 dark:bg-neutral-900/60"
                            >
                                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('customers.details.loyalty.title') }}
                                </div>

                                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.loyalty.balance') }}</div>
                                        <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-200">
                                            {{ formatNumber(loyalty?.balance || 0) }} {{ loyaltyPointLabel }}
                                        </div>
                                    </div>
                                    <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.loyalty.earn_rate') }}</div>
                                        <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-200">
                                            {{ $t('customers.details.loyalty.rate_value', { rate: formatNumber(loyalty?.rate || 0, 2) }) }}
                                        </div>
                                        <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                            {{ $t('customers.details.loyalty.rounding', { mode: loyaltyRoundingLabel }) }}
                                        </div>
                                    </div>
                                    <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.loyalty.minimum_spend') }}</div>
                                        <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-200">
                                            {{ formatCurrency(loyalty?.minimum_spend || 0) }}
                                        </div>
                                        <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                            {{ loyalty?.enabled ? $t('customers.details.loyalty.enabled') : $t('customers.details.loyalty.disabled') }}
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-5">
                                    <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-200">{{ $t('customers.details.loyalty.recent_activity') }}</h3>

                                    <div v-if="loyaltyRecent.length" class="mt-3 space-y-2 text-sm">
                                        <div
                                            v-for="entry in loyaltyRecent"
                                            :key="entry.id"
                                            class="flex items-start justify-between gap-3 rounded-sm border border-stone-200 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-900"
                                        >
                                            <div>
                                                <div class="font-medium text-stone-800 dark:text-neutral-200">
                                                    {{ loyaltyEventLabel(entry.event) }}
                                                </div>
                                                <div class="mt-0.5 text-xs text-stone-500 dark:text-neutral-400">
                                                    {{ $t('customers.details.loyalty.payment_amount', { amount: formatCurrency(entry.amount) }) }} -
                                                    {{ formatDate(entry.processed_at) }}
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <div
                                                    class="text-sm font-semibold"
                                                    :class="entry.points >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                                >
                                                    {{ formatSignedPoints(entry.points) }}
                                                </div>
                                                <div class="text-xs text-stone-500 dark:text-neutral-400">{{ loyaltyPointLabel }}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-else class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                                        {{ $t('customers.details.loyalty.empty') }}
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </Card>
            </div>
        </div>

        <Modal :show="mailingListDialogOpen" max-width="2xl" @close="closeMailingListDialog">
            <div class="space-y-4 p-5">
                <div>
                    <h4 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('customers.details.mailing_lists.modal_title') }}
                    </h4>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('customers.details.mailing_lists.modal_description') }}
                    </p>
                </div>

                <FloatingSelect
                    v-model="selectedMailingListId"
                    :label="$t('customers.details.mailing_lists.select_label')"
                    :options="mailingListOptions"
                    option-value="id"
                    option-label="name"
                />

                <p v-if="mailingListLoading" class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('customers.details.mailing_lists.loading') }}
                </p>

                <p v-if="mailingListError" class="text-xs text-rose-600">
                    {{ mailingListError }}
                </p>

                <div class="flex items-center justify-end gap-2">
                    <button
                        type="button"
                        @click="closeMailingListDialog"
                        class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                    >
                        {{ $t('customers.actions.cancel') }}
                    </button>
                    <button
                        type="button"
                        :disabled="mailingListBusy || !selectedMailingListId"
                        @click="addCustomerToMailingList"
                        class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                    >
                        {{ $t('customers.details.mailing_lists.confirm_add') }}
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
