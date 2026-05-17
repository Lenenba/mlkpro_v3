<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import dayjs from 'dayjs';
import 'dayjs/locale/fr';
import 'dayjs/locale/es';
import { useI18n } from 'vue-i18n';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { reservationStatusBadgeClass } from '@/Components/Reservation/status';

const { t, locale } = useI18n();
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
const openChairStates = ['available', 'available_ready', 'check_in_needed'];
const occupiedChairStates = ['called', 'busy'];
const queueItems = computed(() => (Array.isArray(queueData.value?.items) ? queueData.value.items : []));
const waitingRows = computed(() => (Array.isArray(queueData.value?.waiting) ? queueData.value.waiting : []));
const statusBadgeClass = (status) => reservationStatusBadgeClass(status);
const formatDateTime = (value) => (value ? dayjs(value).locale(dayjsLocale.value).format('DD MMM HH:mm') : '-');
const formatNow = (value) => (value ? dayjs(value).locale(dayjsLocale.value).format('HH:mm:ss') : '-');
const queueNumberLabel = (item) => item?.queue_number || (item?.id ? `#${item.id}` : '-');

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
    busy: {
        label: t('reservations.queue.screen.states.busy'),
        band: 'bg-violet-500',
        badge: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-200',
    },
    break: {
        label: t('reservations.queue.screen.states.break'),
        band: 'bg-amber-500',
        badge: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-200',
    },
    offline: {
        label: t('reservations.queue.screen.states.offline'),
        band: 'bg-stone-400',
        badge: 'bg-stone-100 text-stone-600 dark:bg-neutral-800 dark:text-neutral-300',
    },
    check_in_needed: {
        label: t('reservations.queue.screen.states.check_in_needed'),
        band: 'bg-amber-500',
        badge: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-200',
    },
    inactive: {
        label: t('reservations.queue.screen.states.inactive'),
        band: 'bg-rose-500',
        badge: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-200',
    },
    unassigned: {
        label: t('reservations.queue.screen.states.unassigned'),
        band: 'bg-stone-500',
        badge: 'bg-stone-100 text-stone-600 dark:bg-neutral-800 dark:text-neutral-300',
    },
}));

const chairStateMeta = (state) => chairStateStyles.value[state] || chairStateStyles.value.available;
const chairAccentTextClass = (state) => ({
    available: 'text-emerald-700 dark:text-emerald-200',
    available_ready: 'text-green-700 dark:text-green-200',
    called: 'text-sky-700 dark:text-sky-200',
    busy: 'text-violet-700 dark:text-violet-200',
    break: 'text-amber-700 dark:text-amber-200',
    offline: 'text-stone-600 dark:text-neutral-300',
    check_in_needed: 'text-amber-700 dark:text-amber-200',
    inactive: 'text-rose-700 dark:text-rose-200',
    unassigned: 'text-stone-600 dark:text-neutral-300',
}[state] || 'text-emerald-700 dark:text-emerald-200');

const IDLE_CHAIR_VIDEO_INTERVAL_SECONDS = 15 * 60;
const IDLE_CHAIR_VIDEO_TRIGGER_WINDOW_SECONDS = 45;
const idleChairVideoCycle = computed(() => Math.floor(nowTick.value.unix() / IDLE_CHAIR_VIDEO_INTERVAL_SECONDS));
const idleChairVideoSecondsIntoCycle = computed(() => nowTick.value.unix() % IDLE_CHAIR_VIDEO_INTERVAL_SECONDS);
const idleChairVideoActiveCycleByChair = ref({});
const idleChairVideoCompletedCycleByChair = ref({});
const takenChairVideoActiveCycleByChair = ref({});
const takenChairVideoCompletedCycleByChair = ref({});

const isIdleChairVideoPlaying = (chair) => (
    !chair?.current && idleChairVideoActiveCycleByChair.value[chair.id] === idleChairVideoCycle.value
);

const isTakenChairVideoPlaying = (chair) => (
    !!chair?.current && takenChairVideoActiveCycleByChair.value[chair.id] === idleChairVideoCycle.value
);

const markIdleChairVideoCompleted = (chairId) => {
    idleChairVideoActiveCycleByChair.value = {
        ...idleChairVideoActiveCycleByChair.value,
        [chairId]: null,
    };
    idleChairVideoCompletedCycleByChair.value = {
        ...idleChairVideoCompletedCycleByChair.value,
        [chairId]: idleChairVideoCycle.value,
    };
};

const markTakenChairVideoCompleted = (chairId) => {
    takenChairVideoActiveCycleByChair.value = {
        ...takenChairVideoActiveCycleByChair.value,
        [chairId]: null,
    };
    takenChairVideoCompletedCycleByChair.value = {
        ...takenChairVideoCompletedCycleByChair.value,
        [chairId]: idleChairVideoCycle.value,
    };
};

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

