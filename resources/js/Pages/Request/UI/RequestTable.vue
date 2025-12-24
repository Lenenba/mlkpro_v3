<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import Modal from '@/Components/UI/Modal.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    requests: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    customers: {
        type: Array,
        default: () => [],
    },
    statuses: {
        type: Array,
        default: () => [],
    },
});

const formatDate = (value) => humanizeDate(value);

const displayCustomer = (customer) =>
    customer?.company_name ||
    `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim() ||
    'Unknown';

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
    customer_id: props.filters?.customer_id ?? '',
});

const filterPayload = () => {
    const payload = {
        search: filterForm.search,
        status: filterForm.status,
        customer_id: filterForm.customer_id,
    };

    Object.keys(payload).forEach((key) => {
        const value = payload[key];
        if (value === '' || value === null || value === undefined) {
            delete payload[key];
        }
    });

    return payload;
};

let filterTimeout;
const autoFilter = () => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }
    filterTimeout = setTimeout(() => {
        router.get(route('request.index'), filterPayload(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
};

watch(() => filterForm.search, () => {
    autoFilter();
});

watch(() => [filterForm.status, filterForm.customer_id], () => {
    autoFilter();
});

const clearFilters = () => {
    filterForm.search = '';
    filterForm.status = '';
    filterForm.customer_id = '';
    autoFilter();
};

const convertModalId = 'hs-request-convert';
const selectedLead = ref(null);
const processingId = ref(null);

const convertForm = useForm({
    customer_id: '',
    property_id: '',
    job_title: '',
    description: '',
});

const selectedCustomer = computed(() => {
    if (!convertForm.customer_id) {
        return null;
    }

    return props.customers.find((customer) => customer.id === Number(convertForm.customer_id)) || null;
});

const propertyOptions = computed(() => selectedCustomer.value?.properties || []);

watch(selectedCustomer, (customer) => {
    const nextProperty =
        customer?.properties?.find((property) => property.is_default)?.id ||
        customer?.properties?.[0]?.id ||
        '';
    convertForm.property_id = nextProperty ? String(nextProperty) : '';
});

const openConvert = (lead) => {
    selectedLead.value = lead;
    convertForm.reset();
    convertForm.clearErrors();

    convertForm.customer_id = lead?.customer_id ? String(lead.customer_id) : '';
    convertForm.job_title = lead?.title || lead?.service_type || 'New Quote';
    convertForm.description = lead?.description || '';

    if (window.HSOverlay) {
        window.HSOverlay.open(`#${convertModalId}`);
    }
};

const closeConvert = () => {
    selectedLead.value = null;
    convertForm.reset();
    convertForm.clearErrors();
};

const submitConvert = () => {
    const leadId = selectedLead.value?.id;
    if (!leadId || convertForm.processing) {
        return;
    }

    convertForm.post(route('request.convert', leadId), {
        preserveScroll: true,
        onSuccess: () => {
            if (window.HSOverlay) {
                window.HSOverlay.close(`#${convertModalId}`);
            }
            closeConvert();
        },
    });
};

const deleteLead = (lead) => {
    if (!lead?.id) {
        return;
    }

    if (!confirm('Delete this request?')) {
        return;
    }

    if (processingId.value) {
        return;
    }

    processingId.value = lead.id;
    router.delete(route('request.destroy', lead.id), {
        preserveScroll: true,
        onFinish: () => {
            processingId.value = null;
        },
    });
};

const statusLabel = (status) => {
    if (status === 'REQ_NEW') {
        return 'New';
    }
    if (status === 'REQ_CONVERTED') {
        return 'Converted';
    }
    return status || 'Unknown';
};

