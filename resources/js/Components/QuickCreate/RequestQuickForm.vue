<script setup>
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import ProspectDuplicateAlert from '@/Components/Prospects/ProspectDuplicateAlert.vue';
import CustomerQuickForm from '@/Components/QuickCreate/CustomerQuickForm.vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    customers: {
        type: Array,
        default: () => [],
    },
    prospects: {
        type: Array,
        default: () => [],
    },
    loadingCustomers: {
        type: Boolean,
        default: false,
    },
    loadingProspects: {
        type: Boolean,
        default: false,
    },
    overlayId: {
        type: String,
        default: null,
    },
});

const emit = defineEmits(['customer-created', 'prospect-created']);

const RELATION_MODE_EXISTING_CUSTOMER = 'existing_customer';
const RELATION_MODE_EXISTING_PROSPECT = 'existing_prospect';
const RELATION_MODE_NEW_CUSTOMER = 'new_customer';
const RELATION_MODE_NEW_PROSPECT = 'new_prospect';
const RELATION_MODE_NONE = 'none';

const { t } = useI18n();

const relationMode = ref(RELATION_MODE_NONE);
const customerSearchQuery = ref('');
const prospectSearchQuery = ref('');
const duplicateAlert = ref(null);
const submitError = ref('');
const isSubmitting = ref(false);

const sourceOptions = computed(() => ([
    { id: 'manual', name: t('requests.sources.manual') },
    { id: 'web_form', name: t('requests.sources.web_form') },
    { id: 'phone', name: t('requests.sources.phone') },
    { id: 'email', name: t('requests.sources.email') },
    { id: 'whatsapp', name: t('requests.sources.whatsapp') },
    { id: 'sms', name: t('requests.sources.sms') },
    { id: 'qr', name: t('requests.sources.qr') },
    { id: 'portal', name: t('requests.sources.portal') },
    { id: 'api', name: t('requests.sources.api') },
    { id: 'import', name: t('requests.sources.import') },
    { id: 'referral', name: t('requests.sources.referral') },
    { id: 'ads', name: t('requests.sources.ads') },
    { id: 'other', name: t('requests.sources.other') },
]));

const urgencyOptions = computed(() => ([
    { id: 'urgent', name: t('requests.urgency.urgent') },
    { id: 'high', name: t('requests.urgency.high') },
    { id: 'medium', name: t('requests.urgency.medium') },
    { id: 'low', name: t('requests.urgency.low') },
]));

const serviceableOptions = computed(() => ([
    { id: '', name: t('requests.quality.unknown') },
    { id: '1', name: t('requests.quality.serviceable') },
    { id: '0', name: t('requests.quality.not_serviceable') },
]));

const form = useForm({
    customer_id: '',
    prospect_id: '',
    source: 'manual',
    service_type: '',
    urgency: '',
    is_serviceable: '',
    budget: '',
    title: '',
    description: '',
    contact_name: '',
    contact_email: '',
    contact_phone: '',
});

const customers = computed(() => (Array.isArray(props.customers) ? props.customers : []));
const prospects = computed(() => (Array.isArray(props.prospects) ? props.prospects : []));

const selectedCustomer = computed(() => {
    if (!form.customer_id) {
        return null;
    }

    return customers.value.find((customer) => String(customer.id) === String(form.customer_id)) || null;
});

const selectedProspect = computed(() => {
    if (!form.prospect_id) {
        return null;
    }

    return prospects.value.find((prospect) => String(prospect.id) === String(form.prospect_id)) || null;
});

const customerHaystack = (customer) => [
    customer.client_type,
    customer.company_name,
    customer.registration_number,
    customer.industry,
    customer.first_name,
    customer.last_name,
    customer.email,
    customer.phone,
    customer.number ? `#${customer.number}` : '',
]
    .filter(Boolean)
    .join(' ')
    .toLowerCase();

