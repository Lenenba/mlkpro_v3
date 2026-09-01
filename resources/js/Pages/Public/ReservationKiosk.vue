<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import dayjs from 'dayjs';
import {
    ArrowRight,
    CalendarCheck2,
    ChevronRight,
    Clock3,
    ListChecks,
    ShieldCheck,
    TicketCheck,
} from 'lucide-vue-next';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import InputError from '@/Components/InputError.vue';
import CompanyBrandLogo from '@/Components/CompanyBrandLogo.vue';
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

const KIOSK_REFRESH_INTERVAL_MS = 30_000;
let kioskRefreshTimer = null;
let kioskRefreshInFlight = false;

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
const defaultPortraitImageUrl = '/images/landing/stock/salon-front-desk.jpg';
const portraitImageUrl = computed(() => {
    const imageUrl = String(props.settings?.kiosk_image_url || '').trim();

    return imageUrl || defaultPortraitImageUrl;
});
const estimatedWait = computed(() => props.settings?.estimated_wait || {});
const estimatedWaitLabel = computed(() => String(estimatedWait.value?.label || '0 à 5 min'));
const estimatedWaitHelper = computed(() => String(estimatedWait.value?.helper || 'Mis à jour selon la file actuelle.'));
const actionItems = computed(() => [
    {
        key: 'walk_in',
        icon: TicketCheck,
        title: t('reservations.kiosk.walk_in.title'),
        subtitle: t('reservations.kiosk.actions.walk_in_subtitle'),
        iconBoxClass: 'border-amber-100 bg-amber-50 text-amber-600',
        activeClass: 'border-amber-500 bg-amber-50/35 shadow-[inset_3px_0_0_#f59e0b]',
        inactiveClass: 'border-[#e5e7eb] hover:border-amber-200 hover:bg-amber-50/25',
    },
    {
        key: 'known_client',
        icon: CalendarCheck2,
        title: t('reservations.kiosk.actions.check_in_title'),
        subtitle: t('reservations.kiosk.actions.check_in_subtitle'),
        iconBoxClass: 'border-sky-100 bg-sky-50 text-sky-600',
        activeClass: 'border-sky-500 bg-sky-50/35 shadow-[inset_3px_0_0_#0ea5e9]',
        inactiveClass: 'border-[#e5e7eb] hover:border-sky-200 hover:bg-sky-50/25',
    },
    {
        key: 'track_ticket',
        icon: ListChecks,
        title: t('reservations.kiosk.track.title'),
        subtitle: t('reservations.kiosk.actions.track_subtitle'),
        iconBoxClass: 'border-violet-100 bg-violet-50 text-violet-600',
        activeClass: 'border-violet-500 bg-violet-50/35 shadow-[inset_3px_0_0_#8b5cf6]',
        inactiveClass: 'border-[#e5e7eb] hover:border-violet-200 hover:bg-violet-50/25',
    },
]);

const activeActionItem = computed(() => actionItems.value.find((item) => item.key === activeMode.value) || actionItems.value[0]);

