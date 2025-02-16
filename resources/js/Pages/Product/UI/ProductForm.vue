<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import productCard from '@Pages/Product/UI/Card.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingNumberInput from '@/Components/FloatingNumberInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import DropzoneInput from '@/Components/DropzoneInput.vue';

Dropzone.autoDiscover = false;

const props = defineProps({
    categories: {
        type: Array,
        required: true,
    },
    product: {
        type: Object,
        default: null,
    },
    id: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(['submitted']); // Déclare l'événement "submitted"

// Initialize the form
const form = useForm({
    name: props.product?.name || '',
    category_id: props.product?.category_id || '',
    stock: props.product?.stock || 0,
    price: props.product?.price || 0,
    minimum_stock: props.product?.minimum_stock || 0,
    description: props.product?.description || '',
    image: props.product?.image || '',
});

// Validator function for the form
const isValid = computed(() => {
    return (
        form.name.trim() !== '' &&
        form.category_id &&
        form.stock >= 0 &&
        form.price >= 0 &&
        form.minimum_stock >= 0 &&
        form.description.trim() !== '' &&
        form.image !== ''
    );
});

// Function to handle form submission
const submit = () => {
    if (!isValid.value) {
        console.error('Form is invalid');
        return;
    }

    const routeName = props.product?.id ? 'product.update' : 'product.store';
    const routeParams = props.product?.id ? props.product.id : undefined;

    form[props.product?.id ? 'put' : 'post'](route(routeName, routeParams), {
        onSuccess: () => {
            console.log('Product saved successfully!');
            emit('submitted'); // Émet l'événement "submitted"
        },
        onError: (errors) => {
            console.error('Validation errors:', errors);
        },
    });
};

const cancel = () => {
    form.reset();
};

const buttonLabel = computed(() => (props.product ? 'Update Product' : 'Create Product'));
</script>


<template>
    <form @submit.prevent="submit">
        <!-- Products Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-6 gap-5">
            <div class="lg:col-span-4 space-y-4">
                <productCard>
                    <template #title>
                        Details
                    </template>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 gap-y-4">
                        <FloatingInput v-model="form.name" label="Name" />
                        <FloatingSelect v-model="form.category_id" label="Category" :options="categories" />
                    </div>
                    <FloatingTextarea v-model="form.description" label="Description" />
                    <DropzoneInput v-model="form.image" label="Image" />
                </productCard>
            </div>
            <div class="lg:col-span-2 space-y-4">
                <productCard>
                    <template #title>
                        Pricing
                    </template>

                    <div class="grid grid-cols-1 gap-4 gap-y-4">
                        <FloatingNumberInput v-model="form.price" label="Price" />
                        <FloatingNumberInput v-model="form.stock" label="stock" />
                        <FloatingNumberInput v-model="form.minimum_stock" label="minimum_stock" />
                    </div>
                </productCard>
            </div>
        </div>
        <!-- Footer -->
        <div class="p-4 flex justify-end gap-x-2">
            <div class="flex justify-end items-center gap-2">
                <button :data-hs-overlay="isValid ? `#${id}` : undefined" type="button"
                    class="py-2 px-3 text-nowrap inline-flex justify-center items-center text-start bg-white border border-gray-200 text-gray-800 text-sm font-medium rounded-lg shadow-sm align-middle hover:bg-gray-50 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700">
                    Cancel
                </button>

                <button type="submit" :data-hs-overlay="isValid ? `#${id}` : undefined" :disabled="!isValid"
                    class="py-2 px-3 text-nowrap inline-flex justify-center items-center gap-x-2 text-start bg-green-600 border border-green-600 text-white text-sm font-medium rounded-lg shadow-sm align-middle hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-1 focus:ring-green-300 dark:focus:ring-green-500">
                    {{ buttonLabel }}
                </button>
            </div>
        </div>
        <!-- End Footer -->
    </form>
</template>
