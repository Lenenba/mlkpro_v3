<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import dayjs from 'dayjs';
import FullCalendar from '@fullcalendar/vue3';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
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
    canManage: {
        type: Boolean,
        default: false,
    },
    selfTeamMemberId: {
        type: Number,
        default: null,
    },
});

const { t } = useI18n();

const calendarRef = ref(null);
const calendarEvents = ref([...(props.events || [])]);
const loadingEvents = ref(false);
const loadError = ref('');

const defaultRange = {
    start: props.range?.start || dayjs().startOf('week').format('YYYY-MM-DD'),
    end: props.range?.end || dayjs().add(4, 'week').endOf('week').format('YYYY-MM-DD'),
};
const currentRange = ref({ ...defaultRange });

const selectedMemberId = ref(props.canManage ? '' : (props.selfTeamMemberId || ''));

const today = dayjs().format('YYYY-MM-DD');
const form = reactive({
    team_member_id: props.selfTeamMemberId || props.teamMembers?.[0]?.id || '',
    shift_date: today,
    start_time: '09:00',
    end_time: '17:00',
    title: '',
    notes: '',
    is_recurring: false,
    frequency: 'weekly',
    recurrence_end_date: dayjs().add(1, 'month').format('YYYY-MM-DD'),
});

const weekdayValues = ['su', 'mo', 'tu', 'we', 'th', 'fr', 'sa'];
const recurrenceWeekdays = ref([]);
const monthlyDay = ref(String(dayjs().date()));

const formErrors = ref({});
const formProcessing = ref(false);
const formNotice = ref({ type: '', message: '' });

const deleteProcessing = ref(false);
const deleteError = ref('');
const selectedShift = ref(null);

const canCreate = computed(() => props.canManage && (props.teamMembers || []).length > 0);

const memberOptions = computed(() =>
    (props.teamMembers || []).map((member) => ({
        value: member.id,
        label: member.title ? `${member.name} - ${member.title}` : member.name,
    }))
);

const memberFilterOptions = computed(() => [
    { value: '', label: t('planning.filters.all_members') },
    ...memberOptions.value,
]);

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

const noticeClass = computed(() => {
    if (!formNotice.value?.message) {
        return '';
    }

    return formNotice.value.type === 'success'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
        : 'border-red-200 bg-red-50 text-red-700';
});

const rangeLabel = computed(() => {
    if (!currentRange.value.start || !currentRange.value.end) {
        return '';
    }
    const start = dayjs(currentRange.value.start);
    const end = dayjs(currentRange.value.end);
    const startLabel = start.isValid() ? start.format('MMM D, YYYY') : currentRange.value.start;
    const endLabel = end.isValid() ? end.format('MMM D, YYYY') : currentRange.value.end;
    return `${startLabel} - ${endLabel}`;
});

const setNotice = (type, message) => {
    formNotice.value = { type, message };
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
        const response = await axios.get(route('planning.events'), {
            params: {
                start,
                end,
                team_member_id: selectedMemberId.value || undefined,
            },
        });
        calendarEvents.value = response?.data?.events || [];
    } catch (error) {
        loadError.value = error?.response?.data?.message || t('planning.errors.load');
    } finally {
        loadingEvents.value = false;
    }
};

const handleDatesSet = (info) => {
    const start = dayjs(info.start).format('YYYY-MM-DD');
    const end = dayjs(info.end).subtract(1, 'day').format('YYYY-MM-DD');
    currentRange.value = { start, end };
    fetchEvents(start, end);
};

const handleEventClick = (info) => {
    if (!props.canManage) {
        return;
    }

    const event = info.event;
    const start = dayjs(event.start);
    const end = dayjs(event.end);

    selectedShift.value = {
        id: event.id,
        title: event.title,
        member: event.extendedProps?.member_name || '',
        date: start.isValid() ? start.format('YYYY-MM-DD') : '',
        start: start.isValid() ? start.format('HH:mm') : '',
        end: end.isValid() ? end.format('HH:mm') : '',
    };
    deleteError.value = '';

    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-planning-delete');
    }
};

const submitShift = async () => {
    if (!canCreate.value || formProcessing.value) {
        return;
    }

    formProcessing.value = true;
    formErrors.value = {};
    setNotice('', '');

    const payload = {
        team_member_id: form.team_member_id,
        shift_date: form.shift_date,
        start_time: form.start_time,
        end_time: form.end_time,
        title: form.title || null,
        notes: form.notes || null,
        is_recurring: form.is_recurring,
    };

    if (form.is_recurring) {
        payload.frequency = form.frequency;
        payload.recurrence_end_date = form.recurrence_end_date;
        payload.repeats_on = buildRepeatsOn();
    }

    try {
        const response = await axios.post(route('planning.shifts.store'), payload);
        const createdCount = response?.data?.created
            ?? response?.data?.events?.length
            ?? 1;

        setNotice('success', t('planning.notices.created', { count: createdCount }));
        form.title = '';
        form.notes = '';
        await fetchEvents(currentRange.value.start, currentRange.value.end);
    } catch (error) {
        formErrors.value = normalizeErrors(error);
        setNotice('error', error?.response?.data?.message || t('planning.errors.save'));
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
        setNotice('success', t('planning.notices.deleted'));
        await fetchEvents(currentRange.value.start, currentRange.value.end);
        selectedShift.value = null;
    } catch (error) {
        deleteError.value = error?.response?.data?.message || t('planning.errors.delete');
    } finally {
        deleteProcessing.value = false;
    }
};

