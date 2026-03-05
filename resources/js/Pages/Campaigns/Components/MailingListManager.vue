<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const { t } = useI18n();

const rows = ref([]);
const selectedList = ref(null);
const busy = ref(false);
const isLoadingLists = ref(false);
const isLoadingCustomers = ref(false);
const error = ref('');
const info = ref('');
const editingId = ref(null);
const listSearch = ref('');
const listPage = ref(1);
const listPerPage = ref(10);
const perPageOptions = [10, 25, 50];
const customerSearch = ref('');
const customerPerPage = ref(25);
const customerPickerOpen = ref(false);
const isLoadingAvailableCustomers = ref(false);
const availableCustomerError = ref('');
const availableCustomerSearch = ref('');
const availableCustomerPerPage = ref(25);
const availableCustomerRows = ref([]);
const availableCustomerMeta = ref(null);
const availableCustomerSelectedIds = ref([]);

const form = ref({
    name: '',
    description: '',
    tags: '',
});

const importForm = ref({
    paste: '',
});

const listCustomers = computed(() => selectedList.value?.customers?.data || []);
const customerMeta = computed(() => selectedList.value?.customers || null);
const customerPage = computed(() => Number(customerMeta.value?.current_page || 1));
const canCustomerPrev = computed(() => Boolean(customerMeta.value?.prev_page_url));
const canCustomerNext = computed(() => Boolean(customerMeta.value?.next_page_url));
const availableCustomerPage = computed(() => Number(availableCustomerMeta.value?.current_page || 1));
const canAvailableCustomerPrev = computed(() => Boolean(availableCustomerMeta.value?.prev_page_url));
const canAvailableCustomerNext = computed(() => Boolean(availableCustomerMeta.value?.next_page_url));
const selectedAvailableCustomerCount = computed(() => availableCustomerSelectedIds.value.length);
const allVisibleAvailableCustomersSelected = computed(() => {
    if (availableCustomerRows.value.length === 0) {
        return false;
    }

    return availableCustomerRows.value.every((customer) => {
        const customerId = Number(customer?.id || 0);
        return customerId > 0 && availableCustomerSelectedIds.value.includes(customerId);
    });
});

