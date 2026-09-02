<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import dayjs from 'dayjs';
import {
    ArrowLeft,
    ArrowRight,
    CalendarDays,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Loader2,
    Sparkles,
    UserRound,
    UsersRound,
} from 'lucide-vue-next';
import ClientPortalNotice from '@/Components/Portal/ClientPortalNotice.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import {
    CLIENT_BOOKING_STEP_KEYS,
    canVisitClientBookingStep,
    createClientBookingStepState,
    invalidateClientBookingStepsFrom,
    transitionClientBookingStep,
} from '@/utils/clientBookingStepper';
import {
    addReservationCalendarTime,
    reservationCalendarDate,
    reservationCalendarEndOf,
    reservationWeekStart,
} from '@/utils/reservationCalendar';

const { locale, t } = useI18n();

const props = defineProps({
    timezone: {
        type: String,
        default: 'UTC',
    },
    teamMembers: {
        type: Array,
        default: () => [],
    },
    services: {
        type: Array,
        default: () => [],
    },
    client: {
        type: Object,
        default: () => ({}),
    },
    capabilities: {
        type: Object,
        default: () => ({
            view: false,
            manage: false,
        }),
    },
    settings: {
        type: Object,
        default: () => ({}),
    },
});

const resolveDisplayTimezone = (value) => {
    const timezone = String(value || 'UTC').trim() || 'UTC';

    try {
        new Intl.DateTimeFormat('en', { timeZone: timezone }).format();

        return timezone;
    } catch {
        return 'UTC';
    }
};
const initialTimezone = resolveDisplayTimezone(props.timezone);

const selectedTeamMemberId = ref('');
const selectedServiceId = ref(props.services?.[0]?.id ? String(props.services[0].id) : '');
const slots = ref([]);
const selectedSlot = ref(null);
const slotsLoading = ref(false);
const slotsLoaded = ref(false);
const slotsError = ref('');
const stepError = ref('');
const successMessage = ref('');
const submitError = ref('');
const submitting = ref(false);
const completedReservation = ref(null);
const stepPanel = ref(null);
const stepState = ref(createClientBookingStepState());
const initialWeekStart = reservationWeekStart(dayjs(), 1, initialTimezone);
const calendarRange = ref({
    start: initialWeekStart.toISOString(),
    end: reservationCalendarEndOf(
        addReservationCalendarTime(initialWeekStart, 6, 'day', initialTimezone),
        'day',
        initialTimezone,
    ).toISOString(),
});
const selectedDayKey = ref('');
const showWaitlistForm = ref(false);
const waitlistSubmitting = ref(false);
const waitlistError = ref('');
const waitlistSuccess = ref('');

const bookingForm = useForm({
    team_member_id: '',
    service_id: selectedServiceId.value,
    starts_at: '',
    ends_at: '',
    duration_minutes: 60,
    party_size: '',
    timezone: initialTimezone,
    contact_name: props.client?.name || '',
    contact_email: props.client?.email || '',
    contact_phone: props.client?.phone || '',
    client_notes: '',
});

const waitlistForm = useForm({
    party_size: '',
    notes: '',
});

const bookingSteps = computed(() => CLIENT_BOOKING_STEP_KEYS.map((key) => ({
    key,
    label: t(`reservations.client.book.steps.${key}.label`),
    short: t(`reservations.client.book.steps.${key}.short`),
})));
const currentStep = computed(() => stepState.value.currentStep);
const maxVisitedStep = computed(() => stepState.value.maxVisitedStep);
const currentStepKey = computed(() => CLIENT_BOOKING_STEP_KEYS[currentStep.value] || 'preferences');
const isBookingComplete = computed(() => stepState.value.completed);
const progressWidthClass = computed(() => [
    'w-1/4',
    'w-2/4',
    'w-3/4',
    'w-full',
][currentStep.value] || 'w-1/4');

const teamOptions = computed(() => [
    { value: '', label: t('reservations.client.index.any_available') },
    ...(props.teamMembers || []).map((member) => ({
        value: String(member.id),
        label: member.title ? `${member.name} - ${member.title}` : member.name,
    })),
]);
const serviceOptions = computed(() => [
    {
        value: '',
        label: t('reservations.client.book.generic_service'),
        search: t('reservations.client.book.generic_service_description'),
    },
    ...(props.services || []).map((service) => ({
        value: String(service.id),
        label: service.name,
        search: [service.name, service.description].filter(Boolean).join(' '),
    })),
]);
const selectedService = computed(() => (props.services || [])
    .find((service) => String(service.id) === String(selectedServiceId.value || '')));
const selectedServiceLabel = computed(() => selectedService.value?.name
    || t('reservations.client.book.generic_service'));
const selectedServiceDescription = computed(() => selectedService.value?.description
    || (selectedService.value
        ? t('reservations.client.book.service_fallback_description')
        : t('reservations.client.book.generic_service_description')));
const selectedContactLabel = computed(() => bookingForm.contact_name
    || bookingForm.contact_email
    || bookingForm.contact_phone
    || t('reservations.client.book.summary.not_provided'));
const localeCode = computed(() => String(locale.value || 'fr-CA').replaceAll('_', '-'));
const displayTimezone = computed(() => resolveDisplayTimezone(props.timezone));

const formatDateValue = (value, options) => {
    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat(localeCode.value, {
        ...options,
        timeZone: displayTimezone.value,
    }).format(date);
};

