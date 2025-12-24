<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import ProductTableList from '@/Components/ProductTableList.vue';
import { ref, watch, computed } from 'vue';

const props = defineProps({
    customer: Object,
    quote: Object,
    lastQuotesNumber: String,
    taxes: Array,
    selectedPropertyId: Number,
});

const page = usePage();
const companyName = computed(() => page.props.auth?.account?.company?.name || 'Entreprise');
const companyLogo = computed(() => page.props.auth?.account?.company?.logo_url || null);

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
    notes: props.quote?.notes || '',
    messages: props.quote?.messages || '',
    product: props.quote?.products?.map(product => ({
        id: product.id,
        name: product.name,
        quantity: Number(product.pivot?.quantity ?? 1),
        price: Number(product.pivot?.price ?? product.price ?? 0),
        total: Number(product.pivot?.total ?? 0),
    })) || [{ id: null, name: '', quantity: 1, price: 0, total: 0 }],
    subtotal: Number(props.quote?.subtotal || 0),
    total: Number(props.quote?.total || 0),
    initial_deposit: Number(props.quote?.initial_deposit || 0),
    taxes: props.quote?.taxes?.map(tax => tax.tax_id) || [],
});

const properties = computed(() => props.customer?.properties || []);
const selectedProperty = computed(() => {
    if (!properties.value.length || !form.property_id) {
        return null;
    }
    return properties.value.find((property) => property.id === form.property_id) || null;
});

const availableTaxes = computed(() => props.taxes || []);
const isLocked = computed(() => Boolean(props.quote?.archived_at) || props.quote?.status === 'accepted');


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
            console.log('Quote saved successfully!');
        },
        onError: (errors) => {
            console.error('Validation errors:', errors);
        },
    });
};

</script>

