<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { crmButtonClass } from '@/utils/crmButtonStyles';
import {
    CUSTOMER_ADVANCED_FILTER_DEFAULTS,
    createCustomerAdvancedFilters,
    countActiveCustomerAdvancedFilters,
    normalizeAvailableCustomerFilters,
    serializeCustomerTags,
} from '@/utils/customerFilters';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    matchingCount: {
        type: Number,
        default: null,
    },
    capabilities: {
        type: Object,
        default: () => ({}),
    },
    availableFilters: {
        type: [Array, Object],
        default: () => [],
    },
    filterOptions: {
        type: Object,
        default: () => ({}),
    },
    showQuoteFilters: {
        type: Boolean,
        default: false,
    },
    showJobFilters: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close', 'apply']);
const { t } = useI18n();
const draft = ref(createCustomerAdvancedFilters(props.filters));
const tagInput = ref('');
const firstControl = ref(null);
const available = computed(() => new Set(normalizeAvailableCustomerFilters(props.availableFilters)));
const hasCapability = (key) => Boolean(props.capabilities?.[key]);
const supports = (keys, capability) => {
    const keyList = Array.isArray(keys) ? keys : [keys];

    return keyList.some((key) => available.value.has(key))
        || (capability ? hasCapability(capability) : false);
};
const showVip = computed(() => supports(['vip', 'is_vip', 'vip_tier_id'], 'campaigns'));
const showAppointments = computed(() => supports([
    'no_next_appointment',
    'upcoming_appointment',
    'has_upcoming_appointment',
    'last_appointment_from',
], 'reservations'));
const showBilling = computed(() => supports([
    'outstanding_balance',
    'has_outstanding_balance',
    'total_invoiced_min',
], 'invoices'));
const showPackages = computed(() => supports(['package_low', 'has_active_package'], 'packages'));
const normalizeOptions = (entries) => (Array.isArray(entries) ? entries : [])
    .map((entry) => {
        if (entry && typeof entry === 'object') {
            const value = entry.value ?? entry.id ?? entry.key ?? '';
            const label = entry.label ?? entry.name ?? String(value);

            return value === '' ? null : { value: String(value), label: String(label) };
        }

        return entry === null || entry === undefined || entry === ''
            ? null
            : { value: String(entry), label: String(entry) };
    })
    .filter(Boolean);
const acquisitionSourceOptions = computed(() => normalizeOptions(props.filterOptions?.acquisition_sources));
const tagOptions = computed(() => normalizeOptions(props.filterOptions?.tags));
const vipTierOptions = computed(() => normalizeOptions(props.filterOptions?.vip_tiers));
const paymentStatuses = computed(() => {
    const configured = normalizeOptions(props.filterOptions?.payment_statuses)
        .map((option) => option.value);

    return configured.length
        ? configured
        : ['pending', 'paid', 'completed', 'failed', 'refunded', 'reversed'];
});
const draftCount = computed(() => countActiveCustomerAdvancedFilters({
    ...draft.value,
    tags: tagOptions.value.length ? draft.value.tags : serializeCustomerTags(tagInput.value),
}));

const anyOptions = computed(() => [
    { value: '', label: t('customers.advanced_filters.options.any') },
    { value: '1', label: t('customers.advanced_filters.options.yes') },
    { value: '0', label: t('customers.advanced_filters.options.no') },
]);
const statusOptions = computed(() => [
    { value: '', label: t('customers.advanced_filters.options.any') },
    { value: 'active', label: t('customers.status.active') },
    { value: 'archived', label: t('customers.status.archived') },
]);
const clientTypeOptions = computed(() => [
    { value: '', label: t('customers.advanced_filters.options.any') },
    { value: 'individual', label: t('customers.form.client_types.individual') },
    { value: 'company', label: t('customers.form.client_types.company') },
]);
const packageStatusOptions = computed(() => [
    { value: '', label: t('customers.advanced_filters.options.any') },
    { value: 'active', label: t('customers.details.customer_packages.statuses.active') },
    { value: 'consumed', label: t('customers.details.customer_packages.statuses.consumed') },
    { value: 'expired', label: t('customers.details.customer_packages.statuses.expired') },
    { value: 'cancelled', label: t('customers.details.customer_packages.statuses.cancelled') },
]);
const recurrenceStatusOptions = computed(() => [
    { value: '', label: t('customers.advanced_filters.options.any') },
    { value: 'active', label: t('customers.details.customer_packages.recurrence_statuses.active') },
    { value: 'payment_due', label: t('customers.details.customer_packages.recurrence_statuses.payment_due') },
    { value: 'suspended', label: t('customers.details.customer_packages.recurrence_statuses.suspended') },
    { value: 'cancelled', label: t('customers.details.customer_packages.recurrence_statuses.cancelled') },
]);
const hydrateDraft = () => {
    draft.value = createCustomerAdvancedFilters(props.filters);
    tagInput.value = draft.value.tags.join(', ');
};

