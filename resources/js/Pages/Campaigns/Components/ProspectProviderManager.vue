<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import FloatingInput from '@/Components/FloatingInput.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ProspectProviderCard from '@/Pages/Campaigns/Components/ProspectProviderCard.vue';
import ProspectProviderLogo from '@/Pages/Campaigns/Components/ProspectProviderLogo.vue';

const props = defineProps({
    initialCards: {
        type: Array,
        default: () => ([]),
    },
    initialSummary: {
        type: Object,
        default: () => ({}),
    },
    canManageSecrets: {
        type: Boolean,
        default: true,
    },
});

const page = usePage();
const { t } = useI18n();

const cards = ref(Array.isArray(props.initialCards) ? props.initialCards : []);
const summary = ref(props.initialSummary && typeof props.initialSummary === 'object' ? props.initialSummary : {});
const access = ref({
    can_manage_secrets: Boolean(props.canManageSecrets),
});
const busy = ref(false);
const isLoading = ref(false);
const error = ref('');
const info = ref('');
const activeProviderKey = ref(null);
const form = ref({
    label: '',
    credentials: {
        api_key: '',
    },
});

const flash = computed(() => page.props?.flash || {});
const activeCard = computed(() => cards.value.find((card) => card.key === activeProviderKey.value) || null);
const activeConnection = computed(() => activeCard.value?.connection || null);
const canManage = computed(() => Boolean(access.value.can_manage_secrets));
const modalOpen = computed(() => Boolean(activeCard.value));

const normalizedSummary = computed(() => ({
    configured: Number(summary.value?.configured || 0),
    connected: Number(summary.value?.connected || 0),
    attention: Number(summary.value?.attention || 0),
}));

const summaryCards = computed(() => ([
    { key: 'configured', value: normalizedSummary.value.configured },
    { key: 'connected', value: normalizedSummary.value.connected },
    { key: 'attention', value: normalizedSummary.value.attention },
]));

const statusClass = (status) => {
    if (status === 'connected') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300';
    }

    if (status === 'pending') {
        return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300';
    }

    if (status === 'not_connected') {
        return 'border-stone-200 bg-stone-50 text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300';
    }

    if (status === 'setup_required') {
        return 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-300';
    }

    if (status === 'expired' || status === 'reconnect_required' || status === 'draft') {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300';
    }

    return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300';
};

const statusLabel = (status) => t(`marketing.prospect_provider_manager.statuses.${status || 'not_connected'}`);

const primaryActionLabel = computed(() => {
    if (!activeCard.value) {
        return '';
    }

    if (!canManage.value) {
        return t('marketing.common.close');
    }

    if (!activeConnection.value) {
        return t('marketing.prospect_provider_manager.actions.connect');
    }

    if (activeCard.value.auth_strategy === 'oauth') {
        return t('marketing.prospect_provider_manager.actions.reconnect');
    }

    return t('marketing.prospect_provider_manager.actions.save');
});

const resetModalForm = (card) => {
    form.value = {
        label: String(card?.connection?.label || `${card?.label || ''} ${card?.auth_strategy === 'oauth' ? 'workspace' : 'account'}`).trim(),
        credentials: {
            api_key: '',
        },
    };
};

const openProvider = (card) => {
    activeProviderKey.value = card.key;
    resetModalForm(card);
    error.value = '';
    info.value = '';
};

const closeModal = () => {
    activeProviderKey.value = null;
    error.value = '';
};

watch(flash, (value) => {
    const success = String(value?.success || '').trim();
    const failure = String(value?.error || '').trim();

    if (success) {
        info.value = success;
    }

    if (failure) {
        error.value = failure;
    }
}, { immediate: true, deep: true });

const normalizeCardsPayload = (payload) => Array.isArray(payload) ? payload : [];
const normalizeSummaryPayload = (payload) => (payload && typeof payload === 'object') ? payload : {};

const syncActiveCard = () => {
    if (!activeProviderKey.value) {
        return;
    }

    const card = cards.value.find((item) => item.key === activeProviderKey.value);
    if (!card) {
        activeProviderKey.value = null;
        return;
    }

    resetModalForm(card);
};

const load = async () => {
    isLoading.value = true;

    try {
        const response = await axios.get(route('marketing.prospect-providers.index'));
        cards.value = normalizeCardsPayload(response.data?.provider_cards);
        summary.value = normalizeSummaryPayload(response.data?.provider_summary);
        access.value = {
            can_manage_secrets: Boolean(response.data?.access?.can_manage_secrets ?? props.canManageSecrets),
        };
        syncActiveCard();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.prospect_provider_manager.error_load');
    } finally {
        isLoading.value = false;
    }
};