const prospectHaystack = (prospect) => [
    prospect.title,
    prospect.service_type,
    prospect.contact_name,
    prospect.contact_email,
    prospect.contact_phone,
    prospect.company_name,
    prospect.status,
]
    .filter(Boolean)
    .join(' ')
    .toLowerCase();

const filteredCustomers = computed(() => {
    const query = customerSearchQuery.value.trim().toLowerCase();
    if (!query) {
        return customers.value;
    }

    const queryDigits = query.replace(/\D/g, '');

    return customers.value.filter((customer) => {
        if (customerHaystack(customer).includes(query)) {
            return true;
        }

        if (!queryDigits) {
            return false;
        }

        const phoneDigits = String(customer.phone || '').replace(/\D/g, '');

        return phoneDigits.includes(queryDigits);
    });
});

const filteredProspects = computed(() => {
    const query = prospectSearchQuery.value.trim().toLowerCase();
    if (!query) {
        return prospects.value;
    }

    const queryDigits = query.replace(/\D/g, '');

    return prospects.value.filter((prospect) => {
        if (prospectHaystack(prospect).includes(query)) {
            return true;
        }

        if (!queryDigits) {
            return false;
        }

        const phoneDigits = String(prospect.contact_phone || '').replace(/\D/g, '');

        return phoneDigits.includes(queryDigits);
    });
});

const hasMinimalRequestData = computed(() => (
    Boolean(form.title)
    || Boolean(form.service_type)
    || Boolean(form.contact_name)
    || Boolean(form.contact_email)
    || Boolean(form.contact_phone)
    || Boolean(form.description)
));

const canSubmit = computed(() => {
    if (!hasMinimalRequestData.value) {
        return false;
    }

    if (relationMode.value === RELATION_MODE_EXISTING_CUSTOMER) {
        return Boolean(form.customer_id);
    }

    if (relationMode.value === RELATION_MODE_EXISTING_PROSPECT) {
        return Boolean(form.prospect_id);
    }

    if (relationMode.value === RELATION_MODE_NEW_CUSTOMER) {
        return Boolean(form.customer_id);
    }

    return true;
});

const displayCustomer = (customer) =>
    customer.company_name
    || `${customer.first_name || ''} ${customer.last_name || ''}`.trim()
    || t('requests.labels.unknown_customer');

const customerLogo = (customer) =>
    customer?.logo_url || customer?.logo || '/images/presets/company-1.svg';

const customerSubtitle = (customer) => {
    const parts = [];
    if (customer.number) {
        parts.push(`#${customer.number}`);
    }
    if (customer.company_name) {
        const contact = [customer.first_name, customer.last_name].filter(Boolean).join(' ').trim();
        if (contact) {
            parts.push(contact);
        }
    }
    if (customer.email) {
        parts.push(customer.email);
    }
    if (customer.phone) {
        parts.push(customer.phone);
    }

    return parts.join(' • ');
};

const leadStatusLabel = (status) => {
    switch (status) {
        case 'REQ_NEW':
            return t('requests.status.new');
        case 'REQ_CALL_REQUESTED':
            return t('requests.status.call_requested');
        case 'REQ_CONTACTED':
            return t('requests.status.contacted');
        case 'REQ_QUALIFIED':
            return t('requests.status.qualified');
        case 'REQ_QUOTE_SENT':
            return t('requests.status.quote_sent');
        case 'REQ_WON':
            return t('requests.status.won');
        case 'REQ_LOST':
            return t('requests.status.lost');
        case 'REQ_CONVERTED':
            return t('requests.status.converted');
        default:
            return status || t('requests.labels.unknown_status');
    }
};

const prospectLabel = (prospect) =>
    prospect?.title
    || prospect?.service_type
    || prospect?.contact_name
    || t('requests.labels.request');

const prospectSubtitle = (prospect) => {
    const parts = [];
    if (prospect?.company_name) {
        parts.push(prospect.company_name);
    }
    if (prospect?.contact_name) {
        parts.push(prospect.contact_name);
    }
    if (prospect?.contact_email) {
        parts.push(prospect.contact_email);
    }
    if (prospect?.contact_phone) {
        parts.push(prospect.contact_phone);
    }
    if (prospect?.status) {
        parts.push(leadStatusLabel(prospect.status));
    }

    return parts.join(' • ');
};

