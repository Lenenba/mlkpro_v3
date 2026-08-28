<script>
import {
    addReservationCalendarTime as addReservationEventCalendarTime,
    reservationCalendarDate as reservationEventCalendarDate,
    reservationCalendarStartOf as reservationEventCalendarStartOf,
} from '@/utils/reservationCalendar';

export const reservationEventDayKeys = ({
    startAt,
    endAt,
    rangeStart = null,
    rangeEnd = null,
    timezone = 'UTC',
}) => {
    const start = reservationEventCalendarDate(startAt, timezone);
    const parsedEnd = reservationEventCalendarDate(endAt || startAt, timezone);

    if (!start.isValid() || !parsedEnd.isValid()) {
        return [];
    }

    const eventEnd = parsedEnd.isAfter(start)
        ? parsedEnd
        : start.add(1, 'millisecond');
    const parsedRangeStart = rangeStart
        ? reservationEventCalendarDate(rangeStart, timezone)
        : null;
    const parsedRangeEnd = rangeEnd
        ? reservationEventCalendarDate(rangeEnd, timezone)
        : null;
    const overlapStart = parsedRangeStart?.isValid() && parsedRangeStart.isAfter(start)
        ? parsedRangeStart
        : start;
    const overlapEnd = parsedRangeEnd?.isValid() && parsedRangeEnd.isBefore(eventEnd)
        ? parsedRangeEnd
        : eventEnd;

    if (!overlapEnd.isAfter(overlapStart)) {
        return [];
    }

    const firstDay = reservationEventCalendarStartOf(overlapStart, 'day', timezone);
    const lastDay = reservationEventCalendarStartOf(
        overlapEnd.subtract(1, 'millisecond'),
        'day',
        timezone
    );
    const keys = [];
    let cursor = firstDay;

    while (!cursor.isAfter(lastDay)) {
        keys.push(cursor.format('YYYY-MM-DD'));

        if (cursor.isSame(lastDay, 'day')) {
            break;
        }

        const next = addReservationEventCalendarTime(cursor, 1, 'day', timezone);
        if (!next.isAfter(cursor)) {
            break;
        }
        cursor = next;
    }

    return keys;
};

export const indexReservationEventsByDay = (events, options) => {
    const map = new Map();

    (events || []).forEach((event) => {
        reservationEventDayKeys({
            startAt: event.startAt,
            endAt: event.endAt,
            ...options,
        }).forEach((dayKey) => {
            const list = map.get(dayKey) || [];
            list.push(event);
            map.set(dayKey, list);
        });
    });

    return map;
};
</script>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc';
import timezonePlugin from 'dayjs/plugin/timezone';
import 'dayjs/locale/fr';
import 'dayjs/locale/es';
import {
    AlertCircle,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Clock3,
    LoaderCircle,
    UserRound,
    UsersRound,
} from 'lucide-vue-next';
import {
    reservationStatusDotClasses,
    reservationStatusEventClasses,
} from '@/Components/Reservation/status';
import {
    addReservationCalendarTime,
    currentReservationDay,
    reservationCalendarDay,
    reservationCalendarEndOf,
    reservationMonthGridDates,
    reservationMonthGridStart,
    reservationCalendarStartOf,
    reservationWeekStart,
    resolveReservationViewAnchor,
} from '@/utils/reservationCalendar';

dayjs.extend(utc);
dayjs.extend(timezonePlugin);

const props = defineProps({
    events: {
        type: Array,
        default: () => [],
    },
    loading: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    emptyLabel: {
        type: String,
        default: '',
    },
    initialView: {
        type: String,
        default: 'month',
    },
    selectedEventId: {
        type: [String, Number],
        default: null,
    },
    showDayCount: {
        type: Boolean,
        default: true,
    },
    loadingLabel: {
        type: String,
        default: '',
    },
    timezone: {
        type: String,
        default: 'UTC',
    },
});

const emit = defineEmits(['range-change', 'event-click', 'view-change']);
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

watch(dayjsLocale, (nextLocale) => {
    dayjs.locale(nextLocale);
}, { immediate: true });

const calendarTimezone = computed(() => {
    const candidate = String(props.timezone || '').trim() || 'UTC';

    try {
        new Intl.DateTimeFormat('en', { timeZone: candidate }).format();
        return candidate;
    } catch {
        return 'UTC';
    }
});

const calendarLocale = computed(() => {
    const candidate = String(locale.value || dayjsLocale.value || 'en').replaceAll('_', '-');

    try {
        new Intl.DateTimeFormat(candidate).format();
        return candidate;
    } catch {
        return dayjsLocale.value;
    }
});

const formatCalendarDate = (value, options) => {
    const date = dayjs.isDayjs(value) ? value.toDate() : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '–';
    }

    return new Intl.DateTimeFormat(calendarLocale.value, {
        ...options,
        timeZone: calendarTimezone.value,
    }).format(date);
};

const todayClock = ref(Date.now());
let todayRefreshTimer = null;
const zonedNow = () => dayjs().tz(calendarTimezone.value);
const toCalendarTime = (value) => dayjs(value).tz(calendarTimezone.value);
const addCalendarTime = (value, amount, unit) => addReservationCalendarTime(
    value,
    amount,
    unit,
    calendarTimezone.value
);
const calendarStartOf = (value, unit) => reservationCalendarStartOf(
    value,
    unit,
    calendarTimezone.value
);
const calendarEndOf = (value, unit) => reservationCalendarEndOf(
    value,
    unit,
    calendarTimezone.value
);
const todayDate = computed(() => {
    void todayClock.value;

    return currentReservationDay(zonedNow(), calendarTimezone.value);
});
const availableViews = ['day', 'week', 'month', 'year'];
const viewMode = ref(availableViews.includes(props.initialView) ? props.initialView : 'month');
const anchorDate = ref(todayDate.value);
const viewLabels = computed(() => ({
    day: t('planning.calendar.day'),
    week: t('planning.calendar.week'),
    month: t('planning.calendar.month'),
    year: t('planning.calendar.year'),
}));

