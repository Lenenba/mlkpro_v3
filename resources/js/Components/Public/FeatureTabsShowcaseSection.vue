<script setup>
import { computed, ref, watch } from 'vue';
import { ArrowRight, ChevronDown } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';
import { buildBackgroundStyle, resolveBackgroundTone } from '@/utils/backgroundPresets';

const props = defineProps({
    section: {
        type: Object,
        required: true,
    },
});

const tabs = computed(() => (
    Array.isArray(props.section?.feature_tabs)
        ? props.section.feature_tabs.filter((tab) => String(tab?.label || '').trim().length > 0)
        : []
));

const activeTabId = ref(null);
const previewTabId = ref(null);
const activeChildId = ref(null);

const currentTab = computed(() => (
    tabs.value.find((tab) => tab.id === activeTabId.value) || null
));

const previewTab = computed(() => (
    tabs.value.find((tab) => tab.id === previewTabId.value) || tabs.value[0] || null
));

const featureTabChildren = (tab) => (
    Array.isArray(tab?.children)
        ? tab.children.filter((child) => String(child?.label || '').trim().length > 0)
        : []
);

const currentChildren = computed(() => (
    featureTabChildren(previewTab.value)
));

watch(tabs, (nextTabs) => {
    if (!nextTabs.length) {
        activeTabId.value = null;
        previewTabId.value = null;
        activeChildId.value = null;
        return;
    }

    if (!nextTabs.some((tab) => tab.id === activeTabId.value)) {
        activeTabId.value = null;
    }

    if (!nextTabs.some((tab) => tab.id === previewTabId.value)) {
        previewTabId.value = nextTabs[0].id;
        activeChildId.value = null;
    }
}, { immediate: true });

watch(currentChildren, (nextChildren) => {
    if (!nextChildren.length) {
        activeChildId.value = null;
        return;
    }

    if (!nextChildren.some((child) => child.id === activeChildId.value)) {
        activeChildId.value = nextChildren[0].id;
    }
}, { immediate: true });

const currentPanel = computed(() => (
    currentChildren.value.find((child) => child.id === activeChildId.value) || previewTab.value || null
));

const hasOpenPanel = computed(() => Boolean(currentTab.value));

const currentMetric = computed(() => (
    String(currentPanel.value?.metric || previewTab.value?.metric || '').trim()
));

const currentStory = computed(() => (
    String(currentPanel.value?.story || previewTab.value?.story || '').trim()
));

const currentPerson = computed(() => (
    String(currentPanel.value?.person || previewTab.value?.person || '').trim()
));

const currentRole = computed(() => (
    String(currentPanel.value?.role || previewTab.value?.role || '').trim()
));

const currentAvatarUrl = computed(() => (
    String(currentPanel.value?.avatar_url || previewTab.value?.avatar_url || '').trim()
));

const currentAvatarAlt = computed(() => (
    String(currentPanel.value?.avatar_alt || previewTab.value?.avatar_alt || currentPerson.value || '').trim()
));

const currentPanelImageUrl = computed(() => (
    String(currentPanel.value?.image_url || previewTab.value?.image_url || '').trim()
));

const currentPanelImageAlt = computed(() => (
    String(
        currentPanel.value?.image_alt
        || previewTab.value?.image_alt
        || currentPanel.value?.title
        || currentPanel.value?.label
        || previewTab.value?.title
        || previewTab.value?.label
        || ''
    ).trim()
));

const currentPersonInitials = computed(() => {
    const parts = currentPerson.value
        .split(/\s+/)
        .map((part) => part.trim())
        .filter(Boolean)
        .slice(0, 2);

    return parts.map((part) => part.charAt(0).toUpperCase()).join('') || '?';
});

const featureTabsStyle = computed(() => (
    props.section?.feature_tabs_style === 'workflow' ? 'workflow' : 'editorial'
));

const isWorkflowStyle = computed(() => featureTabsStyle.value === 'workflow');
const hasDarkBackground = computed(() => resolveBackgroundTone(props.section || {}) === 'dark');

const showcaseStyle = computed(() => {
    const rawSize = Number(props.section?.feature_tabs_font_size);
    const safeSize = Number.isFinite(rawSize) && rawSize > 0
        ? Math.max(14, Math.min(Math.round(rawSize * 0.55), 22))
        : 16;

    return {
        '--feature-tabs-showcase-tab-size': `${safeSize}px`,
        ...buildBackgroundStyle(props.section || {}),
    };
});

