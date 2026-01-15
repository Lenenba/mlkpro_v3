<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import CustomerQuickForm from '@/Components/QuickCreate/CustomerQuickForm.vue';

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

const mode = ref('existing');
const selectedCustomerId = ref('');
const selectedPropertyId = ref('');
const customerSelectRef = ref(null);

const selectedCustomer = computed(() => {
    if (!selectedCustomerId.value) {
        return null;
    }
    return props.customers.find((customer) => customer.id === Number(selectedCustomerId.value)) || null;
});

const propertyOptions = computed(() => selectedCustomer.value?.properties || []);

watch(selectedCustomer, (customer) => {
    selectedPropertyId.value = customer?.properties?.find((property) => property.is_default)?.id
        || customer?.properties?.[0]?.id
        || '';
});

watch(() => props.customers.length, (length) => {
    if (!length) {
        mode.value = 'new';
    }
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
    placeholder: 'Select customer',
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
        if (instance && selectedCustomerId.value) {
            instance.setValue(String(selectedCustomerId.value));
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

watch(() => props.loading, (value) => {
    if (!value && mode.value === 'existing') {
        initCustomerSelect();
    }
});

watch(() => props.customers, () => {
    if (!props.loading && mode.value === 'existing') {
        initCustomerSelect();
    }
}, { deep: true });

watch(mode, (value) => {
    if (value === 'existing' && !props.loading) {
        initCustomerSelect();
    }
});

onMounted(() => {
    if (!props.loading && mode.value === 'existing') {
        initCustomerSelect();
    }

    if (typeof document !== 'undefined') {
        document.addEventListener('open.hs.overlay', handleOverlayOpen);
    }
});

onBeforeUnmount(() => {
    if (typeof document !== 'undefined') {
        document.removeEventListener('open.hs.overlay', handleOverlayOpen);
    }
});

const closeOverlay = () => {
    if (props.overlayId && window.HSOverlay) {
        window.HSOverlay.close(props.overlayId);
    }
};

const startQuote = () => {
    if (!selectedCustomerId.value) {
        return;
    }

    const data = {};
    if (selectedPropertyId.value) {
        data.property_id = selectedPropertyId.value;
    }

    router.get(route('customer.quote.create', selectedCustomerId.value), data);
    closeOverlay();
};

const handleCustomerCreated = (payload) => {
    emit('customer-created', payload);
    const customerId = payload?.customer?.id;
    if (!customerId) {
        return;
    }

    const data = {};
    if (payload.property_id) {
        data.property_id = payload.property_id;
    }

    router.get(route('customer.quote.create', customerId), data);
    closeOverlay();
};
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" @click="mode = 'existing'"
                :class="mode === 'existing'
                    ? 'bg-green-600 text-white border-green-600'
                    : 'bg-white text-stone-700 border-stone-200 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200'"
                class="py-2 px-3 text-sm font-medium rounded-sm border">
                Existing customer
            </button>
            <button type="button" @click="mode = 'new'"
                :class="mode === 'new'
                    ? 'bg-green-600 text-white border-green-600'
                    : 'bg-white text-stone-700 border-stone-200 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200'"
                class="py-2 px-3 text-sm font-medium rounded-sm border">
                New customer
            </button>
        </div>

        <div v-if="mode === 'existing'" class="space-y-4">
            <div v-if="loading" class="text-sm text-stone-500 dark:text-neutral-400">
                Loading customers...
            </div>
            <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="text-sm text-stone-600 dark:text-neutral-400">Customer</label>
                    <select
                        ref="customerSelectRef"
                        v-model="selectedCustomerId"
                        :data-hs-select="customerSelectConfig"
                        class="hidden"
                    >
                        <option value="">Select customer</option>
                        <option
                            v-for="customer in customers"
                            :key="customer.id"
                            :value="customer.id"
                            :data-hs-select-option="customerOptionMeta(customer)"
                        >
                            {{ displayCustomer(customer) }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="text-sm text-stone-600 dark:text-neutral-400">Location</label>
                    <select v-model="selectedPropertyId" :disabled="!propertyOptions.length"
                        class="mt-1 w-full rounded-sm border border-stone-200 bg-stone-100 py-2 px-3 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 disabled:opacity-60 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">No location</option>
                        <option v-for="property in propertyOptions" :key="property.id" :value="property.id">
                            {{ property.street1 || 'Location' }}{{ property.city ? ', ' + property.city : '' }}
                        </option>
                    </select>
                </div>
            </div>
            <div v-if="selectedCustomer && !propertyOptions.length" class="text-xs text-stone-500 dark:text-neutral-400">
                No saved locations for this customer yet.
            </div>

            <div v-if="selectedCustomer" class="rounded-sm border border-stone-200 p-3 text-sm text-stone-600 dark:border-neutral-700 dark:text-neutral-400">
                <div class="font-medium text-stone-700 dark:text-neutral-200">
                    {{ displayCustomer(selectedCustomer) }}
                </div>
                <div v-if="selectedCustomer.email">{{ selectedCustomer.email }}</div>
                <div v-if="selectedCustomer.phone">{{ selectedCustomer.phone }}</div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" :data-hs-overlay="overlayId || undefined"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                    Cancel
                </button>
                <button type="button" @click="startQuote" :disabled="!selectedCustomerId"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                    Continue
                </button>
            </div>
        </div>

        <div v-else>
            <CustomerQuickForm
                :overlay-id="overlayId"
                submit-label="Create customer and continue"
                @created="handleCustomerCreated"
            />
        </div>
    </div>
</template>
