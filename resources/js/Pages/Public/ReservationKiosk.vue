<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import dayjs from 'dayjs';
import {
    ArrowLeft,
    ArrowRight,
    CalendarCheck2,
    CheckCircle2,
    Clock3,
    ListChecks,
    RotateCcw,
    ShieldCheck,
    TicketCheck,
} from 'lucide-vue-next';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import InputError from '@/Components/InputError.vue';
import { reservationStatusBadgeClass } from '@/Components/Reservation/status';
import LanguageSwitcherMenu from '@/Components/UI/LanguageSwitcherMenu.vue';
import PublicKioskLayout from '@/Layouts/PublicKioskLayout.vue';

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
    public_navigation: {
        type: Object,
        default: () => ({
            booking_url: null,
        }),
    },
});

const activeMode = ref('');
const currentJourneyStep = ref(0);
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
const walkInProcessing = ref(false);
const lookupProcessing = ref(false);
const verifyProcessing = ref(false);
const clientTicketProcessing = ref(false);
const trackProcessing = ref(false);

const KIOSK_REFRESH_INTERVAL_MS = 30_000;
const KIOSK_PRIVACY_RESET_MS = 60_000;
let kioskRefreshTimer = null;
let kioskRefreshInFlight = false;
let kioskPrivacyResetTimer = null;
let kioskJourneyRevision = 0;
const kioskRequests = new Set();

const refreshKioskSummary = () => {
    if (kioskRefreshInFlight || document.visibilityState === 'hidden') {
        return;
    }

    kioskRefreshInFlight = true;

    router.reload({
        only: ['settings'],
        preserveState: true,
        preserveScroll: true,
        onFinish: () => {
            kioskRefreshInFlight = false;
        },
    });
};

const stopKioskRefresh = () => {
    if (!kioskRefreshTimer) {
        return;
    }

    window.clearInterval(kioskRefreshTimer);
    kioskRefreshTimer = null;
};

const startKioskRefresh = () => {
    if (kioskRefreshTimer || document.visibilityState === 'hidden') {
        return;
    }

    kioskRefreshTimer = window.setInterval(refreshKioskSummary, KIOSK_REFRESH_INTERVAL_MS);
};

const handleKioskVisibilityChange = () => {
    if (document.visibilityState === 'hidden') {
        stopKioskRefresh();
        return;
    }

    refreshKioskSummary();
    startKioskRefresh();
};

onMounted(() => {
    document.addEventListener('visibilitychange', handleKioskVisibilityChange);
    startKioskRefresh();
});

onBeforeUnmount(() => {
    stopKioskRefresh();
    cancelKioskRequests();
    if (kioskPrivacyResetTimer) {
        window.clearTimeout(kioskPrivacyResetTimer);
    }
    document.removeEventListener('visibilitychange', handleKioskVisibilityChange);
});

const concreteServiceOptions = computed(() => (props.services || []).map((service) => ({
        value: String(service.id),
        label: service.name,
        price: service.price ?? null,
    })));

const serviceOptions = computed(() => [
    { value: '', label: t('reservations.kiosk.fields.any_service') },
    ...concreteServiceOptions.value,
]);

const partySizeOptions = computed(() => [1, 2, 3, 4].map((value) => ({
    value: String(value),
    label: String(value),
})));

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

const startKioskRequest = (processingState) => {
    if (processingState.value) {
        return null;
    }

    const request = {
        controller: new AbortController(),
        processingState,
        revision: kioskJourneyRevision,
    };

    processingState.value = true;
    kioskRequests.add(request);

    return request;
};

const isCurrentKioskRequest = (request) => Boolean(
    request
    && request.revision === kioskJourneyRevision
    && !request.controller.signal.aborted,
);

const finishKioskRequest = (request) => {
    kioskRequests.delete(request);

    if (request.revision === kioskJourneyRevision) {
        request.processingState.value = false;
    }
};

const cancelKioskRequests = () => {
    kioskJourneyRevision += 1;

    kioskRequests.forEach((request) => request.controller.abort());
    kioskRequests.clear();

    walkInProcessing.value = false;
    lookupProcessing.value = false;
    verifyProcessing.value = false;
    clientTicketProcessing.value = false;
    trackProcessing.value = false;
};

const isCanceledKioskRequest = (error, request) => !isCurrentKioskRequest(request)
    || axios.isCancel(error)
    || error?.code === 'ERR_CANCELED';

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

const kioskTitle = computed(() => t('reservations.kiosk.title'));
const companyName = computed(() => String(props.company?.name || '').trim() || kioskTitle.value);
const brandName = computed(() => companyName.value);
const publicBookingHref = computed(() => String(props.public_navigation?.booking_url || '').trim());
const defaultPortraitImageUrl = '/images/landing/stock/salon-front-desk.jpg';
const portraitImageUrl = computed(() => {
    const imageUrl = String(props.settings?.kiosk_image_url || '').trim();

    return imageUrl || defaultPortraitImageUrl;
});
const estimatedWait = computed(() => props.settings?.estimated_wait || {});
const estimatedWaitLabel = computed(() => String(estimatedWait.value?.label || '0 à 5 min'));
const estimatedWaitHelper = computed(() => String(estimatedWait.value?.helper || 'Mis à jour selon la file actuelle.'));
const waitingCount = computed(() => Number(estimatedWait.value?.waiting_count || 0));
const inServiceCount = computed(() => Number(estimatedWait.value?.in_service_count || 0));
const actionItems = computed(() => [
    {
        key: 'walk_in',
        icon: TicketCheck,
        title: t('reservations.kiosk.walk_in.title'),
        subtitle: t('reservations.kiosk.actions.walk_in_subtitle'),
        iconBoxClass: 'border-amber-100 bg-amber-50 text-amber-600',
    },
    {
        key: 'known_client',
        icon: CalendarCheck2,
        title: t('reservations.kiosk.actions.check_in_title'),
        subtitle: t('reservations.kiosk.actions.check_in_subtitle'),
        iconBoxClass: 'border-sky-100 bg-sky-50 text-sky-600',
    },
    {
        key: 'track_ticket',
        icon: ListChecks,
        title: t('reservations.kiosk.track.title'),
        subtitle: t('reservations.kiosk.actions.track_subtitle'),
        iconBoxClass: 'border-violet-100 bg-violet-50 text-violet-600',
    },
]);

const activeActionItem = computed(() => actionItems.value.find((item) => item.key === activeMode.value) || null);

const currentPreview = computed(() => {
    if (!activeActionItem.value) {
        return {
            label: kioskTitle.value,
            title: t('reservations.kiosk.actions.title'),
            description: t('reservations.kiosk.subtitle'),
            icon: TicketCheck,
            iconBoxClass: 'border-emerald-100 bg-emerald-50 text-emerald-700',
            submitLabel: t('reservations.client.book.actions.continue'),
        };
    }

    if (activeMode.value === 'known_client') {
        return {
            label: t('reservations.kiosk.preview.label'),
            title: t('reservations.kiosk.actions.check_in_title'),
            description: t('reservations.kiosk.preview.check_in_description'),
            icon: activeActionItem.value.icon,
            iconBoxClass: activeActionItem.value.iconBoxClass,
            submitLabel: lookupProcessing.value ? t('reservations.kiosk.actions.searching') : t('reservations.kiosk.known_client.lookup'),
        };
    }

    if (activeMode.value === 'track_ticket') {
        return {
            label: t('reservations.kiosk.preview.label'),
            title: t('reservations.kiosk.track.title'),
            description: t('reservations.kiosk.preview.track_description'),
            icon: activeActionItem.value.icon,
            iconBoxClass: activeActionItem.value.iconBoxClass,
            submitLabel: trackProcessing.value ? t('reservations.kiosk.actions.searching') : t('reservations.kiosk.track.submit'),
        };
    }

    return {
        label: t('reservations.kiosk.preview.label'),
        title: t('reservations.kiosk.walk_in.title'),
        description: t('reservations.kiosk.preview.walk_in_description'),
        icon: activeActionItem.value.icon,
        iconBoxClass: activeActionItem.value.iconBoxClass,
        submitLabel: walkInProcessing.value ? t('reservations.kiosk.actions.creating') : t('reservations.kiosk.walk_in.submit'),
    };
});

const journeySteps = computed(() => [
    {
        key: 'choice',
        label: t('reservations.kiosk.actions.title'),
    },
    {
        key: 'details',
        label: activeActionItem.value?.title || t('reservations.kiosk.preview.label'),
    },
    {
        key: 'result',
        label: activeMode.value === 'track_ticket'
            ? t('reservations.kiosk.track.title')
            : activeMode.value === 'known_client'
                ? t('reservations.kiosk.actions.check_in_title')
                : t('reservations.kiosk.labels.ticket'),
    },
]);

