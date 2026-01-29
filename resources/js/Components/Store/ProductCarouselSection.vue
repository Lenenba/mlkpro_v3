<script setup>
import { ref } from 'vue';
import ProductCard from '@/Components/Store/ProductCard.vue';

const props = defineProps({
    sectionId: { type: String, default: '' },
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    actionLabel: { type: String, default: '' },
    emptyLabel: { type: String, default: '' },
    products: { type: Array, default: () => [] },
    cardVariant: { type: String, default: 'compact' },
    descriptionFallback: { type: String, default: '' },
    ratingEmptyLabel: { type: String, default: '' },
    ctaLabel: { type: String, default: '' },
    viewLabel: { type: String, default: '' },
    getBadges: { type: Function, default: null },
    getStockLabel: { type: Function, default: null },
    getStockTone: { type: Function, default: null },
    getQuantity: { type: Function, default: null },
    showQuickAdd: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    skeletonCount: { type: Number, default: 6 },
    slidesQty: {
        type: Object,
        default: () => ({
            xs: 2,
            md: 3,
            lg: 4,
            xl: 5,
        }),
    },
});

const emit = defineEmits(['action', 'open', 'add', 'view', 'increment', 'decrement']);

const trackRef = ref(null);

const scrollByAmount = (direction) => {
    const track = trackRef.value;
    if (!track) {
        return;
    }
    const amount = Math.max(240, track.clientWidth * 0.9);
    track.scrollBy({ left: direction * amount, behavior: 'smooth' });
};
</script>

<template>
    <section :id="sectionId" class="py-8">
        <div class="mx-auto w-full px-4 sm:px-6 lg:px-10">
            <div class="relative">
                <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">
                            {{ title }}
                        </h2>
                        <p v-if="subtitle" class="text-sm text-slate-500">
                            {{ subtitle }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex size-8 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-600 shadow-sm hover:bg-slate-50"
                            @click="scrollByAmount(-1)"
                        >
                            <span class="text-2xl" aria-hidden="true">
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m15 18-6-6 6-6" />
                                </svg>
                            </span>
                            <span class="sr-only">Previous</span>
                        </button>
                        <button
                            type="button"
                            class="inline-flex size-8 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-600 shadow-sm hover:bg-slate-50"
                            @click="scrollByAmount(1)"
                        >
                            <span class="sr-only">Next</span>
                            <span class="text-2xl" aria-hidden="true">
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m9 18 6-6-6-6" />
                                </svg>
                            </span>
                        </button>
                        <button
                            v-if="actionLabel"
                            type="button"
                            class="rounded-full border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600 hover:border-slate-300 hover:bg-white"
                            @click="emit('action')"
                        >
                            {{ actionLabel }}
                        </button>
                    </div>
                </div>

                <div
                    v-if="loading"
                    ref="trackRef"
                    class="flex flex-nowrap gap-3 overflow-x-auto scroll-smooth snap-x snap-mandatory pb-2"
                >
                        <div
                            v-for="n in skeletonCount"
                            :key="`${sectionId}-skeleton-${n}`"
                            class="snap-start shrink-0 w-[70%] sm:w-[45%] md:w-[32%] lg:w-[24%] xl:w-[20%]"
                        >
                            <ProductCard
                                :product="{ id: `skeleton-${n}`, name: 'Loading' }"
                                :variant="cardVariant"
                                :loading="true"
                            />
                        </div>
                </div>

                <div
                    v-else-if="products.length"
                    ref="trackRef"
                    class="flex flex-nowrap gap-3 overflow-x-auto scroll-smooth snap-x snap-mandatory pb-2"
                >
                        <div
                            v-for="product in products"
                            :key="product.id"
                            class="snap-start shrink-0 w-[70%] sm:w-[45%] md:w-[32%] lg:w-[24%] xl:w-[20%]"
                        >
                            <ProductCard
                                :product="product"
                                :variant="cardVariant"
                                :badges="getBadges ? getBadges(product) : []"
                                :stock-label="getStockLabel ? getStockLabel(product) : ''"
                                :stock-tone="getStockTone ? getStockTone(product) : 'neutral'"
                                :description-fallback="descriptionFallback"
                                :rating-empty-label="ratingEmptyLabel"
                                :cta-label="ctaLabel"
                                :view-label="viewLabel"
                                :quantity="getQuantity ? getQuantity(product) : 0"
                                :show-quick-add="showQuickAdd"
                                @open="emit('open', product)"
                                @add="emit('add', $event)"
                                @view="emit('view', product)"
                                @increment="emit('increment', $event)"
                                @decrement="emit('decrement', $event)"
                            />
                        </div>
                </div>

                <p v-else class="mt-4 text-sm text-slate-500">
                    {{ emptyLabel }}
                </p>
            </div>
        </div>
    </section>
</template>
