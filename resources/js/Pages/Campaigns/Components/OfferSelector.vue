<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import CampaignSectionCard from '@/Pages/Campaigns/Components/CampaignSectionCard.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

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
const { t } = useI18n();

const query = ref('');
const typeFilter = ref('all');
const sort = ref('relevance');
const status = ref('active');
const availability = ref('all');
const categoryId = ref('');
const priceMin = ref('');
const priceMax = ref('');
const tagsInput = ref('');
const categoryRuleIds = ref(Array.isArray(props.selectors?.category_ids) ? props.selectors.category_ids.map((value) => Number(value)).filter((value) => value > 0) : []);
const tagRuleValues = ref(Array.isArray(props.selectors?.tags) ? props.selectors.tags.map((value) => String(value).trim()).filter((value) => value !== '') : []);
const showAdvancedFilters = ref(false);
const showAutomaticRules = ref(categoryRuleIds.value.length > 0 || tagRuleValues.value.length > 0);
const catalogDialogOpen = ref(false);

const loading = ref(false);
const error = ref('');
const items = ref([]);
const nextCursor = ref(null);
const highlightIndex = ref(-1);
const availableCategories = ref([]);
const availableTags = ref([]);
let debounceTimer = null;

const selected = computed(() => {
    return Array.isArray(props.modelValue) ? props.modelValue : [];
});

watch(
    () => props.selectors,
    (value) => {
        categoryRuleIds.value = Array.isArray(value?.category_ids)
            ? value.category_ids.map((entry) => Number(entry)).filter((entry) => entry > 0)
            : [];
        tagRuleValues.value = Array.isArray(value?.tags)
            ? value.tags.map((entry) => String(entry).trim()).filter((entry) => entry !== '')
            : [];
        if (categoryRuleIds.value.length > 0 || tagRuleValues.value.length > 0) {
            showAutomaticRules.value = true;
        }
    },
    { deep: true }
);

const humanizeValue = (value) => String(value || '')
    .replaceAll('_', ' ')
    .toLowerCase()
    .replace(/\b\w/g, (char) => char.toUpperCase());

const translateWithFallback = (key, fallback) => {
    const translated = t(key);
    return translated === key ? fallback : translated;
};

const offerTypeLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.offer_selector.type_options.${normalized}`, humanizeValue(value));
};

const offerStatusLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.offer_selector.status_options.${normalized}`, humanizeValue(value));
};

const typeOptions = computed(() => ([
    { value: 'all', label: t('marketing.offer_selector.type_options.all') },
    { value: 'product', label: t('marketing.offer_selector.type_options.product') },
    { value: 'service', label: t('marketing.offer_selector.type_options.service') },
]));

const sortOptions = computed(() => ([
    { value: 'relevance', label: t('marketing.offer_selector.sort_options.relevance') },
    { value: 'newest', label: t('marketing.offer_selector.sort_options.newest') },
    { value: 'best_sellers', label: t('marketing.offer_selector.sort_options.best_sellers') },
    { value: 'alphabetical', label: t('marketing.offer_selector.sort_options.alphabetical') },
]));

const statusOptions = computed(() => ([
    { value: 'active', label: t('marketing.offer_selector.status_options.active') },
    { value: 'inactive', label: t('marketing.offer_selector.status_options.inactive') },
    { value: 'all', label: t('marketing.offer_selector.status_options.all') },
]));

const availabilityOptions = computed(() => ([
    { value: 'all', label: t('marketing.offer_selector.availability_options.all') },
    { value: 'in_stock', label: t('marketing.offer_selector.availability_options.in_stock') },
    { value: 'bookable', label: t('marketing.offer_selector.availability_options.bookable') },
]));

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

const advancedFilterCount = computed(() => [
    sort.value !== 'relevance',
    status.value !== 'active',
    availability.value !== 'all',
    String(categoryId.value || '').trim() !== '',
    String(priceMin.value || '').trim() !== '',
    String(priceMax.value || '').trim() !== '',
    parseTags(tagsInput.value).length > 0,
].filter(Boolean).length);

const automaticRuleSummary = computed(() => {
    const categoryCount = categoryRuleIds.value.length;
    const tagCount = tagRuleValues.value.length;

    if (categoryCount === 0 && tagCount === 0) {
        return t('marketing.offer_selector.rules_empty');
    }

    return t('marketing.offer_selector.rules_active_summary', {
        categories: categoryCount,
        tags: tagCount,
    });
});