const setRelationMode = (value) => {
    relationMode.value = value;
    duplicateAlert.value = null;
    submitError.value = '';
    if (value !== RELATION_MODE_EXISTING_CUSTOMER) {
        form.customer_id = '';
    }
    if (value !== RELATION_MODE_EXISTING_PROSPECT) {
        form.prospect_id = '';
    }
};

const selectCustomer = (customer) => {
    relationMode.value = RELATION_MODE_EXISTING_CUSTOMER;
    form.customer_id = String(customer.id);
};

const selectProspect = (prospect) => {
    relationMode.value = RELATION_MODE_EXISTING_PROSPECT;
    form.prospect_id = String(prospect.id);
};

const clearSelectedCustomer = () => {
    form.customer_id = '';
};

const clearSelectedProspect = () => {
    form.prospect_id = '';
};

watch(
    () => selectedCustomer.value,
    (customer) => {
        if (!customer) {
            return;
        }

        if (!form.contact_name) {
            form.contact_name = displayCustomer(customer);
        }
        if (!form.contact_email) {
            form.contact_email = customer.email || '';
        }
        if (!form.contact_phone) {
            form.contact_phone = customer.phone || '';
        }
    }
);

watch(
    () => selectedProspect.value,
    (prospect) => {
        if (!prospect) {
            return;
        }

        if (!form.title) {
            form.title = prospect.title || '';
        }
        if (!form.service_type) {
            form.service_type = prospect.service_type || '';
        }
        if (!form.contact_name) {
            form.contact_name = prospect.contact_name || '';
        }
        if (!form.contact_email) {
            form.contact_email = prospect.contact_email || '';
        }
        if (!form.contact_phone) {
            form.contact_phone = prospect.contact_phone || '';
        }
    }
);

watch(
    () => [
        form.customer_id,
        form.prospect_id,
        form.source,
        form.service_type,
        form.urgency,
        form.is_serviceable,
        form.budget,
        form.title,
        form.description,
        form.contact_name,
        form.contact_email,
        form.contact_phone,
        relationMode.value,
    ],
    () => {
        duplicateAlert.value = null;
        submitError.value = '';
    }
);

const applyPrefill = (customerId) => {
    resetForm();
    if (customerId) {
        relationMode.value = RELATION_MODE_EXISTING_CUSTOMER;
        form.customer_id = String(customerId);
    }
};

const handlePrefillEvent = (event) => {
    applyPrefill(event?.detail?.customerId);
};

onMounted(() => {
    if (typeof window !== 'undefined') {
        window.addEventListener('quick-create-request', handlePrefillEvent);
    }
});

onBeforeUnmount(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('quick-create-request', handlePrefillEvent);
    }
});

const handleCustomerCreated = (payload) => {
    emit('customer-created', payload);
    const customerId = payload?.customer?.id;
    if (!customerId) {
        return;
    }

    relationMode.value = RELATION_MODE_EXISTING_CUSTOMER;
    customerSearchQuery.value = '';
    form.customer_id = String(customerId);
    duplicateAlert.value = null;
    submitError.value = '';
};

const closeOverlay = () => {
    if (props.overlayId && window.HSOverlay) {
        window.HSOverlay.close(props.overlayId);
    }
};

const resetForm = () => {
    relationMode.value = RELATION_MODE_NONE;
    customerSearchQuery.value = '';
    prospectSearchQuery.value = '';
    duplicateAlert.value = null;
    submitError.value = '';
    form.reset();
    form.source = 'manual';
    form.is_serviceable = '';
    form.budget = '';
};

const shouldIgnoreDuplicates = (value) => value === true;