const filteredRows = computed(() => {
    const query = String(listSearch.value || '').trim().toLowerCase();
    if (!query) {
        return rows.value;
    }

    return rows.value.filter((list) => {
        const haystack = [
            list?.name,
            list?.description,
            list?.customers_count,
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

const parseTags = (value) =>
    String(value || '')
        .split(/[,\n;]+/)
        .map((item) => item.trim())
        .filter((item) => item !== '');

const customerDisplayName = (customer) => {
    return customer?.company_name
        || `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim()
        || '-';
};

const resetForm = () => {
    editingId.value = null;
    form.value = {
        name: '',
        description: '',
        tags: '',
    };
};

const load = async () => {
    isLoadingLists.value = true;
    error.value = '';
    try {
        const response = await axios.get(route('marketing.mailing-lists.index'));
        rows.value = Array.isArray(response.data?.mailing_lists) ? response.data.mailing_lists : [];
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.mailing_list_manager.error_load_lists');
    } finally {
        isLoadingLists.value = false;
    }
};

const selectList = async (listId, options = {}) => {
    if (!listId) {
        selectedList.value = null;
        return;
    }

    isLoadingCustomers.value = true;
    error.value = '';
    try {
        const resolvedSearch = options.search ?? customerSearch.value ?? '';
        const resolvedPerPage = options.perPage ?? customerPerPage.value ?? 25;
        const params = {
            search: resolvedSearch || undefined,
            per_page: Number(resolvedPerPage),
            page: Number(options.page || 1),
        };

        const response = await axios.get(route('marketing.mailing-lists.show', listId), { params });
        selectedList.value = {
            ...(response.data?.mailing_list || null),
            customers: response.data?.customers || { data: [] },
        };
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.mailing_list_manager.error_load_list');
    } finally {
        isLoadingCustomers.value = false;
    }
};

const edit = (list) => {
    editingId.value = Number(list.id);
    form.value = {
        name: list.name || '',
        description: list.description || '',
        tags: Array.isArray(list.tags) ? list.tags.join(', ') : '',
    };
};

const save = async () => {
    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const payload = {
            name: String(form.value.name || '').trim(),
            description: String(form.value.description || '').trim() || null,
            tags: parseTags(form.value.tags),
        };

        if (editingId.value) {
            await axios.put(route('marketing.mailing-lists.update', editingId.value), payload);
            info.value = t('marketing.mailing_list_manager.info_list_updated');
        } else {
            await axios.post(route('marketing.mailing-lists.store'), payload);
            info.value = t('marketing.mailing_list_manager.info_list_created');
        }

        const selectedId = selectedList.value?.id || null;
        resetForm();
        await load();
        if (selectedId) {
            await selectList(selectedId);
        }
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.mailing_list_manager.error_save_list');
    } finally {
        busy.value = false;
    }
};

const remove = async (list) => {
    if (!confirm(t('marketing.mailing_list_manager.confirm_delete', { name: list.name }))) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        await axios.delete(route('marketing.mailing-lists.destroy', list.id));
        if (selectedList.value?.id === list.id) {
            selectedList.value = null;
        }
        info.value = t('marketing.mailing_list_manager.info_list_deleted');
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.mailing_list_manager.error_delete_list');
    } finally {
        busy.value = false;
    }
};

const importPaste = async () => {
    const listId = selectedList.value?.id;
    if (!listId) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.post(route('marketing.mailing-lists.import', listId), {
            paste: importForm.value.paste || '',
        });
        const added = Number(response.data?.result?.added || 0);
        const total = Number(response.data?.result?.total || 0);
        info.value = t('marketing.mailing_list_manager.info_import_completed', { added, total });
        importForm.value.paste = '';
        await load();
        await selectList(listId, { page: customerPage.value });
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.mailing_list_manager.error_import_contacts');
    } finally {
        busy.value = false;
    }
};

const loadAvailableCustomers = async (options = {}) => {
    const listId = selectedList.value?.id;
    if (!listId) {
        return;
    }

    isLoadingAvailableCustomers.value = true;
    availableCustomerError.value = '';

    try {
        const resolvedSearch = options.search ?? availableCustomerSearch.value ?? '';
        const resolvedPerPage = options.perPage ?? availableCustomerPerPage.value ?? 25;
        const params = {
            search: resolvedSearch || undefined,
            per_page: Number(resolvedPerPage),
            page: Number(options.page || 1),
        };

        const response = await axios.get(route('marketing.mailing-lists.available-customers', listId), { params });
        const customers = response.data?.customers || { data: [] };
        availableCustomerRows.value = Array.isArray(customers.data) ? customers.data : [];
        availableCustomerMeta.value = customers;
    } catch (requestError) {
        availableCustomerError.value = requestError?.response?.data?.message || requestError?.message || t('marketing.mailing_list_manager.error_search_customers');
    } finally {
        isLoadingAvailableCustomers.value = false;
    }
};

const openCustomerPicker = async () => {
    if (!selectedList.value?.id) {
        return;
    }

    customerPickerOpen.value = true;
    availableCustomerError.value = '';
    availableCustomerSelectedIds.value = [];
    await loadAvailableCustomers({ page: 1 });
};

const closeCustomerPicker = () => {
    if (availableCustomerFilterTimeout) {
        clearTimeout(availableCustomerFilterTimeout);
        availableCustomerFilterTimeout = null;
    }
    customerPickerOpen.value = false;
    availableCustomerError.value = '';
    availableCustomerRows.value = [];
    availableCustomerMeta.value = null;
    availableCustomerSelectedIds.value = [];
    availableCustomerSearch.value = '';
    availableCustomerPerPage.value = 25;
};

const goToAvailableCustomerPage = (page) => {
    if (!customerPickerOpen.value || page < 1) {
        return;
    }

    loadAvailableCustomers({ page });
};

const toggleAvailableCustomerSelection = (customerId) => {
    const normalizedId = Number(customerId || 0);
    if (normalizedId < 1) {
        return;
    }

    const index = availableCustomerSelectedIds.value.indexOf(normalizedId);
    if (index >= 0) {
        availableCustomerSelectedIds.value.splice(index, 1);
        return;
    }

    availableCustomerSelectedIds.value.push(normalizedId);
};

const toggleAllVisibleAvailableCustomers = () => {
    const visibleIds = availableCustomerRows.value
        .map((customer) => Number(customer?.id || 0))
        .filter((customerId) => customerId > 0);

    if (visibleIds.length === 0) {
        return;
    }

    if (allVisibleAvailableCustomersSelected.value) {
        availableCustomerSelectedIds.value = availableCustomerSelectedIds.value.filter((customerId) => !visibleIds.includes(customerId));
        return;
    }

    const merged = new Set([
        ...availableCustomerSelectedIds.value,
        ...visibleIds,
    ]);
    availableCustomerSelectedIds.value = Array.from(merged);
};

const importSelectedCustomers = async () => {
    const listId = selectedList.value?.id;
    const customerIds = availableCustomerSelectedIds.value
        .map((customerId) => Number(customerId || 0))
        .filter((customerId) => customerId > 0);

    if (!listId || customerIds.length === 0) {
        return;
    }

    const currentPage = customerPage.value;
    const currentSearch = customerSearch.value;
    const currentPerPage = customerPerPage.value;

    busy.value = true;
    error.value = '';
    info.value = '';
    availableCustomerError.value = '';

    try {
        const response = await axios.post(route('marketing.mailing-lists.import', listId), {
            customer_ids: customerIds,
        });
        const added = Number(response.data?.result?.added || 0);
        const alreadyPresent = Number(response.data?.result?.already_present || 0);
        const total = Number(response.data?.result?.total || 0);
        info.value = t('marketing.mailing_list_manager.info_import_selected_completed', { added, alreadyPresent, total });
        closeCustomerPicker();
        await load();
        await selectList(listId, {
            page: currentPage,
            search: currentSearch,
            perPage: currentPerPage,
        });
    } catch (requestError) {
        availableCustomerError.value = requestError?.response?.data?.message || requestError?.message || t('marketing.mailing_list_manager.error_import_selected');
    } finally {
        busy.value = false;
    }
};

const removeCustomer = async (customerId) => {
    const listId = selectedList.value?.id;
    if (!listId || !customerId) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        await axios.post(route('marketing.mailing-lists.remove-customers', listId), {
            customer_ids: [customerId],
        });
        info.value = t('marketing.mailing_list_manager.info_customer_removed');
        await load();
        await selectList(listId, { page: customerPage.value });
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.mailing_list_manager.error_remove_customer');
    } finally {
        busy.value = false;
    }
};

const refreshCustomers = () => {
    const listId = selectedList.value?.id;
    if (!listId) {
        return;
    }

    selectList(listId, {
        page: customerPage.value,
    });
};

const goToCustomerPage = (page) => {
    const listId = selectedList.value?.id;
    if (!listId || page < 1) {
        return;
    }

    selectList(listId, { page });
};

const applyCustomerFilters = () => {
    const listId = selectedList.value?.id;
    if (!listId) {
        return;
    }

    selectList(listId, {
        page: 1,
        search: customerSearch.value,
        perPage: customerPerPage.value,
    });
};

let customerFilterTimeout = null;
let availableCustomerFilterTimeout = null;
watch([customerSearch, customerPerPage], () => {
    if (!selectedList.value?.id) {
        return;
    }
    if (customerFilterTimeout) {
        clearTimeout(customerFilterTimeout);
    }
    customerFilterTimeout = setTimeout(() => {
        applyCustomerFilters();
    }, 280);
});

watch([availableCustomerSearch, availableCustomerPerPage], () => {
    if (!customerPickerOpen.value || !selectedList.value?.id) {
        return;
    }
    if (availableCustomerFilterTimeout) {
        clearTimeout(availableCustomerFilterTimeout);
    }
    availableCustomerFilterTimeout = setTimeout(() => {
        loadAvailableCustomers({
            page: 1,
            search: availableCustomerSearch.value,
            perPage: availableCustomerPerPage.value,
        });
    }, 280);
});

watch(
    () => selectedList.value?.id,
    (nextId, previousId) => {
        if (nextId !== previousId) {
            closeCustomerPicker();
        }
    }
);

onBeforeUnmount(() => {
    if (customerFilterTimeout) {
        clearTimeout(customerFilterTimeout);
    }
    if (availableCustomerFilterTimeout) {
        clearTimeout(availableCustomerFilterTimeout);
    }
});

load();
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="inline-flex items-center gap-1.5 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                <svg class="size-4 text-green-600 dark:text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 7h16" />
                    <path d="M4 12h16" />
                    <path d="M4 17h16" />
                </svg>
                <span>{{ t('marketing.mailing_list_manager.title') }}</span>
            </h3>
            <SecondaryButton :disabled="busy || isLoadingLists || isLoadingCustomers" @click="load">
                {{ t('marketing.common.reload') }}
            </SecondaryButton>
        </div>

        <div v-if="error" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
            {{ error }}
        </div>
        <div v-if="info" class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ info }}
        </div>

        <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                <div class="grid grid-cols-1 gap-2">
                    <FloatingInput v-model="form.name" :label="t('marketing.mailing_list_manager.list_name')" />
                    <FloatingInput v-model="form.description" :label="t('marketing.mailing_list_manager.description')" />
                    <FloatingInput v-model="form.tags" :label="t('marketing.mailing_list_manager.tags')" />
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <PrimaryButton type="button" :disabled="busy" @click="save">
                        {{ editingId ? t('marketing.mailing_list_manager.update_list') : t('marketing.mailing_list_manager.create_list') }}
                    </PrimaryButton>
                    <SecondaryButton type="button" :disabled="busy" @click="resetForm">
                        {{ t('marketing.common.reset') }}
                    </SecondaryButton>
                </div>
            </div>

            <div class="space-y-3 rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    <FloatingInput v-model="listSearch" :label="t('marketing.mailing_list_manager.search_list')" />
                    <FloatingSelect
                        v-model="listPerPage"
                        :label="t('marketing.common.rows_per_page')"
                        :options="perPageOptions.map((value) => ({ value, label: String(value) }))"
                        option-value="value"
                        option-label="label"
                    />
                </div>
                <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                    <thead>
                        <tr class="text-left text-xs uppercase text-stone-500 dark:text-neutral-400">
                            <th class="px-3 py-2 font-medium">{{ t('marketing.template_manager.name') }}</th>
                            <th class="px-3 py-2 font-medium">{{ t('marketing.mailing_list_manager.customers') }}</th>
                            <th class="px-3 py-2 font-medium text-right">{{ t('marketing.mailing_list_manager.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                        <template v-if="isLoadingLists">
                            <tr v-for="row in 6" :key="`mailing-list-skeleton-${row}`">
                                <td v-for="col in 3" :key="`mailing-list-skeleton-${row}-${col}`" class="px-3 py-2">
                                    <div class="h-3 w-full animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                </td>
                            </tr>
                        </template>
                        <tr v-else-if="pagedRows.length === 0">
                            <td colspan="3" class="px-3 py-6 text-center text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('marketing.mailing_list_manager.no_list_found') }}
                            </td>
                        </tr>
                        <tr
                            v-for="list in pagedRows"
                            :key="`mailing-list-${list.id}`"
                            class="cursor-pointer"
                            :class="selectedList?.id === list.id ? 'bg-green-50 dark:bg-green-500/10' : ''"
                            @click="selectList(list.id)"
                        >
                            <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">
                                <div class="font-medium">{{ list.name }}</div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">{{ list.description || '-' }}</div>
                            </td>
                            <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ list.customers_count || 0 }}</td>
                            <td class="px-3 py-2">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        type="button"
                                        class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                        :disabled="busy"
                                        @click.stop="edit(list)"
                                    >
                                        {{ t('marketing.common.edit') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-sm border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700 hover:bg-rose-100 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20"
                                        :disabled="busy"
                                        @click.stop="remove(list)"
                                    >
                                        {{ t('marketing.common.delete') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                    <div>{{ t('marketing.common.results_count', { count: filteredRows.length }) }}</div>
                    <div class="flex items-center gap-2">
                        <SecondaryButton type="button" :disabled="!canGoPrevious" @click="listPage -= 1">
                            {{ t('marketing.common.previous') }}
                        </SecondaryButton>
                        <span>{{ t('marketing.common.page_of', { page: listPage, total: totalPages }) }}</span>
                        <SecondaryButton type="button" :disabled="!canGoNext" @click="listPage += 1">
                            {{ t('marketing.common.next') }}
                        </SecondaryButton>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="selectedList" class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h4 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ t('marketing.mailing_list_manager.customers_count_label', { name: selectedList.name, count: selectedList.customers?.total || 0 }) }}
                </h4>
                <button
                    type="button"
                    class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    :disabled="busy || isLoadingCustomers"
                    @click="refreshCustomers"
                >
                    {{ t('marketing.mailing_list_manager.refresh_list') }}
                </button>
            </div>

            <div class="mt-2 rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.mailing_list_manager.bulk_import_title') }}</div>
                <FloatingTextarea
                    v-model="importForm.paste"
                    class="mt-2"
                    :label="t('marketing.mailing_list_manager.paste_ids')"
                />
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <PrimaryButton
                        type="button"
                        :disabled="busy || !importForm.paste"
                        @click="importPaste"
                    >
                        {{ t('marketing.mailing_list_manager.import_paste') }}
                    </PrimaryButton>
                    <SecondaryButton
                        type="button"
                        :disabled="busy || isLoadingCustomers"
                        @click="openCustomerPicker"
                    >
                        {{ t('marketing.mailing_list_manager.search_clients') }}
                    </SecondaryButton>
                </div>
            </div>

            <div class="mt-3 space-y-2">
                <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                    <FloatingInput v-model="customerSearch" :label="t('marketing.mailing_list_manager.search_customer')" />
                    <FloatingSelect
                        v-model="customerPerPage"
                        :label="t('marketing.common.rows_per_page')"
                        :options="perPageOptions.map((value) => ({ value, label: String(value) }))"
                        option-value="value"
                        option-label="label"
                    />
                </div>
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                    <thead>
                        <tr class="text-left text-xs uppercase text-stone-500 dark:text-neutral-400">
                            <th class="px-3 py-2 font-medium">{{ t('marketing.mailing_list_manager.customer') }}</th>
                            <th class="px-3 py-2 font-medium">{{ t('marketing.mailing_list_manager.email') }}</th>
                            <th class="px-3 py-2 font-medium">{{ t('marketing.mailing_list_manager.phone') }}</th>
                            <th class="px-3 py-2 font-medium">{{ t('marketing.mailing_list_manager.vip') }}</th>
                            <th class="px-3 py-2 font-medium text-right">{{ t('marketing.mailing_list_manager.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                        <template v-if="isLoadingCustomers">
                            <tr v-for="row in 6" :key="`mailing-customer-skeleton-${row}`">
                                <td v-for="col in 5" :key="`mailing-customer-skeleton-${row}-${col}`" class="px-3 py-2">
                                    <div class="h-3 w-full animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                </td>
                            </tr>
                        </template>
                        <tr v-else-if="listCustomers.length === 0">
                            <td colspan="5" class="px-3 py-5 text-center text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('marketing.mailing_list_manager.no_customer_in_list') }}
                            </td>
                        </tr>
                        <tr v-for="customer in listCustomers" :key="`mailing-customer-${customer.id}`">
                            <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">
                                {{ customerDisplayName(customer) }}
                            </td>
                            <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ customer.email || '-' }}</td>
                            <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ customer.phone || '-' }}</td>
                            <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">
                                <span v-if="customer.is_vip" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-200">
                                    {{ customer.vip_tier_code || t('marketing.mailing_list_manager.vip') }}
                                </span>
                                <span v-else>-</span>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end">
                                    <button
                                        type="button"
                                        class="rounded-sm border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700 hover:bg-rose-100 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20"
                                        :disabled="busy"
                                        @click="removeCustomer(customer.id)"
                                    >
                                        {{ t('marketing.mailing_list_manager.remove_customer') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                    <div>{{ t('marketing.mailing_list_manager.customers_count_footer', { count: customerMeta?.total || 0 }) }}</div>
                    <div class="flex items-center gap-2">
                        <SecondaryButton type="button" :disabled="!canCustomerPrev" @click="goToCustomerPage(customerPage - 1)">
                            {{ t('marketing.common.previous') }}
                        </SecondaryButton>
                        <span>{{ t('marketing.common.page_of', { page: customerPage, total: customerMeta?.last_page || customerPage }) }}</span>
                        <SecondaryButton type="button" :disabled="!canCustomerNext" @click="goToCustomerPage(customerPage + 1)">
                            {{ t('marketing.common.next') }}
                        </SecondaryButton>
                    </div>
                </div>
            </div>
        </div>

        <Modal :show="customerPickerOpen" max-width="4xl" @close="closeCustomerPicker">
            <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-4 py-3 dark:border-neutral-700">
                <div>
                    <h5 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ t('marketing.mailing_list_manager.dialog_title') }}</h5>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ t('marketing.mailing_list_manager.dialog_description') }}
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    @click="closeCustomerPicker"
                >
                    {{ t('marketing.common.close') }}
                </button>
            </div>
            <div class="space-y-3 p-4">
                <div v-if="availableCustomerError" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                    {{ availableCustomerError }}
                </div>

                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    <FloatingInput v-model="availableCustomerSearch" :label="t('marketing.mailing_list_manager.search_by_name_email_phone')" />
                    <FloatingSelect
                        v-model="availableCustomerPerPage"
                        :label="t('marketing.common.rows_per_page')"
                        :options="perPageOptions.map((value) => ({ value, label: String(value) }))"
                        option-value="value"
                        option-label="label"
                    />
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                        <thead>
                            <tr class="text-left text-xs uppercase text-stone-500 dark:text-neutral-400">
                                <th class="w-10 px-3 py-2">
                                    <input
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:border-neutral-600 dark:bg-neutral-800"
                                        :checked="allVisibleAvailableCustomersSelected"
                                        :disabled="availableCustomerRows.length === 0"
                                        @change="toggleAllVisibleAvailableCustomers"
                                    >
                                </th>
                                <th class="px-3 py-2 font-medium">{{ t('marketing.mailing_list_manager.customer') }}</th>
                                <th class="px-3 py-2 font-medium">{{ t('marketing.mailing_list_manager.email') }}</th>
                                <th class="px-3 py-2 font-medium">{{ t('marketing.mailing_list_manager.phone') }}</th>
                                <th class="px-3 py-2 font-medium">{{ t('marketing.mailing_list_manager.vip') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <template v-if="isLoadingAvailableCustomers">
                                <tr v-for="row in 6" :key="`available-customer-skeleton-${row}`">
                                    <td v-for="col in 5" :key="`available-customer-skeleton-${row}-${col}`" class="px-3 py-2">
                                        <div class="h-3 w-full animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    </td>
                                </tr>
                            </template>
                            <tr v-else-if="availableCustomerRows.length === 0">
                                <td colspan="5" class="px-3 py-5 text-center text-xs text-stone-500 dark:text-neutral-400">
                                    {{ t('marketing.mailing_list_manager.no_matching_client') }}
                                </td>
                            </tr>
                            <tr v-for="customer in availableCustomerRows" :key="`available-customer-${customer.id}`">
                                <td class="px-3 py-2">
                                    <input
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:border-neutral-600 dark:bg-neutral-800"
                                        :checked="availableCustomerSelectedIds.includes(Number(customer.id))"
                                        @change="toggleAvailableCustomerSelection(customer.id)"
                                    >
                                </td>
                                <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ customerDisplayName(customer) }}</td>
                                <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ customer.email || '-' }}</td>
                                <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ customer.phone || '-' }}</td>
                                <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">
                                    <span v-if="customer.is_vip" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-200">
                                        {{ customer.vip_tier_code || t('marketing.mailing_list_manager.vip') }}
                                    </span>
                                    <span v-else>-</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                    <div>{{ t('marketing.mailing_list_manager.available_customers_count', { count: availableCustomerMeta?.total || 0 }) }}</div>
                    <div class="flex items-center gap-2">
                        <SecondaryButton type="button" :disabled="!canAvailableCustomerPrev" @click="goToAvailableCustomerPage(availableCustomerPage - 1)">
                            {{ t('marketing.common.previous') }}
                        </SecondaryButton>
                        <span>{{ t('marketing.common.page_of', { page: availableCustomerPage, total: availableCustomerMeta?.last_page || availableCustomerPage }) }}</span>
                        <SecondaryButton type="button" :disabled="!canAvailableCustomerNext" @click="goToAvailableCustomerPage(availableCustomerPage + 1)">
                            {{ t('marketing.common.next') }}
                        </SecondaryButton>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-stone-200 pt-3 dark:border-neutral-700">
                    <div class="text-xs text-stone-600 dark:text-neutral-300">
                        {{ t('marketing.mailing_list_manager.selected_count', { count: selectedAvailableCustomerCount }) }}
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <SecondaryButton
                            type="button"
                            :disabled="availableCustomerRows.length === 0"
                            @click="toggleAllVisibleAvailableCustomers"
                        >
                            {{ allVisibleAvailableCustomersSelected ? t('marketing.mailing_list_manager.unselect_page') : t('marketing.mailing_list_manager.select_page') }}
                        </SecondaryButton>
                        <SecondaryButton type="button" :disabled="busy" @click="closeCustomerPicker">
                            {{ t('marketing.common.cancel') }}
                        </SecondaryButton>
                        <PrimaryButton
                            type="button"
                            :disabled="busy || isLoadingAvailableCustomers || selectedAvailableCustomerCount === 0"
                            @click="importSelectedCustomers"
                        >
                            {{ t('marketing.mailing_list_manager.import_selected') }}
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </Modal>
    </div>
</template>
