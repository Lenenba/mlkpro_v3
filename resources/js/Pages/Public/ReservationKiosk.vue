<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import dayjs from 'dayjs';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import { reservationStatusBadgeClass } from '@/Components/Reservation/status';

const { t } = useI18n();

const props = defineProps({
    company: {
        type: Object,
        required: true,
    },
    settings: {
        type: Object,
        default: () => ({}),
    },
    services: {
        type: Array,
        default: () => [],
    },
    team_members: {
        type: Array,
        default: () => [],
    },
    endpoints: {
        type: Object,
        required: true,
    },
});

const activeMode = ref('walk_in');
const walkInResult = ref(null);
const walkInError = ref('');
const walkInSuccess = ref('');
const lookupError = ref('');
const lookupSuccess = ref('');
const lookupResult = ref(null);
const checkInError = ref('');
const checkInSuccess = ref('');
const checkInResult = ref(null);
const trackError = ref('');
const trackResult = ref(null);
const verificationDebugCode = ref('');
const verifiedCode = ref('');

const serviceOptions = computed(() => [
    { value: '', label: t('reservations.kiosk.fields.any_service') },
    ...(props.services || []).map((service) => ({
        value: String(service.id),
        label: service.name,
    })),
]);

const teamOptions = computed(() => [
    { value: '', label: t('reservations.kiosk.fields.any_team_member') },
    ...(props.team_members || []).map((member) => ({
        value: String(member.id),
        label: member.title ? `${member.name} - ${member.title}` : member.name,
    })),
]);

const walkInForm = useForm({
    phone: '',
    guest_name: '',
    service_id: '',
    team_member_id: '',
    estimated_duration_minutes: '',
    party_size: '',
    notes: '',
});

const lookupForm = useForm({
    phone: '',
});

const verifyForm = useForm({
    code: '',
});

const clientTicketForm = useForm({
    service_id: '',
    team_member_id: '',
    estimated_duration_minutes: '',
    party_size: '',
    notes: '',
});

const trackForm = useForm({
    phone: '',
    queue_number: '',
});

const queueStatusClass = (status) => reservationStatusBadgeClass(status);
const queueStatusLabel = (status) => t(`reservations.queue.status.${status}`) || status;
const nextAction = computed(() => String(lookupResult.value?.intent?.next_action || ''));
const hasClientLookup = computed(() => Boolean(lookupResult.value?.found));
const verificationRequired = computed(() => Boolean(lookupResult.value?.verification_required));
const isVerifiedClientFlow = computed(() => Boolean(lookupResult.value?.verified));
const canCreateClientTicket = computed(() => nextAction.value === 'take_ticket');
const hasActiveClientTicket = computed(() => nextAction.value === 'track_ticket' && lookupResult.value?.intent?.active_ticket);
const hasNearbyReservation = computed(() => nextAction.value === 'check_in' && lookupResult.value?.intent?.nearby_reservation);

const normalizeError = (error, fallback) => error?.response?.data?.message || fallback;
const firstValidationError = (errors) => {
    if (!errors || typeof errors !== 'object') {
        return '';
    }

    for (const value of Object.values(errors)) {
        if (Array.isArray(value) && value.length > 0 && typeof value[0] === 'string' && value[0]) {
            return value[0];
        }
        if (typeof value === 'string' && value) {
            return value;
        }
    }

    return '';
};
const applyDuplicateTicketState = (payload, target) => {
    const ticket = payload?.ticket || payload?.intent?.active_ticket || null;
    const message = payload?.message || t('reservations.kiosk.messages.active_ticket_exists');

    if (target === 'walk_in') {
        walkInResult.value = ticket;
        walkInSuccess.value = '';
        walkInError.value = message;
        trackForm.phone = walkInForm.phone;
        trackForm.queue_number = ticket?.queue_number || '';
        return;
    }

    checkInResult.value = ticket;
    checkInSuccess.value = '';
    checkInError.value = message;
    lookupResult.value = {
        ...(lookupResult.value || {}),
        intent: {
            ...(lookupResult.value?.intent || {}),
            next_action: 'track_ticket',
            active_ticket: ticket,
        },
    };
};