const buildPayload = (ignoreDuplicates = false) => ({
    relation_mode: relationMode.value,
    customer_id: form.customer_id || null,
    prospect_id: form.prospect_id || null,
    source: form.source || null,
    service_type: form.service_type || null,
    urgency: form.urgency || null,
    is_serviceable: form.is_serviceable === '' ? null : form.is_serviceable === '1',
    title: form.title || null,
    description: form.description || null,
    contact_name: form.contact_name || null,
    contact_email: form.contact_email || null,
    contact_phone: form.contact_phone || null,
    ignore_duplicates: shouldIgnoreDuplicates(ignoreDuplicates),
    meta: {
        budget: form.budget === '' ? null : Number(form.budget),
        request_type: 'manual_service_request',
    },
});

const submit = async (ignoreDuplicates = false) => {
    if (isSubmitting.value || !canSubmit.value) {
        return;
    }

    isSubmitting.value = true;
    duplicateAlert.value = null;
    submitError.value = '';
    form.clearErrors();

    try {
        const response = await axios.post(route('service-requests.store'), buildPayload(ignoreDuplicates), {
            headers: {
                Accept: 'application/json',
            },
        });

        if (response.data?.customer) {
            emit('customer-created', response.data);
        }
        if (response.data?.prospect) {
            emit('prospect-created', response.data);
        }

        closeOverlay();
        resetForm();
        router.reload({
            preserveScroll: true,
            preserveState: true,
        });
    } catch (error) {
        if (error?.response?.status === 409 && error?.response?.data?.duplicate_alert) {
            duplicateAlert.value = {
                ...error.response.data.duplicate_alert,
                message: error.response.data.message || null,
            };
            return;
        }

        if (error?.response?.status === 422) {
            form.setError(error.response.data?.errors || {});
            submitError.value = error.response.data?.message || '';
            return;
        }

        submitError.value = error?.response?.data?.message || t('requests.feedback.create_error');
    } finally {
        isSubmitting.value = false;
    }
};
</script>