const estimatedRemainingMinutes = (item) => {
    if (!item) {
        return 0;
    }

    const duration = Math.max(0, Number(item.estimated_duration_minutes || 0));
    if (duration <= 0) {
        return 0;
    }

    if (String(item.status || '') !== 'in_service') {
        return Math.round(duration);
    }

    const anchor = item.started_at || item.called_at || item.checked_in_at;
    if (!anchor) {
        return Math.round(duration);
    }

    const elapsedMinutes = Math.max(0, nowTick.value.diff(dayjs(anchor), 'minute', true));

    return Math.max(0, Math.round(duration - elapsedMinutes));
};

const chairs = computed(() => {
    if (Array.isArray(queueData.value?.chairs) && queueData.value.chairs.length) {
        return queueData.value.chairs;
    }

    return [];
});

watch(
    [chairs, idleChairVideoCycle, idleChairVideoSecondsIntoCycle],
    ([chairList, cycle, secondsIntoCycle]) => {
        const nextIdleActive = { ...idleChairVideoActiveCycleByChair.value };
        const nextIdleCompleted = { ...idleChairVideoCompletedCycleByChair.value };
        const nextTakenActive = { ...takenChairVideoActiveCycleByChair.value };
        const nextTakenCompleted = { ...takenChairVideoCompletedCycleByChair.value };
        let idleActiveChanged = false;
        let idleCompletedChanged = false;
        let takenActiveChanged = false;

        for (const chair of chairList) {
            if (chair?.current) {
                if (nextIdleActive[chair.id] !== undefined && nextIdleActive[chair.id] !== null) {
                    delete nextIdleActive[chair.id];
                    idleActiveChanged = true;
                }
                if (
                    secondsIntoCycle < IDLE_CHAIR_VIDEO_TRIGGER_WINDOW_SECONDS
                    && nextTakenActive[chair.id] !== cycle
                    && nextTakenCompleted[chair.id] !== cycle
                ) {
                    nextTakenActive[chair.id] = cycle;
                    takenActiveChanged = true;
                }
                continue;
            }

            if (
                secondsIntoCycle < IDLE_CHAIR_VIDEO_TRIGGER_WINDOW_SECONDS
                && nextIdleActive[chair.id] !== cycle
                && nextIdleCompleted[chair.id] !== cycle
            ) {
                nextIdleActive[chair.id] = cycle;
                idleActiveChanged = true;
            }
            if (nextTakenActive[chair.id] !== undefined && nextTakenActive[chair.id] !== null) {
                delete nextTakenActive[chair.id];
                takenActiveChanged = true;
            }
        }

        if (idleActiveChanged) {
            idleChairVideoActiveCycleByChair.value = nextIdleActive;
        }
        if (idleCompletedChanged) {
            idleChairVideoCompletedCycleByChair.value = nextIdleCompleted;
        }
        if (takenActiveChanged) {
            takenChairVideoActiveCycleByChair.value = nextTakenActive;
        }
    },
    { immediate: true },
);

