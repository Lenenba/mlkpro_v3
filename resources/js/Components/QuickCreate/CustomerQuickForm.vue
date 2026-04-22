<script setup>
import { computed, reactive, ref } from 'vue';
import axios from 'axios';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import {
    buildCustomerClientTypeOptions,
    CUSTOMER_CLIENT_TYPE_COMPANY,
    CUSTOMER_CLIENT_TYPE_INDIVIDUAL,
} from '@/utils/customerClientTypes';
import { assignGeoapifyAddress, useGeoapifyAddressAutocomplete } from '@/Composables/useGeoapifyAddressAutocomplete';
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
});

const emit = defineEmits(['created']);

const { t } = useI18n();

const form = reactive({
    client_type: CUSTOMER_CLIENT_TYPE_INDIVIDUAL,
    salutation: 'Mr',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    company_name: '',
    registration_number: '',
    industry: '',
    discount_rate: '',
    portal_access: true,
    description: '',
    refer_by: '',
    billing_same_as_physical: true,
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

const resetForm = () => {
    form.client_type = CUSTOMER_CLIENT_TYPE_INDIVIDUAL;
    form.salutation = 'Mr';
    form.first_name = '';
    form.last_name = '';
    form.email = '';
    form.phone = '';
    form.company_name = '';
    form.registration_number = '';
    form.industry = '';
    form.discount_rate = '';
    form.description = '';
    form.refer_by = '';
    form.portal_access = true;
    form.billing_same_as_physical = true;
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

const closeOverlay = () => {
    if (props.overlayId && window.HSOverlay) {
        window.HSOverlay.close(props.overlayId);
    }
};

const submit = async () => {
    if (isSubmitting.value) {
        return;
    }

    if (!isValid.value) {
        formError.value = t('customers.form.errors.required_fields');
        return;
    }

    errors.value = {};
    formError.value = '';
    isSubmitting.value = true;

    const payload = {
        client_type: form.client_type,
        salutation: form.salutation,
        first_name: form.first_name,
        last_name: form.last_name,
        email: form.email,
        phone: form.phone,
        company_name: form.company_name,
        registration_number: form.registration_number,
        industry: form.industry,
        discount_rate: form.discount_rate ? Number(form.discount_rate) : 0,
        portal_access: form.portal_access,
        description: form.description,
        refer_by: form.refer_by,
        billing_same_as_physical: form.billing_same_as_physical,
        auto_accept_quotes: form.auto_accept_quotes,
        auto_validate_jobs: form.auto_validate_jobs,
        auto_validate_tasks: form.auto_validate_tasks,
        auto_validate_invoices: form.auto_validate_invoices,
    };

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

    try {
        const response = await axios.post(route('customer.quick.store'), payload);
        emit('created', response.data);
        if (props.closeOnSuccess) {
            closeOverlay();
        }
        resetForm();
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data?.errors || {};
        } else {
            formError.value = t('customers.form.errors.save_failed');
        }
    } finally {
        isSubmitting.value = false;
    }
};

</script>

<template>
    <form @submit.prevent="submit" class="space-y-4">
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
            />
            <FloatingInput
                v-model="form.registration_number"
                :label="$t('customers.form.fields.registration_number')"
            />
            <div class="md:col-span-2">
                <FloatingInput v-model="form.industry" :label="$t('customers.form.fields.industry')" />
            </div>
            </div>
        </div>

        <div>
            <div class="mb-2 text-sm font-medium text-stone-700 dark:text-neutral-200">{{ contactSectionTitle }}</div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <FloatingInput v-model="form.first_name" :label="$t('customers.form.fields.first_name')" :required="true" />
                <FloatingInput v-model="form.last_name" :label="$t('customers.form.fields.last_name')" :required="true" />
                <FloatingInput v-model="form.email" :label="$t('customers.form.fields.email')" :required="true" />
                <FloatingInput v-model="form.phone" :label="$t('customers.form.fields.phone')" />
                <FloatingInput v-model="form.discount_rate" type="number" :label="$t('customers.form.fields.discount_rate')" />
            </div>
        </div>
        <div class="flex items-start gap-2">
            <input id="quick-customer-portal-access" type="checkbox" v-model="form.portal_access"
                class="mt-1 size-4 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700 dark:checked:bg-green-500 dark:checked:border-green-500" />
            <div>
                <label for="quick-customer-portal-access" class="text-sm text-stone-700 dark:text-neutral-200">
                    {{ $t('customers.form.fields.portal_access') }}
                </label>
            </div>
        </div>

        <FloatingTextarea v-model="form.description" :label="$t('customers.form.fields.notes')" />

        <div class="rounded-sm border border-stone-200 p-4 dark:border-neutral-700">
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
                <FloatingInput v-model="form.properties.street1" :label="$t('customers.properties.fields.street1')" :readonly="true" />
                <FloatingInput v-model="form.properties.street2" :label="$t('customers.properties.fields.street2')" :readonly="true" />
                <FloatingInput v-model="form.properties.city" :label="$t('customers.properties.fields.city')" :readonly="true" />
                <FloatingInput v-model="form.properties.state" :label="$t('customers.properties.fields.state')" :readonly="true" />
                <FloatingInput v-model="form.properties.zip" :label="$t('customers.properties.fields.zip')" :readonly="true" />
                <FloatingInput v-model="form.properties.country" :label="$t('customers.properties.fields.country')" :readonly="true" />
            </div>
            <div class="mt-3 flex items-center gap-2">
                <input type="checkbox" v-model="form.billing_same_as_physical"
                    class="size-3.5 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700">
                <span class="text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('customers.form.billing.same_as_property') }}
                </span>
            </div>
            <div class="mt-3 flex items-start gap-2">
                <input type="checkbox" v-model="form.auto_accept_quotes"
                    class="mt-0.5 size-3.5 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700">
                <span class="text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('customers.form.auto_accept_quotes') }}
                </span>
            </div>
            <div class="mt-2 flex items-start gap-2">
                <input type="checkbox" v-model="form.auto_validate_jobs"
                    class="mt-0.5 size-3.5 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700">
                <span class="text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('customers.details.auto_validation.jobs') }}
                </span>
            </div>
            <div class="mt-2 flex items-start gap-2">
                <input type="checkbox" v-model="form.auto_validate_tasks"
                    class="mt-0.5 size-3.5 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700">
                <span class="text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('customers.details.auto_validation.tasks') }}
                </span>
            </div>
            <div class="mt-2 flex items-start gap-2">
                <input type="checkbox" v-model="form.auto_validate_invoices"
                    class="mt-0.5 size-3.5 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700">
                <span class="text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('customers.details.auto_validation.invoices') }}
                </span>
            </div>
        </div>

        <div v-if="errorMessages.length" class="rounded-sm border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            <div v-for="(message, index) in errorMessages" :key="index">
                {{ message }}
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" :data-hs-overlay="overlayId || undefined"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                {{ $t('customers.actions.cancel') }}
            </button>
            <button type="submit" :disabled="isSubmitting"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                {{ resolvedSubmitLabel }}
            </button>
        </div>
    </form>
</template>
