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
const searchQuery = ref('');

const customers = computed(() => (Array.isArray(props.customers) ? props.customers : []));

const selectedCustomer = computed(() => {
    if (!selectedCustomerId.value) {
        return null;
    }
    return customers.value.find((customer) => customer.id === Number(selectedCustomerId.value)) || null;
});

const propertyOptions = computed(() => selectedCustomer.value?.properties || []);

watch(selectedCustomer, (customer) => {
    if (!customer) {
        selectedPropertyId.value = '';
        return;
    }

    selectedPropertyId.value = customer?.properties?.find((property) => property.is_default)?.id
        || customer?.properties?.[0]?.id
        || '';
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
    'Unknown';

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
    selectedCustomerId.value = String(customer.id);
};

const isSelected = (customer) => String(customer.id) === String(selectedCustomerId.value);

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
                Nouveau client
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
                Client existant
            </button>
        </div>

        <div v-if="mode === 'existing'" class="space-y-4">
            <div v-if="loading" class="text-sm text-stone-500 dark:text-neutral-400">
                Chargement des clients...
            </div>
            <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="space-y-3">
                    <label class="text-sm text-stone-600 dark:text-neutral-400">Client</label>
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
                            placeholder="Rechercher un client"
                            class="w-full rounded-sm border border-stone-200 bg-white py-2 ps-9 pe-3 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                        />
                    </div>
                    <div class="max-h-64 overflow-y-auto rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="divide-y divide-stone-200 dark:divide-neutral-800">
                            <button
                                v-for="customer in filteredCustomers"
                                :key="customer.id"
                                type="button"
                                class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm transition"
                                :class="isSelected(customer)
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
                                <span v-if="isSelected(customer)" class="text-green-600">
                                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="20 6 9 17 4 12" />
                                    </svg>
                                </span>
                            </button>
                            <div v-if="!filteredCustomers.length" class="px-3 py-6 text-sm text-stone-500 dark:text-neutral-400">
                                Aucun client
                            </div>
                        </div>
                    </div>
                </div>
                <div class="space-y-3">
                    <label class="text-sm text-stone-600 dark:text-neutral-400">Location</label>
                    <select
                        v-model="selectedPropertyId"
                        :disabled="!propertyOptions.length"
                        class="mt-1 w-full rounded-sm border border-stone-200 bg-stone-100 py-2 px-3 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 disabled:opacity-60 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                    >
                        <option value="">No location</option>
                        <option v-for="property in propertyOptions" :key="property.id" :value="property.id">
                            {{ property.street1 || 'Location' }}{{ property.city ? ', ' + property.city : '' }}
                        </option>
                    </select>

                    <div class="rounded-sm border border-stone-200 p-3 text-sm text-stone-600 dark:border-neutral-700 dark:text-neutral-400">
                        <div v-if="selectedCustomer" class="space-y-1">
                            <div class="font-medium text-stone-700 dark:text-neutral-200">
                                {{ displayCustomer(selectedCustomer) }}
                            </div>
                            <div v-if="selectedCustomer.email">{{ selectedCustomer.email }}</div>
                            <div v-if="selectedCustomer.phone">{{ selectedCustomer.phone }}</div>
                        </div>
                        <div v-else>Selectionnez un client pour continuer.</div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    :data-hs-overlay="overlayId || undefined"
                    class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    @click="startQuote"
                    :disabled="!selectedCustomerId"
                    class="inline-flex items-center rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                >
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
