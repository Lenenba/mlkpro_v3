<script setup>
import SectionHeader from '@/Components/Store/SectionHeader.vue';
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
    skeletonCount: { type: Number, default: 4 },
});

const emit = defineEmits(['action', 'open', 'add', 'view', 'increment', 'decrement']);
</script>

<template>
    <section :id="sectionId" class="py-8">
        <div class="mx-auto w-full px-4 sm:px-6 lg:px-10">
            <SectionHeader
                :title="title"
                :subtitle="subtitle"
                :action-label="actionLabel"
                @action="emit('action')"
            />

            <div v-if="loading" class="mt-4 flex gap-3 overflow-x-auto pb-2 md:grid md:grid-cols-3 md:overflow-visible lg:grid-cols-4">
                <ProductCard
                    v-for="n in skeletonCount"
                    :key="`${sectionId}-skeleton-${n}`"
                    :product="{ id: `skeleton-${n}`, name: 'Loading' }"
                    :variant="cardVariant"
                    :loading="true"
                />
            </div>
            <div v-else-if="products.length" class="mt-4 flex gap-3 overflow-x-auto pb-2 md:grid md:grid-cols-3 md:overflow-visible lg:grid-cols-4">
                <ProductCard
                    v-for="product in products"
                    :key="product.id"
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
            <p v-else class="mt-4 text-sm text-slate-500">
                {{ emptyLabel }}
            </p>
        </div>
    </section>
</template>
