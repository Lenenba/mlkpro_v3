<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    campaigns: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
    prospectProviderSummary: {
        type: Object,
        default: () => ({}),
    },
    enums: {
        type: Object,
        default: () => ({}),
    },
    access: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const filterForm = reactive({
    search: props.filters?.search || '',
    status: props.filters?.status || '',
    type: props.filters?.type || '',
});
const isFiltering = ref(false);

const rows = computed(() => props.campaigns?.data || []);
const canManage = computed(() => Boolean(props.access?.can_manage));
const providerSummary = computed(() => ({
    configured: Number(props.prospectProviderSummary?.configured || 0),
    connected: Number(props.prospectProviderSummary?.connected || 0),
    attention: Number(props.prospectProviderSummary?.attention || 0),
}));

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
    if (!normalized) {
        return '-';
    }

    return translateWithFallback(`marketing.campaign_types.${normalized}`, humanizeValue(value));
};

const statusOptions = computed(() => [
    { value: '', label: t('marketing.campaign_index.status_all') },
    ...((props.enums?.statuses || []).map((status) => ({
        value: status,
        label: statusLabel(status),
    }))),
]);

const typeOptions = computed(() => [
    { value: '', label: t('marketing.campaign_index.type_all') },
    ...((props.enums?.types || []).map((type) => ({
        value: type,
        label: campaignTypeLabel(type),
    }))),
]);

let filterTimeout = null;

const applyFilters = () => {
    const payload = {
        search: filterForm.search,
        status: filterForm.status,
        type: filterForm.type,
    };

    Object.keys(payload).forEach((key) => {
        if (payload[key] === '' || payload[key] === null || payload[key] === undefined) {
            delete payload[key];
        }
    });

    isFiltering.value = true;
    router.get(route('campaigns.index'), payload, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onFinish: () => {
            isFiltering.value = false;
        },
    });
};

watch(
    () => [filterForm.search, filterForm.status, filterForm.type],
    () => {
        if (filterTimeout) {
            clearTimeout(filterTimeout);
        }
        filterTimeout = setTimeout(() => applyFilters(), 280);
    }
);

onBeforeUnmount(() => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }
});

const formatDate = (value) => humanizeDate(value) || '-';
const statusLabel = (status) => {
    const normalized = String(status || '').toLowerCase();
    const labels = {
        draft: 'marketing.campaign_status.draft',
        scheduled: 'marketing.campaign_status.scheduled',
        running: 'marketing.campaign_status.running',
        completed: 'marketing.campaign_status.completed',
        failed: 'marketing.campaign_status.failed',
        canceled: 'marketing.campaign_status.canceled',
    };

    const key = labels[normalized];
    return key ? t(key) : status;
};

const statusBadgeClass = (status) => {
    if (status === 'running') {
        return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300';
    }
    if (status === 'scheduled') {
        return 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300';
    }
    if (status === 'completed') {
        return 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300';
    }
    if (status === 'failed' || status === 'canceled') {
        return 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300';
    }
    return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
};
</script>

