<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { ExternalLink, RefreshCw } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SocialPlatformLogo from '@/Pages/Social/Components/SocialPlatformLogo.vue';

const props = defineProps({
    initialConnector: {
        type: Object,
        default: () => ({}),
    },
    canManage: {
        type: Boolean,
        default: false,
    },
});

const { t } = useI18n();
const connector = ref({ ...props.initialConnector });
const catalog = ref(null);
const loading = ref(false);
const importingChannelId = ref(null);
const error = ref('');
const info = ref('');

const isAvailable = computed(() => Boolean(connector.value?.available));
const hasCatalog = computed(() => catalog.value !== null);

const requestErrorMessage = (_requestError, fallback) => fallback;

watch(() => props.initialConnector, (value) => {
    connector.value = { ...(value || {}) };
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

const importChannel = async (organization, channel) => {
    importingChannelId.value = channel.id;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.post(route('social.buffer.channels.store'), {
            organization_id: organization.id,
            channel_id: channel.id,
        });

        if (!channel.imported) {
            catalog.value.imported_count = Number(catalog.value.imported_count || 0) + 1;
        }

        channel.imported = true;
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
</script>

<template>
    <section
        class="overflow-hidden rounded-sm border border-violet-200 bg-white shadow-sm dark:border-violet-500/30 dark:bg-neutral-900"
        :aria-busy="loading || importingChannelId !== null"
    >
        <div class="border-b border-violet-100 bg-violet-50/70 px-5 py-5 dark:border-violet-500/20 dark:bg-violet-500/10 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-3xl">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-sm bg-violet-600 px-2.5 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-white">
                            Buffer
                        </span>
                        <span class="rounded-sm border border-violet-200 bg-white px-2.5 py-1 text-xs font-medium text-violet-700 dark:border-violet-500/30 dark:bg-neutral-900 dark:text-violet-300">
                            {{ t('social.buffer_connector.local_mode') }}
                        </span>
                        <span class="rounded-sm border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                            {{ t('social.buffer_connector.delivery_disabled') }}
                        </span>
                    </div>

                    <h3 class="mt-4 text-xl font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('social.buffer_connector.title') }}
                    </h3>
                    <p class="mt-2 text-sm leading-6 text-stone-600 dark:text-neutral-300">
                        {{ t('social.buffer_connector.description') }}
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
                        v-if="props.canManage && isAvailable"
                        type="button"
                        class="w-full justify-center sm:w-auto"
                        :disabled="loading || importingChannelId !== null"
                        @click="loadCatalog"
                    >
                        <RefreshCw class="mr-2 size-4" :class="{ 'animate-spin': loading }" />
                        {{ hasCatalog
                            ? t('social.buffer_connector.actions.refresh')
                            : t('social.buffer_connector.actions.connect') }}
                    </PrimaryButton>
                </div>
            </div>
        </div>

        <div class="space-y-5 p-5 sm:p-6">
            <div
                v-if="!isAvailable"
                class="rounded-sm border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                role="status"
            >
                <div class="font-semibold">{{ t('social.buffer_connector.configuration_title') }}</div>
                <div class="mt-1">
                    {{ connector.configured
                        ? t('social.buffer_connector.configuration_enable')
                        : t('social.buffer_connector.configuration_token') }}
                </div>
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

            <div v-if="catalog" class="space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-stone-200 bg-stone-50 px-4 py-3 dark:border-neutral-700 dark:bg-neutral-800/70">
                    <div class="min-w-0">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                            {{ t('social.buffer_connector.account_label') }}
                        </div>
                        <div class="mt-1 break-words font-semibold text-stone-900 dark:text-neutral-100">
                            {{ catalog.account?.name || t('social.buffer_connector.account_fallback') }}
                        </div>
                    </div>
                    <div class="text-sm text-stone-600 dark:text-neutral-300">
                        {{ t('social.buffer_connector.catalog_summary', {
                            channels: catalog.channel_count,
                            imported: catalog.imported_count,
                        }) }}
                    </div>
                </div>

                <div
                    v-for="organization in catalog.organizations"
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
                                        <span
                                            v-if="channel.imported"
                                            class="rounded-sm border border-sky-200 bg-sky-50 px-2 py-0.5 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300"
                                        >
                                            {{ t('social.buffer_connector.states.imported') }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <SecondaryButton
                                v-if="props.canManage"
                                type="button"
                                class="w-full justify-center sm:w-auto"
                                :disabled="channel.imported || !channel.can_import || importingChannelId !== null || loading"
                                @click="importChannel(organization, channel)"
                            >
                                {{ importingChannelId === channel.id
                                    ? t('social.buffer_connector.actions.importing')
                                    : channel.imported
                                        ? t('social.buffer_connector.actions.imported')
                                        : t('social.buffer_connector.actions.import') }}
                            </SecondaryButton>
                        </div>
                    </div>

                    <div v-else class="p-4 text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('social.buffer_connector.no_channels') }}
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