const calendarOptions = computed(() => ({
    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
    initialView: 'timeGridWeek',
    headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'timeGridWeek,dayGridMonth',
    },
    buttonText: {
        today: t('planning.calendar.today'),
        month: t('planning.calendar.month'),
        week: t('planning.calendar.week'),
        day: t('planning.calendar.day'),
    },
    events: calendarEvents.value,
    editable: false,
    selectable: false,
    nowIndicator: true,
    height: 'auto',
    eventClick: handleEventClick,
    datesSet: handleDatesSet,
}));

watch(
    () => props.teamMembers,
    (members) => {
        if (!form.team_member_id && members?.length) {
            form.team_member_id = members[0].id;
        }
    },
    { immediate: true }
);

watch(
    () => form.shift_date,
    (value) => {
        syncDefaultsFromDate(value);
    },
    { immediate: true }
);

watch(
    () => selectedMemberId.value,
    () => {
        if (!props.canManage) {
            return;
        }
        fetchEvents(currentRange.value.start, currentRange.value.end);
    }
);
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
                        {{ t('planning.subtitle') }}
                    </p>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <Card class="lg:col-span-2">
                    <template #title>{{ t('planning.calendar.title') }}</template>
                    <div class="space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('planning.filters.range') }}: {{ rangeLabel || '--' }}
                            </div>
                            <div v-if="props.canManage && memberFilterOptions.length > 1" class="min-w-[220px]">
                                <FloatingSelect
                                    v-model="selectedMemberId"
                                    :label="t('planning.filters.member')"
                                    :options="memberFilterOptions"
                                />
                            </div>
                        </div>

                        <div v-if="loadError" class="rounded-sm border border-red-200 bg-red-50 p-2 text-xs text-red-700">
                            {{ loadError }}
                        </div>
                        <div v-else-if="loadingEvents" class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('planning.filters.loading') }}
                        </div>
                        <div v-else-if="!calendarEvents.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ t('planning.filters.empty') }}
                        </div>

                        <FullCalendar :options="calendarOptions" ref="calendarRef" />
                    </div>
                </Card>

                <Card>
                    <template #title>{{ t('planning.form.title') }}</template>
                    <div v-if="!props.canManage" class="space-y-2 text-sm text-stone-500 dark:text-neutral-400">
                        <p class="font-semibold text-stone-700 dark:text-neutral-200">
                            {{ t('planning.empty.title') }}
                        </p>
                        <p>{{ t('planning.empty.description') }}</p>
                    </div>
                    <form v-else class="space-y-3" @submit.prevent="submitShift">
                        <div v-if="formNotice.message" class="rounded-sm border p-2 text-xs" :class="noticeClass">
                            {{ formNotice.message }}
                        </div>

                        <div>
                            <FloatingSelect
                                v-model="form.team_member_id"
                                :label="t('planning.form.member')"
                                :options="memberOptions"
                            />
                            <InputError :message="formErrors.team_member_id" />
                        </div>

                        <div>
                            <FloatingInput v-model="form.shift_date" type="date" :label="t('planning.form.date')" />
                            <InputError :message="formErrors.shift_date" />
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <FloatingInput v-model="form.start_time" type="time" :label="t('planning.form.start_time')" />
                                <InputError :message="formErrors.start_time" />
                            </div>
                            <div>
                                <FloatingInput v-model="form.end_time" type="time" :label="t('planning.form.end_time')" />
                                <InputError :message="formErrors.end_time" />
                            </div>
                        </div>

                        <div>
                            <FloatingInput v-model="form.title" :label="t('planning.form.shift_title')" />
                            <InputError :message="formErrors.title" />
                        </div>

                        <div>
                            <FloatingTextarea v-model="form.notes" :label="t('planning.form.notes')" />
                            <InputError :message="formErrors.notes" />
                        </div>

                        <div class="flex items-center gap-2 text-sm text-stone-600 dark:text-neutral-300">
                            <input
                                id="planning-recurring"
                                v-model="form.is_recurring"
                                type="checkbox"
                                class="size-4 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900"
                            />
                            <label for="planning-recurring">{{ t('planning.form.recurring') }}</label>
                        </div>

                        <div v-if="form.is_recurring" class="space-y-3">
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
                                :disabled="formProcessing || !canCreate"
                            >
                                {{ formProcessing ? t('planning.form.creating') : t('planning.form.create') }}
                            </button>
                        </div>
                    </form>
                </Card>
            </div>
        </div>

        <Modal id="hs-planning-delete" :title="t('planning.delete.title')">
            <div class="space-y-3">
                <p class="text-sm text-stone-600 dark:text-neutral-300">
                    {{ t('planning.delete.description') }}
                </p>

                <div v-if="selectedShift" class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                    <div class="font-semibold">{{ selectedShift.title }}</div>
                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ selectedShift.member }} - {{ selectedShift.date }} - {{ selectedShift.start }} - {{ selectedShift.end }}
                    </div>
                </div>

                <div v-if="deleteError" class="rounded-sm border border-red-200 bg-red-50 p-2 text-xs text-red-700">
                    {{ deleteError }}
                </div>

                <div class="flex justify-end gap-2">
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
