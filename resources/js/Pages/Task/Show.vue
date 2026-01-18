<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { humanizeDate } from '@/utils/date';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    task: {
        type: Object,
        required: true,
    },
    canManage: {
        type: Boolean,
        default: false,
    },
});

const { t } = useI18n();

const statusClass = (status) => {
    switch (status) {
        case 'todo':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
        case 'in_progress':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
        case 'done':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        default:
            return 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
    }
};
const timingStatusClass = (status) => {
    switch (status) {
        case 'early':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300';
        case 'late':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300';
        case 'on_time':
            return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
        default:
            return 'bg-stone-200 text-stone-600 dark:bg-neutral-700 dark:text-neutral-400';
    }
};

const statusLabel = computed(() => {
    const status = props.task?.status;
    if (!status) {
        return t('tasks.labels.unknown_status');
    }
    const translated = t(`tasks.status.${status}`);
    return translated || status;
});
const statusLabelFor = (status) => {
    if (!status) {
        return '';
    }
    const key = `tasks.status.${status}`;
    const translated = t(key);
    return translated === key ? status : translated;
};
const dueLabel = computed(() => humanizeDate(props.task?.due_date) || t('tasks.labels.no_due_date'));
const assigneeLabel = computed(() => props.task?.assignee?.user?.name || t('tasks.labels.unassigned'));
const timingStatusLabel = (status) => {
    if (!status) {
        return '';
    }
    const key = `tasks.timing.${status}`;
    const label = t(key);
    return label === key ? status : label;
};
const completionReasonLabel = (reason) => {
    if (!reason) {
        return '';
    }
    const key = `tasks.reasons.${reason}`;
    const label = t(key);
    return label === key ? reason : label;
};
const timingStatus = computed(() => props.task?.timing_status);
const completedLabel = computed(() => humanizeDate(props.task?.completed_at));
const histories = computed(() => (Array.isArray(props.task?.status_histories) ? props.task.status_histories : []));
const historyActionLabel = (action) => {
    if (!action) {
        return '';
    }
    const key = `tasks.history.actions.${action}`;
    const label = t(key);
    return label === key ? action : label;
};
const historyActorLabel = (entry) => {
    const name = entry?.user?.name;
    return name ? t('tasks.history.by', { name }) : t('tasks.history.system');
};

const location = computed(() => props.task?.location || null);
const locationAddress = computed(() => {
    if (!location.value) {
        return null;
    }
    if (location.value.address) {
        return location.value.address;
    }
    const parts = [
        location.value.street1,
        location.value.street2,
        location.value.city,
        location.value.state,
        location.value.zip,
        location.value.country,
    ].filter(Boolean);
    return parts.length ? parts.join(', ') : null;
});

const mapUrl = computed(() =>
    locationAddress.value
        ? `https://www.google.com/maps?q=${encodeURIComponent(locationAddress.value)}&output=embed`
        : null
);

