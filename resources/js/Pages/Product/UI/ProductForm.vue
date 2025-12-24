<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import productCard from '@/Components/UI/ProductCard2.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingNumberInput from '@/Components/FloatingNumberInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import DropzoneInput from '@/Components/DropzoneInput.vue';
import Checkbox from '@/Components/Checkbox.vue';
import MultiImageInput from '@/Components/MultiImageInput.vue';

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
        default: null,
    },
});

const emit = defineEmits(['submitted']); // Déclare l'événement "submitted"
const overlayTarget = computed(() => (props.id ? `#${props.id}` : null));

// Initialize the form
const form = useForm({
    name: props.product?.name || '',
    category_id: props.product?.category_id || '',
    stock: props.product?.stock || 0,
    price: props.product?.price || 0,
    cost_price: props.product?.cost_price || 0,
    margin_percent: props.product?.margin_percent || 0,
    minimum_stock: props.product?.minimum_stock || 0,
    description: props.product?.description || '',
    image: props.product?.image_url || props.product?.image || '',
    images: [],
    remove_image_ids: [],
    sku: props.product?.sku || '',
    barcode: props.product?.barcode || '',
    unit: props.product?.unit || '',
    supplier_name: props.product?.supplier_name || '',
    tax_rate: props.product?.tax_rate || 0,
    is_active: props.product?.is_active ?? true,
});

const unitOptions = [
    { id: 'piece', name: 'Piece' },
    { id: 'hour', name: 'Hour' },
    { id: 'm2', name: 'm2' },
    { id: 'other', name: 'Other' },
];

const existingImages = computed(() => props.product?.images || []);
const marginPreview = computed(() => {
    if (!form.price) {
        return 0;
    }
    return ((form.price - form.cost_price) / form.price) * 100;
});

watch([() => form.price, () => form.cost_price], () => {
    if (form.price > 0) {
        form.margin_percent = Number(marginPreview.value.toFixed(2));
    }
});

// Validator function for the form
const isValid = computed(() => {
    return (
        form.name.trim() !== '' &&
        form.category_id &&
        form.stock >= 0 &&
        form.price >= 0 &&
        form.minimum_stock >= 0
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

    form
        .transform((data) => ({
            ...data,
            image: data.image instanceof File ? data.image : null,
            images: data.images?.filter((file) => file instanceof File) || [],
            remove_image_ids: data.remove_image_ids || [],
        }))
        [props.product?.id ? 'put' : 'post'](route(routeName, routeParams), {
            onSuccess: () => {
                console.log('Product saved successfully!');
                emit('submitted');
                if (overlayTarget.value && window.HSOverlay) {
                    window.HSOverlay.close(overlayTarget.value);
                }
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
                        <FloatingInput v-model="form.sku" label="SKU" />
                        <FloatingInput v-model="form.barcode" label="Barcode" />
                        <FloatingSelect v-model="form.unit" label="Unit" :options="unitOptions" />
                        <FloatingInput v-model="form.supplier_name" label="Supplier" />
                        <FloatingNumberInput v-model="form.tax_rate" label="Tax rate (%)" :step="0.01" />
                        <div class="flex items-center gap-x-2">
                            <Checkbox v-model:checked="form.is_active" />
                            <span class="text-sm text-stone-600 dark:text-neutral-400">Active</span>
                        </div>
                    </div>
                    <FloatingTextarea v-model="form.description" label="Description" />
                    <DropzoneInput v-model="form.image" label="Primary image" />
                    <MultiImageInput
                        v-model:files="form.images"
                        v-model:removedIds="form.remove_image_ids"
                        :existing="existingImages"
                    />
                </productCard>
            </div>
            <div class="lg:col-span-2 space-y-4">
                <productCard>
                    <template #title>
                        Pricing
                    </template>

                    <div class="grid grid-cols-1 gap-4 gap-y-4">
                        <FloatingNumberInput v-model="form.price" label="Price" :step="0.01" />
                        <FloatingNumberInput v-model="form.cost_price" label="Cost price" :step="0.01" />
                        <FloatingNumberInput v-model="form.margin_percent" label="Margin (%)" :step="0.01" />
                        <FloatingNumberInput v-model="form.stock" label="Stock" />
                        <FloatingNumberInput v-model="form.minimum_stock" label="Minimum stock" />
                    </div>
                </productCard>
            </div>
        </div>
        <!-- Footer -->
        <div class="p-4 flex justify-end gap-x-2">
            <div class="flex justify-end items-center gap-2">
                <button :data-hs-overlay="overlayTarget || undefined" type="button" @click="cancel"
                    class="py-2 px-3 text-nowrap inline-flex justify-center items-center text-start bg-white border border-stone-200 text-stone-700 text-sm font-medium rounded-sm shadow-sm align-middle hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                    Cancel
                </button>

                <button type="submit" :disabled="!isValid"
                    class="py-2 px-3 text-nowrap inline-flex justify-center items-center gap-x-2 text-start bg-green-600 border border-green-600 text-white text-sm font-medium rounded-sm shadow-sm align-middle hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                    {{ buttonLabel }}
                </button>
            </div>
        </div>
        <!-- End Footer -->
    </form>
</template>
