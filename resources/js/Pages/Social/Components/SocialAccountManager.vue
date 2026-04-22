<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import FloatingInput from '@/Components/FloatingInput.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    initialDefinitions: {
        type: Array,
        default: () => ([]),
    },
    initialConnections: {
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

const page = usePage();
const { t } = useI18n();

const normalizeDefinitions = (payload) => Array.isArray(payload) ? payload : [];
const normalizeConnections = (payload) => Array.isArray(payload) ? payload : [];
const normalizeSummary = (payload) => payload && typeof payload === 'object' ? payload : {};
const normalizeAccess = (payload) => ({
    can_view: Boolean(payload?.can_view),
    can_manage_accounts: Boolean(payload?.can_manage_accounts),
});

const definitions = ref(normalizeDefinitions(props.initialDefinitions));
const connections = ref(normalizeConnections(props.initialConnections));
const summary = ref(normalizeSummary(props.initialSummary));
const access = ref(normalizeAccess(props.initialAccess));
const busy = ref(false);
const isLoading = ref(false);
const error = ref('');
const info = ref('');
const modalMode = ref(null);
const activePlatform = ref(null);
const activeConnectionId = ref(null);
const form = ref({
    label: '',
    display_name: '',
    account_handle: '',
    external_account_id: '',
    is_active: false,
});

const flash = computed(() => page.props?.flash || {});
const canManage = computed(() => Boolean(access.value.can_manage_accounts));
const modalOpen = computed(() => Boolean(activeProvider.value));
const activeConnection = computed(() => (
    connections.value.find((connection) => Number(connection.id) === Number(activeConnectionId.value)) || null
));
const activeProvider = computed(() => {
    const platform = modalMode.value === 'edit'
        ? activeConnection.value?.platform
        : activePlatform.value;

    return definitions.value.find((definition) => definition.key === platform) || null;
});

const summaryCards = computed(() => ([
    {
        key: 'configured',
        value: Number(summary.value?.configured || 0),
    },
    {
        key: 'connected',
        value: Number(summary.value?.connected || 0),
    },
    {
        key: 'attention',
        value: Number(summary.value?.attention || 0),
    },
    {
        key: 'inactive',
        value: Number(summary.value?.inactive || 0),
    },
]));

const providerCards = computed(() => definitions.value.map((definition) => {
    const platformConnections = connections.value.filter((connection) => connection.platform === definition.key);

    return {
        ...definition,
        connection_count: platformConnections.length,
        connected_count: platformConnections.filter((connection) => connection.is_connected).length,
        attention_count: platformConnections.filter((connection) => connection.needs_attention).length,
    };
}));

const statusOrder = {
    connected: 0,
    pending: 1,
    draft: 2,
    reconnect_required: 3,
    error: 4,
    expired: 5,
    disconnected: 6,
};

const sortedConnections = computed(() => [...connections.value].sort((left, right) => {
    const leftStatusWeight = statusOrder[left.status] ?? 99;
    const rightStatusWeight = statusOrder[right.status] ?? 99;

    if (leftStatusWeight !== rightStatusWeight) {
        return leftStatusWeight - rightStatusWeight;
    }

    if (Boolean(left.is_active) !== Boolean(right.is_active)) {
        return left.is_active ? -1 : 1;
    }

    return String(left.label || '').localeCompare(String(right.label || ''));
}));

const syncFormFromConnection = (connection) => {
    form.value = {
        label: String(connection?.label || ''),
        display_name: String(connection?.display_name || ''),
        account_handle: String(connection?.account_handle || ''),
        external_account_id: String(connection?.external_account_id || ''),
        is_active: Boolean(connection?.is_active),
    };
};

const prepareBlankForm = (definition) => {
    form.value = {
        label: String(definition?.label || '').trim() ? `${definition.label} account` : '',
        display_name: '',
        account_handle: '',
        external_account_id: '',
        is_active: false,
    };
};

const closeModal = () => {
    modalMode.value = null;
    activePlatform.value = null;
    activeConnectionId.value = null;
    error.value = '';
};

const openCreate = (definition) => {
    modalMode.value = 'create';
    activePlatform.value = definition.key;
    activeConnectionId.value = null;
    prepareBlankForm(definition);
    error.value = '';
};

const openEdit = (connection) => {
    modalMode.value = 'edit';
    activePlatform.value = connection.platform;
    activeConnectionId.value = connection.id;
    syncFormFromConnection(connection);
    error.value = '';
};

watch(() => props.initialDefinitions, (value) => {
    definitions.value = normalizeDefinitions(value);
}, { deep: true });

watch(() => props.initialConnections, (value) => {
    connections.value = normalizeConnections(value);
}, { deep: true });

watch(() => props.initialSummary, (value) => {
    summary.value = normalizeSummary(value);
}, { deep: true });

watch(() => props.initialAccess, (value) => {
    access.value = normalizeAccess(value);
}, { deep: true });

watch(flash, (value) => {
    const success = String(value?.success || '').trim();
    const failure = String(value?.error || '').trim();

    if (success !== '') {
        info.value = success;
    }

    if (failure !== '') {
        error.value = failure;
    }
}, { immediate: true, deep: true });

const statusClass = (status) => {
    if (status === 'connected') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300';
    }

    if (status === 'pending') {
        return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300';
    }

    if (status === 'draft' || status === 'expired' || status === 'reconnect_required') {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300';
    }

    if (status === 'disconnected') {
        return 'border-stone-200 bg-stone-50 text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300';
    }

    return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300';
};

