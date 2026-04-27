<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SocialPostQualityPanel from '@/Pages/Social/Components/SocialPostQualityPanel.vue';
import SocialVisualPostPreview from '@/Pages/Social/Components/SocialVisualPostPreview.vue';
import { useI18n } from 'vue-i18n';

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
    initialRuleFilters: {
        type: Array,
        default: () => ([]),
    },
    initialSourceFilters: {
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
const normalizeRuleFilters = (payload) => Array.isArray(payload) ? payload : [];
const normalizeSourceFilters = (payload) => Array.isArray(payload) ? payload : [];
const normalizeAccess = (payload) => ({
    can_view: Boolean(payload?.can_view),
    can_manage_posts: Boolean(payload?.can_manage_posts),
    can_manage_automations: Boolean(payload?.can_manage_automations),
    can_publish: Boolean(payload?.can_publish),
    can_submit_for_approval: Boolean(payload?.can_submit_for_approval),
    can_approve: Boolean(payload?.can_approve),
});
const normalizeFilters = (payload) => ({
    search: String(payload?.search || ''),
    rule_id: payload?.rule_id ? Number(payload.rule_id) : '',
    origin: ['all', 'automated', 'manual'].includes(String(payload?.origin || 'all'))
        ? String(payload?.origin || 'all')
        : 'all',
    source_type: String(payload?.source_type || ''),
});

const posts = ref(normalizePosts(props.initialPosts));
const summary = ref(normalizeSummary(props.initialSummary));
const ruleFilters = ref(normalizeRuleFilters(props.initialRuleFilters));
const sourceFilters = ref(normalizeSourceFilters(props.initialSourceFilters));
const access = ref(normalizeAccess(props.initialAccess));
const filters = ref(normalizeFilters(props.initialFilters));
const busy = ref(false);
const isLoading = ref(false);
const error = ref('');
const info = ref('');
const scheduleInputs = ref({});

const canApprove = computed(() => Boolean(access.value.can_approve));
const ruleFilterOptions = computed(() => [
    { value: '', label: t('social.approval_inbox.filters.all_rules') },
    ...ruleFilters.value,
]);
const originFilterOptions = computed(() => [
    { value: 'all', label: t('social.approval_inbox.filters.all_origins') },
    { value: 'automated', label: t('social.approval_inbox.filters.automated_only') },
    { value: 'manual', label: t('social.approval_inbox.filters.manual_only') },
]);
const sourceTypeFilterOptions = computed(() => [
    { value: '', label: t('social.approval_inbox.filters.all_sources') },
    ...sourceFilters.value,
]);

const requestErrorMessage = (requestError, fallback) => {
    const validationMessage = Object.values(requestError?.response?.data?.errors || {})
        .flat()
        .find((value) => typeof value === 'string' && value.trim() !== '');

    return validationMessage
        || requestError?.response?.data?.message
        || requestError?.message
        || fallback;
};

const nextScheduleInput = () => {
    const date = new Date();
    date.setHours(date.getHours() + 2, 0, 0, 0);

    const year = date.getFullYear();
    const month = `${date.getMonth() + 1}`.padStart(2, '0');
    const day = `${date.getDate()}`.padStart(2, '0');
    const hours = `${date.getHours()}`.padStart(2, '0');
    const minutes = `${date.getMinutes()}`.padStart(2, '0');

    return `${year}-${month}-${day}T${hours}:${minutes}`;
};

const refreshScheduleInputs = (records) => {
    const next = {};

    normalizePosts(records).forEach((post) => {
        next[post.id] = String(post?.scheduled_for || nextScheduleInput());
    });

    scheduleInputs.value = next;
};

const refreshFromPayload = (payload) => {
    if (Array.isArray(payload?.posts)) {
        posts.value = normalizePosts(payload.posts);
        refreshScheduleInputs(payload.posts);
    }

    if (payload?.summary) {
        summary.value = normalizeSummary(payload.summary);
    }

    if (Array.isArray(payload?.rule_filters)) {
        ruleFilters.value = normalizeRuleFilters(payload.rule_filters);
    }

    if (Array.isArray(payload?.source_filters)) {
        sourceFilters.value = normalizeSourceFilters(payload.source_filters);
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
    refreshScheduleInputs(value);
}, { deep: true, immediate: true });

watch(() => props.initialSummary, (value) => {
    summary.value = normalizeSummary(value);
}, { deep: true });

watch(() => props.initialRuleFilters, (value) => {
    ruleFilters.value = normalizeRuleFilters(value);
}, { deep: true });

watch(() => props.initialSourceFilters, (value) => {
    sourceFilters.value = normalizeSourceFilters(value);
}, { deep: true });

watch(() => props.initialAccess, (value) => {
    access.value = normalizeAccess(value);
}, { deep: true });

const buildParams = () => {
    const params = {};

    if (filters.value.search.trim() !== '') {
        params.search = filters.value.search.trim();
    }

    if (filters.value.rule_id) {
        params.rule_id = filters.value.rule_id;
    }

    if (filters.value.origin !== 'all') {
        params.origin = filters.value.origin;
    }

    if (filters.value.source_type !== '') {
        params.source_type = filters.value.source_type;
    }

    return params;
};

const load = async () => {
    isLoading.value = true;
    error.value = '';

    try {
        const response = await axios.get(route('social.approvals.index', buildParams()));
        refreshFromPayload(response.data);
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.approval_inbox.messages.load_error'));
    } finally {
        isLoading.value = false;
    }
};

const resetFilters = async () => {
    filters.value = {
        search: '',
        rule_id: '',
        origin: 'all',
        source_type: '',
    };

    await load();
};

const runAction = async (requestFactory, successMessage, fallbackError) => {
    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await requestFactory();
        await load();
        info.value = String(response.data?.message || successMessage);
        return response;
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, fallbackError);
        return null;
    } finally {
        busy.value = false;
    }
};

