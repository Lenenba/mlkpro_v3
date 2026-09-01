<script setup>
import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import CustomerQuickForm from '@/Components/QuickCreate/CustomerQuickForm.vue';
import InputError from '@/Components/InputError.vue';

const { locale, t } = useI18n();

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
    clients: {
        type: Array,
        default: () => [],
    },
    canCreate: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    mode: {
        type: String,
        default: 'existing',
        validator: (value) => ['existing', 'new'].includes(value),
    },
    timezone: {
        type: String,
        default: 'UTC',
    },
});

const emit = defineEmits(['update:modelValue', 'update:mode', 'created', 'processing', 'rebook']);

const searchQuery = ref('');
const isCreatingClient = ref(false);
const rebookingLoading = ref(false);
const rebookingError = ref('');
const rebookingInsights = ref({
    recent_reservations: [],
    frequent_services: [],
});
const rebookingSection = ref(null);
const rebookingCache = new Map();
let rebookingAbortController = null;
let rebookingRequestSequence = 0;
const mode = computed(() => props.mode);

const normalizedClients = computed(() => (Array.isArray(props.clients) ? props.clients : []));
const selectedClient = computed(() => normalizedClients.value.find(
    (client) => String(client.id) === String(props.modelValue || '')
) || null);

const displayClient = (client) => client?.company_name
    || `${client?.first_name || ''} ${client?.last_name || ''}`.trim()
    || `#${client?.id}`;

const clientInitials = (client) => {
    const source = client?.company_name
        || [client?.first_name, client?.last_name].filter(Boolean).join(' ')
        || String(client?.id || '');

    return source
        .trim()
        .split(/\s+/u)
        .slice(0, 2)
        .map((part) => part.charAt(0).toLocaleUpperCase())
        .join('') || '—';
};

const filteredClients = computed(() => {
    const query = searchQuery.value.trim().toLocaleLowerCase();
    if (!query) {
        return normalizedClients.value;
    }

    return normalizedClients.value.filter((client) => [
        client.company_name,
        client.first_name,
        client.last_name,
        client.email,
        client.phone,
    ]
        .filter(Boolean)
        .join(' ')
        .toLocaleLowerCase()
        .includes(query));
});

const recentReservations = computed(() => (
    Array.isArray(rebookingInsights.value?.recent_reservations)
        ? rebookingInsights.value.recent_reservations.slice(0, 3)
        : []
));

const frequentServices = computed(() => (
    Array.isArray(rebookingInsights.value?.frequent_services)
        ? rebookingInsights.value.frequent_services.slice(0, 3)
        : []
));

const hasRebookingInsights = computed(() => (
    recentReservations.value.length > 0 || frequentServices.value.length > 0
));

const normalizeRebookingPayload = (payload) => ({
    recent_reservations: Array.isArray(payload?.recent_reservations) ? payload.recent_reservations : [],
    frequent_services: Array.isArray(payload?.frequent_services) ? payload.frequent_services : [],
});

