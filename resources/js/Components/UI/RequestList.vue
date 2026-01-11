<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    requests: {
        type: Array,
        default: () => [],
    },
    customer: {
        type: Object,
        required: true,
    },
    defaultPropertyId: {
        type: [Number, String],
        default: null,
    },
});

const processingId = ref(null);

const formatDate = (value) => humanizeDate(value);

const titleForRequest = (lead) => lead?.title || lead?.service_type || 'Request';

const statusLabel = (status) => {
    if (status === 'REQ_NEW') {
        return 'New';
    }
    if (status === 'REQ_CONVERTED') {
        return 'Converted';
    }
    return status || 'Unknown';
};

const statusPillClass = (status) => {
    switch (status) {
        case 'REQ_NEW':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'REQ_CONVERTED':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        default:
            return 'bg-stone-100 text-stone-800 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const statusAccentClass = (status) => {
    switch (status) {
        case 'REQ_NEW':
            return 'border-l-amber-400';
        case 'REQ_CONVERTED':
            return 'border-l-emerald-400';
        default:
            return 'border-l-stone-300 dark:border-l-neutral-600';
    }
};

const convertToQuote = (lead) => {
    if (!lead?.id || processingId.value) {
        return;
    }

    processingId.value = lead.id;

    router.post(
        route('request.convert', lead.id),
        {
            customer_id: props.customer.id,
            property_id: props.defaultPropertyId || null,
            job_title: titleForRequest(lead),
        },
        {
            preserveScroll: true,
            onFinish: () => {
                processingId.value = null;
            },
        }
    );
};

const isProcessing = (lead) => processingId.value === lead?.id;
</script>

<template>
    <div class="space-y-3">
        <div
            v-for="lead in requests"
            :key="lead.id"
            class="rounded-sm border border-stone-200 border-l-4 bg-white p-4 shadow-sm dark:bg-neutral-900 dark:border-neutral-700"
            :class="statusAccentClass(lead.status)"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-200">
                        {{ titleForRequest(lead) }}
                    </div>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        Created {{ formatDate(lead.created_at) }}
                    </div>
                </div>

                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusPillClass(lead.status)">
                    {{ statusLabel(lead.status) }}
                </span>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                <div class="flex items-center gap-2">
                    <span v-if="lead.service_type">{{ lead.service_type }}</span>
                    <span v-if="lead.quote?.number">Quote {{ lead.quote.number }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <Link
                        v-if="lead.quote"
                        :href="route('customer.quote.show', lead.quote.id)"
                        class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                    >
                        View quote
                    </Link>
                    <button
                        v-else-if="lead.status === 'REQ_NEW'"
                        type="button"
                        :disabled="isProcessing(lead)"
                        @click="convertToQuote(lead)"
                        class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500"
                    >
                        Convert to quote
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
