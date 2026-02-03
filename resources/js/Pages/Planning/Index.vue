<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import dayjs from 'dayjs';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/UI/Card.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/UI/Modal.vue';

const props = defineProps({
    teamMembers: {
        type: Array,
        default: () => [],
    },
    events: {
        type: Array,
        default: () => [],
    },
    range: {
        type: Object,
        default: () => ({}),
    },
    pendingRequests: {
        type: Array,
        default: () => [],
    },
    timeOffSummary: {
        type: Object,
        default: () => ({}),
    },
    canManage: {
        type: Boolean,
        default: false,
    },
    canApproveTimeOff: {
        type: Boolean,
        default: false,
    },
    selfTeamMemberId: {
        type: Number,
        default: null,
    },
    shiftTemplates: {
        type: Array,
        default: () => [],
    },
    defaultShiftTemplate: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
const page = usePage();
const companyType = computed(() => page.props.auth?.account?.company?.type ?? null);
const isServiceCompany = computed(() => companyType.value !== 'products');
const subtitleLabel = computed(() =>
    isServiceCompany.value ? t('planning.subtitle_services') : t('planning.subtitle')
);
const loadingLabel = computed(() =>
    isServiceCompany.value ? t('planning.filters.loading_services') : t('planning.filters.loading')
);
const emptyLabel = computed(() =>
    isServiceCompany.value ? t('planning.filters.empty_services') : t('planning.filters.empty')
);
const previewEmptyLabel = computed(() =>
    isServiceCompany.value ? t('planning.preview.empty_services') : t('planning.preview.empty')
);
const lockedTitle = computed(() =>
    isServiceCompany.value ? t('planning.empty_services.title') : t('planning.empty.title')
);
const lockedDescription = computed(() =>
    isServiceCompany.value ? t('planning.empty_services.description') : t('planning.empty.description')
);
const yearCountLabel = (count) =>
    isServiceCompany.value
        ? t('planning.preview.count_services', { count })
        : t('planning.preview.count', { count });

const calendarEvents = ref([...(props.events || [])]);
const loadingEvents = ref(false);
const loadError = ref('');
const pendingQueue = ref([...(props.pendingRequests || [])]);
const summaryQueue = ref({
    today: props.timeOffSummary?.today || [],
    week: props.timeOffSummary?.week || [],
});
const pollIntervalMs = 30000;
let pollTimer = null;

const defaultRange = {
    start: props.range?.start || dayjs().startOf('week').format('YYYY-MM-DD'),
    end: props.range?.end || dayjs().add(4, 'week').endOf('week').format('YYYY-MM-DD'),
};
const currentRange = ref({ ...defaultRange });

const todayDate = dayjs();
const initialMonth = currentRange.value.start ? dayjs(currentRange.value.start) : todayDate;
const currentMonth = ref(initialMonth.isValid() ? initialMonth : todayDate);
const selectedDate = ref(todayDate);
const viewMode = ref('month');
const defaultShiftTemplate = computed(() => ({
    start_time: props.defaultShiftTemplate?.start_time || '09:00',
    end_time: props.defaultShiftTemplate?.end_time || '17:00',
    break_minutes: Number(props.defaultShiftTemplate?.break_minutes ?? 60),
    days_of_week: Array.isArray(props.defaultShiftTemplate?.days_of_week)
        ? props.defaultShiftTemplate.days_of_week
        : [],
}));

const form = reactive({
    kind: props.canManage ? 'shift' : 'absence',
    team_member_id: props.selfTeamMemberId || props.teamMembers?.[0]?.id || '',
    shift_date: todayDate.format('YYYY-MM-DD'),
    end_date: todayDate.format('YYYY-MM-DD'),
    start_time: defaultShiftTemplate.value.start_time,
    end_time: defaultShiftTemplate.value.end_time,
    break_minutes: defaultShiftTemplate.value.break_minutes,
    title: '',
    notes: '',
    is_recurring: false,
    frequency: 'weekly',
    recurrence_end_date: dayjs().add(1, 'month').format('YYYY-MM-DD'),
});

const timeOffMode = ref('full');
const weekdayValues = ['su', 'mo', 'tu', 'we', 'th', 'fr', 'sa'];
const recurrenceWeekdays = ref([]);
const monthlyDay = ref(String(dayjs().date()));

const formErrors = ref({});
const formProcessing = ref(false);
const updateProcessing = ref(false);
const deleteProcessing = ref(false);
const deleteError = ref('');
const statusProcessing = ref(false);
const statusError = ref('');
const selectedShift = ref(null);
const dragState = ref(null);

const canCreate = computed(() => {
    if (props.canManage) {
        return (props.teamMembers || []).length > 0;
    }
    return Boolean(props.selfTeamMemberId);
});

const isTimeOffKind = computed(() => ['absence', 'leave'].includes(form.kind));
const isTimeOffHours = computed(() => isTimeOffKind.value && timeOffMode.value === 'hours');
const canSubmit = computed(() => canCreate.value && (props.canManage || isTimeOffKind.value));

const memberOptions = computed(() =>
    (props.teamMembers || []).map((member) => ({
        value: member.id,
        label: member.title ? `${member.name} - ${member.title}` : member.name,
    }))
);

const memberById = computed(() => new Map(
    (props.teamMembers || []).map((member) => [Number(member.id), member])
));

const normalizePosition = (value) => String(value || '').trim().toLowerCase();

const templateMap = computed(() => {
    const map = new Map();
    (props.shiftTemplates || []).forEach((template) => {
        const key = normalizePosition(template.position_title);
        if (!key) {
            return;
        }
        map.set(key, template);
    });
    return map;
});

const isTemplateActiveForDate = (template, dateValue) => {
    const days = template?.days_of_week || [];
    if (!Array.isArray(days) || !days.length) {
        return true;
    }
    const date = dayjs(dateValue);
    if (!date.isValid()) {
        return true;
    }
    const dayKey = weekdayValues[date.day()];
    return days.map((item) => String(item).toLowerCase()).includes(dayKey);
};

const manualTimeOverride = ref(false);
const autoApplying = ref(false);

const applyTemplateForSelection = () => {
    if (form.kind !== 'shift') {
        return;
    }

    const member = memberById.value.get(Number(form.team_member_id));
    const positionKey = normalizePosition(member?.title);
    const template = positionKey ? templateMap.value.get(positionKey) : null;
    const shouldApplyTemplate = template && isTemplateActiveForDate(template, form.shift_date);
    const source = shouldApplyTemplate ? template : defaultShiftTemplate.value;

    autoApplying.value = true;
    if (source?.start_time) {
        form.start_time = source.start_time;
    }
    if (source?.end_time) {
        form.end_time = source.end_time;
    }
    if (typeof source?.break_minutes === 'number') {
        form.break_minutes = source.break_minutes;
    }
    window.setTimeout(() => {
        autoApplying.value = false;
    }, 0);
};

const kindOptions = computed(() => {
    const options = [];
    if (props.canManage) {
        options.push({ value: 'shift', label: t('planning.kinds.shift') });
    }
    options.push(
        { value: 'absence', label: t('planning.kinds.absence') },
        { value: 'leave', label: t('planning.kinds.leave') },
    );
    return options;
});

const timeOffModeOptions = computed(() => ([
    { value: 'full', label: t('planning.form.full_day') },
    { value: 'hours', label: t('planning.form.hours') },
]));

const formTitle = computed(() => {
    if (form.kind === 'absence') return t('planning.form.title_absence');
    if (form.kind === 'leave') return t('planning.form.title_leave');
    return t('planning.form.title');
});

const dateLabel = computed(() =>
    isTimeOffKind.value ? t('planning.form.start_date') : t('planning.form.date')
);

const notesLabel = computed(() =>
    isTimeOffKind.value ? t('planning.form.reason') : t('planning.form.notes')
);

const submitLabel = computed(() => {
    if (formProcessing.value) {
        return isTimeOffKind.value ? t('planning.form.saving_time_off') : t('planning.form.creating');
    }
    return isTimeOffKind.value ? t('planning.form.save_time_off') : t('planning.form.create');
});

const deleteTitle = computed(() => {
    const kind = selectedShift.value?.kind;
    if (kind === 'absence') {
        return t('planning.delete.absence_title');
    }
    if (kind === 'leave') {
        return t('planning.delete.leave_title');
    }
    return t('planning.delete.title');
});

const deleteDescription = computed(() => {
    const kind = selectedShift.value?.kind;
    if (kind === 'absence') {
        return t('planning.delete.absence_description');
    }
    if (kind === 'leave') {
        return t('planning.delete.leave_description');
    }
    return t('planning.delete.description');
});

const getDeleteToastMessage = () => {
    const kind = selectedShift.value?.kind;
    if (kind === 'absence') {
        return t('planning.notices.absence_deleted');
    }
    if (kind === 'leave') {
        return t('planning.notices.leave_deleted');
    }
    return t('planning.notices.deleted');
};

const frequencyOptions = computed(() => ([
    { value: 'daily', label: t('planning.frequency.daily') },
    { value: 'weekly', label: t('planning.frequency.weekly') },
    { value: 'monthly', label: t('planning.frequency.monthly') },
    { value: 'yearly', label: t('planning.frequency.yearly') },
]));

const weekdayOptions = computed(() => ([
    { value: 'mo', label: t('planning.weekdays.mo') },
    { value: 'tu', label: t('planning.weekdays.tu') },
    { value: 'we', label: t('planning.weekdays.we') },
    { value: 'th', label: t('planning.weekdays.th') },
    { value: 'fr', label: t('planning.weekdays.fr') },
    { value: 'sa', label: t('planning.weekdays.sa') },
    { value: 'su', label: t('planning.weekdays.su') },
]));

const weekDayLabels = computed(() => ([
    t('planning.weekdays.mo'),
    t('planning.weekdays.tu'),
    t('planning.weekdays.we'),
    t('planning.weekdays.th'),
    t('planning.weekdays.fr'),
    t('planning.weekdays.sa'),
    t('planning.weekdays.su'),
]));

const palettePool = [
    {
        bg: 'bg-emerald-50',
        text: 'text-emerald-700',
        border: 'border-emerald-500',
        dot: 'bg-emerald-500',
        darkBg: 'dark:bg-emerald-500/10',
        darkText: 'dark:text-emerald-200',
        darkBorder: 'dark:border-emerald-400',
        darkDot: 'dark:bg-emerald-300',
    },
    {
        bg: 'bg-sky-50',
        text: 'text-sky-700',
        border: 'border-sky-500',
        dot: 'bg-sky-500',
        darkBg: 'dark:bg-sky-500/10',
        darkText: 'dark:text-sky-200',
        darkBorder: 'dark:border-sky-400',
        darkDot: 'dark:bg-sky-300',
    },
    {
        bg: 'bg-rose-50',
        text: 'text-rose-700',
        border: 'border-rose-500',
        dot: 'bg-rose-500',
        darkBg: 'dark:bg-rose-500/10',
        darkText: 'dark:text-rose-200',
        darkBorder: 'dark:border-rose-400',
        darkDot: 'dark:bg-rose-300',
    },
    {
        bg: 'bg-amber-50',
        text: 'text-amber-700',
        border: 'border-amber-500',
        dot: 'bg-amber-500',
        darkBg: 'dark:bg-amber-500/10',
        darkText: 'dark:text-amber-200',
        darkBorder: 'dark:border-amber-400',
        darkDot: 'dark:bg-amber-300',
    },
    {
        bg: 'bg-purple-50',
        text: 'text-purple-700',
        border: 'border-purple-500',
        dot: 'bg-purple-500',
        darkBg: 'dark:bg-purple-500/10',
        darkText: 'dark:text-purple-200',
        darkBorder: 'dark:border-purple-400',
        darkDot: 'dark:bg-purple-300',
    },
];

const allMemberIds = computed(() => (props.teamMembers || []).map((member) => member.id));
const memberFilters = ref([]);

const allMembersSelected = computed(() => {
    if (!allMemberIds.value.length) {
        return false;
    }
    return memberFilters.value.length === allMemberIds.value.length;
});

const memberPalette = computed(() => {
    const map = {};
    allMemberIds.value.forEach((id, index) => {
        map[id] = palettePool[index % palettePool.length];
    });
    return map;
});

const getPaletteForMember = (memberId) => memberPalette.value[memberId] || palettePool[0];

const getPaletteForEvent = (event) => {
    const memberId = event?.extendedProps?.team_member_id;
    return getPaletteForMember(memberId);
};

const getMemberDotClasses = (memberId) => {
    const palette = getPaletteForMember(memberId);
    return [palette.dot, palette.darkDot];
};

const getEventClasses = (event) => {
    const palette = getPaletteForEvent(event);
    return [
        'rounded-md border-l-4 px-2.5 py-1.5',
        palette.bg,
        palette.text,
        palette.border,
        palette.darkBg,
        palette.darkText,
        palette.darkBorder,
    ];
};

const selectedDateKey = computed(() =>
    selectedDate.value ? selectedDate.value.format('YYYY-MM-DD') : ''
);

const weekStartsOn = 1;
const getMonthGridStart = (value) => {
    const firstDay = value.startOf('month');
    const offset = (firstDay.day() - weekStartsOn + 7) % 7;
    return firstDay.subtract(offset, 'day');
};

const calendarGridStart = computed(() => getMonthGridStart(currentMonth.value));

const monthLabel = computed(() => currentMonth.value.format('MMMM YYYY'));

const getWeekStart = (date) => {
    const offset = (date.day() - weekStartsOn + 7) % 7;
    return date.subtract(offset, 'day');
};

const getRangeForView = (mode = viewMode.value) => {
    if (mode === 'month') {
        const start = getMonthGridStart(currentMonth.value);
        return { start, end: start.add(41, 'day') };
    }
    if (mode === 'week') {
        const start = getWeekStart(selectedDate.value);
        return { start, end: start.add(6, 'day') };
    }
    if (mode === 'day') {
        const start = selectedDate.value.startOf('day');
        return { start, end: selectedDate.value.endOf('day') };
    }
    const start = currentMonth.value.startOf('year');
    return { start, end: start.endOf('year') };
};

const rangeLabel = computed(() => {
    const range = getRangeForView(viewMode.value);
    if (!range?.start || !range?.end) {
        return '';
    }
    if (viewMode.value === 'day') {
        return range.start.format('MMM D, YYYY');
    }
    if (viewMode.value === 'year') {
        return range.start.format('YYYY');
    }
    return `${range.start.format('MMM D, YYYY')} - ${range.end.format('MMM D, YYYY')}`;
});

const mainTitle = computed(() => {
    if (viewMode.value === 'day') {
        return selectedDate.value.format('MMMM D, YYYY');
    }
    if (viewMode.value === 'week') {
        const range = getRangeForView('week');
        return `${range.start.format('MMM D')} - ${range.end.format('MMM D, YYYY')}`;
    }
    if (viewMode.value === 'year') {
        return currentMonth.value.format('YYYY');
    }
    return monthLabel.value;
});

const calendarDays = computed(() => {
    const start = calendarGridStart.value;
    return Array.from({ length: 42 }, (_, index) => {
        const date = start.add(index, 'day');
        return {
            key: date.format('YYYY-MM-DD'),
            date,
            label: date.date(),
            isCurrentMonth: date.month() === currentMonth.value.month(),
            isToday: date.isSame(todayDate, 'day'),
            isWeekend: [0, 6].includes(date.day()),
        };
    });
});

const monthViewDays = computed(() => {
    const days = calendarDays.value || [];
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
    const start = getWeekStart(selectedDate.value);
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

const weekStartHour = 6;
const weekEndHour = 20;
const dayStartHour = 0;
const dayEndHour = 24;
const hourHeight = 72;
const minuteHeight = hourHeight / 60;

const weekHours = computed(() =>
    Array.from({ length: weekEndHour - weekStartHour }, (_, index) => {
        const hour = weekStartHour + index;
        return {
            hour,
            label: dayjs().hour(hour).minute(0).format('hA'),
        };
    })
);

const dayHours = computed(() =>
    Array.from({ length: dayEndHour - dayStartHour }, (_, index) => {
        const hour = dayStartHour + index;
        return {
            hour,
            label: dayjs().hour(hour).minute(0).format('hA'),
        };
    })
);

const weekTimelineHeight = computed(
    () => (weekEndHour - weekStartHour) * 60 * minuteHeight
);
const dayTimelineHeight = computed(() => (dayEndHour - dayStartHour) * 60 * minuteHeight);

const visibleEvents = computed(() => {
    if (!memberFilters.value.length) {
        return allMemberIds.value.length ? [] : (calendarEvents.value || []);
    }

    return (calendarEvents.value || []).filter((event) => {
        const memberId = event?.extendedProps?.team_member_id;
        if (!memberId) {
            return true;
        }
        return memberFilters.value.includes(memberId);
    });
});

const pendingRequests = computed(() => {
    const pendingFromEvents = visibleEvents.value.filter((event) =>
        isTimeOffEvent(event) && event?.extendedProps?.status === 'pending'
    );
    const merged = new Map();
    [...pendingQueue.value, ...pendingFromEvents].forEach((event) => {
        if (!event?.id) {
            return;
        }
        merged.set(event.id, event);
    });
    let list = Array.from(merged.values());
    if (allMemberIds.value.length && !memberFilters.value.length) {
        return [];
    }
    if (memberFilters.value.length && memberFilters.value.length !== allMemberIds.value.length) {
        const allowed = new Set(memberFilters.value);
        list = list.filter((event) => {
            const memberId = event?.extendedProps?.team_member_id;
            if (!memberId) {
                return false;
            }
            return allowed.has(memberId);
        });
    }
    return list.sort((a, b) => String(a.start).localeCompare(String(b.start)));
});

const filterSummaryList = (list) => {
    if (!Array.isArray(list)) {
        return [];
    }
    if (allMemberIds.value.length && !memberFilters.value.length) {
        return [];
    }
    if (memberFilters.value.length && memberFilters.value.length !== allMemberIds.value.length) {
        const allowed = new Set(memberFilters.value);
        return list.filter((event) => {
            const memberId = event?.extendedProps?.team_member_id;
            if (!memberId) {
                return false;
            }
            return allowed.has(memberId);
        });
    }
    return list;
};

const todayTimeOff = computed(() => filterSummaryList(summaryQueue.value.today || []));
const weekTimeOff = computed(() => filterSummaryList(summaryQueue.value.week || []));

const eventsByDate = computed(() => {
    const map = {};
    visibleEvents.value.forEach((event) => {
        const start = dayjs(event.start);
        if (!start.isValid()) {
            return;
        }
        const key = start.format('YYYY-MM-DD');
        if (!map[key]) {
            map[key] = [];
        }
        map[key].push(event);
    });

    Object.values(map).forEach((list) => {
        list.sort((a, b) => String(a.start).localeCompare(String(b.start)));
    });

    return map;
});

const eventsByMonth = computed(() => {
    const map = {};
    visibleEvents.value.forEach((event) => {
        const start = dayjs(event.start);
        if (!start.isValid()) {
            return;
        }
        const key = start.format('YYYY-MM');
        map[key] = (map[key] || 0) + 1;
    });
    return map;
});

const eventsByMonthList = computed(() => {
    const map = {};
    visibleEvents.value.forEach((event) => {
        const start = dayjs(event.start);
        if (!start.isValid()) {
            return;
        }
        const key = start.format('YYYY-MM');
        if (!map[key]) {
            map[key] = [];
        }
        map[key].push(event);
    });
    Object.values(map).forEach((list) => {
        list.sort((a, b) => String(a.start).localeCompare(String(b.start)));
    });
    return map;
});

const getDayEvents = (dayKey) => eventsByDate.value[dayKey] || [];

const buildEventBlocks = (dayKey, rangeStartHour, rangeEndHour) => {
    if (!dayKey) {
        return [];
    }
    const events = getDayEvents(dayKey);
    if (!events.length) {
        return [];
    }

    const rangeStartMin = rangeStartHour * 60;
    const rangeEndMin = rangeEndHour * 60;
    const normalized = events
        .map((event) => {
            const start = dayjs(event.start);
            const end = dayjs(event.end);
            if (!start.isValid()) {
                return null;
            }
            const startMin = start.hour() * 60 + start.minute();
            let endMin = end.isValid() ? end.hour() * 60 + end.minute() : startMin + 60;
            if (endMin <= startMin) {
                endMin = startMin + 60;
            }
            const clippedStart = Math.max(startMin, rangeStartMin);
            const clippedEnd = Math.min(endMin, rangeEndMin);
            if (clippedEnd <= clippedStart) {
                return null;
            }
            return {
                event,
                startMin,
                endMin,
                clippedStart,
                clippedEnd,
            };
        })
        .filter(Boolean)
        .sort((a, b) => a.startMin - b.startMin);

    if (!normalized.length) {
        return [];
    }

    const clusters = [];
    let currentCluster = null;
    normalized.forEach((item) => {
        if (!currentCluster || item.startMin >= currentCluster.maxEnd) {
            currentCluster = {
                items: [item],
                maxEnd: item.endMin,
            };
            clusters.push(currentCluster);
            return;
        }
        currentCluster.items.push(item);
        currentCluster.maxEnd = Math.max(currentCluster.maxEnd, item.endMin);
    });

    const blocks = [];
    clusters.forEach((cluster) => {
        const lanes = [];
        cluster.items.forEach((item) => {
            let laneIndex = lanes.findIndex((laneEnd) => laneEnd <= item.startMin);
            if (laneIndex === -1) {
                laneIndex = lanes.length;
                lanes.push(item.endMin);
            } else {
                lanes[laneIndex] = item.endMin;
            }
            blocks.push({
                ...item,
                lane: laneIndex,
                laneCount: Math.max(lanes.length, 1),
            });
        });
    });

    return blocks.map((item) => {
        const top = (item.clippedStart - rangeStartMin) * minuteHeight;
        const height = Math.max((item.clippedEnd - item.clippedStart) * minuteHeight, 26);
        const width = 100 / (item.laneCount || 1);
        const left = item.lane * width;
        return {
            event: item.event,
            top,
            height,
            left,
            width,
        };
    });
};

const weekEventBlocks = computed(() => {
    const map = {};
    weekDays.value.forEach((day) => {
        map[day.key] = buildEventBlocks(day.key, weekStartHour, weekEndHour);
    });
    return map;
});

const dayEventBlocks = computed(() =>
    buildEventBlocks(selectedDate.value?.format('YYYY-MM-DD'), dayStartHour, dayEndHour)
);

const getMonthPreviewEvents = (monthKey) => {
    const list = eventsByMonthList.value[monthKey] || [];
    return list.slice(0, 2);
};

const getDayIndicatorClasses = (dayKey) => {
    const dayEvents = getDayEvents(dayKey);
    if (!dayEvents.length) {
        return [];
    }
    const palette = getPaletteForEvent(dayEvents[0]);
    return [palette.dot, palette.darkDot];
};

const formatTimeLabel = (time) => (time.minute() === 0 ? time.format('hA') : time.format('h:mmA'));

const isTimeOffEvent = (event) => ['absence', 'leave'].includes(event?.extendedProps?.kind);

const getEventTitle = (event) => {
    const memberName = event?.extendedProps?.member_name || '';
    const kind = event?.extendedProps?.kind;
    if (kind === 'absence') {
        const prefix = memberName ? `${memberName} · ` : '';
        return `${prefix}${t('planning.kinds.absence')}`;
    }
    if (kind === 'leave') {
        const prefix = memberName ? `${memberName} · ` : '';
        return `${prefix}${t('planning.kinds.leave')}`;
    }
    return event?.title || '';
};

const formatEventTime = (event) => {
    if (isTimeOffEvent(event) && event?.allDay) {
        const allDayLabel = t('planning.all_day');
        if (event?.extendedProps?.status === 'pending') {
            return `${allDayLabel} · ${t('planning.status.pending')}`;
        }
        return allDayLabel;
    }
    const start = dayjs(event.start);
    const end = dayjs(event.end);
    if (!start.isValid() || !end.isValid()) {
        return '';
    }
    const base = `${formatTimeLabel(start)} - ${formatTimeLabel(end)}`;
    if (isTimeOffEvent(event) && event?.extendedProps?.status === 'pending') {
        return `${base} · ${t('planning.status.pending')}`;
    }
    return base;
};

const canDragEvent = (event) => {
    const kind = event?.extendedProps?.kind;
    if (kind === 'shift') {
        return props.canManage;
    }
    if (['absence', 'leave'].includes(kind)) {
        return Boolean(event?.extendedProps?.can_approve || event?.extendedProps?.can_delete);
    }
    return false;
};

const getEventDurationMinutes = (event) => {
    const start = dayjs(event?.start);
    const end = dayjs(event?.end);
    if (!start.isValid() || !end.isValid()) {
        return 60;
    }
    const diff = end.diff(start, 'minute');
    return diff > 0 ? diff : 60;
};

const onDragStart = (event, domEvent) => {
    if (!canDragEvent(event)) {
        domEvent.preventDefault();
        return;
    }
    const duration = getEventDurationMinutes(event);
    dragState.value = {
        event,
        duration,
    };
    if (domEvent?.dataTransfer) {
        domEvent.dataTransfer.effectAllowed = 'move';
        domEvent.dataTransfer.setData('text/plain', String(event.id || 'shift'));
    }
};

const onDragEnd = () => {
    dragState.value = null;
};

const clampToDay = (start) => {
    let newStart = start;
    let newEnd = null;
    if (dragState.value?.duration) {
        newEnd = newStart.add(dragState.value.duration, 'minute');
    }

    const endOfDay = start.endOf('day').minute(59).second(0);
    if (newEnd && newEnd.isAfter(endOfDay)) {
        newEnd = endOfDay;
    }
    if (newEnd && !newEnd.isAfter(newStart)) {
        newEnd = newStart.add(30, 'minute');
    }
    if (newEnd && newEnd.isAfter(endOfDay)) {
        newEnd = endOfDay;
    }

    return { start: newStart, end: newEnd };
};

const updateShiftFromDrag = async (event, start, end) => {
    if (!event?.id || updateProcessing.value) {
        return;
    }
    if (!start?.isValid() || !end?.isValid()) {
        return;
    }

    updateProcessing.value = true;
    try {
        await axios.patch(route('planning.shifts.update', event.id), {
            shift_date: start.format('YYYY-MM-DD'),
            start_time: start.format('HH:mm'),
            end_time: end.format('HH:mm'),
        });
        pushToast('success', t('planning.notices.updated'));
        await fetchEvents(currentRange.value.start, currentRange.value.end);
    } catch (error) {
        const status = error?.response?.status;
        if (status === 409) {
            pushToast('error', error?.response?.data?.message || t('planning.errors.conflict'));
        } else {
            const message = extractErrorMessage(error) || t('planning.errors.update');
            pushToast('error', message);
        }
    } finally {
        updateProcessing.value = false;
    }
};

const handleMonthDrop = async (dayKey, domEvent) => {
    domEvent.preventDefault();
    const dragging = resolveDraggedEvent(domEvent);
    if (!dragging || !canDragEvent(dragging)) {
        return;
    }
    const start = dayjs(dragging.start);
    if (!start.isValid()) {
        return;
    }
    const newStart = dayjs(dayKey)
        .hour(start.hour())
        .minute(start.minute())
        .second(0);
    const { start: clampedStart, end } = clampToDay(newStart);
    await updateShiftFromDrag(dragging, clampedStart, end);
};

const resolveTimeFromDrop = (dayKey, domEvent, baseHour, totalHours) => {
    const rect = domEvent.currentTarget?.getBoundingClientRect();
    if (!rect) {
        return null;
    }
    const offsetY = domEvent.clientY - rect.top;
    const totalMinutes = Math.max(0, Math.min(offsetY / minuteHeight, totalHours * 60));
    const roundedMinutes = Math.round(totalMinutes / 15) * 15;
    return dayjs(dayKey)
        .hour(baseHour)
        .minute(0)
        .second(0)
        .add(roundedMinutes, 'minute');
};

const handleTimelineDrop = async (dayKey, domEvent, mode) => {
    domEvent.preventDefault();
    const dragging = resolveDraggedEvent(domEvent);
    if (!dragging || !canDragEvent(dragging)) {
        return;
    }
    const baseHour = mode === 'week' ? weekStartHour : dayStartHour;
    const totalHours = mode === 'week'
        ? (weekEndHour - weekStartHour)
        : (dayEndHour - dayStartHour);
    const proposedStart = resolveTimeFromDrop(dayKey, domEvent, baseHour, totalHours);
    if (!proposedStart) {
        return;
    }
    const { start, end } = clampToDay(proposedStart);
    await updateShiftFromDrag(dragging, start, end);
};

const isCurrentHour = (dayKey, hour) => {
    const now = dayjs();
    return now.format('YYYY-MM-DD') === dayKey && now.hour() === hour;
};

const yearMonths = computed(() => {
    const start = currentMonth.value.startOf('year');
    return Array.from({ length: 12 }, (_, index) => start.add(index, 'month'));
});

const pushToast = (type, message) => {
    if (typeof window === 'undefined') {
        return;
    }
    window.dispatchEvent(new CustomEvent('mlk-toast', { detail: { type, message } }));
};

const resolveDraggedEvent = (domEvent) => {
    if (dragState.value?.event) {
        return dragState.value.event;
    }
    const dragId = domEvent?.dataTransfer?.getData('text/plain');
    if (!dragId) {
        return null;
    }
    const candidates = visibleEvents.value?.length ? visibleEvents.value : (calendarEvents.value || []);
    return candidates.find((event) => String(event.id) === String(dragId)) || null;
};

const extractErrorMessage = (error) => {
    const data = error?.response?.data || {};
    if (typeof data.message === 'string' && data.message.trim()) {
        return data.message;
    }
    const errors = data.errors || {};
    const key = Object.keys(errors)[0];
    if (!key) {
        return '';
    }
    const value = errors[key];
    if (Array.isArray(value)) {
        return value[0] || '';
    }
    return value || '';
};

const normalizeErrors = (error) => {
    const bag = error?.response?.data?.errors || {};
    const normalized = {};
    Object.entries(bag).forEach(([key, messages]) => {
        if (Array.isArray(messages)) {
            normalized[key] = messages[0] || '';
        } else {
            normalized[key] = messages;
        }
    });
    return normalized;
};

const syncDefaultsFromDate = (value) => {
    if (!value) {
        return;
    }
    const date = dayjs(value);
    if (!date.isValid()) {
        return;
    }

    monthlyDay.value = String(date.date());
    if (!recurrenceWeekdays.value.length) {
        recurrenceWeekdays.value = [weekdayValues[date.day()]];
    }

    if (isTimeOffKind.value) {
        if (timeOffMode.value === 'hours') {
            form.end_date = date.format('YYYY-MM-DD');
        } else {
            const endDate = dayjs(form.end_date);
            if (!endDate.isValid() || endDate.isBefore(date, 'day')) {
                form.end_date = date.format('YYYY-MM-DD');
            }
        }
    }
};

const toggleWeekday = (day) => {
    const index = recurrenceWeekdays.value.indexOf(day);
    if (index >= 0) {
        recurrenceWeekdays.value.splice(index, 1);
        return;
    }
    recurrenceWeekdays.value.push(day);
};

const buildRepeatsOn = () => {
    if (!form.is_recurring) {
        return [];
    }

    if (form.frequency === 'weekly') {
        if (recurrenceWeekdays.value.length) {
            return [...recurrenceWeekdays.value];
        }
        const date = dayjs(form.shift_date);
        if (date.isValid()) {
            return [weekdayValues[date.day()]];
        }
        return [];
    }

    if (form.frequency === 'monthly') {
        const rawDay = Number.parseInt(monthlyDay.value, 10);
        const fallbackDay = dayjs(form.shift_date).isValid()
            ? dayjs(form.shift_date).date()
            : dayjs().date();
        const dayValue = Number.isFinite(rawDay) ? rawDay : fallbackDay;
        const normalized = Math.min(31, Math.max(1, dayValue));
        return [String(normalized)];
    }

    return [];
};

const fetchEvents = async (start, end) => {
    loadingEvents.value = true;
    loadError.value = '';

    try {
        if (allMemberIds.value.length && !memberFilters.value.length) {
            calendarEvents.value = [];
            return;
        }

        const params = {
            start,
            end,
        };

        if (memberFilters.value.length && memberFilters.value.length !== allMemberIds.value.length) {
            params.team_member_ids = [...memberFilters.value];
        }

        const response = await axios.get(route('planning.events'), {
            params,
        });
        calendarEvents.value = response?.data?.events || [];
        if (Array.isArray(response?.data?.pending_requests)) {
            pendingQueue.value = response.data.pending_requests;
        }
        if (response?.data?.time_off_summary) {
            summaryQueue.value = {
                today: response.data.time_off_summary.today || [],
                week: response.data.time_off_summary.week || [],
            };
        }
    } catch (error) {
        loadError.value = error?.response?.data?.message || t('planning.errors.load');
    } finally {
        loadingEvents.value = false;
    }
};

const pollEvents = () => {
    if (document.visibilityState !== 'visible' || loadingEvents.value) {
        return;
    }
    if (allMemberIds.value.length && !memberFilters.value.length) {
        return;
    }
    fetchEvents(currentRange.value.start, currentRange.value.end);
};

const startPolling = () => {
    if (pollTimer) {
        return;
    }
    pollTimer = window.setInterval(pollEvents, pollIntervalMs);
};

const stopPolling = () => {
    if (!pollTimer) {
        return;
    }
    window.clearInterval(pollTimer);
    pollTimer = null;
};

const handleVisibilityChange = () => {
    if (document.visibilityState === 'visible') {
        startPolling();
        pollEvents();
        return;
    }
    stopPolling();
};

const openShiftDetails = (event) => {
    if (!event?.extendedProps?.can_delete && !event?.extendedProps?.can_approve) {
        return;
    }

    const start = dayjs(event.start);
    const end = dayjs(event.end);
    selectedShift.value = {
        id: event.id,
        title: getEventTitle(event),
        member: event.extendedProps?.member_name || '',
        date: start.isValid() ? start.format('YYYY-MM-DD') : '',
        time: formatEventTime(event),
        kind: event.extendedProps?.kind || 'shift',
        status: event.extendedProps?.status || 'approved',
        canApprove: Boolean(event.extendedProps?.can_approve),
    };
    deleteError.value = '';
    statusError.value = '';

    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-planning-delete');
    }
};

const submitShift = async () => {
    if (!canSubmit.value || formProcessing.value) {
        return;
    }

    formProcessing.value = true;
    formErrors.value = {};

    const payload = {
        kind: form.kind,
        team_member_id: form.team_member_id,
        shift_date: form.shift_date,
        title: form.title || null,
        notes: form.notes || null,
    };

    if (!props.canManage && props.selfTeamMemberId) {
        payload.team_member_id = props.selfTeamMemberId;
    }

    if (isTimeOffKind.value) {
        if (timeOffMode.value === 'hours') {
            payload.start_time = form.start_time;
            payload.end_time = form.end_time;
            payload.end_date = form.shift_date;
        } else {
            payload.end_date = form.end_date || form.shift_date;
        }
    } else {
        payload.start_time = form.start_time;
        payload.end_time = form.end_time;
        payload.break_minutes = form.break_minutes;
        payload.is_recurring = form.is_recurring;

        if (form.is_recurring) {
            payload.frequency = form.frequency;
            payload.recurrence_end_date = form.recurrence_end_date;
            payload.repeats_on = buildRepeatsOn();
        }
    }

    try {
        const response = await axios.post(route('planning.shifts.store'), payload);
        const createdCount = response?.data?.created
            ?? response?.data?.events?.length
            ?? 1;

        if (isTimeOffKind.value) {
            let message = '';
            if (form.kind === 'absence') {
                message = createdCount > 1
                    ? t('planning.notices.absence_created_many', { count: createdCount })
                    : t('planning.notices.absence_created');
            } else {
                message = createdCount > 1
                    ? t('planning.notices.leave_created_many', { count: createdCount })
                    : t('planning.notices.leave_created');
            }
            pushToast('success', message);
        } else {
            pushToast('success', t('planning.notices.created', { count: createdCount }));
        }
        form.title = '';
        form.notes = '';
        if (isTimeOffKind.value) {
            form.end_date = form.shift_date;
        }
        await fetchEvents(currentRange.value.start, currentRange.value.end);
    } catch (error) {
        formErrors.value = normalizeErrors(error);
        pushToast('error', error?.response?.data?.message || t('planning.errors.save'));
    } finally {
        formProcessing.value = false;
    }
};

const deleteShift = async () => {
    if (!selectedShift.value?.id || deleteProcessing.value) {
        return;
    }

    deleteProcessing.value = true;
    deleteError.value = '';

    try {
        await axios.delete(route('planning.shifts.destroy', selectedShift.value.id));
        if (window.HSOverlay) {
            window.HSOverlay.close('#hs-planning-delete');
        }
        pushToast('success', getDeleteToastMessage());
        await fetchEvents(currentRange.value.start, currentRange.value.end);
        selectedShift.value = null;
    } catch (error) {
        deleteError.value = error?.response?.data?.message || t('planning.errors.delete');
    } finally {
        deleteProcessing.value = false;
    }
};

const updateShiftStatus = async (status, target = selectedShift.value) => {
    const shiftId = target?.id;
    const canApprove = target?.canApprove ?? target?.extendedProps?.can_approve;
    if (!shiftId || statusProcessing.value) {
        return;
    }
    if (!canApprove) {
        return;
    }

    statusProcessing.value = true;
    statusError.value = '';

    try {
        await axios.patch(route('planning.shifts.status', shiftId), { status });
        if (window.HSOverlay) {
            window.HSOverlay.close('#hs-planning-delete');
        }
        if (status === 'approved') {
            pushToast('success', t('planning.notices.approved'));
        } else {
            pushToast('success', t('planning.notices.rejected'));
        }
        await fetchEvents(currentRange.value.start, currentRange.value.end);
        selectedShift.value = null;
    } catch (error) {
        statusError.value = error?.response?.data?.message || t('planning.errors.status');
    } finally {
        statusProcessing.value = false;
    }
};

const syncRangeFromView = (mode = viewMode.value, shouldFetch = false) => {
    const range = getRangeForView(mode);
    currentRange.value = {
        start: range.start.format('YYYY-MM-DD'),
        end: range.end.format('YYYY-MM-DD'),
    };

    if (shouldFetch) {
        fetchEvents(currentRange.value.start, currentRange.value.end);
    }
};

const setMonth = (month, shouldFetch = true, keepDay = true) => {
    currentMonth.value = month;
    if (keepDay) {
        const day = selectedDate.value ? selectedDate.value.date() : 1;
        selectedDate.value = month.date(Math.min(day, month.daysInMonth()));
    }
    if (viewMode.value === 'month' || viewMode.value === 'year') {
        syncRangeFromView(viewMode.value, shouldFetch);
    }
};

const goPrevMonth = () => {
    setMonth(currentMonth.value.subtract(1, 'month'), viewMode.value === 'month');
    if (viewMode.value === 'day' || viewMode.value === 'week') {
        syncRangeFromView(viewMode.value, true);
    }
};

const goNextMonth = () => {
    setMonth(currentMonth.value.add(1, 'month'), viewMode.value === 'month');
    if (viewMode.value === 'day' || viewMode.value === 'week') {
        syncRangeFromView(viewMode.value, true);
    }
};

const goPrev = () => {
    if (viewMode.value === 'day') {
        selectedDate.value = selectedDate.value.subtract(1, 'day');
        currentMonth.value = selectedDate.value;
        syncRangeFromView('day', true);
        return;
    }
    if (viewMode.value === 'week') {
        selectedDate.value = selectedDate.value.subtract(1, 'week');
        currentMonth.value = selectedDate.value;
        syncRangeFromView('week', true);
        return;
    }
    if (viewMode.value === 'year') {
        currentMonth.value = currentMonth.value.subtract(1, 'year');
        syncRangeFromView('year', true);
        return;
    }
    goPrevMonth();
};

const goNext = () => {
    if (viewMode.value === 'day') {
        selectedDate.value = selectedDate.value.add(1, 'day');
        currentMonth.value = selectedDate.value;
        syncRangeFromView('day', true);
        return;
    }
    if (viewMode.value === 'week') {
        selectedDate.value = selectedDate.value.add(1, 'week');
        currentMonth.value = selectedDate.value;
        syncRangeFromView('week', true);
        return;
    }
    if (viewMode.value === 'year') {
        currentMonth.value = currentMonth.value.add(1, 'year');
        syncRangeFromView('year', true);
        return;
    }
    goNextMonth();
};

const goToday = () => {
    const today = dayjs();
    selectedDate.value = today;
    currentMonth.value = today;
    syncRangeFromView(viewMode.value, true);
};

const selectDate = (date) => {
    selectedDate.value = date;
    if (!date.isSame(currentMonth.value, 'month')) {
        setMonth(date, viewMode.value === 'month', false);
    }
    if (viewMode.value === 'day' || viewMode.value === 'week') {
        syncRangeFromView(viewMode.value, true);
    }
};

const openDayView = (date) => {
    if (!date) {
        return;
    }
    selectedDate.value = date;
    setViewMode('day');
    setShiftDate(date.format('YYYY-MM-DD'));
};

const setShiftDate = (dateKey) => {
    form.shift_date = dateKey;
    selectedDate.value = dayjs(dateKey);
    if (viewMode.value === 'day' || viewMode.value === 'week') {
        syncRangeFromView(viewMode.value, true);
    }
};

const setViewMode = (mode) => {
    if (viewMode.value === mode) {
        return;
    }
    viewMode.value = mode;
    syncRangeFromView(mode, true);
};

const scrollToForm = () => {
    const target = document.getElementById('planning-shift-form');
    if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
};

const toggleAllMembers = () => {
    if (allMembersSelected.value) {
        memberFilters.value = [];
        return;
    }
    memberFilters.value = [...allMemberIds.value];
};

const toggleMemberFilter = (memberId) => {
    const index = memberFilters.value.indexOf(memberId);
    if (index >= 0) {
        memberFilters.value.splice(index, 1);
        return;
    }
    memberFilters.value.push(memberId);
};

watch(
    () => props.teamMembers,
    (members) => {
        if (!props.canManage && props.selfTeamMemberId) {
            form.team_member_id = props.selfTeamMemberId;
        } else if (!form.team_member_id && members?.length) {
            form.team_member_id = members[0].id;
        }
        memberFilters.value = (members || []).map((member) => member.id);
    },
    { immediate: true }
);

watch(
    () => props.pendingRequests,
    (requests) => {
        pendingQueue.value = Array.isArray(requests) ? [...requests] : [];
    },
    { immediate: true }
);

watch(
    () => props.timeOffSummary,
    (summary) => {
        summaryQueue.value = {
            today: summary?.today || [],
            week: summary?.week || [],
        };
    },
    { immediate: true }
);

watch(
    () => memberFilters.value,
    () => {
        syncRangeFromView(viewMode.value, true);
    }
);

watch(
    () => form.shift_date,
    (value) => {
        syncDefaultsFromDate(value);
        if (!manualTimeOverride.value) {
            applyTemplateForSelection();
        }
    },
    { immediate: true }
);

watch(
    () => form.team_member_id,
    () => {
        manualTimeOverride.value = false;
        applyTemplateForSelection();
    }
);

watch(
    () => [form.start_time, form.end_time, form.break_minutes],
    () => {
        if (autoApplying.value) {
            return;
        }
        manualTimeOverride.value = true;
    }
);

watch(
    () => form.kind,
    (value, previous) => {
        if (!props.canManage && value === 'shift') {
            form.kind = 'absence';
            return;
        }
        if (isTimeOffKind.value) {
            form.is_recurring = false;
            if (!previous || previous === 'shift') {
                timeOffMode.value = 'full';
            }
            if (timeOffMode.value === 'hours') {
                form.end_date = form.shift_date;
            } else if (!form.end_date) {
                form.end_date = form.shift_date;
            }
            return;
        }
        if (value === 'shift' && previous !== 'shift') {
            manualTimeOverride.value = false;
            applyTemplateForSelection();
        }
    }
);

watch(
    () => timeOffMode.value,
    (value) => {
        if (!isTimeOffKind.value) {
            return;
        }
        if (value === 'hours') {
            form.end_date = form.shift_date;
            return;
        }
        const startDate = dayjs(form.shift_date);
        const endDate = dayjs(form.end_date);
        if (!endDate.isValid() || (startDate.isValid() && endDate.isBefore(startDate, 'day'))) {
            form.end_date = form.shift_date;
        }
    }
);

syncRangeFromView(viewMode.value, false);

onMounted(() => {
    if (document.visibilityState === 'visible') {
        startPolling();
    }
    document.addEventListener('visibilitychange', handleVisibilityChange);
});

onBeforeUnmount(() => {
    stopPolling();
    document.removeEventListener('visibilitychange', handleVisibilityChange);
});

</script>

<template>
    <AuthenticatedLayout>
        <Head :title="t('planning.title')" />

        <div class="space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ t('planning.title') }}
                    </h1>
                    <p class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ subtitleLabel }}
                    </p>
                </div>
            </div>

            <div class="grid gap-4 xl:grid-cols-[260px_minmax(0,1fr)_320px]">
                <aside class="space-y-4">
                    <div
                        v-if="props.canApproveTimeOff"
                        class="rounded-xl border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                                {{ t('planning.absence_overview.title') }}
                            </span>
                            <span class="text-[10px] text-stone-400 dark:text-neutral-500">
                                {{ t('planning.absence_overview.week_range') }}
                            </span>
                        </div>

                        <div class="mt-3 space-y-3">
                            <div>
                                <div class="text-[11px] font-semibold uppercase text-stone-500 dark:text-neutral-400">
                                    {{ t('planning.absence_overview.today') }}
                                </div>
                                <div v-if="!todayTimeOff.length" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ t('planning.absence_overview.empty_today') }}
                                </div>
                                <div v-else class="mt-2 space-y-2">
                                    <div
                                        v-for="event in todayTimeOff"
                                        :key="`today-${event.id}`"
                                        class="rounded-md border border-stone-200 bg-stone-50 p-2 text-xs text-stone-700 dark:border-neutral-800 dark:bg-neutral-800 dark:text-neutral-200"
                                    >
                                        <div class="font-semibold">{{ getEventTitle(event) }}</div>
                                        <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                                            {{ formatEventTime(event) }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div class="text-[11px] font-semibold uppercase text-stone-500 dark:text-neutral-400">
                                    {{ t('planning.absence_overview.week') }}
                                </div>
                                <div v-if="!weekTimeOff.length" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ t('planning.absence_overview.empty_week') }}
                                </div>
                                <div v-else class="mt-2 space-y-2">
                                    <div
                                        v-for="event in weekTimeOff.slice(0, 6)"
                                        :key="`week-${event.id}`"
                                        class="rounded-md border border-stone-200 bg-white p-2 text-xs text-stone-700 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-200"
                                    >
                                        <div class="font-semibold">{{ getEventTitle(event) }}</div>
                                        <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                                            {{ dayjs(event.start).format('YYYY-MM-DD') }} · {{ formatEventTime(event) }}
                                        </div>
                                    </div>
                                </div>
                                <div v-if="weekTimeOff.length > 6" class="mt-2 text-[11px] text-stone-500 dark:text-neutral-400">
                                    {{ t('planning.absence_overview.more_week', { count: weekTimeOff.length - 6 }) }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                                {{ monthLabel }}
                            </span>
                            <div class="flex items-center gap-1">
                                <button type="button" class="flex h-7 w-7 items-center justify-center rounded-full text-stone-500 hover:bg-stone-100 dark:text-neutral-400 dark:hover:bg-neutral-800" @click="goPrevMonth">
                                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
                                </button>
                                <button type="button" class="flex h-7 w-7 items-center justify-center rounded-full text-stone-500 hover:bg-stone-100 dark:text-neutral-400 dark:hover:bg-neutral-800" @click="goNextMonth">
                                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6" /></svg>
                                </button>
                            </div>
                        </div>

                        <div class="mt-2 grid grid-cols-7 text-[10px] uppercase text-stone-400">
                            <span v-for="label in weekDayLabels" :key="label" class="py-1 text-center">
                                {{ label }}
                            </span>
                        </div>

                        <div class="mt-1 grid grid-cols-7 gap-1">
                            <button
                                v-for="day in calendarDays"
                                :key="`mini-${day.key}`"
                                type="button"
                                class="group relative flex h-7 items-center justify-center rounded-full text-[11px] transition"
                                :class="[
                                    day.isCurrentMonth ? 'text-stone-700 dark:text-neutral-200' : 'text-stone-300 dark:text-neutral-600',
                                    day.key === selectedDateKey
                                        ? 'bg-stone-900 text-white dark:bg-neutral-100 dark:text-neutral-900'
                                        : '',
                                    day.isToday && day.key !== selectedDateKey
                                        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                        : '',
                                ]"
                                @click="selectDate(day.date); setShiftDate(day.key)"
                            >
                                <span>{{ day.label }}</span>
                                <span
                                    v-if="eventsByDate[day.key]?.length"
                                    class="absolute bottom-0.5 h-1 w-1 rounded-full"
                                    :class="getDayIndicatorClasses(day.key)"
                                ></span>
                            </button>
                        </div>
                    </div>

                    <div
                        v-if="props.canApproveTimeOff"
                        class="rounded-xl border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                                {{ t('planning.pending.title') }}
                            </span>
                            <span v-if="pendingRequests.length" class="text-[10px] font-semibold text-amber-600 dark:text-amber-300">
                                {{ t('planning.pending.count', { count: pendingRequests.length }) }}
                            </span>
                        </div>

                        <div v-if="!pendingRequests.length" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('planning.pending.empty') }}
                        </div>
                        <div v-else class="mt-2 space-y-2">
                            <div
                                v-for="request in pendingRequests.slice(0, 5)"
                                :key="request.id"
                                class="rounded-md border border-stone-200 bg-stone-50 p-2 text-xs text-stone-700 dark:border-neutral-800 dark:bg-neutral-800 dark:text-neutral-200"
                            >
                                <div class="font-semibold">
                                    {{ getEventTitle(request) }}
                                </div>
                                <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                                    {{ dayjs(request.start).format('YYYY-MM-DD') }} · {{ formatEventTime(request) }}
                                </div>
                                <div v-if="request.extendedProps?.can_approve" class="mt-2 flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="rounded-sm bg-emerald-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                                        :disabled="statusProcessing"
                                        @click="updateShiftStatus('approved', request)"
                                    >
                                        {{ t('planning.status.approve') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-sm bg-amber-500 px-2 py-1 text-[11px] font-semibold text-white hover:bg-amber-600 disabled:opacity-50"
                                        :disabled="statusProcessing"
                                        @click="updateShiftStatus('rejected', request)"
                                    >
                                        {{ t('planning.status.reject') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div v-if="pendingRequests.length > 5" class="mt-2 text-[11px] text-stone-500 dark:text-neutral-400">
                            {{ t('planning.pending.more', { count: pendingRequests.length - 5 }) }}
                        </div>
                    </div>

                    <div class="rounded-xl border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div class="text-xs font-semibold text-stone-600 dark:text-neutral-300">
                            Calendars
                        </div>
                        <div class="mt-2 space-y-2">
                            <label class="flex items-center gap-2 text-xs text-stone-700 dark:text-neutral-200">
                                <input
                                    type="checkbox"
                                    class="size-3.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500 dark:border-neutral-700"
                                    :checked="allMembersSelected"
                                    @change="toggleAllMembers"
                                />
                                {{ t('planning.filters.all_members') }}
                            </label>

                            <div class="space-y-1">
                                <label
                                    v-for="member in props.teamMembers"
                                    :key="member.id"
                                    class="flex items-center gap-2 text-xs text-stone-700 dark:text-neutral-200"
                                >
                                    <input
                                        type="checkbox"
                                        class="size-3.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500 dark:border-neutral-700"
                                        :checked="memberFilters.includes(member.id)"
                                        @change="toggleMemberFilter(member.id)"
                                    />
                                    <span class="flex items-center gap-2">
                                        <span class="h-2 w-2 rounded-full" :class="getMemberDotClasses(member.id)"></span>
                                        {{ member.name }}
                                    </span>
                                </label>
                            </div>
                        </div>

                        <button
                            v-if="props.canManage"
                            type="button"
                            class="mt-4 w-full rounded-md border border-stone-200 bg-stone-50 px-3 py-2 text-xs font-semibold text-stone-700 shadow-sm hover:bg-stone-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                        >
                            Invite people
                        </button>
                    </div>
                </aside>

                <section class="rounded-xl border border-stone-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900 overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3 dark:border-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('planning.filters.range') }}: {{ rangeLabel || '--' }}
                            <span v-if="loadingEvents" class="ms-2 text-stone-400">
                                ({{ loadingLabel }})
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
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
                            <button
                                type="button"
                                class="flex h-8 w-8 items-center justify-center rounded-md bg-emerald-600 text-white shadow-sm transition hover:bg-emerald-700"
                                @click="scrollToForm"
                                aria-label="Add shift"
                            >
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14" /><path d="M12 5v14" /></svg>
                            </button>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ mainTitle }}
                        </h2>
                        <div class="flex items-center gap-1">
                            <button type="button" class="flex h-8 w-8 items-center justify-center rounded-md border border-stone-200 text-stone-500 hover:bg-stone-50 dark:border-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-800" @click="goPrev">
                                <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
                            </button>
                            <button type="button" class="rounded-md border border-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-800" @click="goToday">
                                {{ t('planning.calendar.today') }}
                            </button>
                            <button type="button" class="flex h-8 w-8 items-center justify-center rounded-md border border-stone-200 text-stone-500 hover:bg-stone-50 dark:border-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-800" @click="goNext">
                                <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6" /></svg>
                            </button>
                        </div>
                    </div>

                    <div class="border-t border-stone-200 dark:border-neutral-800">
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
                                    @dragover.prevent
                                    @drop="handleMonthDrop(day.key, $event)"
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
                                        @click="setShiftDate(day.key)"
                                    >
                                        <span v-if="day.label === 1" class="me-1 hidden sm:inline text-[10px] font-semibold text-stone-500 dark:text-neutral-400">
                                            {{ day.date.format('MMMM') }}
                                        </span>
                                        {{ day.label }}
                                    </button>

                                    <div class="mt-8 px-2 pb-2 space-y-1">
                                        <button
                                            v-for="event in getDayEvents(day.key).slice(0, 2)"
                                            :key="event.id"
                                            type="button"
                                            class="w-full text-left text-[11px] leading-snug"
                                            :class="[getEventClasses(event), canDragEvent(event) ? 'cursor-move' : '']"
                                            :draggable="canDragEvent(event)"
                                            @dragstart="onDragStart(event, $event)"
                                            @dragend="onDragEnd"
                                            @click="openShiftDetails(event)"
                                        >
                                            <span class="block truncate font-semibold">{{ getEventTitle(event) }}</span>
                                            <span class="block truncate">{{ formatEventTime(event) }}</span>
                                        </button>

                                        <button
                                            v-if="getDayEvents(day.key).length > 2"
                                            type="button"
                                            class="text-[11px] text-stone-500 hover:text-stone-700 dark:text-neutral-400"
                                            @click="openDayView(day.date)"
                                        >
                                            {{ getDayEvents(day.key).length - 2 }} more
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-else-if="viewMode === 'week'" class="p-4">
                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-7">
                                <div
                                    v-for="day in weekDays"
                                    :key="`week-list-${day.key}`"
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
                                            @click="setShiftDate(day.key)"
                                        >
                                            {{ day.label }}
                                        </button>
                                    </div>
                                    <div
                                        class="flex-1 max-h-[70vh] space-y-2 overflow-y-auto px-3 pb-3 pt-2"
                                        :class="day.isWeekend ? 'bg-stone-50/70 dark:bg-neutral-900/40' : ''"
                                        @dragover.prevent
                                        @drop="handleMonthDrop(day.key, $event)"
                                    >
                                        <p v-if="!getDayEvents(day.key).length" class="text-xs text-stone-400 dark:text-neutral-500">
                                            {{ emptyLabel }}
                                        </p>
                                        <button
                                            v-for="event in getDayEvents(day.key)"
                                            :key="event.id"
                                            type="button"
                                            class="w-full text-left text-[12px] leading-snug shadow-sm ring-1 ring-black/5 transition hover:shadow-md"
                                            :class="[getEventClasses(event), canDragEvent(event) ? 'cursor-move' : '']"
                                            :draggable="canDragEvent(event)"
                                            @dragstart="onDragStart(event, $event)"
                                            @dragend="onDragEnd"
                                            @click="openShiftDetails(event)"
                                        >
                                            <div class="flex items-start justify-between gap-2">
                                                <span class="truncate font-semibold">{{ getEventTitle(event) }}</span>
                                            </div>
                                            <div class="mt-1 text-[11px] font-medium text-stone-600 dark:text-neutral-300">
                                                {{ formatEventTime(event) }}
                                            </div>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-else-if="viewMode === 'day'" class="p-4">
                            <div class="flex items-center justify-between">
                                <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ selectedDate.format('dddd, MMMM D, YYYY') }}
                                </div>
                                <button
                                    type="button"
                                    class="rounded-md border border-stone-200 px-2 py-1 text-xs text-stone-500 hover:bg-stone-50 dark:border-neutral-800 dark:text-neutral-300"
                                    @click="setShiftDate(selectedDate.format('YYYY-MM-DD'))"
                                >
                                    Set shift date
                                </button>
                            </div>
                            <div
                                class="mt-4 rounded-xl border border-stone-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                                @dragover.prevent
                                @drop="handleMonthDrop(selectedDate.format('YYYY-MM-DD'), $event)"
                            >
                                <div class="flex items-center justify-between border-b border-stone-200 px-4 py-3 dark:border-neutral-800">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ t('planning.calendar.day') }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ getDayEvents(selectedDate.format('YYYY-MM-DD')).length }}
                                    </div>
                                </div>
                                <div class="max-h-[70vh] space-y-2 overflow-y-auto px-4 py-3">
                                    <p v-if="!getDayEvents(selectedDate.format('YYYY-MM-DD')).length" class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ emptyLabel }}
                                    </p>
                                    <button
                                        v-for="event in getDayEvents(selectedDate.format('YYYY-MM-DD'))"
                                        :key="event.id"
                                        type="button"
                                        class="w-full text-left text-[12px] leading-snug shadow-sm ring-1 ring-black/5 transition hover:shadow-md"
                                        :class="[getEventClasses(event), canDragEvent(event) ? 'cursor-move' : '']"
                                        :draggable="canDragEvent(event)"
                                        @dragstart="onDragStart(event, $event)"
                                        @dragend="onDragEnd"
                                        @click="openShiftDetails(event)"
                                    >
                                        <div class="flex items-start justify-between gap-2">
                                            <span class="truncate text-sm font-semibold">{{ getEventTitle(event) }}</span>
                                        </div>
                                        <div class="mt-1 text-[11px] font-medium text-stone-600 dark:text-neutral-300">
                                            {{ formatEventTime(event) }}
                                        </div>
                                        <div
                                            v-if="event.extendedProps?.notes"
                                            class="mt-1 text-[10px] text-stone-500 dark:text-neutral-400 line-clamp-2"
                                        >
                                            {{ event.extendedProps?.notes }}
                                        </div>
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
                                            :key="event.id"
                                            class="flex items-center gap-2 text-[11px] text-stone-600 dark:text-neutral-300"
                                        >
                                            <span class="h-1.5 w-1.5 rounded-full" :class="getDayIndicatorClasses(dayjs(event.start).format('YYYY-MM-DD'))"></span>
                                            <span class="truncate">
                                                {{ dayjs(event.start).format('MMM D') }} · {{ getEventTitle(event) }}
                                            </span>
                                        </div>
                                        <div v-if="!getMonthPreviewEvents(month.format('YYYY-MM')).length" class="text-[10px] text-stone-400 dark:text-neutral-500">
                                            {{ previewEmptyLabel }}
                                        </div>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="loadError"
                        class="border-t border-stone-200 bg-red-50 p-3 text-xs text-red-700 dark:border-neutral-800 dark:bg-red-500/10 dark:text-red-200"
                    >
                        {{ loadError }}
                    </div>
                    <div
                        v-else-if="!loadingEvents && !visibleEvents.length"
                        class="border-t border-stone-200 p-3 text-xs text-stone-500 dark:border-neutral-800 dark:text-neutral-400"
                    >
                        {{ emptyLabel }}
                    </div>
                </section>

                <Card id="planning-shift-form" class="xl:sticky xl:top-24">
                    <template #title>{{ formTitle }}</template>
                    <div v-if="!canCreate" class="space-y-2 text-sm text-stone-500 dark:text-neutral-400">
                        <p class="font-semibold text-stone-700 dark:text-neutral-200">
                            {{ lockedTitle }}
                        </p>
                        <p>{{ lockedDescription }}</p>
                    </div>
                    <form v-else class="space-y-3" @submit.prevent="submitShift">
                        <div>
                            <FloatingSelect
                                v-model="form.kind"
                                :label="t('planning.form.kind')"
                                :options="kindOptions"
                            />
                            <InputError :message="formErrors.kind" />
                        </div>

                        <div v-if="props.canManage">
                            <FloatingSelect
                                v-model="form.team_member_id"
                                :label="t('planning.form.member')"
                                :options="memberOptions"
                            />
                            <InputError :message="formErrors.team_member_id" />
                        </div>

                        <div>
                            <FloatingInput v-model="form.shift_date" type="date" :label="dateLabel" />
                            <InputError :message="formErrors.shift_date" />
                        </div>

                        <div v-if="isTimeOffKind">
                            <FloatingSelect
                                v-model="timeOffMode"
                                :label="t('planning.form.duration')"
                                :options="timeOffModeOptions"
                            />
                        </div>

                        <div v-if="isTimeOffKind && !isTimeOffHours">
                            <FloatingInput v-model="form.end_date" type="date" :label="t('planning.form.end_date')" />
                            <InputError :message="formErrors.end_date" />
                        </div>

                        <div v-if="!isTimeOffKind || isTimeOffHours" class="grid grid-cols-2 gap-2">
                            <div>
                                <FloatingInput v-model="form.start_time" type="time" :label="t('planning.form.start_time')" />
                                <InputError :message="formErrors.start_time" />
                            </div>
                            <div>
                                <FloatingInput v-model="form.end_time" type="time" :label="t('planning.form.end_time')" />
                                <InputError :message="formErrors.end_time" />
                            </div>
                        </div>

                        <div v-if="!isTimeOffKind" class="mt-2">
                            <FloatingInput v-model="form.break_minutes" type="number" min="0" max="60" :label="t('planning.form.break_minutes')" />
                            <InputError :message="formErrors.break_minutes" />
                        </div>

                        <div v-if="!isTimeOffKind">
                            <FloatingInput v-model="form.title" :label="t('planning.form.shift_title')" />
                            <InputError :message="formErrors.title" />
                        </div>

                        <div>
                            <FloatingTextarea v-model="form.notes" :label="notesLabel" />
                            <InputError :message="formErrors.notes" />
                        </div>

                        <div v-if="!isTimeOffKind" class="flex items-center gap-2 text-sm text-stone-600 dark:text-neutral-300">
                            <input
                                id="planning-recurring"
                                v-model="form.is_recurring"
                                type="checkbox"
                                class="size-4 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900"
                            />
                            <label for="planning-recurring">{{ t('planning.form.recurring') }}</label>
                        </div>

                        <div v-if="!isTimeOffKind && form.is_recurring" class="space-y-3">
                            <div>
                                <FloatingSelect
                                    v-model="form.frequency"
                                    :label="t('planning.form.frequency')"
                                    :options="frequencyOptions"
                                />
                                <InputError :message="formErrors.frequency" />
                            </div>

                            <div v-if="form.frequency === 'weekly'" class="space-y-2">
                                <p class="text-xs font-semibold text-stone-600 dark:text-neutral-300">
                                    {{ t('planning.form.weekdays') }}
                                </p>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="day in weekdayOptions"
                                        :key="day.value"
                                        type="button"
                                        class="rounded-full px-3 py-1 text-xs font-semibold transition"
                                        :class="recurrenceWeekdays.includes(day.value)
                                            ? 'bg-stone-800 text-white dark:bg-neutral-100 dark:text-neutral-900'
                                            : 'bg-stone-100 text-stone-600 hover:bg-stone-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700'"
                                        @click="toggleWeekday(day.value)"
                                    >
                                        {{ day.label }}
                                    </button>
                                </div>
                                <InputError :message="formErrors.repeats_on" />
                            </div>

                            <div v-else-if="form.frequency === 'monthly'">
                                <FloatingInput v-model="monthlyDay" type="number" :label="t('planning.form.month_day')" />
                            </div>

                            <div>
                                <FloatingInput
                                    v-model="form.recurrence_end_date"
                                    type="date"
                                    :label="t('planning.form.recurrence_end')"
                                />
                                <InputError :message="formErrors.recurrence_end_date" />
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button
                                type="submit"
                                class="rounded-sm border border-emerald-200 bg-emerald-600 px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50"
                                :disabled="formProcessing || !canSubmit"
                            >
                                {{ submitLabel }}
                            </button>
                        </div>
                    </form>
                </Card>
            </div>
        </div>

        <Modal id="hs-planning-delete" :title="deleteTitle">
            <div class="space-y-3">
                <p class="text-sm text-stone-600 dark:text-neutral-300">
                    {{ deleteDescription }}
                </p>

                <div v-if="selectedShift" class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                    <div class="font-semibold">{{ selectedShift.title }}</div>
                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ selectedShift.member }} - {{ selectedShift.date }} - {{ selectedShift.time }}
                    </div>
                    <div
                        v-if="selectedShift.status && ['absence', 'leave'].includes(selectedShift.kind)"
                        class="mt-1 text-xs text-stone-500 dark:text-neutral-400"
                    >
                        {{ t('planning.status.label') }}: {{ t(`planning.status.${selectedShift.status}`) }}
                    </div>
                </div>

                <div v-if="deleteError" class="rounded-sm border border-red-200 bg-red-50 p-2 text-xs text-red-700">
                    {{ deleteError }}
                </div>
                <div v-if="statusError" class="rounded-sm border border-red-200 bg-red-50 p-2 text-xs text-red-700">
                    {{ statusError }}
                </div>

                <div class="flex justify-end gap-2">
                    <button
                        v-if="selectedShift?.canApprove"
                        type="button"
                        class="rounded-sm border border-emerald-200 bg-emerald-600 px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50"
                        :disabled="statusProcessing"
                        @click="updateShiftStatus('approved')"
                    >
                        {{ statusProcessing ? t('planning.status.approving') : t('planning.status.approve') }}
                    </button>
                    <button
                        v-if="selectedShift?.canApprove"
                        type="button"
                        class="rounded-sm border border-amber-200 bg-amber-500 px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-amber-600 disabled:opacity-50"
                        :disabled="statusProcessing"
                        @click="updateShiftStatus('rejected')"
                    >
                        {{ statusProcessing ? t('planning.status.rejecting') : t('planning.status.reject') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        data-hs-overlay="#hs-planning-delete"
                    >
                        {{ t('planning.delete.cancel') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-sm border border-red-200 bg-red-600 px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50"
                        :disabled="deleteProcessing"
                        @click="deleteShift"
                    >
                        {{ deleteProcessing ? t('planning.delete.deleting') : t('planning.delete.confirm') }}
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
