<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import { Link, useForm } from '@inertiajs/vue3';
import ListInput from '@/Components/ListInput.vue';

const props = defineProps({
    customer: Object,
});

const Salutation = [
    { id: '1', name: 'Mr' },
    { id: '2', name: 'Mrs' },
    { id: '3', name: 'Miss' },
];
// Initialize the form
const form = useForm({
    first_name: props.customer?.first_name || '',
    last_name: props.customer?.last_name || '',
    street1: props.customer?.street1 || '',
    street2: props.customer?.street2 || '',
    email: props.customer?.email || '',
    phone: props.customer?.phone || '',
    address: props.customer?.address || '',
    city: props.customer?.city || '',
    state: props.customer?.state || '',
    zip: props.customer?.zip || '',
    company_name: props.customer?.company_name || '',
    logo: props.customer?.logo || '',
    type: props.customer?.type || '',
    description: props.customer?.description || '',
    reference: props.customer?.reference || '',
    country: props.customer?.country || '',
    salutation: props.customer?.salutation || '',
});

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
                    <form>
                        <div class="flex flex-row">
                            <FloatingSelect label="Title" v-model="form.salutation" class="w-1/5" :options="Salutation" />
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

                        <FloatingInput v-model="form.reference" label="Referred by" />
                    </form>
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
                    <form>
                        <FloatingInput v-model="form.street1" label="Street1" />
                        <FloatingInput v-model="form.street2" label="Street2" />
                        <div class="flex flex-row">
                            <FloatingInput v-model="form.city" label="City" class="w-full" />
                            <FloatingInput v-model="form.state" label="State" class="w-full" />
                        </div>
                        <div class="flex flex-row">
                            <FloatingInput v-model="form.zip" label="Zip code" class="w-full" />
                            <FloatingInput v-model="form.country" label="Country" class="w-full" />
                        </div>

                        <!-- Input Group -->
                        <div class="flex flex-col sm:flex-row sm:items-center gap-2 mt-4">
                            <div class="flex items-center">
                                <input type="checkbox"
                                    class="shrink-0 size-3.5 border-gray-300 rounded text-green-600 focus:ring-green-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-gray-800"
                                    id="hs-pro-danscch">
                                <label for="hs-pro-danscch" class="text-sm text-gray-500 ms-2 dark:text-neutral-500">
                                    Billing address is the same as the property address
                                </label>
                            </div>
                        </div>
                        <!-- End Input Group -->
                    </form>
                </div>
            </div>
            <div></div>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-1 md:gap-3 lg:gap-1 ">
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
                <button type="button"
                    class="py-1.5 ml-4 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                    Save client
                </button>
            </div>
            <div></div>

        </div>
    </AuthenticatedLayout>
</template>
