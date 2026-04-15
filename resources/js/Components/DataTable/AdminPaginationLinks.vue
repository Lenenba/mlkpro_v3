<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    links: {
        type: Array,
        default: () => [],
    },
});

const normalizedLinks = computed(() => (Array.isArray(props.links) ? props.links : []));
const paginationItems = computed(() => normalizedLinks.value.map((link, index) => {
    const rawLabel = String(link?.label ?? '');
    const plainLabel = rawLabel.replace(/<[^>]*>/g, '').trim();

    return {
        key: `pagination-link-${index}-${plainLabel || 'item'}-${link?.url || (link?.active ? 'active' : 'disabled')}`,
        rawLabel,
        plainLabel,
        url: link?.url || null,
        active: Boolean(link?.active),
    };
}));
</script>

<template>
    <div v-if="paginationItems.length" class="flex flex-wrap items-center justify-center gap-1.5 text-sm text-stone-600 dark:text-neutral-400">
        <template v-for="item in paginationItems" :key="item.key">
            <span
                v-if="!item.url"
                v-html="item.rawLabel"
                class="inline-flex min-w-8 items-center justify-center rounded-sm border border-stone-200 bg-stone-50 px-2.5 py-1.5 text-xs font-medium text-stone-400 dark:border-neutral-700 dark:bg-neutral-800/70 dark:text-neutral-500"
            />
            <Link
                v-else
                :href="item.url"
                v-html="item.rawLabel"
                class="inline-flex min-w-8 items-center justify-center rounded-sm border px-2.5 py-1.5 text-center text-xs font-medium transition-colors duration-150"
                :class="item.active
                    ? 'border-emerald-600 bg-emerald-600 text-white shadow-sm dark:border-emerald-500 dark:bg-emerald-500 dark:text-white'
                    : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                :aria-current="item.active ? 'page' : undefined"
                preserve-scroll
                preserve-state
            />
        </template>
    </div>
</template>
