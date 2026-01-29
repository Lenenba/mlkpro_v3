<script setup>
import { computed, ref, watch } from 'vue';
import Badge from '@/Components/Store/Badge.vue';
import Price from '@/Components/Store/Price.vue';

const props = defineProps({
    product: { type: Object, required: true },
    variant: { type: String, default: 'grid' },
    badges: { type: Array, default: () => [] },
    stockLabel: { type: String, default: '' },
    stockTone: { type: String, default: 'neutral' },
    descriptionFallback: { type: String, default: '' },
    ratingEmptyLabel: { type: String, default: '' },
    ctaLabel: { type: String, default: '' },
    viewLabel: { type: String, default: '' },
    showView: { type: Boolean, default: false },
    showRating: { type: Boolean, default: true },
    loading: { type: Boolean, default: false },
    quantity: { type: Number, default: 0 },
    showQuickAdd: { type: Boolean, default: false },
});

const emit = defineEmits(['open', 'add', 'view', 'increment', 'decrement']);

const priceMeta = computed(() => {
    const promoActive = Boolean(props.product?.promo_active) && Number(props.product?.promo_price || 0) > 0;
    const current = promoActive ? Number(props.product?.promo_price || 0) : Number(props.product?.price || 0);
    const original = promoActive ? Number(props.product?.price || 0) : null;
    return { current, original, promoActive };
});

const stockToneMap = {
    neutral: 'bg-slate-100 text-slate-600',
    warning: 'bg-amber-50 text-amber-700',
    danger: 'bg-rose-50 text-rose-700',
};

const ratingLabel = computed(() => {
    const count = Number(props.product?.rating_count || 0);
    if (!count) {
        return props.ratingEmptyLabel;
    }
    const avg = Number(props.product?.rating_avg || 0);
    return `${avg.toFixed(1)} / 5 (${count})`;
});

const productName = computed(() => String(props.product?.name || ''));
const productInitial = computed(() => (productName.value ? productName.value.charAt(0) : '?'));

const imageLoaded = ref(false);
watch(
    () => props.product?.image_url,
    (next) => {
        imageLoaded.value = !next;
    },
    { immediate: true },
);

const handleImageLoad = () => {
    imageLoaded.value = true;
};

const showImageSkeleton = computed(() => props.loading || (Boolean(props.product?.image_url) && !imageLoaded.value));
const showContentSkeleton = computed(() => props.loading);
const stockUnavailable = computed(() => Number(props.product?.stock || 0) <= 0);

const cardClass = computed(() => {
    const base = 'group relative flex h-full flex-col overflow-hidden rounded-sm border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md focus-within:ring-2 focus-within:ring-emerald-400';
    if (props.variant === 'featured') {
        return `${base} p-4 md:p-6`;
    }
    if (props.variant === 'compact') {
        return `${base} p-1.5`;
    }
    return `${base} p-2`;
});

const mediaClass = computed(() => {
    if (props.variant === 'featured') {
        return 'aspect-[4/3]';
    }
    if (props.variant === 'compact') {
        return 'aspect-[16/9]';
    }
    return 'aspect-[16/10]';
});

const contentClass = computed(() => {
    if (props.variant === 'featured') {
        return 'mt-4 flex flex-1 flex-col gap-4';
    }
    if (props.variant === 'compact') {
        return 'mt-1.5 flex flex-1 flex-col gap-1';
    }
    return 'mt-2 flex flex-1 flex-col gap-1.5';
});

const titleClass = computed(() => {
    if (props.variant === 'featured') {
        return 'text-base font-semibold text-slate-900';
    }
    if (props.variant === 'compact') {
        return 'text-[12px] font-semibold text-slate-900';
    }
    return 'text-[13px] font-semibold text-slate-900';
});

const descriptionClass = computed(() => {
    if (props.variant === 'compact') {
        return 'min-h-5 text-[11px] text-slate-500';
    }
    return 'min-h-6 text-[12px] text-slate-500';
});

const ratingClass = computed(() => (
    props.variant === 'featured' ? 'text-xs text-slate-400' : 'text-[11px] text-slate-400'
));

const priceSize = computed(() => {
    if (props.variant === 'featured') {
        return 'lg';
    }
    if (props.variant === 'compact') {
        return 'xs';
    }
    return 'sm';
});

const handleOpen = () => emit('open', props.product);
const handleAdd = () => emit('add', props.product);
const handleView = () => emit('view', props.product);
const handleIncrement = () => emit('increment', props.product);
const handleDecrement = () => emit('decrement', props.product);
</script>

