<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import FloatingInput from '@/Components/FloatingInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    initialPosts: {
        type: Array,
        default: () => ([]),
    },
    initialFilters: {
        type: Object,
        default: () => ({}),
    },
    initialSummary: {
        type: Object,
        default: () => ({}),
    },
    initialPlatformFilters: {
        type: Array,
        default: () => ([]),
    },
    initialStatusFilters: {
        type: Array,
        default: () => ([]),
    },
    initialAccess: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const normalizePosts = (payload) => Array.isArray(payload) ? payload : [];
const normalizeSummary = (payload) => payload && typeof payload === 'object' ? payload : {};
const normalizeFilters = (payload) => ({
    status: String(payload?.status || ''),
    platform: String(payload?.platform || ''),
    search: String(payload?.search || ''),
});
const normalizeAccess = (payload) => ({
    can_view: Boolean(payload?.can_view),
    can_manage_posts: Boolean(payload?.can_manage_posts),
    can_publish: Boolean(payload?.can_publish),
    can_submit_for_approval: Boolean(payload?.can_submit_for_approval),
    can_approve: Boolean(payload?.can_approve),
});

const posts = ref(normalizePosts(props.initialPosts));
const summary = ref(normalizeSummary(props.initialSummary));
const filters = ref(normalizeFilters(props.initialFilters));
const access = ref(normalizeAccess(props.initialAccess));
const isLoading = ref(false);
const busy = ref(false);
const error = ref('');
const info = ref('');

const canManage = computed(() => Boolean(access.value.can_manage_posts));
const canApprove = computed(() => Boolean(access.value.can_approve));

const sortedPosts = computed(() => [...posts.value].sort((left, right) => {
    const leftDate = Date.parse(String(
        left?.published_at || left?.failed_at || left?.scheduled_for || left?.updated_at || ''
    )) || 0;
    const rightDate = Date.parse(String(
        right?.published_at || right?.failed_at || right?.scheduled_for || right?.updated_at || ''
    )) || 0;

    return rightDate - leftDate;
}));

const requestErrorMessage = (requestError, fallback) => {
    const validationMessage = Object.values(requestError?.response?.data?.errors || {})
        .flat()
        .find((value) => typeof value === 'string' && value.trim() !== '');

    return validationMessage
        || requestError?.response?.data?.message
        || requestError?.message
        || fallback;
};

const refreshFromPayload = (payload) => {
    if (Array.isArray(payload?.posts)) {
        posts.value = normalizePosts(payload.posts);
    }

    if (payload?.summary) {
        summary.value = normalizeSummary(payload.summary);
    }

    if (payload?.filters) {
        filters.value = normalizeFilters(payload.filters);
    }

    if (payload?.access) {
        access.value = normalizeAccess(payload.access);
    }
};

watch(() => props.initialPosts, (value) => {
    posts.value = normalizePosts(value);
}, { deep: true });

watch(() => props.initialSummary, (value) => {
    summary.value = normalizeSummary(value);
}, { deep: true });

watch(() => props.initialFilters, (value) => {
    filters.value = normalizeFilters(value);
}, { deep: true });

watch(() => props.initialAccess, (value) => {
    access.value = normalizeAccess(value);
}, { deep: true });

const buildParams = () => {
    const params = {};

    if (filters.value.status) {
        params.status = filters.value.status;
    }

    if (filters.value.platform) {
        params.platform = filters.value.platform;
    }

    if (filters.value.search.trim() !== '') {
        params.search = filters.value.search.trim();
    }

    return params;
};

const load = async () => {
    isLoading.value = true;
    error.value = '';

    try {
        const response = await axios.get(route('social.history', buildParams()));
        refreshFromPayload(response.data);
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.history_manager.messages.load_error'));
    } finally {
        isLoading.value = false;
    }
};

const applyFilters = async () => {
    await load();
};

const resetFilters = async () => {
    filters.value = {
        status: '',
        platform: '',
        search: '',
    };

    await load();
};

const statusClass = (status) => {
    if (status === 'scheduled') {
        return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300';
    }

    if (status === 'published') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300';
    }

    if (status === 'partial_failed' || status === 'failed') {
        return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300';
    }

    if (status === 'publishing') {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300';
    }

    if (status === 'pending_approval') {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300';
    }

    return 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-800/70 dark:text-neutral-300';
};