const formatDateTime = (value) => formatDateValue(value, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
});
const formatTime = (value) => formatDateValue(value, {
    hour: '2-digit',
    minute: '2-digit',
});
const formatDayLabel = (value) => formatDateValue(value, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
});
const formatDayAriaLabel = (value) => formatDateValue(value, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

const baseWeekDays = computed(() => {
    const weekStart = reservationWeekStart(calendarRange.value.start, 1, displayTimezone.value);

    return Array.from({ length: 7 }, (_, index) => {
        const date = addReservationCalendarTime(weekStart, index, 'day', displayTimezone.value);

        return {
            date: date.toDate(),
            key: date.format('YYYY-MM-DD'),
        };
    });
});
const slotsByDay = computed(() => {
    const groupedSlots = new Map();

    for (const slot of slots.value || []) {
        const dateKey = slot.date
            || reservationCalendarDate(slot.starts_at, displayTimezone.value).format('YYYY-MM-DD');
        const daySlots = groupedSlots.get(dateKey) || [];
        daySlots.push(slot);
        groupedSlots.set(dateKey, daySlots);
    }

    return groupedSlots;
});
const weekDays = computed(() => baseWeekDays.value.map((day) => ({
    ...day,
    count: (slotsByDay.value.get(day.key) || []).length,
    label: formatDayLabel(day.date),
    ariaLabel: formatDayAriaLabel(day.date),
})));
const selectedDaySlots = computed(() => slotsByDay.value.get(selectedDayKey.value) || []);
const selectedDay = computed(() => weekDays.value.find((day) => day.key === selectedDayKey.value));
const selectedDayLabel = computed(() => selectedDay.value?.ariaLabel || '');
const weekLabel = computed(() => {
    const firstDay = baseWeekDays.value[0]?.date;
    const lastDay = baseWeekDays.value[6]?.date;

    if (!firstDay || !lastDay) {
        return '';
    }

    const formatter = new Intl.DateTimeFormat(localeCode.value, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: displayTimezone.value,
    });

    return typeof formatter.formatRange === 'function'
        ? formatter.formatRange(firstDay, lastDay)
        : `${formatter.format(firstDay)} – ${formatter.format(lastDay)}`;
});
const currentDayKey = reservationCalendarDate(dayjs(), displayTimezone.value).format('YYYY-MM-DD');
const currentWeekStart = reservationWeekStart(dayjs(), 1, displayTimezone.value);
const canGoToPreviousWeek = computed(() => (
    reservationWeekStart(calendarRange.value.start, 1, displayTimezone.value)
        .isAfter(currentWeekStart)
));
const selectedSlotLabel = computed(() => {
    if (!selectedSlot.value) {
        return t('reservations.client.book.summary.not_selected');
    }

    const resourceLabel = selectedSlot.value.resource_name
        ? ` · ${selectedSlot.value.resource_name}`
        : '';

    return `${formatDateTime(selectedSlot.value.starts_at)} – ${formatTime(selectedSlot.value.ends_at)} · ${selectedSlot.value.team_member_name}${resourceLabel}`;
});
const completedReservationLabel = computed(() => {
    const reservation = completedReservation.value;
    if (!reservation?.starts_at) {
        return selectedSlotLabel.value;
    }

    const endLabel = reservation.ends_at ? ` – ${formatTime(reservation.ends_at)}` : '';
    const memberName = reservation.team_member?.user?.name
        || reservation.teamMember?.user?.name
        || reservation.team_member_name
        || '';

    return `${formatDateTime(reservation.starts_at)}${endLabel}${memberName ? ` · ${memberName}` : ''}`;
});

const waitlistEnabled = computed(() => Boolean(props.settings?.waitlist_enabled));
const ownerOnlyMode = computed(() => Boolean(props.settings?.owner_only_mode));
const slotBookingAvailable = computed(() => Boolean(props.settings?.slot_booking_enabled ?? true));
const slotDurationMinutes = computed(() => {
    const value = Number(props.settings?.slot_duration_minutes || props.settings?.slot_interval_minutes || 60);

    return Math.max(5, Math.min(240, Number.isFinite(value) ? value : 60));
});
const hasNoSlots = computed(() => slotsLoaded.value
    && !slotsLoading.value
    && !slotsError.value
    && (slots.value || []).length === 0);
const hasDepositPolicy = computed(() => (
    Boolean(props.settings?.deposit_required) && Number(props.settings?.deposit_amount || 0) > 0
));
const hasNoShowFeePolicy = computed(() => (
    Boolean(props.settings?.no_show_fee_enabled) && Number(props.settings?.no_show_fee_amount || 0) > 0
));
const canSubmit = computed(() => slotBookingAvailable.value
    && currentStepKey.value === 'review'
    && Boolean(selectedSlot.value)
    && !submitting.value);
const canViewReservations = computed(() => Boolean(props.capabilities?.view));
const followUpUrl = computed(() => (canViewReservations.value
    ? route('client.reservations.index')
    : route('dashboard')));
const followUpLabel = computed(() => (canViewReservations.value
    ? t('reservations.client.book.actions.view_reservations')
    : t('reservations.client.book.actions.back_to_dashboard')));
const successDescription = computed(() => (canViewReservations.value
    ? t('reservations.client.book.success.description')
    : t('reservations.client.book.success.description_book_only')));

const formatMoney = (value) => Number(value || 0).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});
const focusStepPanel = () => {
    nextTick(() => stepPanel.value?.focus());
};

const setCurrentStep = (targetStep) => {
    stepState.value = transitionClientBookingStep(stepState.value, targetStep, { force: true });
    stepError.value = '';
    focusStepPanel();
};

const goToStep = (targetStep) => {
    if (submitting.value || !canVisitClientBookingStep(stepState.value, targetStep)) {
        return;
    }

    stepState.value = transitionClientBookingStep(stepState.value, targetStep);
    stepError.value = '';
    submitError.value = '';
    focusStepPanel();
};

let slotsRequestId = 0;
let slotsTimer = null;

const clearSlotsTimer = () => {
    if (!slotsTimer) {
        return;
    }

    clearTimeout(slotsTimer);
    slotsTimer = null;
};

