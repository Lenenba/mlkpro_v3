<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { ExternalLink, LogIn, LogOut, RefreshCw } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SocialPlatformLogo from '@/Pages/Social/Components/SocialPlatformLogo.vue';

const props = defineProps({
    initialConnector: {
        type: Object,
        default: () => ({}),
    },
    initialConnections: {
        type: Array,
        default: () => ([]),
    },
    canManage: {
        type: Boolean,
        default: false,
    },
});

const { t } = useI18n();
const normalizeConnections = (payload) => (
    Array.isArray(payload)
        ? payload
            .filter((connection) => connection && typeof connection === 'object')
            .map((connection) => ({ ...connection }))
        : []
);
const connectionIdentityKey = (platform, externalAccountId) => {
    const normalizedPlatform = String(platform || '').trim().toLowerCase();
    const normalizedExternalAccountId = String(externalAccountId || '').trim();

    return normalizedPlatform !== '' && normalizedExternalAccountId !== ''
        ? JSON.stringify([normalizedPlatform, normalizedExternalAccountId])
        : '';
};
const connector = ref({ ...props.initialConnector });
const synchronizedConnections = ref(normalizeConnections(props.initialConnections));
const catalog = ref(null);
const loading = ref(false);
const connecting = ref(false);
const disconnecting = ref(false);
const syncing = ref(false);
const importingChannelId = ref(null);
const error = ref('');
const info = ref('');

const isAvailable = computed(() => Boolean(connector.value?.available));
const isConnected = computed(() => Boolean(connector.value?.connected));
const canConnect = computed(() => Boolean(connector.value?.can_connect));
const canDisconnect = computed(() => Boolean(connector.value?.can_disconnect));
const isDeliveryAuthorized = computed(() => Boolean(connector.value?.delivery_authorized));
const isDeliveryEnabled = computed(() => Boolean(connector.value?.delivery_enabled));
const hasCatalog = computed(() => catalog.value !== null);
const catalogChannels = computed(() => (
    (Array.isArray(catalog.value?.organizations) ? catalog.value.organizations : [])
        .flatMap((organization) => (
            Array.isArray(organization?.channels) ? organization.channels : []
        ))
));
const synchronizedConnectionIdentities = computed(() => new Set(
    synchronizedConnections.value
        .map((connection) => connectionIdentityKey(
            connection?.platform,
            connection?.external_account_id,
        ))
        .filter((identity) => identity !== ''),
));
const availableCatalogOrganizations = computed(() => (
    (Array.isArray(catalog.value?.organizations) ? catalog.value.organizations : [])
        .map((organization) => ({
            ...organization,
            channels: (Array.isArray(organization?.channels) ? organization.channels : [])
                .filter((channel) => (
                    !Boolean(channel?.imported)
                    && !synchronizedConnectionIdentities.value.has(
                        connectionIdentityKey(channel?.platform, channel?.id),
                    )
                )),
        }))
        .filter((organization) => organization.channels.length > 0)
));
const availableCatalogChannels = computed(() => (
    availableCatalogOrganizations.value.flatMap((organization) => organization.channels)
));
const hasChannelsAwaitingPublication = computed(() => catalogChannels.value.some((channel) => (
    Boolean(channel?.can_import) && !Boolean(channel?.publication_enabled)
)));
const canSyncChannels = computed(() => (
    isDeliveryAuthorized.value
));
const shouldAuthorizePublishing = computed(() => (
    connector.value?.mode === 'oauth'
    && isConnected.value
    && !isDeliveryAuthorized.value
    && hasChannelsAwaitingPublication.value
));
const busy = computed(() => (
    loading.value
    || connecting.value
    || disconnecting.value
    || syncing.value
    || importingChannelId.value !== null
));
const connectionStatusOrder = {
    connected: 0,
    pending: 1,
    authorizing: 2,
    draft: 3,
    reconnect_required: 4,
    error: 5,
    expired: 6,
    disconnected: 7,
};
const knownConnectionStatuses = new Set(Object.keys(connectionStatusOrder));
const sortedSynchronizedConnections = computed(() => [...synchronizedConnections.value].sort((left, right) => {
    const leftStatusWeight = connectionStatusOrder[left.status] ?? 99;
    const rightStatusWeight = connectionStatusOrder[right.status] ?? 99;

    if (leftStatusWeight !== rightStatusWeight) {
        return leftStatusWeight - rightStatusWeight;
    }

    if (Boolean(left.is_active) !== Boolean(right.is_active)) {
        return left.is_active ? -1 : 1;
    }

    return String(left.display_name || left.label || '').localeCompare(
        String(right.display_name || right.label || ''),
    );
}));

