<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

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
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to load mailing lists.';
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
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to load mailing list.';
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
            info.value = 'Mailing list updated.';
        } else {
            await axios.post(route('marketing.mailing-lists.store'), payload);
            info.value = 'Mailing list created.';
        }

        const selectedId = selectedList.value?.id || null;
        resetForm();
        await load();
        if (selectedId) {
            await selectList(selectedId);
        }
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to save mailing list.';
    } finally {
        busy.value = false;
    }
};

const remove = async (list) => {
    if (!confirm(`Delete mailing list "${list.name}"?`)) {
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
        info.value = 'Mailing list deleted.';
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to delete mailing list.';
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
        info.value = `Import completed. Added ${added}, total ${total}.`;
        importForm.value.paste = '';
        await load();
        await selectList(listId, { page: customerPage.value });
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to import contacts.';
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
        info.value = 'Customer removed from mailing list.';
        await load();
        await selectList(listId, { page: customerPage.value });
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to remove customer.';
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

onBeforeUnmount(() => {
    if (customerFilterTimeout) {
        clearTimeout(customerFilterTimeout);
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
                <span>Mailing Lists</span>
            </h3>
            <SecondaryButton :disabled="busy || isLoadingLists || isLoadingCustomers" @click="load">
                Reload
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
                    <FloatingInput v-model="form.name" label="List name" />
                    <FloatingInput v-model="form.description" label="Description" />
                    <FloatingInput v-model="form.tags" label="Tags" />
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <PrimaryButton type="button" :disabled="busy" @click="save">
                        {{ editingId ? 'Update list' : 'Create list' }}
                    </PrimaryButton>
                    <SecondaryButton type="button" :disabled="busy" @click="resetForm">
                        Reset
                    </SecondaryButton>
                </div>
            </div>

            <div class="space-y-3 rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    <FloatingInput v-model="listSearch" label="Search list" />
                    <FloatingSelect
                        v-model="listPerPage"
                        label="Rows / page"
                        :options="perPageOptions.map((value) => ({ value, label: String(value) }))"
                        option-value="value"
                        option-label="label"
                    />
                </div>
                <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                    <thead>
                        <tr class="text-left text-xs uppercase text-stone-500 dark:text-neutral-400">
                            <th class="px-3 py-2 font-medium">Name</th>
                            <th class="px-3 py-2 font-medium">Customers</th>
                            <th class="px-3 py-2 font-medium text-right">Actions</th>
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
                                No mailing list found.
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
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-sm border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700 hover:bg-rose-100 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20"
                                        :disabled="busy"
                                        @click.stop="remove(list)"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                    <div>{{ filteredRows.length }} result(s)</div>
                    <div class="flex items-center gap-2">
                        <SecondaryButton type="button" :disabled="!canGoPrevious" @click="listPage -= 1">
                            Previous
                        </SecondaryButton>
                        <span>Page {{ listPage }} / {{ totalPages }}</span>
                        <SecondaryButton type="button" :disabled="!canGoNext" @click="listPage += 1">
                            Next
                        </SecondaryButton>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="selectedList" class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h4 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ selectedList.name }} - {{ selectedList.customers?.total || 0 }} customers
                </h4>
                <button
                    type="button"
                    class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    :disabled="busy || isLoadingCustomers"
                    @click="refreshCustomers"
                >
                    Refresh list
                </button>
            </div>

            <div class="mt-2 rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">Bulk import (paste IDs, emails or phones)</div>
                <FloatingTextarea
                    v-model="importForm.paste"
                    class="mt-2"
                    label="Paste IDs, emails or phones"
                />
                <div class="mt-2">
                    <PrimaryButton
                        type="button"
                        :disabled="busy || !importForm.paste"
                        @click="importPaste"
                    >
                        Import paste
                    </PrimaryButton>
                </div>
            </div>

            <div class="mt-3 space-y-2">
                <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                    <FloatingInput v-model="customerSearch" label="Search customer" />
                    <FloatingSelect
                        v-model="customerPerPage"
                        label="Rows / page"
                        :options="perPageOptions.map((value) => ({ value, label: String(value) }))"
                        option-value="value"
                        option-label="label"
                    />
                </div>
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                    <thead>
                        <tr class="text-left text-xs uppercase text-stone-500 dark:text-neutral-400">
                            <th class="px-3 py-2 font-medium">Customer</th>
                            <th class="px-3 py-2 font-medium">Email</th>
                            <th class="px-3 py-2 font-medium">Phone</th>
                            <th class="px-3 py-2 font-medium">VIP</th>
                            <th class="px-3 py-2 font-medium text-right">Actions</th>
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
                                No customer in this list.
                            </td>
                        </tr>
                        <tr v-for="customer in listCustomers" :key="`mailing-customer-${customer.id}`">
                            <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">
                                {{ customer.company_name || `${customer.first_name || ''} ${customer.last_name || ''}`.trim() || '-' }}
                            </td>
                            <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ customer.email || '-' }}</td>
                            <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ customer.phone || '-' }}</td>
                            <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">
                                <span v-if="customer.is_vip" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-200">
                                    {{ customer.vip_tier_code || 'VIP' }}
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
                                        Remove
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                    <div>{{ customerMeta?.total || 0 }} customer(s)</div>
                    <div class="flex items-center gap-2">
                        <SecondaryButton type="button" :disabled="!canCustomerPrev" @click="goToCustomerPage(customerPage - 1)">
                            Previous
                        </SecondaryButton>
                        <span>Page {{ customerPage }}</span>
                        <SecondaryButton type="button" :disabled="!canCustomerNext" @click="goToCustomerPage(customerPage + 1)">
                            Next
                        </SecondaryButton>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
