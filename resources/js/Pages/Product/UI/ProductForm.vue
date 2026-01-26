<script setup>
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { humanizeDate } from '@/utils/date';
import productCard from '@/Components/UI/ProductCard2.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingNumberInput from '@/Components/FloatingNumberInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import DropzoneInput from '@/Components/DropzoneInput.vue';
import Checkbox from '@/Components/Checkbox.vue';
import MultiImageInput from '@/Components/MultiImageInput.vue';
import ValidationSummary from '@/Components/ValidationSummary.vue';
import { useI18n } from 'vue-i18n';

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
const initialImageUrl = ref(props.product?.image_url || '');
const initialImage = ref(props.product?.image_url || props.product?.image || '');

const { t } = useI18n();

// Initialize the form
const form = useForm({
    name: props.product?.name || '',
    category_id: props.product?.category_id || '',
    stock: props.product?.stock || 0,
    price: props.product?.price || 0,
    cost_price: props.product?.cost_price || 0,
    margin_percent: props.product?.margin_percent || 0,
    minimum_stock: props.product?.minimum_stock || 0,
    tracking_type: props.product?.tracking_type || 'none',
    description: props.product?.description || '',
    image: props.product?.image_url || props.product?.image || '',
    image_url: props.product?.image_url || '',
    images: [],
    remove_image_ids: [],
    sku: props.product?.sku || '',
    barcode: props.product?.barcode || '',
    unit: props.product?.unit || '',
    supplier_name: props.product?.supplier_name || '',
    supplier_email: props.product?.supplier_email || '',
    tax_rate: props.product?.tax_rate || 0,
    is_active: props.product?.is_active ?? true,
});

watch(
    () => props.product,
    (value) => {
        initialImageUrl.value = value?.image_url || '';
        initialImage.value = value?.image_url || value?.image || '';
    },
    { immediate: true }
);

const unitOptions = computed(() => ([
    { id: 'piece', name: t('products.units.piece') },
    { id: 'hour', name: t('products.units.hour') },
    { id: 'm2', name: t('products.units.m2') },
    { id: 'other', name: t('products.units.other') },
]));

const trackingOptions = computed(() => ([
    { id: 'none', name: t('products.tracking.standard_full') },
    { id: 'lot', name: t('products.tracking.lot_tracked') },
    { id: 'serial', name: t('products.tracking.serial_tracked') },
]));

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const formatRelativeDate = (value) => humanizeDate(value);

const categoryOptions = ref(Array.isArray(props.categories) ? [...props.categories] : []);

watch(() => props.categories, (next) => {
    categoryOptions.value = Array.isArray(next) ? [...next] : [];
});

const selectableCategories = computed(() => {
    const base = categoryOptions.value || [];
    const currentId = props.product?.category_id;
    if (!currentId) {
        return base;
    }
    if (base.some((category) => category.id === currentId)) {
        return base;
    }
    const current = props.product?.category;
    if (current) {
        return [...base, { id: current.id, name: current.name }];
    }
    return base;
});

const existingImages = computed(() => props.product?.images || []);
const showCategoryForm = ref(false);
const categoryName = ref('');
const categoryError = ref('');
const creatingCategory = ref(false);
const marginPreview = computed(() => {
    if (!form.price) {
        return 0;
    }
    return ((form.price - form.cost_price) / form.price) * 100;
});

const lotItems = computed(() => {
    if (!Array.isArray(props.product?.lots)) {
        return [];
    }
    return [...props.product.lots].sort((a, b) => {
        const aDate = a?.expires_at ? new Date(a.expires_at).getTime() : Number.MAX_SAFE_INTEGER;
        const bDate = b?.expires_at ? new Date(b.expires_at).getTime() : Number.MAX_SAFE_INTEGER;
        return aDate - bDate;
    });
});

const isLotExpired = (lot) => {
    if (!lot?.expires_at) {
        return false;
    }
    return new Date(lot.expires_at).getTime() < Date.now();
};