const statusLabel = (status) => t(`social.accounts_manager.statuses.${status || 'draft'}`);

const formatDate = (value) => {
    if (!value) {
        return t('social.accounts_manager.empty_value');
    }

    try {
        return new Date(value).toLocaleString();
    } catch {
        return t('social.accounts_manager.empty_value');
    }
};

const requestErrorMessage = (requestError, fallback) => {
    const validationMessage = Object.values(requestError?.response?.data?.errors || {})
        .flat()
        .find((value) => typeof value === 'string' && value.trim() !== '');

    return validationMessage
        || requestError?.response?.data?.message
        || requestError?.message
        || fallback;
};

const load = async () => {
    isLoading.value = true;
    error.value = '';

    try {
        const response = await axios.get(route('social.accounts.index'));

        definitions.value = normalizeDefinitions(response.data?.provider_definitions);
        connections.value = normalizeConnections(response.data?.connections);
        summary.value = normalizeSummary(response.data?.summary);
        access.value = normalizeAccess(response.data?.access);

        if (modalMode.value === 'edit' && activeConnectionId.value) {
            const refreshedConnection = connections.value.find((connection) => Number(connection.id) === Number(activeConnectionId.value));

            if (refreshedConnection) {
                syncFormFromConnection(refreshedConnection);
            } else {
                closeModal();
            }
        }
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.accounts_manager.messages.load_error'));
    } finally {
        isLoading.value = false;
    }
};

