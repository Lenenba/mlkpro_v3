<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import dayjs from 'dayjs';
import 'dayjs/locale/fr';
import {
    reservationStatusDotClasses,
    reservationStatusEventClasses,
} from '@/Components/Reservation/status';

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
});

const emit = defineEmits(['range-change', 'event-click', 'view-change']);
const { t, locale } = useI18n();
const dayjsLocale = computed(() => (String(locale.value || '').toLowerCase().startsWith('fr') ? 'fr' : 'en'));

watch(dayjsLocale, (nextLocale) => {
    dayjs.locale(nextLocale);
}, { immediate: true });

const weekStartsOn = 1;
const todayDate = dayjs();
const availableViews = ['day', 'week', 'month', 'year'];
const viewMode = ref(availableViews.includes(props.initialView) ? props.initialView : 'month');
const anchorDate = ref(todayDate);

const eventKey = (event) => String(event.id ?? `${event.start || ''}-${event.title || ''}`);
const selectedKey = computed(() => (props.selectedEventId === null ? null : String(props.selectedEventId)));

const getWeekStart = (date) => {
    const offset = (date.day() - weekStartsOn + 7) % 7;
    return date.subtract(offset, 'day');
};

const getMonthGridStart = (value) => {
    const firstDay = value.startOf('month');
    const offset = (firstDay.day() - weekStartsOn + 7) % 7;
    return firstDay.subtract(offset, 'day');
};

