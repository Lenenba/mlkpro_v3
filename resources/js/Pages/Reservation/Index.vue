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
import Modal from '@/Components/Modal.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import ReservationCalendarBoard from '@/Components/Reservation/ReservationCalendarBoard.vue';
import ReservationStats from '@/Components/Reservation/ReservationStats.vue';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import { reservationStatusBadgeClass } from '@/Components/Reservation/status';
import { paymentMethodLabel as resolvePaymentMethodLabel, useTenantPaymentMethods } from '@/Composables/useTenantPaymentMethods';

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
const calendarEvents = ref([...(props.events || [])]);
const calendarLoading = ref(false);
const calendarError = ref('');
const detailsActionError = ref('');
const waitlistRows = ref([...(props.waitlists || [])]);
const waitlistActionError = ref('');
const waitlistActionSuccess = ref('');
const waitlistUpdatingId = ref(null);
const queueRows = ref([...(props.queueItems || [])]);
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
const ownerOnlyMode = computed(() => Boolean(props.settings?.owner_only_mode));
const canManageReservationActions = computed(() => canManage.value && !ownerOnlyMode.value);
const canUpdateStatus = computed(() => Boolean(props.access?.can_update_status));
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
const reservationTabCount = computed(() => Number(props.reservations?.total ?? props.reservations?.data?.length ?? 0));
const activeDataTab = ref(queueStripeReturn.queueItemId > 0 ? 'queue' : 'reservations');
const ownTeamMemberId = computed(() => {
    const raw = props.access?.own_team_member_id;
    return raw ? String(raw) : '';
});
const calendarRange = ref({
    start: dayjs().startOf('month').toISOString(),
    end: dayjs().endOf('month').toISOString(),
});

const showEditor = ref(false);
const showDetails = ref(false);
const activeReservation = ref(null);
const showAdvanced = ref(false);
const lastFocusedReservationId = ref(null);
const conversionLoading = ref(false);
const conversionSubmitting = ref(false);
const conversionPayload = ref(null);
const conversionError = ref('');
const conversionSuccess = ref('');

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
const showTeamFilters = computed(() => teamOptions.value.length > 1);

const serviceOptions = computed(() => [
    { value: '', label: t('reservations.form.none') },
    ...(props.services || []).map((service) => ({
        value: String(service.id),
        label: service.name,
    })),
]);

const clientOptions = computed(() => [
    { value: '', label: t('reservations.form.none') },
    ...(props.clients || []).map((client) => ({
        value: String(client.id),
        label: client.company_name
            || `${client.first_name || ''} ${client.last_name || ''}`.trim()
            || client.email
            || `#${client.id}`,
    })),
]);

const isDateSort = computed(() => ['date_asc', 'date_desc'].includes(filterForm.sort));
const isDateSortAsc = computed(() => filterForm.sort === 'date_asc');
const isStatusSort = computed(() => filterForm.sort === 'status');
const reservationRows = computed(() => (Array.isArray(props.reservations?.data) ? props.reservations.data : []));
const focusReservationId = computed(() => Number(props.focus_reservation_id || 0));
const reservationLinks = computed(() => props.reservations?.links || []);
const currentPerPage = computed(() => resolveDataTablePerPage(props.reservations?.per_page, props.filters?.per_page));
const reservationPaginationLabel = computed(() => t('reservations.pagination.showing', {
    from: props.reservations?.from || 0,
    to: props.reservations?.to || 0,
}));

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
const reservationClientName = (reservation) => (
    reservation?.client?.company_name
    || `${reservation?.client?.first_name || ''} ${reservation?.client?.last_name || ''}`.trim()
    || reservation?.prospect?.contact_name
    || reservation?.metadata?.public_booking?.contact_name
    || '-'
);
const reservationMemberName = (reservation) => (
    ownerOnlyMode.value
        ? '-'
        : (reservation?.team_member?.user?.name || reservation?.teamMember?.user?.name || '-')
);
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
const queueRowHasActions = (item) => Boolean(item?.can_update_status) && [
    'not_arrived',
    'checked_in',
    'pre_called',
    'called',
    'skipped',
    'in_service',
    'awaiting_payment',
].includes(String(item?.status || ''));
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
const isPast = (value) => (value ? dayjs(value).isBefore(dayjs()) : false);
const activePaymentPolicy = computed(() => activeReservation.value?.metadata?.payment_policy || {});
const activePaymentState = computed(() => activeReservation.value?.metadata?.payment_state || {});
const hasPaymentPolicy = computed(() => (
    Boolean(activePaymentPolicy.value?.deposit_required)
    || Boolean(activePaymentPolicy.value?.no_show_fee_enabled)
));
const isPublicBookingProspect = computed(() => Boolean(activeReservation.value?.prospect_id && !activeReservation.value?.client_id));
const publicBookingContact = computed(() => {
    const prospect = activeReservation.value?.prospect || {};
    const meta = activeReservation.value?.metadata?.public_booking || {};

    return {
        name: prospect.contact_name || meta.contact_name || '',
        email: prospect.contact_email || meta.contact_email || '',
        phone: prospect.contact_phone || meta.contact_phone || '',
        link: activeReservation.value?.public_booking_link?.name || activeReservation.value?.publicBookingLink?.name || meta.link_name || '',
    };
});

