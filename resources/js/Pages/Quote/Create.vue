<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import FloatingNumberMiniInput from '@/Components/FloatingNumberMiniInput.vue';
import { ref, watch, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
    customer: Object,
    quote: Object,
    lastQuotesNumber: String,
});

const form = useForm({
    // Pré-remplissage pour création ou édition
    customer_id: props.quote?.customer_id || props.customer.id,
    property_id: props.quote?.property_id || props.customer.properties[0]?.id,
    job_title: props.quote?.job_title || '',
    notes: props.quote?.notes || '',
    messages: props.quote?.messages || '',
    product: props.quote?.products?.map(product => ({
        id: product.id,
        name: product.name,
        quantity: product.pivot.quantity,
        price: product.pivot.price,
        total: product.pivot.total,
    })) || [{ id: null, name: '', quantity: 1, price: 0, total: 0 }],
    subtotal: props.quote?.subtotal || 0,
    discount: props.quote?.discount || 0,
    tax: props.quote?.tax || 0,
    total: props.quote?.total || 0,
    initial_deposit: props.quote?.initial_deposit || 0,
});

// Ajouter une nouvelle ligne de produit
const addNewLine = () => {
    form.product.push({ id: null, name: '', quantity: 1, price: 0, total: 0 });
};

// Supprimer une ligne de produit
const removeLine = index => {
    if (form.product.length > 1) {
        form.product.splice(index, 1);
    }
};

// Gestion de la recherche de produits
const searchResults = ref([]);
const searchProducts = async (query, index) => {
    if (query.length > 0) {
        try {
            const response = await axios.get(route('product.search'), { params: { query } });
            searchResults.value[index] = response.data;
        } catch (error) {
            console.error('Error fetching products:', error);
        }
    } else {
        searchResults.value[index] = [];
    }
};

// Sélectionner un produit
const selectProduct = (product, index) => {
    form.product[index] = {
        id: product.id,
        name: product.name,
        quantity: 1,
        price: product.price,
        total: product.price,
    };
    searchResults.value[index] = [];
};

// Watch pour recalculer les totaux
watch(
    () => form.product,
    (newProducts) => {
        // Mise à jour des totaux par produit
        newProducts.forEach(product => {
            product.total = product.quantity * product.price;
        });

        // Calcul du sous-total
        form.subtotal = newProducts.reduce((acc, product) => acc + product.total, 0);
    },
    { deep: true }
);

// Taxes (TPS/TVQ) et totaux
const showTaxDetails = ref(false);

// Vérification initiale pour déterminer si les détails des taxes doivent être affichés
if (props.quote) {
    const subtotal = parseFloat(props.quote.subtotal || 0);
    const total = parseFloat(props.quote.total || 0);

    // Si le total est différent du sous-total, cela signifie que des taxes sont incluses
    showTaxDetails.value = subtotal !== total;
}

const taxRates = { tps: 0.05, tvq: 0.09975 };
const taxes = computed(() => ({
    tps: form.subtotal * taxRates.tps,
    tvq: form.subtotal * taxRates.tvq,
    totalTaxes: form.subtotal * (taxRates.tps + taxRates.tvq),
}));
const totalWithTaxes = computed(() => {
    const subtotal = Number(form.subtotal) || 0; // Assurez-vous que le sous-total est un nombre
    const totalTaxes = showTaxDetails.value ? taxes?.value.totalTaxes || 0 : 0; // Assurez-vous que les taxes sont valides
    return parseFloat((subtotal + totalTaxes).toFixed(2)); // Retourne un nombre avec 2 décimales
});


// Activer/désactiver les détails des taxes
const toggleTaxDetails = () => {
    showTaxDetails.value = !showTaxDetails.value;
    form.total = totalWithTaxes.value;
};

// Gestion des acomptes
const showDepositInput = ref(false);

// Vérification initiale pour déterminer si les détails des taxes doivent être affichés
if (props.quote) {
    showDepositInput.value =  props.quote.initial_deposit > 0? true : false;
}

const depositValue = ref(form.initial_deposit || 0);
const minimumDeposit = computed(() => (form.total * 0.15).toFixed(2));
const validateDeposit = () => {
    if (depositValue.value < minimumDeposit.value) {
        depositValue.value = minimumDeposit.value;
    }
};
const toggleDepositInput = () => {
    showDepositInput.value = true;
    depositValue.value = minimumDeposit.value;
};

