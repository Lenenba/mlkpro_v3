<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

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

const maxStatusTotal = computed(() => Math.max(...byStatus.value.map((item) => Number(item.total || 0)), 1));
const maxSourceTotal = computed(() => Math.max(...bySource.value.map((item) => Number(item.total || 0)), 1));
const maxAssigneeTotal = computed(() => Math.max(...byAssignee.value.map((item) => Number(item.total || 0)), 1));

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
        note: t('requests.analytics.dashboard.cards.total_note'),
        borderClass: 'border-t-stone-700',
    },
    {
        key: 'new_this_week',
        label: t('requests.analytics.dashboard.cards.new_this_week'),
        value: formatNumber(summary.value.new_this_week),
        note: t('requests.analytics.dashboard.cards.new_this_week_note'),
        borderClass: 'border-t-sky-600',
    },
    {
        key: 'new_this_month',
        label: t('requests.analytics.dashboard.cards.new_this_month'),
        value: formatNumber(summary.value.new_this_month),
        note: t('requests.analytics.dashboard.cards.new_this_month_note'),
        borderClass: 'border-t-indigo-600',
    },
    {
        key: 'due_today',
        label: t('requests.analytics.dashboard.cards.due_today'),
        value: formatNumber(summary.value.due_today),
        note: t('requests.analytics.dashboard.cards.due_today_note'),
        borderClass: 'border-t-amber-600',
    },
    {
        key: 'overdue',
        label: t('requests.analytics.dashboard.cards.overdue'),
        value: formatNumber(summary.value.overdue),
        note: t('requests.analytics.dashboard.cards.overdue_note'),
        borderClass: 'border-t-rose-600',
    },
    {
        key: 'won',
        label: t('requests.analytics.dashboard.cards.won'),
        value: formatNumber(summary.value.won),
        note: t('requests.analytics.dashboard.cards.won_note'),
        borderClass: 'border-t-emerald-600',
    },
    {
        key: 'lost',
        label: t('requests.analytics.dashboard.cards.lost'),
        value: formatNumber(summary.value.lost),
        note: t('requests.analytics.dashboard.cards.lost_note'),
        borderClass: 'border-t-rose-700',
    },
    {
        key: 'conversion_rate',
        label: t('requests.analytics.dashboard.cards.conversion_rate', { days: windowDays.value }),
        value: formatPercent(summary.value.conversion_rate),
        note: t('requests.analytics.dashboard.cards.conversion_rate_note', {
            converted: formatNumber(summary.value.conversion_converted_count),
            created: formatNumber(summary.value.conversion_created_count),
        }),
        borderClass: 'border-t-cyan-600',
    },
    {
        key: 'avg_conversion_days',
        label: t('requests.analytics.dashboard.cards.avg_conversion_days', { days: windowDays.value }),
        value: formatDays(summary.value.avg_conversion_days),
        note: t('requests.analytics.dashboard.cards.avg_conversion_days_note'),
        borderClass: 'border-t-violet-600',
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

        <div v-if="activeTab === 'overview'" class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5">
            <div
                v-for="card in cards"
                :key="card.key"
                class="rounded-sm border border-stone-200 border-t-4 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800"
                :class="card.borderClass"
            >
                <div class="text-xs text-stone-500 dark:text-neutral-400">{{ card.label }}</div>
                <div class="mt-2 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ card.value }}</div>
                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ card.note }}</div>
            </div>
        </div>

        <div v-else-if="activeTab === 'pipeline'" class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr),minmax(0,1fr)]">
            <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('requests.analytics.dashboard.sections.by_status') }}
                </h3>
                <div v-if="byStatus.length" class="mt-4 space-y-3">
                    <div v-for="item in byStatus" :key="item.status" class="space-y-1">
                        <div class="flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                            <span>{{ statusLabel(item.status) }}</span>
                            <span>{{ formatNumber(item.total) }}</span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-stone-200 dark:bg-neutral-700">
                            <div
                                class="h-full rounded-full bg-stone-700 dark:bg-neutral-300"
                                :style="{ width: `${Math.max((Number(item.total || 0) / maxStatusTotal) * 100, item.total ? 8 : 0)}%` }"
                            />
                        </div>
                    </div>
                </div>
                <div v-else class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.analytics.no_data') }}
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('requests.analytics.dashboard.sections.by_source') }}
                </h3>
                <div v-if="bySource.length" class="mt-4 space-y-3">
                    <div v-for="item in bySource" :key="item.source" class="space-y-1">
                        <div class="flex items-center justify-between gap-3 text-xs text-stone-500 dark:text-neutral-400">
                            <span class="truncate">{{ sourceLabel(item.source) }}</span>
                            <span>{{ formatNumber(item.total) }} · {{ formatPercent(item.rate) }}</span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-stone-200 dark:bg-neutral-700">
                            <div
                                class="h-full rounded-full bg-emerald-500"
                                :style="{ width: `${Math.max((Number(item.total || 0) / maxSourceTotal) * 100, item.total ? 8 : 0)}%` }"
                            />
                        </div>
                        <div class="flex flex-wrap gap-2 text-[11px] text-stone-500 dark:text-neutral-400">
                            <span>{{ $t('requests.analytics.dashboard.labels.converted') }}: {{ formatNumber(item.converted) }}</span>
                            <span>{{ $t('requests.analytics.dashboard.labels.won') }}: {{ formatNumber(item.won) }}</span>
                            <span>{{ $t('requests.analytics.dashboard.labels.lost') }}: {{ formatNumber(item.lost) }}</span>
                        </div>
                    </div>
                </div>
                <div v-else class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.analytics.no_data') }}
                </div>
            </div>
        </div>

        <div v-else class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('requests.analytics.dashboard.sections.by_assignee') }}
                </h3>
                <span class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.analytics.dashboard.sections.by_assignee_note') }}
                </span>
            </div>
            <div v-if="byAssignee.length" class="mt-4 space-y-3">
                <div
                    v-for="item in byAssignee"
                    :key="item.assignee_id ?? 'unassigned'"
                    class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800"
                >
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ item.name || $t('requests.analytics.dashboard.unassigned') }}
                            </div>
                            <div class="mt-1 h-2 w-full max-w-xs overflow-hidden rounded-full bg-stone-200 dark:bg-neutral-700">
                                <div
                                    class="h-full rounded-full bg-indigo-500"
                                    :style="{ width: `${Math.max((Number(item.total || 0) / maxAssigneeTotal) * 100, item.total ? 8 : 0)}%` }"
                                />
                            </div>
                        </div>
                        <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(item.total) }}
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2 text-[11px] text-stone-600 dark:text-neutral-300">
                        <span class="rounded-full bg-stone-200 px-2 py-1 dark:bg-neutral-700">
                            {{ $t('requests.analytics.dashboard.labels.due_today') }}: {{ formatNumber(item.due_today) }}
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
                </div>
            </div>
            <div v-else class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                {{ $t('requests.analytics.no_data') }}
            </div>
        </div>
    </div>
</template>