const submit = async () => {
    if (!canManage.value || !activeProvider.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    const payload = {
        label: String(form.value.label || '').trim(),
        display_name: String(form.value.display_name || '').trim(),
        account_handle: String(form.value.account_handle || '').trim(),
        external_account_id: String(form.value.external_account_id || '').trim(),
    };

    if (modalMode.value === 'edit' && activeConnection.value?.status === 'connected') {
        payload.is_active = Boolean(form.value.is_active);
    }

    try {
        await (modalMode.value === 'edit' && activeConnection.value
            ? axios.put(route('social.accounts.update', activeConnection.value.id), payload)
            : axios.post(route('social.accounts.store'), {
                platform: activeProvider.value.key,
                ...payload,
            }));

        info.value = t('social.accounts_manager.messages.save_success');
        await load();
        closeModal();
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.accounts_manager.messages.save_error'));
    } finally {
        busy.value = false;
    }
};

const oauthActionLabel = (connection) => {
    const status = String(connection?.status || '');

    return ['pending', 'error', 'reconnect_required', 'expired', 'disconnected'].includes(status)
        ? t('social.accounts_manager.actions.reconnect_oauth')
        : t('social.accounts_manager.actions.connect_oauth');
};

const authorizeConnection = async (connection) => {
    if (!canManage.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.post(route('social.accounts.authorize', connection.id));
        const redirectUrl = String(response.data?.redirect_url || '').trim();

        info.value = String(response.data?.message || t('social.accounts_manager.messages.authorize_redirect'));

        if (redirectUrl !== '') {
            window.location.assign(redirectUrl);
            return;
        }

        await load();
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.accounts_manager.messages.authorize_error'));
    } finally {
        busy.value = false;
    }
};

const refreshConnectionTokens = async (connection) => {
    if (!canManage.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        await axios.post(route('social.accounts.refresh', connection.id));
        info.value = t('social.accounts_manager.messages.refresh_success');
        await load();
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.accounts_manager.messages.refresh_error'));
    } finally {
        busy.value = false;
    }
};

const disconnectConnection = async () => {
    if (!canManage.value || !activeConnection.value) {
        return;
    }

    const name = activeConnection.value.label || activeProvider.value?.label || 'Pulse account';
    if (!window.confirm(t('social.accounts_manager.messages.confirm_disconnect', { name }))) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        await axios.post(route('social.accounts.disconnect', activeConnection.value.id));
        info.value = t('social.accounts_manager.messages.disconnect_success');
        await load();
        closeModal();
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.accounts_manager.messages.disconnect_error'));
    } finally {
        busy.value = false;
    }
};

