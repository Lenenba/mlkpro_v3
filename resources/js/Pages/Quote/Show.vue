<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
const props = defineProps({
    quote: Object,
});

</script>

<template>

    <Head title="View quote" />

    <AuthenticatedLayout>
        <div class="grid grid-cols-5 gap-4">
            <div class="col-span-1"></div> <!-- Colonne vide -->
            <div class="col-span-3">
                <div
                    class="p-5 space-y-3 flex flex-col bg-gray-100 border border-gray-100 rounded-sm shadow-sm xl:shadow-none dark:bg-green-800 dark:border-green-700">
                    <!-- Header -->
                    <div class="flex justify-between items-center mb-4">
                        <h1 class="text-xl inline-block font-semibold text-gray-800 dark:text-green-100">
                            Quote For {{ quote.customer.company_name }}
                        </h1>
                    </div>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div class="col-span-2 space-x-2">
                            <div class="bg-white rounded-sm p-4 mb-4">
                                {{ quote.job_title }}
                            </div>
                            <div class="flex flex-row space-x-6">
                                <div class="lg:col-span-3">
                                    <p>
                                        Property address
                                    </p>
                                    <div class="text-xs text-gray-600">
                                        {{ quote.customer.properties[0].country }}
                                    </div>
                                    <div class="text-xs text-gray-600">
                                        {{ quote.customer.properties[0].street1 }}
                                    </div>
                                    <div class="text-xs text-gray-600">
                                        {{ quote.customer.properties[0].state }} - {{ quote.customer.properties[0].zip
                                        }}
                                    </div>
                                </div>
                                <div class="lg:col-span-3">
                                    <p>
                                        Contact details
                                    </p>
                                    <div class="text-xs text-gray-600">
                                        {{ quote.customer.first_name }} {{ quote.customer.last_name }}
                                    </div>
                                    <div class="text-xs text-gray-600">
                                        {{ quote.customer.email }}
                                    </div>
                                    <div class="text-xs text-gray-600">
                                        {{ quote.customer.phone }}
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
                                    <span>{{ quote?.number }} </span>
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
                        <div class="min-w-full inline-block align-middle">
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
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-gray-200 dark:divide-neutral-700">
                                    <tr v-for="product in quote.products" :key="product.id">
                                        <td class="size-px whitespace-nowrap px-4 py-3">
                                            {{ product.name }}
                                        </td>
                                        <td class="size-px whitespace-nowrap px-4 py-3">
                                            {{ product.pivot.quantity }}
                                        </td>
                                        <td class="size-px whitespace-nowrap px-4 py-3">
                                            {{ product.pivot.price }}
                                        </td>
                                        <td class="size-px whitespace-nowrap px-4 py-3">
                                            {{ product.pivot.total }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <!-- End Table -->
                        </div>
                    </div>
                </div>
                <div
                    class="p-5 grid grid-cols-2 gap-4 justify-between bg-white border border-gray-100 rounded-sm shadow-sm xl:shadow-none dark:bg-green-800 dark:border-green-700">

                    <div>
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
                                        $ {{ quote.subtotal }}
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

                        <!-- Section des détails des taxes (affichée ou masquée) -->
                        <div v-if="quote.subtotal < quote.total"
                            class="space-y-2 py-4 border-t border-gray-200 dark:border-neutral-700">
                            <div class="flex justify-between">
                                <p class="text-sm text-gray-500 dark:text-neutral-500">TPS (5%) :</p>
                                <p class="text-sm text-gray-800 dark:text-neutral-200">${{ (quote.subtotal *
                                    0.05).toFixed(2) }}</p>
                            </div>
                            <div class="flex justify-between">
                                <p class="text-sm text-gray-500 dark:text-neutral-500">TVQ (9.975%) :</p>
                                <p class="text-sm text-gray-800 dark:text-neutral-200">${{ (quote.subtotal *
                                    0.09975).toFixed(2) }}</p>
                            </div>
                            <div class="flex justify-between font-bold">
                                <p class="text-sm text-gray-800 dark:text-neutral-200">Total taxes :</p>
                                <p class="text-sm text-gray-800 dark:text-neutral-200">${{ ((quote.subtotal * 0.05) +
                                    (quote.subtotal * 0.09975)).toFixed(2)
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
                                    $ {{ quote.total }}
                                </p>
                            </div>
                        </div>


                        <!-- End List Item -->

                        <!-- List Item -->
                        <div v-if="quote.initial_deposit > 0"
                            class="py-4 grid grid-cols-2 items-center gap-x-4 border-t border-gray-600 dark:border-neutral-700">
                            <!-- Label -->
                            <div class="col-span-1">
                                <p class="text-sm text-gray-500 dark:text-neutral-500">Required deposit:</p>
                            </div>

                            <!-- Contenu dynamique -->
                            <div class="flex justify-end">
                                <!-- Si le champ est affiché -->
                                <div class="flex items-center gap-x-2">
                                    <span class="text-xs text-gray-500 dark:text-neutral-500">
                                        (Min: ${{ quote.initial_deposit }})
                                    </span>
                                </div>
                            </div>
                        </div>
                        <!-- End List Item -->
                    </div>
                </div>
            </div>
            <div class="col-span-1">
            </div> <!-- Colonne vide -->
        </div>
    </AuthenticatedLayout>
</template>
