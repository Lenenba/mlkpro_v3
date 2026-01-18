<script setup>
import { computed, reactive, ref, watch } from 'vue';
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
    overlayId: {
        type: String,
        default: null,
    },
});

const emit = defineEmits(['created', 'category-created']);

const { t } = useI18n();

const unitOptions = computed(() => ([
    { id: 'piece', name: t('services.units.piece') },
    { id: 'hour', name: t('services.units.hour') },
    { id: 'm2', name: t('services.units.m2') },
    { id: 'other', name: t('services.units.other') },
]));

const categoryOptions = ref(Array.isArray(props.categories) ? [...props.categories] : []);

const form = reactive({
    name: '',
    category_id: categoryOptions.value[0]?.id || '',
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
    categoryOptions.value = Array.isArray(next) ? [...next] : [];
    if (!form.category_id && categoryOptions.value.length) {
        form.category_id = categoryOptions.value[0].id;
    }
});

const showCategoryForm = ref(false);
const categoryName = ref('');
const categoryError = ref('');
const creatingCategory = ref(false);

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
    emit('category-created', category);
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
    form.category_id = categoryOptions.value[0]?.id || '';
    form.unit = '';
    form.price = 0;
    form.tax_rate = 0;
    form.is_active = true;
    form.description = '';
    categoryName.value = '';
    categoryError.value = '';
    showCategoryForm.value = false;
};

const submit = async () => {
    if (isSubmitting.value) {
        return;
    }

    if (!isValid.value) {
        formError.value = t('services.form.errors.required_fields');
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
            formError.value = t('services.form.errors.save_failed');
        }
    } finally {
        isSubmitting.value = false;
    }
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
            <div class="flex items-center gap-2">
                <Checkbox v-model:checked="form.is_active" />
                <span class="text-sm text-stone-600 dark:text-neutral-400">{{ $t('services.status.active') }}</span>
            </div>
        </div>

        <FloatingTextarea v-model="form.description" :label="$t('services.form.description')" />

        <div v-if="errorMessages.length" class="rounded-sm border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            <div v-for="(message, index) in errorMessages" :key="index">
                {{ message }}
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" :data-hs-overlay="overlayId || undefined"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                {{ $t('services.actions.cancel') }}
            </button>
            <button type="submit" :disabled="isSubmitting"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                {{ $t('services.actions.create_service') }}
            </button>
        </div>
    </form>
</template>