const shouldUseAnchor = (href) => /^(https?:|mailto:|tel:|#)/i.test(String(href || '').trim());

const resolveHref = (href) => {
    const value = String(href || '').trim();
    if (!value) return '#';
    if (value.startsWith('http://') || value.startsWith('https://') || value.startsWith('/') || value.startsWith('#')) {
        return value;
    }
    try {
        return route(value);
    } catch (error) {
        return value;
    }
};

const isExternalHref = (href) => {
    const value = String(href || '').trim();
    if (!value.startsWith('http://') && !value.startsWith('https://')) {
        return false;
    }

    if (typeof window === 'undefined') {
        return true;
    }

    try {
        const url = new URL(value, window.location.origin);
        return url.origin !== window.location.origin;
    } catch (error) {
        return true;
    }
};

const setActiveTab = (tab) => {
    if (!tab?.id) {
        return;
    }

    previewTabId.value = tab.id;

    const nextChildren = featureTabChildren(tab);
    if (!nextChildren.some((child) => child.id === activeChildId.value)) {
        activeChildId.value = nextChildren[0]?.id ?? null;
    }

    if (activeTabId.value === tab.id) {
        activeTabId.value = null;
        return;
    }

    activeTabId.value = tab.id;
};

const setActiveChild = (child, tab = previewTab.value) => {
    if (!child?.id) {
        return;
    }

    if (tab?.id) {
        previewTabId.value = tab.id;
    }

    activeChildId.value = child.id;
};

const setAccordionChild = (tab, child) => {
    if (!tab?.id || !child?.id) {
        return;
    }

    previewTabId.value = tab.id;
    activeTabId.value = tab.id;
    activeChildId.value = child.id;
};
</script>

<template>
    <div
        class="feature-tabs-showcase"
        :class="{
            'feature-tabs-showcase--workflow': isWorkflowStyle,
            'feature-tabs-showcase--editorial': !isWorkflowStyle,
            'feature-tabs-showcase--contrast': hasDarkBackground,
        }"
        :style="showcaseStyle"
    >
        <div class="feature-tabs-showcase__container">
            <div
                v-if="section.kicker || section.title || section.body || section.primary_label || section.secondary_label"
                class="feature-tabs-showcase__header"
            >
                <div v-if="section.kicker" class="feature-tabs-showcase__eyebrow">
                    {{ section.kicker }}
                </div>
                <h2 v-if="section.title" class="feature-tabs-showcase__title">
                    {{ section.title }}
                </h2>
                <div
                    v-if="section.body"
                    class="feature-tabs-showcase__subtitle"
                    v-html="section.body"
                ></div>

                <div v-if="section.primary_label || section.secondary_label" class="feature-tabs-showcase__header-actions">
                    <template v-if="section.primary_label">
                        <a
                            v-if="isExternalHref(resolveHref(section.primary_href))"
                            :href="resolveHref(section.primary_href)"
                            class="feature-tabs-showcase__header-link"
                            rel="noopener noreferrer"
                            target="_blank"
                        >
                            {{ section.primary_label }}
                        </a>
                        <Link
                            v-else
                            :href="resolveHref(section.primary_href)"
                            class="feature-tabs-showcase__header-link"
                        >
                            {{ section.primary_label }}
                        </Link>
                    </template>

                    <template v-if="section.secondary_label">
                        <a
                            v-if="isExternalHref(resolveHref(section.secondary_href))"
                            :href="resolveHref(section.secondary_href)"
                            class="feature-tabs-showcase__header-link feature-tabs-showcase__header-link--muted"
                            rel="noopener noreferrer"
                            target="_blank"
                        >
                            {{ section.secondary_label }}
                        </a>
                        <Link
                            v-else
                            :href="resolveHref(section.secondary_href)"
                            class="feature-tabs-showcase__header-link feature-tabs-showcase__header-link--muted"
                        >
                            {{ section.secondary_label }}
                        </Link>
                    </template>
                </div>
            </div>

            <div
                v-if="tabs.length"
                class="feature-tabs-showcase__accordion-grid"
                :class="{ 'feature-tabs-showcase__accordion-grid--collapsed': !hasOpenPanel }"
            >
                <div class="feature-tabs-showcase__accordion">
                    <article
                        v-for="tab in tabs"
                        :key="tab.id"
                        class="feature-tabs-showcase__accordion-item"
                        :class="{ 'is-active': currentTab?.id === tab.id }"
                    >
                        <button
                            type="button"
                            class="feature-tabs-showcase__accordion-trigger"
                            :aria-expanded="currentTab?.id === tab.id && (!isWorkflowStyle || featureTabChildren(tab).length > 0)"
                            @click="setActiveTab(tab)"
                        >
                            <div class="feature-tabs-showcase__accordion-trigger-copy">
                                <span class="feature-tabs-showcase__accordion-label">
                                    {{ isWorkflowStyle ? (tab.label || tab.title) : tab.label }}
                                </span>
                                <span v-if="!isWorkflowStyle" class="feature-tabs-showcase__accordion-heading">
                                    {{ tab.title || tab.label }}
                                </span>
                            </div>
                            <ChevronDown
                                class="feature-tabs-showcase__accordion-icon"
                                :class="{ 'is-open': currentTab?.id === tab.id }"
                                aria-hidden="true"
                            />
                        </button>

                        <div
                            v-if="currentTab?.id === tab.id && (!isWorkflowStyle || featureTabChildren(tab).length > 0)"
                            class="feature-tabs-showcase__accordion-content"
                        >
                            <div
                                v-if="tab.body && !isWorkflowStyle"
                                class="feature-tabs-showcase__accordion-body"
                                v-html="tab.body"
                            ></div>

                            <div
                                v-if="featureTabChildren(tab).length"
                                class="feature-tabs-showcase__accordion-children"
                            >
                                <button
                                    v-for="child in featureTabChildren(tab)"
                                    :key="child.id"
                                    type="button"
                                    class="feature-tabs-showcase__accordion-child"
                                    :class="{ 'is-active': currentPanel?.id === child.id }"
                                    @click="setAccordionChild(tab, child)"
                                >
                                    <span class="feature-tabs-showcase__accordion-child-arrow" aria-hidden="true">-&gt;</span>
                                    <span class="feature-tabs-showcase__accordion-child-label">{{ child.label }}</span>
                                </button>
                            </div>

                            <ul v-else-if="tab.items?.length && !isWorkflowStyle" class="feature-tabs-showcase__accordion-points">
                                <li
                                    v-for="(item, itemIndex) in tab.items"
                                    :key="`${tab.id}-accordion-item-${itemIndex}`"
                                    class="feature-tabs-showcase__accordion-point"
                                >
                                    {{ item }}
                                </li>
                            </ul>

                            <div v-if="tab.metric && !isWorkflowStyle" class="feature-tabs-showcase__accordion-metric">
                                {{ tab.metric }}
                            </div>
                        </div>
                    </article>
                </div>

                <article
                    v-if="currentPanel"
                    class="feature-tabs-showcase__panel feature-tabs-showcase__panel--detail"
                    :class="{ 'feature-tabs-showcase__panel--workflow': isWorkflowStyle }"
                >
                    <div
                        class="feature-tabs-showcase__media-shell"
                        :class="{ 'feature-tabs-showcase__media-shell--filled': Boolean(currentPanelImageUrl) }"
                    >
                        <div class="feature-tabs-showcase__media-glow"></div>
                        <img
                            v-if="currentPanelImageUrl"
                            :src="currentPanelImageUrl"
                            :alt="currentPanelImageAlt"
                            class="feature-tabs-showcase__media"
                            loading="lazy"
                            decoding="async"
                        >
                        <div v-else class="feature-tabs-showcase__media-fallback">
                            <div class="feature-tabs-showcase__media-mark">
                                {{ previewTab?.label }}
                            </div>
                            <p v-if="currentMetric" class="feature-tabs-showcase__media-caption">
                                {{ currentMetric }}
                            </p>
                        </div>
                        <div v-if="currentMetric && !isWorkflowStyle" class="feature-tabs-showcase__metric">
                            {{ currentMetric }}
                        </div>
                    </div>

                    <div class="feature-tabs-showcase__copy" :class="{ 'feature-tabs-showcase__copy--workflow': isWorkflowStyle }">
                        <div class="feature-tabs-showcase__copy-top">
                            <div v-if="isWorkflowStyle && currentMetric" class="feature-tabs-showcase__copy-kicker">
                                {{ currentMetric }}
                            </div>
                            <h3 v-if="currentPanel.title || currentPanel.label" class="feature-tabs-showcase__copy-title">
                                {{ currentPanel.title || currentPanel.label }}
                            </h3>
                            <div
                                v-if="currentPanel.body"
                                class="feature-tabs-showcase__copy-body"
                                v-html="currentPanel.body"
                            ></div>

                            <component
                                :is="shouldUseAnchor(currentPanel.cta_href) ? 'a' : Link"
                                v-if="currentPanel.cta_label"
                                :href="resolveHref(currentPanel.cta_href)"
                                :target="isExternalHref(resolveHref(currentPanel.cta_href)) ? '_blank' : undefined"
                                :rel="isExternalHref(resolveHref(currentPanel.cta_href)) ? 'noopener noreferrer' : undefined"
                                class="feature-tabs-showcase__cta"
                            >
                                <span>{{ currentPanel.cta_label }}</span>
                                <ArrowRight class="h-4 w-4" aria-hidden="true" />
                            </component>
                        </div>

                        <div class="feature-tabs-showcase__details">
                            <div
                                v-if="currentChildren.length > 1 && !isWorkflowStyle"
                                class="feature-tabs-showcase__subtabs"
                                role="tablist"
                                :aria-label="previewTab?.label || 'Feature tab details'"
                            >
                                <button
                                    v-for="child in currentChildren"
                                    :key="child.id"
                                    type="button"
                                    class="feature-tabs-showcase__subtab"
                                    :class="{ 'is-active': currentPanel?.id === child.id }"
                                    :aria-selected="currentPanel?.id === child.id"
                                    @click="setActiveChild(child)"
                                >
                                    {{ child.label }}
                                </button>
                            </div>

                            <ul v-else-if="previewTab?.items?.length" class="feature-tabs-showcase__points">
                                <li
                                    v-for="(item, itemIndex) in previewTab.items"
                                    :key="`${previewTab.id}-item-${itemIndex}`"
                                    class="feature-tabs-showcase__point"
                                >
                                    {{ item }}
                                </li>
                            </ul>

                            <div
                                v-if="currentMetric || currentStory || currentPerson || currentRole || currentAvatarUrl"
                                class="feature-tabs-showcase__story"
                            >
                                <div v-if="currentMetric" class="feature-tabs-showcase__story-metric">
                                    {{ currentMetric }}
                                </div>
                                <div
                                    v-if="currentStory"
                                    class="feature-tabs-showcase__story-quote"
                                    v-html="currentStory"
                                ></div>
                                <div v-if="currentPerson || currentRole || currentAvatarUrl" class="feature-tabs-showcase__story-person">
                                    <img
                                        v-if="currentAvatarUrl"
                                        :src="currentAvatarUrl"
                                        :alt="currentAvatarAlt"
                                        class="feature-tabs-showcase__story-avatar"
                                        loading="lazy"
                                        decoding="async"
                                    >
                                    <div
                                        v-else
                                        class="feature-tabs-showcase__story-avatar feature-tabs-showcase__story-avatar--empty"
                                        aria-hidden="true"
                                    >
                                        {{ currentPersonInitials }}
                                    </div>
                                    <div>
                                        <div v-if="currentPerson" class="feature-tabs-showcase__story-name">
                                            {{ currentPerson }}
                                        </div>
                                        <div v-if="currentRole" class="feature-tabs-showcase__story-role">
                                            {{ currentRole }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </div>
</template>

<style scoped>
.feature-tabs-showcase {
    --feature-tabs-showcase-overlay-opacity: 0.45;
    --feature-tabs-showcase-overlay-dot: rgba(8, 58, 92, 0.06);
    --feature-tabs-showcase-overlay-dot-alt: rgba(8, 58, 92, 0.04);
    position: relative;
    overflow: hidden;
    padding-block: clamp(4rem, 8vw, 6.75rem);
    background:
        radial-gradient(circle at top left, rgba(15, 118, 110, 0.08), rgba(15, 118, 110, 0) 28%),
        radial-gradient(circle at bottom right, rgba(8, 58, 92, 0.08), rgba(8, 58, 92, 0) 30%),
        linear-gradient(180deg, #f8f3ea 0%, #f6efe5 100%);
}

.feature-tabs-showcase::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image:
        radial-gradient(var(--feature-tabs-showcase-overlay-dot) 0.8px, transparent 0.8px),
        radial-gradient(var(--feature-tabs-showcase-overlay-dot-alt) 0.8px, transparent 0.8px);
    background-position: 0 0, 12px 12px;
    background-size: 24px 24px;
    opacity: var(--feature-tabs-showcase-overlay-opacity);
    pointer-events: none;
}

.feature-tabs-showcase--contrast {
    --feature-tabs-showcase-overlay-opacity: 0.28;
    --feature-tabs-showcase-overlay-dot: rgba(248, 250, 252, 0.16);
    --feature-tabs-showcase-overlay-dot-alt: rgba(248, 250, 252, 0.1);
}

.feature-tabs-showcase__container {
    position: relative;
    z-index: 1;
    width: min(var(--public-shell-width, 88rem), 100%);
    margin: 0 auto;
    padding-inline: var(--public-shell-gutter, 1.25rem);
}

.feature-tabs-showcase__header {
    max-width: 52rem;
    margin-inline: auto;
    text-align: center;
}

.feature-tabs-showcase__eyebrow {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.45rem 0.9rem;
    border-radius: 0.125rem;
    background: rgba(8, 58, 92, 0.08);
    color: #083a5c;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.feature-tabs-showcase__title {
    margin-top: 1.2rem;
    color: #083a5c;
    font-family: var(--page-font-heading, var(--front-font-heading));
    font-size: clamp(2.2rem, 1.95rem + 1.2vw, 3.85rem);
    line-height: 1.02;
    letter-spacing: -0.04em;
}

.feature-tabs-showcase__subtitle {
    margin: 1rem auto 0;
    max-width: 42rem;
    color: #29485c;
    font-size: 1rem;
    line-height: 1.7;
}

.feature-tabs-showcase__subtitle :deep(p),
.feature-tabs-showcase__subtitle :deep(div) {
    margin: 0 0 1rem;
}

.feature-tabs-showcase__subtitle :deep(p:last-child),
.feature-tabs-showcase__subtitle :deep(div:last-child) {
    margin-bottom: 0;
}

.feature-tabs-showcase__header-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.85rem 1.2rem;
    margin-top: 1.35rem;
}