watch(() => props.show, async (show) => {
    if (!show) {
        return;
    }

    hydrateDraft();
    await nextTick();
    firstControl.value?.focus?.();
});

const reset = () => {
    draft.value = createCustomerAdvancedFilters(CUSTOMER_ADVANCED_FILTER_DEFAULTS);
    tagInput.value = '';
    nextTick(() => firstControl.value?.focus?.());
};

const togglePaymentStatus = (status) => {
    const current = Array.isArray(draft.value.payment_statuses)
        ? draft.value.payment_statuses
        : [];

    draft.value.payment_statuses = current.includes(status)
        ? current.filter((entry) => entry !== status)
        : [...current, status];
};

const toggleTag = (tag) => {
    const current = Array.isArray(draft.value.tags) ? draft.value.tags : [];

    draft.value.tags = current.includes(tag)
        ? current.filter((entry) => entry !== tag)
        : [...current, tag];
};

const apply = () => {
    emit('apply', {
        ...draft.value,
        tags: tagOptions.value.length ? draft.value.tags : serializeCustomerTags(tagInput.value),
    });
};
</script>

<template>
    <Modal
        :show="show"
        max-width="5xl"
        position="center"
        full-screen-mobile
        aria-labelledby="customer-advanced-filters-title"
        aria-describedby="customer-advanced-filters-description"
        @close="emit('close')"
    >
        <div class="flex h-dvh flex-col sm:h-auto sm:max-h-[calc(100vh-3rem)]">
            <header class="flex shrink-0 items-start justify-between gap-4 border-b border-stone-200 px-4 py-4 sm:px-6 dark:border-neutral-700">
                <div class="min-w-0">
                    <h2 id="customer-advanced-filters-title" class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('customers.advanced_filters.title') }}
                    </h2>
                    <p id="customer-advanced-filters-description" class="mt-1 break-words text-xs text-stone-500 dark:text-neutral-400">
                        {{ t('customers.advanced_filters.description') }}
                    </p>
                </div>
                <button
                    type="button"
                    class="inline-flex size-11 shrink-0 items-center justify-center rounded-sm text-stone-500 hover:bg-stone-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-neutral-400 dark:hover:bg-neutral-800"
                    :aria-label="t('customers.advanced_filters.close')"
                    @click="emit('close')"
                >
                    <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>
            </header>

            <div class="min-h-0 flex-1 space-y-7 overflow-y-auto px-4 py-5 sm:px-6">
                <fieldset class="space-y-3">
                    <legend class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ t('customers.advanced_filters.sections.profile') }}
                    </legend>
                    <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                        <FloatingSelect ref="firstControl" v-model="draft.status" :label="t('customers.filters.status')" :options="statusOptions" />
                        <FloatingSelect v-model="draft.client_type" :label="t('customers.advanced_filters.fields.client_type')" :options="clientTypeOptions" />
                        <FloatingInput v-model="draft.city" :label="t('customers.filters.city')" />
                        <FloatingInput v-model="draft.country" :label="t('customers.filters.country')" />
                        <FloatingSelect
                            v-if="acquisitionSourceOptions.length"
                            v-model="draft.acquisition_source"
                            :label="t('customers.advanced_filters.fields.acquisition_source')"
                            :options="[{ value: '', label: t('customers.advanced_filters.options.any') }, ...acquisitionSourceOptions]"
                        />
                        <FloatingInput v-else v-model="draft.acquisition_source" :label="t('customers.advanced_filters.fields.acquisition_source')" />
                        <FloatingInput v-if="!tagOptions.length" v-model="tagInput" :label="t('customers.advanced_filters.fields.tags')" :placeholder="t('customers.advanced_filters.fields.tags_hint')" />
                        <FloatingSelect v-if="showVip" v-model="draft.is_vip" :label="t('customers.advanced_filters.fields.vip')" :options="anyOptions" />
                        <FloatingSelect
                            v-if="showVip && vipTierOptions.length"
                            v-model="draft.vip_tier_id"
                            :label="t('customers.advanced_filters.fields.vip_tier')"
                            :options="[{ value: '', label: t('customers.advanced_filters.options.any') }, ...vipTierOptions]"
                        />
                        <FloatingSelect v-if="showQuoteFilters" v-model="draft.has_quotes" :label="t('customers.filters.quotes')" :options="anyOptions" />
                        <FloatingSelect v-if="showJobFilters" v-model="draft.has_works" :label="t('customers.filters.jobs')" :options="anyOptions" />
                    </div>

                    <div v-if="tagOptions.length">
                        <div class="mb-2 text-xs font-medium text-stone-600 dark:text-neutral-300">
                            {{ t('customers.advanced_filters.fields.tags') }}
                        </div>
                        <div class="grid max-h-60 grid-cols-1 gap-2 overflow-y-auto rounded-sm border border-stone-200 bg-stone-50/60 p-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 dark:border-neutral-700 dark:bg-neutral-800/40">
                            <label
                                v-for="option in tagOptions"
                                :key="option.value"
                                :title="option.label"
                                class="flex min-h-11 min-w-0 cursor-pointer items-center gap-2 rounded-sm border px-3 py-2 text-xs transition-colors focus-within:ring-2 focus-within:ring-green-600"
                                :class="draft.tags.includes(option.value)
                                    ? 'border-green-600 bg-green-50 text-green-800 dark:border-green-500 dark:bg-green-950/40 dark:text-green-200'
                                    : 'border-stone-200 bg-white text-stone-700 hover:border-stone-300 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:border-neutral-600'"
                            >
                                <input
                                    type="checkbox"
                                    class="shrink-0 rounded border-stone-300 text-green-600 focus:ring-green-600 dark:border-neutral-600 dark:bg-neutral-900"
                                    :checked="draft.tags.includes(option.value)"
                                    @change="toggleTag(option.value)"
                                >
                                <span class="min-w-0 truncate">{{ option.label }}</span>
                            </label>
                        </div>
                    </div>

                    <div v-if="showPackages" class="grid gap-3 border-t border-stone-100 pt-3 md:grid-cols-2 lg:grid-cols-3 dark:border-neutral-800">
                        <FloatingSelect v-model="draft.has_active_package" :label="t('customers.filters.active_package')" :options="anyOptions" />
                        <FloatingSelect v-model="draft.package_status" :label="t('customers.filters.package_status')" :options="packageStatusOptions" />
                        <FloatingInput v-model="draft.package_remaining_lte" type="number" min="0" step="1" :label="t('customers.filters.package_remaining_lte')" />
                        <FloatingInput v-model="draft.package_expires_within_days" type="number" min="0" step="1" :label="t('customers.filters.package_expires_within_days')" />
                        <FloatingSelect v-model="draft.package_is_recurring" :label="t('customers.filters.package_recurrence')" :options="anyOptions" />
                        <FloatingSelect v-model="draft.package_recurrence_status" :label="t('customers.filters.package_recurrence_status')" :options="recurrenceStatusOptions" />
                    </div>
                </fieldset>

                <fieldset v-if="showAppointments" class="space-y-3 border-t border-stone-200 pt-5 dark:border-neutral-700">
                    <legend class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ t('customers.advanced_filters.sections.appointments') }}
                    </legend>
                    <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                        <FloatingSelect v-model="draft.has_upcoming_appointment" :label="t('customers.advanced_filters.fields.upcoming_appointment')" :options="anyOptions" />
                        <FloatingInput v-model="draft.appointments_min" type="number" min="0" step="1" :label="t('customers.advanced_filters.fields.appointments_min')" />
                        <FloatingInput v-model="draft.appointments_max" type="number" min="0" step="1" :label="t('customers.advanced_filters.fields.appointments_max')" />
                        <FloatingInput v-model="draft.cancellations_min" type="number" min="0" step="1" :label="t('customers.advanced_filters.fields.cancellations_min')" />
                        <FloatingInput v-model="draft.no_shows_min" type="number" min="0" step="1" :label="t('customers.advanced_filters.fields.no_shows_min')" />
                    </div>
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <DatePicker v-model="draft.last_appointment_from" :label="t('customers.advanced_filters.fields.last_appointment_from')" />
                        <DatePicker v-model="draft.last_appointment_to" :label="t('customers.advanced_filters.fields.last_appointment_to')" />
                        <DatePicker v-model="draft.next_appointment_from" :label="t('customers.advanced_filters.fields.next_appointment_from')" />
                        <DatePicker v-model="draft.next_appointment_to" :label="t('customers.advanced_filters.fields.next_appointment_to')" />
                    </div>
                </fieldset>

                <fieldset v-if="showBilling" class="space-y-3 border-t border-stone-200 pt-5 dark:border-neutral-700">
                    <legend class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ t('customers.advanced_filters.sections.billing') }}
                    </legend>
                    <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                        <FloatingSelect v-model="draft.has_outstanding_balance" :label="t('customers.advanced_filters.fields.outstanding_balance')" :options="anyOptions" />
                        <FloatingInput v-model="draft.outstanding_min" type="number" min="0" step="0.01" :label="t('customers.advanced_filters.fields.outstanding_min')" />
                        <FloatingInput v-model="draft.outstanding_max" type="number" min="0" step="0.01" :label="t('customers.advanced_filters.fields.outstanding_max')" />
                        <FloatingInput v-model="draft.total_invoiced_min" type="number" min="0" step="0.01" :label="t('customers.advanced_filters.fields.total_invoiced_min')" />
                        <FloatingInput v-model="draft.total_invoiced_max" type="number" min="0" step="0.01" :label="t('customers.advanced_filters.fields.total_invoiced_max')" />
                        <DatePicker v-model="draft.last_invoice_from" :label="t('customers.advanced_filters.fields.last_invoice_from')" />
                        <DatePicker v-model="draft.last_invoice_to" :label="t('customers.advanced_filters.fields.last_invoice_to')" />
                    </div>
                    <div>
                        <div class="mb-2 text-xs font-medium text-stone-600 dark:text-neutral-300">
                            {{ t('customers.advanced_filters.fields.payment_statuses') }}
                        </div>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            <label
                                v-for="status in paymentStatuses"
                                :key="status"
                                :title="t(`customers.advanced_filters.payment_statuses.${status}`)"
                                class="flex min-h-11 min-w-0 cursor-pointer items-center gap-2 rounded-sm border px-3 py-2 text-xs transition-colors focus-within:ring-2 focus-within:ring-green-600"
                                :class="draft.payment_statuses.includes(status)
                                    ? 'border-green-600 bg-green-50 text-green-800 dark:border-green-500 dark:bg-green-950/40 dark:text-green-200'
                                    : 'border-stone-200 bg-white text-stone-700 hover:border-stone-300 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:border-neutral-600'"
                            >
                                <input
                                    type="checkbox"
                                    class="shrink-0 rounded border-stone-300 text-green-600 focus:ring-green-600 dark:border-neutral-600 dark:bg-neutral-900"
                                    :checked="draft.payment_statuses.includes(status)"
                                    @change="togglePaymentStatus(status)"
                                >
                                <span class="min-w-0 truncate">{{ t(`customers.advanced_filters.payment_statuses.${status}`) }}</span>
                            </label>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="space-y-3 border-t border-stone-200 pt-5 dark:border-neutral-700">
                    <legend class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ t('customers.advanced_filters.sections.period') }}
                    </legend>
                    <div class="grid gap-3 md:grid-cols-2">
                        <DatePicker v-model="draft.created_from" :label="t('customers.filters.created_from')" />
                        <DatePicker v-model="draft.created_to" :label="t('customers.filters.created_to')" />
                    </div>
                </fieldset>
            </div>

            <footer class="sticky bottom-0 z-10 flex shrink-0 flex-col gap-3 border-t border-stone-200 bg-white px-4 py-3 shadow-[0_-8px_24px_rgba(0,0,0,0.06)] sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:border-neutral-700 dark:bg-neutral-900 dark:shadow-[0_-8px_24px_rgba(0,0,0,0.22)]">
                <p class="text-xs text-stone-500 dark:text-neutral-400" aria-live="polite">
                    {{ t('customers.advanced_filters.draft_count', { count: draftCount }) }}
                    <template v-if="matchingCount !== null">
                        · {{ t('customers.advanced_filters.current_results', { count: matchingCount }) }}
                    </template>
                </p>
                <div class="grid w-full grid-cols-1 gap-2 sm:flex sm:w-auto sm:flex-wrap sm:justify-end">
                    <button type="button" :class="[crmButtonClass('secondary', 'toolbar'), 'min-h-11 whitespace-nowrap']" @click="reset">
                        {{ t('customers.advanced_filters.reset') }}
                    </button>
                    <button type="button" :class="[crmButtonClass('secondary', 'toolbar'), 'min-h-11 whitespace-nowrap']" @click="emit('close')">
                        {{ t('customers.actions.cancel') }}
                    </button>
                    <button type="button" :class="[crmButtonClass('primary', 'toolbar'), 'min-h-11 whitespace-nowrap']" @click="apply">
                        {{ t('customers.advanced_filters.apply') }}
                    </button>
                </div>
            </footer>
        </div>
    </Modal>
</template>
