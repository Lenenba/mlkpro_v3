<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import { Link, useForm, Head } from '@inertiajs/vue3';
import ListInput from '@/Components/ListInput.vue';
import { ref } from 'vue';
import axios from 'axios';


const props = defineProps({
    customer: Object,
});

const Salutation = [
    { id: 'Mr', name: 'Mr' },
    { id: 'Mrs', name: 'Mrs' },
    { id: 'Miss', name: 'Miss' },
];

const resolvePrimaryProperty = () => {
    const properties = props.customer?.properties;
    const primary = Array.isArray(properties)
        ? (properties.find((property) => property.is_default) || properties[0] || null)
        : (properties || null);

    return {
        street1: primary?.street1 || '',
        street2: primary?.street2 || '',
        city: primary?.city || '',
        state: primary?.state || '',
        zip: primary?.zip || '',
        country: primary?.country || '',
    };
};
// Initialize the form
const form = useForm({
    first_name: props.customer?.first_name || '',
    last_name: props.customer?.last_name || '',
    email: props.customer?.email || '',
    company_name: props.customer?.company_name || '',
    billing_same_as_physical: props.customer?.billing_same_as_physical || false,
    logo: props.customer?.logo || '',
    description: props.customer?.description || '',
    refer_by: props.customer?.refer_by || '',
    salutation: props.customer?.salutation || '',
    phone: props.customer?.phone || '',
    properties: resolvePrimaryProperty(),
});


const submit = () => {
    const routeName = props.customer?.id ? 'customer.update' : 'customer.store';
    const routeParams = props.customer?.id ? props.customer.id : undefined;

    form[props.customer?.id ? 'put' : 'post'](route(routeName, routeParams), {
        onSuccess: () => {
            console.log('Customer saved successfully!');
        },
        onError: (errors) => {
            console.error('Validation errors:', errors);
        },
    });
};

const query = ref('');
const suggestions = ref([]);
const isSearching = ref(false);

const searchAddress = async () => {
    if (query.value.length < 2) {
        suggestions.value = [];
        return;
    }

    isSearching.value = true;
    try {
        const response = await axios.get(
            `https://api-adresse.data.gouv.fr/search/`,
            {
                params: {
                    q: query.value,
                    limit: 5,
                },
            }
        );

        suggestions.value = response.data.features.map((feature) => ({
            id: feature.properties.id,
            label: feature.properties.label,
            details: feature.properties,
        }));
    } catch (error) {
        console.error('Erreur lors de la recherche d\'adresse :', error);
    } finally {
        isSearching.value = false;
    }
};

