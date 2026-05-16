<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import dayjs from 'dayjs';
import {
    ArrowRight,
    CalendarCheck2,
    ChevronDown,
    ChevronRight,
    Clock3,
    Globe2,
    Heart,
    ListChecks,
    ShieldCheck,
    TicketCheck,
    UsersRound,
} from 'lucide-vue-next';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
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
    { value: '', label: 'Sélectionner un service' },
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
    party_size: '1',
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
    party_size: '1',
    notes: '',
});

const trackForm = useForm({
    phone: '',
    queue_number: '',
});

const phoneCountryProfiles = {
    CA: {
        flag: '🇨🇦',
        dialCode: '+1',
        localPlaceholder: '(514) 555-0192',
        internationalPlaceholder: '+1 514 555 0192',
    },
    US: {
        flag: '🇺🇸',
        dialCode: '+1',
        localPlaceholder: '(212) 555-0192',
        internationalPlaceholder: '+1 212 555 0192',
    },
    FR: {
        flag: '🇫🇷',
        dialCode: '+33',
        localPlaceholder: '6 12 34 56 78',
        internationalPlaceholder: '+33 6 12 34 56 78',
    },
    BE: {
        flag: '🇧🇪',
        dialCode: '+32',
        localPlaceholder: '470 12 34 56',
        internationalPlaceholder: '+32 470 12 34 56',
    },
    CH: {
        flag: '🇨🇭',
        dialCode: '+41',
        localPlaceholder: '76 123 45 67',
        internationalPlaceholder: '+41 76 123 45 67',
    },
    SN: {
        flag: '🇸🇳',
        dialCode: '+221',
        localPlaceholder: '77 123 45 67',
        internationalPlaceholder: '+221 77 123 45 67',
    },
    CM: {
        flag: '🇨🇲',
        dialCode: '+237',
        localPlaceholder: '6 77 12 34 56',
        internationalPlaceholder: '+237 6 77 12 34 56',
    },
    CI: {
        flag: '🇨🇮',
        dialCode: '+225',
        localPlaceholder: '07 12 34 56 78',
        internationalPlaceholder: '+225 07 12 34 56 78',
    },
    MA: {
        flag: '🇲🇦',
        dialCode: '+212',
        localPlaceholder: '6 12 34 56 78',
        internationalPlaceholder: '+212 6 12 34 56 78',
    },
};

const countryAliases = {
    CANADA: 'CA',
    CAN: 'CA',
    CAD: 'CA',
    QUEBEC: 'CA',
    QC: 'CA',
    ON: 'CA',
    BC: 'CA',
    AB: 'CA',
    MB: 'CA',
    SK: 'CA',
    NS: 'CA',
    NB: 'CA',
    NL: 'CA',
    PE: 'CA',
    NT: 'CA',
    NU: 'CA',
    YT: 'CA',
    MONTREAL: 'CA',
    LAVAL: 'CA',
    TORONTO: 'CA',
    OTTAWA: 'CA',
    VANCOUVER: 'CA',
    'UNITED STATES': 'US',
    USA: 'US',
    USD: 'US',
    'ETATS UNIS': 'US',
    FRANCE: 'FR',
    EUR: 'FR',
    BELGIUM: 'BE',
    BELGIQUE: 'BE',
    SWITZERLAND: 'CH',
    SUISSE: 'CH',
    SENEGAL: 'SN',
    CAMEROON: 'CM',
    CAMEROUN: 'CM',
    'COTE D IVOIRE': 'CI',
    'COTE DIVOIRE': 'CI',
    MAROC: 'MA',
    MOROCCO: 'MA',
};

const normalizePhoneCountryText = (value) => String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-zA-Z0-9/+ ]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .toUpperCase();

const inferCountryFromPhone = (value) => {
    const phone = String(value || '').replace(/\s+/g, '');

    if (phone.startsWith('+221')) return 'SN';
    if (phone.startsWith('+237')) return 'CM';
    if (phone.startsWith('+225')) return 'CI';
    if (phone.startsWith('+212')) return 'MA';
    if (phone.startsWith('+33')) return 'FR';
    if (phone.startsWith('+32')) return 'BE';
    if (phone.startsWith('+41')) return 'CH';
    if (phone.startsWith('+1')) return 'CA';

    return '';
};

