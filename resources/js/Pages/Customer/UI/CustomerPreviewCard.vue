<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CardNoHeader from '@/Components/UI/CardNoHeader.vue';
import { humanizeDate } from '@/utils/date';
import { useCurrencyFormatter } from '@/utils/currency';

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({}),
    },
    billing: {
        type: Object,
        default: () => ({ summary: {} }),
    },
    latestQuote: {
        type: Object,
        default: null,
    },
    latestWork: {
        type: Object,
        default: null,
    },
    latestInvoice: {
        type: Object,
        default: null,
    },
});

const { t } = useI18n();

const formatDate = (value) => humanizeDate(value);
const { formatCurrency } = useCurrencyFormatter();
const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });
const formatStatus = (status, keyPrefix = '') => {
    if (!status) {
        return t('customers.labels.unknown_status');
    }
    if (keyPrefix) {
        const key = `${keyPrefix}.${status}`;
        const translated = t(key);
        if (translated && translated !== key) {
            return translated;
        }
    }

    return String(status).replace(/_/g, ' ');
};
const hasValue = (value) => value !== null && value !== undefined;

const kpiMax = computed(() => {
    const values = [
        Number(props.stats?.quotes || 0),
        Number(props.stats?.active_works || 0),
        Number(props.stats?.jobs || 0),
        Number(props.stats?.invoices || 0),
        Number(props.stats?.requests || 0),
    ];

    return Math.max(1, ...values);
});

const kpiBarWidth = (value) => {
    const safe = Number(value || 0);
    if (safe <= 0) {
        return '0%';
    }

    const max = kpiMax.value || 1;
    const percent = max ? Math.round((safe / max) * 100) : 0;

    return `${Math.min(100, Math.max(12, percent))}%`;
};

const balanceBarWidth = computed(() => {
    const balance = Math.max(0, Number(props.billing?.summary?.balance_due || 0));
    const total = Math.max(0, Number(props.billing?.summary?.total_invoiced || 0));
    if (!balance) {
        return '0%';
    }
    if (!total) {
        return '60%';
    }

    const percent = Math.round((balance / total) * 100);

    return `${Math.min(100, Math.max(12, percent))}%`;
});
</script>