const queueStatusClass = (status) => reservationStatusBadgeClass(status);
const queueStatusLabel = (status) => t(`reservations.queue.status.${status}`) || status;
const nextAction = computed(() => String(lookupResult.value?.intent?.next_action || ''));
const hasClientLookup = computed(() => Boolean(lookupResult.value?.found));
const verificationRequired = computed(() => Boolean(lookupResult.value?.verification_required));
const isVerifiedClientFlow = computed(() => Boolean(lookupResult.value?.verified));
const canCreateClientTicket = computed(() => nextAction.value === 'take_ticket');
const hasActiveClientTicket = computed(() => nextAction.value === 'track_ticket' && lookupResult.value?.intent?.active_ticket);
const hasNearbyReservation = computed(() => nextAction.value === 'check_in' && lookupResult.value?.intent?.nearby_reservation);
const isJourneyComplete = computed(() => {
    if (activeMode.value === 'walk_in') {
        return Boolean(walkInResult.value);
    }

    if (activeMode.value === 'track_ticket') {
        return Boolean(trackResult.value);
    }

    return Boolean(checkInResult.value || hasActiveClientTicket.value);
});
const journeyTicket = computed(() => {
    if (activeMode.value === 'walk_in') {
        return walkInResult.value;
    }

    if (activeMode.value === 'track_ticket') {
        return trackResult.value;
    }

    return checkInResult.value || lookupResult.value?.intent?.active_ticket || null;
});
const journeyResultTitle = computed(() => {
    if (activeMode.value === 'track_ticket') {
        return t('reservations.kiosk.guided.tracking_title');
    }

    if (activeMode.value === 'known_client') {
        return t('reservations.kiosk.guided.arrival_title');
    }

    return t('reservations.kiosk.guided.ticket_title');
});
const currentJourneyHeadingId = computed(() => {
    if (currentJourneyStep.value === 0) {
        return 'reservation-kiosk-actions-title';
    }

    if (currentJourneyStep.value === 1) {
        return 'reservation-kiosk-form-title';
    }

    return 'reservation-kiosk-result-title';
});
const hasKioskFeedback = computed(() => Boolean(
    walkInError.value
    || walkInSuccess.value
    || walkInResult.value
    || lookupError.value
    || lookupSuccess.value
    || checkInError.value
    || checkInSuccess.value
    || checkInResult.value
    || trackError.value
    || trackResult.value,
));

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

const focusJourneyHeading = async () => {
    await nextTick();
    document.querySelector('[data-kiosk-step-heading]')?.focus?.();
};

const focusActiveForm = async () => {
    await nextTick();
    const form = document.querySelector('[data-kiosk-active-form]');

    if (form) {
        form.scrollIntoView({ behavior: 'auto', block: 'center' });
        const firstInput = form.querySelector('input, select, button');
        firstInput?.focus?.();
    }
};

const stopPrivacyReset = () => {
    if (!kioskPrivacyResetTimer) {
        return;
    }

    window.clearTimeout(kioskPrivacyResetTimer);
    kioskPrivacyResetTimer = null;
};

const resetKioskJourney = async () => {
    stopPrivacyReset();
    cancelKioskRequests();

    activeMode.value = '';
    currentJourneyStep.value = 0;
    walkInResult.value = null;
    walkInError.value = '';
    walkInSuccess.value = '';
    lookupError.value = '';
    lookupSuccess.value = '';
    lookupResult.value = null;
    checkInError.value = '';
    checkInSuccess.value = '';
    checkInResult.value = null;
    trackError.value = '';
    trackResult.value = null;
    verificationDebugCode.value = '';
    verifiedCode.value = '';

    walkInForm.reset();
    lookupForm.reset();
    verifyForm.reset();
    clientTicketForm.reset();
    trackForm.reset();
    walkInForm.clearErrors();
    lookupForm.clearErrors();
    verifyForm.clearErrors();
    clientTicketForm.clearErrors();
    trackForm.clearErrors();

    await nextTick();
    document.querySelector('[data-kiosk-choice-heading]')?.focus?.();
};

const schedulePrivacyReset = () => {
    stopPrivacyReset();

    if (currentJourneyStep.value === 0) {
        return;
    }

    kioskPrivacyResetTimer = window.setTimeout(resetKioskJourney, KIOSK_PRIVACY_RESET_MS);
};

const showJourneyResult = () => {
    currentJourneyStep.value = 2;
    schedulePrivacyReset();
    focusJourneyHeading();
};

const setMode = (mode) => {
    activeMode.value = mode;
};

const continueAction = () => {
    if (!activeMode.value) {
        return;
    }

    currentJourneyStep.value = 1;
    schedulePrivacyReset();
    focusActiveForm();
};

const returnToPreviousStep = () => {
    if (currentJourneyStep.value <= 1) {
        resetKioskJourney();
        return;
    }

    cancelKioskRequests();

    if (activeMode.value === 'known_client' && !isJourneyComplete.value) {
        lookupResult.value = null;
        lookupSuccess.value = '';
        lookupError.value = '';
        checkInError.value = '';
        verificationDebugCode.value = '';
        verifiedCode.value = '';
        verifyForm.reset();
        verifyForm.clearErrors();
    }

    currentJourneyStep.value = 1;
    schedulePrivacyReset();
    focusActiveForm();
};

const switchToWalkIn = () => {
    const phone = lookupForm.phone;

    activeMode.value = 'walk_in';
    currentJourneyStep.value = 1;
    walkInForm.phone = phone;
    lookupResult.value = null;
    lookupSuccess.value = '';
    lookupError.value = '';
    schedulePrivacyReset();
    focusActiveForm();
};

const applyDuplicateTicketState = (payload, target) => {
    const ticket = payload?.ticket || payload?.intent?.active_ticket || null;
    const message = t('reservations.kiosk.messages.active_ticket_exists');

    if (target === 'walk_in') {
        walkInResult.value = ticket;
        walkInSuccess.value = '';
        walkInError.value = message;
        trackForm.phone = normalizeKioskPhonePayload(walkInForm.phone);
        trackForm.queue_number = ticket?.queue_number || '';
        showJourneyResult();
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
    schedulePrivacyReset();
    focusJourneyHeading();
};

const submitWalkIn = async () => {
    const request = startKioskRequest(walkInProcessing);

    if (!request) {
        return;
    }

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
            signal: request.controller.signal,
        });

        if (!isCurrentKioskRequest(request)) {
            return;
        }

        walkInResult.value = response?.data?.ticket || null;
        walkInSuccess.value = t('reservations.kiosk.messages.ticket_created');
        walkInForm.reset('guest_name', 'service_id', 'team_member_id', 'estimated_duration_minutes', 'party_size', 'notes');
        walkInForm.party_size = '1';
        showJourneyResult();
    } catch (error) {
        if (isCanceledKioskRequest(error, request)) {
            return;
        }

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
    } finally {
        finishKioskRequest(request);
    }
};

const lookupClient = async () => {
    const request = startKioskRequest(lookupProcessing);

    if (!request) {
        return;
    }

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
            signal: request.controller.signal,
        });

        if (!isCurrentKioskRequest(request)) {
            return;
        }

        lookupResult.value = response?.data || null;
        verificationDebugCode.value = response?.data?.verification?.debug_code || '';

        if (!response?.data?.found) {
            lookupSuccess.value = t('reservations.kiosk.messages.client_not_found');
            showJourneyResult();
            return;
        }
        if (response?.data?.verification_required && !response?.data?.verified) {
            lookupSuccess.value = t('reservations.kiosk.messages.code_sent');
            schedulePrivacyReset();
            await nextTick();
            document.querySelector('#verification-code')?.focus?.();
            return;
        }
        lookupSuccess.value = t('reservations.kiosk.messages.client_found');
        showJourneyResult();
    } catch (error) {
        if (isCanceledKioskRequest(error, request)) {
            return;
        }

        if (error?.response?.status === 422) {
            lookupForm.setError(error.response.data?.errors || {});
            lookupError.value = t('reservations.errors.validation');
            return;
        }
        lookupError.value = normalizeError(error, t('reservations.kiosk.errors.lookup'));
    } finally {
        finishKioskRequest(request);
    }
};

