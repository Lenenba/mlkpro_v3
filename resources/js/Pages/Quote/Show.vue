<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import StarRating from '@/Components/UI/StarRating.vue';
import SalesActivityPanel from '@/Components/CRM/SalesActivityPanel.vue';
import { humanizeDate } from '@/utils/date';
import { useI18n } from 'vue-i18n';
const props = defineProps({
    quote: Object,
    activity: {
        type: Array,
        default: () => [],
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

const page = usePage();
const { t } = useI18n();
const formatDate = (value) => humanizeDate(value);
const formatAbsoluteDate = (value) => {
    if (!value) {
        return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    return date.toLocaleString();
};
const companyName = computed(() => page.props.auth?.account?.company?.name || t('quotes.company_fallback'));
const companyLogo = computed(() => page.props.auth?.account?.company?.logo_url || null);
const customerLabel = computed(() => {
    const customer = props.quote?.customer;
    const label = customer?.company_name
        || `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim();
    return label || t('quotes.labels.customer_fallback');
});

const fallbackProperty = computed(() => {
    const properties = props.quote.customer?.properties || [];
    return properties.find((item) => item.is_default) || properties[0] || null;
});

const property = computed(() => props.quote.property || fallbackProperty.value);
const taxTotal = computed(() => (props.quote.taxes || []).reduce((sum, tax) => sum + Number(tax.amount || 0), 0));
const quoteStatusLabel = computed(() => {
    const status = props.quote?.status;
    if (!status) {
        return t('quotes.show.summary.none');
    }

    const key = `quotes.status.${status}`;
    const translated = t(key);

    return translated === key ? status : translated;
});

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
const quoteNumber = computed(() => props.quote?.number || t('quotes.labels.quote_fallback'));
const formatCurrency = (value) => Number(value || 0).toFixed(2);

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

    <Head :title="$t('quotes.show_title')" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-5 rise-stagger">
                <div
                    class="flex flex-col space-y-5 rounded-sm border border-stone-200 bg-white p-6 shadow-sm xl:shadow-none dark:border-neutral-700 dark:bg-neutral-900">
                    <!-- Header -->
                    <div class="flex flex-col gap-3 border-b border-stone-200 pb-4 sm:flex-row sm:items-start sm:justify-between dark:border-neutral-700">
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
                        <div class="flex items-center gap-2">
                            <Link
                                :href="route('pipeline.timeline', { entityType: 'quote', entityId: quote.id })"
                                class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700"
                            >
                                {{ $t('quotes.show.timeline') }}
                            </Link>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(18rem,0.95fr)]">
                        <div class="space-y-4">
                            <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 text-base font-semibold text-stone-800 dark:border-neutral-700 dark:bg-neutral-800/50 dark:text-neutral-100">
                                {{ quote.job_title }}
                            </div>
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/50">
                                        <p class="text-sm font-medium text-stone-700 dark:text-neutral-200">
                                            {{ $t('quotes.form.property_address') }}
                                        </p>
                                        <div v-if="property" class="mt-3 space-y-1.5 text-sm text-stone-600 dark:text-neutral-400">
                                            <div>{{ property.country }}</div>
                                            <div>{{ property.street1 }}</div>
                                            <div>{{ property.state }} - {{ property.zip }}</div>
                                        </div>
                                        <div v-else class="mt-3 text-sm text-stone-600 dark:text-neutral-400">
                                            {{ $t('quotes.form.no_property_selected') }}
                                        </div>
                                    </div>
                                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/50">
                                        <p class="text-sm font-medium text-stone-700 dark:text-neutral-200">
                                            {{ $t('quotes.form.contact_details') }}
                                        </p>
                                        <div class="mt-3 space-y-1.5 text-sm text-stone-600 dark:text-neutral-400">
                                            <div>{{ quote.customer.first_name }} {{ quote.customer.last_name }}</div>
                                            <div>{{ quote.customer.email }}</div>
                                            <div>{{ quote.customer.phone }}</div>
                                        </div>
                                    </div>
                            </div>
                        </div>
                        <div class="flex h-full flex-col rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('quotes.form.quote_details') }}
                                </p>
                                <div class="flex items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                    <span>{{ $t('quotes.form.quote_label') }}:</span>
                                    <span class="font-semibold text-stone-700 dark:text-neutral-200">
                                        {{ quoteNumber }}
                                    </span>
                                </div>
                            </div>
                            <div class="mt-4 flex items-center justify-between gap-3 rounded-sm border border-dashed border-stone-200 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:text-neutral-400">
                                <span>{{ $t('quotes.show.summary.status') }}:</span>
                                <span class="font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ quoteStatusLabel }}
                                </span>
                            </div>
                            <div class="mt-3 flex items-center justify-between gap-3 rounded-sm border border-dashed border-stone-200 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:text-neutral-400">
                                    <span>{{ $t('quotes.form.rate_opportunity') }}:</span>
                                    <span class="flex items-center gap-2">
                                        <StarRating :value="ratingValue" show-value :empty-label="$t('quotes.show.no_rating_yet')" />
                                        <span v-if="ratingCount" class="text-xs text-stone-500 dark:text-neutral-400">
                                            ({{ ratingCount }})
                                        </span>
                                    </span>
                            </div>
                            <div class="mt-auto pt-4">
                                    <button type="button" disabled
                                        class="inline-flex items-center gap-x-2 rounded-sm border border-green-200 bg-white px-3 py-2 text-sm font-medium text-green-800 shadow-sm hover:bg-green-50 focus:bg-green-50 focus:outline-none disabled:pointer-events-none disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-green-300 dark:hover:bg-green-700 dark:focus:bg-green-700">
                                        {{ $t('quotes.form.add_custom_fields') }}
                                    </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr),320px]">
                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('quotes.show.summary.title') }}
                        </h2>
                        <div class="mt-3 grid grid-cols-1 gap-3 text-sm text-stone-600 dark:text-neutral-300 sm:grid-cols-2">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-400 dark:text-neutral-500">
                                    {{ $t('quotes.show.summary.status') }}
                                </div>
                                <div class="mt-1 text-stone-800 dark:text-neutral-200">
                                    {{ quoteStatusLabel }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-400 dark:text-neutral-500">
                                    {{ $t('quotes.show.summary.next_follow_up') }}
                                </div>
                                <div
                                    class="mt-1 text-stone-800 dark:text-neutral-200"
                                    :title="formatAbsoluteDate(quote.next_follow_up_at)"
                                >
                                    {{ quote.next_follow_up_at ? formatDate(quote.next_follow_up_at) : $t('quotes.show.summary.none') }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-400 dark:text-neutral-500">
                                    {{ $t('quotes.show.summary.last_followed_up') }}
                                </div>
                                <div
                                    class="mt-1 text-stone-800 dark:text-neutral-200"
                                    :title="formatAbsoluteDate(quote.last_followed_up_at)"
                                >
                                    {{ quote.last_followed_up_at ? formatDate(quote.last_followed_up_at) : $t('quotes.show.summary.none') }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-400 dark:text-neutral-500">
                                    {{ $t('quotes.show.summary.follow_up_count') }}
                                </div>
                                <div class="mt-1 text-stone-800 dark:text-neutral-200">
                                    {{ quote.follow_up_count ?? 0 }}
                                </div>
                            </div>
                        </div>
                    </section>

                    <SalesActivityPanel
                        :items="activity"
                        :can-log="canLogSalesActivity"
                        :quick-actions="salesActivityQuickActions"
                        :manual-actions="salesActivityManualActions"
                        :store-route="route('crm.sales-activities.quotes.store', quote.id)"
                        i18n-prefix="quotes.show.sales_activity"
                        dialog-id="quote-sales-activity-modal"
                    />
                </div>
                <div
                    class="p-5 space-y-3 flex flex-col bg-white border border-stone-200 rounded-sm shadow-sm xl:shadow-none dark:bg-neutral-900 dark:border-neutral-700">
                    <!-- Table Section -->
                    <div
                        class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                        <div class="inline-block min-h-[200px] min-w-[920px] w-full align-middle lg:min-w-full">
                            <!-- Table -->
                            <table class="min-w-full table-fixed divide-y divide-stone-200 dark:divide-neutral-700">
                                <colgroup>
                                    <col class="w-[52%]" />
                                    <col class="w-[16%]" />
                                    <col class="w-[16%]" />
                                    <col class="w-[16%]" />
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th scope="col">
                                            <div
                                                class="px-4 py-3.5 text-start flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
                                                {{ $t('quotes.show.table.product_services') }}
                                            </div>
                                        </th>

                                        <th scope="col">
                                            <div
                                                class="px-4 py-3.5 text-start flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
                                                {{ $t('quotes.show.table.qty') }}
                                            </div>
                                        </th>

                                        <th scope="col">
                                            <div
                                                class="px-4 py-3.5 text-start flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
                                                {{ $t('quotes.show.table.unit_cost') }}
                                            </div>
                                        </th>

                                        <th scope="col">
                                            <div
                                                class="px-4 py-3.5 text-start flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
                                                {{ $t('quotes.show.table.total') }}
                                            </div>
                                        </th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                    <tr v-for="product in quote.products" :key="product.id" class="align-top">
                                        <td class="px-4 py-4 align-top">
                                            <div class="space-y-1">
                                                <div class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                                                    {{ product.name }}
                                                </div>
                                                <div v-if="product.pivot?.description" class="text-xs text-stone-500 dark:text-neutral-400">
                                                    {{ product.pivot.description }}
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 text-sm text-stone-700 dark:text-neutral-300">
                                            {{ product.pivot.quantity }}
                                        </td>
                                        <td class="px-4 py-4 text-sm text-stone-700 dark:text-neutral-300">
                                            ${{ formatCurrency(product.pivot.price) }}
                                        </td>
                                        <td class="px-4 py-4 text-sm text-stone-700 dark:text-neutral-300">
                                            ${{ formatCurrency(product.pivot.total) }}
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
                                {{ $t('quotes.show.price_sources_title') }}
                            </h2>
                            <p class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('quotes.show.price_sources_subtitle') }}
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
                                        {{ $t('quotes.show.variant_label', { value: line.details.strategy }) }}
                                    </span>
                                    <span v-if="line.details.selection_basis"
                                        class="rounded-full border border-stone-200 px-2 py-1 dark:border-neutral-700">
                                        {{ $t('quotes.show.basis_label', { value: line.details.selection_basis }) }}
                                    </span>
                                </div>
                            </div>

                            <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
                                <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                    <p class="text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ $t('quotes.show.selected_supplier') }}</p>
                                    <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ line.details.selected_source?.name || $t('quotes.show.not_available') }}
                                    </div>
                                    <div
                                        v-if="line.details.selected_source?.price !== undefined && line.details.selected_source?.price !== null"
                                        class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('quotes.show.supplier_unit_cost', { price: line.details.selected_source.price }) }}
                                    </div>
                                    <div v-if="line.details.selected_source?.title" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('quotes.show.product_label', { name: line.details.selected_source.title }) }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('quotes.show.quote_unit_price', { price: line.product.pivot.price }) }}
                                    </div>
                                    <div v-if="line.details.best_source" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('quotes.show.best_price', { name: line.details.best_source.name, price: line.details.best_source.price }) }}
                                    </div>
                                    <div v-if="line.details.preferred_source" class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('quotes.show.preferred_supplier', { name: line.details.preferred_source.name, price: line.details.preferred_source.price }) }}
                                    </div>
                                    <div v-if="line.details.source_status === 'missing'" class="mt-2 text-xs text-amber-600 dark:text-amber-300">
                                        {{ $t('quotes.show.source_status_missing') }}
                                    </div>
                                    <div v-if="line.details.source_status === 'skipped'" class="mt-2 text-xs text-amber-600 dark:text-amber-300">
                                        {{ $t('quotes.show.source_status_skipped') }}
                                    </div>
                                    <a v-if="line.details.selected_source?.url"
                                        :href="line.details.selected_source.url"
                                        target="_blank"
                                        rel="noopener"
                                        class="mt-2 inline-flex text-xs text-green-700 hover:underline dark:text-green-400">
                                        {{ $t('quotes.show.open_source_link') }}
                                    </a>
                                </div>

                                <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                    <p class="text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ $t('quotes.show.selection_reason') }}</p>
                                    <p class="mt-1 text-xs text-stone-600 dark:text-neutral-300">
                                        {{ line.details.selection_reason || $t('quotes.show.not_available') }}
                                    </p>
                                    <div v-if="line.details.source_query" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('quotes.show.query_label', { query: line.details.source_query }) }}
                                    </div>
                                    <div v-if="line.details.benchmarks" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('quotes.show.benchmarks_label', { min: line.details.benchmarks.min, median: line.details.benchmarks.median, max: line.details.benchmarks.max }) }}
                                        <span v-if="line.details.benchmarks.preferred_min !== null && line.details.benchmarks.preferred_min !== undefined">
                                            , {{ $t('quotes.show.benchmarks_preferred', { value: line.details.benchmarks.preferred_min }) }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div v-if="line.details.sources?.length"
                                class="mt-3 rounded-sm border border-stone-200 bg-white p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                <p class="text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ $t('quotes.show.all_supplier_prices') }}</p>
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
                                                {{ $t('quotes.show.source_link') }}
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
                                    {{ $t('quotes.form.subtotal') }}:
                                </p>
                            </div>
                            <div class="col-span-1 flex justify-end">
                                <p>
                                    <a class="text-sm text-green-600 decoration-2 hover:underline font-medium focus:outline-none focus:underline dark:text-green-400 dark:hover:text-green-500"
                                        href="#">
                                        $ {{ formatCurrency(quote.subtotal) }}
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

                        <!-- Section des details des taxes -->
                        <div v-if="quote.taxes && quote.taxes.length"
                            class="space-y-2 py-4 border-t border-stone-200 dark:border-neutral-700">
                            <div v-for="tax in quote.taxes" :key="tax.id" class="flex justify-between">
                                <p class="text-sm text-stone-500 dark:text-neutral-500">
                                    {{ tax.tax?.name || $t('quotes.show.tax_fallback') }} ({{ Number(tax.rate || 0).toFixed(2) }}%) :
                                </p>
                                <p class="text-sm text-stone-800 dark:text-neutral-200">
                                    ${{ formatCurrency(tax.amount) }}
                                </p>
                            </div>
                            <div class="flex justify-between font-bold">
                                <p class="text-sm text-stone-800 dark:text-neutral-200">{{ $t('quotes.form.total_taxes') }}:</p>
                                <p class="text-sm text-stone-800 dark:text-neutral-200">${{ formatCurrency(taxTotal) }}</p>
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
                                    $ {{ formatCurrency(quote.total) }}
                                </p>
                            </div>
                        </div>


                        <!-- End List Item -->

                        <!-- List Item -->
                        <div v-if="quote.initial_deposit > 0"
                            class="py-4 grid grid-cols-2 items-center gap-x-4 border-t border-stone-600 dark:border-neutral-700">
                            <!-- Label -->
                            <div class="col-span-1">
                                <p class="text-sm text-stone-500 dark:text-neutral-500">{{ $t('quotes.form.required_deposit') }}:</p>
                            </div>

                            <!-- Contenu dynamique -->
                            <div class="flex justify-end">
                                <!-- Si le champ est affiché -->
                                <div class="flex items-center gap-x-2">
                                    <span class="text-xs text-stone-500 dark:text-neutral-500">
                                        ({{ $t('quotes.form.minimum_label', { amount: formatCurrency(quote.initial_deposit) }) }})
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
