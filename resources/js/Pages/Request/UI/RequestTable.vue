<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import Modal from '@/Components/UI/Modal.vue';
import Checkbox from '@/Components/Checkbox.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import { humanizeDate } from '@/utils/date';
import { isFeatureEnabled } from '@/utils/features';
import { useI18n } from 'vue-i18n';
import RequestBoard from '@/Pages/Request/UI/RequestBoard.vue';

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
    assignees: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();

const allowedViews = ['table', 'board'];
const resolveView = (value) => (allowedViews.includes(value) ? value : 'table');
const viewMode = ref(resolveView(props.filters?.view));

const formatDate = (value) => humanizeDate(value);
const formatAbsoluteDate = (value) => {
    if (!value) {
        return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    return date.toLocaleString();
};

const displayCustomer = (customer) =>
    customer?.company_name ||
    `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim() ||
    t('requests.labels.unknown_customer');

const statusLabel = (status) => {
    switch (status) {
        case 'REQ_NEW':
            return t('requests.status.new');
        case 'REQ_CONTACTED':
            return t('requests.status.contacted');
        case 'REQ_QUALIFIED':
            return t('requests.status.qualified');
        case 'REQ_QUOTE_SENT':
            return t('requests.status.quote_sent');
        case 'REQ_WON':
            return t('requests.status.won');
        case 'REQ_LOST':
            return t('requests.status.lost');
        case 'REQ_CONVERTED':
            return t('requests.status.converted');
        default:
            return status || t('requests.labels.unknown_status');
    }
};

const statusClass = (status) => {
    switch (status) {
        case 'REQ_NEW':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'REQ_CONTACTED':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300';
        case 'REQ_QUALIFIED':
            return 'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/10 dark:text-indigo-300';
        case 'REQ_QUOTE_SENT':
        case 'REQ_CONVERTED':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-300';
        case 'REQ_WON':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'REQ_LOST':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300';
        default:
            return 'bg-stone-100 text-stone-800 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
    customer_id: props.filters?.customer_id ?? '',
});
const isLoading = ref(false);
const statusSelectOptions = computed(() => {
    const options = (props.statuses || []).map((status) => ({
        id: String(status.id),
        name: statusLabel(String(status.id)),
    }));

    if (!options.find((option) => option.id === 'REQ_CONVERTED')) {
        options.push({ id: 'REQ_CONVERTED', name: statusLabel('REQ_CONVERTED') });
    }

    return options;
});

const statusActionOptions = computed(() =>
    (props.statuses || []).map((status) => ({
        id: String(status.id),
        name: statusLabel(String(status.id)),
    }))
);

const assigneeSelectOptions = computed(() => [
    { id: '', name: t('requests.labels.unassigned') },
    ...(props.assignees || []).map((assignee) => ({
        id: String(assignee.id),
        name: assignee.name || t('requests.labels.unassigned'),
    })),
]);

const bulkAssigneeOptions = computed(() =>
    (props.assignees || []).map((assignee) => ({
        id: String(assignee.id),
        name: assignee.name || t('requests.labels.unassigned'),
    }))
);

const filterPayload = () => {
    const payload = {
        search: filterForm.search,
        status: filterForm.status,
        customer_id: filterForm.customer_id,
        view: viewMode.value,
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
        isLoading.value = true;
        router.get(route('request.index'), filterPayload(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                isLoading.value = false;
            },
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

const setViewMode = (mode) => {
    if (!allowedViews.includes(mode) || viewMode.value === mode) {
        return;
    }
    viewMode.value = mode;
    if (typeof window !== 'undefined') {
        window.localStorage.setItem('request_view_mode', mode);
    }
    isLoading.value = true;
    autoFilter();
};

if (typeof window !== 'undefined') {
    const storedView = window.localStorage.getItem('request_view_mode');
    if (allowedViews.includes(storedView) && storedView !== viewMode.value) {
        setViewMode(storedView);
    }
}

const tableRows = computed(() => (Array.isArray(props.requests?.data) ? props.requests.data : []));
const selected = ref([]);
const selectAllRef = ref(null);
const allSelected = computed(() =>
    tableRows.value.length > 0 && selected.value.length === tableRows.value.length
);
const someSelected = computed(() =>
    selected.value.length > 0 && !allSelected.value
);

watch(tableRows, () => {
    selected.value = [];
}, { deep: true });

watch([allSelected, someSelected], () => {
    if (selectAllRef.value) {
        selectAllRef.value.indeterminate = someSelected.value;
    }
});

const toggleAll = (event) => {
    selected.value = event.target.checked
        ? tableRows.value.map((lead) => lead.id)
        : [];
};

const bulkStatus = ref('');
const bulkAssignee = ref('');
const bulkLostReason = ref('');
const bulkErrors = ref({});
const bulkProcessing = ref(false);

watch(() => bulkStatus.value, (value) => {
    if (value !== 'REQ_LOST') {
        bulkLostReason.value = '';
        bulkErrors.value = {};
    }
});

const submitBulkStatus = () => {
    if (!selected.value.length || !bulkStatus.value || bulkProcessing.value) {
        return;
    }

    let lostReason = bulkLostReason.value;
    if (bulkStatus.value === 'REQ_LOST' && !lostReason) {
        lostReason = window.prompt(t('requests.bulk.lost_reason_prompt'));
        if (!lostReason) {
            return;
        }
        bulkLostReason.value = lostReason;
    }

    bulkErrors.value = {};
    bulkProcessing.value = true;
    router.patch(route('request.bulk'), {
        ids: selected.value,
        status: bulkStatus.value,
        lost_reason: bulkStatus.value === 'REQ_LOST' ? lostReason : null,
    }, {
        preserveScroll: true,
        onError: (errors) => {
            bulkErrors.value = errors || {};
        },
        onFinish: () => {
            bulkProcessing.value = false;
        },
        onSuccess: () => {
            selected.value = [];
            bulkStatus.value = '';
            bulkLostReason.value = '';
        },
    });
};

const submitBulkAssign = () => {
    if (!selected.value.length || bulkProcessing.value || !bulkAssignee.value) {
        return;
    }

    bulkErrors.value = {};
    bulkProcessing.value = true;
    router.patch(route('request.bulk'), {
        ids: selected.value,
        assigned_team_member_id: Number(bulkAssignee.value),
    }, {
        preserveScroll: true,
        onError: (errors) => {
            bulkErrors.value = errors || {};
        },
        onFinish: () => {
            bulkProcessing.value = false;
        },
        onSuccess: () => {
            selected.value = [];
            bulkAssignee.value = '';
        },
    });
};

const convertModalId = 'hs-request-convert';
const updateModalId = 'hs-request-update';
const selectedLead = ref(null);
const processingId = ref(null);

const convertForm = useForm({
    customer_id: '',
    property_id: '',
    job_title: '',
    description: '',
    create_customer: false,
    customer_name: '',
    contact_name: '',
    contact_email: '',
    contact_phone: '',
});

const canSubmitConvert = computed(() => {
    if (convertForm.processing) {
        return false;
    }
    if (convertForm.create_customer) {
        return true;
    }
    return Boolean(convertForm.customer_id);
});

const selectedCustomer = computed(() => {
    if (convertForm.create_customer) {
        return null;
    }
    if (!convertForm.customer_id) {
        return null;
    }

    return props.customers.find((customer) => customer.id === Number(convertForm.customer_id)) || null;
});

const propertyOptions = computed(() => selectedCustomer.value?.properties || []);
const customerSelectOptions = computed(() =>
    (props.customers || []).map((customer) => ({
        id: String(customer.id),
        name: displayCustomer(customer),
    }))
);
const propertySelectOptions = computed(() =>
    propertyOptions.value.map((property) => ({
        id: String(property.id),
        name: `${property.street1 || t('requests.convert.location_fallback')}${property.city ? `, ${property.city}` : ''}`,
    }))
);

watch(selectedCustomer, (customer) => {
    const nextProperty =
        customer?.properties?.find((property) => property.is_default)?.id ||
        customer?.properties?.[0]?.id ||
        '';
    convertForm.property_id = nextProperty ? String(nextProperty) : '';
});

watch(
    () => convertForm.create_customer,
    (isCreating) => {
        if (isCreating) {
            convertForm.customer_id = '';
            convertForm.property_id = '';
        }
    }
);

const openConvert = (lead) => {
    selectedLead.value = lead;
    convertForm.reset();
    convertForm.clearErrors();

    convertForm.customer_id = lead?.customer_id ? String(lead.customer_id) : '';
    convertForm.job_title = lead?.title || lead?.service_type || t('requests.convert.default_job_title');
    convertForm.description = lead?.description || '';
    convertForm.create_customer = !lead?.customer_id && !props.customers.length;
    convertForm.customer_name = lead?.customer?.company_name || lead?.contact_name || '';
    convertForm.contact_name = lead?.contact_name || '';
    convertForm.contact_email = lead?.contact_email || '';
    convertForm.contact_phone = lead?.contact_phone || '';

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

const updateForm = useForm({
    status: '',
    assigned_team_member_id: '',
    next_follow_up_at: '',
    lost_reason: '',
});

const showLostReason = computed(() => updateForm.status === 'REQ_LOST');

const formatDateTimeLocal = (value) => {
    if (!value) {
        return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    const pad = (value) => String(value).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

const openUpdate = (lead) => {
    selectedLead.value = lead;
    updateForm.reset();
    updateForm.clearErrors();

    updateForm.status = lead?.status || '';
    updateForm.assigned_team_member_id = lead?.assigned_team_member_id ? String(lead.assigned_team_member_id) : '';
    updateForm.next_follow_up_at = formatDateTimeLocal(lead?.next_follow_up_at);
    updateForm.lost_reason = lead?.lost_reason || '';

    if (window.HSOverlay) {
        window.HSOverlay.open(`#${updateModalId}`);
    }
};

