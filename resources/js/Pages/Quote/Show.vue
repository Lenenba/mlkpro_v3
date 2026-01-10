<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import StarRating from '@/Components/UI/StarRating.vue';
const props = defineProps({
    quote: Object,
});

const page = usePage();
const companyName = computed(() => page.props.auth?.account?.company?.name || 'Entreprise');
const companyLogo = computed(() => page.props.auth?.account?.company?.logo_url || null);

const fallbackProperty = computed(() => {
    const properties = props.quote.customer?.properties || [];
    return properties.find((item) => item.is_default) || properties[0] || null;
});

const property = computed(() => props.quote.property || fallbackProperty.value);
const taxTotal = computed(() => (props.quote.taxes || []).reduce((sum, tax) => sum + Number(tax.amount || 0), 0));

const ratingValue = computed(() => {
    const average = props.quote?.ratings_avg_rating;
    if (average !== null && average !== undefined && average !== '') {
        return Number(average);
    }
    const ratings = props.quote?.ratings || [];
    if (!ratings.length) {
        return null;
    }
    const sum = ratings.reduce((total, rating) => total + Number(rating.rating || 0), 0);
    return sum / ratings.length;
});

const ratingCount = computed(() => {
    if (props.quote?.ratings_count !== undefined && props.quote?.ratings_count !== null) {
        return Number(props.quote.ratings_count);
    }
    return props.quote?.ratings?.length || 0;
});

const sourceLines = computed(() => {
    const products = props.quote?.products || [];
    return products
        .map((product) => {
            const rawDetails = product?.pivot?.source_details ?? null;
            let details = rawDetails;
            if (typeof rawDetails === 'string') {
                try {
                    details = JSON.parse(rawDetails);
                } catch (error) {
                    details = null;
                }
            }
            return { product, details };
        })
        .filter((line) => {
            if (!line.details) {
                return false;
            }
            return (
                (line.details.sources && line.details.sources.length > 0) ||
                line.details.selected_source ||
                line.details.selection_reason ||
                line.details.benchmarks ||
                line.details.best_source ||
                line.details.source_query ||
                line.details.source_status
            );
        });
});

</script>

