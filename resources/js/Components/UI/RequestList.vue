<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { humanizeDate } from '@/utils/date';
import { useI18n } from 'vue-i18n';

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
const { t } = useI18n();

const formatDate = (value) => humanizeDate(value);

const titleForRequest = (lead) => lead?.title || lead?.service_type || t('requests.labels.request');

const requestSubtitle = (lead) => lead?.service_type || t('requests.labels.request_number', { id: lead?.id || '-' });

const statusLabel = (status) => {
    if (status === 'REQ_NEW') {
        return t('requests.status.new');
    }
    if (status === 'REQ_CONVERTED') {
        return t('requests.status.converted');
    }
    return status || t('requests.labels.unknown_status');
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
    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
        <div
            v-for="lead in requests"
            :key="lead.id"
            class="overflow-hidden rounded-sm border border-stone-200 bg-white shadow-sm dark:bg-neutral-900 dark:border-neutral-700"
        >
            <div class="flex flex-col gap-2 border-b border-stone-200 bg-stone-50/60 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-neutral-700 dark:bg-neutral-900/40">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-sm bg-amber-500 text-[11px] font-semibold text-white">
                        RQ
                    </span>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ titleForRequest(lead) }}
                        </div>
                        <div class="truncate text-xs text-stone-500 dark:text-neutral-400">
                            {{ requestSubtitle(lead) }}
                        </div>
                    </div>
                </div>

                <div class="hs-dropdown [--placement:bottom-right] relative inline-flex">
                    <button :id="`request-actions-${lead.id}`" type="button"
                        class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-500 shadow-sm hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                        aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                            height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="1" />
                            <circle cx="12" cy="5" r="1" />
                            <circle cx="12" cy="19" r="1" />
                        </svg>
                    </button>

                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                        role="menu" aria-orientation="vertical" :aria-labelledby="`request-actions-${lead.id}`">
                        <div class="p-1">
                            <Link
                                v-if="lead.quote"
                                :href="route('customer.quote.show', lead.quote.id)"
                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-stone-800 hover:bg-stone-100 focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                    class="lucide lucide-eye shrink-0 size-3.5">
                                    <path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                                {{ $t('requests.actions.view_quote') }}
                            </Link>
                            <button
                                v-else-if="lead.status === 'REQ_NEW'"
                                type="button"
                                :disabled="isProcessing(lead)"
                                @click="convertToQuote(lead)"
                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                            >
                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M12 5v14" />
                                    <path d="M5 12h14" />
                                </svg>
                                {{ $t('requests.actions.convert_to_quote') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="divide-y divide-stone-200 px-4 py-2 text-xs text-stone-500 dark:divide-neutral-700 dark:text-neutral-400">
                <div class="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:justify-between">
                    <span>{{ $t('requests.table.created') }}</span>
                    <span class="text-sm font-semibold text-stone-800 dark:text-neutral-200 sm:text-right">
                        {{ formatDate(lead.created_at) }}
                    </span>
                </div>
                <div class="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:justify-between">
                    <span>{{ $t('requests.table.status') }}</span>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold"
                        :class="statusPillClass(lead.status)">
                        {{ statusLabel(lead.status) }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
