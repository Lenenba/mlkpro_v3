<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import DropzoneInput from '@/Components/DropzoneInput.vue';
import InputError from '@/Components/InputError.vue';
import { companyIconPresets, defaultCompanyIcon } from '@/utils/iconPresets';
import { Link, useForm, Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';


const props = defineProps({
    customer: Object,
});

const { t } = useI18n();

const isCreating = !props.customer?.id;
const page = usePage();
const isGuidedDemo = computed(() => Boolean(page.props.demo?.is_guided));
const demoPrefilled = ref(false);

const salutations = computed(() => ([
    { id: 'Mr', name: t('customers.form.salutations.mr') },
    { id: 'Mrs', name: t('customers.form.salutations.mrs') },
    { id: 'Miss', name: t('customers.form.salutations.miss') },
]));

const billingModes = computed(() => ([
    { id: 'per_task', name: t('customers.form.billing_modes.per_task') },
    { id: 'per_segment', name: t('customers.form.billing_modes.per_segment') },
    { id: 'end_of_job', name: t('customers.form.billing_modes.end_of_job') },
    { id: 'deferred', name: t('customers.form.billing_modes.deferred') },
]));

const billingGroupings = computed(() => ([
    { id: 'single', name: t('customers.form.billing_groupings.single') },
    { id: 'periodic', name: t('customers.form.billing_groupings.periodic') },
]));

const billingCycles = computed(() => ([
    { id: 'weekly', name: t('customers.form.billing_cycles.weekly') },
    { id: 'biweekly', name: t('customers.form.billing_cycles.biweekly') },
    { id: 'monthly', name: t('customers.form.billing_cycles.monthly') },
    { id: 'every_n_tasks', name: t('customers.form.billing_cycles.every_n_tasks') },
]));

const isCompanyIcon = (value) => companyIconPresets.includes(value);
const initialLogoPath = props.customer?.logo_url || props.customer?.logo || '';
const initialLogoIcon = isCompanyIcon(props.customer?.logo)
    ? props.customer.logo
    : (isCompanyIcon(initialLogoPath) ? initialLogoPath : '');
const defaultLogoIcon = initialLogoIcon || (isCreating ? defaultCompanyIcon : '');
const initialLogoPreview = defaultLogoIcon ? '' : initialLogoPath;

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
    first_name: props.customer?.first_name || '',
    last_name: props.customer?.last_name || '',
    email: props.customer?.email || '',
    portal_access: props.customer?.portal_access ?? true,
    company_name: props.customer?.company_name || '',
    billing_same_as_physical: props.customer?.billing_same_as_physical || false,
    logo: initialLogoPreview,
    logo_icon: defaultLogoIcon,
    description: props.customer?.description || '',
    refer_by: props.customer?.refer_by || '',
    salutation: props.customer?.salutation || '',
    phone: props.customer?.phone || '',
    properties: resolvePrimaryProperty(),
    billing_mode: props.customer?.billing_mode || 'end_of_job',
    billing_cycle: props.customer?.billing_cycle || '',
    billing_grouping: props.customer?.billing_grouping || 'single',
    billing_delay_days: props.customer?.billing_delay_days ?? '',
    billing_date_rule: props.customer?.billing_date_rule || '',
    discount_rate: props.customer?.discount_rate ?? '',
    auto_accept_quotes: props.customer?.auto_accept_quotes ?? false,
    auto_validate_jobs: props.customer?.auto_validate_jobs ?? false,
    auto_validate_tasks: props.customer?.auto_validate_tasks ?? false,
    auto_validate_invoices: props.customer?.auto_validate_invoices ?? false,
});

const selectCompanyIcon = (icon) => {
    form.logo_icon = icon;
    form.logo = null;
};

const clearCompanyIcon = () => {
    form.logo_icon = '';
};

watch(() => form.logo, (value) => {
    if (value instanceof File) {
        form.logo_icon = '';
    }
});

