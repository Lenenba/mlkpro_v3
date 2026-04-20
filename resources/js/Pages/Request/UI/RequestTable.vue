<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Link, router, useForm } from '@inertiajs/vue3';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableBulkBar from '@/Components/DataTable/AdminDataTableBulkBar.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import Modal from '@/Components/UI/Modal.vue';
import Checkbox from '@/Components/Checkbox.vue';
import DateTimePicker from '@/Components/DateTimePicker.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import { humanizeDate } from '@/utils/date';
import { buildLeadScore, badgeClass } from '@/utils/leadScore';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import { useDataTableSelection } from '@/Composables/useDataTableSelection';
import { useI18n } from 'vue-i18n';
import RequestBoard from '@/Pages/Request/UI/RequestBoard.vue';
import RequestTableActionsMenu from '@/Pages/Request/UI/RequestTableActionsMenu.vue';
import { useAccountFeatures } from '@/Composables/useAccountFeatures';
import {
    createBulkActionFailureResult,
    dispatchBulkActionToast,
    extractBulkActionErrorMessages,
    normalizeBulkActionResult,
    resolveBulkActionErrorMessage,
} from '@/utils/bulkActions';

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
    bulkActions: {
        type: Object,
        default: () => ({}),
    },
    leadIntake: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
const { hasFeature } = useAccountFeatures();

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
        case 'REQ_CALL_REQUESTED':
            return t('requests.status.call_requested');
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
        case 'REQ_CALL_REQUESTED':
            return 'bg-cyan-100 text-cyan-800 dark:bg-cyan-500/10 dark:text-cyan-300';
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

const triageQueueLabel = (queue) => {
    switch (queue) {
        case 'new':
            return t('requests.triage.queues.new');
        case 'due_soon':
            return t('requests.triage.queues.due_soon');
        case 'stale':
            return t('requests.triage.queues.stale');
        case 'breached':
            return t('requests.triage.queues.breached');
        case 'active':
            return t('requests.triage.queues.active');
        case 'closed':
            return t('requests.triage.queues.closed');
        default:
            return queue || t('requests.triage.queues.unknown');
    }
};

