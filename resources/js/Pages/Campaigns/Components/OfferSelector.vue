<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    modelValue: {
        type: Array,
        default: () => [],
    },
    selectors: {
        type: Object,
        default: () => ({
            category_ids: [],
            tags: [],
        }),
    },
    offerMode: {
        type: String,
        default: 'MIXED',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue', 'update:selectors']);

const query = ref('');
const typeFilter = ref('all');
const sort = ref('relevance');
const status = ref('active');
const availability = ref('all');
const categoryId = ref('');
const priceMin = ref('');
const priceMax = ref('');
const tagsInput = ref('');
const categorySnapshot = ref(Array.isArray(props.selectors?.category_ids) ? props.selectors.category_ids.join(', ') : '');
const tagsSnapshot = ref(Array.isArray(props.selectors?.tags) ? props.selectors.tags.join(', ') : '');

const loading = ref(false);
const error = ref('');
const items = ref([]);
const nextCursor = ref(null);
const highlightIndex = ref(-1);
let debounceTimer = null;

const selected = computed(() => {
    return Array.isArray(props.modelValue) ? props.modelValue : [];
});

const resolvedType = computed(() => {
    if (props.offerMode === 'PRODUCTS') {
        return 'product';
    }
    if (props.offerMode === 'SERVICES') {
        return 'service';
    }
    return typeFilter.value;
});

const selectedKeys = computed(() => {
    const keys = new Set();
    selected.value.forEach((offer) => {
        const type = String(offer.offer_type || offer.type || '').toLowerCase();
        const id = Number(offer.offer_id || offer.id || 0);
        if (type && id > 0) {
            keys.add(`${type}:${id}`);
        }
    });
    return keys;
});

const parseTags = (input) => {
    return String(input || '')
        .split(/[,\n;]+/)
        .map((value) => value.trim())
        .filter((value) => value !== '')
        .slice(0, 12);
};

const parseIds = (input) => {
    return String(input || '')
        .split(/[,\n; ]+/)
        .map((value) => Number(value))
        .filter((value) => Number.isInteger(value) && value > 0)
        .slice(0, 20);
};

const normalizedOffer = (item) => ({
    offer_type: String(item.type || '').toLowerCase(),
    offer_id: Number(item.id),
    id: Number(item.id),
    type: String(item.type || '').toLowerCase(),
    name: String(item.name || ''),
    price: item.price ?? null,
    status: item.status || null,
    availability: item.availability || null,
    thumbnailUrl: item.thumbnailUrl || null,
    categoryName: item.categoryName || null,
    sku: item.sku || null,
    serviceCode: item.serviceCode || null,
});

const fetchOffers = async (reset = true) => {
    if (props.disabled) {
        return;
    }

    loading.value = true;
    error.value = '';

    try {
        const params = {
            q: query.value || undefined,
            type: resolvedType.value || 'all',
            sort: sort.value,
            status: status.value,
            availability: availability.value === 'all' ? undefined : availability.value,
            category_id: categoryId.value ? Number(categoryId.value) : undefined,
            price_min: priceMin.value ? Number(priceMin.value) : undefined,
            price_max: priceMax.value ? Number(priceMax.value) : undefined,
            tags: parseTags(tagsInput.value),
            cursor: reset ? undefined : nextCursor.value,
            limit: 20,
        };

        const response = await axios.get(route('offers.search'), { params });
        const payload = response.data || {};
        const batch = Array.isArray(payload.items) ? payload.items : [];

        if (reset) {
            items.value = batch;
        } else {
            items.value = [...items.value, ...batch];
        }

        nextCursor.value = payload.nextCursor || null;
        highlightIndex.value = items.value.length > 0 ? 0 : -1;
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || 'Offer search failed.';
    } finally {
        loading.value = false;
    }
};

const scheduleFetch = () => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
    debounceTimer = setTimeout(() => fetchOffers(true), 260);
};

watch(
    () => [query.value, resolvedType.value, sort.value, status.value, availability.value, categoryId.value, priceMin.value, priceMax.value, tagsInput.value],
    () => scheduleFetch()
);

const toggleOffer = (item) => {
    const offer = normalizedOffer(item);
    const key = `${offer.offer_type}:${offer.offer_id}`;
    const current = [...selected.value];
    const index = current.findIndex((row) => `${String(row.offer_type || row.type).toLowerCase()}:${Number(row.offer_id || row.id)}` === key);
    if (index >= 0) {
        current.splice(index, 1);
    } else {
        current.push(offer);
    }

    emit('update:modelValue', current);
};

const removeOffer = (offer) => {
    const key = `${String(offer.offer_type || offer.type).toLowerCase()}:${Number(offer.offer_id || offer.id)}`;
    const next = selected.value.filter((row) => {
        const rowKey = `${String(row.offer_type || row.type).toLowerCase()}:${Number(row.offer_id || row.id)}`;
        return rowKey !== key;
    });
    emit('update:modelValue', next);
};

const onSearchKeydown = (event) => {
    if (!items.value.length) {
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        highlightIndex.value = Math.min(items.value.length - 1, highlightIndex.value + 1);
        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        highlightIndex.value = Math.max(0, highlightIndex.value - 1);
        return;
    }

    if (event.key === 'Enter') {
        event.preventDefault();
        const highlighted = items.value[highlightIndex.value];
        if (highlighted) {
            toggleOffer(highlighted);
        }
    }
};

const applySnapshotSelectors = () => {
    emit('update:selectors', {
        category_ids: parseIds(categorySnapshot.value),
        tags: parseTags(tagsSnapshot.value),
    });
};