const requestErrorMessage = (requestError, fallback) => {
    if (Number(requestError?.response?.status) !== 422) {
        return fallback;
    }

    const validationErrors = requestError?.response?.data?.errors;
    if (!validationErrors || typeof validationErrors !== 'object' || Array.isArray(validationErrors)) {
        return fallback;
    }

    const validationMessage = Object.values(validationErrors)
        .flatMap((messages) => (Array.isArray(messages) ? messages : [messages]))
        .find((message) => typeof message === 'string' && message.trim() !== '');

    return validationMessage?.trim() || fallback;
};

watch(() => props.initialConnector, (value) => {
    connector.value = { ...(value || {}) };
}, { deep: true });

watch(() => props.initialConnections, (value) => {
    synchronizedConnections.value = normalizeConnections(value);
}, { deep: true });

const loadCatalog = async () => {
    loading.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.get(route('social.buffer.catalog'));
        catalog.value = response.data;
        connector.value = response.data?.connector || connector.value;
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.buffer_connector.messages.load_error'));
    } finally {
        loading.value = false;
    }
};

const connectBuffer = async () => {
    connecting.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.post(route('social.buffer.connect'));
        const redirectUrl = String(response.data?.redirect_url || '');

        if (!redirectUrl) {
            throw new Error('Missing Buffer redirect URL');
        }

        window.location.assign(redirectUrl);
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.buffer_connector.messages.connect_error'));
        connecting.value = false;
    }
};

const disconnectBuffer = async () => {
    const accountName = connector.value?.account_name || t('social.buffer_connector.account_fallback');

    if (!window.confirm(t('social.buffer_connector.messages.confirm_disconnect', { name: accountName }))) {
        return;
    }

    disconnecting.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.post(route('social.buffer.disconnect'));
        connector.value = response.data?.connector || connector.value;
        if (Array.isArray(response.data?.connections)) {
            replaceSynchronizedConnections(response.data.connections);
        }
        catalog.value = null;
        info.value = t(response.data?.message_key || 'social.buffer_connector.messages.disconnect_success');
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.buffer_connector.messages.disconnect_error'));
    } finally {
        disconnecting.value = false;
    }
};

const applyConnectorPayload = (responseConnector) => {
    if (!responseConnector) {
        return;
    }

    connector.value = responseConnector;
    if (catalog.value) {
        catalog.value.connector = responseConnector;
    }
};

const synchronizedConnectionKey = (connection) => {
    const id = String(connection?.id || '').trim();

    if (id !== '') {
        return `id:${id}`;
    }

    const identity = connectionIdentityKey(connection?.platform, connection?.external_account_id);

    return identity !== '' ? `channel:${identity}` : '';
};

const replaceSynchronizedConnections = (connections) => {
    synchronizedConnections.value = normalizeConnections(connections);
};

const upsertSynchronizedConnections = (connections) => {
    const nextConnections = [...synchronizedConnections.value];

    for (const connection of normalizeConnections(connections)) {
        const id = String(connection?.id || '').trim();
        const identity = connectionIdentityKey(connection?.platform, connection?.external_account_id);
        const existingIndex = nextConnections.findIndex((candidate) => {
            const candidateId = String(candidate?.id || '').trim();

            if (id !== '' && candidateId === id) {
                return true;
            }

            return identity !== '' && connectionIdentityKey(
                candidate?.platform,
                candidate?.external_account_id,
            ) === identity;
        });

        if (existingIndex >= 0) {
            nextConnections.splice(existingIndex, 1, connection);
        } else {
            nextConnections.push(connection);
        }
    }

    synchronizedConnections.value = nextConnections;
};

const applyConnectionPayloads = (connections) => {
    const normalizedConnections = normalizeConnections(connections);
    upsertSynchronizedConnections(normalizedConnections);

    const connectionsByIdentity = new Map(
        normalizedConnections
            .map((connection) => [
                connectionIdentityKey(connection?.platform, connection?.external_account_id),
                connection,
            ])
            .filter(([identity]) => identity !== '')
    );

    for (const channel of catalogChannels.value) {
        const connection = connectionsByIdentity.get(
            connectionIdentityKey(channel?.platform, channel?.id),
        );
        if (!connection) {
            continue;
        }

        channel.imported = true;
        channel.publication_enabled = Boolean(connection.is_connected);
    }

    if (catalog.value) {
        catalog.value.imported_count = catalogChannels.value.filter((channel) => channel.imported).length;
    }
};

