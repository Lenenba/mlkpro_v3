<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import dayjs from 'dayjs';
import 'dayjs/locale/fr';
import 'dayjs/locale/es';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import AdminFilterSummary from '@/Components/DataTable/AdminFilterSummary.vue';
import AdminQuickFilters from '@/Components/DataTable/AdminQuickFilters.vue';
import Modal from '@/Components/Modal.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import ReservationCalendarBoard from '@/Components/Reservation/ReservationCalendarBoard.vue';
import ReservationDetailsPanel from '@/Components/Reservation/ReservationDetailsPanel.vue';
import ReservationCustomerChooser from '@/Components/Reservation/ReservationCustomerChooser.vue';
import ReservationListTable from '@/Components/Reservation/ReservationListTable.vue';
import ReservationStats from '@/Components/Reservation/ReservationStats.vue';
import ReservationAdvancedFiltersDialog from '@/Components/Reservation/ReservationAdvancedFiltersDialog.vue';
import ModuleKpiSection from '@/Components/Dashboard/ModuleKpiSection.vue';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import { reservationStatusBadgeClass } from '@/Components/Reservation/status';
import { paymentMethodLabel as resolvePaymentMethodLabel, useTenantPaymentMethods } from '@/Composables/useTenantPaymentMethods';
import { crmSegmentedControlButtonClass, crmSegmentedControlClass } from '@/utils/crmButtonStyles';
import { currentReservationDay } from '@/utils/reservationCalendar';
import { reservationCalendarUrl, reservationFilterFields, reservationReloadProps } from '@/utils/reservationNavigation';
import {
    RESERVATION_QUICK_FILTERS,
    countReservationAdvancedFilters,
    createReservationAdvancedFilters,
    initialReservationQuickFilters,
    normalizeReservationQuickFilterMode,
    reservationFilterPayload,
    toggleReservationQuickFilter,
} from '@/utils/reservationFilters';
import {
    nextReservationListSort,
    reservationListAllowedStatusTransitions,
    reservationListCanUpdateStatus,
    reservationListCanView,
    reservationListSortColumn,
    reservationListSortDirection,
    reservationListSortValue,
} from '@/utils/reservationList';
import {
    RESERVATION_QUEUE_QUICK_FILTERS,
    normalizeReservationQueueQuickFilter,
    reservationQueueMatchesQuickFilter,
    reservationQueuePrimaryAction,
    reservationQueueQuickCounts,
} from '@/utils/reservationQueue';

const { t, locale } = useI18n();
const queueStripeReturn = (() => {
    if (typeof window === 'undefined') {
        return { status: '', queueItemId: 0, invoiceId: 0, attemptId: '' };
    }

    const query = new URLSearchParams(window.location.search);

    return {
        status: String(query.get('stripe') || ''),
        queueItemId: Number(query.get('queue_checkout') || 0),
        invoiceId: Number(query.get('invoice_id') || 0),
        attemptId: String(query.get('stripe_attempt') || ''),
    };
})();
const reservationCreateRequest = (() => {
    if (typeof window === 'undefined') {
        return { shouldOpen: false, customerId: 0 };
    }

    const query = new URLSearchParams(window.location.search);

    return {
        shouldOpen: query.get('open_editor') === '1',
        customerId: Number(query.get('customer_id') || 0),
    };
})();
const dayjsLocale = computed(() => {
    const value = String(locale.value || '').toLowerCase();

    if (value.startsWith('fr')) {
        return 'fr';
    }

    if (value.startsWith('es')) {
        return 'es';
    }

    return 'en';
});

const props = defineProps({
    filters: {
        type: Object,
        default: () => ({}),
    },
    reservations: {
        type: Object,
        default: () => ({ data: [] }),
    },
    reservationCount: {
        type: Number,
        default: null,
    },
    events: {
        type: Array,
        default: () => [],
    },
    statuses: {
        type: Array,
        default: () => [],
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
    quickCounts: {
        type: Object,
        default: () => ({}),
    },
    performance: {
        type: Object,
        default: () => ({}),
    },
    waitlists: {
        type: Array,
        default: () => [],
    },
    waitlistStats: {
        type: Object,
        default: () => ({}),
    },
    queueItems: {
        type: Array,
        default: () => [],
    },
    queueStats: {
        type: Object,
        default: () => ({}),
    },
    access: {
        type: Object,
        default: () => ({}),
    },
    teamMembers: {
        type: Array,
        default: () => [],
    },
    services: {
        type: Array,
        default: () => [],
    },
    clients: {
        type: Array,
        default: () => [],
    },
    timezone: {
        type: String,
        default: 'UTC',
    },
    defaults: {
        type: Object,
        default: () => ({}),
    },
    settings: {
        type: Object,
        default: () => ({}),
    },
    paymentMethodSettings: {
        type: Object,
        default: () => ({}),
    },
    tips: {
        type: Object,
        default: () => ({}),
    },
    focus_reservation_id: {
        type: [Number, String],
        default: 0,
    },
});

const viewMode = ref(props.filters?.view_mode || 'calendar');
const calendarView = ref(props.filters?.calendar_view || 'week');
const calendarDate = ref(props.filters?.calendar_date || currentReservationDay(dayjs(), props.timezone).format('YYYY-MM-DD'));
const calendarEvents = ref([...(props.events || [])]);
const calendarLoading = ref(false);
const calendarError = ref('');
const listLoading = ref(false);
const filtersLoading = ref(false);
const filtersBusy = computed(() => filtersLoading.value || calendarLoading.value);
let synchronizingFilters = false;
const listError = ref('');
const listStatusActionError = ref('');
const listStatusUpdatingId = ref(null);
const detailsActionError = ref('');
const detailsActionLoading = ref(false);
const waitlistRows = ref([...(props.waitlists || [])]);
const waitlistActionError = ref('');
const waitlistActionSuccess = ref('');
const waitlistUpdatingId = ref(null);
const queueRows = ref([...(props.queueItems || [])]);
const queueViewModes = ['table', 'cards'];
const queueViewMode = ref('table');
const queueQuickFilter = ref('all');
if (typeof window !== 'undefined') {
    const storedQueueViewMode = window.localStorage.getItem('reservation_queue_view_mode');
    if (queueViewModes.includes(storedQueueViewMode)) {
        queueViewMode.value = storedQueueViewMode;
    }

    queueQuickFilter.value = normalizeReservationQueueQuickFilter(
        window.localStorage.getItem('reservation_queue_quick_filter')
    );
}
const queueActionError = ref(queueStripeReturn.status === 'error'
    ? t('reservations.queue.checkout.stripe_return.error')
    : '');
const queueActionSuccess = ref(queueStripeReturn.status === 'success'
    ? t('reservations.queue.checkout.stripe_return.success')
    : '');
const queueActionWarning = ref(
    queueStripeReturn.status === 'pending'
        ? t('reservations.queue.checkout.stripe_return.pending')
        : (queueStripeReturn.status === 'cancel'
            ? t('reservations.queue.checkout.stripe_return.cancelled')
            : '')
);
const queueReceiptUrl = ref(
    queueStripeReturn.status === 'success' && queueStripeReturn.invoiceId > 0
        ? route('invoice.pdf', queueStripeReturn.invoiceId)
        : ''
);
const queueUpdatingId = ref(null);
const queueCallingNext = ref(false);
const openQueueActionsFor = ref(null);
const queueActionButtonRefs = ref({});
const queueActionMenuRef = ref(null);
const queueActionMenuStyle = ref({});
let queueActionListenersBound = false;
let queueStripeStatusTimer = null;
let queueStripeStatusPolls = 0;
const showQueueCheckout = ref(false);
const activeQueueCheckoutItem = ref(null);
const queueCheckoutProcessing = ref(false);
const queueCheckoutError = ref('');
const queueCheckoutForm = ref({
    method: '',
    reference: '',
    notes: '',
    receipt_delivery: '',
});
const showQueueAvailabilityConfirmation = ref(false);
const pendingQueueAvailabilityConfirmation = ref(null);
const queueAvailabilityConfirmationProcessing = ref(false);
const queueTipEnabled = ref(false);
const queueTipMode = ref('percent');
const queueTipPercent = ref(0);
const queueTipFixedAmount = ref(0);
const canViewAll = computed(() => Boolean(props.access?.can_view_all));
const canManage = computed(() => Boolean(props.access?.can_manage));
const canCreateCustomer = computed(() => Boolean(props.access?.can_create_customer));
const ownerOnlyMode = computed(() => Boolean(props.settings?.owner_only_mode));
const canManageReservationActions = computed(() => canManage.value && !ownerOnlyMode.value);
const waitlistEnabled = computed(() => Boolean(props.settings?.waitlist_enabled));
const queueModeEnabled = computed(() => Boolean(props.settings?.queue_mode_enabled));
const queueAssignmentMode = computed(() => (
    ['per_staff', 'global_pull'].includes(String(props.settings?.queue_assignment_mode || ''))
        ? String(props.settings?.queue_assignment_mode)
        : 'per_staff'
));
const hasQueueTab = computed(() => queueModeEnabled.value || queueRows.value.length > 0);
const hasWaitlistTab = computed(() => waitlistEnabled.value || waitlistRows.value.length > 0);
const activeQueueActionItem = computed(() => queueRows.value.find(
    (item) => Number(item.id) === openQueueActionsFor.value
) || null);
const queueAvailabilityConfirmationMemberName = computed(() => (
    pendingQueueAvailabilityConfirmation.value?.teamMemberName
        || t('reservations.queue.availability_confirmation.fallback_member')
));
const {
    allowedPaymentMethods,
    defaultPaymentMethod,
    hasMultiplePaymentMethods,
} = useTenantPaymentMethods(computed(() => props.paymentMethodSettings));
const reservationTabCount = computed(() => Number(props.reservationCount ?? props.reservations?.total ?? props.reservations?.data?.length ?? 0));
const activeDataTab = ref(queueStripeReturn.queueItemId > 0 ? 'queue' : (props.filters?.data_tab || 'reservations'));
const calendarVisible = computed(() => activeDataTab.value === 'reservations' && viewMode.value === 'calendar');
const ownTeamMemberId = computed(() => {
    const raw = props.access?.own_team_member_id;
    return raw ? String(raw) : '';
});
const calendarRange = ref({
    start: '',
    end: '',
});

const showEditor = ref(false);
const showDetails = ref(false);
const activeReservation = ref(null);
const localClients = ref([...(props.clients || [])]);
const reservationCustomerMode = ref('existing');
const customerCreationProcessing = ref(false);
const reservationStartsAtField = ref(null);
const detailsLoading = ref(false);
const detailsLoadError = ref('');
const showAdvanced = ref(false);
const lastFocusedReservationId = ref(null);
const conversionLoading = ref(false);
const conversionSubmitting = ref(false);
const conversionPayload = ref(null);
const conversionError = ref('');
const conversionSuccess = ref('');
let calendarAbortController = null;
let calendarRequestSequence = 0;
let calendarRequestKey = '';
let listRequestSequence = 0;
let listCancelToken = null;
let detailsAbortController = null;
let detailsRequestSequence = 0;
let conversionAbortController = null;
let conversionMutationSequence = 0;

watch(
    () => [hasQueueTab.value, hasWaitlistTab.value],
    ([hasQueue, hasWaitlist]) => {
        if (activeDataTab.value === 'queue' && !hasQueue) {
            activeDataTab.value = 'reservations';
            return;
        }
        if (activeDataTab.value === 'waitlist' && !hasWaitlist) {
            activeDataTab.value = 'reservations';
        }
    },
    { immediate: true }
);

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
    team_member_id: props.filters?.team_member_id ?? ownTeamMemberId.value,
    service_id: props.filters?.service_id ?? '',
    date_from: props.filters?.date_from ?? '',
    date_to: props.filters?.date_to ?? '',
    scope: props.filters?.scope ?? (ownTeamMemberId.value ? 'mine' : 'all'),
    quick_filters: initialReservationQuickFilters(props.filters),
    quick_filter_mode: normalizeReservationQuickFilterMode(props.filters?.quick_filter_mode),
    sort: props.filters?.sort ?? 'date_asc',
    view_mode: props.filters?.view_mode ?? viewMode.value,
});

const reservationForm = useForm({
    team_member_id: '',
    client_id: '',
    service_id: '',
    status: props.defaults?.status || 'confirmed',
    starts_at: '',
    ends_at: '',
    duration_minutes: props.defaults?.duration_minutes || 60,
    internal_notes: '',
    client_notes: '',
    timezone: props.timezone || 'UTC',
});

const conversionForm = useForm({
    mode: 'create_new',
    customer_id: '',
    contact_name: '',
    contact_email: '',
    contact_phone: '',
    company_name: '',
});

const reservationMap = computed(() => {
    const map = new Map();
    (props.reservations?.data || []).forEach((item) => map.set(Number(item.id), item));
    return map;
});

const statusOptions = computed(() => [
    { value: '', label: t('reservations.filters.all_statuses') },
    ...(props.statuses || []).map((status) => ({
        value: status,
        label: t(`reservations.status.${status}`) || status.replace(/_/g, ' '),
    })),
]);

const reservationQuickFilters = computed(() => RESERVATION_QUICK_FILTERS.map((value) => ({
    value,
    label: t(`reservations.quick.${value}`),
})));

const updateReservationFilters = (update) => {
    synchronizingFilters = true;
    update();
    refreshList({ reason: 'filters', replace: false });
    nextTick(() => { synchronizingFilters = false; });
};

const setReservationQuickFilter = (value) => {
    updateReservationFilters(() => {
        filterForm.quick_filters = toggleReservationQuickFilter(filterForm.quick_filters, value);
    });
};

const clearReservationQuickFilters = () => {
    if (filterForm.quick_filters.length) {
        updateReservationFilters(() => { filterForm.quick_filters = []; });
    }
};

const setReservationQuickFilterMode = (mode) => {
    const normalized = normalizeReservationQuickFilterMode(mode);
    if (normalized !== filterForm.quick_filter_mode) {
        updateReservationFilters(() => { filterForm.quick_filter_mode = normalized; });
    }
};

const applyReservationAdvancedFilters = (filters) => {
    updateReservationFilters(() => {
        Object.assign(filterForm, createReservationAdvancedFilters({ ...filters, scope: filterForm.scope }, ownTeamMemberId.value));
    });
    showAdvanced.value = false;
};

const setReservationScope = (scope) => {
    if (scope === filterForm.scope) {
        return;
    }
    updateReservationFilters(() => {
        filterForm.team_member_id = scope === 'mine' ? ownTeamMemberId.value : '';
        filterForm.scope = scope;
    });
};

const scopeOptions = computed(() => {
    const options = [];
    if (ownTeamMemberId.value) {
        options.push({ value: 'mine', label: t('reservations.scope.mine') });
    }
    if (canViewAll.value) {
        options.push({ value: 'all', label: t('reservations.scope.all') });
    }
    if (!options.length) {
        options.push({ value: 'all', label: t('reservations.scope.all') });
    }
    return options;
});

const teamOptions = computed(() => [
    { value: '', label: t('planning.filters.all_members') },
    ...(props.teamMembers || []).map((member) => ({
        value: String(member.id),
        label: member.title ? `${member.name} - ${member.title}` : member.name,
    })),
]);

const serviceOptions = computed(() => [
    { value: '', label: t('reservations.form.none') },
    ...(props.services || []).map((service) => ({
        value: String(service.id),
        label: service.name,
    })),
]);

const clientOptions = computed(() => [
    { value: '', label: t('reservations.form.none') },
    ...localClients.value.map((client) => ({
        value: String(client.id),
        label: client.company_name
            || `${client.first_name || ''} ${client.last_name || ''}`.trim()
            || `#${client.id}`,
    })),
]);