fetchOffers(true);

onBeforeUnmount(() => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
});
</script>

<template>
    <div class="space-y-3">
        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
            <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">Selected offers</div>
            <div v-if="selected.length === 0" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                No offer selected yet.
            </div>
            <div v-else class="mt-2 flex flex-wrap gap-2">
                <button
                    v-for="offer in selected"
                    :key="`selected-${offer.offer_type || offer.type}-${offer.offer_id || offer.id}`"
                    type="button"
                    class="inline-flex items-center gap-1 rounded-sm border border-green-200 bg-green-50 px-2 py-1 text-xs text-green-700 dark:border-green-500/20 dark:bg-green-500/10 dark:text-green-300"
                    @click="removeOffer(offer)"
                >
                    <span>{{ offer.name }}</span>
                    <span class="font-semibold">x</span>
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-2 md:grid-cols-4">
            <input
                v-model="query"
                type="text"
                placeholder="Search offers..."
                class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                :disabled="disabled"
                @keydown="onSearchKeydown"
            >
            <select
                v-if="offerMode === 'MIXED'"
                v-model="typeFilter"
                class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                :disabled="disabled"
            >
                <option value="all">All types</option>
                <option value="product">Products</option>
                <option value="service">Services</option>
            </select>
            <select
                v-model="sort"
                class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                :disabled="disabled"
            >
                <option value="relevance">Relevance</option>
                <option value="newest">Newest</option>
                <option value="best_sellers">Best sellers</option>
                <option value="alphabetical">A-Z</option>
            </select>
            <select
                v-model="status"
                class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                :disabled="disabled"
            >
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="all">All statuses</option>
            </select>
            <select
                v-model="availability"
                class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                :disabled="disabled"
            >
                <option value="all">All availability</option>
                <option value="in_stock">In stock</option>
                <option value="bookable">Bookable</option>
            </select>
        </div>

        <div class="grid grid-cols-1 gap-2 md:grid-cols-4">
            <input
                v-model="categoryId"
                type="number"
                min="1"
                placeholder="Category ID"
                class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                :disabled="disabled"
            >
            <input
                v-model="priceMin"
                type="number"
                min="0"
                step="0.01"
                placeholder="Price min"
                class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                :disabled="disabled"
            >
            <input
                v-model="priceMax"
                type="number"
                min="0"
                step="0.01"
                placeholder="Price max"
                class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                :disabled="disabled"
            >
            <input
                v-model="tagsInput"
                type="text"
                placeholder="Tags (comma separated)"
                class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                :disabled="disabled"
            >
        </div>

        <div class="rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
            <div v-if="error" class="border-b border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                {{ error }}
            </div>
            <div class="max-h-72 overflow-y-auto">
                <button
                    v-for="(item, index) in items"
                    :key="`offer-${item.type}-${item.id}`"
                    type="button"
                    class="flex w-full items-center gap-3 border-b border-stone-200 px-3 py-2 text-left text-sm last:border-b-0 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                    :class="{
                        'bg-stone-100 dark:bg-neutral-800': highlightIndex === index,
                    }"
                    :disabled="disabled"
                    @click="toggleOffer(item)"
                >
                    <img
                        v-if="item.thumbnailUrl"
                        :src="item.thumbnailUrl"
                        alt=""
                        class="h-8 w-8 rounded-sm object-cover"
                    >
                    <div v-else class="h-8 w-8 rounded-sm bg-stone-100 dark:bg-neutral-700" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium text-stone-700 dark:text-neutral-200">{{ item.name }}</div>
                        <div class="truncate text-xs text-stone-500 dark:text-neutral-400">
                            {{ item.type }} | {{ item.categoryName || 'No category' }} | {{ item.status }}
                        </div>
                    </div>
                    <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                        {{ item.price ?? '-' }}
                    </div>
                    <div class="w-5 text-center text-xs">
                        <span
                            v-if="selectedKeys.has(`${item.type}:${item.id}`)"
                            class="inline-flex h-5 w-5 items-center justify-center rounded-sm bg-green-600 text-white"
                        >
                            ✓
                        </span>
                    </div>
                </button>
                <div v-if="!loading && items.length === 0" class="px-3 py-6 text-center text-xs text-stone-500 dark:text-neutral-400">
                    No offers found.
                </div>
            </div>
            <div class="flex items-center justify-between border-t border-stone-200 px-3 py-2 dark:border-neutral-700">
                <span class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ loading ? 'Loading...' : `${items.length} result(s)` }}
                </span>
                <button
                    v-if="nextCursor"
                    type="button"
                    class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    :disabled="loading || disabled"
                    @click="fetchOffers(false)"
                >
                    Load more
                </button>
            </div>
        </div>

        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
            <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">Category/Tag Snapshot (MVP)</div>
            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                Snapshot strategy: selected category/tag IDs resolve to explicit offers when saving the campaign.
            </p>
            <div class="mt-2 grid grid-cols-1 gap-2 md:grid-cols-2">
                <input
                    v-model="categorySnapshot"
                    type="text"
                    placeholder="Category IDs: 3, 8, 21"
                    class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                    :disabled="disabled"
                >
                <input
                    v-model="tagsSnapshot"
                    type="text"
                    placeholder="Tags: summer, vip"
                    class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                    :disabled="disabled"
                >
            </div>
            <div class="mt-2">
                <button
                    type="button"
                    class="rounded-sm border border-transparent bg-green-600 px-2 py-1 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                    :disabled="disabled"
                    @click="applySnapshotSelectors"
                >
                    Apply snapshot selectors
                </button>
            </div>
        </div>
    </div>
</template>