const syncAllChannels = async () => {
    if (!isDeliveryAuthorized.value) {
        await connectBuffer();

        return;
    }

    syncing.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.post(route('social.buffer.channels.sync'));

        applyConnectorPayload(response.data?.connector);
        applyConnectionPayloads(response.data?.connections);
        catalog.value.synced_count = Number(response.data?.synced_count || 0);
        catalog.value.active_count = Number(response.data?.active_count || 0);
        catalog.value.skipped_count = Number(response.data?.skipped_count || 0);
        info.value = t(
            response.data?.message_key || 'social.buffer_connector.messages.sync_success',
            {
                synced: catalog.value.synced_count,
                active: catalog.value.active_count,
                skipped: catalog.value.skipped_count,
            },
        );
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.buffer_connector.messages.sync_error'));
    } finally {
        syncing.value = false;
    }
};

const importChannel = async (organization, channel) => {
    importingChannelId.value = channel.id;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.post(route('social.buffer.channels.store'), {
            organization_id: organization.id,
            channel_id: channel.id,
        });

        applyConnectorPayload(response.data?.connector);
        applyConnectionPayloads([response.data?.connection]);
        info.value = t(response.data?.message_key || 'social.buffer_connector.messages.import_success');
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.buffer_connector.messages.import_error'));
    } finally {
        importingChannelId.value = null;
    }
};

const channelHealthLabel = (channel) => {
    if (channel.import_block_reason) {
        return t(`social.buffer_connector.block_reasons.${channel.import_block_reason}`);
    }

    if (channel.is_queue_paused) {
        return t('social.buffer_connector.states.queue_paused');
    }

    return t('social.buffer_connector.states.available');
};

const channelHealthToneClass = (channel) => {
    if (channel.is_disconnected || channel.is_locked || !channel.supported) {
        return 'text-rose-700 dark:text-rose-300';
    }

    if (channel.is_queue_paused) {
        return 'text-amber-700 dark:text-amber-300';
    }

    return 'text-emerald-700 dark:text-emerald-300';
};

const connectionStatus = (connection) => (
    knownConnectionStatuses.has(connection?.status) ? connection.status : 'draft'
);

const statusLabel = (connection) => (
    t(`social.accounts_manager.statuses.${connectionStatus(connection)}`)
);