const openComposer = (post) => {
    router.visit(route('social.composer', { draft: post.id }));
};

const requestRevision = async (post) => {
    const response = await runAction(
        () => axios.post(route('social.posts.prepare-revision', post.id)),
        t('social.approval_inbox.messages.revision_success'),
        t('social.approval_inbox.messages.revision_error')
    );

    const draftId = Number(response?.data?.draft?.id || 0);
    if (draftId > 0) {
        router.visit(route('social.composer', { draft: draftId }));
    }
};

const approveNow = async (post) => {
    await runAction(
        () => axios.post(route('social.posts.approve', post.id), {
            mode: 'immediate',
        }),
        t('social.approval_inbox.messages.approve_now_success'),
        t('social.approval_inbox.messages.approve_now_error')
    );
};

const approveScheduled = async (post) => {
    await runAction(
        () => axios.post(route('social.posts.approve', post.id), {
            mode: 'scheduled',
            scheduled_for: scheduleInputs.value[post.id] || '',
        }),
        t('social.approval_inbox.messages.approve_schedule_success'),
        t('social.approval_inbox.messages.approve_schedule_error')
    );
};

const rejectPost = async (post) => {
    await runAction(
        () => axios.post(route('social.posts.reject', post.id)),
        t('social.approval_inbox.messages.reject_success'),
        t('social.approval_inbox.messages.reject_error')
    );
};

const regeneratePost = async (post) => {
    await runAction(
        () => axios.post(route('social.posts.regenerate', post.id)),
        t('social.approval_inbox.messages.regenerate_success'),
        t('social.approval_inbox.messages.regenerate_error')
    );
};

const draftLabel = (post) => {
    const text = String(post?.text || '').trim();
    if (text !== '') {
        return text.length > 90 ? `${text.slice(0, 87)}...` : text;
    }

    return t('social.approval_inbox.untitled_post');
};

const formatDate = (value) => {
    if (!value) {
        return t('social.approval_inbox.empty_value');
    }

    try {
        return new Date(value).toLocaleString();
    } catch {
        return t('social.approval_inbox.empty_value');
    }
};

