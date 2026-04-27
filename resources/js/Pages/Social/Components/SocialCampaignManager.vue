<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    initialConnectedAccounts: {
        type: Array,
        default: () => ([]),
    },
    initialRecentBatches: {
        type: Array,
        default: () => ([]),
    },
    initialIntentionOptions: {
        type: Array,
        default: () => ([]),
    },
    initialSummary: {
        type: Object,
        default: () => ({}),
    },
    initialAccess: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const normalizeAccess = (payload) => ({
    can_view: Boolean(payload?.can_view),
    can_manage_posts: Boolean(payload?.can_manage_posts),
});
const tomorrowDate = () => {
    const date = new Date();
    date.setDate(date.getDate() + 1);

    return date.toISOString().slice(0, 10);
};

const connectedAccounts = ref(Array.isArray(props.initialConnectedAccounts) ? props.initialConnectedAccounts : []);
const recentBatches = ref(Array.isArray(props.initialRecentBatches) ? props.initialRecentBatches : []);
const summary = ref(props.initialSummary && typeof props.initialSummary === 'object' ? props.initialSummary : {});
const access = ref(normalizeAccess(props.initialAccess));
const generatedPosts = ref([]);
const busy = ref(false);
const error = ref('');
const info = ref('');
const form = ref({
    name: '',
    intention_type: 'product_launch',
    brief: '',
    start_date: tomorrowDate(),
    post_count: 4,
    duration_days: 7,
    image_url: '',
    link_url: '',
    target_connection_ids: [],
});

const canManage = computed(() => Boolean(access.value.can_manage_posts));
const selectedCount = computed(() => form.value.target_connection_ids.length);
const intentionOptions = computed(() => (
    Array.isArray(props.initialIntentionOptions) && props.initialIntentionOptions.length
        ? props.initialIntentionOptions
        : ['product_launch', 'promotion', 'event', 'service_push', 'client_reengagement'].map((value) => ({ value }))
));

watch(() => props.initialConnectedAccounts, (value) => {
    connectedAccounts.value = Array.isArray(value) ? value : [];
}, { deep: true });

watch(() => props.initialRecentBatches, (value) => {
    recentBatches.value = Array.isArray(value) ? value : [];
}, { deep: true });

watch(() => props.initialSummary, (value) => {
    summary.value = value && typeof value === 'object' ? value : {};
}, { deep: true });

watch(() => props.initialAccess, (value) => {
    access.value = normalizeAccess(value);
}, { deep: true });

const requestErrorMessage = (requestError, fallback) => {
    const validationMessage = Object.values(requestError?.response?.data?.errors || {})
        .flat()
        .find((value) => typeof value === 'string' && value.trim() !== '');

    return validationMessage
        || requestError?.response?.data?.message
        || requestError?.message
        || fallback;
};

const toggleTarget = (accountId) => {
    if (!canManage.value) {
        return;
    }

    const id = Number(accountId);
    const exists = form.value.target_connection_ids.includes(id);

    form.value.target_connection_ids = exists
        ? form.value.target_connection_ids.filter((value) => value !== id)
        : [...form.value.target_connection_ids, id];
};

const openComposer = (post) => {
    const postId = Number(post?.id || 0);
    if (postId > 0) {
        router.visit(route('social.composer', { draft: postId }));
    }
};

const generateCampaign = async () => {
    if (!canManage.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.post(route('social.campaigns.store'), {
            name: String(form.value.name || '').trim(),
            intention_type: form.value.intention_type,
            brief: String(form.value.brief || '').trim(),
            start_date: form.value.start_date,
            post_count: Number(form.value.post_count || 4),
            duration_days: Number(form.value.duration_days || 7),
            image_url: String(form.value.image_url || '').trim(),
            link_url: String(form.value.link_url || '').trim(),
            target_connection_ids: form.value.target_connection_ids,
        });

        generatedPosts.value = Array.isArray(response.data?.posts) ? response.data.posts : [];
        recentBatches.value = Array.isArray(response.data?.recent_batches) ? response.data.recent_batches : recentBatches.value;
        summary.value = response.data?.summary || summary.value;
        info.value = String(response.data?.message || t('social.campaign_manager.messages.generate_success'));
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.campaign_manager.messages.generate_error'));
    } finally {
        busy.value = false;
    }
};