const verifyClient = async () => {
    if (!lookupForm.phone) {
        lookupError.value = t('reservations.kiosk.errors.lookup_first');
        return;
    }

    const request = startKioskRequest(verifyProcessing);

    if (!request) {
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
            signal: request.controller.signal,
        });

        if (!isCurrentKioskRequest(request)) {
            return;
        }

        lookupResult.value = response?.data || null;
        verifiedCode.value = verifyForm.code;
        lookupSuccess.value = t('reservations.kiosk.messages.phone_verified');
        showJourneyResult();
    } catch (error) {
        if (isCanceledKioskRequest(error, request)) {
            return;
        }

        if (error?.response?.status === 422) {
            verifyForm.setError(error.response.data?.errors || {});
            lookupError.value = t('reservations.errors.validation');
            return;
        }
        lookupError.value = normalizeError(error, t('reservations.kiosk.errors.verify'));
    } finally {
        finishKioskRequest(request);
    }
};

const createClientTicket = async () => {
    if (!lookupForm.phone) {
        lookupError.value = t('reservations.kiosk.errors.lookup_first');
        return;
    }

    const request = startKioskRequest(clientTicketProcessing);

    if (!request) {
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
            signal: request.controller.signal,
        });

        if (!isCurrentKioskRequest(request)) {
            return;
        }

        lookupResult.value = {
            ...(lookupResult.value || {}),
            intent: {
                ...(lookupResult.value?.intent || {}),
                next_action: 'track_ticket',
                active_ticket: response?.data?.ticket || null,
            },
        };
        checkInResult.value = response?.data?.ticket || null;
        checkInSuccess.value = t('reservations.kiosk.messages.ticket_created');
        clientTicketForm.reset();
        clientTicketForm.party_size = '1';
        schedulePrivacyReset();
        focusJourneyHeading();
    } catch (error) {
        if (isCanceledKioskRequest(error, request)) {
            return;
        }

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
    } finally {
        finishKioskRequest(request);
    }
};

const checkInReservation = async () => {
    if (!lookupForm.phone) {
        checkInError.value = t('reservations.kiosk.errors.lookup_first');
        return;
    }

    const request = startKioskRequest(lookupProcessing);

    if (!request) {
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
            signal: request.controller.signal,
        });

        if (!isCurrentKioskRequest(request)) {
            return;
        }

        checkInResult.value = response?.data?.queue_item || null;
        checkInSuccess.value = t('reservations.kiosk.messages.check_in_done');
        schedulePrivacyReset();
        focusJourneyHeading();
    } catch (error) {
        if (isCanceledKioskRequest(error, request)) {
            return;
        }

        checkInError.value = normalizeError(error, t('reservations.kiosk.errors.check_in'));
    } finally {
        finishKioskRequest(request);
    }
};

const trackTicket = async () => {
    const request = startKioskRequest(trackProcessing);

    if (!request) {
        return;
    }

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
            signal: request.controller.signal,
        });

        if (!isCurrentKioskRequest(request)) {
            return;
        }

        trackResult.value = response?.data?.ticket || null;
        showJourneyResult();
    } catch (error) {
        if (isCanceledKioskRequest(error, request)) {
            return;
        }

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
    } finally {
        finishKioskRequest(request);
    }
};

const formatDateTime = (value) => (value ? dayjs(value).format('DD MMM HH:mm') : '-');
</script>