<template>
    <Head :title="t('marketing.campaign_index.head_title')" />

    <AuthenticatedLayout>
        <div class="space-y-4">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-green-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="inline-flex items-center gap-2 text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            <svg class="size-5 text-green-600 dark:text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 10l9-6 9 6-9 6-9-6z" />
                                <path d="M3 17l9 6 9-6" />
                                <path d="M3 17V10" />
                                <path d="M21 17V10" />
                            </svg>
                            <span>{{ t('marketing.campaign_index.page_title') }}</span>
                        </h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.campaign_index.page_description') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Link :href="route('campaigns.prospect-providers.manage')">
                            <SecondaryButton type="button">
                                {{ t('marketing.campaign_index.actions.manage_prospect_providers') }}
                            </SecondaryButton>
                        </Link>
                        <template v-if="canManage">
                        <Link :href="route('campaigns.templates.manage')">
                            <SecondaryButton type="button">
                                {{ t('marketing.campaign_index.actions.manage_templates') }}
                            </SecondaryButton>
                        </Link>
                        <Link :href="route('campaigns.create')">
                            <PrimaryButton>
                                <span class="inline-flex items-center gap-1.5">
                                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M5 12h14" />
                                        <path d="M12 5v14" />
                                    </svg>
                                    <span>{{ t('marketing.campaign_index.new_campaign') }}</span>
                                </span>
                            </PrimaryButton>
                        </Link>
                        </template>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_index.stats.total') }}</div>
                        <div class="mt-1 text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ stats.total || 0 }}</div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_index.stats.draft') }}</div>
                        <div class="mt-1 text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ stats.draft || 0 }}</div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_index.stats.scheduled') }}</div>
                        <div class="mt-1 text-xl font-semibold text-sky-700 dark:text-sky-300">{{ stats.scheduled || 0 }}</div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_index.stats.running') }}</div>
                        <div class="mt-1 text-xl font-semibold text-emerald-700 dark:text-emerald-300">{{ stats.running || 0 }}</div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_index.stats.completed') }}</div>
                        <div class="mt-1 text-xl font-semibold text-indigo-700 dark:text-indigo-300">{{ stats.completed || 0 }}</div>
                    </div>
                </div>

                <div class="mt-4 rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ t('marketing.campaign_index.providers.title') }}
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('marketing.campaign_index.providers.description') }}
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <span class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                                {{ t('marketing.campaign_index.providers.configured', { count: providerSummary.configured }) }}
                            </span>
                            <span class="rounded-sm border border-emerald-200 bg-emerald-50 px-2 py-1 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
                                {{ t('marketing.campaign_index.providers.connected', { count: providerSummary.connected }) }}
                            </span>
                            <span class="rounded-sm border border-amber-200 bg-amber-50 px-2 py-1 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300">
                                {{ t('marketing.campaign_index.providers.attention', { count: providerSummary.attention }) }}
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <FloatingInput
                        v-model="filterForm.search"
                        :label="t('marketing.campaign_index.filters.search')"
                    />
                    <FloatingSelect
                        v-model="filterForm.status"
                        :label="t('marketing.campaign_index.filters.status')"
                        :options="statusOptions"
                        option-value="value"
                        option-label="label"
                    />
                    <FloatingSelect
                        v-model="filterForm.type"
                        :label="t('marketing.campaign_index.filters.type')"
                        :options="typeOptions"
                        option-value="value"
                        option-label="label"
                    />
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                        <thead>
                            <tr class="text-left text-xs uppercase text-stone-500 dark:text-neutral-400">
                                <th class="px-4 py-3 font-medium">{{ t('marketing.campaign_index.table.campaign') }}</th>
                                <th class="px-4 py-3 font-medium">{{ t('marketing.campaign_index.table.type') }}</th>
                                <th class="px-4 py-3 font-medium">{{ t('marketing.campaign_index.table.status') }}</th>
                                <th class="px-4 py-3 font-medium">{{ t('marketing.campaign_index.table.channels') }}</th>
                                <th class="px-4 py-3 font-medium">{{ t('marketing.campaign_index.table.runs') }}</th>
                                <th class="px-4 py-3 font-medium">{{ t('marketing.campaign_index.table.recipients') }}</th>
                                <th class="px-4 py-3 font-medium">{{ t('marketing.campaign_index.table.updated_at') }}</th>
                                <th class="px-4 py-3 font-medium text-right">{{ t('marketing.campaign_index.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <template v-if="isFiltering">
                                <tr v-for="row in 6" :key="`campaign-skeleton-${row}`">
                                    <td v-for="col in 8" :key="`campaign-skeleton-${row}-${col}`" class="px-4 py-3">
                                        <div class="h-3 w-full animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!isFiltering && rows.length === 0">
                                <td colspan="8" class="px-4 py-8 text-center text-stone-500 dark:text-neutral-400">
                                    {{ t('marketing.campaign_index.no_campaign') }}
                                </td>
                            </tr>
                            <tr v-for="campaign in rows" v-show="!isFiltering" :key="campaign.id" class="text-stone-700 dark:text-neutral-200">
                                <td class="px-4 py-3">
                                    <div class="font-semibold">{{ campaign.name }}</div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        #{{ campaign.id }}
                                    </div>
                                </td>
                                <td class="px-4 py-3">{{ campaignTypeLabel(campaign.campaign_type || campaign.type) }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusBadgeClass(campaign.status)">
                                        {{ statusLabel(campaign.status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        <span
                                            v-for="channel in campaign.channels || []"
                                            :key="`c-${campaign.id}-${channel.channel}`"
                                            class="rounded-sm border border-stone-200 bg-stone-50 px-1.5 py-0.5 text-xs dark:border-neutral-700 dark:bg-neutral-800"
                                        >
                                            {{ channel.channel }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">{{ campaign.runs_count || 0 }}</td>
                                <td class="px-4 py-3">{{ campaign.recipients_count || 0 }}</td>
                                <td class="px-4 py-3">{{ formatDate(campaign.updated_at) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        <Link
                                            :href="route('campaigns.show', campaign.id)"
                                            class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:hover:bg-neutral-700"
                                        >
                                            {{ t('marketing.campaign_index.actions.view') }}
                                        </Link>
                                        <Link
                                            v-if="canManage"
                                            :href="route('campaigns.edit', campaign.id)"
                                            class="rounded-sm border border-transparent bg-green-600 px-2 py-1 text-xs text-white hover:bg-green-700"
                                        >
                                            {{ t('marketing.campaign_index.actions.edit') }}
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="campaigns?.next_page_url || campaigns?.prev_page_url" class="flex items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-xs text-stone-500 dark:border-neutral-700 dark:text-neutral-400">
                    <div>
                        {{ t('marketing.campaign_index.page', { page: campaigns.current_page || 1 }) }}
                    </div>
                    <div class="flex items-center gap-2">
                        <Link
                            v-if="campaigns.prev_page_url"
                            :href="campaigns.prev_page_url"
                            class="rounded-sm border border-stone-200 bg-white px-2 py-1 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:hover:bg-neutral-700"
                        >
                            {{ t('marketing.common.previous') }}
                        </Link>
                        <Link
                            v-if="campaigns.next_page_url"
                            :href="campaigns.next_page_url"
                            class="rounded-sm border border-stone-200 bg-white px-2 py-1 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:hover:bg-neutral-700"
                        >
                            {{ t('marketing.common.next') }}
                        </Link>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