const selectAddress = (details) => {
    form.properties.street1 = details.name || '';
    form.properties.street2 = '';
    form.properties.city = details.city || '';
    form.properties.state = details.context || '';
    form.properties.zip = details.postcode || '';
    form.properties.country = 'France';
    suggestions.value = [];

};
</script>
<template>

    <Head title="Customers" />
    <AuthenticatedLayout>
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-1 md:gap-3 lg:gap-1 ">
            <div></div>
            <div>
                <h1 class="text-xl font-bold text-gray-800 dark:text-white">New Client</h1>
            </div>
            <div></div>
            <div></div>

        </div>
        <form @submit.prevent="submit">
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-1 md:gap-3 lg:gap-1 ">
                <div></div>
                <div
                    class="flex flex-col bg-white border shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70">
                    <div class="flex flex-row  border-b rounded-t-sm py-3 px-4 md:px-5 dark:border-neutral-700">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-user">
                            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                        <h3 class="text-lg  ml-2 font-bold text-gray-800 dark:text-white">
                            Client details
                        </h3>
                    </div>
                    <div class="p-4 md:p-5">
                        <div class="flex flex-row">
                            <FloatingSelect label="Title" v-model="form.salutation" class="w-1/5"
                                :options="Salutation" />
                            <FloatingInput v-model="form.first_name" label="First name" class="w-2/5" />
                            <FloatingInput v-model="form.last_name" label="Last name" class="w-2/5" />
                        </div>
                        <FloatingInput v-model="form.company_name" label="Company name" />
                        <h2 class="pt-4 text-sm  my-2 font-bold text-gray-800 dark:text-white"> Contact details</h2>
                        <FloatingInput v-model="form.phone" label="Phone" />
                        <FloatingInput v-model="form.email" label="Email address" />
                        <h2 class="pt-4 text-sm  my-2 font-bold text-gray-800 dark:text-white"> Additional notifications
                        </h2>
                        <ListInput />
                        <h2 class="pt-4 text-sm  my-2 font-bold text-gray-800 dark:text-white"> Additional client detail
                        </h2>
                        <FloatingTextarea v-model="form.description" label="Description" />
                        <FloatingInput v-model="form.refer_by" label="Referred by" />
                    </div>
                </div>
                <div
                    class="flex flex-col bg-gray-100 border shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70">
                    <div class="flex flex-row border-b rounded-t-sm py-3 px-4 md:px-5 dark:border-neutral-700">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-house">
                            <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" />
                            <path
                                d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                        </svg>
                        <h3 class="text-lg  ml-2 font-bold text-gray-800 dark:text-white">
                            Properties
                        </h3>
                    </div>
                    <div class="p-4 md:p-5">

                        <div class="max-w-full mb-4">
                            <!-- SearchBox -->
                            <div class="relative" data-hs-combo-box='{
                                    "groupingType": "default",
                                    "preventSelection": true,
                                    "isOpenOnFocus": true
                                }'>
                                <!-- Input Field -->
                                <div class="relative">
                                    <div
                                        class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                                        <svg class="shrink-0 size-4 text-gray-400 dark:text-white/60"
                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="11" cy="11" r="8"></circle>
                                            <path d="m21 21-4.3-4.3"></path>
                                        </svg>
                                    </div>
                                    <input v-model="query" @input="searchAddress"
                                        class="py-3 ps-10 pe-4 block w-full border-gray-200 rounded-sm text-sm focus:border-blue-500 focus:ring-blue-500"
                                        type="text" role="combobox" aria-expanded="false"
                                        placeholder="Search for an address" data-hs-combo-box-input="" />
                                </div>

                                <!-- Suggestions Dropdown -->
                                <div v-if="suggestions.length"
                                    class="absolute z-50 w-full bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:bg-neutral-800">
                                    <div
                                        class="max-h-[300px] p-2 overflow-y-auto overflow-hidden [&::-webkit-scrollbar]:w-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-gray-100 [&::-webkit-scrollbar-thumb]:bg-gray-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                                        <div v-for="suggestion in suggestions" :key="suggestion.id"
                                            class="py-2 px-3 flex items-center gap-x-3 hover:bg-gray-100 rounded-lg dark:hover:bg-neutral-700 cursor-pointer"
                                            @click="selectAddress(suggestion.details)">
                                            <span class="text-sm text-gray-800 dark:text-neutral-200">{{
                                                suggestion.label
                                                }}</span>
                                        </div>
                                    </div>
                                </div>
                                <!-- End Suggestions Dropdown -->
                            </div>
                            <!-- End SearchBox -->
                        </div>
                        <FloatingInput v-model="form.properties.street1" label="Street1" />
                        <FloatingInput v-model="form.properties.street2" label="Street2" />
                        <div class="flex flex-row">
                            <FloatingInput v-model="form.properties.city" label="City" class="w-full" />
                            <FloatingInput v-model="form.properties.state" label="State" class="w-full" />
                        </div>
                        <div class="flex flex-row">
                            <FloatingInput v-model="form.properties.zip" label="Zip code" class="w-full" />
                            <FloatingInput v-model="form.properties.country" label="Country" class="w-full" />
                        </div>

                        <!-- Input Group -->
                        <div class="flex flex-col sm:flex-row sm:items-center gap-2 mt-4">
                            <div class="flex items-center">
                                <input type="checkbox" v-model="form.billing_same_as_physical"
                                    class="shrink-0 size-3.5 border-gray-300 rounded text-green-600 focus:ring-green-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-gray-800"
                                    id="hs-pro-danscch">
                                <label for="hs-pro-danscch" class="text-sm text-gray-500 ms-2 dark:text-neutral-500">
                                    Billing address is the same as the property address
                                </label>
                            </div>
                        </div>
                        <!-- End Input Group -->
                    </div>
                </div>
                <div></div>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-1 md:gap-3 lg:gap-1 mt-4">
                <div></div>
                <div>
                    <button type="button"
                        class="py-1.5 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-gray-200 bg-white text-gray-800 shadow-sm hover:bg-gray-50 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 focus:outline-none focus:bg-gray-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700">
                        Cancel
                    </button>
                </div>
                <div class="flex justify-end">
                    <button type="button"
                        class="py-1.5 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-green-600 text-green-600 hover:border-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                        Save and create another
                    </button>
                    <button type="submit"
                        class="py-1.5 ml-4 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                        Save client
                    </button>
                </div>
                <div></div>

            </div>
        </form>
    </AuthenticatedLayout>
</template>
