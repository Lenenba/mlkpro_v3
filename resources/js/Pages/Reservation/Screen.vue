<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import dayjs from 'dayjs';
import 'dayjs/locale/fr';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { reservationStatusBadgeClass } from '@/Components/Reservation/status';

const { t, locale } = useI18n();
const dayjsLocale = computed(() => (String(locale.value || '').toLowerCase().startsWith('fr') ? 'fr' : 'en'));

const props = defineProps({
    queue: {
        type: Object,
        default: () => ({
            stats: { waiting: 0, called: 0, in_service: 0 },
            assignment_mode: 'per_staff',
            items: [],
            chairs: [],
            waiting: [],
            total_active: 0,
            generated_at: null,
        }),
    },
    teamMembers: { type: Array, default: () => [] },
    timezone: { type: String, default: 'UTC' },
    settings: {
        type: Object,
        default: () => ({
            queue_mode_enabled: false,
            queue_assignment_mode: 'per_staff',
            business_preset: 'service_general',
            queue_grace_minutes: 5,
        }),
    },
    screen: {
        type: Object,
        default: () => ({ anonymize_clients: true, mode: 'board' }),
    },
    kiosk: { type: Object, default: () => ({ public_url: null }) },
});

const queueData = ref({ ...(props.queue || {}) });
const loading = ref(false);
const error = ref('');
const nowLabel = ref(dayjs().toISOString());
const nowTick = ref(dayjs());
let refreshTimer = null;
let clockTimer = null;

const queueModeEnabled = computed(() => Boolean(props.settings?.queue_mode_enabled));
const queueAssignmentMode = computed(() => {
    const raw = String(queueData.value?.assignment_mode || props.settings?.queue_assignment_mode || 'per_staff');
    return ['per_staff', 'global_pull'].includes(raw) ? raw : 'per_staff';
});
const anonymizeClients = computed(() => Boolean(props.screen?.anonymize_clients));
const isTvMode = computed(() => String(props.screen?.mode || 'board') === 'tv');
const presetLabel = computed(() => t(`settings.reservations.presets.${props.settings?.business_preset || 'service_general'}`));
const kioskPublicUrl = computed(() => props.kiosk?.public_url || null);

const waitingStatuses = ['checked_in', 'pre_called', 'called', 'skipped', 'not_arrived'];
const queueItems = computed(() => (Array.isArray(queueData.value?.items) ? queueData.value.items : []));
const statusBadgeClass = (status) => reservationStatusBadgeClass(status);
const formatDateTime = (value) => (value ? dayjs(value).locale(dayjsLocale.value).format('DD MMM HH:mm') : '-');
const formatNow = (value) => (value ? dayjs(value).locale(dayjsLocale.value).format('HH:mm:ss') : '-');

const refreshScreen = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await axios.get(route('reservation.screen.data'), {
            params: {
                anonymize: anonymizeClients.value ? 1 : 0,
            },
        });

        queueData.value = response?.data?.queue || {};
        nowLabel.value = response?.data?.fetched_at || dayjs().toISOString();
    } catch (err) {
        error.value = err?.response?.data?.message || t('reservations.queue.screen.refresh_error');
    } finally {
        loading.value = false;
    }
};

const chairStateStyles = computed(() => ({
    available: {
        label: t('reservations.queue.screen.states.available'),
        band: 'bg-emerald-500',
        badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200',
    },
    available_ready: {
        label: t('reservations.queue.screen.states.available_ready'),
        band: 'bg-green-500',
        badge: 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-200',
    },
    called: {
        label: t('reservations.queue.screen.states.called'),
        band: 'bg-sky-500',
        badge: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-200',
    },
    in_service: {
        label: t('reservations.queue.screen.states.in_service'),
        band: 'bg-violet-500',
        badge: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-200',
    },
    check_in_needed: {
        label: t('reservations.queue.screen.states.check_in_needed'),
        band: 'bg-amber-500',
        badge: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-200',
    },
    blocked: {
        label: t('reservations.queue.screen.states.blocked'),
        band: 'bg-rose-500',
        badge: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-200',
    },
}));

const chairStateMeta = (state) => chairStateStyles.value[state] || chairStateStyles.value.available;

const originBadgeClass = (origin) => (
    origin === 'booking'
        ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-200'
        : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-200'
);

const originLabel = (origin, itemType) => {
    const fallbackOrigin = itemType === 'appointment' ? 'booking' : 'walk_in';
    return t(`reservations.queue.origins.${origin || fallbackOrigin}`);
};