watch(calendarTimezone, (nextTimezone) => {
    anchorDate.value = reservationCalendarDay(anchorDate.value, nextTimezone);
});

onMounted(() => {
    todayRefreshTimer = window.setInterval(() => {
        todayClock.value = Date.now();
    }, 60_000);
});

onBeforeUnmount(() => {
    if (todayRefreshTimer !== null) {
        window.clearInterval(todayRefreshTimer);
        todayRefreshTimer = null;
    }
});

const eventKey = (event) => String(event.id ?? `${event.start || ''}-${event.title || ''}`);
const selectedKey = computed(() => (props.selectedEventId === null ? null : String(props.selectedEventId)));

const personInitials = (value) => {
    const words = String(value || '')
        .trim()
        .split(/\s+/u)
        .filter(Boolean);

    if (!words.length) {
        return '–';
    }

    const selectedWords = words.length === 1 ? words : [words[0], words.at(-1)];

    return selectedWords
        .map((word) => Array.from(word)[0] || '')
        .join('')
        .toLocaleUpperCase()
        .slice(0, 2);
};

const rangeForView = (mode = viewMode.value) => {
    if (mode === 'day') {
        return {
            start: calendarStartOf(anchorDate.value, 'day'),
            end: calendarEndOf(anchorDate.value, 'day'),
        };
    }

    if (mode === 'week') {
        const start = reservationWeekStart(
            anchorDate.value,
            undefined,
            calendarTimezone.value
        );
        return {
            start,
            end: calendarEndOf(addCalendarTime(start, 6, 'day'), 'day'),
        };
    }

    if (mode === 'month') {
        const start = reservationMonthGridStart(
            anchorDate.value,
            undefined,
            calendarTimezone.value
        );
        return {
            start,
            end: calendarEndOf(addCalendarTime(start, 41, 'day'), 'day'),
        };
    }

    const start = calendarStartOf(anchorDate.value, 'year');
    return {
        start,
        end: calendarEndOf(start, 'year'),
    };
};

const emitRangeChange = () => {
    const range = rangeForView(viewMode.value);

    emit('range-change', {
        start: range.start.toISOString(),
        end: range.end.toISOString(),
        view: viewMode.value,
    });
};

watch(
    () => props.initialView,
    (value) => {
        if (availableViews.includes(value)) {
            viewMode.value = value;
        }
    }
);

watch([viewMode, anchorDate], () => {
    emit('view-change', viewMode.value);
    emitRangeChange();
}, { immediate: true });

const visibleRange = computed(() => rangeForView(viewMode.value));

const parsedEvents = computed(() => (props.events || [])
    .map((event) => {
        if (!event?.start) {
            return null;
        }

        const start = toCalendarTime(event.start);
        const end = toCalendarTime(event.end || event.start);

        if (!start.isValid() || !end.isValid()) {
            return null;
        }

        const extendedProps = event?.extendedProps || {};
        const title = event.title || t('reservations.title');
        const titleParts = title.split('·').map((part) => part.trim()).filter(Boolean);
        const serviceName = extendedProps.service_name || titleParts[0] || title;
        const clientName = extendedProps.client_name || titleParts[1] || '';
        const teamMemberName = extendedProps.team_member_name || '';

        return {
            ...event,
            key: eventKey(event),
            dayKey: start.format('YYYY-MM-DD'),
            monthKey: start.format('YYYY-MM'),
            title,
            serviceName,
            clientName,
            teamMemberName,
            personName: teamMemberName || clientName,
            personInitials: personInitials(teamMemberName || clientName),
            source: extendedProps.source || '',
            status: extendedProps.status || 'slot',
            requiresOutcomeReview: Boolean(extendedProps.outcome_review_required_at),
            startAt: start,
            endAt: end,
            original: event,
        };
    })
    .filter(Boolean)
    .sort((left, right) => left.startAt.valueOf() - right.startAt.valueOf()));

const eventsByDay = computed(() => {
    const range = visibleRange.value;

    return indexReservationEventsByDay(parsedEvents.value, {
        timezone: calendarTimezone.value,
        rangeStart: range.start,
        rangeEnd: addCalendarTime(range.end, 1, 'millisecond'),
    });
});

const getDayEvents = (dayKey) => eventsByDay.value.get(dayKey) || [];

const getEventStatus = (event) => String(event?.status || event?.original?.extendedProps?.status || '').toLowerCase();
const getEventDotClasses = (event) => reservationStatusDotClasses(getEventStatus(event));

const getEventStatusLabel = (event) => {
    const status = getEventStatus(event);
    const key = `reservations.status.${status}`;
    const translated = t(key);

    const statusLabel = translated === key
        ? status.replaceAll('_', ' ').replace(/\b\p{L}/gu, (letter) => letter.toLocaleUpperCase())
        : translated;

    return event?.requiresOutcomeReview
        ? `${statusLabel} · ${t('reservations.outcome_review.badge')}`
        : statusLabel;
};