const loadSlots = async () => {
    if (!calendarRange.value.start || !calendarRange.value.end) {
        return false;
    }

    if (!slotBookingAvailable.value) {
        slots.value = [];
        selectedSlot.value = null;
        slotsError.value = '';
        slotsLoading.value = false;
        slotsLoaded.value = true;
        return true;
    }

    const requestId = ++slotsRequestId;
    slotsLoading.value = true;
    slotsLoaded.value = false;
    slotsError.value = '';
    bookingForm.clearErrors('party_size');

    try {
        const response = await axios.get(route('client.reservations.slots'), {
            params: {
                range_start: calendarRange.value.start,
                range_end: calendarRange.value.end,
                team_member_id: selectedTeamMemberId.value || undefined,
                service_id: selectedServiceId.value || undefined,
                duration_minutes: slotDurationMinutes.value,
                party_size: bookingForm.party_size || undefined,
            },
        });

        if (requestId !== slotsRequestId) {
            return false;
        }

        slots.value = response?.data?.slots || [];
        const visibleDayKeys = new Set(baseWeekDays.value.map((day) => day.key));
        if (!selectedDayKey.value || !visibleDayKeys.has(selectedDayKey.value)) {
            selectedDayKey.value = slots.value.find((slot) => visibleDayKeys.has(slot.date))?.date
                || baseWeekDays.value.find((day) => day.key >= currentDayKey)?.key
                || baseWeekDays.value[0]?.key
                || '';
        }

        if (selectedSlot.value) {
            const selectionStillExists = slots.value.some((slot) => (
                Number(slot.team_member_id) === Number(selectedSlot.value.team_member_id)
                && slot.starts_at === selectedSlot.value.starts_at
            ));

            if (!selectionStillExists) {
                selectedSlot.value = null;
                stepState.value = invalidateClientBookingStepsFrom(stepState.value, 2);
            }
        }

        return true;
    } catch (error) {
        if (requestId === slotsRequestId) {
            slots.value = [];
            selectedSlot.value = null;
            stepState.value = invalidateClientBookingStepsFrom(stepState.value, 2);
            const validationErrors = error?.response?.status === 422
                ? (error.response.data?.errors || {})
                : {};

            if (Object.keys(validationErrors).length) {
                bookingForm.setError(validationErrors);
            }

            slotsError.value = validationErrors.party_size?.[0]
                || error?.response?.data?.message
                || t('reservations.errors.load_slots');
        }

        return false;
    } finally {
        if (requestId === slotsRequestId) {
            slotsLoading.value = false;
            slotsLoaded.value = true;
        }
    }
};

const queueLoadSlots = () => {
    clearSlotsTimer();

    slotsTimer = setTimeout(() => {
        slotsTimer = null;
        void loadSlots();
    }, 280);
};

watch(
    () => [selectedTeamMemberId.value, selectedServiceId.value, bookingForm.party_size],
    () => {
        selectedSlot.value = null;
        slots.value = [];
        slotsLoaded.value = false;
        stepState.value = invalidateClientBookingStepsFrom(stepState.value, 1);
        stepError.value = '';
        successMessage.value = '';
        submitError.value = '';
        queueLoadSlots();
    },
);

watch(selectedServiceId, (value) => {
    bookingForm.service_id = value || '';
});

const changeWeek = (direction) => {
    if (direction < 0 && !canGoToPreviousWeek.value) {
        return;
    }

    clearSlotsTimer();

    slotsRequestId += 1;
    const currentStart = reservationWeekStart(
        calendarRange.value.start,
        1,
        displayTimezone.value,
    );
    const nextStart = addReservationCalendarTime(
        currentStart,
        direction * 7,
        'day',
        displayTimezone.value,
    );
    const nextEnd = reservationCalendarEndOf(
        addReservationCalendarTime(nextStart, 6, 'day', displayTimezone.value),
        'day',
        displayTimezone.value,
    );

    calendarRange.value = {
        start: nextStart.toISOString(),
        end: nextEnd.toISOString(),
    };
    selectedDayKey.value = '';
    selectedSlot.value = null;
    slots.value = [];
    slotsLoaded.value = false;
    stepState.value = invalidateClientBookingStepsFrom(stepState.value, 2);
    stepError.value = '';
    submitError.value = '';
    successMessage.value = '';
    bookingForm.clearErrors('team_member_id', 'starts_at', 'ends_at', 'resource_ids');
    void loadSlots();
};

const selectDay = (day) => {
    if (!day?.key || selectedDayKey.value === day.key) {
        return;
    }

    selectedDayKey.value = day.key;
    selectedSlot.value = null;
    stepState.value = invalidateClientBookingStepsFrom(stepState.value, 2);
    stepError.value = '';
    submitError.value = '';
    successMessage.value = '';
    bookingForm.clearErrors('team_member_id', 'starts_at', 'ends_at', 'resource_ids');
};

const selectSlot = (slot) => {
    selectedSlot.value = slot;
    stepError.value = '';
    successMessage.value = '';
    submitError.value = '';
    bookingForm.clearErrors('team_member_id', 'starts_at', 'ends_at', 'resource_ids');
};

const submitBooking = async () => {
    if (submitting.value) {
        return;
    }

    submitError.value = '';
    successMessage.value = '';
    bookingForm.clearErrors();

    if (!selectedSlot.value) {
        submitError.value = t('reservations.client.book.select_slot_error');
        return;
    }

    submitting.value = true;

    try {
        const response = await axios.post(route('client.reservations.store'), {
            team_member_id: Number(selectedSlot.value.team_member_id),
            service_id: selectedServiceId.value ? Number(selectedServiceId.value) : null,
            starts_at: selectedSlot.value.starts_at,
            ends_at: selectedSlot.value.ends_at,
            duration_minutes: slotDurationMinutes.value,
            party_size: bookingForm.party_size ? Number(bookingForm.party_size) : null,
            timezone: bookingForm.timezone || displayTimezone.value,
            contact_name: bookingForm.contact_name || null,
            contact_email: bookingForm.contact_email || null,
            contact_phone: bookingForm.contact_phone || null,
            client_notes: bookingForm.client_notes || null,
            resource_ids: selectedSlot.value.resource_id ? [Number(selectedSlot.value.resource_id)] : [],
        }, {
            headers: {
                Accept: 'application/json',
            },
        });

        const reservation = response?.data?.reservation;
        completedReservation.value = reservation || null;
        successMessage.value = t('reservations.client.book.actions.submitted');
        setCurrentStep(3);
    } catch (error) {
        if (error?.response?.status === 422) {
            const validationErrors = error.response.data?.errors || {};
            const hasSlotConflict = Boolean(
                validationErrors.starts_at
                || validationErrors.ends_at
                || validationErrors.team_member_id
                || validationErrors.resource_ids
            );

            bookingForm.setError(validationErrors);
            submitError.value = hasSlotConflict
                ? t('reservations.client.book.slot_conflict_error')
                : t('reservations.errors.validation');

            if (hasSlotConflict) {
                selectedSlot.value = null;
                stepState.value = invalidateClientBookingStepsFrom(stepState.value, 2);
                await loadSlots();
                setCurrentStep(1);
            }
        } else {
            submitError.value = error?.response?.data?.message || t('reservations.errors.create');
        }
    } finally {
        submitting.value = false;
    }
};

