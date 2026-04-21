<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import AdminPaginationLinks from '@/Components/DataTable/AdminPaginationLinks.vue';
import { crmButtonClass, crmSegmentedControlButtonClass, crmSegmentedControlClass } from '@/utils/crmButtonStyles';
import { humanizeDate } from '@/utils/date';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    notification_history: {
        type: Object,
        required: true,
    },
    history_filters: {
        type: Object,
        default: () => ({}),
    },
    history_stats: {
        type: Object,
        default: () => ({}),
    },
    history_type_options: {
        type: Array,
        default: () => [],
    },
    history_per_page_options: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();

const filterForm = useForm({
    status: props.history_filters?.status ?? 'all',
    type: props.history_filters?.type ?? '',
    per_page: String(props.history_filters?.per_page ?? 10),
});

const notificationItems = computed(() => (Array.isArray(props.notification_history?.data) ? props.notification_history.data : []));
const notificationLinks = computed(() => props.notification_history?.links || []);

const statusCards = computed(() => ([
    { id: 'all', label: t('notifications_center.stats.all'), count: Number(props.history_stats?.all || 0) },
    { id: 'unread', label: t('notifications_center.stats.unread'), count: Number(props.history_stats?.unread || 0) },
    { id: 'read', label: t('notifications_center.stats.read'), count: Number(props.history_stats?.read || 0) },
    { id: 'archived', label: t('notifications_center.stats.archived'), count: Number(props.history_stats?.archived || 0) },
]));

const typeSelectOptions = computed(() => [
    { id: '', name: t('notifications_center.filters.type_all') },
    ...(props.history_type_options || []).map((option) => ({
        id: String(option.id || ''),
        name: `${typeLabel(String(option.id || 'system'))} (${Number(option.count || 0)})`,
    })),
]);

const perPageSelectOptions = computed(() =>
    (props.history_per_page_options || []).map((value) => ({
        id: String(value),
        name: String(value),
    }))
);

