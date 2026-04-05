<script setup>
import { computed } from 'vue';
import { BACKGROUND_PRESET_WELCOME_HERO, resolveBackgroundPreset } from '@/utils/backgroundPresets';

const props = defineProps({
    eyebrow: {
        type: String,
        default: '',
    },
    title: {
        type: String,
        default: '',
    },
    subtitle: {
        type: String,
        default: '',
    },
    subtitleHtml: {
        type: String,
        default: '',
    },
    meta: {
        type: String,
        default: '',
    },
    imageSrc: {
        type: String,
        default: '/images/landing/stock/team-laptop-window.jpg',
    },
    imageAlt: {
        type: String,
        default: '',
    },
});

const brandPanelStyle = computed(() => ({
    background: resolveBackgroundPreset(BACKGROUND_PRESET_WELCOME_HERO),
}));

const resolvedImageAlt = computed(() => props.imageAlt || props.title || 'Page hero image');
</script>

<template>
    <section class="public-front-hero">
        <div class="public-front-hero__shell">
            <div class="public-front-hero__panels" aria-hidden="true">
                <div class="public-front-hero__panel public-front-hero__panel--brand" :style="brandPanelStyle"></div>
                <div class="public-front-hero__panel public-front-hero__panel--image">
                    <img
                        :src="imageSrc"
                        :alt="resolvedImageAlt"
                        class="public-front-hero__image"
                        loading="eager"
                        decoding="async"
                    />
                </div>
                <div class="public-front-hero__veil"></div>
            </div>

            <div class="public-front-hero__content">
                <div class="public-front-hero__copy">
                    <div v-if="eyebrow" class="public-front-hero__eyebrow">{{ eyebrow }}</div>
                    <h1 class="public-front-hero__title">{{ title }}</h1>
                    <div
                        v-if="subtitleHtml"
                        class="public-front-hero__subtitle public-front-hero__subtitle--rich"
                        v-html="subtitleHtml"
                    ></div>
                    <p v-else-if="subtitle" class="public-front-hero__subtitle">{{ subtitle }}</p>
                    <p v-if="meta" class="public-front-hero__meta">{{ meta }}</p>
                    <div v-if="$slots.default" class="public-front-hero__extra">
                        <slot />
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.public-front-hero {
    padding: 0;
}

.public-front-hero__shell {
    position: relative;
    width: 100%;
    min-height: clamp(18rem, 32vw, 24rem);
    overflow: hidden;
    border-bottom: 1px solid rgba(148, 163, 184, 0.22);
    background: #0f172a;
    isolation: isolate;
}

.public-front-hero__panels {
    position: absolute;
    inset: 0;
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(18rem, 0.95fr);
}

.public-front-hero__panel {
    position: relative;
    min-width: 0;
    min-height: 100%;
}

.public-front-hero__panel--brand::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        linear-gradient(120deg, rgba(15, 23, 42, 0.3) 0%, rgba(15, 23, 42, 0) 38%),
        radial-gradient(circle at 22% 20%, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0) 28%);
}

.public-front-hero__panel--image {
    background: #dbe4f0;
}

.public-front-hero__image {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center;
    filter: saturate(0.98) contrast(1.02);
}

.public-front-hero__veil {
    position: absolute;
    inset: 0;
    background:
        linear-gradient(90deg, rgba(7, 14, 24, 0.86) 0%, rgba(7, 14, 24, 0.76) 34%, rgba(7, 14, 24, 0.34) 64%, rgba(7, 14, 24, 0.1) 100%);
}

.public-front-hero__content {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    min-height: inherit;
    width: min(var(--public-shell-width, 88rem), 100%);
    margin-inline: auto;
    box-sizing: border-box;
    padding-block: clamp(1.8rem, 4vw, 3.25rem);
    padding-inline: var(--public-shell-gutter, 1.25rem);
}

.public-front-hero__copy {
    width: min(100%, 39rem);
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
}

.public-front-hero__eyebrow {
    color: #a7f3d0;
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.public-front-hero__title {
    margin: 0;
    color: #f8fafc;
    font-family: var(--page-font-heading, var(--front-font-heading));
    font-size: clamp(2rem, 4vw, 3.6rem);
    font-weight: 700;
    line-height: 0.95;
    letter-spacing: -0.04em;
    max-width: 11ch;
}

.public-front-hero__subtitle,
.public-front-hero__subtitle--rich :deep(p) {
    margin: 0;
    max-width: 34rem;
    color: rgba(236, 253, 245, 0.88);
    font-size: 0.98rem;
    line-height: 1.65;
}

.public-front-hero__subtitle--rich :deep(p + p) {
    margin-top: 0.7rem;
}

.public-front-hero__subtitle--rich :deep(strong) {
    color: #f8fafc;
}

.public-front-hero__meta {
    margin: 0;
    color: rgba(226, 232, 240, 0.72);
    font-size: 0.8rem;
    line-height: 1.5;
}

.public-front-hero__extra {
    padding-top: 0.35rem;
}

@media (max-width: 960px) {
    .public-front-hero__panels {
        grid-template-columns: 1fr;
    }

    .public-front-hero__panel--image {
        position: absolute;
        inset: 44% 0 0;
    }

    .public-front-hero__veil {
        background:
            linear-gradient(180deg, rgba(7, 14, 24, 0.9) 0%, rgba(7, 14, 24, 0.82) 46%, rgba(7, 14, 24, 0.52) 72%, rgba(7, 14, 24, 0.32) 100%);
    }

    .public-front-hero__content {
        align-items: flex-start;
        padding-block: 1.5rem;
        padding-inline: var(--public-shell-gutter, 1.25rem);
    }

    .public-front-hero__title {
        max-width: 12ch;
        font-size: clamp(1.9rem, 7vw, 2.8rem);
    }
}
</style>

