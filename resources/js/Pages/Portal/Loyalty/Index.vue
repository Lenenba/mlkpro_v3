<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    customer: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    entries: { type: Object, default: () => ({ data: [] }) },
    eventOptions: { type: Array, default: () => [] },
    program: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const isLoading = ref(false);
const showAdvanced = ref(Boolean(props.filters?.period === 'custom' || props.filters?.from || props.filters?.to));
let filterTimeout;

const filterForm = useForm({
    period: props.filters?.period || '30d',
    from: props.filters?.from || '',
    to: props.filters?.to || '',
    event: props.filters?.event || '',
    sort: props.filters?.sort || 'processed_at',
    direction: props.filters?.direction || 'desc',
    per_page: Number(props.filters?.per_page || 15),
});

const periodOptions = computed(() => ([
    { id: '7d', name: t('loyalty_module.period.last_7_days') },
    { id: '30d', name: t('loyalty_module.period.last_30_days') },
    { id: '90d', name: t('loyalty_module.period.last_90_days') },
    { id: 'month', name: t('loyalty_module.period.this_month') },
    { id: 'custom', name: t('loyalty_module.period.custom') },
]));

const eventLabel = (event) => {
    const key = `loyalty_module.events.${event || 'accrual'}`;
    const translated = t(key);
    return translated === key ? (event || 'accrual') : translated;
};

const eventFilterOptions = computed(() => ([
    { id: '', name: t('loyalty_module.filters.all_events') },
    ...(props.eventOptions || []).map((event) => ({ id: event, name: eventLabel(event) })),
]));

const perPageOptions = computed(() => ([
    { id: 10, name: '10' },
    { id: 15, name: '15' },
    { id: 25, name: '25' },
    { id: 50, name: '50' },
]));

const filterPayload = () => {
    const payload = {
        period: filterForm.period,
        from: filterForm.from,
        to: filterForm.to,
        event: filterForm.event,
        sort: filterForm.sort,
        direction: filterForm.direction,
        per_page: filterForm.per_page,
    };

    Object.keys(payload).forEach((key) => {
        const value = payload[key];
        if (value === '' || value === null || value === undefined) {
            delete payload[key];
        }
    });

    return payload;
};

const applyFilters = () => {
    isLoading.value = true;
    router.get(route('portal.loyalty.index'), filterPayload(), {
        only: ['filters', 'entries', 'eventOptions', 'program', 'stats', 'customer'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
            isLoading.value = false;
        },
    });
};

const autoFilter = () => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }
    filterTimeout = setTimeout(() => applyFilters(), 320);
};

watch(
    () => [
        filterForm.period,
        filterForm.from,
        filterForm.to,
        filterForm.event,
        filterForm.sort,
        filterForm.direction,
        filterForm.per_page,
    ],
    autoFilter
);

const clearFilters = () => {
    filterForm.period = '30d';
    filterForm.from = '';
    filterForm.to = '';
    filterForm.event = '';
    filterForm.sort = 'processed_at';
    filterForm.direction = 'desc';
    filterForm.per_page = 15;
    showAdvanced.value = false;
    applyFilters();
};

const toggleSort = (column) => {
    if (filterForm.sort === column) {
        filterForm.direction = filterForm.direction === 'asc' ? 'desc' : 'asc';
        return;
    }
    filterForm.sort = column;
    filterForm.direction = column === 'processed_at' ? 'desc' : 'asc';
};