const inferCountryCode = (value) => {
    const normalized = normalizePhoneCountryText(value);

    if (!normalized) {
        return '';
    }

    if (phoneCountryProfiles[normalized]) {
        return normalized;
    }

    if (normalized.includes('AMERICA/TORONTO')
        || normalized.includes('AMERICA/MONTREAL')
        || normalized.includes('AMERICA/VANCOUVER')
        || normalized.includes('AMERICA/EDMONTON')
        || normalized.includes('AMERICA/WINNIPEG')
        || normalized.includes('AMERICA/HALIFAX')
        || normalized.includes('AMERICA/REGINA')
        || normalized.includes('AMERICA/ST JOHNS')) {
        return 'CA';
    }

    if (normalized.includes('AMERICA/NEW YORK')
        || normalized.includes('AMERICA/CHICAGO')
        || normalized.includes('AMERICA/LOS ANGELES')
        || normalized.includes('AMERICA/DENVER')) {
        return 'US';
    }

    if (normalized.includes('+')) {
        const phoneCountry = inferCountryFromPhone(normalized);

        if (phoneCountry) {
            return phoneCountry;
        }
    }

    if (countryAliases[normalized]) {
        return countryAliases[normalized];
    }

    const words = normalized.split(' ');
    for (let index = 0; index < words.length; index += 1) {
        const oneWord = words[index];
        const twoWords = `${oneWord} ${words[index + 1] || ''}`.trim();
        const threeWords = `${oneWord} ${words[index + 1] || ''} ${words[index + 2] || ''}`.trim();

        if (countryAliases[threeWords]) return countryAliases[threeWords];
        if (countryAliases[twoWords]) return countryAliases[twoWords];
        if (countryAliases[oneWord]) return countryAliases[oneWord];
    }

    return '';
};

const inferredPhoneCountryCode = computed(() => {
    const candidates = [
        props.company?.country_code,
        props.company?.country,
        props.company?.province,
        props.company?.city,
        props.company?.timezone,
        props.company?.currency_code,
        props.settings?.country_code,
        props.settings?.country,
        props.settings?.currency_code,
        props.company?.phone,
    ];

    for (const candidate of candidates) {
        const countryCode = inferCountryCode(candidate);

        if (countryCode) {
            return countryCode;
        }
    }

    return 'CA';
});

const phoneProfile = computed(() => phoneCountryProfiles[inferredPhoneCountryCode.value] || phoneCountryProfiles.CA);

const normalizeKioskPhonePayload = (value) => {
    const rawValue = String(value || '').trim();

    if (!rawValue || rawValue.startsWith('+')) {
        return rawValue;
    }

    const digits = rawValue.replace(/\D/g, '');

    if (!digits) {
        return rawValue;
    }

    const dialDigits = phoneProfile.value.dialCode.replace(/\D/g, '');

    if (digits.startsWith(dialDigits)) {
        return `+${digits}`;
    }

    return `${phoneProfile.value.dialCode}${digits}`;
};

const brandName = computed(() => 'Kiosque salon');
const defaultPortraitImageUrl = '/images/landing/stock/salon-front-desk.jpg';
const portraitImageUrl = computed(() => {
    const imageUrl = String(props.settings?.kiosk_image_url || '').trim();

    return imageUrl || defaultPortraitImageUrl;
});
const estimatedWait = computed(() => props.settings?.estimated_wait || {});
const estimatedWaitLabel = computed(() => String(estimatedWait.value?.label || '0 à 5 min'));
const estimatedWaitHelper = computed(() => String(estimatedWait.value?.helper || 'Mis à jour selon la file actuelle.'));
const selectedServiceLabel = computed(() => {
    const selected = serviceOptions.value.find((option) => option.value === String(walkInForm.service_id || clientTicketForm.service_id || ''));

    return selected?.value ? selected.label : 'Sélectionner un service';
});

const actionItems = [
    {
        key: 'walk_in',
        icon: TicketCheck,
        title: 'Prendre un ticket walk-in',
        subtitle: 'Je n’ai pas de rendez-vous',
        iconBoxClass: 'border-amber-100 bg-amber-50 text-amber-600',
        activeClass: 'border-amber-500 bg-amber-50/35 shadow-[inset_3px_0_0_#f59e0b]',
        inactiveClass: 'border-[#e5e7eb] hover:border-amber-200 hover:bg-amber-50/25',
    },
    {
        key: 'known_client',
        icon: CalendarCheck2,
        title: 'Signaler mon arrivée',
        subtitle: 'J’ai déjà une réservation',
        iconBoxClass: 'border-sky-100 bg-sky-50 text-sky-600',
        activeClass: 'border-sky-500 bg-sky-50/35 shadow-[inset_3px_0_0_#0ea5e9]',
        inactiveClass: 'border-[#e5e7eb] hover:border-sky-200 hover:bg-sky-50/25',
    },
    {
        key: 'track_ticket',
        icon: ListChecks,
        title: 'Suivre mon ticket',
        subtitle: 'Voir ma position dans la file',
        iconBoxClass: 'border-violet-100 bg-violet-50 text-violet-600',
        activeClass: 'border-violet-500 bg-violet-50/35 shadow-[inset_3px_0_0_#8b5cf6]',
        inactiveClass: 'border-[#e5e7eb] hover:border-violet-200 hover:bg-violet-50/25',
    },
];

const activeActionItem = computed(() => actionItems.find((item) => item.key === activeMode.value) || actionItems[0]);

