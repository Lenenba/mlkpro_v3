<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
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
    canManageSecrets: {
        type: Boolean,
        default: true,
    },
});

const { t } = useI18n();

const definitions = ref(Array.isArray(props.initialDefinitions) ? props.initialDefinitions : []);
const rows = ref(Array.isArray(props.initialConnections) ? props.initialConnections : []);
const summary = ref(props.initialSummary && typeof props.initialSummary === 'object' ? props.initialSummary : {});
const access = ref({
    can_manage_secrets: Boolean(props.canManageSecrets),
});
const busy = ref(false);
const isLoadingList = ref(false);
const error = ref('');
const info = ref('');
const editingId = ref(null);
const listSearch = ref('');
const listPage = ref(1);
const listPerPage = ref(10);
const perPageOptions = [10, 25, 50];

const providerOptions = computed(() => definitions.value.map((definition) => ({
    value: definition.key,
    label: definition.label,
})));

const createEmptyForm = () => ({
    provider_key: providerOptions.value[0]?.value || 'apollo',
    label: '',
    credentials: {
        api_key: '',
    },
});

const form = ref(createEmptyForm());

const selectedDefinition = computed(() => {
    return definitions.value.find((definition) => definition.key === form.value.provider_key) || null;
});

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

const filteredRows = computed(() => {
    const query = String(listSearch.value || '').trim().toLowerCase();
    if (!query) {
        return rows.value;
    }

    return rows.value.filter((connection) => {
        const haystack = [
            connection?.provider_label,
            connection?.provider_key,
            connection?.label,
            connection?.status,
        ]
            .map((value) => String(value || '').toLowerCase())
            .join(' ');

        return haystack.includes(query);
    });
});

const totalPages = computed(() => Math.max(1, Math.ceil(filteredRows.value.length / listPerPage.value)));
const pagedRows = computed(() => {
    const start = (listPage.value - 1) * listPerPage.value;
    return filteredRows.value.slice(start, start + listPerPage.value);
});
const providerTableRows = computed(() => (isLoadingList.value
    ? Array.from({ length: 4 }, (_, index) => ({ id: `provider-skeleton-${index}`, __skeleton: true }))
    : pagedRows.value));
const canGoPrevious = computed(() => listPage.value > 1);
const canGoNext = computed(() => listPage.value < totalPages.value);

watch([filteredRows, listPerPage], () => {
    listPage.value = 1;
});

watch(totalPages, (value) => {
    if (listPage.value > value) {
        listPage.value = value;
    }
});

watch(providerOptions, (options) => {
    if (!options.length) {
        return;
    }

    if (!options.some((option) => option.value === form.value.provider_key)) {
        form.value.provider_key = options[0].value;
    }
}, { immediate: true });

const statusClass = (status) => {
    if (status === 'connected') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300';
    }

    if (status === 'invalid' || status === 'expired' || status === 'rate_limited') {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300';
    }

    if (status === 'draft') {
        return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300';
    }

    if (status === 'disconnected') {
        return 'border-stone-200 bg-stone-50 text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300';
    }

    return 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300';
};

const statusLabel = (status) => t(`marketing.prospect_provider_manager.statuses.${status || 'draft'}`);

const resetForm = () => {
    editingId.value = null;
    form.value = createEmptyForm();
};

const normalizeConnectionsPayload = (payload) => Array.isArray(payload) ? payload : [];
const normalizeDefinitionsPayload = (payload) => Array.isArray(payload) ? payload : [];
const normalizeSummaryPayload = (payload) => (payload && typeof payload === 'object') ? payload : {};

const load = async () => {
    isLoadingList.value = true;
    error.value = '';

    try {
        const response = await axios.get(route('marketing.prospect-providers.index'));
        definitions.value = normalizeDefinitionsPayload(response.data?.provider_definitions);
        rows.value = normalizeConnectionsPayload(response.data?.provider_connections);
        summary.value = normalizeSummaryPayload(response.data?.provider_summary);
        access.value = {
            can_manage_secrets: Boolean(response.data?.access?.can_manage_secrets ?? props.canManageSecrets),
        };

        if (!editingId.value && providerOptions.value.length) {
            form.value.provider_key = providerOptions.value[0].value;
        }
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.prospect_provider_manager.error_load');
    } finally {
        isLoadingList.value = false;
    }
};