const currentPreview = computed(() => {
    if (activeMode.value === 'known_client') {
        return {
            label: t('reservations.kiosk.preview.label'),
            title: t('reservations.kiosk.actions.check_in_title'),
            description: t('reservations.kiosk.preview.check_in_description'),
            icon: activeActionItem.value.icon,
            iconBoxClass: activeActionItem.value.iconBoxClass,
            submitLabel: lookupForm.processing ? t('reservations.kiosk.actions.searching') : t('reservations.kiosk.known_client.lookup'),
        };
    }

    if (activeMode.value === 'track_ticket') {
        return {
            label: t('reservations.kiosk.preview.label'),
            title: t('reservations.kiosk.track.title'),
            description: t('reservations.kiosk.preview.track_description'),
            icon: activeActionItem.value.icon,
            iconBoxClass: activeActionItem.value.iconBoxClass,
            submitLabel: trackForm.processing ? t('reservations.kiosk.actions.searching') : t('reservations.kiosk.track.submit'),
        };
    }

    return {
        label: t('reservations.kiosk.preview.label'),
        title: t('reservations.kiosk.walk_in.title'),
        description: t('reservations.kiosk.preview.walk_in_description'),
        icon: activeActionItem.value.icon,
        iconBoxClass: activeActionItem.value.iconBoxClass,
        submitLabel: walkInForm.processing ? t('reservations.kiosk.actions.creating') : t('reservations.kiosk.walk_in.submit'),
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

const prefersReducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const focusActiveForm = async () => {
    await nextTick();
    const form = document.querySelector('[data-kiosk-active-form]');

    if (form) {
        form.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'center' });
        const firstInput = form.querySelector('input, select, button');
        firstInput?.focus?.();
    }
};

const setMode = (mode) => {
    activeMode.value = mode;
    focusActiveForm();
};

const continueAction = () => {
    const form = document.querySelector('[data-kiosk-active-form]');
    if (form?.requestSubmit) {
        form.requestSubmit();
        return;
    }

    focusActiveForm();
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
    <PublicKioskLayout>
        <Head :title="`${kioskTitle} - ${brandName}`" />

        <main class="reservation-kiosk-page">
            <div class="reservation-kiosk-shell">
            <header class="reservation-kiosk-header">
                <div class="flex flex-wrap items-center gap-5">
                    <h1 class="reservation-kiosk-brand-title text-[23px] font-bold leading-none text-[#0f1720] lg:text-[clamp(21px,2.7vh,24px)]">
                        {{ kioskTitle }}
                    </h1>
                    <span class="reservation-kiosk-category inline-flex items-center border border-[#dcebe3] bg-[#eef7f2] px-3 py-1.5 text-[12px] font-semibold text-[#0b7e55]">
                        {{ $t('reservations.kiosk.category') }}
                    </span>
                </div>

                <div class="flex items-center justify-center md:justify-self-center">
                    <CompanyBrandLogo
                        :company="company"
                        :name="brandName"
                        container-class="h-14 w-[190px] p-1.5"
                        class="reservation-kiosk-brand-logo"
                    />
                </div>

                <div class="flex justify-start md:justify-end">
                    <LanguageSwitcherMenu
                        button-class="relative inline-flex size-11 items-center justify-center rounded-sm text-sky-700 hover:bg-stone-100 focus:outline-none focus:ring-2 focus:ring-sky-500"
                        icon-class="size-6"
                    />
                </div>
            </header>

            <div class="reservation-kiosk-content">
                <section class="reservation-kiosk-hero-grid">
                    <section class="reservation-kiosk-portrait" aria-labelledby="reservation-kiosk-welcome-title">
                        <img
                            :src="portraitImageUrl"
                            alt=""
                            class="reservation-kiosk-portrait__image block"
                            loading="eager"
                        >
                        <div class="reservation-kiosk-portrait__scrim" aria-hidden="true"></div>

                        <div class="reservation-kiosk-intro">
                            <div class="reservation-kiosk-intro-stack">
                                <div class="reservation-kiosk-intro-copy">
                                    <span class="reservation-kiosk-intro-kicker">{{ $t('reservations.kiosk.category') }}</span>
                                    <h2 id="reservation-kiosk-welcome-title" class="reservation-kiosk-title">
                                        <span>{{ $t('reservations.kiosk.hero.welcome') }}</span><br>
                                        <span class="reservation-kiosk-title__brand">{{ brandName }}</span>
                                    </h2>
                                    <p class="reservation-kiosk-description">
                                        <span>{{ $t('reservations.kiosk.hero.line_one') }}</span><br class="hidden sm:block">
                                        <span>{{ $t('reservations.kiosk.hero.line_two') }}</span>
                                    </p>
                                </div>

                                <div class="reservation-kiosk-wait-card">
                                    <div class="reservation-kiosk-wait-row">
                                        <div class="reservation-kiosk-wait-icon">
                                            <Clock3 class="reservation-kiosk-wait-icon__svg" aria-hidden="true" />
                                        </div>
                                        <div>
                                            <p class="reservation-kiosk-wait-label">{{ $t('reservations.kiosk.wait.title') }}</p>
                                            <p class="reservation-kiosk-wait-value">{{ estimatedWaitLabel }}</p>
                                            <p class="reservation-kiosk-wait-helper">{{ estimatedWaitHelper }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="reservation-kiosk-actions" aria-labelledby="reservation-kiosk-actions-title">
                        <div class="reservation-kiosk-actions__heading">
                            <div>
                                <span class="reservation-kiosk-actions__eyebrow">{{ kioskTitle }}</span>
                                <h2 id="reservation-kiosk-actions-title" class="reservation-kiosk-actions__title">
                                    {{ $t('reservations.kiosk.actions.title') }}
                                </h2>
                            </div>
                            <span class="reservation-kiosk-actions__count" aria-hidden="true">3</span>
                        </div>

                        <div class="reservation-kiosk-action-list">
                            <button
                                v-for="(item, index) in actionItems"
                                :key="item.key"
                                type="button"
                                class="group reservation-kiosk-action"
                                :class="activeMode === item.key ? item.activeClass : item.inactiveClass"
                                :aria-pressed="activeMode === item.key"
                                aria-controls="kiosk-form-panel"
                                @click="setMode(item.key)"
                            >
                                <span class="reservation-kiosk-action__icon" :class="item.iconBoxClass">
                                    <component :is="item.icon" class="reservation-kiosk-action__icon-svg" aria-hidden="true" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="reservation-kiosk-action__index" aria-hidden="true">0{{ index + 1 }}</span>
                                    <span class="reservation-kiosk-action__title">{{ item.title }}</span>
                                    <span class="reservation-kiosk-action__subtitle">{{ item.subtitle }}</span>
                                </span>
                                <ChevronRight class="reservation-kiosk-action__chevron" aria-hidden="true" />
                            </button>
                        </div>

                        <button
                            type="button"
                            class="reservation-kiosk-continue"
                            aria-controls="kiosk-form-panel"
                            @click="continueAction"
                        >
                            <span class="flex-1 text-center">{{ currentPreview.submitLabel }}</span>
                            <ArrowRight class="size-6" aria-hidden="true" />
                        </button>
                    </section>
                </section>

                <section id="kiosk-form-panel" class="reservation-kiosk-form-panel mt-4 border bg-white p-3 lg:mt-3 lg:p-[clamp(10px,1.5vh,14px)]" aria-labelledby="reservation-kiosk-form-title" data-kiosk-form>
                    <div class="grid gap-4 lg:grid-cols-[minmax(300px,360px)_minmax(0,1fr)] lg:gap-3 xl:grid-cols-[380px_minmax(0,1fr)] 2xl:grid-cols-[400px_minmax(0,1fr)]">
                        <div class="reservation-kiosk-form-preview flex items-center gap-5 lg:border-r lg:pr-4">
                            <div class="reservation-kiosk-form-preview__icon flex h-[68px] w-[68px] shrink-0 items-center justify-center border lg:h-[clamp(52px,7vh,68px)] lg:w-[clamp(52px,7vh,68px)]" :class="currentPreview.iconBoxClass">
                                <component :is="currentPreview.icon" class="size-8 lg:h-[clamp(26px,4.2vh,34px)] lg:w-[clamp(26px,4.2vh,34px)]" aria-hidden="true" />
                            </div>
                            <div>
                                <p class="text-[11px] font-bold uppercase text-[#475569]">{{ currentPreview.label }}</p>
                                <h3 id="reservation-kiosk-form-title" class="mt-1.5 text-[16px] font-extrabold leading-5 text-[#0f1720]">{{ currentPreview.title }}</h3>
                                <p class="mt-2 max-w-[270px] text-[12px] font-medium leading-5 text-[#475569]">{{ currentPreview.description }}</p>
                            </div>
                        </div>

                        <div class="reservation-kiosk-form-fields">
                            <form v-if="activeMode === 'walk_in'" class="grid gap-3 xl:grid-cols-[1fr_0.95fr_1fr_0.62fr_auto]" data-kiosk-active-form @submit.prevent="submitWalkIn">
                                <div>
                                    <FloatingInput
                                        id="walk-in-phone"
                                        v-model="walkInForm.phone"
                                        type="tel"
                                        :label="$t('reservations.kiosk.fields.phone')"
                                        :placeholder="phoneProfile.internationalPlaceholder"
                                        :required="true"
                                        autocomplete="tel"
                                        class="h-[52px] border-[#dfe5e1] bg-white text-[#334155]"
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
                                        class="h-[52px] border-[#dfe5e1] bg-white text-[#334155]"
                                    />
                                    <InputError class="mt-1" :message="walkInForm.errors.guest_name" />
                                </div>

                                <div>
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
                                        class="h-[52px] border-[#dfe5e1] bg-white text-[#334155]"
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
                                        class="h-[52px] border-[#dfe5e1] bg-white text-[#334155]"
                                    />
                                    <InputError class="mt-1" :message="walkInForm.errors.party_size" />
                                </div>

                                <div class="flex items-end">
                                    <button type="submit" class="reservation-kiosk-submit h-[52px] px-4 text-sm font-extrabold text-white transition disabled:opacity-60" :disabled="walkInForm.processing">
                                        {{ currentPreview.submitLabel }}
                                    </button>
                                </div>
                            </form>

                            <form v-else-if="activeMode === 'known_client'" class="grid gap-3 xl:grid-cols-[1.1fr_1fr_auto]" data-kiosk-active-form @submit.prevent="lookupClient">
                                <div>
                                    <FloatingInput
                                        id="lookup-phone"
                                        v-model="lookupForm.phone"
                                        type="tel"
                                        :label="$t('reservations.kiosk.fields.phone')"
                                        :placeholder="phoneProfile.internationalPlaceholder"
                                        :required="true"
                                        autocomplete="tel"
                                        class="h-[52px] border-[#dfe5e1] bg-white text-[#334155]"
                                    />
                                    <InputError class="mt-1" :message="lookupForm.errors.phone" />
                                </div>
                                <div>
                                    <FloatingSelect
                                        id="lookup-service-search"
                                        v-model="clientTicketForm.service_id"
                                        :label="$t('reservations.kiosk.fields.service')"
                                        :options="serviceOptions"
                                        option-value="value"
                                        option-label="label"
                                        filterable
                                        :filter-placeholder="$t('reservations.kiosk.placeholders.search_service')"
                                        :empty-label="$t('reservations.kiosk.messages.no_service_match')"
                                        class="h-[52px] border-[#dfe5e1] bg-white text-[#334155]"
                                    />
                                </div>
                                <div class="flex items-end">
                                    <button type="submit" class="reservation-kiosk-submit h-[52px] px-4 text-sm font-extrabold text-white transition disabled:opacity-60" :disabled="lookupForm.processing">
                                        {{ currentPreview.submitLabel }}
                                    </button>
                                </div>
                            </form>

                            <form v-else class="grid gap-3 xl:grid-cols-[1.1fr_0.92fr_auto]" data-kiosk-active-form @submit.prevent="trackTicket">
                                <div>
                                    <FloatingInput
                                        id="track-phone"
                                        v-model="trackForm.phone"
                                        type="tel"
                                        :label="$t('reservations.kiosk.fields.phone')"
                                        :placeholder="phoneProfile.internationalPlaceholder"
                                        :required="true"
                                        autocomplete="tel"
                                        class="h-[52px] border-[#dfe5e1] bg-white text-[#334155]"
                                    />
                                    <InputError class="mt-1" :message="trackForm.errors.phone" />
                                </div>
                                <div>
                                    <FloatingInput
                                        id="track-number"
                                        v-model="trackForm.queue_number"
                                        :label="$t('reservations.kiosk.fields.queue_number')"
                                        placeholder="A-001"
                                        class="h-[52px] border-[#dfe5e1] bg-white text-[#334155]"
                                    />
                                    <InputError class="mt-1" :message="trackForm.errors.queue_number" />
                                </div>
                                <div class="flex items-end">
                                    <button type="submit" class="reservation-kiosk-submit h-[52px] px-4 text-sm font-extrabold text-white transition disabled:opacity-60" :disabled="trackForm.processing">
                                        {{ currentPreview.submitLabel }}
                                    </button>
                                </div>
                            </form>

                            <div class="reservation-kiosk-security mt-3 flex items-center gap-3 border px-4 py-2 text-[12px] font-medium text-[#334155]">
                                <ShieldCheck class="size-5 shrink-0 text-[#0f9a68]" aria-hidden="true" />
                                {{ $t('reservations.kiosk.security_notice') }}
                            </div>
                        </div>
                    </div>

                    <div class="space-y-3" :class="{ 'mt-4': hasKioskFeedback }" aria-live="polite" aria-atomic="false">
                        <div v-if="walkInError && activeMode === 'walk_in'" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ walkInError }}</div>
                        <div v-if="walkInSuccess && activeMode === 'walk_in'" class="rounded-sm border border-[#dcebe3] bg-[#eef7f2] px-3 py-2 text-sm text-[#0b7e55]">{{ walkInSuccess }}</div>
                        <div v-if="walkInResult && activeMode === 'walk_in'" class="rounded-sm border border-[#dcebe3] bg-white px-4 py-3 text-sm text-[#334155]">
                            <div class="font-extrabold text-[#0f1720]">{{ $t('reservations.kiosk.labels.ticket') }}: {{ walkInResult.queue_number }}</div>
                            <div class="mt-1 text-xs text-[#64748b]">
                                Position: {{ walkInResult.position ?? '-' }} · ETA {{ walkInResult.eta_minutes !== null && walkInResult.eta_minutes !== undefined ? `${walkInResult.eta_minutes} min` : '-' }}
                            </div>
                        </div>

                        <div v-if="lookupError && activeMode === 'known_client'" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ lookupError }}</div>
                        <div v-if="lookupSuccess && activeMode === 'known_client'" class="rounded-sm border border-[#dcebe3] bg-[#eef7f2] px-3 py-2 text-sm text-[#0b7e55]">{{ lookupSuccess }}</div>

                        <div v-if="verificationRequired && !isVerifiedClientFlow && activeMode === 'known_client'" class="rounded-sm border border-amber-200 bg-amber-50 p-3">
                            <p class="text-sm font-medium text-amber-800">{{ $t('reservations.kiosk.known_client.verify_prompt') }}</p>
                            <p v-if="verificationDebugCode" class="mt-1 text-xs text-amber-700">
                                {{ $t('reservations.kiosk.known_client.debug_code') }}: <strong>{{ verificationDebugCode }}</strong>
                            </p>
                            <form class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="verifyClient">
                                <div class="min-w-[190px] flex-1">
                                    <FloatingInput
                                        id="verification-code"
                                        v-model="verifyForm.code"
                                        :label="$t('reservations.kiosk.fields.code')"
                                        class="h-[52px] border-[#dfe5e1] bg-white text-[#334155]"
                                    />
                                    <InputError class="mt-1" :message="verifyForm.errors.code" />
                                </div>
                                <button type="submit" class="h-[52px] rounded-sm bg-amber-700 px-4 text-xs font-extrabold text-white transition hover:bg-amber-800 focus-visible:outline focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-amber-900 disabled:opacity-60" :disabled="verifyForm.processing">
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
                                <button type="button" class="reservation-kiosk-submit mt-3 min-h-11 px-4 py-2 text-xs font-extrabold text-white transition disabled:opacity-60" :disabled="lookupForm.processing" @click="checkInReservation">
                                    {{ $t('reservations.kiosk.known_client.check_in') }}
                                </button>
                            </div>

                            <form v-else-if="canCreateClientTicket" class="rounded-sm border border-[#dfe5e1] bg-white p-3 text-sm lg:col-span-2" @submit.prevent="createClientTicket">
                                <p class="text-xs font-medium text-[#64748b]">{{ $t('reservations.kiosk.known_client.create_ticket_help') }}</p>
                                <div class="mt-3 grid gap-3 md:grid-cols-4">
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
                                        class="h-[52px] border-[#dfe5e1] bg-white text-[#334155]"
                                    />
                                    <FloatingSelect
                                        id="client-ticket-team"
                                        v-model="clientTicketForm.team_member_id"
                                        :label="$t('reservations.kiosk.fields.team_member')"
                                        :options="teamOptions"
                                        option-value="value"
                                        option-label="label"
                                        filterable
                                        class="h-[52px] border-[#dfe5e1] bg-white text-[#334155]"
                                    />
                                    <FloatingSelect
                                        id="client-ticket-party"
                                        v-model="clientTicketForm.party_size"
                                        :label="$t('reservations.kiosk.fields.party_size')"
                                        :options="partySizeOptions"
                                        option-value="value"
                                        option-label="label"
                                        class="h-[52px] border-[#dfe5e1] bg-white text-[#334155]"
                                    />
                                    <button type="submit" class="reservation-kiosk-submit h-[52px] px-4 text-xs font-extrabold text-white transition disabled:opacity-60" :disabled="clientTicketForm.processing">
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

                        <div v-if="checkInError && activeMode === 'known_client'" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ checkInError }}</div>
                        <div v-if="checkInSuccess && activeMode === 'known_client'" class="rounded-sm border border-[#dcebe3] bg-[#eef7f2] px-3 py-2 text-sm text-[#0b7e55]">{{ checkInSuccess }}</div>
                        <div v-if="checkInResult && activeMode === 'known_client'" class="rounded-sm border border-[#dcebe3] bg-white px-3 py-2 text-sm text-[#334155]">
                            {{ $t('reservations.kiosk.labels.ticket') }}: {{ checkInResult.queue_number }} · Position: {{ checkInResult.position ?? '-' }}
                        </div>

                        <div v-if="trackError && activeMode === 'track_ticket'" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ trackError }}</div>
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
    padding: clamp(0.75rem, 1.35vw, 1.35rem);
    overflow-x: hidden;
    color: var(--kiosk-ink);
    background:
        radial-gradient(circle at 7% 0%, rgb(15 154 104 / 0.14), transparent 31rem),
        radial-gradient(circle at 96% 18%, rgb(14 165 233 / 0.09), transparent 27rem),
        linear-gradient(180deg, #f7faf8 0%, #edf3ef 100%);
}