const mapLink = computed(() =>
    locationAddress.value
        ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(locationAddress.value)}`
        : null
);
</script>

<template>
    <Head :title="$t('tasks.details.page_title')" />
    <AuthenticatedLayout>
        <div class="max-w-4xl mx-auto space-y-4 rise-stagger">
            <div v-if="mapUrl" class="overflow-hidden bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <iframe
                    :src="mapUrl"
                    class="w-full h-56"
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                ></iframe>
                <div class="p-4">
                    <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('tasks.details.address_label') }}</p>
                    <p class="mt-2 text-sm text-stone-800 dark:text-neutral-100">{{ locationAddress }}</p>
                    <a
                        v-if="mapLink"
                        :href="mapLink"
                        target="_blank"
                        rel="noopener"
                        class="mt-3 inline-flex text-xs font-medium text-emerald-600 hover:text-emerald-700"
                    >
                        {{ $t('tasks.details.open_in_maps') }}
                    </a>
                </div>
            </div>

            <div v-else class="bg-white border border-stone-200 rounded-sm p-4 text-sm text-stone-500 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-400">
                {{ $t('tasks.details.address_unavailable') }}
            </div>

            <div class="p-5 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ task.title || $t('tasks.labels.task') }}
                        </h1>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                            <span class="py-1 px-2 rounded-full font-medium" :class="statusClass(task.status)">
                                {{ statusLabel }}
                            </span>
                            <span v-if="timingStatus" class="py-1 px-2 rounded-full font-medium" :class="timingStatusClass(timingStatus)">
                                {{ timingStatusLabel(timingStatus) }}
                            </span>
                            <span>{{ $t('tasks.details.due') }}: {{ dueLabel }}</span>
                            <span>{{ $t('tasks.details.assignee') }}: {{ assigneeLabel }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link
                            :href="route('pipeline.timeline', { entityType: 'task', entityId: task.id })"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700"
                        >
                            {{ $t('tasks.details.timeline') }}
                        </Link>
                        <Link
                            v-if="canManage"
                            :href="route('task.index')"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700"
                        >
                            {{ $t('tasks.details.back_to_tasks') }}
                        </Link>
                    </div>
                </div>
            </div>

            <div class="p-5 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <h2 class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('tasks.details.description') }}</h2>
                <p class="mt-2 text-sm text-stone-700 dark:text-neutral-300">
                    {{ task.description || $t('tasks.details.no_description') }}
                </p>
            </div>

            <div class="p-5 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <h2 class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('tasks.details.execution') }}</h2>
                <div class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
                    <div>
                        <div class="text-xs uppercase text-stone-400 dark:text-neutral-500">
                            {{ $t('tasks.details.due') }}
                        </div>
                        <div class="mt-1 text-stone-700 dark:text-neutral-200">
                            {{ dueLabel }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-stone-400 dark:text-neutral-500">
                            {{ $t('tasks.details.completed_at') }}
                        </div>
                        <div class="mt-1 text-stone-700 dark:text-neutral-200">
                            {{ completedLabel || $t('tasks.details.not_completed') }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-stone-400 dark:text-neutral-500">
                            {{ $t('tasks.details.completion_reason') }}
                        </div>
                        <div class="mt-1 text-stone-700 dark:text-neutral-200">
                            {{ completionReasonLabel(task.completion_reason) || $t('tasks.details.no_reason') }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="p-5 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <h2 class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('tasks.details.materials') }}</h2>
                <div v-if="task.materials?.length" class="mt-3 space-y-2">
                    <div
                        v-for="material in task.materials"
                        :key="material.id || material.label"
                        class="flex items-center justify-between text-sm text-stone-700 dark:text-neutral-300"
                    >
                        <span>{{ material.label || material.product?.name || $t('tasks.details.material_fallback') }}</span>
                        <span>{{ material.quantity || 0 }} {{ material.unit || '' }}</span>
                    </div>
                </div>
                <p v-else class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('tasks.details.no_materials') }}
                </p>
            </div>

            <div class="p-5 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <h2 class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('tasks.history.title') }}</h2>
                <div v-if="histories.length" class="mt-3 space-y-3">
                    <div
                        v-for="entry in histories"
                        :key="entry.id"
                        class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="font-semibold">
                                {{ historyActionLabel(entry.action) }}
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ humanizeDate(entry.created_at) }}
                            </div>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                            <span>{{ historyActorLabel(entry) }}</span>
                            <span v-if="entry.from_status || entry.to_status">
                                {{ statusLabelFor(entry.from_status) || entry.from_status || '-' }}
                                →
                                {{ statusLabelFor(entry.to_status) || entry.to_status || '-' }}
                            </span>
                            <span v-if="entry.timing_status" class="rounded-full px-2 py-0.5 font-semibold"
                                :class="timingStatusClass(entry.timing_status)">
                                {{ timingStatusLabel(entry.timing_status) }}
                            </span>
                            <span v-if="entry.reason_code" class="rounded-full px-2 py-0.5 font-semibold bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300">
                                {{ completionReasonLabel(entry.reason_code) || entry.reason_code }}
                            </span>
                        </div>
                        <p v-if="entry.note" class="mt-2 text-xs text-stone-600 dark:text-neutral-300">
                            {{ entry.note }}
                        </p>
                    </div>
                </div>
                <p v-else class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('tasks.history.empty') }}
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
