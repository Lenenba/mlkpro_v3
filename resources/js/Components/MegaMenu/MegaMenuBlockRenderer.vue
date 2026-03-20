<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    block: {
        type: Object,
        required: true,
    },
    preview: {
        type: Boolean,
        default: false,
    },
});

const safeHref = (href) => {
    const value = String(href || '').trim();
    return value || '#';
};

const linkTarget = (target) => (target === '_blank' ? '_blank' : undefined);
const linkRel = (target) => (target === '_blank' ? 'noopener noreferrer' : undefined);

const tone = computed(() => props.block?.settings?.tone || 'default');
const showcaseItems = computed(() =>
    props.block?.type === 'product_showcase' && Array.isArray(props.block?.payload?.items)
        ? props.block.payload.items
        : []
);
const activeShowcaseIndex = ref(0);
const activeShowcaseItem = computed(() => showcaseItems.value[activeShowcaseIndex.value] || showcaseItems.value[0] || null);
const showcaseHeading = computed(() => activeShowcaseItem.value?.label || props.block?.payload?.title || 'Products & Services');
const showcaseSummary = computed(() =>
    activeShowcaseItem.value?.summary
    || activeShowcaseItem.value?.note
    || props.block?.payload?.description
    || ''
);

const wrapperClasses = computed(() => {
    if (['navigation_group', 'product_showcase', 'category_list', 'quick_links', 'cards'].includes(props.block.type)) {
        return 'space-y-3';
    }

    const base = 'overflow-hidden rounded-[22px] border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900';

    if (props.block.type === 'promo_banner') {
        return `${base} text-white`;
    }

    return base;
});

const navigationLinkClasses = computed(() =>
    tone.value === 'contrast'
        ? 'group flex items-start justify-between gap-4 py-2 text-lg font-semibold tracking-tight text-stone-900 transition hover:text-stone-600 dark:text-white dark:hover:text-neutral-300'
        : 'group flex items-start justify-between gap-3 py-1.5 text-[15px] font-medium text-stone-800 transition hover:text-stone-600 dark:text-neutral-100 dark:hover:text-neutral-300'
);

const setActiveShowcaseIndex = (index) => {
    activeShowcaseIndex.value = index;
};

watch(
    () => showcaseItems.value.length,
    (length) => {
        if (!length) {
            activeShowcaseIndex.value = 0;
            return;
        }

        if (activeShowcaseIndex.value >= length) {
            activeShowcaseIndex.value = 0;
        }
    },
    { immediate: true }
);
</script>

