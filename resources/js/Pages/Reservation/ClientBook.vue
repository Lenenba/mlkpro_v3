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

const bookingForm = useForm({
    team_member_id: '',
    service_id: '',
    starts_at: '',
    ends_at: '',
    duration_minutes: 60,
    timezone: props.timezone || 'UTC',
    contact_name: props.client?.name || '',
    contact_email: props.client?.email || '',
    contact_phone: props.client?.phone || '',
    client_notes: '',
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

    return `${dayjs(selectedSlot.value.starts_at).format('ddd, MMM D HH:mm')} - ${dayjs(selectedSlot.value.ends_at).format('HH:mm')} (${selectedSlot.value.team_member_name})`;
});

const canSubmit = computed(() => Boolean(selectedSlot.value) && !submitting.value);

const loadSlots = async () => {
    if (!calendarRange.value.start || !calendarRange.value.end) {
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
                duration_minutes: bookingForm.duration_minutes || undefined,
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
    () => [selectedTeamMemberId.value, selectedServiceId.value, bookingForm.duration_minutes],
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
            duration_minutes: Number(bookingForm.duration_minutes || 60),
            timezone: bookingForm.timezone || props.timezone || 'UTC',
            contact_name: bookingForm.contact_name || null,
            contact_email: bookingForm.contact_email || null,
            contact_phone: bookingForm.contact_phone || null,
            client_notes: bookingForm.client_notes || null,
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
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ $t('reservations.client.book.title') }}</h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('reservations.client.book.subtitle') }}</p>
                    </div>
                    <Link
                        :href="route('client.reservations.index')"
                        class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 dark:border-neutral-700 dark:text-neutral-200"
                    >
                        {{ $t('reservations.client.book.my_reservations') }}
                    </Link>
                </div>
            </section>

            <section class="grid gap-4 xl:grid-cols-3">
                <div class="space-y-4 xl:col-span-2">
                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="grid gap-3 lg:grid-cols-3">
                            <FloatingSelect v-model="selectedTeamMemberId" :options="teamOptions" :label="$t('reservations.client.book.fields.team_member')" />
                            <FloatingSelect v-model="selectedServiceId" :options="serviceOptions" :label="$t('reservations.client.book.fields.service')" />
                            <div>
                                <FloatingInput v-model="bookingForm.duration_minutes" type="number" min="5" :label="$t('reservations.client.book.fields.duration')" />
                                <InputError class="mt-1" :message="bookingForm.errors.duration_minutes" />
                            </div>
                        </div>

                        <div v-if="selectedService?.description" class="mt-3 rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                            {{ selectedService.description }}
                        </div>
                    </div>

                    <ReservationCalendarBoard
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
                </div>

                <div class="space-y-4">
                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('reservations.client.book.summary_title') }}</h2>
                        <div class="mt-3 space-y-3">
                            <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('reservations.client.book.selected_slot') }}</div>
                                <div class="mt-1 font-medium text-stone-700 dark:text-neutral-200">{{ selectedSlotLabel }}</div>
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

                            <button
                                type="button"
                                class="w-full rounded-sm bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                                :disabled="!canSubmit"
                                @click="submitBooking"
                            >
                                {{ submitting ? $t('reservations.client.book.actions.submitting') : $t('reservations.client.book.actions.submit') }}
                            </button>
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
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
