<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
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
    Mail,
    Phone,
    Sparkles,
    UserRound,
} from 'lucide-vue-next';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PublicChatWidget from '@/Components/AiAssistant/PublicChatWidget.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    company: {
        type: Object,
        required: true,
    },
    link: {
        type: Object,
        required: true,
    },
    services: {
        type: Array,
        default: () => [],
    },
    settings: {
        type: Object,
        default: () => ({}),
    },
    endpoints: {
        type: Object,
        required: true,
    },
    ai_assistant: {
        type: Object,
        default: () => ({
            enabled: false,
            endpoints: {},
        }),
    },
});

const steps = [
    { key: 'service', label: 'Service', short: 'Service' },
    { key: 'date', label: 'Date', short: 'Date' },
    { key: 'time', label: 'Horaire', short: 'Heure' },
    { key: 'person', label: 'Personne', short: 'Equipe' },
    { key: 'contact', label: 'Coordonnees', short: 'Contact' },
    { key: 'review', label: 'Resume', short: 'Resume' },
    { key: 'done', label: 'Confirmation', short: 'OK' },
];

const currentStep = ref(0);
const maxVisitedStep = ref(0);
const selectedServiceId = ref(props.services?.[0]?.id ? String(props.services[0].id) : '');
const serviceWasChosen = ref(false);
const calendarMonth = ref(dayjs().startOf('month'));
const selectedDate = ref('');
const selectedTime = ref('');
const selectedTeamMemberId = ref('auto');
const monthAvailableDates = ref([]);
const daySlots = ref([]);
const monthLoading = ref(false);
const dayLoading = ref(false);
const slotsError = ref('');
const stepError = ref('');
const submitError = ref('');
const successPayload = ref(null);
const submitting = ref(false);
const resolvedDurationMinutes = ref(60);

const form = useForm({
    first_name: '',
    last_name: '',
    phone: '',
    email: '',
    message: '',
    website: '',
});

const timezone = computed(() => props.settings?.timezone || 'UTC');
const selectedService = computed(() => (props.services || []).find((service) => String(service.id) === selectedServiceId.value));
const durationMinutes = computed(() => Number(selectedService.value?.duration_minutes || resolvedDurationMinutes.value || 60));
const availableDateSet = computed(() => new Set(monthAvailableDates.value));
const selectedSlotCandidates = computed(() => daySlots.value.filter((slot) => String(slot.starts_at) === selectedTime.value));
const filteredDaySlots = computed(() => {
    if (selectedTeamMemberId.value === 'auto') {
        return daySlots.value;
    }

    return daySlots.value.filter((slot) => Number(slot.team_member_id) === Number(selectedTeamMemberId.value));
});
const selectedSlot = computed(() => {
    const candidates = selectedSlotCandidates.value;
    if (!selectedTime.value || candidates.length === 0) {
        return null;
    }

    if (selectedTeamMemberId.value === 'auto') {
        return [...candidates].sort((left, right) => String(left.team_member_name).localeCompare(String(right.team_member_name)))[0] || null;
    }

    return candidates.find((slot) => Number(slot.team_member_id) === Number(selectedTeamMemberId.value)) || null;
});
const selectedPersonLabel = computed(() => {
    if (!selectedTime.value) {
        return '-';
    }

    if (selectedTeamMemberId.value === 'auto') {
        return 'Assignation automatique';
    }

    return selectedSlot.value?.team_member_name || '-';
});
const canContinueFromContact = computed(() => (
    form.first_name.trim() !== ''
    && form.last_name.trim() !== ''
    && form.phone.trim() !== ''
    && /\S+@\S+\.\S+/.test(form.email.trim())
));
const reviewReady = computed(() => Boolean(
    selectedService.value
    && selectedDate.value
    && selectedTime.value
    && selectedSlot.value
    && canContinueFromContact.value
));
const currentStepKey = computed(() => steps[currentStep.value]?.key || 'service');
const visibleMonthLabel = computed(() => formatMonthLabel(calendarMonth.value));
const confirmationDateLabel = computed(() => {
    const startsAt = successPayload.value?.reservation?.starts_at;

    return startsAt ? formatDateTimeFromIso(startsAt).date : formatDateLong(selectedDate.value);
});
const confirmationTimeLabel = computed(() => {
    const startsAt = successPayload.value?.reservation?.starts_at;

    return startsAt ? formatDateTimeFromIso(startsAt).time : (selectedSlot.value?.time || '-');
});
const aiAssistant = computed(() => props.ai_assistant || {});
const aiAssistantEnabled = computed(() => Boolean(
    aiAssistant.value.enabled
    && aiAssistant.value.company_slug
    && aiAssistant.value.endpoints?.create
    && aiAssistant.value.endpoints?.message
));
const aiVisitorName = computed(() => [form.first_name, form.last_name]
    .map((part) => String(part || '').trim())
    .filter(Boolean)
    .join(' '));