<template>
    <PublicKioskLayout :company="company" logo-href="">
        <template #brand-actions>
            <nav
                v-if="publicBookingHref"
                :aria-label="t('reservations.public_navigation.aria_label')"
            >
                <a
                    :href="publicBookingHref"
                    class="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 whitespace-nowrap rounded-sm border border-emerald-700 bg-emerald-700 px-2.5 py-2 text-xs font-semibold text-white shadow-sm transition-colors hover:border-emerald-800 hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 sm:px-3 sm:text-sm"
                    :aria-label="t('reservations.public_navigation.book')"
                    data-testid="reservation-kiosk-booking-link"
                >
                    <CalendarCheck2 class="size-4 shrink-0" aria-hidden="true" />
                    <span class="hidden sm:inline">{{ t('reservations.public_navigation.book') }}</span>
                </a>
            </nav>
        </template>

        <Head :title="`${kioskTitle} - ${brandName}`" />

        <main class="reservation-kiosk-page">
            <div class="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6 sm:py-5 lg:px-8">
            <div class="reservation-kiosk-shell" data-testid="reservation-kiosk-guided-shell">
                <aside class="reservation-kiosk-portrait" aria-labelledby="reservation-kiosk-welcome-title" data-testid="reservation-kiosk-brand-panel">
                    <div class="reservation-kiosk-portrait__media" aria-hidden="true">
                        <img
                            :src="portraitImageUrl"
                            alt=""
                            class="reservation-kiosk-portrait__image block"
                            loading="eager"
                        >
                        <div class="reservation-kiosk-portrait__scrim" aria-hidden="true"></div>
                    </div>

                    <div class="reservation-kiosk-brand-content">
                        <div class="reservation-kiosk-intro">
                            <span class="reservation-kiosk-intro-kicker">{{ $t('reservations.kiosk.category') }}</span>
                            <h2 id="reservation-kiosk-welcome-title" class="reservation-kiosk-title">
                                <span>{{ $t('reservations.kiosk.hero.welcome') }}</span>
                                <span class="reservation-kiosk-title__brand">{{ brandName }}</span>
                            </h2>
                            <p class="reservation-kiosk-description">
                                {{ $t('reservations.kiosk.hero.line_one') }}
                                {{ $t('reservations.kiosk.hero.line_two') }}
                            </p>
                        </div>

                        <div class="reservation-kiosk-brand-metrics">
                            <div class="reservation-kiosk-metric reservation-kiosk-metric--primary">
                                <Clock3 class="reservation-kiosk-metric__icon" aria-hidden="true" />
                                <div class="reservation-kiosk-metric__copy">
                                    <p class="reservation-kiosk-metric__label">{{ $t('reservations.kiosk.wait.title') }}</p>
                                    <p class="reservation-kiosk-metric__value">{{ estimatedWaitLabel }}</p>
                                </div>
                            </div>
                            <div class="reservation-kiosk-metric">
                                <span class="reservation-kiosk-metric__number">{{ waitingCount }}</span>
                                <span class="reservation-kiosk-metric__label">{{ $t('reservations.kiosk.guided.waiting') }}</span>
                            </div>
                            <div class="reservation-kiosk-metric">
                                <span class="reservation-kiosk-metric__number">{{ inServiceCount }}</span>
                                <span class="reservation-kiosk-metric__label">{{ $t('reservations.kiosk.guided.in_service') }}</span>
                            </div>
                            <p class="reservation-kiosk-metric__helper">{{ estimatedWaitHelper }}</p>
                        </div>
                    </div>
                </aside>

                <section
                    class="reservation-kiosk-workspace"
                    data-testid="reservation-kiosk-journey"
                    @input.capture="schedulePrivacyReset"
                    @keydown.capture="schedulePrivacyReset"
                    @pointerdown.capture="schedulePrivacyReset"
                >
                    <header class="reservation-kiosk-workspace__header">
                        <div>
                            <p class="reservation-kiosk-workspace__eyebrow">{{ kioskTitle }}</p>
                            <p class="reservation-kiosk-workspace__brand">{{ brandName }}</p>
                        </div>

                        <div class="reservation-kiosk-workspace__tools">
                            <button
                                v-if="currentJourneyStep > 0"
                                type="button"
                                class="reservation-kiosk-tool-button"
                                :aria-label="$t('reservations.kiosk.guided.restart')"
                                @click="resetKioskJourney"
                            >
                                <RotateCcw class="size-4" aria-hidden="true" />
                                <span>{{ $t('reservations.kiosk.guided.restart') }}</span>
                            </button>
                            <LanguageSwitcherMenu
                                button-class="relative inline-flex size-11 items-center justify-center rounded-sm border border-stone-200 bg-white text-sky-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-sky-500"
                                icon-class="size-6"
                            />
                        </div>
                    </header>

                    <nav class="reservation-kiosk-stepper" :aria-label="$t('reservations.kiosk.guided.progress')" data-kiosk-stepper>
                        <ol class="reservation-kiosk-stepper__list">
                            <li
                                v-for="(step, index) in journeySteps"
                                :key="step.key"
                                class="reservation-kiosk-stepper__item"
                                :class="{
                                    'is-current': currentJourneyStep === index,
                                    'is-complete': currentJourneyStep > index,
                                }"
                            >
                                <span
                                    class="reservation-kiosk-stepper__marker"
                                    :aria-current="currentJourneyStep === index ? 'step' : undefined"
                                >
                                    <CheckCircle2 v-if="currentJourneyStep > index" class="size-4" aria-hidden="true" />
                                    <span v-else>{{ index + 1 }}</span>
                                </span>
                                <span class="reservation-kiosk-stepper__label">{{ step.label }}</span>
                            </li>
                        </ol>
                    </nav>

                    <div
                        id="kiosk-journey-panel"
                        class="reservation-kiosk-stage"
                        :class="{ 'has-feedback': hasKioskFeedback }"
                        role="region"
                        :aria-labelledby="currentJourneyHeadingId"
                        data-kiosk-form
                    >
                        <section v-if="currentJourneyStep === 0" class="reservation-kiosk-step" data-testid="kiosk-intent-step">
                            <div class="reservation-kiosk-step__heading">
                                <span class="reservation-kiosk-step__index">01</span>
                                <h1
                                    id="reservation-kiosk-actions-title"
                                    class="reservation-kiosk-step__title"
                                    tabindex="-1"
                                    data-kiosk-choice-heading
                                    data-kiosk-step-heading
                                >
                                    {{ $t('reservations.kiosk.actions.title') }}
                                </h1>
                                <p class="reservation-kiosk-step__description">{{ $t('reservations.kiosk.subtitle') }}</p>
                            </div>

                            <div class="reservation-kiosk-action-list">
                                <button
                                    v-for="(item, index) in actionItems"
                                    :key="item.key"
                                    type="button"
                                    class="reservation-kiosk-action"
                                    :aria-pressed="activeMode === item.key"
                                    aria-controls="kiosk-journey-panel"
                                    @click="setMode(item.key)"
                                >
                                    <span class="reservation-kiosk-action__icon" :class="item.iconBoxClass">
                                        <component :is="item.icon" class="size-7" aria-hidden="true" />
                                    </span>
                                    <span class="reservation-kiosk-action__copy">
                                        <span class="reservation-kiosk-action__index" aria-hidden="true">0{{ index + 1 }}</span>
                                        <span class="reservation-kiosk-action__title">{{ item.title }}</span>
                                        <span class="reservation-kiosk-action__subtitle">{{ item.subtitle }}</span>
                                    </span>
                                    <CheckCircle2
                                        v-if="activeMode === item.key"
                                        class="reservation-kiosk-action__selected"
                                        aria-hidden="true"
                                    />
                                </button>
                            </div>

                            <div class="reservation-kiosk-step__footer reservation-kiosk-step__footer--end">
                                <button
                                    type="button"
                                    class="reservation-kiosk-primary-button"
                                    :disabled="!activeMode"
                                    aria-controls="kiosk-journey-panel"
                                    @click="continueAction"
                                >
                                    <span>{{ $t('reservations.kiosk.guided.continue') }}</span>
                                    <ArrowRight class="size-5" aria-hidden="true" />
                                </button>
                            </div>
                        </section>

                        <section v-else-if="currentJourneyStep === 1" class="reservation-kiosk-step" data-testid="kiosk-details-step">
                            <div class="reservation-kiosk-step__heading reservation-kiosk-step__heading--with-icon">
                                <span class="reservation-kiosk-step__icon" :class="currentPreview.iconBoxClass">
                                    <component :is="currentPreview.icon" class="size-7" aria-hidden="true" />
                                </span>
                                <div>
                                    <span class="reservation-kiosk-step__index">02 · {{ currentPreview.label }}</span>
                                    <h1
                                        id="reservation-kiosk-form-title"
                                        class="reservation-kiosk-step__title"
                                        tabindex="-1"
                                        data-kiosk-step-heading
                                    >
                                        {{ currentPreview.title }}
                                    </h1>
                                    <p class="reservation-kiosk-step__description">{{ currentPreview.description }}</p>
                                </div>
                            </div>

                            <div class="reservation-kiosk-form-fields">
                                <form
                                    v-if="activeMode === 'walk_in'"
                                    class="reservation-kiosk-form-grid"
                                    data-kiosk-active-form
                                    @submit.prevent="submitWalkIn"
                                >
                                    <div>
                                        <FloatingInput
                                            id="walk-in-phone"
                                            v-model="walkInForm.phone"
                                            type="tel"
                                            :label="$t('reservations.kiosk.fields.phone')"
                                            :placeholder="phoneProfile.internationalPlaceholder"
                                            :required="true"
                                            autocomplete="tel"
                                            class="h-[56px] border-[#dfe5e1] bg-white text-[#334155]"
                                        />
                                        <InputError class="mt-1" :message="walkInForm.errors.phone" />
                                    </div>

                                    <div>
                                        <FloatingInput
                                            id="walk-in-name"
                                            v-model="walkInForm.guest_name"
                                            :label="$t('reservations.kiosk.fields.guest_name')"
                                            :placeholder="$t('reservations.kiosk.placeholders.guest_name')"
                                            autocomplete="name"
                                            class="h-[56px] border-[#dfe5e1] bg-white text-[#334155]"
                                        />
                                        <InputError class="mt-1" :message="walkInForm.errors.guest_name" />
                                    </div>

                                    <div class="reservation-kiosk-form-grid__wide">
                                        <FloatingSelect
                                            id="walk-in-service-search"
                                            v-model="walkInForm.service_id"
                                            :label="$t('reservations.kiosk.fields.service')"
                                            :options="serviceOptions"
                                            option-value="value"
                                            option-label="label"
                                            filterable
                                            :filter-placeholder="$t('reservations.kiosk.placeholders.search_service')"
                                            :empty-label="$t('reservations.kiosk.messages.no_service_match')"
                                            class="h-[56px] border-[#dfe5e1] bg-white text-[#334155]"
                                        />
                                        <InputError class="mt-1" :message="walkInForm.errors.service_id" />
                                    </div>

                                    <div>
                                        <FloatingSelect
                                            id="walk-in-party"
                                            v-model="walkInForm.party_size"
                                            :label="$t('reservations.kiosk.fields.party_size')"
                                            :options="partySizeOptions"
                                            option-value="value"
                                            option-label="label"
                                            class="h-[56px] border-[#dfe5e1] bg-white text-[#334155]"
                                        />
                                        <InputError class="mt-1" :message="walkInForm.errors.party_size" />
                                    </div>

                                    <div class="reservation-kiosk-form-actions">
                                        <button type="button" class="reservation-kiosk-secondary-button" @click="returnToPreviousStep">
                                            <ArrowLeft class="size-5" aria-hidden="true" />
                                            {{ $t('reservations.kiosk.guided.back') }}
                                        </button>
                                        <button type="submit" class="reservation-kiosk-primary-button" :disabled="walkInProcessing">
                                            {{ currentPreview.submitLabel }}
                                            <ArrowRight class="size-5" aria-hidden="true" />
                                        </button>
                                    </div>
                                </form>

                                <template v-else-if="activeMode === 'known_client'">
                                    <form
                                        v-if="!verificationRequired || isVerifiedClientFlow"
                                        class="reservation-kiosk-form-grid reservation-kiosk-form-grid--single"
                                        data-kiosk-active-form
                                        @submit.prevent="lookupClient"
                                    >
                                        <div>
                                            <FloatingInput
                                                id="lookup-phone"
                                                v-model="lookupForm.phone"
                                                type="tel"
                                                :label="$t('reservations.kiosk.fields.phone')"
                                                :placeholder="phoneProfile.internationalPlaceholder"
                                                :required="true"
                                                autocomplete="tel"
                                                class="h-[56px] border-[#dfe5e1] bg-white text-[#334155]"
                                            />
                                            <InputError class="mt-1" :message="lookupForm.errors.phone" />
                                        </div>
                                        <div class="reservation-kiosk-form-actions">
                                            <button type="button" class="reservation-kiosk-secondary-button" @click="returnToPreviousStep">
                                                <ArrowLeft class="size-5" aria-hidden="true" />
                                                {{ $t('reservations.kiosk.guided.back') }}
                                            </button>
                                            <button type="submit" class="reservation-kiosk-primary-button" :disabled="lookupProcessing">
                                                {{ currentPreview.submitLabel }}
                                                <ArrowRight class="size-5" aria-hidden="true" />
                                            </button>
                                        </div>
                                    </form>

                                    <form v-else class="reservation-kiosk-verification" @submit.prevent="verifyClient">
                                        <div class="reservation-kiosk-notice reservation-kiosk-notice--warning">
                                            <p class="font-bold">{{ $t('reservations.kiosk.known_client.verify_prompt') }}</p>
                                            <p v-if="verificationDebugCode" class="mt-1 text-xs">
                                                {{ $t('reservations.kiosk.known_client.debug_code') }}: <strong>{{ verificationDebugCode }}</strong>
                                            </p>
                                        </div>
                                        <div>
                                            <FloatingInput
                                                id="verification-code"
                                                v-model="verifyForm.code"
                                                :label="$t('reservations.kiosk.fields.code')"
                                                class="h-[56px] border-[#dfe5e1] bg-white text-[#334155]"
                                            />
                                            <InputError class="mt-1" :message="verifyForm.errors.code" />
                                        </div>
                                        <div class="reservation-kiosk-form-actions">
                                            <button type="button" class="reservation-kiosk-secondary-button" @click="returnToPreviousStep">
                                                <ArrowLeft class="size-5" aria-hidden="true" />
                                                {{ $t('reservations.kiosk.guided.back') }}
                                            </button>
                                            <button type="submit" class="reservation-kiosk-primary-button" :disabled="verifyProcessing">
                                                {{ verifyProcessing ? $t('reservations.client.book.actions.submitting') : $t('reservations.kiosk.known_client.verify') }}
                                            </button>
                                        </div>
                                    </form>
                                </template>

                                <form
                                    v-else
                                    class="reservation-kiosk-form-grid reservation-kiosk-form-grid--single"
                                    data-kiosk-active-form
                                    @submit.prevent="trackTicket"
                                >
                                    <div>
                                        <FloatingInput
                                            id="track-phone"
                                            v-model="trackForm.phone"
                                            type="tel"
                                            :label="$t('reservations.kiosk.fields.phone')"
                                            :placeholder="phoneProfile.internationalPlaceholder"
                                            :required="true"
                                            autocomplete="tel"
                                            class="h-[56px] border-[#dfe5e1] bg-white text-[#334155]"
                                        />
                                        <InputError class="mt-1" :message="trackForm.errors.phone" />
                                    </div>
                                    <div>
                                        <FloatingInput
                                            id="track-number"
                                            v-model="trackForm.queue_number"
                                            :label="$t('reservations.kiosk.fields.queue_number')"
                                            placeholder="A-001"
                                            class="h-[56px] border-[#dfe5e1] bg-white text-[#334155]"
                                        />
                                        <InputError class="mt-1" :message="trackForm.errors.queue_number" />
                                    </div>
                                    <div class="reservation-kiosk-form-actions">
                                        <button type="button" class="reservation-kiosk-secondary-button" @click="returnToPreviousStep">
                                            <ArrowLeft class="size-5" aria-hidden="true" />
                                            {{ $t('reservations.kiosk.guided.back') }}
                                        </button>
                                        <button type="submit" class="reservation-kiosk-primary-button" :disabled="trackProcessing">
                                            {{ currentPreview.submitLabel }}
                                            <ArrowRight class="size-5" aria-hidden="true" />
                                        </button>
                                    </div>
                                </form>

                                <div class="reservation-kiosk-feedback" aria-live="polite" aria-atomic="false">
                                    <div v-if="walkInError && activeMode === 'walk_in'" class="reservation-kiosk-notice reservation-kiosk-notice--error" role="alert">{{ walkInError }}</div>
                                    <div v-if="lookupError && activeMode === 'known_client'" class="reservation-kiosk-notice reservation-kiosk-notice--error" role="alert">{{ lookupError }}</div>
                                    <div v-if="lookupSuccess && verificationRequired && !isVerifiedClientFlow" class="reservation-kiosk-notice reservation-kiosk-notice--success">{{ lookupSuccess }}</div>
                                    <div v-if="trackError && activeMode === 'track_ticket'" class="reservation-kiosk-notice reservation-kiosk-notice--error" role="alert">{{ trackError }}</div>
                                </div>

                                <div class="reservation-kiosk-security">
                                    <ShieldCheck class="size-5 shrink-0 text-[#0f9a68]" aria-hidden="true" />
                                    <span>{{ $t('reservations.kiosk.security_notice') }}</span>
                                </div>
                            </div>
                        </section>

                        <section v-else class="reservation-kiosk-step" data-testid="kiosk-result-step">
                            <div class="reservation-kiosk-step__heading reservation-kiosk-step__heading--with-icon">
                                <span class="reservation-kiosk-step__icon border-emerald-100 bg-emerald-50 text-emerald-700">
                                    <CheckCircle2 v-if="isJourneyComplete" class="size-8" aria-hidden="true" />
                                    <component :is="currentPreview.icon" v-else class="size-7" aria-hidden="true" />
                                </span>
                                <div>
                                    <span class="reservation-kiosk-step__index">03 · {{ $t('reservations.kiosk.guided.confirmation') }}</span>
                                    <h1
                                        id="reservation-kiosk-result-title"
                                        class="reservation-kiosk-step__title"
                                        tabindex="-1"
                                        data-kiosk-step-heading
                                    >
                                        {{ journeyResultTitle }}
                                    </h1>
                                    <p class="reservation-kiosk-step__description">
                                        {{ $t(journeyTicket ? 'reservations.kiosk.guided.result_help' : 'reservations.kiosk.guided.next_action_help') }}
                                    </p>
                                </div>
                            </div>

                            <div class="reservation-kiosk-feedback" aria-live="polite" aria-atomic="false">
                                <div v-if="walkInError && activeMode === 'walk_in'" class="reservation-kiosk-notice reservation-kiosk-notice--warning">{{ walkInError }}</div>
                                <div v-if="walkInSuccess && activeMode === 'walk_in'" class="reservation-kiosk-notice reservation-kiosk-notice--success">{{ walkInSuccess }}</div>
                                <div v-if="lookupError && activeMode === 'known_client'" class="reservation-kiosk-notice reservation-kiosk-notice--error" role="alert">{{ lookupError }}</div>
                                <div v-if="lookupSuccess && activeMode === 'known_client'" class="reservation-kiosk-notice reservation-kiosk-notice--success">{{ lookupSuccess }}</div>
                                <div v-if="checkInError && activeMode === 'known_client'" class="reservation-kiosk-notice reservation-kiosk-notice--error" role="alert">{{ checkInError }}</div>
                                <div v-if="checkInSuccess && activeMode === 'known_client'" class="reservation-kiosk-notice reservation-kiosk-notice--success">{{ checkInSuccess }}</div>
                                <div v-if="trackError && activeMode === 'track_ticket'" class="reservation-kiosk-notice reservation-kiosk-notice--error" role="alert">{{ trackError }}</div>
                            </div>

                            <article v-if="journeyTicket" class="reservation-kiosk-ticket" data-testid="kiosk-ticket-result" role="status" aria-live="polite">
                                <div class="reservation-kiosk-ticket__topline">
                                    <span>{{ $t('reservations.kiosk.labels.ticket') }}</span>
                                    <span v-if="journeyTicket.status" class="reservation-kiosk-ticket__status" :class="queueStatusClass(journeyTicket.status)">
                                        {{ queueStatusLabel(journeyTicket.status) }}
                                    </span>
                                </div>
                                <p class="reservation-kiosk-ticket__number">{{ journeyTicket.queue_number || '-' }}</p>
                                <div class="reservation-kiosk-ticket__metrics">
                                    <div>
                                        <span>{{ $t('reservations.kiosk.guided.position') }}</span>
                                        <strong>{{ journeyTicket.position ?? '-' }}</strong>
                                    </div>
                                    <div>
                                        <span>{{ $t('reservations.kiosk.guided.eta') }}</span>
                                        <strong>{{ journeyTicket.eta_minutes !== null && journeyTicket.eta_minutes !== undefined ? `${journeyTicket.eta_minutes} min` : '-' }}</strong>
                                    </div>
                                </div>
                                <div v-if="journeyTicket.service_name || journeyTicket.team_member_name" class="reservation-kiosk-ticket__details">
                                    <span v-if="journeyTicket.service_name">{{ journeyTicket.service_name }}</span>
                                    <span v-if="journeyTicket.team_member_name">{{ journeyTicket.team_member_name }}</span>
                                </div>
                            </article>

                            <template v-if="activeMode === 'known_client' && !journeyTicket">
                                <div v-if="lookupResult && !hasClientLookup" class="reservation-kiosk-empty-result">
                                    <h2>{{ $t('reservations.kiosk.guided.client_not_found_title') }}</h2>
                                    <p>{{ $t('reservations.kiosk.guided.client_not_found_help') }}</p>
                                    <button type="button" class="reservation-kiosk-primary-button" @click="switchToWalkIn">
                                        {{ $t('reservations.kiosk.guided.take_ticket') }}
                                        <ArrowRight class="size-5" aria-hidden="true" />
                                    </button>
                                </div>

                                <template v-else-if="hasClientLookup">
                                    <div class="reservation-kiosk-client-card">
                                        <div>
                                            <span>{{ $t('reservations.kiosk.guided.identified_client') }}</span>
                                            <strong>{{ lookupResult.client?.name }}</strong>
                                        </div>
                                        <p>{{ lookupResult.client?.phone || lookupForm.phone }}</p>
                                    </div>

                                    <div v-if="hasNearbyReservation" class="reservation-kiosk-decision-card">
                                        <p class="reservation-kiosk-decision-card__eyebrow">{{ $t('reservations.kiosk.known_client.reservation_ready') }}</p>
                                        <p class="reservation-kiosk-decision-card__title">
                                            {{ formatDateTime(lookupResult.intent.nearby_reservation?.starts_at) }}
                                        </p>
                                        <p class="reservation-kiosk-decision-card__meta">
                                            {{ queueStatusLabel(lookupResult.intent.nearby_reservation?.status || 'confirmed') }}
                                        </p>
                                        <button type="button" class="reservation-kiosk-primary-button" :disabled="lookupProcessing" @click="checkInReservation">
                                            {{ $t('reservations.kiosk.known_client.check_in') }}
                                            <ArrowRight class="size-5" aria-hidden="true" />
                                        </button>
                                    </div>

                                    <form v-else-if="canCreateClientTicket" class="reservation-kiosk-decision-card" @submit.prevent="createClientTicket">
                                        <p class="reservation-kiosk-decision-card__eyebrow">{{ $t('reservations.kiosk.known_client.create_ticket_help') }}</p>
                                        <div class="reservation-kiosk-form-grid">
                                            <FloatingSelect
                                                id="client-ticket-service"
                                                v-model="clientTicketForm.service_id"
                                                :label="$t('reservations.kiosk.fields.service')"
                                                :options="serviceOptions"
                                                option-value="value"
                                                option-label="label"
                                                filterable
                                                :filter-placeholder="$t('reservations.kiosk.placeholders.search_service')"
                                                :empty-label="$t('reservations.kiosk.messages.no_service_match')"
                                                class="h-[56px] border-[#dfe5e1] bg-white text-[#334155]"
                                            />
                                            <FloatingSelect
                                                id="client-ticket-team"
                                                v-model="clientTicketForm.team_member_id"
                                                :label="$t('reservations.kiosk.fields.team_member')"
                                                :options="teamOptions"
                                                option-value="value"
                                                option-label="label"
                                                filterable
                                                class="h-[56px] border-[#dfe5e1] bg-white text-[#334155]"
                                            />
                                            <FloatingSelect
                                                id="client-ticket-party"
                                                v-model="clientTicketForm.party_size"
                                                :label="$t('reservations.kiosk.fields.party_size')"
                                                :options="partySizeOptions"
                                                option-value="value"
                                                option-label="label"
                                                class="h-[56px] border-[#dfe5e1] bg-white text-[#334155]"
                                            />
                                        </div>
                                        <InputError class="mt-2" :message="clientTicketForm.errors.service_id || clientTicketForm.errors.team_member_id || clientTicketForm.errors.party_size" />
                                        <button type="submit" class="reservation-kiosk-primary-button" :disabled="clientTicketProcessing">
                                            {{ clientTicketProcessing ? $t('reservations.client.book.actions.submitting') : $t('reservations.kiosk.known_client.create_ticket') }}
                                            <ArrowRight class="size-5" aria-hidden="true" />
                                        </button>
                                    </form>
                                </template>
                            </template>

                            <div class="reservation-kiosk-result-actions">
                                <button v-if="!isJourneyComplete" type="button" class="reservation-kiosk-secondary-button" @click="returnToPreviousStep">
                                    <ArrowLeft class="size-5" aria-hidden="true" />
                                    {{ $t('reservations.kiosk.guided.back') }}
                                </button>
                                <button type="button" class="reservation-kiosk-primary-button" @click="resetKioskJourney">
                                    {{ isJourneyComplete ? $t('reservations.kiosk.guided.finish') : $t('reservations.kiosk.guided.restart') }}
                                    <RotateCcw class="size-5" aria-hidden="true" />
                                </button>
                            </div>

                            <p class="reservation-kiosk-auto-reset">
                                <ShieldCheck class="size-4" aria-hidden="true" />
                                {{ $t('reservations.kiosk.guided.auto_reset') }}
                            </p>
                        </section>
                    </div>
                </section>
            </div>
            </div>
        </main>
    </PublicKioskLayout>
