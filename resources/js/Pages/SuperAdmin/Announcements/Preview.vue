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
    <Head :title="$t('super_admin.announcements_preview.page_title')" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.announcements_preview.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('super_admin.announcements_preview.subtitle') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Link :href="route('superadmin.announcements.index')"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            {{ $t('super_admin.announcements_preview.manage') }}
                        </Link>
                    </div>
                </div>
            </section>

            <div class="grid gap-4 xl:grid-cols-2">
                <div class="space-y-4">
                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.announcements_preview.top.title') }}
                        </h2>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.announcements_preview.top.subtitle') }}
                        </p>
                    </div>
                    <AnnouncementsPanel
                        v-if="hasTopAnnouncements"
                        :announcements="topAnnouncements"
                        variant="side"
                        :title="$t('super_admin.announcements_preview.top.panel_title')"
                        :subtitle="$t('super_admin.announcements_preview.example_view')"
                        :limit="3"
                    />
                    <div v-else
                        class="rounded-sm border border-dashed border-stone-200 bg-white p-4 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                        {{ $t('super_admin.announcements_preview.top.empty') }}
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.announcements_preview.quick.title') }}
                        </h2>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.announcements_preview.quick.subtitle') }}
                        </p>
                    </div>
                    <AnnouncementsPanel
                        v-if="hasQuickAnnouncements"
                        :announcements="quickAnnouncements"
                        variant="side"
                        :fill-height="false"
                        :title="$t('super_admin.announcements_preview.quick.panel_title')"
                        :subtitle="$t('super_admin.announcements_preview.example_view')"
                        :limit="3"
                    />
                    <div v-else
                        class="rounded-sm border border-dashed border-stone-200 bg-white p-4 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                        {{ $t('super_admin.announcements_preview.quick.empty') }}
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