const connectOrReconnect = async () => {
    if (!canManage.value || !activeCard.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const payload = {
            label: String(form.value.label || '').trim(),
        };

        if (activeCard.value.auth_strategy === 'api_key') {
            payload.credentials = {
                api_key: String(form.value.credentials?.api_key || '').trim(),
            };
        }

        const response = activeConnection.value
            ? await axios.post(route('marketing.prospect-providers.reconnect', activeConnection.value.id), payload)
            : await axios.post(route('marketing.prospect-providers.connect'), {
                provider_key: activeCard.value.key,
                ...payload,
            });

        if (response.data?.redirect_url) {
            window.location.assign(response.data.redirect_url);
            return;
        }

        info.value = response.data?.message || t('marketing.prospect_provider_manager.info_connected');
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.prospect_provider_manager.error_connect');
    } finally {
        busy.value = false;
    }
};

const refreshProvider = async () => {
    if (!canManage.value || !activeConnection.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.post(route('marketing.prospect-providers.refresh', activeConnection.value.id));
        info.value = response.data?.message || t('marketing.prospect_provider_manager.info_refreshed');
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.prospect_provider_manager.error_refresh');
    } finally {
        busy.value = false;
    }
};

const disconnectProvider = async () => {
    if (!canManage.value || !activeConnection.value || !activeCard.value) {
        return;
    }

    if (!confirm(t('marketing.prospect_provider_manager.confirm_disconnect', {
        name: activeConnection.value.label || activeCard.value.label,
    }))) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.post(route('marketing.prospect-providers.disconnect', activeConnection.value.id));
        info.value = response.data?.message || t('marketing.prospect_provider_manager.info_disconnected');
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.prospect_provider_manager.error_disconnect');
    } finally {
        busy.value = false;
    }
};

