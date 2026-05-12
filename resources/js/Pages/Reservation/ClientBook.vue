<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import dayjs from 'dayjs';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import ReservationCalendarBoard from '@/Components/Reservation/ReservationCalendarBoard.vue';
import { reservationStatusBadgeClass } from '@/Components/Reservation/status';
import { crmButtonClass, crmSegmentedControlButtonClass, crmSegmentedControlClass } from '@/utils/crmButtonStyles';

const { t } = useI18n();

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
    upcomingReservations: {
        type: Array,
        default: () => [],
    },
    waitlistEntries: {
        type: Array,
        default: () => [],
    },
    queueTickets: {
        type: Array,
        default: () => [],
    },
    settings: {
        type: Object,
        default: () => ({}),
    },
});

const selectedTeamMemberId = ref('');
const selectedServiceId = ref('');
const slots = ref([]);
const selectedSlot = ref(null);
const slotsLoading = ref(false);
const slotsError = ref('');
const successMessage = ref('');
const submitError = ref('');
const submitting = ref(false);
const calendarRange = ref({
    start: dayjs().startOf('week').toISOString(),
    end: dayjs().endOf('week').toISOString(),
});
const upcomingReservations = ref([...(props.upcomingReservations || [])]);
const waitlistEntries = ref([...(props.waitlistEntries || [])]);
const queueTickets = ref([...(props.queueTickets || [])]);
const showWaitlistForm = ref(false);
const waitlistSubmitting = ref(false);
const waitlistError = ref('');
const waitlistSuccess = ref('');
const ticketError = ref('');
const ticketSuccess = ref('');

const bookingForm = useForm({
    team_member_id: '',
    service_id: '',
    starts_at: '',
    ends_at: '',
    duration_minutes: 60,
    party_size: '',
    timezone: props.timezone || 'UTC',
    contact_name: props.client?.name || '',
    contact_email: props.client?.email || '',
    contact_phone: props.client?.phone || '',
    client_notes: '',
});

const waitlistForm = useForm({
    party_size: '',
    notes: '',
});

if (props.services?.length) {
    const firstService = props.services[0];
    selectedServiceId.value = String(firstService.id);
    bookingForm.service_id = String(firstService.id);
}