<template>
    <div :class="wrapperClasses">
        <template v-if="block.type === 'navigation_group'">
            <div v-if="block.payload?.title" class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                {{ block.payload.title }}
            </div>
            <p v-if="block.payload?.description" class="max-w-sm text-sm leading-6 text-stone-500 dark:text-neutral-400">
                {{ block.payload.description }}
            </p>
            <div class="space-y-1">
                <a
                    v-for="(link, index) in (block.payload?.links || [])"
                    :key="`${block.id || block.title || 'nav'}-${index}`"
                    :href="preview ? '#' : safeHref(link.resolved_href || link.href)"
                    :target="linkTarget(link.target)"
                    :rel="linkRel(link.target)"
                    :class="navigationLinkClasses"
                    @click="preview ? $event.preventDefault() : null"
                >
                    <div class="min-w-0">
                        <div class="truncate">{{ link.label || 'Link' }}</div>
                        <div v-if="link.note" class="mt-1 text-sm font-normal leading-5 text-stone-500 dark:text-neutral-400">
                            {{ link.note }}
                        </div>
                    </div>
                    <span v-if="link.badge" class="mt-1 shrink-0 rounded-full bg-stone-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-stone-700 dark:bg-neutral-800 dark:text-neutral-200">
                        {{ link.badge }}
                    </span>
                </a>
            </div>
        </template>

        <template v-else-if="block.type === 'product_showcase'">
            <div class="grid gap-8 xl:grid-cols-[minmax(0,1.1fr)_minmax(320px,0.9fr)] xl:items-start">
                <div class="space-y-6">
                    <div class="space-y-3">
                        <div v-if="block.payload?.title" class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                            {{ block.payload.title }}
                        </div>
                        <div class="max-w-3xl">
                            <h3 class="text-3xl font-semibold tracking-tight text-stone-900 dark:text-white">
                                {{ showcaseHeading }}
                            </h3>
                            <p v-if="showcaseSummary" class="mt-3 max-w-2xl text-sm leading-7 text-stone-600 dark:text-neutral-300">
                                {{ showcaseSummary }}
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-x-8 gap-y-2 sm:grid-cols-2">
                        <a
                            v-for="(item, index) in showcaseItems"
                            :key="`${block.id || block.title || 'showcase'}-${index}`"
                            :href="preview ? '#' : safeHref(item.resolved_href || item.href)"
                            :target="linkTarget(item.target)"
                            :rel="linkRel(item.target)"
                            class="group flex items-start justify-between gap-4 border-b border-stone-200 py-3 transition hover:border-stone-400 dark:border-neutral-700 dark:hover:border-neutral-500"
                            @mouseenter="setActiveShowcaseIndex(index)"
                            @focus="setActiveShowcaseIndex(index)"
                            @click="preview ? $event.preventDefault() : null"
                        >
                            <div class="min-w-0">
                                <div class="text-[15px] font-semibold text-stone-900 transition group-hover:text-stone-700 dark:text-white dark:group-hover:text-neutral-200">
                                    {{ item.label || 'Product' }}
                                </div>
                                <div v-if="item.note" class="mt-1 text-sm leading-6 text-stone-500 dark:text-neutral-400">
                                    {{ item.note }}
                                </div>
                            </div>
                            <span v-if="item.badge" class="mt-0.5 shrink-0 rounded-sm bg-stone-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-stone-700 dark:bg-neutral-800 dark:text-neutral-200">
                                {{ item.badge }}
                            </span>
                        </a>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="overflow-hidden rounded-sm border border-stone-200 bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800">
                        <img
                            v-if="activeShowcaseItem?.image_url"
                            :src="activeShowcaseItem.image_url"
                            :alt="activeShowcaseItem.image_alt || activeShowcaseItem.label || 'Product preview'"
                            :title="activeShowcaseItem.image_title || undefined"
                            class="h-[260px] w-full object-cover"
                            loading="lazy"
                            decoding="async"
                        />
                        <div v-else class="flex h-[260px] items-center justify-center px-6 text-sm font-medium text-stone-500 dark:text-neutral-400">
                            Add a preview image for this product.
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'category_list'">
            <div v-if="block.payload?.title" class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                {{ block.payload.title }}
            </div>
            <p v-if="block.payload?.description" class="text-sm leading-6 text-stone-500 dark:text-neutral-400">
                {{ block.payload.description }}
            </p>
            <div class="space-y-2">
                <a
                    v-for="(category, index) in (block.payload?.categories || [])"
                    :key="`${block.id || block.title || 'cat'}-${index}`"
                    :href="preview ? '#' : safeHref(category.resolved_href || category.href)"
                    class="flex items-center justify-between gap-3 py-1.5 text-[15px] font-medium text-stone-800 transition hover:text-stone-600 dark:text-neutral-100 dark:hover:text-neutral-300"
                    @click="preview ? $event.preventDefault() : null"
                >
                    <span>{{ category.label || 'Category' }}</span>
                    <span v-if="category.meta" class="text-xs font-normal text-stone-500 dark:text-neutral-400">{{ category.meta }}</span>
                </a>
            </div>
        </template>

        <template v-else-if="block.type === 'quick_links'">
            <div v-if="block.payload?.title" class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                {{ block.payload.title }}
            </div>
            <div class="flex flex-wrap gap-3">
                <a
                    v-for="(link, index) in (block.payload?.links || [])"
                    :key="`${block.id || block.title || 'quick'}-${index}`"
                    :href="preview ? '#' : safeHref(link.resolved_href || link.href)"
                    :target="linkTarget(link.target)"
                    :rel="linkRel(link.target)"
                    class="inline-flex items-center rounded-full border border-stone-300 px-4 py-2 text-sm font-medium text-stone-800 transition hover:border-stone-900 hover:bg-stone-50 dark:border-neutral-600 dark:text-neutral-100 dark:hover:border-neutral-300 dark:hover:bg-neutral-800"
                    @click="preview ? $event.preventDefault() : null"
                >
                    {{ link.label || 'Shortcut' }}
                </a>
            </div>
        </template>

        <template v-else-if="block.type === 'cards'">
            <div v-if="block.payload?.title" class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                {{ block.payload.title }}
            </div>
            <div class="grid gap-3">
                <a
                    v-for="(card, index) in (block.payload?.cards || [])"
                    :key="`${block.id || block.title || 'card'}-${index}`"
                    :href="preview ? '#' : safeHref(card.resolved_href || card.href)"
                    class="overflow-hidden rounded-[20px] border border-stone-200 bg-white transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-700 dark:bg-neutral-900"
                    @click="preview ? $event.preventDefault() : null"
                >
                    <img
                        v-if="card.image_url"
                        :src="card.image_url"
                        :alt="card.image_alt || card.title || 'Card image'"
                        :title="card.image_title || undefined"
                        class="h-32 w-full object-cover"
                        loading="lazy"
                        decoding="async"
                    />
                    <div class="space-y-2 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="text-base font-semibold text-stone-900 dark:text-white">{{ card.title || 'Card title' }}</div>
                            <span v-if="card.badge" class="rounded-full bg-stone-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-stone-700 dark:bg-neutral-800 dark:text-neutral-200">
                                {{ card.badge }}
                            </span>
                        </div>
                        <div v-if="card.body" class="text-sm leading-6 text-stone-600 dark:text-neutral-300" v-html="card.body"></div>
                    </div>
                </a>
            </div>
        </template>

        <template v-else-if="block.type === 'featured_content'">
            <div class="grid gap-0 md:grid-cols-[1.05fr_0.95fr]">
                <div class="space-y-3 p-5">
                    <div v-if="block.payload?.eyebrow" class="text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">
                        {{ block.payload.eyebrow }}
                    </div>
                    <div class="text-xl font-semibold tracking-tight text-stone-900 dark:text-white">{{ block.payload?.title || 'Featured content' }}</div>
                    <div v-if="block.payload?.body" class="text-sm leading-6 text-stone-600 dark:text-neutral-300" v-html="block.payload.body"></div>
                    <a
                        v-if="block.payload?.cta_label"
                        :href="preview ? '#' : safeHref(block.payload.resolved_cta_href || block.payload.cta_href)"
                        class="inline-flex rounded-full border border-stone-900 px-4 py-2 text-sm font-semibold text-stone-900 transition hover:bg-stone-900 hover:text-white dark:border-white dark:text-white dark:hover:bg-white dark:hover:text-stone-900"
                        @click="preview ? $event.preventDefault() : null"
                    >
                        {{ block.payload.cta_label }}
                    </a>
                </div>
                <img
                    v-if="block.payload?.image_url"
                    :src="block.payload.image_url"
                    :alt="block.payload.image_alt || block.payload.title || 'Featured image'"
                    :title="block.payload.image_title || undefined"
                    class="h-full min-h-[220px] w-full object-cover"
                    loading="lazy"
                    decoding="async"
                />
            </div>
        </template>

        <template v-else-if="block.type === 'image'">
            <a
                :href="preview ? '#' : safeHref(block.payload?.resolved_href || block.payload?.href)"
                class="block"
                @click="preview ? $event.preventDefault() : null"
            >
                <img
                    v-if="block.payload?.image_url"
                    :src="block.payload.image_url"
                    :alt="block.payload.image_alt || block.payload.caption || 'Image block'"
                    :title="block.payload.image_title || undefined"
                    class="h-44 w-full object-cover"
                    loading="lazy"
                    decoding="async"
                />
                <div v-if="block.payload?.caption" class="px-4 py-3 text-sm leading-6 text-stone-600 dark:text-neutral-300">
                    {{ block.payload.caption }}
                </div>
            </a>
        </template>

        <template v-else-if="block.type === 'promo_banner'">
            <div class="grid min-h-[240px] bg-neutral-950 md:grid-cols-[0.95fr_1.05fr]">
                <div class="flex flex-col justify-between p-5">
                    <div class="space-y-3">
                        <div v-if="block.payload?.badge" class="inline-flex rounded-sm bg-yellow-400 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-stone-950">
                            {{ block.payload.badge }}
                        </div>
                        <div class="text-2xl font-semibold tracking-tight">{{ block.payload?.title || 'Promo banner' }}</div>
                        <div v-if="block.payload?.body" class="text-sm leading-6 text-stone-200/85" v-html="block.payload.body"></div>
                    </div>
                    <a
                        v-if="block.payload?.cta_label"
                        :href="preview ? '#' : safeHref(block.payload.resolved_cta_href || block.payload.cta_href)"
                        class="inline-flex w-fit rounded-full border border-white/40 px-4 py-2 text-sm font-semibold text-white transition hover:border-white hover:bg-white hover:text-stone-950"
                        @click="preview ? $event.preventDefault() : null"
                    >
                        {{ block.payload.cta_label }}
                    </a>
                </div>
                <img
                    v-if="block.payload?.image_url"
                    :src="block.payload.image_url"
                    :alt="block.payload.image_alt || block.payload.title || 'Promo banner'"
                    :title="block.payload.image_title || undefined"
                    class="h-full w-full object-cover"
                    loading="lazy"
                    decoding="async"
                />
            </div>
        </template>

        <template v-else-if="block.type === 'cta'">
            <div class="space-y-3 p-5 text-center">
                <div class="text-xl font-semibold tracking-tight text-stone-900 dark:text-white">{{ block.payload?.title || 'Call to action' }}</div>
                <div v-if="block.payload?.body" class="text-sm leading-6 text-stone-600 dark:text-neutral-300" v-html="block.payload.body"></div>
                <a
                    v-if="block.payload?.button_label"
                    :href="preview ? '#' : safeHref(block.payload.resolved_button_href || block.payload.button_href)"
                    class="inline-flex rounded-full bg-stone-900 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700 dark:bg-white dark:text-stone-900"
                    @click="preview ? $event.preventDefault() : null"
                >
                    {{ block.payload.button_label }}
                </a>
            </div>
        </template>

        <template v-else-if="block.type === 'module_shortcut'">
            <div class="space-y-3 p-5">
                <div v-if="block.payload?.title" class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                    {{ block.payload.title }}
                </div>
                <div class="grid gap-2">
                    <a
                        v-for="(shortcut, index) in (block.payload?.shortcuts || [])"
                        :key="`${block.id || block.title || 'shortcut'}-${index}`"
                        :href="preview ? '#' : safeHref(shortcut.resolved_href)"
                        class="rounded-full border border-stone-200 px-4 py-2 text-sm font-medium text-stone-800 transition hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-100 dark:hover:bg-neutral-800"
                        @click="preview ? $event.preventDefault() : null"
                    >
                        {{ shortcut.label || 'Shortcut' }}
                    </a>
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'demo_preview'">
            <div class="space-y-4 p-5">
                <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">{{ block.payload?.title || 'Demo preview' }}</div>
                <div v-if="block.payload?.body" class="text-sm leading-6 text-stone-600 dark:text-neutral-300" v-html="block.payload.body"></div>
                <img
                    v-if="block.payload?.preview_image_url"
                    :src="block.payload.preview_image_url"
                    :alt="block.payload.preview_image_alt || block.payload.title || 'Demo preview'"
                    :title="block.payload.preview_image_title || undefined"
                    class="h-36 w-full rounded-[18px] object-cover"
                    loading="lazy"
                    decoding="async"
                />
                <div v-if="(block.payload?.metrics || []).length" class="grid grid-cols-2 gap-3">
                    <div
                        v-for="(metric, index) in (block.payload?.metrics || [])"
                        :key="`${block.id || block.title || 'metric'}-${index}`"
                        class="rounded-[18px] bg-stone-50 px-4 py-3 dark:bg-neutral-800"
                    >
                        <div class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ metric.label || 'Metric' }}</div>
                        <div class="mt-1 text-lg font-semibold tracking-tight text-stone-900 dark:text-white">{{ metric.value || '-' }}</div>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="block.type === 'html'">
            <div class="prose prose-sm max-w-none p-5 prose-stone dark:prose-invert" v-html="block.payload?.html || ''"></div>
        </template>

        <template v-else>
            <div class="space-y-3 p-5">
                <div v-if="block.payload?.title" class="text-xl font-semibold tracking-tight text-stone-900 dark:text-white">
                    {{ block.payload.title }}
                </div>
                <div class="prose prose-sm max-w-none prose-stone dark:prose-invert" v-html="block.payload?.body || ''"></div>
            </div>
        </template>
    </div>
</template>