.feature-tabs-showcase__header-link {
    color: #083a5c;
    font-size: 0.95rem;
    font-weight: 700;
    text-decoration: none;
}

.feature-tabs-showcase__header-link::after {
    content: '->';
    margin-left: 0.35rem;
    font-size: 0.85em;
}

.feature-tabs-showcase__header-link--muted {
    color: #496173;
}

.feature-tabs-showcase__accordion-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 1.4rem;
    margin-top: 2.2rem;
    align-items: start;
}

.feature-tabs-showcase__accordion {
    display: grid;
    gap: 0.85rem;
}

.feature-tabs-showcase__accordion-item {
    overflow: hidden;
    border: 1px solid rgba(8, 58, 92, 0.12);
    border-radius: var(--page-radius, 4px);
    background: rgba(255, 255, 255, 0.78);
    box-shadow: 0 18px 34px -28px rgba(15, 23, 42, 0.36);
}

.feature-tabs-showcase__accordion-item.is-active {
    border-color: rgba(8, 58, 92, 0.28);
    background: #fffdf8;
}

.feature-tabs-showcase__accordion-trigger {
    display: flex;
    width: 100%;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.05rem;
    border: 0;
    background: transparent;
    text-align: left;
}

.feature-tabs-showcase__accordion-trigger-copy {
    display: grid;
    gap: 0.4rem;
    min-width: 0;
}