</template>

<style scoped>
.reservation-kiosk-page {
    --kiosk-ink: #102019;
    --kiosk-muted: #52635b;
    --kiosk-green: #0b7e55;
    --kiosk-green-dark: #086744;
    --kiosk-border: #d8e5de;
    position: relative;
    flex: 1 1 auto;
    min-height: 0;
    overflow-x: hidden;
    color: var(--kiosk-ink);
    background: #f5f5f4;
}

.reservation-kiosk-shell {
    position: relative;
    z-index: 1;
    display: grid;
    max-width: 1280px;
    grid-template-columns: 420px minmax(0, 1fr);
    margin-inline: auto;
    overflow: visible;
    border: 1px solid #e7e5e4;
    border-radius: 0.125rem;
    background: #fff;
    box-shadow: 0 1px 2px rgb(15 23 42 / 0.06);
    isolation: isolate;
}

.reservation-kiosk-portrait {
    position: relative;
    min-height: 0;
    overflow: hidden;
    border: 0;
    border-radius: 0.125rem;
    background: #1c2d25;
    isolation: isolate;
}

.reservation-kiosk-portrait__image {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    min-height: 100%;
    object-fit: cover;
    object-position: center;
}

.reservation-kiosk-portrait__scrim {
    position: absolute;
    z-index: 1;
    inset: 0;
    background: rgb(5 18 12 / 0.58);
}