const canConfirmStatus = (status) => ['pending', 'rescheduled'].includes(String(status || ''));
const isConfirmedStatus = (status) => String(status || '') === 'confirmed';
const canCancelStatus = (status) => ['pending', 'confirmed', 'rescheduled'].includes(String(status || ''));
const canSetPendingStatus = (status) => ['confirmed', 'rescheduled'].includes(String(status || ''));
const canCompleteReservation = (reservation) =>
    ['confirmed', 'rescheduled'].includes(String(reservation?.status || ''))
    && isPast(reservation?.ends_at || reservation?.starts_at);
const canMarkNoShow = (reservation) =>
    ['pending', 'confirmed', 'rescheduled'].includes(String(reservation?.status || ''))
    && isPast(reservation?.starts_at);
const cancelActionLabel = computed(() =>
    ['pending', 'rescheduled'].includes(String(activeReservation.value?.status || ''))
        ? t('reservations.actions.decline')
        : t('reservations.actions.cancel')
);

const loadEvents = async () => {
    if (!calendarRange.value.start || !calendarRange.value.end) {
        return;
    }

    calendarLoading.value = true;
    calendarError.value = '';

    try {
        const response = await axios.get(route('reservation.events'), {
            params: {
                start: calendarRange.value.start,
                end: calendarRange.value.end,
                status: filterForm.status || undefined,
                team_member_id: filterForm.team_member_id || undefined,
                service_id: filterForm.service_id || undefined,
                scope: filterForm.scope || undefined,
            },
        });

        calendarEvents.value = response?.data?.events || [];
    } catch (error) {
        calendarError.value = error?.response?.data?.message || t('reservations.errors.load_events');
    } finally {
        calendarLoading.value = false;
    }
};

const refreshList = () => {
    filterForm.view_mode = viewMode.value;

    router.get(
        route('reservation.index'),
        {
            search: filterForm.search || undefined,
            status: filterForm.status || undefined,
            team_member_id: filterForm.team_member_id || undefined,
            service_id: filterForm.service_id || undefined,
            date_from: filterForm.date_from || undefined,
            date_to: filterForm.date_to || undefined,
            scope: filterForm.scope || undefined,
            sort: filterForm.sort || undefined,
            view_mode: viewMode.value,
            per_page: currentPerPage.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['filters', 'reservations', 'stats', 'performance', 'waitlists', 'waitlistStats', 'queueItems', 'queueStats'],
        }
    );

    loadEvents();
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
watch(
    () => [
        filterForm.search,
        filterForm.status,
        filterForm.team_member_id,
        filterForm.service_id,
        filterForm.date_from,
        filterForm.date_to,
        filterForm.scope,
        filterForm.sort,
        viewMode.value,
    ],
    () => {
        if (filterTimer) {
            clearTimeout(filterTimer);
        }
        filterTimer = setTimeout(refreshList, 300);
    }
);

onBeforeUnmount(() => {
    if (filterTimer) {
        clearTimeout(filterTimer);
    }

    stopQueueStripeStatusPolling();
    teardownQueueActionListeners();
});

watch(
    () => filterForm.scope,
    (next, previous) => {
        if (next === 'mine' && ownTeamMemberId.value) {
            filterForm.team_member_id = ownTeamMemberId.value;
        }
        if (next === 'all' && previous === 'mine' && canViewAll.value) {
            filterForm.team_member_id = '';
        }
    }
);

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
    filterForm.search = '';
    filterForm.status = '';
    filterForm.team_member_id = filterForm.scope === 'mine' ? ownTeamMemberId.value : '';
    filterForm.service_id = '';
    filterForm.date_from = '';
    filterForm.date_to = '';
    filterForm.sort = 'date_asc';
};