.feature-tabs-showcase__accordion-label {
    color: #496173;
    font-size: 0.76rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.feature-tabs-showcase__accordion-heading {
    color: #083a5c;
    font-family: var(--page-font-heading, var(--front-font-heading));
    font-size: clamp(1.05rem, 0.98rem + 0.2vw, 1.28rem);
    line-height: 1.14;
    letter-spacing: -0.02em;
}

.feature-tabs-showcase__accordion-icon {
    flex-shrink: 0;
    width: 1rem;
    height: 1rem;
    margin-top: 0.25rem;
    color: #083a5c;
    transition: transform 0.2s ease;
}

.feature-tabs-showcase__accordion-icon.is-open {
    transform: rotate(180deg);
}

.feature-tabs-showcase__accordion-content {
    display: grid;
    gap: 0.85rem;
    padding: 0 1.05rem 1.05rem;
}

.feature-tabs-showcase__accordion-body {
    color: #29485c;
    font-size: 0.94rem;
    line-height: 1.65;
}

.feature-tabs-showcase__accordion-body :deep(p),
.feature-tabs-showcase__accordion-body :deep(div) {
    margin: 0 0 0.85rem;
}

.feature-tabs-showcase__accordion-body :deep(p:last-child),
.feature-tabs-showcase__accordion-body :deep(div:last-child) {
    margin-bottom: 0;
}

.feature-tabs-showcase__accordion-children {
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
}

.feature-tabs-showcase__accordion-child {
    display: inline-flex;
    align-items: center;
    padding: 0.58rem 0.82rem;
    border: 1px solid rgba(8, 58, 92, 0.12);
    border-radius: var(--page-radius, 4px);
    background: rgba(255, 255, 255, 0.82);
    color: #0f3550;
    font-size: 0.84rem;
    font-weight: 700;
    line-height: 1.2;
    text-align: left;
    transition: border-color 0.2s ease, background 0.2s ease, transform 0.2s ease;
}

.feature-tabs-showcase__accordion-child:hover,
.feature-tabs-showcase__accordion-child:focus-visible {
    transform: translateY(-1px);
    border-color: rgba(8, 58, 92, 0.24);
}

.feature-tabs-showcase__accordion-child.is-active {
    border-color: rgba(8, 58, 92, 0.26);
    background: rgba(132, 204, 22, 0.18);
    color: #072b41;
}

.feature-tabs-showcase__accordion-child-arrow {
    display: none;
    flex-shrink: 0;
}

.feature-tabs-showcase__accordion-child-label {
    min-width: 0;
}

.feature-tabs-showcase__accordion-points {
    display: grid;
    gap: 0.65rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.feature-tabs-showcase__accordion-point {
    position: relative;
    padding-left: 1rem;
    color: #17394c;
    font-size: 0.94rem;
    line-height: 1.55;
}

.feature-tabs-showcase__accordion-point::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0.56rem;
    width: 0.42rem;
    height: 0.42rem;
    border-radius: 999px;
    background: #84cc16;
}

.feature-tabs-showcase__accordion-metric {
    color: #083a5c;
    font-size: 0.88rem;
    font-weight: 800;
    line-height: 1.45;
}

.feature-tabs-showcase__tabs {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.85rem;
    margin-top: 2rem;
}

.feature-tabs-showcase__tab {
    border: 1px solid rgba(8, 58, 92, 0.12);
    border-radius: 0.125rem;
    padding: 0.8rem 1.2rem;
    background: rgba(255, 255, 255, 0.72);
    color: #083a5c;
    font-size: var(--feature-tabs-showcase-tab-size, 16px);
    font-weight: 700;
    line-height: 1;
    box-shadow: 0 14px 28px -24px rgba(15, 23, 42, 0.38);
    transition: transform 0.2s ease, border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
}

.feature-tabs-showcase__tab:hover,
.feature-tabs-showcase__tab:focus-visible {
    transform: translateY(-1px);
    border-color: rgba(8, 58, 92, 0.36);
    box-shadow: 0 16px 30px -24px rgba(15, 23, 42, 0.48);
}

.feature-tabs-showcase__tab.is-active {
    border-color: #083a5c;
    background: #fffdf8;
}

.feature-tabs-showcase__panel {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 0;
    margin-top: 2.2rem;
    border: 1px solid rgba(8, 58, 92, 0.08);
    background: rgba(255, 255, 255, 0.58);
    box-shadow: 0 34px 60px -42px rgba(15, 23, 42, 0.35);
    overflow: hidden;
}

.feature-tabs-showcase__panel--detail {
    margin-top: 0;
}

.feature-tabs-showcase__media-shell {
    position: relative;
    min-height: 21rem;
    background:
        radial-gradient(circle at top left, rgba(255, 255, 255, 0.85), rgba(255, 255, 255, 0) 28%),
        linear-gradient(135deg, #e7edf2 0%, #d8e2ea 100%);
}

.feature-tabs-showcase__media-shell--filled {
    background: linear-gradient(135deg, #dfe8ef 0%, #cfdbe5 100%);
}

.feature-tabs-showcase__media-glow {
    position: absolute;
    inset: auto auto 1.8rem 1.8rem;
    width: 10rem;
    height: 10rem;
    border-radius: 999px;
    background: rgba(132, 204, 22, 0.14);
    filter: blur(6px);
}

.feature-tabs-showcase__media {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center;
}

.feature-tabs-showcase__media-fallback {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    gap: 0.75rem;
    padding: 2rem;
    background:
        radial-gradient(circle at top left, rgba(255, 255, 255, 0.7), rgba(255, 255, 255, 0) 35%),
        linear-gradient(135deg, rgba(216, 226, 234, 0.95), rgba(191, 219, 254, 0.82));
}

.feature-tabs-showcase__media-mark {
    color: #083a5c;
    font-family: var(--page-font-heading, var(--front-font-heading));
    font-size: clamp(2.2rem, 1.95rem + 0.8vw, 3rem);
    line-height: 0.95;
    letter-spacing: -0.04em;
}

.feature-tabs-showcase__media-caption {
    max-width: 16rem;
    color: #365468;
    font-size: 0.95rem;
    line-height: 1.55;
}

.feature-tabs-showcase__metric {
    position: absolute;
    left: 1.4rem;
    right: 1.4rem;
    bottom: 1.4rem;
    padding: 0.95rem 1.05rem;
    border: 1px solid rgba(8, 58, 92, 0.12);
    background: rgba(255, 255, 255, 0.92);
    color: #083a5c;
    font-size: 0.92rem;
    font-weight: 700;
    box-shadow: 0 18px 36px -28px rgba(15, 23, 42, 0.4);
}

.feature-tabs-showcase__copy {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 1.75rem;
    padding: clamp(1.75rem, 4vw, 2.5rem);
    background: rgba(255, 251, 245, 0.72);
}

.feature-tabs-showcase__copy-title {
    color: #083a5c;
    font-family: var(--page-font-heading, var(--front-font-heading));
    font-size: clamp(1.8rem, 1.55rem + 0.6vw, 2.45rem);
    line-height: 1.05;
    letter-spacing: -0.03em;
}

.feature-tabs-showcase__copy-kicker {
    margin-bottom: 0.75rem;
    color: #083a5c;
    font-size: 0.82rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.feature-tabs-showcase__copy-body {
    margin-top: 1rem;
    color: #274457;
    font-size: 1rem;
    line-height: 1.7;
}

.feature-tabs-showcase__copy-body :deep(p),
.feature-tabs-showcase__copy-body :deep(div) {
    margin: 0 0 1rem;
}

.feature-tabs-showcase__copy-body :deep(p:last-child),
.feature-tabs-showcase__copy-body :deep(div:last-child) {
    margin-bottom: 0;
}

.feature-tabs-showcase__cta {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    margin-top: 1.35rem;
    padding: 0.85rem 1.15rem;
    border-radius: 0.125rem;
    background: #083a5c;
    color: #ffffff;
    font-size: 0.94rem;
    font-weight: 700;
    text-decoration: none;
    width: fit-content;
    transition: background 0.2s ease, transform 0.2s ease;
}

.feature-tabs-showcase__cta:hover {
    background: #062c45;
    transform: translateY(-1px);
}

.feature-tabs-showcase__details {
    display: grid;
    gap: 1rem;
}

.feature-tabs-showcase__subtabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
}

.feature-tabs-showcase__subtab {
    display: inline-flex;
    align-items: center;
    padding: 0.6rem 0.85rem;
    border: 1px solid rgba(8, 58, 92, 0.12);
    border-radius: 0.125rem;
    background: rgba(255, 255, 255, 0.74);
    color: #0f3550;
    font-size: 0.88rem;
    font-weight: 700;
    line-height: 1.25;
    transition: border-color 0.2s ease, background 0.2s ease, transform 0.2s ease;
}

.feature-tabs-showcase__subtab:hover,
.feature-tabs-showcase__subtab:focus-visible {
    transform: translateY(-1px);
    border-color: rgba(8, 58, 92, 0.24);
}

.feature-tabs-showcase__subtab.is-active {
    border-color: rgba(8, 58, 92, 0.26);
    background: rgba(132, 204, 22, 0.18);
    color: #072b41;
}

.feature-tabs-showcase__points {
    display: grid;
    gap: 0.65rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.feature-tabs-showcase__point {
    position: relative;
    padding-left: 1rem;
    color: #17394c;
    font-size: 0.96rem;
    line-height: 1.55;
}

.feature-tabs-showcase__point::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0.58rem;
    width: 0.42rem;
    height: 0.42rem;
    border-radius: 999px;
    background: #84cc16;
}

.feature-tabs-showcase__story {
    padding-top: 1.5rem;
    border-top: 1px solid rgba(8, 58, 92, 0.12);
}

.feature-tabs-showcase__story-metric {
    color: #083a5c;
    font-size: 1.12rem;
    font-weight: 800;
    line-height: 1.4;
}

.feature-tabs-showcase__story-quote {
    margin-top: 0.9rem;
    color: #19384b;
    font-size: 1rem;
    line-height: 1.75;
}

.feature-tabs-showcase__story-quote :deep(p),
.feature-tabs-showcase__story-quote :deep(div) {
    margin: 0 0 1rem;
}

.feature-tabs-showcase__story-quote :deep(p:last-child),
.feature-tabs-showcase__story-quote :deep(div:last-child) {
    margin-bottom: 0;
}

.feature-tabs-showcase__story-person {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    margin-top: 1.1rem;
}

.feature-tabs-showcase__story-avatar {
    width: 3rem;
    height: 3rem;
    border-radius: 999px;
    object-fit: cover;
    background: #dbeafe;
}

.feature-tabs-showcase__story-avatar--empty {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #083a5c;
    font-size: 0.88rem;
    font-weight: 800;
}

.feature-tabs-showcase__story-name {
    color: #082c45;
    font-size: 1rem;
    font-weight: 800;
}

.feature-tabs-showcase__story-role {
    margin-top: 0.1rem;
    color: #496173;
    font-size: 0.92rem;
}

.feature-tabs-showcase--contrast .feature-tabs-showcase__eyebrow {
    background: rgba(248, 250, 252, 0.12);
    color: #f8fafc;
}

.feature-tabs-showcase--contrast .feature-tabs-showcase__title,
.feature-tabs-showcase--contrast .feature-tabs-showcase__header-link {
    color: #f8fafc;
}

.feature-tabs-showcase--contrast .feature-tabs-showcase__subtitle,
.feature-tabs-showcase--contrast .feature-tabs-showcase__header-link--muted {
    color: rgba(226, 232, 240, 0.82);
}

.feature-tabs-showcase--contrast.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-child:hover,
.feature-tabs-showcase--contrast.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-child:focus-visible {
    background: rgba(248, 250, 252, 0.08);
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__header {
    max-width: 46rem;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__header-link {
    font-size: 0.92rem;
    text-decoration: underline;
    text-decoration-thickness: 0.12em;
    text-underline-offset: 0.18em;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__header-link::after {
    content: none;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-grid {
    gap: 1.6rem;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion {
    gap: 0.7rem;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-item {
    overflow: visible;
    border: 0;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-trigger {
    align-items: center;
    padding: 1rem 1.15rem;
    border-radius: var(--page-radius, 4px);
    background: #083a5c;
    color: #f8fafc;
    box-shadow: 0 18px 34px -28px rgba(8, 58, 92, 0.56);
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-item.is-active .feature-tabs-showcase__accordion-trigger {
    background: #062f4b;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-label,
.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-heading,
.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-icon {
    color: #f8fafc;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-trigger-copy {
    display: flex;
    align-items: center;
    min-height: 1.5rem;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-label {
    font-size: clamp(1.12rem, 1rem + 0.25vw, 1.38rem);
    font-weight: 800;
    letter-spacing: -0.02em;
    text-transform: none;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-icon {
    width: 1.1rem;
    height: 1.1rem;
    margin-top: 0;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-heading {
    display: none;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-content {
    gap: 0.8rem;
    padding: 0.8rem 0.4rem 0.15rem;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-body {
    padding-left: 0.25rem;
    font-size: 0.9rem;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-children {
    display: grid;
    gap: 0.2rem;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-child {
    width: 100%;
    justify-content: flex-start;
    gap: 0.7rem;
    padding: 0.7rem 0.75rem;
    border: 0;
    border-radius: var(--page-radius, 4px);
    background: transparent;
    box-shadow: none;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-child:hover,
.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-child:focus-visible {
    transform: none;
    background: rgba(8, 58, 92, 0.06);
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-child.is-active {
    background: rgba(132, 204, 22, 0.28);
    color: #072b41;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-child-arrow {
    display: inline-flex;
    color: #083a5c;
    font-size: 0.9rem;
    font-weight: 800;
    line-height: 1;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-point {
    padding-left: 1.2rem;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__panel {
    grid-template-columns: minmax(0, 1fr);
    gap: 1.2rem;
    border: 0;
    background: transparent;
    box-shadow: none;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__media-shell {
    min-height: 22rem;
    overflow: hidden;
    border: 1px solid rgba(8, 58, 92, 0.08);
    border-radius: var(--page-radius, 4px);
    box-shadow: 0 26px 48px -34px rgba(15, 23, 42, 0.34);
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__media-glow {
    display: none;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__copy {
    gap: 1rem;
    padding: 0;
    background: transparent;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__copy-title {
    font-size: clamp(1.7rem, 1.45rem + 0.5vw, 2.15rem);
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__copy-body {
    margin-top: 0.7rem;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__cta {
    margin-top: 1rem;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__details {
    gap: 0.8rem;
}

.feature-tabs-showcase--workflow .feature-tabs-showcase__story {
    padding-top: 0.9rem;
}

@media (min-width: 1024px) {
    .feature-tabs-showcase__accordion-grid {
        grid-template-columns: minmax(18rem, 0.78fr) minmax(0, 1.12fr);
        gap: 1.5rem;
    }

    .feature-tabs-showcase__accordion-grid--collapsed {
        grid-template-columns: minmax(18rem, 0.78fr) minmax(0, 1.12fr);
    }

    .feature-tabs-showcase__panel {
        grid-template-columns: minmax(0, 0.94fr) minmax(0, 1fr);
    }

    .feature-tabs-showcase__media-shell {
        min-height: 28rem;
    }

    .feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-grid {
        grid-template-columns: minmax(18rem, 0.72fr) minmax(0, 1fr);
        gap: 2rem;
    }

    .feature-tabs-showcase--workflow .feature-tabs-showcase__accordion-grid--collapsed {
        grid-template-columns: minmax(18rem, 0.72fr) minmax(0, 1fr);
    }

    .feature-tabs-showcase--workflow .feature-tabs-showcase__panel {
        align-content: start;
    }

    .feature-tabs-showcase--workflow .feature-tabs-showcase__media-shell {
        min-height: 26rem;
    }
}
</style>