const elapsedSeconds = (value) => {
    if (!value) {
        return 0;
    }

    const start = dayjs(value);
    return start.isValid() ? Math.max(0, nowTick.value.diff(start, 'second')) : 0;
};

const timerValue = (item) => {
    if (!item) {
        return '00:00';
    }

    const anchor = item.started_at || item.called_at || item.checked_in_at;
    const totalSeconds = elapsedSeconds(anchor);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
};

const progressPercent = (item) => {
    if (!item) {
        return 0;
    }

    const duration = Math.max(0, Number(item.estimated_duration_minutes || 0));
    if (duration <= 0) {
        return 0;
    }

    const anchor = item.started_at || item.called_at || item.checked_in_at;
    const elapsedMinutes = elapsedSeconds(anchor) / 60;
    return Math.max(0, Math.min(100, Math.round((elapsedMinutes / duration) * 100)));
};

const deriveChairsFromItems = () => {
    const members = Array.isArray(props.teamMembers) ? props.teamMembers : [];
    if (!members.length) {
        return [];
    }

    const globalPool = queueItems.value
        .filter((item) => waitingStatuses.includes(String(item.status || '')))
        .sort((left, right) => Number(left.position || 99999) - Number(right.position || 99999));

    return members.map((member, index) => {
        const memberId = Number(member.id || 0);
        const isPresent = member.is_present !== false;
        const memberItems = queueItems.value.filter((item) => Number(item.team_member_id || 0) === memberId);
        const current = memberItems.find((item) => ['in_service', 'called', 'pre_called'].includes(String(item.status || ''))) || null;

        let next = memberItems
            .filter((item) => waitingStatuses.includes(String(item.status || '')))
            .filter((item) => !current || Number(item.id) !== Number(current.id))
            .sort((left, right) => Number(left.position || 99999) - Number(right.position || 99999))[0] || null;

        if (!next && queueAssignmentMode.value === 'global_pull') {
            next = globalPool.find((item) => Number(item.team_member_id || 0) === 0) || null;
        }

        let state = 'available';
        if (current?.status === 'in_service') {
            state = 'in_service';
        } else if (['called', 'pre_called'].includes(String(current?.status || ''))) {
            state = 'called';
        } else if (!isPresent) {
            state = 'blocked';
        } else if (next?.status === 'not_arrived') {
            state = 'check_in_needed';
        } else if (next) {
            state = 'available_ready';
        }

        return {
            id: memberId,
            chair_number: index + 1,
            chair_label: `Chair ${index + 1}`,
            team_member_name: member.name || 'Member',
            team_member_title: member.title || null,
            is_present: isPresent,
            state,
            current,
            next,
        };
    });
};

const chairs = computed(() => {
    if (Array.isArray(queueData.value?.chairs) && queueData.value.chairs.length) {
        return queueData.value.chairs;
    }

    return deriveChairsFromItems();
});

const summary = computed(() => {
    const activeSeats = chairs.value.length;
    const occupied = chairs.value.filter((chair) => ['called', 'in_service'].includes(String(chair.state || ''))).length;
    const ready = chairs.value.filter((chair) => !chair.current && chair.next).length;

    const waitingEtas = queueItems.value
        .filter((item) => waitingStatuses.includes(String(item.status || '')))
        .map((item) => Number(item.eta_minutes))
        .filter((value) => Number.isFinite(value) && value >= 0);

    const avgWait = waitingEtas.length
        ? Math.round(waitingEtas.reduce((carry, value) => carry + value, 0) / waitingEtas.length)
        : 0;

    const nextBooking = queueItems.value
        .filter((item) => String(item.origin || '') === 'booking' && waitingStatuses.includes(String(item.status || '')))
        .map((item) => item.reservation_starts_at)
        .filter(Boolean)
        .sort((left, right) => dayjs(left).valueOf() - dayjs(right).valueOf())[0] || null;

    return { activeSeats, occupied, ready, avgWait, nextBooking };
});

const modeToggleUrl = computed(() => route('reservation.screen', {
    anonymize: anonymizeClients.value ? 1 : 0,
    mode: isTvMode.value ? 'board' : 'tv',
}));

const nameToggleUrl = computed(() => route('reservation.screen', {
    anonymize: anonymizeClients.value ? 0 : 1,
    mode: isTvMode.value ? 'tv' : 'board',
}));

onMounted(() => {
    refreshTimer = setInterval(refreshScreen, 10000);
    clockTimer = setInterval(() => {
        nowTick.value = dayjs();
    }, 1000);
});