const edit = (connection) => {
    if (!access.value.can_manage_secrets) {
        return;
    }

    editingId.value = Number(connection.id);
    form.value = {
        provider_key: connection.provider_key || providerOptions.value[0]?.value || 'apollo',
        label: connection.label || '',
        credentials: {
            api_key: '',
        },
    };
    error.value = '';
    info.value = '';
};

const save = async () => {
    if (!access.value.can_manage_secrets) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const payload = {
            provider_key: String(form.value.provider_key || '').trim(),
            label: String(form.value.label || '').trim(),
            credentials: {
                api_key: String(form.value.credentials?.api_key || '').trim(),
            },
        };

        if (editingId.value) {
            await axios.put(route('marketing.prospect-providers.update', editingId.value), payload);
            info.value = t('marketing.prospect_provider_manager.info_updated');
        } else {
            await axios.post(route('marketing.prospect-providers.store'), payload);
            info.value = t('marketing.prospect_provider_manager.info_created');
        }

        resetForm();
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.prospect_provider_manager.error_save');
    } finally {
        busy.value = false;
    }
};

const validateConnection = async (connection) => {
    if (!access.value.can_manage_secrets) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        await axios.post(route('marketing.prospect-providers.validate', connection.id));
        info.value = t('marketing.prospect_provider_manager.info_validated', {
            provider: connection.provider_label || connection.label || connection.provider_key,
        });
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.prospect_provider_manager.error_validate');
    } finally {
        busy.value = false;
    }
};