const continueBookingStep = async () => {
    stepError.value = '';
    submitError.value = '';

    if (currentStepKey.value === 'preferences') {
        clearSlotsTimer();
        const slotsReady = await loadSlots();
        if (!slotsReady) {
            stepError.value = slotsError.value || t('reservations.errors.load_slots');
            return;
        }

        setCurrentStep(1);
        return;
    }

    if (currentStepKey.value === 'slot') {
        if (!selectedSlot.value) {
            stepError.value = t('reservations.client.book.select_slot_error');
            return;
        }

        setCurrentStep(2);
        return;
    }

    if (currentStepKey.value === 'review') {
        await submitBooking();
    }
};

const backStep = () => {
    if (currentStep.value <= 0 || submitting.value) {
        return;
    }

    goToStep(currentStep.value - 1);
};

const resetBooking = async () => {
    selectedSlot.value = null;
    completedReservation.value = null;
    successMessage.value = '';
    submitError.value = '';
    stepError.value = '';
    bookingForm.client_notes = '';
    bookingForm.clearErrors();
    stepState.value = createClientBookingStepState();
    await loadSlots();
    focusStepPanel();
};

const submitWaitlist = async () => {
    if (!waitlistEnabled.value || waitlistSubmitting.value) {
        return;
    }

    if (!calendarRange.value.start || !calendarRange.value.end) {
        return;
    }

    waitlistError.value = '';
    waitlistSuccess.value = '';
    waitlistForm.clearErrors();
    waitlistSubmitting.value = true;

    try {
        const response = await axios.post(route('client.reservations.waitlist.store'), {
            service_id: selectedServiceId.value ? Number(selectedServiceId.value) : null,
            team_member_id: selectedTeamMemberId.value ? Number(selectedTeamMemberId.value) : null,
            requested_start_at: calendarRange.value.start,
            requested_end_at: calendarRange.value.end,
            duration_minutes: slotDurationMinutes.value,
            party_size: waitlistForm.party_size
                ? Number(waitlistForm.party_size)
                : (bookingForm.party_size ? Number(bookingForm.party_size) : null),
            notes: waitlistForm.notes || null,
        }, {
            headers: {
                Accept: 'application/json',
            },
        });

        waitlistForm.reset();
        showWaitlistForm.value = false;
        waitlistSuccess.value = response?.data?.message || t('reservations.client.book.waitlist.created');
    } catch (error) {
        if (error?.response?.status === 422) {
            waitlistForm.setError(error.response.data?.errors || {});
            waitlistError.value = t('reservations.errors.validation');
        } else {
            waitlistError.value = error?.response?.data?.message || t('reservations.client.book.waitlist.create_error');
        }
    } finally {
        waitlistSubmitting.value = false;
    }
};

onBeforeUnmount(() => {
    slotsRequestId += 1;
    clearSlotsTimer();
});
</script>

