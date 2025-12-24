<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AnnouncementsPanel from '@/Components/Dashboard/AnnouncementsPanel.vue';

const props = defineProps({
    topAnnouncements: {
        type: Array,
        default: () => [],
    },
    quickAnnouncements: {
        type: Array,
        default: () => [],
    },
});

const hasTopAnnouncements = computed(() => props.topAnnouncements.length > 0);
const hasQuickAnnouncements = computed(() => props.quickAnnouncements.length > 0);
</script>

<template>
    <Head title="Announcement Preview" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Announcement preview</h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            Preview how announcements appear on the company dashboard.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Link :href="route('superadmin.announcements.index')"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            Manage announcements
                        </Link>
                    </div>
                </div>
            </section>

            <div class="grid gap-4 xl:grid-cols-2">
                <div class="space-y-4">
                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Top placement</h2>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            Shown next to KPI cards at the top of the dashboard.
                        </p>
                    </div>
                    <AnnouncementsPanel
                        v-if="hasTopAnnouncements"
                        :announcements="topAnnouncements"
                        variant="side"
                        title="Top placement"
                        subtitle="Example view"
                        :limit="3"
                    />
                    <div v-else
                        class="rounded-sm border border-dashed border-stone-200 bg-white p-4 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                        No active announcements for the top placement.
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Quick actions placement</h2>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            Replaces the quick actions card on the right side of the dashboard.
                        </p>
                    </div>
                    <AnnouncementsPanel
                        v-if="hasQuickAnnouncements"
                        :announcements="quickAnnouncements"
                        variant="side"
                        title="Quick actions slot"
                        subtitle="Example view"
                        :limit="3"
                    />
                    <div v-else
                        class="rounded-sm border border-dashed border-stone-200 bg-white p-4 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                        No active announcements for the quick actions placement.
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