<template>
    <div class="space-y-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <button
                type="button"
                @click="setRelationMode(RELATION_MODE_EXISTING_CUSTOMER)"
                class="flex min-h-[120px] flex-col items-center justify-center gap-3 rounded-sm border px-4 py-5 text-sm font-semibold transition"
                :class="relationMode === RELATION_MODE_EXISTING_CUSTOMER
                    ? 'border-green-600 bg-green-50 text-green-700'
                    : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50'"
            >
                {{ $t('requests.quick_form.existing_customer') }}
            </button>
            <button
                type="button"
                @click="setRelationMode(RELATION_MODE_EXISTING_PROSPECT)"
                class="flex min-h-[120px] flex-col items-center justify-center gap-3 rounded-sm border px-4 py-5 text-sm font-semibold transition"
                :class="relationMode === RELATION_MODE_EXISTING_PROSPECT
                    ? 'border-green-600 bg-green-50 text-green-700'
                    : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50'"
            >
                {{ $t('requests.quick_form.existing_prospect') }}
            </button>
            <button
                type="button"
                @click="setRelationMode(RELATION_MODE_NEW_CUSTOMER)"
                class="flex min-h-[120px] flex-col items-center justify-center gap-3 rounded-sm border px-4 py-5 text-sm font-semibold transition"
                :class="relationMode === RELATION_MODE_NEW_CUSTOMER
                    ? 'border-green-600 bg-green-50 text-green-700'
                    : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50'"
            >
                {{ $t('requests.quick_form.new_customer') }}
            </button>
            <button
                type="button"
                @click="setRelationMode(RELATION_MODE_NEW_PROSPECT)"
                class="flex min-h-[120px] flex-col items-center justify-center gap-3 rounded-sm border px-4 py-5 text-sm font-semibold transition"
                :class="relationMode === RELATION_MODE_NEW_PROSPECT
                    ? 'border-green-600 bg-green-50 text-green-700'
                    : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50'"
            >
                {{ $t('requests.quick_form.new_prospect') }}
            </button>
            <button
                type="button"
                @click="setRelationMode(RELATION_MODE_NONE)"
                class="flex min-h-[120px] flex-col items-center justify-center gap-3 rounded-sm border px-4 py-5 text-sm font-semibold transition"
                :class="relationMode === RELATION_MODE_NONE
                    ? 'border-green-600 bg-green-50 text-green-700'
                    : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50'"
            >
                {{ $t('requests.quick_form.no_relation') }}
            </button>
        </div>

        <div
            v-if="relationMode === RELATION_MODE_NEW_CUSTOMER"
            class="rounded-sm border border-stone-200 bg-stone-50/70 p-4 dark:border-neutral-700 dark:bg-neutral-900/50"
        >
            <p class="mb-3 text-sm text-stone-600 dark:text-neutral-300">
                {{ $t('requests.quick_form.new_customer_hint') }}
            </p>
            <CustomerQuickForm
                :overlay-id="overlayId"
                :submit-label="$t('requests.quick_form.create_customer')"
                @created="handleCustomerCreated"
            />
        </div>

        <form @submit.prevent="submit()" class="space-y-4">
            <div
                v-if="relationMode === RELATION_MODE_EXISTING_CUSTOMER"
                class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900"
            >
                <label class="text-sm text-stone-600 dark:text-neutral-400">{{ $t('requests.quick_form.customer_optional') }}</label>
                <div v-if="loadingCustomers" class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.quick_form.loading_customers') }}
                </div>
                <div v-else class="mt-2 space-y-3">
                    <input
                        v-model="customerSearchQuery"
                        type="text"
                        :placeholder="$t('requests.quick_form.search_placeholder')"
                        class="w-full rounded-sm border border-stone-200 bg-white py-2 px-3 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                    />
                    <div class="max-h-64 overflow-y-auto rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="divide-y divide-stone-200 dark:divide-neutral-800">
                            <button
                                v-for="customer in filteredCustomers"
                                :key="customer.id"
                                type="button"
                                class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm transition"
                                :class="String(customer.id) === String(form.customer_id)
                                    ? 'bg-green-50 text-stone-900'
                                    : 'hover:bg-stone-50 text-stone-700 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                                @click="selectCustomer(customer)"
                            >
                                <span class="flex size-10 items-center justify-center overflow-hidden rounded-sm bg-stone-100 dark:bg-neutral-800">
                                    <img :src="customerLogo(customer)" :alt="displayCustomer(customer)" class="size-10 object-cover" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-medium text-stone-800 dark:text-neutral-100">
                                        {{ displayCustomer(customer) }}
                                    </div>
                                    <div v-if="customerSubtitle(customer)" class="truncate text-xs text-stone-500 dark:text-neutral-400">
                                        {{ customerSubtitle(customer) }}
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>
                    <button v-if="form.customer_id" type="button" class="text-xs text-stone-500 hover:text-stone-700 dark:text-neutral-400 dark:hover:text-neutral-200" @click="clearSelectedCustomer">
                        {{ $t('requests.quick_form.continue_without') }}
                    </button>
                </div>
            </div>

            <div
                v-if="relationMode === RELATION_MODE_EXISTING_PROSPECT"
                class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900"
            >
                <label class="text-sm text-stone-600 dark:text-neutral-400">{{ $t('requests.quick_form.prospect_optional') }}</label>
                <div v-if="loadingProspects" class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.quick_form.loading_prospects') }}
                </div>
                <div v-else class="mt-2 space-y-3">
                    <input
                        v-model="prospectSearchQuery"
                        type="text"
                        :placeholder="$t('requests.quick_form.search_prospect_placeholder')"
                        class="w-full rounded-sm border border-stone-200 bg-white py-2 px-3 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                    />
                    <div class="max-h-64 overflow-y-auto rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="divide-y divide-stone-200 dark:divide-neutral-800">
                            <button
                                v-for="prospect in filteredProspects"
                                :key="prospect.id"
                                type="button"
                                class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm transition"
                                :class="String(prospect.id) === String(form.prospect_id)
                                    ? 'bg-green-50 text-stone-900'
                                    : 'hover:bg-stone-50 text-stone-700 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                                @click="selectProspect(prospect)"
                            >
                                <span class="flex size-10 items-center justify-center rounded-sm bg-amber-500 text-[11px] font-semibold text-white">
                                    PR
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-medium text-stone-800 dark:text-neutral-100">
                                        {{ prospectLabel(prospect) }}
                                    </div>
                                    <div v-if="prospectSubtitle(prospect)" class="truncate text-xs text-stone-500 dark:text-neutral-400">
                                        {{ prospectSubtitle(prospect) }}
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>
                    <button v-if="form.prospect_id" type="button" class="text-xs text-stone-500 hover:text-stone-700 dark:text-neutral-400 dark:hover:text-neutral-200" @click="clearSelectedProspect">
                        {{ $t('requests.quick_form.continue_without_prospect') }}
                    </button>
                </div>
            </div>

            <div
                v-if="relationMode === RELATION_MODE_NEW_PROSPECT"
                class="rounded-sm border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"
            >
                {{ $t('requests.quick_form.new_prospect_hint') }}
            </div>

            <div
                v-if="relationMode === RELATION_MODE_NONE"
                class="rounded-sm border border-stone-200 bg-stone-50 px-4 py-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900/50 dark:text-neutral-300"
            >
                {{ $t('requests.quick_form.unlinked_hint') }}
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <FloatingInput v-model="form.title" :label="$t('requests.quick_form.title')" />
                <FloatingInput v-model="form.service_type" :label="$t('requests.quick_form.service_type')" />
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <FloatingSelect
                    v-model="form.source"
                    :label="$t('requests.quick_form.source')"
                    :options="sourceOptions"
                    :placeholder="$t('requests.quick_form.source_placeholder')"
                />
                <FloatingSelect
                    v-model="form.urgency"
                    :label="$t('requests.quick_form.urgency_optional')"
                    :options="urgencyOptions"
                    :placeholder="$t('requests.quick_form.urgency_placeholder')"
                />
                <FloatingSelect
                    v-model="form.is_serviceable"
                    :label="$t('requests.quick_form.serviceable_optional')"
                    :options="serviceableOptions"
                    :placeholder="$t('requests.quick_form.serviceable_placeholder')"
                />
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <FloatingInput v-model="form.budget" type="number" min="0" step="0.01" :label="$t('requests.quick_form.budget_optional')" />
                <FloatingInput v-model="form.contact_name" :label="$t('requests.quick_form.contact_name_optional')" />
                <FloatingInput v-model="form.contact_phone" :label="$t('requests.quick_form.contact_phone_optional')" />
            </div>

            <FloatingInput v-model="form.contact_email" :label="$t('requests.quick_form.contact_email_optional')" />

            <div>
                <FloatingTextarea v-model="form.description" :label="$t('requests.quick_form.description_optional')" />
                <InputError class="mt-1" :message="form.errors.description" />
            </div>

            <InputError class="mt-1" :message="form.errors.customer_id" />
            <InputError class="mt-1" :message="form.errors.prospect_id" />
            <InputError class="mt-1" :message="form.errors.contact_name" />
            <InputError class="mt-1" :message="form.errors.contact_email" />
            <InputError class="mt-1" :message="form.errors.contact_phone" />

            <ProspectDuplicateAlert :alert="duplicateAlert" :can-continue="true" @continue="submit(true)" />

            <div v-if="submitError" class="rounded-sm border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ submitError }}
            </div>

            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    :data-hs-overlay="overlayId || undefined"
                    class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                >
                    {{ $t('requests.actions.cancel') }}
                </button>
                <button
                    type="submit"
                    :disabled="isSubmitting || !canSubmit"
                    class="inline-flex items-center rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                >
                    {{ $t('requests.actions.create_request') }}
                </button>
            </div>
        </form>
    </div>
</template>
