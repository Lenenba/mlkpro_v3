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
</script>

<template>
    <div v-if="normalizedLinks.length" class="flex flex-wrap items-center justify-center gap-2 text-sm text-stone-600 dark:text-neutral-400">
        <template v-for="link in normalizedLinks" :key="link.url || link.label">
            <span
                v-if="!link.url"
                v-html="link.label"
                class="rounded-sm border border-stone-200 px-2 py-1 text-stone-400 dark:border-neutral-700"
            />
            <Link
                v-else
                :href="link.url"
                v-html="link.label"
                class="rounded-sm border border-stone-200 px-2 py-1 text-center dark:border-neutral-700"
                :class="link.active ? 'border-transparent bg-green-600 text-white' : 'hover:bg-stone-50 dark:hover:bg-neutral-700'"
                preserve-scroll
            />
        </template>
    </div>
</template>