// Soumettre le formulaire
const submit = () => {
    const routeName = props.quote?.id ? 'customer.quote.update' : 'customer.quote.store';
    const routeParams = props.quote?.id ? { quote: props.quote.id } : { customer: props.customer.id };

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
        <div class="grid grid-cols-5 gap-4">
            <div class="col-span-1"></div> <!-- Colonne vide -->
            <div class="col-span-3">
                <form @submit.prevent="submit">
                    <div
                        class="p-5 space-y-3 flex flex-col bg-gray-100 border border-gray-100 rounded-sm shadow-sm xl:shadow-none dark:bg-green-800 dark:border-green-700">
                        <!-- Header -->
                        <div class="flex justify-between items-center mb-4">
                            <h1 class="text-xl inline-block font-semibold text-gray-800 dark:text-green-100">
                                Quote For {{ customer.company_name }}
                            </h1>
                        </div>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <div class="col-span-2 space-x-2">
                                <FloatingInput v-model="form.job_title" label="Job title" class="mb-2" />
                                <div class="flex flex-row space-x-6">
                                    <div class="lg:col-span-3">
                                        <p>
                                            Property address
                                        </p>
                                        <div class="text-xs text-gray-600">
                                            {{ customer.properties[0].country }}
                                        </div>
                                        <div class="text-xs text-gray-600">
                                            {{ customer.properties[0].street1 }}
                                        </div>
                                        <div class="text-xs text-gray-600">
                                            {{ customer.properties[0].state }} - {{ customer.properties[0].zip }}
                                        </div>
                                    </div>
                                    <div class="lg:col-span-3">
                                        <p>
                                            Contact details
                                        </p>
                                        <div class="text-xs text-gray-600">
                                            {{ customer.first_name }} {{ customer.last_name }}
                                        </div>
                                        <div class="text-xs text-gray-600">
                                            {{ customer.email }}
                                        </div>
                                        <div class="text-xs text-gray-600">
                                            {{ customer.phone }}
                                        </div>
                                    </div>
                                </div>

                            </div>
                            <div class="bg-white p-4 ">
                                <div class="lg:col-span-3">
                                    <p>
                                        Quote details
                                    </p>
                                    <div class="text-xs text-gray-600 flex justify-between">
                                        <span> Quote :</span>
                                        <span>{{ lastQuotesNumber|| quote?.number }} </span>
                                    </div>
                                    <div class="text-xs text-gray-600 flex justify-between">
                                        <span> Rate opportunity :</span>
                                        <span class="flex flex-row space-x-1">
                                            <svg xmlns="http://www.w3.org/2000/svg" cl viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round" class="lucide lucide-star h-4 w-4">
                                                <path
                                                    d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />
                                            </svg>
                                        </span>
                                    </div>
                                    <div class="text-xs text-gray-600 flex justify-between mt-5">
                                        <button type="button" disabled
                                            class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-green-200 bg-white text-green-800 shadow-sm hover:bg-green-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-green-50 dark:bg-green-800 dark:border-green-700 dark:text-green-300 dark:hover:bg-green-700 dark:focus:bg-green-700">
                                            Add custom fields</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div
                        class="p-5 space-y-3 flex flex-col bg-white border border-gray-100 rounded-sm shadow-sm xl:shadow-none dark:bg-green-800 dark:border-green-700">
                        <!-- Table Section -->
                        <div
                            class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-gray-100 [&::-webkit-scrollbar-thumb]:bg-gray-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                            <div class="min-w-full inline-block align-middle min-h-[300px]">
                                <!-- Table -->
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-neutral-700">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="min-w-[450px] ">
                                                <div
                                                    class="pe-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-gray-800 dark:text-neutral-200">
                                                    Product/Services
                                                </div>
                                            </th>

                                            <th scope="col">
                                                <div
                                                    class="px-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-gray-800 dark:text-neutral-200">
                                                    Qty.
                                                </div>
                                            </th>

                                            <th scope="col">
                                                <div
                                                    class="px-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-gray-800 dark:text-neutral-200">
                                                    Unit cost
                                                </div>
                                            </th>

                                            <th scope="col">
                                                <div
                                                    class="px-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-gray-800 dark:text-neutral-200">
                                                    Total
                                                </div>
                                            </th>
                                            <th scope="col" class="size-px">
                                                <div
                                                    class="px-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-gray-800 dark:text-neutral-200">
                                                    Actions
                                                </div>
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody class="divide-y divide-gray-200 dark:divide-neutral-700">
                                        <tr v-for="(product, index) in form.product" :key="index">
                                            <td class="size-px whitespace-nowrap px-4 py-3">
                                                <span class="text-sm text-gray-600 dark:text-neutral-400">
                                                    <div class="relative">
                                                        <FloatingInput autofocus v-model="form.product[index].name"
                                                            label="Name"
                                                            @input="searchProducts(form.product[index].name, index)" />
                                                    </div>
                                                    <div class="relative w-full">
                                                        <ul v-if="searchResults[index]?.length"
                                                            class="absolute left-0 top-full z-50 w-full max-h-60 overflow-y-auto bg-white border border-gray-200 rounded-md shadow-lg dark:bg-neutral-800 dark:border-neutral-700">
                                                            <li v-for="result in searchResults[index]" :key="result.id"
                                                                @click="selectProduct(result, index)"
                                                                class="px-3 py-2 cursor-pointer hover:bg-gray-100 dark:hover:bg-neutral-700 text-gray-800 dark:text-neutral-200">
                                                                {{ result.name }}
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </span>
                                            </td>
                                            <td class="size-px whitespace-nowrap px-4 py-3">
                                                <FloatingNumberMiniInput v-model="form.product[index].quantity"
                                                    label="Quantity" />
                                            </td>
                                            <td class="size-px whitespace-nowrap px-4 py-3">
                                                <FloatingNumberMiniInput v-model="form.product[index].price"
                                                    aria-disabled="true" label="Unit Price" />
                                            </td>
                                            <td class="size-px whitespace-nowrap px-4 py-3">
                                                <FloatingNumberMiniInput v-model="form.product[index].total"
                                                    label="Total" />
                                            </td>
                                            <td>
                                                <button type="button" v-if="form.product.length > 1"
                                                    @click="removeLine(index)"
                                                    class="px-4 py-4 inline-flex items-center gap-x-2 text-sm font-medium text-red-800  hover:text-red-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none   dark:text-red-300 ">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                        viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                        class="lucide lucide-trash-2">
                                                        <path d="M3 6h18" />
                                                        <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" />
                                                        <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" />
                                                        <line x1="10" x2="10" y1="11" y2="17" />
                                                        <line x1="14" x2="14" y1="11" y2="17" />
                                                    </svg>
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <!-- End Table -->
                            </div>
                        </div>
                        <!-- End Table Section -->
                        <div class="text-xs text-gray-600 flex justify-between mt-5">
                            <button id="hs-pro-in1trsbgwmdid1" type="button" @click="addNewLine"
                                class="hs-tooltip-toggle ml-4 py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                                Add new product line
                            </button>
                        </div>
                    </div>
                    <div
                        class="p-5 grid grid-cols-2 gap-4 justify-between bg-white border border-gray-100 rounded-sm shadow-sm xl:shadow-none dark:bg-green-800 dark:border-green-700">

                        <div>
                            <FloatingTextarea v-model="form.messages" label="Client message" />
                        </div>
                        <div class="border-l border-gray-200 dark:border-neutral-700 rounded-sm p-4">
                            <!-- List Item -->
                            <div class="py-4 grid grid-cols-2 gap-x-4  dark:border-neutral-700">
                                <div class="col-span-1">
                                    <p class="text-sm text-gray-500 dark:text-neutral-500">
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
                            <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-gray-200 dark:border-neutral-700">
                                <div class="col-span-1">
                                    <p class="text-sm text-gray-500 dark:text-neutral-500">
                                        Discount (%):
                                    </p>
                                </div>
                                <div class="flex justify-end">
                                    <p class="text-sm text-gray-800 dark:text-neutral-200">
                                        Add discount
                                    </p>
                                </div>
                            </div>
                            <!-- End List Item -->

                            <!-- List Item -->
                            <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-gray-200 dark:border-neutral-700">
                                <!-- Label pour la ligne des taxes -->
                                <div class="col-span-1">
                                    <p class="text-sm text-gray-500 dark:text-neutral-500">
                                        Tax:
                                    </p>
                                </div>
                                <div class="flex justify-end">
                                    <div class="flex items-center gap-x-2">
                                        <button @click="toggleTaxDetails" type="button"
                                            class="py-1.5 ps-1.5 pe-2.5 inline-flex items-center gap-x-1 text-xs font-medium border border-green-500 text-green-800 rounded-sm dark:bg-green-500/10 dark:text-green-500">
                                            {{ showTaxDetails ? 'Hide taxes' : 'Add tax' }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <!-- Section des détails des taxes (affichée ou masquée) -->
                            <div v-if="showTaxDetails"
                                class="space-y-2 py-4 border-t border-gray-200 dark:border-neutral-700">
                                <div class="flex justify-between">
                                    <p class="text-sm text-gray-500 dark:text-neutral-500">TPS (5%) :</p>
                                    <p class="text-sm text-gray-800 dark:text-neutral-200">${{ taxes.tps.toFixed(2) }}</p>
                                </div>
                                <div class="flex justify-between">
                                    <p class="text-sm text-gray-500 dark:text-neutral-500">TVQ (9.975%) :</p>
                                    <p class="text-sm text-gray-800 dark:text-neutral-200">${{ taxes.tvq.toFixed(2) }}</p>
                                </div>
                                <div class="flex justify-between font-bold">
                                    <p class="text-sm text-gray-800 dark:text-neutral-200">Total taxes :</p>
                                    <p class="text-sm text-gray-800 dark:text-neutral-200">${{ taxes.totalTaxes.toFixed(2)
                                        }}</p>
                                </div>
                            </div>
                            <!-- End List Item -->

                            <!-- List Item -->
                            <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-gray-200 dark:border-neutral-700">
                                <div class="col-span-1">
                                    <p class="text-sm text-gray-800 font-bold dark:text-neutral-500">
                                        Total amount:
                                    </p>
                                </div>
                                <div class="flex justify-end">
                                    <p class="text-sm text-gray-800 font-bold dark:text-neutral-200">
                                        $ {{ totalWithTaxes?.toFixed(2) }}
                                    </p>
                                </div>
                            </div>


                            <!-- End List Item -->

                            <!-- List Item -->
                            <div
                                class="py-4 grid grid-cols-2 items-center gap-x-4 border-t border-gray-600 dark:border-neutral-700">
                                <!-- Label -->
                                <div class="col-span-1">
                                    <p class="text-sm text-gray-500 dark:text-neutral-500">Required deposit:</p>
                                </div>

                                <!-- Contenu dynamique -->
                                <div class="flex justify-end">
                                    <!-- Si le champ est affiché -->
                                    <div v-if="showDepositInput" class="flex items-center gap-x-2">
                                        <input type="number" v-model="form.initial_deposit" @blur="validateDeposit"
                                            class="w-20 p-1 text-sm border border-gray-300 rounded-sm focus:outline-none focus:ring focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700 dark:text-white"
                                            :min="minimumDeposit" />
                                        <span class="text-xs text-gray-500 dark:text-neutral-500">
                                            (Min: ${{ minimumDeposit }})
                                        </span>
                                    </div>

                                    <!-- Si le champ n'est pas affiché -->
                                    <span v-else @click="toggleDepositInput"
                                        class="py-1.5 ps-1.5 pe-2.5 inline-flex items-center gap-x-1 text-xs font-medium bg-green-100 text-green-800 rounded-sm cursor-pointer hover:bg-green-200 dark:bg-green-500/10 dark:text-green-500 dark:hover:bg-green-600">
                                        Add required deposit
                                    </span>
                                </div>
                            </div>
                            <!-- End List Item -->
                        </div>
                    </div>
                    <div
                        class="p-5 grid grid-cols-1 gap-4 justify-between bg-white border border-gray-100 rounded-sm shadow-sm xl:shadow-none dark:bg-green-800 dark:border-green-700">
                        <FloatingTextarea v-model="form.notes" label="Terms and conditions" />

                        <div class="flex justify-between">
                            <button type="button"
                                class="py-1.5 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-gray-200 bg-white text-gray-800 shadow-sm hover:bg-gray-50 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 focus:outline-none focus:bg-gray-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700">
                                Cancel
                            </button>
                            <div>
                                <button type="button" disabled
                                    class="py-1.5 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-green-600 text-green-600 hover:border-gray-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-gray-500">
                                    Save and create another
                                </button>
                                <button id="hs-pro-in1trsbgwmdid1" type="submit"
                                    class="hs-tooltip-toggle ml-4 py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                                    Save quote
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="col-span-1"></div> <!-- Colonne vide -->
        </div>
    </AuthenticatedLayout>
</template>
