<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CardTileTabs from '@/Components/UI/CardTileTabs.vue';
import { humanizeDate } from '@/utils/date';
import {
    serviceRequestAddressLabel,
    serviceRequestCustomerLabel,
    serviceRequestRelationKind,
    serviceRequestRequesterLabel,
    serviceRequestSourceLabel,
    serviceRequestStatusClass,
    serviceRequestStatusLabel,
    serviceRequestTitle,
} from '@/utils/serviceRequestPresentation';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    serviceRequest: {
        type: Object,
        required: true,
    },
    activity: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();

const leftTab = ref('overview');
const rightTab = ref('relations');

const leftTabs = computed(() => ([
    { id: 'overview', label: t('service_requests.show.tabs.left.overview'), initials: 'AP', tone: 'emerald' },
    { id: 'description', label: t('service_requests.show.tabs.left.description'), initials: 'DS', tone: 'sky' },
    { id: 'address', label: t('service_requests.show.tabs.left.address'), initials: 'AD', tone: 'amber' },
]));

const rightTabs = computed(() => ([
    { id: 'relations', label: t('service_requests.show.tabs.right.relations'), initials: 'RL', tone: 'emerald' },
    { id: 'source', label: t('service_requests.show.tabs.right.source'), initials: 'SR', tone: 'sky' },
    { id: 'activity', label: t('service_requests.show.tabs.right.activity'), initials: 'AC', tone: 'cyan' },
]));

const formatDate = (value) => humanizeDate(value);
const requestDate = computed(() => props.serviceRequest?.submitted_at || props.serviceRequest?.created_at);
const relationKind = computed(() => serviceRequestRelationKind(props.serviceRequest));
const requesterLabel = computed(() => serviceRequestRequesterLabel(props.serviceRequest, t));
const addressLabel = computed(() => serviceRequestAddressLabel(props.serviceRequest, t));

const metadataRows = computed(() => {
    const meta = props.serviceRequest?.meta || {};

    return [
        { key: 'request_type', label: t('service_requests.show.request_type'), value: props.serviceRequest?.request_type || null },
        { key: 'service_type', label: t('service_requests.show.service_type'), value: props.serviceRequest?.service_type || null },
        { key: 'urgency', label: t('service_requests.show.urgency'), value: meta.urgency || null },
        { key: 'budget', label: t('service_requests.show.budget'), value: meta.budget !== undefined && meta.budget !== null ? Number(meta.budget).toLocaleString() : null },
        { key: 'serviceable', label: t('service_requests.show.serviceable'), value: meta.is_serviceable === true ? t('service_requests.show.yes') : (meta.is_serviceable === false ? t('service_requests.show.no') : null) },
    ].filter((item) => item.value !== null && item.value !== '');
});

const sourceRows = computed(() => {
    const sourceMeta = props.serviceRequest?.source_meta || {};

    return [
        { key: 'source', label: t('service_requests.show.source'), value: serviceRequestSourceLabel(props.serviceRequest?.source, t) },
        { key: 'channel', label: t('service_requests.show.channel'), value: props.serviceRequest?.channel || t('service_requests.labels.none') },
        { key: 'source_ref', label: t('service_requests.show.source_ref'), value: props.serviceRequest?.source_ref || t('service_requests.labels.none') },
        ...Object.entries(sourceMeta)
            .filter(([, value]) => value !== null && value !== '')
            .map(([key, value]) => ({
                key: `meta-${key}`,
                label: key,
                value: Array.isArray(value) || typeof value === 'object' ? JSON.stringify(value) : String(value),
            })),
    ];
});

const timelineRows = computed(() => ([
    { key: 'submitted', label: t('service_requests.show.timeline.submitted'), value: props.serviceRequest?.submitted_at || props.serviceRequest?.created_at },
    { key: 'accepted', label: t('service_requests.show.timeline.accepted'), value: props.serviceRequest?.accepted_at || null },
    { key: 'completed', label: t('service_requests.show.timeline.completed'), value: props.serviceRequest?.completed_at || null },
    { key: 'cancelled', label: t('service_requests.show.timeline.cancelled'), value: props.serviceRequest?.cancelled_at || null },
]).filter((item) => item.value));