.reservation-kiosk-page::before {
    position: fixed;
    inset: 0;
    pointer-events: none;
    content: '';
    background-image: radial-gradient(rgb(15 23 32 / 0.055) 0.65px, transparent 0.65px);
    background-size: 18px 18px;
    -webkit-mask-image: linear-gradient(to bottom, rgb(0 0 0 / 0.45), transparent 64%);
    mask-image: linear-gradient(to bottom, rgb(0 0 0 / 0.45), transparent 64%);
}

.reservation-kiosk-shell {
    position: relative;
    z-index: 1;
    max-width: 1680px;
    margin-inline: auto;
    overflow: hidden;
    border: 1px solid rgb(255 255 255 / 0.86);
    border-radius: 0.125rem;
    background: rgb(255 255 255 / 0.94);
    box-shadow: 0 28px 80px -42px rgb(15 23 32 / 0.34);
    isolation: isolate;
}

.reservation-kiosk-header {
    display: grid;
    min-height: 88px;
    flex-shrink: 0;
    grid-template-columns: minmax(0, 1fr);
    align-items: center;
    gap: 1rem;
    border-bottom: 1px solid rgb(216 229 222 / 0.82);
    padding: 0.875rem clamp(1rem, 2.2vw, 2rem);
    background: rgb(255 255 255 / 0.84);
    backdrop-filter: blur(14px);
}