const isStale = (post) => {
    const requestedAt = post?.approval_request?.requested_at;
    if (!requestedAt) {
        return false;
    }

    try {
        return new Date(requestedAt).getTime() <= Date.now() - (24 * 60 * 60 * 1000);
    } catch {
        return false;
    }
};
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap justify-end gap-2">
            <SecondaryButton :disabled="busy || isLoading" @click="load">
                {{ t('social.approval_inbox.actions.reload') }}
            </SecondaryButton>
            <SecondaryButton :disabled="busy || isLoading" @click="resetFilters">
                {{ t('social.approval_inbox.actions.reset_filters') }}
            </SecondaryButton>
        </div>

        <div
            v-if="!canApprove"
            class="rounded-3xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
        >
            <div class="font-semibold">{{ t('social.approval_inbox.read_only_title') }}</div>
            <div class="mt-1">{{ t('social.approval_inbox.read_only_description') }}</div>
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

        <section class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                    {{ t('social.approval_inbox.summary.pending') }}
                </div>
                <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ Number(summary.pending || 0) }}
                </div>
            </div>
            <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                    {{ t('social.approval_inbox.summary.automated') }}
                </div>
                <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ Number(summary.automated || 0) }}
                </div>
            </div>
            <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                    {{ t('social.approval_inbox.summary.manual') }}
                </div>
                <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ Number(summary.manual || 0) }}
                </div>
            </div>
            <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                    {{ t('social.approval_inbox.summary.stale') }}
                </div>
                <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ Number(summary.stale || 0) }}
                </div>
            </div>
        </section>

        <section class="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-[1.1fr,0.7fr,0.7fr,0.7fr,auto]">
                <FloatingInput v-model="filters.search" :label="t('social.approval_inbox.fields.search')" :disabled="busy || isLoading" />
                <FloatingSelect
                    v-model="filters.rule_id"
                    :label="t('social.approval_inbox.fields.rule_id')"
                    :options="ruleFilterOptions"
                    :disabled="busy || isLoading"
                />
                <FloatingSelect
                    v-model="filters.origin"
                    :label="t('social.approval_inbox.fields.origin')"
                    :options="originFilterOptions"
                    :disabled="busy || isLoading"
                />
                <FloatingSelect
                    v-model="filters.source_type"
                    :label="t('social.approval_inbox.fields.source_type')"
                    :options="sourceTypeFilterOptions"
                    :disabled="busy || isLoading"
                />
                <div class="flex items-end">
                    <PrimaryButton class="w-full justify-center" :disabled="busy || isLoading" @click="load">
                        {{ t('social.approval_inbox.actions.apply_filters') }}
                    </PrimaryButton>
                </div>
            </div>
        </section>

        <section v-if="posts.length" class="space-y-4">
            <article
                v-for="post in posts"
                :key="post.id"
                class="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300">
                                {{ t('social.approval_inbox.status_pending') }}
                            </span>
                            <span v-if="isStale(post)" class="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                                {{ t('social.approval_inbox.status_stale') }}
                            </span>
                            <span v-if="post.automation_rule?.name" class="inline-flex items-center rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300">
                                {{ post.automation_rule.name }}
                            </span>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ formatDate(post.approval_request?.requested_at) }}
                            </span>
                        </div>

                        <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                            {{ draftLabel(post) }}
                        </h4>

                        <div class="flex flex-wrap items-center gap-3 text-xs text-stone-500 dark:text-neutral-400">
                            <span v-if="post.approval_request?.requested_by?.name">
                                {{ t('social.approval_inbox.meta.requested_by', { actor: post.approval_request.requested_by.name }) }}
                            </span>
                            <span v-if="post.source_label">
                                {{ t('social.approval_inbox.meta.source_label', { source: post.source_label }) }}
                            </span>
                            <span v-if="post.automation?.generation_mode">
                                {{ t('social.approval_inbox.meta.generation_mode', { mode: post.automation.generation_mode }) }}
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <SecondaryButton :disabled="busy" @click="openComposer(post)">
                            {{ t('social.approval_inbox.actions.open_composer') }}
                        </SecondaryButton>
                        <SecondaryButton v-if="canApprove" :disabled="busy" @click="requestRevision(post)">
                            {{ t('social.approval_inbox.actions.request_revision') }}
                        </SecondaryButton>
                        <SecondaryButton v-if="canApprove && post.social_automation_rule_id" :disabled="busy" @click="regeneratePost(post)">
                            {{ t('social.approval_inbox.actions.regenerate') }}
                        </SecondaryButton>
                    </div>
                </div>

                <div
                    class="mt-4 grid grid-cols-1 gap-4"
                    :class="canApprove ? 'xl:grid-cols-[1fr,320px]' : 'xl:grid-cols-1'"
                >
                    <div class="space-y-4">
                        <SocialPostQualityPanel
                            :text="post.text"
                            :image-url="post.image_url"
                            :link-url="post.link_url"
                            :link-label="post.link_cta_label"
                            :targets="post.targets"
                        />

                        <SocialVisualPostPreview
                            :text="post.text"
                            :image-url="post.image_url"
                            :link-url="post.link_url"
                            :link-label="post.link_cta_label || t('social.approval_inbox.preview_cta_fallback')"
                            :targets="post.targets"
                            :empty-text="t('social.approval_inbox.empty_text')"
                        />
                    </div>

                    <div v-if="canApprove" class="space-y-4">
                        <div class="rounded-3xl border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                {{ t('social.approval_inbox.actions.approve_schedule') }}
                            </div>
                            <FloatingInput
                                v-model="scheduleInputs[post.id]"
                                type="datetime-local"
                                class="mt-3"
                                :label="t('social.approval_inbox.fields.scheduled_for')"
                                :disabled="busy"
                            />
                            <div class="mt-3 grid grid-cols-1 gap-2">
                                <PrimaryButton class="justify-center" :disabled="busy" @click="approveNow(post)">
                                    {{ t('social.approval_inbox.actions.approve_now') }}
                                </PrimaryButton>
                                <SecondaryButton class="justify-center" :disabled="busy" @click="approveScheduled(post)">
                                    {{ t('social.approval_inbox.actions.approve_schedule') }}
                                </SecondaryButton>
                                <SecondaryButton class="justify-center" :disabled="busy" @click="rejectPost(post)">
                                    {{ t('social.approval_inbox.actions.reject') }}
                                </SecondaryButton>
                            </div>
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
                {{ t('social.approval_inbox.empty_title') }}
            </div>
            <div class="mt-1">
                {{ t('social.approval_inbox.empty_description') }}
            </div>
        </section>
    </div>
</template>