const statusClass = (status) => {
    switch (status) {
        case 'REQ_NEW':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'REQ_CONVERTED':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        default:
            return 'bg-stone-100 text-stone-800 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const openQuickCreate = () => {
    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-quick-create-request');
    }
};
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700"
    >
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            <div class="flex flex-col sm:flex-row sm:items-center gap-2 flex-1">
                <div class="relative flex-1">
                    <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                        <svg
                            class="shrink-0 size-4 text-stone-500 dark:text-neutral-400"
                            xmlns="http://www.w3.org/2000/svg"
                            width="24"
                            height="24"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.3-4.3" />
                        </svg>
                    </div>
                    <input
                        v-model="filterForm.search"
                        type="text"
                        class="py-2 ps-10 pe-3 block w-full border-transparent rounded-sm bg-stone-100 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200"
                        placeholder="Search requests..."
                    />
                </div>

                <select
                    v-model="filterForm.status"
                    class="py-2 px-3 border-transparent rounded-sm bg-stone-100 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200"
                >
                    <option value="">All statuses</option>
                    <option v-for="status in statuses" :key="status.id" :value="status.id">
                        {{ status.name }}
                    </option>
                </select>

                <select
                    v-model="filterForm.customer_id"
                    class="py-2 px-3 border-transparent rounded-sm bg-stone-100 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200"
                >
                    <option value="">All customers</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                        {{ displayCustomer(customer) }}
                    </option>
                </select>

                <button
                    type="button"
                    class="py-2 px-3 rounded-sm border border-stone-200 bg-white text-sm text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                    @click="clearFilters"
                >
                    Clear
                </button>
            </div>

            <button
                type="button"
                class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700"
                @click="openQuickCreate"
            >
                New request
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                <thead>
                    <tr>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            Request
                        </th>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            Customer
                        </th>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            Status
                        </th>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            Created
                        </th>
                        <th class="px-5 py-2.5 text-end text-sm font-normal text-stone-500 dark:text-neutral-500">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                    <tr v-for="lead in requests.data" :key="lead.id">
                        <td class="px-5 py-3">
                            <div class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                                {{ lead.title || lead.service_type || `Request #${lead.id}` }}
                            </div>
                            <div v-if="lead.description" class="mt-1 text-xs text-stone-500 dark:text-neutral-400 line-clamp-2">
                                {{ lead.description }}
                            </div>
                        </td>
                        <td class="px-5 py-3 text-sm text-stone-700 dark:text-neutral-300">
                            <div v-if="lead.customer">
                                {{ displayCustomer(lead.customer) }}
                            </div>
                            <div v-else class="text-xs text-stone-500 dark:text-neutral-400">
                                Unassigned
                            </div>
                        </td>
                        <td class="px-5 py-3">
                            <span class="inline-flex items-center rounded-sm px-2 py-0.5 text-xs font-medium" :class="statusClass(lead.status)">
                                {{ statusLabel(lead.status) }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-sm text-stone-700 dark:text-neutral-300">
                            {{ formatDate(lead.created_at) }}
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end">
                                <div class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                                    <button type="button"
                                        class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                        aria-haspopup="menu" aria-expanded="false" aria-label="Actions">
                                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                            height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="1" />
                                            <circle cx="12" cy="5" r="1" />
                                            <circle cx="12" cy="19" r="1" />
                                        </svg>
                                    </button>

                                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                        role="menu" aria-orientation="vertical">
                                        <div class="p-1">
                                            <Link v-if="lead.quote"
                                                :href="route('customer.quote.show', lead.quote.id)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                View quote
                                            </Link>
                                            <button v-else-if="lead.status === 'REQ_NEW'" type="button" @click="openConvert(lead)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800">
                                                Convert
                                            </button>
                                            <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                            <button type="button" @click="deleteLead(lead)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800"
                                                :disabled="processingId === lead.id">
                                                Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>

                    <tr v-if="!requests.data.length">
                        <td colspan="5" class="px-5 py-6 text-center text-sm text-stone-500 dark:text-neutral-400">
                            No requests found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="requests.next_page_url || requests.prev_page_url" class="flex items-center justify-between gap-3">
            <Link
                v-if="requests.prev_page_url"
                :href="requests.prev_page_url"
                class="py-2 px-3 rounded-sm border border-stone-200 bg-white text-sm text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
            >
                Previous
            </Link>
            <span class="text-xs text-stone-500 dark:text-neutral-400">
                Showing {{ requests.from || 0 }}-{{ requests.to || 0 }}
            </span>
            <Link
                v-if="requests.next_page_url"
                :href="requests.next_page_url"
                class="py-2 px-3 rounded-sm border border-stone-200 bg-white text-sm text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
            >
                Next
            </Link>
        </div>
    </div>

    <Modal :title="'Convert request to quote'" :id="convertModalId">
        <div class="space-y-4">
            <div v-if="selectedLead" class="rounded-sm border border-stone-200 p-3 text-sm text-stone-600 dark:border-neutral-700 dark:text-neutral-400">
                <div class="font-medium text-stone-800 dark:text-neutral-200">
                    {{ selectedLead.title || selectedLead.service_type || `Request #${selectedLead.id}` }}
                </div>
                <div v-if="selectedLead.contact_email">{{ selectedLead.contact_email }}</div>
                <div v-if="selectedLead.contact_phone">{{ selectedLead.contact_phone }}</div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="text-sm text-stone-600 dark:text-neutral-400">Customer</label>
                    <select
                        v-model="convertForm.customer_id"
                        class="mt-1 w-full rounded-sm border border-stone-200 bg-stone-100 py-2 px-3 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                    >
                        <option value="">Select customer</option>
                        <option v-for="customer in customers" :key="customer.id" :value="String(customer.id)">
                            {{ displayCustomer(customer) }}
                        </option>
                    </select>
                    <InputError class="mt-1" :message="convertForm.errors.customer_id" />
                </div>
                <div>
                    <label class="text-sm text-stone-600 dark:text-neutral-400">Location</label>
                    <select
                        v-model="convertForm.property_id"
                        :disabled="!propertyOptions.length"
                        class="mt-1 w-full rounded-sm border border-stone-200 bg-stone-100 py-2 px-3 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 disabled:opacity-60 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                    >
                        <option value="">No location</option>
                        <option v-for="property in propertyOptions" :key="property.id" :value="String(property.id)">
                            {{ property.street1 || 'Location' }}{{ property.city ? ', ' + property.city : '' }}
                        </option>
                    </select>
                    <InputError class="mt-1" :message="convertForm.errors.property_id" />
                </div>
            </div>

            <div>
                <FloatingInput v-model="convertForm.job_title" label="Job title" />
                <InputError class="mt-1" :message="convertForm.errors.job_title" />
            </div>

            <div>
                <FloatingTextarea v-model="convertForm.description" label="Notes (optional)" />
            </div>

            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    :data-hs-overlay="`#${convertModalId}`"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                    @click="closeConvert"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    :disabled="convertForm.processing || !convertForm.customer_id"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50"
                    @click="submitConvert"
                >
                    Convert
                </button>
            </div>
        </div>
    </Modal>
</template>