const closeUpdate = () => {
    selectedLead.value = null;
    updateForm.reset();
    updateForm.clearErrors();
};

const submitUpdate = () => {
    const leadId = selectedLead.value?.id;
    if (!leadId || updateForm.processing) {
        return;
    }

    updateForm.put(route('request.update', leadId), {
        preserveScroll: true,
        onSuccess: () => {
            if (window.HSOverlay) {
                window.HSOverlay.close(`#${updateModalId}`);
            }
            closeUpdate();
        },
    });
};

const deleteLead = (lead) => {
    if (!lead?.id) {
        return;
    }

    if (!confirm(t('requests.actions.delete_confirm'))) {
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

const isClosedStatus = (status) => ['REQ_WON', 'REQ_LOST'].includes(status);

const isOverdue = (lead) => {
    if (!lead?.next_follow_up_at || isClosedStatus(lead?.status)) {
        return false;
    }
    const dueDate = new Date(lead.next_follow_up_at);
    if (Number.isNaN(dueDate.getTime())) {
        return false;
    }
    return dueDate.getTime() < Date.now();
};

const canConvertLead = (lead) => {
    if (!lead) {
        return false;
    }
    if (lead.quote) {
        return false;
    }
    return !isClosedStatus(lead.status);
};

const setLeadStatus = (lead, status) => {
    if (!lead || lead.status === status) {
        return;
    }
    let payload = { status };
    if (status === 'REQ_LOST') {
        const reason = lead?.lost_reason || window.prompt(t('requests.bulk.lost_reason_prompt'));
        if (!reason) {
            return;
        }
        payload = { status, lost_reason: reason };
    }
    router.put(route('request.update', lead.id), payload, {
        preserveScroll: true,
        only: ['requests', 'stats', 'flash'],
    });
};

const page = usePage();
const featureFlags = computed(() => page.props.auth?.account?.features || {});
const canUseRequests = computed(() => isFeatureEnabled(featureFlags.value, 'requests'));
const canUseQuotes = computed(() => isFeatureEnabled(featureFlags.value, 'quotes'));

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
                        :placeholder="$t('requests.filters.search_placeholder')"
                    />
                </div>

                <FloatingSelect
                    v-model="filterForm.status"
                    :label="$t('requests.table.status')"
                    :options="statusSelectOptions"
                    :placeholder="$t('requests.filters.all_statuses')"
                    dense
                    class="min-w-[150px]"
                />

                <FloatingSelect
                    v-model="filterForm.customer_id"
                    :label="$t('requests.table.customer')"
                    :options="customerSelectOptions"
                    :placeholder="$t('requests.filters.all_customers')"
                    dense
                    class="min-w-[170px]"
                />

                <button
                    type="button"
                    class="py-2 px-3 rounded-sm border border-stone-200 bg-white text-sm text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                    @click="clearFilters"
                >
                    {{ $t('requests.actions.clear') }}
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-2 justify-end">
                <div class="inline-flex items-center rounded-sm border border-stone-200 bg-white p-0.5 text-xs font-semibold text-stone-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                    <button
                        type="button"
                        @click="setViewMode('table')"
                        class="inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5"
                        :class="viewMode === 'table'
                            ? 'bg-green-600 text-white shadow-sm dark:bg-white dark:text-stone-900'
                            : 'text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100'"
                    >
                        <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 6h18" />
                            <path d="M3 12h18" />
                            <path d="M3 18h18" />
                        </svg>
                        {{ $t('requests.view.table') }}
                    </button>
                    <button
                        type="button"
                        @click="setViewMode('board')"
                        class="inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5"
                        :class="viewMode === 'board'
                            ? 'bg-green-600 text-white shadow-sm dark:bg-white dark:text-stone-900'
                            : 'text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100'"
                    >
                        <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="7" height="7" rx="1" />
                            <rect x="14" y="3" width="7" height="7" rx="1" />
                            <rect x="14" y="14" width="7" height="7" rx="1" />
                            <rect x="3" y="14" width="7" height="7" rx="1" />
                        </svg>
                        {{ $t('requests.view.board') }}
                    </button>
                </div>

                <button
                    v-if="canUseRequests"
                    type="button"
                    class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700"
                    @click="openQuickCreate"
                >
                    {{ $t('requests.actions.new_request') }}
                </button>
            </div>
        </div>

        <div v-if="viewMode === 'table' && selected.length" class="flex flex-wrap items-center gap-2">
            <span class="text-xs text-stone-500 dark:text-neutral-400">
                {{ $t('requests.bulk.selected', { count: selected.length }) }}
            </span>
            <div class="flex flex-wrap items-end gap-2">
                <FloatingSelect
                    v-model="bulkStatus"
                    :label="$t('requests.bulk.status_label')"
                    :options="statusActionOptions"
                    :placeholder="$t('requests.bulk.status_placeholder')"
                    dense
                    class="min-w-[170px]"
                />
                <input
                    v-if="bulkStatus === 'REQ_LOST'"
                    v-model="bulkLostReason"
                    type="text"
                    class="py-2 px-3 rounded-sm border border-stone-200 bg-white text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('requests.bulk.lost_reason')"
                />
                <button
                    type="button"
                    class="py-2 px-3 rounded-sm border border-transparent bg-emerald-600 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
                    :disabled="bulkProcessing || !bulkStatus"
                    @click="submitBulkStatus"
                >
                    {{ $t('requests.bulk.apply_status') }}
                </button>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <FloatingSelect
                    v-model="bulkAssignee"
                    :label="$t('requests.bulk.assign_label')"
                    :options="bulkAssigneeOptions"
                    :placeholder="$t('requests.bulk.assign_placeholder')"
                    dense
                    class="min-w-[180px]"
                />
                <button
                    type="button"
                    class="py-2 px-3 rounded-sm border border-stone-200 bg-white text-sm font-medium text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                    :disabled="bulkProcessing || !bulkAssignee"
                    @click="submitBulkAssign"
                >
                    {{ $t('requests.bulk.apply_assign') }}
                </button>
            </div>
            <InputError class="mt-1 w-full" :message="bulkErrors.lost_reason" />
        </div>

        <div v-if="viewMode === 'board'" class="pt-2">
            <RequestBoard :requests="requests.data" :statuses="statuses" />
        </div>

        <div v-else class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                <thead>
                    <tr>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            <input
                                ref="selectAllRef"
                                type="checkbox"
                                :checked="allSelected"
                                class="rounded-sm border-stone-200 text-green-600 shadow-sm focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-emerald-400 dark:focus:ring-emerald-400"
                                @change="toggleAll"
                            />
                        </th>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('requests.table.request') }}
                        </th>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('requests.table.customer') }}
                        </th>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('requests.table.status') }}
                        </th>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('requests.table.assignee') }}
                        </th>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('requests.table.follow_up') }}
                        </th>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('requests.table.created') }}
                        </th>
                        <th class="px-5 py-2.5 text-end text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('requests.table.actions') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                    <template v-if="isLoading">
                        <tr v-for="row in 6" :key="`skeleton-${row}`">
                            <td colspan="8" class="px-4 py-3">
                                <div class="grid grid-cols-8 gap-4 animate-pulse">
                                    <div class="h-3 w-4 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-32 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-28 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <template v-else>
                    <tr v-for="lead in requests.data" :key="lead.id">
                        <td class="px-5 py-3">
                            <Checkbox v-model:checked="selected" :value="lead.id" />
                        </td>
                        <td class="px-5 py-3">
                            <div class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                                <Link
                                    :href="route('request.show', lead.id)"
                                    class="hover:text-emerald-600"
                                >
                                    {{ lead.title || lead.service_type || $t('requests.labels.request_number', { id: lead.id }) }}
                                </Link>
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
                                {{ $t('requests.labels.unknown_customer') }}
                            </div>
                        </td>
                        <td class="px-5 py-3">
                            <div class="hs-dropdown [--auto-close:inside] [--placement:bottom-left] relative inline-flex">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-sm px-2 py-0.5 text-xs font-medium"
                                    :class="statusClass(lead.status)"
                                >
                                    {{ statusLabel(lead.status) }}
                                    <svg class="size-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                                <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                    role="menu" aria-orientation="vertical">
                                    <div class="p-1">
                                        <button
                                            v-for="option in statusActionOptions"
                                            :key="option.id"
                                            type="button"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                            :class="option.id === lead.status ? 'text-emerald-600 dark:text-emerald-400' : ''"
                                            @click="setLeadStatus(lead, option.id)"
                                        >
                                            {{ option.name }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-3 text-sm text-stone-700 dark:text-neutral-300">
                            <span v-if="lead.assignee?.user?.name || lead.assignee?.name">
                                {{ lead.assignee?.user?.name || lead.assignee?.name }}
                            </span>
                            <span v-else class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('requests.labels.unassigned') }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-sm text-stone-700 dark:text-neutral-300">
                            <span v-if="lead.next_follow_up_at"
                                class="inline-flex items-center gap-2"
                                :class="isOverdue(lead) ? 'text-rose-600 dark:text-rose-400' : 'text-stone-700 dark:text-neutral-200'"
                                :title="formatAbsoluteDate(lead.next_follow_up_at)">
                                {{ formatDate(lead.next_follow_up_at) }}
                            </span>
                            <span v-else class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('requests.labels.no_follow_up') }}
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
                                        aria-haspopup="menu" aria-expanded="false" :aria-label="$t('requests.table.actions')">
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
                                                {{ $t('requests.actions.view_quote') }}
                                            </Link>
                                            <button type="button" @click="openUpdate(lead)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                {{ $t('requests.actions.update') }}
                                            </button>
                                            <button v-if="canUseQuotes && canConvertLead(lead)" type="button" @click="openConvert(lead)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800">
                                                {{ $t('requests.actions.convert') }}
                                            </button>
                                            <Link
                                                :href="route('request.show', lead.id)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                            >
                                                {{ $t('requests.actions.view') }}
                                            </Link>
                                            <Link
                                                :href="route('pipeline.timeline', { entityType: 'request', entityId: lead.id })"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                            >
                                                {{ $t('requests.actions.timeline') }}
                                            </Link>
                                            <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                            <button type="button" @click="deleteLead(lead)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800"
                                                :disabled="processingId === lead.id">
                                                {{ $t('requests.actions.delete') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>

                    <tr v-if="!requests.data.length">
                        <td colspan="8" class="px-5 py-6 text-center text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('requests.empty') }}
                        </td>
                    </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div v-if="viewMode === 'table' && (requests.next_page_url || requests.prev_page_url)" class="flex items-center justify-between gap-3">
            <Link
                v-if="requests.prev_page_url"
                :href="requests.prev_page_url"
                class="py-2 px-3 rounded-sm border border-stone-200 bg-white text-sm text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
            >
                {{ $t('requests.pagination.previous') }}
            </Link>
            <span class="text-xs text-stone-500 dark:text-neutral-400">
                {{ $t('requests.pagination.showing', { from: requests.from || 0, to: requests.to || 0 }) }}
            </span>
            <Link
                v-if="requests.next_page_url"
                :href="requests.next_page_url"
                class="py-2 px-3 rounded-sm border border-stone-200 bg-white text-sm text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
            >
                {{ $t('requests.pagination.next') }}
            </Link>
        </div>
    </div>

    <Modal :title="$t('requests.convert.title')" :id="convertModalId">
        <div class="space-y-4">
            <div v-if="selectedLead" class="rounded-sm border border-stone-200 p-3 text-sm text-stone-600 dark:border-neutral-700 dark:text-neutral-400">
                <div class="font-medium text-stone-800 dark:text-neutral-200">
                    {{ selectedLead.title || selectedLead.service_type || $t('requests.labels.request_number', { id: selectedLead.id }) }}
                </div>
                <div v-if="selectedLead.contact_email">{{ selectedLead.contact_email }}</div>
                <div v-if="selectedLead.contact_phone">{{ selectedLead.contact_phone }}</div>
            </div>

            <label class="flex items-center gap-2 text-sm text-stone-600 dark:text-neutral-300">
                <input
                    v-model="convertForm.create_customer"
                    type="checkbox"
                    class="rounded border-stone-300 text-green-600 focus:ring-green-600 dark:border-neutral-600"
                />
                {{ $t('requests.convert.create_customer') }}
            </label>

            <div v-if="!convertForm.create_customer" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <FloatingSelect
                        v-model="convertForm.customer_id"
                        :label="$t('requests.convert.customer')"
                        :options="customerSelectOptions"
                        :placeholder="$t('requests.convert.select_customer')"
                    />
                    <InputError class="mt-1" :message="convertForm.errors.customer_id" />
                </div>
                <div>
                    <FloatingSelect
                        v-model="convertForm.property_id"
                        :label="$t('requests.convert.location')"
                        :options="propertySelectOptions"
                        :placeholder="$t('requests.convert.no_location')"
                        :disabled="!propertyOptions.length"
                    />
                    <InputError class="mt-1" :message="convertForm.errors.property_id" />
                </div>
            </div>

            <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <FloatingInput v-model="convertForm.customer_name" :label="$t('requests.convert.customer_name')" />
                    <InputError class="mt-1" :message="convertForm.errors.customer_name" />
                </div>
                <div>
                    <FloatingInput v-model="convertForm.contact_name" :label="$t('requests.convert.contact_name')" />
                    <InputError class="mt-1" :message="convertForm.errors.contact_name" />
                </div>
                <div>
                    <FloatingInput v-model="convertForm.contact_email" :label="$t('requests.convert.contact_email')" type="email" />
                    <InputError class="mt-1" :message="convertForm.errors.contact_email" />
                </div>
                <div>
                    <FloatingInput v-model="convertForm.contact_phone" :label="$t('requests.convert.contact_phone')" />
                    <InputError class="mt-1" :message="convertForm.errors.contact_phone" />
                </div>
            </div>

            <div>
                <FloatingInput v-model="convertForm.job_title" :label="$t('requests.convert.job_title')" />
                <InputError class="mt-1" :message="convertForm.errors.job_title" />
            </div>

            <div>
                <FloatingTextarea v-model="convertForm.description" :label="$t('requests.convert.notes_optional')" />
            </div>

            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    :data-hs-overlay="`#${convertModalId}`"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                    @click="closeConvert"
                >
                    {{ $t('requests.actions.cancel') }}
                </button>
                <button
                    type="button"
                    :disabled="!canSubmitConvert"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50"
                    @click="submitConvert"
                >
                    {{ $t('requests.actions.convert') }}
                </button>
            </div>
        </div>
    </Modal>

    <Modal :title="$t('requests.update.title')" :id="updateModalId">
        <div class="space-y-4">
            <div v-if="selectedLead" class="rounded-sm border border-stone-200 p-3 text-sm text-stone-600 dark:border-neutral-700 dark:text-neutral-400">
                <div class="font-medium text-stone-800 dark:text-neutral-200">
                    {{ selectedLead.title || selectedLead.service_type || $t('requests.labels.request_number', { id: selectedLead.id }) }}
                </div>
                <div v-if="selectedLead.contact_email">{{ selectedLead.contact_email }}</div>
                <div v-if="selectedLead.contact_phone">{{ selectedLead.contact_phone }}</div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <FloatingSelect
                        v-model="updateForm.status"
                        :label="$t('requests.update.status')"
                        :options="statusSelectOptions"
                        :placeholder="$t('requests.filters.all_statuses')"
                    />
                    <InputError class="mt-1" :message="updateForm.errors.status" />
                </div>
                <div>
                    <FloatingSelect
                        v-model="updateForm.assigned_team_member_id"
                        :label="$t('requests.update.assignee')"
                        :options="assigneeSelectOptions"
                        :placeholder="$t('requests.labels.unassigned')"
                    />
                    <InputError class="mt-1" :message="updateForm.errors.assigned_team_member_id" />
                </div>
            </div>

            <div>
                <FloatingInput
                    v-model="updateForm.next_follow_up_at"
                    type="datetime-local"
                    :label="$t('requests.update.follow_up')"
                />
                <InputError class="mt-1" :message="updateForm.errors.next_follow_up_at" />
            </div>

            <div v-if="showLostReason">
                <FloatingTextarea v-model="updateForm.lost_reason" :label="$t('requests.update.lost_reason')" />
                <InputError class="mt-1" :message="updateForm.errors.lost_reason" />
            </div>

            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    :data-hs-overlay="`#${updateModalId}`"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                    @click="closeUpdate"
                >
                    {{ $t('requests.actions.cancel') }}
                </button>
                <button
                    type="button"
                    :disabled="updateForm.processing"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50"
                    @click="submitUpdate"
                >
                    {{ $t('requests.actions.save') }}
                </button>
            </div>
        </div>
    </Modal>
</template>