const submitWalkIn = async () => {
    walkInError.value = '';
    walkInSuccess.value = '';
    walkInResult.value = null;
    walkInForm.clearErrors();

    try {
        const response = await axios.post(props.endpoints.walk_in_ticket, {
            phone: walkInForm.phone,
            guest_name: walkInForm.guest_name || null,
            service_id: walkInForm.service_id ? Number(walkInForm.service_id) : null,
            team_member_id: walkInForm.team_member_id ? Number(walkInForm.team_member_id) : null,
            estimated_duration_minutes: walkInForm.estimated_duration_minutes ? Number(walkInForm.estimated_duration_minutes) : null,
            party_size: walkInForm.party_size ? Number(walkInForm.party_size) : null,
            notes: walkInForm.notes || null,
        }, {
            headers: {
                Accept: 'application/json',
            },
        });

        walkInResult.value = response?.data?.ticket || null;
        walkInSuccess.value = response?.data?.message || t('reservations.kiosk.messages.ticket_created');
        walkInForm.reset('guest_name', 'service_id', 'team_member_id', 'estimated_duration_minutes', 'party_size', 'notes');
    } catch (error) {
        if (error?.response?.status === 409 && error?.response?.data?.duplicate_ticket) {
            applyDuplicateTicketState(error.response.data, 'walk_in');
            return;
        }
        if (error?.response?.status === 422) {
            const errors = error.response.data?.errors || {};
            walkInForm.setError(errors);
            walkInError.value = firstValidationError(errors) || t('reservations.errors.validation');
            return;
        }
        walkInError.value = normalizeError(error, t('reservations.kiosk.errors.create_ticket'));
    }
};

const lookupClient = async () => {
    lookupError.value = '';
    lookupSuccess.value = '';
    checkInError.value = '';
    checkInSuccess.value = '';
    checkInResult.value = null;
    verificationDebugCode.value = '';
    verifyForm.reset();
    lookupForm.clearErrors();

    try {
        const response = await axios.post(props.endpoints.lookup_client, {
            phone: lookupForm.phone,
            send_verification: true,
        }, {
            headers: {
                Accept: 'application/json',
            },
        });

        lookupResult.value = response?.data || null;
        verificationDebugCode.value = response?.data?.verification?.debug_code || '';

        if (!response?.data?.found) {
            lookupSuccess.value = t('reservations.kiosk.messages.client_not_found');
            return;
        }
        if (response?.data?.verification_required && !response?.data?.verified) {
            lookupSuccess.value = t('reservations.kiosk.messages.code_sent');
            return;
        }
        lookupSuccess.value = t('reservations.kiosk.messages.client_found');
    } catch (error) {
        if (error?.response?.status === 422) {
            lookupForm.setError(error.response.data?.errors || {});
            lookupError.value = t('reservations.errors.validation');
            return;
        }
        lookupError.value = normalizeError(error, t('reservations.kiosk.errors.lookup'));
    }
};

const verifyClient = async () => {
    if (!lookupForm.phone) {
        lookupError.value = t('reservations.kiosk.errors.lookup_first');
        return;
    }

    lookupError.value = '';
    verifyForm.clearErrors();

    try {
        const response = await axios.post(props.endpoints.verify_client, {
            phone: lookupForm.phone,
            code: verifyForm.code,
        }, {
            headers: {
                Accept: 'application/json',
            },
        });

        lookupResult.value = response?.data || null;
        verifiedCode.value = verifyForm.code;
        lookupSuccess.value = t('reservations.kiosk.messages.phone_verified');
    } catch (error) {
        if (error?.response?.status === 422) {
            verifyForm.setError(error.response.data?.errors || {});
            lookupError.value = t('reservations.errors.validation');
            return;
        }
        lookupError.value = normalizeError(error, t('reservations.kiosk.errors.verify'));
    }
};

