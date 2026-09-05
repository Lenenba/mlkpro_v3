<script setup>
import { computed, nextTick, reactive, ref, useId } from 'vue';
import axios from 'axios';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import CustomerMediaFields from '@/Components/Customer/CustomerMediaFields.vue';
import {
    buildCustomerClientTypeOptions,
    CUSTOMER_CLIENT_TYPE_COMPANY,
    CUSTOMER_CLIENT_TYPE_INDIVIDUAL,
} from '@/utils/customerClientTypes';
import { defaultCustomerIconForType } from '@/utils/iconPresets';
import { toFormData } from '@/utils/formData';
import { assignGeoapifyAddress, useGeoapifyAddressAutocomplete } from '@/Composables/useGeoapifyAddressAutocomplete';
import { useAccountFeatures } from '@/Composables/useAccountFeatures';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    overlayId: {
        type: String,
        default: null,
    },
    submitLabel: {
        type: String,
        default: '',
    },
    closeOnSuccess: {
        type: Boolean,
        default: false,
    },
    compact: {
        type: Boolean,
        default: false,
    },
    defaultPortalAccess: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['created', 'cancel', 'processing']);

const { t } = useI18n();
const { hasFeature } = useAccountFeatures();
const quotesFeatureEnabled = computed(() => hasFeature('quotes'));
const jobsFeatureEnabled = computed(() => hasFeature('jobs'));
const tasksFeatureEnabled = computed(() => hasFeature('tasks'));
const invoicesFeatureEnabled = computed(() => hasFeature('invoices'));
const billingModes = computed(() => [
    ...(tasksFeatureEnabled.value
        ? [{ id: 'per_task', name: t('customers.form.billing_modes.per_task') }]
        : []),
    ...(jobsFeatureEnabled.value
        ? [{ id: 'per_segment', name: t('customers.form.billing_modes.per_segment') }]
        : []),
    {
        id: 'end_of_job',
        name: t(jobsFeatureEnabled.value
            ? 'customers.form.billing_modes.end_of_job'
            : 'customers.form.billing_modes.end_of_service'),
    },
    { id: 'deferred', name: t('customers.form.billing_modes.deferred') },
]);
const billingGroupings = computed(() => ([
    { id: 'single', name: t('customers.form.billing_groupings.single') },
    { id: 'periodic', name: t('customers.form.billing_groupings.periodic') },
]));
const billingCycles = computed(() => ([
    { id: 'weekly', name: t('customers.form.billing_cycles.weekly') },
    { id: 'biweekly', name: t('customers.form.billing_cycles.biweekly') },
    { id: 'monthly', name: t('customers.form.billing_cycles.monthly') },
    ...(tasksFeatureEnabled.value
        ? [{ id: 'every_n_tasks', name: t('customers.form.billing_cycles.every_n_tasks') }]
        : []),
]));

const form = reactive({
    client_type: CUSTOMER_CLIENT_TYPE_INDIVIDUAL,
    salutation: 'Mr',
    first_name: '',
    last_name: '',
    birth_date: '',
    email: '',
    phone: '',
    company_name: '',
    registration_number: '',
    industry: '',
    logo: null,
    logo_icon: defaultCustomerIconForType(CUSTOMER_CLIENT_TYPE_INDIVIDUAL),
    discount_rate: '',
    portal_access: props.defaultPortalAccess,
    description: '',
    refer_by: '',
    billing_same_as_physical: false,
    billing_mode: 'end_of_job',
    billing_cycle: '',
    billing_grouping: 'single',
    billing_delay_days: '',
    billing_date_rule: '',
    auto_accept_quotes: false,
    auto_validate_jobs: false,
    auto_validate_tasks: false,
    auto_validate_invoices: false,
    properties: {
        type: 'physical',
        street1: '',
        street2: '',
        city: '',
        state: '',
        zip: '',
        country: '',
    },
});

const errors = ref({});
const formError = ref('');
const isSubmitting = ref(false);
const errorSummary = ref(null);
const portalAccessId = `quick-customer-portal-access-${useId().replaceAll(':', '')}`;
const {
    query: addressQuery,
    suggestions: addressSuggestions,
    searchAddress,
    selectAddress,
} = useGeoapifyAddressAutocomplete({
    onSelect: (details) => {
        assignGeoapifyAddress(form.properties, details);
    },
});

const clientTypeOptions = computed(() => buildCustomerClientTypeOptions(t));
const isCompanyClient = computed(() => form.client_type === CUSTOMER_CLIENT_TYPE_COMPANY);
const maxBirthDate = new Date().toISOString().slice(0, 10);
const contactSectionTitle = computed(() => (
    isCompanyClient.value
        ? t('customers.form.sections.main_contact')
        : t('customers.form.sections.contact_details')
));

