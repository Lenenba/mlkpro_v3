<script setup>
import { computed } from 'vue';
import { resolvePublicStockImage } from '@/utils/publicResponsiveImages';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    src: {
        type: String,
        required: true,
    },
    alt: {
        type: String,
        default: '',
    },
    loading: {
        type: String,
        default: 'lazy',
    },
    decoding: {
        type: String,
        default: 'async',
    },
    fetchPriority: {
        type: String,
        default: null,
    },
    sizes: {
        type: String,
        default: '100vw',
    },
    width: {
        type: [Number, String],
        default: null,
    },
    height: {
        type: [Number, String],
        default: null,
    },
});

const stockImage = computed(() => resolvePublicStockImage(props.src));
const intrinsicWidth = computed(() => props.width || stockImage.value?.width || undefined);
const intrinsicHeight = computed(() => props.height || stockImage.value?.height || undefined);
</script>

<template>
    <picture v-bind="$attrs" class="public-responsive-image">
        <source
            v-if="stockImage"
            :srcset="stockImage.avifSrcSet"
            :sizes="sizes"
            type="image/avif"
        >
        <source
            v-if="stockImage"
            :srcset="stockImage.webpSrcSet"
            :sizes="sizes"
            type="image/webp"
        >
        <img
            :src="src"
            :alt="alt"
            :width="intrinsicWidth"
            :height="intrinsicHeight"
            :sizes="stockImage ? sizes : undefined"
            :loading="loading"
            :decoding="decoding"
            :fetchpriority="fetchPriority || undefined"
        >
    </picture>
</template>

<style scoped>
.public-responsive-image {
    display: block;
}

.public-responsive-image > img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: inherit;
    border-radius: inherit;
}
</style>
