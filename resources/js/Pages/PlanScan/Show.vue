<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ValidationSummary from '@/Components/ValidationSummary.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';

const props = defineProps({
    scan: Object,
    customers: Array,
    tradeOptions: Array,
    priorityOptions: Array,
});

const variants = computed(() => props.scan?.variants || []);
const analysis = computed(() => props.scan?.analysis || {});
const metrics = computed(() => props.scan?.metrics || {});
const needsCustomer = computed(() => !props.scan?.customer_id);
const pricing = computed(() => analysis.value?.pricing || {});
const reviewedPayload = computed(() => props.scan?.ai_reviewed_payload || {});
const aiExtraction = computed(() => props.scan?.ai_extraction_normalized || {});
const aiMetrics = computed(() => aiExtraction.value?.metrics || {});
const aiConfidence = computed(() => aiExtraction.value?.confidence || {});
const aiFieldFlags = computed(() => analysis.value?.ai?.field_flags || aiExtraction.value?.field_flags || []);
const aiFlags = computed(() => reviewedPayload.value?.review_flags || aiExtraction.value?.review_flags || []);
const aiAssumptions = computed(() => reviewedPayload.value?.assumptions || aiExtraction.value?.assumptions || []);
const aiRecommendedAction = computed(() => analysis.value?.ai?.recommended_action || aiExtraction.value?.recommended_action || 'review');
const isScanProcessing = computed(() => props.scan?.status === 'processing');
const isStaleProcessing = computed(() => {
    if (!isScanProcessing.value || !props.scan?.updated_at) {
        return false;
    }

    const updatedAt = new Date(props.scan.updated_at);
    if (Number.isNaN(updatedAt.getTime())) {
        return false;
    }

    return Date.now() - updatedAt.getTime() >= 5 * 60 * 1000;
});
const aiUsage = computed(() => props.scan?.ai_usage || {});
const aiAttempts = computed(() => props.scan?.ai_attempts || []);
const aiAttemptCount = computed(() => {
    const usageCount = Number(aiUsage.value?.attempt_count ?? 0);

    return usageCount > 0 ? usageCount : aiAttempts.value.length;
});
const aiModelsTried = computed(() => {
    const fromUsage = Array.isArray(aiUsage.value?.models_tried) ? aiUsage.value.models_tried : [];
    if (fromUsage.length) {
        return fromUsage;
    }

    return [...new Set(
        aiAttempts.value
            .map((attempt) => attempt.model || attempt.requested_model)
            .filter(Boolean)
    )];
});
const aiEstimatedCostLabel = computed(() => {
    const value = props.scan?.ai_estimated_cost_usd;

    if (value === null || value === undefined || value === '') {
        return props.scan?.ai_cache_hit ? '$0.000000' : 'Not configured';
    }

    const normalized = Number(value);

    return Number.isFinite(normalized) ? `$${normalized.toFixed(6)}` : 'Not configured';
});
const aiCacheSourceLabel = computed(() => {
    const source = props.scan?.ai_cache_source;

    if (!source) {
        return null;
    }

    if (source === 'runtime_cache') {
        return 'Runtime cache';
    }

    if (source.startsWith('scan:')) {
        return `Previous scan #${source.split(':')[1]}`;
    }

    return source;
});
const analysisSummary = computed(() => {
    if (isScanProcessing.value) {
        return isStaleProcessing.value
            ? 'This scan has been processing longer than expected. You can retry or escalate the AI pass now.'
            : 'AI extraction is still running. Review details and quote variants will unlock once the scan is ready.';
    }

    if (analysis.value?.summary) {
        return analysis.value.summary;
    }

    if (props.scan?.status === 'failed') {
        return 'Analysis failed. Retry the AI pass or review the scan manually.';
    }

    return 'Analysis ready.';
});
const scanStage = computed(() => {
    if (props.scan?.status === 'failed') {
        return {
            label: 'Failed',
            classes: 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        };
    }

    if (props.scan?.status === 'processing') {
        return {
            label: 'Extracting',
            classes: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
        };
    }

    if (props.scan?.ai_review_required) {
        return {
            label: 'Review required',
            classes: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
        };
    }

    return {
        label: 'Ready for quote',
        classes: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
    };
});

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
const propertyOptions = computed(() =>
    properties.value.map((property) => ({
        id: property.id,
        name: `${property.street1 || 'Property'}${property.city ? `, ${property.city}` : ''}`,
    }))
);

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

const reanalysisForm = useForm({
    mode: 'retry',
});