const isLotExpiringSoon = (lot) => {
    if (!lot?.expires_at) {
        return false;
    }
    const expiry = new Date(lot.expires_at).getTime();
    const now = Date.now();
    const soon = now + (1000 * 60 * 60 * 24 * 30);
    return expiry >= now && expiry <= soon;
};

const getLotStatus = (lot) => {
    if (isLotExpired(lot)) {
        return t('products.lots.status.expired');
    }
    if (isLotExpiringSoon(lot)) {
        return t('products.lots.status.expiring');
    }
    return t('products.lots.status.active');
};

const lotStatusClasses = {
    expired: 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300',
    expiring: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    active: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
};

const getLotStatusClass = (lot) => {
    if (isLotExpired(lot)) {
        return lotStatusClasses.expired;
    }
    if (isLotExpiringSoon(lot)) {
        return lotStatusClasses.expiring;
    }
    return lotStatusClasses.active;
};

const getLotLabel = (lot) => {
    if (lot?.serial_number) {
        return t('products.lots.serial_label', { number: lot.serial_number });
    }
    if (lot?.lot_number) {
        return t('products.lots.lot_label', { number: lot.lot_number });
    }
    return t('products.lots.lot_fallback');
};

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
        form.setError('form', t('products.form.errors.required_fields'));
        return;
    }

    form.clearErrors('form');

    const routeName = props.product?.id ? 'product.update' : 'product.store';
    const routeParams = props.product?.id ? props.product.id : undefined;

    form
        .transform((data) => ({
            ...data,
            image: data.image instanceof File ? data.image : null,
            image_url: (() => {
                if (data.image instanceof File) {
                    return null;
                }
                const candidate = (data.image_url || (typeof data.image === 'string' ? data.image : ''))
                    ?.toString()
                    .trim();
                if (!candidate) {
                    return null;
                }
                if (candidate === initialImageUrl.value || candidate === initialImage.value) {
                    return null;
                }
                return candidate;
            })(),
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

const buttonLabel = computed(() => (props.product
    ? t('products.actions.update_product')
    : t('products.actions.create_product')));
</script>


<template>
    <form @submit.prevent="submit">
        <ValidationSummary :errors="form.errors" />
        <!-- Products Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-6 gap-5">
            <div class="lg:col-span-4 space-y-4 min-w-0">
                <productCard>
                    <template #title>
                        {{ $t('products.form.details') }}
                    </template>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 gap-y-4">
                        <FloatingInput v-model="form.name" :label="$t('products.form.name')" :required="true" />
            <FloatingSelect v-model="form.category_id" :label="$t('products.form.category')" :options="selectableCategories" :required="true" />
                        <div class="md:col-span-2 space-y-2">
                            <div class="flex items-center justify-between">
                                <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('products.form.category_hint') }}</p>
                                <button
                                    type="button"
                                    class="text-xs font-semibold text-green-700 hover:text-green-800 dark:text-green-400 action-feedback"
                                    @click="showCategoryForm = !showCategoryForm"
                                >
                                    {{ showCategoryForm ? $t('products.form.category_hide') : $t('products.form.category_add') }}
                                </button>
                            </div>
                            <p v-if="!selectableCategories.length" class="text-xs text-amber-600 dark:text-amber-300">
                                {{ $t('products.form.category_empty') }}
                            </p>
                            <div v-if="showCategoryForm" class="flex flex-col gap-2 sm:flex-row sm:items-end">
                                <div class="flex-1">
                                    <FloatingInput v-model="categoryName" :label="$t('products.form.category_new')" />
                                    <p v-if="categoryError" class="mt-1 text-xs text-red-600">{{ categoryError }}</p>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60 action-feedback"
                                    :disabled="creatingCategory"
                                    @click="createCategory"
                                >
                                    {{ $t('products.form.category_create') }}
                                </button>
                            </div>
                        </div>
                        <FloatingInput v-model="form.sku" :label="$t('products.form.sku')" />
                        <FloatingInput v-model="form.barcode" :label="$t('products.form.barcode')" />
                        <FloatingSelect v-model="form.tracking_type" :label="$t('products.form.tracking')" :options="trackingOptions" />
                        <FloatingSelect v-model="form.unit" :label="$t('products.form.unit')" :options="unitOptions" />
                        <FloatingInput v-model="form.supplier_name" :label="$t('products.form.supplier')" />
                        <FloatingInput v-model="form.supplier_email" :label="$t('products.form.supplier_email')" />
                        <FloatingNumberInput v-model="form.tax_rate" :label="$t('products.form.tax_rate')" :step="0.01" />
                        <div class="flex items-center gap-x-2">
                            <Checkbox v-model:checked="form.is_active" />
                            <span class="text-sm text-stone-600 dark:text-neutral-400">{{ $t('products.status.active') }}</span>
                        </div>
                    </div>
                    <FloatingTextarea v-model="form.description" :label="$t('products.form.description')" />
                    <DropzoneInput v-model="form.image" :label="$t('products.form.primary_image')" />
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
                        {{ $t('products.form.pricing') }}
                    </template>

                    <div class="grid grid-cols-1 gap-4 gap-y-4">
                        <FloatingNumberInput v-model="form.price" :label="$t('products.form.price')" :step="0.01" :required="true" />
                        <FloatingNumberInput v-model="form.cost_price" :label="$t('products.form.cost_price')" :step="0.01" />
                        <FloatingNumberInput v-model="form.margin_percent" :label="$t('products.form.margin')" :step="0.01" />
                        <FloatingNumberInput v-model="form.stock" :label="$t('products.form.stock')" :required="true" />
                        <FloatingNumberInput v-model="form.minimum_stock" :label="$t('products.form.minimum_stock')" :required="true" />
                    </div>
                </productCard>

                <productCard v-if="props.product?.inventories?.length">
                    <template #title>
                        {{ $t('products.form.inventory') }}
                    </template>

                    <div class="space-y-3 text-sm text-stone-600 dark:text-neutral-300">
                        <div v-for="inventory in props.product.inventories" :key="inventory.id"
                            class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="flex items-center justify-between">
                                <div class="font-medium text-stone-700 dark:text-neutral-200">
                                    {{ inventory.warehouse?.name || $t('products.labels.warehouse') }}
                                </div>
                                <span class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('products.labels.bin') }} {{ inventory.bin_location || '-' }}
                                </span>
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                <div>{{ $t('products.labels.on_hand') }}: {{ formatNumber(inventory.on_hand) }}</div>
                                <div>{{ $t('products.labels.reserved') }}: {{ formatNumber(inventory.reserved) }}</div>
                                <div>{{ $t('products.labels.damaged') }}: {{ formatNumber(inventory.damaged) }}</div>
                                <div>{{ $t('products.labels.minimum') }}: {{ formatNumber(inventory.minimum_stock) }}</div>
                            </div>
                        </div>
                    </div>
                </productCard>

                <productCard v-if="Array.isArray(props.product?.lots)">
                    <template #title>
                        {{ $t('products.form.lots_title') }}
                    </template>

                    <div v-if="!lotItems.length" class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('products.form.lots_empty') }}
                    </div>
                    <div v-else class="space-y-2">
                        <div v-for="lot in lotItems" :key="lot.id"
                            class="rounded-sm border border-stone-200 p-3 text-sm text-stone-600 dark:border-neutral-700 dark:text-neutral-300">
                            <div class="flex items-center justify-between gap-2">
                                <div class="font-medium text-stone-700 dark:text-neutral-200">
                                    {{ getLotLabel(lot) }}
                                </div>
                                <span class="rounded-full px-2 py-1 text-[10px] font-semibold"
                                    :class="getLotStatusClass(lot)">
                                    {{ getLotStatus(lot) }}
                                </span>
                            </div>
                            <div class="mt-2 grid grid-cols-1 gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                <div>
                                    {{ $t('products.labels.warehouse') }}: {{ lot.warehouse?.name || $t('products.labels.warehouse') }}
                                </div>
                                <div>
                                    {{ $t('products.labels.quantity') }}: {{ formatNumber(lot.quantity) }}
                                </div>
                                <div v-if="lot.received_at">
                                    {{ $t('products.labels.received') }} {{ formatRelativeDate(lot.received_at) }}
                                </div>
                                <div v-if="lot.expires_at">
                                    {{ $t('products.labels.expires') }} {{ formatRelativeDate(lot.expires_at) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </productCard>

                <productCard>
                    <template #title>
                        {{ $t('products.price_lookup.title') }}
                    </template>

                    <div class="space-y-3">
                        <FloatingInput
                            v-model="priceLookupQuery"
                            :label="$t('products.price_lookup.search_term')"
                            @update:modelValue="markManualQuery"
                        />

                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                                :disabled="priceLookupLoading"
                                @click="searchPrices"
                            >
                                {{ $t('products.price_lookup.search_action') }}
                            </button>
                            <button
                                v-if="priceLookupResults.length"
                                type="button"
                                class="inline-flex items-center rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-200 dark:hover:bg-emerald-500/20"
                                :disabled="priceLookupLoading"
                                @click="applyBestPrice"
                            >
                                {{ $t('products.price_lookup.apply_best') }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                :disabled="priceLookupLoading"
                                @click="clearLookup"
                            >
                                {{ $t('products.price_lookup.clear') }}
                            </button>
                        </div>

                        <div v-if="priceLookupMeta?.provider" class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('products.price_lookup.provider') }}
                            <span class="font-semibold text-stone-600 dark:text-neutral-300">{{ priceLookupMeta.provider }}</span>
                            <span v-if="priceLookupMeta.provider_ready === false" class="text-amber-600 dark:text-amber-300">
                                {{ $t('products.price_lookup.provider_not_configured') }}
                            </span>
                        </div>
                        <div v-if="priceLookupMeta?.preferred_suppliers?.length" class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('products.price_lookup.preferred') }}: {{ priceLookupMeta.preferred_suppliers.join(', ') }}
                        </div>
                        <div v-if="priceLookupMeta?.enabled_suppliers?.length" class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('products.price_lookup.enabled') }}: {{ priceLookupMeta.enabled_suppliers.join(', ') }}
                        </div>

                        <div v-if="priceLookupLoading" class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('products.price_lookup.searching') }}
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
                                            loading="lazy"
                                            decoding="async"
                                        />
                                        <div>
                                            <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                                {{ source.name }}
                                                <span
                                                    v-if="index === 0"
                                                    class="ml-2 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"
                                                >
                                                    {{ $t('products.price_lookup.best_price') }}
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
                </productCard>
            </div>
        </div>
        <!-- Footer -->
        <div class="p-4 flex justify-end gap-x-2">
            <div class="flex justify-end items-center gap-2">
                <button :data-hs-overlay="overlayTarget || undefined" type="button" @click="cancel"
                    class="py-2 px-3 text-nowrap inline-flex justify-center items-center text-start bg-white border border-stone-200 text-stone-700 text-sm font-medium rounded-sm shadow-sm align-middle hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800 action-feedback">
                    {{ $t('products.actions.cancel') }}
                </button>

                <button type="submit" :disabled="form.processing"
                    class="py-2 px-3 text-nowrap inline-flex justify-center items-center gap-x-2 text-start bg-green-600 border border-green-600 text-white text-sm font-medium rounded-sm shadow-sm align-middle hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500 action-feedback">
                    {{ buttonLabel }}
                </button>
            </div>
        </div>
        <!-- End Footer -->
    </form>
</template>
