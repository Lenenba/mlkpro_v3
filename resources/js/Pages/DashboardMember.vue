<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AnnouncementsPanel from '@/Components/Dashboard/AnnouncementsPanel.vue';
import KpiSparkline from '@/Components/Dashboard/KpiSparkline.vue';
import KpiTrendBadge from '@/Components/Dashboard/KpiTrendBadge.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';
import { buildSparklinePoints, buildTrend } from '@/utils/kpi';

const props = defineProps({
    stats: Object,
    tasks: Array,
    tasksToday: Array,
    worksToday: Array,
    agendaAlerts: {
        type: Object,
        default: () => ({}),
    },
    kpiSeries: {
        type: Object,
        default: () => ({}),
    },
    announcements: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { t } = useI18n();
const userName = computed(() => page.props.auth?.user?.name || t('dashboard_tasks.fallback_name'));
const hasAnnouncements = computed(() => (props.announcements || []).length > 0);
const tasksToday = computed(() => props.tasksToday || []);
const worksToday = computed(() => props.worksToday || []);
const agendaAlerts = computed(() => props.agendaAlerts || {});
const kpiSeries = computed(() => props.kpiSeries || {});
const kpiConfig = {
    tasks_todo: { direction: 'down' },
    tasks_in_progress: { direction: 'up' },
    tasks_done: { direction: 'up' },
};
const kpiData = computed(() => {
    const data = {};
    Object.entries(kpiConfig).forEach(([key, config]) => {
        const values = kpiSeries.value?.[key] || [];
        data[key] = {
            points: buildSparklinePoints(values),
            trend: buildTrend(values, config.direction),
        };
    });
    return data;
});

const stat = (key) => props.stats?.[key] ?? 0;
const formatDate = (value) => humanizeDate(value) || '-';
const formatTime = (value) => {
    if (!value) {
        return '';
    }
    const [hours, minutes] = value.split(':');
    if (!hours || !minutes) {
        return value;
    }
    return `${hours}:${minutes}`;
};
const formatTimeRange = (task) => {
    const start = formatTime(task.start_time);
    const end = formatTime(task.end_time);
    if (start && end) {
        return `${start} - ${end}`;
    }
    if (start) {
        return start;
    }
    if (end) {
        return end;
    }
    return t('dashboard.time.any');
};
const buildItemDateTime = (item) => {
    if (!item?.due_date) {
        return null;
    }
    const timeValue = item.start_time || item.end_time || '23:59';
    const [year, month, day] = item.due_date.split('-').map(Number);
    const [hour, minute] = timeValue.split(':').map(Number);
    if (!year || !month || !day) {
        return null;
    }
    return new Date(year, (month - 1), day, Number.isFinite(hour) ? hour : 0, Number.isFinite(minute) ? minute : 0, 0);
};
const resolvePriorityKey = (item) => {
    const dateTime = buildItemDateTime(item);
    if (!dateTime) {
        return 'low';
    }
    const diffMinutes = Math.round((dateTime.getTime() - Date.now()) / 60000);
    if (diffMinutes <= 120) {
        return 'high';
    }
    if (diffMinutes <= 360) {
        return 'medium';
    }
    return 'low';
};
const priorityConfig = computed(() => ({
    high: {
        label: t('dashboard.priority.high'),
        class: 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-200',
    },
    medium: {
        label: t('dashboard.priority.medium'),
        class: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-200',
    },
    low: {
        label: t('dashboard.priority.low'),
        class: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200',
    },
}));
const autoBadgeConfig = computed(() => ({
    started: {
        label: t('dashboard.auto.started'),
        class: 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-200',
    },
    completed: {
        label: t('dashboard.auto.completed'),
        class: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200',
    },
}));
const resolvePriority = (task) => priorityConfig.value[resolvePriorityKey(task)];
const resolveAutoBadges = (item) => {
    const badges = [];
    if (item?.auto_started_at) {
        badges.push({
            key: `${item.key}-auto-start`,
            label: autoBadgeConfig.value.started.label,
            class: autoBadgeConfig.value.started.class,
        });
    }
    if (item?.auto_completed_at) {
        badges.push({
            key: `${item.key}-auto-complete`,
            label: autoBadgeConfig.value.completed.label,
            class: autoBadgeConfig.value.completed.class,
        });
    }
    return badges;
};
const resolveStatusLabel = (key, fallback) => {
    const label = t(key);
    return label === key ? fallback : label;
};
const workStatusLabel = (status) => {
    const key = status || 'scheduled';
    return resolveStatusLabel(`dashboard.status.work.${key}`, key.replace('_', ' '));
};
const taskStatusLabel = (status) => {
    const key = status || 'todo';
    return resolveStatusLabel(`dashboard.status.task.${key}`, key.replace('_', ' '));
};
const formatStatus = (item) => {
    if (!item?.status) {
        return '-';
    }
    return item.type === 'work' ? workStatusLabel(item.status) : taskStatusLabel(item.status);
};
const todayItems = computed(() => {
    const taskItems = (tasksToday.value || []).map((task) => ({
        ...task,
        type: 'task',
        key: `task-${task.id}`,
    }));
    const workItems = (worksToday.value || []).map((work) => ({
        ...work,
        type: 'work',
        key: `work-${work.id}`,
    }));
    const items = [...taskItems, ...workItems];
    return items.sort((a, b) => {
        const dateA = buildItemDateTime(a);
        const dateB = buildItemDateTime(b);
        if (!dateA && !dateB) {
            return 0;
        }
        if (!dateA) {
            return 1;
        }
        if (!dateB) {
            return -1;
        }
        return dateA.getTime() - dateB.getTime();
    });
});
const formatAgendaCount = (count, singularKey, pluralKey) => {
    const label = count === 1 ? t(singularKey) : t(pluralKey);
    return `${count} ${label}`;
};
const agendaAlertItems = computed(() => {
    const alerts = agendaAlerts.value || {};
    const tasksStarted = Number(alerts.tasks_started || 0);
    const worksStarted = Number(alerts.works_started || 0);
    const tasksCompleted = Number(alerts.tasks_completed || 0);
    const worksCompleted = Number(alerts.works_completed || 0);
    const items = [];

    if (tasksStarted > 0) {
        items.push({
            key: 'tasks-started',
            label: t('dashboard.agenda.auto_started', {
                count: formatAgendaCount(tasksStarted, 'dashboard.agenda.task', 'dashboard.agenda.tasks'),
            }),
            class: autoBadgeConfig.value.started.class,
        });
    }
    if (worksStarted > 0) {
        items.push({
            key: 'works-started',
            label: t('dashboard.agenda.auto_started', {
                count: formatAgendaCount(worksStarted, 'dashboard.agenda.job', 'dashboard.agenda.jobs'),
            }),
            class: autoBadgeConfig.value.started.class,
        });
    }
    if (tasksCompleted > 0) {
        items.push({
            key: 'tasks-completed',
            label: t('dashboard.agenda.auto_completed_at', {
                count: formatAgendaCount(tasksCompleted, 'dashboard.agenda.task', 'dashboard.agenda.tasks'),
                time: '18:00',
            }),
            class: autoBadgeConfig.value.completed.class,
        });
    }
    if (worksCompleted > 0) {
        items.push({
            key: 'works-completed',
            label: t('dashboard.agenda.auto_completed', {
                count: formatAgendaCount(worksCompleted, 'dashboard.agenda.job', 'dashboard.agenda.jobs'),
            }),
            class: autoBadgeConfig.value.completed.class,
        });
    }

    return items;
});
const hasAgendaAlerts = computed(() => agendaAlertItems.value.length > 0);
</script>

<template>
    <Head :title="$t('dashboard.title')" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-5">
            <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ $t('dashboard.title') }}</h1>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('dashboard_tasks.subtitle_member', { name: userName }) }}
                </p>
            </div>

            <div :class="['grid gap-4', hasAnnouncements ? 'xl:grid-cols-[minmax(0,1fr)_320px]' : 'grid-cols-1']">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div class="rounded-sm border border-stone-200 border-t-4 border-t-amber-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('dashboard.status.task.todo') }}</p>
                            <KpiTrendBadge :trend="kpiData.tasks_todo.trend" />
                        </div>
                        <p class="mt-1 text-2xl font-semibold text-stone-900 dark:text-neutral-100">{{ stat('tasks_todo') }}</p>
                        <KpiSparkline
                            :points="kpiData.tasks_todo.points"
                            color-class="bg-amber-500/70 dark:bg-amber-400/50"
                        />
                    </div>
                    <div class="rounded-sm border border-stone-200 border-t-4 border-t-blue-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('dashboard.status.task.in_progress') }}</p>
                            <KpiTrendBadge :trend="kpiData.tasks_in_progress.trend" />
                        </div>
                        <p class="mt-1 text-2xl font-semibold text-stone-900 dark:text-neutral-100">{{ stat('tasks_in_progress') }}</p>
                        <KpiSparkline
                            :points="kpiData.tasks_in_progress.points"
                            color-class="bg-blue-500/70 dark:bg-blue-400/50"
                        />
                    </div>
                    <div class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('dashboard.status.task.done') }}</p>
                            <KpiTrendBadge :trend="kpiData.tasks_done.trend" />
                        </div>
                        <p class="mt-1 text-2xl font-semibold text-stone-900 dark:text-neutral-100">{{ stat('tasks_done') }}</p>
                        <KpiSparkline
                            :points="kpiData.tasks_done.points"
                            color-class="bg-emerald-500/70 dark:bg-emerald-400/50"
                        />
                    </div>
                </div>
                <AnnouncementsPanel
                    v-if="hasAnnouncements"
                    :announcements="announcements"
                    variant="side"
                    :title="$t('dashboard_tasks.announcements.title')"
                    :subtitle="$t('dashboard_tasks.announcements.subtitle')"
                    :limit="2"
                />
            </div>

            <div class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                <div class="py-3 px-4 border-b border-stone-200 dark:border-neutral-700 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('dashboard_tasks.timeline.title') }}
                        </h2>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('dashboard_tasks.timeline.subtitle') }}
                        </p>
                    </div>
                    <a
                        :href="route('tasks.calendar')"
                        class="text-xs font-medium text-emerald-600 hover:text-emerald-700"
                        target="_blank"
                        rel="noreferrer"
                        download
                    >
                        {{ $t('dashboard.timeline.sync_calendar') }}
                    </a>
                </div>

                <div class="p-4">
                    <div v-if="hasAgendaAlerts" class="mb-3 rounded-sm border border-sky-200 bg-sky-50 p-3 text-xs text-sky-800 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200">
                        <div class="font-semibold">{{ $t('dashboard_tasks.timeline.auto_alerts') }}</div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span
                                v-for="item in agendaAlertItems"
                                :key="item.key"
                                :class="['rounded-full px-2 py-0.5 text-[11px] font-medium', item.class]"
                            >
                                {{ item.label }}
                            </span>
                        </div>
                    </div>
                    <div v-if="!todayItems.length" class="text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('dashboard_tasks.timeline.empty') }}
                    </div>

                    <div v-else class="space-y-3">
                        <div v-for="(item, index) in todayItems" :key="item.key" class="flex gap-3">
                            <div class="flex flex-col items-center">
                                <div class="mt-1 h-2 w-2 rounded-full bg-emerald-500"></div>
                                <div v-if="index < todayItems.length - 1" class="mt-1 flex-1 w-px bg-stone-200 dark:bg-neutral-700"></div>
                            </div>
                            <div class="flex-1 rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ formatTimeRange(item) }}</div>
                                        <div class="truncate font-medium text-stone-900 dark:text-neutral-100">
                                            {{ item.title || (item.type === 'work' ? $t('dashboard.labels.job') : $t('dashboard.labels.task')) }}
                                        </div>
                                    </div>
                                    <div class="flex flex-col items-end gap-1">
                                        <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', resolvePriority(item).class]">
                                            {{ resolvePriority(item).label }}
                                        </span>
                                        <span
                                            v-for="badge in resolveAutoBadges(item)"
                                            :key="badge.key"
                                            :class="['rounded-full px-2 py-0.5 text-xs font-medium', badge.class]"
                                        >
                                            {{ badge.label }}
                                        </span>
                                        <span class="text-[11px] uppercase text-stone-400 dark:text-neutral-500">
                                            {{ item.type === 'work' ? $t('dashboard.labels.job') : $t('dashboard.labels.task') }}
                                        </span>
                                        <span class="text-[11px] uppercase text-stone-400 dark:text-neutral-500">
                                            {{ formatStatus(item) }}
                                        </span>
                                    </div>
                                </div>
                                <div v-if="item.assignee?.name" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('dashboard.labels.assignee', { name: item.assignee.name }) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                <div class="py-3 px-4 border-b border-stone-200 dark:border-neutral-700 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('dashboard_tasks.my_tasks.title') }}
                    </h2>
                    <Link :href="route('task.index')" class="text-xs font-medium text-green-600 hover:text-green-700">
                        {{ $t('dashboard_tasks.my_tasks.view_all') }}
                    </Link>
                </div>

                <div class="p-4">
                    <div v-if="!tasks?.length" class="text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('dashboard_tasks.my_tasks.empty') }}
                    </div>

                    <div v-else class="space-y-2">
                        <div v-for="task in tasks" :key="task.id"
                            class="flex items-center justify-between gap-3 rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700">
                            <div class="min-w-0">
                                <div class="truncate font-medium text-stone-900 dark:text-neutral-100">{{ task.title }}</div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('dashboard_tasks.labels.due', { date: formatDate(task.due_date) }) }}
                                </div>
                            </div>
                            <div class="shrink-0 rounded-full bg-stone-100 px-2 py-0.5 text-xs text-stone-700 dark:bg-neutral-900 dark:text-neutral-200">
                                {{ taskStatusLabel(task.status) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