</script>

<template>
    <Head :title="serviceRequestTitle(serviceRequest, t)" />

    <AuthenticatedLayout>
        <div class="space-y-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ serviceRequestTitle(serviceRequest, t) }}
                        </h1>
                        <span
                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold"
                            :class="serviceRequestStatusClass(serviceRequest.status)"
                        >
                            {{ serviceRequestStatusLabel(serviceRequest.status, t) }}
                        </span>
                        <span class="inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-700 dark:bg-neutral-800 dark:text-neutral-300">
                            {{ serviceRequestSourceLabel(serviceRequest.source, t) }}
                        </span>
                    </div>
                    <p class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                        {{ requesterLabel }} · {{ formatDate(requestDate) }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        :href="route('service-requests.index')"
                        class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    >
                        {{ $t('service_requests.actions.back') }}
                    </Link>
                    <Link
                        v-if="serviceRequest.customer"
                        :href="route('customer.show', serviceRequest.customer.id)"
                        class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    >
                        {{ $t('service_requests.actions.open_customer') }}
                    </Link>
                    <Link
                        v-if="serviceRequest.prospect"
                        :href="route('prospects.show', serviceRequest.prospect.id)"
                        class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    >
                        {{ $t('service_requests.actions.open_prospect') }}
                    </Link>
                </div>
            </div>

            <section class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5">
                <article class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('service_requests.show.summary.status') }}</div>
                    <div class="mt-2">
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold" :class="serviceRequestStatusClass(serviceRequest.status)">
                            {{ serviceRequestStatusLabel(serviceRequest.status, t) }}
                        </span>
                    </div>
                </article>
                <article class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('service_requests.show.summary.submitted') }}</div>
                    <div class="mt-2 text-sm font-medium text-stone-800 dark:text-neutral-100">
                        {{ formatDate(requestDate) }}
                    </div>
                </article>
                <article class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('service_requests.show.summary.relation') }}</div>
                    <div class="mt-2 text-sm font-medium text-stone-800 dark:text-neutral-100">
                        {{ $t(`service_requests.relations.${relationKind}`) }}
                    </div>
                </article>
                <article class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('service_requests.show.summary.source') }}</div>
                    <div class="mt-2 text-sm font-medium text-stone-800 dark:text-neutral-100">
                        {{ serviceRequestSourceLabel(serviceRequest.source, t) }}
                    </div>
                </article>
                <article class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('service_requests.show.summary.service') }}</div>
                    <div class="mt-2 text-sm font-medium text-stone-800 dark:text-neutral-100">
                        {{ serviceRequest.service_type || serviceRequest.request_type || $t('service_requests.labels.none') }}
                    </div>
                </article>
            </section>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr),320px]">
                <div class="space-y-4">
                    <section class="overflow-hidden rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <CardTileTabs
                            v-model="leftTab"
                            :tabs="leftTabs"
                            :aria-label="$t('service_requests.show.overview_title')"
                            grid-class="grid-cols-1 sm:grid-cols-3"
                        />
                    </section>

                    <section
                        v-if="leftTab === 'overview'"
                        class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('service_requests.show.overview_title') }}
                        </h2>
                        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('service_requests.show.requester') }}</div>
                                <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">{{ requesterLabel }}</div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('service_requests.show.email') }}</div>
                                <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">{{ serviceRequest.requester_email || $t('service_requests.labels.none') }}</div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('service_requests.show.phone') }}</div>
                                <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">{{ serviceRequest.requester_phone || $t('service_requests.labels.none') }}</div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('service_requests.show.timeline.title') }}</div>
                                <div class="mt-1 space-y-1 text-sm text-stone-800 dark:text-neutral-100">
                                    <div v-for="item in timelineRows" :key="item.key">
                                        {{ item.label }}: {{ formatDate(item.value) }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="metadataRows.length" class="mt-4 border-t border-stone-200 pt-4 dark:border-neutral-700">
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div v-for="row in metadataRows" :key="row.key">
                                    <div class="text-xs uppercase tracking-wide text-stone-400">{{ row.label }}</div>
                                    <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">{{ row.value }}</div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section
                        v-else-if="leftTab === 'description'"
                        class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('service_requests.show.description_title') }}
                        </h2>
                        <p class="mt-3 whitespace-pre-line text-sm text-stone-600 dark:text-neutral-300">
                            {{ serviceRequest.description || $t('service_requests.show.no_description') }}
                        </p>
                    </section>

                    <section
                        v-else
                        class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('service_requests.show.address_title') }}
                        </h2>
                        <p class="mt-3 text-sm text-stone-600 dark:text-neutral-300">
                            {{ addressLabel }}
                        </p>
                    </section>
                </div>

                <div class="space-y-4">
                    <section class="overflow-hidden rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <CardTileTabs
                            v-model="rightTab"
                            :tabs="rightTabs"
                            :aria-label="$t('service_requests.show.relations_title')"
                            grid-class="grid-cols-1 sm:grid-cols-3 xl:grid-cols-1"
                        />
                    </section>

                    <section
                        v-if="rightTab === 'relations'"
                        class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('service_requests.show.relations_title') }}
                        </h2>
                        <div class="mt-3 space-y-4 text-sm text-stone-600 dark:text-neutral-300">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('service_requests.relations.customer') }}</div>
                                <div class="mt-1">
                                    <Link
                                        v-if="serviceRequest.customer"
                                        :href="route('customer.show', serviceRequest.customer.id)"
                                        class="font-medium text-stone-800 hover:text-emerald-700 dark:text-neutral-100 dark:hover:text-emerald-300"
                                    >
                                        {{ serviceRequestCustomerLabel(serviceRequest.customer) }}
                                    </Link>
                                    <span v-else>{{ $t('service_requests.labels.none') }}</span>
                                </div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('service_requests.relations.prospect') }}</div>
                                <div class="mt-1">
                                    <Link
                                        v-if="serviceRequest.prospect"
                                        :href="route('prospects.show', serviceRequest.prospect.id)"
                                        class="font-medium text-stone-800 hover:text-emerald-700 dark:text-neutral-100 dark:hover:text-emerald-300"
                                    >
                                        {{ serviceRequest.prospect.title || serviceRequest.prospect.contact_name || serviceRequest.prospect.service_type || `#${serviceRequest.prospect.id}` }}
                                    </Link>
                                    <span v-else>{{ $t('service_requests.labels.none') }}</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section
                        v-else-if="rightTab === 'source'"
                        class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('service_requests.show.source_title') }}
                        </h2>
                        <div class="mt-3 space-y-3 text-sm text-stone-600 dark:text-neutral-300">
                            <div
                                v-for="row in sourceRows"
                                :key="row.key"
                                class="flex flex-col gap-1 border-b border-stone-100 pb-3 last:border-b-0 last:pb-0 dark:border-neutral-800"
                            >
                                <div class="text-xs uppercase tracking-wide text-stone-400">
                                    {{ row.label }}
                                </div>
                                <div class="break-all text-sm text-stone-800 dark:text-neutral-100">
                                    {{ row.value }}
                                </div>
                            </div>
                        </div>
                    </section>

                    <section
                        v-else
                        class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('service_requests.show.activity_title') }}
                        </h2>
                        <div v-if="activity.length" class="mt-3 space-y-3">
                            <article
                                v-for="entry in activity"
                                :key="entry.id"
                                class="rounded-sm border border-stone-100 bg-stone-50/70 p-3 dark:border-neutral-800 dark:bg-neutral-950"
                            >
                                <div class="flex items-center justify-between gap-2 text-xs uppercase tracking-wide text-stone-400">
                                    <span>{{ entry.user?.name || $t('service_requests.activity.system') }}</span>
                                    <span>{{ formatDate(entry.created_at) }}</span>
                                </div>
                                <p class="mt-2 text-sm text-stone-700 dark:text-neutral-200">
                                    {{ entry.description || entry.action }}
                                </p>
                            </article>
                        </div>
                        <p v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('service_requests.activity.empty') }}
                        </p>
                    </section>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