const categoryFilterOptions = computed(() => ([
    { value: '', label: t('marketing.offer_selector.all_categories') },
    ...availableCategories.value.map((category) => ({
        value: String(category.id),
        label: category.name,
    })),
]));

const selectedRuleCategoryNames = computed(() => {
    const labels = new Map(availableCategories.value.map((category) => [Number(category.id), category.name]));

    return categoryRuleIds.value.map((id) => labels.get(Number(id)) || `#${id}`);
});

const selectedRuleTags = computed(() => tagRuleValues.value);

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
        availableCategories.value = Array.isArray(payload.filters?.categories) ? payload.filters.categories : [];
        availableTags.value = Array.isArray(payload.filters?.tags) ? payload.filters.tags : [];

        if (reset) {
            items.value = batch;
        } else {
            items.value = [...items.value, ...batch];
        }

        nextCursor.value = payload.nextCursor || null;
        highlightIndex.value = items.value.length > 0 ? 0 : -1;
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.offer_selector.error_search');
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

const syncAutomaticRules = () => {
    emit('update:selectors', {
        category_ids: categoryRuleIds.value,
        tags: tagRuleValues.value,
    });
};

const toggleRuleCategory = (categoryIdValue) => {
    const resolvedId = Number(categoryIdValue || 0);
    if (resolvedId <= 0) {
        return;
    }

    if (categoryRuleIds.value.includes(resolvedId)) {
        categoryRuleIds.value = categoryRuleIds.value.filter((entry) => entry !== resolvedId);
    } else {
        categoryRuleIds.value = [...categoryRuleIds.value, resolvedId];
    }

    syncAutomaticRules();
};

const toggleRuleTag = (tagValue) => {
    const normalizedTag = String(tagValue || '').trim();
    if (!normalizedTag) {
        return;
    }

    if (tagRuleValues.value.includes(normalizedTag)) {
        tagRuleValues.value = tagRuleValues.value.filter((entry) => entry !== normalizedTag);
    } else {
        tagRuleValues.value = [...tagRuleValues.value, normalizedTag];
    }

    syncAutomaticRules();
};

const clearAutomaticRules = () => {
    categoryRuleIds.value = [];
    tagRuleValues.value = [];
    syncAutomaticRules();
};

fetchOffers(true);

onBeforeUnmount(() => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
});
</script>