const isDateSort = computed(() => ['date_asc', 'date_desc'].includes(filterForm.sort));
const isDateSortAsc = computed(() => filterForm.sort === 'date_asc');
const isStatusSort = computed(() => ['status', 'status_asc', 'status_desc'].includes(filterForm.sort));
const reservationRows = computed(() => (Array.isArray(props.reservations?.data) ? props.reservations.data : []));
const focusReservationId = computed(() => Number(props.focus_reservation_id || 0));
const reservationLinks = computed(() => props.reservations?.links || []);
const currentPerPage = computed(() => resolveDataTablePerPage(props.reservations?.per_page, props.filters?.per_page));
const reservationPaginationLabel = computed(() => t('reservations.pagination.showing', {
    from: props.reservations?.from || 0,
    to: props.reservations?.to || 0,
}));
const hasActiveReservationFilters = computed(() => activeReservationFilters.value.length > 0);
const advancedFilterCount = computed(() => countReservationAdvancedFilters(filterForm));
const activeReservationFilters = computed(() => {
    const optionLabel = (options, value) => options.find((option) => String(option.value) === String(value))?.label || value;
    const fields = [
        { field: 'search', label: t('reservations.filters.search'), value: filterForm.search },
        { field: 'status', label: t('reservations.filters.status'), value: filterForm.status && optionLabel(statusOptions.value, filterForm.status) },
        { field: 'service_id', label: t('reservations.form.item'), value: filterForm.service_id && optionLabel(serviceOptions.value, filterForm.service_id) },
        { field: 'team_member_id', label: t('reservations.details.team_member'), value: filterForm.scope !== 'mine' && filterForm.team_member_id && optionLabel(teamOptions.value, filterForm.team_member_id) },
        { field: 'date_from', label: t('reservations.filters.date_from'), value: filterForm.date_from },
        { field: 'date_to', label: t('reservations.filters.date_to'), value: filterForm.date_to },
    ].filter((filter) => filter.value).map((filter) => ({
        id: filter.field,
        field: filter.field,
        label: t('reservations.filter_summary.filter_badge', { label: filter.label, value: filter.value }),
    }));
    const quickFilters = filterForm.quick_filters.map((value) => ({
        id: `quick:${value}`, field: 'quick_filters', value, label: t(`reservations.quick.${value}`),
    }));
    return [...fields.filter((filter) => filter.field === 'search'), ...quickFilters, ...fields.filter((filter) => filter.field !== 'search')];
});

const removeReservationFilter = (filter) => {
    updateReservationFilters(() => {
        if (filter.field === 'quick_filters') {
            filterForm.quick_filters = filterForm.quick_filters.filter((value) => value !== filter.value);
        } else {
            filterForm[filter.field] = '';
        }
    });
};

const statusBadgeClass = (status) => reservationStatusBadgeClass(status);
const waitlistBadgeStatus = (status) => {
    if (status === 'released') {
        return 'rescheduled';
    }
    if (status === 'booked') {
        return 'completed';
    }
    if (status === 'expired') {
        return 'cancelled';
    }
    return status;
};
const formatDateTime = (value) => (value ? dayjs(value).locale(dayjsLocale.value).format('DD MMM YYYY HH:mm') : '-');
const queueDateTimeFormatter = computed(() => new Intl.DateTimeFormat(locale.value || undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: props.timezone || 'UTC',
}));
const queueTimeFormatter = computed(() => new Intl.DateTimeFormat(locale.value || undefined, {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: props.timezone || 'UTC',
}));
const formatQueueDateTime = (value) => {
    const date = value ? new Date(value) : null;

    return date && !Number.isNaN(date.getTime()) ? queueDateTimeFormatter.value.format(date) : '-';
};
const formatQueueSchedule = (item) => {
    if (!item?.reservation_starts_at) {
        return formatQueueDateTime(item?.checked_in_at);
    }

    const start = formatQueueDateTime(item.reservation_starts_at);
    const end = item?.reservation_ends_at ? new Date(item.reservation_ends_at) : null;

    return end && !Number.isNaN(end.getTime())
        ? `${start} – ${queueTimeFormatter.value.format(end)}`
        : start;
};
const queueOpenReservationLabel = (item) => t('reservations.queue.details.view_reservation_for', {
    client: item?.client_name || item?.queue_number || `#${item?.reservation_id || ''}`,
});
const formatMoney = (value) => Number(value || 0).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});
const formatRate = (value) => Number(value || 0).toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 4,
});
const roundMoney = (value) => Math.round((Number(value || 0) + Number.EPSILON) * 100) / 100;
const queueStatusBadgeClass = (status) => (
    status === 'awaiting_payment'
        ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'
        : statusBadgeClass(status)
);
const queueRowHasActions = (item) => {
    if (!item?.can_update_status) {
        return false;
    }

    if (Array.isArray(item?.allowed_actions)) {
        return item.allowed_actions.length > 0;
    }

    return [
        'not_arrived',
        'checked_in',
        'pre_called',
        'called',
        'skipped',
        'in_service',
        'awaiting_payment',
    ].includes(String(item?.status || ''));
};
const localQueueQuickCounts = computed(() => reservationQueueQuickCounts(queueRows.value));
const queueQuickFilters = computed(() => RESERVATION_QUEUE_QUICK_FILTERS.map((value) => ({
    value,
    label: t(`reservations.queue.filters.${value}`),
    count: Number(props.queueStats?.[value] ?? localQueueQuickCounts.value[value] ?? 0),
})));
const filteredQueueRows = computed(() => queueRows.value.filter(
    (item) => reservationQueueMatchesQuickFilter(item, queueQuickFilter.value)
));
const queuePrimaryActionFor = (item) => reservationQueuePrimaryAction(item);
const queuePrimaryActionLabel = (item) => {
    const action = queuePrimaryActionFor(item);

    return action
        ? t(`reservations.queue.actions.${action === 'finish' ? 'finish_checkout' : action}`)
        : '';
};
const queueActionIsPrimary = (item, action) => (
    queuePrimaryActionFor(item) === String(action || '').replaceAll('-', '_')
);
const queueRowHasSecondaryActions = (item) => {
    if (!Array.isArray(item?.allowed_actions)) {
        return false;
    }

    const primaryAction = queuePrimaryActionFor(item);

    return item.allowed_actions.some(
        (action) => String(action || '').replaceAll('-', '_') !== primaryAction
    );
};
const formatQueueMoney = (value, currencyCode = 'CAD') => {
    const amount = Number(value || 0);
    const currency = String(currencyCode || 'CAD').toUpperCase();

    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(Number.isFinite(amount) ? amount : 0);
    } catch {
        return `${formatMoney(amount)} ${currency}`;
    }
};
const queueCheckoutBaseAmount = computed(() => {
    const checkout = activeQueueCheckoutItem.value?.checkout || {};
    const value = Number(checkout.subtotal ?? checkout.base_amount ?? 0);

    return Number.isFinite(value) ? Math.max(0, value) : 0;
});
const queueCheckoutTaxTotal = computed(() => {
    const value = Number(activeQueueCheckoutItem.value?.checkout?.tax_total || 0);

    return Number.isFinite(value) ? Math.max(0, value) : 0;
});
const queueCheckoutInvoiceTotal = computed(() => {
    const checkout = activeQueueCheckoutItem.value?.checkout || {};
    const value = Number(checkout.invoice_total ?? (queueCheckoutBaseAmount.value + queueCheckoutTaxTotal.value));

    return Number.isFinite(value) ? Math.max(0, value) : 0;
});
const queueCheckoutTaxRate = computed(() => {
    const value = Number(activeQueueCheckoutItem.value?.checkout?.tax_rate || 0);

    return Number.isFinite(value) ? Math.max(0, value) : 0;
});
const queueCheckoutTaxLabel = computed(() => (
    queueCheckoutTaxRate.value > 0
        ? t('reservations.queue.checkout.taxes_with_rate', { rate: formatRate(queueCheckoutTaxRate.value) })
        : t('reservations.queue.checkout.taxes')
));
const queueCheckoutCurrency = computed(() => activeQueueCheckoutItem.value?.checkout?.currency_code || 'CAD');
const maxQueueTipPercent = computed(() => Number(props.tips?.max_percent ?? 30));
const maxQueueTipFixed = computed(() => Number(props.tips?.max_fixed_amount ?? 200));
const queueQuickTipPercents = computed(() => props.tips?.quick_percents || [5, 10, 15, 20]);
const queueQuickTipFixedAmounts = computed(() => props.tips?.quick_fixed_amounts || [2, 5, 10]);
const normalizedQueueTipPercent = computed(() => Math.max(0, Math.min(
    Number(queueTipPercent.value || 0) || 0,
    maxQueueTipPercent.value
)));
const normalizedQueueTipFixedAmount = computed(() => Math.max(0, Math.min(
    Number(queueTipFixedAmount.value || 0) || 0,
    maxQueueTipFixed.value
)));
const queueCheckoutTipAmount = computed(() => {
    if (!queueTipEnabled.value) {
        return 0;
    }

    return queueTipMode.value === 'percent'
        ? roundMoney(queueCheckoutBaseAmount.value * (normalizedQueueTipPercent.value / 100))
        : roundMoney(normalizedQueueTipFixedAmount.value);
});
const queueCheckoutChargedTotal = computed(() => roundMoney(
    queueCheckoutInvoiceTotal.value + queueCheckoutTipAmount.value
));
const queuePaymentMethodLabel = (method) => resolvePaymentMethodLabel(method, {
    cash: t('reservations.queue.checkout.methods.cash'),
    card: t('reservations.queue.checkout.methods.card'),
    bankTransfer: t('reservations.queue.checkout.methods.bank_transfer'),
    check: t('reservations.queue.checkout.methods.check'),
});
const queuePaymentMethodOptions = computed(() => allowedPaymentMethods.value.map((method) => ({
    value: method,
    label: queuePaymentMethodLabel(method),
})));
const queueReceiptDeliveryOptions = computed(() => ([
    { value: '', label: t('reservations.queue.checkout.receipt.none') },
    { value: 'email', label: t('reservations.queue.checkout.receipt.email') },
    { value: 'sms', label: t('reservations.queue.checkout.receipt.sms') },
]));
const isQueueCardCheckout = computed(() => queueCheckoutForm.value.method === 'card');
const queueCheckoutSubmitLabel = computed(() => {
    if (queueCheckoutProcessing.value) {
        return t('reservations.queue.checkout.processing');
    }

    return isQueueCardCheckout.value
        ? t('reservations.queue.checkout.submit_card')
        : t('reservations.queue.checkout.submit');
});
const canSubmitQueueCheckout = computed(() => (
    Boolean(activeQueueCheckoutItem.value?.id)
    && queueCheckoutInvoiceTotal.value > 0
    && Boolean(queueCheckoutForm.value.method)
));
const queueCheckoutPayload = computed(() => ({
    method: queueCheckoutForm.value.method,
    tip_enabled: queueTipEnabled.value,
    tip_mode: queueTipEnabled.value ? queueTipMode.value : 'none',
    tip_percent: queueTipEnabled.value && queueTipMode.value === 'percent'
        ? normalizedQueueTipPercent.value
        : null,
    tip_amount: queueTipEnabled.value && queueTipMode.value === 'fixed'
        ? normalizedQueueTipFixedAmount.value
        : 0,
    reference: queueCheckoutForm.value.reference || null,
    notes: queueCheckoutForm.value.notes || null,
    receipt_delivery: queueCheckoutForm.value.receipt_delivery || null,
}));
watch(
    () => [allowedPaymentMethods.value, defaultPaymentMethod.value],
    () => {
        if (!allowedPaymentMethods.value.includes(queueCheckoutForm.value.method)) {
            queueCheckoutForm.value.method = defaultPaymentMethod.value;
        }
    },
    { immediate: true }
);
const firstValidationMessage = (errors) => {
    if (!errors || typeof errors !== 'object') {
        return '';
    }

    for (const value of Object.values(errors)) {
        if (Array.isArray(value) && value[0]) {
            return value[0];
        }
        if (typeof value === 'string' && value) {
            return value;
        }
    }

    return '';
};
const toLocalInput = (value) => (value ? dayjs(value).format('YYYY-MM-DDTHH:mm') : '');
const activePermissions = computed(() => activeReservation.value?.permissions || {});
const activeAllowedStatusTransitions = computed(() => (
    Array.isArray(activePermissions.value?.allowed_status_transitions)
        ? activePermissions.value.allowed_status_transitions
        : []
));
const canEditActiveReservation = computed(() => (
    Boolean(activePermissions.value?.can_edit)
    && !detailsLoading.value
));
const canConvertActiveReservation = computed(() => Boolean(activePermissions.value?.can_convert) && !detailsLoading.value);
const canUpdateActiveReservationStatus = computed(() => (
    Boolean(activePermissions.value?.can_update_status)
    && !detailsLoading.value
));
const isPublicBookingProspect = computed(() => Boolean(
    canConvertActiveReservation.value
    && activeReservation.value?.prospect_id
    && !activeReservation.value?.client_id
));
const publicBookingContact = computed(() => {
    const prospect = activeReservation.value?.prospect || {};
    const publicBooking = activeReservation.value?.public_booking || {};

    return {
        name: prospect.contact_name || publicBooking.contact_name || '',
        email: prospect.contact_email || publicBooking.contact_email || '',
        phone: prospect.contact_phone || publicBooking.contact_phone || '',
        link: activeReservation.value?.public_booking_link?.name || publicBooking.link_name || '',
    };
});

const canTransitionActiveReservationTo = (status) => (
    canUpdateActiveReservationStatus.value
    && activeAllowedStatusTransitions.value.includes(status)
);
const cancelActionLabel = computed(() =>
    ['pending', 'rescheduled'].includes(String(activeReservation.value?.status || ''))
        ? t('reservations.actions.decline')
        : t('reservations.actions.cancel')
);

