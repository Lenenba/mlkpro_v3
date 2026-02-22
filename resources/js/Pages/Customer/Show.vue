<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Header from './UI/Header.vue';
import Card from '@/Components/UI/Card.vue';
import CardNoHeader from '@/Components/UI/CardNoHeader.vue';
import DescriptionList from '@/Components/UI/DescriptionList.vue';
import CardNav from '@/Components/UI/CardNav.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import { humanizeDate } from '@/utils/date';
import { useI18n } from 'vue-i18n';

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
});

const { t } = useI18n();

const page = usePage();
const companyType = computed(() => page.props.auth?.account?.company?.type ?? null);
const showSales = computed(() => companyType.value === 'products');
const showServiceOps = computed(() => companyType.value !== 'products');
const loyaltyFeatureEnabled = computed(() => {
    const featureFlag = page.props.auth?.account?.features?.loyalty;
    if (typeof featureFlag === 'boolean') {
        return featureFlag;
    }

    return Boolean(props.loyalty?.feature_enabled ?? true);
});

const properties = computed(() => props.customer?.properties || []);
const tags = computed(() => props.customer?.tags || []);
const latestQuote = computed(() => (props.customer?.quotes || [])[0] || null);
const latestWork = computed(() => (props.customer?.works || [])[0] || null);
const latestInvoice = computed(() => (props.customer?.invoices || [])[0] || null);

const formatDate = (value) => humanizeDate(value);
const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
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
const loyalty = computed(() => props.loyalty || {});
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
const kpiMax = computed(() => {
    const values = [
        Number(props.stats?.quotes || 0),
        Number(props.stats?.active_works || 0),
        Number(props.stats?.jobs || 0),
        Number(props.stats?.invoices || 0),
        Number(props.stats?.requests || 0),
    ];

    return Math.max(1, ...values);
});
const kpiBarWidth = (value) => {
    const safe = Number(value || 0);
    if (safe <= 0) {
        return '0%';
    }
    const max = kpiMax.value || 1;
    const percent = max ? Math.round((safe / max) * 100) : 0;
    return `${Math.min(100, Math.max(12, percent))}%`;
};
const balanceBarWidth = computed(() => {
    const balance = Math.max(0, Number(props.billing?.summary?.balance_due || 0));
    const total = Math.max(0, Number(props.billing?.summary?.total_invoiced || 0));
    if (!balance) {
        return '0%';
    }
    if (!total) {
        return '60%';
    }

    const percent = Math.round((balance / total) * 100);
    return `${Math.min(100, Math.max(12, percent))}%`;
});

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

const submitAutoValidation = () => {
    if (autoValidationForm.processing) {
        return;
    }

    autoValidationForm.patch(route('customer.auto-validation.update', props.customer.id), {
        preserveScroll: true,
    });
};