const toggleDateSort = () => {
    if (filterForm.sort === 'date_asc') {
        filterForm.sort = 'date_desc';
        return;
    }
    filterForm.sort = 'date_asc';
};

const setStatusSort = () => {
    filterForm.sort = 'status';
};

const onCalendarRangeChange = (payload) => {
    calendarRange.value = {
        start: payload.start,
        end: payload.end,
    };
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
    showEditor.value = true;
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

const openDetails = (reservation) => {
    detailsActionError.value = '';
    conversionError.value = '';
    conversionSuccess.value = '';
    conversionPayload.value = null;
    activeReservation.value = reservation;
    showDetails.value = true;
    if (reservation?.prospect_id && !reservation?.client_id) {
        loadPublicBookingConversion();
    }
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
    if (!activeReservation.value?.id) {
        return;
    }

    conversionLoading.value = true;
    conversionError.value = '';

    try {
        const response = await axios.get(route('reservation.public-booking-conversion.show', activeReservation.value.id));
        conversionPayload.value = {
            ...response?.data,
            default_mode: (response?.data?.matches || []).length ? 'link_existing' : 'create_new',
        };
        hydrateConversionForm();
    } catch (error) {
        conversionError.value = error?.response?.data?.message || 'Impossible de charger les options de conversion.';
    } finally {
        conversionLoading.value = false;
    }
};

const convertPublicBooking = async (mode, customerId = null) => {
    if (!activeReservation.value?.id) {
        return;
    }

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
        const response = await axios.post(route('reservation.public-booking-conversion.store', activeReservation.value.id), payload);
        activeReservation.value = response?.data?.reservation || activeReservation.value;
        conversionSuccess.value = response?.data?.message || 'Prospect converti en client.';
        conversionPayload.value = {
            ...(conversionPayload.value || {}),
            already_converted: true,
            matches: response?.data?.matches || conversionPayload.value?.matches || [],
        };
        refreshList();
    } catch (error) {
        if (error?.response?.status === 422) {
            conversionForm.setError(error.response.data?.errors || {});
            conversionError.value = firstValidationMessage(error.response.data?.errors || {}) || 'Impossible de convertir ce prospect.';
        } else {
            conversionError.value = error?.response?.data?.message || 'Impossible de convertir ce prospect.';
        }
    } finally {
        conversionSubmitting.value = false;
    }
};

watch(
    () => [focusReservationId.value, reservationRows.value.map((reservation) => reservation.id).join(',')],
    () => {
        const id = focusReservationId.value;
        if (!id || lastFocusedReservationId.value === id) {
            return;
        }

        const reservation = reservationMap.value.get(id);
        if (!reservation) {
            return;
        }

        activeDataTab.value = 'reservations';
        viewMode.value = 'list';
        lastFocusedReservationId.value = id;
        openDetails(reservation);
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
        client_notes: source?.extendedProps?.client_notes,
        internal_notes: source?.extendedProps?.internal_notes,
    };

    openDetails(reservationMap.value.get(eventId) || fallback);
};

