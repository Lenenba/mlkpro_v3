<script setup>
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import productCard from '@/Components/UI/ProductCard2.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingNumberInput from '@/Components/FloatingNumberInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import DropzoneInput from '@/Components/DropzoneInput.vue';
import Checkbox from '@/Components/Checkbox.vue';
import MultiImageInput from '@/Components/MultiImageInput.vue';
import ValidationSummary from '@/Components/ValidationSummary.vue';

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
    image_url: props.product?.image_url || '',
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

const priceLookupQuery = ref(props.product?.name || '');
const priceLookupResults = ref([]);
const priceLookupMeta = ref(null);
const priceLookupError = ref(null);
const priceLookupLoading = ref(false);
const hasManualQuery = ref(false);

const markManualQuery = () => {
    hasManualQuery.value = true;
};

watch(
    () => form.name,
    (value) => {
        if (!hasManualQuery.value) {
            priceLookupQuery.value = value;
        }
    }
);

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
        form.image = source.image_url;
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
    priceLookupError.value = null;
};

const searchPrices = async () => {
    const query = priceLookupQuery.value?.trim();
    priceLookupError.value = null;
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
        form.setError('form', 'Please fill all required fields.');
        return;
    }

    form.clearErrors('form');

    const routeName = props.product?.id ? 'product.update' : 'product.store';
    const routeParams = props.product?.id ? props.product.id : undefined;

    form
        .transform((data) => ({
            ...data,
            image: data.image instanceof File ? data.image : null,
            image_url: data.image instanceof File ? null : (data.image_url || (typeof data.image === 'string' ? data.image : null)),
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
        });
};

const cancel = () => {
    form.reset();
};

const buttonLabel = computed(() => (props.product ? 'Update Product' : 'Create Product'));
</script>


<template>
    <form @submit.prevent="submit">
        <ValidationSummary :errors="form.errors" />
        <!-- Products Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-6 gap-5">
            <div class="lg:col-span-4 space-y-4 min-w-0">
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
            <div class="lg:col-span-2 space-y-4 min-w-0">
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

                <productCard>
                    <template #title>
                        Price lookup
                    </template>

                    <div class="space-y-3">
                        <FloatingInput
                            v-model="priceLookupQuery"
                            label="Search term"
                            @update:modelValue="markManualQuery"
                        />

                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                                :disabled="priceLookupLoading"
                                @click="searchPrices"
                            >
                                Search prices
                            </button>
                            <button
                                v-if="priceLookupResults.length"
                                type="button"
                                class="inline-flex items-center rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-200 dark:hover:bg-emerald-500/20"
                                :disabled="priceLookupLoading"
                                @click="applyBestPrice"
                            >
                                Apply best price
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                :disabled="priceLookupLoading"
                                @click="clearLookup"
                            >
                                Clear
                            </button>
                        </div>

                        <div v-if="priceLookupMeta?.provider" class="text-xs text-stone-500 dark:text-neutral-400">
                            Provider:
                            <span class="font-semibold text-stone-600 dark:text-neutral-300">{{ priceLookupMeta.provider }}</span>
                            <span v-if="priceLookupMeta.provider_ready === false" class="text-amber-600 dark:text-amber-300">
                                (not configured)
                            </span>
                        </div>
                        <div v-if="priceLookupMeta?.preferred_suppliers?.length" class="text-xs text-stone-500 dark:text-neutral-400">
                            Preferred: {{ priceLookupMeta.preferred_suppliers.join(', ') }}
                        </div>
                        <div v-if="priceLookupMeta?.enabled_suppliers?.length" class="text-xs text-stone-500 dark:text-neutral-400">
                            Enabled: {{ priceLookupMeta.enabled_suppliers.join(', ') }}
                        </div>

                        <div v-if="priceLookupLoading" class="text-xs text-stone-500 dark:text-neutral-400">
                            Searching suppliers...
                        </div>
                        <div v-if="priceLookupError" class="text-xs text-rose-600 dark:text-rose-300">
                            {{ priceLookupError }}
                        </div>

                        <div v-if="priceLookupResults.length" class="space-y-3">
                            <div
                                v-for="(source, index) in priceLookupResults"
                                :key="source.url || source.name"
                                class="rounded-sm border border-stone-200 bg-white p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex items-start gap-2">
                                        <img
                                            v-if="source.image_url"
                                            :src="source.image_url"
                                            :alt="source.title || source.name"
                                            class="h-10 w-10 rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                                        />
                                        <div>
                                            <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                                {{ source.name }}
                                                <span
                                                    v-if="index === 0"
                                                    class="ml-2 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"
                                                >
                                                    Best price
                                                </span>
                                            </div>
                                            <div v-if="source.title" class="mt-1 text-[10px] text-stone-400 dark:text-neutral-500">
                                                {{ source.title }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
                                        ${{ formatPrice(source.price) }}
                                    </div>
                                </div>
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <a
                                        v-if="source.url"
                                        :href="source.url"
                                        target="_blank"
                                        rel="noopener"
                                        class="text-green-700 hover:underline dark:text-green-400"
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

                <button type="submit" :disabled="form.processing"
                    class="py-2 px-3 text-nowrap inline-flex justify-center items-center gap-x-2 text-start bg-green-600 border border-green-600 text-white text-sm font-medium rounded-sm shadow-sm align-middle hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                    {{ buttonLabel }}
                </button>
            </div>
        </div>
        <!-- End Footer -->
    </form>
</template>