const applyFilters = () => {
    const payload = {
        status: filterForm.status,
        type: filterForm.type,
        per_page: filterForm.per_page,
    };

    Object.keys(payload).forEach((key) => {
        if (payload[key] === '' || payload[key] === null || payload[key] === undefined) {
            delete payload[key];
        }
    });

    router.get(route('notifications.index'), payload, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

watch(() => [filterForm.type, filterForm.per_page], () => {
    applyFilters();
});

const clearFilters = () => {
    filterForm.status = 'all';
    filterForm.type = '';
    filterForm.per_page = '10';
    applyFilters();
};

const setStatus = (status) => {
    if (!status || filterForm.status === status) {
        return;
    }

    filterForm.status = status;
    applyFilters();
};

const postAction = (routeName, notificationId) => {
    router.post(route(routeName, notificationId), {}, {
        preserveScroll: true,
        preserveState: true,
    });
};

const markRead = (notificationId) => postAction('notifications.read', notificationId);
const archiveNotification = (notificationId) => postAction('notifications.archive', notificationId);
const restoreNotification = (notificationId) => postAction('notifications.restore', notificationId);

const formatDate = (value) => humanizeDate(value);
const formatAbsoluteDate = (value) => {
    if (!value) {
        return t('notifications_center.meta.never');
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return t('notifications_center.meta.never');
    }

    return date.toLocaleString();
};

const typeLabel = (type) => {
    const key = `notifications_center.types.${type || 'system'}`;
    const translated = t(key);

    if (translated && translated !== key) {
        return translated;
    }

    return String(type || 'system')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
};

const typeBadgeClass = (type) => {
    switch (type) {
        case 'orders':
            return 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300';
        case 'message':
            return 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300';
        case 'billing':
            return 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
        case 'crm':
            return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
        case 'support':
            return 'bg-cyan-50 text-cyan-700 dark:bg-cyan-500/10 dark:text-cyan-300';
        case 'security':
            return 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-800 dark:text-neutral-300';
    }
};
</script>

<template>
    <Head :title="$t('notifications_center.page_title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-900 dark:text-neutral-100">
                            {{ $t('notifications_center.title') }}
                        </h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('notifications_center.subtitle') }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <FloatingSelect
                            v-model="filterForm.type"
                            :label="$t('notifications_center.filters.type_label')"
                            :options="typeSelectOptions"
                            dense
                            class="min-w-[180px]"
                        />

                        <FloatingSelect
                            v-model="filterForm.per_page"
                            :label="$t('notifications_center.filters.per_page_label')"
                            :options="perPageSelectOptions"
                            dense
                            class="min-w-[130px]"
                        />

                        <button
                            type="button"
                            :class="crmButtonClass('secondary', 'toolbar')"
                            @click="clearFilters"
                        >
                            {{ $t('notifications_center.filters.clear') }}
                        </button>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <div :class="crmSegmentedControlClass()">
                        <button
                            v-for="status in statusCards"
                            :key="status.id"
                            type="button"
                            :class="crmSegmentedControlButtonClass(filterForm.status === status.id)"
                            @click="setStatus(status.id)"
                        >
                            <span>{{ status.label }}</span>
                            <span class="rounded-full bg-white/70 px-1.5 py-0.5 text-[11px] font-semibold text-current dark:bg-neutral-950/30">
                                {{ status.count }}
                            </span>
                        </button>
                    </div>
                </div>
            </section>

            <section class="space-y-3">
                <article
                    v-for="notification in notificationItems"
                    :key="notification.id"
                    class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm transition dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0 flex-1 space-y-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                    :class="notification.is_read ? 'bg-stone-100 text-stone-500 dark:bg-neutral-800 dark:text-neutral-400' : 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300'"
                                >
                                    {{ notification.is_read ? $t('notifications_center.stats.read') : $t('notifications_center.stats.unread') }}
                                </span>
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                    :class="typeBadgeClass(notification.type)"
                                >
                                    {{ typeLabel(notification.type) }}
                                </span>
                                <span
                                    v-if="notification.is_archived"
                                    class="inline-flex items-center rounded-full bg-stone-200 px-2 py-0.5 text-[11px] font-semibold text-stone-700 dark:bg-neutral-700 dark:text-neutral-200"
                                >
                                    {{ $t('notifications_center.stats.archived') }}
                                </span>
                            </div>

                            <div class="space-y-1">
                                <h2 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ notification.title }}
                                </h2>
                                <p class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ notification.message || $t('notifications_center.meta.no_destination') }}
                                </p>
                            </div>

                            <dl class="grid gap-3 text-xs text-stone-500 sm:grid-cols-3 dark:text-neutral-400">
                                <div>
                                    <dt class="font-semibold uppercase tracking-wide">{{ $t('notifications_center.meta.received') }}</dt>
                                    <dd class="mt-1" :title="formatAbsoluteDate(notification.created_at)">
                                        {{ formatDate(notification.created_at) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-semibold uppercase tracking-wide">{{ $t('notifications_center.meta.read') }}</dt>
                                    <dd class="mt-1" :title="formatAbsoluteDate(notification.read_at)">
                                        {{ formatAbsoluteDate(notification.read_at) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-semibold uppercase tracking-wide">{{ $t('notifications_center.meta.archived') }}</dt>
                                    <dd class="mt-1" :title="formatAbsoluteDate(notification.archived_at)">
                                        {{ formatAbsoluteDate(notification.archived_at) }}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                            <Link
                                :href="route('notifications.open', { notification: notification.id, source: 'history' })"
                                :class="crmButtonClass('primary', 'toolbar')"
                            >
                                {{ $t('notifications_center.actions.open') }}
                            </Link>

                            <button
                                v-if="!notification.is_read"
                                type="button"
                                :class="crmButtonClass('secondary', 'toolbar')"
                                @click="markRead(notification.id)"
                            >
                                {{ $t('notifications_center.actions.mark_read') }}
                            </button>

                            <button
                                v-if="!notification.is_archived"
                                type="button"
                                :class="crmButtonClass('secondary', 'toolbar')"
                                @click="archiveNotification(notification.id)"
                            >
                                {{ $t('notifications_center.actions.archive') }}
                            </button>

                            <button
                                v-if="notification.is_archived"
                                type="button"
                                :class="crmButtonClass('secondary', 'toolbar')"
                                @click="restoreNotification(notification.id)"
                            >
                                {{ $t('notifications_center.actions.restore') }}
                            </button>
                        </div>
                    </div>
                </article>

                <div
                    v-if="!notificationItems.length"
                    class="rounded-sm border border-dashed border-stone-200 bg-white px-5 py-10 text-center dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <h2 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                        {{ $t('notifications_center.empty_title') }}
                    </h2>
                    <p class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('notifications_center.empty_body') }}
                    </p>
                </div>

                <AdminPaginationLinks :links="notificationLinks" />
            </section>
        </div>
    </AuthenticatedLayout>
</template>