const getEventSourceLabel = (event) => {
    const normalized = {
        internal: 'staff',
        employee: 'staff',
        public: 'public_booking',
        online: 'public_booking',
    }[String(event?.source || '').toLowerCase()] || String(event?.source || '').toLowerCase();

    if (!normalized) {
        return '';
    }

    const knownSource = ['staff', 'client', 'api', 'public_booking'].includes(normalized)
        ? normalized
        : 'unknown';
    const key = `reservations.details.sources.${knownSource}`;
    const translated = t(key);

    return translated === key
        ? normalized.replaceAll('_', ' ').replace(/\b\p{L}/gu, (letter) => letter.toLocaleUpperCase())
        : translated;
};

const eventsByMonth = computed(() => {
    const map = {};

    parsedEvents.value.forEach((event) => {
        const key = event.monthKey;
        map[key] = (map[key] || 0) + 1;
    });

    return map;
});

const eventsByMonthList = computed(() => {
    const map = {};

    parsedEvents.value.forEach((event) => {
        const key = event.monthKey;
        if (!map[key]) {
            map[key] = [];
        }
        map[key].push(event);
    });

    Object.values(map).forEach((list) => {
        list.sort((left, right) => left.startAt.valueOf() - right.startAt.valueOf());
    });

    return map;
});

const getMonthPreviewEvents = (monthKey) => (eventsByMonthList.value[monthKey] || []).slice(0, 2);

const rangeLabel = computed(() => {
    const start = visibleRange.value.start;
    const end = visibleRange.value.end;

    if (viewMode.value === 'day') {
        return formatCalendarDate(start, { dateStyle: 'medium' });
    }

    if (viewMode.value === 'year') {
        return formatCalendarDate(start, { year: 'numeric' });
    }

    return `${formatCalendarDate(start, { dateStyle: 'medium' })} – ${formatCalendarDate(end, { dateStyle: 'medium' })}`;
});

const mainTitle = computed(() => {
    if (viewMode.value === 'day') {
        return formatCalendarDate(anchorDate.value, { dateStyle: 'long' });
    }

    if (viewMode.value === 'week') {
        const start = reservationWeekStart(
            anchorDate.value,
            undefined,
            calendarTimezone.value
        );
        const end = addCalendarTime(start, 6, 'day');
        return `${formatCalendarDate(start, { month: 'short', day: 'numeric' })} – ${formatCalendarDate(end, { year: 'numeric', month: 'short', day: 'numeric' })}`;
    }

    if (viewMode.value === 'year') {
        return formatCalendarDate(anchorDate.value, { year: 'numeric' });
    }

    return formatCalendarDate(anchorDate.value, { year: 'numeric', month: 'long' });
});

const weekDayLabels = computed(() => ([
    t('planning.weekdays.mo'),
    t('planning.weekdays.tu'),
    t('planning.weekdays.we'),
    t('planning.weekdays.th'),
    t('planning.weekdays.fr'),
    t('planning.weekdays.sa'),
    t('planning.weekdays.su'),
]));

const monthGrid = computed(() => {
    return reservationMonthGridDates(
        anchorDate.value,
        42,
        calendarTimezone.value
    ).map((date) => {
        return {
            key: date.format('YYYY-MM-DD'),
            date,
            label: date.date(),
            isCurrentMonth: date.month() === anchorDate.value.month(),
            isToday: date.isSame(todayDate.value, 'day'),
            isWeekend: [0, 6].includes(date.day()),
        };
    });
});

const weekDays = computed(() => {
    const start = reservationWeekStart(
        anchorDate.value,
        undefined,
        calendarTimezone.value
    );

    return Array.from({ length: 7 }, (_, index) => {
        const date = addCalendarTime(start, index, 'day');

        return {
            key: date.format('YYYY-MM-DD'),
            date,
            label: date.date(),
            isToday: date.isSame(todayDate.value, 'day'),
            isWeekend: [0, 6].includes(date.day()),
        };
    });
});

const dayEvents = computed(() => getDayEvents(anchorDate.value.format('YYYY-MM-DD')));
const hasEvents = computed(() => parsedEvents.value.length > 0);
const computedLoadingLabel = computed(() => props.loadingLabel || t('planning.filters.loading'));
const yearMonths = computed(() => {
    const start = calendarStartOf(anchorDate.value, 'year');
    return Array.from({ length: 12 }, (_, index) => addCalendarTime(start, index, 'month'));
});
const yearCountLabel = (count) => t('planning.preview.count_services', { count });

const setViewMode = (mode) => {
    if (!availableViews.includes(mode)) {
        return;
    }

    const nextAnchor = resolveReservationViewAnchor({
        currentView: viewMode.value,
        nextView: mode,
        anchor: anchorDate.value,
        now: zonedNow(),
        timezone: calendarTimezone.value,
    });

    if (!nextAnchor.isSame(anchorDate.value)) {
        anchorDate.value = nextAnchor;
    }

    viewMode.value = mode;
};

const goPrev = () => {
    if (viewMode.value === 'day') {
        anchorDate.value = addCalendarTime(anchorDate.value, -1, 'day');
        return;
    }

    if (viewMode.value === 'week') {
        anchorDate.value = addCalendarTime(anchorDate.value, -1, 'week');
        return;
    }

    if (viewMode.value === 'month') {
        anchorDate.value = addCalendarTime(anchorDate.value, -1, 'month');
        return;
    }

    anchorDate.value = addCalendarTime(anchorDate.value, -1, 'year');
};

const goNext = () => {
    if (viewMode.value === 'day') {
        anchorDate.value = addCalendarTime(anchorDate.value, 1, 'day');
        return;
    }

    if (viewMode.value === 'week') {
        anchorDate.value = addCalendarTime(anchorDate.value, 1, 'week');
        return;
    }

    if (viewMode.value === 'month') {
        anchorDate.value = addCalendarTime(anchorDate.value, 1, 'month');
        return;
    }

    anchorDate.value = addCalendarTime(anchorDate.value, 1, 'year');
};