const pointLabel = computed(() => props.program?.points_label || t('loyalty_module.default_points_label'));
const currentPage = computed(() => Number(props.entries?.current_page || 1));
const totalPages = computed(() => Number(props.entries?.last_page || 1));
const formatNumber = (value) => Number(value || 0).toLocaleString();
const formatCurrency = (value) => `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const formatDateTime = (value) => humanizeDate(value) || '-';

const referenceLabel = (entry) => {
    if (entry?.sale_number) return t('loyalty_module.references.sale', { number: entry.sale_number });
    if (entry?.sale_id) return t('loyalty_module.references.sale_id', { id: entry.sale_id });
    if (entry?.payment_id) return t('loyalty_module.references.payment', { id: entry.payment_id });
    return '-';
};

const eventBadgeClass = (event) => {
    if (event === 'accrual') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
    if (event === 'redemption') return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
    if (event === 'refund') return 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300';
    if (event === 'redemption_reversal') return 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300';
    return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
};

const signedPoints = (value) => `${Number(value || 0) > 0 ? '+' : ''}${formatNumber(value)}`;
const pointsClass = (value) => (Number(value || 0) >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400');

onBeforeUnmount(() => {
    if (filterTimeout) clearTimeout(filterTimeout);
});
</script>

<template>
    <Head :title="$t('client_loyalty.title')" />

    <AuthenticatedLayout>
        <div class="space-y-3 loyalty-client-enter">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-amber-500 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ $t('client_loyalty.title') }}</h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('client_loyalty.subtitle') }}</p>
                    </div>
                    <span class="inline-flex rounded-sm border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300">
                        {{ customer?.name || '-' }}
                    </span>
                </div>

                <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5">
                    <FloatingSelect v-model="filterForm.period" :options="periodOptions" :label="$t('loyalty_module.filters.period')" />
                    <FloatingSelect v-model="filterForm.event" :options="eventFilterOptions" :label="$t('loyalty_module.filters.event')" />
                    <FloatingSelect v-model="filterForm.per_page" :options="perPageOptions" :label="$t('loyalty_module.filters.per_page')" />
                    <FloatingInput v-if="showAdvanced || filterForm.period === 'custom'" v-model="filterForm.from" type="date" :label="$t('loyalty_module.filters.from')" />
                    <FloatingInput v-if="showAdvanced || filterForm.period === 'custom'" v-model="filterForm.to" type="date" :label="$t('loyalty_module.filters.to')" />
                </div>

                <div class="mt-3 flex flex-wrap items-center justify-end gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                        @click="showAdvanced = !showAdvanced"
                    >
                        {{ showAdvanced ? $t('loyalty_module.actions.hide_advanced') : $t('loyalty_module.actions.show_advanced') }}
                    </button>
                    <button type="button" class="inline-flex items-center rounded-sm border border-transparent bg-amber-500 px-3 py-2 text-xs font-medium text-white hover:bg-amber-600" @click="applyFilters">
                        {{ $t('loyalty_module.actions.apply_filters') }}
                    </button>
                    <button type="button" class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700" @click="clearFilters">
                        {{ $t('loyalty_module.actions.clear_filters') }}
                    </button>
                </div>
            </section>

            <section class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_loyalty.kpi.balance') }}</div>
                    <div v-if="isLoading" class="mt-2 h-7 w-28 animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                    <div v-else class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ formatNumber(stats.balance) }} {{ pointLabel }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_loyalty.kpi.earned_period') }}</div>
                    <div v-if="isLoading" class="mt-2 h-7 w-20 animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                    <div v-else class="mt-1 text-2xl font-semibold text-emerald-700 dark:text-emerald-400">+{{ formatNumber(stats.points_earned_period) }}</div>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_loyalty.kpi.earned_lifetime') }}: +{{ formatNumber(stats.points_earned_lifetime) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_loyalty.kpi.spent_period') }}</div>
                    <div v-if="isLoading" class="mt-2 h-7 w-20 animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                    <div v-else class="mt-1 text-2xl font-semibold text-rose-700 dark:text-rose-400">-{{ formatNumber(stats.points_spent_period) }}</div>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_loyalty.kpi.spent_lifetime') }}: -{{ formatNumber(stats.points_spent_lifetime) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_loyalty.kpi.movements') }}</div>
                    <div v-if="isLoading" class="mt-2 h-7 w-16 animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                    <div v-else class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ formatNumber(stats.movements_count_period) }}</div>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_loyalty.kpi.movements_lifetime') }}: {{ formatNumber(stats.movements_count_lifetime) }}</div>
                </div>
            </section>

            <section class="grid grid-cols-1 gap-4 xl:h-[calc(100vh-25.5rem)] xl:min-h-[420px] xl:grid-cols-[320px,minmax(0,1fr)]">
                <aside class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('client_loyalty.program.title') }}</h2>
                    <div class="mt-3 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                        <div>{{ $t('client_loyalty.program.status') }}: {{ program?.is_enabled ? $t('loyalty_module.program.enabled') : $t('loyalty_module.program.disabled') }}</div>
                        <div>{{ $t('client_loyalty.program.rate') }}: {{ Number(program?.points_per_currency_unit || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 4 }) }}</div>
                        <div>{{ $t('client_loyalty.program.minimum_spend') }}: {{ formatCurrency(program?.minimum_spend || 0) }}</div>
                        <div>{{ $t('client_loyalty.program.label') }}: {{ pointLabel }}</div>
                    </div>
                </aside>

                <div class="p-5 space-y-4 flex h-full min-h-0 flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('client_loyalty.ledger.title') }}</h2>
                        <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                            {{ $t('loyalty_module.ledger.page_indicator', { current: currentPage, total: totalPages }) }}
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 overflow-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar]:w-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                        <div class="min-w-full inline-block align-middle">
                            <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                                <thead>
                                    <tr>
                                        <th scope="col" class="min-w-40">
                                            <button type="button" class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300" @click="toggleSort('processed_at')">
                                                {{ $t('loyalty_module.ledger.date') }}
                                                <svg v-if="filterForm.sort === 'processed_at'" class="size-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                                    <path d="m6 9 6 6 6-6" />
                                                </svg>
                                            </button>
                                        </th>
                                        <th scope="col" class="min-w-32">
                                            <button type="button" class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300" @click="toggleSort('event')">
                                                {{ $t('loyalty_module.ledger.event') }}
                                                <svg v-if="filterForm.sort === 'event'" class="size-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                                    <path d="m6 9 6 6 6-6" />
                                                </svg>
                                            </button>
                                        </th>
                                        <th scope="col" class="min-w-32">
                                            <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                                {{ $t('loyalty_module.ledger.reference') }}
                                            </div>
                                        </th>
                                        <th scope="col" class="min-w-20">
                                            <button type="button" class="px-5 py-2.5 text-end w-full flex justify-end items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300" @click="toggleSort('points')">
                                                {{ $t('loyalty_module.ledger.points') }}
                                                <svg v-if="filterForm.sort === 'points'" class="size-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                                    <path d="m6 9 6 6 6-6" />
                                                </svg>
                                            </button>
                                        </th>
                                        <th scope="col" class="min-w-20">
                                            <button type="button" class="px-5 py-2.5 text-end w-full flex justify-end items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300" @click="toggleSort('amount')">
                                                {{ $t('loyalty_module.ledger.amount') }}
                                                <svg v-if="filterForm.sort === 'amount'" class="size-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                                    <path d="m6 9 6 6 6-6" />
                                                </svg>
                                            </button>
                                        </th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                    <template v-if="isLoading">
                                        <tr v-for="row in 8" :key="`loyalty-client-skeleton-${row}`">
                                            <td colspan="5" class="px-4 py-3">
                                                <div class="grid grid-cols-5 gap-4 animate-pulse">
                                                    <div class="h-3 w-28 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                                    <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                                    <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                                    <div class="h-3 w-10 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                                    <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                    <template v-else>
                                        <tr v-if="!(entries.data || []).length">
                                            <td colspan="5" class="px-4 py-8 text-center text-stone-600 dark:text-neutral-300">
                                                {{ $t('loyalty_module.ledger.empty') }}
                                            </td>
                                        </tr>
                                        <tr v-for="entry in entries.data || []" :key="entry.id">
                                            <td class="size-px whitespace-nowrap px-4 py-2 text-start text-sm text-stone-600 dark:text-neutral-300">
                                                {{ formatDateTime(entry.processed_at) }}
                                            </td>
                                            <td class="size-px whitespace-nowrap px-4 py-2 text-start">
                                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="eventBadgeClass(entry.event)">
                                                    {{ eventLabel(entry.event) }}
                                                </span>
                                            </td>
                                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">
                                                {{ referenceLabel(entry) }}
                                            </td>
                                            <td class="size-px whitespace-nowrap px-4 py-2 text-end text-sm font-semibold" :class="pointsClass(entry.points)">
                                                {{ signedPoints(entry.points) }}
                                            </td>
                                            <td class="size-px whitespace-nowrap px-4 py-2 text-end text-sm font-medium text-stone-700 dark:text-neutral-200">
                                                {{ formatCurrency(entry.amount) }}
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="(entries.data || []).length" class="mt-5 flex flex-wrap justify-between items-center gap-2">
                        <p class="text-sm text-stone-800 dark:text-neutral-200">
                            <span class="font-medium">{{ entries.total ?? (entries.data || []).length }}</span>
                            <span class="text-stone-500 dark:text-neutral-500"> {{ $t('loyalty_module.ledger.results') }}</span>
                        </p>

                        <nav class="flex justify-end items-center gap-x-1" aria-label="Pagination">
                            <Link :href="entries.prev_page_url" v-if="entries.prev_page_url">
                                <button type="button"
                                    class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                                    :aria-label="$t('invoices.pagination.previous')">
                                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path d="m15 18-6-6 6-6" />
                                    </svg>
                                    <span class="sr-only">{{ $t('invoices.pagination.previous') }}</span>
                                </button>
                            </Link>
                            <div class="flex items-center gap-x-1">
                                <span class="min-h-[38px] min-w-[38px] flex justify-center items-center bg-stone-100 text-stone-800 py-2 px-3 text-sm rounded-sm disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:text-white" aria-current="page">{{ entries.from }}</span>
                                <span class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">{{ $t('invoices.pagination.of') }}</span>
                                <span class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">{{ entries.to }}</span>
                            </div>
                            <Link :href="entries.next_page_url" v-if="entries.next_page_url">
                                <button type="button"
                                    class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                                    :aria-label="$t('invoices.pagination.next')">
                                    <span class="sr-only">{{ $t('invoices.pagination.next') }}</span>
                                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path d="m9 18 6-6-6-6" />
                                    </svg>
                                </button>
                            </Link>
                        </nav>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.loyalty-client-enter {
    animation: loyaltyClientFadeUp 260ms ease-out both;
}

@keyframes loyaltyClientFadeUp {
    from {
        opacity: 0;
        transform: translateY(8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .loyalty-client-enter {
        animation: none;
    }
}
</style>