const rangeForView = (mode = viewMode.value) => {
    if (mode === 'day') {
        return {
            start: anchorDate.value.startOf('day'),
            end: anchorDate.value.endOf('day'),
        };
    }

    if (mode === 'week') {
        const start = getWeekStart(anchorDate.value);
        return {
            start,
            end: start.add(6, 'day').endOf('day'),
        };
    }

    if (mode === 'month') {
        const start = getMonthGridStart(anchorDate.value);
        return {
            start,
            end: start.add(41, 'day').endOf('day'),
        };
    }

    const start = anchorDate.value.startOf('year');
    return {
        start,
        end: start.endOf('year'),
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

const parsedEvents = computed(() => (props.events || [])
    .map((event) => {
        const start = dayjs(event.start);
        const end = dayjs(event.end || event.start);

        if (!start.isValid() || !end.isValid()) {
            return null;
        }

        return {
            ...event,
            key: eventKey(event),
            dayKey: start.format('YYYY-MM-DD'),
            monthKey: start.format('YYYY-MM'),
            title: event.title || t('reservations.title'),
            status: event?.extendedProps?.status || 'slot',
            startAt: start,
            endAt: end,
            original: event,
        };
    })
    .filter(Boolean)
    .sort((left, right) => left.startAt.valueOf() - right.startAt.valueOf()));

const eventsByDay = computed(() => {
    const map = new Map();
    parsedEvents.value.forEach((event) => {
        const list = map.get(event.dayKey) || [];
        list.push(event);
        map.set(event.dayKey, list);
    });
    return map;
});

const getDayEvents = (dayKey) => eventsByDay.value.get(dayKey) || [];

const getEventStatus = (event) => String(event?.status || event?.original?.extendedProps?.status || '').toLowerCase();
const getEventDotClasses = (event) => reservationStatusDotClasses(getEventStatus(event));

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

const visibleRange = computed(() => rangeForView(viewMode.value));

const rangeLabel = computed(() => {
    const start = visibleRange.value.start;
    const end = visibleRange.value.end;

    if (viewMode.value === 'day') {
        return start.format('MMM D, YYYY');
    }

    if (viewMode.value === 'year') {
        return start.format('YYYY');
    }

    return `${start.format('MMM D, YYYY')} - ${end.format('MMM D, YYYY')}`;
});

const mainTitle = computed(() => {
    if (viewMode.value === 'day') {
        return anchorDate.value.format('MMMM D, YYYY');
    }

    if (viewMode.value === 'week') {
        const start = getWeekStart(anchorDate.value);
        const end = start.add(6, 'day');
        return `${start.format('MMM D')} - ${end.format('MMM D, YYYY')}`;
    }

    if (viewMode.value === 'year') {
        return anchorDate.value.format('YYYY');
    }

    return anchorDate.value.format('MMMM YYYY');
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
    const start = getMonthGridStart(anchorDate.value);

    return Array.from({ length: 42 }, (_, index) => {
        const date = start.add(index, 'day');

        return {
            key: date.format('YYYY-MM-DD'),
            date,
            label: date.date(),
            isCurrentMonth: date.month() === anchorDate.value.month(),
            isToday: date.isSame(todayDate, 'day'),
            isWeekend: [0, 6].includes(date.day()),
        };
    });
});

const monthViewDays = computed(() => {
    const days = monthGrid.value || [];
    if (!days.length) {
        return days;
    }

    const weekStart = getWeekStart(todayDate);
    const weekKey = weekStart.format('YYYY-MM-DD');
    const startIndex = days.findIndex((day) => day.key === weekKey);

    if (startIndex <= 0) {
        return days;
    }

    return [...days.slice(startIndex), ...days.slice(0, startIndex)];
});

const weekDays = computed(() => {
    const start = getWeekStart(anchorDate.value);

    return Array.from({ length: 7 }, (_, index) => {
        const date = start.add(index, 'day');

        return {
            key: date.format('YYYY-MM-DD'),
            date,
            label: date.date(),
            isToday: date.isSame(todayDate, 'day'),
            isWeekend: [0, 6].includes(date.day()),
        };
    });
});

const dayEvents = computed(() => getDayEvents(anchorDate.value.format('YYYY-MM-DD')));
const hasEvents = computed(() => parsedEvents.value.length > 0);
const computedLoadingLabel = computed(() => props.loadingLabel || t('planning.filters.loading'));
const yearMonths = computed(() => {
    const start = anchorDate.value.startOf('year');
    return Array.from({ length: 12 }, (_, index) => start.add(index, 'month'));
});
const yearCountLabel = (count) => t('planning.preview.count_services', { count });

const setViewMode = (mode) => {
    if (!availableViews.includes(mode)) {
        return;
    }

    viewMode.value = mode;
};

const goPrev = () => {
    if (viewMode.value === 'day') {
        anchorDate.value = anchorDate.value.subtract(1, 'day');
        return;
    }

    if (viewMode.value === 'week') {
        anchorDate.value = anchorDate.value.subtract(1, 'week');
        return;
    }

    if (viewMode.value === 'month') {
        anchorDate.value = anchorDate.value.subtract(1, 'month');
        return;
    }

    anchorDate.value = anchorDate.value.subtract(1, 'year');
};

const goNext = () => {
    if (viewMode.value === 'day') {
        anchorDate.value = anchorDate.value.add(1, 'day');
        return;
    }

    if (viewMode.value === 'week') {
        anchorDate.value = anchorDate.value.add(1, 'week');
        return;
    }

    if (viewMode.value === 'month') {
        anchorDate.value = anchorDate.value.add(1, 'month');
        return;
    }

    anchorDate.value = anchorDate.value.add(1, 'year');
};

const goToday = () => {
    anchorDate.value = todayDate;
};

const openDay = (date) => {
    anchorDate.value = dayjs(date);
};

const openDayView = (date) => {
    anchorDate.value = dayjs(date);
    viewMode.value = 'day';
};

const setMonth = (month, preserveDay = true) => {
    const next = dayjs(month);
    if (!next.isValid()) {
        return;
    }

    if (!preserveDay) {
        anchorDate.value = next.startOf('month');
        return;
    }

    const day = anchorDate.value.date();
    anchorDate.value = next.date(Math.min(day, next.daysInMonth()));
};

const formatEventTime = (event) => {
    if (event?.original?.allDay) {
        return t('planning.all_day');
    }

    const start = event.startAt.format('HH:mm');
    const end = event.endAt.format('HH:mm');

    return start === end ? start : `${start} - ${end}`;
};

const clickEvent = (event) => {
    emit('event-click', event.original || event);
};

const eventClasses = (event) => reservationStatusEventClasses(getEventStatus(event), {
    selected: selectedKey.value !== null && selectedKey.value === event.key,
});
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-stone-200 border-t-4 border-t-emerald-600 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3 dark:border-neutral-800">
            <div class="text-xs text-stone-500 dark:text-neutral-400">
                {{ t('planning.filters.range') }}: {{ rangeLabel || '--' }}
                <span v-if="loading" class="ms-2 text-stone-400 dark:text-neutral-500">
                    ({{ computedLoadingLabel }})
                </span>
            </div>

            <div class="inline-flex rounded-md border border-stone-200 bg-stone-50 p-0.5 text-xs text-stone-600 shadow-sm dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300">
                <button
                    type="button"
                    class="rounded-sm px-2 py-1"
                    :class="viewMode === 'day' ? 'bg-white text-stone-900 shadow-sm dark:bg-neutral-800 dark:text-white' : ''"
                    @click="setViewMode('day')"
                >
                    {{ t('planning.calendar.day') }}
                </button>
                <button
                    type="button"
                    class="rounded-sm px-2 py-1"
                    :class="viewMode === 'week' ? 'bg-white text-stone-900 shadow-sm dark:bg-neutral-800 dark:text-white' : ''"
                    @click="setViewMode('week')"
                >
                    {{ t('planning.calendar.week') }}
                </button>
                <button
                    type="button"
                    class="rounded-sm px-2 py-1"
                    :class="viewMode === 'month' ? 'bg-white text-stone-900 shadow-sm dark:bg-neutral-800 dark:text-white' : ''"
                    @click="setViewMode('month')"
                >
                    {{ t('planning.calendar.month') }}
                </button>
                <button
                    type="button"
                    class="rounded-sm px-2 py-1"
                    :class="viewMode === 'year' ? 'bg-white text-stone-900 shadow-sm dark:bg-neutral-800 dark:text-white' : ''"
                    @click="setViewMode('year')"
                >
                    Year
                </button>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
            <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                {{ mainTitle }}
            </h2>

            <div class="flex items-center gap-1">
                <button
                    type="button"
                    class="flex h-8 w-8 items-center justify-center rounded-md border border-stone-200 text-stone-500 hover:bg-stone-50 dark:border-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    @click="goPrev"
                >
                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="m15 18-6-6 6-6" />
                    </svg>
                </button>
                <button
                    type="button"
                    class="rounded-md border border-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    @click="goToday"
                >
                    {{ t('planning.calendar.today') }}
                </button>
                <button
                    type="button"
                    class="flex h-8 w-8 items-center justify-center rounded-md border border-stone-200 text-stone-500 hover:bg-stone-50 dark:border-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    @click="goNext"
                >
                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="m9 18 6-6-6-6" />
                    </svg>
                </button>
            </div>
        </div>

        <div class="border-t border-stone-200 dark:border-neutral-800">
            <div v-if="loading" class="p-4">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="index in 6"
                        :key="`reservation-calendar-skeleton-${index}`"
                        class="animate-pulse rounded-sm border border-stone-200 p-3 dark:border-neutral-700"
                    >
                        <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                        <div class="mt-2 h-2 w-32 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                        <div class="mt-2 h-8 rounded-sm bg-stone-100 dark:bg-neutral-800"></div>
                    </div>
                </div>
            </div>

            <template v-else>
                <div v-if="viewMode === 'month'">
                    <div class="grid grid-cols-7 bg-stone-50 text-[11px] uppercase text-stone-500 dark:bg-neutral-900/60 dark:text-neutral-400">
                        <span v-for="label in weekDayLabels" :key="`header-${label}`" class="py-2 text-end px-3">
                            {{ label }}
                        </span>
                    </div>

                    <div class="grid grid-cols-7">
                        <div
                            v-for="(day, index) in monthViewDays"
                            :key="day.key"
                            class="relative min-h-[120px] border-t border-l border-stone-200 dark:border-neutral-800"
                            :class="[
                                index % 7 === 0 ? 'border-l-0' : '',
                                day.isWeekend ? 'bg-stone-50/70 dark:bg-neutral-900/40' : '',
                            ]"
                        >
                            <button
                                type="button"
                                class="absolute right-1.5 top-1.5 z-10 flex min-w-7 items-center justify-center rounded-full px-1 text-xs"
                                :class="[
                                    day.isToday
                                        ? 'bg-emerald-600 text-white'
                                        : day.isCurrentMonth
                                            ? 'text-stone-700 dark:text-neutral-200'
                                            : 'text-stone-400 dark:text-neutral-500',
                                ]"
                                @click="openDay(day.date)"
                            >
                                {{ day.label }}
                            </button>

                            <div class="mt-8 space-y-1 px-2 pb-2">
                                    <button
                                        v-for="event in getDayEvents(day.key).slice(0, 2)"
                                        :key="`${day.key}-${event.key}`"
                                        type="button"
                                        class="w-full text-left text-[11px] leading-snug shadow-sm ring-1 ring-black/5 transition hover:shadow-md"
                                        :class="eventClasses(event)"
                                        @click="clickEvent(event)"
                                    >
                                        <span class="block truncate font-semibold">{{ event.title }}</span>
                                        <span class="block truncate text-[10px]">{{ formatEventTime(event) }}</span>
                                    </button>

                                <button
                                    v-if="getDayEvents(day.key).length > 2"
                                    type="button"
                                    class="text-[11px] text-stone-500 hover:text-stone-700 dark:text-neutral-400"
                                    @click="openDayView(day.date)"
                                >
                                    {{ getDayEvents(day.key).length - 2 }} more
                                </button>

                                <div
                                    v-if="showDayCount && getDayEvents(day.key).length"
                                    class="text-[10px] font-semibold text-stone-400 dark:text-neutral-500"
                                >
                                    {{ getDayEvents(day.key).length }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-else-if="viewMode === 'week'" class="p-4">
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-7">
                        <div
                            v-for="day in weekDays"
                            :key="`week-${day.key}`"
                            class="flex min-h-[260px] flex-col overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                        >
                            <div class="flex items-center justify-between border-b border-stone-200 px-3 py-2 dark:border-neutral-800">
                                <div
                                    class="text-[11px] font-semibold uppercase tracking-wide"
                                    :class="day.isToday ? 'text-emerald-600 dark:text-emerald-400' : 'text-stone-500 dark:text-neutral-400'"
                                >
                                    {{ day.date.format('ddd') }}
                                </div>
                                <button
                                    type="button"
                                    class="flex min-w-7 items-center justify-center rounded-full px-1 text-xs font-semibold"
                                    :class="day.isToday ? 'bg-emerald-600 text-white' : 'text-stone-600 dark:text-neutral-300'"
                                    @click="openDay(day.date)"
                                >
                                    {{ day.label }}
                                </button>
                            </div>

                            <div class="flex-1 space-y-2 overflow-y-auto px-3 pb-3 pt-2" :class="day.isWeekend ? 'bg-stone-50/70 dark:bg-neutral-900/40' : ''">
                                <p v-if="!getDayEvents(day.key).length" class="text-xs text-stone-400 dark:text-neutral-500">
                                    {{ emptyLabel || t('reservations.empty') }}
                                </p>

                                <button
                                    v-for="event in getDayEvents(day.key)"
                                    :key="`${day.key}-${event.key}`"
                                    type="button"
                                    class="w-full text-left text-[12px] leading-snug shadow-sm ring-1 ring-black/5 transition hover:shadow-md"
                                    :class="eventClasses(event)"
                                    @click="clickEvent(event)"
                                >
                                    <div class="flex items-start justify-between gap-2">
                                        <span class="block truncate font-semibold">{{ event.title }}</span>
                                        <span class="mt-1 h-2 w-2 rounded-full" :class="getEventDotClasses(event)"></span>
                                    </div>
                                    <span class="mt-1 block text-[11px] font-medium">{{ formatEventTime(event) }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-else-if="viewMode === 'day'" class="p-4">
                    <div class="rounded-xl border border-stone-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div class="flex items-center justify-between border-b border-stone-200 px-4 py-3 dark:border-neutral-800">
                            <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                {{ t('planning.calendar.day') }}
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ dayEvents.length }}
                            </div>
                        </div>

                        <div class="max-h-[70vh] space-y-2 overflow-y-auto px-4 py-3">
                            <p v-if="!dayEvents.length" class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ emptyLabel || t('reservations.empty') }}
                            </p>

                            <button
                                v-for="event in dayEvents"
                                :key="`day-${event.key}`"
                                type="button"
                                class="w-full text-left text-[12px] leading-snug shadow-sm ring-1 ring-black/5 transition hover:shadow-md"
                                :class="eventClasses(event)"
                                @click="clickEvent(event)"
                            >
                                <span class="block truncate text-sm font-semibold">{{ event.title }}</span>
                                <span class="mt-1 block text-[11px] font-medium">{{ formatEventTime(event) }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div v-else class="p-4">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <button
                            v-for="month in yearMonths"
                            :key="month.format('YYYY-MM')"
                            type="button"
                            class="rounded-lg border border-stone-200 p-3 text-left shadow-sm transition hover:border-emerald-300 hover:bg-emerald-50/40 dark:border-neutral-800 dark:hover:border-emerald-500/40 dark:hover:bg-emerald-500/10"
                            :class="month.isSame(todayDate, 'month') ? 'border-emerald-500/60' : ''"
                            @click="setMonth(month, false); setViewMode('month')"
                        >
                            <div class="text-xs font-semibold text-stone-500 dark:text-neutral-400">
                                {{ month.format('MMMM') }}
                            </div>
                            <div class="mt-2 text-sm font-semibold text-stone-700 dark:text-neutral-200">
                                {{ month.format('YYYY') }}
                            </div>
                            <div class="mt-1 text-[11px] text-stone-500 dark:text-neutral-400">
                                {{ yearCountLabel(eventsByMonth[month.format('YYYY-MM')] || 0) }}
                            </div>
                            <div class="mt-2 space-y-1">
                                <div
                                    v-for="event in getMonthPreviewEvents(month.format('YYYY-MM'))"
                                    :key="event.key"
                                    class="flex items-center gap-2 text-[11px] text-stone-600 dark:text-neutral-300"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full" :class="getEventDotClasses(event)"></span>
                                    <span class="truncate">
                                        {{ event.startAt.format('MMM D') }} · {{ event.title }}
                                    </span>
                                </div>
                                <div v-if="!getMonthPreviewEvents(month.format('YYYY-MM')).length" class="text-[10px] text-stone-400 dark:text-neutral-500">
                                    {{ emptyLabel || t('reservations.empty') }}
                                </div>
                            </div>
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <div
            v-if="error"
            class="border-t border-red-200 bg-red-50 p-3 text-xs text-red-700 dark:border-neutral-800 dark:bg-red-500/10 dark:text-red-200"
        >
            {{ error }}
        </div>
        <div
            v-else-if="!loading && !hasEvents"
            class="border-t border-stone-200 p-3 text-xs text-stone-500 dark:border-neutral-800 dark:text-neutral-400"
        >
            {{ emptyLabel || t('reservations.empty') }}
        </div>
    </section>
</template>