<template>
    <div class="space-y-4">
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.35fr),minmax(320px,0.85fr)]">
            <CampaignSectionCard
                :title="t('marketing.offer_selector.selected_offers')"
                :description="t('marketing.offer_selector.selected_description')"
                compact
            >
                <template #actions>
                    <span class="inline-flex rounded-full border border-stone-200 bg-stone-50 px-3 py-1 text-xs font-medium text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                        {{ t('marketing.offer_selector.selected_count', { count: selected.length }) }}
                    </span>
                </template>

                <div v-if="selected.length === 0" class="text-sm text-stone-500 dark:text-neutral-400">
                    {{ t('marketing.offer_selector.no_offer_selected') }}
                </div>
                <div v-else class="flex flex-wrap gap-2">
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
            </CampaignSectionCard>

            <CampaignSectionCard
                :title="t('marketing.offer_selector.catalog_title')"
                :description="t('marketing.offer_selector.catalog_description')"
                compact
            >
                <div class="flex h-full flex-col justify-between gap-3 rounded-lg border border-dashed border-stone-300 bg-stone-50 px-4 py-4 dark:border-neutral-600 dark:bg-neutral-800">
                    <div>
                        <div class="text-sm font-medium text-stone-800 dark:text-neutral-100">
                            {{ t('marketing.offer_selector.catalog_browser_title') }}
                        </div>
                        <p class="mt-1 text-xs leading-5 text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.offer_selector.catalog_browser_summary', { count: selected.length }) }}
                        </p>
                    </div>
                    <div class="flex justify-start xl:justify-end">
                        <PrimaryButton type="button" :disabled="disabled" @click="catalogDialogOpen = true">
                            {{ t('marketing.offer_selector.open_catalog') }}
                        </PrimaryButton>
                    </div>
                </div>
            </CampaignSectionCard>
        </div>

        <CampaignSectionCard
            :title="t('marketing.offer_selector.snapshot_title')"
            :description="t('marketing.offer_selector.snapshot_description')"
            compact
        >
            <template #actions>
                <button
                    type="button"
                    class="text-xs font-medium text-green-700 underline underline-offset-2 dark:text-green-300"
                    @click="showAutomaticRules = !showAutomaticRules"
                >
                    {{ showAutomaticRules ? t('marketing.offer_selector.hide_rules') : t('marketing.offer_selector.show_rules') }}
                </button>
            </template>

            <div class="rounded-lg border border-dashed border-stone-300 bg-stone-50 px-3 py-3 text-xs text-stone-500 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                {{ automaticRuleSummary }}
            </div>

            <div v-if="showAutomaticRules" class="mt-4 space-y-3">
                <p class="text-xs leading-5 text-stone-500 dark:text-neutral-400">
                    {{ t('marketing.offer_selector.rules_note') }}
                </p>

                <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <div class="space-y-2">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.offer_selector.category_ids') }}
                        </div>
                        <div v-if="availableCategories.length === 0" class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.offer_selector.no_category_options') }}
                        </div>
                        <div v-else class="flex flex-wrap gap-2">
                            <button
                                v-for="category in availableCategories"
                                :key="`rule-category-${category.id}`"
                                type="button"
                                class="rounded-sm border px-3 py-1.5 text-xs font-medium"
                                :class="categoryRuleIds.includes(Number(category.id))
                                    ? 'border-green-300 bg-green-50 text-green-700 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-300'
                                    : 'border-stone-200 bg-white text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300'"
                                :disabled="disabled"
                                @click="toggleRuleCategory(category.id)"
                            >
                                {{ category.name }}
                            </button>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.offer_selector.tags_snapshot') }}
                        </div>
                        <div v-if="availableTags.length === 0" class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.offer_selector.no_tag_options') }}
                        </div>
                        <div v-else class="flex flex-wrap gap-2">
                            <button
                                v-for="tag in availableTags"
                                :key="`rule-tag-${tag.value}`"
                                type="button"
                                class="rounded-sm border px-3 py-1.5 text-xs font-medium"
                                :class="tagRuleValues.includes(tag.value)
                                    ? 'border-green-300 bg-green-50 text-green-700 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-300'
                                    : 'border-stone-200 bg-white text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300'"
                                :disabled="disabled"
                                @click="toggleRuleTag(tag.value)"
                            >
                                {{ tag.label }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div class="flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                        <span v-if="selectedRuleCategoryNames.length > 0">{{ selectedRuleCategoryNames.join(' | ') }}</span>
                        <span v-if="selectedRuleTags.length > 0">{{ selectedRuleTags.join(' | ') }}</span>
                    </div>

                    <div class="shrink-0">
                        <SecondaryButton type="button" :disabled="disabled" @click="clearAutomaticRules">
                            {{ t('marketing.offer_selector.clear_rules') }}
                        </SecondaryButton>
                    </div>
                </div>
            </div>
        </CampaignSectionCard>

        <Modal :show="catalogDialogOpen" max-width="4xl" @close="catalogDialogOpen = false">
            <div class="space-y-4 p-5">
                <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h4 class="text-base font-semibold text-stone-800 dark:text-neutral-100">
                            {{ t('marketing.offer_selector.catalog_modal_title') }}
                        </h4>
                        <p class="mt-1 text-sm leading-6 text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.offer_selector.catalog_modal_description') }}
                        </p>
                    </div>
                    <SecondaryButton type="button" @click="catalogDialogOpen = false">
                        {{ t('marketing.offer_selector.done') }}
                    </SecondaryButton>
                </div>

                <div
                    class="grid grid-cols-1 gap-3"
                    :class="offerMode === 'MIXED' ? 'md:grid-cols-[minmax(0,1fr),220px]' : ''"
                >
                    <FloatingInput
                        v-model="query"
                        :label="t('marketing.offer_selector.search_offers')"
                        :disabled="disabled"
                        @keydown="onSearchKeydown"
                    />
                    <FloatingSelect
                        v-if="offerMode === 'MIXED'"
                        v-model="typeFilter"
                        :label="t('marketing.offer_selector.offer_type')"
                        :options="typeOptions"
                        option-value="value"
                        option-label="label"
                        :disabled="disabled"
                    />
                </div>

                <div class="rounded-lg border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <div class="text-sm font-medium text-stone-800 dark:text-neutral-100">
                                {{ t('marketing.offer_selector.advanced_filters') }}
                            </div>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{
                                    advancedFilterCount > 0
                                        ? t('marketing.offer_selector.advanced_filters_active', { count: advancedFilterCount })
                                        : t('marketing.offer_selector.advanced_filters_empty')
                                }}
                            </p>
                        </div>
                        <SecondaryButton type="button" @click="showAdvancedFilters = !showAdvancedFilters">
                            {{ showAdvancedFilters ? t('marketing.offer_selector.hide_advanced_filters') : t('marketing.offer_selector.show_advanced_filters') }}
                        </SecondaryButton>
                    </div>

                    <div v-if="showAdvancedFilters" class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <FloatingSelect
                            v-model="sort"
                            :label="t('marketing.offer_selector.sort')"
                            :options="sortOptions"
                            option-value="value"
                            option-label="label"
                            :disabled="disabled"
                        />
                        <FloatingSelect
                            v-model="status"
                            :label="t('marketing.offer_selector.status')"
                            :options="statusOptions"
                            option-value="value"
                            option-label="label"
                            :disabled="disabled"
                        />
                        <FloatingSelect
                            v-model="availability"
                            :label="t('marketing.offer_selector.availability')"
                            :options="availabilityOptions"
                            option-value="value"
                            option-label="label"
                            :disabled="disabled"
                        />
                        <FloatingSelect
                            v-model="categoryId"
                            :label="t('marketing.offer_selector.category_filter')"
                            :options="categoryFilterOptions"
                            option-value="value"
                            option-label="label"
                            :disabled="disabled"
                        />
                        <FloatingInput
                            v-model="priceMin"
                            type="number"
                            :label="t('marketing.offer_selector.price_min')"
                            :disabled="disabled"
                        />
                        <FloatingInput
                            v-model="priceMax"
                            type="number"
                            :label="t('marketing.offer_selector.price_max')"
                            :disabled="disabled"
                        />
                        <div class="md:col-span-2">
                            <FloatingInput
                                v-model="tagsInput"
                                :label="t('marketing.offer_selector.tags')"
                                :disabled="disabled"
                            />
                        </div>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                    <div v-if="error" class="border-b border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                        {{ error }}
                    </div>
                    <div class="max-h-[28rem] overflow-y-auto">
                        <template v-if="loading && items.length === 0">
                            <div v-for="row in 8" :key="`offer-loading-${row}`" class="flex items-center gap-3 border-b border-stone-200 px-3 py-2 dark:border-neutral-700">
                                <div class="h-8 w-8 animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="min-w-0 flex-1 space-y-1">
                                    <div class="h-3 w-2/3 animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-2.5 w-1/2 animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                </div>
                                <div class="h-3 w-8 animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            </div>
                        </template>
                        <button
                            v-for="(item, index) in items"
                            :key="`offer-${item.type}-${item.id}`"
                            type="button"
                            class="flex w-full items-center gap-3 border-b border-stone-200 px-3 py-2 text-left text-sm last:border-b-0 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            :class="{ 'bg-stone-100 dark:bg-neutral-800': highlightIndex === index }"
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
                                    {{ offerTypeLabel(item.type) }} | {{ item.categoryName || t('marketing.offer_selector.no_category') }} | {{ offerStatusLabel(item.status) }}
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
                            {{ t('marketing.offer_selector.no_offers_found') }}
                        </div>
                    </div>
                    <div class="flex items-center justify-between border-t border-stone-200 px-3 py-2 dark:border-neutral-700">
                        <span class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ loading ? t('marketing.offer_selector.loading') : t('marketing.offer_selector.results_count', { count: items.length }) }}
                        </span>
                        <PrimaryButton
                            v-if="nextCursor"
                            type="button"
                            :disabled="loading || disabled"
                            @click="fetchOffers(false)"
                        >
                            {{ t('marketing.offer_selector.load_more') }}
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </Modal>
    </div>
</template>
