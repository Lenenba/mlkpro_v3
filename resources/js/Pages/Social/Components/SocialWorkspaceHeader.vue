<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
    activeTab: {
        type: String,
        default: 'overview',
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const tabs = computed(() => ([
    {
        key: 'overview',
        label: t('social.workspace.tabs.overview'),
        href: route('social.index'),
    },
    {
        key: 'accounts',
        label: t('social.workspace.tabs.accounts'),
        href: route('social.accounts.index'),
    },
    {
        key: 'composer',
        label: t('social.workspace.tabs.composer'),
        href: route('social.composer'),
    },
    {
        key: 'calendar',
        label: t('social.workspace.tabs.calendar'),
        href: route('social.calendar'),
    },
    {
        key: 'brand_voice',
        label: t('social.workspace.tabs.brand_voice'),
        href: route('social.brand-voice'),
    },
    {
        key: 'media',
        label: t('social.workspace.tabs.media'),
        href: route('social.media.index'),
    },
    {
        key: 'templates',
        label: t('social.workspace.tabs.templates'),
        href: route('social.templates.index'),
    },
    {
        key: 'history',
        label: t('social.workspace.tabs.history'),
        href: route('social.history'),
    },
    {
        key: 'autopilot',
        label: t('social.workspace.tabs.autopilot'),
        href: route('social.automations.index'),
    },
    {
        key: 'approvals',
        label: t('social.workspace.tabs.approvals'),
        href: route('social.approvals.index'),
    },
]));

const tabClass = (key) => (
    props.activeTab === key
        ? 'border-sky-500 bg-sky-50 text-sky-700 dark:border-sky-500/60 dark:bg-sky-500/10 dark:text-sky-200'
        : 'border-transparent text-stone-600 hover:border-stone-200 hover:bg-white hover:text-stone-900 dark:text-neutral-300 dark:hover:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-neutral-100'
);
</script>

<template>
    <section class="border-b border-stone-200 pb-4 dark:border-neutral-800">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ title }}
                </h1>
                <p v-if="description" class="mt-1 max-w-3xl text-sm text-stone-500 dark:text-neutral-400">
                    {{ description }}
                </p>
            </div>

            <Link
                :href="route('workspace.hubs.show', { category: 'growth' })"
                class="text-sm font-medium text-stone-500 transition hover:text-sky-700 dark:text-neutral-400 dark:hover:text-sky-300"
            >
                {{ t('social.workspace.actions.back_to_growth') }}
            </Link>
        </div>

        <nav class="mt-4 flex gap-1 overflow-x-auto pb-1" :aria-label="t('social.workspace.eyebrow')">
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="tab.href"
                class="inline-flex shrink-0 items-center rounded-md border px-3 py-2 text-sm font-medium transition"
                :class="tabClass(tab.key)"
                :aria-current="activeTab === tab.key ? 'page' : undefined"
            >
                {{ tab.label }}
            </Link>
        </nav>
    </section>
</template>