.reservation-kiosk-intro {
    position: relative;
    z-index: 2;
    display: block;
    min-height: 0;
    margin-top: auto;
    padding: 0;
    color: white;
}

.reservation-kiosk-intro-kicker {
    display: inline-flex;
    align-items: center;
    min-height: 30px;
    border: 1px solid rgb(255 255 255 / 0.28);
    border-radius: 0.125rem;
    padding-inline: 0.75rem;
    color: rgb(255 255 255 / 0.9);
    background: rgb(255 255 255 / 0.12);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.reservation-kiosk-title {
    display: grid;
    gap: 0.2rem;
    max-width: 340px;
    margin-top: 1.15rem;
    color: white;
    font-size: 2.375rem;
    font-weight: 700;
    line-height: 2.5rem;
    letter-spacing: -0.035em;
    text-wrap: balance;
}

.reservation-kiosk-title > span:first-child {
    white-space: nowrap;
}

.reservation-kiosk-title__brand {
    display: block;
    color: #a7f3d0;
}

.reservation-kiosk-description {
    max-width: 470px;
    margin-top: 1rem;
    color: rgb(255 255 255 / 0.84);
    font-size: clamp(14px, 1.25vw, 17px);
    font-weight: 550;
    line-height: 1.65;
    text-wrap: pretty;
}

.reservation-kiosk-action-list {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 168px));
    justify-content: start;
    gap: 0.75rem;
    margin-top: 1.25rem;
}

.reservation-kiosk-action {
    position: relative;
    display: flex;
    aspect-ratio: 1;
    min-height: 0;
    width: 100%;
    flex-direction: column;
    align-items: flex-start;
    gap: 0;
    border-width: 1px;
    border-radius: 0.125rem;
    background: white;
    padding: 0.9rem;
    text-align: left;
}

.reservation-kiosk-action:focus-visible {
    outline: 3px solid var(--kiosk-green);
    outline-offset: 3px;
}

.reservation-kiosk-action__icon {
    display: flex;
    width: 42px;
    height: 42px;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    border-width: 1px;
    border-radius: 0.125rem;
}

.reservation-kiosk-action__index {
    display: block;
    color: #809087;
    font-size: 9px;
    font-weight: 600;
    letter-spacing: 0.12em;
    line-height: 11px;
}

.reservation-kiosk-action__title {
    display: -webkit-box;
    width: 100%;
    min-height: 40px;
    max-height: 40px;
    overflow: hidden;
    color: var(--kiosk-ink);
    font-size: 14px;
    font-weight: 600;
    line-height: 1.25rem;
    overflow-wrap: anywhere;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}

.reservation-kiosk-action__subtitle {
    display: -webkit-box;
    width: 100%;
    min-height: 32px;
    max-height: 32px;
    overflow: hidden;
    color: var(--kiosk-muted);
    font-size: 11px;
    font-weight: 550;
    line-height: 1rem;
    overflow-wrap: anywhere;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}

.reservation-kiosk-portrait__media {
    position: absolute;
    inset: 0;
}

