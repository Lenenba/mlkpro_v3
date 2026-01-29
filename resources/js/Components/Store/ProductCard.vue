<script setup>
import { computed } from 'vue';
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
});

const emit = defineEmits(['open', 'add', 'view']);

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

const cardClass = computed(() => {
    const base = 'group relative flex h-full flex-col overflow-hidden rounded-sm border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md focus-within:ring-2 focus-within:ring-emerald-400';
    if (props.variant === 'featured') {
        return `${base} p-4 md:p-6`;
    }
    if (props.variant === 'compact') {
        return `${base} p-2`;
    }
    return `${base} p-2.5`;
});

const mediaClass = computed(() => {
    if (props.variant === 'featured') {
        return 'aspect-[4/3]';
    }
    if (props.variant === 'compact') {
        return 'aspect-[16/9]';
    }
    return 'aspect-[3/2]';
});

const contentClass = computed(() => {
    if (props.variant === 'featured') {
        return 'mt-4 flex flex-1 flex-col gap-4';
    }
    if (props.variant === 'compact') {
        return 'mt-2 flex flex-1 flex-col gap-1.5';
    }
    return 'mt-2.5 flex flex-1 flex-col gap-2';
});

const descriptionClass = computed(() => {
    if (props.variant === 'compact') {
        return 'min-h-6 text-xs text-slate-500';
    }
    return 'min-h-7 text-xs text-slate-500';
});

const handleOpen = () => emit('open', props.product);
const handleAdd = () => emit('add', props.product);
const handleView = () => emit('view', props.product);
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
                <img
                    v-if="product.image_url"
                    :src="product.image_url"
                    :alt="product.name"
                    class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                    loading="lazy"
                    decoding="async"
                >
                <div v-else class="flex h-full w-full items-center justify-center text-2xl font-semibold text-slate-300">
                    {{ product.name?.charAt(0) }}
                </div>
            </div>
            <div v-if="badges.length" class="absolute left-3 top-3 flex flex-wrap gap-2">
                <Badge v-for="badge in badges" :key="badge.label" :label="badge.label" :tone="badge.tone" />
            </div>
        </div>

        <div :class="contentClass">
            <div class="flex items-start justify-between gap-3">
                <h3 class="text-sm font-semibold text-slate-900">
                    {{ product.name }}
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
                <Price :current="priceMeta.current" :original="priceMeta.original" size="sm" />
                <span v-if="showRating" class="text-xs text-slate-400">
                    {{ ratingLabel }}
                </span>
            </div>
            <div class="mt-auto flex flex-wrap items-center gap-2">
                <button
                    v-if="ctaLabel"
                    type="button"
                    class="rounded-sm bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 disabled:cursor-not-allowed disabled:bg-emerald-300"
                    :disabled="Number(product.stock || 0) <= 0"
                    :aria-label="`${ctaLabel} ${product.name}`"
                    @click.stop="handleAdd"
                >
                    {{ ctaLabel }}
                </button>
                <button
                    v-if="showView && viewLabel"
                    type="button"
                    class="rounded-sm border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:border-slate-300 hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                    :aria-label="`${viewLabel} ${product.name}`"
                    @click.stop="handleView"
                >
                    {{ viewLabel }}
                </button>
                <span v-if="product.sku" class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    {{ product.sku }}
                </span>
            </div>
        </div>
    </article>
</template>