const activityHref = (log) => {
    const type = log?.subject_type || '';
    const id = log?.subject_id;

    if (!id) {
        return null;
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

const resetNewPropertyForm = () => {
    newPropertyForm.reset();
    newPropertyForm.type = 'physical';
    newPropertyForm.is_default = false;
    newPropertyForm.clearErrors();
};

const cancelEditProperty = () => {
    editingPropertyId.value = null;
    editPropertyForm.reset();
    editPropertyForm.clearErrors();
};

const startAddProperty = () => {
    cancelEditProperty();
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

                <Card v-if="loyaltyFeatureEnabled" class="mt-5">
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

                <CardNav v-if="showServiceOps" class="mt-5" :customer="customer" :stats="stats" />

                <Card v-if="showServiceOps" class="mt-5">
                    <template #title>{{ $t('customers.details.schedule.title') }}</template>

                    <div class="space-y-5">
                        <div>
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
                                    class="flex items-center justify-between gap-3 rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700"
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

                        <div>
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
                                    class="flex items-start justify-between gap-3 rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700"
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
                    </div>
                </Card>

                <Card class="mt-5">
                    <template #title>{{ $t('customers.details.activity.title') }}</template>

                    <div class="space-y-3 text-sm">
                        <div
                            v-for="log in activity"
                            :key="log.id"
                            class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700"
                        >
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                {{ log.subject }} • {{ formatDate(log.created_at) }}
                            </div>
                            <div class="mt-1 text-sm text-stone-800 dark:text-neutral-200">
                                <Link v-if="activityHref(log)" :href="activityHref(log)" class="hover:underline">
                                    {{ log.description || log.action }}
                                </Link>
                                <span v-else>{{ log.description || log.action }}</span>
                            </div>
                        </div>
                        <div v-if="!activity.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('customers.details.activity.empty') }}
                        </div>
                    </div>
                </Card>
            </div>
            <div class="rise-stagger">
                <CardNoHeader v-if="showServiceOps">
                    <template #title>{{ $t('customers.details.preview.title') }}</template>

                    <div class="grid grid-cols-2 gap-3 rise-stagger">
                        <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.quotes') }}</div>
                                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ formatNumber(stats?.quotes ?? 0) }}
                                    </div>
                                </div>
                                <div class="flex h-9 w-9 items-center justify-center rounded-sm bg-emerald-50 text-emerald-600 animate-micro-pop dark:bg-emerald-500/10 dark:text-emerald-300">
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="lucide lucide-text-quote h-5 w-5"
                                    >
                                        <path d="M17 6H3" />
                                        <path d="M21 12H8" />
                                        <path d="M21 18H8" />
                                        <path d="M3 12v6" />
                                    </svg>
                                </div>
                            </div>
                            <div v-if="(stats?.quotes ?? 0) > 0" class="mt-3 h-1.5 w-full rounded-full bg-stone-100 dark:bg-neutral-800">
                                <div
                                    class="kpi-bar h-full rounded-full bg-emerald-500"
                                    :style="{ '--kpi-width': kpiBarWidth(stats?.quotes) }"
                                ></div>
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.active_jobs') }}</div>
                                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ formatNumber(stats?.active_works ?? 0) }}
                                    </div>
                                </div>
                                <div class="flex h-9 w-9 items-center justify-center rounded-sm bg-sky-50 text-sky-600 animate-micro-pop dark:bg-sky-500/10 dark:text-sky-300">
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="lucide lucide-monitor-cog h-5 w-5"
                                    >
                                        <path d="M12 17v4" />
                                        <path d="m15.2 4.9-.9-.4" />
                                        <path d="m15.2 7.1-.9.4" />
                                        <path d="m16.9 3.2-.4-.9" />
                                        <path d="m16.9 8.8-.4.9" />
                                        <path d="m19.5 2.3-.4.9" />
                                        <path d="m19.5 9.7-.4-.9" />
                                        <path d="m21.7 4.5-.9.4" />
                                        <path d="m21.7 7.5-.9-.4" />
                                        <path d="M22 13v2a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7" />
                                        <path d="M8 21h8" />
                                        <circle cx="18" cy="6" r="3" />
                                    </svg>
                                </div>
                            </div>
                            <div v-if="(stats?.active_works ?? 0) > 0" class="mt-3 h-1.5 w-full rounded-full bg-stone-100 dark:bg-neutral-800">
                                <div
                                    class="kpi-bar h-full rounded-full bg-sky-500"
                                    :style="{ '--kpi-width': kpiBarWidth(stats?.active_works) }"
                                ></div>
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.jobs') }}</div>
                                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ formatNumber(stats?.jobs ?? 0) }}
                                    </div>
                                </div>
                                <div class="flex h-9 w-9 items-center justify-center rounded-sm bg-amber-50 text-amber-600 animate-micro-pop dark:bg-amber-500/10 dark:text-amber-300">
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="lucide lucide-house h-5 w-5"
                                    >
                                        <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" />
                                        <path
                                            d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"
                                        />
                                    </svg>
                                </div>
                            </div>
                            <div v-if="(stats?.jobs ?? 0) > 0" class="mt-3 h-1.5 w-full rounded-full bg-stone-100 dark:bg-neutral-800">
                                <div
                                    class="kpi-bar h-full rounded-full bg-amber-500"
                                    :style="{ '--kpi-width': kpiBarWidth(stats?.jobs) }"
                                ></div>
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.invoices') }}</div>
                                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ formatNumber(stats?.invoices ?? 0) }}
                                    </div>
                                </div>
                                <div class="flex h-9 w-9 items-center justify-center rounded-sm bg-indigo-50 text-indigo-600 animate-micro-pop dark:bg-indigo-500/10 dark:text-indigo-300">
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="lucide lucide-file-text h-5 w-5"
                                    >
                                        <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" />
                                        <path d="M14 2v4a2 2 0 0 0 2 2h4" />
                                        <path d="M10 9H8" />
                                        <path d="M16 13H8" />
                                        <path d="M16 17H8" />
                                    </svg>
                                </div>
                            </div>
                            <div v-if="(stats?.invoices ?? 0) > 0" class="mt-3 h-1.5 w-full rounded-full bg-stone-100 dark:bg-neutral-800">
                                <div
                                    class="kpi-bar h-full rounded-full bg-indigo-500"
                                    :style="{ '--kpi-width': kpiBarWidth(stats?.invoices) }"
                                ></div>
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.requests') }}</div>
                                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ formatNumber(stats?.requests ?? 0) }}
                                    </div>
                                </div>
                                <div class="flex h-9 w-9 items-center justify-center rounded-sm bg-rose-50 text-rose-600 animate-micro-pop dark:bg-rose-500/10 dark:text-rose-300">
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="lucide lucide-git-pull-request h-5 w-5"
                                    >
                                        <circle cx="18" cy="18" r="3" />
                                        <circle cx="6" cy="6" r="3" />
                                        <path d="M13 6h3a2 2 0 0 1 2 2v7" />
                                        <line x1="6" x2="6" y1="9" y2="21" />
                                    </svg>
                                </div>
                            </div>
                            <div v-if="(stats?.requests ?? 0) > 0" class="mt-3 h-1.5 w-full rounded-full bg-stone-100 dark:bg-neutral-800">
                                <div
                                    class="kpi-bar h-full rounded-full bg-rose-500"
                                    :style="{ '--kpi-width': kpiBarWidth(stats?.requests) }"
                                ></div>
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.balance_due') }}</div>
                                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ formatCurrency(billing?.summary?.balance_due) }}
                                    </div>
                                </div>
                                <div class="flex h-9 w-9 items-center justify-center rounded-sm bg-teal-50 text-teal-600 animate-micro-pop dark:bg-teal-500/10 dark:text-teal-300">
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="lucide lucide-receipt h-5 w-5"
                                    >
                                        <path d="M4 2h16v20l-4-2-4 2-4-2-4 2V2z" />
                                        <path d="M16 8h-8" />
                                        <path d="M16 12h-8" />
                                        <path d="M10 16h-2" />
                                    </svg>
                                </div>
                            </div>
                            <div
                                v-if="Number(billing?.summary?.balance_due || 0) > 0"
                                class="mt-3 h-1.5 w-full rounded-full bg-stone-100 dark:bg-neutral-800"
                            >
                                <div class="kpi-bar h-full rounded-full bg-teal-500" :style="{ '--kpi-width': balanceBarWidth }"></div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 space-y-3 text-sm">
                        <div class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.latest_quote') }}</div>
                            <div v-if="latestQuote">
                                <Link
                                    :href="route('customer.quote.show', latestQuote.id)"
                                    class="font-medium text-stone-800 hover:underline dark:text-neutral-200"
                                >
                                    {{ latestQuote.number ? $t('customers.details.preview.quote_number', { number: latestQuote.number }) : $t('customers.details.preview.quote_fallback') }}
                                </Link>
                                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatStatus(latestQuote.status, 'quotes.status') }} | {{ formatDate(latestQuote.created_at) }}
                                </div>
                                <div v-if="hasValue(latestQuote.total)" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('customers.details.preview.total') }} {{ formatCurrency(latestQuote.total) }}
                                </div>
                            </div>
                            <div v-else class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.no_quotes') }}</div>
                        </div>
                        <div class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.latest_job') }}</div>
                            <div v-if="latestWork">
                                <Link
                                    :href="route('work.show', latestWork.id)"
                                    class="font-medium text-stone-800 hover:underline dark:text-neutral-200"
                                >
                                    {{ latestWork.job_title || $t('customers.details.preview.job_fallback') }}
                                </Link>
                                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatStatus(latestWork.status, 'jobs.status') }} | {{ formatDate(latestWork.start_date || latestWork.created_at) }}
                                </div>
                            </div>
                            <div v-else class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.no_jobs') }}</div>
                        </div>
                        <div class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.latest_invoice') }}</div>
                            <div v-if="latestInvoice">
                                <Link
                                    :href="route('invoice.show', latestInvoice.id)"
                                    class="font-medium text-stone-800 hover:underline dark:text-neutral-200"
                                >
                                    {{ latestInvoice.number ? $t('customers.details.preview.invoice_number', { number: latestInvoice.number }) : $t('customers.details.preview.invoice_fallback') }}
                                </Link>
                                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatStatus(latestInvoice.status, 'dashboard.status.invoice') }} | {{ formatDate(latestInvoice.created_at) }}
                                </div>
                                <div v-if="hasValue(latestInvoice.total)" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('customers.details.preview.total') }} {{ formatCurrency(latestInvoice.total) }}
                                </div>
                            </div>
                            <div v-else class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.no_invoices') }}</div>
                        </div>
                    </div>
                </CardNoHeader>
                <CardNoHeader>
                    <template #title>{{ $t('customers.details.contact.title') }}</template>
                    <DescriptionList :item="customer" />
                </CardNoHeader>
                <CardNoHeader class="mt-5">
                    <template #title>{{ $t('customers.details.tags.title') }}</template>

                    <div v-if="!editingTags" class="space-y-3">
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

                    <form v-else class="space-y-3" @submit.prevent="submitTags">
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
                </CardNoHeader>
                <CardNoHeader v-if="showServiceOps" class="mt-5">
                    <template #title>{{ $t('customers.details.auto_validation.title') }}</template>

                    <form class="space-y-3" @submit.prevent="submitAutoValidation">
                        <div class="space-y-2">
                            <label
                                for="customer-auto-accept-quotes"
                                class="flex items-center justify-between gap-3 text-sm text-stone-700 dark:text-neutral-200"
                            >
                                <span>{{ $t('customers.details.auto_validation.quotes') }}</span>
                                <input
                                    id="customer-auto-accept-quotes"
                                    type="checkbox"
                                    v-model="autoValidationForm.auto_accept_quotes"
                                    class="relative w-11 h-6 p-px bg-stone-100 border-transparent text-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none checked:bg-none checked:text-green-600 checked:border-green-600 focus:checked:border-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-900

                                    before:inline-block before:size-5 before:bg-white checked:before:bg-white before:translate-x-0 checked:before:translate-x-full before:rounded-full before:shadow before:transform before:ring-0 before:transition before:ease-in-out before:duration-200 dark:before:bg-neutral-400 dark:checked:before:bg-white"
                                />
                            </label>
                            <label
                                for="customer-auto-validate-jobs"
                                class="flex items-center justify-between gap-3 text-sm text-stone-700 dark:text-neutral-200"
                            >
                                <span>{{ $t('customers.details.auto_validation.jobs') }}</span>
                                <input
                                    id="customer-auto-validate-jobs"
                                    type="checkbox"
                                    v-model="autoValidationForm.auto_validate_jobs"
                                    class="relative w-11 h-6 p-px bg-stone-100 border-transparent text-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none checked:bg-none checked:text-green-600 checked:border-green-600 focus:checked:border-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-900

                                    before:inline-block before:size-5 before:bg-white checked:before:bg-white before:translate-x-0 checked:before:translate-x-full before:rounded-full before:shadow before:transform before:ring-0 before:transition before:ease-in-out before:duration-200 dark:before:bg-neutral-400 dark:checked:before:bg-white"
                                />
                            </label>
                            <label
                                for="customer-auto-validate-tasks"
                                class="flex items-center justify-between gap-3 text-sm text-stone-700 dark:text-neutral-200"
                            >
                                <span>{{ $t('customers.details.auto_validation.tasks') }}</span>
                                <input
                                    id="customer-auto-validate-tasks"
                                    type="checkbox"
                                    v-model="autoValidationForm.auto_validate_tasks"
                                    class="relative w-11 h-6 p-px bg-stone-100 border-transparent text-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none checked:bg-none checked:text-green-600 checked:border-green-600 focus:checked:border-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-900

                                    before:inline-block before:size-5 before:bg-white checked:before:bg-white before:translate-x-0 checked:before:translate-x-full before:rounded-full before:shadow before:transform before:ring-0 before:transition before:ease-in-out before:duration-200 dark:before:bg-neutral-400 dark:checked:before:bg-white"
                                />
                            </label>
                            <label
                                for="customer-auto-validate-invoices"
                                class="flex items-center justify-between gap-3 text-sm text-stone-700 dark:text-neutral-200"
                            >
                                <span>{{ $t('customers.details.auto_validation.invoices') }}</span>
                                <input
                                    id="customer-auto-validate-invoices"
                                    type="checkbox"
                                    v-model="autoValidationForm.auto_validate_invoices"
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
                </CardNoHeader>
                <CardNoHeader class="mt-5">
                    <template #title>{{ $t('customers.details.last_interaction.title') }}</template>

                    <div v-if="lastInteraction" class="space-y-1 text-sm">
                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                            {{ lastInteraction.subject }} • {{ formatDate(lastInteraction.created_at) }}
                        </div>
                        <div class="text-sm text-stone-800 dark:text-neutral-200">
                            {{ lastInteraction.description || lastInteraction.action }}
                        </div>
                    </div>
                    <div v-else class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('customers.details.last_interaction.empty') }}</div>
                </CardNoHeader>
                <Card v-if="showServiceOps" class="mt-5">
                    <template #title>{{ $t('customers.details.billing_history.title') }}</template>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.billing_history.invoiced') }}</div>
                            <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-200">
                                {{ formatCurrency(billing?.summary?.total_invoiced) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.billing_history.paid') }}</div>
                            <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-200">
                                {{ formatCurrency(billing?.summary?.total_paid) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
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
                                class="flex items-start justify-between gap-3 rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700"
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
                </Card>
                <Card class="mt-5">
                    <template #title>{{ $t('customers.details.loyalty.title') }}</template>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.loyalty.balance') }}</div>
                            <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-200">
                                {{ formatNumber(loyalty?.balance || 0) }} {{ loyaltyPointLabel }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.loyalty.earn_rate') }}</div>
                            <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-200">
                                {{ $t('customers.details.loyalty.rate_value', { rate: formatNumber(loyalty?.rate || 0, 2) }) }}
                            </div>
                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('customers.details.loyalty.rounding', { mode: loyaltyRoundingLabel }) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
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
                                class="flex items-start justify-between gap-3 rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700"
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
                </Card>
                <Card class="mt-5">
                    <template #title>{{ $t('customers.details.notes.title') }}</template>

                    <div v-if="!editingNotes" class="space-y-3">
                        <p class="text-sm text-stone-700 whitespace-pre-wrap dark:text-neutral-200">
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

                    <form v-else class="space-y-3" @submit.prevent="submitNotes">
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
                </Card>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