.reservation-kiosk-brand-content {
    position: relative;
    z-index: 2;
    display: flex;
    min-height: 100%;
    flex-direction: column;
    justify-content: space-between;
    gap: 1.5rem;
    padding: 2.5rem;
    color: #fff;
}

.reservation-kiosk-brand-metrics {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 70px 70px;
    gap: 0.5rem;
}

.reservation-kiosk-metric {
    display: flex;
    min-width: 0;
    min-height: 82px;
    flex-direction: column;
    justify-content: center;
    overflow: hidden;
    border: 1px solid rgb(255 255 255 / 0.34);
    border-radius: 0.125rem;
    padding: 0.75rem;
    color: #fff;
    background: rgb(7 30 20 / 0.82);
}

.reservation-kiosk-metric--primary {
    flex-direction: row;
    align-items: center;
    justify-content: flex-start;
    gap: 0.5rem;
    padding: 0.625rem;
    color: var(--kiosk-ink);
    background: #fff;
}

.reservation-kiosk-metric__icon {
    width: 1.5rem;
    height: 1.5rem;
    flex-shrink: 0;
    color: var(--kiosk-green);
}

.reservation-kiosk-metric__copy {
    width: 100%;
    min-width: 0;
    overflow: hidden;
}

.reservation-kiosk-metric__label {
    color: inherit;
    font-size: 9px;
    font-weight: 600;
    letter-spacing: 0.07em;
    line-height: 1.15;
    text-transform: uppercase;
}

.reservation-kiosk-metric__value,
.reservation-kiosk-metric__number {
    display: block;
    margin-top: 0.25rem;
    font-weight: 700;
    line-height: 1;
    white-space: nowrap;
}

.reservation-kiosk-metric__value {
    max-width: 100%;
    overflow: hidden;
    color: var(--kiosk-green);
    font-size: 22px;
    text-overflow: ellipsis;
}

.reservation-kiosk-metric__number {
    font-size: 24px;
}

.reservation-kiosk-metric:not(.reservation-kiosk-metric--primary) {
    display: grid;
    grid-template-rows: 24px 22px;
    align-content: center;
    gap: 0.25rem;
}

.reservation-kiosk-metric:not(.reservation-kiosk-metric--primary) .reservation-kiosk-metric__number {
    margin-top: 0;
    line-height: 24px;
}

.reservation-kiosk-metric:not(.reservation-kiosk-metric--primary) .reservation-kiosk-metric__label {
    min-height: 22px;
    line-height: 11px;
}

.reservation-kiosk-metric__helper {
    grid-column: 1 / -1;
    color: rgb(255 255 255 / 0.72);
    font-size: 11px;
    font-weight: 600;
}

.reservation-kiosk-workspace {
    display: flex;
    min-width: 0;
    min-height: 0;
    flex-direction: column;
    overflow: visible;
    padding: clamp(1rem, 2vw, 1.75rem);
    background: #fafaf9;
}

.reservation-kiosk-workspace__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    border: 1px solid #e7e5e4;
    border-radius: 0.125rem;
    padding: 0.75rem 1rem;
    background: #fff;
    box-shadow: 0 1px 2px rgb(15 23 42 / 0.05);
}