const reanalyze = (mode) => {
    reanalysisForm
        .transform(() => ({
            mode,
        }))
        .post(route('plan-scans.reanalyze', { planScan: props.scan.id }), {
            preserveScroll: true,
            preserveState: true,
        });
};

const reanalysisDisabled = computed(() =>
    reanalysisForm.processing || (isScanProcessing.value && !isStaleProcessing.value)
);

const reviewForm = useForm({
    trade_type: props.scan?.trade_type || props.tradeOptions?.[0]?.id || 'general',
    surface_m2: '',
    rooms: '',
    priority: 'balanced',
    line_items: [],
});

const normalizeReviewLine = (line = {}) => ({
    name: line.name || '',
    quantity: line.quantity ?? 1,
    unit: line.unit || 'u',
    description: line.description || line.notes || '',
    base_cost: line.base_cost ?? '',
    is_labor: Boolean(line.is_labor),
    confidence: line.confidence ?? '',
    notes: line.notes || '',
});

const hydrateReviewForm = (scan) => {
    const payload = scan?.ai_reviewed_payload || {};
    const extraction = scan?.ai_extraction_normalized || {};
    const sourceLines = (payload.line_items?.length ? payload.line_items : extraction.detected_lines || []).map(normalizeReviewLine);

    reviewForm.defaults({
        trade_type: payload.trade_type || scan?.trade_type || extraction.trade_guess || props.tradeOptions?.[0]?.id || 'general',
        surface_m2: payload.metrics?.surface_m2 ?? scan?.metrics?.surface_m2 ?? extraction.metrics?.surface_m2_estimate ?? '',
        rooms: payload.metrics?.rooms ?? scan?.metrics?.rooms ?? extraction.metrics?.room_count_estimate ?? '',
        priority: payload.metrics?.priority || scan?.metrics?.priority || 'balanced',
        line_items: sourceLines,
    });
    reviewForm.reset();
    reviewForm.clearErrors();
};

watch(
    () => props.scan,
    (scan) => {
        hydrateReviewForm(scan);
    },
    { immediate: true, deep: true }
);

const addReviewLine = () => {
    reviewForm.line_items.push(normalizeReviewLine());
};

const removeReviewLine = (index) => {
    reviewForm.line_items.splice(index, 1);
};