.reservation-kiosk-brand-title {
    letter-spacing: -0.025em;
}

.reservation-kiosk-category {
    gap: 0.45rem;
    border-radius: 0.125rem;
}

.reservation-kiosk-category::before {
    width: 0.4rem;
    height: 0.4rem;
    border-radius: 999px;
    background: var(--kiosk-green);
    box-shadow: 0 0 0 3px rgb(11 126 85 / 0.12);
    content: '';
}

.reservation-kiosk-brand-logo {
    border-color: #e2ebe6;
    border-radius: 0.125rem;
    background: #fff;
    box-shadow: 0 10px 26px -20px rgb(15 23 32 / 0.42);
}

.reservation-kiosk-brand-logo.company-brand-logo--custom {
    background-color: #fff;
    background-image: linear-gradient(145deg, #fff, #f5f8f6);
}

.reservation-kiosk-content {
    padding: clamp(0.875rem, 1.7vw, 1.4rem) clamp(1rem, 2.2vw, 2rem) 0.5rem;
}

.reservation-kiosk-hero-grid {
    display: grid;
    gap: clamp(0.875rem, 1.4vw, 1.25rem);
}

.reservation-kiosk-portrait {
    position: relative;
    min-height: 540px;
    overflow: hidden;
    border: 1px solid rgb(255 255 255 / 0.64);
    border-radius: 0.125rem;
    background: #1c2d25;
    box-shadow: 0 24px 52px -32px rgb(15 23 32 / 0.62);
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
    transform: scale(1.002);
}

.reservation-kiosk-portrait__scrim {
    position: absolute;
    z-index: 1;
    inset: 0;
    background:
        linear-gradient(90deg, rgb(5 18 12 / 0.92) 0%, rgb(5 18 12 / 0.78) 48%, rgb(5 18 12 / 0.58) 100%),
        linear-gradient(0deg, rgb(5 18 12 / 0.48) 0%, transparent 58%);
}

.reservation-kiosk-intro {
    position: relative;
    z-index: 2;
    display: flex;
    min-height: 540px;
    padding: clamp(1.5rem, 4vw, 3.25rem);
    color: white;
}

.reservation-kiosk-intro-stack {
    display: grid;
    width: 100%;
    align-content: end;
    align-items: end;
    gap: clamp(1.25rem, 2.5vw, 2rem);
}

.reservation-kiosk-intro-copy {
    max-width: 650px;
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
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    backdrop-filter: blur(8px);
}

.reservation-kiosk-title {
    max-width: 620px;
    margin-top: 1rem;
    color: white;
    font-size: clamp(30px, 8.5vw, 44px);
    font-weight: 800;
    line-height: 1.06;
    letter-spacing: -0.035em;
    text-wrap: balance;
    text-shadow: 0 3px 22px rgb(0 0 0 / 0.3);
}

.reservation-kiosk-title__brand {
    color: #a7f3d0;
}

.reservation-kiosk-description {
    max-width: 560px;
    margin-top: 1rem;
    color: rgb(255 255 255 / 0.84);
    font-size: clamp(14px, 2.4vw, 16px);
    font-weight: 550;
    line-height: 1.65;
    text-wrap: pretty;
    text-shadow: 0 2px 14px rgb(0 0 0 / 0.32);
}

.reservation-kiosk-wait-card {
    width: 100%;
    max-width: 350px;
    border: 1px solid rgb(255 255 255 / 0.68);
    border-radius: 0.125rem;
    padding: 0.875rem;
    color: var(--kiosk-ink);
    background: rgb(255 255 255 / 0.92);
    box-shadow: 0 18px 36px -26px rgb(0 0 0 / 0.68);
    backdrop-filter: blur(14px);
}

.reservation-kiosk-wait-row {
    display: flex;
    align-items: center;
    gap: 0.875rem;
}

.reservation-kiosk-wait-icon {
    display: flex;
    width: 3rem;
    height: 3rem;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    border: 1px solid #cceadd;
    border-radius: 0.125rem;
    color: var(--kiosk-green);
    background: #eaf7f0;
    box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.8);
}

