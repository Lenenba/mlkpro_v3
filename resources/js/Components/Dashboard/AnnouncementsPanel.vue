<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { humanizeDate } from '@/utils/date';
import {
    isMediaOnlyAnnouncement,
    selectPanelAnnouncements,
} from '@/Components/Dashboard/announcementPanel';

const props = defineProps({
    announcements: {
        type: Array,
        default: () => [],
    },
    title: {
        type: String,
        default: null,
    },
    subtitle: {
        type: String,
        default: null,
    },
    variant: {
        type: String,
        default: 'panel',
    },
    limit: {
        type: Number,
        default: 4,
    },
    fillHeight: {
        type: Boolean,
        default: true,
    },
});

const { t } = useI18n();

const visibleAnnouncements = computed(() => selectPanelAnnouncements(props.announcements, props.limit));

const hasAnnouncements = computed(() => visibleAnnouncements.value.length > 0);
const resolvedTitle = computed(() => props.title || t('dashboard.announcements.updates_title'));
const resolvedSubtitle = computed(() => props.subtitle || t('dashboard.announcements.updates_subtitle'));
const isSide = computed(() => props.variant === 'side');
const fillsSideHeight = computed(() => isSide.value && props.fillHeight);
const isMediaOnlyPanel = computed(() => (
    visibleAnnouncements.value.length === 1
    && isMediaOnlyAnnouncement(visibleAnnouncements.value[0])
));
const panelBackground = computed(() => {
    if (isMediaOnlyPanel.value) {
        return null;
    }

    const value = visibleAnnouncements.value[0]?.background_color;
    return typeof value === 'string' && /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(value)
        ? value
        : null;
});
const hasDarkPanelBackground = computed(() => {
    if (!panelBackground.value) {
        return false;
    }

    const shortHex = panelBackground.value.slice(1);
    const hex = shortHex.length === 3
        ? shortHex.split('').map((character) => `${character}${character}`).join('')
        : shortHex;
    const red = Number.parseInt(hex.slice(0, 2), 16);
    const green = Number.parseInt(hex.slice(2, 4), 16);
    const blue = Number.parseInt(hex.slice(4, 6), 16);

    return ((red * 299) + (green * 587) + (blue * 114)) / 1000 < 150;
});
const sectionStyle = computed(() => (
    panelBackground.value ? { backgroundColor: panelBackground.value } : undefined
));
const primaryTextClass = computed(() => {
    if (!panelBackground.value) {
        return 'text-stone-800 dark:text-neutral-100';
    }

    return hasDarkPanelBackground.value ? 'text-white' : 'text-stone-900';
});
const secondaryTextClass = computed(() => {
    if (!panelBackground.value) {
        return 'text-stone-600 dark:text-neutral-300';
    }

    return hasDarkPanelBackground.value ? 'text-white/85' : 'text-stone-700';
});
const mutedTextClass = computed(() => {
    if (!panelBackground.value) {
        return 'text-stone-500 dark:text-neutral-400';
    }

    return hasDarkPanelBackground.value ? 'text-white/70' : 'text-stone-600';
});
const linkClass = computed(() => (
    panelBackground.value && hasDarkPanelBackground.value
        ? 'text-white underline decoration-white/50 underline-offset-2 hover:text-white/80'
        : 'text-green-600 hover:text-green-700'
));
const sectionClass = computed(() => {
    const shell = 'overflow-hidden rounded-sm border border-stone-200 shadow-sm dark:border-neutral-700';
    if (isMediaOnlyPanel.value) {
        return fillsSideHeight.value
            ? `${shell} relative bg-white dark:bg-neutral-900 xl:h-full xl:min-h-0 xl:self-stretch`
            : `${shell} bg-white dark:bg-neutral-900`;
    }
    if (isSide.value) {
        const base = `${shell} bg-white p-4 dark:bg-neutral-900`;
        return fillsSideHeight.value ? `${base} xl:h-full xl:self-stretch` : base;
    }
    return `${shell} bg-white p-5 dark:bg-neutral-900`;
});
const gridClass = computed(() => {
    if (isMediaOnlyPanel.value) {
        // On desktop, keep the media out of the parent grid's intrinsic size
        // calculation. The KPI column defines the row height and this layer
        // then fills it. Mobile keeps its natural media height.
        return fillsSideHeight.value
            ? 'grid xl:absolute xl:inset-0 xl:min-h-0'
            : 'grid';
    }

    return isSide.value ? 'mt-3 grid' : 'mt-4 grid gap-4 lg:grid-cols-2';
});
const standardMediaClass = 'mt-3 block h-auto w-full object-contain';
const mediaOnlyClass = computed(() => (
    fillsSideHeight.value
        ? 'block h-auto max-h-full w-full object-cover object-center xl:h-full xl:min-h-0'
        : 'block h-auto max-h-full w-full object-cover object-center'
));
const cardClass = (index) => {
    const classes = ['min-w-0 text-sm'];

    if (isMediaOnlyPanel.value) {
        classes.push('overflow-hidden bg-white dark:bg-neutral-900');
        if (fillsSideHeight.value) {
            classes.push('xl:h-full xl:min-h-0');
        }
    }

    if (!isMediaOnlyPanel.value && isSide.value && index > 0) {
        classes.push('mt-3 border-t pt-3');
        classes.push(
            panelBackground.value
                ? (hasDarkPanelBackground.value ? 'border-white/20' : 'border-black/10')
                : 'border-stone-200 dark:border-neutral-700',
        );
    }

    return classes.join(' ');
};