.reservation-kiosk-workspace__eyebrow {
    color: var(--kiosk-green);
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.reservation-kiosk-workspace__brand {
    margin-top: 0.2rem;
    color: var(--kiosk-ink);
    font-size: 16px;
    font-weight: 600;
}

.reservation-kiosk-workspace__tools {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.reservation-kiosk-tool-button,
.reservation-kiosk-primary-button,
.reservation-kiosk-secondary-button {
    display: inline-flex;
    min-height: 44px;
    align-items: center;
    justify-content: center;
    gap: 0.55rem;
    border-radius: 0.125rem;
    padding: 0.625rem 1rem;
    font-size: 13px;
    font-weight: 600;
}

.reservation-kiosk-tool-button,
.reservation-kiosk-secondary-button {
    border: 1px solid #d8e5de;
    color: #405149;
    background: #fff;
}

.reservation-kiosk-primary-button {
    min-width: 136px;
    border: 1px solid var(--kiosk-green);
    color: #fff;
    background: var(--kiosk-green);
}

.reservation-kiosk-primary-button:disabled {
    cursor: not-allowed;
    border-color: #cbd5d0;
    color: #829087;
    background: #edf1ef;
    box-shadow: none;
}

.reservation-kiosk-tool-button:focus-visible,
.reservation-kiosk-primary-button:focus-visible,
.reservation-kiosk-secondary-button:focus-visible {
    outline: 3px solid var(--kiosk-green);
    outline-offset: 3px;
}

.reservation-kiosk-stepper {
    width: 100%;
    margin-top: 1rem;
    border: 1px solid #e7e5e4;
    border-radius: 0.125rem;
    padding: 0.75rem 1rem;
    background: #fff;
    box-shadow: 0 1px 2px rgb(15 23 42 / 0.05);
}

.reservation-kiosk-stepper__list {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.reservation-kiosk-stepper__item {
    position: relative;
    display: grid;
    justify-items: center;
    gap: 0.55rem;
    color: #8b9991;
    text-align: center;
}

.reservation-kiosk-stepper__item:not(:last-child)::after {
    position: absolute;
    z-index: 0;
    top: 15px;
    left: calc(50% + 23px);
    width: calc(100% - 46px);
    height: 2px;
    background: #e0e7e3;
    content: '';
}

.reservation-kiosk-stepper__item.is-complete:not(:last-child)::after {
    background: var(--kiosk-green);
}

.reservation-kiosk-stepper__marker {
    position: relative;
    z-index: 1;
    display: inline-flex;
    width: 32px;
    height: 32px;
    align-items: center;
    justify-content: center;
    border: 1px solid #d8e5de;
    border-radius: 0.125rem;
    color: #728179;
    background: #fff;
    font-size: 12px;
    font-weight: 700;
}

.reservation-kiosk-stepper__item.is-current,
.reservation-kiosk-stepper__item.is-complete {
    color: var(--kiosk-green);
}

.reservation-kiosk-stepper__item.is-current .reservation-kiosk-stepper__marker,
.reservation-kiosk-stepper__item.is-complete .reservation-kiosk-stepper__marker {
    border-color: var(--kiosk-green);
    color: #fff;
    background: var(--kiosk-green);
}

.reservation-kiosk-stepper__label {
    max-width: 170px;
    overflow: hidden;
    font-size: 11px;
    font-weight: 600;
    line-height: 1.25;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.reservation-kiosk-stage {
    display: flex;
    width: 100%;
    flex: 1 1 auto;
    align-items: stretch;
    margin-top: 1rem;
    overflow: visible;
    border: 1px solid #e7e5e4;
    border-radius: 0.125rem;
    padding: clamp(1rem, 2vw, 1.5rem);
    background: #fff;
    box-shadow: 0 1px 2px rgb(15 23 42 / 0.05);
}

.reservation-kiosk-step {
    display: flex;
    width: 100%;
    align-self: stretch;
    flex-direction: column;
}

.reservation-kiosk-step__heading {
    max-width: 660px;
}

.reservation-kiosk-step__heading--with-icon {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
}

.reservation-kiosk-step__icon {
    display: inline-flex;
    width: 58px;
    height: 58px;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    border-width: 1px;
    border-radius: 0.125rem;
}

.reservation-kiosk-step__index {
    display: block;
    color: var(--kiosk-green);
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.reservation-kiosk-step__title {
    margin-top: 0.5rem;
    color: var(--kiosk-ink);
    font-size: clamp(22px, 2vw, 28px);
    font-weight: 700;
    line-height: 1.2;
    letter-spacing: -0.02em;
    text-wrap: balance;
}

.reservation-kiosk-step__title:focus {
    outline: none;
}

.reservation-kiosk-step__description {
    max-width: 620px;
    margin-top: 0.75rem;
    color: var(--kiosk-muted);
    font-size: 14px;
    line-height: 1.55;
}

.reservation-kiosk-action[aria-pressed='true'] {
    border-color: var(--kiosk-green);
    background: #ecfdf5;
}

.reservation-kiosk-action__copy {
    display: grid;
    width: 100%;
    min-width: 0;
    grid-template-rows: 11px 40px 32px;
    align-content: start;
    margin-top: 0.6rem;
}

.reservation-kiosk-action__selected {
    position: absolute;
    top: 0.8rem;
    right: 0.8rem;
    width: 1.1rem;
    height: 1.1rem;
    color: var(--kiosk-green);
}

.reservation-kiosk-step__footer,
.reservation-kiosk-form-actions,
.reservation-kiosk-result-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-top: 1.5rem;
}

.reservation-kiosk-step__footer--end {
    justify-content: flex-end;
    margin-top: auto;
    padding-top: 1.5rem;
}

.reservation-kiosk-form-fields {
    min-width: 0;
    margin-top: 1.75rem;
}

.reservation-kiosk-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.reservation-kiosk-form-grid--single {
    grid-template-columns: minmax(0, 1fr);
}

.reservation-kiosk-form-grid__wide,
.reservation-kiosk-form-actions {
    grid-column: 1 / -1;
}

.reservation-kiosk-stage :deep(.app-field-control) {
    border-radius: 0.125rem;
}

.reservation-kiosk-feedback {
    display: grid;
    gap: 0.65rem;
    margin-top: 1rem;
}

.reservation-kiosk-feedback:empty {
    display: none;
}

.reservation-kiosk-notice {
    border: 1px solid;
    border-radius: 0.125rem;
    padding: 0.8rem 1rem;
    font-size: 13px;
    line-height: 1.5;
}

.reservation-kiosk-notice--success {
    border-color: #bce4d0;
    color: #086744;
    background: #edf9f3;
}

.reservation-kiosk-notice--warning {
    border-color: #f5d48c;
    color: #8a4b0a;
    background: #fff8e7;
}

.reservation-kiosk-notice--error {
    border-color: #fecaca;
    color: #be123c;
    background: #fff1f2;
}

.reservation-kiosk-security,
.reservation-kiosk-auto-reset {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    margin-top: 1rem;
    border: 1px solid #d5e9df;
    border-radius: 0.125rem;
    padding: 0.75rem 0.9rem;
    color: #405149;
    background: #f2faf6;
    font-size: 12px;
    font-weight: 600;
}

.reservation-kiosk-verification,
.reservation-kiosk-decision-card,
.reservation-kiosk-empty-result {
    display: grid;
    gap: 1rem;
    margin-top: 1.25rem;
}

.reservation-kiosk-ticket {
    margin-top: 1.5rem;
    border: 1px solid #bce4d0;
    border-radius: 0.125rem;
    padding: clamp(1.25rem, 3vw, 2rem);
    background: #f0fdf4;
}

.reservation-kiosk-ticket__topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    color: var(--kiosk-green);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.reservation-kiosk-ticket__status {
    border-radius: 0.125rem;
    padding: 0.35rem 0.55rem;
    letter-spacing: 0;
    text-transform: none;
}

.reservation-kiosk-ticket__number {
    margin-top: 0.65rem;
    color: var(--kiosk-ink);
    font-size: clamp(42px, 6vw, 68px);
    font-weight: 700;
    line-height: 1;
    letter-spacing: -0.045em;
}

.reservation-kiosk-ticket__metrics {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
    margin-top: 1.3rem;
}

.reservation-kiosk-ticket__metrics > div {
    display: grid;
    gap: 0.25rem;
    border-left: 3px solid #69c89b;
    padding: 0.7rem 0.9rem;
    background: rgb(255 255 255 / 0.72);
}

.reservation-kiosk-ticket__metrics span,
.reservation-kiosk-client-card span {
    color: #64756c;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.reservation-kiosk-ticket__metrics strong {
    color: var(--kiosk-ink);
    font-size: 22px;
}

.reservation-kiosk-ticket__details {
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem 1rem;
    margin-top: 1rem;
    color: #52635b;
    font-size: 13px;
    font-weight: 650;
}

.reservation-kiosk-client-card,
.reservation-kiosk-decision-card,
.reservation-kiosk-empty-result {
    border: 1px solid var(--kiosk-border);
    border-radius: 0.125rem;
    padding: 1rem;
    background: #fbfdfc;
}

.reservation-kiosk-client-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-top: 1.25rem;
}

.reservation-kiosk-client-card strong {
    display: block;
    margin-top: 0.25rem;
    color: var(--kiosk-ink);
    font-size: 17px;
}

.reservation-kiosk-client-card p,
.reservation-kiosk-decision-card__meta,
.reservation-kiosk-empty-result p {
    color: var(--kiosk-muted);
    font-size: 13px;
}

.reservation-kiosk-decision-card__eyebrow {
    color: var(--kiosk-green);
    font-size: 11px;
    font-weight: 600;
}

.reservation-kiosk-decision-card__title,
.reservation-kiosk-empty-result h2 {
    color: var(--kiosk-ink);
    font-size: 20px;
    font-weight: 700;
}

.reservation-kiosk-auto-reset {
    justify-content: center;
    border-color: transparent;
    color: #74837b;
    background: transparent;
    text-align: center;
}

@media (hover: hover) {
    .reservation-kiosk-action:not([aria-pressed='true']):hover {
        border-color: #b9d9c9;
    }

    .reservation-kiosk-tool-button:hover,
    .reservation-kiosk-secondary-button:hover {
        border-color: #a9cbbb;
        background: #f4faf7;
    }

    .reservation-kiosk-primary-button:not(:disabled):hover {
        background: var(--kiosk-green-dark);
    }
}

@media (max-width: 1023px) {
    .reservation-kiosk-shell {
        min-height: 0;
        grid-template-columns: minmax(0, 1fr);
    }

    .reservation-kiosk-portrait {
        min-height: 330px;
    }

    .reservation-kiosk-brand-content {
        min-height: 330px;
        padding: 1.5rem;
    }

    .reservation-kiosk-intro {
        max-width: 630px;
    }

    .reservation-kiosk-title {
        font-size: clamp(30px, 6vw, 46px);
    }

    .reservation-kiosk-brand-metrics {
        max-width: 650px;
    }

}

@media (max-width: 639px) {
    .reservation-kiosk-portrait,
    .reservation-kiosk-brand-content {
        min-height: 198px;
    }

    .reservation-kiosk-brand-content {
        gap: 0.75rem;
        padding: 0.75rem;
    }

    .reservation-kiosk-intro,
    .reservation-kiosk-metric__helper {
        display: none;
    }

    .reservation-kiosk-brand-metrics {
        grid-template-columns: minmax(0, 1fr);
    }

    .reservation-kiosk-metric:not(.reservation-kiosk-metric--primary) {
        display: none;
    }

    .reservation-kiosk-metric--primary {
        grid-column: auto;
    }

    .reservation-kiosk-metric {
        min-height: 67px;
        padding: 0.65rem;
    }

    .reservation-kiosk-metric__icon {
        display: none;
    }

    .reservation-kiosk-metric__value,
    .reservation-kiosk-metric__number {
        font-size: 18px;
    }

    .reservation-kiosk-workspace {
        min-height: 0;
        padding: 1rem;
    }

    .reservation-kiosk-workspace__brand,
    .reservation-kiosk-tool-button span {
        display: none;
    }

    .reservation-kiosk-tool-button {
        width: 44px;
        padding: 0;
    }

    .reservation-kiosk-stepper {
        margin-top: 0.75rem;
    }

    .reservation-kiosk-stepper__label {
        max-width: 86px;
        font-size: 9px;
    }

    .reservation-kiosk-stage {
        align-items: flex-start;
        padding: 1rem;
    }

    .reservation-kiosk-step__title {
        font-size: 22px;
    }

    .reservation-kiosk-step__heading--with-icon {
        gap: 0.75rem;
    }

    .reservation-kiosk-step__icon {
        width: 48px;
        height: 48px;
    }

    .reservation-kiosk-action-list {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .reservation-kiosk-action {
        min-height: 0;
        padding: 0.625rem;
    }

    .reservation-kiosk-action__icon {
        width: 36px;
        height: 36px;
    }

    .reservation-kiosk-action__index,
    .reservation-kiosk-action__subtitle {
        display: none;
    }

    .reservation-kiosk-action__copy {
        display: block;
        margin-top: 0.5rem;
    }

    .reservation-kiosk-action__title {
        font-size: 13px;
        line-height: 1rem;
    }

    .reservation-kiosk-form-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .reservation-kiosk-form-grid__wide,
    .reservation-kiosk-form-actions {
        grid-column: auto;
    }

    .reservation-kiosk-form-actions,
    .reservation-kiosk-result-actions {
        align-items: stretch;
        flex-direction: column;
    }

    .reservation-kiosk-primary-button,
    .reservation-kiosk-secondary-button {
        width: 100%;
    }

    .reservation-kiosk-ticket__metrics {
        grid-template-columns: minmax(0, 1fr);
    }

    .reservation-kiosk-client-card {
        align-items: flex-start;
        flex-direction: column;
    }
}

@media (prefers-reduced-motion: reduce) {
    .reservation-kiosk-page {
        scroll-behavior: auto;
    }
}
</style>
