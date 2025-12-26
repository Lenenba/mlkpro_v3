<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    scan: Object,
    customers: Array,
});

const variants = computed(() => props.scan?.variants || []);
const analysis = computed(() => props.scan?.analysis || {});
const metrics = computed(() => props.scan?.metrics || {});
const needsCustomer = computed(() => !props.scan?.customer_id);
const pricing = computed(() => analysis.value?.pricing || {});

const customerOptions = computed(() =>
    (props.customers || []).map((customer) => ({
        id: customer.id,
        name: customer.company_name || `${customer.first_name} ${customer.last_name}`,
    }))
);

const selectedCustomerId = ref(props.scan?.customer_id || null);
const selectedPropertyId = ref(props.scan?.property_id || null);

const selectedCustomer = computed(() =>
    props.customers?.find((customer) => customer.id === selectedCustomerId.value) || null
);

const properties = computed(() => selectedCustomer.value?.properties || []);

watch(
    () => selectedCustomerId.value,
    () => {
        const defaultProperty = properties.value.find((property) => property.is_default);
        selectedPropertyId.value = defaultProperty?.id || properties.value[0]?.id || null;
    }
);

const convert = (variantKey) => {
    const payload = { variant: variantKey };
    if (needsCustomer.value) {
        payload.customer_id = selectedCustomerId.value;
        payload.property_id = selectedPropertyId.value || null;
    }
    router.post(route('plan-scans.convert', { planScan: props.scan.id }), payload, { preserveScroll: true });
};
</script>

