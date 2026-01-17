<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingNumberInput from '@/Components/FloatingNumberInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { useI18n } from 'vue-i18n';

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

const { t } = useI18n();

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

const unitOptions = computed(() => ([
    { id: 'piece', name: t('services.units.piece') },
    { id: 'hour', name: t('services.units.hour') },
    { id: 'm2', name: t('services.units.m2') },
    { id: 'other', name: t('services.units.other') },
]));

const categoryOptions = ref(Array.isArray(props.categories) ? [...props.categories] : []);

watch(() => props.categories, (next) => {
    categoryOptions.value = Array.isArray(next) ? [...next] : [];
});

const showCategoryForm = ref(false);
const categoryName = ref('');
const categoryError = ref('');
const creatingCategory = ref(false);

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

const addCategoryOption = (category) => {
    if (!category?.id) {
        return;
    }
    const exists = categoryOptions.value.some((item) => Number(item.id) === Number(category.id));
    if (!exists) {
        categoryOptions.value = [...categoryOptions.value, { id: category.id, name: category.name }]
            .sort((a, b) => String(a.name).localeCompare(String(b.name)));
    }
    form.category_id = category.id;
};

const createCategory = async () => {
    const name = categoryName.value.trim();
    if (!name) {
        categoryError.value = t('services.form.errors.category_name_required');
        return;
    }

    creatingCategory.value = true;
    categoryError.value = '';

    try {
        const response = await axios.post(route('settings.categories.store'), {
            name,
        }, {
            headers: { Accept: 'application/json' },
        });
        const created = response?.data?.category;
        if (created) {
            addCategoryOption(created);
            categoryName.value = '';
            showCategoryForm.value = false;
        } else {
            categoryError.value = t('services.form.errors.category_create_failed');
        }
    } catch (error) {
        categoryError.value = error?.response?.data?.errors?.name?.[0]
            || error?.response?.data?.message
            || t('services.form.errors.category_create_failed');
    } finally {
        creatingCategory.value = false;
    }
};

watch(categoryName, (value) => {
    if (value && categoryError.value) {
        categoryError.value = '';
    }
});

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
        form.setError('form', t('services.form.errors.required_fields'));
        return;
    }

    form.clearErrors('form');

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
            <FloatingInput v-model="form.name" :label="$t('services.form.name')" :required="true" />
            <FloatingSelect v-model="form.category_id" :label="$t('services.form.category')" :options="categoryOptions" :required="true" />
            <div class="md:col-span-2 space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('services.form.category_hint') }}</p>
                    <button
                        type="button"
                        class="text-xs font-semibold text-green-700 hover:text-green-800 dark:text-green-400"
                        @click="showCategoryForm = !showCategoryForm"
                    >
                        {{ showCategoryForm ? $t('services.form.category_hide') : $t('services.form.category_add') }}
                    </button>
                </div>
                <p v-if="!categoryOptions.length" class="text-xs text-amber-600 dark:text-amber-300">
                    {{ $t('services.form.category_empty') }}
                </p>
                <div v-if="showCategoryForm" class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <FloatingInput v-model="categoryName" :label="$t('services.form.category_new')" />
                        <p v-if="categoryError" class="mt-1 text-xs text-red-600">{{ categoryError }}</p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                        :disabled="creatingCategory"
                        @click="createCategory"
                    >
                        {{ $t('services.form.category_create') }}
                    </button>
                </div>
            </div>
            <FloatingSelect v-model="form.unit" :label="$t('services.form.unit')" :options="unitOptions" />
            <FloatingNumberInput v-model="form.tax_rate" :label="$t('services.form.tax_rate')" :step="0.01" />
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <FloatingNumberInput v-model="form.price" :label="$t('services.form.price')" :step="0.01" :required="true" />
            <div class="flex items-center gap-2 p-2 rounded-sm border border-stone-200 bg-white dark:bg-neutral-900 dark:border-neutral-700">
                <Checkbox v-model:checked="form.is_active" />
                <span class="text-sm text-stone-600 dark:text-neutral-400">{{ $t('services.status.active') }}</span>
            </div>
        </div>

        <FloatingTextarea v-model="form.description" :label="$t('services.form.description')" />

        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">{{ $t('services.materials.title') }}</h3>
                <button type="button" @click="addMaterial"
                    class="py-1.5 px-2.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                    {{ $t('services.materials.add') }}
                </button>
            </div>

            <div v-if="form.materials.length" class="space-y-3">
                <div v-for="(material, index) in form.materials" :key="material.id || index"
                    class="rounded-sm border border-stone-200 bg-stone-50 p-3 space-y-3 dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <FloatingSelect
                            v-model="material.product_id"
                            :options="materialOptions"
                            :label="$t('services.materials.product')"
                            @update:modelValue="applyMaterialDefaults(material)"
                        />
                        <FloatingInput v-model="material.label" :label="$t('services.materials.label')" />
                        <FloatingNumberInput v-model="material.quantity" :label="$t('services.materials.quantity')" :step="0.01" />
                        <FloatingNumberInput v-model="material.unit_price" :label="$t('services.materials.unit_price')" :step="0.01" />
                        <FloatingInput v-model="material.unit" :label="$t('services.materials.unit')" />
                        <div class="flex items-center gap-2 p-2 rounded-sm border border-stone-200 bg-white dark:bg-neutral-900 dark:border-neutral-700">
                            <Checkbox v-model:checked="material.billable" />
                            <span class="text-sm text-stone-600 dark:text-neutral-400">{{ $t('services.materials.billable') }}</span>
                        </div>
                    </div>

                    <FloatingTextarea v-model="material.description" :label="$t('services.materials.description_optional')" />

                    <div class="flex justify-end">
                        <button type="button" @click="removeMaterial(index)"
                            class="py-1.5 px-2.5 text-xs font-medium rounded-sm border border-red-200 bg-white text-red-600 hover:bg-red-50 dark:bg-neutral-800 dark:border-red-500/40 dark:text-red-400">
                            {{ $t('services.materials.remove') }}
                        </button>
                    </div>
                </div>
            </div>
            <p v-else class="text-xs text-stone-500 dark:text-neutral-500">
                {{ $t('services.materials.empty') }}
            </p>
        </div>

        <div v-if="Object.keys(form.errors).length" class="rounded-sm border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            <div v-for="(messages, field) in form.errors" :key="field">
                {{ Array.isArray(messages) ? messages[0] : messages }}
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" :data-hs-overlay="overlayTarget || undefined"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                {{ $t('services.actions.cancel') }}
            </button>
            <button type="submit" :disabled="form.processing"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                {{ props.service ? $t('services.actions.update_service') : $t('services.actions.create_service') }}
            </button>
        </div>
    </form>
</template>