const summary = computed(() => {
    const activeSeats = chairs.value.length;
    const occupiedChairs = chairs.value.filter((chair) => occupiedChairStates.includes(String(chair.state || '')));
    const occupied = occupiedChairs.length;
    const ready = chairs.value.filter((chair) => !chair.current && chair.next).length;
    const openSeats = chairs.value.filter((chair) => openChairStates.includes(String(chair.state || ''))).length;

    let avgWait = 0;
    if (openSeats === 0 && occupiedChairs.length > 0) {
        const remainingDurations = occupiedChairs
            .map((chair) => estimatedRemainingMinutes(chair.current))
            .filter((value) => Number.isFinite(value) && value > 0);

        avgWait = remainingDurations.length
            ? Math.round(remainingDurations.reduce((carry, value) => carry + value, 0) / remainingDurations.length)
            : 0;
    }

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

                <section :class="isTvMode ? 'grid gap-3 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5' : 'grid gap-3 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5'">
                    <article
                        v-for="chair in chairs"
                        :key="`chair-card-${chair.id}`"
                        class="overflow-hidden rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <div class="h-1.5" :class="chairStateMeta(chair.state).band" />
                        <div class="space-y-2.5 p-3">
                            <div class="flex items-start justify-between gap-2">
                                <div :class="isTvMode ? 'min-w-0 flex-1 min-h-[4.75rem]' : 'min-w-0 flex-1 min-h-[4.25rem]'">
                                    <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ chair.chair_label || `Chair ${chair.chair_number || '-'}` }}
                                    </p>
                                    <p :class="isTvMode ? 'mt-1 text-lg font-semibold leading-tight text-stone-900 dark:text-neutral-100' : 'mt-1 text-sm font-semibold leading-tight text-stone-900 dark:text-neutral-100'">
                                        {{ chair.team_member_name || '-' }}
                                    </p>
                                    <p v-if="chair.team_member_title" class="mt-0.5 text-xs text-stone-500 dark:text-neutral-400">{{ chair.team_member_title }}</p>
                                </div>
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="chairStateMeta(chair.state).badge">
                                    <span class="size-1.5 rounded-full bg-current" />
                                    {{ chairStateMeta(chair.state).label }}
                                </span>
                            </div>

                            <div class="relative overflow-hidden rounded-sm border border-stone-200 bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800/70">
                                <div
                                    v-if="chair.current"
                                    class="absolute right-2 top-2 z-10 rounded-full border border-stone-200 bg-white px-2 py-0.5 text-[10px] font-semibold text-stone-700 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                >
                                    {{ queueNumberLabel(chair.current) }}
                                </div>

                                <div :class="isTvMode ? 'relative min-h-[210px]' : 'relative min-h-[180px]'">
                                        <video
                                            v-if="isTakenChairVideoPlaying(chair)"
                                            :key="`taken-chair-video-${chair.id}-${idleChairVideoCycle}`"
                                            class="absolute inset-0 h-full w-full object-contain object-center select-none"
                                            autoplay
                                            muted
                                            playsinline
                                            preload="metadata"
                                            poster="/images/chair-taken.png"
                                            aria-hidden="true"
                                            disablepictureinpicture
                                            @ended="markTakenChairVideoCompleted(chair.id)"
                                        >
                                            <source src="/videos/chair-taken.mp4" type="video/mp4" />
                                        </video>
                                        <img
                                            v-else-if="chair.current"
                                            src="/images/chair-taken.png"
                                            :alt="chair.chair_label || `Chair ${chair.chair_number || '-'}`"
                                            class="absolute inset-0 h-full w-full object-contain object-center select-none"
                                            draggable="false"
                                            loading="lazy"
                                        />
                                        <video
                                            v-else-if="isIdleChairVideoPlaying(chair)"
                                            :key="`idle-chair-video-${chair.id}-${idleChairVideoCycle}`"
                                            class="absolute inset-0 h-full w-full object-contain object-center select-none"
                                            autoplay
                                            muted
                                            playsinline
                                            preload="metadata"
                                            poster="/images/barber-chair.png"
                                            aria-hidden="true"
                                            disablepictureinpicture
                                            @ended="markIdleChairVideoCompleted(chair.id)"
                                        >
                                            <source src="/videos/barber-chair.mp4" type="video/mp4" />
                                        </video>
                                        <img
                                            v-else
                                            src="/images/barber-chair.png"
                                            :alt="chair.chair_label || `Chair ${chair.chair_number || '-'}`"
                                            class="absolute inset-0 h-full w-full object-contain object-center select-none"
                                            draggable="false"
                                            loading="lazy"
                                        />
                                </div>
                            </div>

                            <div class="rounded-sm border border-stone-200 bg-stone-50 p-2.5 dark:border-neutral-700 dark:bg-neutral-800/70">
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
                                            <span class="font-semibold" :class="chairAccentTextClass(chair.state)">{{ timerValue(chair.current) }}</span>
                                        </div>
                                        <div class="mt-1 h-1.5 w-full rounded-full bg-stone-200 dark:bg-neutral-700">
                                            <div class="h-1.5 rounded-full" :class="chair.current.status === 'in_service' ? chairStateMeta(chair.state).band : 'bg-sky-500'" :style="{ width: `${progressPercent(chair.current)}%` }" />
                                        </div>
                                    </div>
                                </template>
                                <p v-else class="mt-1 text-sm text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.screen.states.available') }}</p>
                            </div>

                            <div class="rounded-sm border border-stone-200 bg-white p-2.5 dark:border-neutral-700 dark:bg-neutral-900/40">
                                <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('reservations.queue.screen.up_next') }}</p>
                                <template v-if="chair.next">
                                    <div class="mt-1 flex items-center justify-between gap-2">
                                        <p class="text-sm font-semibold text-stone-900 dark:text-neutral-100">{{ queueNumberLabel(chair.next) }}</p>
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

                    <AdminDataTable
                        embedded
                        dense
                        :rows="waitingRows"
                        :show-pagination="false"
                        class="mt-3"
                    >
                        <template #head>
                            <tr>
                                <th scope="col" class="min-w-28 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    {{ $t('reservations.queue.columns.ticket') }}
                                </th>
                                <th scope="col" class="min-w-40 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    {{ $t('reservations.table.customer') }}
                                </th>
                                <th scope="col" class="min-w-40 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    {{ $t('reservations.table.item') }}
                                </th>
                                <th scope="col" class="min-w-40 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    {{ $t('planning.form.member') }}
                                </th>
                                <th scope="col" class="min-w-32 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    {{ $t('reservations.queue.columns.position') }}
                                </th>
                                <th scope="col" class="min-w-32 px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    {{ $t('reservations.table.status') }}
                                </th>
                            </tr>
                        </template>

                        <template #row="{ row: item }">
                            <tr>
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
                        </template>

                        <template #empty>
                            <div
                                class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-4 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400"
                            >
                                {{ $t('reservations.queue.empty') }}
                            </div>
                        </template>
                    </AdminDataTable>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