const formatRebookingDate = (value) => {
    if (!value) {
        return '';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const formatOptions = {
        dateStyle: 'medium',
        timeStyle: 'short',
    };

    try {
        return new Intl.DateTimeFormat(locale.value || 'fr', {
            ...formatOptions,
            timeZone: props.timezone,
        }).format(date);
    } catch {
        return new Intl.DateTimeFormat(locale.value || 'fr', {
            ...formatOptions,
            timeZone: 'UTC',
        }).format(date);
    }
};

const formatDuration = (value) => {
    const duration = Number(value || 0);

    return duration > 0
        ? t('reservations.form.rebooking.duration', { count: duration })
        : '';
};

const loadRebookingInsights = async (force = false) => {
    const customerId = Number(selectedClient.value?.id || 0);
    const requestSequence = ++rebookingRequestSequence;

    rebookingAbortController?.abort();
    rebookingAbortController = null;
    rebookingError.value = '';

    if (!customerId) {
        rebookingLoading.value = false;
        rebookingInsights.value = normalizeRebookingPayload();
        return;
    }

    if (!force && rebookingCache.has(customerId)) {
        rebookingLoading.value = false;
        rebookingInsights.value = rebookingCache.get(customerId);
        return;
    }

    rebookingLoading.value = true;
    const controller = new AbortController();
    rebookingAbortController = controller;

    try {
        const response = await axios.get(route('reservation.customer-rebooking', {
            customer: customerId,
        }), {
            signal: controller.signal,
        });
        if (requestSequence !== rebookingRequestSequence) {
            return;
        }

        const insights = normalizeRebookingPayload(response.data);
        rebookingCache.set(customerId, insights);
        rebookingInsights.value = insights;
    } catch (error) {
        if (requestSequence !== rebookingRequestSequence || axios.isCancel(error) || error?.code === 'ERR_CANCELED') {
            return;
        }

        rebookingInsights.value = normalizeRebookingPayload();
        rebookingError.value = t('reservations.form.rebooking.load_error');
    } finally {
        if (requestSequence === rebookingRequestSequence) {
            rebookingLoading.value = false;
            rebookingAbortController = null;
        }
    }
};

const selectRebookingTemplate = (template, source) => {
    emit('rebook', {
        ...template,
        source,
    });
};

const setMode = (nextMode, force = false) => {
    if (nextMode === 'new' && !props.canCreate) {
        return;
    }
    if (isCreatingClient.value && !force) {
        return;
    }

    searchQuery.value = '';
    emit('update:mode', nextMode);
};

const selectClient = async (clientId, event = null) => {
    emit('update:modelValue', clientId ? String(clientId) : '');

    if (!clientId || event?.detail !== 0) {
        return;
    }

    await nextTick();
    rebookingSection.value?.focus();
};

const handleCreated = (payload) => {
    emit('created', payload);
    setMode('existing', true);
};

const handleProcessing = (processing) => {
    isCreatingClient.value = Boolean(processing);
    emit('processing', isCreatingClient.value);
};

watch(
    () => [normalizedClients.value.length, props.canCreate],
    ([clientCount, canCreate]) => {
        if (!clientCount && canCreate && !props.modelValue) {
            setMode('new');
        }
    },
    { immediate: true }
);

watch(
    () => selectedClient.value?.id,
    () => loadRebookingInsights(),
    { immediate: true }
);

onBeforeUnmount(() => {
    rebookingRequestSequence += 1;
    rebookingAbortController?.abort();
});
</script>

<template>
    <div class="space-y-5" data-testid="reservation-customer-chooser">
        <div
            v-if="canCreate"
            class="grid grid-cols-1 gap-3 sm:grid-cols-2"
            role="group"
            :aria-label="$t('reservations.form.customer_choice')"
        >
            <button
                type="button"
                data-testid="reservation-customer-mode-existing"
                class="flex min-h-24 items-center gap-4 rounded-sm border px-5 py-4 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60 dark:focus-visible:ring-offset-neutral-900"
                :class="mode === 'existing'
                    ? 'border-emerald-500 bg-emerald-50/80 text-emerald-800 dark:border-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-300'
                    : 'border-stone-200 bg-white text-stone-700 hover:border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                :aria-pressed="mode === 'existing'"
                :disabled="isCreatingClient"
                @click="setMode('existing')"
            >
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-sm"
                    :class="mode === 'existing'
                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                        : 'bg-stone-100 text-stone-500 dark:bg-neutral-800 dark:text-neutral-400'"
                >
                    <svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
                    </svg>
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold">{{ $t('reservations.form.existing_customer') }}</span>
                    <span class="mt-1 block text-xs font-normal opacity-75">{{ $t('reservations.form.existing_customer_hint') }}</span>
                </span>
            </button>

            <button
                type="button"
                data-testid="reservation-customer-mode-new"
                class="flex min-h-24 items-center gap-4 rounded-sm border px-5 py-4 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60 dark:focus-visible:ring-offset-neutral-900"
                :class="mode === 'new'
                    ? 'border-emerald-500 bg-emerald-50/80 text-emerald-800 dark:border-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-300'
                    : 'border-stone-200 bg-white text-stone-700 hover:border-stone-300 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                :aria-pressed="mode === 'new'"
                :disabled="isCreatingClient"
                @click="setMode('new')"
            >
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-sm"
                    :class="mode === 'new'
                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                        : 'bg-stone-100 text-stone-500 dark:bg-neutral-800 dark:text-neutral-400'"
                >
                    <svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <path d="M19 8v6M22 11h-6" />
                    </svg>
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold">{{ $t('reservations.form.new_customer') }}</span>
                    <span class="mt-1 block text-xs font-normal opacity-75">{{ $t('reservations.form.new_customer_choice_hint') }}</span>
                </span>
            </button>
        </div>

        <div v-if="mode === 'existing'" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="min-w-0 space-y-3">
                <label for="reservation-customer-search" class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                    {{ $t('reservations.form.customer') }}
                </label>
                <div class="relative">
                    <svg aria-hidden="true" class="pointer-events-none absolute start-4 top-1/2 size-4 -translate-y-1/2 text-stone-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8" />
                        <path d="m21 21-4.3-4.3" />
                    </svg>
                    <input
                        id="reservation-customer-search"
                        v-model="searchQuery"
                        type="search"
                        data-testid="reservation-customer-search"
                        class="block min-h-11 w-full rounded-sm border border-stone-200 bg-white py-2 pe-3 ps-10 text-sm text-stone-800 placeholder:text-stone-400 focus:border-emerald-500 focus:ring-emerald-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                        :placeholder="$t('reservations.form.search_customer')"
                    />
                </div>

                <div class="max-h-56 overflow-y-auto rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 border-b border-stone-100 px-3 py-3 text-left transition dark:border-neutral-800"
                        :class="!modelValue
                            ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200'
                            : 'text-stone-700 hover:bg-stone-50 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                        :aria-pressed="!modelValue"
                        @click="selectClient('', $event)"
                    >
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-stone-100 text-stone-500 dark:bg-neutral-800 dark:text-neutral-400">
                            <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2" />
                                <circle cx="9.5" cy="7" r="4" />
                                <path d="m17 8 5 5M22 8l-5 5" />
                            </svg>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">{{ $t('reservations.form.no_customer') }}</span>
                            <span class="block truncate text-xs opacity-70">{{ $t('reservations.form.no_customer_hint') }}</span>
                        </span>
                        <svg v-if="!modelValue" aria-hidden="true" class="size-4 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="m20 6-11 11-5-5" />
                        </svg>
                    </button>

                    <button
                        v-for="client in filteredClients"
                        :key="client.id"
                        type="button"
                        class="flex w-full items-center gap-3 border-b border-stone-100 px-3 py-3 text-left transition last:border-b-0 dark:border-neutral-800"
                        :class="String(client.id) === String(modelValue)
                            ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200'
                            : 'text-stone-700 hover:bg-stone-50 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                        :aria-pressed="String(client.id) === String(modelValue)"
                        @click="selectClient(client.id, $event)"
                    >
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-stone-100 text-xs font-semibold text-stone-600 dark:bg-neutral-800 dark:text-neutral-300">
                            {{ clientInitials(client) }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">{{ displayClient(client) }}</span>
                            <span v-if="client.email || client.phone" class="mt-0.5 block truncate text-xs opacity-70">
                                {{ [client.email, client.phone].filter(Boolean).join(' · ') }}
                            </span>
                        </span>
                        <svg v-if="String(client.id) === String(modelValue)" aria-hidden="true" class="size-4 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="m20 6-11 11-5-5" />
                        </svg>
                    </button>

                    <p v-if="!filteredClients.length" class="px-4 py-6 text-center text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('reservations.form.no_customer_results') }}
                    </p>
                </div>
                <InputError :message="error" />
            </div>

            <div class="min-w-0 rounded-sm border border-stone-200 bg-stone-50/70 p-4 dark:border-neutral-700 dark:bg-neutral-800/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                    {{ $t('reservations.form.selected_customer') }}
                </p>
                <template v-if="selectedClient">
                    <div class="mt-4 flex items-center gap-3">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                            {{ clientInitials(selectedClient) }}
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                {{ displayClient(selectedClient) }}
                            </p>
                            <p v-if="selectedClient.email || selectedClient.phone" class="mt-1 truncate text-xs text-stone-500 dark:text-neutral-400">
                                {{ [selectedClient.email, selectedClient.phone].filter(Boolean).join(' · ') }}
                            </p>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('reservations.form.selected_customer_hint') }}
                            </p>
                        </div>
                    </div>

                    <section
                        ref="rebookingSection"
                        class="mt-4 border-t border-stone-200 pt-4 dark:border-neutral-700"
                        tabindex="-1"
                        aria-labelledby="reservation-rebooking-title"
                        data-testid="reservation-rebooking"
                    >
                        <h4 id="reservation-rebooking-title" class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ $t('reservations.form.rebooking.title') }}
                        </h4>

                        <div v-if="rebookingLoading" class="mt-3 space-y-2" role="status" :aria-label="$t('reservations.form.rebooking.loading')">
                            <span class="sr-only">{{ $t('reservations.form.rebooking.loading') }}</span>
                            <div v-for="index in 3" :key="index" class="animate-pulse rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                                <div class="h-3 w-2/3 rounded bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="mt-2 h-2 w-1/2 rounded bg-stone-100 dark:bg-neutral-800"></div>
                            </div>
                        </div>

                        <div
                            v-else-if="rebookingError"
                            class="mt-3 flex flex-col gap-3 rounded-sm border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300 sm:flex-row sm:items-center sm:justify-between"
                            role="alert"
                        >
                            <span>{{ rebookingError }}</span>
                            <button
                                type="button"
                                class="shrink-0 font-semibold underline underline-offset-2"
                                @click="loadRebookingInsights(true)"
                            >
                                {{ $t('reservations.form.rebooking.retry') }}
                            </button>
                        </div>

                        <div v-else-if="!hasRebookingInsights" class="mt-3 rounded-sm border border-dashed border-stone-300 bg-white px-4 py-5 text-center dark:border-neutral-700 dark:bg-neutral-900">
                            <p class="text-sm font-medium text-stone-700 dark:text-neutral-200">
                                {{ $t('reservations.form.rebooking.empty') }}
                            </p>
                            <p class="mt-1 text-xs leading-5 text-stone-500 dark:text-neutral-400">
                                {{ $t('reservations.form.rebooking.empty_hint') }}
                            </p>
                        </div>

                        <div v-else class="mt-4 max-h-80 space-y-5 overflow-y-auto pe-1">
                            <div v-if="recentReservations.length" class="space-y-2">
                                <h5 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('reservations.form.rebooking.recent_reservations') }}
                                </h5>
                                <article
                                    v-for="reservation in recentReservations"
                                    :key="`reservation-${reservation.id}`"
                                    class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    <div class="flex items-start gap-3">
                                        <span class="flex size-9 shrink-0 items-center justify-center rounded-sm bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                                            <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M8 2v4M16 2v4M3 10h18" />
                                                <rect x="3" y="4" width="18" height="18" rx="2" />
                                                <path d="m9 16 2 2 4-4" />
                                            </svg>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                                {{ reservation.service?.name || $t('reservations.form.rebooking.service_unavailable') }}
                                            </p>
                                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                {{ [formatRebookingDate(reservation.starts_at), formatDuration(reservation.duration_minutes)].filter(Boolean).join(' · ') }}
                                            </p>
                                            <p v-if="reservation.team_member?.name" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                {{ reservation.team_member.name }}
                                                <span v-if="!reservation.team_member.is_available" class="text-amber-700 dark:text-amber-300">
                                                    · {{ $t('reservations.form.rebooking.team_member_unavailable') }}
                                                </span>
                                            </p>
                                            <p v-if="reservation.service && !reservation.service.is_available" class="mt-1 text-xs font-medium text-amber-700 dark:text-amber-300">
                                                {{ $t('reservations.form.rebooking.choose_another_service') }}
                                            </p>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        class="mt-3 inline-flex min-h-9 w-full items-center justify-center gap-2 rounded-sm border border-emerald-600 px-3 py-1.5 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 dark:border-emerald-500 dark:text-emerald-300 dark:hover:bg-emerald-950/40"
                                        :aria-label="$t('reservations.form.rebooking.action_aria', { service: reservation.service?.name || $t('reservations.form.rebooking.service_unavailable') })"
                                        @click="selectRebookingTemplate(reservation, 'recent_reservation')"
                                    >
                                        {{ $t('reservations.form.rebooking.action') }}
                                        <svg aria-hidden="true" class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M5 12h14M13 6l6 6-6 6" />
                                        </svg>
                                    </button>
                                </article>
                            </div>

                            <div v-if="frequentServices.length" class="space-y-2">
                                <h5 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('reservations.form.rebooking.frequent_services') }}
                                </h5>
                                <article
                                    v-for="service in frequentServices"
                                    :key="`service-${service.service?.id || service.service?.name}`"
                                    class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    <div class="flex items-start gap-3">
                                        <span class="flex size-9 shrink-0 items-center justify-center rounded-sm bg-stone-100 text-stone-500 dark:bg-neutral-800 dark:text-neutral-300">
                                            <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M20 7h-9M14 17H5" />
                                                <circle cx="17" cy="17" r="3" />
                                                <circle cx="7" cy="7" r="3" />
                                            </svg>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                                {{ service.service?.name || $t('reservations.form.rebooking.service_unavailable') }}
                                            </p>
                                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                {{ $t('reservations.form.rebooking.reservation_count', { count: service.reservation_count }) }}
                                                <template v-if="service.duration_minutes"> · {{ formatDuration(service.duration_minutes) }}</template>
                                            </p>
                                            <p v-if="service.last_booked_at" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                {{ $t('reservations.form.rebooking.last_booked', { date: formatRebookingDate(service.last_booked_at) }) }}
                                            </p>
                                            <p v-if="service.team_member?.name" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                {{ service.team_member.name }}
                                                <span v-if="!service.team_member.is_available" class="text-amber-700 dark:text-amber-300">
                                                    · {{ $t('reservations.form.rebooking.team_member_unavailable') }}
                                                </span>
                                            </p>
                                            <p v-if="service.service && !service.service.is_available" class="mt-1 text-xs font-medium text-amber-700 dark:text-amber-300">
                                                {{ $t('reservations.form.rebooking.choose_another_service') }}
                                            </p>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        class="mt-3 inline-flex min-h-9 w-full items-center justify-center gap-2 rounded-sm border border-emerald-600 px-3 py-1.5 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 dark:border-emerald-500 dark:text-emerald-300 dark:hover:bg-emerald-950/40"
                                        :aria-label="$t('reservations.form.rebooking.action_aria', { service: service.service?.name || $t('reservations.form.rebooking.service_unavailable') })"
                                        @click="selectRebookingTemplate(service, 'frequent_service')"
                                    >
                                        {{ $t('reservations.form.rebooking.action') }}
                                        <svg aria-hidden="true" class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M5 12h14M13 6l6 6-6 6" />
                                        </svg>
                                    </button>
                                </article>
                            </div>
                        </div>
                    </section>
                </template>
                <div v-else class="mt-4 flex items-start gap-3 text-stone-600 dark:text-neutral-300">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-white text-stone-400 dark:bg-neutral-900 dark:text-neutral-500">
                        <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2" />
                            <circle cx="9.5" cy="7" r="4" />
                        </svg>
                    </span>
                    <p class="text-sm leading-5">{{ $t('reservations.form.no_customer_selected_hint') }}</p>
                </div>
            </div>
        </div>

        <div v-else class="rounded-sm border border-emerald-200 bg-emerald-50/40 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/30 sm:p-5">
            <div class="mb-5">
                <h3 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                    {{ $t('reservations.form.new_customer_title') }}
                </h3>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('reservations.form.new_customer_hint') }}
                </p>
            </div>
            <CustomerQuickForm
                compact
                :default-portal-access="false"
                :close-on-success="false"
                :submit-label="$t('reservations.form.create_customer')"
                @created="handleCreated"
                @cancel="setMode('existing')"
                @processing="handleProcessing"
            />
        </div>
    </div>
</template>
