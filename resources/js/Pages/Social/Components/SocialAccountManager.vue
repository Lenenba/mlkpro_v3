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

const brandMap = {
    facebook: {
        mark: 'f',
        surface: 'bg-gradient-to-br from-[#1877F2] via-[#1B74E4] to-[#0A58CA]',
        ring: 'ring-[#1877F2]/20',
    },
    instagram: {
        mark: 'ig',
        surface: 'bg-gradient-to-br from-[#F58529] via-[#DD2A7B] to-[#8134AF]',
        ring: 'ring-[#DD2A7B]/20',
    },
    linkedin: {
        mark: 'in',
        surface: 'bg-gradient-to-br from-[#0A66C2] via-[#0A66C2] to-[#004182]',
        ring: 'ring-[#0A66C2]/20',
    },
    x: {
        mark: 'X',
        surface: 'bg-gradient-to-br from-[#111827] via-[#1F2937] to-[#374151]',
        ring: 'ring-[#111827]/20',
    },
};

const definitions = ref(normalizeDefinitions(props.initialDefinitions));
const connections = ref(normalizeConnections(props.initialConnections));
const summary = ref(normalizeSummary(props.initialSummary));
const access = ref(normalizeAccess(props.initialAccess));
const busy = ref(false);
const isLoading = ref(false);
const error = ref('');
const info = ref('');
const openPlatformKey = ref(null);
const selectedConnectionId = ref(null);
const form = ref({
    label: '',
    display_name: '',
    account_handle: '',
    external_account_id: '',
    is_active: false,
});

const flash = computed(() => page.props?.flash || {});
const canManage = computed(() => Boolean(access.value.can_manage_accounts));
const modalOpen = computed(() => Boolean(openPlatformKey.value));

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

const providerCards = computed(() => definitions.value.map((definition) => {
    const platformConnections = sortedConnections.value.filter((connection) => connection.platform === definition.key);
    const primaryConnection = platformConnections.find((connection) => connection.is_connected)
        || platformConnections[0]
        || null;

    return {
        ...definition,
        brand: brandMap[definition.key] || {
            mark: String(definition.label || '?').slice(0, 1).toUpperCase(),
            surface: 'bg-gradient-to-br from-stone-700 to-stone-900',
            ring: 'ring-stone-300/30',
        },
        connections: platformConnections,
        primary_connection: primaryConnection,
        connection_count: platformConnections.length,
        connected_count: platformConnections.filter((connection) => connection.is_connected).length,
        needs_attention_count: platformConnections.filter((connection) => connection.needs_attention).length,
    };
}));

const activeProviderCard = computed(() => (
    providerCards.value.find((definition) => definition.key === openPlatformKey.value) || null
));

const platformConnections = computed(() => activeProviderCard.value?.connections || []);

const selectedConnection = computed(() => (
    platformConnections.value.find((connection) => Number(connection.id) === Number(selectedConnectionId.value))
    || platformConnections.value[0]
    || null
));

const summaryChips = computed(() => ([
    {
        key: 'platforms',
        value: definitions.value.length,
    },
    {
        key: 'connected',
        value: Number(summary.value?.connected || 0),
    },
    {
        key: 'attention',
        value: Number(summary.value?.attention || 0),
    },
]));

const syncFormFromConnection = (connection) => {
    form.value = {
        label: String(connection?.label || ''),
        display_name: String(connection?.display_name || ''),
        account_handle: String(connection?.account_handle || ''),
        external_account_id: String(connection?.external_account_id || ''),
        is_active: Boolean(connection?.is_active),
    };
};

const clearForm = () => {
    form.value = {
        label: '',
        display_name: '',
        account_handle: '',
        external_account_id: '',
        is_active: false,
    };
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

watch(activeProviderCard, (provider) => {
    if (!provider) {
        selectedConnectionId.value = null;
        clearForm();

        return;
    }

    const hasSelectedConnection = provider.connections.some((connection) => (
        Number(connection.id) === Number(selectedConnectionId.value)
    ));

    if (!hasSelectedConnection) {
        selectedConnectionId.value = provider.connections[0]?.id ?? null;
    }
}, { immediate: true });

watch(selectedConnection, (connection) => {
    if (!connection) {
        clearForm();
        return;
    }

    syncFormFromConnection(connection);
}, { immediate: true });

const requestErrorMessage = (requestError, fallback) => {
    const validationMessage = Object.values(requestError?.response?.data?.errors || {})
        .flat()
        .find((value) => typeof value === 'string' && value.trim() !== '');

    return validationMessage
        || requestError?.response?.data?.message
        || requestError?.message
        || fallback;
};

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
        return 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-800/70 dark:text-neutral-300';
    }

    return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300';
};