load();
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-lg font-semibold text-stone-900 dark:text-neutral-100">
                    {{ t('marketing.prospect_provider_manager.title') }}
                </h3>
                <p class="mt-1 max-w-3xl text-sm text-stone-500 dark:text-neutral-400">
                    {{ t('marketing.prospect_provider_manager.description') }}
                </p>
            </div>

            <SecondaryButton :disabled="busy || isLoading" @click="load">
                {{ t('marketing.common.reload') }}
            </SecondaryButton>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <div
                v-for="card in summaryCards"
                :key="`provider-summary-${card.key}`"
                class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            >
                <div class="text-xs uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                    {{ t(`marketing.prospect_provider_manager.summary.${card.key}`) }}
                </div>
                <div class="mt-2 text-3xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ card.value }}
                </div>
            </div>
        </div>

        <div
            v-if="!access.can_manage_secrets"
            class="rounded-3xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
        >
            <div class="font-semibold">{{ t('marketing.prospect_provider_manager.read_only_title') }}</div>
            <div class="mt-1">{{ t('marketing.prospect_provider_manager.read_only_description') }}</div>
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

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-3 md:grid-cols-2">
            <ProspectProviderCard
                v-for="card in cards"
                :key="card.key"
                :card="card"
                :can-manage-secrets="access.can_manage_secrets"
                @select="openProvider"
            />
        </div>

        <Modal :show="modalOpen" max-width="3xl" @close="closeModal">
            <div v-if="activeCard" class="space-y-5 p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-start gap-4">
                        <ProspectProviderLogo :provider-key="String(activeCard.logo_key || activeCard.key || '')" size-class="size-14" />
                        <div class="space-y-2">
                            <div>
                                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                    {{ activeCard.auth_strategy === 'oauth' ? 'OAuth' : 'API key' }}
                                </div>
                                <h4 class="text-xl font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ activeCard.label }}
                                </h4>
                            </div>
                            <p class="max-w-2xl text-sm leading-6 text-stone-600 dark:text-neutral-300">
                                {{ activeCard.connect_description }}
                            </p>
                        </div>
                    </div>

                    <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold" :class="statusClass(activeCard.display_status)">
                        {{ statusLabel(activeCard.display_status) }}
                    </span>
                </div>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1.1fr,0.9fr]">
                    <div class="space-y-4 rounded-3xl border border-stone-200 bg-stone-50/90 p-4 dark:border-neutral-700 dark:bg-neutral-800/70">
                        <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ t('marketing.prospect_provider_manager.modal.connection_title') }}
                        </div>

                        <template v-if="activeConnection">
                            <div class="space-y-3 text-sm text-stone-600 dark:text-neutral-300">
                                <div>
                                    <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                        {{ t('marketing.prospect_provider_manager.fields.label') }}
                                    </div>
                                    <div class="mt-1 font-medium text-stone-900 dark:text-neutral-100">
                                        {{ activeConnection.label }}
                                    </div>
                                </div>

                                <div v-if="activeConnection.external_account_label">
                                    <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                        {{ t('marketing.prospect_provider_manager.modal.connected_as') }}
                                    </div>
                                    <div class="mt-1 font-medium text-stone-900 dark:text-neutral-100">
                                        {{ activeConnection.external_account_label }}
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div>
                                        <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                            {{ t('marketing.prospect_provider_manager.modal.connected_at') }}
                                        </div>
                                        <div class="mt-1">
                                            {{ activeConnection.connected_at ? new Date(activeConnection.connected_at).toLocaleString() : t('marketing.prospect_provider_manager.not_connected') }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                            {{ t('marketing.prospect_provider_manager.modal.last_refresh') }}
                                        </div>
                                        <div class="mt-1">
                                            {{ activeConnection.last_refreshed_at ? new Date(activeConnection.last_refreshed_at).toLocaleString() : t('marketing.prospect_provider_manager.not_validated') }}
                                        </div>
                                    </div>
                                </div>

                                <div v-if="activeConnection.token_expires_at">
                                    <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                        {{ t('marketing.prospect_provider_manager.modal.expires_at') }}
                                    </div>
                                    <div class="mt-1">
                                        {{ new Date(activeConnection.token_expires_at).toLocaleString() }}
                                    </div>
                                </div>

                                <div v-if="activeConnection.last_error" class="rounded-2xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                                    {{ activeConnection.last_error }}
                                </div>
                            </div>
                        </template>

                        <template v-else>
                            <div class="rounded-2xl border border-dashed border-stone-300 bg-white px-4 py-4 text-sm text-stone-500 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-400">
                                {{ t('marketing.prospect_provider_manager.modal.no_connection_description') }}
                            </div>
                        </template>

                        <div v-if="activeCard.scopes?.length" class="space-y-2">
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('marketing.prospect_provider_manager.modal.requested_scopes') }}
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="scope in activeCard.scopes"
                                    :key="`${activeCard.key}-scope-${scope}`"
                                    class="rounded-full border border-stone-200 bg-white px-2.5 py-1 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                                >
                                    {{ scope }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4 rounded-3xl border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ canManage ? t('marketing.prospect_provider_manager.modal.manage_title') : t('marketing.prospect_provider_manager.modal.details_title') }}
                        </div>

                        <div
                            v-if="activeCard.setup_required"
                            class="rounded-2xl border border-violet-200 bg-violet-50 px-3 py-3 text-sm text-violet-700 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-300"
                        >
                            {{ activeCard.setup_message }}
                        </div>

                        <template v-if="canManage">
                            <FloatingInput
                                v-model="form.label"
                                :label="t('marketing.prospect_provider_manager.fields.label')"
                            />

                            <template v-if="activeCard.auth_strategy === 'api_key'">
                                <FloatingInput
                                    v-model="form.credentials.api_key"
                                    type="password"
                                    :label="t('marketing.prospect_provider_manager.fields.api_key')"
                                />
                                <div class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-3 py-3 text-xs text-stone-500 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                                    {{ activeConnection?.has_credentials ? t('marketing.prospect_provider_manager.secret_update_hint') : t('marketing.prospect_provider_manager.secret_store_hint') }}
                                </div>
                            </template>

                            <template v-else>
                                <div class="rounded-2xl border border-stone-200 bg-stone-50 px-3 py-3 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                    {{ t('marketing.prospect_provider_manager.oauth_hint') }}
                                </div>
                            </template>

                            <div class="flex flex-wrap items-center gap-2 pt-2">
                                <PrimaryButton
                                    type="button"
                                    :disabled="busy || activeCard.setup_required"
                                    @click="connectOrReconnect"
                                >
                                    {{ primaryActionLabel }}
                                </PrimaryButton>

                                <SecondaryButton
                                    v-if="activeConnection && activeCard.supports_refresh"
                                    type="button"
                                    :disabled="busy"
                                    @click="refreshProvider"
                                >
                                    {{ t('marketing.prospect_provider_manager.actions.refresh') }}
                                </SecondaryButton>

                                <button
                                    v-if="activeConnection"
                                    type="button"
                                    class="rounded-full border border-rose-200 bg-rose-50 px-4 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20"
                                    :disabled="busy"
                                    @click="disconnectProvider"
                                >
                                    {{ t('marketing.prospect_provider_manager.actions.disconnect') }}
                                </button>
                            </div>
                        </template>

                        <template v-else>
                            <div class="rounded-2xl border border-stone-200 bg-stone-50 px-3 py-3 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                {{ t('marketing.prospect_provider_manager.view_only_actions') }}
                            </div>
                        </template>
                    </div>
                </div>

                <div class="flex justify-end">
                    <SecondaryButton type="button" :disabled="busy" @click="closeModal">
                        {{ t('marketing.common.close') }}
                    </SecondaryButton>
                </div>
            </div>
        </Modal>
    </div>
</template>
