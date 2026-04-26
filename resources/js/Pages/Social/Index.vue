<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SocialWorkspaceHeader from '@/Pages/Social/Components/SocialWorkspaceHeader.vue';

const props = defineProps({
    connection_summary: {
        type: Object,
        default: () => ({}),
    },
    post_summary: {
        type: Object,
        default: () => ({}),
    },
    automation_summary: {
        type: Object,
        default: () => ({}),
    },
    approval_summary: {
        type: Object,
        default: () => ({}),
    },
    workspace_stats: {
        type: Object,
        default: () => ({}),
    },
    recent_drafts: {
        type: Array,
        default: () => ([]),
    },
    access: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const normalizeLinkCandidate = (value) => {
    const candidate = String(value || '').trim();
    if (candidate === '') {
        return '';
    }

    if (/^[a-z][a-z0-9+.-]*:/i.test(candidate)) {
        return candidate;
    }

    if (candidate.startsWith('//')) {
        return `https:${candidate}`;
    }

    if (/\s/u.test(candidate) || !candidate.includes('.')) {
        return candidate;
    }

    return `https://${candidate}`;
};
const linkHostFor = (value) => {
    const candidate = normalizeLinkCandidate(value);
    if (candidate === '') {
        return '';
    }

    try {
        return new URL(candidate).host.replace(/^www\./i, '');
    } catch {
        return candidate;
    }
};
const recentDraftLabel = (draft) => {
    const text = String(draft?.text || '').trim();
    if (text !== '') {
        return text;
    }

    const label = String(draft?.link_cta_label || '').trim();
    if (label !== '') {
        return label;
    }

    const host = linkHostFor(draft?.link_url);
    if (host !== '') {
        return host;
    }

    return t('social.index_page.untitled_draft');
};

const formatDate = (value) => {
    if (!value) {
        return t('social.index_page.empty_value');
    }

    try {
        return new Date(value).toLocaleString();
    } catch {
        return t('social.index_page.empty_value');
    }
};
</script>

<template>
    <Head :title="t('social.index_page.head_title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <SocialWorkspaceHeader
                active-tab="overview"
                :title="t('social.index_page.page_title')"
                :description="t('social.index_page.page_description')"
                :stats="props.workspace_stats"
            />

            <div
                v-if="!access.can_manage_posts"
                class="rounded-3xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
            >
                <div class="font-semibold">{{ t('social.index_page.read_only_title') }}</div>
                <div class="mt-1">{{ t('social.index_page.read_only_description') }}</div>
            </div>

            <section class="grid grid-cols-1 gap-5 xl:grid-cols-2">
                <article class="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                        {{ t('social.workspace.tabs.accounts') }}
                    </div>
                    <h2 class="mt-2 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('social.index_page.cards.accounts_title') }}
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-stone-600 dark:text-neutral-300">
                        {{ t('social.index_page.cards.accounts_description') }}
                    </p>

                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <div class="rounded-3xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/70">
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.index_page.cards.configured_accounts') }}
                            </div>
                            <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                                {{ Number(connection_summary.configured || 0) }}
                            </div>
                        </div>
                        <div class="rounded-3xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/70">
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.index_page.cards.connected_accounts') }}
                            </div>
                            <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                                {{ Number(connection_summary.connected || 0) }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-5">
                        <Link :href="route('social.accounts.index')">
                            <SecondaryButton type="button">
                                {{ access.can_manage_posts ? t('social.index_page.cards.accounts_action_manage') : t('social.index_page.cards.accounts_action_view') }}
                            </SecondaryButton>
                        </Link>
                    </div>
                </article>

                <article class="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                        {{ t('social.workspace.tabs.composer') }}
                    </div>
                    <h2 class="mt-2 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('social.index_page.cards.composer_title') }}
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-stone-600 dark:text-neutral-300">
                        {{ t('social.index_page.cards.composer_description') }}
                    </p>

                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <div class="rounded-3xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/70">
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.workspace.stats.draft_posts') }}
                            </div>
                            <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                                {{ Number(post_summary.drafts || 0) }}
                            </div>
                        </div>
                        <div class="rounded-3xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/70">
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.workspace.stats.scheduled_posts') }}
                            </div>
                            <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                                {{ Number(post_summary.scheduled || 0) }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-5">
                        <Link :href="route('social.composer')">
                            <PrimaryButton type="button">
                                {{ access.can_manage_posts ? t('social.index_page.cards.composer_action_manage') : t('social.index_page.cards.composer_action_view') }}
                            </PrimaryButton>
                        </Link>
                    </div>
                </article>

                <article class="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                        {{ t('social.workspace.tabs.autopilot') }}
                    </div>
                    <h2 class="mt-2 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('social.index_page.cards.autopilot_title') }}
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-stone-600 dark:text-neutral-300">
                        {{ t('social.index_page.cards.autopilot_description') }}
                    </p>

                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <div class="rounded-3xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/70">
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.index_page.cards.active_rules') }}
                            </div>
                            <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                                {{ Number(automation_summary.active || 0) }}
                            </div>
                        </div>
                        <div class="rounded-3xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/70">
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.automation_manager.summary.total') }}
                            </div>
                            <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                                {{ Number(automation_summary.total || 0) }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-5">
                        <Link :href="route('social.automations.index')">
                            <PrimaryButton type="button">
                                {{ access.can_manage_automations ? t('social.index_page.cards.autopilot_action_manage') : t('social.index_page.cards.autopilot_action_view') }}
                            </PrimaryButton>
                        </Link>
                    </div>
                </article>

                <article class="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                        {{ t('social.workspace.tabs.approvals') }}
                    </div>
                    <h2 class="mt-2 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('social.index_page.cards.approvals_title') }}
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-stone-600 dark:text-neutral-300">
                        {{ t('social.index_page.cards.approvals_description') }}
                    </p>

                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <div class="rounded-3xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/70">
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.index_page.cards.pending_approvals') }}
                            </div>
                            <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                                {{ Number(approval_summary.pending || 0) }}
                            </div>
                        </div>
                        <div class="rounded-3xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/70">
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.workspace.stats.draft_posts') }}
                            </div>
                            <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                                {{ Number(post_summary.drafts || 0) }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-5">
                        <Link :href="route('social.approvals.index')">
                            <SecondaryButton type="button">
                                {{ access.can_approve ? t('social.index_page.cards.approvals_action_manage') : t('social.index_page.cards.approvals_action_view') }}
                            </SecondaryButton>
                        </Link>
                    </div>
                </article>
            </section>

            <section class="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-stone-900 dark:text-neutral-100">
                            {{ t('social.index_page.recent_drafts_title') }}
                        </h2>
                        <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                            {{ t('social.index_page.recent_drafts_description') }}
                        </p>
                    </div>
                    <Link :href="route('social.composer')">
                        <SecondaryButton type="button">
                            {{ t('social.index_page.open_composer') }}
                        </SecondaryButton>
                    </Link>
                </div>

                <div v-if="recent_drafts.length" class="mt-4 grid grid-cols-1 gap-3 xl:grid-cols-3">
                    <Link
                        v-for="draft in recent_drafts"
                        :key="draft.id"
                        :href="route('social.composer', { draft: draft.id })"
                        class="rounded-3xl border border-stone-200 bg-stone-50 p-4 transition hover:border-sky-300 dark:border-neutral-700 dark:bg-neutral-800/60 dark:hover:border-sky-500/40"
                    >
                        <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ recentDraftLabel(draft) }}
                        </div>
                        <div class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                            {{ t(`social.composer_manager.statuses.${draft.status || 'draft'}`) }}
                        </div>
                        <div class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('social.index_page.draft_targets', { count: Number(draft.selected_accounts_count || 0) }) }}
                        </div>
                        <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ formatDate(draft.updated_at) }}
                        </div>
                    </Link>
                </div>

                <div
                    v-else
                    class="mt-4 rounded-3xl border border-dashed border-stone-300 bg-stone-50 px-5 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-400"
                >
                    {{ t('social.index_page.empty_recent_drafts') }}
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
