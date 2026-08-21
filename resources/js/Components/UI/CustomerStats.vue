<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import CustomerKpiGrid from '@/Components/Customer/CustomerKpiGrid.vue';
import { useAccountFeatures } from '@/Composables/useAccountFeatures';
import { useCurrencyFormatter } from '@/utils/currency';
import { normalizeAvailableCustomerFilters } from '@/utils/customerFilters';

const props = defineProps({
    kpis: {
        type: Object,
        default: () => ({}),
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    filterMeta: {
        type: Object,
        default: () => ({}),
    },
    customerIndexContext: {
        type: Object,
        default: () => ({ capabilities: {} }),
    },
});

const emit = defineEmits(['activate-filter']);
const { t } = useI18n();
const { formatCurrency } = useCurrencyFormatter();
const { hasFeature } = useAccountFeatures();
const showSecondary = ref(false);
const appointmentProfile = computed(() => props.customerIndexContext?.profile === 'appointment');
const quotesFeatureEnabled = computed(() => !appointmentProfile.value && hasFeature('quotes'));
const jobsFeatureEnabled = computed(() => !appointmentProfile.value && hasFeature('jobs'));

const source = computed(() => ({
    total: props.kpis?.total ?? props.stats?.total ?? 0,
    new_this_month: props.kpis?.new_this_month ?? props.stats?.new ?? 0,
    active: props.kpis?.active ?? props.stats?.active ?? 0,
    inactive: props.kpis?.inactive,
    vip: props.kpis?.vip,
    no_next_appointment: props.kpis?.no_next_appointment,
    recent_cancellations: props.kpis?.recent_cancellations,
    recent_no_shows: props.kpis?.recent_no_shows,
    return_rate: props.kpis?.return_rate,
    average_appointments_per_customer: props.kpis?.average_appointments_per_customer,
    outstanding: props.kpis?.outstanding,
    average_value_per_customer: props.kpis?.average_value_per_customer,
    with_quotes: props.stats?.with_quotes,
    with_works: props.stats?.with_works,
}));

const availableFilters = computed(() => new Set(
    normalizeAvailableCustomerFilters(props.filterMeta?.available_filters)
));
const quickFilters = computed(() => new Set(
    Array.isArray(props.filters?.quick_filters) ? props.filters.quick_filters : []
));
const capabilities = computed(() => props.customerIndexContext?.capabilities || {});

const hasValue = (key) => source.value[key] !== null && source.value[key] !== undefined;
const canUseQuickFilter = (key) => availableFilters.value.has(key);
const number = (value, maximumFractionDigits = 0) => Number(value || 0).toLocaleString(undefined, {
    maximumFractionDigits,
});
const percent = (value) => `${number(value, 1)} %`;
const currencyMetric = (metric) => formatCurrency(metric?.amount ?? 0, metric?.currency_code || null);
const quickAction = (key) => (canUseQuickFilter(key) ? { type: 'quick', key } : null);
const advancedAction = (key, value) => ({ type: 'advanced', key, value });
const isActionActive = (action) => {
    if (!action) {
        return false;
    }

    if (action.type === 'quick') {
        return quickFilters.value.has(action.key);
    }

    return String(props.filters?.[action.key] ?? '') === String(action.value ?? '');
};
const card = ({ key, label, value, detail = '', tone, icon, action = null }) => ({
    key,
    label,
    value,
    detail,
    tone,
    icon,
    action,
    interactive: Boolean(action),
    active: isActionActive(action),
    ariaLabel: action
        ? t(isActionActive(action) ? 'customers.kpis.remove_filter' : 'customers.kpis.apply_filter', { label })
        : undefined,
});

const primaryCards = computed(() => [
    card({
        key: 'total',
        label: t('customers.stats.total'),
        value: number(source.value.total),
        tone: 'indigo',
        icon: 'users',
    }),
    card({
        key: 'new_this_month',
        label: t('customers.stats.new_this_month'),
        value: number(source.value.new_this_month),
        tone: 'emerald',
        icon: 'user-plus',
        action: quickAction('new_this_month'),
    }),
    card({
        key: 'active',
        label: t('customers.stats.active'),
        value: number(source.value.active),
        tone: 'sky',
        icon: 'users',
        action: advancedAction('status', 'active'),
    }),
    hasValue('vip') && (capabilities.value.campaigns || canUseQuickFilter('vip'))
        ? card({
            key: 'vip',
            label: t('customers.stats.vip'),
            value: number(source.value.vip),
            tone: 'amber',
            icon: 'star',
            action: quickAction('vip'),
        })
        : null,
    hasValue('no_next_appointment') && (capabilities.value.reservations || canUseQuickFilter('no_next_appointment'))
        ? card({
            key: 'no_next_appointment',
            label: t('customers.stats.no_next_appointment'),
            value: number(source.value.no_next_appointment),
            tone: 'violet',
            icon: 'calendar',
            action: quickAction('no_next_appointment'),
        })
        : null,
    hasValue('outstanding') && (capabilities.value.invoices || canUseQuickFilter('outstanding_balance'))
        ? card({
            key: 'outstanding',
            label: t('customers.stats.outstanding'),
            value: currencyMetric(source.value.outstanding),
            detail: t('customers.stats.customers_count', { count: Number(source.value.outstanding?.customers || 0) }),
            tone: 'rose',
            icon: 'invoice',
            action: quickAction('outstanding_balance'),
        })
        : null,
].filter(Boolean));

const secondaryCards = computed(() => [
    hasValue('inactive')
        ? card({
            key: 'inactive',
            label: t('customers.stats.inactive'),
            value: number(source.value.inactive),
            tone: 'stone',
            icon: 'users',
            action: quickAction('inactive'),
        })
        : null,
    hasValue('recent_cancellations')
        ? card({
            key: 'recent_cancellations',
            label: t('customers.stats.recent_cancellations'),
            value: number(source.value.recent_cancellations),
            tone: 'amber',
            icon: 'alert',
            action: quickAction('recent_cancellations'),
        })
        : null,
    hasValue('recent_no_shows')
        ? card({
            key: 'recent_no_shows',
            label: t('customers.stats.recent_no_shows'),
            value: number(source.value.recent_no_shows),
            tone: 'rose',
            icon: 'alert',
            action: quickAction('recent_no_shows'),
        })
        : null,
    hasValue('return_rate')
        ? card({
            key: 'return_rate',
            label: t('customers.stats.return_rate'),
            value: percent(source.value.return_rate),
            tone: 'emerald',
            icon: 'repeat',
        })
        : null,
    hasValue('average_value_per_customer')
        ? card({
            key: 'average_value_per_customer',
            label: t('customers.stats.average_value_per_customer'),
            value: currencyMetric(source.value.average_value_per_customer),
            tone: 'cyan',
            icon: 'invoice',
        })
        : null,
    hasValue('average_appointments_per_customer')
        ? card({
            key: 'average_appointments_per_customer',
            label: t('customers.stats.average_appointments_per_customer'),
            value: number(source.value.average_appointments_per_customer, 1),
            tone: 'violet',
            icon: 'calendar',
        })
        : null,
    quotesFeatureEnabled.value && hasValue('with_quotes')
        ? card({
            key: 'with_quotes',
            label: t('customers.stats.with_quotes'),
            value: number(source.value.with_quotes),
            tone: 'amber',
            icon: 'invoice',
            action: advancedAction('has_quotes', '1'),
        })
        : null,
    jobsFeatureEnabled.value && hasValue('with_works')
        ? card({
            key: 'with_works',
            label: t('customers.stats.with_jobs'),
            value: number(source.value.with_works),
            tone: 'rose',
            icon: 'users',
            action: advancedAction('has_works', '1'),
        })
        : null,
].filter(Boolean));
</script>

<template>
    <section class="mb-3 space-y-2 md:mb-5 md:space-y-3" aria-labelledby="customer-kpis-title">
        <div id="customer-kpis-title" class="sr-only">{{ t('customers.kpis.title') }}</div>
        <CustomerKpiGrid
            :cards="primaryCards"
            labelled-by="customer-kpis-title"
            @activate="emit('activate-filter', $event)"
        />

        <div v-if="secondaryCards.length" class="space-y-2">
            <button
                type="button"
                class="inline-flex min-h-11 items-center gap-1.5 rounded-sm px-2 text-xs font-semibold text-stone-600 hover:bg-stone-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-neutral-300 dark:hover:bg-neutral-800"
                :aria-expanded="String(showSecondary)"
                aria-controls="customer-secondary-kpis"
                @click="showSecondary = !showSecondary"
            >
                {{ t(showSecondary ? 'customers.kpis.show_less' : 'customers.kpis.show_more') }}
                <svg
                    class="size-3.5 transition-transform motion-reduce:transition-none"
                    :class="showSecondary ? 'rotate-180' : ''"
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <path d="m6 9 6 6 6-6" />
                </svg>
            </button>

            <CustomerKpiGrid
                v-show="showSecondary"
                id="customer-secondary-kpis"
                :cards="secondaryCards"
                labelled-by="customer-kpis-title"
                @activate="emit('activate-filter', $event)"
            />
        </div>
    </section>
</template>