<template>
    <CardNoHeader>
        <template #title>{{ $t('customers.details.preview.title') }}</template>

        <div class="grid grid-cols-2 gap-3 rise-stagger">
            <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.quotes') }}</div>
                        <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(stats?.quotes ?? 0) }}
                        </div>
                    </div>
                    <div class="flex h-9 w-9 items-center justify-center rounded-sm bg-emerald-50 text-emerald-600 animate-micro-pop dark:bg-emerald-500/10 dark:text-emerald-300">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-text-quote h-5 w-5">
                            <path d="M17 6H3" />
                            <path d="M21 12H8" />
                            <path d="M21 18H8" />
                            <path d="M3 12v6" />
                        </svg>
                    </div>
                </div>
                <div v-if="(stats?.quotes ?? 0) > 0" class="mt-3 h-1.5 w-full rounded-full bg-stone-100 dark:bg-neutral-800">
                    <div class="kpi-bar h-full rounded-full bg-emerald-500" :style="{ '--kpi-width': kpiBarWidth(stats?.quotes) }"></div>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.active_jobs') }}</div>
                        <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(stats?.active_works ?? 0) }}
                        </div>
                    </div>
                    <div class="flex h-9 w-9 items-center justify-center rounded-sm bg-sky-50 text-sky-600 animate-micro-pop dark:bg-sky-500/10 dark:text-sky-300">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-monitor-cog h-5 w-5">
                            <path d="M12 17v4" />
                            <path d="m15.2 4.9-.9-.4" />
                            <path d="m15.2 7.1-.9.4" />
                            <path d="m16.9 3.2-.4-.9" />
                            <path d="m16.9 8.8-.4.9" />
                            <path d="m19.5 2.3-.4.9" />
                            <path d="m19.5 9.7-.4-.9" />
                            <path d="m21.7 4.5-.9.4" />
                            <path d="m21.7 7.5-.9-.4" />
                            <path d="M22 13v2a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7" />
                            <path d="M8 21h8" />
                            <circle cx="18" cy="6" r="3" />
                        </svg>
                    </div>
                </div>
                <div v-if="(stats?.active_works ?? 0) > 0" class="mt-3 h-1.5 w-full rounded-full bg-stone-100 dark:bg-neutral-800">
                    <div class="kpi-bar h-full rounded-full bg-sky-500" :style="{ '--kpi-width': kpiBarWidth(stats?.active_works) }"></div>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.jobs') }}</div>
                        <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(stats?.jobs ?? 0) }}
                        </div>
                    </div>
                    <div class="flex h-9 w-9 items-center justify-center rounded-sm bg-amber-50 text-amber-600 animate-micro-pop dark:bg-amber-500/10 dark:text-amber-300">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-house h-5 w-5">
                            <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" />
                            <path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                        </svg>
                    </div>
                </div>
                <div v-if="(stats?.jobs ?? 0) > 0" class="mt-3 h-1.5 w-full rounded-full bg-stone-100 dark:bg-neutral-800">
                    <div class="kpi-bar h-full rounded-full bg-amber-500" :style="{ '--kpi-width': kpiBarWidth(stats?.jobs) }"></div>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.invoices') }}</div>
                        <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(stats?.invoices ?? 0) }}
                        </div>
                    </div>
                    <div class="flex h-9 w-9 items-center justify-center rounded-sm bg-indigo-50 text-indigo-600 animate-micro-pop dark:bg-indigo-500/10 dark:text-indigo-300">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-file-text h-5 w-5">
                            <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" />
                            <path d="M14 2v4a2 2 0 0 0 2 2h4" />
                            <path d="M10 9H8" />
                            <path d="M16 13H8" />
                            <path d="M16 17H8" />
                        </svg>
                    </div>
                </div>
                <div v-if="(stats?.invoices ?? 0) > 0" class="mt-3 h-1.5 w-full rounded-full bg-stone-100 dark:bg-neutral-800">
                    <div class="kpi-bar h-full rounded-full bg-indigo-500" :style="{ '--kpi-width': kpiBarWidth(stats?.invoices) }"></div>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.requests') }}</div>
                        <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(stats?.requests ?? 0) }}
                        </div>
                    </div>
                    <div class="flex h-9 w-9 items-center justify-center rounded-sm bg-rose-50 text-rose-600 animate-micro-pop dark:bg-rose-500/10 dark:text-rose-300">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-git-pull-request h-5 w-5">
                            <circle cx="18" cy="18" r="3" />
                            <circle cx="6" cy="6" r="3" />
                            <path d="M13 6h3a2 2 0 0 1 2 2v7" />
                            <line x1="6" x2="6" y1="9" y2="21" />
                        </svg>
                    </div>
                </div>
                <div v-if="(stats?.requests ?? 0) > 0" class="mt-3 h-1.5 w-full rounded-full bg-stone-100 dark:bg-neutral-800">
                    <div class="kpi-bar h-full rounded-full bg-rose-500" :style="{ '--kpi-width': kpiBarWidth(stats?.requests) }"></div>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.balance_due') }}</div>
                        <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatCurrency(billing?.summary?.balance_due) }}
                        </div>
                    </div>
                    <div class="flex h-9 w-9 items-center justify-center rounded-sm bg-teal-50 text-teal-600 animate-micro-pop dark:bg-teal-500/10 dark:text-teal-300">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-receipt h-5 w-5">
                            <path d="M4 2h16v20l-4-2-4 2-4-2-4 2V2z" />
                            <path d="M16 8h-8" />
                            <path d="M16 12h-8" />
                            <path d="M10 16h-2" />
                        </svg>
                    </div>
                </div>
                <div v-if="Number(billing?.summary?.balance_due || 0) > 0" class="mt-3 h-1.5 w-full rounded-full bg-stone-100 dark:bg-neutral-800">
                    <div class="kpi-bar h-full rounded-full bg-teal-500" :style="{ '--kpi-width': balanceBarWidth }"></div>
                </div>
            </div>
        </div>

        <div class="mt-4 space-y-3 text-sm">
            <div class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.latest_quote') }}</div>
                <div v-if="latestQuote">
                    <Link :href="route('customer.quote.show', latestQuote.id)" class="font-medium text-stone-800 hover:underline dark:text-neutral-200">
                        {{ latestQuote.number ? $t('customers.details.preview.quote_number', { number: latestQuote.number }) : $t('customers.details.preview.quote_fallback') }}
                    </Link>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ formatStatus(latestQuote.status, 'quotes.status') }} | {{ formatDate(latestQuote.created_at) }}
                    </div>
                    <div v-if="hasValue(latestQuote.total)" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('customers.details.preview.total') }} {{ formatCurrency(latestQuote.total) }}
                    </div>
                </div>
                <div v-else class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.no_quotes') }}</div>
            </div>

            <div class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.latest_job') }}</div>
                <div v-if="latestWork">
                    <Link :href="route('work.show', latestWork.id)" class="font-medium text-stone-800 hover:underline dark:text-neutral-200">
                        {{ latestWork.job_title || $t('customers.details.preview.job_fallback') }}
                    </Link>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ formatStatus(latestWork.status, 'jobs.status') }} | {{ formatDate(latestWork.start_date || latestWork.created_at) }}
                    </div>
                </div>
                <div v-else class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.no_jobs') }}</div>
            </div>

            <div class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.latest_invoice') }}</div>
                <div v-if="latestInvoice">
                    <Link :href="route('invoice.show', latestInvoice.id)" class="font-medium text-stone-800 hover:underline dark:text-neutral-200">
                        {{ latestInvoice.number ? $t('customers.details.preview.invoice_number', { number: latestInvoice.number }) : $t('customers.details.preview.invoice_fallback') }}
                    </Link>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ formatStatus(latestInvoice.status, 'dashboard.status.invoice') }} | {{ formatDate(latestInvoice.created_at) }}
                    </div>
                    <div v-if="hasValue(latestInvoice.total)" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('customers.details.preview.total') }} {{ formatCurrency(latestInvoice.total) }}
                    </div>
                </div>
                <div v-else class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.no_invoices') }}</div>
            </div>
        </div>
    </CardNoHeader>
</template>
