<script setup>
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarCheck2,
    CircleDollarSign,
    FileText,
    History,
    LoaderCircle,
    MessageSquareText,
    RotateCcw,
    StickyNote,
    UserRoundPen,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCurrencyFormatter } from '@/utils/currency';
import {
    CUSTOMER_ACTIVITY_DEFAULT_PERIOD,
    CUSTOMER_ACTIVITY_PERIODS,
    customerActivityFilterCount,
    mergeCustomerActivityItems,
    normalizeCustomerActivityFilters,
    normalizeCustomerActivityPayload,
    serializeCustomerActivityFilters,
    toggleCustomerActivityType,
    validateCustomerActivityFilters,
} from '@/utils/customerActivity';

const props = defineProps({
    activity: {
        type: [Object, Array],
        default: null,
    },
    fallbackItems: {
        type: Array,
        default: () => [],
    },
    endpoint: {
        type: String,
        required: true,
    },
});

const { locale, t } = useI18n();
const { formatCurrency } = useCurrencyFormatter();

const initialPayload = normalizeCustomerActivityPayload(props.activity, props.fallbackItems);
const timeline = ref(initialPayload);
const filters = ref(normalizeCustomerActivityFilters(initialPayload.meta, initialPayload.meta.available_types));
const isLoading = ref(false);
const isLoadingMore = ref(false);
const requestError = ref('');
const loadMoreError = ref('');
const filterError = ref('');
let activeRequest = null;
let requestSequence = 0;

const translate = (key, fallback = '', params = {}) => {
    const translationKey = `customers.details.history.${key}`;
    const translated = t(translationKey, params);

    return translated === translationKey ? (fallback || key) : translated;
};

const periodOptions = computed(() => CUSTOMER_ACTIVITY_PERIODS.map((period) => ({
    value: period,
    label: translate(`periods.${period}`, period),
})));

const availableTypeOptions = computed(() => {
    const options = Array.isArray(timeline.value.meta.available_types)
        ? timeline.value.meta.available_types
        : [];
    const byValue = new Map(options.map((option) => [option.value, option]));

    for (const type of filters.value.types) {
        if (!byValue.has(type)) {
            byValue.set(type, { value: type, label: '' });
        }
    }

    return [...byValue.values()].map((option) => ({
        value: option.value,
        label: option.label || translate(`types.${option.value}`, option.value),
    }));
});

const authorizedTypes = computed(() => new Set(
    (timeline.value.meta.available_types || []).map((option) => option.value),
));

const visibleItems = computed(() => timeline.value.data.filter((item) => (
    !authorizedTypes.value.size || authorizedTypes.value.has(item.category)
)));

const activeFilterCount = computed(() => customerActivityFilterCount(filters.value));
const hasMore = computed(() => Boolean(timeline.value.meta.has_more));
const loadedCountLabel = computed(() => hasMore.value
    ? translate('loaded_count_more', '{count}+', { count: visibleItems.value.length })
    : translate('loaded_count', '{count}', { count: visibleItems.value.length }));