const formatDate = (value) => {
    if (!value) {
        return t('social.campaign_manager.empty_value');
    }

    try {
        return new Date(value).toLocaleString();
    } catch {
        return t('social.campaign_manager.empty_value');
    }
};

const postLabel = (post) => {
    const text = String(post?.text || '').trim();

    return text !== ''
        ? (text.length > 110 ? `${text.slice(0, 107)}...` : text)
        : t('social.campaign_manager.untitled_post');
};
</script>

<template>
    <div class="space-y-5">
        <div
            v-if="!access.can_manage_posts"
            class="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
        >
            <div class="font-semibold">{{ t('social.campaign_manager.read_only_title') }}</div>
            <div class="mt-1">{{ t('social.campaign_manager.read_only_description') }}</div>
        </div>

        <div
            v-if="error"
            class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300"
        >
            {{ error }}
        </div>

        <div
            v-if="info"
            class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300"
        >
            {{ info }}
        </div>

        <section class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <div class="rounded-md border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                    {{ t('social.campaign_manager.summary.generated') }}
                </div>
                <div class="mt-1 text-xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ generatedPosts.length }}
                </div>
            </div>
            <div class="rounded-md border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                    {{ t('social.campaign_manager.summary.scheduled') }}
                </div>
                <div class="mt-1 text-xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ Number(summary.scheduled || 0) }}
                </div>
            </div>
            <div class="rounded-md border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                    {{ t('social.campaign_manager.summary.targets') }}
                </div>
                <div class="mt-1 text-xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ selectedCount }}
                </div>
            </div>
        </section>

        <section class="grid grid-cols-1 gap-5 xl:grid-cols-[1.15fr,0.85fr]">
            <form class="rounded-md border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" @submit.prevent="generateCampaign">
                <div>
                    <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('social.campaign_manager.form_title') }}
                    </h4>
                    <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('social.campaign_manager.form_description') }}
                    </p>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <FloatingInput
                        v-model="form.name"
                        :label="t('social.campaign_manager.fields.name')"
                        :disabled="busy || !canManage"
                    />

                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                            {{ t('social.campaign_manager.fields.intention_type') }}
                        </span>
                        <select
                            v-model="form.intention_type"
                            class="block w-full rounded-md border border-stone-300 bg-white px-4 py-3 text-sm text-stone-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                            :disabled="busy || !canManage"
                        >
                            <option
                                v-for="option in intentionOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ t(`social.campaign_manager.intentions.${option.value}`) }}
                            </option>
                        </select>
                    </label>
                </div>

                <div class="mt-4">
                    <FloatingTextarea
                        v-model="form.brief"
                        :label="t('social.campaign_manager.fields.brief')"
                        rows="5"
                        :disabled="busy || !canManage"
                    />
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                            {{ t('social.campaign_manager.fields.start_date') }}
                        </span>
                        <input
                            v-model="form.start_date"
                            type="date"
                            class="block w-full rounded-md border border-stone-300 bg-white px-4 py-3 text-sm text-stone-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                            :disabled="busy || !canManage"
                        >
                    </label>

                    <FloatingInput
                        v-model="form.post_count"
                        type="number"
                        min="2"
                        max="8"
                        :label="t('social.campaign_manager.fields.post_count')"
                        :disabled="busy || !canManage"
                    />

                    <FloatingInput
                        v-model="form.duration_days"
                        type="number"
                        min="1"
                        max="30"
                        :label="t('social.campaign_manager.fields.duration_days')"
                        :disabled="busy || !canManage"
                    />
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <FloatingInput
                        v-model="form.link_url"
                        :label="t('social.campaign_manager.fields.link_url')"
                        :disabled="busy || !canManage"
                    />
                    <FloatingInput
                        v-model="form.image_url"
                        :label="t('social.campaign_manager.fields.image_url')"
                        :disabled="busy || !canManage"
                    />
                </div>

                <div class="mt-5">
                    <PrimaryButton type="submit" :disabled="busy || !canManage">
                        {{ busy ? t('social.campaign_manager.actions.generating') : t('social.campaign_manager.actions.generate') }}
                    </PrimaryButton>
                </div>
            </form>

            <section class="rounded-md border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                    {{ t('social.campaign_manager.targets_title') }}
                </h4>
                <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                    {{ t('social.campaign_manager.targets_description') }}
                </p>

                <div v-if="connectedAccounts.length" class="mt-4 space-y-3">
                    <button
                        v-for="account in connectedAccounts"
                        :key="account.id"
                        type="button"
                        class="w-full rounded-md border p-4 text-left transition"
                        :class="form.target_connection_ids.includes(Number(account.id))
                            ? 'border-sky-600 bg-sky-50 dark:border-sky-500 dark:bg-sky-500/10'
                            : 'border-stone-200 bg-stone-50 hover:border-sky-300 dark:border-neutral-700 dark:bg-neutral-800/70 dark:hover:border-sky-500/40'"
                        :disabled="busy || !canManage"
                        @click="toggleTarget(account.id)"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ account.label }}
                                </div>
                                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ account.provider_label || account.platform }}
                                </div>
                            </div>
                            <span class="text-sm font-semibold text-sky-700 dark:text-sky-300">
                                {{ form.target_connection_ids.includes(Number(account.id)) ? t('social.campaign_manager.selected') : '+' }}
                            </span>
                        </div>
                    </button>
                </div>

                <div
                    v-else
                    class="mt-4 rounded-md border border-dashed border-stone-300 bg-stone-50 px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-400"
                >
                    {{ t('social.campaign_manager.empty_targets') }}
                </div>
            </section>
        </section>

        <section v-if="generatedPosts.length" class="rounded-md border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('social.campaign_manager.generated_title') }}
                    </h4>
                    <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('social.campaign_manager.generated_description') }}
                    </p>
                </div>
            </div>

            <div class="mt-4 space-y-3">
                <article
                    v-for="post in generatedPosts"
                    :key="post.id"
                    class="rounded-md border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/60"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ formatDate(post.scheduled_for || post.updated_at) }}
                            </div>
                            <p class="mt-2 whitespace-pre-line text-sm text-stone-700 dark:text-neutral-200">
                                {{ postLabel(post) }}
                            </p>
                        </div>

                        <SecondaryButton type="button" :disabled="busy" @click="openComposer(post)">
                            {{ t('social.campaign_manager.actions.edit_post') }}
                        </SecondaryButton>
                    </div>
                </article>
            </div>
        </section>

        <section class="rounded-md border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                {{ t('social.campaign_manager.recent_title') }}
            </h4>

            <div v-if="recentBatches.length" class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="batch in recentBatches"
                    :key="batch.id"
                    class="rounded-md border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/60"
                >
                    <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                        {{ batch.name }}
                    </div>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ t(`social.campaign_manager.intentions.${batch.intention_type}`) }} · {{ formatDate(batch.generated_at) }}
                    </div>
                    <div class="mt-3 text-sm text-stone-600 dark:text-neutral-300">
                        {{ t('social.campaign_manager.batch_counts', {
                            count: Number(batch.post_count || 0),
                            scheduled: Number(batch.scheduled_count || 0),
                        }) }}
                    </div>
                </div>
            </div>

            <div
                v-else
                class="mt-4 rounded-md border border-dashed border-stone-300 bg-stone-50 px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-400"
            >
                {{ t('social.campaign_manager.empty_recent') }}
            </div>
        </section>
    </div>
</template>
