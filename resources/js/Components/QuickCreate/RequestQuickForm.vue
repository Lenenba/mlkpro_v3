<script setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    customers: {
        type: Array,
        default: () => [],
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
            <select
                v-model="form.customer_id"
                class="mt-1 w-full rounded-lg border border-stone-200 bg-stone-100 py-2 px-3 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
            >
                <option value="">No customer yet</option>
                <option v-for="customer in customers" :key="customer.id" :value="String(customer.id)">
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
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-lg border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
            >
                Cancel
            </button>
            <button
                type="submit"
                :disabled="form.processing"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-lg border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50"
            >
                Create request
            </button>
        </div>
    </form>
</template>