<template>

    <Head title="Create quote" />

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
                                    class="h-12 w-12 rounded-sm border border-stone-200 object-cover dark:border-neutral-700" />
                                <div>
                                    <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                        {{ companyName }}
                                    </p>
                                    <h1 class="text-xl inline-block font-semibold text-stone-800 dark:text-green-100">
                                        Quote For {{ customer.company_name }}
                                    </h1>
                                </div>
                            </div>
                        </div>
                        <div v-if="isLocked" class="text-xs text-amber-600">
                            This quote is locked because it has been accepted or archived.
                        </div>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <div class="col-span-2 space-x-2">
                                <FloatingInput v-model="form.job_title" label="Job title" class="mb-2" :disabled="isLocked" />
                                <div class="mb-3">
                                    <label class="text-xs text-stone-500 dark:text-neutral-400">Property</label>
                                    <select v-model.number="form.property_id"
                                        :disabled="isLocked"
                                        class="mt-1 w-full py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                        <option v-if="!customer.properties || !customer.properties.length" value="">No property</option>
                                        <option v-for="property in customer.properties" :key="property.id" :value="property.id">
                                            {{ property.street1 }}{{ property.city ? ', ' + property.city : '' }}
                                        </option>
                                    </select>
                                </div>
                                <div class="flex flex-row space-x-6">
                                    <div class="lg:col-span-3">
                                        <p>
                                            Property address
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
                                            No property selected.
                                        </div>
                                    </div>
                                    <div class="lg:col-span-3">
                                        <p>
                                            Contact details
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
                                        Quote details
                                    </p>
                                    <div class="text-xs text-stone-600 dark:text-neutral-400 flex justify-between">
                                        <span> Quote :</span>
                                        <span>{{ lastQuotesNumber|| quote?.number }} </span>
                                    </div>
                                    <div class="text-xs text-stone-600 dark:text-neutral-400 flex justify-between mt-2">
                                    <span>Status :</span>
                                    <select v-model="form.status"
                                            :disabled="isLocked"
                                            class="py-1 px-2 text-xs bg-white border border-stone-200 rounded-sm text-stone-700 focus:border-green-500 focus:ring-green-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                        <option value="draft">Draft</option>
                                        <option value="sent">Sent</option>
                                            <option value="accepted">Accepted</option>
                                            <option value="declined">Declined</option>
                                        </select>
                                    </div>
                                    <div class="text-xs text-stone-600 dark:text-neutral-400 flex justify-between">
                                        <span> Rate opportunity :</span>
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
                                            Add custom fields</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div
                        class="p-5 space-y-3 flex flex-col bg-white border border-stone-200 rounded-sm shadow-sm xl:shadow-none dark:bg-neutral-900 dark:border-neutral-700">
                        <ProductTableList v-model="form.product" :read-only="isLocked" @update:subtotal="updateSubtotal" />
                    </div>
                    <div
                        class="p-5 grid grid-cols-2 gap-4 justify-between bg-white border border-stone-200 rounded-sm shadow-sm xl:shadow-none dark:bg-neutral-900 dark:border-neutral-700">

                        <div>
                            <FloatingTextarea v-model="form.messages" label="Client message" :disabled="isLocked" />
                        </div>
                        <div class="border-l border-stone-200 dark:border-neutral-700 rounded-sm p-4">
                            <!-- List Item -->
                            <div class="py-4 grid grid-cols-2 gap-x-4  dark:border-neutral-700">
                                <div class="col-span-1">
                                    <p class="text-sm text-stone-500 dark:text-neutral-500">
                                        Subtotal:
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
                                        Discount (%):
                                    </p>
                                </div>
                                <div class="flex justify-end">
                                    <p class="text-sm text-stone-800 dark:text-neutral-200">
                                        Add discount
                                    </p>
                                </div>
                            </div>
                            <!-- End List Item -->

                            <!-- List Item -->
                            <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-stone-200 dark:border-neutral-700">
                                <!-- Label pour la ligne des taxes -->
                                <div class="col-span-1">
                                    <p class="text-sm text-stone-500 dark:text-neutral-500">
                                        Tax:
                                    </p>
                                </div>
                                <div class="flex justify-end">
                                    <div class="flex items-center gap-x-2">
                                        <button @click="toggleTaxDetails" type="button" :disabled="isLocked"
                                            class="py-1.5 ps-1.5 pe-2.5 inline-flex items-center gap-x-1 text-xs font-medium border border-green-500 text-green-800 rounded-sm disabled:opacity-50 disabled:pointer-events-none dark:bg-green-500/10 dark:text-green-500">
                                            {{ showTaxDetails ? 'Hide taxes' : 'Add tax' }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <!-- Section des details des taxes (affichee ou masquee) -->
                            <div v-if="showTaxDetails"
                                class="space-y-3 py-4 border-t border-stone-200 dark:border-neutral-700">
                                <div v-if="!availableTaxes.length" class="text-xs text-stone-500 dark:text-neutral-500">
                                    No taxes configured.
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
                                    <p class="text-sm text-stone-800 dark:text-neutral-200">Total taxes :</p>
                                    <p class="text-sm text-stone-800 dark:text-neutral-200">${{ totalTaxAmount.toFixed(2) }}</p>
                                </div>
                            </div>
                            <!-- End List Item -->

                            <!-- List Item -->
                            <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-stone-200 dark:border-neutral-700">
                                <div class="col-span-1">
                                    <p class="text-sm text-stone-800 font-bold dark:text-neutral-500">
                                        Total amount:
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
                                    <p class="text-sm text-stone-500 dark:text-neutral-500">Required deposit:</p>
                                </div>

                                <!-- Contenu dynamique -->
                                <div class="flex justify-end">
                                    <!-- Si le champ est affiché -->
                                    <div v-if="showDepositInput" class="flex items-center gap-x-2">
                                        <input type="number" v-model="form.initial_deposit" @blur="validateDeposit" :disabled="isLocked"
                                            class="w-20 p-1 text-sm border border-stone-300 rounded-sm focus:outline-none focus:ring focus:ring-green-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-white"
                                            :min="minimumDeposit" />
                                        <span class="text-xs text-stone-500 dark:text-neutral-500">
                                            (Min: ${{ minimumDeposit }})
                                        </span>
                                    </div>

                                    <!-- Si le champ n'est pas affiché -->
                                    <span v-else-if="!isLocked" @click="toggleDepositInput"
                                        class="py-1.5 ps-1.5 pe-2.5 inline-flex items-center gap-x-1 text-xs font-medium bg-green-100 text-green-800 rounded-sm cursor-pointer hover:bg-green-200 dark:bg-green-500/10 dark:text-green-500 dark:hover:bg-green-600">
                                        Add required deposit
                                    </span>
                                </div>
                            </div>
                            <!-- End List Item -->
                        </div>
                    </div>
                    <div
                        class="p-5 grid grid-cols-1 gap-4 justify-between bg-white border border-stone-200 rounded-sm shadow-sm xl:shadow-none dark:bg-neutral-900 dark:border-neutral-700">
                        <FloatingTextarea v-model="form.notes" label="Terms and conditions" :disabled="isLocked" />

                        <div class="flex justify-between">
                            <button type="button"
                                class="py-1.5 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 focus:outline-none focus:bg-stone-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700">
                                Cancel
                            </button>
                            <div>
                                <button type="button" disabled
                                    class="py-1.5 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-green-600 text-green-600 hover:border-stone-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-stone-500">
                                    Save and create another
                                </button>
                                <button id="hs-pro-in1trsbgwmdid1" type="submit"
                                    :disabled="isLocked || form.processing"
                                    class="hs-tooltip-toggle ml-4 py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                                    Save quote
                                </button>
                            </div>
                        </div>
                    </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
