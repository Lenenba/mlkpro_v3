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

const page = usePage();
const companyType = computed(() => page.props.auth?.account?.company?.type ?? null);
const showSales = computed(() => companyType.value === 'products');
const showServiceOps = computed(() => companyType.value !== 'products');

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
const formatStatus = (status) => (status || '-').replace(/_/g, ' ');
const hasValue = (value) => value !== null && value !== undefined;
const topProducts = computed(() => props.topProducts || []);
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
    const preferred = [insights.preferred_day, insights.preferred_period].filter(Boolean).join(' • ');

    return [
        {
            label: 'Dernier achat',
            value: insights.last_purchase_at ? formatDate(insights.last_purchase_at) : '-',
        },
        {
            label: 'Jours depuis',
            value: hasValue(insights.days_since_last_purchase)
                ? `${numberLabel(insights.days_since_last_purchase)} j`
                : '-',
        },
        {
            label: 'Achat moyen',
            value: formatCurrency(insights.average_order_value || 0),
        },
        {
            label: 'Articles moyens',
            value: hasValue(insights.average_items) ? numberLabel(insights.average_items, 1) : '-',
        },
        {
            label: 'Cadence moyenne',
            value: hasValue(insights.purchase_frequency_days)
                ? `${numberLabel(insights.purchase_frequency_days, 1)} j`
                : '-',
        },
        {
            label: 'Achats 30 jours',
            value: numberLabel(insights.recent_30_count || 0),
        },
        {
            label: 'Habitude',
            value: preferred || '-',
        },
    ];
});

const propertyTypes = [
    { id: 'physical', name: 'Physical' },
    { id: 'billing', name: 'Billing' },
    { id: 'other', name: 'Other' },
];

const propertyTypeLabel = (type) => propertyTypes.find((option) => option.id === type)?.name || type;

