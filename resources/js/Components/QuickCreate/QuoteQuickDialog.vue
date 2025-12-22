<script setup>
import { computed, ref, watch } from 'vue';
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
                class="py-2 px-3 text-sm font-medium rounded-lg border">
                Existing customer
            </button>
            <button type="button" @click="mode = 'new'"
                :class="mode === 'new'
                    ? 'bg-green-600 text-white border-green-600'
                    : 'bg-white text-stone-700 border-stone-200 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200'"
                class="py-2 px-3 text-sm font-medium rounded-lg border">
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
                    <select v-model="selectedCustomerId"
                        class="mt-1 w-full rounded-lg border border-stone-200 bg-stone-100 py-2 px-3 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">Select customer</option>
                        <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                            {{ displayCustomer(customer) }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="text-sm text-stone-600 dark:text-neutral-400">Location</label>
                    <select v-model="selectedPropertyId" :disabled="!propertyOptions.length"
                        class="mt-1 w-full rounded-lg border border-stone-200 bg-stone-100 py-2 px-3 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 disabled:opacity-60 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
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

            <div v-if="selectedCustomer" class="rounded-lg border border-stone-200 p-3 text-sm text-stone-600 dark:border-neutral-700 dark:text-neutral-400">
                <div class="font-medium text-stone-700 dark:text-neutral-200">
                    {{ displayCustomer(selectedCustomer) }}
                </div>
                <div v-if="selectedCustomer.email">{{ selectedCustomer.email }}</div>
                <div v-if="selectedCustomer.phone">{{ selectedCustomer.phone }}</div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" :data-hs-overlay="overlayId || undefined"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-lg border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                    Cancel
                </button>
                <button type="button" @click="startQuote" :disabled="!selectedCustomerId"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-lg border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
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
