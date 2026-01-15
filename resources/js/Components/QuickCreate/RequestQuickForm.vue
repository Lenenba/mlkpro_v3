<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';

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

const form = useForm({
    customer_id: '',
    channel: 'manual',
    service_type: '',
    urgency: '',
    title: '',
    description: '',
    contact_name: '',
    contact_email: '',
    contact_phone: '',
});

const customerSelectRef = ref(null);

const selectedCustomer = computed(() => {
    if (!form.customer_id) {
        return null;
    }
    return props.customers.find((customer) => customer.id === Number(form.customer_id)) || null;
});

const displayCustomer = (customer) =>
    customer.company_name ||
    `${customer.first_name || ''} ${customer.last_name || ''}`.trim() ||
    'Unknown';

const escapeAttribute = (value) =>
    String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

const customerLogoSrc = (customer) =>
    customer?.logo_url || customer?.logo || '/images/presets/company-1.svg';

const customerOptionMeta = (customer) => {
    const label = displayCustomer(customer);
    const icon = `<img src='${customerLogoSrc(customer)}' alt='${escapeAttribute(label)}' class='size-6 rounded-sm object-cover' />`;
    const description = customer.email || customer.phone || '';

    return JSON.stringify({
        icon,
        description,
    });
};

const customerSelectConfig = JSON.stringify({
    hasSearch: true,
    searchPlaceholder: 'Search customer',
    placeholder: 'No customer yet',
    optionAllowEmptyOption: true,
    searchWrapperClasses: 'sticky top-0 bg-white p-2 border-b border-stone-200 dark:bg-neutral-900 dark:border-neutral-700',
    searchClasses: 'block w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200',
    toggleTag: '<button type="button" aria-expanded="false"><span data-title class="flex-1 truncate text-left"></span><svg class="shrink-0 size-4 text-stone-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6" /></svg></button>',
    toggleClasses: 'mt-1 w-full inline-flex items-center justify-between gap-2 rounded-sm border border-stone-200 bg-stone-100 px-3 py-2 text-sm text-stone-700 hover:border-stone-300 focus:outline-none focus:ring-2 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200',
    dropdownClasses: 'mt-2 z-50 w-full max-h-72 overflow-y-auto rounded-sm border border-stone-200 bg-white p-1 shadow-lg dark:border-neutral-700 dark:bg-neutral-900 [&::-webkit-scrollbar]:w-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500',
    optionTag: '<button type="button"></button>',
    optionClasses: 'w-full rounded-sm px-2 py-1.5 text-left text-sm text-stone-800 hover:bg-stone-100 focus:outline-none focus:bg-stone-100 dark:text-neutral-200 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800',
    optionTemplate: '<div class="flex items-center gap-2 w-full"><span data-icon class="flex size-6 items-center justify-center overflow-hidden rounded-sm bg-stone-100 dark:bg-neutral-700"></span><div class="min-w-0 flex-1"><span data-title class="block truncate"></span><span data-description class="block truncate text-xs text-stone-500 dark:text-neutral-400 empty:hidden"></span></div><span class="hidden hs-selected:block"><svg class="shrink-0 size-3.5 text-stone-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12" /></svg></span></div>',
});

const initCustomerSelect = () => {
    if (typeof window === 'undefined' || !window.HSSelect) {
        return;
    }

    nextTick(() => {
        if (!customerSelectRef.value) {
            return;
        }

        const existing = window.HSSelect.getInstance(customerSelectRef.value, true);
        if (existing?.element) {
            existing.element.destroy();
        }

        window.HSSelect.autoInit();

        const instance = window.HSSelect.getInstance(customerSelectRef.value);
        if (instance) {
            instance.setValue(String(form.customer_id || ''));
        }
    });
};

const handleOverlayOpen = (event) => {
    if (!props.overlayId) {
        return;
    }

    const targetId = props.overlayId.replace('#', '');
    if (event?.target?.id !== targetId) {
        return;
    }

    initCustomerSelect();
};

const applyPrefill = (customerId) => {
    form.reset();
    form.channel = 'manual';
    form.customer_id = customerId ? String(customerId) : '';
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

watch(() => props.loading, (value) => {
    if (!value) {
        initCustomerSelect();
    }
});

watch(() => props.customers, () => {
    if (!props.loading) {
        initCustomerSelect();
    }
}, { deep: true });

watch(() => form.customer_id, (value) => {
    if (typeof window === 'undefined' || !window.HSSelect) {
        return;
    }

    const instance = customerSelectRef.value
        ? window.HSSelect.getInstance(customerSelectRef.value)
        : null;

    if (instance) {
        instance.setValue(String(value || ''));
    }
});

const handlePrefillEvent = (event) => {
    const customerId = event?.detail?.customerId;
    applyPrefill(customerId);
};

onMounted(() => {
    if (typeof window !== 'undefined') {
        window.addEventListener('quick-create-request', handlePrefillEvent);
    }

    if (!props.loading) {
        initCustomerSelect();
    }

    if (typeof document !== 'undefined') {
        document.addEventListener('open.hs.overlay', handleOverlayOpen);
    }
});

onBeforeUnmount(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('quick-create-request', handlePrefillEvent);
    }

    if (typeof document !== 'undefined') {
        document.removeEventListener('open.hs.overlay', handleOverlayOpen);
    }
});

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
        preserveScroll: true,
        onSuccess: () => {
            closeOverlay();
            form.reset();
            form.channel = 'manual';
        },
    });
};
</script>

<template>
    <form @submit.prevent="submit" class="space-y-4">
        <div>
            <label class="text-sm text-stone-600 dark:text-neutral-400">Customer (optional)</label>
            <div v-if="loading" class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                Loading customers...
            </div>
            <select
                v-else
                ref="customerSelectRef"
                v-model="form.customer_id"
                :data-hs-select="customerSelectConfig"
                class="hidden"
            >
                <option value="">No customer yet</option>
                <option
                    v-for="customer in customers"
                    :key="customer.id"
                    :value="String(customer.id)"
                    :data-hs-select-option="customerOptionMeta(customer)"
                >
                    {{ displayCustomer(customer) }}
                </option>
            </select>
            <InputError class="mt-1" :message="form.errors.customer_id" />
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <FloatingInput v-model="form.title" label="Title" />
                <InputError class="mt-1" :message="form.errors.title" />
            </div>
            <div>
                <FloatingInput v-model="form.service_type" label="Service type" />
                <InputError class="mt-1" :message="form.errors.service_type" />
            </div>
            <div>
                <FloatingInput v-model="form.urgency" label="Urgency (optional)" />
                <InputError class="mt-1" :message="form.errors.urgency" />
            </div>
            <div>
                <FloatingInput v-model="form.contact_name" label="Contact name (optional)" />
                <InputError class="mt-1" :message="form.errors.contact_name" />
            </div>
            <div>
                <FloatingInput v-model="form.contact_email" label="Contact email (optional)" />
                <InputError class="mt-1" :message="form.errors.contact_email" />
            </div>
            <div>
                <FloatingInput v-model="form.contact_phone" label="Contact phone (optional)" />
                <InputError class="mt-1" :message="form.errors.contact_phone" />
            </div>
        </div>

        <div>
            <FloatingTextarea v-model="form.description" label="Description (optional)" />
            <InputError class="mt-1" :message="form.errors.description" />
        </div>

        <div class="flex justify-end gap-2">
            <button
                type="button"
                :data-hs-overlay="overlayId || undefined"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
            >
                Cancel
            </button>
            <button
                type="submit"
                :disabled="form.processing"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50"
            >
                Create request
            </button>
        </div>
    </form>
</template>
