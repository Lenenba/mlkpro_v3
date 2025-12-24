<script setup>
import { computed, reactive, ref, watch } from 'vue';
import axios from 'axios';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingNumberInput from '@/Components/FloatingNumberInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import Checkbox from '@/Components/Checkbox.vue';

const props = defineProps({
    categories: {
        type: Array,
        required: true,
    },
    overlayId: {
        type: String,
        default: null,
    },
});

const emit = defineEmits(['created']);

const unitOptions = [
    { id: 'piece', name: 'Piece' },
    { id: 'hour', name: 'Hour' },
    { id: 'm2', name: 'm2' },
    { id: 'other', name: 'Other' },
];

const form = reactive({
    name: '',
    category_id: props.categories[0]?.id || '',
    unit: '',
    price: 0,
    tax_rate: 0,
    is_active: true,
    description: '',
});

const errors = ref({});
const formError = ref('');
const isSubmitting = ref(false);

watch(() => props.categories, (next) => {
    if (!form.category_id && next?.length) {
        form.category_id = next[0].id;
    }
});

const isValid = computed(() => {
    return form.name.trim() !== '' && form.category_id && form.price >= 0;
});

const errorMessages = computed(() => {
    const messages = [];
    Object.values(errors.value || {}).forEach((value) => {
        if (Array.isArray(value) && value.length) {
            messages.push(value[0]);
        } else if (typeof value === 'string' && value.length) {
            messages.push(value);
        }
    });
    if (formError.value) {
        messages.push(formError.value);
    }
    return messages;
});

const closeOverlay = () => {
    if (props.overlayId && window.HSOverlay) {
        window.HSOverlay.close(props.overlayId);
    }
};

const resetForm = () => {
    form.name = '';
    form.category_id = props.categories[0]?.id || '';
    form.unit = '';
    form.price = 0;
    form.tax_rate = 0;
    form.is_active = true;
    form.description = '';
};

const submit = async () => {
    if (!isValid.value || isSubmitting.value) {
        return;
    }

    errors.value = {};
    formError.value = '';
    isSubmitting.value = true;

    const payload = {
        name: form.name,
        category_id: form.category_id,
        unit: form.unit,
        price: form.price,
        tax_rate: form.tax_rate,
        is_active: form.is_active,
        description: form.description,
    };

    try {
        const response = await axios.post(route('service.quick.store'), payload);
        emit('created', response.data);
        closeOverlay();
        resetForm();
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data?.errors || {};
        } else {
            formError.value = 'Unable to save service. Please try again.';
        }
    } finally {
        isSubmitting.value = false;
    }
};
</script>

<template>
    <form @submit.prevent="submit" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <FloatingInput v-model="form.name" label="Name" />
            <FloatingSelect v-model="form.category_id" label="Category" :options="categories" />
            <FloatingSelect v-model="form.unit" label="Unit" :options="unitOptions" />
            <FloatingNumberInput v-model="form.tax_rate" label="Tax rate (%)" />
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <FloatingNumberInput v-model="form.price" label="Price" />
            <div class="flex items-center gap-2">
                <Checkbox v-model:checked="form.is_active" />
                <span class="text-sm text-stone-600 dark:text-neutral-400">Active</span>
            </div>
        </div>

        <FloatingTextarea v-model="form.description" label="Description" />

        <div v-if="errorMessages.length" class="rounded-sm border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            <div v-for="(message, index) in errorMessages" :key="index">
                {{ message }}
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" :data-hs-overlay="overlayId || undefined"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                Cancel
            </button>
            <button type="submit" :disabled="!isValid || isSubmitting"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                Create service
            </button>
        </div>
    </form>
</template>