const triageQueueClass = (queue) => {
    switch (queue) {
        case 'new':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
        case 'due_soon':
            return 'bg-cyan-100 text-cyan-800 dark:bg-cyan-500/10 dark:text-cyan-300';
        case 'stale':
            return 'bg-orange-100 text-orange-800 dark:bg-orange-500/10 dark:text-orange-300';
        case 'breached':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300';
        case 'active':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const triageRiskLabel = (riskLevel) => {
    switch (riskLevel) {
        case 'critical':
            return t('requests.triage.risk_levels.critical');
        case 'high':
            return t('requests.triage.risk_levels.high');
        case 'medium':
            return t('requests.triage.risk_levels.medium');
        case 'low':
            return t('requests.triage.risk_levels.low');
        case 'closed':
            return t('requests.triage.risk_levels.closed');
        default:
            return riskLevel || t('requests.triage.risk_levels.unknown');
    }
};

const triageRiskClass = (riskLevel) => {
    switch (riskLevel) {
        case 'critical':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300';
        case 'high':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
        case 'medium':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300';
        case 'low':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const triagePriorityClass = (priority) => {
    const value = Number(priority || 0);

    if (value >= 90) {
        return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300';
    }

    if (value >= 70) {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300';
    }

    if (value > 0) {
        return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300';
    }

    return 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300';
};

const triageRowClass = (lead) => {
    switch (lead?.triage_queue) {
        case 'breached':
            return 'bg-rose-50/50 dark:bg-rose-500/5';
        case 'due_soon':
            return 'bg-cyan-50/50 dark:bg-cyan-500/5';
        case 'stale':
            return 'bg-amber-50/40 dark:bg-amber-500/5';
        default:
            return '';
    }
};

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
    customer_id: props.filters?.customer_id ?? '',
    queue: props.filters?.queue ?? '',
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
    (Array.isArray(props.bulkActions?.controls?.status?.options) && props.bulkActions.controls.status.options.length
        ? props.bulkActions.controls.status.options
        : (props.statuses || []).map((status) => ({
            value: String(status.id),
            label: statusLabel(String(status.id)),
        }))
    ).map((status) => ({
        id: String(status.value ?? status.id),
        name: String(status.label ?? status.name ?? status.value ?? status.id),
    }))
);

const assigneeSelectOptions = computed(() => [
    { id: '', name: t('requests.labels.unassigned') },
    ...(props.assignees || []).map((assignee) => ({
        id: String(assignee.id),
        name: assignee.name || t('requests.labels.unassigned'),
    })),
]);
const quickFollowUpOptions = computed(() => ([
    { id: 'tomorrow', label: t('requests.quick_actions.follow_up_tomorrow'), days: 1 },
    { id: 'three_days', label: t('requests.quick_actions.follow_up_three_days'), days: 3 },
    { id: 'seven_days', label: t('requests.quick_actions.follow_up_seven_days'), days: 7 },
]));

const bulkAssigneeOptions = computed(() =>
    (Array.isArray(props.bulkActions?.controls?.assign?.options) && props.bulkActions.controls.assign.options.length
        ? props.bulkActions.controls.assign.options
        : (props.assignees || []).map((assignee) => ({
            value: String(assignee.id),
            label: assignee.name || t('requests.labels.unassigned'),
        }))
    ).map((assignee) => ({
        id: String(assignee.value ?? assignee.id),
        name: String(assignee.label ?? assignee.name ?? assignee.value ?? assignee.id),
    }))
);

const bulkSelectionLabelKey = computed(() => props.bulkActions?.selection_label_key || 'requests.bulk.selected');
const bulkStatusLabelKey = computed(() => props.bulkActions?.controls?.status?.label_key || 'requests.bulk.status_label');
const bulkStatusPlaceholderKey = computed(() => props.bulkActions?.controls?.status?.placeholder_key || 'requests.bulk.status_placeholder');
const bulkStatusSubmitLabelKey = computed(() => props.bulkActions?.controls?.status?.submit_label_key || 'requests.bulk.apply_status');
const bulkLostReasonPlaceholderKey = computed(() => props.bulkActions?.controls?.status?.lost_reason_placeholder_key || 'requests.bulk.lost_reason');
const bulkLostReasonPromptKey = computed(() => props.bulkActions?.controls?.status?.lost_reason_prompt_key || 'requests.bulk.lost_reason_prompt');
const bulkLostReasonTriggerValue = computed(() => props.bulkActions?.controls?.status?.lost_reason_trigger_value || 'REQ_LOST');
const bulkAssignLabelKey = computed(() => props.bulkActions?.controls?.assign?.label_key || 'requests.bulk.assign_label');
const bulkAssignPlaceholderKey = computed(() => props.bulkActions?.controls?.assign?.placeholder_key || 'requests.bulk.assign_placeholder');
const bulkAssignSubmitLabelKey = computed(() => props.bulkActions?.controls?.assign?.submit_label_key || 'requests.bulk.apply_assign');
const queueFilterOptions = computed(() => ([
    {
        id: '',
        name: t('requests.triage.queues.all'),
        count: Number(props.stats?.total || 0),
    },
    {
        id: 'new',
        name: triageQueueLabel('new'),
        count: Number(props.stats?.new_queue || 0),
    },
    {
        id: 'due_soon',
        name: triageQueueLabel('due_soon'),
        count: Number(props.stats?.due_soon || 0),
    },
    {
        id: 'stale',
        name: triageQueueLabel('stale'),
        count: Number(props.stats?.stale || 0),
    },
    {
        id: 'breached',
        name: triageQueueLabel('breached'),
        count: Number(props.stats?.breached || 0),
    },
]));

const filterPayload = () => {
    const payload = {
        search: filterForm.search,
        status: filterForm.status,
        customer_id: filterForm.customer_id,
        queue: filterForm.queue,
        view: viewMode.value,
        per_page: currentPerPage.value,
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
            only: ['requests', 'filters', 'stats', 'analytics'],
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

watch(() => [filterForm.status, filterForm.customer_id, filterForm.queue], () => {
    autoFilter();
});

const clearFilters = () => {
    filterForm.search = '';
    filterForm.status = '';
    filterForm.customer_id = '';
    filterForm.queue = '';
    autoFilter();
};

const setQueueFilter = (queue) => {
    filterForm.queue = filterForm.queue === queue ? '' : queue;
};

const requestLinks = computed(() => props.requests?.links || []);
const currentPerPage = computed(() => resolveDataTablePerPage(props.requests?.per_page, props.filters?.per_page));

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
const requestTableRows = computed(() => (isLoading.value
    ? Array.from({ length: 6 }, (_, index) => ({ id: `request-skeleton-${index}`, __skeleton: true }))
    : tableRows.value));
const {
    selected,
    selectedCount,
    selectAllRef,
    allSelected,
    toggleAll,
    clearSelection,
} = useDataTableSelection(tableRows);

const bulkStatus = ref('');
const bulkAssignee = ref('');
const bulkLostReason = ref('');
const bulkErrors = ref({});
const bulkProcessing = ref(false);
const bulkResult = ref(null);

const clearBulkResult = () => {
    bulkResult.value = null;
};

const setBulkResult = (payload) => {
    bulkResult.value = normalizeBulkActionResult(payload);

    return bulkResult.value;
};

watch(selectedCount, (count, previousCount) => {
    if (count > 0 && count !== previousCount) {
        clearBulkResult();
    }
});

const reloadBulkContext = () => new Promise((resolve) => {
    router.reload({
        only: ['requests', 'filters', 'stats', 'analytics'],
        preserveScroll: true,
        preserveState: true,
        onFinish: () => resolve(),
    });
});

watch(() => bulkStatus.value, (value) => {
    if (value !== bulkLostReasonTriggerValue.value) {
        bulkLostReason.value = '';
        bulkErrors.value = {};
    }
});

const submitBulkStatus = async () => {
    if (!selected.value.length || !bulkStatus.value || bulkProcessing.value) {
        return;
    }

    let lostReason = bulkLostReason.value;
    if (bulkStatus.value === bulkLostReasonTriggerValue.value && !lostReason) {
        lostReason = window.prompt(t(bulkLostReasonPromptKey.value));
        if (!lostReason) {
            return;
        }
        bulkLostReason.value = lostReason;
    }

    bulkErrors.value = {};
    clearBulkResult();
    bulkProcessing.value = true;

    try {
        const { data } = await axios.patch(route('request.bulk'), {
            ids: selected.value,
            status: bulkStatus.value,
            lost_reason: bulkStatus.value === bulkLostReasonTriggerValue.value ? lostReason : null,
        }, {
            headers: {
                Accept: 'application/json',
            },
        });

        const result = setBulkResult(data);
        clearSelection();
        bulkStatus.value = '';
        bulkLostReason.value = '';
        dispatchBulkActionToast(result, t);
        await reloadBulkContext();
    } catch (error) {
        if (error?.response?.status === 422) {
            bulkErrors.value = error?.response?.data?.errors || {};

            return;
        }

        const errors = extractBulkActionErrorMessages(error);
        const message = resolveBulkActionErrorMessage(error, t);
        const result = createBulkActionFailureResult({
            message,
            errors: errors.length ? errors : [message],
            selectedCount: selected.value.length,
        });

        bulkResult.value = result;
        dispatchBulkActionToast(result, t);
    } finally {
        bulkProcessing.value = false;
    }
};

const submitBulkAssign = async () => {
    if (!selected.value.length || bulkProcessing.value || !bulkAssignee.value) {
        return;
    }

    bulkErrors.value = {};
    clearBulkResult();
    bulkProcessing.value = true;

    try {
        const { data } = await axios.patch(route('request.bulk'), {
            ids: selected.value,
            assigned_team_member_id: Number(bulkAssignee.value),
        }, {
            headers: {
                Accept: 'application/json',
            },
        });

        const result = setBulkResult(data);
        clearSelection();
        bulkAssignee.value = '';
        dispatchBulkActionToast(result, t);
        await reloadBulkContext();
    } catch (error) {
        if (error?.response?.status === 422) {
            bulkErrors.value = error?.response?.data?.errors || {};

            return;
        }

        const errors = extractBulkActionErrorMessages(error);
        const message = resolveBulkActionErrorMessage(error, t);
        const result = createBulkActionFailureResult({
            message,
            errors: errors.length ? errors : [message],
            selectedCount: selected.value.length,
        });

        bulkResult.value = result;
        dispatchBulkActionToast(result, t);
    } finally {
        bulkProcessing.value = false;
    }
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

const runQuickLeadUpdate = (lead, payload, options = {}) => {
    if (!lead?.id || processingId.value) {
        return;
    }

    processingId.value = lead.id;

    router.put(route('request.update', lead.id), payload, {
        preserveScroll: true,
        only: ['requests', 'stats', 'flash'],
        ...options,
        onFinish: (...args) => {
            processingId.value = null;
            options.onFinish?.(...args);
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
    runQuickLeadUpdate(lead, payload);
};

const setLeadAssignee = (lead, assigneeId) => {
    if (!lead) {
        return;
    }

    const normalizedAssigneeId = assigneeId === '' ? null : Number(assigneeId);
    const currentAssigneeId = lead.assigned_team_member_id ?? null;

    if (currentAssigneeId === normalizedAssigneeId) {
        return;
    }

    runQuickLeadUpdate(lead, {
        assigned_team_member_id: normalizedAssigneeId,
    });
};

const buildQuickFollowUpAt = (days) => {
    const date = new Date();
    date.setHours(9, 0, 0, 0);
    date.setDate(date.getDate() + days);

    if (date.getTime() <= Date.now()) {
        date.setDate(date.getDate() + 1);
    }

    return date.toISOString();
};

const setLeadFollowUp = (lead, days) => {
    if (!lead || isClosedStatus(lead.status)) {
        return;
    }

    runQuickLeadUpdate(lead, {
        next_follow_up_at: buildQuickFollowUpAt(days),
    });
};

const clearLeadFollowUp = (lead) => {
    if (!lead?.next_follow_up_at || isClosedStatus(lead.status)) {
        return;
    }

    runQuickLeadUpdate(lead, {
        next_follow_up_at: null,
    });
};

const canUseRequests = computed(() => hasFeature('requests'));
const canUseQuotes = computed(() => hasFeature('quotes'));

const openQuickCreate = () => {
    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-quick-create-request');
    }
};

const intakeModalId = 'hs-request-intake';
const importModalId = 'hs-request-import';
const intakeCopied = ref(false);
let intakeCopiedTimeout;

const setIntakeCopied = () => {
    intakeCopied.value = true;
    if (intakeCopiedTimeout) {
        clearTimeout(intakeCopiedTimeout);
    }
    intakeCopiedTimeout = setTimeout(() => {
        intakeCopied.value = false;
    }, 2000);
};

const fallbackCopyText = (value) => {
    if (typeof document === 'undefined' || !document.body) {
        return false;
    }
    const textArea = document.createElement('textarea');
    textArea.value = value;
    textArea.setAttribute('readonly', '');
    textArea.style.position = 'fixed';
    textArea.style.top = '-9999px';
    textArea.style.left = '-9999px';
    textArea.style.opacity = '0';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    textArea.setSelectionRange(0, textArea.value.length);

    let copied = false;
    try {
        copied = document.execCommand('copy');
    } catch (error) {
        copied = false;
    }

    document.body.removeChild(textArea);
    return copied;
};

const copyText = async (value) => {
    if (!value) {
        return;
    }

    let copied = false;

    try {
        if (typeof window !== 'undefined' && typeof navigator !== 'undefined' && window.isSecureContext && navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(value);
            copied = true;
        }
    } catch (error) {
        copied = false;
    }

    if (!copied) {
        copied = fallbackCopyText(value);
    }

    if (copied) {
        setIntakeCopied();
    }
};

const intakeExample = computed(() => {
    if (!props.leadIntake?.api_endpoint) {
        return '';
    }
    return `curl -X POST '${props.leadIntake.api_endpoint}' \\\n  -H 'Authorization: Bearer YOUR_TOKEN' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"contact_name\":\"Jane Doe\",\"contact_email\":\"jane@example.com\",\"service_type\":\"Cleaning\",\"description\":\"Need a quote\"}'`;
});

const importForm = useForm({
    file: null,
    mapping: {},
});
const importHeaders = ref([]);
const importMapping = ref({
    contact_name: '',
    contact_email: '',
    contact_phone: '',
    title: '',
    service_type: '',
    description: '',
    channel: '',
    urgency: '',
    budget: '',
    external_customer_id: '',
    street1: '',
    street2: '',
    city: '',
    state: '',
    postal_code: '',
    country: '',
    next_follow_up_at: '',
    is_serviceable: '',
});

const importFields = computed(() => ([
    { key: 'contact_name', label: t('requests.import.fields.contact_name') },
    { key: 'contact_email', label: t('requests.import.fields.contact_email') },
    { key: 'contact_phone', label: t('requests.import.fields.contact_phone') },
    { key: 'title', label: t('requests.import.fields.title') },
    { key: 'service_type', label: t('requests.import.fields.service_type') },
    { key: 'description', label: t('requests.import.fields.description') },
    { key: 'channel', label: t('requests.import.fields.channel') },
    { key: 'urgency', label: t('requests.import.fields.urgency') },
    { key: 'budget', label: t('requests.import.fields.budget') },
    { key: 'external_customer_id', label: t('requests.import.fields.external_customer_id') },
    { key: 'street1', label: t('requests.import.fields.street1') },
    { key: 'street2', label: t('requests.import.fields.street2') },
    { key: 'city', label: t('requests.import.fields.city') },
    { key: 'state', label: t('requests.import.fields.state') },
    { key: 'postal_code', label: t('requests.import.fields.postal_code') },
    { key: 'country', label: t('requests.import.fields.country') },
    { key: 'next_follow_up_at', label: t('requests.import.fields.next_follow_up_at') },
    { key: 'is_serviceable', label: t('requests.import.fields.is_serviceable') },
]));

const headerOptions = computed(() => ([
    { id: '', name: t('requests.import.skip') },
    ...importHeaders.value.map((header) => ({ id: header, name: header })),
]));

const parseCsvLine = (line) => {
    const result = [];
    let current = '';
    let inQuotes = false;
    for (let i = 0; i < line.length; i += 1) {
        const char = line[i];
        if (char === '"') {
            if (inQuotes && line[i + 1] === '"') {
                current += '"';
                i += 1;
            } else {
                inQuotes = !inQuotes;
            }
        } else if (char === ',' && !inQuotes) {
            result.push(current);
            current = '';
        } else {
            current += char;
        }
    }
    result.push(current);
    return result.map((value) => value.trim()).filter((value) => value !== '');
};

const autoMapHeaders = (headers) => {
    const normalized = headers.map((header) => header.toLowerCase());
    const findMatch = (patterns) => {
        for (const pattern of patterns) {
            const index = normalized.findIndex((header) => header.includes(pattern));
            if (index !== -1) {
                return headers[index];
            }
        }
        return '';
    };

    importMapping.value = {
        contact_name: findMatch(['name', 'contact', 'client', 'customer']),
        contact_email: findMatch(['email', 'e-mail']),
        contact_phone: findMatch(['phone', 'telephone', 'mobile', 'cell']),
        title: findMatch(['title', 'subject']),
        service_type: findMatch(['service', 'job', 'category']),
        description: findMatch(['description', 'details', 'notes', 'message']),
        channel: findMatch(['channel', 'source']),
        urgency: findMatch(['urgency', 'priority']),
        budget: findMatch(['budget', 'amount', 'estimate', 'price']),
        external_customer_id: findMatch(['external', 'customer_id', 'external_customer_id']),
        street1: findMatch(['street', 'address']),
        street2: findMatch(['street2', 'address2', 'suite']),
        city: findMatch(['city', 'ville']),
        state: findMatch(['state', 'province', 'region']),
        postal_code: findMatch(['postal', 'zip', 'postcode']),
        country: findMatch(['country', 'pays']),
        next_follow_up_at: findMatch(['follow_up', 'followup', 'next_follow']),
        is_serviceable: findMatch(['serviceable', 'is_serviceable']),
    };
};

const setImportFile = async (event) => {
    const file = event.target.files?.[0] || null;
    importForm.file = file;
    importHeaders.value = [];
    if (!file) {
        return;
    }
    const text = await file.text();
    const firstLine = text.split(/\r?\n/)[0] || '';
    const headers = parseCsvLine(firstLine);
    importHeaders.value = headers;
    autoMapHeaders(headers);
};

const resetImport = () => {
    importForm.reset();
    importHeaders.value = [];
    autoMapHeaders([]);
};

const submitImport = () => {
    if (!importForm.file) {
        return;
    }
    importForm.mapping = { ...importMapping.value };
    importForm.post(route('request.import'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            resetImport();
            if (window.HSOverlay) {
                window.HSOverlay.close(`#${importModalId}`);
            }
        },
    });
};

const scoreInfo = (lead) => buildLeadScore(lead, t);
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700"
    >
        <div class="space-y-3">
            <AdminDataTableToolbar
                :show-clear="false"
                :show-apply="false"
                :busy="isLoading"
            >
                <template #search>
                    <div class="relative flex-1">
                        <div class="absolute inset-y-0 start-0 z-20 flex items-center ps-3.5 pointer-events-none">
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
                </template>

                <template #actions>
                    <div class="inline-flex items-center rounded-sm border border-stone-200 bg-white p-0.5 text-xs font-semibold text-stone-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        <button
                            type="button"
                            @click="setViewMode('table')"
                            class="inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5"
                            :class="viewMode === 'table'
                                ? 'bg-green-600 text-white shadow-sm dark:bg-white dark:text-stone-900'
                                : 'text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100'"
                            data-testid="request-view-table"
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
                            data-testid="request-view-board"
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

                    <template v-if="canUseRequests">
                        <button
                            type="button"
                            class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                            :data-hs-overlay="`#${intakeModalId}`"
                        >
                            {{ $t('requests.actions.intake') }}
                        </button>
                        <button
                            type="button"
                            class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                            :data-hs-overlay="`#${importModalId}`"
                        >
                            {{ $t('requests.actions.import_csv') }}
                        </button>
                        <button
                            type="button"
                            class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700"
                            @click="openQuickCreate"
                        >
                            {{ $t('requests.actions.new_request') }}
                        </button>
                    </template>
                </template>
            </AdminDataTableToolbar>

            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
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

            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.triage.label') }}
                </span>
                <button
                    v-for="queue in queueFilterOptions"
                    :key="queue.id || 'all'"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium transition"
                    :class="filterForm.queue === queue.id
                        ? `${triageQueueClass(queue.id || 'active')} border-transparent`
                        : 'border-stone-200 bg-white text-stone-600 hover:border-stone-300 hover:text-stone-800 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:text-neutral-100'"
                    :data-testid="`request-queue-filter-${queue.id || 'all'}`"
                    @click="setQueueFilter(queue.id)"
                >
                    <span>{{ queue.name }}</span>
                    <span class="rounded-full bg-white/70 px-1.5 py-0.5 text-[11px] font-semibold text-current dark:bg-neutral-950/30">
                        {{ queue.count }}
                    </span>
                </button>
            </div>
        </div>

        <AdminDataTableBulkBar
            v-if="viewMode === 'table'"
            :count="selectedCount"
            :label="$t(bulkSelectionLabelKey, { count: selectedCount })"
            :result="bulkResult"
            data-testid="request-bulk-bar"
        >
            <div class="flex flex-wrap items-end gap-2">
                <FloatingSelect
                    v-model="bulkStatus"
                    :label="$t(bulkStatusLabelKey)"
                    :options="statusActionOptions"
                    :placeholder="$t(bulkStatusPlaceholderKey)"
                    dense
                    class="min-w-[170px]"
                    data-testid="request-bulk-status"
                />
                <input
                    v-if="bulkStatus === bulkLostReasonTriggerValue"
                    v-model="bulkLostReason"
                    type="text"
                    class="py-2 px-3 rounded-sm border border-stone-200 bg-white text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t(bulkLostReasonPlaceholderKey)"
                />
                <button
                    type="button"
                    class="py-2 px-3 rounded-sm border border-transparent bg-emerald-600 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
                    :disabled="bulkProcessing || !bulkStatus"
                    data-testid="request-bulk-status-submit"
                    @click="submitBulkStatus"
                >
                    {{ $t(bulkStatusSubmitLabelKey) }}
                </button>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <FloatingSelect
                    v-model="bulkAssignee"
                    :label="$t(bulkAssignLabelKey)"
                    :options="bulkAssigneeOptions"
                    :placeholder="$t(bulkAssignPlaceholderKey)"
                    dense
                    class="min-w-[180px]"
                    data-testid="request-bulk-assignee"
                />
                <button
                    type="button"
                    class="py-2 px-3 rounded-sm border border-stone-200 bg-white text-sm font-medium text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                    :disabled="bulkProcessing || !bulkAssignee"
                    data-testid="request-bulk-assign-submit"
                    @click="submitBulkAssign"
                >
                    {{ $t(bulkAssignSubmitLabelKey) }}
                </button>
            </div>
            <InputError class="mt-1 w-full basis-full" :message="bulkErrors.lost_reason" />
        </AdminDataTableBulkBar>

        <div v-if="viewMode === 'board'" class="pt-2">
            <RequestBoard :requests="requests.data" :statuses="statuses" />
        </div>

        <AdminDataTable
            v-else
            embedded
            :rows="requestTableRows"
            :links="requestLinks"
            :show-pagination="tableRows.length > 0"
            show-per-page
            :per-page="currentPerPage"
        >
            <template #empty>
                <div class="px-5 py-6 text-center text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.empty') }}
                </div>
            </template>

            <template #head>
                <tr>
                        <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            <input
                                ref="selectAllRef"
                                type="checkbox"
                                :checked="allSelected"
                                class="rounded-sm border-stone-200 text-green-600 shadow-sm focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-emerald-400 dark:focus:ring-emerald-400"
                                data-testid="request-select-all"
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
            </template>

            <template #row="{ row: lead }">
                <tr v-if="lead.__skeleton">
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
                <tr v-else :class="triageRowClass(lead)" :data-testid="`request-row-${lead.id}`">
                        <td class="px-5 py-3">
                            <Checkbox
                                v-model:checked="selected"
                                :value="lead.id"
                                :data-testid="`request-select-${lead.id}`"
                            />
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
                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200">
                                    {{ $t('requests.badges.score') }} {{ scoreInfo(lead).score }}
                                </span>
                                <span
                                    v-for="badge in scoreInfo(lead).badges"
                                    :key="badge.key + badge.label + lead.id"
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="badgeClass(badge.tone)"
                                >
                                    {{ badge.label }}
                                </span>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="['border', triagePriorityClass(lead.triage_priority)]"
                                    :data-testid="`request-priority-${lead.id}`"
                                >
                                    {{ $t('requests.triage.priority_short', { value: lead.triage_priority || 0 }) }}
                                </span>
                                <span
                                    v-if="lead.risk_level"
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="triageRiskClass(lead.risk_level)"
                                >
                                    {{ triageRiskLabel(lead.risk_level) }}
                                </span>
                                <span
                                    v-if="lead.days_since_activity !== null
                                        && lead.days_since_activity !== undefined
                                        && Number(lead.days_since_activity) > 0"
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200"
                                >
                                    {{ $t('requests.triage.inactive_days', { count: lead.days_since_activity }) }}
                                </span>
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
                            <div class="flex flex-col items-start gap-1.5">
                                <div class="hs-dropdown [--auto-close:inside] [--placement:bottom-left] relative inline-flex">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-sm px-2 py-0.5 text-xs font-medium"
                                        :class="statusClass(lead.status)"
                                        :data-testid="`request-status-trigger-${lead.id}`"
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
                                                :data-testid="`request-status-option-${lead.id}-${option.id}`"
                                                @click="setLeadStatus(lead, option.id)"
                                            >
                                                {{ option.name }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <span
                                    v-if="lead.triage_queue"
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="triageQueueClass(lead.triage_queue)"
                                    :data-testid="`request-triage-queue-${lead.id}`"
                                >
                                    {{ triageQueueLabel(lead.triage_queue) }}
                                </span>
                            </div>
                        </td>
                        <td class="px-5 py-3 text-sm text-stone-700 dark:text-neutral-300">
                            <div class="flex min-w-[11rem] flex-col items-start gap-2">
                                <span v-if="lead.assignee?.user?.name || lead.assignee?.name">
                                    {{ lead.assignee?.user?.name || lead.assignee?.name }}
                                </span>
                                <span v-else class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('requests.labels.unassigned') }}
                                </span>
                                <select
                                    class="w-full rounded-sm border border-stone-200 bg-white px-2 py-1.5 text-xs text-stone-700 focus:border-emerald-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                    :value="lead.assigned_team_member_id ? String(lead.assigned_team_member_id) : ''"
                                    :disabled="processingId === lead.id"
                                    :data-testid="`request-assignee-select-${lead.id}`"
                                    @change="setLeadAssignee(lead, $event.target.value)"
                                >
                                    <option
                                        v-for="option in assigneeSelectOptions"
                                        :key="`assignee-${lead.id}-${option.id}`"
                                        :value="option.id"
                                    >
                                        {{ option.name }}
                                    </option>
                                </select>
                            </div>
                        </td>
                        <td class="px-5 py-3 text-sm text-stone-700 dark:text-neutral-300">
                            <div class="flex min-w-[12rem] flex-col items-start gap-2">
                                <span v-if="lead.next_follow_up_at"
                                    class="inline-flex items-center gap-2"
                                    :class="isOverdue(lead) ? 'text-rose-600 dark:text-rose-400' : 'text-stone-700 dark:text-neutral-200'"
                                    :title="formatAbsoluteDate(lead.next_follow_up_at)">
                                    {{ formatDate(lead.next_follow_up_at) }}
                                </span>
                                <span v-else class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('requests.labels.no_follow_up') }}
                                </span>
                                <div v-if="!isClosedStatus(lead.status)" class="flex flex-wrap items-center gap-1">
                                    <button
                                        v-for="preset in quickFollowUpOptions"
                                        :key="`${lead.id}-${preset.id}`"
                                        type="button"
                                        class="rounded-full border border-stone-200 bg-white px-2 py-0.5 text-[11px] font-medium text-stone-600 hover:border-emerald-200 hover:text-emerald-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:border-emerald-500/40 dark:hover:text-emerald-300"
                                        :disabled="processingId === lead.id"
                                        :data-testid="`request-follow-up-${preset.id}-${lead.id}`"
                                        @click="setLeadFollowUp(lead, preset.days)"
                                    >
                                        {{ preset.label }}
                                    </button>
                                    <button
                                        v-if="lead.next_follow_up_at"
                                        type="button"
                                        class="rounded-full border border-transparent px-2 py-0.5 text-[11px] font-medium text-stone-500 hover:text-rose-600 disabled:cursor-not-allowed disabled:opacity-50 dark:text-neutral-400 dark:hover:text-rose-300"
                                        :disabled="processingId === lead.id"
                                        :data-testid="`request-follow-up-clear-${lead.id}`"
                                        @click="clearLeadFollowUp(lead)"
                                    >
                                        {{ $t('requests.actions.clear') }}
                                    </button>
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-3 text-sm text-stone-700 dark:text-neutral-300">
                            {{ formatDate(lead.created_at) }}
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <button
                                    v-if="canUseQuotes && canConvertLead(lead)"
                                    type="button"
                                    class="inline-flex items-center rounded-sm border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300 dark:hover:bg-emerald-500/20"
                                    :disabled="processingId === lead.id"
                                    :data-testid="`request-convert-${lead.id}`"
                                    @click="openConvert(lead)"
                                >
                                    {{ $t('requests.actions.convert') }}
                                </button>
                                <RequestTableActionsMenu
                                    :lead="lead"
                                    :can-use-quotes="canUseQuotes"
                                    :can-convert="canConvertLead(lead)"
                                    :processing="processingId === lead.id"
                                    @update="openUpdate(lead)"
                                    @convert="openConvert(lead)"
                                    @delete="deleteLead(lead)"
                                />
                            </div>
                        </td>
                    </tr>

            </template>

            <template #pagination_prefix>
                <span class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.pagination.showing', { from: requests.from || 0, to: requests.to || 0 }) }}
                </span>
            </template>
        </AdminDataTable>
    </div>

    <Modal :title="$t('requests.intake.title')" :id="intakeModalId">
        <div class="space-y-4">
            <div>
                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('requests.intake.public_form') }}
                </h3>
                <p class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.intake.public_form_hint') }}
                </p>
                <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                    <input
                        type="text"
                        readonly
                        class="flex-1 rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                        :value="leadIntake?.public_form_url || ''"
                    />
                    <button
                        type="button"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        @click="copyText(leadIntake?.public_form_url)"
                    >
                        {{ $t('requests.intake.copy') }}
                    </button>
                </div>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('requests.intake.webhook') }}
                </h3>
                <p class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.intake.webhook_hint') }}
                </p>
                <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                    <input
                        type="text"
                        readonly
                        class="flex-1 rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                        :value="leadIntake?.api_endpoint || ''"
                    />
                    <button
                        type="button"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        @click="copyText(leadIntake?.api_endpoint)"
                    >
                        {{ $t('requests.intake.copy') }}
                    </button>
                </div>
                <div v-if="intakeExample" class="mt-3 rounded-sm border border-stone-200 bg-stone-50 p-3 text-[11px] text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                    <div class="mb-1 font-semibold text-stone-700 dark:text-neutral-200">{{ $t('requests.intake.example') }}</div>
                    <pre class="whitespace-pre-wrap">{{ intakeExample }}</pre>
                </div>
                <div v-if="intakeCopied" class="mt-2 text-xs text-emerald-600 dark:text-emerald-400">
                    {{ $t('requests.intake.copied') }}
                </div>
            </div>
        </div>
    </Modal>

    <Modal :title="$t('requests.import.title')" :id="importModalId">
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-stone-700 dark:text-neutral-300">
                    {{ $t('requests.import.csv_file') }}
                </label>
                <input
                    type="file"
                    accept=".csv,text/csv"
                    class="mt-2 block w-full text-sm text-stone-700 dark:text-neutral-200"
                    @change="setImportFile"
                />
                <InputError class="mt-1" :message="importForm.errors.file" />
            </div>

            <div v-if="importHeaders.length">
                <div class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.import.mapping_hint') }}
                </div>
                <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div v-for="field in importFields" :key="field.key">
                        <FloatingSelect
                            v-model="importMapping[field.key]"
                            :label="field.label"
                            :options="headerOptions"
                            dense
                        />
                    </div>
                </div>
            </div>
            <div v-else class="text-xs text-stone-500 dark:text-neutral-400">
                {{ $t('requests.import.missing_headers') }}
            </div>

            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                    :data-hs-overlay="`#${importModalId}`"
                    @click="resetImport"
                >
                    {{ $t('requests.actions.cancel') }}
                </button>
                <button
                    type="button"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50"
                    :disabled="importForm.processing || !importForm.file"
                    @click="submitImport"
                >
                    {{ $t('requests.import.submit') }}
                </button>
            </div>
        </div>
    </Modal>

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
                    data-testid="request-convert-submit"
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
                <DateTimePicker
                    v-model="updateForm.next_follow_up_at"
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
