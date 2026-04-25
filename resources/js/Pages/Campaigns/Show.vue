<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    campaign: { type: Object, required: true },
    eventStats: { type: Object, default: () => ({}) },
    clickNoConversion: { type: Array, default: () => [] },
    deliveryInsights: { type: Object, default: () => ({}) },
    funnel: { type: Object, default: () => ({}) },
    prospectingDashboard: { type: Object, default: () => ({}) },
    access: { type: Object, default: () => ({}) },
    pulse: { type: Object, default: () => ({}) },
});

const canManage = computed(() => Boolean(props.access?.can_manage));
const canOpenPulseComposer = computed(() => Boolean(props.pulse?.can_open));
const runs = computed(() => props.campaign?.runs || []);
const events = computed(() =>
    Object.entries(props.eventStats || {}).map(([key, value]) => ({ key, value: Number(value || 0) }))
);
const insights = computed(() => props.deliveryInsights || {});
const abInsights = computed(() => {
    const source = insights.value?.ab_assignments || {};
    return {
        a: Number(source.A || 0),
        b: Number(source.B || 0),
        total: Number(source.total || 0),
        splitA: source.split_a_percent ?? null,
        splitB: source.split_b_percent ?? null,
    };
});
const fallbackInsights = computed(() => {
    const source = insights.value?.fallback || {};
    return {
        count: Number(source.count || 0),
        failedCount: Number(source.failed_count || 0),
        rate: source.rate_percent ?? 0,
    };
});
const channelInsights = computed(() => {
    const source = insights.value?.channels || {};

    return Object.entries(source)
        .map(([channel, metrics]) => ({
            channel: String(channel).toUpperCase(),
            targeted: Number(metrics?.targeted || 0),
            sent: Number(metrics?.sent || 0),
            delivered: Number(metrics?.delivered || 0),
            failed: Number(metrics?.failed || 0),
            clicked: Number(metrics?.clicked || 0),
            converted: Number(metrics?.converted || 0),
            fallbackCount: Number(metrics?.fallback_count || 0),
            deliveryRate: Number(metrics?.delivery_rate_percent || 0),
        }))
        .sort((left, right) => left.channel.localeCompare(right.channel));
});
const funnelInsights = computed(() => props.funnel || {});
const funnelStages = computed(() => {
    const source = funnelInsights.value?.stages || {};

    return [
        { key: 'prospects', value: Number(source.prospects || 0), label: t('marketing.campaign_show.funnel.prospects') },
        { key: 'contacted', value: Number(source.contacted || 0), label: t('marketing.campaign_show.funnel.contacted') },
        { key: 'replied', value: Number(source.replied || 0), label: t('marketing.campaign_show.funnel.replied') },
        { key: 'qualified', value: Number(source.qualified || 0), label: t('marketing.campaign_show.funnel.qualified') },
        { key: 'leads', value: Number(source.leads || 0), label: t('marketing.campaign_show.funnel.leads') },
        { key: 'customers', value: Number(source.customers || 0), label: t('marketing.campaign_show.funnel.customers') },
    ];
});
const funnelRates = computed(() => {
    const source = funnelInsights.value?.rates || {};

    return {
        prospectToLead: source.prospect_to_lead_percent,
        leadToCustomer: source.lead_to_customer_percent,
        overallCustomer: source.overall_customer_percent,
    };
});
const prospectingInsights = computed(() => props.prospectingDashboard || {});
const showProspectingDashboard = computed(() => Boolean(
    prospectingInsights.value?.enabled
    || Number(prospectingInsights.value?.summary?.total_batches || 0) > 0
    || Number(prospectingInsights.value?.summary?.total_prospects || 0) > 0
));
const prospectingSummary = computed(() => prospectingInsights.value?.summary || {});
const prospectingRecentBatches = computed(() => Array.isArray(prospectingInsights.value?.recent_batches) ? prospectingInsights.value.recent_batches : []);
const prospectingTopProspects = computed(() => Array.isArray(prospectingInsights.value?.top_prospects) ? prospectingInsights.value.top_prospects : []);
const { t } = useI18n();

