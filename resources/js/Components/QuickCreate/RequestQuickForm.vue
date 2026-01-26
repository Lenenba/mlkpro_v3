<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import CustomerQuickForm from '@/Components/QuickCreate/CustomerQuickForm.vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    customers: {
        type: Array,
        default: () => [],
    },
    loading: {
        type: Boolean,
        default: false,
    },
    overlayId: {
        type: String,
        default: null,
    },
});

const emit = defineEmits(['customer-created']);

const { t } = useI18n();

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

const mode = ref('existing');
const searchQuery = ref('');

const form = useForm({
    customer_id: '',
    channel: 'manual',
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

const selectedCustomer = computed(() => {
    if (!form.customer_id) {
        return null;
    }
    return customers.value.find((customer) => customer.id === Number(form.customer_id)) || null;
});

watch([() => customers.value.length, () => props.loading], ([length, loading]) => {
    if (!loading && !length) {
        mode.value = 'new';
    }
});

watch(mode, () => {
    searchQuery.value = '';
});

const displayCustomer = (customer) =>
    customer.company_name ||
    `${customer.first_name || ''} ${customer.last_name || ''}`.trim() ||
    t('requests.labels.unknown_customer');

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

const searchHaystack = (customer) =>
    [
        customer.company_name,
        customer.first_name,
        customer.last_name,
        customer.email,
        customer.phone,
        customer.number ? `#${customer.number}` : '',
        customer.number,
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

const filteredCustomers = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();
    if (!query) {
        return customers.value;
    }
    const queryDigits = query.replace(/\D/g, '');
    return customers.value.filter((customer) => {
        const haystack = searchHaystack(customer);
        if (haystack.includes(query)) {
            return true;
        }
        if (queryDigits) {
            const phoneDigits = String(customer.phone || '').replace(/\D/g, '');
            if (phoneDigits.includes(queryDigits)) {
                return true;
            }
        }
        return false;
    });
});

const selectCustomer = (customer) => {
    form.customer_id = String(customer.id);
};

const clearCustomer = () => {
    form.customer_id = '';
};

watch(selectedCustomer, (customer) => {
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
});

const applyPrefill = (customerId) => {
    mode.value = 'existing';
    searchQuery.value = '';
    form.reset();
    form.channel = 'manual';
    form.is_serviceable = '';
    form.budget = '';
    form.customer_id = customerId ? String(customerId) : '';
};

const handlePrefillEvent = (event) => {
    const customerId = event?.detail?.customerId;
    applyPrefill(customerId);
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

    mode.value = 'existing';
    searchQuery.value = '';
    form.customer_id = String(customerId);
};

const closeOverlay = () => {
    if (props.overlayId && window.HSOverlay) {
        window.HSOverlay.close(props.overlayId);
    }
};

const submit = () => {
    if (form.processing) {
        return;
    }

    form.post(route('request.store'), {
        transform: (data) => ({
            ...data,
            is_serviceable: data.is_serviceable === '' ? null : data.is_serviceable === '1',
            meta: {
                budget: data.budget === '' ? null : Number(data.budget),
            },
        }),
        preserveScroll: true,
        onSuccess: () => {
            closeOverlay();
            form.reset();
            form.channel = 'manual';
            form.is_serviceable = '';
            form.budget = '';
        },
    });
};
</script>

<template>
    <div class="space-y-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <button
                type="button"
                @click="mode = 'new'"
                class="flex min-h-[140px] flex-col items-center justify-center gap-3 rounded-sm border px-4 py-5 text-sm font-semibold transition"
                :class="mode === 'new'
                    ? 'border-green-600 bg-green-50 text-green-700'
                    : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50'"
            >
                <span class="flex size-12 items-center justify-center rounded-sm bg-green-100 text-green-600">
                    <svg class="size-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <path d="M19 8v6" />
                        <path d="M22 11h-6" />
                    </svg>
                </span>
                {{ $t('requests.quick_form.new_customer') }}
            </button>
            <button
                type="button"
                @click="mode = 'existing'"
                class="flex min-h-[140px] flex-col items-center justify-center gap-3 rounded-sm border px-4 py-5 text-sm font-semibold transition"
                :class="mode === 'existing'
                    ? 'border-green-600 bg-green-50 text-green-700'
                    : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50'"
            >
                <span class="flex size-12 items-center justify-center rounded-sm bg-stone-100 text-stone-600">
                    <svg class="size-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-3-3.87" />
                        <path d="M7 21v-2a4 4 0 0 1 4-4h0a4 4 0 0 1 4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <path d="M19 7a4 4 0 1 1-2 7.5" />
                    </svg>
                </span>
                {{ $t('requests.quick_form.existing_customer') }}
            </button>
        </div>

        <CustomerQuickForm
            v-if="mode === 'new'"
            :overlay-id="overlayId"
            :submit-label="$t('requests.quick_form.create_customer')"
            @created="handleCustomerCreated"
        />

        <form v-else @submit.prevent="submit" class="space-y-4">
            <div>
                <label class="text-sm text-stone-600 dark:text-neutral-400">{{ $t('requests.quick_form.customer_optional') }}</label>
                <div v-if="loading" class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.quick_form.loading_customers') }}
                </div>
                <div v-else class="mt-2 space-y-3">
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-stone-400">
                            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8" />
                                <path d="m21 21-4.3-4.3" />
                            </svg>
                        </div>
                        <input
                            v-model="searchQuery"
                            type="text"
                            :placeholder="$t('requests.quick_form.search_placeholder')"
                            class="w-full rounded-sm border border-stone-200 bg-white py-2 ps-9 pe-3 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                        />
                    </div>
                    <div class="max-h-64 overflow-y-auto rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="divide-y divide-stone-200 dark:divide-neutral-800">
                            <button
                                type="button"
                                class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm transition"
                                :class="!form.customer_id
                                    ? 'bg-green-50 text-stone-900'
                                    : 'hover:bg-stone-50 text-stone-700 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                                @click="clearCustomer"
                            >
                                <span class="flex size-10 items-center justify-center rounded-sm bg-stone-100 text-stone-600 dark:bg-neutral-800">
                                    <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M8 12h8" />
                                    </svg>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-medium text-stone-800 dark:text-neutral-100">
                                        {{ $t('requests.quick_form.no_customer') }}
                                    </div>
                                    <div class="truncate text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('requests.quick_form.continue_without') }}
                                    </div>
                                </div>
                                <span v-if="!form.customer_id" class="text-green-600">
                                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="20 6 9 17 4 12" />
                                    </svg>
                                </span>
                            </button>
                            <button
                                v-for="customer in filteredCustomers"
                                :key="customer.id"
                                type="button"
                                class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm transition"
                                :class="form.customer_id && String(customer.id) === String(form.customer_id)
                                    ? 'bg-green-50 text-stone-900'
                                    : 'hover:bg-stone-50 text-stone-700 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                                @click="selectCustomer(customer)"
                            >
                                <span class="flex size-10 items-center justify-center overflow-hidden rounded-sm bg-stone-100 dark:bg-neutral-800">
                                    <img
                                        :src="customerLogo(customer)"
                                        :alt="displayCustomer(customer)"
                                        class="size-10 object-cover"
                                    />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-medium text-stone-800 dark:text-neutral-100">
                                        {{ displayCustomer(customer) }}
                                    </div>
                                    <div v-if="customerSubtitle(customer)" class="truncate text-xs text-stone-500 dark:text-neutral-400">
                                        {{ customerSubtitle(customer) }}
                                    </div>
                                </div>
                                <span v-if="String(customer.id) === String(form.customer_id)" class="text-green-600">
                                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="20 6 9 17 4 12" />
                                    </svg>
                                </span>
                            </button>
                            <div v-if="!filteredCustomers.length" class="px-3 py-6 text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('requests.quick_form.no_customer') }}
                            </div>
                        </div>
                    </div>
                    <InputError class="mt-1" :message="form.errors.customer_id" />
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <div>
                    <FloatingInput v-model="form.title" :label="$t('requests.quick_form.title')" />
                    <InputError class="mt-1" :message="form.errors.title" />
                </div>
                <div>
                    <FloatingInput v-model="form.service_type" :label="$t('requests.quick_form.service_type')" />
                    <InputError class="mt-1" :message="form.errors.service_type" />
                </div>
                <div>
                    <FloatingSelect
                        v-model="form.channel"
                        :label="$t('requests.quick_form.source')"
                        :options="sourceOptions"
                        :placeholder="$t('requests.quick_form.source_placeholder')"
                    />
                    <InputError class="mt-1" :message="form.errors.channel" />
                </div>
                <div>
                    <FloatingSelect
                        v-model="form.urgency"
                        :label="$t('requests.quick_form.urgency_optional')"
                        :options="urgencyOptions"
                        :placeholder="$t('requests.quick_form.urgency_placeholder')"
                    />
                    <InputError class="mt-1" :message="form.errors.urgency" />
                </div>
                <div>
                    <FloatingSelect
                        v-model="form.is_serviceable"
                        :label="$t('requests.quick_form.serviceable_optional')"
                        :options="serviceableOptions"
                        :placeholder="$t('requests.quick_form.serviceable_placeholder')"
                    />
                    <InputError class="mt-1" :message="form.errors.is_serviceable" />
                </div>
                <div>
                    <FloatingInput
                        v-model="form.budget"
                        type="number"
                        step="0.01"
                        :label="$t('requests.quick_form.budget_optional')"
                    />
                    <InputError class="mt-1" :message="form.errors.budget" />
                </div>
                <div>
                    <FloatingInput v-model="form.contact_name" :label="$t('requests.quick_form.contact_name_optional')" />
                    <InputError class="mt-1" :message="form.errors.contact_name" />
                </div>
                <div>
                    <FloatingInput v-model="form.contact_email" :label="$t('requests.quick_form.contact_email_optional')" />
                    <InputError class="mt-1" :message="form.errors.contact_email" />
                </div>
                <div>
                    <FloatingInput v-model="form.contact_phone" :label="$t('requests.quick_form.contact_phone_optional')" />
                    <InputError class="mt-1" :message="form.errors.contact_phone" />
                </div>
            </div>

            <div>
                <FloatingTextarea v-model="form.description" :label="$t('requests.quick_form.description_optional')" />
                <InputError class="mt-1" :message="form.errors.description" />
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
                    :disabled="form.processing"
                    class="inline-flex items-center rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                >
                    {{ $t('requests.actions.create_request') }}
                </button>
            </div>
        </form>
    </div>
</template>
