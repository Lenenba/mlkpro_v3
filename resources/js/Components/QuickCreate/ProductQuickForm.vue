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
    { id: 'piece', name: t('products.units.piece') },
    { id: 'hour', name: t('products.units.hour') },
    { id: 'm2', name: t('products.units.m2') },
    { id: 'other', name: t('products.units.other') },
]));

const categoryOptions = ref(Array.isArray(props.categories) ? [...props.categories] : []);

const form = reactive({
    name: '',
    category_id: categoryOptions.value[0]?.id || '',
    sku: '',
    price: 0,
    cost_price: 0,
    margin_percent: 0,
    stock: 0,
    minimum_stock: 0,
    unit: '',
    supplier_name: '',
    supplier_email: '',
    tax_rate: 0,
    is_active: true,
    description: '',
    image_url: '',
});

const errors = ref({});
const formError = ref('');
const isSubmitting = ref(false);
const priceLookupQuery = ref('');
const priceLookupResults = ref([]);
const priceLookupMeta = ref(null);
const priceLookupError = ref('');
const priceLookupLoading = ref(false);
const hasManualQuery = ref(false);

watch(() => props.categories, (next) => {
    categoryOptions.value = Array.isArray(next) ? [...next] : [];
    if (!form.category_id && categoryOptions.value.length) {
        form.category_id = categoryOptions.value[0].id;
    }
});

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

watch(
    () => form.name,
    (value) => {
        if (!hasManualQuery.value) {
            priceLookupQuery.value = value;
        }
    }
);

const markManualQuery = () => {
    hasManualQuery.value = true;
};

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
        categoryError.value = t('products.form.errors.category_name_required');
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
            categoryError.value = t('products.form.errors.category_create_failed');
        }
    } catch (error) {
        categoryError.value = error?.response?.data?.errors?.name?.[0]
            || error?.response?.data?.message
            || t('products.form.errors.category_create_failed');
    } finally {
        creatingCategory.value = false;
    }
};

watch(categoryName, (value) => {
    if (value && categoryError.value) {
        categoryError.value = '';
    }
});

const formatPrice = (value) => {
    const numeric = Number(value);
    if (Number.isNaN(numeric)) {
        return value;
    }
    return numeric.toFixed(2);
};

const applyCost = (source) => {
    const numeric = Number(source?.price ?? 0);
    if (!Number.isNaN(numeric)) {
        form.cost_price = numeric;
    }
    if (source?.name) {
        form.supplier_name = source.name;
    }
};

const applyPrice = (source) => {
    const numeric = Number(source?.price ?? 0);
    if (!Number.isNaN(numeric)) {
        form.price = numeric;
    }
    if (source?.name) {
        form.supplier_name = source.name;
    }
};

const applyImage = (source) => {
    if (source?.image_url) {
        form.image_url = source.image_url;
    }
};

const applyBestPrice = () => {
    const best = priceLookupResults.value[0];
    if (!best) {
        priceLookupError.value = t('products.price_lookup.no_live_prices');
        return;
    }
    if (!form.name?.trim()) {
        form.name = best.title || priceLookupQuery.value?.trim() || form.name;
    }
    if (!form.description?.trim() && best.title) {
        form.description = best.title;
    }
    applyCost(best);
    applyPrice(best);
    applyImage(best);
};

const clearLookup = () => {
    priceLookupResults.value = [];
    priceLookupMeta.value = null;
    priceLookupError.value = '';
};

const searchPrices = async () => {
    const query = priceLookupQuery.value?.trim();
    priceLookupError.value = '';
    priceLookupResults.value = [];
    priceLookupMeta.value = null;

    if (!query) {
        priceLookupError.value = t('products.price_lookup.search_required');
        return;
    }

    priceLookupLoading.value = true;
    try {
        const response = await axios.get(route('product.price-lookup'), {
            params: { query },
        });
        const data = response?.data || {};
        priceLookupMeta.value = data;
        priceLookupResults.value = Array.isArray(data.sources) ? data.sources : [];
        if (!priceLookupResults.value.length) {
            priceLookupError.value = t('products.price_lookup.no_live_prices');
        }
    } catch (error) {
        priceLookupError.value = error?.response?.data?.message || t('products.price_lookup.search_failed');
    } finally {
        priceLookupLoading.value = false;
    }
};

