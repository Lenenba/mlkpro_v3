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
});

const viewMode = ref(props.filters?.view_mode || 'calendar');
const calendarEvents = ref([...(props.events || [])]);
const calendarLoading = ref(false);
const calendarError = ref('');
const detailsActionError = ref('');
const canViewAll = computed(() => Boolean(props.access?.can_view_all));
const canManage = computed(() => Boolean(props.access?.can_manage));
const canUpdateStatus = computed(() => Boolean(props.access?.can_update_status));
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

const sortOptions = computed(() => ([
    { value: 'date_asc', label: t('reservations.sort.date_asc') },
    { value: 'date_desc', label: t('reservations.sort.date_desc') },
    { value: 'status', label: t('reservations.sort.status') },
]));

const statusBadgeClass = (status) => reservationStatusBadgeClass(status);
const formatDateTime = (value) => (value ? dayjs(value).locale(dayjsLocale.value).format('DD MMM YYYY HH:mm') : '-');
const toLocalInput = (value) => (value ? dayjs(value).format('YYYY-MM-DDTHH:mm') : '');
const isPast = (value) => (value ? dayjs(value).isBefore(dayjs()) : false);

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
            only: ['filters', 'reservations', 'stats'],
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

const clearFilters = () => {
    filterForm.search = '';
    filterForm.status = '';
    filterForm.team_member_id = filterForm.scope === 'mine' ? ownTeamMemberId.value : '';
    filterForm.service_id = '';
    filterForm.date_from = '';
    filterForm.date_to = '';
    filterForm.sort = 'date_asc';
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
        only: ['filters', 'reservations', 'stats'],
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

            <ReservationStats :stats="stats" />

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div
                        v-if="scopeOptions.length > 1"
                        class="inline-flex rounded-md border border-stone-200 bg-stone-50 p-0.5 text-xs text-stone-600 shadow-sm dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300"
                    >
                        <button
                            v-for="option in scopeOptions"
                            :key="`reservation-scope-${option.value}`"
                            type="button"
                            class="rounded-sm px-2 py-1"
                            :class="filterForm.scope === option.value ? 'bg-white text-stone-900 shadow-sm dark:bg-neutral-800 dark:text-white' : ''"
                            @click="filterForm.scope = option.value"
                        >
                            {{ option.label }}
                        </button>
                    </div>
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
                </div>

                <div class="mt-3 grid gap-3 lg:grid-cols-4">
                    <FloatingInput v-model="filterForm.search" :label="$t('reservations.filters.search')" />
                    <FloatingSelect v-model="filterForm.status" :options="statusOptions" :label="$t('reservations.filters.status')" />
                    <FloatingSelect v-model="filterForm.service_id" :options="serviceOptions" :label="$t('reservations.form.item')" />
                    <FloatingSelect v-model="filterForm.sort" :options="sortOptions" :label="$t('reservations.filters.sort')" />
                </div>

                <div class="mt-3 grid gap-3 lg:grid-cols-4">
                    <FloatingSelect
                        v-model="filterForm.team_member_id"
                        :options="teamOptions"
                        :label="$t('planning.form.member')"
                        :disabled="filterForm.scope === 'mine'"
                    />
                    <FloatingInput v-model="filterForm.date_from" type="date" :label="$t('reservations.filters.date_from')" />
                    <FloatingInput v-model="filterForm.date_to" type="date" :label="$t('reservations.filters.date_to')" />
                    <div class="flex items-end justify-end">
                        <button
                            type="button"
                            class="w-full rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                            @click="clearFilters"
                        >
                            {{ $t('reservations.actions.clear_filters') }}
                        </button>
                    </div>
                </div>
            </section>

            <ReservationCalendarBoard
                v-if="viewMode === 'calendar'"
                :events="calendarEvents"
                :loading="calendarLoading"
                :error="calendarError"
                :empty-label="$t('reservations.empty')"
                :selected-event-id="activeReservation?.id || null"
                :loading-label="$t('planning.filters.loading')"
                @range-change="onCalendarRangeChange"
                @event-click="openFromEvent"
            />

            <section v-else class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div v-if="!reservations?.data?.length" class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                    {{ $t('reservations.empty') }}
                </div>

                <template v-else>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase text-stone-500 dark:text-neutral-400">
                                    <th class="px-3 py-2">{{ $t('reservations.table.when') }}</th>
                                    <th class="px-3 py-2">{{ $t('reservations.table.item') }}</th>
                                    <th class="px-3 py-2">{{ $t('reservations.table.customer') }}</th>
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
                                    <td class="px-3 py-2">{{ reservation.service?.name || '-' }}</td>
                                    <td class="px-3 py-2">
                                        {{ reservation.client?.company_name || `${reservation.client?.first_name || ''} ${reservation.client?.last_name || ''}`.trim() || '-' }}
                                    </td>
                                    <td class="px-3 py-2">{{ reservation.team_member?.user?.name || '-' }}</td>
                                    <td class="px-3 py-2">
                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadgeClass(reservation.status)">
                                            {{ $t(`reservations.status.${reservation.status}`) || reservation.status?.replace(/_/g, ' ') }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <button type="button" class="mr-3 text-xs underline" @click="openDetails(reservation)">{{ $t('reservations.actions.view') }}</button>
                                        <button
                                            v-if="canManage"
                                            type="button"
                                            class="mr-3 text-xs underline"
                                            @click="openEdit(reservation)"
                                        >
                                            {{ $t('reservations.actions.edit') }}
                                        </button>
                                        <button
                                            v-if="canManage"
                                            type="button"
                                            class="text-xs text-rose-600"
                                            @click="removeReservation(reservation)"
                                        >
                                            {{ $t('reservations.actions.delete') }}
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