<template>
    <article
        :class="cardClass"
        role="button"
        tabindex="0"
        @click="handleOpen"
        @keydown.enter.prevent="handleOpen"
        @keydown.space.prevent="handleOpen"
    >
        <div class="relative">
            <div :class="['w-full overflow-hidden rounded-sm bg-slate-100', mediaClass]">
                <div class="relative h-full w-full">
                    <img
                        v-if="product.image_url"
                        :src="product.image_url"
                        :alt="productName"
                        class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                        :class="imageLoaded ? 'opacity-100' : 'opacity-0'"
                        loading="lazy"
                        decoding="async"
                        @load="handleImageLoad"
                    >
                    <div
                        v-if="showImageSkeleton"
                        class="absolute inset-0 animate-pulse bg-slate-200"
                    ></div>
                    <div v-else-if="!product.image_url" class="flex h-full w-full items-center justify-center text-2xl font-semibold text-slate-300">
                        {{ productInitial }}
                    </div>
                </div>
            </div>
            <div v-if="badges.length" class="absolute left-3 top-3 flex flex-wrap gap-2">
                <Badge v-for="badge in badges" :key="badge.label" :label="badge.label" :tone="badge.tone" />
            </div>
        </div>

        <div :class="contentClass">
            <template v-if="showContentSkeleton">
                <div class="space-y-2">
                    <div class="h-4 w-2/3 rounded-sm bg-slate-200 animate-pulse"></div>
                    <div class="h-3 w-full rounded-sm bg-slate-200 animate-pulse"></div>
                    <div class="flex items-center justify-between gap-3">
                        <div class="h-4 w-16 rounded-sm bg-slate-200 animate-pulse"></div>
                        <div class="h-3 w-12 rounded-sm bg-slate-200 animate-pulse"></div>
                    </div>
                    <div class="h-7 w-24 rounded-sm bg-slate-200 animate-pulse"></div>
                </div>
            </template>
            <template v-else>
                <div class="flex items-start justify-between gap-3">
                    <h3 :class="titleClass">
                        {{ productName }}
                    </h3>
                    <span
                        v-if="stockLabel"
                        :class="['rounded-sm px-2 py-0.5 text-[11px] font-semibold', stockToneMap[stockTone] || stockToneMap.neutral]"
                    >
                        {{ stockLabel }}
                    </span>
                </div>
                <p :class="descriptionClass">
                    {{ product.description || descriptionFallback }}
                </p>
                <div class="flex items-center justify-between gap-3">
                    <Price :current="priceMeta.current" :original="priceMeta.original" :size="priceSize" />
                    <span v-if="showRating" :class="ratingClass">
                        {{ ratingLabel }}
                    </span>
                </div>
                <div class="mt-auto flex flex-wrap items-center gap-2">
                    <template v-if="showQuickAdd">
                        <div class="inline-flex items-center gap-1 rounded-sm border border-slate-200 px-2 py-1 text-xs text-slate-600">
                            <button
                                type="button"
                                class="rounded-sm px-1 text-slate-500 hover:text-slate-700 disabled:opacity-40"
                                :disabled="quantity <= 0"
                                aria-label="Decrease quantity"
                                @click.stop="handleDecrement"
                            >
                                -
                            </button>
                            <span class="min-w-5 text-center text-xs font-semibold text-slate-700">
                                {{ quantity }}
                            </span>
                            <button
                                type="button"
                                class="rounded-sm px-1 text-slate-500 hover:text-slate-700 disabled:opacity-40"
                                :disabled="stockUnavailable"
                                aria-label="Increase quantity"
                                @click.stop="handleIncrement"
                            >
                                +
                            </button>
                        </div>
                    </template>
                    <button
                        v-else-if="ctaLabel"
                        type="button"
                        class="rounded-sm bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 disabled:cursor-not-allowed disabled:bg-emerald-300"
                        :disabled="stockUnavailable"
                        :aria-label="`${ctaLabel} ${productName}`"
                        @click.stop="handleAdd"
                    >
                        {{ ctaLabel }}
                    </button>
                    <button
                        v-if="showView && viewLabel"
                        type="button"
                        class="rounded-sm border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:border-slate-300 hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                        :aria-label="`${viewLabel} ${productName}`"
                        @click.stop="handleView"
                    >
                        {{ viewLabel }}
                    </button>
                    <span v-if="product.sku" class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                        {{ product.sku }}
                    </span>
                </div>
            </template>
        </div>
    </article>
</template>
