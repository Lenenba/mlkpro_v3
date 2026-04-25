<script setup>
import axios from 'axios';
import { computed, onUnmounted, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/UI/Modal.vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    lead: {
        type: Object,
        required: true,
    },
    customerConversion: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
const modalId = 'prospect-customer-conversion-modal';
const searchQuery = ref('');
const searchResults = ref([]);
const searchLoading = ref(false);
const searchError = ref('');
const submitError = ref('');
const submitting = ref(false);
let searchTimer = null;

const form = useForm({
    mode: 'create_new',
    customer_id: '',
    contact_name: '',
    contact_email: '',
    contact_phone: '',
    company_name: '',
    street1: '',
    street2: '',
    city: '',
    state: '',
    postal_code: '',
    country: '',
});

const matchOptions = computed(() => Array.isArray(props.customerConversion?.matches) ? props.customerConversion.matches : []);

const existingCustomerOptions = computed(() => {
    const customers = [];
    const seen = new Set();

    for (const customer of [...matchOptions.value, ...searchResults.value]) {
        if (!customer || seen.has(customer.id)) {
            continue;
        }

        seen.add(customer.id);
        customers.push(customer);
    }

    return customers;
});

const selectedExistingCustomer = computed(() =>
    existingCustomerOptions.value.find((customer) => String(customer.id) === String(form.customer_id)) || null
);

const createPreviewAddress = computed(() =>
    [
        form.street1,
        form.street2,
        form.city,
        form.state,
        form.postal_code,
        form.country,
    ].filter(Boolean).join(', ')
);

const summaryCustomerLabel = computed(() => {
    if (form.mode === 'link_existing') {
        return displayCustomer(selectedExistingCustomer.value);
    }

    return form.company_name || form.contact_name || t('requests.customer_conversion.preview_value_missing');
});

const canSubmit = computed(() => {
    if (submitting.value) {
        return false;
    }

    if (form.mode === 'link_existing') {
        return Boolean(form.customer_id);
    }

    return Boolean(form.contact_name?.trim()) && Boolean(form.contact_email?.trim());
});

const resetForm = () => {
    const preview = props.customerConversion?.preview || {};
    const matches = matchOptions.value;
    const shouldPreselectExisting = (props.customerConversion?.default_mode === 'link_existing') && matches.length === 1;

    form.mode = props.customerConversion?.default_mode || 'create_new';
    form.customer_id = shouldPreselectExisting ? String(matches[0].id) : '';
    form.contact_name = preview.contact_name || '';
    form.contact_email = preview.contact_email || '';
    form.contact_phone = preview.contact_phone || '';
    form.company_name = preview.company_name || '';
    form.street1 = preview.street1 || '';
    form.street2 = preview.street2 || '';
    form.city = preview.city || '';
    form.state = preview.state || '';
    form.postal_code = preview.postal_code || '';
    form.country = preview.country || '';
    form.clearErrors();
    submitError.value = '';
};

const resetSearch = () => {
    searchQuery.value = '';
    searchResults.value = [];
    searchError.value = '';
};

const open = () => {
    resetForm();
    resetSearch();

    if (window.HSOverlay) {
        window.HSOverlay.open(`#${modalId}`);
    }
};

const close = () => {
    if (window.HSOverlay) {
        window.HSOverlay.close(`#${modalId}`);
    }
};

const handleOpen = () => {
    loadCustomers('');
};

const handleClose = () => {
    if (searchTimer) {
        clearTimeout(searchTimer);
        searchTimer = null;
    }
};

const displayCustomer = (customer) =>
    customer?.display_name ||
    customer?.company_name ||
    `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim() ||
    t('requests.labels.unknown_customer');

const formatAddress = (customer) => {
    const property = customer?.default_property;

    if (!property) {
        return '';
    }

    return property.full_address || [
        property.street1,
        property.street2,
        property.city,
        property.state,
        property.zip,
        property.country,
    ].filter(Boolean).join(', ');
};

const chooseExistingCustomer = (customer) => {
    form.mode = 'link_existing';
    form.customer_id = String(customer.id);
    form.clearErrors('customer_id');
    submitError.value = '';
};

const loadCustomers = async (query = '') => {
    searchLoading.value = true;
    searchError.value = '';

    try {
        const response = await axios.get(route('customer.options'), {
            params: {
                scope: 'request',
                search: query || undefined,
                limit: 8,
            },
            headers: {
                Accept: 'application/json',
            },
        });

        searchResults.value = Array.isArray(response?.data?.customers) ? response.data.customers : [];
    } catch (error) {
        searchError.value = error?.response?.data?.message || t('requests.customer_conversion.search_error');
    } finally {
        searchLoading.value = false;
    }
};

const submit = async () => {
    if (!canSubmit.value) {
        return;
    }

    form.clearErrors();
    submitError.value = '';
    submitting.value = true;

    try {
        await axios.post(
            props.customerConversion?.submit_url || route('prospects.convert-customer', props.lead.id),
            {
                mode: form.mode,
                customer_id: form.mode === 'link_existing' && form.customer_id ? Number(form.customer_id) : null,
                contact_name: form.contact_name || null,
                contact_email: form.contact_email || null,
                contact_phone: form.contact_phone || null,
                company_name: form.company_name || null,
                street1: form.street1 || null,
                street2: form.street2 || null,
                city: form.city || null,
                state: form.state || null,
                postal_code: form.postal_code || null,
                country: form.country || null,
            },
            {
                headers: {
                    Accept: 'application/json',
                },
            }
        );

        close();
        router.reload({
            preserveScroll: true,
            preserveState: false,
        });
    } catch (error) {
        if (error?.response?.status === 422) {
            form.setError(error.response.data?.errors || {});
            submitError.value = error.response.data?.message || '';
            return;
        }

        submitError.value = error?.response?.data?.message || t('requests.feedback.convert_customer_error');
    } finally {
        submitting.value = false;
    }
};

watch(
    () => form.mode,
    (mode) => {
        submitError.value = '';
        form.clearErrors();

        if (mode === 'create_new') {
            form.customer_id = '';
            return;
        }

        if (!form.customer_id && matchOptions.value.length === 1) {
            form.customer_id = String(matchOptions.value[0].id);
        }
    }
);

watch(searchQuery, (value) => {
    if (searchTimer) {
        clearTimeout(searchTimer);
    }

    searchTimer = setTimeout(() => {
        loadCustomers(value);
    }, 250);
});

onUnmounted(() => {
    if (searchTimer) {
        clearTimeout(searchTimer);
    }
});

defineExpose({
    open,
    close,
});
</script>

<template>
    <Modal :id="modalId" :title="$t('requests.customer_conversion.title')" @open="handleOpen" @close="handleClose">
        <div class="space-y-5">
            <div class="space-y-1">
                <p class="text-sm font-medium text-stone-800 dark:text-neutral-100">
                    {{ $t('requests.customer_conversion.subtitle') }}
                </p>
                <p class="text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.customer_conversion.description') }}
                </p>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <button
                    type="button"
                    class="rounded-sm border px-4 py-3 text-left transition"
                    :class="form.mode === 'link_existing'
                        ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-100'
                        : 'border-stone-200 bg-white text-stone-700 hover:border-stone-300 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200'"
                    @click="form.mode = 'link_existing'"
                >
                    <div class="text-sm font-semibold">
                        {{ $t('requests.customer_conversion.modes.link_existing') }}
                    </div>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('requests.customer_conversion.mode_hints.link_existing') }}
                    </div>
                </button>
                <button
                    type="button"
                    class="rounded-sm border px-4 py-3 text-left transition"
                    :class="form.mode === 'create_new'
                        ? 'border-sky-300 bg-sky-50 text-sky-900 dark:border-sky-500/40 dark:bg-sky-500/10 dark:text-sky-100'
                        : 'border-stone-200 bg-white text-stone-700 hover:border-stone-300 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200'"
                    @click="form.mode = 'create_new'"
                >
                    <div class="text-sm font-semibold">
                        {{ $t('requests.customer_conversion.modes.create_new') }}
                    </div>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('requests.customer_conversion.mode_hints.create_new') }}
                    </div>
                </button>
            </div>

            <section v-if="matchOptions.length" class="rounded-sm border border-amber-200 bg-amber-50/70 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-semibold text-amber-900 dark:text-amber-100">
                            {{ $t('requests.customer_conversion.matches_title') }}
                        </h3>
                        <p class="mt-1 text-xs text-amber-800/80 dark:text-amber-100/80">
                            {{ $t('requests.customer_conversion.matches_hint') }}
                        </p>
                    </div>
                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/20 dark:text-amber-100">
                        {{ matchOptions.length }}
                    </span>
                </div>

                <div class="mt-3 space-y-3">
                    <div
                        v-for="customer in matchOptions"
                        :key="`match-${customer.id}`"
                        class="rounded-sm border border-amber-200 bg-white p-3 dark:border-amber-500/20 dark:bg-neutral-950"
                    >
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ displayCustomer(customer) }}
                                    </div>
                                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-medium text-stone-700 dark:bg-neutral-800 dark:text-neutral-200">
                                        {{ $t('requests.customer_conversion.match_score', { score: customer.score }) }}
                                    </span>
                                    <span
                                        v-if="String(form.customer_id) === String(customer.id)"
                                        class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-100"
                                    >
                                        {{ $t('requests.customer_conversion.selected_badge') }}
                                    </span>
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    <span v-if="customer.number">{{ customer.number }} · </span>{{ customer.email || '-' }} · {{ customer.phone || '-' }}
                                </div>
                                <div v-if="formatAddress(customer)" class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatAddress(customer) }}
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="reason in customer.match_reasons || []"
                                        :key="`${customer.id}-${reason.code}`"
                                        class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800 dark:bg-amber-500/20 dark:text-amber-100"
                                    >
                                        {{ reason.label }}
                                    </span>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"
                                @click="chooseExistingCustomer(customer)"
                            >
                                {{ $t('requests.customer_conversion.select_existing') }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="form.mode === 'link_existing'" class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-neutral-200">
                        {{ $t('requests.customer_conversion.search_label') }}
                    </label>
                    <input
                        v-model="searchQuery"
                        type="text"
                        class="block w-full rounded-sm border border-stone-300 bg-white px-3 py-2 text-sm text-stone-800 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:border-emerald-400 dark:focus:ring-emerald-500/20"
                        :placeholder="$t('requests.customer_conversion.search_placeholder')"
                    />
                    <InputError class="mt-1" :message="form.errors.customer_id" />
                    <p v-if="searchError" class="mt-2 text-xs text-rose-600 dark:text-rose-300">
                        {{ searchError }}
                    </p>
                </div>

                <div v-if="searchLoading" class="text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.customer_conversion.search_loading') }}
                </div>

                <div v-else-if="existingCustomerOptions.length" class="space-y-3">
                    <div
                        v-for="customer in existingCustomerOptions"
                        :key="`existing-${customer.id}`"
                        class="rounded-sm border p-3"
                        :class="String(form.customer_id) === String(customer.id)
                            ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10'
                            : 'border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900'"
                    >
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-1">
                                <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ displayCustomer(customer) }}
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    <span v-if="customer.number">{{ customer.number }} · </span>{{ customer.email || '-' }} · {{ customer.phone || '-' }}
                                </div>
                                <div v-if="formatAddress(customer)" class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatAddress(customer) }}
                                </div>
                            </div>
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-sm border px-3 py-2 text-sm font-medium"
                                :class="String(form.customer_id) === String(customer.id)
                                    ? 'border-emerald-300 bg-emerald-100 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/20 dark:text-emerald-100'
                                    : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                                @click="chooseExistingCustomer(customer)"
                            >
                                {{ String(form.customer_id) === String(customer.id)
                                    ? $t('requests.customer_conversion.selected_badge')
                                    : $t('requests.customer_conversion.select_existing') }}
                            </button>
                        </div>
                    </div>
                </div>

                <p v-else class="text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.customer_conversion.search_empty') }}
                </p>
            </section>

            <section v-else class="space-y-4">
                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-neutral-200">
                            {{ $t('requests.customer_conversion.fields.contact_name') }}
                        </label>
                        <input
                            v-model="form.contact_name"
                            type="text"
                            class="block w-full rounded-sm border border-stone-300 bg-white px-3 py-2 text-sm text-stone-800 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20"
                        />
                        <InputError class="mt-1" :message="form.errors.contact_name" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-neutral-200">
                            {{ $t('requests.customer_conversion.fields.company_name') }}
                        </label>
                        <input
                            v-model="form.company_name"
                            type="text"
                            class="block w-full rounded-sm border border-stone-300 bg-white px-3 py-2 text-sm text-stone-800 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20"
                        />
                        <InputError class="mt-1" :message="form.errors.company_name" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-neutral-200">
                            {{ $t('requests.customer_conversion.fields.contact_email') }}
                        </label>
                        <input
                            v-model="form.contact_email"
                            type="email"
                            class="block w-full rounded-sm border border-stone-300 bg-white px-3 py-2 text-sm text-stone-800 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20"
                        />
                        <InputError class="mt-1" :message="form.errors.contact_email" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-neutral-200">
                            {{ $t('requests.customer_conversion.fields.contact_phone') }}
                        </label>
                        <input
                            v-model="form.contact_phone"
                            type="text"
                            class="block w-full rounded-sm border border-stone-300 bg-white px-3 py-2 text-sm text-stone-800 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20"
                        />
                        <InputError class="mt-1" :message="form.errors.contact_phone" />
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-neutral-200">
                            {{ $t('requests.customer_conversion.fields.street1') }}
                        </label>
                        <input
                            v-model="form.street1"
                            type="text"
                            class="block w-full rounded-sm border border-stone-300 bg-white px-3 py-2 text-sm text-stone-800 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20"
                        />
                        <InputError class="mt-1" :message="form.errors.street1" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-neutral-200">
                            {{ $t('requests.customer_conversion.fields.street2') }}
                        </label>
                        <input
                            v-model="form.street2"
                            type="text"
                            class="block w-full rounded-sm border border-stone-300 bg-white px-3 py-2 text-sm text-stone-800 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20"
                        />
                        <InputError class="mt-1" :message="form.errors.street2" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-neutral-200">
                            {{ $t('requests.customer_conversion.fields.city') }}
                        </label>
                        <input
                            v-model="form.city"
                            type="text"
                            class="block w-full rounded-sm border border-stone-300 bg-white px-3 py-2 text-sm text-stone-800 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20"
                        />
                        <InputError class="mt-1" :message="form.errors.city" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-neutral-200">
                            {{ $t('requests.customer_conversion.fields.state') }}
                        </label>
                        <input
                            v-model="form.state"
                            type="text"
                            class="block w-full rounded-sm border border-stone-300 bg-white px-3 py-2 text-sm text-stone-800 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20"
                        />
                        <InputError class="mt-1" :message="form.errors.state" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-neutral-200">
                            {{ $t('requests.customer_conversion.fields.postal_code') }}
                        </label>
                        <input
                            v-model="form.postal_code"
                            type="text"
                            class="block w-full rounded-sm border border-stone-300 bg-white px-3 py-2 text-sm text-stone-800 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20"
                        />
                        <InputError class="mt-1" :message="form.errors.postal_code" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-neutral-200">
                            {{ $t('requests.customer_conversion.fields.country') }}
                        </label>
                        <input
                            v-model="form.country"
                            type="text"
                            class="block w-full rounded-sm border border-stone-300 bg-white px-3 py-2 text-sm text-stone-800 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20"
                        />
                        <InputError class="mt-1" :message="form.errors.country" />
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950">
                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('requests.customer_conversion.preview_title') }}
                </h3>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.customer_conversion.preview.customer') }}</div>
                        <div class="mt-1 text-sm font-medium text-stone-800 dark:text-neutral-100">
                            {{ summaryCustomerLabel }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.customer_conversion.preview.status_after') }}</div>
                        <div class="mt-1 text-sm font-medium text-stone-800 dark:text-neutral-100">
                            {{ $t('requests.status.converted') }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.customer_conversion.preview.contact') }}</div>
                        <div class="mt-1 text-sm text-stone-700 dark:text-neutral-200">
                            {{ form.contact_name || $t('requests.customer_conversion.preview_value_missing') }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.customer_conversion.preview.email') }}</div>
                        <div class="mt-1 text-sm text-stone-700 dark:text-neutral-200">
                            {{ form.mode === 'link_existing'
                                ? (selectedExistingCustomer?.email || $t('requests.customer_conversion.preview_value_missing'))
                                : (form.contact_email || $t('requests.customer_conversion.preview_value_missing')) }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.customer_conversion.preview.phone') }}</div>
                        <div class="mt-1 text-sm text-stone-700 dark:text-neutral-200">
                            {{ form.mode === 'link_existing'
                                ? (selectedExistingCustomer?.phone || $t('requests.customer_conversion.preview_value_missing'))
                                : (form.contact_phone || $t('requests.customer_conversion.preview_value_missing')) }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.customer_conversion.preview.address') }}</div>
                        <div class="mt-1 text-sm text-stone-700 dark:text-neutral-200">
                            {{ form.mode === 'link_existing'
                                ? (formatAddress(selectedExistingCustomer) || $t('requests.customer_conversion.preview_value_missing'))
                                : (createPreviewAddress || $t('requests.customer_conversion.preview_value_missing')) }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900 md:col-span-2">
                        <div class="text-xs uppercase tracking-wide text-stone-400">{{ $t('requests.customer_conversion.preview.quote') }}</div>
                        <div class="mt-1 text-sm text-stone-700 dark:text-neutral-200">
                            {{ customerConversion?.preview?.quote?.number || $t('requests.customer_conversion.no_quote') }}
                        </div>
                    </div>
                </div>
            </section>

            <div v-if="submitError" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-100">
                {{ submitError }}
            </div>

            <div class="flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    @click="close"
                >
                    {{ $t('requests.actions.cancel') }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
                    :disabled="!canSubmit"
                    @click="submit"
                >
                    {{ form.mode === 'link_existing'
                        ? $t('requests.customer_conversion.submit_link')
                        : $t('requests.customer_conversion.submit_create') }}
                </button>
            </div>
        </div>
    </Modal>
</template>
