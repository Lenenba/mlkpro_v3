<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import CustomerMediaFields from '@/Components/Customer/CustomerMediaFields.vue';
import InputError from '@/Components/InputError.vue';
import PreferenceToggleRow from '@/Components/PreferenceToggleRow.vue';
import FormActionBar from '@/Components/UI/FormActionBar.vue';
import {
    customerIconPresetsForType,
    defaultCustomerIconForType,
    isCustomerIconPreset,
} from '@/utils/iconPresets';
import {
    buildCustomerClientTypeOptions,
    CUSTOMER_CLIENT_TYPE_COMPANY,
    CUSTOMER_CLIENT_TYPE_INDIVIDUAL,
    resolveCustomerClientType,
} from '@/utils/customerClientTypes';
import { assignGeoapifyAddress, useGeoapifyAddressAutocomplete } from '@/Composables/useGeoapifyAddressAutocomplete';
import { useAccountFeatures } from '@/Composables/useAccountFeatures';
import { useForm, Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';


const props = defineProps({
    customer: Object,
    canManageNotes: {
        type: Boolean,
        default: false,
    },
});

const { t } = useI18n();

const isCreating = !props.customer?.id;
const page = usePage();
const { hasFeature } = useAccountFeatures();
const quotesFeatureEnabled = computed(() => hasFeature('quotes'));
const jobsFeatureEnabled = computed(() => hasFeature('jobs'));
const tasksFeatureEnabled = computed(() => hasFeature('tasks'));
const invoicesFeatureEnabled = computed(() => hasFeature('invoices'));
const hasAutoValidationFeatures = computed(() => (
    quotesFeatureEnabled.value
    || jobsFeatureEnabled.value
    || tasksFeatureEnabled.value
    || invoicesFeatureEnabled.value
));
const isGuidedDemo = computed(() => Boolean(page.props.demo?.is_guided));
const demoPrefilled = ref(false);
const clientTypeOptions = computed(() => buildCustomerClientTypeOptions(t));
const initialClientType = resolveCustomerClientType(
    props.customer,
    props.customer?.id ? resolveCustomerClientType(props.customer) : CUSTOMER_CLIENT_TYPE_INDIVIDUAL
);

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

const resolveBillingMode = (billingMode) => {
    if (billingMode === 'per_task' && !tasksFeatureEnabled.value) {
        return 'end_of_job';
    }

    if (billingMode === 'per_segment' && !jobsFeatureEnabled.value) {
        return 'end_of_job';
    }

    return billingMode || 'end_of_job';
};

const initialLogoPath = props.customer?.logo_url || props.customer?.logo || '';
const initialLogoIconCandidate = isCustomerIconPreset(props.customer?.logo)
    ? props.customer.logo
    : (isCustomerIconPreset(initialLogoPath) ? initialLogoPath : '');
const initialLogoIcon = customerIconPresetsForType(initialClientType).includes(initialLogoIconCandidate)
    ? initialLogoIconCandidate
    : '';
const defaultLogoIcon = initialLogoIcon || (
    isCreating || initialLogoIconCandidate ? defaultCustomerIconForType(initialClientType) : ''
);
const initialLogoPreview = isCustomerIconPreset(initialLogoPath) ? '' : initialLogoPath;

const resolvePrimaryProperty = () => {
    const properties = props.customer?.properties;
    const primary = Array.isArray(properties)
        ? (properties.find((property) => property.is_default) || properties[0] || null)
        : (properties || null);

    return {
        street1: primary?.street1 || '',
        street2: primary?.street2 || '',
        city: primary?.city || '',
        state: primary?.state || '',
        zip: primary?.zip || '',
        country: primary?.country || '',
    };
};

// Initialize the form
const form = useForm({
    client_type: initialClientType,
    first_name: props.customer?.first_name || '',
    last_name: props.customer?.last_name || '',
    birth_date: String(props.customer?.birth_date || '').slice(0, 10),
    email: props.customer?.email || '',
    portal_access: props.customer?.portal_access ?? true,
    company_name: props.customer?.company_name || '',
    registration_number: props.customer?.registration_number || '',
    industry: props.customer?.industry || '',
    billing_same_as_physical: props.customer?.billing_same_as_physical || false,
    logo: initialLogoPreview,
    logo_icon: defaultLogoIcon,
    description: props.canManageNotes ? (props.customer?.description || '') : '',
    refer_by: props.customer?.refer_by || '',
    salutation: props.customer?.salutation || 'Mr',
    phone: props.customer?.phone || '',
    properties: resolvePrimaryProperty(),
    billing_mode: resolveBillingMode(props.customer?.billing_mode),
    billing_cycle: props.customer?.billing_cycle || '',
    billing_grouping: props.customer?.billing_grouping || 'single',
    billing_delay_days: props.customer?.billing_delay_days ?? '',
    billing_date_rule: props.customer?.billing_date_rule || '',
    discount_rate: props.customer?.discount_rate ?? '',
    auto_accept_quotes: quotesFeatureEnabled.value && (props.customer?.auto_accept_quotes ?? false),
    auto_validate_jobs: jobsFeatureEnabled.value && (props.customer?.auto_validate_jobs ?? false),
    auto_validate_tasks: tasksFeatureEnabled.value && (props.customer?.auto_validate_tasks ?? false),
    auto_validate_invoices: invoicesFeatureEnabled.value && (props.customer?.auto_validate_invoices ?? false),
});

const autoValidationOptions = computed(() => [
    ...(quotesFeatureEnabled.value
        ? [{
            id: 'customer-auto-accept-quotes',
            formKey: 'auto_accept_quotes',
            label: t('customers.form.auto_accept_quotes'),
            description: t(form.auto_accept_quotes
                ? 'customers.form.states.enabled'
                : 'customers.form.states.disabled'),
        }]
        : []),
    ...(jobsFeatureEnabled.value
        ? [{
            id: 'customer-auto-validate-jobs',
            formKey: 'auto_validate_jobs',
            label: t('customers.details.auto_validation.jobs'),
            description: t(form.auto_validate_jobs
                ? 'customers.form.states.enabled'
                : 'customers.form.states.disabled'),
        }]
        : []),
    ...(tasksFeatureEnabled.value
        ? [{
            id: 'customer-auto-validate-tasks',
            formKey: 'auto_validate_tasks',
            label: t('customers.details.auto_validation.tasks'),
            description: t(form.auto_validate_tasks
                ? 'customers.form.states.enabled'
                : 'customers.form.states.disabled'),
        }]
        : []),
    ...(invoicesFeatureEnabled.value
        ? [{
            id: 'customer-auto-validate-invoices',
            formKey: 'auto_validate_invoices',
            label: t('customers.details.auto_validation.invoices'),
            description: t(form.auto_validate_invoices
                ? 'customers.form.states.enabled'
                : 'customers.form.states.disabled'),
        }]
        : []),
]);

const isCompanyClient = computed(() => form.client_type === CUSTOMER_CLIENT_TYPE_COMPANY);
const maxBirthDate = new Date().toISOString().slice(0, 10);
const contactSectionTitle = computed(() => (
    isCompanyClient.value
        ? t('customers.form.sections.main_contact')
        : t('customers.form.sections.contact_details')
));
const pageTitle = computed(() => t(isCreating
    ? 'customers.form.title.new'
    : 'customers.form.title.edit'));
const pageIntro = computed(() => t(isCreating
    ? 'customers.form.intro.new'
    : 'customers.form.intro.edit'));
const portalAccessDescription = computed(() => t(isCreating
    ? 'customers.form.section_help.portal_access_create'
    : 'customers.form.section_help.portal_access_edit'));
const cancelHref = computed(() => (
    isCreating
        ? route('customer.index')
        : route('customer.show', props.customer.id)
));

const performSubmit = ({ createAnother = false } = {}) => {
    const routeName = props.customer?.id ? 'customer.update' : 'customer.store';
    const routeParams = props.customer?.id ? props.customer.id : undefined;

    form
        .transform((data) => {
            const payload = { ...data };
            payload.birth_date = data.client_type === CUSTOMER_CLIENT_TYPE_INDIVIDUAL
                ? (data.birth_date || null)
                : null;
            payload.auto_accept_quotes = quotesFeatureEnabled.value && Boolean(data.auto_accept_quotes);
            payload.auto_validate_jobs = jobsFeatureEnabled.value && Boolean(data.auto_validate_jobs);
            payload.auto_validate_tasks = tasksFeatureEnabled.value && Boolean(data.auto_validate_tasks);
            payload.auto_validate_invoices = invoicesFeatureEnabled.value && Boolean(data.auto_validate_invoices);
            if (data.logo instanceof File) {
                payload.logo = data.logo;
            } else {
                delete payload.logo;
            }
            if (!payload.logo_icon) {
                delete payload.logo_icon;
            }
            if (!props.canManageNotes) {
                delete payload.description;
            }
            if (isCreating && createAnother) {
                payload.create_another = true;
            }
            return payload;
        })
        [props.customer?.id ? 'put' : 'post'](route(routeName, routeParams), {
            onSuccess: () => {
                if (isCreating && createAnother) {
                    form.reset();
                    form.clearErrors();
                    resetSearch();
                }
                if (isCreating && typeof window !== 'undefined') {
                    window.dispatchEvent(new CustomEvent('demo:customer_created'));
                }
            },
        });
};

const submit = () => performSubmit();
const submitAndCreateAnother = () => performSubmit({ createAnother: true });

const isEmpty = (value) => !String(value || '').trim();

const buildDemoEmail = () => {
    const accountEmail = page.props.auth?.user?.email || 'guided-demo@example.test';
    const domain = accountEmail.split('@')[1] || 'example.test';
    const token = Date.now().toString(36).slice(-6);
    return `guided-customer-${token}@${domain}`;
};

const shouldPrefillGuided = () => {
    if (!isGuidedDemo.value || !isCreating || demoPrefilled.value) {
        return false;
    }
    return isEmpty(form.first_name)
        && isEmpty(form.last_name)
        && isEmpty(form.email);
};

const prefillGuidedCustomer = () => {
    demoPrefilled.value = true;
    form.client_type = CUSTOMER_CLIENT_TYPE_COMPANY;
    if (isEmpty(form.first_name)) {
        form.first_name = 'Guided';
    }
    if (isEmpty(form.last_name)) {
        form.last_name = 'Customer';
    }
    if (isEmpty(form.email)) {
        form.email = buildDemoEmail();
    }
    if (isEmpty(form.company_name)) {
        form.company_name = 'Guided Demo Client';
    }
    if (isEmpty(form.registration_number)) {
        form.registration_number = 'GUIDED-DEMO';
    }
    if (isEmpty(form.industry)) {
        form.industry = 'Professional services';
    }
    if (isEmpty(form.phone)) {
        form.phone = '555-0102';
    }
    if (form.properties) {
        form.properties.street1 = form.properties.street1 || '320 Demo Street';
        form.properties.city = form.properties.city || 'Austin';
        form.properties.state = form.properties.state || 'TX';
        form.properties.zip = form.properties.zip || '73301';
        form.properties.country = form.properties.country || 'US';
    }
};

onMounted(() => {
    if (shouldPrefillGuided()) {
        prefillGuidedCustomer();
    }
});

const {
    query,
    suggestions,
    isSearching,
    searchAddress,
    selectAddress,
    resetSearch,
} = useGeoapifyAddressAutocomplete({
    onSelect: (details) => {
        assignGeoapifyAddress(form.properties, details);
    },
    onError: (error) => {
        console.error('Erreur lors de la recherche d\'adresse :', error);
    },
});

const addressSearchInput = ref(null);
const addressSuggestionButtons = ref([]);

const setAddressSuggestionButton = (element, index) => {
    addressSuggestionButtons.value[index] = element;
};

const focusAddressSuggestion = (index) => {
    const suggestionCount = suggestions.value.length;
    if (!suggestionCount) {
        return;
    }

    const nextIndex = (index + suggestionCount) % suggestionCount;
    addressSuggestionButtons.value[nextIndex]?.focus();
};

const chooseAddressSuggestion = (details) => {
    selectAddress(details);
    addressSearchInput.value?.focus();
};

const chooseFirstAddressSuggestion = () => {
    const firstSuggestion = suggestions.value[0];
    if (firstSuggestion) {
        chooseAddressSuggestion(firstSuggestion.details);
    }
};

const focusAddressSearch = () => {
    addressSearchInput.value?.focus();
};

const closeAddressSuggestions = () => {
    resetSearch(query.value);
    focusAddressSearch();
};

const handleCancelClick = (event) => {
    if (form.processing) {
        event.preventDefault();
    }
};
</script>
<template>

    <Head :title="pageTitle" />
    <AuthenticatedLayout>
        <div class="mx-auto mb-6 w-full max-w-6xl">
            <Link
                :href="cancelHref"
                :aria-disabled="form.processing"
                :class="form.processing ? 'pointer-events-none opacity-50' : ''"
                class="mb-3 inline-flex items-center gap-2 text-sm font-medium text-stone-500 transition hover:text-stone-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2 dark:text-neutral-400 dark:hover:text-white dark:focus-visible:ring-offset-neutral-900"
                @click="handleCancelClick"
            >
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="m15 18-6-6 6-6" />
                </svg>
                {{ $t('customers.actions.cancel') }}
            </Link>
            <div class="md:flex md:items-start md:justify-between md:gap-6">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-stone-900 dark:text-white">
                        {{ pageTitle }}
                    </h1>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-stone-500 dark:text-neutral-400">
                        {{ pageIntro }}
                    </p>
                </div>
                <p class="mt-2 shrink-0 text-xs text-stone-500 dark:text-neutral-500 md:mt-1">
                    {{ $t('customers.form.intro.required') }}
                </p>
            </div>
        </div>
        <form
            class="mx-auto w-full max-w-6xl space-y-5 pb-24"
            :aria-busy="form.processing"
            @submit.prevent="submit"
        >
            <div class="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(300px,.85fr)]">
                <div
                    class="flex flex-col overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex items-start gap-3 border-b border-stone-200 px-4 py-4 dark:border-neutral-700 md:px-6">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-user">
                            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                        <div>
                            <h2 class="font-semibold text-stone-900 dark:text-white">
                                {{ $t('customers.form.sections.client_details') }}
                            </h2>
                            <p class="mt-0.5 text-sm leading-5 text-stone-500 dark:text-neutral-400">
                                {{ $t('customers.form.section_help.client_details') }}
                            </p>
                        </div>
                    </div>
                    <div class="p-4 md:p-6">
                        <FloatingSelect
                            id="customer-client-type"
                            v-model="form.client_type"
                            :label="$t('customers.form.fields.client_type')"
                            :options="clientTypeOptions"
                            :required="true"
                            :aria-invalid="Boolean(form.errors.client_type)"
                            :aria-describedby="form.errors.client_type ? 'customer-client-type-error' : undefined"
                        />
                        <InputError id="customer-client-type-error" class="mt-1" :message="form.errors.client_type" />

                        <h2 v-if="isCompanyClient" class="pt-4 text-sm my-2 font-bold text-stone-800 dark:text-white">
                            {{ $t('customers.form.sections.company_details') }}
                        </h2>
                        <div v-if="isCompanyClient" class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <FloatingInput
                                    id="customer-company-name"
                                    v-model="form.company_name"
                                    autocomplete="organization"
                                    :label="$t('customers.form.fields.company_name')"
                                    :required="true"
                                    :aria-invalid="Boolean(form.errors.company_name)"
                                    :aria-describedby="form.errors.company_name ? 'customer-company-name-error' : undefined"
                                />
                                <InputError id="customer-company-name-error" class="mt-1" :message="form.errors.company_name" />
                            </div>
                            <div>
                                <FloatingInput
                                    id="customer-registration-number"
                                    v-model="form.registration_number"
                                    :label="$t('customers.form.fields.registration_number')"
                                    :aria-invalid="Boolean(form.errors.registration_number)"
                                    :aria-describedby="form.errors.registration_number ? 'customer-registration-number-error' : undefined"
                                />
                                <InputError id="customer-registration-number-error" class="mt-1" :message="form.errors.registration_number" />
                            </div>
                            <div class="md:col-span-2">
                                <FloatingInput
                                    id="customer-industry"
                                    v-model="form.industry"
                                    :label="$t('customers.form.fields.industry')"
                                    :aria-invalid="Boolean(form.errors.industry)"
                                    :aria-describedby="form.errors.industry ? 'customer-industry-error' : undefined"
                                />
                                <InputError id="customer-industry-error" class="mt-1" :message="form.errors.industry" />
                            </div>
                        </div>

                        <div class="mt-5 border-t border-stone-200 pt-5 dark:border-neutral-700">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-white">
                                {{ $t('customers.form.sections.profile') }}
                            </h2>
                            <p class="mt-1 text-xs leading-5 text-stone-500 dark:text-neutral-400">
                                {{ $t('customers.form.section_help.profile') }}
                            </p>
                        </div>
                        <CustomerMediaFields
                            v-model:logo="form.logo"
                            v-model:logoIcon="form.logo_icon"
                            class="mt-3"
                            :client-type="form.client_type"
                            :logo-error="form.errors.logo"
                            :logo-icon-error="form.errors.logo_icon"
                        />
                        <h2 class="pt-4 text-sm my-2 font-bold text-stone-800 dark:text-white">{{ contactSectionTitle }}</h2>
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <FloatingInput
                                    id="customer-first-name"
                                    v-model="form.first_name"
                                    autocomplete="given-name"
                                    :label="$t('customers.form.fields.first_name')"
                                    :required="true"
                                    :aria-invalid="Boolean(form.errors.first_name)"
                                    :aria-describedby="form.errors.first_name ? 'customer-first-name-error' : undefined"
                                />
                                <InputError id="customer-first-name-error" class="mt-1" :message="form.errors.first_name" />
                            </div>
                            <div>
                                <FloatingInput
                                    id="customer-last-name"
                                    v-model="form.last_name"
                                    autocomplete="family-name"
                                    :label="$t('customers.form.fields.last_name')"
                                    :required="true"
                                    :aria-invalid="Boolean(form.errors.last_name)"
                                    :aria-describedby="form.errors.last_name ? 'customer-last-name-error' : undefined"
                                />
                                <InputError id="customer-last-name-error" class="mt-1" :message="form.errors.last_name" />
                            </div>
                            <div>
                                <FloatingInput
                                    id="customer-phone"
                                    v-model="form.phone"
                                    type="tel"
                                    autocomplete="tel"
                                    :label="$t('customers.form.fields.phone')"
                                    :aria-invalid="Boolean(form.errors.phone)"
                                    :aria-describedby="form.errors.phone ? 'customer-phone-error' : undefined"
                                />
                                <InputError id="customer-phone-error" class="mt-1" :message="form.errors.phone" />
                            </div>
                            <div>
                                <FloatingInput
                                    id="customer-email"
                                    v-model="form.email"
                                    type="email"
                                    autocomplete="email"
                                    :label="$t('customers.form.fields.email')"
                                    :required="true"
                                    :aria-invalid="Boolean(form.errors.email)"
                                    :aria-describedby="form.errors.email ? 'customer-email-error' : undefined"
                                />
                                <InputError id="customer-email-error" class="mt-1" :message="form.errors.email" />
                            </div>
                            <div v-if="!isCompanyClient">
                                <FloatingInput
                                    id="customer-birth-date"
                                    v-model="form.birth_date"
                                    type="date"
                                    autocomplete="bday"
                                    :max="maxBirthDate"
                                    :label="$t('customers.form.fields.birth_date')"
                                    :aria-invalid="Boolean(form.errors.birth_date)"
                                    :aria-describedby="form.errors.birth_date ? 'customer-birth-date-error' : undefined"
                                />
                                <InputError id="customer-birth-date-error" class="mt-1" :message="form.errors.birth_date" />
                            </div>
                        </div>
                        <div class="mt-5 border-t border-stone-200 pt-5 dark:border-neutral-700">
                            <PreferenceToggleRow
                                id="customer-portal-access"
                                v-model="form.portal_access"
                                :label="$t('customers.form.fields.portal_access')"
                                :description="portalAccessDescription"
                                :described-by="form.errors.portal_access ? 'customer-portal-access-error' : ''"
                            />
                            <InputError id="customer-portal-access-error" class="mt-1" :message="form.errors.portal_access" />
                        </div>
                        <div v-if="hasAutoValidationFeatures" class="mt-5 border-t border-stone-200 pt-5 dark:border-neutral-700">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-white">
                                {{ $t('customers.form.sections.auto_validation') }}
                            </h2>
                            <p class="mt-1 text-xs leading-5 text-stone-500 dark:text-neutral-400">
                                {{ $t('customers.form.section_help.auto_validation') }}
                            </p>
                        </div>
                        <div v-if="hasAutoValidationFeatures" class="mt-2 flex flex-col gap-y-2">
                            <PreferenceToggleRow
                                v-for="option in autoValidationOptions"
                                :id="option.id"
                                :key="option.formKey"
                                v-model="form[option.formKey]"
                                :label="option.label"
                                :description="option.description"
                            />
                        </div>
                        <div class="mt-5 border-t border-stone-200 pt-5 dark:border-neutral-700">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-white">
                                {{ $t('customers.form.sections.additional_details') }}
                            </h2>
                            <p class="mt-1 text-xs leading-5 text-stone-500 dark:text-neutral-400">
                                {{ $t('customers.form.section_help.additional_details') }}
                            </p>
                        </div>
                        <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div v-if="canManageNotes" class="md:col-span-2">
                                <FloatingTextarea
                                    id="customer-description"
                                    v-model="form.description"
                                    :label="$t('customers.form.fields.description')"
                                    :aria-invalid="Boolean(form.errors.description)"
                                    :aria-describedby="form.errors.description ? 'customer-description-error' : undefined"
                                />
                                <InputError id="customer-description-error" class="mt-1" :message="form.errors.description" />
                            </div>
                            <div>
                                <FloatingInput
                                    id="customer-referred-by"
                                    v-model="form.refer_by"
                                    :label="$t('customers.form.fields.referred_by')"
                                    :aria-invalid="Boolean(form.errors.refer_by)"
                                    :aria-describedby="form.errors.refer_by ? 'customer-referred-by-error' : undefined"
                                />
                                <InputError id="customer-referred-by-error" class="mt-1" :message="form.errors.refer_by" />
                            </div>
                            <div>
                                <FloatingInput
                                    id="customer-discount-rate"
                                    v-model="form.discount_rate"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    :label="$t('customers.form.fields.discount_rate')"
                                    :aria-invalid="Boolean(form.errors.discount_rate)"
                                    :aria-describedby="form.errors.discount_rate
                                        ? 'customer-discount-rate-hint customer-discount-rate-error'
                                        : 'customer-discount-rate-hint'"
                                />
                                <p id="customer-discount-rate-hint" class="mt-1 text-xs leading-5 text-stone-500 dark:text-neutral-400">
                                    {{ $t('customers.form.fields.discount_rate_hint') }}
                                </p>
                                <InputError id="customer-discount-rate-error" class="mt-1" :message="form.errors.discount_rate" />
                            </div>
                        </div>
                    </div>
                </div>
                <div
                    class="flex flex-col overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex items-start gap-3 border-b border-stone-200 px-4 py-4 dark:border-neutral-700 md:px-5">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-house">
                            <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" />
                            <path
                                d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                        </svg>
                        <div>
                            <h2 class="font-semibold text-stone-900 dark:text-white">
                                {{ jobsFeatureEnabled ? $t('customers.properties.title') : $t('customers.form.sections.location') }}
                            </h2>
                            <p class="mt-0.5 text-sm leading-5 text-stone-500 dark:text-neutral-400">
                                {{ $t('customers.form.section_help.location') }}
                            </p>
                        </div>
                    </div>
                    <div class="p-4 md:p-5">

                        <div class="max-w-full mb-4">
                            <!-- SearchBox -->
                            <div class="relative">
                                <!-- Input Field -->
                                <div class="relative">
                                    <div
                                        class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                                        <svg class="shrink-0 size-4 text-stone-400 dark:text-white/60"
                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="11" cy="11" r="8"></circle>
                                            <path d="m21 21-4.3-4.3"></path>
                                        </svg>
                                    </div>
                                    <input
                                        id="customer-address-search"
                                        ref="addressSearchInput"
                                        v-model="query"
                                        type="search"
                                        role="combobox"
                                        autocomplete="off"
                                        class="block w-full rounded-lg border-stone-200 py-3 pe-10 ps-10 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                        :aria-label="$t('customers.form.fields.search_address')"
                                        :aria-expanded="suggestions.length > 0"
                                        aria-autocomplete="list"
                                        aria-controls="customer-address-suggestions"
                                        :placeholder="$t('customers.form.fields.search_address')"
                                        @input="searchAddress"
                                        @keydown.down.prevent="focusAddressSuggestion(0)"
                                        @keydown.enter.prevent="chooseFirstAddressSuggestion"
                                        @keydown.esc.prevent="closeAddressSuggestions"
                                    />
                                    <svg
                                        v-if="isSearching"
                                        class="absolute inset-y-0 end-3 my-auto size-4 animate-spin text-green-600"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        aria-hidden="true"
                                    >
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z" />
                                    </svg>
                                </div>

                                <p v-if="isSearching" class="sr-only" role="status" aria-live="polite">
                                    {{ $t('global_search.loading') }}
                                </p>

                                <!-- Suggestions Dropdown -->
                                <div
                                    v-if="suggestions.length"
                                    id="customer-address-suggestions"
                                    role="listbox"
                                    class="absolute z-50 mt-1 w-full rounded-lg border border-stone-200 bg-white shadow-xl dark:border-neutral-700 dark:bg-neutral-800"
                                >
                                    <div
                                        class="max-h-[300px] p-2 overflow-y-auto overflow-hidden [&::-webkit-scrollbar]:w-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                                        <button
                                            v-for="(suggestion, index) in suggestions"
                                            :key="suggestion.id"
                                            :ref="(element) => setAddressSuggestionButton(element, index)"
                                            type="button"
                                            role="option"
                                            :aria-selected="false"
                                            class="flex w-full items-start rounded-md px-3 py-2 text-left text-sm text-stone-800 transition hover:bg-stone-100 focus:bg-stone-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 dark:text-neutral-200 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                            @click="chooseAddressSuggestion(suggestion.details)"
                                            @keydown.down.prevent="focusAddressSuggestion(index + 1)"
                                            @keydown.up.prevent="index === 0 ? focusAddressSearch() : focusAddressSuggestion(index - 1)"
                                            @keydown.esc.prevent="closeAddressSuggestions"
                                        >
                                            {{ suggestion.label }}
                                        </button>
                                    </div>
                                </div>
                                <!-- End Suggestions Dropdown -->
                            </div>
                            <!-- End SearchBox -->
                        </div>
                        <p class="mb-3 text-xs leading-5 text-stone-500 dark:text-neutral-400">
                            {{ $t('customers.form.fields.manual_address_hint') }}
                        </p>
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div class="md:col-span-2">
                                <FloatingInput
                                    id="customer-street-1"
                                    v-model="form.properties.street1"
                                    autocomplete="address-line1"
                                    :label="$t('customers.properties.fields.street1')"
                                    :aria-invalid="Boolean(form.errors['properties.street1'])"
                                    :aria-describedby="form.errors['properties.street1'] ? 'customer-street-1-error' : undefined"
                                />
                                <InputError id="customer-street-1-error" class="mt-1" :message="form.errors['properties.street1']" />
                            </div>
                            <div class="md:col-span-2">
                                <FloatingInput
                                    id="customer-street-2"
                                    v-model="form.properties.street2"
                                    autocomplete="address-line2"
                                    :label="$t('customers.properties.fields.street2')"
                                    :aria-invalid="Boolean(form.errors['properties.street2'])"
                                    :aria-describedby="form.errors['properties.street2'] ? 'customer-street-2-error' : undefined"
                                />
                                <InputError id="customer-street-2-error" class="mt-1" :message="form.errors['properties.street2']" />
                            </div>
                            <div>
                                <FloatingInput
                                    id="customer-city"
                                    v-model="form.properties.city"
                                    autocomplete="address-level2"
                                    :label="$t('customers.properties.fields.city')"
                                    :aria-invalid="Boolean(form.errors['properties.city'])"
                                    :aria-describedby="form.errors['properties.city'] ? 'customer-city-error' : undefined"
                                />
                                <InputError id="customer-city-error" class="mt-1" :message="form.errors['properties.city']" />
                            </div>
                            <div>
                                <FloatingInput
                                    id="customer-state"
                                    v-model="form.properties.state"
                                    autocomplete="address-level1"
                                    :label="$t('customers.properties.fields.state')"
                                    :aria-invalid="Boolean(form.errors['properties.state'])"
                                    :aria-describedby="form.errors['properties.state'] ? 'customer-state-error' : undefined"
                                />
                                <InputError id="customer-state-error" class="mt-1" :message="form.errors['properties.state']" />
                            </div>
                            <div>
                                <FloatingInput
                                    id="customer-zip"
                                    v-model="form.properties.zip"
                                    autocomplete="postal-code"
                                    :label="$t('customers.properties.fields.zip')"
                                    :aria-invalid="Boolean(form.errors['properties.zip'])"
                                    :aria-describedby="form.errors['properties.zip'] ? 'customer-zip-error' : undefined"
                                />
                                <InputError id="customer-zip-error" class="mt-1" :message="form.errors['properties.zip']" />
                            </div>
                            <div>
                                <FloatingInput
                                    id="customer-country"
                                    v-model="form.properties.country"
                                    autocomplete="country-name"
                                    :label="$t('customers.properties.fields.country')"
                                    :aria-invalid="Boolean(form.errors['properties.country'])"
                                    :aria-describedby="form.errors['properties.country'] ? 'customer-country-error' : undefined"
                                />
                                <InputError id="customer-country-error" class="mt-1" :message="form.errors['properties.country']" />
                            </div>
                        </div>

                        <!-- Input Group -->
                        <div v-if="invoicesFeatureEnabled" class="flex flex-col sm:flex-row sm:items-center gap-2 mt-4">
                            <div class="flex items-center">
                                <input id="customer-billing-same-as-physical" v-model="form.billing_same_as_physical" type="checkbox"
                                    class="shrink-0 size-3.5 border-stone-300 rounded text-green-600 focus:ring-green-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-stone-800"
                                    :aria-invalid="Boolean(form.errors.billing_same_as_physical)"
                                    :aria-describedby="form.errors.billing_same_as_physical ? 'customer-billing-same-as-physical-error' : undefined"
                                >
                                <label for="customer-billing-same-as-physical" class="text-sm text-stone-500 ms-2 dark:text-neutral-500">
                                    {{ jobsFeatureEnabled
                                        ? $t('customers.form.billing.same_as_property')
                                        : $t('customers.form.billing.same_as_address') }}
                                </label>
                            </div>
                        </div>
                        <InputError id="customer-billing-same-as-physical-error" class="mt-1" :message="form.errors.billing_same_as_physical" />
                        <!-- End Input Group -->
                    </div>
                </div>
            </div>
            <div v-if="invoicesFeatureEnabled && (jobsFeatureEnabled || tasksFeatureEnabled)"
                class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div
                    class="flex flex-col">
                    <div class="flex items-start gap-3 border-b border-stone-200 px-4 py-4 dark:border-neutral-700 md:px-6">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-receipt">
                            <path d="M4 2h16v20l-4-2-4 2-4-2-4 2V2z" />
                            <path d="M16 8h-8" />
                            <path d="M16 12h-8" />
                            <path d="M10 16h-2" />
                        </svg>
                        <div>
                            <h2 class="font-semibold text-stone-900 dark:text-white">
                                {{ $t('customers.form.sections.billing_preferences') }}
                            </h2>
                            <p class="mt-0.5 text-sm leading-5 text-stone-500 dark:text-neutral-400">
                                {{ $t('customers.form.section_help.billing_preferences') }}
                            </p>
                        </div>
                    </div>
                    <div class="space-y-3 p-4 md:p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <FloatingSelect
                                    id="customer-billing-mode"
                                    v-model="form.billing_mode"
                                    :label="$t('customers.form.billing.mode')"
                                    :options="billingModes"
                                    :aria-invalid="Boolean(form.errors.billing_mode)"
                                    :aria-describedby="form.errors.billing_mode ? 'customer-billing-mode-error' : undefined"
                                />
                                <InputError id="customer-billing-mode-error" class="mt-1" :message="form.errors.billing_mode" />
                            </div>
                            <div>
                                <FloatingSelect
                                    id="customer-billing-grouping"
                                    v-model="form.billing_grouping"
                                    :label="$t('customers.form.billing.grouping')"
                                    :options="billingGroupings"
                                    :aria-invalid="Boolean(form.errors.billing_grouping)"
                                    :aria-describedby="form.errors.billing_grouping ? 'customer-billing-grouping-error' : undefined"
                                />
                                <InputError id="customer-billing-grouping-error" class="mt-1" :message="form.errors.billing_grouping" />
                            </div>
                        </div>
                        <div v-if="form.billing_mode === 'per_segment' || form.billing_grouping === 'periodic' || form.billing_mode === 'deferred'"
                            class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <FloatingSelect
                                    id="customer-billing-cycle"
                                    v-model="form.billing_cycle"
                                    :label="$t('customers.form.billing.cycle')"
                                    :options="billingCycles"
                                    :aria-invalid="Boolean(form.errors.billing_cycle)"
                                    :aria-describedby="form.errors.billing_cycle ? 'customer-billing-cycle-error' : undefined"
                                />
                                <InputError id="customer-billing-cycle-error" class="mt-1" :message="form.errors.billing_cycle" />
                            </div>
                            <div v-if="form.billing_mode === 'deferred'">
                                <FloatingInput
                                    id="customer-billing-delay-days"
                                    v-model="form.billing_delay_days"
                                    type="number"
                                    min="0"
                                    max="365"
                                    step="1"
                                    :label="$t('customers.form.billing.delay_days')"
                                    :aria-invalid="Boolean(form.errors.billing_delay_days)"
                                    :aria-describedby="form.errors.billing_delay_days ? 'customer-billing-delay-days-error' : undefined"
                                />
                                <InputError id="customer-billing-delay-days-error" class="mt-1" :message="form.errors.billing_delay_days" />
                            </div>
                        </div>
                        <div v-if="form.billing_mode === 'deferred'">
                            <FloatingInput
                                id="customer-billing-date-rule"
                                v-model="form.billing_date_rule"
                                :label="$t('customers.form.billing.date_rule')"
                                :aria-invalid="Boolean(form.errors.billing_date_rule)"
                                :aria-describedby="form.errors.billing_date_rule ? 'customer-billing-date-rule-error' : undefined"
                            />
                            <InputError id="customer-billing-date-rule-error" class="mt-1" :message="form.errors.billing_date_rule" />
                        </div>
                    </div>
                </div>
            </div>
            <FormActionBar :action-columns="isCreating ? 2 : 1">
                <template #hint>
                    <p>
                        {{ $t('customers.form.actions_hint') }}
                    </p>
                </template>
                <template #secondary>
                    <Link
                        :href="cancelHref"
                        :aria-disabled="form.processing"
                        :class="form.processing ? 'pointer-events-none opacity-50' : ''"
                        class="mt-2 inline-flex w-full items-center justify-center rounded-lg border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium text-stone-700 shadow-sm transition hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700 dark:focus-visible:ring-offset-neutral-900 md:mt-0 md:w-auto"
                        @click="handleCancelClick"
                    >
                        {{ $t('customers.actions.cancel') }}
                    </Link>
                </template>

                <button
                    v-if="isCreating"
                    type="button"
                    :disabled="form.processing"
                    class="action-feedback inline-flex w-full items-center justify-center rounded-lg border border-green-600 px-4 py-2.5 text-sm font-medium text-green-700 transition hover:border-green-700 hover:bg-green-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 dark:text-green-400 dark:hover:bg-green-500/10 dark:focus-visible:ring-offset-neutral-900"
                    @click="submitAndCreateAnother"
                >
                    {{ $t('customers.form.actions.save_create_another') }}
                </button>
                <button
                    type="submit"
                    data-testid="demo-customer-save"
                    :disabled="form.processing"
                    class="action-feedback inline-flex w-full items-center justify-center gap-2 rounded-lg border border-transparent bg-green-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-green-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-60 dark:focus-visible:ring-offset-neutral-900"
                >
                    <svg v-if="form.processing" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z" />
                    </svg>
                    {{ form.processing
                        ? $t('customers.form.actions.saving')
                        : (isCreating
                            ? $t('customers.form.actions.save_client')
                            : $t('customers.form.actions.update_client')) }}
                </button>
            </FormActionBar>
        </form>
    </AuthenticatedLayout>
</template>