const createClientTicket = async () => {
    if (!lookupForm.phone) {
        lookupError.value = t('reservations.kiosk.errors.lookup_first');
        return;
    }

    checkInError.value = '';
    checkInSuccess.value = '';
    clientTicketForm.clearErrors();

    try {
        const response = await axios.post(props.endpoints.walk_in_ticket, {
            phone: lookupForm.phone,
            service_id: clientTicketForm.service_id ? Number(clientTicketForm.service_id) : null,
            team_member_id: clientTicketForm.team_member_id ? Number(clientTicketForm.team_member_id) : null,
            estimated_duration_minutes: clientTicketForm.estimated_duration_minutes ? Number(clientTicketForm.estimated_duration_minutes) : null,
            party_size: clientTicketForm.party_size ? Number(clientTicketForm.party_size) : null,
            notes: clientTicketForm.notes || null,
            verification_code: verifiedCode.value || null,
        }, {
            headers: {
                Accept: 'application/json',
            },
        });

        lookupResult.value = {
            ...(lookupResult.value || {}),
            intent: {
                ...(lookupResult.value?.intent || {}),
                next_action: 'track_ticket',
                active_ticket: response?.data?.ticket || null,
            },
        };
        checkInResult.value = response?.data?.ticket || null;
        checkInSuccess.value = response?.data?.message || t('reservations.kiosk.messages.ticket_created');
        clientTicketForm.reset();
    } catch (error) {
        if (error?.response?.status === 409 && error?.response?.data?.duplicate_ticket) {
            applyDuplicateTicketState(error.response.data, 'client_ticket');
            return;
        }
        if (error?.response?.status === 422) {
            const errors = error.response.data?.errors || {};
            clientTicketForm.setError(errors);
            checkInError.value = firstValidationError(errors) || t('reservations.errors.validation');
            return;
        }
        checkInError.value = normalizeError(error, t('reservations.kiosk.errors.create_ticket'));
    }
};

const checkInReservation = async () => {
    if (!lookupForm.phone) {
        checkInError.value = t('reservations.kiosk.errors.lookup_first');
        return;
    }

    checkInError.value = '';
    checkInSuccess.value = '';
    const reservationId = lookupResult.value?.intent?.nearby_reservation?.id || null;

    try {
        const response = await axios.post(props.endpoints.check_in, {
            phone: lookupForm.phone,
            reservation_id: reservationId,
            verification_code: verifiedCode.value || null,
        }, {
            headers: {
                Accept: 'application/json',
            },
        });

        checkInResult.value = response?.data?.queue_item || null;
        checkInSuccess.value = response?.data?.message || t('reservations.kiosk.messages.check_in_done');
    } catch (error) {
        checkInError.value = normalizeError(error, t('reservations.kiosk.errors.check_in'));
    }
};

const trackTicket = async () => {
    trackError.value = '';
    trackResult.value = null;
    trackForm.clearErrors();

    try {
        const response = await axios.post(props.endpoints.track_ticket, {
            phone: trackForm.phone,
            queue_number: trackForm.queue_number || null,
        }, {
            headers: {
                Accept: 'application/json',
            },
        });

        trackResult.value = response?.data?.ticket || null;
    } catch (error) {
        if (error?.response?.status === 404) {
            trackError.value = t('reservations.kiosk.errors.track_not_found');
            return;
        }
        if (error?.response?.status === 422) {
            trackForm.setError(error.response.data?.errors || {});
            trackError.value = t('reservations.errors.validation');
            return;
        }
        trackError.value = normalizeError(error, t('reservations.kiosk.errors.track'));
    }
};

const formatDateTime = (value) => (value ? dayjs(value).format('DD MMM HH:mm') : '-');
</script>