const submit = () => {
    const routeName = props.customer?.id ? 'customer.update' : 'customer.store';
    const routeParams = props.customer?.id ? props.customer.id : undefined;

    form
        .transform((data) => {
            const payload = { ...data };
            if (data.logo instanceof File) {
                payload.logo = data.logo;
            } else {
                delete payload.logo;
            }
            if (!payload.logo_icon) {
                delete payload.logo_icon;
            }
            return payload;
        })
        [props.customer?.id ? 'put' : 'post'](route(routeName, routeParams), {
            onSuccess: () => {
                if (isCreating && typeof window !== 'undefined') {
                    window.dispatchEvent(new CustomEvent('demo:customer_created'));
                }
            },
        });
};

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
        && isEmpty(form.email)
        && isEmpty(form.salutation);
};

const prefillGuidedCustomer = () => {
    demoPrefilled.value = true;
    if (isEmpty(form.salutation)) {
        form.salutation = 'Mr';
    }
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

const query = ref('');
const suggestions = ref([]);
const isSearching = ref(false);
const geoapifyKey = import.meta.env.VITE_GEOAPIFY_KEY;

const searchAddress = async () => {
    if (query.value.length < 2) {
        suggestions.value = [];
        return;
    }

    if (!geoapifyKey) {
        suggestions.value = [];
        return;
    }

    isSearching.value = true;
    try {
        const url = new URL('https://api.geoapify.com/v1/geocode/autocomplete');
        url.search = new URLSearchParams({
            text: query.value,
            apiKey: geoapifyKey,
            limit: '5',
            filter: 'countrycode:ca,us',
        }).toString();

        const response = await fetch(url.toString());
        if (!response.ok) {
            throw new Error(`Geoapify request failed: ${response.status}`);
        }

        const data = await response.json();
        suggestions.value = (data.features || []).map((feature) => ({
            id: feature.properties?.place_id || feature.properties?.formatted || feature.properties?.name,
            label: feature.properties?.formatted || feature.properties?.name || '',
            details: feature.properties || {},
        }));
    } catch (error) {
        console.error('Erreur lors de la recherche d\'adresse :', error);
    } finally {
        isSearching.value = false;
    }
};

const selectAddress = (details) => {
    const address = details || {};
    const streetParts = [];
    if (address.house_number) {
        streetParts.push(address.house_number);
    }
    if (address.street) {
        streetParts.push(address.street);
    }
    const city = address.city || address.town || address.village || address.hamlet || address.suburb;

    form.properties.street1 = streetParts.join(' ').trim();
    form.properties.street2 = '';
    form.properties.city = city || '';
    form.properties.state = address.state || address.county || address.region || '';
    form.properties.zip = address.postcode || '';
    form.properties.country = address.country || '';
    suggestions.value = [];
    query.value = details.formatted || details.name || query.value;
};
</script>
<template>

    <Head :title="isCreating ? $t('customers.form.title.new') : $t('customers.form.title.edit')" />
    <AuthenticatedLayout>
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-1 md:gap-3 lg:gap-1 ">
            <div></div>
            <div>
                <h1 class="text-xl font-bold text-stone-800 dark:text-white">
                    {{ isCreating ? $t('customers.form.title.new') : $t('customers.form.title.edit') }}
                </h1>
            </div>
            <div></div>
            <div></div>

        </div>
        <form @submit.prevent="submit">
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-1 md:gap-3 lg:gap-1 ">
                <div></div>
                <div
                    class="flex flex-col bg-white border shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70">
                    <div class="flex flex-row  border-b rounded-t-sm py-3 px-4 md:px-5 dark:border-neutral-700">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-user">
                            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                        <h3 class="text-lg  ml-2 font-bold text-stone-800 dark:text-white">
                            {{ $t('customers.form.sections.client_details') }}
                        </h3>
                    </div>
                    <div class="p-4 md:p-5">
                        <div class="flex flex-row">
                            <FloatingSelect v-model="form.salutation" class="w-1/5" :required="true"
                                :label="$t('customers.form.fields.title')" :options="salutations" />
                            <FloatingInput v-model="form.first_name" :label="$t('customers.form.fields.first_name')" class="w-2/5" :required="true" />
                            <FloatingInput v-model="form.last_name" :label="$t('customers.form.fields.last_name')" class="w-2/5" :required="true" />
                        </div>
                        <FloatingInput v-model="form.company_name" :label="$t('customers.form.fields.company_name')" />
                        <div class="mt-4 space-y-2">
                            <label class="text-sm font-semibold text-stone-800 dark:text-white">{{ $t('customers.form.fields.company_logo') }}</label>
                            <DropzoneInput v-model="form.logo" :label="$t('customers.form.fields.upload_company_logo')" />
                            <InputError class="mt-1" :message="form.errors.logo" />
                            <div class="mt-3 space-y-2">
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('customers.form.fields.choose_company_icon') }}
                                </p>
                                <div class="grid grid-cols-4 gap-2">
                                    <button
                                        v-for="icon in companyIconPresets"
                                        :key="icon"
                                        type="button"
                                        @click="selectCompanyIcon(icon)"
                                        class="relative flex items-center justify-center rounded-sm border border-stone-200 bg-white p-2 transition hover:border-green-500 dark:border-neutral-700 dark:bg-neutral-900"
                                        :class="form.logo_icon === icon ? 'ring-2 ring-green-500 border-green-500' : ''"
                                    >
                                        <img :src="icon" :alt="$t('customers.form.fields.company_icon_alt')" class="size-10" />
                                        <span
                                            v-if="icon === defaultCompanyIcon"
                                            class="absolute top-1 right-1 rounded-full bg-green-600 px-1.5 py-0.5 text-[10px] font-semibold text-white"
                                        >
                                            {{ $t('customers.form.fields.default_icon') }}
                                        </span>
                                    </button>
                                </div>
                                <div v-if="form.logo_icon" class="flex justify-end">
                                    <button type="button" @click="clearCompanyIcon"
                                        class="text-xs font-semibold text-stone-600 hover:text-stone-800 dark:text-neutral-400 dark:hover:text-neutral-200">
                                        {{ $t('customers.form.fields.clear_icon') }}
                                    </button>
                                </div>
                                <InputError class="mt-1" :message="form.errors.logo_icon" />
                            </div>
                        </div>
                        <h2 class="pt-4 text-sm  my-2 font-bold text-stone-800 dark:text-white">{{ $t('customers.form.sections.contact_details') }}</h2>
                        <FloatingInput v-model="form.phone" :label="$t('customers.form.fields.phone')" />
                        <FloatingInput v-model="form.email" :label="$t('customers.form.fields.email')" :required="true" />
                        <div class="mt-3 flex items-start gap-2">
                            <input id="customer-portal-access" type="checkbox" v-model="form.portal_access"
                                class="mt-1 size-4 rounded border-stone-300 text-green-600 focus:ring-green-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:checked:bg-green-500 dark:checked:border-green-500" />
                            <div>
                                <label for="customer-portal-access" class="text-sm text-stone-800 dark:text-neutral-200">
                                    {{ $t('customers.form.fields.portal_access') }}
                                </label>
                            </div>
                        </div>
                        <h2 class="pt-4 text-sm  my-2 font-bold text-stone-800 dark:text-white">{{ $t('customers.form.sections.auto_validation') }}</h2>
                        <div class="-mx-3 flex flex-col gap-y-1">
                            <label for="customer-auto-accept-quotes"
                                class="py-2 px-3 group flex justify-between items-center gap-x-3 cursor-pointer hover:bg-stone-100 rounded-sm dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                <span class="grow">
                                    <span class="flex items-center gap-x-3">
                                        <span
                                            class="shrink-0 size-11 inline-flex justify-center items-center bg-stone-100 text-stone-800 rounded-full group-hover:bg-stone-200 group-focus:bg-stone-200 dark:bg-neutral-700 dark:text-neutral-300 dark:bg-neutral-800 dark:group-hover:bg-neutral-700 dark:group-focus:bg-neutral-700">
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2Z" />
                                                <path d="M14 2v6h6" />
                                                <path d="M8 13h8" />
                                                <path d="M8 17h5" />
                                            </svg>
                                        </span>
                                        <span class="grow">
                                            <span class="block text-sm text-stone-800 dark:text-neutral-200">
                                                {{ $t('customers.form.auto_accept_quotes') }}
                                            </span>
                                        </span>
                                    </span>
                                </span>

                                <input type="checkbox" id="customer-auto-accept-quotes" v-model="form.auto_accept_quotes"
                                    class="relative w-11 h-6 p-px bg-stone-100 border-transparent text-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none checked:bg-none checked:text-green-600 checked:border-green-600 focus:checked:border-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-900

                                before:inline-block before:size-5 before:bg-white checked:before:bg-white before:translate-x-0 checked:before:translate-x-full before:rounded-full before:shadow before:transform before:ring-0 before:transition before:ease-in-out before:duration-200 dark:before:bg-neutral-400 dark:checked:before:bg-white">
                            </label>
                            <label for="customer-auto-validate-jobs"
                                class="py-2 px-3 group flex justify-between items-center gap-x-3 cursor-pointer hover:bg-stone-100 rounded-sm dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                <span class="grow">
                                    <span class="flex items-center gap-x-3">
                                        <span
                                            class="shrink-0 size-11 inline-flex justify-center items-center bg-stone-100 text-stone-800 rounded-full group-hover:bg-stone-200 group-focus:bg-stone-200 dark:bg-neutral-700 dark:text-neutral-300 dark:bg-neutral-800 dark:group-hover:bg-neutral-700 dark:group-focus:bg-neutral-700">
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round">
                                                <rect width="20" height="14" x="2" y="7" rx="2" />
                                                <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2" />
                                                <path d="M2 13h20" />
                                            </svg>
                                        </span>
                                        <span class="grow">
                                            <span class="block text-sm text-stone-800 dark:text-neutral-200">
                                                {{ $t('customers.details.auto_validation.jobs') }}
                                            </span>
                                        </span>
                                    </span>
                                </span>

                                <input type="checkbox" id="customer-auto-validate-jobs" v-model="form.auto_validate_jobs"
                                    class="relative w-11 h-6 p-px bg-stone-100 border-transparent text-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none checked:bg-none checked:text-green-600 checked:border-green-600 focus:checked:border-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-900

                                before:inline-block before:size-5 before:bg-white checked:before:bg-white before:translate-x-0 checked:before:translate-x-full before:rounded-full before:shadow before:transform before:ring-0 before:transition before:ease-in-out before:duration-200 dark:before:bg-neutral-400 dark:checked:before:bg-white">
                            </label>
                            <label for="customer-auto-validate-tasks"
                                class="py-2 px-3 group flex justify-between items-center gap-x-3 cursor-pointer hover:bg-stone-100 rounded-sm dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                <span class="grow">
                                    <span class="flex items-center gap-x-3">
                                        <span
                                            class="shrink-0 size-11 inline-flex justify-center items-center bg-stone-100 text-stone-800 rounded-full group-hover:bg-stone-200 group-focus:bg-stone-200 dark:bg-neutral-700 dark:text-neutral-300 dark:bg-neutral-800 dark:group-hover:bg-neutral-700 dark:group-focus:bg-neutral-700">
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M9 11l3 3L22 4" />
                                                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
                                            </svg>
                                        </span>
                                        <span class="grow">
                                            <span class="block text-sm text-stone-800 dark:text-neutral-200">
                                                {{ $t('customers.details.auto_validation.tasks') }}
                                            </span>
                                        </span>
                                    </span>
                                </span>

                                <input type="checkbox" id="customer-auto-validate-tasks" v-model="form.auto_validate_tasks"
                                    class="relative w-11 h-6 p-px bg-stone-100 border-transparent text-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none checked:bg-none checked:text-green-600 checked:border-green-600 focus:checked:border-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-900

                                before:inline-block before:size-5 before:bg-white checked:before:bg-white before:translate-x-0 checked:before:translate-x-full before:rounded-full before:shadow before:transform before:ring-0 before:transition before:ease-in-out before:duration-200 dark:before:bg-neutral-400 dark:checked:before:bg-white">
                            </label>
                            <label for="customer-auto-validate-invoices"
                                class="py-2 px-3 group flex justify-between items-center gap-x-3 cursor-pointer hover:bg-stone-100 rounded-sm dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                <span class="grow">
                                    <span class="flex items-center gap-x-3">
                                        <span
                                            class="shrink-0 size-11 inline-flex justify-center items-center bg-stone-100 text-stone-800 rounded-full group-hover:bg-stone-200 group-focus:bg-stone-200 dark:bg-neutral-700 dark:text-neutral-300 dark:bg-neutral-800 dark:group-hover:bg-neutral-700 dark:group-focus:bg-neutral-700">
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M4 2h16v20l-4-2-4 2-4-2-4 2V2z" />
                                                <path d="M8 6h8" />
                                                <path d="M8 10h8" />
                                                <path d="M8 14h6" />
                                            </svg>
                                        </span>
                                        <span class="grow">
                                            <span class="block text-sm text-stone-800 dark:text-neutral-200">
                                                {{ $t('customers.details.auto_validation.invoices') }}
                                            </span>
                                        </span>
                                    </span>
                                </span>

                                <input type="checkbox" id="customer-auto-validate-invoices" v-model="form.auto_validate_invoices"
                                    class="relative w-11 h-6 p-px bg-stone-100 border-transparent text-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none checked:bg-none checked:text-green-600 checked:border-green-600 focus:checked:border-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-900

                                before:inline-block before:size-5 before:bg-white checked:before:bg-white before:translate-x-0 checked:before:translate-x-full before:rounded-full before:shadow before:transform before:ring-0 before:transition before:ease-in-out before:duration-200 dark:before:bg-neutral-400 dark:checked:before:bg-white">
                            </label>
                        </div>
                        <h2 class="pt-4 text-sm  my-2 font-bold text-stone-800 dark:text-white">{{ $t('customers.form.sections.additional_details') }}</h2>
                        <FloatingTextarea v-model="form.description" :label="$t('customers.form.fields.description')" />
                        <FloatingInput v-model="form.refer_by" :label="$t('customers.form.fields.referred_by')" />
                        <FloatingInput v-model="form.discount_rate" type="number" :label="$t('customers.form.fields.discount_rate')" />
                    </div>
                </div>
                <div
                    class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70">
                    <div class="flex flex-row border-b rounded-t-sm py-3 px-4 md:px-5 dark:border-neutral-700">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-house">
                            <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" />
                            <path
                                d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                        </svg>
                        <h3 class="text-lg  ml-2 font-bold text-stone-800 dark:text-white">
                            {{ $t('customers.properties.title') }}
                        </h3>
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
                                    <input v-model="query" @input="searchAddress"
                                        class="py-3 ps-10 pe-4 block w-full border-stone-200 rounded-sm text-sm focus:border-green-600 focus:ring-green-600"
                                        type="text" role="combobox" aria-expanded="false"
                                        :placeholder="$t('customers.form.fields.search_address')" />
                                </div>

                                <!-- Suggestions Dropdown -->
                                <div v-if="suggestions.length"
                                    class="absolute z-50 w-full bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:bg-neutral-800">
                                    <div
                                        class="max-h-[300px] p-2 overflow-y-auto overflow-hidden [&::-webkit-scrollbar]:w-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                                        <div v-for="suggestion in suggestions" :key="suggestion.id"
                                            class="py-2 px-3 flex items-center gap-x-3 hover:bg-stone-100 rounded-sm dark:hover:bg-neutral-700 cursor-pointer"
                                            @click="selectAddress(suggestion.details)">
                                            <span class="text-sm text-stone-800 dark:text-neutral-200">{{
                                                suggestion.label
                                                }}</span>
                                        </div>
                                    </div>
                                </div>
                                <!-- End Suggestions Dropdown -->
                            </div>
                            <!-- End SearchBox -->
                        </div>
                        <FloatingInput v-model="form.properties.street1" :label="$t('customers.properties.fields.street1')" />
                        <FloatingInput v-model="form.properties.street2" :label="$t('customers.properties.fields.street2')" />
                        <div class="flex flex-row">
                            <FloatingInput v-model="form.properties.city" :label="$t('customers.properties.fields.city')" class="w-full" />
                            <FloatingInput v-model="form.properties.state" :label="$t('customers.properties.fields.state')" class="w-full" />
                        </div>
                        <div class="flex flex-row">
                            <FloatingInput v-model="form.properties.zip" :label="$t('customers.properties.fields.zip')" class="w-full" />
                            <FloatingInput v-model="form.properties.country" :label="$t('customers.properties.fields.country')" class="w-full" />
                        </div>

                        <!-- Input Group -->
                        <div class="flex flex-col sm:flex-row sm:items-center gap-2 mt-4">
                            <div class="flex items-center">
                                <input type="checkbox" v-model="form.billing_same_as_physical"
                                    class="shrink-0 size-3.5 border-stone-300 rounded text-green-600 focus:ring-green-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-stone-800"
                                    id="hs-pro-danscch">
                                <label for="hs-pro-danscch" class="text-sm text-stone-500 ms-2 dark:text-neutral-500">
                                    {{ $t('customers.form.billing.same_as_property') }}
                                </label>
                            </div>
                        </div>
                        <!-- End Input Group -->
                    </div>
                </div>
                <div></div>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-1 md:gap-3 lg:gap-1 mt-4">
                <div></div>
                <div
                    class="flex flex-col bg-white border shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70">
                    <div class="flex flex-row border-b rounded-t-sm py-3 px-4 md:px-5 dark:border-neutral-700">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-receipt">
                            <path d="M4 2h16v20l-4-2-4 2-4-2-4 2V2z" />
                            <path d="M16 8h-8" />
                            <path d="M16 12h-8" />
                            <path d="M10 16h-2" />
                        </svg>
                        <h3 class="text-lg  ml-2 font-bold text-stone-800 dark:text-white">
                            {{ $t('customers.form.sections.billing_preferences') }}
                        </h3>
                    </div>
                    <div class="p-4 md:p-5 space-y-3">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <FloatingSelect v-model="form.billing_mode" :label="$t('customers.form.billing.mode')"
                                :options="billingModes" />
                            <FloatingSelect v-model="form.billing_grouping" :label="$t('customers.form.billing.grouping')"
                                :options="billingGroupings" />
                        </div>
                        <div v-if="form.billing_mode === 'per_segment' || form.billing_grouping === 'periodic' || form.billing_mode === 'deferred'"
                            class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <FloatingSelect v-model="form.billing_cycle" :label="$t('customers.form.billing.cycle')"
                                :options="billingCycles" />
                            <FloatingInput v-if="form.billing_mode === 'deferred'"
                                v-model="form.billing_delay_days" type="number" :label="$t('customers.form.billing.delay_days')" />
                        </div>
                        <div v-if="form.billing_mode === 'deferred'">
                            <FloatingInput v-model="form.billing_date_rule"
                                :label="$t('customers.form.billing.date_rule')" />
                        </div>
                    </div>
                </div>
                <div></div>
                <div></div>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-1 md:gap-3 lg:gap-1 mt-4">
                <div></div>
                <div>
                    <button type="button"
                        class="py-1.5 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 focus:outline-none focus:bg-stone-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700">
                        {{ $t('customers.actions.cancel') }}
                    </button>
                </div>
                <div class="flex justify-end">
                    <button type="button"
                        class="py-1.5 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-green-600 text-green-600 hover:border-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500 action-feedback">
                        {{ $t('customers.form.actions.save_create_another') }}
                    </button>
                    <button type="submit" data-testid="demo-customer-save"
                        class="py-1.5 ml-4 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500 action-feedback">
                        {{ isCreating ? $t('customers.form.actions.save_client') : $t('customers.form.actions.update_client') }}
                    </button>
                </div>
                <div></div>

            </div>
        </form>
    </AuthenticatedLayout>
</template>