const saveReview = () => {
    reviewForm
        .transform((data) => ({
            ...data,
            surface_m2: data.surface_m2 === '' ? null : data.surface_m2,
            rooms: data.rooms === '' ? null : data.rooms,
            line_items: data.line_items
                .filter((line) => (line.name || '').trim() !== '')
                .map((line) => ({
                    ...line,
                    base_cost: line.base_cost === '' ? null : line.base_cost,
                    confidence: line.confidence === '' ? null : line.confidence,
                })),
        }))
        .patch(route('plan-scans.review', { planScan: props.scan.id }), {
            preserveScroll: true,
        });
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
                        <span
                            class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold"
                            :class="scan.ai_status === 'completed'
                                ? 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'
                                : scan.ai_status === 'extracting' || scan.ai_status === 'queued' || scan.ai_status === 'escalating'
                                    ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'
                                    : scan.ai_status === 'failed'
                                        ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'
                                        : 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300'">
                            AI {{ scan.ai_status || 'manual' }}
                        </span>
                        <span class="rounded-full border border-stone-200 px-3 py-1 text-xs text-stone-600 dark:border-neutral-700 dark:text-neutral-300">
                            Confidence {{ scan.confidence_score || 0 }}%
                        </span>
                        <span
                            v-if="scan.ai_cache_hit"
                            class="rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200"
                        >
                            Cache hit
                        </span>
                        <span
                            v-if="scan.ai_review_required"
                            class="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                        >
                            Review recommended
                        </span>
                        <span
                            class="rounded-full px-3 py-1 text-xs font-semibold"
                            :class="scanStage.classes"
                        >
                            {{ scanStage.label }}
                        </span>
                        <Link :href="route('plan-scans.index')" class="text-sm text-stone-500 hover:underline dark:text-neutral-400">
                            Back to list
                        </Link>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        :disabled="reanalysisDisabled"
                        class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-semibold text-stone-600 hover:border-stone-300 hover:text-stone-800 disabled:opacity-60 dark:border-neutral-700 dark:text-neutral-300 dark:hover:text-neutral-100"
                        @click="reanalyze('retry')"
                    >
                        Retry AI
                    </button>
                    <button
                        type="button"
                        :disabled="reanalysisDisabled"
                        class="rounded-sm border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-700 hover:bg-sky-100 disabled:opacity-60 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200"
                        @click="reanalyze('escalate')"
                    >
                        Escalate AI
                    </button>
                    <span v-if="scan.ai_retry_count" class="text-xs text-stone-500 dark:text-neutral-400">
                        Retries: {{ scan.ai_retry_count }}
                    </span>
                    <span v-if="isStaleProcessing" class="text-xs font-medium text-rose-600 dark:text-rose-300">
                        Processing looks stuck. Retry is available.
                    </span>
                </div>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-4">
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
                        <h2 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">AI extraction</h2>
                        <div class="mt-3 space-y-2">
                            <div>Trade guess: {{ aiExtraction.trade_guess || '-' }}</div>
                            <div>AI surface: {{ aiMetrics.surface_m2_estimate || '-' }} m2</div>
                            <div>AI rooms: {{ aiMetrics.room_count_estimate || '-' }}</div>
                            <div>AI overall confidence: {{ aiConfidence.overall || 0 }}%</div>
                            <div>Recommended action: {{ aiRecommendedAction }}</div>
                            <div v-if="scan.ai_model" class="text-xs text-stone-500 dark:text-neutral-400">
                                Model: {{ scan.ai_model }}
                            </div>
                            <div v-if="scan.ai_last_requested_at" class="text-xs text-stone-500 dark:text-neutral-400">
                                Last run: {{ scan.ai_last_requested_at }}
                            </div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        <h2 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">Analysis</h2>
                        <p class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                            {{ analysisSummary }}
                        </p>
                        <div v-if="analysis.assumptions?.length" class="mt-3 space-y-1 text-xs text-stone-500 dark:text-neutral-400">
                            <div v-for="item in analysis.assumptions" :key="item">• {{ item }}</div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        <h2 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">Review flags</h2>
                        <div v-if="aiFlags.length || aiAssumptions.length || scan.ai_error_message" class="mt-2 space-y-2">
                            <div v-if="scan.ai_error_message" class="rounded-sm border border-amber-200 bg-amber-50 p-2 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                                {{ scan.ai_error_message }}
                            </div>
                            <div v-if="aiFlags.length" class="space-y-1">
                                <div class="font-semibold text-stone-700 dark:text-neutral-200">Flags</div>
                                <div v-for="item in aiFlags" :key="item">• {{ item }}</div>
                            </div>
                            <div v-if="aiAssumptions.length" class="space-y-1">
                                <div class="font-semibold text-stone-700 dark:text-neutral-200">AI assumptions</div>
                                <div v-for="item in aiAssumptions" :key="item">• {{ item }}</div>
                            </div>
                        </div>
                        <p v-else class="mt-2 text-stone-500 dark:text-neutral-400">
                            <template v-if="isScanProcessing">
                                Review flags will appear once extraction finishes.
                            </template>
                            <template v-else>
                            No review flags detected.
                            </template>
                        </p>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 lg:col-span-2">
                        <h2 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">Confidence by field</h2>
                        <div v-if="aiFieldFlags.length" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div
                                v-for="flag in aiFieldFlags"
                                :key="flag.field"
                                class="rounded-sm border p-3"
                                :class="flag.status === 'ok'
                                    ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-500/20 dark:bg-emerald-500/10'
                                    : flag.status === 'review'
                                        ? 'border-amber-200 bg-amber-50 dark:border-amber-500/20 dark:bg-amber-500/10'
                                        : 'border-rose-200 bg-rose-50 dark:border-rose-500/20 dark:bg-rose-500/10'"
                            >
                                <div class="flex items-center justify-between gap-3">
                                    <div class="font-semibold text-stone-700 dark:text-neutral-200">{{ flag.label }}</div>
                                    <span class="rounded-full px-2 py-1 text-[10px] font-semibold uppercase"
                                        :class="flag.status === 'ok'
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200'
                                            : flag.status === 'review'
                                                ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-200'
                                                : 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-200'">
                                        {{ flag.status }}
                                    </span>
                                </div>
                                <div class="mt-2 text-sm text-stone-800 dark:text-neutral-100">{{ flag.value }}</div>
                                <div class="mt-1 text-[11px] text-stone-500 dark:text-neutral-400">
                                    Confidence {{ flag.confidence }}%
                                </div>
                                <div class="mt-2 text-[11px] text-stone-600 dark:text-neutral-300">
                                    {{ flag.message }}
                                </div>
                            </div>
                        </div>
                        <p v-else class="mt-2 text-stone-500 dark:text-neutral-400">
                            <template v-if="isScanProcessing">
                                Confidence markers will appear after the current AI run.
                            </template>
                            <template v-else>
                            No per-field confidence markers yet.
                            </template>
                        </p>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 lg:col-span-2">
                        <h2 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">AI operations</h2>
                        <div class="mt-2 grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-950">
                                <div class="text-[11px] uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">Attempts</div>
                                <div class="mt-2 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ aiAttemptCount }}</div>
                                <div class="mt-1 text-[11px] text-stone-500 dark:text-neutral-400">
                                    {{ aiModelsTried.length ? aiModelsTried.join(', ') : 'No remote call recorded' }}
                                </div>
                            </div>
                            <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-950">
                                <div class="text-[11px] uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">Estimated cost</div>
                                <div class="mt-2 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ aiEstimatedCostLabel }}</div>
                                <div class="mt-1 text-[11px] text-stone-500 dark:text-neutral-400">
                                    Tokens {{ aiUsage.total_tokens || 0 }}
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 space-y-2">
                            <div class="flex flex-wrap items-center gap-2 text-[11px] text-stone-500 dark:text-neutral-400">
                                <span>Prompt {{ aiUsage.prompt_tokens || 0 }}</span>
                                <span>Completion {{ aiUsage.completion_tokens || 0 }}</span>
                                <span v-if="scan.ai_cache_hit">Cache source {{ aiCacheSourceLabel }}</span>
                            </div>
                            <div v-if="aiAttempts.length" class="space-y-2">
                                <div
                                    v-for="(attempt, index) in aiAttempts"
                                    :key="`attempt-${index}`"
                                    class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700"
                                >
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                            {{ attempt.source === 'cache' ? 'Cached result' : `${attempt.source} attempt` }}
                                        </div>
                                        <span class="rounded-full px-2 py-1 text-[10px] font-semibold uppercase"
                                            :class="attempt.status === 'completed'
                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200'
                                                : attempt.status === 'failed'
                                                    ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-200'
                                                    : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-200'">
                                            {{ attempt.status }}
                                        </span>
                                    </div>
                                    <div class="mt-1 text-[11px] text-stone-500 dark:text-neutral-400">
                                        Model {{ attempt.model || attempt.requested_model || 'n/a' }}
                                        <span v-if="attempt.overall_confidence">| Confidence {{ attempt.overall_confidence }}%</span>
                                        <span v-if="attempt.recommended_action">| Action {{ attempt.recommended_action }}</span>
                                    </div>
                                    <div class="mt-1 text-[11px] text-stone-500 dark:text-neutral-400">
                                        Tokens {{ attempt.usage?.total_tokens || 0 }}
                                        <span v-if="attempt.estimated_cost_usd !== null && attempt.estimated_cost_usd !== undefined">
                                            | Cost ${{ Number(attempt.estimated_cost_usd).toFixed(6) }}
                                        </span>
                                        <span v-if="attempt.cache_source">| {{ aiCacheSourceLabel || attempt.cache_source }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="pricing.provider" class="rounded-sm border border-stone-200 bg-white p-4 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 lg:col-span-2">
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

                    <div class="rounded-sm border border-stone-200 bg-white p-4 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 lg:col-span-2">
                        <h2 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">Differentiator</h2>
                        <ul class="mt-3 space-y-2 text-sm">
                            <li>3 quote levels with margins and benchmarks.</li>
                            <li>AI-assisted intake with trade, surface, rooms, and review flags.</li>
                            <li>Live Canadian suppliers with line-by-line sources.</li>
                            <li>Fast conversion to a draft quote.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div
                class="p-5 space-y-4 flex flex-col bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-900 dark:border-neutral-700"
            >
                <ValidationSummary :errors="reviewForm.errors" />

                <template v-if="isScanProcessing">
                    <div class="rounded-sm border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                        AI extraction is still in progress. The review cockpit will unlock automatically once the scan is ready.
                    </div>
                </template>

                <template v-else>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">AI review cockpit</h2>
                            <p class="text-sm text-stone-500 dark:text-neutral-400">
                                Confirm the extracted metrics and adjust the detected lines before converting to a quote.
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-semibold text-stone-600 hover:border-stone-300 hover:text-stone-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:text-neutral-100"
                                @click="addReviewLine"
                            >
                                Add line
                            </button>
                            <button
                                type="button"
                                :disabled="reviewForm.processing"
                                class="rounded-sm bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                                @click="saveReview"
                            >
                                Save and regenerate
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                        <FloatingSelect v-model="reviewForm.trade_type" label="Trade" :options="tradeOptions" />
                        <FloatingSelect v-model="reviewForm.priority" label="Priority" :options="priorityOptions" />
                        <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <label class="text-[11px] uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">Surface (m2)</label>
                            <input
                                v-model="reviewForm.surface_m2"
                                type="number"
                                step="0.1"
                                class="mt-2 w-full border-0 bg-transparent p-0 text-sm text-stone-800 focus:ring-0 dark:text-neutral-100"
                            />
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <label class="text-[11px] uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">Rooms</label>
                            <input
                                v-model="reviewForm.rooms"
                                type="number"
                                step="1"
                                class="mt-2 w-full border-0 bg-transparent p-0 text-sm text-stone-800 focus:ring-0 dark:text-neutral-100"
                            />
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between border-b border-stone-200 px-4 py-3 dark:border-neutral-700">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">Detected and reviewed lines</h3>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    These lines now feed the variant generation when present.
                                </p>
                            </div>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ reviewForm.line_items.length }} line(s)
                            </span>
                        </div>

                        <div v-if="!reviewForm.line_items.length" class="px-4 py-4 text-sm text-stone-500 dark:text-neutral-400">
                            No detected lines yet. Add one manually to drive the next quote regeneration.
                        </div>

                        <div v-else class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <div
                                v-for="(line, index) in reviewForm.line_items"
                                :key="`line-${index}`"
                                class="grid grid-cols-1 gap-3 px-4 py-4 lg:grid-cols-12"
                            >
                                <div class="lg:col-span-4">
                                    <label class="text-[11px] uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">Name</label>
                                    <input
                                        v-model="line.name"
                                        type="text"
                                        class="mt-2 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-800 focus:border-green-500 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                                    />
                                </div>
                                <div class="lg:col-span-2">
                                    <label class="text-[11px] uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">Quantity</label>
                                    <input
                                        v-model="line.quantity"
                                        type="number"
                                        step="0.1"
                                        class="mt-2 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-800 focus:border-green-500 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                                    />
                                </div>
                                <div class="lg:col-span-1">
                                    <label class="text-[11px] uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">Unit</label>
                                    <input
                                        v-model="line.unit"
                                        type="text"
                                        class="mt-2 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-800 focus:border-green-500 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                                    />
                                </div>
                                <div class="lg:col-span-2">
                                    <label class="text-[11px] uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">Base cost</label>
                                    <input
                                        v-model="line.base_cost"
                                        type="number"
                                        step="0.01"
                                        class="mt-2 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-800 focus:border-green-500 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                                    />
                                </div>
                                <div class="lg:col-span-2">
                                    <label class="text-[11px] uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">Confidence</label>
                                    <input
                                        v-model="line.confidence"
                                        type="number"
                                        step="1"
                                        min="0"
                                        max="100"
                                        class="mt-2 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-800 focus:border-green-500 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                                    />
                                </div>
                                <div class="flex items-end justify-end lg:col-span-1">
                                    <button
                                        type="button"
                                        class="rounded-sm border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50 dark:border-rose-500/30 dark:text-rose-300 dark:hover:bg-rose-500/10"
                                        @click="removeReviewLine(index)"
                                    >
                                        Remove
                                    </button>
                                </div>

                                <div class="lg:col-span-7">
                                    <label class="text-[11px] uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">Description</label>
                                    <input
                                        v-model="line.description"
                                        type="text"
                                        class="mt-2 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-800 focus:border-green-500 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                                    />
                                </div>
                                <div class="lg:col-span-4">
                                    <label class="text-[11px] uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">Notes</label>
                                    <input
                                        v-model="line.notes"
                                        type="text"
                                        class="mt-2 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-800 focus:border-green-500 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                                    />
                                </div>
                                <div class="flex items-end lg:col-span-1">
                                    <label class="flex items-center gap-2 text-xs font-medium text-stone-600 dark:text-neutral-300">
                                        <input v-model="line.is_labor" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-500 dark:border-neutral-700" />
                                        Labor
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
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
                        <FloatingSelect
                            v-model="selectedCustomerId"
                            label="Customer"
                            :options="customerOptions"
                            placeholder="Select customer"
                        />
                        <FloatingSelect
                            v-model="selectedPropertyId"
                            label="Property"
                            :options="propertyOptions"
                            placeholder="No property"
                        />
                    </div>
                </div>
                <div v-if="scan.ai_review_required" class="rounded-sm border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                    This scan still requires review. Save the review cockpit first, or retry/escalate the AI pass before creating a quote.
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
                            :disabled="scan.ai_review_required || (needsCustomer && !selectedCustomerId)"
                            @click="convert(variant.key)"
                        >
                            {{ scan.ai_review_required ? 'Review required first' : 'Create quote' }}
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
