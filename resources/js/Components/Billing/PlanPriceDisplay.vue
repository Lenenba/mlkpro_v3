<script setup>
import { computed } from 'vue';

const props = defineProps({
    pricing: {
        type: Object,
        default: () => ({}),
    },
    contactOnly: {
        type: Boolean,
        default: false,
    },
    customLabel: {
        type: String,
        default: 'Custom pricing',
    },
    emptyLabel: {
        type: String,
        default: '--',
    },
    intervalLabel: {
        type: String,
        default: null,
    },
    showPromotionBadge: {
        type: Boolean,
        default: true,
    },
    containerClass: {
        type: String,
        default: '',
    },
    priceClass: {
        type: String,
        default: '',
    },
    originalPriceClass: {
        type: String,
        default: '',
    },
    intervalClass: {
        type: String,
        default: '',
    },
    badgeClass: {
        type: String,
        default: '',
    },
});

const currentDisplayPrice = computed(() =>
    props.pricing?.discounted_display_price
    || props.pricing?.display_price
    || props.pricing?.original_display_price
    || (props.contactOnly ? props.customLabel : props.emptyLabel)
);

const originalDisplayPrice = computed(() =>
    props.pricing?.original_display_price
    || props.pricing?.display_price
    || null
);

const promotionPercent = computed(() => {
    const raw = Number(props.pricing?.promotion?.discount_percent || 0);

    return Number.isFinite(raw) && raw > 0 ? raw : null;
});

const showOriginalPrice = computed(() =>
    Boolean(
        props.pricing?.is_discounted
        && originalDisplayPrice.value
        && originalDisplayPrice.value !== currentDisplayPrice.value
    )
);

const showPromotionBadge = computed(() =>
    Boolean(
        props.showPromotionBadge
        && props.pricing?.is_discounted
        && promotionPercent.value
    )
);
</script>

<template>
    <div :class="containerClass || 'flex flex-wrap items-baseline gap-x-2 gap-y-1'">
        <span
            v-if="showOriginalPrice"
            :class="originalPriceClass || 'text-sm font-medium text-stone-400 line-through'"
        >
            {{ originalDisplayPrice }}
        </span>
        <span :class="priceClass || 'text-2xl font-semibold text-stone-900'">
            {{ currentDisplayPrice }}
        </span>
        <span
            v-if="intervalLabel && !contactOnly"
            :class="intervalClass || 'text-sm font-medium text-stone-500'"
        >
            {{ intervalLabel }}
        </span>
        <span
            v-if="showPromotionBadge"
            :class="badgeClass || 'rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700'"
        >
            -{{ promotionPercent }}%
        </span>
    </div>
</template>