const testStatusClass = (status) => (
    status === 'success'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300'
        : 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300'
);

const statusLabel = (status) => t(`social.accounts_manager.statuses.${status || 'draft'}`);

const providerPrimaryActionLabel = (definition) => {
    if (!canManage.value) {
        return t('social.accounts_manager.actions.view_connections');
    }

    return definition.connection_count > 0
        ? t('social.accounts_manager.actions.manage_accounts')
        : t('social.accounts_manager.actions.connect_platform');
};

const providerSummaryLine = (definition) => {
    if (definition.connected_count > 0) {
        return t('social.accounts_manager.provider_connected_count', { count: definition.connected_count });
    }

    if (definition.connection_count > 0) {
        return t('social.accounts_manager.provider_accounts_count', { count: definition.connection_count });
    }

    return t('social.accounts_manager.provider_ready');
};

const connectionHeadline = (connection) => {
    const displayName = String(connection?.display_name || '').trim();
    if (displayName !== '') {
        return displayName;
    }

    const handle = String(connection?.account_handle || '').trim();
    if (handle !== '') {
        return handle;
    }

    const externalId = String(connection?.external_account_id || '').trim();
    if (externalId !== '') {
        return externalId;
    }

    return t('social.accounts_manager.empty_value');
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
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.accounts_manager.messages.load_error'));
    } finally {
        isLoading.value = false;
    }
};

const openProvider = (definition) => {
    openPlatformKey.value = definition.key;
    selectedConnectionId.value = definition.primary_connection?.id || definition.connections?.[0]?.id || null;
    error.value = '';
};

const closeModal = () => {
    openPlatformKey.value = null;
    selectedConnectionId.value = null;
    error.value = '';
};

const startAuthorizationRequest = async (connection) => {
    const response = await axios.post(route('social.accounts.authorize', connection.id));
    const redirectUrl = String(response.data?.redirect_url || '').trim();

    info.value = String(response.data?.message || t('social.accounts_manager.messages.authorize_redirect'));

    if (redirectUrl !== '') {
        window.location.assign(redirectUrl);
        return true;
    }

    return false;
};

const connectPlatform = async (definition, { openAfterLoad = true } = {}) => {
    if (!canManage.value) {
        openProvider(definition);
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const createResponse = await axios.post(route('social.accounts.store'), {
            platform: definition.key,
        });

        const connection = createResponse.data?.connection || null;
        info.value = String(createResponse.data?.message || t('social.accounts_manager.messages.save_success'));

        if (connection?.id) {
            const redirected = await startAuthorizationRequest(connection);
            if (redirected) {
                return;
            }
        }

        await load();

        if (openAfterLoad) {
            openProvider(definition);
        }
    } catch (requestError) {
        await load();

        if (openAfterLoad) {
            openProvider(definition);
        }

        error.value = requestErrorMessage(requestError, t('social.accounts_manager.messages.authorize_error'));
    } finally {
        busy.value = false;
    }
};

const handleProviderCard = async (definition) => {
    if (canManage.value && definition.connection_count === 0) {
        await connectPlatform(definition, { openAfterLoad: false });
        return;
    }

    openProvider(definition);
};

const authorizeConnection = async (connection) => {
    if (!canManage.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const redirected = await startAuthorizationRequest(connection);

        if (!redirected) {
            await load();
        }
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.accounts_manager.messages.authorize_error'));
    } finally {
        busy.value = false;
    }
};

const saveConnection = async () => {
    if (!canManage.value || !selectedConnection.value) {
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
        is_active: Boolean(form.value.is_active),
    };

    try {
        await axios.put(route('social.accounts.update', selectedConnection.value.id), payload);
        info.value = t('social.accounts_manager.messages.save_success');
        await load();
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.accounts_manager.messages.save_error'));
    } finally {
        busy.value = false;
    }
};

const testConnection = async (connection) => {
    if (!canManage.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.post(route('social.accounts.test', connection.id));
        await load();

        if (response.data?.result?.success) {
            info.value = String(response.data?.message || t('social.accounts_manager.messages.test_success'));
        } else {
            error.value = String(response.data?.message || t('social.accounts_manager.messages.test_error'));
        }
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.accounts_manager.messages.test_error'));
    } finally {
        busy.value = false;
    }
};

