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
    sku: '',
    price: 0,
    cost_price: 0,
    margin_percent: 0,
    stock: 0,
    minimum_stock: 0,
    unit: '',
    supplier_name: '',
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
    if (!form.category_id && next?.length) {
        form.category_id = next[0].id;
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
        priceLookupError.value = 'No live prices found.';
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
        priceLookupError.value = 'Enter a product name to search.';
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
            priceLookupError.value = 'No live prices found.';
        }
    } catch (error) {
        priceLookupError.value = error?.response?.data?.message || 'Price lookup failed.';
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
    form.category_id = props.categories[0]?.id || '';
    form.sku = '';
    form.price = 0;
    form.cost_price = 0;
    form.margin_percent = 0;
    form.stock = 0;
    form.minimum_stock = 0;
    form.unit = '';
    form.supplier_name = '';
    form.tax_rate = 0;
    form.is_active = true;
    form.description = '';
    form.image_url = '';
    priceLookupQuery.value = '';
    priceLookupResults.value = [];
    priceLookupMeta.value = null;
    priceLookupError.value = '';
    hasManualQuery.value = false;
};

const submit = async () => {
    if (isSubmitting.value) {
        return;
    }

    if (!isValid.value) {
        formError.value = 'Please fill all required fields.';
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
            formError.value = 'Unable to save product. Please try again.';
        }
    } finally {
        isSubmitting.value = false;
    }
};
</script>

<template>
    <form @submit.prevent="submit" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <FloatingInput v-model="form.name" label="Name" :required="true" />
            <FloatingSelect v-model="form.category_id" label="Category" :options="categories" :required="true" />
            <FloatingInput v-model="form.sku" label="SKU" />
            <FloatingSelect v-model="form.unit" label="Unit" :options="unitOptions" />
            <FloatingInput v-model="form.supplier_name" label="Supplier" />
            <FloatingNumberInput v-model="form.tax_rate" label="Tax rate (%)" :step="0.01" />
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <FloatingNumberInput v-model="form.price" label="Price" :step="0.01" :required="true" />
            <FloatingNumberInput v-model="form.cost_price" label="Cost price" :step="0.01" />
            <FloatingNumberInput v-model="form.margin_percent" label="Margin (%)" :step="0.01" />
            <FloatingNumberInput v-model="form.stock" label="Stock" :required="true" />
            <FloatingNumberInput v-model="form.minimum_stock" label="Minimum stock" :required="true" />
        </div>

        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
            <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">Price lookup</div>
            <div class="mt-2 space-y-2">
                <FloatingInput
                    v-model="priceLookupQuery"
                    label="Search term"
                    @update:modelValue="markManualQuery"
                />
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center rounded-sm bg-green-600 px-3 py-2 text-[11px] font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                        :disabled="priceLookupLoading"
                        @click="searchPrices"
                    >
                        Search prices
                    </button>
                    <button
                        v-if="priceLookupResults.length"
                        type="button"
                        class="inline-flex items-center rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-100 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-200 dark:hover:bg-emerald-500/20"
                        :disabled="priceLookupLoading"
                        @click="applyBestPrice"
                    >
                        Apply best price
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center rounded-sm border border-stone-200 px-3 py-2 text-[11px] font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                        :disabled="priceLookupLoading"
                        @click="clearLookup"
                    >
                        Clear
                    </button>
                </div>

                <div v-if="priceLookupMeta?.provider" class="text-[11px] text-stone-500 dark:text-neutral-400">
                    Provider: <span class="font-semibold">{{ priceLookupMeta.provider }}</span>
                </div>
                <div v-if="form.image_url" class="flex items-center gap-2 text-[11px] text-stone-500 dark:text-neutral-400">
                    <img
                        :src="form.image_url"
                        alt="Selected"
                        class="h-8 w-8 rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                    />
                    <span>Selected image</span>
                    <button
                        type="button"
                        class="text-[10px] text-stone-500 hover:text-stone-700 dark:text-neutral-400 dark:hover:text-neutral-200"
                        @click="form.image_url = ''"
                    >
                        Remove
                    </button>
                </div>
                <div v-if="priceLookupError" class="text-[11px] text-rose-600 dark:text-rose-300">
                    {{ priceLookupError }}
                </div>
                <div v-if="priceLookupLoading" class="text-[11px] text-stone-500 dark:text-neutral-400">
                    Searching suppliers...
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
                                            Best price
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
                                Open link
                            </a>
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 px-2 py-1 text-[10px] font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                @click="applyCost(source)"
                            >
                                Use as cost
                            </button>
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 px-2 py-1 text-[10px] font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                @click="applyPrice(source)"
                            >
                                Use as price
                            </button>
                            <button
                                v-if="source.image_url"
                                type="button"
                                class="rounded-sm border border-stone-200 px-2 py-1 text-[10px] font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                @click="applyImage(source)"
                            >
                                Use image
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <FloatingTextarea v-model="form.description" label="Description" />

        <div class="flex items-center gap-2">
            <Checkbox v-model:checked="form.is_active" />
            <span class="text-sm text-stone-600 dark:text-neutral-400">Active</span>
        </div>

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
            <button type="submit" :disabled="isSubmitting"
                class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                Create product
            </button>
        </div>
    </form>
</template>