<template>
    <div class="space-y-4">
        <nav
            v-if="slotBookingAvailable"
            class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            :aria-label="$t('reservations.client.book.stepper.aria_label')"
        >
            <div class="sm:hidden">
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="font-semibold text-stone-800 dark:text-neutral-100">
                        {{ bookingSteps[currentStep]?.label }}
                    </span>
                    <span class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('reservations.client.book.stepper.progress', { current: currentStep + 1, total: bookingSteps.length }) }}
                    </span>
                </div>
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-stone-100 dark:bg-neutral-800">
                    <div class="h-full rounded-full bg-emerald-600 transition-[width] motion-reduce:transition-none" :class="progressWidthClass" />
                </div>
            </div>

            <ol class="hidden grid-cols-4 gap-2 sm:grid">
                <li v-for="(step, index) in bookingSteps" :key="step.key" class="min-w-0">
                    <button
                        type="button"
                        class="group flex w-full min-w-0 items-center gap-2 rounded-sm px-3 py-2 text-left text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 motion-reduce:transition-none dark:focus-visible:ring-offset-neutral-900"
                        :class="index === currentStep
                            ? 'bg-emerald-700 text-white shadow-sm'
                            : index <= maxVisitedStep
                                ? 'text-stone-700 hover:bg-stone-100 dark:text-neutral-200 dark:hover:bg-neutral-800'
                                : 'cursor-default text-stone-400 dark:text-neutral-600'"
                        :disabled="index > maxVisitedStep || isBookingComplete"
                        :aria-current="index === currentStep ? 'step' : undefined"
                        :aria-label="$t('reservations.client.book.stepper.step_label', { current: index + 1, total: bookingSteps.length, label: step.label })"
                        @click="goToStep(index)"
                    >
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-full text-[11px]"
                            :class="index === currentStep
                                ? 'bg-white text-emerald-700'
                                : index < currentStep || isBookingComplete
                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
                                    : 'bg-stone-100 text-stone-500 dark:bg-neutral-800 dark:text-neutral-400'"
                        >
                            <CheckCircle2 v-if="index < currentStep || (isBookingComplete && index < bookingSteps.length - 1)" class="size-4" aria-hidden="true" />
                            <span v-else>{{ index + 1 }}</span>
                        </span>
                        <span class="min-w-0 truncate">{{ step.label }}</span>
                        <span v-if="index < currentStep" class="sr-only">{{ $t('reservations.client.book.stepper.completed') }}</span>
                    </button>
                </li>
            </ol>
        </nav>

        <section
            v-if="!slotBookingAvailable"
            class="rounded-sm border border-amber-200 bg-amber-50 p-5 shadow-sm dark:border-amber-500/30 dark:bg-amber-500/10"
        >
            <div class="flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-sm bg-white text-amber-700 dark:bg-neutral-900 dark:text-amber-300">
                    <CalendarDays class="size-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <h2 class="font-semibold text-amber-950 dark:text-amber-100">
                        {{ $t('reservations.client.book.states.unavailable_title') }}
                    </h2>
                    <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
                        {{ $t('reservations.client.book.states.unavailable_description') }}
                    </p>
                    <Link :href="followUpUrl" class="mt-4 inline-flex min-h-11 items-center rounded-sm bg-amber-700 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-800">
                        {{ followUpLabel }}
                    </Link>
                </div>
            </div>
        </section>

        <div
            v-else
            class="grid gap-4"
            :class="isBookingComplete ? 'grid-cols-1' : 'lg:grid-cols-[minmax(0,1fr)_20rem]'"
        >
            <section
                ref="stepPanel"
                tabindex="-1"
                class="min-w-0 overflow-hidden rounded-sm border border-stone-200 bg-white shadow-sm focus:outline-none dark:border-neutral-700 dark:bg-neutral-900"
                :aria-busy="slotsLoading || submitting"
                aria-labelledby="client-booking-step-title"
            >
                <section v-if="currentStepKey === 'preferences'" class="p-4 sm:p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                                {{ $t('reservations.client.book.steps.preferences.eyebrow') }}
                            </p>
                            <h2 id="client-booking-step-title" class="mt-1 text-xl font-semibold text-stone-950 dark:text-white">
                                {{ $t('reservations.client.book.steps.preferences.title') }}
                            </h2>
                            <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('reservations.client.book.steps.preferences.description') }}
                            </p>
                        </div>
                        <Sparkles class="size-5 shrink-0 text-amber-500" aria-hidden="true" />
                    </div>

                    <div class="mt-5 grid items-start gap-3 sm:grid-cols-[minmax(0,1.2fr)_minmax(14rem,0.8fr)]">
                        <div class="min-w-0">
                            <FloatingSelect
                                id="client-booking-service-search"
                                v-model="selectedServiceId"
                                :options="serviceOptions"
                                option-value="value"
                                option-label="label"
                                filterable
                                select-on-focus
                                :label="$t('reservations.client.book.fields.service_search')"
                                :filter-placeholder="$t('reservations.client.book.fields.service_search_placeholder')"
                                :empty-label="$t('reservations.client.book.states.no_service_match')"
                                :toggle-label="$t('reservations.client.book.actions.show_service_suggestions')"
                                aria-describedby="client-booking-service-search-hint"
                            />
                            <p id="client-booking-service-search-hint" class="mt-1.5 text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('reservations.client.book.service_search_hint') }}
                            </p>
                        </div>

                        <div class="flex min-w-0 items-start gap-3 rounded-sm border border-emerald-200 bg-emerald-50/70 px-3 py-3 dark:border-emerald-500/30 dark:bg-emerald-500/10">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-sm bg-white text-emerald-700 shadow-sm dark:bg-neutral-900 dark:text-emerald-300">
                                <CalendarDays class="size-4" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                                    {{ $t('reservations.client.book.summary.service') }}
                                </p>
                                <p class="mt-0.5 truncate font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ selectedServiceLabel }}
                                </p>
                                <p class="mt-0.5 line-clamp-2 text-xs text-stone-600 dark:text-neutral-400">
                                    {{ selectedServiceDescription }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <ClientPortalNotice
                        v-if="!services.length"
                        class="mt-4"
                        tone="info"
                        :message="$t('reservations.client.book.states.no_services')"
                        compact
                    />

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <FloatingSelect
                            v-if="teamOptions.length > 1"
                            v-model="selectedTeamMemberId"
                            :options="teamOptions"
                            :label="$t('reservations.client.book.fields.team_member')"
                        />
                        <div>
                            <FloatingInput
                                id="client-booking-party-size"
                                v-model="bookingForm.party_size"
                                type="number"
                                min="1"
                                max="500"
                                :aria-invalid="Boolean(bookingForm.errors.party_size)"
                                :aria-describedby="bookingForm.errors.party_size ? 'client-booking-party-size-error' : undefined"
                                :label="$t('reservations.client.book.fields.party_size')"
                            />
                            <InputError id="client-booking-party-size-error" class="mt-1" :message="bookingForm.errors.party_size" />
                        </div>
                    </div>
                </section>

                <section v-else-if="currentStepKey === 'slot'" class="p-4 sm:p-6">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                            {{ $t('reservations.client.book.steps.slot.eyebrow') }}
                        </p>
                        <h2 id="client-booking-step-title" class="mt-1 text-xl font-semibold text-stone-950 dark:text-white">
                            {{ $t('reservations.client.book.steps.slot.title') }}
                        </h2>
                        <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('reservations.client.book.steps.slot.description') }}
                        </p>
                    </div>

                    <ClientPortalNotice
                        v-if="ownerOnlyMode"
                        class="mt-4"
                        tone="warning"
                        :message="$t('reservations.owner_only.client_notice')"
                        compact
                    />

                    <div class="mt-5 overflow-hidden rounded-sm border border-stone-200 bg-stone-50/70 dark:border-neutral-700 dark:bg-neutral-950/40">
                        <div class="flex items-center justify-between gap-3 border-b border-stone-200 bg-white px-3 py-3 dark:border-neutral-700 dark:bg-neutral-900 sm:px-4">
                            <button
                                type="button"
                                class="inline-flex size-11 shrink-0 items-center justify-center rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                :disabled="!canGoToPreviousWeek || slotsLoading"
                                :aria-label="$t('reservations.client.book.steps.slot.previous_week')"
                                @click="changeWeek(-1)"
                            >
                                <ChevronLeft class="size-5" aria-hidden="true" />
                            </button>
                            <div class="min-w-0 text-center">
                                <p class="text-xs font-semibold uppercase tracking-wide text-stone-400">
                                    {{ $t('reservations.client.book.steps.slot.week') }}
                                </p>
                                <p class="truncate text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ weekLabel }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="inline-flex size-11 shrink-0 items-center justify-center rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 disabled:cursor-wait disabled:opacity-40 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                :disabled="slotsLoading"
                                :aria-label="$t('reservations.client.book.steps.slot.next_week')"
                                @click="changeWeek(1)"
                            >
                                <ChevronRight class="size-5" aria-hidden="true" />
                            </button>
                        </div>

                        <div class="p-3 sm:p-4">
                            <div v-if="slotsLoading" class="space-y-4" role="status" aria-live="polite">
                                <span class="sr-only">{{ $t('reservations.client.book.loading_slots') }}</span>
                                <div class="grid grid-cols-4 gap-2 sm:grid-cols-7" aria-hidden="true">
                                    <div v-for="index in 7" :key="`client-booking-day-skeleton-${index}`" class="h-20 animate-pulse rounded-sm bg-stone-200 motion-reduce:animate-none dark:bg-neutral-800" />
                                </div>
                                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3" aria-hidden="true">
                                    <div v-for="index in 6" :key="`client-booking-slot-skeleton-${index}`" class="h-16 animate-pulse rounded-sm bg-stone-200 motion-reduce:animate-none dark:bg-neutral-800" />
                                </div>
                            </div>

                            <ClientPortalNotice
                                v-else-if="slotsError"
                                tone="warning"
                                :message="slotsError"
                                compact
                            />

                            <div v-else class="space-y-5">
                                <div class="-mx-1 overflow-x-auto pb-1">
                                    <div class="grid min-w-[42rem] grid-cols-7 gap-2 px-1 sm:min-w-0">
                                        <button
                                            v-for="day in weekDays"
                                            :key="day.key"
                                            type="button"
                                            class="min-h-20 rounded-sm border px-2 py-3 text-center transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 motion-reduce:transition-none dark:focus-visible:ring-offset-neutral-900"
                                            :class="selectedDayKey === day.key
                                                ? 'border-emerald-600 bg-emerald-700 text-white shadow-sm'
                                                : 'border-stone-200 bg-white text-stone-700 hover:border-emerald-400 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200'"
                                            :aria-pressed="selectedDayKey === day.key"
                                            :aria-label="$t('reservations.client.book.steps.slot.day_availability', { date: day.ariaLabel, count: day.count })"
                                            @click="selectDay(day)"
                                        >
                                            <span class="block text-xs font-semibold capitalize">{{ day.label }}</span>
                                            <span
                                                class="mt-2 block text-[11px]"
                                                :class="selectedDayKey === day.key
                                                    ? 'text-emerald-50'
                                                    : day.count
                                                        ? 'text-emerald-700 dark:text-emerald-300'
                                                        : 'text-stone-400 dark:text-neutral-500'"
                                            >
                                                {{ day.count
                                                    ? $t('reservations.client.book.steps.slot.available_count', { count: day.count })
                                                    : $t('reservations.client.book.steps.slot.unavailable') }}
                                            </span>
                                        </button>
                                    </div>
                                </div>

                                <ClientPortalNotice
                                    v-if="hasNoSlots"
                                    tone="info"
                                    :message="$t('reservations.client.book.no_availability')"
                                    compact
                                />

                                <div v-else-if="selectedDay" class="border-t border-stone-200 pt-4 dark:border-neutral-700">
                                    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                                        <div>
                                            <h3 class="font-semibold text-stone-900 dark:text-neutral-100">
                                                {{ $t('reservations.client.book.steps.slot.times_for', { date: selectedDayLabel }) }}
                                            </h3>
                                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                {{ $t('reservations.client.book.steps.slot.time_hint') }}
                                            </p>
                                        </div>
                                        <span v-if="selectedDaySlots.length" class="text-xs font-medium text-emerald-700 dark:text-emerald-300">
                                            {{ $t('reservations.client.book.steps.slot.available_count', { count: selectedDaySlots.length }) }}
                                        </span>
                                    </div>

                                    <div v-if="selectedDaySlots.length" class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                        <button
                                            v-for="slot in selectedDaySlots"
                                            :key="`${slot.team_member_id}:${slot.starts_at}:${slot.resource_id || 'none'}`"
                                            type="button"
                                            class="min-h-16 rounded-sm border px-3 py-2 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 motion-reduce:transition-none dark:focus-visible:ring-offset-neutral-900"
                                            :class="selectedSlot?.team_member_id === slot.team_member_id && selectedSlot?.starts_at === slot.starts_at
                                                ? 'border-emerald-600 bg-emerald-50 shadow-sm dark:border-emerald-400 dark:bg-emerald-500/10'
                                                : 'border-stone-200 bg-white hover:border-emerald-400 dark:border-neutral-700 dark:bg-neutral-900'"
                                            :aria-pressed="selectedSlot?.team_member_id === slot.team_member_id && selectedSlot?.starts_at === slot.starts_at"
                                            :aria-label="$t('reservations.client.book.steps.slot.slot_label', { time: formatTime(slot.starts_at), member: slot.team_member_name })"
                                            @click="selectSlot(slot)"
                                        >
                                            <span class="block text-sm font-semibold text-stone-950 dark:text-white">
                                                {{ formatTime(slot.starts_at) }} – {{ formatTime(slot.ends_at) }}
                                            </span>
                                            <span class="mt-1 block truncate text-xs text-stone-500 dark:text-neutral-400">
                                                {{ slot.team_member_name }}<template v-if="slot.resource_name"> · {{ slot.resource_name }}</template>
                                            </span>
                                        </button>
                                    </div>

                                    <ClientPortalNotice
                                        v-else
                                        class="mt-3"
                                        tone="info"
                                        :message="$t('reservations.client.book.steps.slot.no_slots_for_day')"
                                        compact
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="selectedSlot"
                        class="mt-4 flex items-start gap-3 rounded-sm border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100"
                        role="status"
                        aria-live="polite"
                    >
                        <CheckCircle2 class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                        <div>
                            <div class="font-semibold">{{ $t('reservations.client.book.selected_slot') }}</div>
                            <div class="mt-1">{{ selectedSlotLabel }}</div>
                        </div>
                    </div>
                </section>

                <section v-else-if="currentStepKey === 'review'" class="p-4 sm:p-6">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                            {{ $t('reservations.client.book.steps.review.eyebrow') }}
                        </p>
                        <h2 id="client-booking-step-title" class="mt-1 text-xl font-semibold text-stone-950 dark:text-white">
                            {{ $t('reservations.client.book.steps.review.title') }}
                        </h2>
                        <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('reservations.client.book.steps.review.description') }}
                        </p>
                    </div>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-sm border border-stone-200 p-4 dark:border-neutral-700">
                            <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                {{ $t('reservations.client.book.summary.service') }}
                            </div>
                            <div class="mt-1 font-semibold text-stone-950 dark:text-white">{{ selectedServiceLabel }}</div>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-4 dark:border-neutral-700">
                            <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                {{ $t('reservations.client.book.summary.slot') }}
                            </div>
                            <div class="mt-1 text-sm font-semibold text-stone-950 dark:text-white">{{ selectedSlotLabel }}</div>
                        </div>
                    </div>

                    <div class="mt-5 border-t border-stone-200 pt-5 dark:border-neutral-700">
                        <h3 class="font-semibold text-stone-900 dark:text-neutral-100">
                            {{ $t('reservations.client.book.summary.contact') }}
                        </h3>
                        <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('reservations.client.book.summary.contact_hint') }}
                        </p>

                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <FloatingInput
                                    id="client-booking-contact-name"
                                    v-model="bookingForm.contact_name"
                                    :aria-invalid="Boolean(bookingForm.errors.contact_name)"
                                    :aria-describedby="bookingForm.errors.contact_name ? 'client-booking-contact-name-error' : undefined"
                                    :label="$t('reservations.client.book.fields.contact_name')"
                                />
                                <InputError id="client-booking-contact-name-error" class="mt-1" :message="bookingForm.errors.contact_name" />
                            </div>
                            <div>
                                <FloatingInput
                                    id="client-booking-contact-email"
                                    v-model="bookingForm.contact_email"
                                    type="email"
                                    :aria-invalid="Boolean(bookingForm.errors.contact_email)"
                                    :aria-describedby="bookingForm.errors.contact_email ? 'client-booking-contact-email-error' : undefined"
                                    :label="$t('reservations.client.book.fields.contact_email')"
                                />
                                <InputError id="client-booking-contact-email-error" class="mt-1" :message="bookingForm.errors.contact_email" />
                            </div>
                            <div>
                                <FloatingInput
                                    id="client-booking-contact-phone"
                                    v-model="bookingForm.contact_phone"
                                    :aria-invalid="Boolean(bookingForm.errors.contact_phone)"
                                    :aria-describedby="bookingForm.errors.contact_phone ? 'client-booking-contact-phone-error' : undefined"
                                    :label="$t('reservations.client.book.fields.contact_phone')"
                                />
                                <InputError id="client-booking-contact-phone-error" class="mt-1" :message="bookingForm.errors.contact_phone" />
                            </div>
                            <div>
                                <FloatingTextarea
                                    id="client-booking-client-notes"
                                    v-model="bookingForm.client_notes"
                                    :aria-invalid="Boolean(bookingForm.errors.client_notes)"
                                    :aria-describedby="bookingForm.errors.client_notes ? 'client-booking-client-notes-error' : undefined"
                                    :label="$t('reservations.client.book.fields.client_notes')"
                                />
                                <InputError id="client-booking-client-notes-error" class="mt-1" :message="bookingForm.errors.client_notes" />
                            </div>
                        </div>
                        <InputError class="mt-2" :message="bookingForm.errors.team_member_id || bookingForm.errors.starts_at" />
                    </div>

                    <div v-if="hasDepositPolicy || hasNoShowFeePolicy" class="mt-5 grid gap-3 sm:grid-cols-2">
                        <ClientPortalNotice
                            v-if="hasDepositPolicy"
                            tone="warning"
                            :message="$t('reservations.client.book.deposit_notice', { amount: formatMoney(props.settings.deposit_amount) })"
                            compact
                        />
                        <ClientPortalNotice
                            v-if="hasNoShowFeePolicy"
                            tone="warning"
                            :message="$t('reservations.client.book.no_show_notice', { amount: formatMoney(props.settings.no_show_fee_amount) })"
                            compact
                        />
                    </div>
                </section>

                <section v-else class="flex min-h-[32rem] items-center justify-center p-4 sm:p-6" role="status" aria-live="polite">
                    <div class="mx-auto max-w-xl text-center">
                        <div class="mx-auto flex size-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
                            <CheckCircle2 class="size-9" aria-hidden="true" />
                        </div>
                        <h2 id="client-booking-step-title" class="mt-5 text-2xl font-semibold text-stone-950 dark:text-white">
                            {{ $t('reservations.client.book.success.title') }}
                        </h2>
                        <p class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                            {{ successMessage || successDescription }}
                        </p>
                        <div class="mt-5 rounded-sm border border-stone-200 bg-stone-50 p-4 text-left text-sm dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="font-semibold text-stone-950 dark:text-white">
                                {{ completedReservation?.service?.name || selectedServiceLabel }}
                            </div>
                            <div class="mt-1 text-stone-600 dark:text-neutral-300">{{ completedReservationLabel }}</div>
                        </div>
                        <div class="mt-5 flex flex-col justify-center gap-2 sm:flex-row">
                            <Link :href="followUpUrl" class="inline-flex min-h-11 items-center justify-center rounded-sm bg-stone-900 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-800 dark:bg-white dark:text-neutral-900">
                                {{ followUpLabel }}
                            </Link>
                            <button type="button" class="inline-flex min-h-11 items-center justify-center rounded-sm border border-stone-200 px-4 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="resetBooking">
                                {{ $t('reservations.client.book.actions.start_over') }}
                            </button>
                        </div>
                    </div>
                </section>

                <div v-if="currentStepKey !== 'done'" class="border-t border-stone-200 px-4 py-4 sm:px-6 dark:border-neutral-700">
                    <ClientPortalNotice v-if="stepError" class="mb-3" tone="error" :message="stepError" compact />
                    <ClientPortalNotice v-if="submitError" class="mb-3" tone="error" :message="submitError" compact />
                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <button
                            type="button"
                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-sm border border-stone-200 px-4 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            :disabled="currentStep === 0 || submitting"
                            @click="backStep"
                        >
                            <ArrowLeft class="size-4" aria-hidden="true" />
                            {{ $t('reservations.client.book.actions.back') }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-sm bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="submitting || slotsLoading || (currentStepKey === 'review' && !canSubmit)"
                            :aria-busy="submitting || slotsLoading"
                            @click="continueBookingStep"
                        >
                            <Loader2 v-if="submitting || slotsLoading" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                            <CheckCircle2 v-else-if="currentStepKey === 'review'" class="size-4" aria-hidden="true" />
                            <ArrowRight v-else class="size-4" aria-hidden="true" />
                            {{ currentStepKey === 'review'
                                ? $t('reservations.client.book.actions.submit')
                                : $t('reservations.client.book.actions.continue') }}
                        </button>
                    </div>
                </div>
            </section>

            <aside v-if="!isBookingComplete" class="h-fit rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 lg:sticky lg:top-5">
                <h2 class="text-sm font-semibold text-stone-950 dark:text-white">
                    {{ $t('reservations.client.book.summary_title') }}
                </h2>
                <div class="mt-4 space-y-4 text-sm">
                    <div class="flex items-start gap-3">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-sm bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                            <Sparkles class="size-4" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <div class="text-xs font-semibold uppercase tracking-wide text-stone-400">{{ $t('reservations.client.book.summary.service') }}</div>
                            <div class="break-words font-medium text-stone-900 dark:text-neutral-100">{{ selectedServiceLabel }}</div>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-sm bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">
                            <Clock3 class="size-4" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <div class="text-xs font-semibold uppercase tracking-wide text-stone-400">{{ $t('reservations.client.book.summary.slot') }}</div>
                            <div class="break-words font-medium text-stone-900 dark:text-neutral-100">{{ selectedSlotLabel }}</div>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-sm bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                            <UsersRound class="size-4" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <div class="text-xs font-semibold uppercase tracking-wide text-stone-400">{{ $t('reservations.client.book.summary.party_size') }}</div>
                            <div class="font-medium text-stone-900 dark:text-neutral-100">{{ bookingForm.party_size || $t('reservations.client.book.summary.not_provided') }}</div>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-sm bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                            <UserRound class="size-4" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <div class="text-xs font-semibold uppercase tracking-wide text-stone-400">{{ $t('reservations.client.book.summary.contact') }}</div>
                            <div class="break-words font-medium text-stone-900 dark:text-neutral-100">{{ selectedContactLabel }}</div>
                            <div v-if="bookingForm.contact_email" class="break-all text-xs text-stone-500 dark:text-neutral-400">{{ bookingForm.contact_email }}</div>
                        </div>
                    </div>
                </div>
            </aside>
        </div>

        <section
            v-if="waitlistEnabled && (hasNoSlots || ownerOnlyMode || !slotBookingAvailable) && (currentStepKey === 'slot' || !slotBookingAvailable)"
            class="rounded-sm border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100"
        >
            <h2 class="font-semibold">{{ $t('reservations.client.book.waitlist.title') }}</h2>
            <p class="mt-1 text-xs">{{ $t('reservations.client.book.waitlist.description') }}</p>
            <button
                type="button"
                class="mt-3 inline-flex min-h-11 items-center rounded-sm border border-amber-300 bg-white px-3 py-2 text-xs font-semibold text-amber-800 dark:border-amber-300/40 dark:bg-transparent dark:text-amber-100"
                aria-controls="client-booking-waitlist-form"
                :aria-expanded="showWaitlistForm"
                @click="showWaitlistForm = !showWaitlistForm"
            >
                {{ showWaitlistForm ? $t('quotes.form.cancel') : $t('reservations.client.book.waitlist.join_button') }}
            </button>

            <form id="client-booking-waitlist-form" v-if="showWaitlistForm" class="mt-3 grid gap-3 sm:grid-cols-2" :aria-busy="waitlistSubmitting" @submit.prevent="submitWaitlist">
                <div>
                    <FloatingInput
                        id="client-booking-waitlist-party-size"
                        v-model="waitlistForm.party_size"
                        type="number"
                        min="1"
                        max="500"
                        :aria-invalid="Boolean(waitlistForm.errors.party_size)"
                        :aria-describedby="waitlistForm.errors.party_size ? 'client-booking-waitlist-party-size-error' : undefined"
                        :label="$t('reservations.client.book.fields.party_size')"
                    />
                    <InputError id="client-booking-waitlist-party-size-error" class="mt-1" :message="waitlistForm.errors.party_size" />
                </div>
                <div class="sm:col-span-2">
                    <FloatingTextarea
                        id="client-booking-waitlist-notes"
                        v-model="waitlistForm.notes"
                        :aria-invalid="Boolean(waitlistForm.errors.notes)"
                        :aria-describedby="waitlistForm.errors.notes ? 'client-booking-waitlist-notes-error' : undefined"
                        :label="$t('reservations.client.book.waitlist.notes')"
                    />
                    <InputError id="client-booking-waitlist-notes-error" class="mt-1" :message="waitlistForm.errors.notes" />
                </div>
                <div class="sm:col-span-2">
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-sm bg-amber-700 px-4 py-2 text-xs font-semibold text-white disabled:opacity-50" :disabled="waitlistSubmitting">
                        {{ waitlistSubmitting ? $t('reservations.client.book.actions.submitting') : $t('reservations.client.book.waitlist.join_button') }}
                    </button>
                </div>
            </form>

            <ClientPortalNotice v-if="waitlistError" class="mt-3" tone="error" :message="waitlistError" compact />
            <ClientPortalNotice v-if="waitlistSuccess" class="mt-3" tone="success" :message="waitlistSuccess" compact />
        </section>
    </div>
</template>
