<script setup>
import { computed, reactive, ref } from 'vue';
import axios from 'axios';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';

const props = defineProps({
    overlayId: {
        type: String,
        default: null,
    },
    submitLabel: {
        type: String,
        default: 'Save customer',
    },
    closeOnSuccess: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['created']);

const form = reactive({
    salutation: 'Mr',
    first_name: '',
    last_name: '',
    email: '',
    temporary_password: '',
    phone: '',
    company_name: '',
    description: '',
    refer_by: '',
    billing_same_as_physical: true,
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

const salutations = [
    { id: 'Mr', name: 'Mr' },
    { id: 'Mrs', name: 'Mrs' },
    { id: 'Miss', name: 'Miss' },
];

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
        form.salutation &&
        form.first_name.trim() &&
        form.last_name.trim() &&
        form.email.trim() &&
        form.temporary_password.trim().length >= 8 &&
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
        messages.push('City is required when saving a location.');
    }
    return messages;
});

const resetForm = () => {
    form.salutation = 'Mr';
    form.first_name = '';
    form.last_name = '';
    form.email = '';
    form.temporary_password = '';
    form.phone = '';
    form.company_name = '';
    form.description = '';
    form.refer_by = '';
    form.billing_same_as_physical = true;
    form.properties = {
        type: 'physical',
        street1: '',
        street2: '',
        city: '',
        state: '',
        zip: '',
        country: '',
    };
};

const closeOverlay = () => {
    if (props.overlayId && window.HSOverlay) {
        window.HSOverlay.close(props.overlayId);
    }
};

const submit = async () => {
    if (!isValid.value || isSubmitting.value) {
        return;
    }

    errors.value = {};
    formError.value = '';
    isSubmitting.value = true;

    const payload = {
        salutation: form.salutation,
        first_name: form.first_name,
        last_name: form.last_name,
        email: form.email,
        temporary_password: form.temporary_password,
        phone: form.phone,
        company_name: form.company_name,
        description: form.description,
        refer_by: form.refer_by,
        billing_same_as_physical: form.billing_same_as_physical,
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
            formError.value = 'Unable to save customer. Please try again.';
        }
    } finally {
        isSubmitting.value = false;
    }
};
</script>

<template>
    <form @submit.prevent="submit" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <FloatingSelect v-model="form.salutation" label="Title" :options="salutations" />
            <FloatingInput v-model="form.first_name" label="First name" />
            <FloatingInput v-model="form.last_name" label="Last name" />
            <FloatingInput v-model="form.company_name" label="Company name" />
            <FloatingInput v-model="form.email" label="Email" />
            <FloatingInput v-model="form.temporary_password" label="Mot de passe temporaire" type="password" />
            <FloatingInput v-model="form.phone" label="Phone" />
        </div>

        <FloatingTextarea v-model="form.description" label="Notes" />

        <div class="rounded-lg border border-stone-200 p-4 dark:border-neutral-700">
            <div class="text-sm font-medium text-stone-700 dark:text-neutral-200">Location</div>
            <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                <FloatingInput v-model="form.properties.street1" label="Street" />
                <FloatingInput v-model="form.properties.street2" label="Street 2" />
                <FloatingInput v-model="form.properties.city" label="City" />
                <FloatingInput v-model="form.properties.state" label="State" />
                <FloatingInput v-model="form.properties.zip" label="Zip code" />
                <FloatingInput v-model="form.properties.country" label="Country" />
            </div>
            <div class="mt-3 flex items-center gap-2">
                <input type="checkbox" v-model="form.billing_same_as_physical"
                    class="size-3.5 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:bg-neutral-900 dark:border-neutral-700">
                <span class="text-sm text-stone-600 dark:text-neutral-400">
                    Billing address matches the property address
                </span>
            </div>
        </div>

        <div v-if="errorMessages.length" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            <div v-for="(message, index) in errorMessages" :key="index">
                {{ message }}
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" :data-hs-overlay="overlayId || undefined"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-lg border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                Cancel
            </button>
            <button type="submit" :disabled="!isValid || isSubmitting"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-lg border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                {{ submitLabel }}
            </button>
        </div>
    </form>
</template>
