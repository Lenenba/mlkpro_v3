<script setup>
import {
    AlertTriangle,
    CalendarDays,
    Clock3,
    Coins,
    Crown,
    FileText,
    History,
    Mail,
    PackageOpen,
    Phone,
    RefreshCw,
    Sparkles,
    UserRound,
    UsersRound,
    X,
} from 'lucide-vue-next';
import { computed, ref, useSlots, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ReservationStatusBadge from '@/Components/Reservation/ReservationStatusBadge.vue';
import EntityAvatar from '@/Components/UI/EntityAvatar.vue';
import { formatCurrencyAmount } from '@/utils/currency';

const props = defineProps({
    reservation: {
        type: Object,
        default: null,
    },
    timezone: {
        type: String,
        default: 'UTC',
    },
    loading: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    busy: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close', 'retry']);
const slots = useSlots();
const { locale, t } = useI18n();
const serviceImageFailed = ref(false);

const interpolate = (value, params) =>
    Object.entries(params).reduce(
        (result, [key, replacement]) => result.replaceAll(`{${key}}`, String(replacement)),
        value,
    );

const translate = (key, fallback, params = {}) => {
    const translationKey = key.startsWith('reservations.details.')
        ? key
        : `reservations.details.${key}`;
    const translated = t(translationKey, params);

    return translated === translationKey ? interpolate(fallback, params) : translated;
};

const humanize = (value) =>
    String(value || '')
        .replaceAll('_', ' ')
        .replace(/\b\p{L}/gu, (letter) => letter.toLocaleUpperCase());

const firstString = (...values) =>
    values.find((value) => typeof value === 'string' && value.trim())?.trim() || '';

const entityName = (entity, fallback) => {
    const nestedUser = entity?.user || {};
    const composedName = [entity?.first_name, entity?.last_name].filter(Boolean).join(' ').trim();

    return (
        firstString(
            entity?.display_name,
            entity?.name,
            entity?.contact_name,
            entity?.company_name,
            composedName,
            nestedUser?.display_name,
            nestedUser?.name,
        ) || fallback
    );
};

const hasActions = computed(() => Boolean(slots.actions));
const hasSupplementary = computed(() => Boolean(slots.supplementary));

const service = computed(() => props.reservation?.service || {});
const client = computed(
    () =>
        props.reservation?.client ||
        props.reservation?.customer ||
        props.reservation?.contact ||
        props.reservation?.prospect ||
        {},
);
const teamMember = computed(
    () => props.reservation?.team_member || props.reservation?.teamMember || {},
);

const serviceName = computed(
    () =>
        firstString(service.value.name, service.value.title, props.reservation?.service_name) ||
        translate('service_fallback', 'Service'),
);
const clientName = computed(
    () =>
        entityName(client.value, firstString(props.reservation?.client_name)) ||
        translate('customer_fallback', 'Customer'),
);
const teamMemberName = computed(
    () =>
        entityName(teamMember.value, firstString(props.reservation?.team_member_name)) ||
        translate('team_member_fallback', 'Team member'),
);
const teamMemberTitle = computed(() => firstString(teamMember.value.title));
const isVipClient = computed(() => Boolean(client.value.is_vip));
const partySize = computed(() => {
    const value = Number(props.reservation?.party_size || 0);

    return Number.isFinite(value) && value > 0 ? Math.round(value) : null;
});

const serviceImageUrl = computed(() => {
    const hasRealImage = [true, 1, '1'].includes(service.value.has_image);
    const imageUrl = firstString(service.value.image_url);

    return hasRealImage && imageUrl ? imageUrl : '';
});
const showServiceImage = computed(() => Boolean(serviceImageUrl.value) && !serviceImageFailed.value);

watch(serviceImageUrl, () => {
    serviceImageFailed.value = false;
});

const clientAvatar = computed(() => firstString(client.value.avatar_url, client.value.logo_url));
const teamMemberAvatar = computed(() =>
    firstString(
        teamMember.value.avatar_url,
        teamMember.value.user?.avatar_url,
        teamMember.value.user?.profile_picture_url,
    ),
);
const clientAvatarShape = computed(() => {
    const kind = firstString(
        client.value.type,
        client.value.customer_type,
        client.value.client_type,
    ).toLowerCase();

    return ['company', 'organization', 'business'].some((value) => kind.includes(value))
        ? 'rounded'
        : 'circle';
});

const clientEmail = computed(() =>
    firstString(
        client.value.email,
        client.value.contact_email,
        props.reservation?.client_email,
    ),
);
const clientPhone = computed(() =>
    firstString(
        client.value.phone,
        client.value.contact_phone,
        props.reservation?.client_phone,
    ),
);

const resolvedTimezone = computed(
    () => firstString(props.reservation?.timezone, props.timezone) || 'UTC',
);
const startAt = computed(() => firstString(props.reservation?.starts_at, props.reservation?.start));
const endAt = computed(() => firstString(props.reservation?.ends_at, props.reservation?.end));

const formatDatePart = (value, options) => {
    const date = new Date(value);

    if (!value || Number.isNaN(date.getTime())) {
        return translate('unavailable', 'Unavailable');
    }

    const formatterOptions = { ...options, timeZone: resolvedTimezone.value };

    try {
        return new Intl.DateTimeFormat(locale.value || 'fr-CA', formatterOptions).format(date);
    } catch {
        return new Intl.DateTimeFormat(locale.value || 'fr-CA', {
            ...options,
            timeZone: 'UTC',
        }).format(date);
    }
};

const formattedDate = computed(() =>
    formatDatePart(startAt.value, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }),
);
const formattedTimeRange = computed(() => {
    const start = formatDatePart(startAt.value, { hour: '2-digit', minute: '2-digit' });

    if (!endAt.value) {
        return start;
    }

    return `${start} – ${formatDatePart(endAt.value, { hour: '2-digit', minute: '2-digit' })}`;
});

const durationMinutes = computed(() => {
    const explicitDuration = Number(
        props.reservation?.duration_minutes ?? props.reservation?.duration ?? NaN,
    );

    if (Number.isFinite(explicitDuration) && explicitDuration >= 0) {
        return Math.round(explicitDuration);
    }

    const start = new Date(startAt.value).getTime();
    const end = new Date(endAt.value).getTime();

    return Number.isFinite(start) && Number.isFinite(end) && end >= start
        ? Math.round((end - start) / 60000)
        : null;
});
const durationLabel = computed(() =>
    durationMinutes.value === null
        ? translate('unavailable', 'Unavailable')
        : translate('minutes', '{count} min', { count: durationMinutes.value }),
);

const serviceDescription = computed(() => firstString(service.value.description));
const serviceCategory = computed(() =>
    firstString(service.value.category?.name, service.value.category_name),
);
const cataloguePrice = computed(() =>
    service.value.catalogue_price ?? service.value.catalog_price ?? service.value.price ?? null,
);
const hasCataloguePrice = computed(
    () =>
        cataloguePrice.value !== null &&
        cataloguePrice.value !== '' &&
        Number.isFinite(Number(cataloguePrice.value)),
);
const payment = computed(() => props.reservation?.payment || {});
const currencyCode = computed(
    () =>
        firstString(
            service.value.currency_code,
            payment.value.currency_code,
            payment.value.policy?.currency_code,
        ) || 'CAD',
);
const formattedCataloguePrice = computed(() =>
    hasCataloguePrice.value
        ? formatCurrencyAmount(cataloguePrice.value, currencyCode.value)
        : translate('unavailable', 'Unavailable'),
);

const paymentStateLabel = (state) => {
    const normalized = String(state || '').trim().toLowerCase();

    if (!normalized) {
        return translate('unavailable', 'Unavailable');
    }

    return translate(`payment_states.${normalized}`, humanize(normalized));
};

const paymentRows = computed(() => {
    const policy = payment.value.policy || {};
    const state = payment.value.state || {};
    const rows = [];
    const depositAmount = policy.deposit_amount ?? state.deposit_due_amount;
    const hasDeposit =
        Boolean(policy.deposit_required) ||
        Number(depositAmount || 0) > 0 ||
        Boolean(state.deposit_status);

    if (hasDeposit) {
        rows.push({
            id: 'deposit',
            label: translate('deposit', 'Deposit'),
            amount: formatCurrencyAmount(depositAmount || 0, currencyCode.value),
            state: paymentStateLabel(state.deposit_status),
        });
    }

    const noShowAmount = state.no_show_fee_amount ?? policy.no_show_fee_amount;
    const hasNoShowFee =
        Boolean(policy.no_show_fee_enabled) ||
        Number(noShowAmount || 0) > 0 ||
        Boolean(state.no_show_fee_status);

    if (hasNoShowFee) {
        rows.push({
            id: 'no-show-fee',
            label: translate('no_show_fee', 'No-show fee'),
            amount: formatCurrencyAmount(noShowAmount || 0, currencyCode.value),
            state: paymentStateLabel(state.no_show_fee_status),
        });
    }

    return rows;
});

const clientNotes = computed(() =>
    firstString(props.reservation?.client_notes, props.reservation?.notes),
);
const internalNotes = computed(() => firstString(props.reservation?.internal_notes));

const resources = computed(() => {
    const directResources = Array.isArray(props.reservation?.resources)
        ? props.reservation.resources
        : null;
    const source =
        directResources ||
        props.reservation?.resource_allocations ||
        props.reservation?.resourceAllocations ||
        [];

    return source
        .map((item, index) => {
            const resource = item?.resource || item || {};

            return {
                id: item?.id || resource.id || `resource-${index}`,
                name: firstString(resource.name, resource.title, item?.resource_name),
                type: firstString(resource.type, resource.category, item?.resource_type),
                quantity: Number(item?.quantity ?? resource.quantity ?? 1),
            };
        })
        .filter((item) => item.name);
});

const actorName = (actor) => (actor ? entityName(actor, '') : '');
const joinDetails = (...details) => details.filter(Boolean).join(' · ');
const sourceLabel = computed(() => {
    const rawSource = firstString(
        props.reservation?.source,
        props.reservation?.booking_source,
    ).toLowerCase();
    const normalized = {
        internal: 'staff',
        employee: 'staff',
        public: 'public_booking',
        online: 'public_booking',
    }[rawSource] || rawSource;
    const knownSource = ['staff', 'client', 'api', 'public_booking'].includes(normalized)
        ? normalized
        : 'unknown';

    return translate(`sources.${knownSource}`, humanize(normalized || 'unknown'));
});

const formatDateTime = (value) =>
    formatDatePart(value, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

const landmarks = computed(() => {
    const items = [];
    const reservation = props.reservation || {};

    if (reservation.created_at) {
        const creator = actorName(reservation.created_by || reservation.creator);

        items.push({
            id: 'created',
            icon: History,
            iconClass: 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
            label: translate('created_at', 'Created'),
            datetime: reservation.created_at,
            value: formatDateTime(reservation.created_at),
            detail: joinDetails(
                creator
                    ? translate('created_by', 'Created by {name}', { name: creator })
                    : '',
                translate('source', 'Source: {source}', { source: sourceLabel.value }),
            ),
        });
    }

    if (startAt.value) {
        items.push({
            id: 'scheduled',
            icon: CalendarDays,
            iconClass:
                'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
            label: translate('scheduled_for', 'Scheduled for'),
            datetime: startAt.value,
            value: formatDateTime(startAt.value),
            detail: resolvedTimezone.value,
        });
    }

    if (reservation.cancelled_at) {
        const canceller = actorName(reservation.cancelled_by || reservation.canceller);

        items.push({
            id: 'cancelled',
            icon: AlertTriangle,
            iconClass: 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
            label: translate('cancelled_at', 'Cancelled'),
            datetime: reservation.cancelled_at,
            value: formatDateTime(reservation.cancelled_at),
            detail: joinDetails(
                canceller
                    ? translate('cancelled_by', 'Cancelled by {name}', { name: canceller })
                    : '',
                firstString(reservation.cancel_reason, reservation.cancellation_reason)
                    ? translate('cancellation_reason', 'Reason: {reason}', {
                          reason: firstString(
                              reservation.cancel_reason,
                              reservation.cancellation_reason,
                          ),
                      })
                    : '',
            ),
        });
    }

    if (reservation.auto_closed_at) {
        items.push({
            id: 'auto-closed',
            icon: Sparkles,
            iconClass:
                'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
            label: translate('auto_closed_at', 'Automatically closed'),
            datetime: reservation.auto_closed_at,
            value: formatDateTime(reservation.auto_closed_at),
            detail: reservation.auto_closed_reason
                ? translate('auto_closed_reason', 'Reason: {reason}', {
                      reason: reservation.auto_closed_reason,
                  })
                : '',
        });
    }

    return items;
});

const panelDescription = computed(() =>
    translate('description', '{service} for {customer} on {date}.', {
        service: serviceName.value,
        customer: clientName.value,
        date: formattedDate.value,
    }),
);
</script>

<template>
    <article
        data-reservation-details-panel
        class="flex h-dvh min-h-0 flex-col bg-stone-50 text-stone-900 dark:bg-neutral-950 dark:text-neutral-100"
        aria-labelledby="reservation-details-title"
        aria-describedby="reservation-details-subtitle"
    >
            <header
                class="relative shrink-0 overflow-hidden border-b border-emerald-100 bg-emerald-50 px-5 py-5 dark:border-neutral-800 dark:bg-neutral-900 sm:px-7 sm:py-6"
            >
                <div class="relative flex min-w-0 items-start gap-4 pr-12">
                    <div
                        class="relative flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-emerald-700 bg-emerald-600 text-white shadow-lg shadow-emerald-900/10 ring-1 ring-stone-900/5 dark:border-emerald-600 dark:bg-emerald-700 dark:shadow-black/30 sm:h-24 sm:w-24"
                    >
                        <img
                            v-if="showServiceImage"
                            :src="serviceImageUrl"
                            :alt="
                                translate('media.service_alt', 'Image for {service}', {
                                    service: serviceName,
                                })
                            "
                            class="h-full w-full object-cover"
                            loading="eager"
                            decoding="async"
                            @error="serviceImageFailed = true"
                        />
                        <Sparkles v-else aria-hidden="true" class="h-8 w-8 sm:h-10 sm:w-10" />
                    </div>

                    <div class="min-w-0 flex-1 pt-0.5">
                        <p
                            class="text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-emerald-700 dark:text-emerald-300"
                        >
                            {{ translate('eyebrow', 'Reservation') }}
                        </p>
                        <h1
                            id="reservation-details-title"
                            class="mt-1 text-xl font-semibold leading-tight text-stone-950 dark:text-white sm:text-2xl"
                        >
                            {{ translate('title', 'Reservation details') }}
                        </h1>
                        <p
                            id="reservation-details-subtitle"
                            class="mt-1.5 break-words text-sm leading-5 text-stone-600 dark:text-neutral-300"
                        >
                            {{ panelDescription }}
                        </p>
                        <span id="reservation-details-description" class="sr-only">
                            {{ panelDescription }}
                        </span>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <ReservationStatusBadge
                                v-if="reservation?.status"
                                :status="reservation.status"
                            />
                            <span class="rounded-full border border-stone-200/90 bg-white/80 px-2.5 py-1 text-[0.6875rem] font-semibold text-stone-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900/80 dark:text-neutral-300">
                                {{ translate('reference', 'Reservation #{id}', { id: reservation?.id || '–' }) }}
                            </span>
                            <span class="rounded-full border border-stone-200/90 bg-white/80 px-2.5 py-1 text-[0.6875rem] font-medium text-stone-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900/80 dark:text-neutral-300">
                                {{ sourceLabel }}
                            </span>
                            <span
                                v-if="partySize"
                                class="inline-flex items-center gap-1 rounded-full border border-stone-200/90 bg-white/80 px-2.5 py-1 text-[0.6875rem] font-medium text-stone-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900/80 dark:text-neutral-300"
                            >
                                <UsersRound aria-hidden="true" class="h-3 w-3" />
                                {{ translate('party_size_value', '{count} people', { count: partySize }) }}
                            </span>
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    class="absolute right-3 top-3 inline-flex h-11 w-11 items-center justify-center rounded-full text-stone-500 transition hover:bg-white/80 hover:text-stone-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-50 motion-reduce:transition-none dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-white dark:focus-visible:ring-offset-neutral-900 sm:right-5 sm:top-5"
                    :disabled="busy"
                    :aria-label="
                        translate('reservations.details.close', 'Close reservation details')
                    "
                    @click="!busy && emit('close')"
                >
                    <X aria-hidden="true" class="h-5 w-5" />
                </button>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain" aria-live="polite">
                <div
                    v-if="loading"
                    class="flex min-h-full flex-col items-center justify-center px-6 py-16 text-center"
                    role="status"
                >
                    <RefreshCw
                        aria-hidden="true"
                        class="h-8 w-8 animate-spin text-emerald-600 motion-reduce:animate-none dark:text-emerald-400"
                    />
                    <p class="mt-4 text-sm font-medium text-stone-700 dark:text-neutral-200">
                        {{ translate('loading', 'Loading reservation details…') }}
                    </p>
                    <div
                        aria-hidden="true"
                        class="mt-8 grid w-full max-w-lg animate-pulse gap-3 motion-reduce:animate-none"
                    >
                        <div class="h-24 rounded-2xl bg-stone-200 dark:bg-neutral-800" />
                        <div class="grid grid-cols-2 gap-3">
                            <div class="h-32 rounded-2xl bg-stone-200 dark:bg-neutral-800" />
                            <div class="h-32 rounded-2xl bg-stone-200 dark:bg-neutral-800" />
                        </div>
                    </div>
                </div>

                <div
                    v-else-if="error"
                    class="flex min-h-full flex-col items-center justify-center px-6 py-16 text-center"
                    role="alert"
                >
                    <span
                        class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300"
                    >
                        <AlertTriangle aria-hidden="true" class="h-7 w-7" />
                    </span>
                    <h2 class="mt-5 text-lg font-semibold text-stone-950 dark:text-white">
                        {{ translate('error_title', 'Unable to load this reservation') }}
                    </h2>
                    <p class="mt-2 max-w-md break-words text-sm leading-6 text-stone-600 dark:text-neutral-300">
                        {{
                            error ||
                            translate(
                                'reservations.details.error_description',
                                'Please try again.',
                            )
                        }}
                    </p>
                    <button
                        type="button"
                        class="mt-6 inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 motion-reduce:transition-none dark:focus-visible:ring-offset-neutral-950"
                        @click="emit('retry')"
                    >
                        <RefreshCw aria-hidden="true" class="h-4 w-4" />
                        {{ translate('reservations.details.retry', 'Try again') }}
                    </button>
                </div>

                <div v-else-if="reservation" class="space-y-6 px-5 py-6 sm:px-7 sm:py-7">
                    <section aria-labelledby="reservation-schedule-title">
                        <div class="mb-3 flex items-center gap-2">
                            <CalendarDays aria-hidden="true" class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                            <h2
                                id="reservation-schedule-title"
                                class="text-sm font-semibold text-stone-950 dark:text-white"
                            >
                                {{ translate('schedule', 'Schedule') }}
                            </h2>
                        </div>
                        <dl
                            class="grid overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900 sm:grid-cols-3"
                        >
                            <div class="min-w-0 border-b border-stone-100 p-4 dark:border-neutral-800 sm:border-b-0 sm:border-r">
                                <dt class="text-xs font-medium text-stone-500 dark:text-neutral-400">
                                    {{ translate('date', 'Date') }}
                                </dt>
                                <dd class="mt-1 break-words text-sm font-semibold capitalize text-stone-900 dark:text-white">
                                    <time :datetime="startAt || undefined">{{ formattedDate }}</time>
                                </dd>
                            </div>
                            <div class="min-w-0 border-b border-stone-100 p-4 dark:border-neutral-800 sm:border-b-0 sm:border-r">
                                <dt class="flex items-center gap-1.5 text-xs font-medium text-stone-500 dark:text-neutral-400">
                                    <Clock3 aria-hidden="true" class="h-3.5 w-3.5" />
                                    {{ translate('time', 'Time') }}
                                </dt>
                                <dd class="mt-1 break-words text-sm font-semibold text-stone-900 dark:text-white">
                                    {{ formattedTimeRange }}
                                </dd>
                            </div>
                            <div class="min-w-0 p-4">
                                <dt class="text-xs font-medium text-stone-500 dark:text-neutral-400">
                                    {{ translate('duration', 'Duration') }}
                                </dt>
                                <dd class="mt-1 break-words text-sm font-semibold text-stone-900 dark:text-white">
                                    {{ durationLabel }}
                                </dd>
                                <dd class="mt-1 break-all text-[0.6875rem] text-stone-500 dark:text-neutral-400">
                                    {{ resolvedTimezone }}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section
                        class="grid gap-3 sm:grid-cols-2"
                        :aria-label="translate('people', 'People')"
                    >
                        <article class="min-w-0 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <div class="flex min-w-0 items-center gap-3">
                                <EntityAvatar
                                    :src="clientAvatar"
                                    :name="clientName"
                                    :shape="clientAvatarShape"
                                    size="lg"
                                />
                                <div class="min-w-0">
                                    <p class="text-xs font-medium text-stone-500 dark:text-neutral-400">
                                        {{ translate('customer', 'Customer') }}
                                    </p>
                                    <h2 class="truncate text-sm font-semibold text-stone-950 dark:text-white">
                                        {{ clientName }}
                                    </h2>
                                    <span
                                        v-if="isVipClient"
                                        class="mt-1 inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[0.6875rem] font-semibold text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"
                                    >
                                        <Crown aria-hidden="true" class="h-3 w-3" />
                                        {{ translate('vip', 'VIP client') }}
                                    </span>
                                </div>
                            </div>
                            <div v-if="clientEmail || clientPhone" class="mt-4 space-y-2 border-t border-stone-100 pt-3 dark:border-neutral-800">
                                <a
                                    v-if="clientEmail"
                                    :href="`mailto:${clientEmail}`"
                                    class="flex min-h-7 min-w-0 items-center gap-2 text-xs text-stone-600 hover:text-emerald-700 focus:outline-none focus-visible:rounded focus-visible:ring-2 focus-visible:ring-emerald-500 dark:text-neutral-300 dark:hover:text-emerald-300"
                                >
                                    <Mail aria-hidden="true" class="h-3.5 w-3.5 shrink-0" />
                                    <span class="min-w-0 break-all">{{ clientEmail }}</span>
                                </a>
                                <a
                                    v-if="clientPhone"
                                    :href="`tel:${clientPhone}`"
                                    class="flex min-h-7 min-w-0 items-center gap-2 text-xs text-stone-600 hover:text-emerald-700 focus:outline-none focus-visible:rounded focus-visible:ring-2 focus-visible:ring-emerald-500 dark:text-neutral-300 dark:hover:text-emerald-300"
                                >
                                    <Phone aria-hidden="true" class="h-3.5 w-3.5 shrink-0" />
                                    <span class="min-w-0 break-all">{{ clientPhone }}</span>
                                </a>
                            </div>
                            <p v-else class="mt-4 border-t border-stone-100 pt-3 text-xs text-stone-500 dark:border-neutral-800 dark:text-neutral-400">
                                {{ translate('no_contact', 'No contact details available.') }}
                            </p>
                        </article>

                        <article class="min-w-0 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <div class="flex min-w-0 items-center gap-3">
                                <EntityAvatar
                                    :src="teamMemberAvatar"
                                    :name="teamMemberName"
                                    size="lg"
                                />
                                <div class="min-w-0">
                                    <p class="text-xs font-medium text-stone-500 dark:text-neutral-400">
                                        {{ translate('team_member', 'Team member') }}
                                    </p>
                                    <h2 class="truncate text-sm font-semibold text-stone-950 dark:text-white">
                                        {{ teamMemberName }}
                                    </h2>
                                    <p v-if="teamMemberTitle" class="mt-0.5 truncate text-xs text-stone-500 dark:text-neutral-400">
                                        {{ teamMemberTitle }}
                                    </p>
                                </div>
                            </div>
                            <div class="mt-4 flex items-center gap-2 border-t border-stone-100 pt-3 text-xs text-stone-500 dark:border-neutral-800 dark:text-neutral-400">
                                <UsersRound aria-hidden="true" class="h-3.5 w-3.5" />
                                {{ translate('assigned_team_member', 'Assigned team member') }}
                            </div>
                        </article>
                    </section>

                    <section aria-labelledby="reservation-service-title">
                        <div class="mb-3 flex items-center gap-2">
                            <PackageOpen aria-hidden="true" class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                            <h2
                                id="reservation-service-title"
                                class="text-sm font-semibold text-stone-950 dark:text-white"
                            >
                                {{ translate('service', 'Service') }}
                            </h2>
                        </div>
                        <div class="flex min-w-0 items-start justify-between gap-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <div class="min-w-0">
                                <p class="break-words text-sm font-semibold text-stone-950 dark:text-white">
                                    {{ serviceName }}
                                </p>
                                <span
                                    v-if="serviceCategory"
                                    class="mt-1.5 inline-flex rounded-full bg-stone-100 px-2 py-0.5 text-[0.6875rem] font-medium text-stone-600 dark:bg-neutral-800 dark:text-neutral-300"
                                >
                                    {{ serviceCategory }}
                                </span>
                                <p
                                    v-if="serviceDescription"
                                    class="mt-1 whitespace-pre-line break-words text-xs leading-5 text-stone-600 dark:text-neutral-300"
                                >
                                    {{ serviceDescription }}
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-[0.6875rem] font-medium text-stone-500 dark:text-neutral-400">
                                    {{ translate('catalogue_price', 'Catalog price') }}
                                </p>
                                <p class="mt-0.5 text-sm font-semibold text-stone-950 dark:text-white">
                                    {{ formattedCataloguePrice }}
                                </p>
                            </div>
                        </div>
                    </section>

                    <section v-if="paymentRows.length" aria-labelledby="reservation-payment-title">
                        <div class="mb-3 flex items-center gap-2">
                            <Coins aria-hidden="true" class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                            <h2
                                id="reservation-payment-title"
                                class="text-sm font-semibold text-stone-950 dark:text-white"
                            >
                                {{ translate('payment', 'Payment policy') }}
                            </h2>
                        </div>
                        <dl class="divide-y divide-stone-100 overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
                            <div
                                v-for="row in paymentRows"
                                :key="row.id"
                                class="flex min-w-0 items-center justify-between gap-4 p-4"
                            >
                                <div class="min-w-0">
                                    <dt class="text-xs font-medium text-stone-500 dark:text-neutral-400">
                                        {{ row.label }}
                                    </dt>
                                    <dd class="mt-0.5 break-words text-xs text-stone-700 dark:text-neutral-200">
                                        {{ row.state }}
                                    </dd>
                                </div>
                                <dd class="shrink-0 text-sm font-semibold text-stone-950 dark:text-white">
                                    {{ row.amount }}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section aria-labelledby="reservation-notes-title">
                        <div class="mb-3 flex items-center gap-2">
                            <FileText aria-hidden="true" class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                            <h2
                                id="reservation-notes-title"
                                class="text-sm font-semibold text-stone-950 dark:text-white"
                            >
                                {{ translate('notes', 'Notes') }}
                            </h2>
                        </div>
                        <div v-if="clientNotes || internalNotes" class="grid gap-3 sm:grid-cols-2">
                            <article
                                v-if="clientNotes"
                                class="min-w-0 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                            >
                                <h3 class="flex items-center gap-2 text-xs font-semibold text-stone-700 dark:text-neutral-200">
                                    <UserRound aria-hidden="true" class="h-3.5 w-3.5" />
                                    {{ translate('client_notes', 'Customer notes') }}
                                </h3>
                                <p class="mt-2 whitespace-pre-line break-words text-sm leading-6 text-stone-600 dark:text-neutral-300">
                                    {{ clientNotes }}
                                </p>
                            </article>
                            <article
                                v-if="internalNotes"
                                class="min-w-0 rounded-2xl border border-amber-200 bg-amber-50/80 p-4 shadow-sm dark:border-amber-800/50 dark:bg-amber-500/5"
                            >
                                <h3 class="flex items-center gap-2 text-xs font-semibold text-amber-800 dark:text-amber-300">
                                    <Sparkles aria-hidden="true" class="h-3.5 w-3.5" />
                                    {{ translate('internal_notes', 'Internal notes') }}
                                </h3>
                                <p class="mt-2 whitespace-pre-line break-words text-sm leading-6 text-amber-950/80 dark:text-amber-100/80">
                                    {{ internalNotes }}
                                </p>
                            </article>
                        </div>
                        <p
                            v-else
                            class="rounded-2xl border border-dashed border-stone-300 bg-white/60 px-4 py-5 text-center text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900/60 dark:text-neutral-400"
                        >
                            {{ translate('no_notes', 'No notes for this reservation.') }}
                        </p>
                    </section>

                    <section v-if="resources.length" aria-labelledby="reservation-resources-title">
                        <div class="mb-3 flex items-center gap-2">
                            <PackageOpen aria-hidden="true" class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                            <h2
                                id="reservation-resources-title"
                                class="text-sm font-semibold text-stone-950 dark:text-white"
                            >
                                {{ translate('resources', 'Resources') }}
                            </h2>
                        </div>
                        <ul class="grid gap-2 sm:grid-cols-2">
                            <li
                                v-for="resource in resources"
                                :key="resource.id"
                                class="flex min-w-0 items-center gap-3 rounded-xl border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                            >
                                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-stone-100 text-stone-600 dark:bg-neutral-800 dark:text-neutral-300">
                                    <PackageOpen aria-hidden="true" class="h-4 w-4" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-stone-900 dark:text-white">
                                        {{ resource.name }}
                                    </p>
                                    <p v-if="resource.type" class="truncate text-xs text-stone-500 dark:text-neutral-400">
                                        {{ resource.type }}
                                    </p>
                                </div>
                                <span
                                    v-if="resource.quantity > 1"
                                    class="shrink-0 rounded-full bg-stone-100 px-2 py-0.5 text-[0.6875rem] font-semibold text-stone-600 dark:bg-neutral-800 dark:text-neutral-300"
                                >
                                    {{
                                        translate('resource_quantity', '×{count}', {
                                            count: resource.quantity,
                                        })
                                    }}
                                </span>
                            </li>
                        </ul>
                    </section>

                    <section v-if="landmarks.length" aria-labelledby="reservation-landmarks-title">
                        <div class="mb-3 flex items-center gap-2">
                            <History aria-hidden="true" class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                            <h2
                                id="reservation-landmarks-title"
                                class="text-sm font-semibold text-stone-950 dark:text-white"
                            >
                                {{ translate('landmarks', 'Reservation landmarks') }}
                            </h2>
                        </div>
                        <ul class="grid gap-2 sm:grid-cols-2">
                            <li
                                v-for="landmark in landmarks"
                                :key="landmark.id"
                                class="flex min-w-0 items-start gap-3 rounded-xl border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                            >
                                <span
                                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                                    :class="landmark.iconClass"
                                >
                                    <component :is="landmark.icon" aria-hidden="true" class="h-4 w-4" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-xs font-medium text-stone-500 dark:text-neutral-400">
                                        {{ landmark.label }}
                                    </p>
                                    <p class="mt-0.5 break-words text-sm font-semibold text-stone-900 dark:text-white">
                                        <time :datetime="landmark.datetime">{{ landmark.value }}</time>
                                    </p>
                                    <p
                                        v-if="landmark.detail"
                                        class="mt-1 whitespace-pre-line break-words text-xs leading-5 text-stone-500 dark:text-neutral-400"
                                    >
                                        {{ landmark.detail }}
                                    </p>
                                </div>
                            </li>
                        </ul>
                    </section>

                    <section v-if="hasSupplementary">
                        <slot name="supplementary" />
                    </section>
                </div>
            </div>

            <footer
                v-if="reservation && !loading && !error && hasActions"
                class="sticky bottom-0 z-10 shrink-0 border-t border-stone-200 bg-white/95 px-5 py-4 shadow-[0_-12px_32px_-24px_rgba(28,25,23,0.5)] backdrop-blur dark:border-neutral-800 dark:bg-neutral-900/95 sm:px-7"
            >
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <slot name="actions" />
                </div>
            </footer>
    </article>
</template>