const goToday = () => {
    anchorDate.value = currentReservationDay(zonedNow(), calendarTimezone.value);
};

const openDay = (date) => {
    anchorDate.value = reservationCalendarDay(date, calendarTimezone.value);
    viewMode.value = 'day';
};

const openDayView = (date) => {
    openDay(date);
};

const setMonth = (month, preserveDay = true) => {
    const next = reservationCalendarDay(month, calendarTimezone.value);
    if (!next.isValid()) {
        return;
    }

    if (!preserveDay) {
        anchorDate.value = reservationCalendarStartOf(
            next,
            'month',
            calendarTimezone.value
        );
        return;
    }

    const day = anchorDate.value.date();
    const targetDay = Math.min(day, next.daysInMonth());
    anchorDate.value = addReservationCalendarTime(
        reservationCalendarStartOf(next, 'month', calendarTimezone.value),
        targetDay - 1,
        'day',
        calendarTimezone.value
    );
};

const formatEventTime = (event) => {
    if (event?.original?.allDay) {
        return t('planning.all_day');
    }

    const start = event.startAt.format('HH:mm');
    const end = event.endAt.format('HH:mm');

    return start === end ? start : `${start} - ${end}`;
};

const eventAccessibleLabel = (event) => [
    formatEventTime(event),
    event.serviceName,
    event.clientName,
    event.teamMemberName,
    getEventStatusLabel(event),
].filter(Boolean).join(', ');

const yearMonthAccessibleLabel = (month) => {
    const monthEvents = eventsByMonthList.value[month.format('YYYY-MM')] || [];

    return [
        formatCalendarDate(month, { year: 'numeric', month: 'long' }),
        yearCountLabel(monthEvents.length),
        ...monthEvents.slice(0, 2).map((event) => eventAccessibleLabel(event)),
    ].filter(Boolean).join('. ');
};