const resolvedSubmitLabel = computed(() =>
    props.submitLabel || t('customers.form.actions.save_client')
);

const hasPropertyInput = computed(() => {
    const { type, ...fields } = form.properties || {};
    const values = Object.values(fields);
    return values.some((value) => String(value || '').trim().length > 0);
});

const propertyValid = computed(() => {
    if (!hasPropertyInput.value) {
        return true;
    }
    return String(form.properties.city || '').trim().length > 0;
});

const isValid = computed(() => {
    return (
        form.client_type &&
        form.first_name.trim() &&
        form.last_name.trim() &&
        form.email.trim() &&
        (!isCompanyClient.value || form.company_name.trim()) &&
        propertyValid.value
    );
});

const errorMessages = computed(() => {
    const messages = [];
    Object.values(errors.value || {}).forEach((value) => {
        if (Array.isArray(value) && value.length) {
            messages.push(value[0]);
        } else if (typeof value === 'string' && value.length) {
            messages.push(value);
        }
    });
    if (formError.value) {
        messages.push(formError.value);
    }
    if (!propertyValid.value) {
        messages.push(t('customers.form.errors.city_required'));
    }
    return messages;
});

const focusErrorSummary = async () => {
    await nextTick();
    errorSummary.value?.focus();
};

const resetForm = () => {
    form.client_type = CUSTOMER_CLIENT_TYPE_INDIVIDUAL;
    form.salutation = 'Mr';
    form.first_name = '';
    form.last_name = '';
    form.birth_date = '';
    form.email = '';
    form.phone = '';
    form.company_name = '';
    form.registration_number = '';
    form.industry = '';
    form.logo = null;
    form.logo_icon = defaultCustomerIconForType(CUSTOMER_CLIENT_TYPE_INDIVIDUAL);
    form.discount_rate = '';
    form.description = '';
    form.refer_by = '';
    form.portal_access = props.defaultPortalAccess;
    form.billing_same_as_physical = false;
    form.billing_mode = 'end_of_job';
    form.billing_cycle = '';
    form.billing_grouping = 'single';
    form.billing_delay_days = '';
    form.billing_date_rule = '';
    form.auto_accept_quotes = false;
    form.auto_validate_jobs = false;
    form.auto_validate_tasks = false;
    form.auto_validate_invoices = false;
    form.properties = {
        type: 'physical',
        street1: '',
        street2: '',
        city: '',
        state: '',
        zip: '',
        country: '',
    };
    addressQuery.value = '';
    addressSuggestions.value = [];
};

const hideOverlay = () => {
    if (props.overlayId && window.HSOverlay) {
        window.HSOverlay.close(props.overlayId);
    }
};

const closeOverlay = () => {
    if (isSubmitting.value) {
        return;
    }

    emit('cancel');
    hideOverlay();
};

const submit = async () => {
    if (isSubmitting.value) {
        return;
    }

    if (!isValid.value) {
        formError.value = t('customers.form.errors.required_fields');
        await focusErrorSummary();
        return;
    }

    errors.value = {};
    formError.value = '';

    const payload = {
        client_type: form.client_type,
        salutation: form.salutation,
        first_name: form.first_name,
        last_name: form.last_name,
        birth_date: isCompanyClient.value ? null : (form.birth_date || null),
        email: form.email,
        phone: form.phone,
        company_name: form.company_name,
        registration_number: form.registration_number,
        industry: form.industry,
        discount_rate: form.discount_rate ? Number(form.discount_rate) : 0,
        portal_access: form.portal_access,
        description: form.description,
        refer_by: form.refer_by,
    };

    if (!props.compact && typeof File !== 'undefined' && form.logo instanceof File) {
        payload.logo = form.logo;
    } else if (!props.compact && form.logo_icon) {
        payload.logo_icon = form.logo_icon;
    }

    if (invoicesFeatureEnabled.value) {
        payload.billing_same_as_physical = Boolean(form.billing_same_as_physical);
        payload.billing_mode = form.billing_mode;
        payload.billing_cycle = form.billing_cycle || null;
        payload.billing_grouping = form.billing_grouping;
        payload.billing_delay_days = form.billing_delay_days === '' ? null : Number(form.billing_delay_days);
        payload.billing_date_rule = form.billing_date_rule || null;
    }
    if (quotesFeatureEnabled.value) {
        payload.auto_accept_quotes = Boolean(form.auto_accept_quotes);
    }
    if (jobsFeatureEnabled.value) {
        payload.auto_validate_jobs = Boolean(form.auto_validate_jobs);
    }
    if (tasksFeatureEnabled.value) {
        payload.auto_validate_tasks = Boolean(form.auto_validate_tasks);
    }
    if (invoicesFeatureEnabled.value) {
        payload.auto_validate_invoices = Boolean(form.auto_validate_invoices);
    }

    if (hasPropertyInput.value && propertyValid.value) {
        payload.properties = {
            type: form.properties.type || 'physical',
            street1: form.properties.street1,
            street2: form.properties.street2,
            city: form.properties.city,
            state: form.properties.state,
            zip: form.properties.zip,
            country: form.properties.country,
        };
    }

    isSubmitting.value = true;
    emit('processing', true);

    try {
        const response = await axios.post(route('customer.quick.store'), toFormData(payload), {
            headers: { Accept: 'application/json' },
        });
        emit('created', response.data);
        if (props.closeOnSuccess) {
            hideOverlay();
        }
        resetForm();
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data?.errors || {};
            await focusErrorSummary();
        } else {
            formError.value = t('customers.form.errors.save_failed');
        }
    } finally {
        isSubmitting.value = false;
        emit('processing', false);
    }
};