const persistCalendarNavigation = () => {
    if (typeof window === 'undefined') {
        return;
    }

    const url = reservationCalendarUrl(window.location.href, { view: calendarView.value, date: calendarDate.value });
    const currentUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`;
    if (url === currentUrl) {
        return;
    }

    router.replace({
        url,
        props: (current) => ({
            ...current,
            filters: { ...current.filters, calendar_view: calendarView.value, calendar_date: calendarDate.value },
        }),
        preserveState: true,
        preserveScroll: true,
    });
};

const loadEvents = async ({ force = false } = {}) => {
    if (!calendarVisible.value || !calendarRange.value.start || !calendarRange.value.end) {
        return;
    }

    const params = {
        start: calendarRange.value.start,
        end: calendarRange.value.end,
        ...reservationFilterPayload(filterForm),
    };
    const requestKey = JSON.stringify(params);
    if (!force && calendarRequestKey === requestKey) {
        return;
    }

    calendarAbortController?.abort();
    const controller = new AbortController();
    const requestSequence = ++calendarRequestSequence;
    calendarAbortController = controller;
    calendarRequestKey = requestKey;
    calendarLoading.value = true;
    calendarError.value = '';

    try {
        const response = await axios.get(route('reservation.events'), {
            signal: controller.signal,
            params,
        });

        if (requestSequence !== calendarRequestSequence) {
            return;
        }

        calendarEvents.value = response?.data?.events || [];
    } catch (error) {
        if (axios.isCancel(error) || error?.code === 'ERR_CANCELED' || requestSequence !== calendarRequestSequence) {
            return;
        }

        calendarRequestKey = '';
        calendarError.value = error?.response?.data?.message || t('reservations.errors.load_events');
    } finally {
        if (requestSequence === calendarRequestSequence) {
            calendarAbortController = null;
            calendarLoading.value = false;
        }
    }
};

const refreshList = (overrides = {}) => {
    if (filterTimer) {
        clearTimeout(filterTimer);
        filterTimer = null;
    }
    filterForm.view_mode = viewMode.value;
    const reason = overrides.reason || 'mutation';
    const payload = reservationFilterPayload(filterForm);
    const previousPayload = reservationFilterPayload(props.filters);
    const changedFilters = reservationFilterFields.filter(
        (field) => JSON.stringify(payload[field]) !== JSON.stringify(previousPayload[field])
    );
    const tracksReservationList = activeDataTab.value === 'reservations' && viewMode.value === 'list';
    const requestSequence = ++listRequestSequence;
    const requestedPerPage = resolveDataTablePerPage(overrides?.per_page, currentPerPage.value);

    listCancelToken?.cancel();
    listLoading.value = tracksReservationList;
    filtersLoading.value = true;
    if (tracksReservationList) {
        listError.value = '';
    }

    router.get(
        route('reservation.index'),
        {
            ...payload,
            sort: filterForm.sort || undefined,
            view_mode: viewMode.value,
            calendar_view: calendarView.value,
            calendar_date: calendarDate.value,
            data_tab: activeDataTab.value,
            per_page: requestedPerPage,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: overrides.replace ?? true,
            only: reservationReloadProps({ tab: activeDataTab.value, view: viewMode.value, reason, changedFilters }),
            onCancelToken: (token) => { listCancelToken = token; },
            onError: () => {
                if (tracksReservationList && requestSequence === listRequestSequence) {
                    listError.value = t('reservations.errors.load_list');
                }
            },
            onFinish: () => {
                if (requestSequence === listRequestSequence) {
                    listLoading.value = false;
                    filtersLoading.value = false;
                    listCancelToken = null;
                    persistCalendarNavigation();
                }
            },
        }
    );

    if (reason === 'mutation' || changedFilters.length > 0) {
        loadEvents({ force: reason === 'mutation' });
    }
};

const stopQueueStripeStatusPolling = () => {
    if (queueStripeStatusTimer) {
        clearTimeout(queueStripeStatusTimer);
        queueStripeStatusTimer = null;
    }
};

const scheduleQueueStripeStatusPoll = (delay = 2500) => {
    stopQueueStripeStatusPolling();

    if (queueStripeStatusPolls >= 120) {
        return;
    }

    queueStripeStatusTimer = setTimeout(pollQueueStripeStatus, Math.max(1500, Number(delay) || 2500));
};

const pollQueueStripeStatus = async () => {
    if (!queueStripeReturn.attemptId || queueStripeReturn.status !== 'pending') {
        return;
    }

    queueStripeStatusPolls += 1;

    try {
        const response = await axios.get(route('reservation.queue.stripe.status', queueStripeReturn.attemptId));
        const payload = response?.data || {};

        if (payload.state === 'success') {
            queueActionError.value = '';
            queueActionWarning.value = '';
            queueActionSuccess.value = t('reservations.queue.checkout.stripe_return.success');
            queueReceiptUrl.value = payload?.invoice?.receipt_url || '';
            stopQueueStripeStatusPolling();
            refreshList();
            return;
        }

        if (payload.state === 'cancel') {
            queueActionError.value = '';
            queueActionSuccess.value = '';
            queueActionWarning.value = t('reservations.queue.checkout.stripe_return.cancelled');
            stopQueueStripeStatusPolling();
            return;
        }

        if (payload.state === 'error') {
            queueActionSuccess.value = '';
            queueActionWarning.value = '';
            queueActionError.value = t('reservations.queue.checkout.stripe_return.error');
            stopQueueStripeStatusPolling();
            return;
        }

        queueActionWarning.value = t('reservations.queue.checkout.stripe_return.pending');
        scheduleQueueStripeStatusPoll(payload.poll_after_ms);
    } catch (error) {
        const payload = error?.response?.data || {};
        if (payload.state === 'pending' || Number(error?.response?.status || 0) >= 500) {
            queueActionWarning.value = t('reservations.queue.checkout.stripe_return.pending');
            scheduleQueueStripeStatusPoll(payload.poll_after_ms);
            return;
        }

        queueActionSuccess.value = '';
        queueActionWarning.value = '';
        queueActionError.value = t('reservations.queue.checkout.stripe_return.error');
        stopQueueStripeStatusPolling();
    }
};

onMounted(() => {
    window.addEventListener('popstate', cancelPendingFilterRequests);
    if (queueStripeReturn.status === 'pending' && queueStripeReturn.attemptId) {
        scheduleQueueStripeStatusPoll(500);
    }

    const requestedCustomerExists = (props.clients || []).some(
        (client) => Number(client.id) === reservationCreateRequest.customerId
    );

    if (reservationCreateRequest.shouldOpen && typeof window !== 'undefined') {
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.delete('customer_id');
        currentUrl.searchParams.delete('open_editor');
        window.history.replaceState(
            window.history.state,
            '',
            `${currentUrl.pathname}${currentUrl.search}${currentUrl.hash}`
        );
    }

    if (reservationCreateRequest.shouldOpen
        && reservationCreateRequest.customerId > 0
        && requestedCustomerExists
        && canManageReservationActions.value) {
        activeDataTab.value = 'reservations';
        openCreate();
        reservationForm.client_id = String(reservationCreateRequest.customerId);
    }
});

let filterTimer = null;
const cancelPendingFilterRequests = () => {
    clearTimeout(filterTimer);
    filterTimer = null;
    listRequestSequence += 1;
    listCancelToken?.cancel();
    listCancelToken = null;
    listLoading.value = false;
    filtersLoading.value = false;
    calendarRequestSequence += 1;
    calendarAbortController?.abort();
    calendarAbortController = null;
    calendarRequestKey = '';
    calendarLoading.value = false;
};

watch(() => props.filters, async (filters) => {
    if (filtersLoading.value || filterTimer) {
        return;
    }
    synchronizingFilters = true;
    viewMode.value = filters?.view_mode || 'calendar';
    activeDataTab.value = filters?.data_tab || 'reservations';
    calendarView.value = filters?.calendar_view || 'week';
    calendarDate.value = filters?.calendar_date || currentReservationDay(dayjs(), props.timezone).format('YYYY-MM-DD');
    Object.assign(filterForm, createReservationAdvancedFilters(filters, ownTeamMemberId.value), {
        search: filters?.search ?? '',
        scope: filters?.scope ?? (ownTeamMemberId.value ? 'mine' : 'all'),
        quick_filters: initialReservationQuickFilters(filters),
        quick_filter_mode: normalizeReservationQuickFilterMode(filters?.quick_filter_mode),
        sort: filters?.sort ?? 'date_asc',
    });
    await nextTick();
    synchronizingFilters = false;
    loadEvents();
});

watch(
    () => [...reservationFilterFields.map((field) => filterForm[field]), filterForm.sort],
    (next, previous) => {
        if (synchronizingFilters) {
            return;
        }
        if (filterTimer) {
            clearTimeout(filterTimer);
        }
        const onlySortChanged = next.slice(0, -1).every((value, index) => value === previous[index]);
        filterTimer = setTimeout(() => refreshList({ reason: onlySortChanged ? 'ordering' : 'filters' }), 300);
    }
);

watch([viewMode, activeDataTab], () => {
    if (synchronizingFilters) {
        return;
    }
    if (!calendarVisible.value) {
        calendarRequestSequence += 1;
        calendarAbortController?.abort();
        calendarAbortController = null;
        calendarRequestKey = '';
        calendarLoading.value = false;
    }
    refreshList({ reason: 'navigation' });
});

watch([calendarView, calendarDate], () => {
    if (!synchronizingFilters) {
        persistCalendarNavigation();
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('popstate', cancelPendingFilterRequests);
    if (filterTimer) {
        clearTimeout(filterTimer);
    }

    listRequestSequence += 1;
    listCancelToken?.cancel();
    calendarRequestSequence += 1;
    calendarAbortController?.abort();
    detailsAbortController?.abort();
    conversionAbortController?.abort();
    stopQueueStripeStatusPolling();
    teardownQueueActionListeners();
});

watch(
    () => props.waitlists,
    (value) => {
        waitlistRows.value = [...(value || [])];
    }
);

watch(
    () => props.queueItems,
    (value) => {
        queueRows.value = [...(value || [])];
    }
);

const clearFilters = () => {
    updateReservationFilters(() => {
        Object.assign(filterForm, createReservationAdvancedFilters({ scope: filterForm.scope }, ownTeamMemberId.value), {
            search: '', quick_filters: [], quick_filter_mode: 'all', sort: 'date_asc',
        });
    });
};

const setReservationSort = (column) => {
    filterForm.sort = nextReservationListSort(filterForm.sort, column);
};

const setReservationSortValue = (sort) => {
    filterForm.sort = reservationListSortValue(
        reservationListSortColumn(sort),
        reservationListSortDirection(sort),
    );
};

const setReservationPerPage = (perPage) => {
    const normalizedPerPage = resolveDataTablePerPage(perPage, currentPerPage.value);

    if (normalizedPerPage !== currentPerPage.value) {
        refreshList({ per_page: normalizedPerPage, reason: 'ordering' });
    }
};

const toggleDateSort = () => setReservationSort('date');
const setStatusSort = () => {
    setReservationSort('status');
};

const onCalendarRangeChange = (payload) => {
    calendarRange.value = {
        start: payload.start,
        end: payload.end,
    };
    persistCalendarNavigation();
    loadEvents();
};

const openCreate = () => {
    if (!canManageReservationActions.value) {
        return;
    }
    activeReservation.value = null;
    reservationForm.reset();
    reservationForm.clearErrors();
    reservationForm.status = props.defaults?.status || 'confirmed';
    reservationForm.duration_minutes = props.defaults?.duration_minutes || 60;
    reservationForm.timezone = props.timezone || 'UTC';
    reservationCustomerMode.value = 'existing';
    customerCreationProcessing.value = false;
    showEditor.value = true;
};

const openEdit = (reservation) => {
    if (!canManageReservationActions.value) {
        return;
    }
    activeReservation.value = reservation;
    reservationForm.clearErrors();
    reservationForm.team_member_id = reservation?.team_member_id ? String(reservation.team_member_id) : '';
    reservationForm.client_id = reservation?.client_id ? String(reservation.client_id) : '';
    reservationForm.service_id = reservation?.service_id ? String(reservation.service_id) : '';
    reservationForm.status = reservation?.status || 'pending';
    reservationForm.starts_at = toLocalInput(reservation?.starts_at);
    reservationForm.ends_at = toLocalInput(reservation?.ends_at);
    reservationForm.duration_minutes = reservation?.duration_minutes || 60;
    reservationForm.internal_notes = reservation?.internal_notes || '';
    reservationForm.client_notes = reservation?.client_notes || '';
    reservationForm.timezone = reservation?.timezone || props.timezone || 'UTC';
    reservationCustomerMode.value = 'existing';
    customerCreationProcessing.value = false;
    showEditor.value = true;
};

const closeEditor = () => {
    if (reservationForm.processing || customerCreationProcessing.value) {
        return;
    }

    showEditor.value = false;
    reservationCustomerMode.value = 'existing';
};

const handleCustomerCreated = (payload) => {
    const customer = payload?.customer;
    if (!customer?.id) {
        return;
    }

    const existingIndex = localClients.value.findIndex((item) => Number(item.id) === Number(customer.id));
    if (existingIndex >= 0) {
        localClients.value.splice(existingIndex, 1, customer);
    } else {
        localClients.value.unshift(customer);
    }

    reservationForm.client_id = String(customer.id);
    reservationForm.clearErrors('client_id');
    reservationCustomerMode.value = 'existing';
};

const handleRebook = async (template) => {
    const serviceId = Number(template?.service?.id || 0);
    const teamMemberId = Number(template?.team_member?.id || 0);
    const durationMinutes = Number(template?.duration_minutes || 0);
    const serviceIsAvailable = template?.service?.is_available === true
        && (props.services || []).some((service) => Number(service.id) === serviceId);
    const teamMemberIsAvailable = template?.team_member?.is_available === true
        && (props.teamMembers || []).some((member) => Number(member.id) === teamMemberId);

    reservationForm.service_id = serviceIsAvailable ? String(serviceId) : '';
    reservationForm.team_member_id = teamMemberIsAvailable ? String(teamMemberId) : '';
    reservationForm.duration_minutes = durationMinutes > 0
        ? durationMinutes
        : (props.defaults?.duration_minutes || 60);
    reservationForm.starts_at = '';
    reservationForm.ends_at = '';
    reservationForm.clearErrors(
        'service_id',
        'team_member_id',
        'duration_minutes',
        'starts_at',
        'ends_at'
    );

    await nextTick();
    reservationStartsAtField.value?.focus();
};

const submitReservation = () => {
    if (!canManageReservationActions.value) {
        return;
    }
    const onSuccess = () => {
        showEditor.value = false;
        refreshList();
    };

    reservationForm.transform((data) => ({
        ...data,
        team_member_id: data.team_member_id ? Number(data.team_member_id) : null,
        client_id: data.client_id ? Number(data.client_id) : null,
        service_id: data.service_id ? Number(data.service_id) : null,
        duration_minutes: data.duration_minutes ? Number(data.duration_minutes) : null,
    }));

    if (activeReservation.value?.id) {
        reservationForm.put(route('reservation.update', activeReservation.value.id), {
            preserveScroll: true,
            onSuccess,
        });
        return;
    }

    reservationForm.post(route('reservation.store'), {
        preserveScroll: true,
        onSuccess,
    });
};

const resetConversionState = () => {
    conversionMutationSequence += 1;
    conversionAbortController?.abort();
    conversionAbortController = null;
    detailsActionLoading.value = false;
    conversionLoading.value = false;
    conversionSubmitting.value = false;
    detailsActionError.value = '';
    conversionError.value = '';
    conversionSuccess.value = '';
    conversionPayload.value = null;
    conversionForm.clearErrors();
};

const loadReservationDetails = async (reservationId) => {
    const id = Number(reservationId || 0);
    if (!id) {
        return;
    }

    detailsAbortController?.abort();
    detailsAbortController = new AbortController();
    const requestSequence = ++detailsRequestSequence;
    detailsLoading.value = true;
    detailsLoadError.value = '';
    let shouldLoadConversion = false;

    try {
        const response = await axios.get(route('reservation.show', id), {
            signal: detailsAbortController.signal,
        });
        const reservation = response?.data?.reservation;

        if (!reservation || requestSequence !== detailsRequestSequence) {
            return;
        }

        activeReservation.value = {
            ...(activeReservation.value || {}),
            ...reservation,
        };
        shouldLoadConversion = Boolean(
            reservation?.permissions?.can_convert
            && reservation?.prospect_id
            && !reservation?.client_id
        );
    } catch (error) {
        if (axios.isCancel(error) || error?.code === 'ERR_CANCELED' || requestSequence !== detailsRequestSequence) {
            return;
        }

        detailsLoadError.value = error?.response?.data?.message || t('reservations.details.error');
    } finally {
        if (requestSequence === detailsRequestSequence) {
            detailsLoading.value = false;
        }
    }

    if (shouldLoadConversion && requestSequence === detailsRequestSequence && showDetails.value) {
        loadPublicBookingConversion();
    }
};

const openDetails = (reservation) => {
    const id = Number(reservation?.id || 0);
    if (!id || !reservationListCanView(reservation)) {
        return;
    }

    resetConversionState();
    detailsLoadError.value = '';
    activeReservation.value = { ...(reservation || {}), id };
    showDetails.value = true;
    loadReservationDetails(id);
};

const retryReservationDetails = () => {
    loadReservationDetails(activeReservation.value?.id);
};

const closeDetails = (force = false) => {
    if (detailsActionLoading.value && !force) {
        return;
    }

    detailsRequestSequence += 1;
    conversionMutationSequence += 1;
    detailsAbortController?.abort();
    conversionAbortController?.abort();
    detailsAbortController = null;
    conversionAbortController = null;
    detailsLoading.value = false;
    conversionSubmitting.value = false;
    showDetails.value = false;
};

const hydrateConversionForm = () => {
    const contact = publicBookingContact.value;
    conversionForm.clearErrors();
    conversionForm.mode = conversionPayload.value?.default_mode || ((conversionPayload.value?.matches || []).length ? 'link_existing' : 'create_new');
    conversionForm.customer_id = conversionPayload.value?.matches?.[0]?.id ? String(conversionPayload.value.matches[0].id) : '';
    conversionForm.contact_name = contact.name;
    conversionForm.contact_email = contact.email;
    conversionForm.contact_phone = contact.phone;
    conversionForm.company_name = contact.name;
};

const loadPublicBookingConversion = async () => {
    if (!activeReservation.value?.id || !canConvertActiveReservation.value) {
        return;
    }

    conversionLoading.value = true;
    conversionError.value = '';
    conversionAbortController?.abort();
    const controller = new AbortController();
    const reservationId = Number(activeReservation.value.id);
    conversionAbortController = controller;

    try {
        const response = await axios.get(route('reservation.public-booking-conversion.show', reservationId), {
            signal: controller.signal,
        });
        if (controller.signal.aborted || Number(activeReservation.value?.id) !== reservationId || !showDetails.value) {
            return;
        }
        conversionPayload.value = {
            ...response?.data,
            default_mode: (response?.data?.matches || []).length ? 'link_existing' : 'create_new',
        };
        hydrateConversionForm();
    } catch (error) {
        if (axios.isCancel(error) || error?.code === 'ERR_CANCELED') {
            return;
        }
        conversionError.value = error?.response?.data?.message || t('reservations.details.conversion.errors.load');
    } finally {
        if (conversionAbortController === controller) {
            conversionAbortController = null;
            conversionLoading.value = false;
        }
    }
};

const convertPublicBooking = async (mode, customerId = null) => {
    if (!activeReservation.value?.id || !canConvertActiveReservation.value || conversionSubmitting.value) {
        return;
    }

    const reservationId = Number(activeReservation.value.id);
    const requestSequence = ++conversionMutationSequence;
    conversionSubmitting.value = true;
    conversionError.value = '';
    conversionSuccess.value = '';
    conversionForm.clearErrors();

    const payload = {
        mode,
        customer_id: customerId || conversionForm.customer_id || null,
        contact_name: conversionForm.contact_name || publicBookingContact.value.name || null,
        contact_email: conversionForm.contact_email || publicBookingContact.value.email || null,
        contact_phone: conversionForm.contact_phone || publicBookingContact.value.phone || null,
        company_name: conversionForm.company_name || null,
    };

    try {
        const response = await axios.post(route('reservation.public-booking-conversion.store', reservationId), payload);
        if (
            requestSequence !== conversionMutationSequence
            || Number(activeReservation.value?.id) !== reservationId
            || !showDetails.value
        ) {
            refreshList();
            return;
        }
        activeReservation.value = {
            ...(activeReservation.value || {}),
            ...(response?.data?.reservation || {}),
            permissions: {
                ...(activeReservation.value?.permissions || {}),
                can_convert: false,
            },
        };
        conversionSuccess.value = t('reservations.details.conversion.success.converted');
        conversionPayload.value = {
            ...(conversionPayload.value || {}),
            already_converted: true,
            matches: response?.data?.matches || conversionPayload.value?.matches || [],
        };
        await loadReservationDetails(activeReservation.value.id);
        refreshList();
    } catch (error) {
        if (requestSequence !== conversionMutationSequence) {
            return;
        }
        if (error?.response?.status === 422) {
            conversionForm.setError(error.response.data?.errors || {});
            conversionError.value = firstValidationMessage(error.response.data?.errors || {}) || t('reservations.details.conversion.errors.convert');
        } else {
            conversionError.value = error?.response?.data?.message || t('reservations.details.conversion.errors.convert');
        }
    } finally {
        if (requestSequence === conversionMutationSequence) {
            conversionSubmitting.value = false;
        }
    }
};

watch(
    () => [focusReservationId.value, reservationRows.value.map((reservation) => reservation.id).join(',')],
    () => {
        const id = focusReservationId.value;
        if (!id || lastFocusedReservationId.value === id) {
            return;
        }

        activeDataTab.value = 'reservations';
        viewMode.value = 'list';
        lastFocusedReservationId.value = id;
        openDetails(reservationMap.value.get(id) || { id });
    },
    { immediate: true }
);

const openFromEvent = (rawEvent) => {
    const eventId = Number(rawEvent?.id || rawEvent?.original?.id || 0);
    const source = rawEvent?.original || rawEvent;

    const fallback = {
        id: eventId,
        status: source?.extendedProps?.status,
        starts_at: source?.start,
        ends_at: source?.end,
        service: { name: source?.extendedProps?.service_name },
        teamMember: { user: { name: source?.extendedProps?.team_member_name } },
    };

    openDetails(reservationMap.value.get(eventId) || fallback);
};

const updateStatus = async (status) => {
    if (!activeReservation.value?.id) {
        return;
    }
    if (!canTransitionActiveReservationTo(status) || detailsActionLoading.value) {
        return;
    }

    detailsActionError.value = '';
    detailsActionLoading.value = true;

    try {
        await axios.patch(route('reservation.status', activeReservation.value.id), { status });
        closeDetails(true);
        refreshList();
    } catch (error) {
        detailsActionError.value = error?.response?.data?.message || t('reservations.errors.update_status');
    } finally {
        detailsActionLoading.value = false;
    }
};

const updateReservationStatusFromList = async (reservation, status) => {
    const reservationId = Number(reservation?.id || 0);
    const allowedTransitions = reservationListAllowedStatusTransitions(reservation);

    if (
        !reservationId
        || !reservationListCanUpdateStatus(reservation)
        || !allowedTransitions.includes(status)
        || listStatusUpdatingId.value !== null
    ) {
        return;
    }

    if (
        status === 'cancelled'
        && typeof window !== 'undefined'
        && !window.confirm(t('reservations.actions.cancel_confirm', { reference: `#${reservationId}` }))
    ) {
        return;
    }

    listStatusActionError.value = '';
    listStatusUpdatingId.value = reservationId;

    try {
        await axios.patch(route('reservation.status', reservationId), { status });
        refreshList();
    } catch (error) {
        listStatusActionError.value = firstValidationMessage(error?.response?.data?.errors)
            || error?.response?.data?.message
            || t('reservations.errors.update_status');
    } finally {
        listStatusUpdatingId.value = null;
    }
};