const currentPreview = computed(() => {
    if (activeMode.value === 'known_client') {
        return {
            label: 'APERÇU',
            title: 'Signaler mon arrivée',
            description: 'Retrouvez votre réservation et confirmez votre présence.',
            icon: activeActionItem.value.icon,
            iconBoxClass: activeActionItem.value.iconBoxClass,
            submitLabel: lookupForm.processing ? 'Recherche...' : 'Rechercher ma réservation',
        };
    }

    if (activeMode.value === 'track_ticket') {
        return {
            label: 'APERÇU',
            title: 'Suivre mon ticket',
            description: 'Entrez votre téléphone et votre ticket pour suivre votre place.',
            icon: activeActionItem.value.icon,
            iconBoxClass: activeActionItem.value.iconBoxClass,
            submitLabel: trackForm.processing ? 'Recherche...' : 'Voir ma position',
        };
    }

    return {
        label: 'APERÇU',
        title: 'Prendre un ticket walk-in',
        description: 'Remplissez quelques informations pour obtenir votre ticket.',
        icon: activeActionItem.value.icon,
        iconBoxClass: activeActionItem.value.iconBoxClass,
        submitLabel: walkInForm.processing ? 'Création...' : 'Obtenir mon ticket',
    };
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
const hasKioskFeedback = computed(() => {
    if (activeMode.value === 'walk_in') {
        return Boolean(walkInError.value || walkInSuccess.value || walkInResult.value);
    }

    if (activeMode.value === 'known_client') {
        return Boolean(
            lookupError.value
            || lookupSuccess.value
            || (verificationRequired.value && !isVerifiedClientFlow.value)
            || (hasClientLookup.value && isVerifiedClientFlow.value)
            || checkInError.value
            || checkInSuccess.value
            || checkInResult.value,
        );
    }

    return Boolean(trackError.value || trackResult.value);
});

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

const setMode = (mode) => {
    activeMode.value = mode;
};

const continueAction = () => {
    const form = document.querySelector('[data-kiosk-form]');

    if (form) {
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        const firstInput = form.querySelector('input, select, button');
        firstInput?.focus?.();
    }
};

const applyDuplicateTicketState = (payload, target) => {
    const ticket = payload?.ticket || payload?.intent?.active_ticket || null;
    const message = payload?.message || t('reservations.kiosk.messages.active_ticket_exists');

    if (target === 'walk_in') {
        walkInResult.value = ticket;
        walkInSuccess.value = '';
        walkInError.value = message;
        trackForm.phone = normalizeKioskPhonePayload(walkInForm.phone);
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
            phone: normalizeKioskPhonePayload(walkInForm.phone),
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
        walkInForm.party_size = '1';
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
            phone: normalizeKioskPhonePayload(lookupForm.phone),
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
            phone: normalizeKioskPhonePayload(lookupForm.phone),
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
            phone: normalizeKioskPhonePayload(lookupForm.phone),
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
        clientTicketForm.party_size = '1';
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
            phone: normalizeKioskPhonePayload(lookupForm.phone),
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
            phone: normalizeKioskPhonePayload(trackForm.phone),
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
    <Head :title="brandName" />

    <main class="min-h-screen bg-[#f6f8f7] px-4 py-4 text-[#0f1720] lg:h-screen lg:min-h-0 lg:overflow-hidden lg:px-3 lg:py-[10px]">
        <div class="mx-auto max-w-[1640px] overflow-hidden rounded-sm border border-[#dfe8e2] bg-white shadow-[0_18px_55px_rgba(15,23,32,0.06)] lg:flex lg:h-full lg:flex-col">
            <header class="grid min-h-[96px] shrink-0 grid-cols-1 items-center gap-4 border-b border-[#e5e7eb] px-7 py-4 md:grid-cols-[1fr_auto_1fr] lg:h-[clamp(72px,10vh,96px)] lg:min-h-0 lg:px-7 lg:py-3 xl:px-9">
                <div class="flex flex-wrap items-center gap-5">
                    <h1 class="text-[23px] font-bold leading-none text-[#0f1720] lg:text-[clamp(21px,2.7vh,24px)]">
                        {{ brandName }}
                    </h1>
                    <span class="inline-flex items-center rounded-sm border border-[#dcebe3] bg-[#eef7f2] px-3 py-1.5 text-[12px] font-semibold text-[#0b7e55]">
                        Salon de coiffure / beauté
                    </span>
                </div>

                <div class="flex items-center justify-center md:justify-self-center" aria-label="Malikia Pro">
                    <ApplicationLogo class="h-10 w-40 lg:h-[clamp(32px,4.8vh,40px)] lg:w-[clamp(128px,18vh,160px)]" />
                </div>

                <div class="flex justify-start md:justify-end">
                    <button type="button" class="inline-flex h-10 items-center gap-2 rounded-sm border border-[#e5e7eb] bg-white px-3.5 text-[13px] font-semibold text-[#111827] shadow-[0_1px_2px_rgba(15,23,32,0.03)]">
                        <Globe2 class="size-4" aria-hidden="true" />
                        Français
                        <ChevronDown class="size-4" aria-hidden="true" />
                    </button>
                </div>
            </header>

            <div class="px-7 pb-5 pt-5 lg:flex lg:min-h-0 lg:flex-1 lg:flex-col lg:px-7 lg:pb-3 lg:pt-3 xl:px-9">
                <section class="grid gap-5 lg:h-[clamp(370px,54vh,500px)] lg:shrink-0 lg:grid-cols-[0.92fr_0.93fr_1.7fr] lg:items-stretch xl:grid-cols-[430px_374px_minmax(0,1fr)]">
                    <div class="flex min-h-[430px] flex-col justify-center py-4 lg:h-full lg:min-h-0 lg:pl-4">
                        <div class="space-y-7 lg:space-y-[clamp(18px,3vh,28px)]">
                            <div>
                                <h2 class="text-[30px] font-extrabold leading-[1.1] text-[#0f1720] sm:text-[36px] lg:text-[clamp(30px,4.3vh,38px)] 2xl:text-[39px]">
                                    <span class="sm:whitespace-nowrap">Bienvenue chez</span><br>
                                    <span class="text-[#0f9a68] sm:whitespace-nowrap">{{ brandName }}</span>
                                </h2>
                                <p class="mt-6 max-w-[430px] text-[15px] font-medium leading-7 text-[#334155] lg:mt-[clamp(16px,2.6vh,24px)] lg:text-[clamp(13px,1.8vh,15px)] 2xl:text-[15px]">
                                    <span class="sm:whitespace-nowrap">Prenez votre place, signalez votre arrivée</span><br class="hidden sm:block">
                                    <span class="sm:whitespace-nowrap">ou suivez votre ticket en quelques secondes.</span>
                                </p>
                            </div>

                            <div class="w-full max-w-[318px] rounded-sm border border-[#dcebe3] bg-[#f8fbf9] p-3.5 shadow-[0_12px_28px_rgba(15,23,32,0.035)] lg:p-[clamp(11px,1.6vh,14px)]">
                                <div class="flex gap-3.5">
                                    <div class="flex size-12 shrink-0 items-center justify-center rounded-sm border border-teal-100 bg-teal-50 text-teal-600 shadow-[0_8px_22px_rgba(15,154,104,0.08)] lg:h-[clamp(42px,6.2vh,48px)] lg:w-[clamp(42px,6.2vh,48px)]">
                                        <Clock3 class="size-7 lg:h-[clamp(23px,3.5vh,28px)] lg:w-[clamp(23px,3.5vh,28px)]" aria-hidden="true" />
                                    </div>
                                        <div>
                                            <p class="text-[13px] font-medium text-[#1f2937]">Temps d’attente estimé</p>
                                            <p class="mt-1.5 text-[24px] font-extrabold leading-none text-[#0b7e55] lg:text-[clamp(21px,3vh,24px)]">{{ estimatedWaitLabel }}</p>
                                            <p class="mt-2.5 text-[12px] font-medium text-[#475569]">{{ estimatedWaitHelper }}</p>
                                        </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <figure class="min-h-[430px] overflow-hidden rounded-sm border border-[#e5e7eb] bg-[#eef7f2] shadow-[0_16px_40px_rgba(15,23,32,0.05)] lg:h-full lg:min-h-0">
                        <img
                            :src="portraitImageUrl"
                            alt="Professionnelle de salon souriante"
                            class="h-full min-h-[430px] w-full object-cover object-center lg:min-h-0 lg:object-[52%_center]"
                            loading="eager"
                        >
                    </figure>

                    <section class="rounded-sm border border-[#dfe5e1] bg-white p-5 shadow-[0_12px_32px_rgba(15,23,32,0.035)] lg:h-full lg:p-[clamp(16px,2.2vh,20px)]">
                        <h2 class="text-[21px] font-extrabold leading-7 text-[#0f1720] lg:text-[clamp(19px,2.5vh,21px)]">
                            Que souhaitez-vous faire ?
                        </h2>

                        <div class="mt-5 space-y-3.5 lg:mt-4 lg:space-y-3">
                            <button
                                v-for="item in actionItems"
                                :key="item.key"
                                type="button"
                                class="group flex min-h-[84px] w-full items-center gap-4 rounded-sm border bg-white px-4 text-left transition focus:outline-none focus:ring-2 focus:ring-[#0f9a68]/25 lg:min-h-[clamp(68px,9.8vh,84px)]"
                                :class="activeMode === item.key ? item.activeClass : item.inactiveClass"
                                @click="setMode(item.key)"
                            >
                                <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-sm border lg:h-[clamp(44px,6.8vh,56px)] lg:w-[clamp(44px,6.8vh,56px)]" :class="item.iconBoxClass">
                                    <component :is="item.icon" class="size-7 lg:h-[clamp(23px,3.7vh,28px)] lg:w-[clamp(23px,3.7vh,28px)]" aria-hidden="true" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[15px] font-extrabold leading-5 text-[#0f1720] lg:text-[clamp(14px,2vh,15px)]">{{ item.title }}</span>
                                    <span class="mt-1 block text-[13px] font-medium text-[#4b5563]">{{ item.subtitle }}</span>
                                </span>
                                <ChevronRight class="size-6 shrink-0 text-[#0f1720] transition group-hover:translate-x-0.5" aria-hidden="true" />
                            </button>
                        </div>

                        <button
                            type="button"
                            class="mt-4 flex h-[48px] w-full items-center justify-center rounded-sm bg-[#0f9a68] px-5 text-[15px] font-extrabold text-white transition hover:bg-[#0b865b] focus:outline-none focus:ring-2 focus:ring-[#0f9a68]/30 lg:mt-3 lg:h-[46px]"
                            @click="continueAction"
                        >
                            <span class="flex-1 text-center">Continuer</span>
                            <ArrowRight class="size-6" aria-hidden="true" />
                        </button>
                    </section>
                </section>

                <section class="mt-4 rounded-sm border border-[#dfe5e1] bg-white p-3 shadow-[0_10px_26px_rgba(15,23,32,0.035)] lg:mt-3 lg:p-[clamp(10px,1.5vh,14px)]" data-kiosk-form>
                    <div class="grid gap-4 lg:grid-cols-[minmax(300px,360px)_minmax(0,1fr)] lg:gap-3 xl:grid-cols-[380px_minmax(0,1fr)] 2xl:grid-cols-[400px_minmax(0,1fr)]">
                        <div class="flex items-center gap-5 lg:border-r lg:border-[#dfe5e1] lg:pr-4">
                            <div class="flex h-[68px] w-[68px] shrink-0 items-center justify-center rounded-sm border lg:h-[clamp(52px,7vh,68px)] lg:w-[clamp(52px,7vh,68px)]" :class="currentPreview.iconBoxClass">
                                <component :is="currentPreview.icon" class="size-8 lg:h-[clamp(26px,4.2vh,34px)] lg:w-[clamp(26px,4.2vh,34px)]" aria-hidden="true" />
                            </div>
                            <div>
                                <p class="text-[11px] font-bold uppercase text-[#475569]">{{ currentPreview.label }}</p>
                                <h3 class="mt-1.5 text-[16px] font-extrabold leading-5 text-[#0f1720]">{{ currentPreview.title }}</h3>
                                <p class="mt-2 max-w-[270px] text-[12px] font-medium leading-5 text-[#475569]">{{ currentPreview.description }}</p>
                            </div>
                        </div>

                        <div>
                            <form v-if="activeMode === 'walk_in'" class="grid gap-3 xl:grid-cols-[1fr_1fr_0.72fr_auto]" @submit.prevent="submitWalkIn">
                                <div>
                                    <label for="walk-in-phone" class="text-[12px] font-semibold text-[#334155]">Numéro de téléphone <span class="text-[#dc2626]">*</span></label>
                                    <div class="mt-2 flex h-[44px] overflow-hidden rounded-sm border border-[#dfe5e1] bg-white text-[14px] text-[#334155] focus-within:border-[#0f9a68] focus-within:ring-2 focus-within:ring-[#0f9a68]/10">
                                        <span class="flex items-center gap-2 border-r border-[#dfe5e1] px-3">
                                            <span class="text-base">{{ phoneProfile.flag }}</span>
                                            <ChevronDown class="size-4 text-[#0f1720]" aria-hidden="true" />
                                        </span>
                                        <span class="flex items-center border-r border-[#dfe5e1] px-3 font-semibold">{{ phoneProfile.dialCode }}</span>
                                        <input id="walk-in-phone" v-model="walkInForm.phone" type="tel" class="min-w-0 flex-1 border-0 px-3 text-[14px] font-medium text-[#334155] placeholder:text-[#64748b] focus:ring-0" :placeholder="phoneProfile.localPlaceholder" autocomplete="tel">
                                    </div>
                                    <InputError class="mt-1" :message="walkInForm.errors.phone" />
                                </div>

                                <div>
                                    <label for="walk-in-service" class="text-[12px] font-semibold text-[#334155]">Service souhaité <span class="text-[#dc2626]">*</span></label>
                                    <div class="relative mt-2">
                                        <select id="walk-in-service" v-model="walkInForm.service_id" class="h-[44px] w-full appearance-none rounded-sm border-[#dfe5e1] bg-white px-3 pr-9 text-[14px] font-medium text-[#475569] focus:border-[#0f9a68] focus:ring-[#0f9a68]/20">
                                            <option v-for="option in serviceOptions" :key="option.value || 'empty'" :value="option.value">{{ option.label }}</option>
                                        </select>
                                        <ChevronDown class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-[#0f1720]" aria-hidden="true" />
                                    </div>
                                </div>

                                <div>
                                    <label for="walk-in-party" class="text-[12px] font-semibold text-[#334155]">Nombre de personnes</label>
                                    <div class="relative mt-2">
                                        <UsersRound class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[#0f1720]" aria-hidden="true" />
                                        <select id="walk-in-party" v-model="walkInForm.party_size" class="h-[44px] w-full appearance-none rounded-sm border-[#dfe5e1] bg-white pl-10 pr-9 text-[14px] font-semibold text-[#334155] focus:border-[#0f9a68] focus:ring-[#0f9a68]/20">
                                            <option value="1">1</option>
                                            <option value="2">2</option>
                                            <option value="3">3</option>
                                            <option value="4">4</option>
                                        </select>
                                        <ChevronDown class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-[#0f1720]" aria-hidden="true" />
                                    </div>
                                    <InputError class="mt-1" :message="walkInForm.errors.party_size" />
                                </div>

                                <div class="flex items-end">
                                    <button type="submit" class="h-[44px] rounded-sm bg-[#0f9a68] px-4 text-sm font-extrabold text-white transition hover:bg-[#0b865b] disabled:opacity-60" :disabled="walkInForm.processing">
                                        {{ currentPreview.submitLabel }}
                                    </button>
                                </div>
                            </form>

                            <form v-else-if="activeMode === 'known_client'" class="grid gap-3 xl:grid-cols-[1.1fr_1fr_auto]" @submit.prevent="lookupClient">
                                <div>
                                    <label for="lookup-phone" class="text-[12px] font-semibold text-[#334155]">Numéro de téléphone <span class="text-[#dc2626]">*</span></label>
                                    <input id="lookup-phone" v-model="lookupForm.phone" type="tel" class="mt-2 h-[44px] w-full rounded-sm border-[#dfe5e1] bg-white px-3 text-[14px] font-medium text-[#334155] placeholder:text-[#64748b] focus:border-[#0f9a68] focus:ring-[#0f9a68]/20" :placeholder="phoneProfile.internationalPlaceholder" autocomplete="tel">
                                    <InputError class="mt-1" :message="lookupForm.errors.phone" />
                                </div>
                                <div>
                                    <label for="lookup-service-preview" class="text-[12px] font-semibold text-[#334155]">Service souhaité</label>
                                    <div id="lookup-service-preview" class="mt-2 flex h-[44px] items-center rounded-sm border border-[#dfe5e1] bg-white px-3 text-[14px] font-medium text-[#64748b]">{{ selectedServiceLabel }}</div>
                                </div>
                                <div class="flex items-end">
                                    <button type="submit" class="h-[44px] rounded-sm bg-[#0f9a68] px-4 text-sm font-extrabold text-white transition hover:bg-[#0b865b] disabled:opacity-60" :disabled="lookupForm.processing">
                                        {{ currentPreview.submitLabel }}
                                    </button>
                                </div>
                            </form>

                            <form v-else class="grid gap-3 xl:grid-cols-[1.1fr_0.92fr_auto]" @submit.prevent="trackTicket">
                                <div>
                                    <label for="track-phone" class="text-[12px] font-semibold text-[#334155]">Numéro de téléphone <span class="text-[#dc2626]">*</span></label>
                                    <input id="track-phone" v-model="trackForm.phone" type="tel" class="mt-2 h-[44px] w-full rounded-sm border-[#dfe5e1] bg-white px-3 text-[14px] font-medium text-[#334155] placeholder:text-[#64748b] focus:border-[#0f9a68] focus:ring-[#0f9a68]/20" :placeholder="phoneProfile.internationalPlaceholder" autocomplete="tel">
                                    <InputError class="mt-1" :message="trackForm.errors.phone" />
                                </div>
                                <div>
                                    <label for="track-number" class="text-[12px] font-semibold text-[#334155]">Numéro de ticket</label>
                                    <input id="track-number" v-model="trackForm.queue_number" type="text" class="mt-2 h-[44px] w-full rounded-sm border-[#dfe5e1] bg-white px-3 text-[14px] font-medium text-[#334155] placeholder:text-[#64748b] focus:border-[#0f9a68] focus:ring-[#0f9a68]/20" placeholder="A-001">
                                    <InputError class="mt-1" :message="trackForm.errors.queue_number" />
                                </div>
                                <div class="flex items-end">
                                    <button type="submit" class="h-[44px] rounded-sm bg-[#0f9a68] px-4 text-sm font-extrabold text-white transition hover:bg-[#0b865b] disabled:opacity-60" :disabled="trackForm.processing">
                                        {{ currentPreview.submitLabel }}
                                    </button>
                                </div>
                            </form>

                            <div class="mt-3 flex items-center gap-3 rounded-sm border border-[#dcebe3] bg-[#f4faf6] px-4 py-2 text-[12px] font-medium text-[#334155]">
                                <ShieldCheck class="size-5 shrink-0 text-[#0f9a68]" aria-hidden="true" />
                                Vos informations sont sécurisées et utilisées uniquement pour la gestion de votre attente.
                            </div>
                        </div>
                    </div>

                    <div v-if="hasKioskFeedback" class="mt-4 space-y-3">
                        <div v-if="walkInError && activeMode === 'walk_in'" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ walkInError }}</div>
                        <div v-if="walkInSuccess && activeMode === 'walk_in'" class="rounded-sm border border-[#dcebe3] bg-[#eef7f2] px-3 py-2 text-sm text-[#0b7e55]">{{ walkInSuccess }}</div>
                        <div v-if="walkInResult && activeMode === 'walk_in'" class="rounded-sm border border-[#dcebe3] bg-white px-4 py-3 text-sm text-[#334155]">
                            <div class="font-extrabold text-[#0f1720]">Ticket: {{ walkInResult.queue_number }}</div>
                            <div class="mt-1 text-xs text-[#64748b]">
                                Position: {{ walkInResult.position ?? '-' }} · ETA {{ walkInResult.eta_minutes !== null && walkInResult.eta_minutes !== undefined ? `${walkInResult.eta_minutes} min` : '-' }}
                            </div>
                        </div>

                        <div v-if="lookupError && activeMode === 'known_client'" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ lookupError }}</div>
                        <div v-if="lookupSuccess && activeMode === 'known_client'" class="rounded-sm border border-[#dcebe3] bg-[#eef7f2] px-3 py-2 text-sm text-[#0b7e55]">{{ lookupSuccess }}</div>

                        <div v-if="verificationRequired && !isVerifiedClientFlow && activeMode === 'known_client'" class="rounded-sm border border-amber-200 bg-amber-50 p-3">
                            <p class="text-sm font-medium text-amber-800">{{ $t('reservations.kiosk.known_client.verify_prompt') }}</p>
                            <p v-if="verificationDebugCode" class="mt-1 text-xs text-amber-700">
                                {{ $t('reservations.kiosk.known_client.debug_code') }}: <strong>{{ verificationDebugCode }}</strong>
                            </p>
                            <form class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="verifyClient">
                                <div class="min-w-[190px] flex-1">
                                    <label for="verification-code" class="text-[12px] font-semibold text-[#334155]">{{ $t('reservations.kiosk.fields.code') }}</label>
                                    <input id="verification-code" v-model="verifyForm.code" class="mt-2 h-[44px] w-full rounded-sm border-[#dfe5e1] bg-white px-3 text-sm focus:border-[#0f9a68] focus:ring-[#0f9a68]/20">
                                    <InputError class="mt-1" :message="verifyForm.errors.code" />
                                </div>
                                <button type="submit" class="h-[44px] rounded-sm bg-amber-600 px-4 text-xs font-extrabold text-white disabled:opacity-60" :disabled="verifyForm.processing">
                                    {{ verifyForm.processing ? $t('reservations.client.book.actions.submitting') : $t('reservations.kiosk.known_client.verify') }}
                                </button>
                            </form>
                        </div>

                        <div v-if="hasClientLookup && isVerifiedClientFlow && activeMode === 'known_client'" class="grid gap-3 lg:grid-cols-2">
                            <div class="rounded-sm border border-[#dfe5e1] bg-white px-4 py-3 text-sm">
                                <div class="font-extrabold text-[#0f1720]">{{ lookupResult.client?.name }}</div>
                                <div class="mt-1 text-xs text-[#64748b]">{{ lookupResult.client?.phone || lookupForm.phone }}</div>
                            </div>

                            <div v-if="hasNearbyReservation" class="rounded-sm border border-[#dcebe3] bg-[#eef7f2] p-3 text-sm text-[#0f1720]">
                                <p class="font-extrabold">{{ $t('reservations.kiosk.known_client.reservation_ready') }}</p>
                                <p class="mt-1 text-xs text-[#475569]">
                                    {{ formatDateTime(lookupResult.intent.nearby_reservation?.starts_at) }}
                                    · {{ queueStatusLabel(lookupResult.intent.nearby_reservation?.status || 'confirmed') }}
                                </p>
                                <button type="button" class="mt-3 rounded-sm bg-[#0f9a68] px-3 py-2 text-xs font-extrabold text-white disabled:opacity-60" :disabled="lookupForm.processing" @click="checkInReservation">
                                    {{ $t('reservations.kiosk.known_client.check_in') }}
                                </button>
                            </div>

                            <form v-else-if="canCreateClientTicket" class="rounded-sm border border-[#dfe5e1] bg-white p-3 text-sm lg:col-span-2" @submit.prevent="createClientTicket">
                                <p class="text-xs font-medium text-[#64748b]">{{ $t('reservations.kiosk.known_client.create_ticket_help') }}</p>
                                <div class="mt-3 grid gap-3 md:grid-cols-4">
                                    <select v-model="clientTicketForm.service_id" class="h-[44px] rounded-sm border-[#dfe5e1] text-sm focus:border-[#0f9a68] focus:ring-[#0f9a68]/20">
                                        <option v-for="option in serviceOptions" :key="`client-${option.value || 'empty'}`" :value="option.value">{{ option.label }}</option>
                                    </select>
                                    <select v-model="clientTicketForm.team_member_id" class="h-[44px] rounded-sm border-[#dfe5e1] text-sm focus:border-[#0f9a68] focus:ring-[#0f9a68]/20">
                                        <option v-for="option in teamOptions" :key="`team-${option.value || 'empty'}`" :value="option.value">{{ option.label }}</option>
                                    </select>
                                    <select v-model="clientTicketForm.party_size" class="h-[44px] rounded-sm border-[#dfe5e1] text-sm focus:border-[#0f9a68] focus:ring-[#0f9a68]/20">
                                        <option value="1">1 personne</option>
                                        <option value="2">2 personnes</option>
                                        <option value="3">3 personnes</option>
                                        <option value="4">4 personnes</option>
                                    </select>
                                    <button type="submit" class="h-[44px] rounded-sm bg-[#0f9a68] px-4 text-xs font-extrabold text-white disabled:opacity-60" :disabled="clientTicketForm.processing">
                                        {{ clientTicketForm.processing ? $t('reservations.client.book.actions.submitting') : $t('reservations.kiosk.known_client.create_ticket') }}
                                    </button>
                                </div>
                            </form>

                            <div v-else-if="hasActiveClientTicket" class="rounded-sm border border-[#dcebe3] bg-[#eef7f2] p-3 text-sm text-[#0f1720] lg:col-span-2">
                                <p class="font-extrabold">{{ $t('reservations.kiosk.known_client.active_ticket') }}</p>
                                <p class="mt-1 text-xs text-[#475569]">
                                    {{ lookupResult.intent.active_ticket.queue_number }}
                                    · Position {{ lookupResult.intent.active_ticket.position ?? '-' }}
                                    · ETA {{ lookupResult.intent.active_ticket.eta_minutes !== null && lookupResult.intent.active_ticket.eta_minutes !== undefined ? `${lookupResult.intent.active_ticket.eta_minutes} min` : '-' }}
                                </p>
                            </div>
                        </div>

                        <div v-if="checkInError && activeMode === 'known_client'" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ checkInError }}</div>
                        <div v-if="checkInSuccess && activeMode === 'known_client'" class="rounded-sm border border-[#dcebe3] bg-[#eef7f2] px-3 py-2 text-sm text-[#0b7e55]">{{ checkInSuccess }}</div>
                        <div v-if="checkInResult && activeMode === 'known_client'" class="rounded-sm border border-[#dcebe3] bg-white px-3 py-2 text-sm text-[#334155]">
                            Ticket: {{ checkInResult.queue_number }} · Position: {{ checkInResult.position ?? '-' }}
                        </div>

                        <div v-if="trackError && activeMode === 'track_ticket'" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ trackError }}</div>
                        <div v-if="trackResult && activeMode === 'track_ticket'" class="rounded-sm border border-[#dcebe3] bg-[#eef7f2] px-4 py-3 text-sm text-[#0f1720]">
                            <div class="flex items-center justify-between gap-2">
                                <div class="font-extrabold">{{ trackResult.queue_number }}</div>
                                <span class="rounded-sm px-2 py-0.5 text-[11px] font-bold capitalize" :class="queueStatusClass(trackResult.status)">
                                    {{ queueStatusLabel(trackResult.status) }}
                                </span>
                            </div>
                            <div class="mt-1 text-xs text-[#475569]">{{ trackResult.service_name || '-' }} · {{ trackResult.team_member_name || '-' }}</div>
                            <div class="mt-1 text-xs text-[#475569]">
                                Position: {{ trackResult.position ?? '-' }}
                                · ETA {{ trackResult.eta_minutes !== null && trackResult.eta_minutes !== undefined ? `${trackResult.eta_minutes} min` : '-' }}
                            </div>
                        </div>
                    </div>
                </section>

                <footer class="py-2 text-center text-[12px] font-medium text-[#475569]">
                    Merci de votre confiance. Nous faisons de notre mieux pour vous offrir la meilleure expérience.
                    <Heart class="ml-2 inline size-4 fill-[#0f9a68] align-[-2px] text-[#0f9a68]" aria-hidden="true" />
                </footer>
            </div>
        </div>
    </main>
</template>
