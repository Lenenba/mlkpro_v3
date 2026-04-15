<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ProspectProviderLogo from '@/Pages/Campaigns/Components/ProspectProviderLogo.vue';

const props = defineProps({
    card: {
        type: Object,
        required: true,
    },
    canManageSecrets: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['select']);

const { t } = useI18n();

const displayStatus = computed(() => String(props.card?.display_status || props.card?.connection?.status || 'not_connected'));
const connection = computed(() => props.card?.connection || null);
const primaryActionLabel = computed(() => {
    if (!props.canManageSecrets) {
        return t('marketing.prospect_provider_manager.view_details');
    }

    if (!connection.value) {
        return t('marketing.prospect_provider_manager.actions.connect');
    }

    if (displayStatus.value === 'connected') {
        return t('marketing.prospect_provider_manager.actions.manage');
    }

    if (displayStatus.value === 'pending') {
        return t('marketing.prospect_provider_manager.actions.resume');
    }

    return t('marketing.prospect_provider_manager.actions.reconnect');
});

const statusClass = computed(() => {
    if (displayStatus.value === 'connected') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300';
    }

    if (displayStatus.value === 'pending') {
        return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300';
    }

    if (displayStatus.value === 'not_connected') {
        return 'border-stone-200 bg-stone-50 text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300';
    }

    if (displayStatus.value === 'setup_required') {
        return 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-300';
    }

    if (displayStatus.value === 'expired' || displayStatus.value === 'reconnect_required') {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300';
    }

    return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300';
});

const statusLabel = computed(() => t(`marketing.prospect_provider_manager.statuses.${displayStatus.value}`));
</script>

<template>
    <button
        type="button"
        class="group relative flex h-full w-full flex-col justify-between rounded-3xl border border-stone-200 bg-white/95 p-5 text-left shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-stone-300 hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-sky-300 dark:border-neutral-700 dark:bg-neutral-900/95 dark:hover:border-neutral-600"
        @click="emit('select', card)"
    >
        <div class="space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-center gap-3">
                    <ProspectProviderLogo :provider-key="String(card.logo_key || card.key || '')" />
                    <div>
                        <div class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                            {{ card.label }}
                        </div>
                        <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                            {{ card.auth_strategy === 'oauth' ? 'OAuth' : 'API key' }}
                        </div>
                    </div>
                </div>

                <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold" :class="statusClass">
                    {{ statusLabel }}
                </span>
            </div>

            <p class="text-sm leading-6 text-stone-600 dark:text-neutral-300">
                {{ card.short_description }}
            </p>

            <div class="rounded-2xl border border-stone-200/80 bg-stone-50/90 p-3 text-sm dark:border-neutral-700 dark:bg-neutral-800/80">
                <template v-if="connection">
                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                        {{ connection.label }}
                    </div>
                    <div v-if="connection.external_account_label" class="mt-1 text-stone-600 dark:text-neutral-300">
                        {{ connection.external_account_label }}
                    </div>
                    <div v-else class="mt-1 text-stone-500 dark:text-neutral-400">
                        {{ card.connect_description }}
                    </div>
                    <div v-if="connection.last_error" class="mt-2 text-xs text-rose-600 dark:text-rose-300">
                        {{ connection.last_error }}
                    </div>
                </template>

                <template v-else>
                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                        {{ t('marketing.prospect_provider_manager.card.ready_title') }}
                    </div>
                    <div class="mt-1 text-stone-500 dark:text-neutral-400">
                        {{ card.connect_description }}
                    </div>
                </template>
            </div>
        </div>

        <div class="mt-5 flex items-center justify-between gap-3 text-xs">
            <span class="text-stone-500 dark:text-neutral-400">
                {{ connection?.connected_at ? t('marketing.prospect_provider_manager.card.connected_at', { date: new Date(connection.connected_at).toLocaleDateString() }) : t('marketing.prospect_provider_manager.card.no_connection') }}
            </span>
            <span class="inline-flex items-center gap-1 rounded-full bg-stone-900 px-3 py-1.5 font-semibold text-white transition group-hover:bg-stone-700 dark:bg-neutral-100 dark:text-neutral-900 dark:group-hover:bg-white">
                {{ primaryActionLabel }}
            </span>
        </div>
    </button>
</template>