const updateWaitlistStatus = async (entry, status) => {
    if (!entry?.id || !entry?.can_update_status) {
        return;
    }

    waitlistActionError.value = '';
    waitlistActionSuccess.value = '';
    waitlistUpdatingId.value = Number(entry.id);

    try {
        const response = await axios.patch(route('reservation.waitlist.status', entry.id), {
            status,
        });

        const updated = response?.data?.waitlist || { ...entry, status };
        waitlistRows.value = waitlistRows.value.map((row) => (
            Number(row.id) === Number(entry.id) ? updated : row
        ));
        waitlistActionSuccess.value = response?.data?.message || t('reservations.waitlist.actions.updated');
    } catch (error) {
        waitlistActionError.value = error?.response?.data?.message || t('reservations.waitlist.actions.update_error');
    } finally {
        waitlistUpdatingId.value = null;
    }
};

const queueActionRouteName = (action) => {
    if (action === 'check-in') {
        return 'reservation.queue.check-in';
    }
    if (action === 'pre-call') {
        return 'reservation.queue.pre-call';
    }
    if (action === 'call') {
        return 'reservation.queue.call';
    }
    if (action === 'start') {
        return 'reservation.queue.start';
    }
    if (action === 'done') {
        return 'reservation.queue.done';
    }
    if (action === 'finish') {
        return 'reservation.queue.finish';
    }
    return 'reservation.queue.skip';
};

const queueAssignmentPayload = (item) => {
    const payload = {};
    if (!item?.team_member_id && ownTeamMemberId.value) {
        payload.team_member_id = Number(ownTeamMemberId.value);
    } else if (!item?.team_member_id && item?.recommended_team_member_id) {
        payload.team_member_id = Number(item.recommended_team_member_id);
    }

    return payload;
};

const queueAvailabilityConfirmationFromError = (error) => {
    const response = error?.response;
    const confirmation = response?.data?.availability_confirmation;

    if (
        Number(response?.status) !== 409
        || response?.data?.code !== 'queue_team_member_availability_confirmation_required'
        || !confirmation
    ) {
        return null;
    }

    return confirmation;
};

const openQueueAvailabilityConfirmation = (error, pendingAction) => {
    const confirmation = queueAvailabilityConfirmationFromError(error);
    if (!confirmation) {
        return false;
    }

    pendingQueueAvailabilityConfirmation.value = {
        ...pendingAction,
        teamMemberId: confirmation.team_member_id,
        teamMemberName: confirmation.team_member_name || '',
    };
    showQueueAvailabilityConfirmation.value = true;

    return true;
};

const closeQueueAvailabilityConfirmation = () => {
    if (queueAvailabilityConfirmationProcessing.value) {
        return;
    }

    showQueueAvailabilityConfirmation.value = false;
    pendingQueueAvailabilityConfirmation.value = null;
};

const setQueueActionButtonRef = (itemId, element) => {
    if (element) {
        queueActionButtonRefs.value[itemId] = element;
        return;
    }

    delete queueActionButtonRefs.value[itemId];
};

const updateQueueActionMenuPosition = async () => {
    if (!openQueueActionsFor.value) {
        return;
    }

    await nextTick();

    const button = queueActionButtonRefs.value[openQueueActionsFor.value];
    const menu = queueActionMenuRef.value;
    if (!button || !menu) {
        return;
    }

    const buttonRect = button.getBoundingClientRect();
    const menuRect = menu.getBoundingClientRect();
    const padding = 12;
    const left = Math.max(padding, Math.min(
        buttonRect.right - menuRect.width,
        window.innerWidth - menuRect.width - padding
    ));
    const belowTop = buttonRect.bottom + 8;
    const top = belowTop + menuRect.height <= window.innerHeight - padding
        ? belowTop
        : Math.max(padding, buttonRect.top - menuRect.height - 8);

    queueActionMenuStyle.value = {
        left: `${left}px`,
        top: `${top}px`,
    };
};

const teardownQueueActionListeners = () => {
    if (!queueActionListenersBound) {
        return;
    }

    document.removeEventListener('click', handleQueueActionDocumentClick, true);
    document.removeEventListener('keydown', handleQueueActionKeydown, true);
    window.removeEventListener('resize', updateQueueActionMenuPosition);
    window.removeEventListener('scroll', updateQueueActionMenuPosition, true);
    queueActionListenersBound = false;
};

const closeQueueActions = () => {
    openQueueActionsFor.value = null;
    queueActionMenuStyle.value = {};
    teardownQueueActionListeners();
};

const setQueueViewMode = (mode) => {
    if (!queueViewModes.includes(mode) || queueViewMode.value === mode) {
        return;
    }

    closeQueueActions();
    queueViewMode.value = mode;

    if (typeof window !== 'undefined') {
        window.localStorage.setItem('reservation_queue_view_mode', mode);
    }
};

const setQueueQuickFilter = (value) => {
    closeQueueActions();
    queueQuickFilter.value = normalizeReservationQueueQuickFilter(value);

    if (typeof window !== 'undefined') {
        window.localStorage.setItem('reservation_queue_quick_filter', queueQuickFilter.value);
    }
};

const openQueueReservation = (item) => {
    const reservationId = Number(item?.reservation_id || 0);
    if (!reservationId) {
        return;
    }

    closeQueueActions();
    openDetails(reservationMap.value.get(reservationId) || { id: reservationId });
};

const handleQueueActionDocumentClick = (event) => {
    const button = openQueueActionsFor.value
        ? queueActionButtonRefs.value[openQueueActionsFor.value]
        : null;

    if (button?.contains(event.target) || queueActionMenuRef.value?.contains(event.target)) {
        return;
    }

    closeQueueActions();
};

