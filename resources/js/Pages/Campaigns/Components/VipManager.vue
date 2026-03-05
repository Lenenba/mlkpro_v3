<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const rows = ref([]);
const busy = ref(false);
const isLoadingList = ref(false);
const error = ref('');
const info = ref('');
const editingId = ref(null);
const vipCustomersCount = ref(0);
const listSearch = ref('');
const listPage = ref(1);
const listPerPage = ref(10);
const perPageOptions = [10, 25, 50];

const form = ref({
    code: '',
    name: '',
    perks: '',
    is_active: true,
});

const resetForm = () => {
    editingId.value = null;
    form.value = {
        code: '',
        name: '',
        perks: '',
        is_active: true,
    };
};

const parsePerks = (value) =>
    String(value || '')
        .split(/[,\n;]+/)
        .map((item) => item.trim())
        .filter((item) => item !== '');

const filteredRows = computed(() => {
    const query = String(listSearch.value || '').trim().toLowerCase();
    if (!query) {
        return rows.value;
    }

    return rows.value.filter((tier) => {
        const haystack = [
            tier?.code,
            tier?.name,
            Array.isArray(tier?.perks) ? tier.perks.join(' ') : '',
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

const load = async () => {
    isLoadingList.value = true;
    error.value = '';

    try {
        const response = await axios.get(route('marketing.vip.index'));
        rows.value = Array.isArray(response.data?.vip_tiers) ? response.data.vip_tiers : [];
        vipCustomersCount.value = Number(response.data?.vip_customers_count || 0);
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to load VIP tiers.';
    } finally {
        isLoadingList.value = false;
    }
};

const edit = (tier) => {
    editingId.value = Number(tier.id);
    form.value = {
        code: tier.code || '',
        name: tier.name || '',
        perks: Array.isArray(tier.perks) ? tier.perks.join(', ') : '',
        is_active: Boolean(tier.is_active ?? true),
    };
};

const save = async () => {
    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const payload = {
            code: String(form.value.code || '').trim().toUpperCase(),
            name: String(form.value.name || '').trim(),
            perks: parsePerks(form.value.perks),
            is_active: Boolean(form.value.is_active),
        };

        if (editingId.value) {
            await axios.put(route('marketing.vip.update', editingId.value), payload);
            info.value = 'VIP tier updated.';
        } else {
            await axios.post(route('marketing.vip.store'), payload);
            info.value = 'VIP tier created.';
        }

        resetForm();
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to save VIP tier.';
    } finally {
        busy.value = false;
    }
};

const remove = async (tier) => {
    if (!confirm(`Delete VIP tier "${tier.name}"?`)) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';
    try {
        await axios.delete(route('marketing.vip.destroy', tier.id));
        info.value = 'VIP tier deleted.';
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to delete VIP tier.';
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
                    <svg class="size-4 text-amber-500 dark:text-amber-300" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="m12 3 2.8 5.7L21 9.6l-4.5 4.4L17.5 21 12 18l-5.5 3 1-7-4.5-4.4 6.2-.9z" />
                    </svg>
                    <span>VIP Tiers</span>
                </h3>
                <p class="text-xs text-stone-500 dark:text-neutral-400">
                    VIP customers: {{ vipCustomersCount }}
                </p>
            </div>
            <SecondaryButton :disabled="busy || isLoadingList" @click="load">
                Reload
            </SecondaryButton>
        </div>

        <div v-if="error" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
            {{ error }}
        </div>
        <div v-if="info" class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ info }}
        </div>

        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
            <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                <FloatingInput
                    v-model="form.code"
                    label="Tier code (GOLD)"
                />
                <FloatingInput
                    v-model="form.name"
                    label="Tier name"
                />
                <FloatingTextarea
                    v-model="form.perks"
                    label="Perks"
                    class="md:col-span-2"
                />
                <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                    <input
                        v-model="form.is_active"
                        type="checkbox"
                        class="rounded border-stone-300 text-green-600 focus:ring-green-600"
                    >
                    <span>Tier active</span>
                </label>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <PrimaryButton type="button" :disabled="busy" @click="save">
                    {{ editingId ? 'Update tier' : 'Create tier' }}
                </PrimaryButton>
                <SecondaryButton type="button" :disabled="busy" @click="resetForm">
                    Reset
                </SecondaryButton>
            </div>
        </div>

        <div class="space-y-3 rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                <FloatingInput v-model="listSearch" label="Search tier" />
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
                        <th class="px-3 py-2 font-medium">Code</th>
                        <th class="px-3 py-2 font-medium">Name</th>
                        <th class="px-3 py-2 font-medium">Perks</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                    <template v-if="isLoadingList">
                        <tr v-for="row in 6" :key="`vip-skeleton-${row}`">
                            <td v-for="col in 5" :key="`vip-skeleton-${row}-${col}`" class="px-3 py-2">
                                <div class="h-3 w-full animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            </td>
                        </tr>
                    </template>
                    <tr v-else-if="pagedRows.length === 0">
                        <td colspan="5" class="px-3 py-6 text-center text-xs text-stone-500 dark:text-neutral-400">
                            No VIP tier found.
                        </td>
                    </tr>
                    <tr v-for="tier in pagedRows" :key="`vip-tier-${tier.id}`">
                        <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ tier.code }}</td>
                        <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ tier.name }}</td>
                        <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">
                            {{ Array.isArray(tier.perks) ? tier.perks.join(', ') : '-' }}
                        </td>
                        <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="tier.is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200' : 'bg-stone-100 text-stone-700 dark:bg-neutral-800 dark:text-neutral-300'"
                            >
                                {{ tier.is_active ? 'active' : 'inactive' }}
                            </span>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    :disabled="busy"
                                    @click="edit(tier)"
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    class="rounded-sm border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700 hover:bg-rose-100 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20"
                                    :disabled="busy"
                                    @click="remove(tier)"
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
</template>