const deleteConnection = async () => {
    if (!canManage.value || !activeConnection.value) {
        return;
    }

    const name = activeConnection.value.label || activeProvider.value?.label || 'Pulse account';
    if (!window.confirm(t('social.accounts_manager.messages.confirm_delete', { name }))) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        await axios.delete(route('social.accounts.destroy', activeConnection.value.id));
        info.value = t('social.accounts_manager.messages.delete_success');
        await load();
        closeModal();
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.accounts_manager.messages.delete_error'));
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-lg font-semibold text-stone-900 dark:text-neutral-100">
                    {{ t('social.accounts_manager.title') }}
                </h3>
                <p class="mt-1 max-w-3xl text-sm text-stone-500 dark:text-neutral-400">
                    {{ t('social.accounts_manager.description') }}
                </p>
            </div>

            <SecondaryButton :disabled="busy || isLoading" @click="load">
                {{ t('social.accounts_manager.reload') }}
            </SecondaryButton>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
            <div
                v-for="card in summaryCards"
                :key="`social-summary-${card.key}`"
                class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            >
                <div class="text-xs uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                    {{ t(`social.accounts_manager.summary.${card.key}`) }}
                </div>
                <div class="mt-2 text-3xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ card.value }}
                </div>
            </div>
        </div>

        <div
            v-if="!access.can_manage_accounts"
            class="rounded-3xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
        >
            <div class="font-semibold">{{ t('social.accounts_manager.read_only_title') }}</div>
            <div class="mt-1">{{ t('social.accounts_manager.read_only_description') }}</div>
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

        <section class="space-y-3">
            <div>
                <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                    {{ t('social.accounts_manager.provider_directory_title') }}
                </h4>
                <p class="mt-1 max-w-3xl text-sm text-stone-500 dark:text-neutral-400">
                    {{ t('social.accounts_manager.provider_directory_description') }}
                </p>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article
                    v-for="definition in providerCards"
                    :key="definition.key"
                    class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ definition.target_type || t('social.accounts_manager.empty_value') }}
                            </div>
                            <h5 class="mt-1 text-base font-semibold text-stone-900 dark:text-neutral-100">
                                {{ definition.label }}
                            </h5>
                        </div>
                        <div class="rounded-2xl bg-sky-50 px-2.5 py-1 text-xs font-semibold text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">
                            {{ t('social.accounts_manager.provider_accounts_count', { count: definition.connection_count }) }}
                        </div>
                    </div>

                    <p class="mt-3 text-sm leading-6 text-stone-600 dark:text-neutral-300">
                        {{ definition.short_description }}
                    </p>

                    <div
                        v-if="definition.setup_required && definition.setup_message"
                        class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300"
                    >
                        {{ definition.setup_message }}
                    </div>

                    <div class="mt-4 space-y-3 text-sm text-stone-600 dark:text-neutral-300">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.accounts_manager.provider_target_type') }}
                            </span>
                            <span class="font-medium text-stone-900 dark:text-neutral-100">
                                {{ definition.target_type || t('social.accounts_manager.empty_value') }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.accounts_manager.provider_scopes') }}
                            </span>
                            <span class="font-medium text-stone-900 dark:text-neutral-100">
                                {{ definition.scopes?.length || 0 }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.accounts_manager.summary.connected') }}
                            </span>
                            <span class="font-medium text-stone-900 dark:text-neutral-100">
                                {{ t('social.accounts_manager.provider_connected_count', { count: definition.connected_count }) }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <span
                            v-for="support in definition.supports || []"
                            :key="`${definition.key}-support-${support}`"
                            class="rounded-full border border-stone-200 bg-stone-50 px-2.5 py-1 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                        >
                            {{ support }}
                        </span>
                    </div>

                    <div class="mt-4">
                        <PrimaryButton
                            type="button"
                            :disabled="busy || isLoading"
                            @click="openCreate(definition)"
                        >
                            {{ canManage ? t('social.accounts_manager.provider_prepare') : t('social.accounts_manager.provider_view') }}
                        </PrimaryButton>
                    </div>
                </article>
            </div>
        </section>

        <section class="space-y-3">
            <div>
                <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                    {{ t('social.accounts_manager.connection_library_title') }}
                </h4>
                <p class="mt-1 max-w-3xl text-sm text-stone-500 dark:text-neutral-400">
                    {{ t('social.accounts_manager.connection_library_description') }}
                </p>
            </div>

            <div v-if="sortedConnections.length" class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <article
                    v-for="connection in sortedConnections"
                    :key="connection.id"
                    class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="space-y-1">
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ connection.provider_label }}
                            </div>
                            <h5 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                                {{ connection.label }}
                            </h5>
                            <p class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ connection.display_name || connection.account_handle || connection.external_account_id || t('social.accounts_manager.empty_value') }}
                            </p>
                        </div>

                        <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold" :class="statusClass(connection.status)">
                            {{ statusLabel(connection.status) }}
                        </span>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-3 text-sm text-stone-600 dark:text-neutral-300 sm:grid-cols-2">
                        <div>
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.accounts_manager.fields.account_handle') }}
                            </div>
                            <div class="mt-1">{{ connection.account_handle || t('social.accounts_manager.empty_value') }}</div>
                        </div>

                        <div>
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.accounts_manager.fields.external_account_id') }}
                            </div>
                            <div class="mt-1">{{ connection.external_account_id || t('social.accounts_manager.empty_value') }}</div>
                        </div>

                        <div>
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.accounts_manager.modal.connected_at') }}
                            </div>
                            <div class="mt-1">{{ formatDate(connection.connected_at) }}</div>
                        </div>

                        <div>
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.accounts_manager.modal.last_synced_at') }}
                            </div>
                            <div class="mt-1">{{ formatDate(connection.last_synced_at) }}</div>
                        </div>
                    </div>

                    <div v-if="connection.metadata?.connection_flow === 'oauth_scaffold'" class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300">
                        <div class="font-semibold">{{ t('social.accounts_manager.oauth_scaffold_badge') }}</div>
                        <div class="mt-1">{{ t('social.accounts_manager.oauth_scaffold_description') }}</div>
                    </div>

                    <div
                        v-if="connection.setup_required && connection.setup_message"
                        class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300"
                    >
                        {{ connection.setup_message }}
                    </div>

                    <div v-if="connection.last_error" class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 px-3 py-3 text-sm text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                        {{ connection.last_error }}
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <span
                            v-for="permission in connection.permissions || []"
                            :key="`${connection.id}-permission-${permission}`"
                            class="rounded-full border border-stone-200 bg-stone-50 px-2.5 py-1 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                        >
                            {{ permission }}
                        </span>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <PrimaryButton
                            v-if="canManage && connection.auth_method === 'oauth' && connection.status !== 'connected'"
                            type="button"
                            :disabled="busy || isLoading || connection.setup_required"
                            @click="authorizeConnection(connection)"
                        >
                            {{ oauthActionLabel(connection) }}
                        </PrimaryButton>

                        <SecondaryButton
                            v-if="canManage && connection.is_connected && connection.supports_refresh && connection.has_refresh_token"
                            type="button"
                            :disabled="busy || isLoading"
                            @click="refreshConnectionTokens(connection)"
                        >
                            {{ t('social.accounts_manager.actions.refresh_tokens') }}
                        </SecondaryButton>

                        <SecondaryButton type="button" :disabled="busy" @click="openEdit(connection)">
                            {{ canManage ? t('social.accounts_manager.edit_details') : t('social.accounts_manager.view_details') }}
                        </SecondaryButton>
                    </div>
                </article>
            </div>

            <div
                v-else
                class="rounded-3xl border border-dashed border-stone-300 bg-white px-5 py-8 text-center text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400"
            >
                <div class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                    {{ t('social.accounts_manager.empty_title') }}
                </div>
                <div class="mt-2">
                    {{ t('social.accounts_manager.empty_description') }}
                </div>
            </div>
        </section>

        <Modal :show="modalOpen" max-width="4xl" @close="closeModal">
            <div v-if="activeProvider" class="space-y-5 p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-2">
                        <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                            {{ activeProvider.target_type || t('social.accounts_manager.empty_value') }}
                        </div>
                        <h4 class="text-xl font-semibold text-stone-900 dark:text-neutral-100">
                            {{ modalMode === 'edit' ? t('social.accounts_manager.modal.edit_title') : t('social.accounts_manager.modal.create_title') }}
                        </h4>
                        <p class="max-w-2xl text-sm leading-6 text-stone-600 dark:text-neutral-300">
                            {{ activeProvider.short_description }}
                        </p>
                    </div>

                    <span
                        class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold"
                        :class="statusClass(activeConnection?.status || 'draft')"
                    >
                        {{ statusLabel(activeConnection?.status || 'draft') }}
                    </span>
                </div>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1.1fr,0.9fr]">
                    <div class="space-y-4 rounded-3xl border border-stone-200 bg-stone-50/90 p-4 dark:border-neutral-700 dark:bg-neutral-800/70">
                        <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ t('social.accounts_manager.modal.details_title') }}
                        </div>

                        <div class="space-y-3 text-sm text-stone-600 dark:text-neutral-300">
                            <div>
                                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                    {{ t('social.accounts_manager.modal.provider_target') }}
                                </div>
                                <div class="mt-1 font-medium text-stone-900 dark:text-neutral-100">
                                    {{ activeProvider.target_type || t('social.accounts_manager.empty_value') }}
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                    <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                        {{ t('social.accounts_manager.modal.connected_at') }}
                                    </div>
                                    <div class="mt-1">
                                        {{ formatDate(activeConnection?.connected_at) }}
                                    </div>
                                </div>

                                <div>
                                    <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                        {{ t('social.accounts_manager.modal.last_synced_at') }}
                                    </div>
                                    <div class="mt-1">
                                        {{ formatDate(activeConnection?.last_synced_at) }}
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                    {{ t('social.accounts_manager.modal.token_expires_at') }}
                                </div>
                                <div class="mt-1">
                                    {{ formatDate(activeConnection?.token_expires_at) }}
                                </div>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300">
                            <div class="font-semibold">{{ t('social.accounts_manager.oauth_scaffold_badge') }}</div>
                            <div class="mt-1">
                                {{ activeConnection ? t('social.accounts_manager.modal.draft_notice') : t('social.accounts_manager.oauth_scaffold_description') }}
                            </div>
                        </div>

                        <div
                            v-if="activeProvider.setup_required && activeProvider.setup_message"
                            class="rounded-2xl border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300"
                        >
                            {{ activeProvider.setup_message }}
                        </div>

                        <div class="space-y-2">
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.accounts_manager.modal.platform_capabilities') }}
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="support in activeProvider.supports || []"
                                    :key="`${activeProvider.key}-modal-support-${support}`"
                                    class="rounded-full border border-stone-200 bg-white px-2.5 py-1 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                                >
                                    {{ support }}
                                </span>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.accounts_manager.modal.requested_scopes') }}
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="scope in activeProvider.scopes || []"
                                    :key="`${activeProvider.key}-modal-scope-${scope}`"
                                    class="rounded-full border border-stone-200 bg-white px-2.5 py-1 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                                >
                                    {{ scope }}
                                </span>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.accounts_manager.modal.permissions') }}
                            </div>
                            <div v-if="activeConnection?.permissions?.length" class="flex flex-wrap gap-2">
                                <span
                                    v-for="permission in activeConnection.permissions"
                                    :key="`${activeConnection.id}-modal-permission-${permission}`"
                                    class="rounded-full border border-stone-200 bg-white px-2.5 py-1 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                                >
                                    {{ permission }}
                                </span>
                            </div>
                            <div v-else class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ t('social.accounts_manager.modal.no_permissions') }}
                            </div>
                        </div>

                        <div v-if="activeConnection?.last_error" class="rounded-2xl border border-rose-200 bg-rose-50 px-3 py-3 text-sm text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                            <div class="font-semibold">{{ t('social.accounts_manager.modal.last_error') }}</div>
                            <div class="mt-1">{{ activeConnection.last_error }}</div>
                        </div>
                    </div>

                    <div class="space-y-4 rounded-3xl border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ canManage ? t('social.accounts_manager.modal.manage_title') : t('social.accounts_manager.modal.details_title') }}
                        </div>

                        <template v-if="canManage">
                            <div class="space-y-2">
                                <FloatingInput
                                    v-model="form.label"
                                    :label="t('social.accounts_manager.fields.label')"
                                />
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ t('social.accounts_manager.field_hints.label') }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <FloatingInput
                                    v-model="form.display_name"
                                    :label="t('social.accounts_manager.fields.display_name')"
                                />
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ t('social.accounts_manager.field_hints.display_name') }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <FloatingInput
                                    v-model="form.account_handle"
                                    :label="t('social.accounts_manager.fields.account_handle')"
                                />
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ t('social.accounts_manager.field_hints.account_handle') }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <FloatingInput
                                    v-model="form.external_account_id"
                                    :label="t('social.accounts_manager.fields.external_account_id')"
                                />
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ t('social.accounts_manager.field_hints.external_account_id') }}
                                </p>
                            </div>

                            <label
                                v-if="activeConnection?.status === 'connected'"
                                class="flex items-start gap-3 rounded-2xl border border-stone-200 bg-stone-50 px-3 py-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                            >
                                <input
                                    v-model="form.is_active"
                                    type="checkbox"
                                    class="mt-0.5 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:border-neutral-600 dark:bg-neutral-900 dark:checked:bg-green-500"
                                >
                                <span>
                                    <span class="block font-medium">{{ t('social.accounts_manager.modal.connected_toggle_label') }}</span>
                                    <span class="mt-1 block text-xs text-stone-500 dark:text-neutral-400">
                                        {{ t('social.accounts_manager.modal.connected_toggle_hint') }}
                                    </span>
                                </span>
                            </label>

                            <div class="flex flex-wrap items-center gap-2 pt-2">
                                <PrimaryButton type="button" :disabled="busy || isLoading" @click="submit">
                                    {{ modalMode === 'edit' ? t('social.accounts_manager.actions.update_account') : t('social.accounts_manager.actions.save_draft') }}
                                </PrimaryButton>

                                <SecondaryButton
                                    v-if="activeConnection && activeConnection.auth_method === 'oauth' && activeConnection.status !== 'connected'"
                                    type="button"
                                    :disabled="busy || isLoading || activeConnection.setup_required"
                                    @click="authorizeConnection(activeConnection)"
                                >
                                    {{ oauthActionLabel(activeConnection) }}
                                </SecondaryButton>

                                <SecondaryButton
                                    v-if="activeConnection && activeConnection.is_connected && activeConnection.supports_refresh && activeConnection.has_refresh_token"
                                    type="button"
                                    :disabled="busy || isLoading"
                                    @click="refreshConnectionTokens(activeConnection)"
                                >
                                    {{ t('social.accounts_manager.actions.refresh_tokens') }}
                                </SecondaryButton>

                                <SecondaryButton type="button" :disabled="busy" @click="closeModal">
                                    {{ t('social.accounts_manager.actions.close') }}
                                </SecondaryButton>

                                <button
                                    v-if="activeConnection"
                                    type="button"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-4 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300 dark:hover:bg-amber-500/20"
                                    :disabled="busy"
                                    @click="disconnectConnection"
                                >
                                    {{ t('social.accounts_manager.actions.disconnect') }}
                                </button>

                                <button
                                    v-if="activeConnection"
                                    type="button"
                                    class="rounded-full border border-rose-200 bg-rose-50 px-4 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20"
                                    :disabled="busy"
                                    @click="deleteConnection"
                                >
                                    {{ t('social.accounts_manager.actions.delete') }}
                                </button>
                            </div>
                        </template>

                        <template v-else>
                            <div class="rounded-2xl border border-stone-200 bg-stone-50 px-3 py-3 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                {{ t('social.accounts_manager.modal.read_only_notice') }}
                            </div>

                            <div class="grid grid-cols-1 gap-3 text-sm text-stone-600 dark:text-neutral-300">
                                <div>
                                    <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                        {{ t('social.accounts_manager.fields.label') }}
                                    </div>
                                    <div class="mt-1 font-medium text-stone-900 dark:text-neutral-100">
                                        {{ activeConnection?.label || t('social.accounts_manager.empty_value') }}
                                    </div>
                                </div>

                                <div>
                                    <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                        {{ t('social.accounts_manager.fields.display_name') }}
                                    </div>
                                    <div class="mt-1">
                                        {{ activeConnection?.display_name || t('social.accounts_manager.empty_value') }}
                                    </div>
                                </div>

                                <div>
                                    <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                        {{ t('social.accounts_manager.fields.account_handle') }}
                                    </div>
                                    <div class="mt-1">
                                        {{ activeConnection?.account_handle || t('social.accounts_manager.empty_value') }}
                                    </div>
                                </div>

                                <div>
                                    <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                        {{ t('social.accounts_manager.fields.external_account_id') }}
                                    </div>
                                    <div class="mt-1">
                                        {{ activeConnection?.external_account_id || t('social.accounts_manager.empty_value') }}
                                    </div>
                                </div>
                            </div>

                            <div class="pt-2">
                                <SecondaryButton type="button" :disabled="busy" @click="closeModal">
                                    {{ t('social.accounts_manager.actions.close') }}
                                </SecondaryButton>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </Modal>
    </div>
</template>