<template>
    <Head title="Plan scan" />
    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-5">
            <div
                class="p-5 space-y-4 flex flex-col bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-900 dark:border-neutral-700"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">Plan scan</p>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ scan.job_title || 'Plan scan' }}
                        </h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            Trade: {{ scan.trade_type || 'general' }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold"
                            :class="scan.status === 'ready'
                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                : scan.status === 'processing'
                                    ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'
                                    : scan.status === 'failed'
                                        ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'
                                        : 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300'">
                            {{ scan.status }}
                        </span>
                        <span class="rounded-full border border-stone-200 px-3 py-1 text-xs text-stone-600 dark:border-neutral-700 dark:text-neutral-300">
                            Confidence {{ scan.confidence_score || 0 }}%
                        </span>
                        <Link :href="route('plan-scans.index')" class="text-sm text-stone-500 hover:underline dark:text-neutral-400">
                            Back to list
                        </Link>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div class="rounded-sm border border-stone-200 bg-white p-4 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        <h2 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">Plan details</h2>
                        <div class="mt-3 space-y-2">
                            <div>Surface: {{ metrics.surface_m2 || '-' }} m2</div>
                            <div>Rooms: {{ metrics.rooms || '-' }}</div>
                            <div>Priority: {{ metrics.priority || 'balanced' }}</div>
                            <div v-if="scan.plan_file_url">
                                <a :href="scan.plan_file_url" target="_blank" rel="noopener" class="text-green-700 hover:underline dark:text-green-400">
                                    View plan file
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        <h2 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">Analysis</h2>
                        <p class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                            {{ analysis.summary || 'Analysis ready.' }}
                        </p>
                        <div v-if="analysis.assumptions?.length" class="mt-3 space-y-1 text-xs text-stone-500 dark:text-neutral-400">
                            <div v-for="item in analysis.assumptions" :key="item">• {{ item }}</div>
                        </div>
                    </div>
                    <div v-if="pricing.provider" class="rounded-sm border border-stone-200 bg-white p-4 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        <h2 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">Pricing sources</h2>
                        <div class="mt-2 space-y-1">
                            <div>
                                Provider:
                                <span class="font-semibold text-stone-600 dark:text-neutral-300">{{ pricing.provider }}</span>
                                <span v-if="pricing.provider_ready === false" class="text-amber-600 dark:text-amber-300">
                                    (live sources not configured)
                                </span>
                            </div>
                            <div v-if="pricing.preferred_suppliers?.length">
                                Preferred: {{ pricing.preferred_suppliers.join(', ') }}
                            </div>
                            <div v-if="pricing.enabled_suppliers?.length">
                                Enabled: {{ pricing.enabled_suppliers.join(', ') }}
                            </div>
                            <div v-if="pricing.live_lookup_limit">
                                Live lookups: {{ pricing.lookups_attempted || 0 }}/{{ pricing.live_lookup_limit }}
                            </div>
                            <div v-if="pricing.missing_sources">
                                Missing live sources: {{ pricing.missing_sources }}
                            </div>
                            <div v-if="pricing.skipped_sources">
                                Skipped live pricing: {{ pricing.skipped_sources }}
                            </div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        <h2 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">Differentiator</h2>
                        <ul class="mt-3 space-y-2 text-sm">
                            <li>3 quote levels with margins and benchmarks.</li>
                            <li>Live Canadian suppliers with line-by-line sources.</li>
                            <li>Fast conversion to a draft quote.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div
                class="p-5 space-y-4 flex flex-col bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-900 dark:border-neutral-700"
            >
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">Quote variants</h2>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            Pick the option that matches the client expectations.
                        </p>
                    </div>
                </div>

                <div v-if="needsCustomer" class="rounded-sm border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                    Select a customer to create a quote from a variant.
                    <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div>
                            <label class="text-xs text-amber-700 dark:text-amber-200">Customer</label>
                            <select
                                v-model.number="selectedCustomerId"
                                class="mt-2 w-full rounded-sm border border-amber-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-500 focus:ring-green-500 dark:border-amber-500/40 dark:bg-neutral-900 dark:text-neutral-200"
                            >
                                <option value="">Select customer</option>
                                <option v-for="customer in customerOptions" :key="customer.id" :value="customer.id">
                                    {{ customer.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs text-amber-700 dark:text-amber-200">Property</label>
                            <select
                                v-model.number="selectedPropertyId"
                                class="mt-2 w-full rounded-sm border border-amber-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-500 focus:ring-green-500 dark:border-amber-500/40 dark:bg-neutral-900 dark:text-neutral-200"
                            >
                                <option value="">No property</option>
                                <option v-for="property in properties" :key="property.id" :value="property.id">
                                    {{ property.street1 }}{{ property.city ? `, ${property.city}` : '' }}
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <div v-if="!variants.length" class="rounded-sm border border-stone-200 bg-white p-4 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                    Variants will appear once the scan is ready.
                </div>
                <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div
                        v-for="variant in variants"
                        :key="variant.key"
                        class="rounded-sm border bg-white p-4 shadow-sm dark:bg-neutral-900"
                        :class="variant.recommended ? 'border-green-500' : 'border-stone-200 dark:border-neutral-700'"
                    >
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-base font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ variant.label }}
                                </h3>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    Margin {{ variant.margin_percent }}%
                                </p>
                            </div>
                            <span v-if="variant.recommended" class="rounded-full bg-green-100 px-2 py-1 text-xs font-semibold text-green-700 dark:bg-green-500/10 dark:text-green-300">
                                Recommended
                            </span>
                        </div>

                        <div class="mt-3 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            ${{ variant.total }}
                        </div>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            Benchmark ${{ variant.reference_total }} | Savings ${{ variant.savings_vs_reference }}
                        </p>

                        <div v-if="variant.details" class="mt-3 rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                            <p class="font-semibold text-stone-700 dark:text-neutral-200">{{ variant.details.summary }}</p>
                            <div class="mt-2 space-y-1">
                                <div>Best for: {{ variant.details.best_for }}</div>
                                <div>Lead time: {{ variant.details.lead_time_days }} days</div>
                                <div>Materials: {{ variant.details.materials }}</div>
                                <div>Support: {{ variant.details.support }}</div>
                            </div>
                        </div>
                        <ul v-else-if="variant.highlights?.length" class="mt-3 space-y-1 text-xs text-stone-500 dark:text-neutral-400">
                            <li v-for="item in variant.highlights" :key="item">• {{ item }}</li>
                        </ul>

                        <button
                            type="button"
                            class="mt-3 w-full rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                            :disabled="needsCustomer && !selectedCustomerId"
                            @click="convert(variant.key)"
                        >
                            Create quote
                        </button>

                        <div class="mt-4 space-y-3">
                            <div v-for="item in variant.items" :key="item.name" class="rounded-sm border border-stone-200 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:text-neutral-300">
                                <div class="flex items-center justify-between">
                                    <span class="font-semibold text-stone-700 dark:text-neutral-200">{{ item.name }}</span>
                                    <span>{{ item.quantity }} {{ item.unit }}</span>
                                </div>
                                <div class="mt-1 flex items-center justify-between">
                                    <span>Unit: ${{ item.unit_price }}</span>
                                    <span>Total: ${{ item.total }}</span>
                                </div>
                                <div v-if="item.selected_source" class="mt-1 text-[11px] text-stone-500 dark:text-neutral-400">
                                    Selected: {{ item.selected_source.name }} ${{ item.selected_source.price }}
                                </div>
                                <div v-if="item.selected_source?.title" class="text-[10px] text-stone-400 dark:text-neutral-500">
                                    {{ item.selected_source.title }}
                                </div>
                                <div v-if="item.source_status === 'missing'" class="mt-1 text-[11px] text-amber-600 dark:text-amber-300">
                                    No live price found for this line.
                                </div>
                                <div v-if="item.source_status === 'skipped'" class="mt-1 text-[11px] text-amber-600 dark:text-amber-300">
                                    Live pricing skipped to keep scan fast.
                                </div>
                                <div v-if="item.source_status === 'labor'" class="mt-1 text-[11px] text-stone-400 dark:text-neutral-500">
                                    Labor line: no supplier pricing required.
                                </div>
                                <div v-if="item.source_query" class="mt-1 text-[11px] text-stone-500 dark:text-neutral-400">
                                    Query: {{ item.source_query }}
                                </div>
                                <div v-if="item.best_source" class="mt-1 text-[11px] text-stone-500 dark:text-neutral-400">
                                    Best price: {{ item.best_source.name }} ${{ item.best_source.price }}
                                </div>
                                <div v-if="item.preferred_source" class="mt-1 text-[11px] text-stone-500 dark:text-neutral-400">
                                    Preferred: {{ item.preferred_source.name }} ${{ item.preferred_source.price }}
                                </div>
                                <div v-if="item.sources?.length" class="mt-2 text-[11px] text-stone-500 dark:text-neutral-400">
                                    <div class="font-semibold text-stone-600 dark:text-neutral-300">Sources:</div>
                                    <div class="mt-1 space-y-1">
                                        <div v-for="source in item.sources" :key="source.name" class="space-y-0.5">
                                            <div class="inline-flex items-center gap-1">
                                                <a
                                                    v-if="source.url"
                                                    :href="source.url"
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="text-green-700 hover:underline dark:text-green-400"
                                                >
                                                    {{ source.name }}
                                                </a>
                                                <span v-else>{{ source.name }}</span>
                                                <span>${{ source.price }}</span>
                                            </div>
                                            <div v-if="source.title" class="text-[10px] text-stone-400 dark:text-neutral-500">
                                                {{ source.title }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