const dayAccessibleLabel = (day) => {
    const date = formatCalendarDate(day.date, {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
    const count = getDayEvents(day.key).length;

    return `${t('planning.calendar.open_day', { date })}. ${yearCountLabel(count)}`;
};

const clickEvent = (event) => {
    emit('event-click', event.original || event);
};

const eventClasses = (event) => [
    ...reservationStatusEventClasses(getEventStatus(event), {
        selected: selectedKey.value !== null && selectedKey.value === event.key,
    }),
    ...(event?.requiresOutcomeReview
        ? ['outline', 'outline-2', 'outline-offset-1', 'outline-amber-400', 'dark:outline-amber-300']
        : []),
];
</script>

<template>
    <section
        class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-950"
        aria-labelledby="reservation-calendar-title"
        :aria-busy="loading"
    >
        <header
            class="border-b border-stone-200 bg-stone-50/80 px-4 py-4 dark:border-neutral-800 dark:bg-neutral-900 sm:px-5 sm:py-5"
        >
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div class="min-w-0">
                    <p
                        class="flex items-center gap-2 text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-emerald-700 dark:text-emerald-300"
                    >
                        <CalendarDays aria-hidden="true" class="h-4 w-4" />
                        {{ t('planning.calendar.title') }}
                    </p>
                    <h2
                        id="reservation-calendar-title"
                        class="mt-1 break-words text-xl font-semibold capitalize text-stone-950 dark:text-white sm:text-2xl"
                    >
                        {{ mainTitle }}
                    </h2>
                    <div
                        class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-stone-600 dark:text-neutral-300"
                    >
                        <span>{{ t('planning.filters.range') }}: {{ rangeLabel || '–' }}</span>
                        <span
                            class="inline-flex items-center rounded-full bg-white px-2 py-0.5 font-semibold text-stone-700 ring-1 ring-stone-200 dark:bg-neutral-800 dark:text-neutral-200 dark:ring-neutral-700"
                        >
                            {{ yearCountLabel(parsedEvents.length) }}
                        </span>
                        <span
                            v-if="loading"
                            class="inline-flex items-center gap-1.5 text-stone-500 dark:text-neutral-400"
                        >
                            <LoaderCircle
                                aria-hidden="true"
                                class="h-3.5 w-3.5 animate-spin motion-reduce:animate-none"
                            />
                            {{ computedLoadingLabel }}
                        </span>
                    </div>
                </div>

                <div
                    class="flex w-full overflow-x-auto rounded-xl border border-stone-200 bg-white p-1 shadow-sm dark:border-neutral-700 dark:bg-neutral-950 xl:w-auto"
                    role="group"
                    :aria-label="t('planning.calendar.title')"
                >
                    <button
                        v-for="mode in availableViews"
                        :key="mode"
                        type="button"
                        class="min-h-10 min-w-[5rem] flex-1 whitespace-nowrap rounded-lg px-3 py-2 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-1 motion-reduce:transition-none dark:focus-visible:ring-offset-neutral-950 xl:flex-none"
                        :class="
                            viewMode === mode
                                ? 'bg-emerald-700 text-white shadow-sm dark:bg-emerald-600'
                                : 'text-stone-600 hover:bg-stone-100 hover:text-stone-950 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:hover:text-white'
                        "
                        :aria-pressed="viewMode === mode"
                        @click="setViewMode(mode)"
                    >
                        {{ viewLabels[mode] }}
                    </button>
                </div>
            </div>

            <div class="mt-4 flex items-center justify-between gap-3 border-t border-stone-200 pt-4 dark:border-neutral-800">
                <button
                    type="button"
                    class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-xl border border-stone-200 bg-white text-stone-600 shadow-sm transition hover:border-stone-300 hover:bg-stone-100 hover:text-stone-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 motion-reduce:transition-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:hover:text-white dark:focus-visible:ring-offset-neutral-900"
                    :aria-label="t('planning.calendar.previous')"
                    @click="goPrev"
                >
                    <ChevronLeft aria-hidden="true" class="h-5 w-5" />
                </button>
                <button
                    type="button"
                    class="min-h-11 rounded-xl border border-emerald-200 bg-white px-4 py-2 text-xs font-bold text-emerald-800 shadow-sm transition hover:border-emerald-300 hover:bg-emerald-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 motion-reduce:transition-none dark:border-emerald-500/30 dark:bg-neutral-950 dark:text-emerald-200 dark:hover:bg-emerald-500/10 dark:focus-visible:ring-offset-neutral-900"
                    @click="goToday"
                >
                    {{ t('planning.calendar.today') }}
                </button>
                <button
                    type="button"
                    class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-xl border border-stone-200 bg-white text-stone-600 shadow-sm transition hover:border-stone-300 hover:bg-stone-100 hover:text-stone-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 motion-reduce:transition-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:hover:text-white dark:focus-visible:ring-offset-neutral-900"
                    :aria-label="t('planning.calendar.next')"
                    @click="goNext"
                >
                    <ChevronRight aria-hidden="true" class="h-5 w-5" />
                </button>
            </div>
        </header>

        <div>
            <div v-if="loading" class="p-4 sm:p-5" role="status">
                <span class="sr-only">{{ computedLoadingLabel }}</span>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="index in 6"
                        :key="`reservation-calendar-skeleton-${index}`"
                        aria-hidden="true"
                        class="animate-pulse rounded-xl border border-stone-200 bg-stone-50 p-4 motion-reduce:animate-none dark:border-neutral-800 dark:bg-neutral-900"
                    >
                        <div class="h-3 w-20 rounded-full bg-stone-200 dark:bg-neutral-700" />
                        <div class="mt-3 h-2.5 w-32 rounded-full bg-stone-200 dark:bg-neutral-700" />
                        <div class="mt-4 h-14 rounded-lg bg-white dark:bg-neutral-800" />
                    </div>
                </div>
            </div>

            <template v-else>
                <div v-if="viewMode === 'month'">
                    <div
                        class="overflow-x-auto focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-500"
                        tabindex="0"
                        :aria-label="`${t('planning.calendar.month')}: ${mainTitle}`"
                    >
                        <div class="min-w-[52rem]">
                            <div
                                class="grid grid-cols-7 border-b border-stone-200 bg-stone-100 text-[0.6875rem] font-bold uppercase tracking-[0.12em] text-stone-500 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-400"
                            >
                                <span
                                    v-for="label in weekDayLabels"
                                    :key="`header-${label}`"
                                    class="px-3 py-3 text-end"
                                >
                                    {{ label }}
                                </span>
                            </div>

                            <div class="grid grid-cols-7">
                                <div
                                    v-for="(day, index) in monthGrid"
                                    :key="day.key"
                                    class="relative min-h-36 border-b border-l border-stone-200 p-2 pt-12 dark:border-neutral-800"
                                    :class="[
                                        index % 7 === 0 ? 'border-l-0' : '',
                                        day.isWeekend
                                            ? 'bg-stone-50/80 dark:bg-neutral-900/40'
                                            : 'bg-white dark:bg-neutral-950',
                                        !day.isCurrentMonth
                                            ? 'bg-stone-100/90 dark:bg-neutral-900/70'
                                            : '',
                                        day.isToday
                                            ? 'bg-emerald-50 ring-1 ring-inset ring-emerald-300 dark:bg-emerald-500/10 dark:ring-emerald-500/40'
                                            : '',
                                    ]"
                                >
                                    <span
                                        v-if="showDayCount && getDayEvents(day.key).length"
                                        class="absolute left-2 top-2 inline-flex min-w-6 items-center justify-center rounded-full bg-stone-200 px-1.5 py-0.5 text-[0.625rem] font-bold text-stone-700 dark:bg-neutral-700 dark:text-neutral-200"
                                        aria-hidden="true"
                                    >
                                        {{ getDayEvents(day.key).length }}
                                    </span>
                                    <button
                                        type="button"
                                        class="absolute right-1.5 top-1.5 z-10 inline-flex min-h-9 min-w-9 items-center justify-center rounded-full px-1 text-xs font-bold transition hover:bg-stone-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-1 motion-reduce:transition-none dark:hover:bg-neutral-700 dark:focus-visible:ring-offset-neutral-900"
                                        :class="[
                                            day.isToday
                                                ? 'bg-emerald-700 text-white hover:bg-emerald-800 dark:bg-emerald-600'
                                                : day.isCurrentMonth
                                                  ? 'text-stone-700 dark:text-neutral-200'
                                                  : 'text-stone-400 dark:text-neutral-500',
                                        ]"
                                        :aria-label="dayAccessibleLabel(day)"
                                        :aria-current="day.isToday ? 'date' : undefined"
                                        @click="openDay(day.date)"
                                    >
                                        {{ day.label }}
                                    </button>

                                    <div class="space-y-1.5">
                                        <button
                                            v-for="event in getDayEvents(day.key).slice(0, 2)"
                                            :key="`${day.key}-${event.key}`"
                                            type="button"
                                            class="group w-full min-w-0 text-left text-[0.6875rem] leading-snug shadow-sm ring-1 ring-black/5 transition duration-150 hover:-translate-y-px hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-1 motion-reduce:transform-none motion-reduce:transition-none dark:ring-white/5 dark:focus-visible:ring-offset-neutral-900"
                                            :class="eventClasses(event)"
                                            :aria-label="eventAccessibleLabel(event)"
                                            :aria-pressed="selectedKey !== null && selectedKey === event.key"
                                            @click="clickEvent(event)"
                                        >
                                            <span class="flex min-w-0 items-center justify-between gap-1.5">
                                                <span class="inline-flex min-w-0 items-center gap-1.5">
                                                    <span
                                                        aria-hidden="true"
                                                        class="h-1.5 w-1.5 shrink-0 rounded-full"
                                                        :class="getEventDotClasses(event)"
                                                    />
                                                    <span class="truncate font-bold">{{ event.serviceName }}</span>
                                                </span>
                                                <span
                                                    v-if="event.personName"
                                                    class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/80 text-[0.5625rem] font-bold text-stone-700 ring-1 ring-black/10 dark:bg-neutral-900/80 dark:text-neutral-200 dark:ring-white/10"
                                                    role="img"
                                                    :aria-label="event.personName"
                                                >
                                                    {{ event.personInitials }}
                                                </span>
                                            </span>
                                            <span class="mt-1 flex min-w-0 items-center gap-1.5">
                                                <span class="min-w-0 flex-1 truncate font-medium opacity-80">
                                                    {{ formatEventTime(event) }}
                                                    <template v-if="event.clientName"> · {{ event.clientName }}</template>
                                                </span>
                                                <span
                                                    class="max-w-[5.5rem] shrink-0 truncate rounded-full bg-white/75 px-1.5 py-0.5 text-[0.5625rem] font-bold ring-1 ring-black/10 dark:bg-neutral-900/70 dark:ring-white/10"
                                                    :title="getEventStatusLabel(event)"
                                                >
                                                    {{ getEventStatusLabel(event) }}
                                                </span>
                                            </span>
                                        </button>

                                        <button
                                            v-if="getDayEvents(day.key).length > 2"
                                            type="button"
                                            class="inline-flex min-h-8 items-center rounded-lg px-2 text-[0.6875rem] font-semibold text-emerald-700 transition hover:bg-emerald-50 hover:text-emerald-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 motion-reduce:transition-none dark:text-emerald-300 dark:hover:bg-emerald-500/10 dark:hover:text-emerald-200"
                                            @click="openDayView(day.date)"
                                        >
                                            {{
                                                t('planning.preview.more', {
                                                    count: getDayEvents(day.key).length - 2,
                                                })
                                            }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-else-if="viewMode === 'week'" class="bg-stone-50/70 p-3 dark:bg-neutral-950 sm:p-5">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                        <article
                            v-for="day in weekDays"
                            :key="`week-${day.key}`"
                            class="flex min-h-72 min-w-0 flex-col overflow-hidden rounded-2xl border bg-white shadow-sm dark:bg-neutral-900"
                            :class="[
                                day.isWeekend
                                    ? 'border-stone-300 dark:border-neutral-700'
                                    : 'border-stone-200 dark:border-neutral-800',
                                day.isToday
                                    ? 'ring-2 ring-emerald-400/70 dark:ring-emerald-500/60'
                                    : '',
                            ]"
                        >
                            <div
                                class="flex items-center justify-between border-b px-3 py-3 dark:border-neutral-800"
                                :class="
                                    day.isToday
                                        ? 'border-emerald-200 bg-emerald-50 dark:bg-emerald-500/10'
                                        : day.isWeekend
                                          ? 'border-stone-200 bg-stone-100/80 dark:bg-neutral-800/70'
                                          : 'border-stone-200 bg-stone-50 dark:bg-neutral-900'
                                "
                            >
                                <div class="min-w-0">
                                    <p
                                        class="truncate text-[0.6875rem] font-bold uppercase tracking-[0.12em]"
                                        :class="
                                            day.isToday
                                                ? 'text-emerald-700 dark:text-emerald-300'
                                                : 'text-stone-500 dark:text-neutral-400'
                                        "
                                    >
                                        {{ formatCalendarDate(day.date, { weekday: 'long' }) }}
                                    </p>
                                    <p class="mt-0.5 text-[0.6875rem] text-stone-500 dark:text-neutral-400">
                                        {{ yearCountLabel(getDayEvents(day.key).length) }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex min-h-10 min-w-10 items-center justify-center rounded-full px-1 text-sm font-bold transition hover:bg-stone-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-1 motion-reduce:transition-none dark:hover:bg-neutral-700 dark:focus-visible:ring-offset-neutral-900"
                                    :class="
                                        day.isToday
                                            ? 'bg-emerald-700 text-white hover:bg-emerald-800 dark:bg-emerald-600'
                                            : 'text-stone-700 dark:text-neutral-200'
                                    "
                                    :aria-label="dayAccessibleLabel(day)"
                                    :aria-current="day.isToday ? 'date' : undefined"
                                    @click="openDay(day.date)"
                                >
                                    {{ day.label }}
                                </button>
                            </div>

                            <div
                                class="flex-1 space-y-2 overflow-y-auto p-3"
                                :class="day.isWeekend ? 'bg-stone-50/70 dark:bg-neutral-900/50' : ''"
                            >
                                <p
                                    v-if="!getDayEvents(day.key).length"
                                    class="rounded-xl border border-dashed border-stone-200 px-3 py-5 text-center text-xs text-stone-400 dark:border-neutral-700 dark:text-neutral-500"
                                >
                                    {{ emptyLabel || t('reservations.empty') }}
                                </p>

                                <button
                                    v-for="event in getDayEvents(day.key)"
                                    :key="`${day.key}-${event.key}`"
                                    type="button"
                                    class="group w-full min-w-0 text-left text-xs leading-snug shadow-sm ring-1 ring-black/5 transition duration-150 hover:-translate-y-px hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-1 motion-reduce:transform-none motion-reduce:transition-none dark:ring-white/5 dark:focus-visible:ring-offset-neutral-900"
                                    :class="eventClasses(event)"
                                    :aria-label="eventAccessibleLabel(event)"
                                    :aria-pressed="selectedKey !== null && selectedKey === event.key"
                                    @click="clickEvent(event)"
                                >
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="inline-flex min-w-0 items-center gap-1.5 font-bold">
                                            <span
                                                aria-hidden="true"
                                                class="h-2 w-2 shrink-0 rounded-full"
                                                :class="getEventDotClasses(event)"
                                            />
                                            <span class="truncate">{{ formatEventTime(event) }}</span>
                                        </span>
                                        <span
                                            v-if="event.personName"
                                            class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/80 text-[0.625rem] font-bold text-stone-700 ring-1 ring-black/10 dark:bg-neutral-900/80 dark:text-neutral-200 dark:ring-white/10"
                                            role="img"
                                            :aria-label="event.personName"
                                        >
                                            {{ event.personInitials }}
                                        </span>
                                    </span>
                                    <span class="mt-1.5 block truncate text-sm font-bold">
                                        {{ event.serviceName }}
                                    </span>
                                    <span v-if="event.clientName" class="mt-0.5 block truncate opacity-80">
                                        {{ event.clientName }}
                                    </span>
                                    <span
                                        class="mt-1.5 inline-flex max-w-full items-center rounded-full bg-white/75 px-2 py-0.5 text-[0.625rem] font-bold ring-1 ring-black/10 dark:bg-neutral-900/70 dark:ring-white/10"
                                    >
                                        <span class="truncate">{{ getEventStatusLabel(event) }}</span>
                                    </span>
                                    <span
                                        v-if="event.teamMemberName"
                                        class="mt-1.5 flex min-w-0 items-center gap-1.5 border-t border-current/10 pt-1.5 text-[0.6875rem] font-medium opacity-75"
                                    >
                                        <UsersRound aria-hidden="true" class="h-3 w-3 shrink-0" />
                                        <span class="truncate">{{ event.teamMemberName }}</span>
                                    </span>
                                </button>
                            </div>
                        </article>
                    </div>
                </div>

                <div v-else-if="viewMode === 'day'" class="bg-stone-50/70 p-3 dark:bg-neutral-950 sm:p-5">
                    <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 bg-stone-50 px-4 py-4 dark:border-neutral-800 dark:bg-neutral-900 sm:px-5"
                            :class="
                                anchorDate.isSame(todayDate, 'day')
                                    ? 'bg-emerald-50 dark:bg-emerald-500/10'
                                    : ''
                            "
                        >
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.12em] text-stone-500 dark:text-neutral-400">
                                    {{ t('planning.calendar.day') }}
                                </p>
                                <p class="mt-1 text-sm font-semibold capitalize text-stone-950 dark:text-white">
                                    {{ formatCalendarDate(anchorDate, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) }}
                                </p>
                            </div>
                            <span
                                class="inline-flex rounded-full bg-stone-200 px-2.5 py-1 text-xs font-bold text-stone-700 dark:bg-neutral-700 dark:text-neutral-200"
                            >
                                {{ yearCountLabel(dayEvents.length) }}
                            </span>
                        </div>

                        <div class="max-h-[70vh] overflow-y-auto p-3 sm:p-5">
                            <p
                                v-if="!dayEvents.length"
                                class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-4 py-10 text-center text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-400"
                            >
                                {{ emptyLabel || t('reservations.empty') }}
                            </p>

                            <div v-else class="grid gap-3 lg:grid-cols-2">
                                <button
                                    v-for="event in dayEvents"
                                    :key="`day-${event.key}`"
                                    type="button"
                                    class="group w-full min-w-0 text-left text-xs leading-snug shadow-sm ring-1 ring-black/5 transition duration-150 hover:-translate-y-px hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 motion-reduce:transform-none motion-reduce:transition-none dark:ring-white/5 dark:focus-visible:ring-offset-neutral-900"
                                    :class="eventClasses(event)"
                                    :aria-label="eventAccessibleLabel(event)"
                                    :aria-pressed="selectedKey !== null && selectedKey === event.key"
                                    @click="clickEvent(event)"
                                >
                                    <span class="flex min-w-0 items-start justify-between gap-3">
                                        <span class="min-w-0">
                                            <span class="inline-flex items-center gap-1.5 font-bold">
                                                <Clock3 aria-hidden="true" class="h-3.5 w-3.5" />
                                                {{ formatEventTime(event) }}
                                            </span>
                                            <span class="mt-2 block break-words text-base font-bold">
                                                {{ event.serviceName }}
                                            </span>
                                        </span>
                                        <span
                                            v-if="event.personName"
                                            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/80 text-xs font-bold text-stone-700 ring-1 ring-black/10 dark:bg-neutral-900/80 dark:text-neutral-200 dark:ring-white/10"
                                            role="img"
                                            :aria-label="event.personName"
                                        >
                                            {{ event.personInitials }}
                                        </span>
                                    </span>
                                    <span class="mt-3 grid gap-2 border-t border-current/10 pt-3 sm:grid-cols-2">
                                        <span
                                            v-if="event.clientName"
                                            class="flex min-w-0 items-center gap-1.5"
                                        >
                                            <UserRound aria-hidden="true" class="h-3.5 w-3.5 shrink-0" />
                                            <span class="truncate">{{ event.clientName }}</span>
                                        </span>
                                        <span
                                            v-if="event.teamMemberName"
                                            class="flex min-w-0 items-center gap-1.5"
                                        >
                                            <UsersRound aria-hidden="true" class="h-3.5 w-3.5 shrink-0" />
                                            <span class="truncate">{{ event.teamMemberName }}</span>
                                        </span>
                                    </span>
                                    <span class="mt-3 flex flex-wrap items-center gap-2">
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white/70 px-2 py-0.5 text-[0.6875rem] font-semibold ring-1 ring-black/5 dark:bg-neutral-900/70 dark:ring-white/10">
                                            <span
                                                aria-hidden="true"
                                                class="h-1.5 w-1.5 rounded-full"
                                                :class="getEventDotClasses(event)"
                                            />
                                            {{ getEventStatusLabel(event) }}
                                        </span>
                                        <span
                                            v-if="getEventSourceLabel(event)"
                                            class="rounded-full bg-white/70 px-2 py-0.5 text-[0.6875rem] font-semibold ring-1 ring-black/5 dark:bg-neutral-900/70 dark:ring-white/10"
                                        >
                                            {{ getEventSourceLabel(event) }}
                                        </span>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-else class="bg-stone-50/70 p-3 dark:bg-neutral-950 sm:p-5">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <button
                            v-for="month in yearMonths"
                            :key="month.format('YYYY-MM')"
                            type="button"
                            class="min-h-44 min-w-0 rounded-2xl border border-stone-200 bg-white p-4 text-left shadow-sm transition duration-150 hover:-translate-y-0.5 hover:border-emerald-300 hover:bg-emerald-50 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 motion-reduce:transform-none motion-reduce:transition-none dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-emerald-500/50 dark:hover:bg-emerald-500/10 dark:focus-visible:ring-offset-neutral-950"
                            :class="
                                month.isSame(todayDate, 'month')
                                    ? 'border-emerald-400 ring-1 ring-emerald-300 dark:border-emerald-500/60 dark:ring-emerald-500/30'
                                    : ''
                            "
                            :aria-label="yearMonthAccessibleLabel(month)"
                            :aria-current="month.isSame(todayDate, 'month') ? 'date' : undefined"
                            @click="setMonth(month, false); setViewMode('month')"
                        >
                            <span class="flex items-start justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-bold capitalize text-stone-950 dark:text-white">
                                        {{ formatCalendarDate(month, { month: 'long' }) }}
                                    </span>
                                    <span class="mt-0.5 block text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatCalendarDate(month, { year: 'numeric' }) }}
                                    </span>
                                </span>
                                <span class="shrink-0 rounded-full bg-stone-100 px-2 py-0.5 text-[0.6875rem] font-bold text-stone-600 dark:bg-neutral-800 dark:text-neutral-300">
                                    {{ eventsByMonth[month.format('YYYY-MM')] || 0 }}
                                </span>
                            </span>
                            <span class="mt-4 block space-y-2">
                                <span
                                    v-for="event in getMonthPreviewEvents(month.format('YYYY-MM'))"
                                    :key="event.key"
                                    class="flex min-w-0 items-center gap-2 rounded-lg bg-stone-50 px-2.5 py-2 text-[0.6875rem] text-stone-600 dark:bg-neutral-950 dark:text-neutral-300"
                                >
                                    <span
                                        aria-hidden="true"
                                        class="h-1.5 w-1.5 shrink-0 rounded-full"
                                        :class="getEventDotClasses(event)"
                                    />
                                    <span class="min-w-0 flex-1 truncate">
                                        <strong>{{ formatCalendarDate(event.startAt, { month: 'short', day: 'numeric' }) }}</strong>
                                        · {{ event.serviceName }}
                                        <template v-if="event.clientName"> · {{ event.clientName }}</template>
                                    </span>
                                    <span
                                        class="max-w-[6rem] shrink-0 truncate rounded-full bg-white px-1.5 py-0.5 text-[0.5625rem] font-bold text-stone-700 ring-1 ring-stone-200 dark:bg-neutral-900 dark:text-neutral-200 dark:ring-neutral-700"
                                        :title="getEventStatusLabel(event)"
                                    >
                                        {{ getEventStatusLabel(event) }}
                                    </span>
                                </span>
                                <span
                                    v-if="!getMonthPreviewEvents(month.format('YYYY-MM')).length"
                                    class="block rounded-lg border border-dashed border-stone-200 px-2.5 py-4 text-center text-[0.6875rem] text-stone-400 dark:border-neutral-700 dark:text-neutral-500"
                                >
                                    {{ emptyLabel || t('reservations.empty') }}
                                </span>
                            </span>
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <div
            v-if="error"
            class="flex items-start gap-2 border-t border-rose-200 bg-rose-50 p-4 text-xs font-medium text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-200"
            role="alert"
        >
            <AlertCircle aria-hidden="true" class="mt-0.5 h-4 w-4 shrink-0" />
            <span class="break-words">{{ error }}</span>
        </div>
        <div
            v-else-if="!loading && !hasEvents"
            class="flex items-center gap-2 border-t border-stone-200 bg-stone-50 p-4 text-xs text-stone-500 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-400"
            role="status"
        >
            <CalendarDays aria-hidden="true" class="h-4 w-4 shrink-0" />
            {{ emptyLabel || t('reservations.empty') }}
        </div>
    </section>
</template>