const updateStatus = async (status) => {
    if (!activeReservation.value?.id) {
        return;
    }
    if (!canUpdateStatus.value) {
        return;
    }

    detailsActionError.value = '';

    try {
        await axios.patch(route('reservation.status', activeReservation.value.id), { status });
        showDetails.value = false;
        refreshList();
    } catch (error) {
        detailsActionError.value = error?.response?.data?.message || t('reservations.errors.update_status');
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
        closeQueueActions();
    }
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

            <ReservationStats :stats="stats" :performance="performance" compact />

            <section class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="inline-flex items-center rounded-sm border border-stone-200 bg-white p-0.5 text-xs font-semibold text-stone-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5"
                            :class="activeDataTab === 'reservations'
                                ? 'bg-green-600 text-white shadow-sm dark:bg-white dark:text-stone-900'
                                : 'text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100'"
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
                        <span class="rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                            {{ $t('reservations.queue.cards.waiting') }}: {{ queueStats.waiting || 0 }}
                        </span>
                        <span class="rounded-full bg-indigo-100 px-2 py-0.5 font-semibold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">
                            {{ $t('reservations.queue.cards.called') }}: {{ queueStats.called || 0 }}
                        </span>
                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                            {{ $t('reservations.queue.cards.in_service') }}: {{ queueStats.in_service || 0 }}
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

                <AdminDataTable embedded :rows="queueRows" :show-pagination="false">
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
                                <div class="font-medium text-stone-700 dark:text-neutral-200">{{ item.queue_number || `#${item.id}` }}</div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ item.item_type === 'appointment' ? $t('reservations.queue.types.appointment') : $t('reservations.queue.types.ticket') }}
                                </div>
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
                                <div v-if="queueRowHasActions(item)" class="relative inline-flex">
                                    <button
                                        type="button"
                                        class="size-7 inline-flex items-center justify-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:pointer-events-none disabled:opacity-50 focus:bg-stone-50 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
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
                                <span v-else class="text-xs text-stone-400 dark:text-neutral-500">-</span>
                            </td>
                        </tr>
                    </template>

                    <template #empty>
                        <div class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-4 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                            {{ $t('reservations.queue.empty') }}
                        </div>
                    </template>
                </AdminDataTable>

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
                            v-if="activeQueueActionItem.status === 'not_arrived'"
                            type="button"
                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-indigo-700 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-neutral-800"
                            @click="updateQueueStatus(activeQueueActionItem, 'check-in')"
                        >
                            {{ $t('reservations.queue.actions.check_in') }}
                        </button>
                        <button
                            v-if="['checked_in', 'skipped'].includes(activeQueueActionItem.status)"
                            type="button"
                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-indigo-700 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-neutral-800"
                            @click="updateQueueStatus(activeQueueActionItem, 'pre-call')"
                        >
                            {{ $t('reservations.queue.actions.pre_call') }}
                        </button>
                        <button
                            v-if="['checked_in', 'pre_called', 'skipped'].includes(activeQueueActionItem.status)"
                            type="button"
                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-neutral-800"
                            @click="updateQueueStatus(activeQueueActionItem, 'call')"
                        >
                            {{ $t('reservations.queue.actions.call') }}
                        </button>
                        <button
                            v-if="['checked_in', 'pre_called', 'called'].includes(activeQueueActionItem.status)"
                            type="button"
                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-sky-700 hover:bg-sky-50 dark:text-sky-300 dark:hover:bg-neutral-800"
                            @click="updateQueueStatus(activeQueueActionItem, 'start')"
                        >
                            {{ $t('reservations.queue.actions.start') }}
                        </button>
                        <button
                            v-if="['in_service', 'called'].includes(activeQueueActionItem.status)"
                            type="button"
                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-neutral-800"
                            @click="openQueueCheckout(activeQueueActionItem)"
                        >
                            {{ $t('reservations.queue.actions.finish_checkout') }}
                        </button>
                        <button
                            v-if="activeQueueActionItem.status === 'awaiting_payment'"
                            type="button"
                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-neutral-800"
                            @click="openQueueCheckout(activeQueueActionItem)"
                        >
                            {{ $t('reservations.queue.actions.checkout') }}
                        </button>
                        <button
                            v-if="activeQueueActionItem.status === 'skipped'"
                            type="button"
                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-700 hover:bg-stone-50 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            @click="updateQueueStatus(activeQueueActionItem, 'done')"
                        >
                            {{ $t('reservations.queue.actions.done_without_payment') }}
                        </button>
                        <button
                            v-if="['checked_in', 'pre_called', 'called'].includes(activeQueueActionItem.status)"
                            type="button"
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
                                <div
                                    v-if="entry.can_update_status"
                                    class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex"
                                >
                                    <button
                                        type="button"
                                        class="size-7 inline-flex items-center justify-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:pointer-events-none disabled:opacity-50 focus:bg-stone-50 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                        aria-haspopup="menu"
                                        aria-expanded="false"
                                        aria-label="Dropdown"
                                        :disabled="waitlistUpdatingId === Number(entry.id)"
                                    >
                                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="1" />
                                            <circle cx="12" cy="5" r="1" />
                                            <circle cx="12" cy="19" r="1" />
                                        </svg>
                                    </button>
                                    <div
                                        class="hs-dropdown-menu hs-dropdown-open:opacity-100 hidden w-44 rounded-sm bg-white opacity-0 shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] transition-[opacity,margin] duration dark:bg-neutral-900 dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)]"
                                        role="menu"
                                        aria-orientation="vertical"
                                    >
                                        <div class="p-1">
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
                                        </div>
                                    </div>
                                </div>
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
                    <div class="flex flex-col lg:flex-row lg:items-center gap-2">
                        <div class="flex-1">
                            <div class="relative">
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
                                    class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                                    :placeholder="$t('reservations.filters.search_placeholder')"
                                >
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 justify-end">
                            <div
                                v-if="scopeOptions.length > 1"
                                class="inline-flex items-center rounded-sm border border-stone-200 bg-white p-0.5 text-xs font-semibold text-stone-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                            >
                                <button
                                    v-for="option in scopeOptions"
                                    :key="`reservation-scope-${option.value}`"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5"
                                    :class="filterForm.scope === option.value
                                        ? 'bg-green-600 text-white shadow-sm dark:bg-white dark:text-stone-900'
                                        : 'text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100'"
                                    @click="filterForm.scope = option.value"
                                >
                                    {{ option.label }}
                                </button>
                            </div>

                            <div class="inline-flex items-center rounded-sm border border-stone-200 bg-white p-0.5 text-xs font-semibold text-stone-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5"
                                    :class="viewMode === 'calendar'
                                        ? 'bg-green-600 text-white shadow-sm dark:bg-white dark:text-stone-900'
                                        : 'text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100'"
                                    @click="viewMode = 'calendar'"
                                >
                                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" />
                                        <line x1="16" y1="2" x2="16" y2="6" />
                                        <line x1="8" y1="2" x2="8" y2="6" />
                                        <line x1="3" y1="10" x2="21" y2="10" />
                                    </svg>
                                    {{ $t('planning.calendar.month') }}
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5"
                                    :class="viewMode === 'list'
                                        ? 'bg-green-600 text-white shadow-sm dark:bg-white dark:text-stone-900'
                                        : 'text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100'"
                                    @click="viewMode = 'list'"
                                >
                                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M8 6h13" />
                                        <path d="M8 12h13" />
                                        <path d="M8 18h13" />
                                        <path d="M3 6h.01" />
                                        <path d="M3 12h.01" />
                                        <path d="M3 18h.01" />
                                    </svg>
                                    {{ $t('reservations.view.list') }}
                                </button>
                            </div>

                            <button
                                type="button"
                                class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-100 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700"
                                @click="showAdvanced = !showAdvanced"
                            >
                                <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" />
                                </svg>
                                {{ $t('reservations.actions.filters') }}
                            </button>

                            <button
                                type="button"
                                class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-100 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700"
                                @click="clearFilters"
                            >
                                <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M3 6h18" />
                                    <path d="M8 6V4h8v2" />
                                    <path d="M19 6l-1 14H6L5 6" />
                                    <path d="M10 11v6" />
                                    <path d="M14 11v6" />
                                </svg>
                                {{ $t('reservations.actions.clear_filters') }}
                            </button>
                        </div>
                    </div>

                    <div v-if="showAdvanced" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-2">
                        <FloatingSelect v-model="filterForm.status" :options="statusOptions" :label="$t('reservations.filters.status')" dense />
                        <FloatingSelect v-model="filterForm.service_id" :options="serviceOptions" :label="$t('reservations.form.item')" dense />
                        <FloatingSelect
                            v-if="showTeamFilters"
                            v-model="filterForm.team_member_id"
                            :options="teamOptions"
                            :label="$t('planning.form.member')"
                            :disabled="filterForm.scope === 'mine'"
                            dense
                        />
                        <FloatingInput v-model="filterForm.date_from" type="date" :label="$t('reservations.filters.date_from')" />
                        <FloatingInput v-model="filterForm.date_to" type="date" :label="$t('reservations.filters.date_to')" />
                    </div>
                </div>
            </section>

            <ReservationCalendarBoard
                v-if="activeDataTab === 'reservations' && viewMode === 'calendar'"
                :events="calendarEvents"
                :loading="calendarLoading"
                :error="calendarError"
                :empty-label="$t('reservations.empty')"
                :selected-event-id="activeReservation?.id || null"
                :loading-label="$t('planning.filters.loading')"
                @range-change="onCalendarRangeChange"
                @event-click="openFromEvent"
            />

            <section v-else-if="activeDataTab === 'reservations'" class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
                <AdminDataTable
                    embedded
                    :rows="reservationRows"
                    :links="reservationLinks"
                    :show-pagination="reservationRows.length > 0"
                    show-per-page
                    :per-page="currentPerPage"
                >
                    <template #head>
                        <tr>
                            <th scope="col" class="min-w-52">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-x-1 px-5 py-2.5 text-start text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300"
                                    @click="toggleDateSort"
                                >
                                    {{ $t('reservations.table.when') }}
                                    <svg
                                        v-if="isDateSort"
                                        class="size-3"
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        :class="isDateSortAsc ? 'rotate-180' : ''"
                                    >
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="min-w-44 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.table.item') }}
                            </th>
                            <th scope="col" class="min-w-52 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.table.customer') }}
                            </th>
                            <th scope="col" class="min-w-40 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('planning.form.member') }}
                            </th>
                            <th scope="col" class="min-w-32">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-x-1 px-5 py-2.5 text-start text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300"
                                    @click="setStatusSort"
                                >
                                    {{ $t('reservations.table.status') }}
                                    <svg
                                        v-if="isStatusSort"
                                        class="size-3"
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                    >
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="min-w-20 px-5 py-2.5 text-end text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.table.actions') }}
                            </th>
                        </tr>
                    </template>

                    <template #row="{ row: reservation }">
                        <tr>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <button type="button" class="text-start hover:underline" @click="openDetails(reservation)">
                                    <div class="text-sm text-stone-700 dark:text-neutral-200">{{ formatDateTime(reservation.starts_at) }}</div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ formatDateTime(reservation.ends_at) }}</div>
                                </button>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">{{ reservation.service?.name || '-' }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">{{ reservationClientName(reservation) }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">{{ reservationMemberName(reservation) }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadgeClass(reservation.status)">
                                    {{ $t(`reservations.status.${reservation.status}`) || reservation.status?.replace(/_/g, ' ') }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-end">
                                <div class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                                    <button
                                        type="button"
                                        class="size-7 inline-flex items-center justify-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:pointer-events-none disabled:opacity-50 focus:bg-stone-50 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                        aria-haspopup="menu"
                                        aria-expanded="false"
                                        aria-label="Dropdown"
                                    >
                                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="1" />
                                            <circle cx="12" cy="5" r="1" />
                                            <circle cx="12" cy="19" r="1" />
                                        </svg>
                                    </button>

                                    <div
                                        class="hs-dropdown-menu hs-dropdown-open:opacity-100 hidden w-32 rounded-sm bg-white opacity-0 shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] transition-[opacity,margin] duration dark:bg-neutral-900 dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)]"
                                        role="menu"
                                        aria-orientation="vertical"
                                    >
                                        <div class="p-1">
                                            <button
                                                type="button"
                                                class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                                @click="openDetails(reservation)"
                                            >
                                                {{ $t('reservations.actions.view') }}
                                            </button>
                                            <button
                                                v-if="canManageReservationActions"
                                                type="button"
                                                class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                                @click="openEdit(reservation)"
                                            >
                                                {{ $t('reservations.actions.edit') }}
                                            </button>
                                            <div v-if="canManageReservationActions" class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                            <button
                                                v-if="canManageReservationActions"
                                                type="button"
                                                class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800"
                                                @click="removeReservation(reservation)"
                                            >
                                                {{ $t('reservations.actions.delete') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>

                    <template #empty>
                        <div class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                            {{ $t('reservations.empty') }}
                        </div>
                    </template>

                    <template #pagination_prefix>
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ reservationPaginationLabel }}
                        </div>
                    </template>
                </AdminDataTable>
            </section>
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

        <Modal :show="showEditor" maxWidth="3xl" @close="showEditor = false">
            <div class="p-5">
                <h2 class="text-sm font-semibold">{{ activeReservation ? $t('reservations.form.edit_title') : $t('reservations.form.create_title') }}</h2>
                <form class="mt-3 space-y-3" @submit.prevent="submitReservation">
                    <div class="grid gap-3 md:grid-cols-3">
                        <div>
                            <FloatingSelect v-model="reservationForm.team_member_id" :options="teamOptions.slice(1)" :label="$t('planning.form.member')" />
                            <InputError class="mt-1" :message="reservationForm.errors.team_member_id" />
                        </div>
                        <div>
                            <FloatingSelect v-model="reservationForm.client_id" :options="clientOptions" :label="$t('reservations.form.customer')" />
                            <InputError class="mt-1" :message="reservationForm.errors.client_id" />
                        </div>
                        <div>
                            <FloatingSelect v-model="reservationForm.service_id" :options="serviceOptions.slice(1)" :label="$t('reservations.form.item')" />
                            <InputError class="mt-1" :message="reservationForm.errors.service_id" />
                        </div>
                    </div>
                    <div class="grid gap-3 md:grid-cols-4">
                        <div>
                            <FloatingInput v-model="reservationForm.starts_at" type="datetime-local" :label="$t('reservations.form.starts_at')" />
                            <InputError class="mt-1" :message="reservationForm.errors.starts_at" />
                        </div>
                        <div>
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
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-sm border border-stone-200 px-3 py-2 text-xs dark:border-neutral-700"
                            @click="showEditor = false"
                        >
                            {{ $t('quotes.form.cancel') }}
                        </button>
                        <button
                            type="submit"
                            class="rounded-sm bg-emerald-600 px-3 py-2 text-xs text-white disabled:opacity-50"
                            :disabled="reservationForm.processing"
                        >
                            {{ reservationForm.processing
                                ? $t('reservations.actions.update')
                                : (activeReservation ? $t('reservations.actions.update') : $t('reservations.actions.create')) }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <Modal :show="showDetails" maxWidth="2xl" @close="showDetails = false">
            <div v-if="activeReservation" class="p-5">
                <h2 class="text-sm font-semibold">{{ $t('reservations.client.index.details_title') }}</h2>
                <div class="mt-3 space-y-2 text-sm">
                    <div>{{ $t('reservations.table.when') }}: {{ formatDateTime(activeReservation.starts_at) }} - {{ formatDateTime(activeReservation.ends_at) }}</div>
                    <div>{{ $t('reservations.table.item') }}: {{ activeReservation.service?.name || '-' }}</div>
                    <div>{{ $t('planning.form.member') }}: {{ reservationMemberName(activeReservation) }}</div>
                    <div>
                        {{ $t('reservations.table.status') }}:
                        <span class="ml-1 rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadgeClass(activeReservation.status)">
                            {{ $t(`reservations.status.${activeReservation.status}`) || activeReservation.status?.replace(/_/g, ' ') }}
                        </span>
                    </div>
                    <div>{{ $t('reservations.client.book.fields.client_notes') }}: {{ activeReservation.client_notes || '-' }}</div>
                    <div>{{ $t('reservations.form.internal_notes') }}: {{ activeReservation.internal_notes || '-' }}</div>
                    <div v-if="activeReservation.prospect_id" class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100">
                        <div class="font-semibold">Reservation publique</div>
                        <div class="mt-1">{{ publicBookingContact.name || '-' }} · {{ publicBookingContact.email || '-' }} · {{ publicBookingContact.phone || '-' }}</div>
                        <div v-if="publicBookingContact.link" class="mt-1 text-emerald-700 dark:text-emerald-200">{{ publicBookingContact.link }}</div>
                    </div>
                    <div v-if="isPublicBookingProspect" class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-semibold text-stone-800 dark:text-neutral-100">Conversion client</div>
                                <div class="mt-1 text-stone-500 dark:text-neutral-400">Verifiez les doublons avant de creer un nouveau client.</div>
                            </div>
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 px-2 py-1 text-[11px] dark:border-neutral-700"
                                :disabled="conversionLoading"
                                @click="loadPublicBookingConversion"
                            >
                                {{ conversionLoading ? 'Chargement...' : 'Verifier' }}
                            </button>
                        </div>

                        <div v-if="conversionPayload?.matches?.length" class="mt-3 space-y-2">
                            <div
                                v-for="match in conversionPayload.matches"
                                :key="`customer-match-${match.id}`"
                                class="flex items-center justify-between gap-3 rounded-sm border border-white bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-900"
                            >
                                <div>
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">{{ match.display_name || `#${match.id}` }}</div>
                                    <div class="text-stone-500 dark:text-neutral-400">{{ match.email || '-' }} · {{ match.phone || '-' }}</div>
                                </div>
                                <button
                                    type="button"
                                    class="rounded-sm bg-emerald-600 px-2 py-1 text-[11px] font-semibold text-white disabled:opacity-50"
                                    :disabled="conversionSubmitting"
                                    @click="convertPublicBooking('link_existing', match.id)"
                                >
                                    Lier
                                </button>
                            </div>
                        </div>

                        <div class="mt-3 grid gap-2 md:grid-cols-2">
                            <FloatingInput v-model="conversionForm.contact_name" label="Nom du client" />
                            <FloatingInput v-model="conversionForm.contact_email" type="email" label="Email" />
                            <FloatingInput v-model="conversionForm.contact_phone" label="Telephone" />
                            <FloatingInput v-model="conversionForm.company_name" label="Entreprise (optionnel)" />
                        </div>
                        <InputError class="mt-2" :message="conversionForm.errors.customer_id || conversionForm.errors.contact_email || conversionForm.errors.contact_phone || conversionForm.errors.contact_name" />
                        <div v-if="conversionError" class="mt-2 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-rose-700">{{ conversionError }}</div>
                        <div v-if="conversionSuccess" class="mt-2 rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-emerald-700">{{ conversionSuccess }}</div>
                        <div class="mt-3 flex justify-end">
                            <button
                                type="button"
                                class="rounded-sm bg-stone-900 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50 dark:bg-white dark:text-neutral-900"
                                :disabled="conversionSubmitting"
                                @click="convertPublicBooking('create_new')"
                            >
                                {{ conversionSubmitting ? 'Conversion...' : 'Creer le client' }}
                            </button>
                        </div>
                    </div>
                    <div v-if="hasPaymentPolicy" class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="font-semibold text-stone-700 dark:text-neutral-200">{{ $t('reservations.payment_policy.title') }}</div>
                        <div class="mt-1 text-stone-600 dark:text-neutral-300">
                            {{ $t('reservations.payment_policy.deposit') }}:
                            <template v-if="activePaymentPolicy.deposit_required">
                                {{ formatMoney(activePaymentPolicy.deposit_amount) }}
                                <span class="capitalize">({{ activePaymentState.deposit_status || '-' }})</span>
                            </template>
                            <template v-else>{{ $t('reservations.payment_policy.none') }}</template>
                        </div>
                        <div class="mt-1 text-stone-600 dark:text-neutral-300">
                            {{ $t('reservations.payment_policy.no_show_fee') }}:
                            <template v-if="activePaymentPolicy.no_show_fee_enabled">
                                {{ formatMoney(activePaymentPolicy.no_show_fee_amount) }}
                                <span class="capitalize">({{ activePaymentState.no_show_fee_status || '-' }})</span>
                            </template>
                            <template v-else>{{ $t('reservations.payment_policy.none') }}</template>
                        </div>
                    </div>
                </div>
                <div v-if="detailsActionError" class="mt-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                    {{ detailsActionError }}
                </div>
                <div class="mt-4 flex flex-wrap justify-end gap-2">
                    <button
                        v-if="canUpdateStatus && canConfirmStatus(activeReservation.status)"
                        type="button"
                        class="rounded-sm bg-emerald-600 px-3 py-2 text-xs text-white"
                        @click="updateStatus('confirmed')"
                    >
                        {{ $t('reservations.actions.confirm') }}
                    </button>
                    <button
                        v-else-if="isConfirmedStatus(activeReservation.status)"
                        type="button"
                        class="cursor-not-allowed rounded-sm bg-emerald-200 px-3 py-2 text-xs text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300"
                        :title="$t('reservations.actions.already_confirmed')"
                        disabled
                    >
                        {{ $t('reservations.actions.confirm') }}
                    </button>
                    <button
                        v-if="canUpdateStatus && canSetPendingStatus(activeReservation.status)"
                        type="button"
                        class="rounded-sm bg-amber-500 px-3 py-2 text-xs text-white"
                        @click="updateStatus('pending')"
                    >
                        {{ $t('reservations.actions.set_pending') }}
                    </button>
                    <button
                        v-if="canUpdateStatus && canCompleteReservation(activeReservation)"
                        type="button"
                        class="rounded-sm bg-sky-600 px-3 py-2 text-xs text-white"
                        @click="updateStatus('completed')"
                    >
                        {{ $t('reservations.actions.complete') }}
                    </button>
                    <button
                        v-if="canUpdateStatus && canMarkNoShow(activeReservation)"
                        type="button"
                        class="rounded-sm bg-stone-600 px-3 py-2 text-xs text-white dark:bg-neutral-700"
                        @click="updateStatus('no_show')"
                    >
                        {{ $t('reservations.actions.no_show') }}
                    </button>
                    <button
                        v-if="canUpdateStatus && canCancelStatus(activeReservation.status)"
                        type="button"
                        class="rounded-sm bg-rose-600 px-3 py-2 text-xs text-white"
                        @click="updateStatus('cancelled')"
                    >
                        {{ cancelActionLabel }}
                    </button>
                    <button
                        v-if="canManageReservationActions"
                        type="button"
                        class="rounded-sm border border-stone-200 px-3 py-2 text-xs dark:border-neutral-700"
                        @click="openEdit(activeReservation); showDetails = false"
                    >
                        {{ $t('reservations.actions.edit') }}
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