const formatWithTimezone = (value, options) => {
    if (!value) {
        return '';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const baseOptions = {
        ...options,
        timeZone: timeline.value.meta.timezone || 'UTC',
    };

    try {
        return new Intl.DateTimeFormat(locale.value || 'fr', baseOptions).format(date);
    } catch {
        return new Intl.DateTimeFormat(locale.value || 'fr', options).format(date);
    }
};

const dateGroupKey = (value) => formatWithTimezone(value, {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
});

const dateGroupLabel = (value) => formatWithTimezone(value, {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
});

const absoluteDate = (value) => formatWithTimezone(value, {
    dateStyle: 'long',
    timeStyle: 'short',
});

const shortDate = (value) => formatWithTimezone(value, {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

const groupedItems = computed(() => {
    const groups = [];

    for (const item of visibleItems.value) {
        const key = dateGroupKey(item.occurred_at) || 'unknown';
        let group = groups.at(-1);

        if (!group || group.key !== key) {
            group = {
                key,
                label: dateGroupLabel(item.occurred_at) || translate('unknown_date', 'Date inconnue'),
                items: [],
            };
            groups.push(group);
        }

        group.items.push(item);
    }

    return groups;
});

const iconByCategory = {
    appointments: CalendarCheck2,
    invoices: FileText,
    payments: CircleDollarSign,
    notes: StickyNote,
    communications: MessageSquareText,
    profile_changes: UserRoundPen,
};

const iconFor = (item) => iconByCategory[item.category] || History;

const iconClassFor = (item) => ({
    appointments: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300',
    invoices: 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-300',
    payments: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
    notes: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300',
    communications: 'border-cyan-200 bg-cyan-50 text-cyan-700 dark:border-cyan-500/20 dark:bg-cyan-500/10 dark:text-cyan-300',
    profile_changes: 'border-stone-200 bg-stone-100 text-stone-700 dark:border-neutral-600 dark:bg-neutral-700 dark:text-neutral-200',
}[item.category] || 'border-stone-200 bg-white text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300');

const statusClassFor = (status) => {
    const normalized = String(status || '').toLowerCase();

    if (['completed', 'confirmed', 'paid', 'succeeded', 'sent', 'active'].includes(normalized)) {
        return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
    }
    if (['cancelled', 'canceled', 'failed', 'refunded', 'reversed', 'no_show', 'inactive'].includes(normalized)) {
        return 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300';
    }
    if (['pending', 'processing', 'scheduled', 'rescheduled', 'partial'].includes(normalized)) {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
    }

    return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
};

const humanize = (value) => {
    const normalized = String(value || '').replace(/[_-]+/g, ' ').trim();
    return normalized ? normalized.charAt(0).toUpperCase() + normalized.slice(1) : '';
};

const typeLabel = (type) => translate(`types.${type}`, humanize(type));
const statusLabel = (status) => translate(`statuses.${status}`, humanize(status));
const actorLabel = (item) => item.actor?.name || translate('system_actor', 'Système');

const resourceHref = (item) => {
    const href = typeof item.resource?.href === 'string' ? item.resource.href.trim() : '';

    return /^\/(?!\/)/.test(href) ? href : null;
};

const amountLabel = (item) => item.amount
    ? formatCurrency(item.amount.value, item.amount.currency_code)
    : '';

const applyResponse = (value, append = false) => {
    const next = normalizeCustomerActivityPayload(value);

    timeline.value = append
        ? {
            ...next,
            data: mergeCustomerActivityItems(timeline.value.data, next.data),
        }
        : next;

    if (!append) {
        filters.value = normalizeCustomerActivityFilters(next.meta, next.meta.available_types);
    }
};

const requestTimeline = async ({ append = false } = {}) => {
    const validationError = validateCustomerActivityFilters(filters.value);

    if (validationError) {
        filterError.value = translate(`validation.${validationError}`, validationError);
        return;
    }

    filterError.value = '';
    requestError.value = '';
    loadMoreError.value = '';

    if (activeRequest) {
        activeRequest.abort();
    }

    const controller = new AbortController();
    const sequence = ++requestSequence;
    activeRequest = controller;

    if (append) {
        isLoadingMore.value = true;
    } else {
        isLoading.value = true;
    }

    try {
        const response = await axios.get(props.endpoint, {
            params: serializeCustomerActivityFilters(filters.value, {
                cursor: append ? timeline.value.meta.next_cursor : '',
                perPage: timeline.value.meta.per_page,
            }),
            headers: {
                Accept: 'application/json',
            },
            signal: controller.signal,
        });

        if (sequence === requestSequence) {
            applyResponse(response.data, append);
        }
    } catch (error) {
        if (error?.code !== 'ERR_CANCELED' && sequence === requestSequence) {
            const message = error?.response?.data?.message || translate('states.error_description', 'Impossible de charger l’historique.');

            if (append) {
                loadMoreError.value = message;
            } else {
                requestError.value = message;
            }
        }
    } finally {
        if (sequence === requestSequence) {
            isLoading.value = false;
            isLoadingMore.value = false;
            activeRequest = null;
        }
    }
};

const selectPeriod = (period) => {
    if (!CUSTOMER_ACTIVITY_PERIODS.includes(period) || period === filters.value.period) {
        return;
    }

    filters.value = {
        ...filters.value,
        period,
        from: period === 'custom' ? filters.value.from : '',
        to: period === 'custom' ? filters.value.to : '',
    };
    filterError.value = '';

    if (period !== 'custom') {
        requestTimeline();
    }
};

const toggleType = (type) => {
    filters.value = {
        ...filters.value,
        types: toggleCustomerActivityType(filters.value.types, type),
    };
    requestTimeline();
};

const removeType = (type) => {
    if (!filters.value.types.includes(type)) {
        return;
    }

    toggleType(type);
};

const clearPeriod = () => {
    filters.value = {
        ...filters.value,
        period: CUSTOMER_ACTIVITY_DEFAULT_PERIOD,
        from: '',
        to: '',
    };
    requestTimeline();
};

const resetFilters = () => {
    filters.value = normalizeCustomerActivityFilters({
        period: CUSTOMER_ACTIVITY_DEFAULT_PERIOD,
        types: [],
    });
    requestTimeline();
};

const refresh = () => requestTimeline();

watch(
    () => props.activity,
    (value) => {
        const next = normalizeCustomerActivityPayload(value, props.fallbackItems);
        timeline.value = next;
        filters.value = normalizeCustomerActivityFilters(next.meta, next.meta.available_types);
    },
);

onBeforeUnmount(() => {
    activeRequest?.abort();
});

defineExpose({ refresh });
</script>

<template>
    <section
        class="overflow-hidden rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900"
        :aria-busy="isLoading || isLoadingMore"
        aria-labelledby="customer-history-title"
    >
        <header class="border-b border-stone-200 px-4 py-4 dark:border-neutral-700 sm:px-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 id="customer-history-title" class="text-sm font-semibold text-stone-900 dark:text-white">
                            {{ translate('title', 'Historique unifié') }}
                        </h2>
                        <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-600 dark:bg-neutral-700 dark:text-neutral-300" aria-live="polite">
                            {{ loadedCountLabel }}
                        </span>
                    </div>
                    <p class="mt-1 max-w-2xl text-xs leading-5 text-stone-500 dark:text-neutral-400">
                        {{ translate('subtitle', 'Rendez-vous, facturation, communications et changements de profil dans une seule chronologie.') }}
                    </p>
                </div>
                <button
                    v-if="activeFilterCount"
                    type="button"
                    class="inline-flex min-h-11 items-center justify-center gap-2 self-start rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 transition hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    @click="resetFilters"
                >
                    <RotateCcw class="size-3.5" aria-hidden="true" />
                    {{ translate('reset', 'Réinitialiser') }}
                </button>
            </div>

            <div class="mt-4 space-y-4 rounded-sm border border-stone-200 bg-stone-50/70 p-3 dark:border-neutral-700 dark:bg-neutral-800/60">
                <fieldset>
                    <legend class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                        {{ translate('period_label', 'Période') }}
                    </legend>
                    <div class="mt-2 flex gap-2 overflow-x-auto pb-1" role="group" :aria-label="translate('period_label', 'Période')">
                        <button
                            v-for="period in periodOptions"
                            :key="period.value"
                            type="button"
                            class="inline-flex min-h-11 shrink-0 items-center rounded-full border px-3 py-2 text-xs font-semibold transition focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            :class="filters.period === period.value
                                ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-300'
                                : 'border-stone-200 bg-white text-stone-600 hover:border-stone-300 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:border-neutral-600'"
                            :aria-pressed="filters.period === period.value"
                            @click="selectPeriod(period.value)"
                        >
                            {{ period.label }}
                        </button>
                    </div>
                </fieldset>

                <div v-if="filters.period === 'custom'" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                    <label class="block">
                        <span class="text-xs font-medium text-stone-700 dark:text-neutral-200">{{ translate('from', 'Du') }}</span>
                        <input
                            v-model="filters.from"
                            type="date"
                            class="mt-1 block min-h-11 w-full rounded-sm border-stone-200 bg-white text-sm text-stone-800 focus:border-emerald-500 focus:ring-emerald-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                        />
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-stone-700 dark:text-neutral-200">{{ translate('to', 'Au') }}</span>
                        <input
                            v-model="filters.to"
                            type="date"
                            class="mt-1 block min-h-11 w-full rounded-sm border-stone-200 bg-white text-sm text-stone-800 focus:border-emerald-500 focus:ring-emerald-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                        />
                    </label>
                    <button
                        type="button"
                        class="inline-flex min-h-11 items-center justify-center rounded-sm bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="isLoading"
                        @click="requestTimeline()"
                    >
                        {{ translate('apply', 'Appliquer') }}
                    </button>
                </div>

                <fieldset v-if="availableTypeOptions.length">
                    <legend class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                        {{ translate('types_label', 'Types d’activité') }}
                    </legend>
                    <div class="mt-2 flex flex-wrap gap-2" role="group" :aria-label="translate('types_label', 'Types d’activité')">
                        <button
                            v-for="type in availableTypeOptions"
                            :key="type.value"
                            type="button"
                            class="inline-flex min-h-11 items-center rounded-full border px-3 py-2 text-xs font-semibold transition focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            :class="filters.types.includes(type.value)
                                ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-300'
                                : 'border-stone-200 bg-white text-stone-600 hover:border-stone-300 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:border-neutral-600'"
                            :aria-pressed="filters.types.includes(type.value)"
                            @click="toggleType(type.value)"
                        >
                            {{ type.label }}
                        </button>
                    </div>
                </fieldset>

                <div v-if="activeFilterCount" class="flex flex-wrap items-center gap-2" aria-live="polite">
                    <span class="text-xs font-medium text-stone-500 dark:text-neutral-400">
                        {{ translate('active_filters', 'Filtres actifs') }}
                    </span>
                    <button
                        v-if="filters.period !== CUSTOMER_ACTIVITY_DEFAULT_PERIOD"
                        type="button"
                        class="inline-flex min-h-9 items-center gap-1 rounded-full bg-stone-200 px-3 py-1.5 text-xs font-medium text-stone-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:bg-neutral-700 dark:text-neutral-200"
                        :aria-label="translate('remove_period', 'Retirer le filtre de période')"
                        @click="clearPeriod"
                    >
                        {{ translate(`periods.${filters.period}`, filters.period) }}
                        <X class="size-3" aria-hidden="true" />
                    </button>
                    <button
                        v-for="type in filters.types"
                        :key="`active-${type}`"
                        type="button"
                        class="inline-flex min-h-9 items-center gap-1 rounded-full bg-stone-200 px-3 py-1.5 text-xs font-medium text-stone-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:bg-neutral-700 dark:text-neutral-200"
                        :aria-label="translate('remove_type', 'Retirer le filtre {type}', { type: typeLabel(type) })"
                        @click="removeType(type)"
                    >
                        {{ typeLabel(type) }}
                        <X class="size-3" aria-hidden="true" />
                    </button>
                </div>

                <p v-if="filterError" class="text-sm text-rose-700 dark:text-rose-300" role="alert">
                    {{ filterError }}
                </p>
            </div>
        </header>

        <div class="relative min-h-48 px-4 py-5 sm:px-5">
            <div v-if="isLoading" class="space-y-4" role="status" aria-live="polite">
                <span class="sr-only">{{ translate('states.loading', 'Chargement de l’historique…') }}</span>
                <div v-for="index in 3" :key="`history-skeleton-${index}`" class="flex animate-pulse gap-3 motion-reduce:animate-none">
                    <div class="size-10 shrink-0 rounded-full bg-stone-200 dark:bg-neutral-700" />
                    <div class="flex-1 space-y-2 rounded-sm border border-stone-100 p-3 dark:border-neutral-800">
                        <div class="h-3 w-1/3 rounded bg-stone-200 dark:bg-neutral-700" />
                        <div class="h-4 w-2/3 rounded bg-stone-200 dark:bg-neutral-700" />
                        <div class="h-3 w-1/2 rounded bg-stone-200 dark:bg-neutral-700" />
                    </div>
                </div>
            </div>

            <div
                v-else-if="requestError"
                class="flex min-h-48 flex-col items-center justify-center rounded-sm border border-rose-200 bg-rose-50 px-4 py-8 text-center dark:border-rose-500/20 dark:bg-rose-500/10"
                role="alert"
            >
                <AlertTriangle class="size-7 text-rose-600 dark:text-rose-300" aria-hidden="true" />
                <h3 class="mt-3 text-sm font-semibold text-rose-800 dark:text-rose-200">
                    {{ translate('states.error_title', 'Historique indisponible') }}
                </h3>
                <p class="mt-1 max-w-md text-sm text-rose-700 dark:text-rose-300">{{ requestError }}</p>
                <button
                    type="button"
                    class="mt-4 inline-flex min-h-11 items-center justify-center rounded-sm border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-500 dark:border-rose-500/30 dark:bg-neutral-900 dark:text-rose-200"
                    @click="requestTimeline()"
                >
                    {{ translate('states.retry', 'Réessayer') }}
                </button>
            </div>

            <div
                v-else-if="!visibleItems.length"
                class="flex min-h-48 flex-col items-center justify-center rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-8 text-center dark:border-neutral-700 dark:bg-neutral-800/50"
            >
                <History class="size-7 text-stone-400 dark:text-neutral-500" aria-hidden="true" />
                <h3 class="mt-3 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ activeFilterCount ? translate('states.no_results_title', 'Aucune activité trouvée') : translate('states.empty_title', 'Aucune activité') }}
                </h3>
                <p class="mt-1 max-w-md text-sm text-stone-500 dark:text-neutral-400">
                    {{ activeFilterCount ? translate('states.no_results_description', 'Essayez une période plus large ou retirez un filtre.') : translate('states.empty_description', 'Les interactions de ce client apparaîtront ici.') }}
                </p>
                <button
                    v-if="activeFilterCount"
                    type="button"
                    class="mt-4 inline-flex min-h-11 items-center justify-center rounded-sm border border-stone-200 bg-white px-4 py-2 text-sm font-semibold text-stone-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                    @click="resetFilters"
                >
                    {{ translate('reset', 'Réinitialiser') }}
                </button>
            </div>

            <ol v-else class="space-y-6" :aria-label="translate('list_label', 'Chronologie des activités du client')" aria-live="polite">
                <li v-for="group in groupedItems" :key="group.key">
                    <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ group.label }}
                    </h3>
                    <ol class="relative space-y-4 before:absolute before:bottom-5 before:left-5 before:top-5 before:w-px before:bg-stone-200 dark:before:bg-neutral-700">
                        <li
                            v-for="item in group.items"
                            :key="item.id"
                            class="relative grid grid-cols-[2.5rem_minmax(0,1fr)] gap-3"
                        >
                            <span class="relative z-10 flex size-10 items-center justify-center rounded-full border" :class="iconClassFor(item)">
                                <component :is="iconFor(item)" class="size-4" aria-hidden="true" />
                            </span>
                            <article class="min-w-0 rounded-sm border border-stone-200 bg-stone-50/70 p-3 dark:border-neutral-700 dark:bg-neutral-800/70 sm:p-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-xs font-semibold text-stone-500 dark:text-neutral-400">
                                                {{ typeLabel(item.category) }}
                                            </span>
                                            <span
                                                v-if="item.status"
                                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                :class="statusClassFor(item.status)"
                                            >
                                                {{ statusLabel(item.status) }}
                                            </span>
                                        </div>
                                        <Link
                                            v-if="resourceHref(item)"
                                            :href="resourceHref(item)"
                                            class="mt-1 block break-words text-sm font-semibold text-stone-900 hover:text-emerald-700 hover:underline focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:text-white dark:hover:text-emerald-300"
                                        >
                                            {{ item.title }}
                                        </Link>
                                        <h4 v-else class="mt-1 break-words text-sm font-semibold text-stone-900 dark:text-white">
                                            {{ item.title }}
                                        </h4>
                                        <p v-if="item.description" class="mt-1 whitespace-pre-line break-words text-sm leading-5 text-stone-600 dark:text-neutral-300">
                                            {{ item.description }}
                                        </p>
                                    </div>
                                    <div class="shrink-0 sm:text-right">
                                        <div v-if="item.amount" class="text-sm font-semibold text-stone-900 dark:text-white">
                                            {{ amountLabel(item) }}
                                        </div>
                                        <time
                                            v-if="item.occurred_at"
                                            :datetime="item.occurred_at"
                                            :title="absoluteDate(item.occurred_at)"
                                            class="text-xs text-stone-500 dark:text-neutral-400"
                                        >
                                            {{ shortDate(item.occurred_at) }}
                                        </time>
                                    </div>
                                </div>
                                <div class="mt-3 border-t border-stone-200 pt-2 text-xs text-stone-500 dark:border-neutral-700 dark:text-neutral-400">
                                    {{ translate('by_actor', 'Par {actor}', { actor: actorLabel(item) }) }}
                                </div>
                            </article>
                        </li>
                    </ol>
                </li>
            </ol>

            <div v-if="!isLoading && visibleItems.length && hasMore" class="mt-6 border-t border-stone-200 pt-4 text-center dark:border-neutral-700">
                <p v-if="loadMoreError" class="mb-3 text-sm text-rose-700 dark:text-rose-300" role="alert">
                    {{ loadMoreError }}
                </p>
                <button
                    type="button"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-sm border border-stone-200 bg-white px-4 py-2 text-sm font-semibold text-stone-700 transition hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 disabled:cursor-wait disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    :disabled="isLoadingMore"
                    @click="requestTimeline({ append: true })"
                >
                    <LoaderCircle v-if="isLoadingMore" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                    {{ isLoadingMore ? translate('states.loading_more', 'Chargement…') : translate('states.load_more', 'Charger plus') }}
                </button>
            </div>
        </div>
    </section>
</template>
