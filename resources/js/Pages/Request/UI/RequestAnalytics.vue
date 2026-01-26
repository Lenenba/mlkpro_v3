<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    analytics: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const formatDate = (value) => humanizeDate(value);
const statusLabel = (status) => {
    switch (status) {
        case 'REQ_NEW':
            return t('requests.status.new');
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

const statusClass = (status) => {
    switch (status) {
        case 'REQ_NEW':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'REQ_CONTACTED':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300';
        case 'REQ_QUALIFIED':
            return 'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/10 dark:text-indigo-300';
        case 'REQ_QUOTE_SENT':
        case 'REQ_CONVERTED':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-300';
        case 'REQ_WON':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'REQ_LOST':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300';
        default:
            return 'bg-stone-100 text-stone-800 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const windowDays = computed(() => props.analytics?.window_days ?? 30);
const avgFirstResponse = computed(() => props.analytics?.avg_first_response_hours ?? null);
const conversionRate = computed(() => props.analytics?.conversion_rate ?? 0);
const totalLeads = computed(() => props.analytics?.total ?? 0);
const riskLeads = computed(() => props.analytics?.risk_leads ?? []);
const bySource = computed(() => props.analytics?.conversion_by_source ?? []);
const leadForm = computed(() => props.analytics?.lead_form ?? {});
const formWindow = computed(() => leadForm.value?.window_days ?? windowDays.value);
const formViews = computed(() => leadForm.value?.views ?? 0);
const formUniqueViews = computed(() => leadForm.value?.unique_views ?? 0);
const formSubmits = computed(() => leadForm.value?.submits ?? 0);
const formConversion = computed(() => leadForm.value?.conversion_rate ?? 0);
const formLastView = computed(() => leadForm.value?.last_view_at ?? null);
const formLastSubmit = computed(() => leadForm.value?.last_submit_at ?? null);

const formatHours = (value) => {
    if (value === null || value === undefined) {
        return '-';
    }
    return `${Number(value).toFixed(1)}h`;
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
const riskLabel = (days) => {
    if (days >= 14) {
        return t('requests.analytics.risk_14');
    }
    return t('requests.analytics.risk_7');
};
const riskClass = (days) => (days >= 14
    ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'
    : 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300');
</script>

<template>
    <div class="space-y-4 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('requests.analytics.title', { days: windowDays }) }}
                </h2>
                <p class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.analytics.subtitle') }}
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('requests.analytics.first_response') }}</div>
                <div class="mt-2 text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ formatHours(avgFirstResponse) }}</div>
                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.analytics.first_response_note') }}
                </div>
            </div>
            <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('requests.analytics.conversion_rate') }}</div>
                <div class="mt-2 text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ conversionRate }}%</div>
                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.analytics.conversion_note') }}
                </div>
            </div>
            <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('requests.analytics.total_leads') }}</div>
                <div class="mt-2 text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ totalLeads }}</div>
                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.analytics.total_note', { days: windowDays }) }}
                </div>
            </div>
        </div>

        <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('requests.analytics.form.title') }}
                </h3>
                <span class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.analytics.form.window', { days: formWindow }) }}
                </span>
            </div>
            <div v-if="formViews === 0 && formSubmits === 0" class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                {{ $t('requests.analytics.form.empty') }}
            </div>
            <div v-else class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
                <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('requests.analytics.form.views') }}</div>
                    <div class="mt-2 text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ formViews }}</div>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('requests.analytics.form.unique', { count: formUniqueViews }) }}
                    </div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('requests.analytics.form.submits') }}</div>
                    <div class="mt-2 text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ formSubmits }}</div>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('requests.analytics.form.submits_note') }}
                    </div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('requests.analytics.form.conversion') }}</div>
                    <div class="mt-2 text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ formConversion }}%</div>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('requests.analytics.form.conversion_note') }}
                    </div>
                </div>
            </div>
            <div v-if="formLastView || formLastSubmit" class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                <span v-if="formLastView">{{ $t('requests.analytics.form.last_view') }}: {{ formatDate(formLastView) }}</span>
                <span v-if="formLastView && formLastSubmit"> · </span>
                <span v-if="formLastSubmit">{{ $t('requests.analytics.form.last_submit') }}: {{ formatDate(formLastSubmit) }}</span>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr),minmax(0,1fr)]">
            <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('requests.analytics.by_source') }}
                </h3>
                <div v-if="bySource.length" class="mt-4 space-y-3">
                    <div v-for="item in bySource" :key="item.source" class="space-y-1">
                        <div class="flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                            <span>{{ sourceLabel(item.source) }}</span>
                            <span>{{ item.won }}/{{ item.total }} · {{ item.rate }}%</span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-stone-200 dark:bg-neutral-700">
                            <div class="h-full rounded-full bg-emerald-500" :style="{ width: `${item.rate}%` }"></div>
                        </div>
                    </div>
                </div>
                <div v-else class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.analytics.no_data') }}
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('requests.analytics.risk_title') }}
                </h3>
                <div v-if="riskLeads.length" class="mt-3 space-y-2">
                    <div
                        v-for="lead in riskLeads"
                        :key="lead.id"
                        class="flex flex-wrap items-center justify-between gap-2 rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                    >
                        <div class="min-w-0">
                            <Link :href="route('request.show', lead.id)" class="text-sm font-semibold text-stone-800 hover:text-emerald-600 dark:text-neutral-200">
                                {{ lead.title || lead.service_type || $t('requests.labels.request_number', { id: lead.id }) }}
                            </Link>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                <span class="rounded-full px-2 py-0.5" :class="statusClass(lead.status)">
                                    {{ statusLabel(lead.status) }}
                                </span>
                                <span>{{ lead.customer_name || $t('requests.labels.unknown_customer') }}</span>
                                <span>{{ lead.assignee_name || $t('requests.labels.unassigned') }}</span>
                            </div>
                        </div>
                        <div class="flex flex-col items-end gap-1 text-[11px] text-stone-500 dark:text-neutral-400">
                            <span>{{ $t('requests.analytics.last_activity') }}: {{ formatDate(lead.last_activity_at) }}</span>
                            <span v-if="lead.next_follow_up_at">
                                {{ $t('requests.analytics.follow_up') }}: {{ formatDate(lead.next_follow_up_at) }}
                            </span>
                            <span class="rounded-full px-2 py-0.5" :class="riskClass(lead.days_since_activity)">
                                {{ riskLabel(lead.days_since_activity) }}
                            </span>
                        </div>
                    </div>
                </div>
                <div v-else class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.analytics.no_risk') }}
                </div>
            </div>
        </div>
    </div>
</template>