const aiSelectedService = computed(() => (serviceWasChosen.value || maxVisitedStep.value > 0) ? selectedService.value : null);
const aiReservationContext = computed(() => ({
    source: 'public_booking_link',
    booking_link_id: props.link.id,
    booking_link_slug: props.link.slug,
    booking_link_name: props.link.name,
    selected_service_id: aiSelectedService.value?.id || null,
    selected_service_name: aiSelectedService.value?.name || null,
    selected_date: selectedDate.value || null,
    selected_time: selectedSlot.value?.starts_at || selectedTime.value || null,
    selected_team_member_id: selectedTeamMemberId.value,
}));

const weekDays = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
const today = dayjs().format('YYYY-MM-DD');

const calendarDays = computed(() => {
    const monthStart = calendarMonth.value.startOf('month');
    const offset = (monthStart.day() + 6) % 7;
    const gridStart = monthStart.subtract(offset, 'day');

    return Array.from({ length: 42 }, (_, index) => {
        const day = gridStart.add(index, 'day');
        const dateKey = day.format('YYYY-MM-DD');
        const inCurrentMonth = day.month() === calendarMonth.value.month();
        const available = availableDateSet.value.has(dateKey);
        const isPast = dateKey < today;

        return {
            key: dateKey,
            label: day.format('D'),
            date: day,
            inCurrentMonth,
            available,
            disabled: monthLoading.value || !inCurrentMonth || isPast || !available,
            selected: selectedDate.value === dateKey,
            isToday: dateKey === today,
        };
    });
});

const timeOptions = computed(() => {
    const grouped = new Map();

    filteredDaySlots.value.forEach((slot) => {
        const key = String(slot.starts_at);
        if (!grouped.has(key)) {
            grouped.set(key, {
                key,
                starts_at: slot.starts_at,
                ends_at: slot.ends_at,
                date: slot.date,
                time: slot.time,
                slots: [],
            });
        }
        grouped.get(key).slots.push(slot);
    });

    return Array.from(grouped.values())
        .map((item) => ({
            ...item,
            available_people: item.slots.length,
        }))
        .sort((left, right) => String(left.starts_at).localeCompare(String(right.starts_at)));
});

const availablePeopleForSelectedTime = computed(() => {
    const slots = selectedTime.value ? selectedSlotCandidates.value : daySlots.value;
    const people = new Map();

    slots.forEach((slot) => {
        people.set(Number(slot.team_member_id), {
            id: Number(slot.team_member_id),
            name: slot.team_member_name || 'Membre disponible',
            first_available_at: slot.starts_at,
        });
    });

    return Array.from(people.values()).sort((left, right) => left.name.localeCompare(right.name));
});

const firstValidationMessage = (errors) => {
    if (!errors || typeof errors !== 'object') {
        return '';
    }

    for (const value of Object.values(errors)) {
        if (Array.isArray(value) && value[0]) {
            return value[0];
        }
        if (typeof value === 'string' && value) {
            return value;
        }
    }

    return '';
};

const formatMonthLabel = (value) => new Intl.DateTimeFormat('fr-CA', {
    month: 'long',
    year: 'numeric',
}).format(value.toDate());

