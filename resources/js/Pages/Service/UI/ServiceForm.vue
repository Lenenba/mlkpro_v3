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
    materialProducts: {
        type: Array,
        default: () => [],
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

const buildMaterial = (material = {}, index = 0) => ({
    id: material.id ?? null,
    product_id: material.product_id ?? '',
    label: material.label ?? '',
    description: material.description ?? '',
    unit: material.unit ?? '',
    quantity: material.quantity ?? 1,
    unit_price: material.unit_price ?? 0,
    billable: material.billable ?? true,
    sort_order: material.sort_order ?? index,
});

const form = useForm({
    name: props.service?.name || '',
    category_id: props.service?.category_id || props.categories?.[0]?.id || '',
    unit: props.service?.unit || '',
    price: props.service?.price || 0,
    tax_rate: props.service?.tax_rate || 0,
    is_active: props.service?.is_active ?? true,
    description: props.service?.description || '',
    materials: (props.service?.service_materials || []).map((material, index) =>
        buildMaterial(material, index)
    ),
});

const unitOptions = [
    { id: 'piece', name: 'Piece' },
    { id: 'hour', name: 'Hour' },
    { id: 'm2', name: 'm2' },
    { id: 'other', name: 'Other' },
];

const materialOptions = computed(() => [
    { id: '', name: 'Custom' },
    ...props.materialProducts.map((product) => ({
        id: product.id,
        name: product.name,
    })),
]);

const materialProductMap = computed(() => {
    const map = new Map();
    props.materialProducts.forEach((product) => {
        map.set(product.id, product);
    });
    return map;
});

const addMaterial = () => {
    form.materials.push(buildMaterial({}, form.materials.length));
};

const removeMaterial = (index) => {
    form.materials.splice(index, 1);
};

const applyMaterialDefaults = (material) => {
    if (!material.product_id) {
        return;
    }
    const product = materialProductMap.value.get(Number(material.product_id));
    if (!product) {
        return;
    }
    if (!material.label) {
        material.label = product.name;
    }
    if (!material.unit) {
        material.unit = product.unit || '';
    }
    if (!material.unit_price) {
        material.unit_price = product.price || 0;
    }
};

const normalizeMaterials = () => {
    form.materials = form.materials
        .map((material, index) => ({
            ...material,
            product_id: material.product_id || null,
            sort_order: index,
        }))
        .filter((material) => material.label || material.product_id);
};

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

    normalizeMaterials();

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

        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">Materials</h3>
                <button type="button" @click="addMaterial"
                    class="py-1.5 px-2.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                    Add material
                </button>
            </div>

            <div v-if="form.materials.length" class="space-y-3">
                <div v-for="(material, index) in form.materials" :key="material.id || index"
                    class="rounded-sm border border-stone-200 bg-stone-50 p-3 space-y-3 dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <FloatingSelect
                            v-model="material.product_id"
                            :options="materialOptions"
                            label="Product"
                            @update:modelValue="applyMaterialDefaults(material)"
                        />
                        <FloatingInput v-model="material.label" label="Label" />
                        <FloatingNumberInput v-model="material.quantity" label="Quantity" />
                        <FloatingNumberInput v-model="material.unit_price" label="Unit price" />
                        <FloatingInput v-model="material.unit" label="Unit" />
                        <div class="flex items-center gap-2 p-2 rounded-sm border border-stone-200 bg-white dark:bg-neutral-900 dark:border-neutral-700">
                            <Checkbox v-model:checked="material.billable" />
                            <span class="text-sm text-stone-600 dark:text-neutral-400">Billable</span>
                        </div>
                    </div>

                    <FloatingTextarea v-model="material.description" label="Description (optional)" />

                    <div class="flex justify-end">
                        <button type="button" @click="removeMaterial(index)"
                            class="py-1.5 px-2.5 text-xs font-medium rounded-sm border border-red-200 bg-white text-red-600 hover:bg-red-50 dark:bg-neutral-800 dark:border-red-500/40 dark:text-red-400">
                            Remove
                        </button>
                    </div>
                </div>
            </div>
            <p v-else class="text-xs text-stone-500 dark:text-neutral-500">
                No materials yet.
            </p>
        </div>

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