const qualityClass = (status) => ({
    good: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
    warning: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300',
    attention: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300',
}[status] || 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-800/70 dark:text-neutral-300');

const aiTraceItems = (post) => (
    Array.isArray(post?.ai_trace?.items) ? post.ai_trace.items : []
);

const aiTraceLabel = (key) => {
    const translated = t(`social.ai_trace.items.${key}`);

    return translated === `social.ai_trace.items.${key}` ? key : translated;
};

const formatDate = (value) => {
    if (!value) {
        return t('social.history_manager.empty_value');
    }

    try {
        return new Date(value).toLocaleString();
    } catch {
        return t('social.history_manager.empty_value');
    }
};
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
const linkHrefFor = (value) => normalizeLinkCandidate(value);
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
const linkSummaryFor = (record) => {
    const label = String(record?.link_cta_label || '').trim();
    const host = linkHostFor(record?.link_url);

    if (label !== '' && host !== '' && label.toLowerCase() !== host.toLowerCase()) {
        return `${label} - ${host}`;
    }

    if (label !== '') {
        return label;
    }

    if (host !== '') {
        return host;
    }

    return '';
};

const draftLabel = (post) => {
    const text = String(post?.text || '').trim();
    if (text !== '') {
        return text.length > 90 ? `${text.slice(0, 87)}...` : text;
    }

    const linkSummary = linkSummaryFor(post);
    if (linkSummary !== '') {
        return linkSummary;
    }

    return t('social.history_manager.untitled_post');
};

const primaryMetaLabel = (post) => {
    if (post?.published_at) {
        return t('social.history_manager.meta.published_at');
    }

    if (post?.failed_at) {
        return t('social.history_manager.meta.failed_at');
    }

    if (post?.scheduled_for) {
        return t('social.history_manager.meta.scheduled_for');
    }

    return t('social.history_manager.meta.updated_at');
};

const primaryMetaValue = (post) => formatDate(
    post?.published_at || post?.failed_at || post?.scheduled_for || post?.updated_at
);

const openDraft = (post) => {
    router.visit(route('social.composer', { draft: post.id }));
};

const createEditableCopy = async (post, mode = 'duplicate') => {
    if (!canManage.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const routeName = mode === 'repost'
            ? 'social.posts.repost'
            : 'social.posts.duplicate';
        const response = await axios.post(route(routeName, post.id));

        const draftId = Number(response.data?.draft?.id || 0);
        if (draftId > 0) {
            router.visit(route('social.composer', { draft: draftId }));
            return;
        }

        info.value = String(response.data?.message || (
            mode === 'repost'
                ? t('social.history_manager.messages.repost_success')
                : t('social.history_manager.messages.duplicate_success')
        ));
    } catch (requestError) {
        error.value = requestErrorMessage(
            requestError,
            mode === 'repost'
                ? t('social.history_manager.messages.repost_error')
                : t('social.history_manager.messages.duplicate_error')
        );
    } finally {
        busy.value = false;
    }
};

const resolveApproval = async (post, decision) => {
    if (!canApprove.value || String(post?.status || '') !== 'pending_approval') {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const routeName = decision === 'reject'
            ? 'social.posts.reject'
            : 'social.posts.approve';
        const response = await axios.post(route(routeName, post.id));

        await load();
        info.value = String(response.data?.message || (
            decision === 'reject'
                ? t('social.history_manager.messages.reject_success')
                : t('social.history_manager.messages.approve_success')
        ));
    } catch (requestError) {
        error.value = requestErrorMessage(
            requestError,
            decision === 'reject'
                ? t('social.history_manager.messages.reject_error')
                : t('social.history_manager.messages.approve_error')
        );
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap justify-end gap-2">
            <SecondaryButton :disabled="busy || isLoading" @click="load">
                {{ t('social.history_manager.actions.reload') }}
            </SecondaryButton>
            <SecondaryButton :disabled="busy || isLoading" @click="resetFilters">
                {{ t('social.history_manager.actions.reset_filters') }}
            </SecondaryButton>
        </div>

        <div
            v-if="!access.can_manage_posts && !access.can_approve"
            class="rounded-3xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
        >
            <div class="font-semibold">{{ t('social.history_manager.read_only_title') }}</div>
            <div class="mt-1">{{ t('social.history_manager.read_only_description') }}</div>
        </div>

        <div
            v-if="error"
            class="rounded-3xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300"
        >
            {{ error }}
        </div>

        <div
            v-if="info"
            class="rounded-3xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300"
        >
            {{ info }}
        </div>

        <section class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('social.history_manager.filters_title') }}
                    </h4>
                    <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('social.history_manager.filters_description') }}
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div class="rounded-2xl bg-stone-100 px-3 py-2 text-sm dark:bg-neutral-800">
                        <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                            {{ t('social.history_manager.summary.total') }}
                        </div>
                        <div class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                            {{ Number(summary.total || 0) }}
                        </div>
                    </div>
                    <div class="rounded-2xl bg-stone-100 px-3 py-2 text-sm dark:bg-neutral-800">
                        <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                            {{ t('social.history_manager.summary.published') }}
                        </div>
                        <div class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                            {{ Number(summary.published || 0) }}
                        </div>
                    </div>
                    <div class="rounded-2xl bg-stone-100 px-3 py-2 text-sm dark:bg-neutral-800">
                        <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                            {{ t('social.history_manager.summary.attention') }}
                        </div>
                        <div class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                            {{ Number(summary.attention || 0) }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-[1.2fr,0.8fr,0.8fr,auto]">
                <FloatingInput
                    v-model="filters.search"
                    :label="t('social.history_manager.fields.search')"
                    :disabled="busy || isLoading"
                />

                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                        {{ t('social.history_manager.fields.status') }}
                    </span>
                    <select
                        v-model="filters.status"
                        class="block w-full rounded-2xl border border-stone-300 bg-white px-4 py-3 text-sm text-stone-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                        :disabled="busy || isLoading"
                    >
                        <option value="">
                            {{ t('social.history_manager.filters.all_statuses') }}
                        </option>
                        <option
                            v-for="statusOption in props.initialStatusFilters"
                            :key="statusOption.value"
                            :value="statusOption.value"
                        >
                            {{ t(`social.composer_manager.statuses.${statusOption.value}`) }}
                        </option>
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                        {{ t('social.history_manager.fields.platform') }}
                    </span>
                    <select
                        v-model="filters.platform"
                        class="block w-full rounded-2xl border border-stone-300 bg-white px-4 py-3 text-sm text-stone-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                        :disabled="busy || isLoading"
                    >
                        <option value="">
                            {{ t('social.history_manager.filters.all_platforms') }}
                        </option>
                        <option
                            v-for="platformOption in props.initialPlatformFilters"
                            :key="platformOption.value"
                            :value="platformOption.value"
                        >
                            {{ platformOption.label }}
                        </option>
                    </select>
                </label>

                <div class="flex items-end">
                    <PrimaryButton type="button" class="w-full justify-center" :disabled="busy || isLoading" @click="applyFilters">
                        {{ t('social.history_manager.actions.apply_filters') }}
                    </PrimaryButton>
                </div>
            </div>
        </section>

        <section v-if="sortedPosts.length" class="space-y-4">
            <article
                v-for="post in sortedPosts"
                :key="post.id"
                class="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold" :class="statusClass(post.status)">
                                {{ t(`social.composer_manager.statuses.${post.status || 'draft'}`) }}
                            </span>
                            <span
                                v-if="post.quality_review"
                                class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold"
                                :class="qualityClass(post.quality_review.status)"
                            >
                                {{ t('social.history_manager.quality_score', { score: Number(post.quality_review.score || 0) }) }}
                            </span>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ primaryMetaLabel(post) }}: {{ primaryMetaValue(post) }}
                            </span>
                        </div>

                        <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                            {{ draftLabel(post) }}
                        </h4>

                        <a
                            v-if="post.link_url"
                            :href="linkHrefFor(post.link_url)"
                            target="_blank"
                            rel="noreferrer"
                            class="block rounded-3xl border border-sky-200 bg-sky-50 px-4 py-3 text-sky-800 transition hover:border-sky-300 hover:bg-sky-100 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-100 dark:hover:border-sky-500/40 dark:hover:bg-sky-500/15"
                        >
                            <span class="block text-sm font-semibold">
                                {{ post.link_cta_label || t('social.history_manager.preview_cta_fallback') }}
                            </span>
                            <span
                                v-if="linkHostFor(post.link_url)"
                                class="mt-1 block text-xs text-sky-700/80 dark:text-sky-200/80"
                            >
                                {{ t('social.history_manager.preview_link_destination') }}: {{ linkHostFor(post.link_url) }}
                            </span>
                        </a>

                        <p
                            v-if="post.failure_reason"
                            class="rounded-2xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300"
                        >
                            {{ post.failure_reason }}
                        </p>

                        <p
                            v-if="post.status === 'pending_approval'"
                            class="rounded-2xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200"
                        >
                            {{ post.approval_request?.requested_by?.name
                                ? t('social.history_manager.approval.pending_with_actor', {
                                    actor: post.approval_request.requested_by.name,
                                })
                                : t('social.history_manager.approval.pending') }}
                        </p>

                        <p
                            v-if="post.approval_request?.status === 'rejected' && post.approval_request?.note"
                            class="rounded-2xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300"
                        >
                            {{ post.approval_request.note }}
                        </p>

                        <details
                            v-if="post.ai_trace?.has_trace"
                            class="rounded-2xl border border-stone-200 bg-stone-50 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800/60"
                        >
                            <summary class="cursor-pointer text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ t('social.ai_trace.title') }}
                            </summary>
                            <p v-if="post.ai_trace.summary" class="mt-2 text-sm text-stone-600 dark:text-neutral-300">
                                {{ post.ai_trace.summary }}
                            </p>
                            <dl v-if="aiTraceItems(post).length" class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2">
                                <div
                                    v-for="item in aiTraceItems(post)"
                                    :key="`${post.id}-${item.key}`"
                                    class="rounded-xl border border-stone-200 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    <dt class="text-xs uppercase tracking-[0.14em] text-stone-400 dark:text-neutral-500">
                                        {{ aiTraceLabel(item.key) }}
                                    </dt>
                                    <dd class="mt-1 text-sm text-stone-700 dark:text-neutral-200">
                                        {{ item.value }}
                                    </dd>
                                </div>
                            </dl>
                        </details>
                    </div>

                    <div v-if="canManage || canApprove" class="flex flex-wrap gap-2">
                        <SecondaryButton
                            v-if="canManage && (post.status === 'draft' || post.status === 'scheduled')"
                            type="button"
                            :disabled="busy"
                            @click="openDraft(post)"
                        >
                            {{ t('social.history_manager.actions.edit_draft') }}
                        </SecondaryButton>
                        <SecondaryButton
                            v-if="canApprove && post.status === 'pending_approval'"
                            type="button"
                            :disabled="busy"
                            @click="resolveApproval(post, 'reject')"
                        >
                            {{ t('social.history_manager.actions.reject_post') }}
                        </SecondaryButton>
                        <PrimaryButton
                            v-if="canApprove && post.status === 'pending_approval'"
                            type="button"
                            :disabled="busy"
                            @click="resolveApproval(post, 'approve')"
                        >
                            {{ t('social.history_manager.actions.approve_post') }}
                        </PrimaryButton>
                        <SecondaryButton
                            v-if="canManage"
                            type="button"
                            :disabled="busy"
                            @click="createEditableCopy(post, 'duplicate')"
                        >
                            {{ t('social.history_manager.actions.duplicate_post') }}
                        </SecondaryButton>
                        <PrimaryButton
                            v-if="post.status === 'published'"
                            type="button"
                            :disabled="busy"
                            @click="createEditableCopy(post, 'repost')"
                        >
                            {{ t('social.history_manager.actions.repost_post') }}
                        </PrimaryButton>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-[1fr,auto]">
                    <div class="space-y-3">
                        <p class="text-sm whitespace-pre-line text-stone-700 dark:text-neutral-200">
                            {{ post.text || t('social.history_manager.empty_text') }}
                        </p>

                        <div v-if="post.image_url" class="overflow-hidden rounded-3xl border border-stone-200 dark:border-neutral-700">
                            <img :src="post.image_url" :alt="t('social.history_manager.preview_image_alt')" class="h-48 w-full object-cover md:h-56">
                        </div>
                    </div>

                    <div class="rounded-3xl border border-stone-200 bg-stone-50 p-4 text-sm dark:border-neutral-700 dark:bg-neutral-800/60">
                        <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                            {{ t('social.history_manager.targets_title') }}
                        </div>
                        <div v-if="post.targets?.length" class="mt-3 space-y-2">
                            <div
                                v-for="target in post.targets"
                                :key="target.id"
                                class="rounded-2xl border border-stone-200 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-900"
                            >
                                <div class="font-medium text-stone-900 dark:text-neutral-100">
                                    {{ target.label || t('social.history_manager.empty_value') }}
                                </div>
                                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ target.provider_label || target.platform || t('social.history_manager.empty_value') }}
                                </div>
                            </div>
                        </div>
                        <div v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                            {{ t('social.history_manager.no_targets') }}
                        </div>
                    </div>
                </div>
            </article>
        </section>

        <section
            v-else
            class="rounded-3xl border border-dashed border-stone-300 bg-stone-50 px-5 py-8 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-400"
        >
            <div class="font-semibold text-stone-900 dark:text-neutral-100">
                {{ t('social.history_manager.empty_title') }}
            </div>
            <div class="mt-1">
                {{ t('social.history_manager.empty_description') }}
            </div>
        </section>
    </div>
</template>