const teamOptions = computed(() => [
    { value: '', label: t('reservations.client.index.any_available') },
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

const selectedService = computed(() =>
    (props.services || []).find((service) => String(service.id) === String(selectedServiceId.value || ''))
);

const slotEvents = computed(() =>
    (slots.value || []).map((slot) => ({
        id: `${slot.team_member_id}:${slot.starts_at}`,
        title: slot.team_member_name,
        start: slot.starts_at,
        end: slot.ends_at,
        extendedProps: {
            slot,
            status: 'slot',
        },
    }))
);

const selectedSlotEventId = computed(() => {
    if (!selectedSlot.value) {
        return null;
    }
    return `${selectedSlot.value.team_member_id}:${selectedSlot.value.starts_at}`;
});

const selectedSlotLabel = computed(() => {
    if (!selectedSlot.value) {
        return '-';
    }

    const resourceLabel = selectedSlot.value.resource_name
        ? ` - ${selectedSlot.value.resource_name}`
        : '';

    return `${dayjs(selectedSlot.value.starts_at).format('ddd, MMM D HH:mm')} - ${dayjs(selectedSlot.value.ends_at).format('HH:mm')} (${selectedSlot.value.team_member_name}${resourceLabel})`;
});

const serviceTabs = computed(() => ([
    {
        id: 'reservations',
        label: t('reservations.client.index.title'),
        description: t('reservations.client.index.subtitle'),
        href: route('client.reservations.index'),
        badge: upcomingReservations.value.length,
        active: false,
    },
    {
        id: 'book',
        label: t('reservations.client.book.title'),
        description: t('reservations.client.book.subtitle'),
        href: route('client.reservations.book'),
        badge: props.services.length,
        active: true,
    },
]));

const bookOverviewCards = computed(() => ([
    {
        key: 'services',
        label: t('reservations.form.item'),
        value: props.services.length,
        meta: selectedService.value?.name || t('reservations.client.book.default_service'),
        border: 'border-t-green-600',
    },
    {
        key: 'upcoming',
        label: t('reservations.client.book.upcoming_title'),
        value: upcomingReservations.value.length,
        meta: selectedSlot.value ? selectedSlotLabel.value : t('reservations.client.book.no_upcoming'),
        border: 'border-t-emerald-600',
    },
    {
        key: 'waitlist',
        label: t('reservations.client.book.waitlist.title'),
        value: waitlistEntries.value.length,
        meta: queueTickets.value.length
            ? t('reservations.queue.client.title')
            : t('reservations.client.book.waitlist.none'),
        border: 'border-t-amber-500',
    },
]));

const waitlistEnabled = computed(() => Boolean(props.settings?.waitlist_enabled));
const queueModeEnabled = computed(() => Boolean(props.settings?.queue_mode_enabled));
const ownerOnlyMode = computed(() => Boolean(props.settings?.owner_only_mode));
const slotBookingAvailable = computed(() => Boolean(props.settings?.slot_booking_enabled ?? true));
const canSubmit = computed(() => slotBookingAvailable.value && Boolean(selectedSlot.value) && !submitting.value);
const slotDurationMinutes = computed(() => {
    const value = Number(props.settings?.slot_duration_minutes || props.settings?.slot_interval_minutes || 60);
    return Math.max(5, Math.min(240, Number.isFinite(value) ? value : 60));
});
const hasNoSlots = computed(() => !slotsLoading.value && (slots.value || []).length === 0);
const hasDepositPolicy = computed(() => (
    Boolean(props.settings?.deposit_required) && Number(props.settings?.deposit_amount || 0) > 0
));
const hasNoShowFeePolicy = computed(() => (
    Boolean(props.settings?.no_show_fee_enabled) && Number(props.settings?.no_show_fee_amount || 0) > 0
));
const formatMoney = (value) => Number(value || 0).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const loadSlots = async () => {
    if (!calendarRange.value.start || !calendarRange.value.end) {
        return;
    }
    if (!slotBookingAvailable.value) {
        slots.value = [];
        selectedSlot.value = null;
        slotsError.value = '';
        slotsLoading.value = false;
        return;
    }

    slotsLoading.value = true;
    slotsError.value = '';

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
        slots.value = response?.data?.slots || [];

        if (selectedSlot.value) {
            const exists = slots.value.find((slot) =>
                slot.team_member_id === selectedSlot.value.team_member_id
                && slot.starts_at === selectedSlot.value.starts_at
            );
            if (!exists) {
                selectedSlot.value = null;
            }
        }
    } catch (error) {
        slotsError.value = error?.response?.data?.message || t('reservations.errors.load_slots');
    } finally {
        slotsLoading.value = false;
    }
};

let slotsTimer = null;
const queueLoadSlots = () => {
    if (slotsTimer) {
        clearTimeout(slotsTimer);
    }
    slotsTimer = setTimeout(loadSlots, 280);
};

watch(
    () => [selectedTeamMemberId.value, selectedServiceId.value, bookingForm.party_size],
    () => {
        successMessage.value = '';
        submitError.value = '';
        queueLoadSlots();
    }
);

watch(selectedServiceId, (value) => {
    bookingForm.service_id = value || '';
});

const onCalendarRangeChange = (payload) => {
    calendarRange.value = {
        start: payload.start,
        end: payload.end,
    };
    queueLoadSlots();
};

const selectSlot = (slot) => {
    selectedSlot.value = slot;
    successMessage.value = '';
    submitError.value = '';
};

const onSlotEventClick = (rawEvent) => {
    const source = rawEvent?.original || rawEvent;
    const slot = source?.extendedProps?.slot;
    if (slot) {
        selectSlot(slot);
    }
};

const submitBooking = async () => {
    submitError.value = '';
    successMessage.value = '';
    bookingForm.clearErrors();

    if (!selectedSlot.value) {
        submitError.value = t('reservations.client.book.select_slot_error');
        return;
    }

    submitting.value = true;

    try {
        const payload = {
            team_member_id: Number(selectedSlot.value.team_member_id),
            service_id: selectedServiceId.value ? Number(selectedServiceId.value) : null,
            starts_at: selectedSlot.value.starts_at,
            ends_at: selectedSlot.value.ends_at,
            duration_minutes: slotDurationMinutes.value,
            party_size: bookingForm.party_size ? Number(bookingForm.party_size) : null,
            timezone: bookingForm.timezone || props.timezone || 'UTC',
            contact_name: bookingForm.contact_name || null,
            contact_email: bookingForm.contact_email || null,
            contact_phone: bookingForm.contact_phone || null,
            client_notes: bookingForm.client_notes || null,
            resource_ids: selectedSlot.value.resource_id ? [Number(selectedSlot.value.resource_id)] : [],
        };

        const response = await axios.post(route('client.reservations.store'), payload, {
            headers: {
                Accept: 'application/json',
            },
        });

        const reservation = response?.data?.reservation;
        if (reservation) {
            upcomingReservations.value = [reservation, ...upcomingReservations.value].slice(0, 8);
        }

        selectedSlot.value = null;
        bookingForm.client_notes = '';
        successMessage.value = response?.data?.message || t('reservations.client.book.actions.submitted');
        await loadSlots();
    } catch (error) {
        if (error?.response?.status === 422) {
            bookingForm.setError(error.response.data?.errors || {});
            submitError.value = t('reservations.errors.validation');
        } else {
            submitError.value = error?.response?.data?.message || t('reservations.errors.create');
        }
    } finally {
        submitting.value = false;
    }
};

const submitWaitlist = async () => {
    if (!waitlistEnabled.value) {
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

        const entry = response?.data?.waitlist;
        if (entry) {
            waitlistEntries.value = [entry, ...waitlistEntries.value].slice(0, 20);
        }
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

const cancelWaitlist = async (entry) => {
    if (!entry?.id || !entry?.can_cancel) {
        return;
    }

    waitlistError.value = '';
    waitlistSuccess.value = '';

    try {
        const response = await axios.patch(route('client.reservations.waitlist.cancel', entry.id), {}, {
            headers: {
                Accept: 'application/json',
            },
        });

        const updated = response?.data?.waitlist || { ...entry, status: 'cancelled', can_cancel: false };
        waitlistEntries.value = waitlistEntries.value.map((item) => (
            Number(item.id) === Number(entry.id) ? updated : item
        ));
        waitlistSuccess.value = response?.data?.message || t('reservations.client.book.waitlist.cancelled');
    } catch (error) {
        waitlistError.value = error?.response?.data?.message || t('reservations.client.book.waitlist.cancel_error');
    }
};

const cancelTicket = async (ticket) => {
    if (!ticket?.id || !ticket?.can_cancel) {
        return;
    }

    ticketError.value = '';
    ticketSuccess.value = '';

    try {
        const response = await axios.patch(route('client.reservations.tickets.cancel', ticket.id), {}, {
            headers: {
                Accept: 'application/json',
            },
        });

        const updated = response?.data?.ticket || { ...ticket, status: 'left', can_cancel: false, can_still_here: false };
        queueTickets.value = queueTickets.value.map((item) => (
            Number(item.id) === Number(ticket.id) ? { ...item, ...updated } : item
        ));
        ticketSuccess.value = response?.data?.message || t('reservations.queue.client.cancelled');
    } catch (error) {
        ticketError.value = error?.response?.data?.message || t('reservations.queue.client.update_error');
    }
};

const stillHereTicket = async (ticket) => {
    if (!ticket?.id || !ticket?.can_still_here) {
        return;
    }

    ticketError.value = '';
    ticketSuccess.value = '';

    try {
        const response = await axios.patch(route('client.reservations.tickets.still-here', ticket.id), {}, {
            headers: {
                Accept: 'application/json',
            },
        });
        const updated = response?.data?.ticket || ticket;
        queueTickets.value = queueTickets.value.map((item) => (
            Number(item.id) === Number(ticket.id) ? { ...item, ...updated } : item
        ));
        ticketSuccess.value = response?.data?.message || t('reservations.queue.client.still_here_done');
    } catch (error) {
        ticketError.value = error?.response?.data?.message || t('reservations.queue.client.update_error');
    }
};

const statusBadgeClass = (status) => reservationStatusBadgeClass(status);

onBeforeUnmount(() => {
    if (slotsTimer) {
        clearTimeout(slotsTimer);
    }
});
</script>

<template>
    <Head :title="$t('reservations.client.book.title')" />
    <AuthenticatedLayout>
        <div class="space-y-4">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-green-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-sm bg-green-100 text-green-700 dark:bg-green-500/10 dark:text-green-300">
                            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 5v14" />
                                <path d="M5 12h14" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('reservations.client.book.title') }}
                            </h1>
                            <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('reservations.client.book.subtitle') }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <nav :class="crmSegmentedControlClass()" aria-label="Service client sections">
                            <Link
                                v-for="tab in serviceTabs"
                                :key="tab.id"
                                :href="tab.href"
                                :aria-current="tab.active ? 'page' : null"
                                :class="crmSegmentedControlButtonClass(tab.active)"
                            >
                                <span>{{ tab.label }}</span>
                                <span
                                    v-if="tab.badge !== undefined && tab.badge !== null"
                                    class="rounded-sm px-1.5 py-0.5 text-[10px] leading-none"
                                    :class="tab.active ? 'bg-white/20 text-white' : 'bg-stone-100 text-stone-500 dark:bg-neutral-800 dark:text-neutral-400'"
                                >
                                    {{ tab.badge }}
                                </span>
                            </Link>
                        </nav>
                        <Link
                            :href="route('client.reservations.index')"
                            :class="crmButtonClass('secondary', 'dialog')"
                        >
                            {{ $t('reservations.client.book.my_reservations') }}
                        </Link>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div
                        v-for="card in bookOverviewCards"
                        :key="card.key"
                        class="rounded-sm border border-stone-200 border-t-4 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800"
                        :class="card.border"
                    >
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ card.label }}
                        </div>
                        <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ card.value }}
                        </div>
                        <div class="mt-1 truncate text-xs text-stone-500 dark:text-neutral-400">
                            {{ card.meta }}
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 xl:grid-cols-3">
                <div class="space-y-4 xl:col-span-2">
                    <div class="rounded-sm border border-stone-200 border-t-4 border-t-green-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div
                            v-if="ownerOnlyMode"
                            class="mb-3 rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100"
                        >
                            {{ $t('reservations.owner_only.client_notice') }}
                        </div>
                        <div class="grid gap-3 lg:grid-cols-3">
                            <FloatingSelect
                                v-if="teamOptions.length > 1"
                                v-model="selectedTeamMemberId"
                                :options="teamOptions"
                                :label="$t('reservations.client.book.fields.team_member')"
                            />
                            <FloatingSelect v-model="selectedServiceId" :options="serviceOptions" :label="$t('reservations.client.book.fields.service')" />
                            <div>
                                <FloatingInput v-model="bookingForm.party_size" type="number" min="1" :label="$t('reservations.client.book.fields.party_size')" />
                                <InputError class="mt-1" :message="bookingForm.errors.party_size" />
                            </div>
                        </div>

                        <div v-if="selectedService?.description" class="mt-3 rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                            {{ selectedService.description }}
                        </div>
                    </div>

                    <ReservationCalendarBoard
                        v-if="slotBookingAvailable"
                        :events="slotEvents"
                        :loading="slotsLoading"
                        :error="slotsError"
                        :empty-label="$t('reservations.client.book.no_availability')"
                        :selected-event-id="selectedSlotEventId"
                        initial-view="week"
                        :loading-label="$t('reservations.client.book.loading_slots')"
                        @range-change="onCalendarRangeChange"
                        @event-click="onSlotEventClick"
                    />

                    <section
                        v-if="waitlistEnabled && (hasNoSlots || ownerOnlyMode)"
                        class="rounded-sm border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100"
                    >
                        <h3 class="text-sm font-semibold">{{ $t('reservations.client.book.waitlist.title') }}</h3>
                        <p class="mt-1 text-xs">{{ $t('reservations.client.book.waitlist.description') }}</p>

                        <div class="mt-3">
                            <button
                                type="button"
                                class="rounded-sm border border-amber-300 bg-white px-3 py-2 text-xs font-semibold text-amber-800 dark:border-amber-300/40 dark:bg-transparent dark:text-amber-100"
                                @click="showWaitlistForm = !showWaitlistForm"
                            >
                                {{ showWaitlistForm ? $t('quotes.form.cancel') : $t('reservations.client.book.waitlist.join_button') }}
                            </button>
                        </div>

                        <form v-if="showWaitlistForm" class="mt-3 grid gap-3 md:grid-cols-2" @submit.prevent="submitWaitlist">
                            <div>
                                <FloatingInput
                                    v-model="waitlistForm.party_size"
                                    type="number"
                                    min="1"
                                    :label="$t('reservations.client.book.fields.party_size')"
                                />
                                <InputError class="mt-1" :message="waitlistForm.errors.party_size" />
                            </div>
                            <div class="md:col-span-2">
                                <FloatingTextarea
                                    v-model="waitlistForm.notes"
                                    :label="$t('reservations.client.book.waitlist.notes')"
                                />
                                <InputError class="mt-1" :message="waitlistForm.errors.notes" />
                            </div>
                            <div class="md:col-span-2">
                                <button
                                    type="submit"
                                    class="rounded-sm bg-amber-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50"
                                    :disabled="waitlistSubmitting"
                                >
                                    {{ waitlistSubmitting ? $t('reservations.client.book.actions.submitting') : $t('reservations.client.book.waitlist.join_button') }}
                                </button>
                            </div>
                        </form>
                    </section>
                </div>

                <div class="space-y-4">
                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('reservations.client.book.summary_title') }}</h2>
                        <div class="mt-3 space-y-3">
                            <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('reservations.client.book.selected_slot') }}</div>
                                <div class="mt-1 font-medium text-stone-700 dark:text-neutral-200">{{ selectedSlotLabel }}</div>
                            </div>
                            <div
                                v-if="hasDepositPolicy"
                                class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-400/40 dark:bg-amber-500/10 dark:text-amber-100"
                            >
                                {{ $t('reservations.client.book.deposit_notice', { amount: formatMoney(props.settings.deposit_amount) }) }}
                            </div>
                            <div
                                v-if="hasNoShowFeePolicy"
                                class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-400/40 dark:bg-rose-500/10 dark:text-rose-100"
                            >
                                {{ $t('reservations.client.book.no_show_notice', { amount: formatMoney(props.settings.no_show_fee_amount) }) }}
                            </div>

                            <div>
                                <FloatingInput v-model="bookingForm.contact_name" :label="$t('reservations.client.book.fields.contact_name')" />
                                <InputError class="mt-1" :message="bookingForm.errors.contact_name" />
                            </div>
                            <div>
                                <FloatingInput v-model="bookingForm.contact_email" type="email" :label="$t('reservations.client.book.fields.contact_email')" />
                                <InputError class="mt-1" :message="bookingForm.errors.contact_email" />
                            </div>
                            <div>
                                <FloatingInput v-model="bookingForm.contact_phone" :label="$t('reservations.client.book.fields.contact_phone')" />
                                <InputError class="mt-1" :message="bookingForm.errors.contact_phone" />
                            </div>
                            <div>
                                <FloatingTextarea v-model="bookingForm.client_notes" :label="$t('reservations.client.book.fields.client_notes')" />
                                <InputError class="mt-1" :message="bookingForm.errors.client_notes" />
                            </div>

                            <InputError class="mt-1" :message="bookingForm.errors.team_member_id || bookingForm.errors.starts_at" />

                            <div v-if="submitError" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                                {{ submitError }}
                            </div>
                            <div v-if="successMessage" class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
                                {{ successMessage }}
                            </div>
                            <div v-if="waitlistError" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                                {{ waitlistError }}
                            </div>
                            <div v-if="waitlistSuccess" class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
                                {{ waitlistSuccess }}
                            </div>

                            <button
                                type="button"
                                class="w-full rounded-sm bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                                :disabled="!canSubmit"
                                @click="submitBooking"
                            >
                                {{ submitting ? $t('reservations.client.book.actions.submitting') : $t('reservations.client.book.actions.submit') }}
                            </button>
                            <div
                                v-if="ownerOnlyMode"
                                class="mt-2 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                            >
                                {{ $t('reservations.owner_only.client_waitlist_hint') }}
                            </div>
                        </div>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('reservations.client.book.upcoming_title') }}</h2>
                        <div class="mt-3 space-y-2">
                            <div
                                v-for="reservation in upcomingReservations"
                                :key="`upcoming-${reservation.id}`"
                                class="rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <div class="font-medium text-stone-700 dark:text-neutral-200">
                                        {{ reservation.service?.name || $t('reservations.client.book.default_service') }}
                                    </div>
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadgeClass(reservation.status)">
                                        {{ $t(`reservations.status.${reservation.status}`) || reservation.status?.replace(/_/g, ' ') }}
                                    </span>
                                </div>
                                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ dayjs(reservation.starts_at).format('ddd, MMM D HH:mm') }}
                                    · {{ reservation.team_member?.user?.name || reservation.teamMember?.user?.name || '-' }}
                                </div>
                            </div>
                            <div v-if="!upcomingReservations.length" class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-3 py-3 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                                {{ $t('reservations.client.book.no_upcoming') }}
                            </div>
                        </div>
                    </section>

                    <section
                        v-if="waitlistEnabled"
                        class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('reservations.client.book.waitlist.my_entries') }}</h2>
                        <div class="mt-3 space-y-2">
                            <div
                                v-for="entry in waitlistEntries"
                                :key="`waitlist-${entry.id}`"
                                class="rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <div class="font-medium text-stone-700 dark:text-neutral-200">
                                        {{ entry.service_name || $t('reservations.client.book.default_service') }}
                                    </div>
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusBadgeClass(entry.status)">
                                        {{ $t(`reservations.waitlist.status.${entry.status}`) || entry.status }}
                                    </span>
                                </div>
                                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ dayjs(entry.requested_start_at).format('ddd, MMM D') }} - {{ dayjs(entry.requested_end_at).format('ddd, MMM D') }}
                                    <template v-if="entry.party_size">
                                        · {{ $t('reservations.table.party_size_value', { value: entry.party_size }) }}
                                    </template>
                                </div>
                                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ entry.team_member_name || $t('reservations.client.index.any_available') }}
                                </div>
                                <div class="mt-2 flex justify-end">
                                    <button
                                        v-if="entry.can_cancel"
                                        type="button"
                                        class="text-xs text-rose-700 underline"
                                        @click="cancelWaitlist(entry)"
                                    >
                                        {{ $t('reservations.actions.cancel') }}
                                    </button>
                                </div>
                            </div>
                            <div v-if="!waitlistEntries.length" class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-3 py-3 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                                {{ $t('reservations.client.book.waitlist.none') }}
                            </div>
                        </div>
                    </section>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