</script>

<template>
    <form @submit.prevent="submit" :aria-busy="isSubmitting">
        <fieldset class="m-0 min-w-0 space-y-4 border-0 p-0" :disabled="isSubmitting">
        <FloatingSelect
            v-model="form.client_type"
            :label="$t('customers.form.fields.client_type')"
            :options="clientTypeOptions"
            :required="true"
        />

        <div v-if="isCompanyClient">
            <div class="mb-2 text-sm font-medium text-stone-700 dark:text-neutral-200">
                {{ $t('customers.form.sections.company_details') }}
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <FloatingInput
                v-model="form.company_name"
                :label="$t('customers.form.fields.company_name')"
                :required="true"
                :class="compact ? 'md:col-span-2' : ''"
            />
            <FloatingInput
                v-if="!compact"
                v-model="form.registration_number"
                :label="$t('customers.form.fields.registration_number')"
            />
            <div v-if="!compact" class="md:col-span-2">
                <FloatingInput v-model="form.industry" :label="$t('customers.form.fields.industry')" />
            </div>
            </div>
        </div>

        <CustomerMediaFields
            v-if="!compact"
            v-model:logo="form.logo"
            v-model:logoIcon="form.logo_icon"
            :client-type="form.client_type"
            :logo-error="errors.logo"
            :logo-icon-error="errors.logo_icon"
        />

        <div>
            <div class="mb-2 text-sm font-medium text-stone-700 dark:text-neutral-200">{{ contactSectionTitle }}</div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <FloatingInput v-model="form.first_name" :label="$t('customers.form.fields.first_name')" :required="true" />
                <FloatingInput v-model="form.last_name" :label="$t('customers.form.fields.last_name')" :required="true" />
                <FloatingInput
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    :label="$t('customers.form.fields.email')"
                    :required="true"
                />
                <FloatingInput
                    v-model="form.phone"
                    type="tel"
                    autocomplete="tel"
                    :label="$t('customers.form.fields.phone')"
                />
                <FloatingInput
                    v-if="!compact && !isCompanyClient"
                    v-model="form.birth_date"
                    type="date"
                    :max="maxBirthDate"
                    :label="$t('customers.form.fields.birth_date')"
                />
                <FloatingInput
                    v-if="!compact"
                    v-model="form.discount_rate"
                    type="number"
                    :label="$t('customers.form.fields.discount_rate')"
                />
            </div>
        </div>
        <div class="flex items-start gap-2">
            <input :id="portalAccessId" type="checkbox" v-model="form.portal_access"
                class="mt-1 size-4 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700 dark:checked:bg-green-500 dark:checked:border-green-500" />
            <div>
                <label :for="portalAccessId" class="text-sm text-stone-700 dark:text-neutral-200">
                    {{ $t('customers.form.fields.portal_access') }}
                </label>
            </div>
        </div>

        <FloatingTextarea
            v-if="!compact"
            v-model="form.description"
            :label="$t('customers.form.fields.notes')"
        />

        <div v-if="!compact" class="rounded-sm border border-stone-200 p-4 dark:border-neutral-700">
            <div class="text-sm font-medium text-stone-700 dark:text-neutral-200">{{ $t('customers.form.sections.location') }}</div>
            <div class="mt-3">
                <div class="relative">
                    <div class="relative">
                        <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                            <svg class="shrink-0 size-4 text-stone-400 dark:text-white/60" xmlns="http://www.w3.org/2000/svg"
                                width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8"></circle>
                                <path d="m21 21-4.3-4.3"></path>
                            </svg>
                        </div>
                        <input v-model="addressQuery" @input="searchAddress"
                            class="py-3 ps-10 pe-4 block w-full border-stone-200 rounded-sm text-sm focus:border-green-600 focus:ring-green-600"
                            type="text" role="combobox" aria-expanded="false" :placeholder="$t('customers.form.fields.search_address')"
                            />
                    </div>

                    <div v-if="addressSuggestions.length"
                        class="absolute z-50 w-full bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:bg-neutral-800">
                        <div
                            class="max-h-[300px] p-2 overflow-y-auto overflow-hidden [&::-webkit-scrollbar]:w-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                            <div v-for="suggestion in addressSuggestions" :key="suggestion.id"
                                class="py-2 px-3 flex items-center gap-x-3 hover:bg-stone-100 rounded-sm dark:hover:bg-neutral-700 cursor-pointer"
                                @click="selectAddress(suggestion.details)">
                                <span class="text-sm text-stone-800 dark:text-neutral-200">{{ suggestion.label }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                <FloatingInput v-model="form.properties.street1" :label="$t('customers.properties.fields.street1')" />
                <FloatingInput v-model="form.properties.street2" :label="$t('customers.properties.fields.street2')" />
                <FloatingInput v-model="form.properties.city" :label="$t('customers.properties.fields.city')" />
                <FloatingInput v-model="form.properties.state" :label="$t('customers.properties.fields.state')" />
                <FloatingInput v-model="form.properties.zip" :label="$t('customers.properties.fields.zip')" />
                <FloatingInput v-model="form.properties.country" :label="$t('customers.properties.fields.country')" />
            </div>
            <div v-if="invoicesFeatureEnabled" class="mt-3 flex items-center gap-2">
                <input type="checkbox" v-model="form.billing_same_as_physical"
                    class="size-3.5 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700">
                <span class="text-sm text-stone-600 dark:text-neutral-400">
                    {{ jobsFeatureEnabled
                        ? $t('customers.form.billing.same_as_property')
                        : $t('customers.form.billing.same_as_address') }}
                </span>
            </div>
            <div v-if="quotesFeatureEnabled" class="mt-3 flex items-start gap-2">
                <input type="checkbox" v-model="form.auto_accept_quotes"
                    class="mt-0.5 size-3.5 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700">
                <span class="text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('customers.form.auto_accept_quotes') }}
                </span>
            </div>
            <div v-if="jobsFeatureEnabled" class="mt-2 flex items-start gap-2">
                <input type="checkbox" v-model="form.auto_validate_jobs"
                    class="mt-0.5 size-3.5 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700">
                <span class="text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('customers.details.auto_validation.jobs') }}
                </span>
            </div>
            <div v-if="tasksFeatureEnabled" class="mt-2 flex items-start gap-2">
                <input type="checkbox" v-model="form.auto_validate_tasks"
                    class="mt-0.5 size-3.5 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700">
                <span class="text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('customers.details.auto_validation.tasks') }}
                </span>
            </div>
            <div v-if="invoicesFeatureEnabled" class="mt-2 flex items-start gap-2">
                <input type="checkbox" v-model="form.auto_validate_invoices"
                    class="mt-0.5 size-3.5 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700">
                <span class="text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('customers.details.auto_validation.invoices') }}
                </span>
            </div>
        </div>

        <div
            v-if="!compact && invoicesFeatureEnabled && (jobsFeatureEnabled || tasksFeatureEnabled)"
            class="rounded-sm border border-stone-200 p-4 dark:border-neutral-700"
        >
            <div class="text-sm font-medium text-stone-700 dark:text-neutral-200">
                {{ $t('customers.form.sections.billing_preferences') }}
            </div>
            <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                <FloatingSelect
                    v-model="form.billing_mode"
                    :label="$t('customers.form.billing.mode')"
                    :options="billingModes"
                />
                <FloatingSelect
                    v-model="form.billing_grouping"
                    :label="$t('customers.form.billing.grouping')"
                    :options="billingGroupings"
                />
            </div>
            <div
                v-if="form.billing_mode === 'per_segment' || form.billing_grouping === 'periodic' || form.billing_mode === 'deferred'"
                class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2"
            >
                <FloatingSelect
                    v-model="form.billing_cycle"
                    :label="$t('customers.form.billing.cycle')"
                    :options="billingCycles"
                />
                <FloatingInput
                    v-if="form.billing_mode === 'deferred'"
                    v-model="form.billing_delay_days"
                    type="number"
                    :label="$t('customers.form.billing.delay_days')"
                />
            </div>
            <FloatingInput
                v-if="form.billing_mode === 'deferred'"
                v-model="form.billing_date_rule"
                class="mt-3"
                :label="$t('customers.form.billing.date_rule')"
            />
        </div>

        <div
            v-if="errorMessages.length"
            ref="errorSummary"
            role="alert"
            aria-live="assertive"
            tabindex="-1"
            class="rounded-sm border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
            <div v-for="(message, index) in errorMessages" :key="index">
                {{ message }}
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" :disabled="isSubmitting" @click="closeOverlay"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                {{ $t('customers.actions.cancel') }}
            </button>
            <button type="submit" :disabled="isSubmitting"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                {{ resolvedSubmitLabel }}
            </button>
        </div>
        </fieldset>
    </form>
</template>