const conversionError = ref('');
const conversionBusy = ref(false);

const humanizeValue = (value) => String(value || '')
    .replaceAll('_', ' ')
    .toLowerCase()
    .replace(/\b\w/g, (char) => char.toUpperCase());

const translateWithFallback = (key, fallback) => {
    const translated = t(key);
    return translated === key ? fallback : translated;
};

const campaignTypeLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.campaign_types.${normalized}`, humanizeValue(value));
};

const campaignDirectionLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.campaign_directions.${normalized}`, humanizeValue(value));
};

const scheduleTypeLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.schedule_types.${normalized}`, humanizeValue(value));
};

const badgeClass = (status) => {
    if (status === 'running') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300';
    if (status === 'scheduled') return 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300';
    if (status === 'completed') return 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300';
    if (status === 'failed' || status === 'canceled') return 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300';
    return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
};
const statusLabel = (status) => {
    const normalized = String(status || '').toLowerCase();
    const labels = {
        draft: 'marketing.campaign_status.draft',
        scheduled: 'marketing.campaign_status.scheduled',
        running: 'marketing.campaign_status.running',
        completed: 'marketing.campaign_status.completed',
        failed: 'marketing.campaign_status.failed',
        canceled: 'marketing.campaign_status.canceled',
        active: 'marketing.campaign_status.active',
        inactive: 'marketing.campaign_status.inactive',
    };

    const key = labels[normalized];
    return key ? t(key) : status;
};

const bodyTemplatePreview = (channel) => {
    const body = String(channel?.body_template || '');
    if (String(channel?.channel || '').toUpperCase() === 'EMAIL') {
        return body;
    }

    return body
        .replace(/<\s*br\s*\/?>/gi, '\n')
        .replace(/<\/(p|div)>/gi, '\n')
        .replace(/<[^>]+>/g, '')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
};

const markConverted = async (recipient) => {
    if (!canManage.value || conversionBusy.value) return;

    const conversionType = prompt(t('marketing.campaign_show.prompts.conversion_type'), 'sale');
    if (!conversionType) return;
    const conversionIdRaw = prompt(t('marketing.campaign_show.prompts.conversion_id'));
    if (!conversionIdRaw) return;
    const conversionId = Number(conversionIdRaw);
    if (!Number.isInteger(conversionId) || conversionId <= 0) return;

    conversionBusy.value = true;
    conversionError.value = '';
    try {
        await axios.post(route('campaigns.conversions.store', props.campaign.id), {
            campaign_recipient_id: recipient.id,
            customer_id: recipient.customer_id,
            conversion_type: conversionType,
            conversion_id: conversionId,
        });
        router.reload({
            only: ['campaign', 'eventStats', 'clickNoConversion', 'deliveryInsights', 'funnel'],
            preserveScroll: true,
        });
    } catch (error) {
        conversionError.value = error?.response?.data?.message || error?.message || t('marketing.campaign_show.errors.conversion');
    } finally {
        conversionBusy.value = false;
    }
};

const openProspectingWorkspace = (step = 3) => {
    if (!canManage.value) return;

    if (typeof window !== 'undefined') {
        window.sessionStorage.setItem('campaign-wizard-next-step', String(step));
    }

    router.visit(route('campaigns.edit', props.campaign.id));
};
</script>

<template>
    <Head :title="t('marketing.campaign_show.head_title', { id: campaign.id })" />
    <AuthenticatedLayout>
        <div class="space-y-4">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-green-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ campaign.name }}</h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.campaign_show.labels.type') }}: {{ campaignTypeLabel(campaign.campaign_type || campaign.type) }} | {{ t('marketing.campaign_show.labels.updated_at') }}: {{ humanizeDate(campaign.updated_at) || '-' }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link
                            v-if="canOpenPulseComposer"
                            :href="route('social.composer', { source_type: 'campaign', source_id: campaign.id })"
                            class="rounded-sm border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-semibold text-sky-700 hover:bg-sky-100 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200 dark:hover:bg-sky-500/20"
                        >
                            {{ t('social.composer_manager.actions.publish_with_pulse') }}
                        </Link>
                        <Link :href="route('campaigns.index')" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700">{{ t('marketing.campaign_show.actions.back') }}</Link>
                        <Link v-if="canManage" :href="route('campaigns.edit', campaign.id)" class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700">{{ t('marketing.campaign_show.actions.edit') }}</Link>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_show.cards.status') }}</div>
                        <div class="mt-1">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="badgeClass(campaign.status)">{{ statusLabel(campaign.status) }}</span>
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_show.cards.schedule_type') }}</div>
                        <div class="mt-1 text-sm font-semibold text-stone-700 dark:text-neutral-200">{{ scheduleTypeLabel(campaign.schedule_type) }}</div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_show.cards.active_channels') }}</div>
                        <div class="mt-1 text-sm font-semibold text-stone-700 dark:text-neutral-200">{{ (campaign.channels || []).filter((c) => c.is_enabled).length }}</div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_show.cards.offers') }}</div>
                        <div class="mt-1 text-sm font-semibold text-stone-700 dark:text-neutral-200">{{ (campaign.offers || campaign.products || []).length }}</div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_show.cards.runs') }}</div>
                        <div class="mt-1 text-sm font-semibold text-stone-700 dark:text-neutral-200">{{ runs.length }}</div>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="mb-2 text-sm font-semibold text-stone-800 dark:text-neutral-100">Delivery insights (latest run)</div>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">Latest run</div>
                        <div class="mt-1 text-sm font-semibold text-stone-700 dark:text-neutral-200">
                            {{ insights.latest_run_id ? `#${insights.latest_run_id}` : '-' }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">A/B split</div>
                        <div class="mt-1 text-sm font-semibold text-stone-700 dark:text-neutral-200">
                            A {{ abInsights.a }} / B {{ abInsights.b }}
                        </div>
                        <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            <template v-if="abInsights.total > 0">
                                A {{ abInsights.splitA }}% | B {{ abInsights.splitB }}%
                            </template>
                            <template v-else>
                                No A/B data
                            </template>
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">Holdout recipients</div>
                        <div class="mt-1 text-sm font-semibold text-stone-700 dark:text-neutral-200">
                            {{ Number(insights.holdout_count || 0) }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">Fallback usage</div>
                        <div class="mt-1 text-sm font-semibold text-stone-700 dark:text-neutral-200">
                            {{ fallbackInsights.count }} triggered
                        </div>
                        <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ fallbackInsights.rate }}% of failed ({{ fallbackInsights.failedCount }} failed)
                        </div>
                    </div>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                        <thead>
                            <tr class="text-left text-xs uppercase text-stone-500 dark:text-neutral-400">
                                <th class="px-3 py-2">Channel</th>
                                <th class="px-3 py-2">Targeted</th>
                                <th class="px-3 py-2">Sent*</th>
                                <th class="px-3 py-2">Delivered*</th>
                                <th class="px-3 py-2">Failed</th>
                                <th class="px-3 py-2">Clicked</th>
                                <th class="px-3 py-2">Converted</th>
                                <th class="px-3 py-2">Fallback</th>
                                <th class="px-3 py-2 text-right">Delivery %</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr v-if="channelInsights.length === 0">
                                <td colspan="9" class="px-3 py-6 text-center text-xs text-stone-500 dark:text-neutral-400">
                                    No channel breakdown available.
                                </td>
                            </tr>
                            <tr v-for="channel in channelInsights" :key="`insight-channel-${channel.channel}`">
                                <td class="px-3 py-2 font-semibold text-stone-700 dark:text-neutral-200">{{ channel.channel }}</td>
                                <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ channel.targeted }}</td>
                                <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ channel.sent }}</td>
                                <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ channel.delivered }}</td>
                                <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ channel.failed }}</td>
                                <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ channel.clicked }}</td>
                                <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ channel.converted }}</td>
                                <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ channel.fallbackCount }}</td>
                                <td class="px-3 py-2 text-right font-semibold text-stone-700 dark:text-neutral-200">{{ channel.deliveryRate }}%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                    * `Sent` includes statuses sent/delivered/opened/clicked/converted. `Delivered` includes delivered/opened/clicked/converted.
                </p>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ t('marketing.campaign_show.sections.funnel') }}</h2>
                    <span class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ t('marketing.campaign_show.funnel.direction') }}: {{ campaignDirectionLabel(funnelInsights.direction) }}
                    </span>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <div
                        v-for="stage in funnelStages"
                        :key="`funnel-${stage.key}`"
                        class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800"
                    >
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ stage.label }}</div>
                        <div class="mt-1 text-lg font-semibold text-stone-700 dark:text-neutral-200">{{ stage.value }}</div>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_show.funnel.prospect_to_lead_rate') }}</div>
                        <div class="mt-1 text-sm font-semibold text-stone-700 dark:text-neutral-200">
                            {{ funnelRates.prospectToLead === null ? t('marketing.campaign_show.funnel.not_available') : `${funnelRates.prospectToLead}%` }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_show.funnel.lead_to_customer_rate') }}</div>
                        <div class="mt-1 text-sm font-semibold text-stone-700 dark:text-neutral-200">
                            {{ funnelRates.leadToCustomer === null ? t('marketing.campaign_show.funnel.not_available') : `${funnelRates.leadToCustomer}%` }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_show.funnel.overall_customer_rate') }}</div>
                        <div class="mt-1 text-sm font-semibold text-stone-700 dark:text-neutral-200">
                            {{ funnelRates.overallCustomer === null ? t('marketing.campaign_show.funnel.not_available') : `${funnelRates.overallCustomer}%` }}
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="showProspectingDashboard" class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ t('marketing.campaign_show.sections.prospecting') }}</h2>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.campaign_show.prospecting.direction') }}: {{ campaignDirectionLabel(prospectingInsights.direction) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link
                            v-if="canManage"
                            :href="route('campaigns.edit', campaign.id)"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                            @click.prevent="openProspectingWorkspace()"
                        >
                            {{ t('marketing.campaign_show.actions.open_prospecting_workspace') }}
                        </Link>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_show.prospecting.total_batches') }}</div>
                        <div class="mt-1 text-lg font-semibold text-stone-700 dark:text-neutral-200">{{ Number(prospectingSummary.total_batches || 0) }}</div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_show.prospecting.pending_review_batches') }}</div>
                        <div class="mt-1 text-lg font-semibold text-stone-700 dark:text-neutral-200">{{ Number(prospectingSummary.pending_review_batches || 0) }}</div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_show.prospecting.ready_for_outreach') }}</div>
                        <div class="mt-1 text-lg font-semibold text-stone-700 dark:text-neutral-200">{{ Number(prospectingSummary.ready_for_outreach_prospects || 0) }}</div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_show.prospecting.follow_up_due') }}</div>
                        <div class="mt-1 text-lg font-semibold text-stone-700 dark:text-neutral-200">{{ Number(prospectingSummary.follow_up_due_prospects || 0) }}</div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_show.prospecting.converted_leads') }}</div>
                        <div class="mt-1 text-lg font-semibold text-stone-700 dark:text-neutral-200">{{ Number(prospectingSummary.converted_leads || 0) }}</div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_show.prospecting.do_not_contact') }}</div>
                        <div class="mt-1 text-lg font-semibold text-stone-700 dark:text-neutral-200">{{ Number(prospectingSummary.do_not_contact_prospects || 0) }}</div>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ t('marketing.campaign_show.prospecting.recent_batches') }}</h3>
                        </div>
                        <div v-if="prospectingRecentBatches.length === 0" class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.campaign_show.prospecting.empty_batches') }}
                        </div>
                        <div v-else class="mt-3 space-y-2">
                            <div v-for="batch in prospectingRecentBatches" :key="`show-prospect-batch-${batch.id}`" class="rounded-sm border border-stone-200 bg-white px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ t('marketing.campaign_show.prospecting.batch_label', { number: batch.batch_number }) }}
                                    </div>
                                    <div class="text-stone-500 dark:text-neutral-400">{{ humanizeValue(batch.status) }}</div>
                                </div>
                                <div class="mt-2 grid grid-cols-2 gap-2 text-stone-600 dark:text-neutral-300">
                                    <div>{{ t('marketing.campaign_show.prospecting.accepted') }}: {{ batch.accepted_count }}</div>
                                    <div>{{ t('marketing.campaign_show.prospecting.duplicates') }}: {{ batch.duplicate_count }}</div>
                                    <div>{{ t('marketing.campaign_show.prospecting.blocked') }}: {{ batch.blocked_count }}</div>
                                    <div>{{ t('marketing.campaign_show.prospecting.leads') }}: {{ batch.lead_count }}</div>
                                </div>
                                <div class="mt-2 text-stone-500 dark:text-neutral-400">
                                    {{ batch.source_reference || batch.source_type || '-' }} | {{ humanizeDate(batch.created_at) || '-' }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ t('marketing.campaign_show.prospecting.top_prospects') }}</h3>
                        </div>
                        <div v-if="prospectingTopProspects.length === 0" class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.campaign_show.prospecting.empty_prospects') }}
                        </div>
                        <div v-else class="mt-3 space-y-2">
                            <div v-for="prospect in prospectingTopProspects" :key="`show-top-prospect-${prospect.id}`" class="rounded-sm border border-stone-200 bg-white px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-stone-800 dark:text-neutral-100">
                                            {{ prospect.contact_name || prospect.company_name || prospect.email || prospect.phone || `#${prospect.id}` }}
                                        </div>
                                        <div class="mt-1 text-stone-500 dark:text-neutral-400">
                                            {{ prospect.company_name || prospect.email || prospect.phone || '-' }}
                                        </div>
                                        <div class="mt-1 text-stone-500 dark:text-neutral-400">
                                            {{ t('marketing.campaign_show.prospecting.batch_label', { number: prospect.batch?.batch_number || '-' }) }}
                                        </div>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <div class="font-semibold text-stone-800 dark:text-neutral-100">{{ prospect.priority_score ?? '-' }}</div>
                                        <div class="mt-1 text-stone-500 dark:text-neutral-400">{{ humanizeValue(prospect.status) }}</div>
                                    </div>
                                </div>
                                <div class="mt-2 text-stone-500 dark:text-neutral-400">
                                    {{ t('marketing.campaign_show.prospecting.fit_intent', { fit: prospect.fit_score ?? '-', intent: prospect.intent_score ?? '-' }) }}
                                </div>
                                <div v-if="prospect.converted_lead" class="mt-2">
                                    <Link :href="route('prospects.show', prospect.converted_lead.id)" class="text-xs font-medium text-emerald-700 underline underline-offset-2 dark:text-emerald-300">
                                        {{ t('marketing.campaign_show.prospecting.open_lead') }}
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ t('marketing.campaign_show.sections.channels') }}</h2>
                    <div class="mt-3 space-y-2">
                        <div v-for="channel in campaign.channels || []" :key="`channel-${channel.id || channel.channel}`" class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="flex items-center justify-between">
                                <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">{{ channel.channel }}</div>
                                <span class="text-xs" :class="channel.is_enabled ? 'text-emerald-700 dark:text-emerald-300' : 'text-stone-500 dark:text-neutral-400'">
                                    {{ channel.is_enabled ? t('marketing.campaign_show.channel.active') : t('marketing.campaign_show.channel.inactive') }}
                                </span>
                            </div>
                            <div v-if="channel.subject_template" class="mt-2 text-xs text-stone-600 dark:text-neutral-300">{{ t('marketing.campaign_show.channel.subject') }}: {{ channel.subject_template }}</div>
                            <div v-if="channel.title_template" class="mt-1 text-xs text-stone-600 dark:text-neutral-300">{{ t('marketing.campaign_show.channel.title') }}: {{ channel.title_template }}</div>
                            <div v-if="channel.body_template" class="mt-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                <div v-if="String(channel.channel || '').toUpperCase() === 'EMAIL'" class="leading-5" v-html="bodyTemplatePreview(channel)"></div>
                                <div v-else class="whitespace-pre-wrap leading-5">{{ bodyTemplatePreview(channel) }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ t('marketing.campaign_show.sections.events') }}</h2>
                    <div class="mt-3 space-y-2">
                        <div v-if="events.length === 0" class="rounded-sm border border-dashed border-stone-200 px-3 py-6 text-center text-xs text-stone-500 dark:border-neutral-700 dark:text-neutral-400">
                            {{ t('marketing.campaign_show.empty.events') }}
                        </div>
                        <div v-for="event in events" :key="`event-${event.key}`" class="flex items-center justify-between rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                            <span class="text-stone-700 dark:text-neutral-200">{{ event.key }}</span>
                            <span class="font-semibold text-stone-700 dark:text-neutral-200">{{ event.value }}</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ t('marketing.campaign_show.sections.recent_runs') }}</h2>
                </div>
                <div class="mt-3 space-y-2">
                    <div v-if="runs.length === 0" class="rounded-sm border border-dashed border-stone-200 px-3 py-6 text-center text-xs text-stone-500 dark:border-neutral-700 dark:text-neutral-400">
                        {{ t('marketing.campaign_show.empty.runs') }}
                    </div>
                    <div v-for="run in runs" :key="`run-${run.id}`" class="flex flex-wrap items-center justify-between gap-2 rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="space-x-2">
                            <span class="font-semibold text-stone-700 dark:text-neutral-200">#{{ run.id }}</span>
                            <span class="text-stone-600 dark:text-neutral-300">{{ run.trigger_type }}</span>
                            <span class="inline-flex rounded-full px-2 py-0.5 font-semibold" :class="badgeClass(run.status)">{{ statusLabel(run.status) }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-stone-500 dark:text-neutral-400">{{ humanizeDate(run.created_at) || '-' }}</span>
                            <Link :href="route('campaign-runs.export', run.id)" class="rounded-sm border border-stone-200 bg-white px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                {{ t('marketing.campaign_show.actions.export_csv') }}
                            </Link>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ t('marketing.campaign_show.sections.clicks_no_conversion') }}</h2>
                <p v-if="conversionError" class="mt-2 text-xs text-rose-600">{{ conversionError }}</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                        <thead>
                            <tr class="text-left text-xs uppercase text-stone-500 dark:text-neutral-400">
                                <th class="px-3 py-2">{{ t('marketing.campaign_show.table.customer') }}</th>
                                <th class="px-3 py-2">{{ t('marketing.campaign_show.table.channel') }}</th>
                                <th class="px-3 py-2">{{ t('marketing.campaign_show.table.destination') }}</th>
                                <th class="px-3 py-2">{{ t('marketing.campaign_show.table.clicked_at') }}</th>
                                <th class="px-3 py-2 text-right">{{ t('marketing.campaign_show.table.action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr v-if="clickNoConversion.length === 0">
                                <td colspan="5" class="px-3 py-6 text-center text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_show.empty.pending_clicks') }}</td>
                            </tr>
                            <tr v-for="row in clickNoConversion" :key="`click-${row.id}`">
                                <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ row.customer?.company_name || `${row.customer?.first_name || ''} ${row.customer?.last_name || ''}`.trim() || '-' }}</td>
                                <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ row.channel }}</td>
                                <td class="px-3 py-2 text-stone-600 dark:text-neutral-300">{{ row.destination || '-' }}</td>
                                <td class="px-3 py-2 text-stone-600 dark:text-neutral-300">{{ humanizeDate(row.clicked_at) || '-' }}</td>
                                <td class="px-3 py-2 text-right">
                                    <button v-if="canManage" type="button" :disabled="conversionBusy" @click="markConverted(row)" class="rounded-sm border border-transparent bg-green-600 px-2 py-1 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60">
                                        {{ t('marketing.campaign_show.actions.mark_conversion') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