const isValid = computed(() => {
    return (
        form.name.trim() !== '' &&
        form.category_id &&
        form.price >= 0 &&
        form.stock >= 0 &&
        form.minimum_stock >= 0
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
    form.sku = '';
    form.price = 0;
    form.cost_price = 0;
    form.margin_percent = 0;
    form.stock = 0;
    form.minimum_stock = 0;
    form.unit = '';
    form.supplier_name = '';
    form.supplier_email = '';
    form.tax_rate = 0;
    form.is_active = true;
    form.description = '';
    form.image_url = '';
    priceLookupQuery.value = '';
    priceLookupResults.value = [];
    priceLookupMeta.value = null;
    priceLookupError.value = '';
    hasManualQuery.value = false;
    categoryName.value = '';
    categoryError.value = '';
    showCategoryForm.value = false;
};

const submit = async () => {
    if (isSubmitting.value) {
        return;
    }

    if (!isValid.value) {
        formError.value = t('products.form.errors.required_fields');
        return;
    }

    errors.value = {};
    formError.value = '';
    isSubmitting.value = true;

    const payload = {
        name: form.name,
        category_id: form.category_id,
        sku: form.sku,
        price: form.price,
        cost_price: form.cost_price,
        margin_percent: form.margin_percent,
        stock: form.stock,
        minimum_stock: form.minimum_stock,
        unit: form.unit,
        supplier_name: form.supplier_name,
        supplier_email: form.supplier_email,
        tax_rate: form.tax_rate,
        is_active: form.is_active,
        description: form.description,
        image_url: form.image_url,
    };

    try {
        const response = await axios.post(route('product.quick.store'), payload);
        emit('created', response.data);
        closeOverlay();
        resetForm();
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data?.errors || {};
        } else {
            formError.value = t('products.form.errors.save_failed');
        }
    } finally {
        isSubmitting.value = false;
    }
};
</script>

<template>
    <form @submit.prevent="submit" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <FloatingInput v-model="form.name" :label="$t('products.form.name')" :required="true" />
            <FloatingSelect v-model="form.category_id" :label="$t('products.form.category')" :options="categoryOptions" :required="true" />
            <div class="md:col-span-2 space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('products.form.category_hint') }}</p>
                    <button
                        type="button"
                        class="text-xs font-semibold text-green-700 hover:text-green-800 dark:text-green-400"
                        @click="showCategoryForm = !showCategoryForm"
                    >
                        {{ showCategoryForm ? $t('products.form.category_hide') : $t('products.form.category_add') }}
                    </button>
                </div>
                <p v-if="!categoryOptions.length" class="text-xs text-amber-600 dark:text-amber-300">
                    {{ $t('products.form.category_empty') }}
                </p>
                <div v-if="showCategoryForm" class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <FloatingInput v-model="categoryName" :label="$t('products.form.category_new')" />
                        <p v-if="categoryError" class="mt-1 text-xs text-red-600">{{ categoryError }}</p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                        :disabled="creatingCategory"
                        @click="createCategory"
                    >
                        {{ $t('products.form.category_create') }}
                    </button>
                </div>
            </div>
            <FloatingInput v-model="form.sku" :label="$t('products.form.sku')" />
            <FloatingSelect v-model="form.unit" :label="$t('products.form.unit')" :options="unitOptions" />
            <FloatingInput v-model="form.supplier_name" :label="$t('products.form.supplier')" />
            <FloatingInput v-model="form.supplier_email" :label="$t('products.form.supplier_email')" />
            <FloatingNumberInput v-model="form.tax_rate" :label="$t('products.form.tax_rate')" :step="0.01" />
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <FloatingNumberInput v-model="form.price" :label="$t('products.form.price')" :step="0.01" :required="true" />
            <FloatingNumberInput v-model="form.cost_price" :label="$t('products.form.cost_price')" :step="0.01" />
            <FloatingNumberInput v-model="form.margin_percent" :label="$t('products.form.margin')" :step="0.01" />
            <FloatingNumberInput v-model="form.stock" :label="$t('products.form.stock')" :required="true" />
            <FloatingNumberInput v-model="form.minimum_stock" :label="$t('products.form.minimum_stock')" :required="true" />
        </div>

        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
            <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">{{ $t('products.price_lookup.title') }}</div>
            <div class="mt-2 space-y-2">
                <FloatingInput
                    v-model="priceLookupQuery"
                    :label="$t('products.price_lookup.search_term')"
                    @update:modelValue="markManualQuery"
                />
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center rounded-sm bg-green-600 px-3 py-2 text-[11px] font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                        :disabled="priceLookupLoading"
                        @click="searchPrices"
                    >
                        {{ $t('products.price_lookup.search_action') }}
                    </button>
                    <button
                        v-if="priceLookupResults.length"
                        type="button"
                        class="inline-flex items-center rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-100 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-200 dark:hover:bg-emerald-500/20"
                        :disabled="priceLookupLoading"
                        @click="applyBestPrice"
                    >
                        {{ $t('products.price_lookup.apply_best') }}
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center rounded-sm border border-stone-200 px-3 py-2 text-[11px] font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                        :disabled="priceLookupLoading"
                        @click="clearLookup"
                    >
                        {{ $t('products.price_lookup.clear') }}
                    </button>
                </div>

                <div v-if="priceLookupMeta?.provider" class="text-[11px] text-stone-500 dark:text-neutral-400">
                    {{ $t('products.price_lookup.provider') }}: <span class="font-semibold">{{ priceLookupMeta.provider }}</span>
                </div>
                <div v-if="form.image_url" class="flex items-center gap-2 text-[11px] text-stone-500 dark:text-neutral-400">
                    <img
                        :src="form.image_url"
                        :alt="$t('products.price_lookup.selected_image')"
                        class="h-8 w-8 rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                    />
                    <span>{{ $t('products.price_lookup.selected_image') }}</span>
                    <button
                        type="button"
                        class="text-[10px] text-stone-500 hover:text-stone-700 dark:text-neutral-400 dark:hover:text-neutral-200"
                        @click="form.image_url = ''"
                    >
                        {{ $t('products.actions.remove') }}
                    </button>
                </div>
                <div v-if="priceLookupError" class="text-[11px] text-rose-600 dark:text-rose-300">
                    {{ priceLookupError }}
                </div>
                <div v-if="priceLookupLoading" class="text-[11px] text-stone-500 dark:text-neutral-400">
                    {{ $t('products.price_lookup.searching') }}
                </div>

                <div v-if="priceLookupResults.length" class="space-y-2">
                    <div
                        v-for="(source, index) in priceLookupResults"
                        :key="source.url || source.name"
                        class="rounded-sm border border-stone-200 bg-white p-2 dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-start gap-2">
                                <img
                                    v-if="source.image_url"
                                    :src="source.image_url"
                                    :alt="source.title || source.name"
                                    class="h-8 w-8 rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                                />
                                <div>
                                    <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                        {{ source.name }}
                                        <span v-if="index === 0" class="ml-2 text-[10px] text-emerald-700 dark:text-emerald-300">
                                            {{ $t('products.price_lookup.best_price') }}
                                        </span>
                                    </div>
                                    <div v-if="source.title" class="mt-1 text-[10px] text-stone-400 dark:text-neutral-500">
                                        {{ source.title }}
                                    </div>
                                </div>
                            </div>
                            <div class="text-[11px] font-semibold text-stone-700 dark:text-neutral-200">
                                ${{ formatPrice(source.price) }}
                            </div>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <a
                                v-if="source.url"
                                :href="source.url"
                                target="_blank"
                                rel="noopener"
                                class="text-[11px] text-green-700 hover:underline dark:text-green-400"
                            >
                                {{ $t('products.price_lookup.open_link') }}
                            </a>
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 px-2 py-1 text-[10px] font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                @click="applyCost(source)"
                            >
                                {{ $t('products.price_lookup.use_cost') }}
                            </button>
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 px-2 py-1 text-[10px] font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                @click="applyPrice(source)"
                            >
                                {{ $t('products.price_lookup.use_price') }}
                            </button>
                            <button
                                v-if="source.image_url"
                                type="button"
                                class="rounded-sm border border-stone-200 px-2 py-1 text-[10px] font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                @click="applyImage(source)"
                            >
                                {{ $t('products.price_lookup.use_image') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <FloatingTextarea v-model="form.description" :label="$t('products.form.description')" />

        <div class="flex items-center gap-2">
            <Checkbox v-model:checked="form.is_active" />
            <span class="text-sm text-stone-600 dark:text-neutral-400">{{ $t('products.status.active') }}</span>
        </div>

        <div v-if="errorMessages.length" class="rounded-sm border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            <div v-for="(message, index) in errorMessages" :key="index">
                {{ message }}
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" :data-hs-overlay="overlayId || undefined"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                {{ $t('products.actions.cancel') }}
            </button>
            <button type="submit" :disabled="isSubmitting"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                {{ $t('products.actions.create_product') }}
            </button>
        </div>
    </form>
</template>