<template>

    <Head title="View quote" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-5 rise-stagger">
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
                                    Quote For {{ quote.customer.company_name }}
                                </h1>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div class="col-span-2 space-x-2">
                            <div class="bg-white rounded-sm border border-stone-200 p-4 mb-4 dark:bg-neutral-900 dark:border-neutral-700">
                                {{ quote.job_title }}
                            </div>
                                <div class="flex flex-row space-x-6">
                                    <div class="lg:col-span-3">
                                        <p>
                                            Property address
                                        </p>
                                        <div v-if="property" class="space-y-1">
                                            <div class="text-xs text-stone-600 dark:text-neutral-400">
                                                {{ property.country }}
                                            </div>
                                            <div class="text-xs text-stone-600 dark:text-neutral-400">
                                                {{ property.street1 }}
                                            </div>
                                            <div class="text-xs text-stone-600 dark:text-neutral-400">
                                                {{ property.state }} - {{ property.zip }}
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
                                            {{ quote.customer.first_name }} {{ quote.customer.last_name }}
                                        </div>
                                        <div class="text-xs text-stone-600 dark:text-neutral-400">
                                            {{ quote.customer.email }}
                                        </div>
                                        <div class="text-xs text-stone-600 dark:text-neutral-400">
                                            {{ quote.customer.phone }}
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
                                    <span>{{ quote?.number }} </span>
                                </div>
                                <div class="text-xs text-stone-600 dark:text-neutral-400 flex justify-between">
                                    <span> Rate opportunity :</span>
                                    <span class="flex items-center gap-2">
                                        <StarRating :value="ratingValue" show-value empty-label="No rating yet" />
                                        <span v-if="ratingCount" class="text-xs text-stone-500 dark:text-neutral-400">
                                            ({{ ratingCount }})
                                        </span>
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
                    <!-- Table Section -->
                    <div
                        class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                        <div class="min-w-full inline-block align-middle">
                            <!-- Table -->
                            <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                                <thead>
                                    <tr>
                                        <th scope="col" class="min-w-[450px] ">
                                            <div
                                                class="pe-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
                                                Product/Services
                                            </div>
                                        </th>

                                        <th scope="col">
                                            <div
                                                class="px-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
                                                Qty.
                                            </div>
                                        </th>

                                        <th scope="col">
                                            <div
                                                class="px-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
                                                Unit cost
                                            </div>
                                        </th>

                                        <th scope="col">
                                            <div
                                                class="px-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
                                                Total
                                            </div>
                                        </th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
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
                    v-if="sourceLines.length"
                    class="p-5 space-y-4 flex flex-col bg-white border border-stone-200 rounded-sm shadow-sm xl:shadow-none dark:bg-neutral-900 dark:border-neutral-700">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                                Price sources & justification
                            </h2>
                            <p class="text-sm text-stone-500 dark:text-neutral-400">
                                Supplier benchmarks, selected source, and why each price was chosen.
                            </p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div v-for="line in sourceLines" :key="line.product.id"
                            class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ line.product.name }}
                                    </p>
                                    <p v-if="line.product.pivot?.description"
                                        class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ line.product.pivot.description }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                    <span v-if="line.details.strategy"
                                        class="rounded-full border border-stone-200 px-2 py-1 dark:border-neutral-700">
                                        Variant {{ line.details.strategy }}
                                    </span>
                                    <span v-if="line.details.selection_basis"
                                        class="rounded-full border border-stone-200 px-2 py-1 dark:border-neutral-700">
                                        Basis {{ line.details.selection_basis }}
                                    </span>
                                </div>
                            </div>

                            <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
                                <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                    <p class="text-xs font-semibold text-stone-700 dark:text-neutral-200">Selected supplier</p>
                                    <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ line.details.selected_source?.name || 'Not available' }}
                                    </div>
                                    <div
                                        v-if="line.details.selected_source?.price !== undefined && line.details.selected_source?.price !== null"
                                        class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        Supplier unit cost: ${{ line.details.selected_source.price }}
                                    </div>
                                    <div v-if="line.details.selected_source?.title" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        Product: {{ line.details.selected_source.title }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        Quote unit price: ${{ line.product.pivot.price }}
                                    </div>
                                    <div v-if="line.details.best_source" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                        Best price: {{ line.details.best_source.name }} ${{ line.details.best_source.price }}
                                    </div>
                                    <div v-if="line.details.preferred_source" class="text-xs text-stone-500 dark:text-neutral-400">
                                        Preferred supplier: {{ line.details.preferred_source.name }} ${{ line.details.preferred_source.price }}
                                    </div>
                                    <div v-if="line.details.source_status === 'missing'" class="mt-2 text-xs text-amber-600 dark:text-amber-300">
                                        No live price found for this line.
                                    </div>
                                    <div v-if="line.details.source_status === 'skipped'" class="mt-2 text-xs text-amber-600 dark:text-amber-300">
                                        Live pricing skipped to keep scan fast.
                                    </div>
                                    <a v-if="line.details.selected_source?.url"
                                        :href="line.details.selected_source.url"
                                        target="_blank"
                                        rel="noopener"
                                        class="mt-2 inline-flex text-xs text-green-700 hover:underline dark:text-green-400">
                                        Open source link
                                    </a>
                                </div>

                                <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                    <p class="text-xs font-semibold text-stone-700 dark:text-neutral-200">Selection reason</p>
                                    <p class="mt-1 text-xs text-stone-600 dark:text-neutral-300">
                                        {{ line.details.selection_reason || 'Not available' }}
                                    </p>
                                    <div v-if="line.details.source_query" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                        Query: {{ line.details.source_query }}
                                    </div>
                                    <div v-if="line.details.benchmarks" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                        Benchmarks: min ${{ line.details.benchmarks.min }}, median ${{ line.details.benchmarks.median }}, max ${{ line.details.benchmarks.max }}
                                        <span v-if="line.details.benchmarks.preferred_min !== null && line.details.benchmarks.preferred_min !== undefined">
                                            , preferred min ${{ line.details.benchmarks.preferred_min }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div v-if="line.details.sources?.length"
                                class="mt-3 rounded-sm border border-stone-200 bg-white p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                <p class="text-xs font-semibold text-stone-700 dark:text-neutral-200">All supplier prices</p>
                                <div class="mt-2 space-y-2">
                                    <div v-for="source in line.details.sources" :key="source.name"
                                        class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="text-xs text-stone-700 dark:text-neutral-200">
                                            <div>{{ source.name }}</div>
                                            <div v-if="source.title" class="text-[10px] text-stone-400 dark:text-neutral-500">
                                                {{ source.title }}
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-3 text-xs text-stone-500 dark:text-neutral-400">
                                            <span>${{ source.price }}</span>
                                            <a v-if="source.url"
                                                :href="source.url"
                                                target="_blank"
                                                rel="noopener"
                                                class="text-green-700 hover:underline dark:text-green-400">
                                                Source link
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div
                    class="p-5 grid grid-cols-2 gap-4 justify-between bg-white border border-stone-200 rounded-sm shadow-sm xl:shadow-none dark:bg-neutral-900 dark:border-neutral-700">

                    <div>
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
                                        $ {{ quote.subtotal }}
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

                        <!-- Section des details des taxes -->
                        <div v-if="quote.taxes && quote.taxes.length"
                            class="space-y-2 py-4 border-t border-stone-200 dark:border-neutral-700">
                            <div v-for="tax in quote.taxes" :key="tax.id" class="flex justify-between">
                                <p class="text-sm text-stone-500 dark:text-neutral-500">
                                    {{ tax.tax?.name || 'Tax' }} ({{ Number(tax.rate || 0).toFixed(2) }}%) :
                                </p>
                                <p class="text-sm text-stone-800 dark:text-neutral-200">
                                    ${{ Number(tax.amount || 0).toFixed(2) }}
                                </p>
                            </div>
                            <div class="flex justify-between font-bold">
                                <p class="text-sm text-stone-800 dark:text-neutral-200">Total taxes :</p>
                                <p class="text-sm text-stone-800 dark:text-neutral-200">${{ taxTotal.toFixed(2) }}</p>
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
                                    $ {{ quote.total }}
                                </p>
                            </div>
                        </div>


                        <!-- End List Item -->

                        <!-- List Item -->
                        <div v-if="quote.initial_deposit > 0"
                            class="py-4 grid grid-cols-2 items-center gap-x-4 border-t border-stone-600 dark:border-neutral-700">
                            <!-- Label -->
                            <div class="col-span-1">
                                <p class="text-sm text-stone-500 dark:text-neutral-500">Required deposit:</p>
                            </div>

                            <!-- Contenu dynamique -->
                            <div class="flex justify-end">
                                <!-- Si le champ est affiché -->
                                <div class="flex items-center gap-x-2">
                                    <span class="text-xs text-stone-500 dark:text-neutral-500">
                                        (Min: ${{ quote.initial_deposit }})
                                    </span>
                                </div>
                            </div>
                        </div>
                        <!-- End List Item -->
                    </div>
                </div>
        </div>
    </AuthenticatedLayout>
</template>
