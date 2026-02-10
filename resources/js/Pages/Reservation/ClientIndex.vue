<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import dayjs from 'dayjs';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import ReservationCalendarBoard from '@/Components/Reservation/ReservationCalendarBoard.vue';
import ReservationStats from '@/Components/Reservation/ReservationStats.vue';
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

const statusBadgeClass = (status) => reservationStatusBadgeClass(status);
const formatDateTime = (value) => (value ? dayjs(value).format('MMM D, YYYY HH:mm') : '-');

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
const canReschedule = (reservation) => Boolean(props.settings?.allow_client_reschedule) && isModifyWindowOpen(reservation);
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
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['filters', 'reservations', 'stats'],
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

const goToPage = (url) => {
    if (!url) {
        return;
    }

    router.visit(url, {
        preserveState: true,
        preserveScroll: true,
        only: ['filters', 'reservations', 'stats'],
    });
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
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ $t('reservations.client.index.title') }}</h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('reservations.client.index.subtitle') }}</p>
                    </div>
                    <Link
                        :href="route('client.reservations.book')"
                        class="rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700"
                    >
                        {{ $t('reservations.client.index.book_button') }}
                    </Link>
                </div>
            </section>

            <ReservationStats :stats="stats" />

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid gap-3 md:grid-cols-3">
                    <FloatingSelect v-model="filterForm.status" :options="statusOptions" :label="$t('reservations.filters.status')" />
                    <FloatingInput v-model="filterForm.date_from" type="date" :label="$t('reservations.filters.date_from')" />
                    <FloatingInput v-model="filterForm.date_to" type="date" :label="$t('reservations.filters.date_to')" />
                </div>
                <div class="mt-3 flex items-center justify-between gap-2">
                    <div class="inline-flex rounded-md border border-stone-200 bg-stone-50 p-0.5 text-xs text-stone-600 shadow-sm dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300">
                        <button
                            type="button"
                            class="rounded-sm px-2 py-1"
                            :class="viewMode === 'calendar' ? 'bg-white text-stone-900 shadow-sm dark:bg-neutral-800 dark:text-white' : ''"
                            @click="viewMode = 'calendar'"
                        >
                            {{ $t('planning.calendar.month') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-sm px-2 py-1"
                            :class="viewMode === 'list' ? 'bg-white text-stone-900 shadow-sm dark:bg-neutral-800 dark:text-white' : ''"
                            @click="viewMode = 'list'"
                        >
                            {{ $t('reservations.view.list') }}
                        </button>
                    </div>
                    <button
                        type="button"
                        class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
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

            <section v-else class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div v-if="!reservations?.data?.length" class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                    {{ $t('reservations.client.index.empty') }}
                </div>

                <template v-else>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase text-stone-500 dark:text-neutral-400">
                                    <th class="px-3 py-2">{{ $t('reservations.table.when') }}</th>
                                    <th class="px-3 py-2">{{ $t('reservations.table.item') }}</th>
                                    <th class="px-3 py-2">{{ $t('planning.form.member') }}</th>
                                    <th class="px-3 py-2">{{ $t('reservations.table.status') }}</th>
                                    <th class="px-3 py-2">{{ $t('reservations.table.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="reservation in reservations.data"
                                    :key="reservation.id"
                                    class="border-t border-stone-100 dark:border-neutral-800"
                                >
                                    <td class="px-3 py-2">
                                        <button type="button" class="hover:underline" @click="openDetails(reservation)">
                                            {{ formatDateTime(reservation.starts_at) }}
                                        </button>
                                    </td>
                                    <td class="px-3 py-2">{{ reservation.service?.name || $t('reservations.client.book.default_service') }}</td>
                                    <td class="px-3 py-2">{{ reservation.team_member?.user?.name || reservation.teamMember?.user?.name || '-' }}</td>
                                    <td class="px-3 py-2">
                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadgeClass(reservation.status)">
                                            {{ $t(`reservations.status.${reservation.status}`) || reservation.status?.replace(/_/g, ' ') }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <button type="button" class="mr-2 text-xs underline" @click="openDetails(reservation)">{{ $t('reservations.actions.view') }}</button>
                                        <button
                                            v-if="canReschedule(reservation)"
                                            type="button"
                                            class="mr-2 text-xs underline"
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
                                            class="ml-2 text-xs text-emerald-700 underline"
                                            @click="openReview(reservation)"
                                        >
                                            {{ $t('reservations.client.index.review.actions.leave') }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
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