const formatDate = (value) => humanizeDate(value) || '';

const announcementWindow = (item) => {
    const start = item?.starts_at ? formatDate(item.starts_at) : '';
    const end = item?.ends_at ? formatDate(item.ends_at) : '';

    if (start && end) {
        return `${start} - ${end}`;
    }
    if (end) {
        return t('dashboard.announcements.valid_until', { date: end });
    }
    if (start) {
        return t('dashboard.announcements.from', { date: start });
    }
    return '';
};

const linkLabel = (item) => item?.link_label || t('dashboard.announcements.learn_more');
</script>

<template>
    <section
        v-if="hasAnnouncements"
        :class="sectionClass"
        :style="sectionStyle"
        :data-display-style="isMediaOnlyPanel ? 'media_only' : 'standard'"
    >
        <div v-if="!isMediaOnlyPanel" class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-sm font-semibold" :class="primaryTextClass">
                    {{ resolvedTitle }}
                </h2>
                <p class="text-xs" :class="mutedTextClass">
                    {{ resolvedSubtitle }}
                </p>
            </div>
        </div>
        <div :class="gridClass">
            <article
                v-for="(item, index) in visibleAnnouncements"
                :key="item.id"
                :class="cardClass(index)"
            >
                <template v-if="!isMediaOnlyAnnouncement(item)">
                    <div class="flex flex-col gap-2">
                        <h3 class="text-base font-semibold" :class="primaryTextClass">
                            {{ item.title }}
                        </h3>
                        <p v-if="item.body" class="text-sm" :class="secondaryTextClass">
                            {{ item.body }}
                        </p>
                        <p v-if="announcementWindow(item)" class="text-xs" :class="mutedTextClass">
                            {{ announcementWindow(item) }}
                        </p>
                    </div>

                    <img
                        v-if="item.media_url && item.media_type === 'image'"
                        :src="item.media_url"
                        alt=""
                        :class="standardMediaClass"
                        loading="lazy"
                        decoding="async"
                    />
                    <video
                        v-else-if="item.media_url && item.media_type === 'video'"
                        controls
                        playsinline
                        preload="metadata"
                        :aria-label="item.title || resolvedTitle"
                        :class="standardMediaClass"
                    >
                        <source :src="item.media_url" />
                    </video>

                    <div v-if="item.link_url" class="mt-3">
                        <a :href="item.link_url" target="_blank" rel="noopener"
                            class="inline-flex items-center gap-2 text-xs font-semibold" :class="linkClass">
                            {{ linkLabel(item) }}
                            <span aria-hidden="true">-&gt;</span>
                        </a>
                    </div>
                </template>
                <template v-else>
                    <img
                        v-if="item.media_type === 'image'"
                        :src="item.media_url"
                        :alt="item.title || resolvedTitle"
                        :class="mediaOnlyClass"
                        loading="eager"
                        fetchpriority="high"
                        decoding="async"
                    />
                    <video
                        v-else-if="item.media_type === 'video'"
                        controls
                        playsinline
                        preload="metadata"
                        :aria-label="item.title || resolvedTitle"
                        :class="mediaOnlyClass"
                    >
                        <source :src="item.media_url" />
                    </video>
                </template>
            </article>
        </div>
    </section>
</template>
