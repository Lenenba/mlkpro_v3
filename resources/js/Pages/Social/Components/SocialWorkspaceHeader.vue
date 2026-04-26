<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SecondaryButton from '@/Components/SecondaryButton.vue';

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

const statCards = computed(() => ([
    {
        key: 'connected_accounts',
        value: Number(props.stats?.connected_accounts || 0),
    },
    {
        key: 'draft_posts',
        value: Number(props.stats?.draft_posts || 0),
    },
    {
        key: 'scheduled_posts',
        value: Number(props.stats?.scheduled_posts || 0),
    },
]));

const tabClass = (key) => (
    props.activeTab === key
        ? 'border-sky-600 bg-sky-600 text-white dark:border-sky-500 dark:bg-sky-500 dark:text-stone-950'
        : 'border-stone-200 bg-white text-stone-600 hover:border-sky-300 hover:text-sky-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:border-sky-500/40 dark:hover:text-sky-300'
);
</script>

<template>
    <section class="rounded-sm border border-stone-200 border-t-4 border-t-sky-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <div class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.22em] text-sky-600 dark:text-sky-300">
                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 11a8 8 0 0 1 16 0" />
                        <path d="M7 14a5 5 0 0 1 10 0" />
                        <circle cx="12" cy="18" r="1.5" />
                    </svg>
                    <span>{{ t('social.workspace.eyebrow') }}</span>
                </div>

                <div>
                    <h1 class="text-xl font-semibold text-stone-900 dark:text-neutral-100">
                        {{ title }}
                    </h1>
                    <p class="mt-1 max-w-3xl text-sm text-stone-500 dark:text-neutral-400">
                        {{ description }}
                    </p>
                </div>
            </div>

            <Link :href="route('workspace.hubs.show', { category: 'growth' })">
                <SecondaryButton type="button">
                    {{ t('social.workspace.actions.back_to_growth') }}
                </SecondaryButton>
            </Link>
        </div>

        <div class="mt-5 flex flex-wrap gap-2">
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="tab.href"
                class="inline-flex items-center rounded-full border px-4 py-2 text-sm font-medium transition"
                :class="tabClass(tab.key)"
            >
                {{ tab.label }}
            </Link>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-3 md:grid-cols-3">
            <div
                v-for="card in statCards"
                :key="card.key"
                class="rounded-3xl border border-stone-200 bg-stone-50/90 p-4 dark:border-neutral-700 dark:bg-neutral-800/70"
            >
                <div class="text-xs uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                    {{ t(`social.workspace.stats.${card.key}`) }}
                </div>
                <div class="mt-2 text-3xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ card.value }}
                </div>
            </div>
        </div>
    </section>
</template>
