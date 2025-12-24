<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AnnouncementsPanel from '@/Components/Dashboard/AnnouncementsPanel.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    stats: Object,
    tasks: Array,
    announcements: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const userName = computed(() => page.props.auth?.user?.name || 'there');
const hasAnnouncements = computed(() => (props.announcements || []).length > 0);

const stat = (key) => props.stats?.[key] ?? 0;
const formatDate = (value) => humanizeDate(value) || '-';
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-5">
            <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Dashboard</h1>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    Welcome back, {{ userName }}. Here are your tasks.
                </p>
            </div>

            <div :class="['grid gap-4', hasAnnouncements ? 'xl:grid-cols-[minmax(0,1fr)_320px]' : 'grid-cols-1']">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div class="rounded-sm border border-stone-200 border-t-4 border-t-amber-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <p class="text-xs text-stone-500 dark:text-neutral-400">To do</p>
                        <p class="mt-1 text-2xl font-semibold text-stone-900 dark:text-neutral-100">{{ stat('tasks_todo') }}</p>
                    </div>
                    <div class="rounded-sm border border-stone-200 border-t-4 border-t-blue-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <p class="text-xs text-stone-500 dark:text-neutral-400">In progress</p>
                        <p class="mt-1 text-2xl font-semibold text-stone-900 dark:text-neutral-100">{{ stat('tasks_in_progress') }}</p>
                    </div>
                    <div class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <p class="text-xs text-stone-500 dark:text-neutral-400">Done</p>
                        <p class="mt-1 text-2xl font-semibold text-stone-900 dark:text-neutral-100">{{ stat('tasks_done') }}</p>
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
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">My tasks</h2>
                    <Link :href="route('task.index')" class="text-xs font-medium text-green-600 hover:text-green-700">
                        View all
                    </Link>
                </div>

                <div class="p-4">
                    <div v-if="!tasks?.length" class="text-sm text-stone-600 dark:text-neutral-400">
                        No tasks assigned yet.
                    </div>

                    <div v-else class="space-y-2">
                        <div v-for="task in tasks" :key="task.id"
                            class="flex items-center justify-between gap-3 rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700">
                            <div class="min-w-0">
                                <div class="truncate font-medium text-stone-900 dark:text-neutral-100">{{ task.title }}</div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    Due: {{ formatDate(task.due_date) }}
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