const propertyHeading = (property) => {
    const chunks = [propertyTypeLabel(property.type), property.country].filter(Boolean);
    return chunks.join(' • ') || 'Property';
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
    if (!confirm('Delete this property?')) {
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
    <Head :title="customer.company_name || `${customer.first_name || ''} ${customer.last_name || ''}`.trim() || 'Customer'" />
    <AuthenticatedLayout>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
            <div class="md:col-span-2 rise-stagger">
                <Header :customer="customer" :can-edit="canEdit" />

                <Card v-if="showSales" class="mt-5">
                    <template #title>
                        <div class="flex items-center justify-between gap-3">
                            <span>Sales</span>
                            <Link
                                :href="route('sales.index')"
                                class="text-xs font-semibold text-green-700 hover:underline dark:text-green-400"
                            >
                                View all
                            </Link>
                        </div>
                    </template>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            <div class="text-xs uppercase text-stone-400">Sales</div>
                            <div class="mt-1 text-lg font-semibold">{{ salesSummary?.count || 0 }}</div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            <div class="text-xs uppercase text-stone-400">Paid</div>
                            <div class="mt-1 text-lg font-semibold">{{ formatCurrency(salesSummary?.paid || 0) }}</div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            <div class="text-xs uppercase text-stone-400">Total</div>
                            <div class="mt-1 text-lg font-semibold">{{ formatCurrency(salesSummary?.total || 0) }}</div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <div v-if="!sales.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            No sales for this customer yet.
                        </div>
                        <div v-else class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <div v-for="sale in sales" :key="sale.id" class="flex items-center justify-between gap-3 py-3 text-sm">
                                <div>
                                    <Link
                                        :href="route('sales.show', sale.id)"
                                        class="font-semibold text-stone-800 hover:underline dark:text-neutral-200"
                                    >
                                        {{ sale.number || `Sale #${sale.id}` }}
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
                    <template #title>Habitudes d'achat</template>

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
                        <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-200">Produits favoris</h3>

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
                                            :alt="product.name || 'Produit'"
                                            class="h-full w-full object-cover"
                                        />
                                        <span v-else>{{ (product.name || 'P').charAt(0).toUpperCase() }}</span>
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                                            {{ product.name || 'Produit' }}
                                        </div>
                                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ product.sku || 'SKU -' }}
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
                        <div v-else class="mt-2 text-sm text-stone-500 dark:text-neutral-400">Aucun achat recent.</div>
                    </div>
                </Card>

                <Card class="mt-5">
                    <template #title>
                        <div class="flex items-center justify-between gap-3">
                            <span>Properties</span>
                            <button
                                type="button"
                                @click="showAddProperty ? cancelAddProperty() : startAddProperty()"
                                class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500"
                            >
                                Add property
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
                                    <FloatingSelect v-model="newPropertyForm.type" label="Type" :options="propertyTypes" />
                                    <InputError class="mt-1" :message="newPropertyForm.errors.type" />
                                </div>
                                <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                    <input
                                        type="checkbox"
                                        v-model="newPropertyForm.is_default"
                                        class="size-3.5 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700"
                                    />
                                    Set as default
                                </label>
                                <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.street1" label="Street 1" />
                                        <InputError class="mt-1" :message="newPropertyForm.errors.street1" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.street2" label="Street 2" />
                                        <InputError class="mt-1" :message="newPropertyForm.errors.street2" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.city" label="City" />
                                        <InputError class="mt-1" :message="newPropertyForm.errors.city" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.state" label="State" />
                                        <InputError class="mt-1" :message="newPropertyForm.errors.state" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.zip" label="Zip code" />
                                        <InputError class="mt-1" :message="newPropertyForm.errors.zip" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="newPropertyForm.country" label="Country" />
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
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="newPropertyForm.processing"
                                    class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                                >
                                    Save
                                </button>
                            </div>
                        </form>
                    </div>

                    <div v-if="!properties.length" class="text-sm text-stone-500 dark:text-neutral-400">
                        No properties yet.
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
                                                Default
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
                                        Set as default
                                    </button>
                                    <button
                                        type="button"
                                        @click="startEditProperty(property)"
                                        class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-stone-200 text-stone-800 hover:bg-stone-300 focus:outline-none focus:bg-stone-300 dark:bg-neutral-600 dark:text-neutral-200 dark:hover:bg-neutral-500"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        @click="deleteProperty(property)"
                                        class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-red-100 text-red-700 hover:bg-red-200 focus:outline-none focus:bg-red-200 dark:bg-red-500/10 dark:text-red-400 dark:hover:bg-red-500/20"
                                    >
                                        Delete
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
                                            <FloatingSelect v-model="editPropertyForm.type" label="Type" :options="propertyTypes" />
                                            <InputError class="mt-1" :message="editPropertyForm.errors.type" />
                                        </div>
                                        <div></div>
                                        <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-3">
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.street1" label="Street 1" />
                                                <InputError class="mt-1" :message="editPropertyForm.errors.street1" />
                                            </div>
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.street2" label="Street 2" />
                                                <InputError class="mt-1" :message="editPropertyForm.errors.street2" />
                                            </div>
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.city" label="City" />
                                                <InputError class="mt-1" :message="editPropertyForm.errors.city" />
                                            </div>
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.state" label="State" />
                                                <InputError class="mt-1" :message="editPropertyForm.errors.state" />
                                            </div>
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.zip" label="Zip code" />
                                                <InputError class="mt-1" :message="editPropertyForm.errors.zip" />
                                            </div>
                                            <div>
                                                <FloatingInput v-model="editPropertyForm.country" label="Country" />
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
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            :disabled="editPropertyForm.processing"
                                            class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                                        >
                                            Save changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </li>
                    </ul>
                </Card>

                <CardNav v-if="showServiceOps" class="mt-5" :customer="customer" :stats="stats" />

                <Card v-if="showServiceOps" class="mt-5">
                    <template #title>Schedule</template>

                    <div class="space-y-5">
                        <div>
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-200">Upcoming jobs</h3>
                                <Link
                                    :href="route('jobs.index')"
                                    class="text-xs font-medium text-green-700 hover:underline dark:text-green-400"
                                >
                                    View all
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
                                            Starts {{ formatDate(work.start_date || work.created_at) }}
                                        </div>
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ work.status }}
                                    </div>
                                </div>
                                <div
                                    v-if="!(schedule?.upcomingJobs || []).length"
                                    class="text-sm text-stone-500 dark:text-neutral-400"
                                >
                                    No upcoming jobs.
                                </div>
                            </div>
                        </div>

                        <div>
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-200">Tasks</h3>
                                <Link
                                    :href="route('task.index')"
                                    class="text-xs font-medium text-green-700 hover:underline dark:text-green-400"
                                >
                                    View all
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
                                            <span v-if="task.due_date">Due {{ formatDate(task.due_date) }}</span>
                                            <span v-else>No due date</span>
                                        </div>
                                    </div>
                                    <div class="text-right text-xs text-stone-500 dark:text-neutral-400">
                                        <div class="capitalize">{{ task.status }}</div>
                                        <div v-if="task.assignee">{{ task.assignee }}</div>
                                    </div>
                                </div>
                                <div v-if="!(schedule?.tasks || []).length" class="text-sm text-stone-500 dark:text-neutral-400">
                                    No tasks yet.
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>

                <Card class="mt-5">
                    <template #title>Recent activity for this client</template>

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
                            No recent activity yet.
                        </div>
                    </div>
                </Card>
            </div>
            <div class="rise-stagger">
                <CardNoHeader v-if="showServiceOps">
                    <template #title>Apercu client</template>

                    <div class="grid grid-cols-2 gap-3 rise-stagger">
                        <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">Devis</div>
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
                                    <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">Chantiers actifs</div>
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
                                    <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">Chantiers</div>
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
                                    <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">Factures</div>
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
                                    <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">Demandes</div>
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
                                    <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">Solde du</div>
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
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">Dernier devis</div>
                            <div v-if="latestQuote">
                                <Link
                                    :href="route('customer.quote.show', latestQuote.id)"
                                    class="font-medium text-stone-800 hover:underline dark:text-neutral-200"
                                >
                                    {{ latestQuote.number ? `Devis ${latestQuote.number}` : 'Devis' }}
                                </Link>
                                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatStatus(latestQuote.status) }} | {{ formatDate(latestQuote.created_at) }}
                                </div>
                                <div v-if="hasValue(latestQuote.total)" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    Total {{ formatCurrency(latestQuote.total) }}
                                </div>
                            </div>
                            <div v-else class="text-xs text-stone-500 dark:text-neutral-400">Aucun devis.</div>
                        </div>
                        <div class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">Dernier chantier</div>
                            <div v-if="latestWork">
                                <Link
                                    :href="route('work.show', latestWork.id)"
                                    class="font-medium text-stone-800 hover:underline dark:text-neutral-200"
                                >
                                    {{ latestWork.job_title || 'Chantier' }}
                                </Link>
                                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatStatus(latestWork.status) }} | {{ formatDate(latestWork.start_date || latestWork.created_at) }}
                                </div>
                            </div>
                            <div v-else class="text-xs text-stone-500 dark:text-neutral-400">Aucun chantier.</div>
                        </div>
                        <div class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">Derniere facture</div>
                            <div v-if="latestInvoice">
                                <Link
                                    :href="route('invoice.show', latestInvoice.id)"
                                    class="font-medium text-stone-800 hover:underline dark:text-neutral-200"
                                >
                                    {{ latestInvoice.number ? `Facture ${latestInvoice.number}` : 'Facture' }}
                                </Link>
                                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatStatus(latestInvoice.status) }} | {{ formatDate(latestInvoice.created_at) }}
                                </div>
                                <div v-if="hasValue(latestInvoice.total)" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    Total {{ formatCurrency(latestInvoice.total) }}
                                </div>
                            </div>
                            <div v-else class="text-xs text-stone-500 dark:text-neutral-400">Aucune facture.</div>
                        </div>
                    </div>
                </CardNoHeader>
                <CardNoHeader>
                    <template #title>Coordonnees</template>
                    <DescriptionList :item="customer" />
                </CardNoHeader>
                <CardNoHeader class="mt-5">
                    <template #title>Tags</template>

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
                        <div v-else class="text-sm text-stone-500 dark:text-neutral-400">Aucun tag.</div>

                        <div class="flex justify-end">
                            <button
                                type="button"
                                @click="startEditTags"
                                class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                            >
                                Modifier
                            </button>
                        </div>
                    </div>

                    <form v-else class="space-y-3" @submit.prevent="submitTags">
                        <div>
                            <FloatingInput v-model="tagsForm.tags" label="Tags (separes par virgules)" />
                            <InputError class="mt-1" :message="tagsForm.errors.tags" />
                        </div>
                        <div class="flex items-center justify-end gap-2">
                            <button
                                type="button"
                                @click="cancelEditTags"
                                class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                            >
                                Annuler
                            </button>
                            <button
                                type="submit"
                                :disabled="tagsForm.processing"
                                class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                            >
                                Enregistrer
                            </button>
                        </div>
                    </form>
                </CardNoHeader>
                <CardNoHeader v-if="showServiceOps" class="mt-5">
                    <template #title>Validation auto</template>

                    <form class="space-y-3" @submit.prevent="submitAutoValidation">
                        <div class="space-y-2">
                            <label
                                for="customer-auto-accept-quotes"
                                class="flex items-center justify-between gap-3 text-sm text-stone-700 dark:text-neutral-200"
                            >
                                <span>Validation auto des devis</span>
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
                                <span>Validation auto des chantiers</span>
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
                                <span>Validation auto des taches</span>
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
                                <span>Validation auto des factures</span>
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
                                Enregistrer
                            </button>
                        </div>
                    </form>
                </CardNoHeader>
                <CardNoHeader class="mt-5">
                    <template #title>Derniere interaction client</template>

                    <div v-if="lastInteraction" class="space-y-1 text-sm">
                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                            {{ lastInteraction.subject }} • {{ formatDate(lastInteraction.created_at) }}
                        </div>
                        <div class="text-sm text-stone-800 dark:text-neutral-200">
                            {{ lastInteraction.description || lastInteraction.action }}
                        </div>
                    </div>
                    <div v-else class="text-sm text-stone-500 dark:text-neutral-400">Aucune interaction.</div>
                </CardNoHeader>
                <Card v-if="showServiceOps" class="mt-5">
                    <template #title>Historique facturation</template>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">Facture</div>
                            <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-200">
                                {{ formatCurrency(billing?.summary?.total_invoiced) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">Encaisse</div>
                            <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-200">
                                {{ formatCurrency(billing?.summary?.total_paid) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">Solde du</div>
                            <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-200">
                                {{ formatCurrency(billing?.summary?.balance_due) }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-5">
                        <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-200">Paiements recents</h3>
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
                                        {{ payment.invoice.number || 'Invoice' }}
                                    </Link>
                                    <div v-else class="font-medium text-stone-800 dark:text-neutral-200">Paiement</div>
                                    <div class="mt-0.5 text-xs text-stone-500 dark:text-neutral-400">
                                        Paye {{ formatDate(payment.paid_at || payment.created_at) }}
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
                                Aucun paiement.
                            </div>
                        </div>
                    </div>
                </Card>
                <Card class="mt-5">
                    <template #title>Notes internes</template>

                    <div v-if="!editingNotes" class="space-y-3">
                        <p class="text-sm text-stone-700 whitespace-pre-wrap dark:text-neutral-200">
                            {{ customer.description || 'Aucune note.' }}
                        </p>
                        <div class="flex justify-end">
                            <button
                                type="button"
                                @click="startEditNotes"
                                class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                            >
                                Modifier
                            </button>
                        </div>
                    </div>

                    <form v-else class="space-y-3" @submit.prevent="submitNotes">
                        <div>
                            <FloatingTextarea v-model="notesForm.description" label="Notes internes" />
                            <InputError class="mt-1" :message="notesForm.errors.description" />
                        </div>
                        <div class="flex items-center justify-end gap-2">
                            <button
                                type="button"
                                @click="cancelEditNotes"
                                class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                            >
                                Annuler
                            </button>
                            <button
                                type="submit"
                                :disabled="notesForm.processing"
                                class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                            >
                                Enregistrer
                            </button>
                        </div>
                    </form>
                </Card>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