<template>
    <GuestLayout
        :card-class="'mt-6 w-full max-w-6xl rounded-sm border border-stone-200 bg-white px-6 py-6 shadow-md dark:border-neutral-700 dark:bg-neutral-900'"
        :logo-url="company.logo_url || ''"
        :logo-alt="company.name || $t('reservations.kiosk.title')"
        :logo-href="''"
        :show-platform-logo="false"
    >
        <Head :title="$t('reservations.kiosk.title')" />

        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ company.name }}
                    </p>
                    <h1 class="text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('reservations.kiosk.title') }}
                    </h1>
                    <p class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('reservations.kiosk.subtitle') }}
                    </p>
                </div>
                <span class="rounded-full bg-cyan-100 px-3 py-1 text-xs font-semibold text-cyan-700 dark:bg-cyan-500/10 dark:text-cyan-300">
                    {{ $t(`settings.reservations.presets.${settings.business_preset || 'service_general'}`) }}
                </span>
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <button
                    type="button"
                    class="rounded-sm border px-3 py-2 text-sm font-semibold transition"
                    :class="activeMode === 'walk_in'
                        ? 'border-emerald-600 bg-emerald-50 text-emerald-700 dark:border-emerald-400 dark:bg-emerald-500/10 dark:text-emerald-200'
                        : 'border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                    @click="activeMode = 'walk_in'"
                >
                    {{ $t('reservations.kiosk.tabs.walk_in') }}
                </button>
                <button
                    type="button"
                    class="rounded-sm border px-3 py-2 text-sm font-semibold transition"
                    :class="activeMode === 'known_client'
                        ? 'border-emerald-600 bg-emerald-50 text-emerald-700 dark:border-emerald-400 dark:bg-emerald-500/10 dark:text-emerald-200'
                        : 'border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                    @click="activeMode = 'known_client'"
                >
                    {{ $t('reservations.kiosk.tabs.known_client') }}
                </button>
                <button
                    type="button"
                    class="rounded-sm border px-3 py-2 text-sm font-semibold transition"
                    :class="activeMode === 'track_ticket'
                        ? 'border-emerald-600 bg-emerald-50 text-emerald-700 dark:border-emerald-400 dark:bg-emerald-500/10 dark:text-emerald-200'
                        : 'border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                    @click="activeMode = 'track_ticket'"
                >
                    {{ $t('reservations.kiosk.tabs.track') }}
                </button>
            </div>

            <section v-if="activeMode === 'walk_in'" class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/60">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('reservations.kiosk.walk_in.title') }}</h2>
                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ $t('reservations.kiosk.walk_in.subtitle') }}</p>

                <form class="mt-3 grid gap-3 md:grid-cols-2" @submit.prevent="submitWalkIn">
                    <div>
                        <FloatingInput v-model="walkInForm.phone" :label="$t('reservations.kiosk.fields.phone')" />
                        <InputError class="mt-1" :message="walkInForm.errors.phone" />
                    </div>
                    <div>
                        <FloatingInput v-model="walkInForm.guest_name" :label="$t('reservations.kiosk.fields.guest_name')" />
                        <InputError class="mt-1" :message="walkInForm.errors.guest_name" />
                    </div>
                    <FloatingSelect v-model="walkInForm.service_id" :options="serviceOptions" :label="$t('reservations.client.book.fields.service')" />
                    <FloatingSelect v-model="walkInForm.team_member_id" :options="teamOptions" :label="$t('reservations.client.book.fields.team_member')" />
                    <div>
                        <FloatingInput v-model="walkInForm.estimated_duration_minutes" type="number" min="5" :label="$t('reservations.queue.client.estimated_duration')" />
                        <InputError class="mt-1" :message="walkInForm.errors.estimated_duration_minutes" />
                    </div>
                    <div>
                        <FloatingInput v-model="walkInForm.party_size" type="number" min="1" :label="$t('reservations.client.book.fields.party_size')" />
                        <InputError class="mt-1" :message="walkInForm.errors.party_size" />
                    </div>
                    <div class="md:col-span-2">
                        <FloatingTextarea v-model="walkInForm.notes" :label="$t('reservations.queue.client.notes')" />
                        <InputError class="mt-1" :message="walkInForm.errors.notes" />
                    </div>
                    <div class="md:col-span-2 flex justify-end">
                        <button type="submit" class="rounded-sm bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60" :disabled="walkInForm.processing">
                            {{ walkInForm.processing ? $t('reservations.client.book.actions.submitting') : $t('reservations.kiosk.walk_in.submit') }}
                        </button>
                    </div>
                </form>

                <div v-if="walkInError" class="mt-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">{{ walkInError }}</div>
                <div v-if="walkInSuccess" class="mt-3 rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">{{ walkInSuccess }}</div>

                <div v-if="walkInResult" class="mt-3 rounded-sm border border-cyan-200 bg-cyan-50 px-3 py-3 text-sm text-cyan-900 dark:border-cyan-400/30 dark:bg-cyan-500/10 dark:text-cyan-100">
                    <div class="font-semibold">{{ $t('reservations.kiosk.labels.ticket') }}: {{ walkInResult.queue_number }}</div>
                    <div class="mt-1 text-xs">
                        {{ $t('reservations.queue.columns.position') }}: {{ walkInResult.position ?? '-' }}
                        · ETA {{ walkInResult.eta_minutes !== null && walkInResult.eta_minutes !== undefined ? `${walkInResult.eta_minutes} min` : '-' }}
                    </div>
                </div>
            </section>

            <section v-else-if="activeMode === 'known_client'" class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/60">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('reservations.kiosk.known_client.title') }}</h2>
                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ $t('reservations.kiosk.known_client.subtitle') }}</p>

                <form class="mt-3 grid gap-3 md:grid-cols-3" @submit.prevent="lookupClient">
                    <div class="md:col-span-2">
                        <FloatingInput v-model="lookupForm.phone" :label="$t('reservations.kiosk.fields.phone')" />
                        <InputError class="mt-1" :message="lookupForm.errors.phone" />
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full rounded-sm bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60" :disabled="lookupForm.processing">
                            {{ lookupForm.processing ? $t('reservations.client.book.actions.submitting') : $t('reservations.kiosk.known_client.lookup') }}
                        </button>
                    </div>
                </form>

                <div v-if="lookupError" class="mt-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">{{ lookupError }}</div>
                <div v-if="lookupSuccess" class="mt-3 rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">{{ lookupSuccess }}</div>

                <div v-if="verificationRequired && !isVerifiedClientFlow" class="mt-3 rounded-sm border border-amber-200 bg-amber-50 p-3 dark:border-amber-400/30 dark:bg-amber-500/10">
                    <p class="text-xs text-amber-800 dark:text-amber-100">{{ $t('reservations.kiosk.known_client.verify_prompt') }}</p>
                    <p v-if="verificationDebugCode" class="mt-1 text-[11px] text-amber-700 dark:text-amber-200">
                        {{ $t('reservations.kiosk.known_client.debug_code') }}: <strong>{{ verificationDebugCode }}</strong>
                    </p>
                    <form class="mt-2 flex flex-wrap items-end gap-2" @submit.prevent="verifyClient">
                        <div class="min-w-[180px] flex-1">
                            <FloatingInput v-model="verifyForm.code" :label="$t('reservations.kiosk.fields.code')" />
                            <InputError class="mt-1" :message="verifyForm.errors.code" />
                        </div>
                        <button type="submit" class="rounded-sm bg-amber-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-60" :disabled="verifyForm.processing">
                            {{ verifyForm.processing ? $t('reservations.client.book.actions.submitting') : $t('reservations.kiosk.known_client.verify') }}
                        </button>
                    </form>
                </div>

                <div v-if="hasClientLookup && isVerifiedClientFlow" class="mt-3 space-y-3">
                    <div class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="font-semibold text-stone-800 dark:text-neutral-100">{{ lookupResult.client?.name }}</div>
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ lookupResult.client?.phone || lookupForm.phone }}</div>
                    </div>

                    <div v-if="hasNearbyReservation" class="rounded-sm border border-cyan-200 bg-cyan-50 p-3 text-sm text-cyan-900 dark:border-cyan-400/30 dark:bg-cyan-500/10 dark:text-cyan-100">
                        <p class="font-semibold">{{ $t('reservations.kiosk.known_client.reservation_ready') }}</p>
                        <p class="mt-1 text-xs">
                            {{ formatDateTime(lookupResult.intent.nearby_reservation?.starts_at) }}
                            · {{ queueStatusLabel(lookupResult.intent.nearby_reservation?.status || 'confirmed') }}
                        </p>
                        <button
                            type="button"
                            class="mt-2 rounded-sm bg-cyan-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-60"
                            :disabled="lookupForm.processing"
                            @click="checkInReservation"
                        >
                            {{ $t('reservations.kiosk.known_client.check_in') }}
                        </button>
                    </div>

                    <div v-else-if="canCreateClientTicket" class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('reservations.kiosk.known_client.create_ticket_help') }}</p>
                        <form class="mt-2 grid gap-2 md:grid-cols-2" @submit.prevent="createClientTicket">
                            <FloatingSelect v-model="clientTicketForm.service_id" :options="serviceOptions" :label="$t('reservations.client.book.fields.service')" />
                            <FloatingSelect v-model="clientTicketForm.team_member_id" :options="teamOptions" :label="$t('reservations.client.book.fields.team_member')" />
                            <FloatingInput v-model="clientTicketForm.estimated_duration_minutes" type="number" min="5" :label="$t('reservations.queue.client.estimated_duration')" />
                            <FloatingInput v-model="clientTicketForm.party_size" type="number" min="1" :label="$t('reservations.client.book.fields.party_size')" />
                            <div class="md:col-span-2">
                                <FloatingTextarea v-model="clientTicketForm.notes" :label="$t('reservations.queue.client.notes')" />
                                <InputError class="mt-1" :message="clientTicketForm.errors.service_id || clientTicketForm.errors.team_member_id || clientTicketForm.errors.estimated_duration_minutes || clientTicketForm.errors.party_size || clientTicketForm.errors.notes" />
                            </div>
                            <div class="md:col-span-2 flex justify-end">
                                <button type="submit" class="rounded-sm bg-indigo-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-60" :disabled="clientTicketForm.processing">
                                    {{ clientTicketForm.processing ? $t('reservations.client.book.actions.submitting') : $t('reservations.kiosk.known_client.create_ticket') }}
                                </button>
                            </div>
                        </form>
                    </div>

                    <div v-else-if="hasActiveClientTicket" class="rounded-sm border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-900 dark:border-indigo-400/30 dark:bg-indigo-500/10 dark:text-indigo-100">
                        <p class="font-semibold">{{ $t('reservations.kiosk.known_client.active_ticket') }}</p>
                        <p class="mt-1 text-xs">
                            {{ lookupResult.intent.active_ticket.queue_number }}
                            · {{ $t('reservations.queue.columns.position') }} {{ lookupResult.intent.active_ticket.position ?? '-' }}
                            · ETA {{ lookupResult.intent.active_ticket.eta_minutes !== null && lookupResult.intent.active_ticket.eta_minutes !== undefined ? `${lookupResult.intent.active_ticket.eta_minutes} min` : '-' }}
                        </p>
                    </div>
                </div>

                <div v-if="checkInError" class="mt-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">{{ checkInError }}</div>
                <div v-if="checkInSuccess" class="mt-3 rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">{{ checkInSuccess }}</div>
                <div v-if="checkInResult" class="mt-2 rounded-sm border border-cyan-200 bg-cyan-50 px-3 py-2 text-xs text-cyan-800 dark:border-cyan-400/30 dark:bg-cyan-500/10 dark:text-cyan-100">
                    {{ $t('reservations.kiosk.labels.ticket') }}: {{ checkInResult.queue_number }}
                    · {{ $t('reservations.queue.columns.position') }}: {{ checkInResult.position ?? '-' }}
                </div>
            </section>

            <section v-else class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/60">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('reservations.kiosk.track.title') }}</h2>
                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ $t('reservations.kiosk.track.subtitle') }}</p>

                <form class="mt-3 grid gap-3 md:grid-cols-3" @submit.prevent="trackTicket">
                    <div>
                        <FloatingInput v-model="trackForm.phone" :label="$t('reservations.kiosk.fields.phone')" />
                        <InputError class="mt-1" :message="trackForm.errors.phone" />
                    </div>
                    <div>
                        <FloatingInput v-model="trackForm.queue_number" :label="$t('reservations.kiosk.fields.queue_number')" />
                        <InputError class="mt-1" :message="trackForm.errors.queue_number" />
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full rounded-sm bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60" :disabled="trackForm.processing">
                            {{ trackForm.processing ? $t('reservations.client.book.actions.submitting') : $t('reservations.kiosk.track.submit') }}
                        </button>
                    </div>
                </form>

                <div v-if="trackError" class="mt-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">{{ trackError }}</div>
                <div v-if="trackResult" class="mt-3 rounded-sm border border-cyan-200 bg-cyan-50 px-3 py-3 text-sm text-cyan-900 dark:border-cyan-400/30 dark:bg-cyan-500/10 dark:text-cyan-100">
                    <div class="flex items-center justify-between gap-2">
                        <div class="font-semibold">{{ trackResult.queue_number }}</div>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="queueStatusClass(trackResult.status)">
                            {{ queueStatusLabel(trackResult.status) }}
                        </span>
                    </div>
                    <div class="mt-1 text-xs">{{ trackResult.service_name || '-' }} · {{ trackResult.team_member_name || '-' }}</div>
                    <div class="mt-1 text-xs">
                        {{ $t('reservations.queue.columns.position') }}: {{ trackResult.position ?? '-' }}
                        · ETA {{ trackResult.eta_minutes !== null && trackResult.eta_minutes !== undefined ? `${trackResult.eta_minutes} min` : '-' }}
                    </div>
                </div>
            </section>
        </div>
    </GuestLayout>
</template>
