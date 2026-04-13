<script setup>
import { computed, reactive } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import { humanizeDate } from '@/utils/date';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import { useI18n } from 'vue-i18n';
import { isFeatureEnabled } from '@/utils/features';
import { useCurrencyFormatter } from '@/utils/currency';

const props = defineProps({
    filters: {
        type: Object,
        default: () => ({}),
    },
    payments: {
        type: Object,
        default: () => ({ data: [] }),
    },
    canViewInvoice: {
        type: Boolean,
        default: false,
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
    teamMembers: {
        type: Array,
        default: () => [],
    },
    works: {
        type: Array,
        default: () => [],
    },
    statusOptions: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();
const page = usePage();
const featureFlags = computed(() => page.props.auth?.account?.features || {});
const hasTeamMembersFeature = computed(() => isFeatureEnabled(featureFlags.value, 'team_members'));
const showTeamMemberFilter = computed(() => hasTeamMembersFeature.value && (props.teamMembers || []).length > 0);
const topCollectorsLabel = computed(() => (
    hasTeamMembersFeature.value ? t('tips_reports.kpi.top_members') : t('tips_reports.kpi.top_collectors')
));
const topCollectorsEmptyLabel = computed(() => (
    hasTeamMembersFeature.value ? t('tips_reports.empty.top_members') : t('tips_reports.empty.top_collectors')
));
const collectorColumnLabel = computed(() => (
    hasTeamMembersFeature.value ? t('tips_reports.table.team_member') : t('tips_reports.table.collected_by')
));
const collectorFallbackLabel = computed(() => (
    hasTeamMembersFeature.value ? '-' : t('tips_reports.table.owner')
));

const filters = reactive({
    period: props.filters?.period || '30d',
    from: props.filters?.from || '',
    to: props.filters?.to || '',
    team_member_id: props.filters?.team_member_id || '',
    work_id: props.filters?.work_id || '',
    status: props.filters?.status || '',
});
const currentPerPage = computed(() => resolveDataTablePerPage(props.payments?.per_page, props.filters?.per_page));

const sanitizedFilters = computed(() => {
    const query = {};
    Object.entries(filters).forEach(([key, value]) => {
        if (key === 'team_member_id' && !hasTeamMembersFeature.value) {
            return;
        }
        if (value !== '' && value !== null && value !== undefined) {
            query[key] = value;
        }
    });
    query.per_page = currentPerPage.value;
    return query;
});

const applyFilters = () => {
    router.get(route('payments.tips.index'), sanitizedFilters.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const clearFilters = () => {
    filters.period = '30d';
    filters.from = '';
    filters.to = '';
    filters.team_member_id = '';
    filters.work_id = '';
    filters.status = '';
    applyFilters();
};

const exportUrl = computed(() => route('payments.tips.export', sanitizedFilters.value));

const { formatCurrency } = useCurrencyFormatter();

const formatDateTime = (value) => {
    const formatted = humanizeDate(value);
    if (formatted) {
        return formatted;
    }

    if (!value) {
        return '-';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return date.toLocaleString();
};

const modeLabel = (mode) => {
    const key = `tips_reports.mode.${mode || 'fixed'}`;
    const translated = t(key);
    return translated === key ? (mode || 'fixed') : translated;
};

const statusLabel = (status) => {
    const key = `tips_reports.status.${status || 'completed'}`;
    const translated = t(key);
    return translated === key ? (status || 'completed') : translated;
};

const statusClass = (status) => {
    if (status === 'completed') {
        return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
    }
    if (status === 'pending') {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
    }
    if (status === 'reversed') {
        return 'bg-orange-100 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300';
    }
    if (status === 'refunded') {
        return 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300';
    }
    if (status === 'failed') {
        return 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300';
    }
    return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
};

const paymentRows = computed(() => (Array.isArray(props.payments?.data) ? props.payments.data : []));
const paymentLinks = computed(() => props.payments?.links || []);
const currentPage = computed(() => Number(props.payments?.current_page || 1));
const totalPages = computed(() => Number(props.payments?.last_page || 1));
const paymentResultsLabel = computed(() => t('datatable.shared.results_count', {
    count: props.payments?.total ?? paymentRows.value.length,
}));
const paymentPageIndicator = computed(() => t('datatable.shared.page_indicator', {
    current: currentPage.value,
    total: totalPages.value,
}));
</script>

<template>
    <Head :title="$t('tips_reports.owner.title')" />

    <AuthenticatedLayout>
        <div class="space-y-4">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ $t('tips_reports.owner.title') }}</h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('tips_reports.owner.subtitle') }}</p>
                    </div>
                    <a
                        :href="exportUrl"
                        class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                    >
                        {{ $t('tips_reports.actions.export_csv') }}
                    </a>
                </div>

                <form class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3 xl:grid-cols-6" @submit.prevent="applyFilters">
                    <select
                        v-model="filters.period"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                    >
                        <option value="7d">{{ $t('tips_reports.period.last_7_days') }}</option>
                        <option value="30d">{{ $t('tips_reports.period.last_30_days') }}</option>
                        <option value="90d">{{ $t('tips_reports.period.last_90_days') }}</option>
                        <option value="month">{{ $t('tips_reports.period.this_month') }}</option>
                        <option value="custom">{{ $t('tips_reports.period.custom') }}</option>
                    </select>

                    <input
                        v-model="filters.from"
                        type="date"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                    />

                    <input
                        v-model="filters.to"
                        type="date"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                    />

                    <select
                        v-if="showTeamMemberFilter"
                        v-model="filters.team_member_id"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                    >
                        <option value="">{{ $t('tips_reports.filters.all_team_members') }}</option>
                        <option v-for="member in teamMembers" :key="member.id" :value="member.id">{{ member.name }}</option>
                    </select>

                    <select
                        v-model="filters.work_id"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                    >
                        <option value="">{{ $t('tips_reports.filters.all_services') }}</option>
                        <option v-for="work in works" :key="work.id" :value="work.id">{{ work.title }}</option>
                    </select>

                    <select
                        v-model="filters.status"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                    >
                        <option value="">{{ $t('tips_reports.filters.all_statuses') }}</option>
                        <option v-for="status in statusOptions" :key="status" :value="status">{{ statusLabel(status) }}</option>
                    </select>
                </form>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-xs font-medium text-white hover:bg-emerald-700"
                        @click="applyFilters"
                    >
                        {{ $t('tips_reports.actions.apply_filters') }}
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                        @click="clearFilters"
                    >
                        {{ $t('tips_reports.actions.clear_filters') }}
                    </button>
                </div>
            </section>

            <section class="grid grid-cols-1 gap-3 md:grid-cols-3">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('tips_reports.kpi.total_tips') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatCurrency(stats.total_tips) }}
                    </div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('tips_reports.kpi.average_tip_per_reservation') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatCurrency(stats.average_tip_per_reservation) }}
                    </div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ topCollectorsLabel }}</div>
                    <div class="mt-2 space-y-1">
                        <div
                            v-for="member in stats.top_members || []"
                            :key="`top-member-${member.user_id}`"
                            class="text-xs text-stone-700 dark:text-neutral-200"
                        >
                            {{ member.name }} - {{ formatCurrency(member.total_tips) }}
                        </div>
                        <div v-if="!(stats.top_members || []).length" class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ topCollectorsEmptyLabel }}
                        </div>
                    </div>
                </div>
            </section>

            <section class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
                <AdminDataTable
                    embedded
                    :rows="paymentRows"
                    :links="paymentLinks"
                    :show-pagination="paymentRows.length > 0"
                    show-per-page
                    :per-page="currentPerPage"
                >
                    <template #head>
                        <tr>
                            <th scope="col" class="min-w-52 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('tips_reports.table.datetime') }}
                            </th>
                            <th scope="col" class="min-w-24 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('tips_reports.table.invoice') }}
                            </th>
                            <th scope="col" class="min-w-48 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('tips_reports.table.customer') }}
                            </th>
                            <th scope="col" class="min-w-40 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ collectorColumnLabel }}
                            </th>
                            <th scope="col" class="min-w-32 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('tips_reports.table.mode') }}
                            </th>
                            <th scope="col" class="min-w-28 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('tips_reports.table.tip_amount') }}
                            </th>
                            <th scope="col" class="min-w-32 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('tips_reports.table.total_charged') }}
                            </th>
                            <th scope="col" class="min-w-28 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('tips_reports.table.status') }}
                            </th>
                        </tr>
                    </template>

                    <template #row="{ row: payment }">
                        <tr>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-700 dark:text-neutral-200">{{ formatDateTime(payment.paid_at) }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-700 dark:text-neutral-200">
                                <Link
                                    v-if="canViewInvoice && payment.invoice_id"
                                    :href="route('invoice.show', payment.invoice_id)"
                                    class="text-emerald-700 hover:underline dark:text-emerald-400"
                                >
                                    {{ payment.invoice_number }}
                                </Link>
                                <span v-else>{{ payment.invoice_number }}</span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-700 dark:text-neutral-200">{{ payment.customer_name }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-700 dark:text-neutral-200">{{ payment.team_member_name || collectorFallbackLabel }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-700 dark:text-neutral-200">{{ modeLabel(payment.tip_mode) }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm font-medium text-stone-800 dark:text-neutral-100">{{ formatCurrency(payment.tip_amount) }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm font-medium text-stone-800 dark:text-neutral-100">{{ formatCurrency(payment.charged_total) }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(payment.status)">
                                    {{ statusLabel(payment.status) }}
                                </span>
                            </td>
                        </tr>
                    </template>

                    <template #empty>
                        <div class="rounded-sm border border-dashed border-stone-300 px-4 py-10 text-center text-sm text-stone-600 dark:border-neutral-600 dark:text-neutral-300">
                            {{ $t('tips_reports.empty.rows') }}
                        </div>
                    </template>

                    <template #pagination_prefix>
                        <div class="flex flex-wrap items-center gap-2 text-sm text-stone-500 dark:text-neutral-400">
                            <span>{{ paymentResultsLabel }}</span>
                            <span class="text-stone-300 dark:text-neutral-600">•</span>
                            <span>{{ paymentPageIndicator }}</span>
                        </div>
                    </template>
                </AdminDataTable>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