onBeforeUnmount(() => {
    if (refreshTimer) {
        clearInterval(refreshTimer);
    }
    if (clockTimer) {
        clearInterval(clockTimer);
    }
});
</script>

<template>
    <Head :title="$t('reservations.queue.screen.title')" />

    <AuthenticatedLayout>
        <div class="space-y-4">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ $t('reservations.queue.screen.title') }}</h1>
                        <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.screen.subtitle') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-cyan-100 px-2 py-0.5 text-xs font-semibold text-cyan-700 dark:bg-cyan-500/10 dark:text-cyan-300">{{ presetLabel }}</span>
                        <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">
                            {{ $t('reservations.queue.screen.assignment_mode') }}: {{ $t(`reservations.queue.assignment_mode.${queueAssignmentMode}`) }}
                        </span>
                        <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-700 dark:bg-neutral-800 dark:text-neutral-200">
                            {{ $t('reservations.queue.screen.updated_at') }}: {{ formatNow(nowLabel) }}
                        </span>
                        <Link :href="modeToggleUrl" class="rounded-sm border border-stone-300 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ isTvMode ? $t('reservations.queue.screen.mode_board') : $t('reservations.queue.screen.mode_tv') }}
                        </Link>
                        <Link :href="nameToggleUrl" class="rounded-sm border border-stone-300 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ anonymizeClients ? $t('reservations.queue.screen.show_names') : $t('reservations.queue.screen.hide_names') }}
                        </Link>
                        <button type="button" class="rounded-sm bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60" :disabled="loading" @click="refreshScreen">
                            {{ loading ? $t('planning.filters.loading') : $t('reservations.queue.screen.refresh') }}
                        </button>
                        <a v-if="kioskPublicUrl" :href="kioskPublicUrl" target="_blank" rel="noopener noreferrer" class="rounded-sm border border-amber-300 px-3 py-1.5 text-xs font-semibold text-amber-700 hover:bg-amber-50 dark:border-amber-300/40 dark:text-amber-200 dark:hover:bg-amber-500/10">
                            {{ $t('reservations.kiosk.open') }}
                        </a>
                    </div>
                </div>
                <div v-if="error" class="mt-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">{{ error }}</div>
            </section>

            <section v-if="!queueModeEnabled" class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-4 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                {{ $t('reservations.queue.screen.disabled') }}
            </section>

            <template v-else>
                <section v-if="!isTvMode" class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    <article class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.screen.metrics.seats_active') }}</p>
                        <p class="mt-1 text-2xl font-bold text-stone-900 dark:text-neutral-100">{{ summary.activeSeats }}</p>
                    </article>
                    <article class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.screen.metrics.occupied') }}</p>
                        <p class="mt-1 text-2xl font-bold text-stone-900 dark:text-neutral-100">{{ summary.occupied }}</p>
                    </article>
                    <article class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.screen.metrics.ready') }}</p>
                        <p class="mt-1 text-2xl font-bold text-stone-900 dark:text-neutral-100">{{ summary.ready }}</p>
                    </article>
                    <article class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.screen.metrics.avg_wait') }}</p>
                        <p class="mt-1 text-2xl font-bold text-stone-900 dark:text-neutral-100">{{ summary.avgWait }} <span class="text-sm font-semibold text-stone-500 dark:text-neutral-400">min</span></p>
                    </article>
                    <article class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.screen.metrics.next_booking') }}</p>
                        <p class="mt-1 text-lg font-bold text-stone-900 dark:text-neutral-100">{{ summary.nextBooking ? formatDateTime(summary.nextBooking) : '-' }}</p>
                    </article>
                </section>

                <section :class="isTvMode ? 'grid gap-4 md:grid-cols-2 xl:grid-cols-3' : 'grid gap-3 sm:grid-cols-2 xl:grid-cols-4'">
                    <article v-for="chair in chairs" :key="`chair-card-${chair.id}`" class="overflow-hidden rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="h-1.5" :class="chairStateMeta(chair.state).band" />
                        <div class="space-y-3 p-4">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ chair.chair_label || `Chair ${chair.chair_number || '-'}` }}</p>
                                    <p :class="isTvMode ? 'text-lg font-semibold text-stone-900 dark:text-neutral-100' : 'text-sm font-semibold text-stone-900 dark:text-neutral-100'">{{ chair.team_member_name || '-' }}</p>
                                </div>
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="chairStateMeta(chair.state).badge">
                                    <span class="size-1.5 rounded-full bg-current" />
                                    {{ chairStateMeta(chair.state).label }}
                                </span>
                            </div>

                            <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800/70">
                                <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.screen.current') }}</p>
                                <template v-if="chair.current">
                                    <div class="mt-1 flex items-center gap-1.5">
                                        <p :class="isTvMode ? 'text-base font-semibold text-stone-900 dark:text-neutral-100' : 'text-sm font-semibold text-stone-900 dark:text-neutral-100'">
                                            {{ chair.current.display_client_name || chair.current.client_name || '-' }}
                                        </p>
                                        <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="originBadgeClass(chair.current.origin)">
                                            {{ originLabel(chair.current.origin, chair.current.item_type) }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-stone-600 dark:text-neutral-300">{{ chair.current.service_name || '-' }}</p>
                                    <div class="mt-2">
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.screen.timer') }}</span>
                                            <span class="font-semibold text-stone-700 dark:text-neutral-200">{{ timerValue(chair.current) }}</span>
                                        </div>
                                        <div class="mt-1 h-1.5 w-full rounded-full bg-stone-200 dark:bg-neutral-700">
                                            <div class="h-1.5 rounded-full transition-all duration-700" :class="chair.current.status === 'in_service' ? 'bg-violet-500' : 'bg-sky-500 animate-pulse'" :style="{ width: `${progressPercent(chair.current)}%` }" />
                                        </div>
                                    </div>
                                </template>
                                <p v-else class="mt-1 text-sm text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.screen.states.available') }}</p>
                            </div>

                            <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900/40">
                                <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.screen.up_next') }}</p>
                                <template v-if="chair.next">
                                    <div class="mt-1 flex items-center justify-between gap-2">
                                        <p class="text-sm font-semibold text-stone-900 dark:text-neutral-100">{{ chair.next.queue_number || `#${chair.next.id}` }}</p>
                                        <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="originBadgeClass(chair.next.origin)">
                                            {{ originLabel(chair.next.origin, chair.next.item_type) }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-stone-600 dark:text-neutral-300">{{ chair.next.display_client_name || chair.next.client_name || '-' }}</p>
                                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                                        ETA {{ chair.next.eta_minutes !== null && chair.next.eta_minutes !== undefined ? `${chair.next.eta_minutes} min` : '-' }}
                                        <span v-if="chair.next.reservation_starts_at">· {{ formatDateTime(chair.next.reservation_starts_at) }}</span>
                                    </p>
                                </template>
                                <p v-else class="mt-1 text-sm text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.screen.no_next') }}</p>
                            </div>
                        </div>
                    </article>
                </section>

                <section
                    v-if="!chairs.length"
                    class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400"
                >
                    {{ $t('reservations.queue.screen.no_chairs') }}
                </section>

                <section
                    v-if="!isTvMode"
                    class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('reservations.queue.screen.waiting_list') }}</h2>

                    <div
                        v-if="!(queueData.waiting || []).length"
                        class="mt-3 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-4 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400"
                    >
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
                                        <th scope="col" class="min-w-28">
                                            <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                                {{ $t('reservations.queue.columns.ticket') }}
                                            </div>
                                        </th>
                                        <th scope="col" class="min-w-40">
                                            <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                                {{ $t('reservations.table.customer') }}
                                            </div>
                                        </th>
                                        <th scope="col" class="min-w-40">
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
                                        <th scope="col" class="min-w-32">
                                            <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                                {{ $t('reservations.table.status') }}
                                            </div>
                                        </th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                    <tr v-for="item in (queueData.waiting || [])" :key="`screen-waiting-${item.id}`">
                                        <td class="size-px whitespace-nowrap px-4 py-2 font-medium text-stone-700 dark:text-neutral-200">
                                            {{ item.queue_number }}
                                        </td>
                                        <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">
                                            {{ item.display_client_name }}
                                        </td>
                                        <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">
                                            {{ item.service_name }}
                                        </td>
                                        <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">
                                            {{ item.team_member_name }}
                                        </td>
                                        <td class="size-px whitespace-nowrap px-4 py-2 text-sm text-stone-600 dark:text-neutral-300">
                                            {{ item.position ?? '-' }}
                                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                                · ETA {{ item.eta_minutes !== null && item.eta_minutes !== undefined ? `${item.eta_minutes} min` : '-' }}
                                            </span>
                                        </td>
                                        <td class="size-px whitespace-nowrap px-4 py-2">
                                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadgeClass(item.status)">
                                                {{ $t(`reservations.queue.status.${item.status}`) || item.status }}
                                            </span>
                                            <div v-if="item.call_expires_at" class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                                {{ $t('reservations.queue.screen.call_expires') }}: {{ formatDateTime(item.call_expires_at) }}
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
