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

const statusClass = (status) => {
    switch (status) {
        case 'REQ_NEW':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'REQ_CONVERTED':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        default:
            return 'bg-gray-100 text-gray-800 dark:bg-neutral-700 dark:text-neutral-200';
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
</script>

<template>
    <div class="space-y-3">
        <div
            v-for="lead in requests"
            :key="lead.id"
            class="rounded-sm border border-gray-200 bg-white p-4 shadow-sm dark:bg-neutral-900 dark:border-neutral-700"
        >
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-sm font-semibold text-gray-800 dark:text-neutral-200">
                        {{ titleForRequest(lead) }}
                    </div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-neutral-400">
                        Created {{ formatDate(lead.created_at) }}
                    </div>
                </div>

                <span class="inline-flex items-center rounded-sm px-2 py-0.5 text-xs font-medium" :class="statusClass(lead.status)">
                    {{ statusLabel(lead.status) }}
                </span>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-end gap-2">
                <Link
                    v-if="lead.quote"
                    :href="route('customer.quote.show', lead.quote.id)"
                    class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-gray-200 bg-white text-gray-800 shadow-sm hover:bg-gray-50 focus:outline-none focus:bg-gray-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                >
                    View quote
                </Link>
                <button
                    v-else-if="lead.status === 'REQ_NEW'"
                    type="button"
                    :disabled="processingId === lead.id"
                    @click="convertToQuote(lead)"
                    class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500"
                >
                    Convert to quote
                </button>
            </div>
        </div>
    </div>
</template>