const formatDateLong = (value) => {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('fr-CA', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(new Date(`${value}T12:00:00`));
};

const formatDateTimeFromIso = (value) => {
    if (!value) {
        return {
            date: '-',
            time: '-',
        };
    }

    const date = new Date(value);

    return {
        date: new Intl.DateTimeFormat('fr-CA', {
            timeZone: timezone.value,
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        }).format(date),
        time: new Intl.DateTimeFormat('fr-CA', {
            timeZone: timezone.value,
            hour: '2-digit',
            minute: '2-digit',
        }).format(date),
    };
};

const formatMoney = (value, currency = 'CAD') => {
    const amount = Number(value || 0);
    if (amount <= 0) {
        return '';
    }

    return amount.toLocaleString(undefined, {
        style: 'currency',
        currency: currency || 'CAD',
    });
};

const durationLabel = (minutes) => {
    const value = Number(minutes || 0);
    if (value <= 0) {
        return '-';
    }

    const hours = Math.floor(value / 60);
    const remainder = value % 60;
    if (hours > 0 && remainder > 0) {
        return `${hours} h ${remainder} min`;
    }
    if (hours > 0) {
        return `${hours} h`;
    }

    return `${value} min`;
};

const goToStep = (index) => {
    if (index < 0 || index >= steps.length || index > maxVisitedStep.value || successPayload.value) {
        return;
    }

    currentStep.value = index;
    stepError.value = '';
};

const setCurrentStep = (index) => {
    currentStep.value = Math.max(0, Math.min(index, steps.length - 1));
    maxVisitedStep.value = Math.max(maxVisitedStep.value, currentStep.value);
    stepError.value = '';
};

const selectService = (service) => {
    selectedServiceId.value = String(service.id);
    serviceWasChosen.value = true;
    selectedDate.value = '';
    selectedTime.value = '';
    selectedTeamMemberId.value = 'auto';
    daySlots.value = [];
    monthAvailableDates.value = [];
    form.clearErrors();
    stepError.value = '';
    submitError.value = '';
};

const selectDate = (day) => {
    if (day.disabled) {
        return;
    }

    selectedDate.value = day.key;
    selectedTime.value = '';
    selectedTeamMemberId.value = 'auto';
    stepError.value = '';
};

const selectTime = (option) => {
    selectedTime.value = option.starts_at;
    if (selectedTeamMemberId.value !== 'auto' && !option.slots.some((slot) => Number(slot.team_member_id) === Number(selectedTeamMemberId.value))) {
        selectedTeamMemberId.value = 'auto';
    }
    stepError.value = '';
    submitError.value = '';
};

const selectPerson = (personId) => {
    selectedTeamMemberId.value = personId === 'auto' ? 'auto' : String(personId);
    stepError.value = '';
};

const previousMonth = () => {
    calendarMonth.value = calendarMonth.value.subtract(1, 'month').startOf('month');
};

const nextMonth = () => {
    calendarMonth.value = calendarMonth.value.add(1, 'month').startOf('month');
};

let monthRequestId = 0;
const loadMonthAvailability = async () => {
    if (!selectedServiceId.value) {
        monthAvailableDates.value = [];
        return;
    }

    const requestId = ++monthRequestId;
    monthLoading.value = true;
    slotsError.value = '';

    try {
        const monthStart = calendarMonth.value.startOf('month').startOf('day');
        const monthEnd = calendarMonth.value.endOf('month').endOf('day');
        const response = await axios.get(props.endpoints.slots, {
            params: {
                service_id: Number(selectedServiceId.value),
                range_start: monthStart.toISOString(),
                range_end: monthEnd.toISOString(),
            },
            headers: {
                Accept: 'application/json',
            },
        });

        if (requestId !== monthRequestId) {
            return;
        }

        monthAvailableDates.value = response?.data?.available_dates || [];
        resolvedDurationMinutes.value = Number(response?.data?.duration_minutes || resolvedDurationMinutes.value || 60);
    } catch (error) {
        if (requestId === monthRequestId) {
            monthAvailableDates.value = [];
            slotsError.value = error?.response?.data?.message || 'Impossible de charger les disponibilites.';
        }
    } finally {
        if (requestId === monthRequestId) {
            monthLoading.value = false;
        }
    }
};

let dayRequestId = 0;
const loadDaySlots = async () => {
    if (!selectedServiceId.value || !selectedDate.value) {
        daySlots.value = [];
        selectedTime.value = '';
        return;
    }

    const requestId = ++dayRequestId;
    dayLoading.value = true;
    slotsError.value = '';

    try {
        const date = dayjs(selectedDate.value);
        const response = await axios.get(props.endpoints.slots, {
            params: {
                service_id: Number(selectedServiceId.value),
                range_start: date.startOf('day').toISOString(),
                range_end: date.endOf('day').toISOString(),
            },
            headers: {
                Accept: 'application/json',
            },
        });

        if (requestId !== dayRequestId) {
            return;
        }

        daySlots.value = response?.data?.slots || [];
        resolvedDurationMinutes.value = Number(response?.data?.duration_minutes || resolvedDurationMinutes.value || 60);
        if (selectedTime.value && !daySlots.value.some((slot) => String(slot.starts_at) === selectedTime.value)) {
            selectedTime.value = '';
            selectedTeamMemberId.value = 'auto';
        }
    } catch (error) {
        if (requestId === dayRequestId) {
            daySlots.value = [];
            selectedTime.value = '';
            selectedTeamMemberId.value = 'auto';
            slotsError.value = error?.response?.data?.message || 'Impossible de charger les creneaux disponibles.';
        }
    } finally {
        if (requestId === dayRequestId) {
            dayLoading.value = false;
        }
    }
};

const continueStep = async () => {
    stepError.value = '';
    submitError.value = '';

    if (currentStepKey.value === 'service') {
        if (!selectedService.value) {
            stepError.value = 'Selectionnez un service pour continuer.';
            return;
        }
        serviceWasChosen.value = true;
        setCurrentStep(1);
        return;
    }

    if (currentStepKey.value === 'date') {
        if (!selectedDate.value) {
            stepError.value = 'Choisissez une date disponible.';
            return;
        }
        await loadDaySlots();
        setCurrentStep(2);
        return;
    }

    if (currentStepKey.value === 'time') {
        if (!selectedTime.value || !selectedSlot.value) {
            stepError.value = 'Choisissez un creneau disponible.';
            return;
        }
        setCurrentStep(3);
        return;
    }

    if (currentStepKey.value === 'person') {
        if (!selectedSlot.value) {
            stepError.value = 'Cette personne n est pas disponible sur le creneau choisi.';
            return;
        }
        setCurrentStep(4);
        return;
    }

    if (currentStepKey.value === 'contact') {
        if (!canContinueFromContact.value) {
            stepError.value = 'Renseignez votre nom, telephone et adresse courriel.';
            return;
        }
        setCurrentStep(5);
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

    setCurrentStep(currentStep.value - 1);
};

const submitBooking = async () => {
    submitError.value = '';
    stepError.value = '';
    form.clearErrors();

    if (!reviewReady.value) {
        submitError.value = 'La reservation est incomplete. Verifiez les etapes precedentes.';
        return;
    }

    submitting.value = true;

    try {
        const slot = selectedSlot.value;
        const response = await axios.post(props.endpoints.store, {
            assignment_mode: selectedTeamMemberId.value === 'auto' ? 'auto' : 'specific',
            service_id: Number(selectedServiceId.value),
            team_member_id: selectedTeamMemberId.value === 'auto' ? null : Number(selectedTeamMemberId.value),
            starts_at: selectedTime.value,
            ends_at: slot.ends_at,
            timezone: timezone.value,
            duration_minutes: durationMinutes.value,
            resource_ids: slot.resource_id ? [Number(slot.resource_id)] : [],
            first_name: form.first_name,
            last_name: form.last_name,
            phone: form.phone,
            email: form.email,
            message: form.message || null,
            website: form.website || undefined,
        }, {
            headers: {
                Accept: 'application/json',
            },
        });

        successPayload.value = response?.data || {};
        setCurrentStep(6);
        await loadDaySlots();
        await loadMonthAvailability();
    } catch (error) {
        if (error?.response?.status === 422) {
            form.setError(error.response.data?.errors || {});
            submitError.value = firstValidationMessage(error.response.data?.errors || {}) || 'Certains champs doivent etre verifies.';
            if (error.response.data?.errors?.starts_at || error.response.data?.errors?.team_member_id) {
                await loadDaySlots();
                setCurrentStep(2);
            }
        } else {
            submitError.value = error?.response?.data?.message || 'Impossible de soumettre cette reservation pour le moment.';
        }
    } finally {
        submitting.value = false;
    }
};

const resetBooking = () => {
    selectedDate.value = '';
    selectedTime.value = '';
    selectedTeamMemberId.value = 'auto';
    daySlots.value = [];
    successPayload.value = null;
    submitError.value = '';
    stepError.value = '';
    form.reset('first_name', 'last_name', 'phone', 'email', 'message', 'website');
    form.clearErrors();
    currentStep.value = 0;
    maxVisitedStep.value = 0;
    serviceWasChosen.value = false;
    loadMonthAvailability();
};

watch(
    () => [selectedServiceId.value, calendarMonth.value.format('YYYY-MM')],
    () => {
        loadMonthAvailability();
    },
    { immediate: true }
);

watch(
    () => [selectedServiceId.value, selectedDate.value],
    () => {
        loadDaySlots();
    }
);

watch(
    () => [selectedTeamMemberId.value, daySlots.value.length],
    () => {
        if (
            selectedTime.value
            && !timeOptions.value.some((option) => option.starts_at === selectedTime.value)
        ) {
            selectedTime.value = '';
        }
    }
);
</script>

<template>
    <GuestLayout :show-platform-logo="false" card-class="w-full">
        <Head :title="link.name" />

        <div class="min-h-screen bg-stone-50 text-stone-900">
            <div class="mx-auto flex w-full max-w-7xl flex-col gap-5 px-4 py-5 sm:px-6 lg:px-8">
                <header class="flex flex-wrap items-center justify-between gap-4 rounded-sm border border-stone-200 bg-white px-4 py-3 shadow-sm">
                    <div class="flex min-w-0 items-center gap-3">
                        <img
                            v-if="company.logo_url"
                            :src="company.logo_url"
                            :alt="company.name"
                            class="size-12 rounded-sm object-cover"
                        >
                        <div v-else class="flex size-12 items-center justify-center rounded-sm bg-emerald-700 text-base font-semibold text-white">
                            {{ String(company.name || 'M').slice(0, 1) }}
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-xs font-semibold uppercase tracking-wide text-emerald-700">{{ company.name }}</p>
                            <h1 class="truncate text-xl font-semibold text-stone-950 sm:text-2xl">{{ link.name }}</h1>
                            <p v-if="link.description" class="mt-1 line-clamp-2 max-w-3xl text-sm text-stone-500">{{ link.description }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-xs text-stone-500">
                        <span v-if="company.phone" class="inline-flex items-center gap-1 rounded-full bg-stone-100 px-3 py-1">
                            <Phone class="size-3.5" />
                            {{ company.phone }}
                        </span>
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-3 py-1 text-emerald-700">
                            <CalendarDays class="size-3.5" />
                            {{ timezone }}
                        </span>
                    </div>
                </header>

                <nav class="overflow-x-auto rounded-sm border border-stone-200 bg-white p-2 shadow-sm" aria-label="Etapes de reservation">
                    <ol class="flex min-w-max items-center gap-2">
                        <li
                            v-for="(step, index) in steps"
                            :key="step.key"
                            class="flex items-center gap-2"
                        >
                            <button
                                type="button"
                                class="group flex items-center gap-2 rounded-sm px-2.5 py-2 text-left text-xs font-semibold transition"
                                :class="index === currentStep
                                    ? 'bg-emerald-700 text-white shadow-sm'
                                    : index <= maxVisitedStep
                                        ? 'text-stone-700 hover:bg-stone-100'
                                        : 'cursor-default text-stone-400'"
                                :disabled="index > maxVisitedStep || Boolean(successPayload)"
                                @click="goToStep(index)"
                            >
                                <span
                                    class="flex size-6 items-center justify-center rounded-full text-[11px]"
                                    :class="index === currentStep
                                        ? 'bg-white text-emerald-700'
                                        : index < maxVisitedStep || successPayload
                                            ? 'bg-emerald-100 text-emerald-700'
                                            : 'bg-stone-100 text-stone-400'"
                                >
                                    <CheckCircle2 v-if="index < currentStep || (successPayload && index < steps.length - 1)" class="size-3.5" />
                                    <span v-else>{{ index + 1 }}</span>
                                </span>
                                <span class="hidden sm:inline">{{ step.label }}</span>
                                <span class="sm:hidden">{{ step.short }}</span>
                            </button>
                            <span v-if="index < steps.length - 1" class="h-px w-5 bg-stone-200" />
                        </li>
                    </ol>
                </nav>

                <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
                    <main class="min-h-[560px] rounded-sm border border-stone-200 bg-white shadow-sm">
                        <section v-if="currentStepKey === 'service'" class="p-4 sm:p-6">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Etape 1</p>
                                    <h2 class="mt-1 text-xl font-semibold text-stone-950">Choisissez le service</h2>
                                </div>
                                <Sparkles class="size-5 text-amber-500" />
                            </div>

                            <div class="mt-5 grid gap-3 md:grid-cols-2">
                                <button
                                    v-for="service in services"
                                    :key="`public-service-${service.id}`"
                                    type="button"
                                    class="rounded-sm border p-4 text-left transition hover:border-emerald-500 hover:bg-emerald-50/50"
                                    :class="String(service.id) === selectedServiceId
                                        ? 'border-emerald-600 bg-emerald-50 shadow-sm'
                                        : 'border-stone-200 bg-white'"
                                    @click="selectService(service)"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <h3 class="text-base font-semibold text-stone-950">{{ service.name }}</h3>
                                            <p class="mt-1 line-clamp-3 text-sm text-stone-500">{{ service.description || 'Service disponible sur reservation.' }}</p>
                                        </div>
                                        <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-emerald-700 shadow-sm">
                                            {{ durationLabel(service.duration_minutes || durationMinutes) }}
                                        </span>
                                    </div>
                                    <div class="mt-4 flex flex-wrap items-center gap-2 text-xs text-stone-500">
                                        <span class="inline-flex items-center gap-1 rounded-full bg-stone-100 px-2.5 py-1">
                                            <Clock3 class="size-3.5" />
                                            {{ durationLabel(service.duration_minutes || durationMinutes) }}
                                        </span>
                                        <span v-if="formatMoney(service.price, service.currency_code)" class="rounded-full bg-stone-100 px-2.5 py-1 font-semibold text-stone-700">
                                            {{ formatMoney(service.price, service.currency_code) }}
                                        </span>
                                    </div>
                                </button>
                            </div>

                            <div v-if="!services.length" class="mt-5 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-8 text-center text-sm text-stone-500">
                                Aucun service n est disponible avec ce lien de reservation.
                            </div>
                        </section>

                        <section v-else-if="currentStepKey === 'date'" class="p-4 sm:p-6">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Etape 2</p>
                                    <h2 class="mt-1 text-xl font-semibold text-stone-950">Choisissez une date disponible</h2>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" class="rounded-sm border border-stone-200 p-2 text-stone-600 hover:bg-stone-50" aria-label="Mois precedent" @click="previousMonth">
                                        <ChevronLeft class="size-4" />
                                    </button>
                                    <div class="min-w-40 text-center text-sm font-semibold capitalize text-stone-800">{{ visibleMonthLabel }}</div>
                                    <button type="button" class="rounded-sm border border-stone-200 p-2 text-stone-600 hover:bg-stone-50" aria-label="Mois suivant" @click="nextMonth">
                                        <ChevronRight class="size-4" />
                                    </button>
                                </div>
                            </div>

                            <div class="mt-5 rounded-sm border border-stone-200">
                                <div class="grid grid-cols-7 border-b border-stone-200 bg-stone-50">
                                    <div v-for="day in weekDays" :key="day" class="px-2 py-3 text-center text-xs font-semibold text-stone-500">{{ day }}</div>
                                </div>
                                <div class="grid grid-cols-7">
                                    <button
                                        v-for="day in calendarDays"
                                        :key="day.key"
                                        type="button"
                                        class="relative min-h-20 border-b border-r border-stone-100 p-2 text-left transition last:border-r-0"
                                        :class="[
                                            day.selected ? 'bg-emerald-700 text-white' : '',
                                            !day.selected && day.available && !day.disabled ? 'bg-white hover:bg-emerald-50' : '',
                                            !day.selected && day.disabled ? 'cursor-not-allowed bg-stone-50 text-stone-300' : 'text-stone-800',
                                            !day.inCurrentMonth ? 'opacity-40' : '',
                                        ]"
                                        :aria-label="`${formatDateLong(day.key)} - ${day.available && !day.disabled ? 'disponible' : 'indisponible'}`"
                                        :aria-pressed="day.selected"
                                        :disabled="day.disabled"
                                        @click="selectDate(day)"
                                    >
                                        <span class="text-sm font-semibold">{{ day.label }}</span>
                                        <span
                                            v-if="day.available && day.inCurrentMonth && !day.disabled"
                                            class="absolute bottom-2 left-2 h-1.5 w-8 rounded-full"
                                            :class="day.selected ? 'bg-white' : 'bg-emerald-500'"
                                        />
                                        <span v-if="day.isToday" class="absolute right-2 top-2 size-1.5 rounded-full" :class="day.selected ? 'bg-white' : 'bg-amber-500'" />
                                    </button>
                                </div>
                            </div>

                            <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-stone-500">
                                <span class="inline-flex items-center gap-1"><span class="h-1.5 w-6 rounded-full bg-emerald-500" /> Disponible</span>
                                <span class="inline-flex items-center gap-1"><span class="h-1.5 w-6 rounded-full bg-stone-300" /> Indisponible</span>
                                <span v-if="monthLoading" class="inline-flex items-center gap-1 text-emerald-700"><Loader2 class="size-3.5 animate-spin" /> Chargement</span>
                            </div>
                        </section>

                        <section v-else-if="currentStepKey === 'time'" class="p-4 sm:p-6">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Etape 3</p>
                                    <h2 class="mt-1 text-xl font-semibold text-stone-950">Choisissez un creneau</h2>
                                    <p class="mt-1 text-sm text-stone-500">{{ formatDateLong(selectedDate) }}</p>
                                </div>
                                <span class="rounded-full bg-stone-100 px-3 py-1 text-xs font-semibold text-stone-600">{{ durationLabel(durationMinutes) }}</span>
                            </div>

                            <div v-if="dayLoading" class="mt-8 flex items-center justify-center gap-2 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-10 text-sm text-stone-500">
                                <Loader2 class="size-4 animate-spin text-emerald-700" />
                                Chargement des creneaux...
                            </div>

                            <div v-else-if="!timeOptions.length" class="mt-5 rounded-sm border border-dashed border-amber-300 bg-amber-50 px-4 py-8 text-center text-sm text-amber-800">
                                Aucun creneau n est disponible pour cette date. Choisissez une autre date ou revenez a l etape precedente.
                            </div>

                            <div v-else class="mt-5 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                <button
                                    v-for="option in timeOptions"
                                    :key="`time-${option.starts_at}`"
                                    type="button"
                                    class="rounded-sm border px-3 py-3 text-left transition"
                                    :class="selectedTime === option.starts_at
                                        ? 'border-emerald-600 bg-emerald-50 text-emerald-900 shadow-sm'
                                        : 'border-stone-200 bg-white text-stone-700 hover:border-emerald-400'"
                                    @click="selectTime(option)"
                                >
                                    <span class="block text-base font-semibold">{{ option.time }}</span>
                                    <span class="mt-1 block text-xs text-stone-500">{{ option.available_people }} personne{{ option.available_people > 1 ? 's' : '' }} disponible{{ option.available_people > 1 ? 's' : '' }}</span>
                                </button>
                            </div>
                        </section>

                        <section v-else-if="currentStepKey === 'person'" class="p-4 sm:p-6">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Etape 4</p>
                                <h2 class="mt-1 text-xl font-semibold text-stone-950">Choisissez la personne</h2>
                                <p class="mt-1 text-sm text-stone-500">{{ formatDateLong(selectedDate) }} a {{ selectedSlot?.time || '-' }}</p>
                            </div>

                            <div class="mt-5 grid gap-3 md:grid-cols-2">
                                <button
                                    type="button"
                                    class="rounded-sm border p-4 text-left transition hover:border-emerald-500"
                                    :class="selectedTeamMemberId === 'auto' ? 'border-emerald-600 bg-emerald-50 shadow-sm' : 'border-stone-200 bg-white'"
                                    @click="selectPerson('auto')"
                                >
                                    <div class="flex items-center gap-3">
                                        <span class="flex size-10 items-center justify-center rounded-sm bg-emerald-700 text-white">
                                            <Sparkles class="size-5" />
                                        </span>
                                        <div>
                                            <div class="font-semibold text-stone-950">Aucune preference</div>
                                            <div class="text-sm text-stone-500">Choisir automatiquement une personne disponible</div>
                                        </div>
                                    </div>
                                </button>

                                <button
                                    v-for="person in availablePeopleForSelectedTime"
                                    :key="`person-${person.id}`"
                                    type="button"
                                    class="rounded-sm border p-4 text-left transition hover:border-emerald-500"
                                    :class="String(person.id) === selectedTeamMemberId ? 'border-emerald-600 bg-emerald-50 shadow-sm' : 'border-stone-200 bg-white'"
                                    @click="selectPerson(person.id)"
                                >
                                    <div class="flex items-center gap-3">
                                        <span class="flex size-10 items-center justify-center rounded-sm bg-stone-100 text-stone-600">
                                            <UserRound class="size-5" />
                                        </span>
                                        <div>
                                            <div class="font-semibold text-stone-950">{{ person.name }}</div>
                                            <div class="text-sm text-stone-500">Disponible sur ce creneau</div>
                                        </div>
                                    </div>
                                </button>
                            </div>
                        </section>

                        <section v-else-if="currentStepKey === 'contact'" class="p-4 sm:p-6">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Etape 5</p>
                                <h2 class="mt-1 text-xl font-semibold text-stone-950">Vos informations</h2>
                            </div>

                            <div class="mt-5 space-y-4">
                                <input v-model="form.website" class="hidden" type="text" tabindex="-1" autocomplete="off">
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <FloatingInput v-model="form.first_name" label="Prenom" />
                                        <InputError class="mt-1" :message="form.errors.first_name" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="form.last_name" label="Nom" />
                                        <InputError class="mt-1" :message="form.errors.last_name" />
                                    </div>
                                </div>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <FloatingInput v-model="form.phone" label="Telephone" />
                                        <InputError class="mt-1" :message="form.errors.phone" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="form.email" type="email" label="Adresse courriel" />
                                        <InputError class="mt-1" :message="form.errors.email" />
                                    </div>
                                </div>
                                <FloatingTextarea v-model="form.message" label="Message ou note optionnelle" />
                            </div>
                        </section>

                        <section v-else-if="currentStepKey === 'review'" class="p-4 sm:p-6">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Etape 6</p>
                                <h2 class="mt-1 text-xl font-semibold text-stone-950">Verifiez votre reservation</h2>
                            </div>

                            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                                <div class="rounded-sm border border-stone-200 p-4">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-stone-500">Service</div>
                                    <div class="mt-1 font-semibold text-stone-950">{{ selectedService?.name || '-' }}</div>
                                    <div class="mt-1 text-sm text-stone-500">{{ durationLabel(durationMinutes) }}</div>
                                </div>
                                <div class="rounded-sm border border-stone-200 p-4">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-stone-500">Date et heure</div>
                                    <div class="mt-1 font-semibold text-stone-950">{{ formatDateLong(selectedDate) }}</div>
                                    <div class="mt-1 text-sm text-stone-500">{{ selectedSlot?.time || '-' }}</div>
                                </div>
                                <div class="rounded-sm border border-stone-200 p-4">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-stone-500">Personne</div>
                                    <div class="mt-1 font-semibold text-stone-950">{{ selectedPersonLabel }}</div>
                                </div>
                                <div class="rounded-sm border border-stone-200 p-4">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-stone-500">Demandeur</div>
                                    <div class="mt-1 font-semibold text-stone-950">{{ form.first_name }} {{ form.last_name }}</div>
                                    <div class="mt-1 text-sm text-stone-500">{{ form.email }} · {{ form.phone }}</div>
                                </div>
                            </div>

                            <div v-if="link.requires_manual_confirmation" class="mt-5 rounded-sm border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                Cette demande sera envoyee a l entreprise pour confirmation.
                            </div>
                        </section>

                        <section v-else class="flex min-h-[560px] items-center justify-center p-4 sm:p-6">
                            <div class="mx-auto max-w-xl text-center">
                                <div class="mx-auto flex size-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                                    <CheckCircle2 class="size-9" />
                                </div>
                                <h2 class="mt-5 text-2xl font-semibold text-stone-950">Reservation envoyee</h2>
                                <p class="mt-2 text-sm text-stone-500">
                                    {{ successPayload?.message || 'Votre demande a ete recue.' }}
                                </p>
                                <div class="mt-5 rounded-sm border border-stone-200 bg-stone-50 p-4 text-left text-sm">
                                    <div class="font-semibold text-stone-950">{{ successPayload?.reservation?.service_name || selectedService?.name }}</div>
                                    <div class="mt-1 text-stone-600">{{ confirmationDateLabel }} · {{ confirmationTimeLabel }}</div>
                                    <div class="mt-1 text-stone-600">{{ successPayload?.reservation?.team_member_name || selectedPersonLabel }}</div>
                                </div>
                                <button
                                    type="button"
                                    class="mt-5 inline-flex items-center justify-center gap-2 rounded-sm bg-stone-900 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-800"
                                    @click="resetBooking"
                                >
                                    <CalendarDays class="size-4" />
                                    Faire une autre reservation
                                </button>
                            </div>
                        </section>

                        <div v-if="currentStepKey !== 'done'" class="border-t border-stone-200 px-4 py-4 sm:px-6">
                            <div v-if="stepError" class="mb-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ stepError }}</div>
                            <div v-if="submitError" class="mb-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ submitError }}</div>
                            <div v-if="slotsError" class="mb-3 rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ slotsError }}</div>

                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-sm border border-stone-200 px-4 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-50"
                                    :disabled="currentStep === 0 || submitting"
                                    @click="backStep"
                                >
                                    <ArrowLeft class="size-4" />
                                    Retour
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-sm bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50"
                                    :disabled="submitting || (currentStepKey === 'service' && !services.length)"
                                    @click="continueStep"
                                >
                                    <Loader2 v-if="submitting" class="size-4 animate-spin" />
                                    <CheckCircle2 v-else-if="currentStepKey === 'review'" class="size-4" />
                                    <ArrowRight v-else class="size-4" />
                                    {{ currentStepKey === 'review' ? 'Confirmer la reservation' : 'Continuer' }}
                                </button>
                            </div>
                        </div>
                    </main>

                    <aside class="h-fit rounded-sm border border-stone-200 bg-white p-4 shadow-sm lg:sticky lg:top-5">
                        <h2 class="text-sm font-semibold text-stone-950">Resume</h2>
                        <div class="mt-4 space-y-3 text-sm">
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 flex size-8 items-center justify-center rounded-sm bg-emerald-50 text-emerald-700">
                                    <Sparkles class="size-4" />
                                </span>
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-stone-400">Service</div>
                                    <div class="truncate font-medium text-stone-900">{{ selectedService?.name || 'A choisir' }}</div>
                                    <div class="text-xs text-stone-500">{{ durationLabel(durationMinutes) }}</div>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 flex size-8 items-center justify-center rounded-sm bg-sky-50 text-sky-700">
                                    <CalendarDays class="size-4" />
                                </span>
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-stone-400">Date</div>
                                    <div class="font-medium text-stone-900">{{ selectedDate ? formatDateLong(selectedDate) : 'A choisir' }}</div>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 flex size-8 items-center justify-center rounded-sm bg-amber-50 text-amber-700">
                                    <Clock3 class="size-4" />
                                </span>
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-stone-400">Heure</div>
                                    <div class="font-medium text-stone-900">{{ selectedSlot?.time || 'A choisir' }}</div>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 flex size-8 items-center justify-center rounded-sm bg-stone-100 text-stone-700">
                                    <UserRound class="size-4" />
                                </span>
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-stone-400">Personne</div>
                                    <div class="font-medium text-stone-900">{{ selectedPersonLabel }}</div>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 flex size-8 items-center justify-center rounded-sm bg-rose-50 text-rose-700">
                                    <Mail class="size-4" />
                                </span>
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-stone-400">Contact</div>
                                    <div class="truncate font-medium text-stone-900">{{ form.first_name || form.last_name ? `${form.first_name} ${form.last_name}` : 'A renseigner' }}</div>
                                    <div class="truncate text-xs text-stone-500">{{ form.email || form.phone || '-' }}</div>
                                </div>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>

            <PublicChatWidget
                v-if="aiAssistantEnabled"
                :company-name="company.name"
                :company-slug="aiAssistant.company_slug"
                :company-logo-url="company.logo_url || ''"
                :assistant-name="aiAssistant.name || 'Malikia AI Assistant'"
                :endpoints="aiAssistant.endpoints"
                :initial-metadata="aiReservationContext"
                :visitor-name="aiVisitorName"
                :visitor-email="form.email"
                :visitor-phone="form.phone"
                channel="public_reservation"
                mode="floating"
            />
        </div>
    </GuestLayout>
</template>
