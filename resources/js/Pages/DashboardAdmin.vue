<script setup>
import { computed } from 'vue';
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
const userName = computed(() => page.props.auth?.user?.name || 'there');
const hasAnnouncements = computed(() => (props.announcements || []).length > 0);
const tasksToday = computed(() => props.tasksToday || []);
const worksToday = computed(() => props.worksToday || []);
const kpiSeries = computed(() => props.kpiSeries || {});
const kpiConfig = {
    tasks_total: { direction: 'up' },
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
    return 'Any time';
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
const priorityConfig = {
    high: {
        label: 'High',
        class: 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-200',
    },
    medium: {
        label: 'Medium',
        class: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-200',
    },
    low: {
        label: 'Low',
        class: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200',
    },
};
const resolvePriority = (task) => priorityConfig[resolvePriorityKey(task)];
const formatStatus = (status) => {
    if (!status) {
        return '-';
    }
    return status.replace('_', ' ');
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
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-5">
            <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Dashboard</h1>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    Welcome back, {{ userName }}. Here is the team task overview.
                </p>
            </div>

            <div :class="['grid gap-4', hasAnnouncements ? 'xl:grid-cols-[minmax(0,1fr)_320px]' : 'grid-cols-1']">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                    <div class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">Total</p>
                            <KpiTrendBadge :trend="kpiData.tasks_total.trend" />
                        </div>
                        <p class="mt-1 text-2xl font-semibold text-stone-900 dark:text-neutral-100">{{ stat('tasks_total') }}</p>
                        <KpiSparkline
                            :points="kpiData.tasks_total.points"
                            color-class="bg-emerald-500/70 dark:bg-emerald-400/50"
                        />
                    </div>
                    <div class="rounded-sm border border-stone-200 border-t-4 border-t-amber-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">To do</p>
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
                            <p class="text-xs text-stone-500 dark:text-neutral-400">In progress</p>
                            <KpiTrendBadge :trend="kpiData.tasks_in_progress.trend" />
                        </div>
                        <p class="mt-1 text-2xl font-semibold text-stone-900 dark:text-neutral-100">{{ stat('tasks_in_progress') }}</p>
                        <KpiSparkline
                            :points="kpiData.tasks_in_progress.points"
                            color-class="bg-blue-500/70 dark:bg-blue-400/50"
                        />
                    </div>
                    <div class="rounded-sm border border-stone-200 border-t-4 border-t-rose-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">Done</p>
                            <KpiTrendBadge :trend="kpiData.tasks_done.trend" />
                        </div>
                        <p class="mt-1 text-2xl font-semibold text-stone-900 dark:text-neutral-100">{{ stat('tasks_done') }}</p>
                        <KpiSparkline
                            :points="kpiData.tasks_done.points"
                            color-class="bg-rose-500/70 dark:bg-rose-400/50"
                        />
                    </div>
                </div>
                <AnnouncementsPanel
                    v-if="hasAnnouncements"
                    :announcements="announcements"
                    variant="side"
                    title="Announcements"
                    subtitle="Active notices for your team."
                    :limit="2"
                />
            </div>

            <div class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                <div class="py-3 px-4 border-b border-stone-200 dark:border-neutral-700 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Today's timeline</h2>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">Priorities are based on time.</p>
                    </div>
                    <a
                        :href="route('tasks.calendar')"
                        class="text-xs font-medium text-emerald-600 hover:text-emerald-700"
                        target="_blank"
                        rel="noreferrer"
                        download
                    >
                        Sync calendar
                    </a>
                </div>

                <div class="p-4">
                    <div v-if="!todayItems.length" class="text-sm text-stone-600 dark:text-neutral-400">
                        No tasks or jobs scheduled for today.
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
                                            {{ item.title || (item.type === 'work' ? 'Job' : 'Task') }}
                                        </div>
                                    </div>
                                    <div class="flex flex-col items-end gap-1">
                                        <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', resolvePriority(item).class]">
                                            {{ resolvePriority(item).label }}
                                        </span>
                                        <span class="text-[11px] uppercase text-stone-400 dark:text-neutral-500">
                                            {{ item.type === 'work' ? 'Job' : 'Task' }}
                                        </span>
                                        <span class="text-[11px] uppercase text-stone-400 dark:text-neutral-500">
                                            {{ formatStatus(item.status) }}
                                        </span>
                                    </div>
                                </div>
                                <div v-if="item.assignee?.name" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                    Assignee: {{ item.assignee.name }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                <div class="py-3 px-4 border-b border-stone-200 dark:border-neutral-700 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Upcoming tasks</h2>
                    <Link :href="route('task.index')" class="text-xs font-medium text-green-600 hover:text-green-700">
                        Manage tasks
                    </Link>
                </div>

                <div class="p-4">
                    <div v-if="!tasks?.length" class="text-sm text-stone-600 dark:text-neutral-400">
                        No tasks yet.
                    </div>

                    <div v-else class="space-y-2">
                        <div v-for="task in tasks" :key="task.id"
                            class="flex items-center justify-between gap-3 rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700">
                            <div class="min-w-0">
                                <div class="truncate font-medium text-stone-900 dark:text-neutral-100">{{ task.title }}</div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    Due: {{ formatDate(task.due_date) }} <span class="mx-1">•</span>
                                    Assignee: {{ task.assignee?.name || '-' }}
                                </div>
                            </div>
                            <div class="shrink-0 rounded-full bg-stone-100 px-2 py-0.5 text-xs text-stone-700 dark:bg-neutral-900 dark:text-neutral-200">
                                {{ task.status }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
