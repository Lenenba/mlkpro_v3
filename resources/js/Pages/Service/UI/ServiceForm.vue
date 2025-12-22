<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
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
    service: {
        type: Object,
        default: null,
    },
    id: {
        type: String,
        default: null,
    },
});

const emit = defineEmits(['submitted']);
const overlayTarget = computed(() => (props.id ? `#${props.id}` : null));

const form = useForm({
    name: props.service?.name || '',
    category_id: props.service?.category_id || props.categories?.[0]?.id || '',
    unit: props.service?.unit || '',
    price: props.service?.price || 0,
    tax_rate: props.service?.tax_rate || 0,
    is_active: props.service?.is_active ?? true,
    description: props.service?.description || '',
});

const unitOptions = [
    { id: 'piece', name: 'Piece' },
    { id: 'hour', name: 'Hour' },
    { id: 'm2', name: 'm2' },
    { id: 'other', name: 'Other' },
];

const isValid = computed(() => {
    return form.name.trim() !== '' && form.category_id && Number(form.price) >= 0;
});

const closeOverlay = () => {
    if (overlayTarget.value && window.HSOverlay) {
        window.HSOverlay.close(overlayTarget.value);
    }
};

const submit = () => {
    if (!isValid.value) {
        return;
    }

    const routeName = props.service?.id ? 'service.update' : 'service.store';
    const routeParams = props.service?.id ? props.service.id : undefined;

    form[props.service?.id ? 'put' : 'post'](route(routeName, routeParams), {
        preserveScroll: true,
        onSuccess: () => {
            emit('submitted');
            closeOverlay();
        },
    });
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
            <div class="flex items-center gap-2 p-2 rounded-sm border border-stone-200 bg-white dark:bg-neutral-900 dark:border-neutral-700">
                <Checkbox v-model:checked="form.is_active" />
                <span class="text-sm text-stone-600 dark:text-neutral-400">Active</span>
            </div>
        </div>

        <FloatingTextarea v-model="form.description" label="Description" />

        <div v-if="Object.keys(form.errors).length" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            <div v-for="(messages, field) in form.errors" :key="field">
                {{ Array.isArray(messages) ? messages[0] : messages }}
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" :data-hs-overlay="overlayTarget || undefined"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-lg border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                Cancel
            </button>
            <button type="submit" :disabled="!isValid || form.processing"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-lg border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                {{ props.service ? 'Update service' : 'Create service' }}
            </button>
        </div>
    </form>
</template>