const statusClass = (connection) => {
    const status = connectionStatus(connection);

    if (status === 'connected') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300';
    }

    if (status === 'pending' || status === 'authorizing') {
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

const connectionDisplayName = (connection) => (
    String(
        connection?.display_name
        || connection?.label
        || connection?.account_handle
        || connection?.external_account_id
        || t('social.buffer_connector.synchronized_account_fallback'),
    ).trim()
);

const connectionProviderLabel = (connection) => (
    String(connection?.provider_label || connection?.platform || '').trim()
);

const formatDate = (value) => {
    if (!value) {
        return t('social.accounts_manager.empty_value');
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? t('social.accounts_manager.empty_value')
        : date.toLocaleString();
};
</script>

<template>
    <section
        class="overflow-hidden rounded-sm border border-violet-200 bg-white shadow-sm dark:border-violet-500/30 dark:bg-neutral-900"
        :aria-busy="busy"
    >
        <div class="border-b border-violet-100 bg-violet-50/70 px-5 py-5 dark:border-violet-500/20 dark:bg-violet-500/10 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-3xl">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-sm bg-violet-600 px-2.5 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-white">
                            Buffer
                        </span>
                        <span class="rounded-sm border border-violet-200 bg-white px-2.5 py-1 text-xs font-medium text-violet-700 dark:border-violet-500/30 dark:bg-neutral-900 dark:text-violet-300">
                            {{ connector.mode === 'oauth'
                                ? t('social.buffer_connector.oauth_mode')
                                : t('social.buffer_connector.local_mode') }}
                        </span>
                        <span
                            class="rounded-sm border px-2.5 py-1 text-xs font-medium"
                            :class="isConnected
                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300'
                                : 'border-stone-200 bg-stone-50 text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300'"
                        >
                            {{ isConnected
                                ? t('social.buffer_connector.states.connected')
                                : t('social.buffer_connector.states.disconnected_account') }}
                        </span>
                        <span
                            class="rounded-sm border px-2.5 py-1 text-xs font-medium"
                            :class="isDeliveryEnabled
                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300'
                                : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300'"
                        >
                            {{ isDeliveryEnabled
                                ? t('social.buffer_connector.delivery_enabled')
                                : t('social.buffer_connector.delivery_disabled') }}
                        </span>
                    </div>

                    <h3 class="mt-4 text-xl font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('social.buffer_connector.title') }}
                    </h3>
                    <p class="mt-2 text-sm leading-6 text-stone-600 dark:text-neutral-300">
                        {{ t('social.buffer_connector.description') }}
                    </p>
                    <p
                        v-if="isConnected && connector.account_name"
                        class="mt-2 text-sm font-semibold text-violet-700 dark:text-violet-300"
                    >
                        {{ t('social.buffer_connector.account_label') }} : {{ connector.account_name }}
                    </p>
                </div>

                <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                    <a
                        href="https://publish.buffer.com/channels"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-sm border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-stone-700 transition hover:bg-stone-50 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800 sm:w-auto"
                    >
                        {{ t('social.buffer_connector.actions.add_in_buffer') }}
                        <ExternalLink class="size-4" />
                        <span class="sr-only">{{ t('social.buffer_connector.opens_new_tab') }}</span>
                    </a>

                    <PrimaryButton
                        v-if="props.canManage && !isConnected"
                        type="button"
                        class="w-full justify-center sm:w-auto"
                        :disabled="!canConnect || busy"
                        @click="connectBuffer"
                    >
                        <LogIn class="mr-2 size-4" />
                        {{ connecting
                            ? t('social.buffer_connector.actions.connecting')
                            : t('social.buffer_connector.actions.connect') }}
                    </PrimaryButton>

                    <PrimaryButton
                        v-if="props.canManage && connector.mode === 'oauth' && isConnected && !isDeliveryAuthorized"
                        type="button"
                        class="w-full justify-center sm:w-auto"
                        :disabled="!canConnect || busy"
                        @click="connectBuffer"
                    >
                        <LogIn class="mr-2 size-4" />
                        {{ connecting
                            ? t('social.buffer_connector.actions.enabling_publishing')
                            : t('social.buffer_connector.actions.enable_publishing') }}
                    </PrimaryButton>

                    <PrimaryButton
                        v-if="props.canManage && isConnected && isAvailable"
                        type="button"
                        class="w-full justify-center sm:w-auto"
                        :disabled="busy"
                        @click="loadCatalog"
                    >
                        <RefreshCw class="mr-2 size-4" :class="{ 'animate-spin': loading }" />
                        {{ hasCatalog
                            ? t('social.buffer_connector.actions.refresh_search')
                            : t('social.buffer_connector.actions.search_new_accounts') }}
                    </PrimaryButton>

                    <SecondaryButton
                        v-if="props.canManage && isConnected && canDisconnect"
                        type="button"
                        class="w-full justify-center sm:w-auto"
                        :disabled="busy"
                        @click="disconnectBuffer"
                    >
                        <LogOut class="mr-2 size-4" />
                        {{ disconnecting
                            ? t('social.buffer_connector.actions.disconnecting')
                            : t('social.buffer_connector.actions.disconnect') }}
                    </SecondaryButton>
                </div>
            </div>
        </div>

        <div class="space-y-5 p-5 sm:p-6">
            <div
                v-if="!connector.configured"
                class="rounded-sm border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                role="status"
            >
                <div class="font-semibold">{{ t('social.buffer_connector.configuration_title') }}</div>
                <div class="mt-1">
                    {{ t('social.buffer_connector.configuration_oauth') }}
                </div>
            </div>

            <div
                v-else-if="!isConnected"
                class="rounded-sm border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300"
                role="status"
            >
                {{ t('social.buffer_connector.connect_prompt') }}
            </div>

            <div
                v-else-if="!props.canManage"
                class="rounded-sm border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300"
                role="status"
            >
                {{ t('social.buffer_connector.owner_only') }}
            </div>

            <div
                v-if="error"
                class="rounded-sm border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300"
                role="alert"
                aria-live="assertive"
            >
                {{ error }}
            </div>

            <div
                v-if="info"
                class="rounded-sm border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300"
                role="status"
                aria-live="polite"
            >
                {{ info }}
            </div>

            <span v-if="loading" class="sr-only" role="status" aria-live="polite">
                {{ t('social.buffer_connector.loading') }}
            </span>

            <section class="space-y-4" aria-labelledby="buffer-synchronized-accounts-title">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="max-w-3xl">
                        <h4
                            id="buffer-synchronized-accounts-title"
                            class="text-base font-semibold text-stone-900 dark:text-neutral-100"
                        >
                            {{ t('social.buffer_connector.synchronized_title') }}
                        </h4>
                        <p class="mt-1 text-sm leading-6 text-stone-600 dark:text-neutral-300">
                            {{ t('social.buffer_connector.synchronized_description') }}
                        </p>
                    </div>
                    <span class="rounded-sm border border-violet-200 bg-violet-50 px-2.5 py-1 text-xs font-semibold text-violet-700 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-300">
                        {{ t('social.buffer_connector.synchronized_count', {
                            count: sortedSynchronizedConnections.length,
                        }) }}
                    </span>
                </div>

                <div
                    v-if="sortedSynchronizedConnections.length"
                    class="grid grid-cols-1 gap-3 lg:grid-cols-2"
                >
                    <article
                        v-for="connection in sortedSynchronizedConnections"
                        :key="synchronizedConnectionKey(connection) || connection.external_account_id"
                        class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/70"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <div class="flex size-11 shrink-0 items-center justify-center rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                                    <SocialPlatformLogo :platform="connection.platform" class="size-5" />
                                </div>

                                <div class="min-w-0">
                                    <div class="truncate font-semibold text-stone-900 dark:text-neutral-100">
                                        {{ connectionDisplayName(connection) }}
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-stone-500 dark:text-neutral-400">
                                        <span v-if="connectionProviderLabel(connection)">
                                            {{ connectionProviderLabel(connection) }}
                                        </span>
                                        <span v-if="connection.account_handle">
                                            {{ connection.account_handle }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <span
                                class="shrink-0 rounded-sm border px-2.5 py-1 text-[11px] font-semibold"
                                :class="statusClass(connection)"
                            >
                                {{ statusLabel(connection) }}
                            </span>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-stone-200 pt-3 text-xs dark:border-neutral-700">
                            <span
                                class="font-medium"
                                :class="connection.is_active && connection.is_connected
                                    ? 'text-emerald-700 dark:text-emerald-300'
                                    : 'text-amber-700 dark:text-amber-300'"
                            >
                                {{ connection.is_active && connection.is_connected
                                    ? t('social.buffer_connector.states.publishing_active')
                                    : t('social.buffer_connector.states.publishing_inactive') }}
                            </span>
                            <span class="text-stone-500 dark:text-neutral-400">
                                {{ t('social.accounts_manager.modal.last_synced_at') }} :
                                {{ formatDate(connection.last_synced_at) }}
                            </span>
                        </div>

                        <div
                            v-if="connection.needs_attention && connection.last_error"
                            class="mt-3 break-words rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                            role="status"
                        >
                            <span class="font-semibold">
                                {{ t('social.buffer_connector.last_error_label') }} :
                            </span>
                            {{ connection.last_error }}
                        </div>
                    </article>
                </div>

                <div
                    v-else
                    class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-5 text-sm dark:border-neutral-700 dark:bg-neutral-800/50"
                >
                    <div class="font-semibold text-stone-800 dark:text-neutral-200">
                        {{ t('social.buffer_connector.synchronized_empty_title') }}
                    </div>
                    <div class="mt-1 text-stone-500 dark:text-neutral-400">
                        {{ t('social.buffer_connector.synchronized_empty_description') }}
                    </div>
                </div>
            </section>

            <div v-if="catalog" class="space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-stone-200 bg-stone-50 px-4 py-3 dark:border-neutral-700 dark:bg-neutral-800/70">
                    <div class="min-w-0">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                            {{ t('social.buffer_connector.account_label') }}
                        </div>
                        <div class="mt-1 break-words font-semibold text-stone-900 dark:text-neutral-100">
                            {{ catalog.account?.name || t('social.buffer_connector.account_fallback') }}
                        </div>
                        <h4 class="mt-3 font-semibold text-stone-900 dark:text-neutral-100">
                            {{ t('social.buffer_connector.available_title') }}
                        </h4>
                        <p class="mt-1 text-sm leading-6 text-stone-600 dark:text-neutral-300">
                            {{ t('social.buffer_connector.available_description') }}
                        </p>
                    </div>
                    <div class="flex max-w-xl flex-wrap items-center justify-end gap-3">
                        <div class="rounded-sm border border-sky-200 bg-sky-50 px-2.5 py-1 text-xs font-semibold text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300">
                            {{ t('social.buffer_connector.available_count', {
                                count: availableCatalogChannels.length,
                            }) }}
                        </div>

                        <div
                            v-if="shouldAuthorizePublishing"
                            class="w-full text-sm text-amber-700 dark:text-amber-300"
                            role="status"
                        >
                            {{ t('social.buffer_connector.messages.publishing_required') }}
                        </div>

                        <PrimaryButton
                            v-if="props.canManage && shouldAuthorizePublishing"
                            type="button"
                            class="w-full justify-center sm:w-auto"
                            :disabled="!canConnect || busy"
                            @click="connectBuffer"
                        >
                            <LogIn class="mr-2 size-4" />
                            {{ connecting
                                ? t('social.buffer_connector.actions.enabling_publishing')
                                : t('social.buffer_connector.actions.enable_publishing') }}
                        </PrimaryButton>

                        <PrimaryButton
                            v-else-if="props.canManage && canSyncChannels"
                            type="button"
                            class="w-full justify-center sm:w-auto"
                            :disabled="busy"
                            @click="syncAllChannels"
                        >
                            <RefreshCw class="mr-2 size-4" :class="{ 'animate-spin': syncing }" />
                            {{ syncing
                                ? t('social.buffer_connector.actions.syncing_all')
                                : t('social.buffer_connector.actions.sync_all') }}
                        </PrimaryButton>
                    </div>
                </div>

                <div
                    v-for="organization in availableCatalogOrganizations"
                    :key="organization.id"
                    class="rounded-sm border border-stone-200 dark:border-neutral-700"
                >
                    <div class="border-b border-stone-200 bg-stone-50 px-4 py-3 dark:border-neutral-700 dark:bg-neutral-800/70">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                            {{ t('social.buffer_connector.organization_label') }}
                        </div>
                        <div class="mt-1 break-words font-semibold text-stone-900 dark:text-neutral-100">
                            {{ organization.name }}
                        </div>
                    </div>

                    <div v-if="organization.channels.length" class="divide-y divide-stone-200 dark:divide-neutral-700">
                        <div
                            v-for="channel in organization.channels"
                            :key="channel.id"
                            class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="flex min-w-0 items-start gap-3">
                                <div class="flex size-11 shrink-0 items-center justify-center rounded-sm border border-stone-200 bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800">
                                    <SocialPlatformLogo v-if="channel.platform" :platform="channel.platform" class="size-5" />
                                    <span v-else class="text-xs font-bold uppercase text-stone-500">{{ channel.service.slice(0, 2) }}</span>
                                </div>

                                <div class="min-w-0">
                                    <div class="truncate font-semibold text-stone-900 dark:text-neutral-100">
                                        {{ channel.display_name || channel.name }}
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-stone-500 dark:text-neutral-400">
                                        <span>{{ channel.service }}</span>
                                        <span>{{ channel.type }}</span>
                                        <span>{{ channel.timezone }}</span>
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs font-medium">
                                        <span :class="channelHealthToneClass(channel)">
                                            {{ channelHealthLabel(channel) }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <SecondaryButton
                                v-if="props.canManage"
                                type="button"
                                class="w-full justify-center sm:w-auto"
                                :disabled="!channel.can_import || busy"
                                @click="importChannel(organization, channel)"
                            >
                                {{ importingChannelId === channel.id
                                    ? t('social.buffer_connector.actions.importing')
                                    : t('social.buffer_connector.actions.import') }}
                            </SecondaryButton>
                        </div>
                    </div>

                    <div v-else class="p-4 text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('social.buffer_connector.no_channels') }}
                    </div>
                </div>

                <div
                    v-if="availableCatalogOrganizations.length === 0"
                    class="rounded-sm border border-dashed border-emerald-300 bg-emerald-50 px-4 py-5 text-sm dark:border-emerald-500/30 dark:bg-emerald-500/10"
                >
                    <div class="font-semibold text-emerald-800 dark:text-emerald-200">
                        {{ t('social.buffer_connector.all_synchronized_title') }}
                    </div>
                    <div class="mt-1 text-emerald-700 dark:text-emerald-300">
                        {{ t('social.buffer_connector.all_synchronized_description') }}
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
