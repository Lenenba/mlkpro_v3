<script setup>
import { computed } from 'vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    announcements: {
        type: Array,
        default: () => [],
    },
    title: {
        type: String,
        default: 'Updates',
    },
    subtitle: {
        type: String,
        default: 'Platform news, tips, and seasonal greetings.',
    },
    variant: {
        type: String,
        default: 'panel',
    },
    limit: {
        type: Number,
        default: 4,
    },
});

const visibleAnnouncements = computed(() => {
    const items = props.announcements || [];
    if (!Number.isFinite(props.limit) || props.limit <= 0) {
        return items;
    }
    return items.slice(0, props.limit);
});

const hasAnnouncements = computed(() => visibleAnnouncements.value.length > 0);
const isSide = computed(() => props.variant === 'side');
const sectionClass = computed(() =>
    isSide.value
        ? 'rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 h-full'
        : 'rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900'
);
const gridClass = computed(() =>
    isSide.value ? 'mt-3 grid gap-3' : 'mt-4 grid gap-4 lg:grid-cols-2'
);
const mediaClass = computed(() =>
    isSide.value ? 'h-36 w-full object-cover' : 'h-44 w-full object-cover'
);
const mediaVideoClass = computed(() => (isSide.value ? 'h-36 w-full' : 'h-44 w-full'));

const formatDate = (value) => humanizeDate(value) || '';

const announcementWindow = (item) => {
    const start = item?.starts_at ? formatDate(item.starts_at) : '';
    const end = item?.ends_at ? formatDate(item.ends_at) : '';

    if (start && end) {
        return `${start} - ${end}`;
    }
    if (end) {
        return `Valid until ${end}`;
    }
    if (start) {
        return `From ${start}`;
    }
    return '';
};

const linkLabel = (item) => item?.link_label || 'Learn more';
</script>

<template>
    <section v-if="hasAnnouncements" :class="sectionClass">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ title }}
                </h2>
                <p class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ subtitle }}
                </p>
            </div>
        </div>
        <div :class="gridClass">
            <article v-for="item in visibleAnnouncements" :key="item.id"
                class="rounded-sm border border-stone-200 p-4 text-sm dark:border-neutral-700">
                <div class="flex flex-col gap-2">
                    <div class="text-base font-semibold text-stone-800 dark:text-neutral-100">
                        {{ item.title }}
                    </div>
                    <p v-if="item.body" class="text-sm text-stone-600 dark:text-neutral-300">
                        {{ item.body }}
                    </p>
                    <p v-if="announcementWindow(item)" class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ announcementWindow(item) }}
                    </p>
                </div>

                <div v-if="item.media_url" class="mt-3 overflow-hidden rounded-sm border border-stone-200 dark:border-neutral-700">
                    <img v-if="item.media_type === 'image'" :src="item.media_url" alt=""
                        :class="mediaClass" />
                    <video v-else-if="item.media_type === 'video'" controls :class="mediaVideoClass">
                        <source :src="item.media_url" />
                    </video>
                </div>

                <div v-if="item.link_url" class="mt-3">
                    <a :href="item.link_url" target="_blank" rel="noopener"
                        class="inline-flex items-center gap-2 text-xs font-semibold text-green-600 hover:text-green-700">
                        {{ linkLabel(item) }}
                        <span aria-hidden="true">-&gt;</span>
                    </a>
                </div>
            </article>
        </div>
    </section>
</template>