const handleQueueActionKeydown = (event) => {
    if (event.key === 'Escape') {
        const trigger = openQueueActionsFor.value
            ? queueActionButtonRefs.value[openQueueActionsFor.value]
            : null;
        closeQueueActions();
        trigger?.focus();
        return;
    }

    if (!queueActionMenuRef.value?.contains(event.target)) {
        return;
    }

    const menuItems = Array.from(queueActionMenuRef.value.querySelectorAll('[role="menuitem"]:not([disabled])'));
    if (!menuItems.length || !['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
        return;
    }

    event.preventDefault();
    const currentIndex = menuItems.indexOf(document.activeElement);
    const nextIndex = event.key === 'Home'
        ? 0
        : (event.key === 'End'
            ? menuItems.length - 1
            : (event.key === 'ArrowUp'
                ? (currentIndex <= 0 ? menuItems.length - 1 : currentIndex - 1)
                : (currentIndex + 1) % menuItems.length));
    menuItems[nextIndex]?.focus();
};

const toggleQueueActions = async (itemId) => {
    const normalizedId = Number(itemId);

    openQueueActionsFor.value = openQueueActionsFor.value === normalizedId ? null : normalizedId;

    if (!openQueueActionsFor.value) {
        teardownQueueActionListeners();
        return;
    }

    await updateQueueActionMenuPosition();

    if (!queueActionListenersBound) {
        document.addEventListener('click', handleQueueActionDocumentClick, true);
        document.addEventListener('keydown', handleQueueActionKeydown, true);
        window.addEventListener('resize', updateQueueActionMenuPosition);
        window.addEventListener('scroll', updateQueueActionMenuPosition, true);
        queueActionListenersBound = true;
    }

    queueActionMenuRef.value?.querySelector('[role="menuitem"]:not([disabled])')?.focus();
};

const updateQueueStatus = async (item, action, options = {}) => {
    if (!item?.id || !item?.can_update_status) {
        return;
    }

    const originalPayload = { ...(options.payload || queueAssignmentPayload(item)) };
    const payload = options.confirmTeamMemberAvailable
        ? { ...originalPayload, confirm_team_member_available: true }
        : originalPayload;

    closeQueueActions();
    queueActionError.value = '';
    queueActionSuccess.value = '';
    queueActionWarning.value = '';
    queueUpdatingId.value = Number(item.id);

    try {
        const response = await axios.patch(route(queueActionRouteName(action), item.id), payload);
        const updated = response?.data?.queue_item || { ...item };
        queueRows.value = queueRows.value.map((row) => (
            Number(row.id) === Number(item.id) ? { ...row, ...updated } : row
        ));
        queueActionSuccess.value = response?.data?.message || t('reservations.queue.actions.updated');
        refreshList();
    } catch (error) {
        const requiresAvailabilityConfirmation = !options.confirmTeamMemberAvailable
            && ['pre-call', 'call'].includes(action)
            && openQueueAvailabilityConfirmation(error, {
                type: 'status',
                item,
                action,
                payload: originalPayload,
            });

        if (requiresAvailabilityConfirmation) {
            return;
        }

        queueActionError.value = error?.response?.data?.message || t('reservations.queue.actions.update_error');
    } finally {
        queueUpdatingId.value = null;
    }
};

const runQueuePrimaryAction = (item) => {
    const action = queuePrimaryActionFor(item);

    if (['finish', 'checkout'].includes(action)) {
        openQueueCheckout(item);
        return;
    }

    if (action) {
        updateQueueStatus(item, action.replace('_', '-'));
    }
};

const resetQueueCheckout = (item) => {
    activeQueueCheckoutItem.value = item || null;
    queueCheckoutError.value = '';
    queueReceiptUrl.value = '';
    queueCheckoutForm.value = {
        method: defaultPaymentMethod.value,
        reference: '',
        notes: '',
        receipt_delivery: '',
    };
    queueTipEnabled.value = false;
    queueTipMode.value = 'percent';
    queueTipPercent.value = Number(props.tips?.default_percent ?? 10);
    queueTipFixedAmount.value = 0;
};

const closeQueueCheckout = () => {
    showQueueCheckout.value = false;
    activeQueueCheckoutItem.value = null;
    queueCheckoutError.value = '';
};

const openQueueCheckout = async (item) => {
    if (!item?.id || !item?.can_update_status || queueCheckoutProcessing.value) {
        return;
    }

    closeQueueActions();
    queueActionError.value = '';
    queueActionSuccess.value = '';
    queueActionWarning.value = '';
    queueCheckoutError.value = '';
    let checkoutItem = item;

    try {
        if (['called', 'in_service'].includes(String(item.status || ''))) {
            queueUpdatingId.value = Number(item.id);
            const response = await axios.patch(
                route('reservation.queue.finish', item.id),
                queueAssignmentPayload(item)
            );
            const updated = response?.data?.queue_item || { ...item };
            checkoutItem = { ...item, ...updated };
            queueRows.value = queueRows.value.map((row) => (
                Number(row.id) === Number(item.id) ? checkoutItem : row
            ));

            if (checkoutItem.status === 'done') {
                queueActionSuccess.value = response?.data?.message || t('reservations.queue.checkout.free_completed');
                refreshList();
                return;
            }
        }

        if (String(checkoutItem.status || '') !== 'awaiting_payment') {
            throw new Error('queue_checkout_not_ready');
        }

        resetQueueCheckout(checkoutItem);
        showQueueCheckout.value = true;
        refreshList();
    } catch (error) {
        queueActionError.value = error?.response?.data?.message
            || firstValidationMessage(error?.response?.data?.errors)
            || t('reservations.queue.checkout.open_error');
    } finally {
        queueUpdatingId.value = null;
    }
};

const submitQueueCheckout = async () => {
    const item = activeQueueCheckoutItem.value;
    if (!canSubmitQueueCheckout.value || !item?.id || queueCheckoutProcessing.value) {
        return;
    }

    queueCheckoutProcessing.value = true;
    queueCheckoutError.value = '';
    queueActionError.value = '';
    queueActionSuccess.value = '';
    queueActionWarning.value = '';

    try {
        const response = await axios.post(
            route('reservation.queue.checkout', item.id),
            queueCheckoutPayload.value
        );
        const checkoutUrl = response?.data?.checkout_url;
        if (typeof checkoutUrl === 'string' && checkoutUrl) {
            window.location.assign(checkoutUrl);
            return;
        }
        const updated = response?.data?.queue_item || { ...item, status: 'done' };
        queueRows.value = queueRows.value.map((row) => (
            Number(row.id) === Number(item.id) ? { ...row, ...updated } : row
        ));
        queueReceiptUrl.value = response?.data?.invoice?.receipt_url || '';
        queueActionSuccess.value = response?.data?.message || t('reservations.queue.checkout.completed');
        closeQueueCheckout();
        refreshList();
    } catch (error) {
        queueCheckoutError.value = error?.response?.data?.message
            || firstValidationMessage(error?.response?.data?.errors)
            || t('reservations.queue.checkout.submit_error');
    } finally {
        queueCheckoutProcessing.value = false;
    }
};

const queueCallNextPayload = () => {
    const payload = {};
    if (queueAssignmentMode.value === 'per_staff') {
        if (ownTeamMemberId.value) {
            payload.team_member_id = Number(ownTeamMemberId.value);
        } else if (filterForm.team_member_id) {
            payload.team_member_id = Number(filterForm.team_member_id);
        }
    }

    return payload;
};

const callNextQueueItem = async (options = {}) => {
    queueActionError.value = '';
    queueActionSuccess.value = '';
    queueActionWarning.value = '';
    queueCallingNext.value = true;
    const originalPayload = { ...(options.payload || queueCallNextPayload()) };
    const payload = options.confirmTeamMemberAvailable
        ? { ...originalPayload, confirm_team_member_available: true }
        : originalPayload;

    try {
        const response = await axios.post(route('reservation.queue.call-next'), payload);
        const updated = response?.data?.queue_item;
        if (updated?.id) {
            const hasExisting = queueRows.value.some((row) => Number(row.id) === Number(updated.id));
            if (hasExisting) {
                queueRows.value = queueRows.value.map((row) => (
                    Number(row.id) === Number(updated.id) ? { ...row, ...updated } : row
                ));
            } else {
                queueRows.value = [{ ...updated }, ...queueRows.value];
            }
        }

        queueActionSuccess.value = response?.data?.message || t('reservations.queue.actions.updated');
        refreshList();
    } catch (error) {
        const requiresAvailabilityConfirmation = !options.confirmTeamMemberAvailable
            && openQueueAvailabilityConfirmation(error, {
                type: 'call-next',
                payload: originalPayload,
            });

        if (requiresAvailabilityConfirmation) {
            return;
        }

        queueActionError.value = error?.response?.data?.message || t('reservations.queue.actions.call_next_empty');
    } finally {
        queueCallingNext.value = false;
    }
};

const confirmQueueMemberAvailability = async () => {
    const pendingAction = pendingQueueAvailabilityConfirmation.value;
    if (!pendingAction || queueAvailabilityConfirmationProcessing.value) {
        return;
    }

    queueAvailabilityConfirmationProcessing.value = true;

    try {
        if (pendingAction.type === 'status') {
            const payload = pendingAction.teamMemberId && !pendingAction.payload?.team_member_id
                ? { ...pendingAction.payload, team_member_id: Number(pendingAction.teamMemberId) }
                : pendingAction.payload;
            await updateQueueStatus(pendingAction.item, pendingAction.action, {
                payload,
                confirmTeamMemberAvailable: true,
            });
            return;
        }

        if (pendingAction.type === 'call-next') {
            const payload = pendingAction.teamMemberId && !pendingAction.payload?.team_member_id
                ? { ...pendingAction.payload, team_member_id: Number(pendingAction.teamMemberId) }
                : pendingAction.payload;
            await callNextQueueItem({
                payload,
                confirmTeamMemberAvailable: true,
            });
        }
    } finally {
        queueAvailabilityConfirmationProcessing.value = false;
        closeQueueAvailabilityConfirmation();
    }
};

const removeReservation = (reservation) => {
    if (!canManageReservationActions.value) {
        return;
    }
    if (!reservation?.id || !window.confirm(t('reservations.actions.delete_confirm'))) {
        return;
    }

    router.delete(route('reservation.destroy', reservation.id), {
        preserveScroll: true,
        onSuccess: refreshList,
    });
};

</script>

<template>
    <Head :title="$t('reservations.title')" />

    <AuthenticatedLayout>
        <div class="space-y-4">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ $t('reservations.title') }}</h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('reservations.subtitle') }}</p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Link
                            v-if="canManage"
                            :href="route('settings.reservations.edit')"
                            class="inline-flex items-center rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            <svg class="me-2 size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="3" />
                                <path d="M12 2v3" />
                                <path d="M12 19v3" />
                                <path d="m4.93 4.93 2.12 2.12" />
                                <path d="m16.95 16.95 2.12 2.12" />
                                <path d="M2 12h3" />
                                <path d="M19 12h3" />
                                <path d="m4.93 19.07 2.12-2.12" />
                                <path d="m16.95 7.05 2.12-2.12" />
                            </svg>
                            {{ $t('settings._label') }}
                        </Link>
                        <button
                            v-if="canManageReservationActions"
                            type="button"
                            class="inline-flex items-center rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700"
                            @click="openCreate"
                        >
                            <svg class="me-2 size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M5 12h14" />
                                <path d="M12 5v14" />
                            </svg>
                            {{ $t('reservations.actions.new') }}
                        </button>
                    </div>
                </div>

                <div
                    v-if="ownerOnlyMode"
                    class="mt-4 rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100"
                >
                    {{ $t('reservations.owner_only.staff_notice') }}
                </div>
            </section>

            <ModuleKpiSection module-key="reservations">
                <ReservationStats :stats="stats" :performance="performance" compact />
            </ModuleKpiSection>

            <section class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="inline-flex items-center rounded-sm border border-stone-200 bg-white p-0.5 text-xs font-semibold text-stone-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5"
                            :class="activeDataTab === 'reservations'
                                ? 'bg-green-600 text-white shadow-sm dark:bg-white dark:text-stone-900'
                                : 'text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100'"
                            data-testid="reservation-tab-reservations"
                            @click="activeDataTab = 'reservations'"
                        >
                            {{ $t('reservations.title') }}
                            <span class="rounded-full bg-black/10 px-1.5 py-0.5 text-[10px] leading-none dark:bg-white/20">
                                {{ reservationTabCount }}
                            </span>
                        </button>
                        <button
                            v-if="hasQueueTab"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5"
                            :class="activeDataTab === 'queue'
                                ? 'bg-green-600 text-white shadow-sm dark:bg-white dark:text-stone-900'
                                : 'text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100'"
                            data-testid="reservation-tab-queue"
                            @click="activeDataTab = 'queue'"
                        >
                            {{ $t('reservations.queue.title') }}
                            <span class="rounded-full bg-black/10 px-1.5 py-0.5 text-[10px] leading-none dark:bg-white/20">
                                {{ queueRows.length }}
                            </span>
                        </button>
                        <button
                            v-if="hasWaitlistTab"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5"
                            :class="activeDataTab === 'waitlist'
                                ? 'bg-green-600 text-white shadow-sm dark:bg-white dark:text-stone-900'
                                : 'text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100'"
                            data-testid="reservation-tab-waitlist"
                            @click="activeDataTab = 'waitlist'"
                        >
                            {{ $t('reservations.waitlist.title') }}
                            <span class="rounded-full bg-black/10 px-1.5 py-0.5 text-[10px] leading-none dark:bg-white/20">
                                {{ waitlistRows.length }}
                            </span>
                        </button>
                    </div>
                </div>
            </section>

            <section
                v-if="activeDataTab === 'queue' && (queueModeEnabled || queueRows.length)"
                class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('reservations.queue.title') }}</h2>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.subtitle') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <div
                            :class="crmSegmentedControlClass()"
                            role="group"
                            :aria-label="$t('reservations.queue.view.label')"
                        >
                            <button
                                type="button"
                                data-testid="reservation-queue-view-table"
                                :class="crmSegmentedControlButtonClass(queueViewMode === 'table')"
                                :aria-pressed="queueViewMode === 'table'"
                                @click="setQueueViewMode('table')"
                            >
                                <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M3 3h18v6H3z" />
                                    <path d="M3 13h18v8H3z" />
                                </svg>
                                {{ $t('reservations.queue.view.table') }}
                            </button>
                            <button
                                type="button"
                                data-testid="reservation-queue-view-cards"
                                :class="crmSegmentedControlButtonClass(queueViewMode === 'cards')"
                                :aria-pressed="queueViewMode === 'cards'"
                                @click="setQueueViewMode('cards')"
                            >
                                <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <rect x="3" y="3" width="7" height="7" rx="1" />
                                    <rect x="14" y="3" width="7" height="7" rx="1" />
                                    <rect x="3" y="14" width="7" height="7" rx="1" />
                                    <rect x="14" y="14" width="7" height="7" rx="1" />
                                </svg>
                                {{ $t('reservations.queue.view.cards') }}
                            </button>
                        </div>
                        <Link
                            :href="route('reservation.screen', { anonymize: 1 })"
                            target="_blank"
                            class="rounded-sm border border-stone-300 px-2 py-0.5 font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            {{ $t('reservations.queue.screen.open') }}
                        </Link>
                        <span class="rounded-full bg-cyan-100 px-2 py-0.5 font-semibold text-cyan-700 dark:bg-cyan-500/10 dark:text-cyan-300">
                            {{ $t(`reservations.queue.assignment_mode.${queueAssignmentMode}`) }}
                        </span>
                        <button
                            type="button"
                            class="rounded-sm bg-emerald-600 px-2.5 py-1 font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                            :disabled="queueCallingNext"
                            @click="callNextQueueItem"
                        >
                            {{ queueCallingNext ? $t('planning.filters.loading') : $t('reservations.queue.actions.call_next') }}
                        </button>
                    </div>
                </div>

                <div
                    class="flex max-w-full gap-2 overflow-x-auto pb-1"
                    role="group"
                    :aria-label="$t('reservations.queue.filters.label')"
                    data-testid="reservation-queue-quick-filters"
                >
                    <button
                        v-for="quickFilter in queueQuickFilters"
                        :key="`reservation-queue-filter-${quickFilter.value}`"
                        type="button"
                        class="inline-flex min-h-9 shrink-0 items-center gap-2 rounded-sm border px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-neutral-800"
                        :class="queueQuickFilter === quickFilter.value
                            ? 'border-emerald-600 bg-emerald-600 text-white dark:border-emerald-500 dark:bg-emerald-500 dark:text-neutral-950'
                            : 'border-stone-200 bg-white text-stone-700 hover:border-emerald-300 hover:bg-emerald-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:border-emerald-700 dark:hover:bg-emerald-500/10'"
                        :aria-pressed="queueQuickFilter === quickFilter.value"
                        :data-testid="`reservation-queue-filter-${quickFilter.value}`"
                        @click="setQueueQuickFilter(quickFilter.value)"
                    >
                        <span>{{ quickFilter.label }}</span>
                        <span
                            class="min-w-5 rounded-sm px-1.5 py-0.5 text-center text-[10px] leading-none"
                            :class="queueQuickFilter === quickFilter.value
                                ? 'bg-black/15 text-current dark:bg-white/20'
                                : 'bg-stone-100 text-stone-600 dark:bg-neutral-800 dark:text-neutral-300'"
                        >
                            {{ quickFilter.count }}
                        </span>
                    </button>
                </div>

                <div v-if="queueActionError" class="mt-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                    {{ queueActionError }}
                </div>
                <div v-if="queueActionWarning" class="mt-3 rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                    {{ queueActionWarning }}
                </div>
                <div v-if="queueActionSuccess" class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
                    <span>{{ queueActionSuccess }}</span>
                    <a
                        v-if="queueReceiptUrl"
                        :href="queueReceiptUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="shrink-0 font-semibold text-emerald-800 underline underline-offset-2 hover:text-emerald-950 dark:text-emerald-200 dark:hover:text-white"
                    >
                        {{ $t('reservations.queue.checkout.receipt.view') }}
                    </a>
                </div>

                <AdminDataTable v-if="queueViewMode === 'table'" embedded :rows="filteredQueueRows" :show-pagination="false">
                    <template #head>
                        <tr>
                            <th scope="col" class="min-w-36 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.queue.columns.ticket') }}
                            </th>
                            <th scope="col" class="min-w-52 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.table.customer') }}
                            </th>
                            <th scope="col" class="min-w-28 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.queue.columns.origin') }}
                            </th>
                            <th scope="col" class="min-w-48 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.table.item') }}
                            </th>
                            <th scope="col" class="min-w-40 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('planning.form.member') }}
                            </th>
                            <th scope="col" class="min-w-32 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.queue.columns.position') }}
                            </th>
                            <th scope="col" class="min-w-28 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.table.status') }}
                            </th>
                            <th scope="col" class="min-w-20 px-5 py-2.5 text-end text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.table.actions') }}
                            </th>
                        </tr>
                    </template>

                    <template #row="{ row: item }">
                        <tr>
                            <td class="size-px whitespace-nowrap px-4 py-2 align-top">
                                <button
                                    v-if="item.reservation_id"
                                    type="button"
                                    class="rounded-sm text-start focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-neutral-800"
                                    :aria-label="queueOpenReservationLabel(item)"
                                    :data-testid="`reservation-queue-open-${item.id}`"
                                    @click="openQueueReservation(item)"
                                >
                                    <span class="block font-medium text-emerald-700 hover:text-emerald-800 dark:text-emerald-300 dark:hover:text-emerald-200">{{ item.queue_number || `#${item.id}` }}</span>
                                    <span class="block text-xs text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.types.appointment') }}</span>
                                </button>
                                <template v-else>
                                    <div class="font-medium text-stone-700 dark:text-neutral-200">{{ item.queue_number || `#${item.id}` }}</div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.types.ticket') }}</div>
                                </template>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">{{ item.client_name || '-' }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                    :class="item.origin === 'booking'
                                        ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300'
                                        : 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'"
                                >
                                    {{ $t(`reservations.queue.origins.${item.origin || (item.item_type === 'appointment' ? 'booking' : 'walk_in')}`) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">{{ item.service_name || '-' }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">{{ item.team_member_name || $t('reservations.client.index.any_available') }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">
                                <div>{{ item.position ?? '-' }}</div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ item.eta_minutes !== null && item.eta_minutes !== undefined ? `${item.eta_minutes} min` : '-' }}
                                </div>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="queueStatusBadgeClass(item.status)">
                                    {{ $t(`reservations.queue.status.${item.status}`) || item.status }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-end align-top">
                                <div v-if="queueRowHasActions(item)" class="inline-flex items-center justify-end gap-1.5">
                                    <button
                                        v-if="queuePrimaryActionFor(item)"
                                        type="button"
                                        class="inline-flex min-h-8 max-w-36 items-center justify-center rounded-sm bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-emerald-700 disabled:pointer-events-none disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:text-neutral-950 dark:hover:bg-emerald-400 dark:focus-visible:ring-offset-neutral-800"
                                        :disabled="queueUpdatingId === Number(item.id)"
                                        :data-testid="`reservation-queue-primary-${item.id}`"
                                        @click="runQueuePrimaryAction(item)"
                                    >
                                        <span class="truncate">{{ queuePrimaryActionLabel(item) }}</span>
                                    </button>
                                    <div v-if="queueRowHasSecondaryActions(item)" class="relative inline-flex">
                                        <button
                                            type="button"
                                            class="size-8 inline-flex items-center justify-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:pointer-events-none disabled:opacity-50 focus:bg-stone-50 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                            aria-haspopup="menu"
                                            :aria-expanded="openQueueActionsFor === Number(item.id)"
                                            :aria-label="$t('reservations.table.actions')"
                                            :disabled="queueUpdatingId === Number(item.id)"
                                            :ref="(element) => setQueueActionButtonRef(item.id, element)"
                                            @click="toggleQueueActions(item.id)"
                                        >
                                            <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <circle cx="12" cy="12" r="1" />
                                                <circle cx="12" cy="5" r="1" />
                                                <circle cx="12" cy="19" r="1" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                                <span v-else class="text-xs text-stone-400 dark:text-neutral-500">-</span>
                            </td>
                        </tr>
                    </template>

                    <template #empty>
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-4 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                            <span>{{ queueQuickFilter === 'all' ? $t('reservations.queue.empty') : $t('reservations.queue.filters.empty') }}</span>
                            <button
                                v-if="queueQuickFilter !== 'all'"
                                type="button"
                                class="font-semibold text-emerald-700 hover:text-emerald-800 dark:text-emerald-300 dark:hover:text-emerald-200"
                                @click="setQueueQuickFilter('all')"
                            >
                                {{ $t('reservations.queue.filters.clear') }}
                            </button>
                        </div>
                    </template>
                </AdminDataTable>

                <div
                    v-else-if="filteredQueueRows.length"
                    data-testid="reservation-queue-card-grid"
                    class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3"
                >
                    <article
                        v-for="item in filteredQueueRows"
                        :key="`queue-card-${item.id}`"
                        class="overflow-hidden rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                        :aria-labelledby="`queue-card-title-${item.id}`"
                    >
                        <header class="flex items-start justify-between gap-3 border-b border-stone-100 px-4 py-3 dark:border-neutral-800">
                            <div class="min-w-0">
                                <p :id="`queue-card-title-${item.id}`" class="truncate text-sm font-semibold text-stone-900 dark:text-white">
                                    {{ item.queue_number || `#${item.id}` }}
                                </p>
                                <p class="mt-0.5 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ item.item_type === 'appointment' ? $t('reservations.queue.types.appointment') : $t('reservations.queue.types.ticket') }}
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1.5">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="queueStatusBadgeClass(item.status)">
                                    {{ $t(`reservations.queue.status.${item.status}`) || item.status }}
                                </span>
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                    :class="item.origin === 'booking'
                                        ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300'
                                        : 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'"
                                >
                                    {{ $t(`reservations.queue.origins.${item.origin || (item.item_type === 'appointment' ? 'booking' : 'walk_in')}`) }}
                                </span>
                            </div>
                        </header>

                        <div class="space-y-3 p-4">
                            <dl class="grid grid-cols-2 gap-3">
                                <div class="col-span-2 rounded-sm bg-stone-50 p-2.5 dark:bg-neutral-800">
                                    <dt class="text-[0.6875rem] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ $t('reservations.table.customer') }}
                                    </dt>
                                    <dd class="mt-1 truncate text-sm font-semibold text-stone-800 dark:text-neutral-100" :title="item.client_name || '-'">
                                        {{ item.client_name || '-' }}
                                    </dd>
                                </div>
                                <div class="min-w-0 rounded-sm bg-stone-50 p-2.5 dark:bg-neutral-800">
                                    <dt class="text-[0.6875rem] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ $t('reservations.table.item') }}
                                    </dt>
                                    <dd class="mt-1 truncate text-xs font-medium text-stone-700 dark:text-neutral-200" :title="item.service_name || '-'">
                                        {{ item.service_name || '-' }}
                                    </dd>
                                </div>
                                <div class="min-w-0 rounded-sm bg-stone-50 p-2.5 dark:bg-neutral-800">
                                    <dt class="text-[0.6875rem] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ $t('planning.form.member') }}
                                    </dt>
                                    <dd class="mt-1 truncate text-xs font-medium text-stone-700 dark:text-neutral-200" :title="item.team_member_name || $t('reservations.client.index.any_available')">
                                        {{ item.team_member_name || $t('reservations.client.index.any_available') }}
                                    </dd>
                                </div>
                                <div class="col-span-2 rounded-sm border border-stone-200 px-3 py-2.5 dark:border-neutral-700">
                                    <dt class="text-[0.6875rem] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ item.reservation_starts_at ? $t('reservations.queue.details.schedule') : $t('reservations.queue.details.check_in') }}
                                    </dt>
                                    <dd class="mt-1 text-xs font-medium text-stone-700 dark:text-neutral-200">
                                        {{ formatQueueSchedule(item) }}
                                    </dd>
                                </div>
                                <div class="rounded-sm border border-stone-200 px-3 py-2.5 dark:border-neutral-700">
                                    <dt class="text-[0.6875rem] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ $t('reservations.queue.columns.position') }}
                                    </dt>
                                    <dd class="mt-1 text-xs font-medium text-stone-700 dark:text-neutral-200">
                                        {{ item.position ?? '-' }} · {{ item.eta_minutes !== null && item.eta_minutes !== undefined ? `${item.eta_minutes} min` : '-' }}
                                    </dd>
                                </div>
                                <div class="rounded-sm border border-stone-200 px-3 py-2.5 dark:border-neutral-700">
                                    <dt class="text-[0.6875rem] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ $t('reservations.queue.details.duration') }}
                                    </dt>
                                    <dd class="mt-1 text-xs font-medium text-stone-700 dark:text-neutral-200">
                                        {{ Number(item.estimated_duration_minutes || 0) > 0 ? `${item.estimated_duration_minutes} min` : '-' }}
                                    </dd>
                                </div>
                                <div v-if="item.call_expires_at" class="col-span-2 rounded-sm border border-amber-200 bg-amber-50 px-3 py-2.5 dark:border-amber-500/30 dark:bg-amber-500/10">
                                    <dt class="text-[0.6875rem] font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                                        {{ $t('reservations.queue.details.call_deadline') }}
                                    </dt>
                                    <dd class="mt-1 text-xs font-medium text-amber-800 dark:text-amber-200">
                                        {{ formatQueueDateTime(item.call_expires_at) }}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <footer
                            v-if="item.reservation_id || queueRowHasActions(item)"
                            class="flex flex-wrap items-center justify-between gap-2 border-t border-stone-100 bg-stone-50 px-4 py-2.5 dark:border-neutral-800 dark:bg-neutral-950"
                        >
                            <button
                                v-if="item.reservation_id"
                                type="button"
                                class="inline-flex min-h-8 items-center justify-center gap-1.5 rounded-sm bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:text-neutral-950 dark:hover:bg-emerald-400 dark:focus-visible:ring-offset-neutral-950"
                                :aria-label="queueOpenReservationLabel(item)"
                                :data-testid="`reservation-queue-open-${item.id}`"
                                @click="openQueueReservation(item)"
                            >
                                <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M2.1 12a10.5 10.5 0 0 1 19.8 0 10.5 10.5 0 0 1-19.8 0Z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                                {{ $t('reservations.queue.details.view_reservation') }}
                            </button>
                            <span v-else />
                            <div v-if="queueRowHasActions(item)" class="inline-flex items-center gap-1.5">
                                <button
                                    v-if="queuePrimaryActionFor(item)"
                                    type="button"
                                    class="inline-flex min-h-8 max-w-36 items-center justify-center rounded-sm bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-emerald-700 disabled:pointer-events-none disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:text-neutral-950 dark:hover:bg-emerald-400 dark:focus-visible:ring-offset-neutral-950"
                                    :disabled="queueUpdatingId === Number(item.id)"
                                    :data-testid="`reservation-queue-primary-${item.id}`"
                                    @click="runQueuePrimaryAction(item)"
                                >
                                    <span class="truncate">{{ queuePrimaryActionLabel(item) }}</span>
                                </button>
                                <div v-if="queueRowHasSecondaryActions(item)" class="relative inline-flex">
                                    <button
                                        type="button"
                                        class="size-8 inline-flex items-center justify-center rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-100 disabled:pointer-events-none disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                        aria-haspopup="menu"
                                        :aria-expanded="openQueueActionsFor === Number(item.id)"
                                        :aria-label="$t('reservations.table.actions')"
                                        :disabled="queueUpdatingId === Number(item.id)"
                                        :ref="(element) => setQueueActionButtonRef(item.id, element)"
                                        @click="toggleQueueActions(item.id)"
                                    >
                                        <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <circle cx="12" cy="12" r="1" />
                                            <circle cx="12" cy="5" r="1" />
                                            <circle cx="12" cy="19" r="1" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </footer>
                    </article>
                </div>

                <div
                    v-else
                    class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-4 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400"
                >
                    <span>{{ queueQuickFilter === 'all' ? $t('reservations.queue.empty') : $t('reservations.queue.filters.empty') }}</span>
                    <button
                        v-if="queueQuickFilter !== 'all'"
                        type="button"
                        class="font-semibold text-emerald-700 hover:text-emerald-800 dark:text-emerald-300 dark:hover:text-emerald-200"
                        @click="setQueueQuickFilter('all')"
                    >
                        {{ $t('reservations.queue.filters.clear') }}
                    </button>
                </div>

                <Teleport to="body">
                    <div
                        v-if="activeQueueActionItem"
                        ref="queueActionMenuRef"
                        class="fixed z-[90] w-40 rounded-sm bg-white p-1 shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:bg-neutral-900 dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)]"
                        :style="queueActionMenuStyle"
                        role="menu"
                        aria-orientation="vertical"
                    >
                        <button
                            v-if="activeQueueActionItem.status === 'not_arrived' && !queueActionIsPrimary(activeQueueActionItem, 'check-in')"
                            type="button"
                            role="menuitem"
                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-indigo-700 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-neutral-800"
                            @click="updateQueueStatus(activeQueueActionItem, 'check-in')"
                        >
                            {{ $t('reservations.queue.actions.check_in') }}
                        </button>
                        <button
                            v-if="['checked_in', 'skipped'].includes(activeQueueActionItem.status) && !queueActionIsPrimary(activeQueueActionItem, 'pre-call')"
                            type="button"
                            role="menuitem"
                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-indigo-700 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-neutral-800"
                            @click="updateQueueStatus(activeQueueActionItem, 'pre-call')"
                        >
                            {{ $t('reservations.queue.actions.pre_call') }}
                        </button>
                        <button
                            v-if="['checked_in', 'pre_called', 'skipped'].includes(activeQueueActionItem.status) && !queueActionIsPrimary(activeQueueActionItem, 'call')"
                            type="button"
                            role="menuitem"
                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-neutral-800"
                            @click="updateQueueStatus(activeQueueActionItem, 'call')"
                        >
                            {{ $t('reservations.queue.actions.call') }}
                        </button>
                        <button
                            v-if="['checked_in', 'pre_called', 'called'].includes(activeQueueActionItem.status) && !queueActionIsPrimary(activeQueueActionItem, 'start')"
                            type="button"
                            role="menuitem"
                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-sky-700 hover:bg-sky-50 dark:text-sky-300 dark:hover:bg-neutral-800"
                            @click="updateQueueStatus(activeQueueActionItem, 'start')"
                        >
                            {{ $t('reservations.queue.actions.start') }}
                        </button>
                        <button
                            v-if="['in_service', 'called'].includes(activeQueueActionItem.status) && !queueActionIsPrimary(activeQueueActionItem, 'finish')"
                            type="button"
                            role="menuitem"
                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-neutral-800"
                            @click="openQueueCheckout(activeQueueActionItem)"
                        >
                            {{ $t('reservations.queue.actions.finish_checkout') }}
                        </button>
                        <button
                            v-if="activeQueueActionItem.status === 'awaiting_payment' && !queueActionIsPrimary(activeQueueActionItem, 'checkout')"
                            type="button"
                            role="menuitem"
                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-neutral-800"
                            @click="openQueueCheckout(activeQueueActionItem)"
                        >
                            {{ $t('reservations.queue.actions.checkout') }}
                        </button>
                        <button
                            v-if="activeQueueActionItem.status === 'skipped'"
                            type="button"
                            role="menuitem"
                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-700 hover:bg-stone-50 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            @click="updateQueueStatus(activeQueueActionItem, 'done')"
                        >
                            {{ $t('reservations.queue.actions.done_without_payment') }}
                        </button>
                        <button
                            v-if="['checked_in', 'pre_called', 'called'].includes(activeQueueActionItem.status)"
                            type="button"
                            role="menuitem"
                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-rose-700 hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-neutral-800"
                            @click="updateQueueStatus(activeQueueActionItem, 'skip')"
                        >
                            {{ $t('reservations.queue.actions.skip') }}
                        </button>
                    </div>
                </Teleport>
            </section>

            <section
                v-if="activeDataTab === 'waitlist' && (waitlistEnabled || waitlistRows.length)"
                class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('reservations.waitlist.title') }}</h2>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('reservations.waitlist.subtitle') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                            {{ $t('reservations.waitlist.cards.pending') }}: {{ waitlistStats.pending || 0 }}
                        </span>
                        <span class="rounded-full bg-indigo-100 px-2 py-0.5 font-semibold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">
                            {{ $t('reservations.waitlist.cards.released') }}: {{ waitlistStats.released || 0 }}
                        </span>
                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                            {{ $t('reservations.waitlist.cards.booked') }}: {{ waitlistStats.booked || 0 }}
                        </span>
                    </div>
                </div>

                <div v-if="waitlistActionError" class="mt-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                    {{ waitlistActionError }}
                </div>
                <div v-if="waitlistActionSuccess" class="mt-3 rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
                    {{ waitlistActionSuccess }}
                </div>

                <AdminDataTable embedded :rows="waitlistRows" :show-pagination="false">
                    <template #head>
                        <tr>
                            <th scope="col" class="min-w-48 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.table.when') }}
                            </th>
                            <th scope="col" class="min-w-52 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.table.customer') }}
                            </th>
                            <th scope="col" class="min-w-48 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.table.item') }}
                            </th>
                            <th scope="col" class="min-w-40 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('planning.form.member') }}
                            </th>
                            <th scope="col" class="min-w-28 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.table.status') }}
                            </th>
                            <th scope="col" class="min-w-20 px-5 py-2.5 text-end text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.table.actions') }}
                            </th>
                        </tr>
                    </template>

                    <template #row="{ row: entry }">
                        <tr>
                            <td class="size-px whitespace-nowrap px-4 py-2 align-top">
                                <div class="font-medium text-stone-700 dark:text-neutral-200">
                                    {{ formatDateTime(entry.requested_start_at) }}
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatDateTime(entry.requested_end_at) }}
                                    <template v-if="entry.party_size">
                                        · {{ $t('reservations.table.party_size_value', { value: entry.party_size }) }}
                                    </template>
                                </div>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">{{ entry.client_name || '-' }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">{{ entry.service_name || '-' }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">{{ entry.team_member_name || $t('reservations.client.index.any_available') }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadgeClass(waitlistBadgeStatus(entry.status))">
                                    {{ $t(`reservations.waitlist.status.${entry.status}`) || entry.status }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-end align-top">
                                <AdminDataTableActions
                                    v-if="entry.can_update_status"
                                    :label="$t('reservations.table.actions')"
                                    menu-width-class="w-44"
                                    :disabled="waitlistUpdatingId === Number(entry.id)"
                                    :trigger-test-id="`waitlist-actions-trigger-${entry.id}`"
                                    :menu-test-id="`waitlist-actions-menu-${entry.id}`"
                                >
                                    <button
                                        v-if="entry.status === 'pending'"
                                        type="button"
                                        class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-indigo-700 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-neutral-800"
                                        @click="updateWaitlistStatus(entry, 'released')"
                                    >
                                        {{ $t('reservations.waitlist.actions.release') }}
                                    </button>
                                    <button
                                        v-if="entry.status === 'released'"
                                        type="button"
                                        class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-neutral-800"
                                        @click="updateWaitlistStatus(entry, 'booked')"
                                    >
                                        {{ $t('reservations.waitlist.actions.booked') }}
                                    </button>
                                    <button
                                        v-if="['pending', 'released'].includes(entry.status)"
                                        type="button"
                                        class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-rose-700 hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-neutral-800"
                                        @click="updateWaitlistStatus(entry, 'cancelled')"
                                    >
                                        {{ $t('reservations.actions.cancel') }}
                                    </button>
                                </AdminDataTableActions>
                                <span v-else class="text-xs text-stone-400 dark:text-neutral-500">-</span>
                            </td>
                        </tr>
                    </template>

                    <template #empty>
                        <div class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-4 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                            {{ $t('reservations.waitlist.empty') }}
                        </div>
                    </template>
                </AdminDataTable>
            </section>

            <section v-if="activeDataTab === 'reservations'" class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="space-y-3">
                    <AdminDataTableToolbar
                        :show-filters="showAdvanced"
                        :show-apply="false"
                        :busy="filtersBusy"
                        filters-available
                        filters-controls="reservation-advanced-filters"
                        clear-test-id="reservation-clear-filters"
                        :filters-label="advancedFilterCount ? $t('reservations.advanced_filters.trigger_active', { count: advancedFilterCount }) : $t('reservations.actions.filters')"
                        :clear-label="$t('reservations.actions.clear')"
                        @toggle-filters="showAdvanced = !showAdvanced"
                        @apply="refreshList({ reason: 'filters' })"
                        @clear="clearFilters"
                    >
                        <template #search>
                            <div class="relative">
                                <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                                    <svg class="shrink-0 size-4 text-stone-500 dark:text-neutral-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <circle cx="11" cy="11" r="8" />
                                        <path d="m21 21-4.3-4.3" />
                                    </svg>
                                </div>
                                <input
                                    v-model="filterForm.search"
                                    data-testid="reservation-search"
                                    :aria-label="$t('reservations.filters.search')"
                                    type="search"
                                    class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                                    :placeholder="$t('reservations.filters.search_placeholder')"
                                    :disabled="filtersBusy"
                                >
                            </div>
                        </template>
                        <template #actions>
                            <div v-if="scopeOptions.length > 1" :class="crmSegmentedControlClass()">
                                <button
                                    v-for="option in scopeOptions"
                                    :key="option.value"
                                    type="button"
                                    :class="crmSegmentedControlButtonClass(filterForm.scope === option.value)"
                                    :aria-pressed="String(filterForm.scope === option.value)"
                                    :disabled="filtersBusy"
                                    @click="setReservationScope(option.value)"
                                >
                                    {{ option.label }}
                                </button>
                            </div>
                            <div :class="crmSegmentedControlClass()">
                                <button type="button" data-testid="reservation-view-calendar" :class="crmSegmentedControlButtonClass(viewMode === 'calendar')" @click="viewMode = 'calendar'">
                                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <rect x="3" y="4" width="18" height="18" rx="2" />
                                        <path d="M16 2v4M8 2v4M3 10h18" />
                                    </svg>
                                    {{ $t('reservations.view.calendar') }}
                                </button>
                                <button type="button" data-testid="reservation-view-list" :class="crmSegmentedControlButtonClass(viewMode === 'list')" @click="viewMode = 'list'">
                                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" />
                                    </svg>
                                    {{ $t('reservations.view.list') }}
                                </button>
                            </div>
                        </template>
                    </AdminDataTableToolbar>

                    <AdminQuickFilters
                        :options="reservationQuickFilters"
                        :selected-values="filterForm.quick_filters"
                        :busy="filtersBusy"
                        :all-label="$t('reservations.quick.all')"
                        :aria-label="$t('reservations.filter_summary.quick_filters_label')"
                        test-id-prefix="reservation-quick-filter"
                        data-testid="reservation-quick-filters"
                        @toggle="setReservationQuickFilter"
                        @clear="clearReservationQuickFilters"
                    />
                    <AdminFilterSummary
                        summary-id="reservation-filter-summary-title"
                        i18n-prefix="reservations"
                        data-testid="reservation-active-filters"
                        :matching-count="reservationTabCount"
                        :active-filters="activeReservationFilters"
                        :quick-filter-mode="filterForm.quick_filter_mode"
                        :quick-filter-count="filterForm.quick_filters.length"
                        :busy="filtersBusy"
                        @update:quick-filter-mode="setReservationQuickFilterMode"
                        @remove="removeReservationFilter"
                        @clear="clearFilters"
                    />
                    <ReservationAdvancedFiltersDialog
                        :show="showAdvanced"
                        :filters="filterForm"
                        :matching-count="reservationTabCount"
                        :status-options="statusOptions"
                        :service-options="serviceOptions"
                        :team-options="teamOptions"
                        :own-team-member-id="ownTeamMemberId"
                        @close="showAdvanced = false"
                        @apply="applyReservationAdvancedFilters"
                    />
                </div>
            </section>

            <ReservationCalendarBoard
                v-if="activeDataTab === 'reservations' && viewMode === 'calendar'"
                :events="calendarEvents"
                :loading="calendarLoading"
                :error="calendarError"
                :empty-label="$t('reservations.empty')"
                :selected-event-id="activeReservation?.id || null"
                v-model:view="calendarView"
                v-model:anchor-date="calendarDate"
                :loading-label="$t('reservations.calendar.loading')"
                :timezone="timezone"
                @range-change="onCalendarRangeChange"
                @event-click="openFromEvent"
            />

            <ReservationListTable
                v-else-if="activeDataTab === 'reservations'"
                :rows="reservationRows"
                :links="reservationLinks"
                :total="Number(reservations?.total ?? reservationRows.length)"
                :loading="listLoading"
                :error="listError"
                :status-action-error="listStatusActionError"
                :status-updating-id="listStatusUpdatingId"
                :can-manage="canManageReservationActions"
                :show-team-member="!ownerOnlyMode"
                :timezone="timezone"
                :per-page="currentPerPage"
                :pagination-label="reservationPaginationLabel"
                :has-active-filters="hasActiveReservationFilters"
                :is-date-sort="isDateSort"
                :is-date-sort-asc="isDateSortAsc"
                :is-status-sort="isStatusSort"
                :sort="filterForm.sort"
                @open="openDetails"
                @edit="openEdit"
                @delete="removeReservation"
                @transition-status="updateReservationStatusFromList"
                @retry="refreshList"
                @clear-filters="clearFilters"
                @toggle-date-sort="toggleDateSort"
                @sort-status="setStatusSort"
                @sort="setReservationSort"
                @set-sort="setReservationSortValue"
                @per-page="setReservationPerPage"
            />
        </div>

        <Modal :show="showQueueCheckout" maxWidth="xl" @close="closeQueueCheckout">
            <form
                v-if="activeQueueCheckoutItem"
                class="flex max-h-[calc(100vh-3rem)] flex-col"
                aria-labelledby="queue-checkout-title"
                aria-describedby="queue-checkout-description"
                @submit.prevent="submitQueueCheckout"
            >
                <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-4 py-4 dark:border-neutral-700 sm:px-5">
                    <div class="min-w-0">
                        <h2 id="queue-checkout-title" class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                            {{ $t('reservations.queue.checkout.title') }}
                        </h2>
                        <p id="queue-checkout-description" class="mt-1 break-words text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('reservations.queue.checkout.subtitle') }}
                        </p>
                    </div>
                    <span
                        class="max-w-[45%] shrink-0 truncate whitespace-nowrap rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300"
                        :title="activeQueueCheckoutItem.queue_number || `#${activeQueueCheckoutItem.id}`"
                    >
                        {{ activeQueueCheckoutItem.queue_number || `#${activeQueueCheckoutItem.id}` }}
                    </span>
                </div>

                <div class="min-h-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain px-4 py-4 sm:px-5 sm:py-5">
                    <div class="grid gap-3 rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="grid grid-cols-[minmax(0,0.42fr)_minmax(0,1fr)] items-start gap-3">
                            <span class="min-w-0 break-words text-stone-500 dark:text-neutral-400">{{ $t('reservations.table.customer') }}</span>
                            <span class="min-w-0 break-words text-right font-medium text-stone-800 dark:text-neutral-100">{{ activeQueueCheckoutItem.client_name || $t('reservations.queue.checkout.walk_in_customer') }}</span>
                        </div>
                        <div class="grid grid-cols-[minmax(0,0.42fr)_minmax(0,1fr)] items-start gap-3">
                            <span class="min-w-0 break-words text-stone-500 dark:text-neutral-400">{{ $t('reservations.table.item') }}</span>
                            <span class="min-w-0 break-words text-right font-medium text-stone-800 dark:text-neutral-100">{{ activeQueueCheckoutItem.checkout?.service_name || activeQueueCheckoutItem.service_name || '-' }}</span>
                        </div>
                        <div class="grid grid-cols-[minmax(0,0.42fr)_minmax(0,1fr)] items-start gap-3">
                            <span class="min-w-0 break-words text-stone-500 dark:text-neutral-400">{{ $t('planning.form.member') }}</span>
                            <span class="min-w-0 break-words text-right font-medium text-stone-800 dark:text-neutral-100">{{ activeQueueCheckoutItem.team_member_name || '-' }}</span>
                        </div>
                    </div>

                    <div class="mt-4 overflow-hidden rounded-sm border border-stone-200 dark:border-neutral-700">
                        <dl class="divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                            <div class="flex min-w-0 items-start justify-between gap-4 px-3 py-2.5">
                                <dt class="min-w-0 break-words text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.checkout.subtotal') }}</dt>
                                <dd class="shrink-0 whitespace-nowrap font-medium text-stone-800 dark:text-neutral-100">{{ formatQueueMoney(queueCheckoutBaseAmount, queueCheckoutCurrency) }}</dd>
                            </div>
                            <div class="flex min-w-0 items-start justify-between gap-4 px-3 py-2.5">
                                <dt class="min-w-0 break-words text-stone-500 dark:text-neutral-400">{{ queueCheckoutTaxLabel }}</dt>
                                <dd class="shrink-0 whitespace-nowrap font-medium text-stone-800 dark:text-neutral-100">{{ formatQueueMoney(queueCheckoutTaxTotal, queueCheckoutCurrency) }}</dd>
                            </div>
                            <div class="flex min-w-0 items-start justify-between gap-4 bg-stone-50 px-3 py-2.5 dark:bg-neutral-800">
                                <dt class="min-w-0 break-words font-semibold text-stone-700 dark:text-neutral-200">{{ $t('reservations.queue.checkout.invoice_total') }}</dt>
                                <dd class="shrink-0 whitespace-nowrap font-semibold text-stone-900 dark:text-white">{{ formatQueueMoney(queueCheckoutInvoiceTotal, queueCheckoutCurrency) }}</dd>
                            </div>
                            <div class="flex min-w-0 items-start justify-between gap-4 bg-emerald-50 px-3 py-3 dark:bg-emerald-500/10">
                                <dt class="min-w-0 break-words font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">{{ $t('reservations.queue.checkout.total_to_collect') }}</dt>
                                <dd class="shrink-0 whitespace-nowrap text-lg font-semibold text-emerald-800 dark:text-emerald-200">{{ formatQueueMoney(queueCheckoutChargedTotal, queueCheckoutCurrency) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="mt-4">
                        <FloatingSelect
                            v-if="hasMultiplePaymentMethods"
                            v-model="queueCheckoutForm.method"
                            :options="queuePaymentMethodOptions"
                            :label="$t('reservations.queue.checkout.payment_method')"
                        />
                        <div v-else class="min-w-0 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.checkout.payment_method') }}</div>
                            <div class="mt-1 break-words font-medium text-stone-800 dark:text-neutral-100">{{ queuePaymentMethodLabel(queueCheckoutForm.method) }}</div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <FloatingSelect
                            v-model="queueCheckoutForm.receipt_delivery"
                            :options="queueReceiptDeliveryOptions"
                            :label="$t('reservations.queue.checkout.receipt.label')"
                        />
                        <p class="mt-2 break-words text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('reservations.queue.checkout.receipt.hint') }}
                        </p>
                    </div>

                    <div class="mt-4 rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-stone-800 dark:text-neutral-100">{{ $t('reservations.queue.checkout.tip_title') }}</div>
                                <div id="queue-checkout-tip-hint" class="mt-0.5 break-words text-xs text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.checkout.tip_hint') }}</div>
                            </div>
                            <button
                                type="button"
                                class="min-h-9 shrink-0 self-start whitespace-nowrap rounded-sm px-3 py-1.5 text-xs font-semibold"
                                :class="queueTipEnabled ? 'bg-emerald-600 text-white' : 'border border-stone-200 text-stone-600 dark:border-neutral-700 dark:text-neutral-300'"
                                :aria-pressed="queueTipEnabled"
                                aria-describedby="queue-checkout-tip-hint"
                                @click="queueTipEnabled = !queueTipEnabled"
                            >
                                {{ queueTipEnabled ? $t('reservations.queue.checkout.tip_on') : $t('reservations.queue.checkout.tip_off') }}
                            </button>
                        </div>

                        <div v-if="queueTipEnabled" class="mt-3 space-y-3">
                            <div class="flex w-full rounded-sm border border-stone-200 p-0.5 text-xs font-semibold dark:border-neutral-700 sm:inline-flex sm:w-auto" role="group" :aria-label="$t('reservations.queue.checkout.tip_title')">
                                <button
                                    type="button"
                                    class="min-h-9 min-w-0 flex-1 truncate rounded-sm px-2.5 py-1.5 sm:flex-none"
                                    :class="queueTipMode === 'percent' ? 'bg-stone-900 text-white dark:bg-white dark:text-stone-900' : 'text-stone-600 dark:text-neutral-300'"
                                    :aria-pressed="queueTipMode === 'percent'"
                                    @click="queueTipMode = 'percent'"
                                >
                                    {{ $t('reservations.queue.checkout.percent') }}
                                </button>
                                <button
                                    type="button"
                                    class="min-h-9 min-w-0 flex-1 truncate rounded-sm px-2.5 py-1.5 sm:flex-none"
                                    :class="queueTipMode === 'fixed' ? 'bg-stone-900 text-white dark:bg-white dark:text-stone-900' : 'text-stone-600 dark:text-neutral-300'"
                                    :aria-pressed="queueTipMode === 'fixed'"
                                    @click="queueTipMode = 'fixed'"
                                >
                                    {{ $t('reservations.queue.checkout.fixed') }}
                                </button>
                            </div>

                            <div v-if="queueTipMode === 'percent'" class="flex flex-wrap items-start gap-2">
                                <button
                                    v-for="percent in queueQuickTipPercents"
                                    :key="`queue-tip-percent-${percent}`"
                                    type="button"
                                    class="min-h-9 shrink-0 rounded-sm border px-2.5 py-1.5 text-xs font-semibold"
                                    :class="Number(queueTipPercent) === Number(percent) ? 'border-emerald-600 bg-emerald-50 text-emerald-700 dark:border-emerald-400 dark:bg-emerald-500/10 dark:text-emerald-300' : 'border-stone-200 text-stone-600 dark:border-neutral-700 dark:text-neutral-300'"
                                    :aria-pressed="Number(queueTipPercent) === Number(percent)"
                                    @click="queueTipPercent = Number(percent)"
                                >
                                    {{ percent }}%
                                </button>
                                <div class="min-w-0 basis-full sm:basis-44 sm:flex-1">
                                    <FloatingInput
                                        v-model="queueTipPercent"
                                        class="w-full"
                                        type="number"
                                        min="0"
                                        :max="maxQueueTipPercent"
                                        step="0.01"
                                        :label="$t('reservations.queue.checkout.custom_percent')"
                                    />
                                </div>
                            </div>
                            <div v-else>
                                <div class="mb-2 flex flex-wrap gap-2">
                                    <button
                                        v-for="amount in queueQuickTipFixedAmounts"
                                        :key="`queue-tip-fixed-${amount}`"
                                        type="button"
                                        class="min-h-9 shrink-0 rounded-sm border px-2.5 py-1.5 text-xs font-semibold"
                                        :class="Number(queueTipFixedAmount) === Number(amount) ? 'border-emerald-600 bg-emerald-50 text-emerald-700 dark:border-emerald-400 dark:bg-emerald-500/10 dark:text-emerald-300' : 'border-stone-200 text-stone-600 dark:border-neutral-700 dark:text-neutral-300'"
                                        :aria-pressed="Number(queueTipFixedAmount) === Number(amount)"
                                        @click="queueTipFixedAmount = Number(amount)"
                                    >
                                        {{ formatQueueMoney(amount, queueCheckoutCurrency) }}
                                    </button>
                                </div>
                                <div class="min-w-0">
                                    <FloatingInput
                                        v-model="queueTipFixedAmount"
                                        class="w-full"
                                        type="number"
                                        min="0"
                                        :max="maxQueueTipFixed"
                                        step="0.01"
                                        :label="$t('reservations.queue.checkout.custom_amount')"
                                    />
                                </div>
                            </div>

                            <div class="flex items-start justify-between gap-3 rounded-sm bg-stone-50 px-3 py-2 text-sm dark:bg-neutral-800" aria-live="polite" aria-atomic="true">
                                <span class="min-w-0 break-words text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.checkout.tip_amount') }}</span>
                                <span class="shrink-0 whitespace-nowrap font-semibold text-stone-800 dark:text-neutral-100">{{ formatQueueMoney(queueCheckoutTipAmount, queueCheckoutCurrency) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <div class="min-w-0">
                            <FloatingInput v-model="queueCheckoutForm.reference" class="w-full" :label="$t('reservations.queue.checkout.reference')" />
                        </div>
                        <div class="min-w-0">
                            <FloatingTextarea v-model="queueCheckoutForm.notes" class="w-full" :label="$t('reservations.queue.checkout.notes')" />
                        </div>
                    </div>

                    <div v-if="queueCheckoutError" class="mt-4 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200" role="alert">
                        {{ queueCheckoutError }}
                    </div>
                </div>

                <div class="flex flex-col-reverse gap-2 border-t border-stone-200 px-4 py-3 dark:border-neutral-700 sm:flex-row sm:justify-end sm:px-5">
                    <button
                        type="button"
                        class="min-h-10 w-full rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 dark:border-neutral-700 dark:text-neutral-200 sm:w-auto"
                        :disabled="queueCheckoutProcessing"
                        @click="closeQueueCheckout"
                    >
                        {{ $t('reservations.queue.checkout.cancel') }}
                    </button>
                    <button
                        type="submit"
                        class="min-h-10 w-full rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                        :disabled="!canSubmitQueueCheckout || queueCheckoutProcessing"
                    >
                        {{ queueCheckoutSubmitLabel }}
                    </button>
                </div>
            </form>
        </Modal>

        <Modal
            :show="showQueueAvailabilityConfirmation"
            maxWidth="md"
            :closeable="!queueAvailabilityConfirmationProcessing"
            @close="closeQueueAvailabilityConfirmation"
        >
            <section class="p-5" aria-labelledby="queue-availability-confirmation-title">
                <h2 id="queue-availability-confirmation-title" class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                    {{ $t('reservations.queue.availability_confirmation.title') }}
                </h2>
                <p class="mt-2 text-sm text-stone-600 dark:text-neutral-300">
                    {{ $t('reservations.queue.availability_confirmation.description', { teamMember: queueAvailabilityConfirmationMemberName }) }}
                </p>
                <p class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('reservations.queue.availability_confirmation.hint') }}
                </p>

                <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 dark:border-neutral-700 dark:text-neutral-200"
                        :disabled="queueAvailabilityConfirmationProcessing"
                        @click="closeQueueAvailabilityConfirmation"
                    >
                        {{ $t('reservations.queue.availability_confirmation.still_busy') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="queueAvailabilityConfirmationProcessing"
                        @click="confirmQueueMemberAvailability"
                    >
                        {{ queueAvailabilityConfirmationProcessing
                            ? $t('reservations.queue.availability_confirmation.continuing')
                            : $t('reservations.queue.availability_confirmation.available_continue') }}
                    </button>
                </div>
            </section>
        </Modal>

        <Modal
            :show="showEditor"
            maxWidth="5xl"
            position="center"
            :full-screen-mobile="true"
            :closeable="!reservationForm.processing && !customerCreationProcessing"
            aria-labelledby="reservation-editor-title"
            aria-describedby="reservation-editor-subtitle"
            @close="closeEditor"
        >
            <div class="flex h-dvh min-h-0 flex-col sm:h-auto sm:max-h-[calc(100vh-3rem)]">
                <header class="flex shrink-0 items-start justify-between gap-4 border-b border-stone-200 px-5 py-4 dark:border-neutral-700 sm:px-6">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-sm bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                            <svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="5" width="18" height="16" rx="2" />
                                <path d="M16 3v4M8 3v4M3 11h18M12 14v4M10 16h4" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <h2 id="reservation-editor-title" class="text-base font-semibold text-stone-900 dark:text-neutral-100 sm:text-lg">
                                {{ activeReservation ? $t('reservations.form.edit_title') : $t('reservations.form.create_title') }}
                            </h2>
                            <p id="reservation-editor-subtitle" class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                                {{ activeReservation
                                    ? $t('reservations.form.edit_subtitle')
                                    : $t('reservations.form.create_subtitle') }}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="inline-flex size-9 shrink-0 items-center justify-center rounded-sm border border-stone-200 text-stone-500 transition hover:bg-stone-50 hover:text-stone-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 dark:border-neutral-700 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                        :aria-label="$t('quotes.form.cancel')"
                        :disabled="reservationForm.processing || customerCreationProcessing"
                        @click="closeEditor"
                    >
                        <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 6 6 18M6 6l12 12" />
                        </svg>
                    </button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    <section v-if="!activeReservation" class="px-5 py-5 sm:px-6 sm:py-6" aria-labelledby="reservation-customer-section-title">
                        <div class="mb-4 flex items-start gap-3">
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-xs font-semibold text-white">1</span>
                            <div>
                                <h3 id="reservation-customer-section-title" class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ $t('reservations.form.customer_section_title') }}
                                </h3>
                                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                                    {{ $t('reservations.form.customer_section_hint') }}
                                </p>
                            </div>
                        </div>

                        <ReservationCustomerChooser
                            v-model="reservationForm.client_id"
                            v-model:mode="reservationCustomerMode"
                            :clients="localClients"
                            :can-create="canCreateCustomer"
                            :error="reservationForm.errors.client_id"
                            :timezone="timezone"
                            @created="handleCustomerCreated"
                            @processing="customerCreationProcessing = $event"
                            @rebook="handleRebook"
                        />
                    </section>

                    <form
                        v-if="activeReservation || reservationCustomerMode === 'existing'"
                        id="reservation-editor-form"
                        @submit.prevent="submitReservation"
                    >
                        <section
                            class="border-t border-stone-200 px-5 py-5 dark:border-neutral-700 sm:px-6 sm:py-6"
                            aria-labelledby="reservation-details-section-title"
                        >
                            <div
                                v-if="reservationForm.errors.reservation"
                                class="mb-4 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200"
                                role="alert"
                            >
                                {{ reservationForm.errors.reservation }}
                            </div>
                            <div class="mb-4 flex items-start gap-3">
                                <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-xs font-semibold text-white">
                                    <template v-if="!activeReservation">2</template>
                                    <svg v-else aria-hidden="true" class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
                                    </svg>
                                </span>
                                <div>
                                    <h3 id="reservation-details-section-title" class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                        {{ $t('reservations.form.reservation_section_title') }}
                                    </h3>
                                    <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                                        {{ $t('reservations.form.reservation_section_hint') }}
                                    </p>
                                </div>
                            </div>

                            <div class="space-y-4 rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800 sm:p-5">
                                <div class="grid gap-3" :class="activeReservation ? 'md:grid-cols-3' : 'md:grid-cols-2'">
                                    <div>
                                        <FloatingSelect v-model="reservationForm.team_member_id" :options="teamOptions.slice(1)" :label="$t('planning.form.member')" />
                                        <InputError class="mt-1" :message="reservationForm.errors.team_member_id" />
                                    </div>
                                    <div v-if="activeReservation">
                                        <FloatingSelect
                                            v-model="reservationForm.client_id"
                                            :options="clientOptions"
                                            :label="$t('reservations.form.customer')"
                                            filterable
                                            :filter-placeholder="$t('reservations.form.search_customer')"
                                        />
                                        <InputError class="mt-1" :message="reservationForm.errors.client_id" />
                                    </div>
                                    <div>
                                        <FloatingSelect v-model="reservationForm.service_id" :options="serviceOptions.slice(1)" :label="$t('reservations.form.item')" />
                                        <InputError class="mt-1" :message="reservationForm.errors.service_id" />
                                    </div>
                                </div>

                                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                                    <div class="xl:col-span-2">
                                        <FloatingInput
                                            id="reservation-starts-at"
                                            ref="reservationStartsAtField"
                                            v-model="reservationForm.starts_at"
                                            type="datetime-local"
                                            :label="$t('reservations.form.starts_at')"
                                        />
                                        <InputError class="mt-1" :message="reservationForm.errors.starts_at" />
                                    </div>
                                    <div class="xl:col-span-2">
                                        <FloatingInput v-model="reservationForm.ends_at" type="datetime-local" :label="$t('reservations.form.ends_at')" />
                                        <InputError class="mt-1" :message="reservationForm.errors.ends_at" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="reservationForm.duration_minutes" type="number" min="5" :label="$t('reservations.client.book.fields.duration')" />
                                        <InputError class="mt-1" :message="reservationForm.errors.duration_minutes" />
                                    </div>
                                    <div>
                                        <FloatingSelect v-model="reservationForm.status" :options="statusOptions.slice(1)" :label="$t('reservations.form.status')" />
                                        <InputError class="mt-1" :message="reservationForm.errors.status" />
                                    </div>
                                </div>

                                <div class="grid gap-3 md:grid-cols-2">
                                    <div>
                                        <FloatingTextarea v-model="reservationForm.client_notes" :label="$t('reservations.client.book.fields.client_notes')" />
                                        <InputError class="mt-1" :message="reservationForm.errors.client_notes" />
                                    </div>
                                    <div>
                                        <FloatingTextarea v-model="reservationForm.internal_notes" :label="$t('reservations.form.internal_notes')" />
                                        <InputError class="mt-1" :message="reservationForm.errors.internal_notes" />
                                    </div>
                                </div>
                            </div>
                        </section>
                    </form>
                </div>

                <footer
                    v-if="activeReservation || reservationCustomerMode === 'existing'"
                    class="flex shrink-0 flex-col-reverse gap-2 border-t border-stone-200 bg-white px-5 py-4 dark:border-neutral-700 dark:bg-neutral-900 sm:flex-row sm:items-center sm:justify-end sm:px-6"
                >
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center justify-center rounded-sm border border-stone-200 bg-white px-4 py-2 text-sm font-medium text-stone-700 transition hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        :disabled="reservationForm.processing"
                        @click="closeEditor"
                    >
                        {{ $t('quotes.form.cancel') }}
                    </button>
                    <button
                        type="submit"
                        form="reservation-editor-form"
                        class="inline-flex min-h-10 items-center justify-center rounded-sm bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="reservationForm.processing"
                    >
                        {{ reservationForm.processing
                            ? $t('reservations.actions.update')
                            : (activeReservation ? $t('reservations.actions.update') : $t('reservations.actions.create')) }}
                    </button>
                </footer>
            </div>
        </Modal>

        <Modal
            :show="showDetails"
            maxWidth="2xl"
            presentation="drawer"
            :closeable="!detailsActionLoading"
            aria-labelledby="reservation-details-title"
            aria-describedby="reservation-details-subtitle"
            @close="closeDetails"
        >
            <ReservationDetailsPanel
                v-if="activeReservation"
                :reservation="activeReservation"
                :timezone="timezone"
                :loading="detailsLoading"
                :error="detailsLoadError"
                :busy="detailsActionLoading"
                @close="closeDetails"
                @retry="retryReservationDetails"
            >
                <template #supplementary>
                    <div
                        v-if="conversionSuccess && !isPublicBookingProspect"
                        class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 shadow-sm dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"
                        role="status"
                    >
                        {{ conversionSuccess }}
                    </div>
                    <section
                        v-if="isPublicBookingProspect"
                        class="rounded-2xl border border-emerald-200/80 bg-emerald-50/80 p-4 shadow-sm dark:border-emerald-500/25 dark:bg-emerald-500/10"
                        aria-labelledby="reservation-conversion-title"
                    >
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700 dark:text-emerald-300">
                                    {{ $t('reservations.details.conversion.public_booking_title') }}
                                </p>
                                <h3 id="reservation-conversion-title" class="mt-1 text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ $t('reservations.details.conversion.title') }}
                                </h3>
                                <p class="mt-1 text-xs leading-5 text-stone-600 dark:text-neutral-300">
                                    {{ $t('reservations.details.conversion.description') }}
                                </p>
                                <p class="mt-2 break-words text-xs text-stone-500 dark:text-neutral-400">
                                    {{ publicBookingContact.name || $t('reservations.details.no_contact') }}
                                    <span v-if="publicBookingContact.email"> · {{ publicBookingContact.email }}</span>
                                    <span v-if="publicBookingContact.phone"> · {{ publicBookingContact.phone }}</span>
                                </p>
                            </div>
                            <button
                                type="button"
                                class="min-h-11 shrink-0 rounded-xl border border-emerald-200 bg-white px-4 py-2 text-xs font-semibold text-emerald-800 transition hover:border-emerald-300 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-emerald-500/30 dark:bg-neutral-900 dark:text-emerald-200 dark:hover:bg-emerald-500/10"
                                :disabled="conversionLoading || conversionSubmitting"
                                @click="loadPublicBookingConversion"
                            >
                                {{ conversionLoading
                                    ? $t('reservations.details.conversion.states.loading')
                                    : $t('reservations.details.conversion.actions.check') }}
                            </button>
                        </div>

                        <div v-if="conversionPayload?.matches?.length" class="mt-4 space-y-2">
                            <article
                                v-for="match in conversionPayload.matches"
                                :key="`customer-match-${match.id}`"
                                class="flex flex-col gap-3 rounded-xl border border-white/90 bg-white/90 p-3 dark:border-neutral-700 dark:bg-neutral-900/80 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-stone-900 dark:text-neutral-100">{{ match.display_name || `#${match.id}` }}</p>
                                    <p class="mt-0.5 break-words text-xs text-stone-500 dark:text-neutral-400">{{ match.email || '-' }} · {{ match.phone || '-' }}</p>
                                </div>
                                <button
                                    type="button"
                                    class="min-h-11 shrink-0 rounded-xl bg-emerald-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="conversionSubmitting"
                                    @click="convertPublicBooking('link_existing', match.id)"
                                >
                                    {{ $t('reservations.details.conversion.actions.link') }}
                                </button>
                            </article>
                        </div>

                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <FloatingInput v-model="conversionForm.contact_name" :label="$t('reservations.details.conversion.fields.name')" />
                            <FloatingInput v-model="conversionForm.contact_email" type="email" :label="$t('reservations.details.conversion.fields.email')" />
                            <FloatingInput v-model="conversionForm.contact_phone" :label="$t('reservations.details.conversion.fields.phone')" />
                            <FloatingInput v-model="conversionForm.company_name" :label="$t('reservations.details.conversion.fields.company')" />
                        </div>
                        <InputError class="mt-2" :message="conversionForm.errors.customer_id || conversionForm.errors.contact_email || conversionForm.errors.contact_phone || conversionForm.errors.contact_name" />
                        <div v-if="conversionError" class="mt-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200" role="alert">
                            {{ conversionError }}
                        </div>
                        <div v-if="conversionSuccess" class="mt-3 rounded-xl border border-emerald-200 bg-white px-3 py-2 text-xs text-emerald-700 dark:border-emerald-500/30 dark:bg-neutral-900 dark:text-emerald-200" role="status">
                            {{ conversionSuccess }}
                        </div>
                        <div class="mt-4 flex justify-end">
                            <button
                                type="button"
                                class="min-h-11 w-full rounded-xl bg-stone-900 px-4 py-2 text-xs font-semibold text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200 sm:w-auto"
                                :disabled="conversionSubmitting"
                                @click="convertPublicBooking('create_new')"
                            >
                                {{ conversionSubmitting
                                    ? $t('reservations.details.conversion.states.converting')
                                    : $t('reservations.details.conversion.actions.create') }}
                            </button>
                        </div>
                    </section>
                </template>

                <template #actions>
                    <div v-if="detailsActionError" class="w-full rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200" role="alert">
                        {{ detailsActionError }}
                    </div>
                    <button
                        v-if="canTransitionActiveReservationTo('confirmed')"
                        type="button"
                        class="min-h-11 rounded-xl bg-emerald-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-wait disabled:opacity-50"
                        :disabled="detailsActionLoading"
                        @click="updateStatus('confirmed')"
                    >
                        {{ $t('reservations.actions.confirm') }}
                    </button>
                    <button
                        v-if="canTransitionActiveReservationTo('pending')"
                        type="button"
                        class="min-h-11 rounded-xl bg-amber-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-amber-600 disabled:cursor-wait disabled:opacity-50"
                        :disabled="detailsActionLoading"
                        @click="updateStatus('pending')"
                    >
                        {{ $t('reservations.actions.set_pending') }}
                    </button>
                    <button
                        v-if="canTransitionActiveReservationTo('completed')"
                        type="button"
                        class="min-h-11 rounded-xl bg-sky-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-700 disabled:cursor-wait disabled:opacity-50"
                        :disabled="detailsActionLoading"
                        @click="updateStatus('completed')"
                    >
                        {{ $t('reservations.actions.complete') }}
                    </button>
                    <button
                        v-if="canTransitionActiveReservationTo('no_show')"
                        type="button"
                        class="min-h-11 rounded-xl bg-stone-700 px-4 py-2 text-xs font-semibold text-white transition hover:bg-stone-800 disabled:cursor-wait disabled:opacity-50 dark:bg-neutral-600"
                        :disabled="detailsActionLoading"
                        @click="updateStatus('no_show')"
                    >
                        {{ $t('reservations.actions.no_show') }}
                    </button>
                    <button
                        v-if="canTransitionActiveReservationTo('cancelled')"
                        type="button"
                        class="min-h-11 rounded-xl bg-rose-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-50"
                        :disabled="detailsActionLoading"
                        @click="updateStatus('cancelled')"
                    >
                        {{ cancelActionLabel }}
                    </button>
                    <button
                        v-if="canEditActiveReservation"
                        type="button"
                        class="min-h-11 rounded-xl border border-stone-200 bg-white px-4 py-2 text-xs font-semibold text-stone-700 transition hover:border-stone-300 hover:bg-stone-50 disabled:cursor-wait disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        :disabled="detailsActionLoading"
                        @click="openEdit(activeReservation); closeDetails()"
                    >
                        {{ $t('reservations.actions.edit') }}
                    </button>
                </template>
            </ReservationDetailsPanel>
        </Modal>
    </AuthenticatedLayout>
</template>