const disconnectConnection = async (connection) => {
    if (!access.value.can_manage_secrets) {
        return;
    }

    if (!confirm(t('marketing.prospect_provider_manager.confirm_disconnect', {
        name: connection.label || connection.provider_label || connection.provider_key,
    }))) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        await axios.post(route('marketing.prospect-providers.disconnect', connection.id));
        info.value = t('marketing.prospect_provider_manager.info_disconnected');
        if (editingId.value === Number(connection.id)) {
            resetForm();
        }
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
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 class="inline-flex items-center gap-1.5 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    <svg class="size-4 text-green-600 dark:text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M8 4h8" />
                        <rect x="3" y="6" width="18" height="14" rx="2" />
                        <path d="M7 10h10" />
                        <path d="M7 14h6" />
                    </svg>
                    <span>{{ t('marketing.prospect_provider_manager.title') }}</span>
                </h3>
                <p class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ t('marketing.prospect_provider_manager.description') }}
                </p>
            </div>
            <SecondaryButton :disabled="busy || isLoadingList" @click="load">
                {{ t('marketing.common.reload') }}
            </SecondaryButton>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <div
                v-for="card in summaryCards"
                :key="`provider-summary-${card.key}`"
                class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            >
                <div class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                    {{ t(`marketing.prospect_provider_manager.summary.${card.key}`) }}
                </div>
                <div class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                    {{ card.value }}
                </div>
            </div>
        </div>

        <div
            v-if="!access.can_manage_secrets"
            class="rounded-sm border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
        >
            <div class="font-semibold">{{ t('marketing.prospect_provider_manager.read_only_title') }}</div>
            <div class="mt-1">{{ t('marketing.prospect_provider_manager.read_only_description') }}</div>
        </div>

        <div v-if="error" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
            {{ error }}
        </div>
        <div v-if="info" class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ info }}
        </div>

        <div
            v-if="access.can_manage_secrets"
            class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800"
        >
            <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                <FloatingSelect
                    v-model="form.provider_key"
                    :label="t('marketing.prospect_provider_manager.fields.provider')"
                    :options="providerOptions"
                    option-value="value"
                    option-label="label"
                />
                <FloatingInput
                    v-model="form.label"
                    :label="t('marketing.prospect_provider_manager.fields.label')"
                />
                <FloatingInput
                    v-model="form.credentials.api_key"
                    type="password"
                    :label="t('marketing.prospect_provider_manager.fields.api_key')"
                    class="md:col-span-2"
                />
            </div>
            <div class="mt-2 rounded-sm border border-dashed border-stone-300 bg-white px-3 py-2 text-xs text-stone-500 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-400">
                {{ editingId ? t('marketing.prospect_provider_manager.secret_update_hint') : t('marketing.prospect_provider_manager.secret_store_hint') }}
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <PrimaryButton type="button" :disabled="busy || !selectedDefinition" @click="save">
                    {{ editingId ? t('marketing.prospect_provider_manager.update_connection') : t('marketing.prospect_provider_manager.create_connection') }}
                </PrimaryButton>
                <SecondaryButton type="button" :disabled="busy" @click="resetForm">
                    {{ t('marketing.common.reset') }}
                </SecondaryButton>
            </div>
        </div>

        <div class="space-y-3 rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                <FloatingInput v-model="listSearch" :label="t('marketing.prospect_provider_manager.search_connection')" />
                <FloatingSelect
                    v-model="listPerPage"
                    :label="t('marketing.common.rows_per_page')"
                    :options="perPageOptions.map((value) => ({ value, label: String(value) }))"
                    option-value="value"
                    option-label="label"
                />
            </div>

            <AdminDataTable embedded :rows="providerTableRows" :show-pagination="false">
                <template #head>
                    <tr class="text-left text-xs uppercase text-stone-500 dark:text-neutral-400">
                        <th class="px-3 py-2 font-medium">{{ t('marketing.prospect_provider_manager.columns.provider') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('marketing.prospect_provider_manager.columns.label') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('marketing.prospect_provider_manager.columns.status') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('marketing.prospect_provider_manager.columns.last_validated') }}</th>
                        <th class="px-3 py-2 font-medium text-right">{{ t('marketing.template_manager.actions') }}</th>
                    </tr>
                </template>

                <template #row="{ row: connection }">
                    <tr>
                        <template v-if="connection.__skeleton">
                            <td v-for="col in 5" :key="`provider-skeleton-${connection.id}-${col}`" class="px-3 py-2">
                                <div class="h-3 w-full animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            </td>
                        </template>
                        <template v-else>
                            <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">
                                <div class="font-medium">{{ connection.provider_label }}</div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">{{ connection.provider_key }}</div>
                            </td>
                            <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">
                                <div>{{ connection.label }}</div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ connection.has_credentials ? t('marketing.prospect_provider_manager.credentials_saved') : t('marketing.prospect_provider_manager.no_credentials') }}
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <span class="inline-flex items-center rounded-sm border px-2 py-1 text-xs font-medium" :class="statusClass(connection.status)">
                                    {{ statusLabel(connection.status) }}
                                </span>
                                <div v-if="connection.last_error" class="mt-1 max-w-xs text-xs text-rose-600 dark:text-rose-300">
                                    {{ connection.last_error }}
                                </div>
                            </td>
                            <td class="px-3 py-2 text-xs text-stone-600 dark:text-neutral-300">
                                {{ connection.last_validated_at ? new Date(connection.last_validated_at).toLocaleString() : t('marketing.prospect_provider_manager.not_validated') }}
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <SecondaryButton
                                        v-if="access.can_manage_secrets"
                                        type="button"
                                        :disabled="busy"
                                        @click="edit(connection)"
                                    >
                                        {{ t('marketing.common.edit') }}
                                    </SecondaryButton>
                                    <SecondaryButton
                                        v-if="access.can_manage_secrets"
                                        type="button"
                                        :disabled="busy || !connection.has_credentials"
                                        @click="validateConnection(connection)"
                                    >
                                        {{ t('marketing.prospect_provider_manager.validate_connection') }}
                                    </SecondaryButton>
                                    <button
                                        v-if="access.can_manage_secrets"
                                        type="button"
                                        class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20"
                                        :disabled="busy"
                                        @click="disconnectConnection(connection)"
                                    >
                                        {{ t('marketing.prospect_provider_manager.disconnect_connection') }}
                                    </button>
                                    <span
                                        v-else
                                        class="text-xs text-stone-500 dark:text-neutral-400"
                                    >
                                        {{ t('marketing.prospect_provider_manager.view_only_actions') }}
                                    </span>
                                </div>
                            </td>
                        </template>
                    </tr>
                </template>

                <template #empty>
                    <div class="px-3 py-6 text-center text-xs text-stone-500 dark:text-neutral-400">
                        {{ t('marketing.prospect_provider_manager.no_connection_found') }}
                    </div>
                </template>
            </AdminDataTable>

            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                <span>{{ t('marketing.common.results_count', { count: filteredRows.length }) }}</span>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="rounded-sm border border-stone-200 bg-white px-2 py-1 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                        :disabled="!canGoPrevious"
                        @click="listPage -= 1"
                    >
                        {{ t('marketing.common.previous') }}
                    </button>
                    <span>{{ t('marketing.common.page_of', { page: listPage, total: totalPages }) }}</span>
                    <button
                        type="button"
                        class="rounded-sm border border-stone-200 bg-white px-2 py-1 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                        :disabled="!canGoNext"
                        @click="listPage += 1"
                    >
                        {{ t('marketing.common.next') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
