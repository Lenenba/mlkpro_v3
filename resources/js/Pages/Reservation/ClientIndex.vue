<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import dayjs from 'dayjs';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ClientPortalTabs from '@/Components/Portal/ClientPortalTabs.vue';
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

const { t } = useI18n();

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
    waitlistEntries: {
        type: Array,
        default: () => [],
    },
    queueTickets: {
        type: Array,
        default: () => [],
    },
    timezone: {
        type: String,
        default: 'UTC',
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
const calendarRange = ref({
    start: dayjs().startOf('month').toISOString(),
    end: dayjs().endOf('month').toISOString(),
});
const detailsActionError = ref('');
const queueTickets = ref([...(props.queueTickets || [])]);
const queueActionError = ref('');
const queueActionSuccess = ref('');

const filterForm = useForm({
    status: props.filters?.status || '',
    date_from: props.filters?.date_from || '',
    date_to: props.filters?.date_to || '',
    view_mode: props.filters?.view_mode || viewMode.value,
});

const showDetails = ref(false);
const activeReservation = ref(null);

const showReschedule = ref(false);
const rescheduleSlots = ref([]);
const rescheduleLoading = ref(false);
const rescheduleError = ref('');
const rescheduleSubmitting = ref(false);
const rescheduleSelectedSlot = ref(null);
const rescheduleRange = ref({ start: '', end: '' });
const rescheduleTeamMemberId = ref('');
const rescheduleForm = useForm({
    client_notes: '',
});

const showReview = ref(false);
const reviewSubmitting = ref(false);
const reviewError = ref('');
const reviewForm = useForm({
    rating: 5,
    feedback: '',
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

const rescheduleTeamOptions = computed(() => {
    const currentName = activeReservation.value?.team_member?.user?.name
        || activeReservation.value?.teamMember?.user?.name
        || t('reservations.client.index.reschedule_member');

    return [
        { value: '', label: t('reservations.client.index.any_available') },
        { value: String(activeReservation.value?.team_member_id || ''), label: currentName },
    ];
});

const rescheduleCalendarEvents = computed(() => (rescheduleSlots.value || []).map((slot) => ({
    id: `${slot.team_member_id}:${slot.starts_at}`,
    title: slot.team_member_name,
    start: slot.starts_at,
    end: slot.ends_at,
    extendedProps: {
        slot,
        status: 'slot',
    },
})));

const selectedRescheduleEventId = computed(() => {
    if (!rescheduleSelectedSlot.value) {
        return null;
    }

    return `${rescheduleSelectedSlot.value.team_member_id}:${rescheduleSelectedSlot.value.starts_at}`;
});
const reservationRows = computed(() => (Array.isArray(props.reservations?.data) ? props.reservations.data : []));
const reservationLinks = computed(() => props.reservations?.links || []);
const currentPerPage = computed(() => resolveDataTablePerPage(props.reservations?.per_page, props.filters?.per_page));
const reservationPaginationLabel = computed(() => t('reservations.pagination.showing', {
    from: props.reservations?.from || 0,
    to: props.reservations?.to || 0,
}));
const reservationCount = computed(() => Number(props.reservations?.total ?? reservationRows.value.length ?? 0));
const serviceTabs = computed(() => ([
    {
        id: 'reservations',
        label: t('reservations.client.index.title'),
        description: t('reservations.client.index.subtitle'),
        href: route('client.reservations.index'),
        badge: reservationCount.value,
        tone: 'emerald',
        active: true,
    },
    {
        id: 'book',
        label: t('reservations.client.book.title'),
        description: t('reservations.client.book.subtitle'),
        href: route('client.reservations.book'),
        tone: 'indigo',
    },
]));
const serviceOverviewCards = computed(() => ([
    {
        key: 'upcoming',
        label: t('reservations.stats.upcoming'),
        value: Number(props.stats?.upcoming || reservationCount.value),
        meta: t('reservations.client.index.title'),
        tone: 'emerald',
    },
    {
        key: 'today',
        label: t('reservations.stats.today'),
        value: Number(props.stats?.today || 0),
        meta: viewMode.value === 'calendar' ? t('planning.calendar.month') : t('reservations.view.list'),
        tone: 'indigo',
    },
    {
        key: 'queue',
        label: t('reservations.queue.title'),
        value: queueTickets.value.length,
        meta: queueModeEnabled.value ? t('reservations.queue.cards.waiting') : t('reservations.queue.client.none'),
        tone: 'amber',
    },
]));

const overviewTone = {
    emerald: 'from-emerald-500/12 via-emerald-50 to-white text-emerald-700 dark:from-emerald-500/10 dark:via-emerald-500/5 dark:to-neutral-900 dark:text-emerald-200',
    indigo: 'from-indigo-500/12 via-indigo-50 to-white text-indigo-700 dark:from-indigo-500/10 dark:via-indigo-500/5 dark:to-neutral-900 dark:text-indigo-200',
    amber: 'from-amber-500/12 via-amber-50 to-white text-amber-700 dark:from-amber-500/10 dark:via-amber-500/5 dark:to-neutral-900 dark:text-amber-200',
};

const statusBadgeClass = (status) => reservationStatusBadgeClass(status);
const formatDateTime = (value) => (value ? dayjs(value).format('MMM D, YYYY HH:mm') : '-');
const queueModeEnabled = computed(() => Boolean(props.settings?.queue_mode_enabled));
const ownerOnlyMode = computed(() => Boolean(props.settings?.owner_only_mode));
const slotBookingAvailable = computed(() => Boolean(props.settings?.slot_booking_enabled ?? true));

const isModifyWindowOpen = (reservation) => {
    if (!reservation?.starts_at) {
        return false;
    }

    if (!['pending', 'confirmed', 'rescheduled'].includes(reservation.status || '')) {
        return false;
    }

    const cutoff = Number(props.settings?.cancellation_cutoff_hours || 0);
    if (cutoff <= 0) {
        return true;
    }

    return dayjs().isBefore(dayjs(reservation.starts_at).subtract(cutoff, 'hour'));
};

const canCancel = (reservation) => Boolean(props.settings?.allow_client_cancel) && isModifyWindowOpen(reservation);
const canReschedule = (reservation) => slotBookingAvailable.value
    && Boolean(props.settings?.allow_client_reschedule)
    && isModifyWindowOpen(reservation);
const canReview = (reservation) => {
    if (!reservation) {
        return false;
    }

    return reservation.status === 'completed' && !reservation.review;
};

const ratingOptions = computed(() => ([
    { value: 5, label: `5 - ${t('reservations.client.index.review.labels.excellent')}` },
    { value: 4, label: `4 - ${t('reservations.client.index.review.labels.good')}` },
    { value: 3, label: `3 - ${t('reservations.client.index.review.labels.okay')}` },
    { value: 2, label: `2 - ${t('reservations.client.index.review.labels.poor')}` },
    { value: 1, label: `1 - ${t('reservations.client.index.review.labels.bad')}` },
]));

const loadEvents = async () => {
    calendarLoading.value = true;
    calendarError.value = '';

    try {
        const response = await axios.get(route('client.reservations.events'), {
            params: {
                start: calendarRange.value.start,
                end: calendarRange.value.end,
                status: filterForm.status || undefined,
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
        route('client.reservations.index'),
        {
            status: filterForm.status || undefined,
            date_from: filterForm.date_from || undefined,
            date_to: filterForm.date_to || undefined,
            view_mode: viewMode.value,
            per_page: currentPerPage.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['filters', 'reservations', 'stats', 'queueTickets'],
        }
    );

    loadEvents();
};

const onCalendarRangeChange = (payload) => {
    calendarRange.value = {
        start: payload.start,
        end: payload.end,
    };
    loadEvents();
};

let filterTimer = null;
watch(
    () => [filterForm.status, filterForm.date_from, filterForm.date_to, viewMode.value],
    () => {
        if (filterTimer) {
            clearTimeout(filterTimer);
        }
        filterTimer = setTimeout(refreshList, 300);
    }
);

watch(
    () => props.queueTickets,
    (value) => {
        queueTickets.value = [...(value || [])];
    }
);

const clearFilters = () => {
    filterForm.status = '';
    filterForm.date_from = '';
    filterForm.date_to = '';
};

const openDetails = (reservation) => {
    detailsActionError.value = '';
    activeReservation.value = reservation;
    showDetails.value = true;
};

const openReview = (reservation) => {
    activeReservation.value = reservation;
    reviewForm.reset();
    reviewForm.clearErrors();
    reviewForm.rating = 5;
    reviewForm.feedback = '';
    reviewError.value = '';
    showReview.value = true;
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
    };

    openDetails(reservationMap.value.get(eventId) || fallback);
};

const cancelReservation = async (reservation) => {
    if (!reservation?.id) {
        return;
    }

    detailsActionError.value = '';
    const reason = window.prompt(t('reservations.client.index.cancel_prompt')) || null;

    try {
        await axios.patch(route('client.reservations.cancel', reservation.id), {
            reason,
        });

        showDetails.value = false;
        refreshList();
    } catch (error) {
        detailsActionError.value = error?.response?.data?.message || t('reservations.errors.cancel');
    }
};

const cancelQueueTicket = async (ticket) => {
    if (!ticket?.id || !ticket?.can_cancel) {
        return;
    }

    queueActionError.value = '';
    queueActionSuccess.value = '';

    try {
        const response = await axios.patch(route('client.reservations.tickets.cancel', ticket.id), {}, {
            headers: {
                Accept: 'application/json',
            },
        });
        const updated = response?.data?.ticket || { ...ticket, status: 'left', can_cancel: false, can_still_here: false };
        queueTickets.value = queueTickets.value.map((item) => (
            Number(item.id) === Number(ticket.id) ? { ...item, ...updated } : item
        ));
        queueActionSuccess.value = response?.data?.message || t('reservations.queue.client.cancelled');
    } catch (error) {
        queueActionError.value = error?.response?.data?.message || t('reservations.queue.client.update_error');
    }
};

const stillHereQueueTicket = async (ticket) => {
    if (!ticket?.id || !ticket?.can_still_here) {
        return;
    }

    queueActionError.value = '';
    queueActionSuccess.value = '';

    try {
        const response = await axios.patch(route('client.reservations.tickets.still-here', ticket.id), {}, {
            headers: {
                Accept: 'application/json',
            },
        });
        const updated = response?.data?.ticket || ticket;
        queueTickets.value = queueTickets.value.map((item) => (
            Number(item.id) === Number(ticket.id) ? { ...item, ...updated } : item
        ));
        queueActionSuccess.value = response?.data?.message || t('reservations.queue.client.still_here_done');
    } catch (error) {
        queueActionError.value = error?.response?.data?.message || t('reservations.queue.client.update_error');
    }
};

const loadRescheduleSlots = async () => {
    if (!activeReservation.value?.id || !rescheduleRange.value.start || !rescheduleRange.value.end) {
        return;
    }

    rescheduleLoading.value = true;
    rescheduleError.value = '';

    try {
        const response = await axios.get(route('client.reservations.slots'), {
            params: {
                range_start: rescheduleRange.value.start,
                range_end: rescheduleRange.value.end,
                team_member_id: rescheduleTeamMemberId.value || undefined,
                service_id: activeReservation.value?.service_id || undefined,
                duration_minutes: activeReservation.value?.duration_minutes || undefined,
            },
        });

        rescheduleSlots.value = response?.data?.slots || [];

        if (rescheduleSelectedSlot.value) {
            const exists = rescheduleSlots.value.find((slot) =>
                slot.team_member_id === rescheduleSelectedSlot.value.team_member_id
                && slot.starts_at === rescheduleSelectedSlot.value.starts_at
            );

            if (!exists) {
                rescheduleSelectedSlot.value = null;
            }
        }
    } catch (error) {
        rescheduleError.value = error?.response?.data?.message || t('reservations.errors.load_slots');
    } finally {
        rescheduleLoading.value = false;
    }
};

let rescheduleTimer = null;
const queueRescheduleSlots = () => {
    if (rescheduleTimer) {
        clearTimeout(rescheduleTimer);
    }
    rescheduleTimer = setTimeout(loadRescheduleSlots, 250);
};

watch(rescheduleTeamMemberId, () => {
    queueRescheduleSlots();
});

const onRescheduleRangeChange = (payload) => {
    rescheduleRange.value = {
        start: payload.start,
        end: payload.end,
    };
    queueRescheduleSlots();
};

const onRescheduleEventClick = (rawEvent) => {
    const source = rawEvent?.original || rawEvent;
    const slot = source?.extendedProps?.slot;
    if (slot) {
        rescheduleSelectedSlot.value = slot;
    }
};

const openReschedule = (reservation) => {
    activeReservation.value = reservation;
    rescheduleForm.reset();
    rescheduleForm.clearErrors();
    rescheduleError.value = '';
    rescheduleSelectedSlot.value = null;
    rescheduleTeamMemberId.value = String(reservation?.team_member_id || '');

    const anchor = reservation?.starts_at ? dayjs(reservation.starts_at) : dayjs();
    rescheduleRange.value = {
        start: anchor.startOf('week').toISOString(),
        end: anchor.endOf('week').toISOString(),
    };

    showReschedule.value = true;
    queueRescheduleSlots();
};

const submitReschedule = async () => {
    rescheduleError.value = '';
    rescheduleForm.clearErrors();

    if (!activeReservation.value?.id || !rescheduleSelectedSlot.value) {
        rescheduleError.value = t('reservations.client.index.select_slot_error');
        return;
    }

    rescheduleSubmitting.value = true;

    try {
        await axios.patch(route('client.reservations.reschedule', activeReservation.value.id), {
            team_member_id: Number(rescheduleSelectedSlot.value.team_member_id),
            service_id: activeReservation.value.service_id || null,
            starts_at: rescheduleSelectedSlot.value.starts_at,
            ends_at: rescheduleSelectedSlot.value.ends_at,
            duration_minutes: Number(activeReservation.value.duration_minutes || 60),
            timezone: props.timezone || 'UTC',
            client_notes: rescheduleForm.client_notes || null,
        });

        showReschedule.value = false;
        showDetails.value = false;
        refreshList();
    } catch (error) {
        if (error?.response?.status === 422) {
            rescheduleForm.setError(error.response.data?.errors || {});
            rescheduleError.value = t('reservations.errors.validation');
        } else {
            rescheduleError.value = error?.response?.data?.message || t('reservations.errors.reschedule');
        }
    } finally {
        rescheduleSubmitting.value = false;
    }
};

const submitReview = async () => {
    reviewError.value = '';
    reviewForm.clearErrors();

    if (!activeReservation.value?.id) {
        reviewError.value = t('reservations.client.index.review.errors.generic');
        return;
    }

    reviewSubmitting.value = true;
    try {
        await axios.post(route('client.reservations.review', activeReservation.value.id), {
            rating: Number(reviewForm.rating || 5),
            feedback: reviewForm.feedback || null,
        });

        showReview.value = false;
        showDetails.value = false;
        refreshList();
    } catch (error) {
        if (error?.response?.status === 422) {
            reviewForm.setError(error.response.data?.errors || {});
            reviewError.value = t('reservations.errors.validation');
        } else {
            reviewError.value = error?.response?.data?.message || t('reservations.client.index.review.errors.generic');
        }
    } finally {
        reviewSubmitting.value = false;
    }
};

onBeforeUnmount(() => {
    if (filterTimer) {
        clearTimeout(filterTimer);
    }
    if (rescheduleTimer) {
        clearTimeout(rescheduleTimer);
    }
});
</script>

<template>
    <Head :title="$t('reservations.client.index.title')" />
    <AuthenticatedLayout>
        <div class="space-y-4">
            <section class="overflow-hidden rounded-[2rem] border border-stone-200/80 bg-white shadow-[0_30px_80px_-50px_rgba(15,23,42,0.45)] dark:border-neutral-800 dark:bg-neutral-900">
                <div class="grid gap-0 lg:grid-cols-[1.45fr_0.95fr]">
                    <div class="relative overflow-hidden bg-gradient-to-br from-emerald-600 via-teal-500 to-emerald-400 px-6 py-7 text-white sm:px-8">
                        <div class="absolute -right-8 top-8 h-40 w-40 rounded-full bg-white/10 blur-2xl"></div>
                        <div class="absolute bottom-0 right-20 h-28 w-28 rounded-full border border-white/15"></div>

                        <div class="relative flex h-full flex-col justify-between gap-6">
                            <div class="space-y-4">
                                <div class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em]">
                                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-white/16">
                                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M8 2v4" />
                                            <path d="M16 2v4" />
                                            <rect width="18" height="18" x="3" y="4" rx="2" />
                                            <path d="M3 10h18" />
                                        </svg>
                                    </span>
                                    {{ $t('reservations.client.index.title') }}
                                </div>

                                <div>
                                    <h1 class="text-3xl font-semibold tracking-tight sm:text-[2.1rem]">
                                        {{ $t('reservations.client.index.title') }}
                                    </h1>
                                    <p class="mt-2 max-w-xl text-sm leading-6 text-white/85 sm:text-base">
                                        {{ $t('reservations.client.index.subtitle') }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <Link
                                    :href="route('client.reservations.book')"
                                    class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 text-sm font-semibold text-emerald-700 transition hover:-translate-y-0.5 hover:shadow-md"
                                >
                                    {{ $t('reservations.client.index.book_button') }}
                                </Link>
                                <span class="rounded-full border border-white/20 bg-white/10 px-3 py-2 text-xs font-medium text-white/80">
                                    {{ $t('reservations.stats.upcoming') }}: {{ reservationCount }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col justify-between gap-3 bg-stone-50/80 p-5 dark:bg-neutral-950/70">
                        <article
                            v-for="card in serviceOverviewCards"
                            :key="card.key"
                            class="rounded-[1.4rem] border border-stone-200/80 bg-gradient-to-br px-4 py-4 shadow-sm dark:border-neutral-800"
                            :class="overviewTone[card.tone]"
                        >
                            <p class="text-xs font-semibold uppercase tracking-[0.18em]">
                                {{ card.label }}
                            </p>
                            <p class="mt-2 text-2xl font-semibold text-stone-900 dark:text-white">
                                {{ card.value }}
                            </p>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-300">
                                {{ card.meta }}
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <ClientPortalTabs
                :tabs="serviceTabs"
                aria-label="Service client sections"
                :columns="2"
            />

            <div
                v-if="ownerOnlyMode"
                class="rounded-[1.5rem] border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100"
            >
                {{ $t('reservations.owner_only.client_notice') }}
            </div>

            <ReservationStats :stats="stats" />

            <section
                v-if="false"
                class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('reservations.queue.client.title') }}</h2>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.client.subtitle') }}</p>
                    </div>
                </div>

                <div class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-400/40 dark:bg-amber-500/10 dark:text-amber-100">
                    {{ $t('reservations.queue.client.kiosk_only_notice') }}
                    <a
                        v-if="settings?.kiosk_public_url"
                        :href="settings.kiosk_public_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="ml-1 font-semibold underline"
                    >
                        {{ $t('reservations.kiosk.open') }}
                    </a>
                </div>

                <div v-if="queueActionError" class="mt-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                    {{ queueActionError }}
                </div>
                <div v-if="queueActionSuccess" class="mt-3 rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
                    {{ queueActionSuccess }}
                </div>

                <AdminDataTable embedded :rows="queueTickets" :show-pagination="false">
                    <template #head>
                        <tr>
                            <th scope="col" class="min-w-28 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.queue.columns.ticket') }}
                            </th>
                            <th scope="col" class="min-w-44 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
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
                            <th scope="col" class="min-w-32 px-5 py-2.5 text-end text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.table.actions') }}
                            </th>
                        </tr>
                    </template>

                    <template #row="{ row: ticket }">
                        <tr>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-700 dark:text-neutral-200">{{ ticket.queue_number || `#${ticket.id}` }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-700 dark:text-neutral-200">{{ ticket.service_name || $t('reservations.client.book.default_service') }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-700 dark:text-neutral-200">{{ ticket.team_member_name || $t('reservations.client.index.any_available') }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-700 dark:text-neutral-200">
                                {{ ticket.position ?? '-' }}
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    ETA: {{ ticket.eta_minutes !== null && ticket.eta_minutes !== undefined ? `${ticket.eta_minutes} min` : '-' }}
                                </div>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadgeClass(ticket.status)">
                                    {{ $t(`reservations.queue.status.${ticket.status}`) || ticket.status }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-end">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <button
                                        v-if="ticket.can_still_here"
                                        type="button"
                                        class="text-xs text-indigo-700 underline"
                                        @click="stillHereQueueTicket(ticket)"
                                    >
                                        {{ $t('reservations.queue.client.still_here') }}
                                    </button>
                                    <button
                                        v-if="ticket.can_cancel"
                                        type="button"
                                        class="text-xs text-rose-700 underline"
                                        @click="cancelQueueTicket(ticket)"
                                    >
                                        {{ $t('reservations.actions.cancel') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>

                    <template #empty>
                        <div class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-3 py-3 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                            {{ $t('reservations.queue.client.none') }}
                        </div>
                    </template>
                </AdminDataTable>
            </section>

            <section class="rounded-[1.75rem] border border-stone-200/80 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                <div class="grid gap-3 md:grid-cols-3">
                    <FloatingSelect v-model="filterForm.status" :options="statusOptions" :label="$t('reservations.filters.status')" />
                    <FloatingInput v-model="filterForm.date_from" type="date" :label="$t('reservations.filters.date_from')" />
                    <FloatingInput v-model="filterForm.date_to" type="date" :label="$t('reservations.filters.date_to')" />
                </div>
                <div class="mt-3 flex items-center justify-between gap-2">
                    <div class="inline-flex items-center rounded-full border border-stone-200 bg-stone-50 p-1 text-xs font-semibold text-stone-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-300">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full px-3.5 py-2"
                            :class="viewMode === 'calendar'
                                ? 'bg-emerald-600 text-white shadow-sm dark:bg-white dark:text-stone-900'
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
                            class="inline-flex items-center gap-1.5 rounded-full px-3.5 py-2"
                            :class="viewMode === 'list'
                                ? 'bg-emerald-600 text-white shadow-sm dark:bg-white dark:text-stone-900'
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
                        class="rounded-full border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                        @click="clearFilters"
                    >
                        {{ $t('reservations.actions.clear_filters') }}
                    </button>
                </div>
            </section>

            <ReservationCalendarBoard
                v-if="viewMode === 'calendar'"
                :events="calendarEvents"
                :loading="calendarLoading"
                :error="calendarError"
                :empty-label="$t('reservations.client.index.empty')"
                :selected-event-id="activeReservation?.id || null"
                :loading-label="$t('reservations.client.book.loading_slots')"
                @range-change="onCalendarRangeChange"
                @event-click="openFromEvent"
            />

            <section v-else class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
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
                            <th scope="col" class="min-w-52 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.table.when') }}
                            </th>
                            <th scope="col" class="min-w-44 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.table.item') }}
                            </th>
                            <th scope="col" class="min-w-40 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('planning.form.member') }}
                            </th>
                            <th scope="col" class="min-w-28 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                {{ $t('reservations.table.status') }}
                            </th>
                            <th scope="col" class="min-w-40 px-5 py-2.5 text-end text-sm font-normal text-stone-500 dark:text-neutral-500">
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
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-700 dark:text-neutral-200">{{ reservation.service?.name || $t('reservations.client.book.default_service') }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-700 dark:text-neutral-200">{{ reservation.team_member?.user?.name || reservation.teamMember?.user?.name || '-' }}</td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadgeClass(reservation.status)">
                                    {{ $t(`reservations.status.${reservation.status}`) || reservation.status?.replace(/_/g, ' ') }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-end">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <button type="button" class="text-xs underline" @click="openDetails(reservation)">{{ $t('reservations.actions.view') }}</button>
                                    <button
                                        v-if="canReschedule(reservation)"
                                        type="button"
                                        class="text-xs underline"
                                        @click="openReschedule(reservation)"
                                    >
                                        {{ $t('reservations.actions.reschedule') }}
                                    </button>
                                    <button
                                        v-if="canCancel(reservation)"
                                        type="button"
                                        class="text-xs text-rose-600"
                                        @click="cancelReservation(reservation)"
                                    >
                                        {{ $t('reservations.actions.cancel') }}
                                    </button>
                                    <button
                                        v-if="canReview(reservation)"
                                        type="button"
                                        class="text-xs text-emerald-700 underline"
                                        @click="openReview(reservation)"
                                    >
                                        {{ $t('reservations.client.index.review.actions.leave') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>

                    <template #empty>
                        <div class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                            {{ $t('reservations.client.index.empty') }}
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
                    <div v-if="activeReservation.review">
                        {{ $t('reservations.client.index.review.fields.rating') }}:
                        <strong>{{ activeReservation.review.rating }} / 5</strong>
                    </div>
                    <div v-if="activeReservation.review">
                        {{ $t('reservations.client.index.review.fields.feedback') }}: {{ activeReservation.review.feedback || '-' }}
                    </div>
                </div>

                <div class="mt-4 rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                    {{ $t('reservations.client.index.cancellation_cutoff', { hours: settings?.cancellation_cutoff_hours || 0 }) }}
                </div>

                <div v-if="detailsActionError" class="mt-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                    {{ detailsActionError }}
                </div>

                <div class="mt-4 flex flex-wrap justify-end gap-2">
                    <button
                        v-if="canReschedule(activeReservation)"
                        type="button"
                        class="rounded-sm border border-stone-200 px-3 py-2 text-xs dark:border-neutral-700"
                        @click="openReschedule(activeReservation)"
                    >
                        {{ $t('reservations.actions.reschedule') }}
                    </button>
                    <button
                        v-if="canCancel(activeReservation)"
                        type="button"
                        class="rounded-sm bg-rose-600 px-3 py-2 text-xs text-white"
                        @click="cancelReservation(activeReservation)"
                    >
                        {{ $t('reservations.actions.cancel') }}
                    </button>
                    <button
                        v-if="canReview(activeReservation)"
                        type="button"
                        class="rounded-sm bg-emerald-600 px-3 py-2 text-xs text-white"
                        @click="openReview(activeReservation)"
                    >
                        {{ $t('reservations.client.index.review.actions.leave') }}
                    </button>
                </div>
            </div>
        </Modal>

        <Modal :show="showReschedule" maxWidth="3xl" @close="showReschedule = false">
            <div class="p-5">
                <h2 class="text-sm font-semibold">{{ $t('reservations.client.index.reschedule_title') }}</h2>

                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <FloatingSelect v-model="rescheduleTeamMemberId" :options="rescheduleTeamOptions" :label="$t('reservations.client.index.reschedule_member')" />
                    <FloatingTextarea v-model="rescheduleForm.client_notes" :label="$t('reservations.client.index.reschedule_notes')" />
                </div>
                <InputError class="mt-1" :message="rescheduleForm.errors.client_notes" />

                <div class="mt-3">
                    <ReservationCalendarBoard
                        :events="rescheduleCalendarEvents"
                        :loading="rescheduleLoading"
                        :error="rescheduleError"
                        :empty-label="$t('reservations.client.book.no_availability')"
                        :selected-event-id="selectedRescheduleEventId"
                        initial-view="week"
                        :loading-label="$t('reservations.client.book.loading_slots')"
                        @range-change="onRescheduleRangeChange"
                        @event-click="onRescheduleEventClick"
                    />
                </div>

                <div class="mt-3 rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                    {{ $t('reservations.client.book.selected_slot') }}:
                    <strong>
                        {{ rescheduleSelectedSlot
                            ? `${dayjs(rescheduleSelectedSlot.starts_at).format('ddd, MMM D HH:mm')} - ${dayjs(rescheduleSelectedSlot.ends_at).format('HH:mm')} (${rescheduleSelectedSlot.team_member_name})`
                            : '-' }}
                    </strong>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-sm border border-stone-200 px-3 py-2 text-xs dark:border-neutral-700"
                        @click="showReschedule = false"
                    >
                        {{ $t('quotes.form.cancel') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-sm bg-emerald-600 px-3 py-2 text-xs text-white disabled:opacity-50"
                        :disabled="rescheduleSubmitting || !rescheduleSelectedSlot"
                        @click="submitReschedule"
                    >
                        {{ rescheduleSubmitting ? $t('reservations.client.book.actions.submitting') : $t('reservations.actions.update') }}
                    </button>
                </div>
            </div>
        </Modal>

        <Modal :show="showReview" maxWidth="md" @close="showReview = false">
            <div class="p-5">
                <h2 class="text-sm font-semibold">{{ $t('reservations.client.index.review.title') }}</h2>
                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ $t('reservations.client.index.review.description') }}</p>

                <div class="mt-3 grid gap-3">
                    <FloatingSelect
                        v-model="reviewForm.rating"
                        :options="ratingOptions"
                        :label="$t('reservations.client.index.review.fields.rating')"
                    />
                    <InputError :message="reviewForm.errors.rating" />

                    <FloatingTextarea
                        v-model="reviewForm.feedback"
                        :label="$t('reservations.client.index.review.fields.feedback')"
                    />
                    <InputError :message="reviewForm.errors.feedback" />
                </div>

                <div v-if="reviewError" class="mt-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                    {{ reviewError }}
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-sm border border-stone-200 px-3 py-2 text-xs dark:border-neutral-700"
                        @click="showReview = false"
                    >
                        {{ $t('quotes.form.cancel') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-sm bg-emerald-600 px-3 py-2 text-xs text-white disabled:opacity-50"
                        :disabled="reviewSubmitting"
                        @click="submitReview"
                    >
                        {{ reviewSubmitting ? $t('reservations.client.index.review.actions.submitting') : $t('reservations.client.index.review.actions.submit') }}
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
