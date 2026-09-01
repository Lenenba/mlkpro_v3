<script setup>
import { computed, defineAsyncComponent, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import KpiMetricGrid from '@/Components/Dashboard/KpiMetricGrid.vue';
import {
    buildProspectAssigneeChartData,
    buildProspectSourceChartData,
    buildProspectStatusChartData,
} from '@/utils/requestAnalyticsCharts';

const Barchart = defineAsyncComponent(
    () => import('@/Components/UI/Barchart.vue'),
);

const props = defineProps({
    analytics: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
const activeTab = ref('overview');

const summary = computed(() => props.analytics?.summary ?? {});
const byStatus = computed(() => props.analytics?.by_status ?? []);
const bySource = computed(() => props.analytics?.by_source ?? []);
const byAssignee = computed(() => props.analytics?.by_assignee ?? []);
const windowDays = computed(() => props.analytics?.window_days ?? 30);

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const formatPercent = (value) => `${Number(value || 0).toFixed(1)}%`;

const formatDays = (value) => {
    if (value === null || value === undefined) {
        return '-';
    }

    return `${Number(value).toFixed(1)}d`;
};

const sourceKey = (source) => {
    if (!source) {
        return 'unknown';
    }

    const value = String(source).toLowerCase();
    const aliases = {
        web: 'web_form',
        website: 'web_form',
        form: 'web_form',
    };

    return aliases[value] || value || 'unknown';
};

const sourceLabel = (source) => t(`requests.sources.${sourceKey(source)}`);

const statusLabel = (status) => {
    switch (status) {
        case 'REQ_NEW':
            return t('requests.status.new');
        case 'REQ_CALL_REQUESTED':
            return t('requests.status.call_requested');
        case 'REQ_CONTACTED':
            return t('requests.status.contacted');
        case 'REQ_QUALIFIED':
            return t('requests.status.qualified');
        case 'REQ_QUOTE_SENT':
            return t('requests.status.quote_sent');
        case 'REQ_WON':
            return t('requests.status.won');
        case 'REQ_LOST':
            return t('requests.status.lost');
        case 'REQ_CONVERTED':
            return t('requests.status.converted');
        default:
            return status || t('requests.labels.unknown_status');
    }
};

const statusChartData = computed(() => buildProspectStatusChartData(byStatus.value, {
    labelForStatus: statusLabel,
    totalLabel: t('requests.analytics.dashboard.charts.total_series'),
}));
const sourceChartData = computed(() => buildProspectSourceChartData(bySource.value, {
    labelForSource: sourceLabel,
    totalLabel: t('requests.analytics.dashboard.charts.total_series'),
    convertedLabel: t('requests.analytics.dashboard.charts.converted_series'),
}));
const assigneeChartData = computed(() => buildProspectAssigneeChartData(byAssignee.value, {
    labelForAssignee: (key, row) => row.name
        || (key === 'unassigned' ? t('requests.analytics.dashboard.unassigned') : ''),
    totalLabel: t('requests.analytics.dashboard.charts.total_series'),
    overdueLabel: t('requests.analytics.dashboard.charts.overdue_series'),
}));
const statusChartHeight = computed(() => Math.min(
    440,
    Math.max(280, statusChartData.value.categories.length * 38 + 80),
));
const sourceChartHeight = computed(() => Math.min(
    420,
    Math.max(260, sourceChartData.value.categories.length * 42 + 80),
));
const assigneeChartHeight = computed(() => Math.min(
    520,
    Math.max(260, assigneeChartData.value.categories.length * 48 + 80),
));
const countChartOptions = computed(() => ({
    dataLabels: {
        enabled: true,
        formatter: (value) => formatNumber(value),
    },
    plotOptions: {
        bar: {
            barHeight: '58%',
        },
    },
    xaxis: {
        min: 0,
        forceNiceScale: true,
        labels: {
            formatter: (value) => formatNumber(value),
        },
    },
}));
const sourceChartOptions = computed(() => ({
    dataLabels: {
        enabled: false,
    },
    plotOptions: {
        bar: {
            barHeight: '64%',
        },
    },
    xaxis: {
        min: 0,
        forceNiceScale: true,
        labels: {
            formatter: (value) => formatNumber(value),
        },
    },
}));

const tabs = computed(() => [
    {
        key: 'overview',
        label: t('requests.analytics.dashboard.tabs.overview'),
        description: t('requests.analytics.dashboard.tabs.overview_note'),
    },
    {
        key: 'pipeline',
        label: t('requests.analytics.dashboard.tabs.pipeline'),
        description: t('requests.analytics.dashboard.tabs.pipeline_note'),
    },
    {
        key: 'assignees',
        label: t('requests.analytics.dashboard.tabs.assignees'),
        description: t('requests.analytics.dashboard.tabs.assignees_note'),
    },
]);

const cards = computed(() => [
    {
        key: 'total',
        label: t('requests.analytics.dashboard.cards.total'),
        value: formatNumber(summary.value.total),
        context: t('requests.analytics.dashboard.cards.total_note'),
        tone: 'stone',
    },
    {
        key: 'new_this_week',
        label: t('requests.analytics.dashboard.cards.new_this_week'),
        value: formatNumber(summary.value.new_this_week),
        context: t('requests.analytics.dashboard.cards.new_this_week_note'),
        tone: 'sky',
    },
    {
        key: 'new_this_month',
        label: t('requests.analytics.dashboard.cards.new_this_month'),
        value: formatNumber(summary.value.new_this_month),
        context: t('requests.analytics.dashboard.cards.new_this_month_note'),
        tone: 'indigo',
    },
    {
        key: 'due_today',
        label: t('requests.analytics.dashboard.cards.due_today'),
        value: formatNumber(summary.value.due_today),
        context: t('requests.analytics.dashboard.cards.due_today_note'),
        tone: 'amber',
    },
    {
        key: 'overdue',
        label: t('requests.analytics.dashboard.cards.overdue'),
        value: formatNumber(summary.value.overdue),
        context: t('requests.analytics.dashboard.cards.overdue_note'),
        tone: 'rose',
    },
    {
        key: 'won',
        label: t('requests.analytics.dashboard.cards.won'),
        value: formatNumber(summary.value.won),
        context: t('requests.analytics.dashboard.cards.won_note'),
        tone: 'emerald',
    },
    {
        key: 'lost',
        label: t('requests.analytics.dashboard.cards.lost'),
        value: formatNumber(summary.value.lost),
        context: t('requests.analytics.dashboard.cards.lost_note'),
        tone: 'rose',
    },
    {
        key: 'conversion_rate',
        label: t('requests.analytics.dashboard.cards.conversion_rate', { days: windowDays.value }),
        value: formatPercent(summary.value.conversion_rate),
        context: t('requests.analytics.dashboard.cards.conversion_rate_note', {
            converted: formatNumber(summary.value.conversion_converted_count),
            created: formatNumber(summary.value.conversion_created_count),
        }),
        tone: 'cyan',
    },
    {
        key: 'avg_conversion_days',
        label: t('requests.analytics.dashboard.cards.avg_conversion_days', { days: windowDays.value }),
        value: formatDays(summary.value.avg_conversion_days),
        context: t('requests.analytics.dashboard.cards.avg_conversion_days_note'),
        tone: 'violet',
    },
]);
</script>

<template>
    <div class="space-y-4 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('requests.analytics.dashboard.title') }}
                </h2>
                <p class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.analytics.dashboard.subtitle') }}
                </p>
            </div>
            <div class="text-xs text-stone-500 dark:text-neutral-400">
                {{ $t('requests.analytics.dashboard.window', { days: windowDays }) }}
            </div>
        </div>

        <div class="overflow-x-auto">
            <div class="flex min-w-max gap-2">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    class="rounded-sm border px-3 py-2 text-left transition"
                    :class="activeTab === tab.key
                        ? 'border-stone-800 bg-stone-800 text-white dark:border-neutral-100 dark:bg-neutral-100 dark:text-neutral-900'
                        : 'border-stone-200 bg-stone-50 text-stone-600 hover:border-stone-300 hover:text-stone-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:border-neutral-500 dark:hover:text-neutral-100'"
                    :aria-pressed="String(activeTab === tab.key)"
                    @click="activeTab = tab.key"
                >
                    <div class="text-xs font-semibold uppercase tracking-[0.12em]">
                        {{ tab.label }}
                    </div>
                    <div
                        class="mt-1 text-[11px]"
                        :class="activeTab === tab.key ? 'text-white/80 dark:text-neutral-700' : 'text-stone-500 dark:text-neutral-400'"
                    >
                        {{ tab.description }}
                    </div>
                </button>
            </div>
        </div>

        <KpiMetricGrid
            v-if="activeTab === 'overview'"
            :metrics="cards"
            grid-class="grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5"
            :aria-label="$t('requests.analytics.dashboard.title')"
        />

        <div v-if="activeTab === 'overview' || activeTab === 'pipeline'" class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr),minmax(0,1fr)]">
            <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <Suspense>
                    <Barchart
                        :title="$t('requests.analytics.dashboard.charts.status_title')"
                        :subtitle="$t('requests.analytics.dashboard.charts.status_subtitle')"
                        :series="statusChartData.series"
                        :categories="statusChartData.categories"
                        :height="statusChartHeight"
                        :options="countChartOptions"
                        :color-tones="['blue']"
                        :value-formatter="formatNumber"
                        :category-label="$t('requests.analytics.dashboard.charts.status_category')"
                        :value-label="$t('requests.analytics.dashboard.charts.count_value')"
                        :table-caption="$t('requests.analytics.dashboard.charts.status_table_caption')"
                        :empty-message="$t('requests.analytics.no_data')"
                        :framed="false"
                        horizontal
                    />
                    <template #fallback>
                        <div
                            class="flex min-h-64 items-center justify-center rounded-sm bg-stone-50 dark:bg-neutral-800"
                            role="status"
                            aria-live="polite"
                        >
                            <span class="sr-only">{{ $t('charts.loading') }}</span>
                            <span class="h-52 w-full rounded-sm bg-stone-100 motion-safe:animate-pulse dark:bg-neutral-700" aria-hidden="true"></span>
                        </div>
                    </template>
                </Suspense>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <Suspense>
                    <Barchart
                        :title="$t('requests.analytics.dashboard.charts.source_title')"
                        :subtitle="$t('requests.analytics.dashboard.charts.source_subtitle')"
                        :series="sourceChartData.series"
                        :categories="sourceChartData.categories"
                        :height="sourceChartHeight"
                        :options="sourceChartOptions"
                        :color-tones="['blue', 'emerald']"
                        :value-formatter="formatNumber"
                        :category-label="$t('requests.analytics.dashboard.charts.source_category')"
                        :value-label="$t('requests.analytics.dashboard.charts.count_value')"
                        :table-caption="$t('requests.analytics.dashboard.charts.source_table_caption')"
                        :empty-message="$t('requests.analytics.no_data')"
                        :framed="false"
                        horizontal
                    />
                    <template #fallback>
                        <div
                            class="flex min-h-64 items-center justify-center rounded-sm bg-stone-50 dark:bg-neutral-800"
                            role="status"
                            aria-live="polite"
                        >
                            <span class="sr-only">{{ $t('charts.loading') }}</span>
                            <span class="h-52 w-full rounded-sm bg-stone-100 motion-safe:animate-pulse dark:bg-neutral-700" aria-hidden="true"></span>
                        </div>
                    </template>
                </Suspense>

                <ul
                    v-if="sourceChartData.details.length"
                    class="mt-3 grid gap-2 border-t border-stone-200 pt-3 text-xs dark:border-neutral-700"
                    :aria-label="$t('requests.analytics.dashboard.charts.source_details_label')"
                >
                    <li
                        v-for="detail in sourceChartData.details"
                        :key="detail.key"
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <span class="font-medium text-stone-700 dark:text-neutral-200">{{ detail.category }}</span>
                        <span class="text-stone-500 dark:text-neutral-400">
                            {{ $t('requests.analytics.dashboard.charts.source_detail', {
                                converted: formatNumber(detail.converted),
                                won: formatNumber(detail.won),
                                lost: formatNumber(detail.lost),
                                rate: formatPercent(detail.rate),
                            }) }}
                        </span>
                    </li>
                </ul>
            </div>
        </div>

        <div v-if="activeTab === 'assignees'" class="grid gap-4 rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <Suspense>
                <Barchart
                    :title="$t('requests.analytics.dashboard.sections.by_assignee')"
                    :subtitle="$t('requests.analytics.dashboard.sections.by_assignee_note')"
                    :series="assigneeChartData.series"
                    :categories="assigneeChartData.categories"
                    :height="assigneeChartHeight"
                    :options="countChartOptions"
                    :color-tones="['blue', 'amber']"
                    :value-formatter="formatNumber"
                    :category-label="$t('requests.analytics.dashboard.charts.assignee_category')"
                    :value-label="$t('requests.analytics.dashboard.charts.count_value')"
                    :table-caption="$t('requests.analytics.dashboard.charts.assignee_table_caption')"
                    :empty-message="$t('requests.analytics.no_data')"
                    :framed="false"
                    horizontal
                />
                <template #fallback>
                    <div
                        class="flex min-h-64 items-center justify-center rounded-sm bg-stone-50 dark:bg-neutral-800"
                        role="status"
                        aria-live="polite"
                    >
                        <span class="sr-only">{{ $t('charts.loading') }}</span>
                        <span class="h-52 w-full rounded-sm bg-stone-100 motion-safe:animate-pulse dark:bg-neutral-700" aria-hidden="true"></span>
                    </div>
                </template>
            </Suspense>

            <ul
                v-if="assigneeChartData.details.length"
                class="grid gap-3 border-t border-stone-200 pt-4 md:grid-cols-2 dark:border-neutral-700"
                :aria-label="$t('requests.analytics.dashboard.charts.assignee_details_label')"
            >
                <li
                    v-for="item in assigneeChartData.details"
                    :key="item.key"
                    class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800"
                >
                    <div class="flex min-w-0 items-center justify-between gap-3">
                        <span class="min-w-0 break-words text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ item.category }}
                        </span>
                        <span class="shrink-0 text-sm font-semibold tabular-nums text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(item.total) }}
                        </span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2 text-[11px] text-stone-600 dark:text-neutral-300">
                        <span class="rounded-full bg-stone-200 px-2 py-1 dark:bg-neutral-700">
                            {{ $t('requests.analytics.dashboard.labels.due_today') }}: {{ formatNumber(item.dueToday) }}
                        </span>
                        <span class="rounded-full bg-rose-100 px-2 py-1 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                            {{ $t('requests.analytics.dashboard.labels.overdue') }}: {{ formatNumber(item.overdue) }}
                        </span>
                        <span class="rounded-full bg-emerald-100 px-2 py-1 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                            {{ $t('requests.analytics.dashboard.labels.won') }}: {{ formatNumber(item.won) }}
                        </span>
                        <span class="rounded-full bg-blue-100 px-2 py-1 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">
                            {{ $t('requests.analytics.dashboard.labels.converted') }}: {{ formatNumber(item.converted) }}
                        </span>
                        <span class="rounded-full bg-stone-200 px-2 py-1 dark:bg-neutral-700">
                            {{ $t('requests.analytics.dashboard.labels.lost') }}: {{ formatNumber(item.lost) }}
                        </span>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>