.reservation-kiosk-wait-icon__svg {
    width: 1.5rem;
    height: 1.5rem;
}

.reservation-kiosk-wait-label {
    color: #405149;
    font-size: 12px;
    font-weight: 700;
}

.reservation-kiosk-wait-value {
    margin-top: 0.25rem;
    color: var(--kiosk-green);
    font-size: 23px;
    font-weight: 850;
    line-height: 1;
}

.reservation-kiosk-wait-helper {
    margin-top: 0.45rem;
    color: #607169;
    font-size: 11px;
    font-weight: 600;
    line-height: 1.35;
}

.reservation-kiosk-actions {
    display: flex;
    min-width: 0;
    flex-direction: column;
    border: 1px solid var(--kiosk-border);
    border-radius: 0.125rem;
    padding: clamp(1rem, 2vw, 1.5rem);
    background:
        radial-gradient(circle at 100% 0%, rgb(15 154 104 / 0.09), transparent 18rem),
        linear-gradient(180deg, #fff 0%, #fbfdfc 100%);
    box-shadow: 0 22px 46px -34px rgb(15 23 32 / 0.42);
}

.reservation-kiosk-actions__heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.reservation-kiosk-actions__eyebrow {
    display: block;
    color: var(--kiosk-green);
    font-size: 10px;
    font-weight: 850;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.reservation-kiosk-actions__title {
    margin-top: 0.35rem;
    color: var(--kiosk-ink);
    font-size: clamp(20px, 2.2vw, 24px);
    font-weight: 850;
    line-height: 1.2;
    letter-spacing: -0.025em;
}

.reservation-kiosk-actions__count {
    display: inline-flex;
    width: 2.75rem;
    height: 2.75rem;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    border: 1px solid #cfe9dc;
    border-radius: 0.125rem;
    color: var(--kiosk-green);
    background: #eef8f3;
    font-size: 13px;
    font-weight: 850;
}

.reservation-kiosk-action-list {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    justify-content: center;
    gap: 0.75rem;
    margin-top: 1rem;
}

.reservation-kiosk-action {
    display: flex;
    min-height: 82px;
    width: 100%;
    align-items: center;
    gap: 0.875rem;
    border-width: 1px;
    border-radius: 0.125rem;
    background: white;
    padding: 0.75rem 0.875rem;
    text-align: left;
    box-shadow: 0 8px 22px -20px rgb(15 23 32 / 0.52);
    transition: transform 180ms ease, background-color 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
}

.reservation-kiosk-action[aria-pressed='true'] {
    border-color: #7bc6a4;
    background: linear-gradient(100deg, #edf8f2 0%, #fff 78%);
    box-shadow: 0 16px 28px -22px rgb(11 126 85 / 0.7), inset 4px 0 0 var(--kiosk-green);
    transform: translateY(-1px);
}

.reservation-kiosk-action:focus-visible {
    outline: 3px solid var(--kiosk-green);
    outline-offset: 3px;
    box-shadow: 0 12px 24px -20px rgb(15 23 32 / 0.58);
}

.reservation-kiosk-action__icon {
    display: flex;
    width: 3.25rem;
    height: 3.25rem;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    border-width: 1px;
    border-radius: 0.125rem;
    box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.9);
}

.reservation-kiosk-action__icon-svg {
    width: 1.5rem;
    height: 1.5rem;
}

.reservation-kiosk-action__index {
    display: block;
    margin-bottom: 0.15rem;
    color: #809087;
    font-size: 9px;
    font-weight: 850;
    letter-spacing: 0.12em;
}

.reservation-kiosk-action__title {
    display: block;
    color: var(--kiosk-ink);
    font-size: 15px;
    font-weight: 850;
    line-height: 1.25rem;
}

.reservation-kiosk-action__subtitle {
    display: block;
    margin-top: 0.15rem;
    color: var(--kiosk-muted);
    font-size: 13px;
    font-weight: 550;
    line-height: 1.25rem;
}

.reservation-kiosk-action__chevron {
    width: 1.35rem;
    height: 1.35rem;
    flex-shrink: 0;
    color: #718078;
    transition: transform 150ms ease;
}

.reservation-kiosk-continue {
    display: flex;
    width: 100%;
    min-height: 54px;
    align-items: center;
    justify-content: center;
    margin-top: 0.875rem;
    border-radius: 0.125rem;
    background: linear-gradient(135deg, var(--kiosk-green), #096a48);
    padding-inline: 1.25rem;
    color: white;
    font-size: 15px;
    font-weight: 850;
    line-height: 1.25rem;
    box-shadow: 0 16px 26px -18px rgb(11 126 85 / 0.72);
    transition: transform 180ms ease, background-color 180ms ease, box-shadow 180ms ease;
}

.reservation-kiosk-continue:hover {
    background: linear-gradient(135deg, var(--kiosk-green-dark), var(--kiosk-green));
    transform: translateY(-1px);
    box-shadow: 0 18px 30px -18px rgb(11 126 85 / 0.82);
}

.reservation-kiosk-continue:focus-visible {
    outline: 3px solid #064e3b;
    outline-offset: 3px;
    box-shadow: 0 16px 26px -18px rgb(11 126 85 / 0.72);
}

.reservation-kiosk-form-panel {
    border-color: var(--kiosk-border);
    border-radius: 0.125rem;
    background:
        linear-gradient(100deg, rgb(238 248 243 / 0.68), rgb(255 255 255 / 0.96) 34%, #fff 100%);
    box-shadow: 0 20px 42px -34px rgb(15 23 32 / 0.4);
}

.reservation-kiosk-form-preview {
    border-color: #dce7e1;
}

.reservation-kiosk-form-preview__icon {
    border-radius: 0.125rem;
    box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.9), 0 10px 22px -18px rgb(15 23 32 / 0.46);
}

.reservation-kiosk-form-fields {
    min-width: 0;
}

.reservation-kiosk-form-panel :deep(.app-field-control) {
    border-radius: 0.125rem;
}

.reservation-kiosk-submit {
    min-width: 132px;
    border-radius: 0.125rem;
    background: var(--kiosk-green);
    box-shadow: 0 12px 22px -16px rgb(11 126 85 / 0.74);
}

.reservation-kiosk-submit:hover {
    background: var(--kiosk-green-dark);
}

.reservation-kiosk-submit:focus-visible {
    outline: 3px solid #064e3b;
    outline-offset: 3px;
}

.reservation-kiosk-security {
    border-color: #d5e9df;
    border-radius: 0.125rem;
    background: rgb(239 249 244 / 0.82);
}

@media (min-width: 640px) {
    .reservation-kiosk-portrait__scrim {
        background:
            linear-gradient(90deg, rgb(5 18 12 / 0.9) 0%, rgb(5 18 12 / 0.72) 42%, rgb(5 18 12 / 0.3) 72%, rgb(5 18 12 / 0.14) 100%),
            linear-gradient(0deg, rgb(5 18 12 / 0.48) 0%, transparent 58%);
    }

    .reservation-kiosk-intro-stack {
        grid-template-columns: minmax(0, 1fr) minmax(260px, 350px);
    }
}

@media (min-width: 768px) {
    .reservation-kiosk-header {
        grid-template-columns: 1fr auto 1fr;
    }

    .reservation-kiosk-portrait,
    .reservation-kiosk-intro {
        min-height: 500px;
    }
}

@media (min-width: 1024px) {
    .reservation-kiosk-page {
        padding: 10px 0.75rem;
    }

    .reservation-kiosk-shell {
        display: flex;
        flex-direction: column;
    }

    .reservation-kiosk-header {
        height: clamp(72px, 10vh, 96px);
        min-height: 0;
        padding: 0.75rem 1.75rem;
    }

    .reservation-kiosk-content {
        display: flex;
        flex: 1 1 0%;
        flex-direction: column;
        padding: 0.75rem 1.75rem;
    }

    .reservation-kiosk-hero-grid {
        height: clamp(390px, 54vh, 510px);
        flex-shrink: 0;
        grid-template-columns: minmax(0, 1.48fr) minmax(360px, 0.82fr);
        align-items: stretch;
    }

    .reservation-kiosk-portrait,
    .reservation-kiosk-intro,
    .reservation-kiosk-actions {
        height: 100%;
        min-height: 0;
    }

    .reservation-kiosk-intro {
        padding: clamp(1.5rem, 3.6vh, 2.6rem);
    }

    .reservation-kiosk-title {
        font-size: clamp(32px, 5.2vh, 48px);
    }

    .reservation-kiosk-description {
        margin-top: clamp(10px, 1.7vh, 16px);
        font-size: clamp(13px, 1.85vh, 16px);
    }

    .reservation-kiosk-wait-card {
        padding: clamp(10px, 1.5vh, 14px);
    }

    .reservation-kiosk-wait-icon {
        width: clamp(40px, 5.8vh, 48px);
        height: clamp(40px, 5.8vh, 48px);
    }

    .reservation-kiosk-wait-icon__svg {
        width: clamp(20px, 3vh, 24px);
        height: clamp(20px, 3vh, 24px);
    }

    .reservation-kiosk-actions {
        padding: clamp(14px, 2.2vh, 22px);
    }

    .reservation-kiosk-actions__title {
        font-size: clamp(19px, 2.6vh, 24px);
    }

    .reservation-kiosk-action-list {
        gap: clamp(8px, 1.4vh, 12px);
        margin-top: clamp(10px, 1.8vh, 16px);
    }

    .reservation-kiosk-action {
        min-height: clamp(66px, 9.2vh, 82px);
    }

    .reservation-kiosk-action__icon {
        width: clamp(44px, 6.2vh, 52px);
        height: clamp(44px, 6.2vh, 52px);
    }

    .reservation-kiosk-action__icon-svg {
        width: clamp(21px, 3.2vh, 24px);
        height: clamp(21px, 3.2vh, 24px);
    }

    .reservation-kiosk-action__title {
        font-size: clamp(14px, 2vh, 15px);
    }

    .reservation-kiosk-continue {
        min-height: clamp(48px, 6.5vh, 54px);
        margin-top: clamp(9px, 1.5vh, 14px);
    }
}

@media (min-width: 1280px) {
    .reservation-kiosk-header,
    .reservation-kiosk-content {
        padding-inline: 2.25rem;
    }

    .reservation-kiosk-hero-grid {
        grid-template-columns: minmax(0, 1.55fr) minmax(400px, 0.78fr);
    }
}

@media (min-width: 1536px) {
    .reservation-kiosk-title {
        font-size: 48px;
    }

    .reservation-kiosk-description {
        font-size: 16px;
    }
}

@media (min-width: 1024px) and (max-height: 760px) {
    .reservation-kiosk-hero-grid {
        height: clamp(350px, 50vh, 390px);
    }

    .reservation-kiosk-action__subtitle,
    .reservation-kiosk-wait-helper {
        display: none;
    }

    .reservation-kiosk-action {
        min-height: 62px;
    }
}

@media (hover: hover) {
    .reservation-kiosk-action:hover .reservation-kiosk-action__chevron {
        transform: translateX(0.2rem);
    }

    .reservation-kiosk-action:not([aria-pressed='true']):hover {
        border-color: #b9d9c9;
        box-shadow: 0 14px 26px -22px rgb(15 23 32 / 0.58);
        transform: translateY(-1px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .reservation-kiosk-action,
    .reservation-kiosk-action__chevron,
    .reservation-kiosk-continue,
    .reservation-kiosk-submit {
        transition-duration: 0.01ms;
    }

    .reservation-kiosk-action,
    .reservation-kiosk-action:hover,
    .reservation-kiosk-continue:hover {
        transform: none;
    }
}
</style>