const disconnectConnection = async (connection) => {
    if (!canManage.value) {
        return;
    }

    const name = connection?.label || activeProviderCard.value?.label || 'Pulse account';
    if (!window.confirm(t('social.accounts_manager.messages.confirm_disconnect', { name }))) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        await axios.post(route('social.accounts.disconnect', connection.id));
        info.value = t('social.accounts_manager.messages.disconnect_success');
        await load();
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.accounts_manager.messages.disconnect_error'));
    } finally {
        busy.value = false;
    }
};

const deleteConnection = async (connection) => {
    if (!canManage.value) {
        return;
    }

    const name = connection?.label || activeProviderCard.value?.label || 'Pulse account';
    if (!window.confirm(t('social.accounts_manager.messages.confirm_delete', { name }))) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        await axios.delete(route('social.accounts.destroy', connection.id));
        info.value = t('social.accounts_manager.messages.delete_success');
        await load();

        if (activeProviderCard.value?.connections?.length === 1) {
            selectedConnectionId.value = null;
        }
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.accounts_manager.messages.delete_error'));
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <div class="space-y-6">
        <section class="overflow-hidden rounded-[32px] border border-stone-200 bg-gradient-to-br from-white via-stone-50 to-sky-50 p-6 shadow-sm dark:border-neutral-700 dark:from-neutral-900 dark:via-neutral-900 dark:to-neutral-800">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-3xl">
                    <div class="text-xs font-semibold uppercase tracking-[0.24em] text-stone-400 dark:text-neutral-500">
                        {{ t('social.accounts_manager.hero_eyebrow') }}
                    </div>
                    <h3 class="mt-3 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('social.accounts_manager.title') }}
                    </h3>
                    <p class="mt-2 text-sm leading-6 text-stone-600 dark:text-neutral-300">
                        {{ t('social.accounts_manager.description') }}
                    </p>
                    <p class="mt-3 text-sm font-medium text-stone-500 dark:text-neutral-400">
                        {{ t('social.accounts_manager.directory_hint') }}
                    </p>
                </div>

                <SecondaryButton :disabled="busy || isLoading" @click="load">
                    {{ t('social.accounts_manager.reload') }}
                </SecondaryButton>
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                <div
                    v-for="chip in summaryChips"
                    :key="`social-account-summary-${chip.key}`"
                    class="rounded-full border border-white/70 bg-white/90 px-4 py-2 text-sm text-stone-700 shadow-sm dark:border-neutral-700 dark:bg-neutral-900/80 dark:text-neutral-200"
                >
                    <span class="font-semibold">{{ chip.value }}</span>
                    <span class="ml-2 text-stone-500 dark:text-neutral-400">
                        {{ t(`social.accounts_manager.summary.${chip.key}`) }}
                    </span>
                </div>
            </div>
        </section>

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

        <section class="grid grid-cols-1 gap-4 xl:grid-cols-2 2xl:grid-cols-4">
            <button
                v-for="definition in providerCards"
                :key="definition.key"
                type="button"
                class="group rounded-[30px] border border-stone-200 bg-white p-5 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-700 dark:bg-neutral-900"
                :class="definition.brand.ring"
                :disabled="busy || isLoading"
                @click="handleProviderCard(definition)"
            >
                <div class="flex items-start justify-between gap-3">
                    <div
                        class="flex h-14 w-14 items-center justify-center rounded-2xl text-lg font-semibold uppercase tracking-[0.18em] text-white shadow-sm"
                        :class="definition.brand.surface"
                    >
                        {{ definition.brand.mark }}
                    </div>

                    <span class="rounded-full border border-stone-200 bg-stone-50 px-3 py-1 text-xs font-medium text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                        {{ definition.connected_count }}/{{ definition.connection_count }}
                    </span>
                </div>

                <div class="mt-5">
                    <h4 class="text-xl font-semibold text-stone-900 dark:text-neutral-100">
                        {{ definition.label }}
                    </h4>
                    <p class="mt-2 min-h-[48px] text-sm leading-6 text-stone-600 dark:text-neutral-300">
                        {{ definition.short_description }}
                    </p>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full border border-stone-200 bg-stone-50 px-3 py-1 text-xs font-medium text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                        {{ providerSummaryLine(definition) }}
                    </span>

                    <span
                        v-if="definition.needs_attention_count > 0"
                        class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300"
                    >
                        {{ t('social.accounts_manager.provider_attention_count', { count: definition.needs_attention_count }) }}
                    </span>

                    <span
                        v-if="definition.setup_required"
                        class="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-medium text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300"
                    >
                        {{ t('social.accounts_manager.provider_setup_required') }}
                    </span>
                </div>

                <div class="mt-6">
                    <span class="inline-flex items-center rounded-full border border-stone-300 px-4 py-2 text-sm font-medium text-stone-800 transition group-hover:border-stone-900 group-hover:text-stone-900 dark:border-neutral-600 dark:text-neutral-200 dark:group-hover:border-neutral-300 dark:group-hover:text-white">
                        {{ providerPrimaryActionLabel(definition) }}
                    </span>
                </div>
            </button>
        </section>

        <Modal :show="modalOpen" max-width="5xl" @close="closeModal">
            <div v-if="activeProviderCard" class="space-y-6 p-6 sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-start gap-4">
                        <div
                            class="flex h-16 w-16 items-center justify-center rounded-3xl text-xl font-semibold uppercase tracking-[0.18em] text-white shadow-sm"
                            :class="activeProviderCard.brand.surface"
                        >
                            {{ activeProviderCard.brand.mark }}
                        </div>

                        <div>
                            <div class="text-xs font-semibold uppercase tracking-[0.24em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.accounts_manager.hero_eyebrow') }}
                            </div>
                            <h4 class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                                {{ activeProviderCard.label }}
                            </h4>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-stone-600 dark:text-neutral-300">
                                {{ t('social.accounts_manager.modal.subtitle') }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <SecondaryButton
                            v-if="canManage"
                            type="button"
                            :disabled="busy || isLoading"
                            @click="connectPlatform(activeProviderCard)"
                        >
                            {{ t('social.accounts_manager.actions.add_account') }}
                        </SecondaryButton>

                        <SecondaryButton type="button" @click="closeModal">
                            {{ t('social.accounts_manager.actions.close') }}
                        </SecondaryButton>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-[280px_minmax(0,1fr)]">
                    <section class="rounded-[28px] border border-stone-200 bg-stone-50/70 p-4 dark:border-neutral-700 dark:bg-neutral-800/60">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h5 class="text-sm font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                                    {{ t('social.accounts_manager.modal.accounts_title') }}
                                </h5>
                                <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                    {{ providerSummaryLine(activeProviderCard) }}
                                </p>
                            </div>
                        </div>

                        <div v-if="platformConnections.length" class="mt-4 space-y-3">
                            <button
                                v-for="connection in platformConnections"
                                :key="connection.id"
                                type="button"
                                class="w-full rounded-3xl border px-4 py-4 text-left transition"
                                :class="Number(connection.id) === Number(selectedConnectionId)
                                    ? 'border-stone-900 bg-white shadow-sm dark:border-white dark:bg-neutral-900'
                                    : 'border-stone-200 bg-white/80 hover:border-stone-300 dark:border-neutral-700 dark:bg-neutral-900/70 dark:hover:border-neutral-500'"
                                @click="selectedConnectionId = connection.id"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                            {{ connection.label || activeProviderCard.label }}
                                        </div>
                                        <div class="mt-1 truncate text-xs text-stone-500 dark:text-neutral-400">
                                            {{ connectionHeadline(connection) }}
                                        </div>
                                    </div>

                                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold" :class="statusClass(connection.status)">
                                        {{ statusLabel(connection.status) }}
                                    </span>
                                </div>
                            </button>
                        </div>

                        <div
                            v-else
                            class="mt-4 rounded-3xl border border-dashed border-stone-300 bg-white px-4 py-5 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900/70 dark:text-neutral-400"
                        >
                            <div class="font-semibold text-stone-900 dark:text-neutral-100">
                                {{ t('social.accounts_manager.modal.accounts_empty_title') }}
                            </div>
                            <div class="mt-2">
                                {{ t('social.accounts_manager.modal.accounts_empty_description') }}
                            </div>
                        </div>
                    </section>

                    <section class="rounded-[28px] border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <template v-if="selectedConnection">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                        {{ t('social.accounts_manager.modal.selected_connection') }}
                                    </div>
                                    <h5 class="mt-1 text-xl font-semibold text-stone-900 dark:text-neutral-100">
                                        {{ selectedConnection.label || activeProviderCard.label }}
                                    </h5>
                                    <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                        {{ connectionHeadline(selectedConnection) }}
                                    </p>
                                </div>

                                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold" :class="statusClass(selectedConnection.status)">
                                    {{ statusLabel(selectedConnection.status) }}
                                </span>
                            </div>

                            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-3 dark:border-neutral-700 dark:bg-neutral-800/70">
                                    <div class="text-[11px] uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                        {{ t('social.accounts_manager.modal.quick_status') }}
                                    </div>
                                    <div class="mt-1 text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                        {{ statusLabel(selectedConnection.status) }}
                                    </div>
                                </div>

                                <div class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-3 dark:border-neutral-700 dark:bg-neutral-800/70">
                                    <div class="text-[11px] uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                        {{ t('social.accounts_manager.modal.connected_at') }}
                                    </div>
                                    <div class="mt-1 text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                        {{ formatDate(selectedConnection.connected_at) }}
                                    </div>
                                </div>

                                <div class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-3 dark:border-neutral-700 dark:bg-neutral-800/70">
                                    <div class="text-[11px] uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                        {{ t('social.accounts_manager.modal.last_test') }}
                                    </div>
                                    <div class="mt-1 text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                        {{ formatDate(selectedConnection.last_tested_at) }}
                                    </div>
                                </div>
                            </div>

                            <div
                                v-if="selectedConnection.last_test_message"
                                class="mt-4 rounded-3xl border px-4 py-3 text-sm"
                                :class="testStatusClass(selectedConnection.last_test_status)"
                            >
                                <div class="font-semibold">
                                    {{ selectedConnection.last_test_status === 'success'
                                        ? t('social.accounts_manager.test.success_title')
                                        : t('social.accounts_manager.test.error_title') }}
                                </div>
                                <div class="mt-1">
                                    {{ selectedConnection.last_test_message }}
                                </div>
                            </div>

                            <div v-if="canManage" class="mt-5 space-y-4">
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <FloatingInput
                                        v-model="form.label"
                                        :label="t('social.accounts_manager.fields.label')"
                                    />
                                    <FloatingInput
                                        v-model="form.display_name"
                                        :label="t('social.accounts_manager.fields.display_name')"
                                    />
                                </div>

                                <label
                                    v-if="selectedConnection.status === 'connected'"
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

                                <div class="flex flex-wrap gap-2">
                                    <PrimaryButton type="button" :disabled="busy || isLoading" @click="saveConnection">
                                        {{ t('social.accounts_manager.actions.save_details') }}
                                    </PrimaryButton>

                                    <SecondaryButton
                                        v-if="selectedConnection.auth_method === 'oauth' && !selectedConnection.is_connected"
                                        type="button"
                                        :disabled="busy || isLoading"
                                        @click="authorizeConnection(selectedConnection)"
                                    >
                                        {{ selectedConnection.status === 'draft' || selectedConnection.status === 'disconnected'
                                            ? t('social.accounts_manager.actions.connect_platform')
                                            : t('social.accounts_manager.actions.reconnect_oauth') }}
                                    </SecondaryButton>

                                    <SecondaryButton
                                        v-if="selectedConnection.has_credentials"
                                        type="button"
                                        :disabled="busy || isLoading"
                                        @click="testConnection(selectedConnection)"
                                    >
                                        {{ t('social.accounts_manager.actions.test_connection') }}
                                    </SecondaryButton>

                                    <button
                                        type="button"
                                        class="rounded-full border border-amber-200 bg-amber-50 px-4 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300 dark:hover:bg-amber-500/20"
                                        :disabled="busy || isLoading"
                                        @click="disconnectConnection(selectedConnection)"
                                    >
                                        {{ t('social.accounts_manager.actions.disconnect') }}
                                    </button>

                                    <button
                                        type="button"
                                        class="rounded-full border border-rose-200 bg-rose-50 px-4 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20"
                                        :disabled="busy || isLoading"
                                        @click="deleteConnection(selectedConnection)"
                                    >
                                        {{ t('social.accounts_manager.actions.delete') }}
                                    </button>
                                </div>
                            </div>

                            <div v-else class="mt-5 rounded-2xl border border-stone-200 bg-stone-50 px-3 py-3 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                {{ t('social.accounts_manager.modal.read_only_notice') }}
                            </div>

                            <details class="mt-5 rounded-3xl border border-stone-200 bg-stone-50/80 p-4 dark:border-neutral-700 dark:bg-neutral-800/70">
                                <summary class="cursor-pointer list-none text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ t('social.accounts_manager.actions.show_technical_details') }}
                                </summary>

                                <p class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                                    {{ t('social.accounts_manager.modal.technical_description') }}
                                </p>

                                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <div class="text-[11px] uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                            {{ t('social.accounts_manager.fields.account_handle') }}
                                        </div>
                                        <div class="mt-1 text-sm text-stone-900 dark:text-neutral-100">
                                            {{ selectedConnection.account_handle || t('social.accounts_manager.empty_value') }}
                                        </div>
                                    </div>

                                    <div>
                                        <div class="text-[11px] uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                            {{ t('social.accounts_manager.fields.external_account_id') }}
                                        </div>
                                        <div class="mt-1 text-sm text-stone-900 dark:text-neutral-100">
                                            {{ selectedConnection.external_account_id || t('social.accounts_manager.empty_value') }}
                                        </div>
                                    </div>

                                    <div>
                                        <div class="text-[11px] uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                            {{ t('social.accounts_manager.modal.last_synced_at') }}
                                        </div>
                                        <div class="mt-1 text-sm text-stone-900 dark:text-neutral-100">
                                            {{ formatDate(selectedConnection.last_synced_at) }}
                                        </div>
                                    </div>

                                    <div>
                                        <div class="text-[11px] uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                            {{ t('social.accounts_manager.modal.token_expires_at') }}
                                        </div>
                                        <div class="mt-1 text-sm text-stone-900 dark:text-neutral-100">
                                            {{ formatDate(selectedConnection.token_expires_at) }}
                                        </div>
                                    </div>

                                    <div>
                                        <div class="text-[11px] uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                            {{ t('social.accounts_manager.provider_target_type') }}
                                        </div>
                                        <div class="mt-1 text-sm text-stone-900 dark:text-neutral-100">
                                            {{ selectedConnection.target_type || t('social.accounts_manager.empty_value') }}
                                        </div>
                                    </div>

                                    <div>
                                        <div class="text-[11px] uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                            {{ t('social.accounts_manager.provider_supports') }}
                                        </div>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <span
                                                v-for="support in selectedConnection.supports || []"
                                                :key="`${selectedConnection.id}-support-${support}`"
                                                class="rounded-full border border-stone-200 bg-white px-2.5 py-1 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                                            >
                                                {{ support }}
                                            </span>
                                            <span
                                                v-if="!(selectedConnection.supports || []).length"
                                                class="text-sm text-stone-500 dark:text-neutral-400"
                                            >
                                                {{ t('social.accounts_manager.empty_value') }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <div class="text-[11px] uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                        {{ t('social.accounts_manager.provider_scopes') }}
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <span
                                            v-for="scope in selectedConnection.requested_scopes || []"
                                            :key="`${selectedConnection.id}-scope-${scope}`"
                                            class="rounded-full border border-stone-200 bg-white px-2.5 py-1 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                                        >
                                            {{ scope }}
                                        </span>
                                        <span
                                            v-if="!(selectedConnection.requested_scopes || []).length"
                                            class="text-sm text-stone-500 dark:text-neutral-400"
                                        >
                                            {{ t('social.accounts_manager.empty_value') }}
                                        </span>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <div class="text-[11px] uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                        {{ t('social.accounts_manager.modal.permissions') }}
                                    </div>
                                    <div v-if="selectedConnection.permissions?.length" class="mt-2 flex flex-wrap gap-2">
                                        <span
                                            v-for="permission in selectedConnection.permissions"
                                            :key="`${selectedConnection.id}-permission-${permission}`"
                                            class="rounded-full border border-stone-200 bg-white px-2.5 py-1 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                                        >
                                            {{ permission }}
                                        </span>
                                    </div>
                                    <div v-else class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                                        {{ t('social.accounts_manager.modal.no_permissions') }}
                                    </div>
                                </div>

                                <div v-if="selectedConnection.last_error" class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 px-3 py-3 text-sm text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                                    <div class="font-semibold">{{ t('social.accounts_manager.modal.last_error') }}</div>
                                    <div class="mt-1">{{ selectedConnection.last_error }}</div>
                                </div>
                            </details>
                        </template>

                        <template v-else>
                            <div class="rounded-3xl border border-dashed border-stone-300 bg-stone-50 px-5 py-8 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-400">
                                <div class="font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ t('social.accounts_manager.modal.accounts_empty_title') }}
                                </div>
                                <div class="mt-2">
                                    {{ t('social.accounts_manager.modal.accounts_empty_description') }}
                                </div>
                            </div>
                        </template>
                    </section>
                </div>
            </div>
        </Modal>
    </div>
</template>
