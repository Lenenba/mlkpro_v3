<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import ProductTableList from '@/Components/ProductTableList.vue';
import ValidationSummary from '@/Components/ValidationSummary.vue';
import { ref, watch, computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    customer: Object,
    quote: Object,
    lastQuotesNumber: String,
    taxes: Array,
    selectedPropertyId: Number,
    templateDefaults: {
        type: Object,
        default: () => ({}),
    },
    templateExamples: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { t } = useI18n();

const companyName = computed(() => page.props.auth?.account?.company?.name || t('quotes.company_fallback'));
const companyLogo = computed(() => page.props.auth?.account?.company?.logo_url || null);
const isEditing = computed(() => Boolean(props.quote?.id));
const templateDefaults = computed(() => props.templateDefaults || {});
const templateExamples = computed(() => props.templateExamples || []);
const defaultMessages = computed(() => templateDefaults.value?.messages || '');
const defaultNotes = computed(() => templateDefaults.value?.notes || '');
const customerLabel = computed(() => {
    const label = props.customer?.company_name
        || `${props.customer?.first_name || ''} ${props.customer?.last_name || ''}`.trim();
    return label || t('quotes.labels.customer_fallback');
});

const parseSourceDetails = (value) => {
    if (!value) {
        return null;
    }
    if (typeof value === 'string') {
        try {
            return JSON.parse(value);
        } catch (error) {
            return null;
        }
    }
    return value;
};

const resolveDefaultPropertyId = (customer) => {
    const properties = customer?.properties || [];
    return properties.find((property) => property.is_default)?.id || properties[0]?.id || null;
};

const initialPropertyId = props.quote?.property_id
    || props.selectedPropertyId
    || resolveDefaultPropertyId(props.customer)
    || null;

const form = useForm({
    // Pre-remplissage pour creation ou edition
    customer_id: props.quote?.customer_id || props.customer.id,
    property_id: initialPropertyId,
    job_title: props.quote?.job_title || '',
    status: props.quote?.status || 'draft',
    notes: isEditing.value ? (props.quote?.notes || '') : defaultNotes.value,
    messages: isEditing.value ? (props.quote?.messages || '') : defaultMessages.value,
    product: props.quote?.products?.map(product => ({
        id: product.id,
        name: product.name,
        quantity: Number(product.pivot?.quantity ?? 1),
        price: Number(product.pivot?.price ?? product.price ?? 0),
        total: Number(product.pivot?.total ?? 0),
        item_type: product.item_type ?? null,
        source_details: parseSourceDetails(product.pivot?.source_details),
    })) || [{ id: null, name: '', quantity: 1, price: 0, total: 0, item_type: null }],
    subtotal: Number(props.quote?.subtotal || 0),
    total: Number(props.quote?.total || 0),
    initial_deposit: Number(props.quote?.initial_deposit || 0),
    taxes: props.quote?.taxes?.map(tax => tax.tax_id) || [],
});

const properties = computed(() => props.customer?.properties || []);
const propertyOptions = computed(() =>
    properties.value.map((property) => ({
        id: property.id,
        name: `${property.street1 || t('quotes.form.property')}${property.city ? `, ${property.city}` : ''}`,
    }))
);
const selectedProperty = computed(() => {
    if (!properties.value.length || !form.property_id) {
        return null;
    }
    return properties.value.find((property) => property.id === form.property_id) || null;
});

const availableTaxes = computed(() => props.taxes || []);
const isLocked = computed(() => Boolean(props.quote?.archived_at) || props.quote?.status === 'accepted');
const statusOptions = computed(() => [
    { id: 'draft', name: t('quotes.status.draft') },
    { id: 'sent', name: t('quotes.status.sent') },
    { id: 'accepted', name: t('quotes.status.accepted') },
    { id: 'declined', name: t('quotes.status.declined') },
]);
const templateOptions = computed(() => {
    const options = templateExamples.value.slice();
    if (defaultMessages.value || defaultNotes.value) {
        options.unshift({
            key: 'default',
            label: t('quotes.form.template_default'),
            messages: defaultMessages.value,
            notes: defaultNotes.value,
        });
    }
    return options;
});
const showTemplatePicker = computed(() =>
    !isLocked.value && !isEditing.value && templateOptions.value.length > 0
);


const updateSubtotal = (newSubtotal) => {
    const value = Number(newSubtotal) || 0;
    form.subtotal = Math.round(value * 100) / 100;
};

// Taxes et totaux
const showTaxDetails = ref(form.taxes.length > 0);

const taxAmount = (tax) => {
    const subtotal = Number(form.subtotal) || 0;
    const rate = Number(tax.rate || 0);
    return Math.round(subtotal * (rate / 100) * 100) / 100;
};

const selectedTaxes = computed(() =>
    availableTaxes.value.filter((tax) => form.taxes.includes(tax.id))
);

const totalTaxAmount = computed(() =>
    selectedTaxes.value.reduce((sum, tax) => sum + taxAmount(tax), 0)
);

const totalWithTaxes = computed(() => {
    const subtotal = Number(form.subtotal) || 0;
    const taxTotal = showTaxDetails.value ? totalTaxAmount.value : 0;
    return Math.round((subtotal + taxTotal) * 100) / 100;
});

watch(totalWithTaxes, (value) => {
    form.total = value;
}, { immediate: true });

// Activer/desactiver les details des taxes
const toggleTaxDetails = () => {
    showTaxDetails.value = !showTaxDetails.value;
    if (!showTaxDetails.value) {
        form.taxes = [];
        return;
    }
    if (!form.taxes.length) {
        form.taxes = availableTaxes.value.map((tax) => tax.id);
    }
};

// Gestion des acomptes
const showDepositInput = ref(Number(form.initial_deposit) > 0);

const minimumDeposit = computed(() => (totalWithTaxes.value * 0.15).toFixed(2));
const validateDeposit = () => {
    const minValue = Number(minimumDeposit.value) || 0;
    if (Number(form.initial_deposit) < minValue) {
        form.initial_deposit = minValue;
    }
};
const toggleDepositInput = () => {
    showDepositInput.value = true;
    form.initial_deposit = Number(minimumDeposit.value) || 0;
};

const applyTemplate = (template) => {
    if (!template || isLocked.value) {
        return;
    }
    if (typeof template.messages === 'string') {
        form.messages = template.messages;
    }
    if (typeof template.notes === 'string') {
        form.notes = template.notes;
    }
};

const dispatchDemoEvent = (eventName) => {
    if (typeof window === 'undefined') {
        return;
    }
    window.dispatchEvent(new CustomEvent(eventName));
};

// Soumettre le formulaire
const submit = () => {
    if (isLocked.value) {
        return;
    }
    const routeName = props.quote?.id ? 'customer.quote.update' : 'customer.quote.store';
    const routeParams = props.quote?.id ? { quote: props.quote.id } : { customer: props.customer.id };

    form.total = totalWithTaxes.value;


    form[props.quote?.id ? 'put' : 'post'](route(routeName, routeParams), {
        onSuccess: () => {
            if (!isEditing.value) {
                dispatchDemoEvent('demo:quote_created');
            }
        },
    });
};

</script>

<template>

    <Head :title="$t('quotes.create_title')" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl">
            <form class="space-y-5" @submit.prevent="submit">
                    <div
                        class="p-5 space-y-3 flex flex-col bg-white border border-stone-200 rounded-sm shadow-sm xl:shadow-none dark:bg-neutral-900 dark:border-neutral-700">
                        <!-- Header -->
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <img v-if="companyLogo"
                                    :src="companyLogo"
                                    :alt="companyName"
                                    class="h-12 w-12 rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                                    loading="lazy"
                                    decoding="async" />
                                <div>
                                    <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                        {{ companyName }}
                                    </p>
                                    <h1 class="text-xl inline-block font-semibold text-stone-800 dark:text-green-100">
                                        {{ $t('quotes.form.quote_for', { customer: customerLabel }) }}
                                    </h1>
                                </div>
                            </div>
                        </div>
                        <div v-if="isLocked" class="text-xs text-amber-600">
                            {{ $t('quotes.form.quote_locked') }}
                        </div>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <div class="col-span-2 space-x-2">
                                <FloatingInput v-model="form.job_title" :label="$t('quotes.form.job_title')" class="mb-2" :disabled="isLocked" />
                                <div class="mb-3">
                                    <FloatingSelect
                                        v-model="form.property_id"
                                        :label="$t('quotes.form.property')"
                                        :options="propertyOptions"
                                        :placeholder="$t('quotes.form.no_property')"
                                        :disabled="isLocked"
                                    />
                                </div>
                                <div class="flex flex-row space-x-6">
                                    <div class="lg:col-span-3">
                                        <p>
                                            {{ $t('quotes.form.property_address') }}
                                        </p>
                                        <div v-if="selectedProperty" class="space-y-1">
                                            <div class="text-xs text-stone-600 dark:text-neutral-400">
                                                {{ selectedProperty.country }}
                                            </div>
                                            <div class="text-xs text-stone-600 dark:text-neutral-400">
                                                {{ selectedProperty.street1 }}
                                            </div>
                                            <div class="text-xs text-stone-600 dark:text-neutral-400">
                                                {{ selectedProperty.state }} - {{ selectedProperty.zip }}
                                            </div>
                                        </div>
                                        <div v-else class="text-xs text-stone-600 dark:text-neutral-400">
                                            {{ $t('quotes.form.no_property_selected') }}
                                        </div>
                                    </div>
                                    <div class="lg:col-span-3">
                                        <p>
                                            {{ $t('quotes.form.contact_details') }}
                                        </p>
                                        <div class="text-xs text-stone-600 dark:text-neutral-400">
                                            {{ customer.first_name }} {{ customer.last_name }}
                                        </div>
                                        <div class="text-xs text-stone-600 dark:text-neutral-400">
                                            {{ customer.email }}
                                        </div>
                                        <div class="text-xs text-stone-600 dark:text-neutral-400">
                                            {{ customer.phone }}
                                        </div>
                                    </div>
                                </div>

                            </div>
                            <div class="bg-white p-4 rounded-sm border border-stone-200 dark:bg-neutral-900 dark:border-neutral-700">
                                <div class="lg:col-span-3">
                                    <p>
                                        {{ $t('quotes.form.quote_details') }}
                                    </p>
                                    <div class="text-xs text-stone-600 dark:text-neutral-400 flex justify-between">
                                        <span>{{ $t('quotes.form.quote_label') }}:</span>
                                        <span>{{ lastQuotesNumber|| quote?.number }} </span>
                                    </div>
                                    <div class="mt-2">
                                        <FloatingSelect
                                            v-model="form.status"
                                            :label="$t('quotes.form.status')"
                                            :options="statusOptions"
                                            :disabled="isLocked"
                                            dense
                                        />
                                    </div>
                                    <div class="text-xs text-stone-600 dark:text-neutral-400 flex justify-between">
                                        <span>{{ $t('quotes.form.rate_opportunity') }}:</span>
                                        <span class="flex flex-row space-x-1">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round" class="lucide lucide-star h-4 w-4">
                                                <path
                                                    d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />
                                            </svg>
                                        </span>
                                    </div>
                                    <div class="text-xs text-stone-600 dark:text-neutral-400 flex justify-between mt-5">
                                        <button type="button" disabled
                                            class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-green-200 bg-white text-green-800 shadow-sm hover:bg-green-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-green-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-green-300 dark:hover:bg-green-700 dark:focus:bg-green-700">
                                            {{ $t('quotes.form.add_custom_fields') }}</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div
                        class="p-5 space-y-3 flex flex-col bg-white border border-stone-200 rounded-sm shadow-sm xl:shadow-none dark:bg-neutral-900 dark:border-neutral-700"
                        data-testid="demo-quote-line-items"
                    >
                        <ProductTableList
                            v-model="form.product"
                            :read-only="isLocked"
                            :allow-mixed-types="true"
                            :enable-price-lookup="true"
                            @update:subtotal="updateSubtotal"
                        />
                    </div>
                    <div
                        class="p-5 grid grid-cols-2 gap-4 justify-between bg-white border border-stone-200 rounded-sm shadow-sm xl:shadow-none dark:bg-neutral-900 dark:border-neutral-700">

                        <div v-if="showTemplatePicker" class="col-span-2 rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-semibold text-stone-700 dark:text-neutral-200">{{ $t('quotes.form.templates') }}</span>
                                <span>{{ $t('quotes.form.template_help') }}</span>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button
                                    v-for="template in templateOptions"
                                    :key="template.key"
                                    type="button"
                                    @click="applyTemplate(template)"
                                    class="rounded-sm border border-stone-200 bg-white px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-100 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                >
                                    {{ template.label }}
                                </button>
                            </div>
                        </div>

                        <div>
                            <FloatingTextarea v-model="form.messages" :label="$t('quotes.form.client_message')" :disabled="isLocked" />
                        </div>
                        <div class="border-l border-stone-200 dark:border-neutral-700 rounded-sm p-4">
                            <!-- List Item -->
                            <div class="py-4 grid grid-cols-2 gap-x-4  dark:border-neutral-700">
                                <div class="col-span-1">
                                    <p class="text-sm text-stone-500 dark:text-neutral-500">
                                        {{ $t('quotes.form.subtotal') }}:
                                    </p>
                                </div>
                                <div class="col-span-1 flex justify-end">
                                    <p>
                                        <a class="text-sm text-green-600 decoration-2 hover:underline font-medium focus:outline-none focus:underline dark:text-green-400 dark:hover:text-green-500"
                                            href="#">
                                            $ {{ form.subtotal }}
                                        </a>
                                    </p>
                                </div>
                            </div>
                            <!-- End List Item -->

                            <!-- List Item -->
                            <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-stone-200 dark:border-neutral-700">
                                <div class="col-span-1">
                                    <p class="text-sm text-stone-500 dark:text-neutral-500">
                                        {{ $t('quotes.form.discount') }}:
                                    </p>
                                </div>
                                <div class="flex justify-end">
                                    <p class="text-sm text-stone-800 dark:text-neutral-200">
                                        {{ $t('quotes.form.add_discount') }}
                                    </p>
                                </div>
                            </div>
                            <!-- End List Item -->

                            <!-- List Item -->
                            <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-stone-200 dark:border-neutral-700">
                                <!-- Label pour la ligne des taxes -->
                                <div class="col-span-1">
                                    <p class="text-sm text-stone-500 dark:text-neutral-500">
                                        {{ $t('quotes.form.tax') }}:
                                    </p>
                                </div>
                                <div class="flex justify-end">
                                    <div class="flex items-center gap-x-2">
                                        <button @click="toggleTaxDetails" type="button" :disabled="isLocked"
                                            class="py-1.5 ps-1.5 pe-2.5 inline-flex items-center gap-x-1 text-xs font-medium border border-green-500 text-green-800 rounded-sm disabled:opacity-50 disabled:pointer-events-none dark:bg-green-500/10 dark:text-green-500">
                                            {{ showTaxDetails ? $t('quotes.form.hide_taxes') : $t('quotes.form.add_tax') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <!-- Section des details des taxes (affichee ou masquee) -->
                            <div v-if="showTaxDetails"
                                class="space-y-3 py-4 border-t border-stone-200 dark:border-neutral-700">
                                <div v-if="!availableTaxes.length" class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ $t('quotes.form.no_taxes') }}
                                </div>
                                <div v-else class="space-y-2">
                                    <label v-for="tax in availableTaxes" :key="tax.id"
                                        class="flex items-center justify-between gap-3 text-sm text-stone-700 dark:text-neutral-200">
                                        <span class="flex items-center gap-2">
                                            <input type="checkbox" :value="tax.id" v-model="form.taxes" :disabled="isLocked"
                                                class="size-4 rounded border-stone-300 text-green-600 focus:ring-green-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-600" />
                                            {{ tax.name }} ({{ tax.rate }}%)
                                        </span>
                                        <span class="text-sm text-stone-800 dark:text-neutral-200">
                                            ${{ taxAmount(tax).toFixed(2) }}
                                        </span>
                                    </label>
                                </div>
                                <div class="flex justify-between font-bold">
                                    <p class="text-sm text-stone-800 dark:text-neutral-200">{{ $t('quotes.form.total_taxes') }}:</p>
                                    <p class="text-sm text-stone-800 dark:text-neutral-200">${{ totalTaxAmount.toFixed(2) }}</p>
                                </div>
                            </div>
                            <!-- End List Item -->

                            <!-- List Item -->
                            <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-stone-200 dark:border-neutral-700">
                                <div class="col-span-1">
                                    <p class="text-sm text-stone-800 font-bold dark:text-neutral-500">
                                        {{ $t('quotes.form.total_amount') }}:
                                    </p>
                                </div>
                                <div class="flex justify-end">
                                    <p class="text-sm text-stone-800 font-bold dark:text-neutral-200">
                                        $ {{ totalWithTaxes?.toFixed(2) }}
                                    </p>
                                </div>
                            </div>


                            <!-- End List Item -->

                            <!-- List Item -->
                            <div
                                class="py-4 grid grid-cols-2 items-center gap-x-4 border-t border-stone-600 dark:border-neutral-700">
                                <!-- Label -->
                                <div class="col-span-1">
                                    <p class="text-sm text-stone-500 dark:text-neutral-500">{{ $t('quotes.form.required_deposit') }}:</p>
                                </div>

                                <!-- Contenu dynamique -->
                                <div class="flex justify-end">
                                    <!-- Si le champ est affiché -->
                                    <div v-if="showDepositInput" class="flex items-center gap-x-2">
                                        <input type="number" v-model="form.initial_deposit" @blur="validateDeposit" :disabled="isLocked"
                                            class="w-20 p-1 text-sm border border-stone-300 rounded-sm focus:outline-none focus:ring focus:ring-green-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-white"
                                            :min="minimumDeposit" />
                                        <span class="text-xs text-stone-500 dark:text-neutral-500">
                                            ({{ $t('quotes.form.minimum_label', { amount: minimumDeposit }) }})
                                        </span>
                                    </div>

                                    <!-- Si le champ n'est pas affiché -->
                                    <span v-else-if="!isLocked" @click="toggleDepositInput"
                                        class="py-1.5 ps-1.5 pe-2.5 inline-flex items-center gap-x-1 text-xs font-medium bg-green-100 text-green-800 rounded-sm cursor-pointer hover:bg-green-200 dark:bg-green-500/10 dark:text-green-500 dark:hover:bg-green-600">
                                        {{ $t('quotes.form.add_required_deposit') }}
                                    </span>
                                </div>
                            </div>
                            <!-- End List Item -->
                        </div>
                    </div>
                    <div
                        class="p-5 grid grid-cols-1 gap-4 justify-between bg-white border border-stone-200 rounded-sm shadow-sm xl:shadow-none dark:bg-neutral-900 dark:border-neutral-700">
                        <FloatingTextarea v-model="form.notes" :label="$t('quotes.form.terms')" :disabled="isLocked" />

                        <div class="flex justify-between">
                            <button type="button"
                                class="py-1.5 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 focus:outline-none focus:bg-stone-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700">
                                {{ $t('quotes.form.cancel') }}
                            </button>
                            <div>
                                <button type="button" disabled
                                    class="py-1.5 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-green-600 text-green-600 hover:border-stone-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-stone-500">
                                    {{ $t('quotes.form.save_and_create_another') }}
                                </button>
                                <button id="hs-pro-in1trsbgwmdid1" type="submit" data-testid="demo-quote-save"
                                    :disabled="isLocked || form.processing"
                                    class="hs-tooltip-toggle ml-4 py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                                    {{ $t('quotes.form.save_quote') }}
                                </button>
                            </div>
                        </div>
                    </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
