<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import dayjs from 'dayjs';
import 'dayjs/locale/fr';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import ReservationCalendarBoard from '@/Components/Reservation/ReservationCalendarBoard.vue';
import ReservationStats from '@/Components/Reservation/ReservationStats.vue';
import { reservationStatusBadgeClass } from '@/Components/Reservation/status';

const { t, locale } = useI18n();
const dayjsLocale = computed(() => (String(locale.value || '').toLowerCase().startsWith('fr') ? 'fr' : 'en'));

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
const queueActionError = ref('');
const queueActionSuccess = ref('');
const queueUpdatingId = ref(null);
const queueCallingNext = ref(false);
const canViewAll = computed(() => Boolean(props.access?.can_view_all));
const canManage = computed(() => Boolean(props.access?.can_manage));
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
const reservationTabCount = computed(() => Number(props.reservations?.total ?? props.reservations?.data?.length ?? 0));
const activeDataTab = ref('reservations');
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
    || '-'
);
const formatMoney = (value) => Number(value || 0).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});
const toLocalInput = (value) => (value ? dayjs(value).format('YYYY-MM-DDTHH:mm') : '');
const isPast = (value) => (value ? dayjs(value).isBefore(dayjs()) : false);
const activePaymentPolicy = computed(() => activeReservation.value?.metadata?.payment_policy || {});
const activePaymentState = computed(() => activeReservation.value?.metadata?.payment_state || {});
const hasPaymentPolicy = computed(() => (
    Boolean(activePaymentPolicy.value?.deposit_required)
    || Boolean(activePaymentPolicy.value?.no_show_fee_enabled)
));

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
    if (!canManage.value) {
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
    if (!canManage.value) {
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
    if (!canManage.value) {
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
    activeReservation.value = reservation;
    showDetails.value = true;
};

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
    return 'reservation.queue.skip';
};

const updateQueueStatus = async (item, action) => {
    if (!item?.id || !item?.can_update_status) {
        return;
    }

    queueActionError.value = '';
    queueActionSuccess.value = '';
    queueUpdatingId.value = Number(item.id);

    try {
        const payload = {};
        if (!item.team_member_id && ownTeamMemberId.value) {
            payload.team_member_id = Number(ownTeamMemberId.value);
        } else if (!item.team_member_id && item.recommended_team_member_id) {
            payload.team_member_id = Number(item.recommended_team_member_id);
        }

        const response = await axios.patch(route(queueActionRouteName(action), item.id), payload);
        const updated = response?.data?.queue_item || { ...item };
        queueRows.value = queueRows.value.map((row) => (
            Number(row.id) === Number(item.id) ? { ...row, ...updated } : row
        ));
        queueActionSuccess.value = response?.data?.message || t('reservations.queue.actions.updated');
        refreshList();
    } catch (error) {
        queueActionError.value = error?.response?.data?.message || t('reservations.queue.actions.update_error');
    } finally {
        queueUpdatingId.value = null;
    }
};

const callNextQueueItem = async () => {
    queueActionError.value = '';
    queueActionSuccess.value = '';
    queueCallingNext.value = true;

    try {
        const payload = {};
        if (queueAssignmentMode.value === 'per_staff') {
            if (ownTeamMemberId.value) {
                payload.team_member_id = Number(ownTeamMemberId.value);
            } else if (filterForm.team_member_id) {
                payload.team_member_id = Number(filterForm.team_member_id);
            }
        }

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
        queueActionError.value = error?.response?.data?.message || t('reservations.queue.actions.call_next_empty');
    } finally {
        queueCallingNext.value = false;
    }
};

const removeReservation = (reservation) => {
    if (!canManage.value) {
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

const goToPage = (url) => {
    if (!url) {
        return;
    }

    router.visit(url, {
        preserveState: true,
        preserveScroll: true,
        only: ['filters', 'reservations', 'stats', 'performance', 'waitlists', 'waitlistStats', 'queueItems', 'queueStats'],
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
                            v-if="canManage"
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
            </section>

            <ReservationStats :stats="stats" :performance="performance" />

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
                <div v-if="queueActionSuccess" class="mt-3 rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
                    {{ queueActionSuccess }}
                </div>

                <div v-if="!queueRows.length" class="mt-3 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-4 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                    {{ $t('reservations.queue.empty') }}
                </div>

                <div
                    v-else
                    class="mt-3 overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500"
                >
                    <div class="min-w-full inline-block align-middle">
                        <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                            <thead>
                                <tr>
                                    <th scope="col" class="min-w-36">
                                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                            {{ $t('reservations.queue.columns.ticket') }}
                                        </div>
                                    </th>
                                    <th scope="col" class="min-w-52">
                                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                            {{ $t('reservations.table.customer') }}
                                        </div>
                                    </th>
                                    <th scope="col" class="min-w-28">
                                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                            {{ $t('reservations.queue.columns.origin') }}
                                        </div>
                                    </th>
                                    <th scope="col" class="min-w-48">
                                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                            {{ $t('reservations.table.item') }}
                                        </div>
                                    </th>
                                    <th scope="col" class="min-w-40">
                                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                            {{ $t('planning.form.member') }}
                                        </div>
                                    </th>
                                    <th scope="col" class="min-w-32">
                                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                            {{ $t('reservations.queue.columns.position') }}
                                        </div>
                                    </th>
                                    <th scope="col" class="min-w-28">
                                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                            {{ $t('reservations.table.status') }}
                                        </div>
                                    </th>
                                    <th scope="col" class="min-w-20 text-end">
                                        <div class="px-5 py-2.5 text-end text-sm font-normal text-stone-500 dark:text-neutral-500">
                                            {{ $t('reservations.table.actions') }}
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                <tr
                                    v-for="item in queueRows"
                                    :key="`queue-item-${item.id}`"
                                >
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
                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadgeClass(item.status)">
                                            {{ $t(`reservations.queue.status.${item.status}`) || item.status }}
                                        </span>
                                    </td>
                                    <td class="size-px whitespace-nowrap px-4 py-2 text-end align-top">
                                        <div
                                            v-if="item.can_update_status"
                                            class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex"
                                        >
                                            <button
                                                type="button"
                                                class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                                aria-haspopup="menu"
                                                aria-expanded="false"
                                                aria-label="Dropdown"
                                                :disabled="queueUpdatingId === Number(item.id)"
                                            >
                                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <circle cx="12" cy="12" r="1" />
                                                    <circle cx="12" cy="5" r="1" />
                                                    <circle cx="12" cy="19" r="1" />
                                                </svg>
                                            </button>
                                            <div
                                                class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                                role="menu"
                                                aria-orientation="vertical"
                                            >
                                                <div class="p-1">
                                                    <button
                                                        v-if="item.status === 'not_arrived'"
                                                        type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-indigo-700 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-neutral-800"
                                                        @click="updateQueueStatus(item, 'check-in')"
                                                    >
                                                        {{ $t('reservations.queue.actions.check_in') }}
                                                    </button>
                                                    <button
                                                        v-if="['checked_in', 'skipped'].includes(item.status)"
                                                        type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-indigo-700 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-neutral-800"
                                                        @click="updateQueueStatus(item, 'pre-call')"
                                                    >
                                                        {{ $t('reservations.queue.actions.pre_call') }}
                                                    </button>
                                                    <button
                                                        v-if="['checked_in', 'pre_called', 'skipped'].includes(item.status)"
                                                        type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-neutral-800"
                                                        @click="updateQueueStatus(item, 'call')"
                                                    >
                                                        {{ $t('reservations.queue.actions.call') }}
                                                    </button>
                                                    <button
                                                        v-if="['checked_in', 'pre_called', 'called'].includes(item.status)"
                                                        type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-sky-700 hover:bg-sky-50 dark:text-sky-300 dark:hover:bg-neutral-800"
                                                        @click="updateQueueStatus(item, 'start')"
                                                    >
                                                        {{ $t('reservations.queue.actions.start') }}
                                                    </button>
                                                    <button
                                                        v-if="['in_service', 'called'].includes(item.status)"
                                                        type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-neutral-800"
                                                        @click="updateQueueStatus(item, 'done')"
                                                    >
                                                        {{ $t('reservations.queue.actions.done') }}
                                                    </button>
                                                    <button
                                                        v-if="['checked_in', 'pre_called', 'called'].includes(item.status)"
                                                        type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-rose-700 hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-neutral-800"
                                                        @click="updateQueueStatus(item, 'skip')"
                                                    >
                                                        {{ $t('reservations.queue.actions.skip') }}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <span v-else class="text-xs text-stone-400 dark:text-neutral-500">-</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
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

                <div v-if="!waitlistRows.length" class="mt-3 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-4 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                    {{ $t('reservations.waitlist.empty') }}
                </div>

                <div
                    v-else
                    class="mt-3 overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500"
                >
                    <div class="min-w-full inline-block align-middle">
                        <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                            <thead>
                                <tr>
                                    <th scope="col" class="min-w-48">
                                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                            {{ $t('reservations.table.when') }}
                                        </div>
                                    </th>
                                    <th scope="col" class="min-w-52">
                                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                            {{ $t('reservations.table.customer') }}
                                        </div>
                                    </th>
                                    <th scope="col" class="min-w-48">
                                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                            {{ $t('reservations.table.item') }}
                                        </div>
                                    </th>
                                    <th scope="col" class="min-w-40">
                                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                            {{ $t('planning.form.member') }}
                                        </div>
                                    </th>
                                    <th scope="col" class="min-w-28">
                                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                            {{ $t('reservations.table.status') }}
                                        </div>
                                    </th>
                                    <th scope="col" class="min-w-20 text-end">
                                        <div class="px-5 py-2.5 text-end text-sm font-normal text-stone-500 dark:text-neutral-500">
                                            {{ $t('reservations.table.actions') }}
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                <tr
                                    v-for="entry in waitlistRows"
                                    :key="`waitlist-${entry.id}`"
                                >
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
                                                class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
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
                                                class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-44 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                                role="menu"
                                                aria-orientation="vertical"
                                            >
                                                <div class="p-1">
                                                    <button
                                                        v-if="entry.status === 'pending'"
                                                        type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-indigo-700 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-neutral-800"
                                                        @click="updateWaitlistStatus(entry, 'released')"
                                                    >
                                                        {{ $t('reservations.waitlist.actions.release') }}
                                                    </button>
                                                    <button
                                                        v-if="entry.status === 'released'"
                                                        type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-neutral-800"
                                                        @click="updateWaitlistStatus(entry, 'booked')"
                                                    >
                                                        {{ $t('reservations.waitlist.actions.booked') }}
                                                    </button>
                                                    <button
                                                        v-if="['pending', 'released'].includes(entry.status)"
                                                        type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-rose-700 hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-neutral-800"
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
                            </tbody>
                        </table>
                    </div>
                </div>
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
                <div v-if="!reservations?.data?.length" class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                    {{ $t('reservations.empty') }}
                </div>

                <template v-else>
                    <div class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                        <div class="min-w-full inline-block align-middle">
                            <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                                <thead>
                                    <tr>
                                        <th scope="col" class="min-w-52">
                                            <button
                                                type="button"
                                                class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300"
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
                                        <th scope="col" class="min-w-44">
                                            <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                                {{ $t('reservations.table.item') }}
                                            </div>
                                        </th>
                                        <th scope="col" class="min-w-52">
                                            <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                                {{ $t('reservations.table.customer') }}
                                            </div>
                                        </th>
                                        <th scope="col" class="min-w-40">
                                            <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                                {{ $t('planning.form.member') }}
                                            </div>
                                        </th>
                                        <th scope="col" class="min-w-32">
                                            <button
                                                type="button"
                                                class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300"
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
                                        <th scope="col" class="min-w-20 text-end">
                                            <div class="px-5 py-2.5 text-end text-sm font-normal text-stone-500 dark:text-neutral-500">
                                                {{ $t('reservations.table.actions') }}
                                            </div>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                    <tr
                                        v-for="reservation in reservations.data"
                                        :key="reservation.id"
                                    >
                                        <td class="size-px whitespace-nowrap px-4 py-2">
                                            <button type="button" class="text-start hover:underline" @click="openDetails(reservation)">
                                                <div class="text-sm text-stone-700 dark:text-neutral-200">{{ formatDateTime(reservation.starts_at) }}</div>
                                                <div class="text-xs text-stone-500 dark:text-neutral-400">{{ formatDateTime(reservation.ends_at) }}</div>
                                            </button>
                                        </td>
                                        <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">{{ reservation.service?.name || '-' }}</td>
                                        <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">{{ reservationClientName(reservation) }}</td>
                                        <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">{{ reservation.team_member?.user?.name || '-' }}</td>
                                        <td class="size-px whitespace-nowrap px-4 py-2">
                                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadgeClass(reservation.status)">
                                                {{ $t(`reservations.status.${reservation.status}`) || reservation.status?.replace(/_/g, ' ') }}
                                            </span>
                                        </td>
                                        <td class="size-px whitespace-nowrap px-4 py-2 text-end">
                                            <div class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                                                <button
                                                    type="button"
                                                    class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
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
                                                    class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-32 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                                    role="menu"
                                                    aria-orientation="vertical"
                                                >
                                                    <div class="p-1">
                                                        <button
                                                            type="button"
                                                            class="w-full text-start flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                                            @click="openDetails(reservation)"
                                                        >
                                                            {{ $t('reservations.actions.view') }}
                                                        </button>
                                                        <button
                                                            v-if="canManage"
                                                            type="button"
                                                            class="w-full text-start flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                                            @click="openEdit(reservation)"
                                                        >
                                                            {{ $t('reservations.actions.edit') }}
                                                        </button>
                                                        <div v-if="canManage" class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                                        <button
                                                            v-if="canManage"
                                                            type="button"
                                                            class="w-full text-start flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800"
                                                            @click="removeReservation(reservation)"
                                                        >
                                                            {{ $t('reservations.actions.delete') }}
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                        <div>
                            {{ $t('reservations.pagination.showing', { from: reservations.from || 0, to: reservations.to || 0 }) }}
                        </div>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 px-3 py-1 disabled:opacity-50 dark:border-neutral-700"
                                :disabled="!reservations.prev_page_url"
                                @click="goToPage(reservations.prev_page_url)"
                            >
                                {{ $t('reservations.pagination.previous') }}
                            </button>
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 px-3 py-1 disabled:opacity-50 dark:border-neutral-700"
                                :disabled="!reservations.next_page_url"
                                @click="goToPage(reservations.next_page_url)"
                            >
                                {{ $t('reservations.pagination.next') }}
                            </button>
                        </div>
                    </div>
                </template>
            </section>
        </div>

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
                    <div>{{ $t('planning.form.member') }}: {{ activeReservation.team_member?.user?.name || activeReservation.teamMember?.user?.name || '-' }}</div>
                    <div>
                        {{ $t('reservations.table.status') }}:
                        <span class="ml-1 rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadgeClass(activeReservation.status)">
                            {{ $t(`reservations.status.${activeReservation.status}`) || activeReservation.status?.replace(/_/g, ' ') }}
                        </span>
                    </div>
                    <div>{{ $t('reservations.client.book.fields.client_notes') }}: {{ activeReservation.client_notes || '-' }}</div>
                    <div>{{ $t('reservations.form.internal_notes') }}: {{ activeReservation.internal_notes || '-' }}</div>
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
                        v-if="canManage"
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
