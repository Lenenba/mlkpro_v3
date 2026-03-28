<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import FeatureTabsShowcaseSection from '@/Components/Public/FeatureTabsShowcaseSection.vue';
import PublicFooterMenu from '@/Components/Public/PublicFooterMenu.vue';
import PublicSectionsRenderer from '@/Components/Public/PublicSectionsRenderer.vue';
import PublicSiteHeader from '@/Components/Public/PublicSiteHeader.vue';
import { Head, Link } from '@inertiajs/vue3';
import { buildBackgroundStyle } from '@/utils/backgroundPresets';
import { useI18n } from 'vue-i18n';
import { defaultFeatureTabsShowcaseSection } from '@/utils/featureTabs';

const props = defineProps({
    canLogin: {
        type: Boolean,
        default: true,
    },
    canRegister: {
        type: Boolean,
        default: true,
    },
    leadFormUrl: {
        type: String,
        default: null,
    },
    welcomeContent: {
        type: Object,
        default: () => ({}),
    },
    pageTheme: {
        type: Object,
        default: () => ({}),
    },
    megaMenu: {
        type: Object,
        default: () => ({}),
    },
    footerMenu: {
        type: Object,
        default: () => ({}),
    },
    footerSection: {
        type: Object,
        default: () => ({}),
    },
});

const { t, locale } = useI18n();
const welcomeContent = computed(() => props.welcomeContent || {});
const normalizedLocale = computed(() => (
    String(locale.value || 'fr').toLowerCase().startsWith('fr') ? 'fr' : 'en'
));
const welcomeShowcaseSection = computed(() => {
    const fallback = defaultFeatureTabsShowcaseSection(normalizedLocale.value);
    const custom = welcomeContent.value.home_service_showcase;

    if (!custom || typeof custom !== 'object') {
        return fallback;
    }

    return {
        ...fallback,
        ...custom,
        feature_tabs: Array.isArray(custom.feature_tabs) && custom.feature_tabs.length
            ? custom.feature_tabs
            : fallback.feature_tabs,
    };
});

const isHrefAllowed = (href) => {
    const key = String(href || '').trim();
    if (!key) return false;
    if (key === 'login') return props.canLogin;
    if (key === 'onboarding.index') return props.canRegister;
    return true;
};

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

const sectionStyle = (section) => buildBackgroundStyle(section || {});

const navMenuItems = computed(() =>
    (welcomeContent.value.nav?.menu || []).filter((item) => item && item.enabled !== false && isHrefAllowed(item.href))
);

const contactNavItem = computed(() => {
    if (!props.leadFormUrl) {
        return null;
    }
    return {
        id: 'contact',
        label: t('welcome.nav.contact'),
        href: props.leadFormUrl,
        style: 'outline',
    };
});

const navMenuWithContact = computed(() => {
    const items = [...navMenuItems.value];
    const contact = contactNavItem.value;
    if (!contact) {
        return items;
    }
    const alreadyExists = items.some((item) => {
        if (!item) {
            return false;
        }
        if (item.id === contact.id) {
            return true;
        }
        return resolveHref(item.href) === contact.href;
    });
    if (alreadyExists) {
        return items;
    }
    return [contact, ...items];
});

const headerMenuItems = computed(() =>
    navMenuWithContact.value.map((item) => ({
        label: item.label || item.href,
        resolved_href: resolveHref(item.href),
        link_target: isExternalHref(resolveHref(item.href)) ? '_blank' : '_self',
        panel_type: 'link',
    }))
);

const genericSections = computed(() =>
    (welcomeContent.value.generic_sections || []).filter((section) => section && section.enabled !== false)
);

const heroSlides = computed(() => {
    const items = Array.isArray(welcomeContent.value.hero?.hero_images)
        ? welcomeContent.value.hero.hero_images
        : [];

    const slides = items
        .map((item, index) => {
            const src = String(typeof item === 'string' ? item : item?.image_url || '').trim();
            if (!src) {
                return null;
            }

            return {
                id: String((typeof item === 'object' && item?.id) || `hero-slide-${index}`),
                src,
                alt: String(typeof item === 'object' ? item?.image_alt || '' : '').trim() || t('welcome.images.hero_alt'),
            };
        })
        .filter(Boolean);

    if (slides.length) {
        return slides;
    }

    const fallbackSrc = String(welcomeContent.value.hero?.image_url || '').trim();
    if (!fallbackSrc) {
        return [];
    }

    return [{
        id: 'hero-slide-fallback',
        src: fallbackSrc,
        alt: String(welcomeContent.value.hero?.image_alt || '').trim() || t('welcome.images.hero_alt'),
    }];
});
const heroSlideIndex = ref(0);
let heroSlideInterval = null;
const HERO_SLIDE_INTERVAL_MS = 5000;

const clearHeroSlideInterval = () => {
    if (heroSlideInterval && typeof window !== 'undefined') {
        window.clearInterval(heroSlideInterval);
        heroSlideInterval = null;
    }
};

const startHeroSlideInterval = () => {
    clearHeroSlideInterval();
    if (typeof window === 'undefined' || heroSlides.value.length <= 1) {
        return;
    }

    heroSlideInterval = window.setInterval(() => {
        heroSlideIndex.value = (heroSlideIndex.value + 1) % heroSlides.value.length;
    }, HERO_SLIDE_INTERVAL_MS);
};

watch(heroSlides, (slides) => {
    heroSlideIndex.value = 0;
    if (!slides.length) {
        clearHeroSlideInterval();
        return;
    }

    startHeroSlideInterval();
}, { deep: true });

onMounted(() => {
    startHeroSlideInterval();
});

onBeforeUnmount(() => {
    clearHeroSlideInterval();
});

const heroSideImage = computed(() => (
    heroSlides.value[heroSlideIndex.value] || null
));
const heroHasVisual = computed(() => Boolean(heroSideImage.value));

const heroStatCards = computed(() =>
    Array.isArray(welcomeContent.value.hero?.stats)
        ? welcomeContent.value.hero.stats
            .map((stat, index) => ({
                id: stat?.id || `stat-${index}`,
                value: String(stat?.value || '').trim(),
                label: String(stat?.label || '').trim(),
            }))
            .filter((stat) => stat.value || stat.label)
            .slice(0, 3)
        : []
);

const normalizedHeroTitleSize = computed(() => {
    const raw = Number(welcomeContent.value.hero?.title_font_size || 0);
    if (!Number.isFinite(raw) || raw <= 0) {
        return 0;
    }

    const maxSize = heroHasVisual.value ? 72 : 96;
    return Math.max(40, Math.min(Math.round(raw), maxSize));
});

const heroTitleStyle = computed(() => {
    const style = {};
    const color = String(welcomeContent.value.hero?.title_color || '').trim();
    if (color) {
        style.color = color;
    }

    if (normalizedHeroTitleSize.value > 0) {
        const maxSize = normalizedHeroTitleSize.value;
        const minSize = Math.max(32, maxSize - 18);
        style.fontSize = `clamp(${minSize}px, 5vw, ${maxSize}px)`;
        style.lineHeight = maxSize >= 72 ? '0.95' : '1';
    } else if (heroHasVisual.value) {
        style.fontSize = 'clamp(2.35rem, 3.6vw, 3.9rem)';
        style.lineHeight = '0.92';
    }

    return style;
});

const heroBodyStyle = computed(() => {
    const style = {};
    const color = String(welcomeContent.value.hero?.body_color || '').trim();
    if (color) {
        style.color = color;
    }

    return style;
});
</script>

<template>
    <Head :title="$t('welcome.meta.title')" />

    <div class="welcome-page front-public-page text-stone-900 dark:text-neutral-100">
        <PublicSiteHeader
            :mega-menu="megaMenu"
            :fallback-items="headerMenuItems"
            :can-login="canLogin"
            :can-register="canRegister"
        />

        <main>
            <section v-if="welcomeContent.hero?.enabled !== false" class="welcome-section welcome-hero"
                :style="sectionStyle(welcomeContent.hero)">
                <div class="welcome-hero-shell" :class="{ 'welcome-hero-shell--with-visual': heroSideImage }">
                    <div class="space-y-4 welcome-hero-copy welcome-hero-copy--left">
                        <div class="welcome-kicker welcome-hero-kicker-intro">
                            {{ welcomeContent.hero?.eyebrow || $t('welcome.hero.eyebrow') }}
                        </div>
                        <div class="welcome-hero-title-wrap">
                            <span class="welcome-hero-title-accent" aria-hidden="true"></span>
                            <h1
                                class="welcome-title welcome-hero-headline welcome-hero-title-intro text-4xl font-semibold tracking-tight sm:text-5xl"
                                :style="heroTitleStyle"
                            >
                                {{ welcomeContent.hero?.title || $t('welcome.hero.title') }}
                            </h1>
                        </div>
                        <div
                            class="welcome-rich welcome-hero-body-intro text-sm sm:text-base"
                            :style="[{ animationDelay: '0.3s' }, heroBodyStyle]"
                            v-html="welcomeContent.hero?.subtitle || $t('welcome.hero.subtitle')"></div>

                        <div class="welcome-hero-actions welcome-fade-up" style="animation-delay: 0.3s;">
                            <template v-if="welcomeContent.hero?.primary_cta || $t('welcome.hero.primary_cta')">
                                <a
                                    v-if="isExternalHref(resolveHref(welcomeContent.hero?.primary_href || 'onboarding.index'))"
                                    :href="resolveHref(welcomeContent.hero?.primary_href || 'onboarding.index')"
                                    class="welcome-hero-button welcome-hero-button--primary"
                                    rel="noopener noreferrer"
                                    target="_blank"
                                >
                                    {{ welcomeContent.hero?.primary_cta || $t('welcome.hero.primary_cta') }}
                                </a>
                                <Link
                                    v-else
                                    :href="resolveHref(welcomeContent.hero?.primary_href || 'onboarding.index')"
                                    class="welcome-hero-button welcome-hero-button--primary"
                                >
                                    {{ welcomeContent.hero?.primary_cta || $t('welcome.hero.primary_cta') }}
                                </Link>
                            </template>

                            <template v-if="welcomeContent.hero?.secondary_cta || $t('welcome.hero.secondary_cta')">
                                <a
                                    v-if="isExternalHref(resolveHref(welcomeContent.hero?.secondary_href || 'login'))"
                                    :href="resolveHref(welcomeContent.hero?.secondary_href || 'login')"
                                    class="welcome-hero-button welcome-hero-button--secondary"
                                    rel="noopener noreferrer"
                                    target="_blank"
                                >
                                    {{ welcomeContent.hero?.secondary_cta || $t('welcome.hero.secondary_cta') }}
                                </a>
                                <Link
                                    v-else
                                    :href="resolveHref(welcomeContent.hero?.secondary_href || 'login')"
                                    class="welcome-hero-button welcome-hero-button--secondary"
                                >
                                    {{ welcomeContent.hero?.secondary_cta || $t('welcome.hero.secondary_cta') }}
                                </Link>
                            </template>
                        </div>

                        <div
                            v-if="welcomeContent.hero?.note || $t('welcome.hero.note')"
                            class="welcome-rich welcome-hero-note welcome-hero-body-intro"
                            style="animation-delay: 0.42s;"
                            v-html="welcomeContent.hero?.note || $t('welcome.hero.note')"
                        ></div>

                        <div
                            v-if="heroStatCards.length"
                            class="welcome-hero-metrics welcome-fade-up"
                            style="animation-delay: 0.5s;"
                        >
                            <div
                                v-for="stat in heroStatCards"
                                :key="stat.id"
                                class="welcome-hero-metric"
                            >
                                <div class="welcome-hero-metric__value">{{ stat.value }}</div>
                                <div class="welcome-hero-metric__label">{{ stat.label }}</div>
                            </div>
                        </div>

                    </div>

                    <div v-if="heroSideImage" class="welcome-hero-visual welcome-fade-in">
                        <Transition name="welcome-hero-slide">
                            <img
                                :key="heroSideImage.id"
                                :src="heroSideImage.src"
                                :alt="heroSideImage.alt"
                                class="welcome-hero-visual-image"
                                loading="eager"
                                fetchpriority="high"
                                decoding="async"
                            />
                        </Transition>
                    </div>
                </div>
            </section>

            <section v-if="welcomeContent.trust?.enabled !== false" class="welcome-section welcome-trust"
                :style="sectionStyle(welcomeContent.trust)">
                <div class="welcome-container">
                    <div class="flex flex-col gap-3 text-center">
                        <div class="text-sm font-semibold text-stone-700">
                            {{ welcomeContent.trust?.title || $t('welcome.trust.title') }}
                        </div>
                        <div class="grid grid-cols-2 gap-3 text-xs text-stone-600 sm:grid-cols-3 lg:grid-cols-6">
                            <div v-for="(item, trustIndex) in (welcomeContent.trust?.items || [])" :key="trustIndex" class="welcome-pill">
                                {{ item }}
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <FeatureTabsShowcaseSection v-if="welcomeShowcaseSection?.enabled !== false" :section="welcomeShowcaseSection" />

            <section v-if="welcomeContent.features?.enabled !== false" class="welcome-section welcome-features"
                :style="sectionStyle(welcomeContent.features)">
                <div class="welcome-container">
                    <div class="flex flex-col gap-2 text-center">
                        <div class="text-xs uppercase tracking-wide text-emerald-200">
                            {{ welcomeContent.features?.kicker || $t('welcome.features.kicker') }}
                        </div>
                        <h2 class="welcome-title text-3xl font-semibold">
                            {{ welcomeContent.features?.title || $t('welcome.features.title') }}
                        </h2>
                        <div class="welcome-rich welcome-rich--inverse text-sm text-emerald-100"
                            v-html="welcomeContent.features?.subtitle || $t('welcome.features.subtitle')"></div>
                    </div>

                    <div class="mt-10 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="(item, featureIndex) in (welcomeContent.features?.items || [])"
                            :key="item.key || featureIndex"
                            class="welcome-feature-card"
                        >
                            <div class="welcome-feature-title">{{ item.title }}</div>
                            <div class="welcome-feature-desc welcome-rich welcome-rich--inverse" v-html="item.desc"></div>
                        </div>
                    </div>

                    <div v-if="welcomeContent.features?.new_features?.enabled !== false" class="mt-12"
                        :style="sectionStyle(welcomeContent.features?.new_features)">
                        <div class="flex flex-col gap-2 text-center">
                            <div class="text-xs uppercase tracking-wide text-emerald-200">
                                {{ welcomeContent.features?.new_features?.kicker || $t('welcome.new_features.kicker') }}
                            </div>
                            <h3 class="welcome-title text-2xl font-semibold">
                                {{ welcomeContent.features?.new_features?.title || $t('welcome.new_features.title') }}
                            </h3>
                            <div class="welcome-rich welcome-rich--inverse text-sm text-emerald-100"
                                v-html="welcomeContent.features?.new_features?.subtitle || $t('welcome.new_features.subtitle')"></div>
                        </div>

                        <div class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                            <div
                                v-for="(item, newFeatureIndex) in (welcomeContent.features?.new_features?.items || [])"
                                :key="item.key || newFeatureIndex"
                                class="welcome-feature-card welcome-feature-card--new"
                            >
                                <div class="welcome-feature-badge">
                                    {{ welcomeContent.features?.new_features?.badge || $t('welcome.new_features.badge') }}
                                </div>
                                <div class="welcome-feature-title">{{ item.title }}</div>
                                <div class="welcome-feature-desc welcome-rich welcome-rich--inverse" v-html="item.desc"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="welcomeContent.workflow?.enabled !== false" class="welcome-section welcome-workflow"
                :style="sectionStyle(welcomeContent.workflow)">
                <div class="welcome-container">
                    <div class="grid grid-cols-1 lg:grid-cols-2 lg:items-center welcome-split">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-stone-500">
                                {{ welcomeContent.workflow?.kicker || $t('welcome.workflow.kicker') }}
                            </div>
                            <h2 class="welcome-title mt-2 text-3xl font-semibold">
                                {{ welcomeContent.workflow?.title || $t('welcome.workflow.title') }}
                            </h2>
                            <div class="welcome-rich mt-3 text-sm text-stone-600"
                                v-html="welcomeContent.workflow?.subtitle || $t('welcome.workflow.subtitle')"></div>

                            <ol class="mt-6 space-y-3 text-sm text-stone-700">
                                <li v-for="(step, stepIndex) in (welcomeContent.workflow?.steps || [])" :key="step.id || stepIndex" class="welcome-step">
                                    <div class="welcome-step-title">{{ step.title }}</div>
                                    <div class="welcome-rich" v-html="step.desc"></div>
                                </li>
                            </ol>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-white pl-4 p-4 shadow-lg">
                            <img
                                :src="welcomeContent.workflow?.image_url || '/images/landing/workflow-board.svg'"
                                :alt="welcomeContent.workflow?.image_alt || $t('welcome.images.workflow_alt')"
                                class="h-auto w-full rounded-sm"
                                loading="lazy"
                                decoding="async"
                            />
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="welcomeContent.field?.enabled !== false" class="welcome-section welcome-field"
                :style="sectionStyle(welcomeContent.field)">
                <div class="welcome-container">
                    <div class="grid grid-cols-1 lg:grid-cols-2 lg:items-center welcome-split">
                        <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-lg">
                            <img
                                :src="welcomeContent.field?.image_url || '/images/landing/mobile-field.svg'"
                                :alt="welcomeContent.field?.image_alt || $t('welcome.images.mobile_alt')"
                                class="h-auto w-full rounded-sm"
                                loading="lazy"
                                decoding="async"
                            />
                        </div>

                        <div>
                            <div class="text-xs uppercase tracking-wide text-stone-500">
                                {{ welcomeContent.field?.kicker || $t('welcome.field.kicker') }}
                            </div>
                            <h2 class="welcome-title mt-2 text-3xl font-semibold">
                                {{ welcomeContent.field?.title || $t('welcome.field.title') }}
                            </h2>
                            <div class="welcome-rich mt-3 text-sm text-stone-600"
                                v-html="welcomeContent.field?.subtitle || $t('welcome.field.subtitle')"></div>

                            <ul class="mt-6 space-y-3 text-sm text-stone-700">
                                <li v-for="(item, fieldIndex) in (welcomeContent.field?.items || [])" :key="fieldIndex" class="welcome-bullet">
                                    {{ item }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>

            <PublicSectionsRenderer
                v-if="genericSections.length"
                :content="{ sections: genericSections, theme: pageTheme }"
            />

            <section v-if="welcomeContent.cta?.enabled !== false" class="welcome-section welcome-cta"
                :style="sectionStyle(welcomeContent.cta)">
                <div class="welcome-container">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="welcome-title text-3xl font-semibold text-white">
                                {{ welcomeContent.cta?.title || $t('welcome.cta.title') }}
                            </h2>
                            <div class="welcome-rich welcome-rich--inverse mt-2 text-sm text-emerald-50"
                                v-html="welcomeContent.cta?.subtitle || $t('welcome.cta.subtitle')"></div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <template v-if="welcomeContent.cta?.primary && isHrefAllowed(welcomeContent.cta?.primary_href)">
                                <a
                                    v-if="isExternalHref(resolveHref(welcomeContent.cta?.primary_href))"
                                    :href="resolveHref(welcomeContent.cta?.primary_href)"
                                    class="rounded-sm bg-white px-4 py-2 text-sm font-semibold text-stone-900 hover:bg-stone-100"
                                    rel="noopener noreferrer"
                                    target="_blank"
                                >
                                    {{ welcomeContent.cta?.primary }}
                                </a>
                                <Link
                                    v-else
                                    :href="resolveHref(welcomeContent.cta?.primary_href)"
                                    class="rounded-sm bg-white px-4 py-2 text-sm font-semibold text-stone-900 hover:bg-stone-100"
                                >
                                    {{ welcomeContent.cta?.primary }}
                                </Link>
                            </template>
                            <template v-if="welcomeContent.cta?.secondary && isHrefAllowed(welcomeContent.cta?.secondary_href)">
                                <a
                                    v-if="isExternalHref(resolveHref(welcomeContent.cta?.secondary_href))"
                                    :href="resolveHref(welcomeContent.cta?.secondary_href)"
                                    class="rounded-sm border border-white/40 px-4 py-2 text-sm font-semibold text-white hover:bg-white/10"
                                    rel="noopener noreferrer"
                                    target="_blank"
                                >
                                    {{ welcomeContent.cta?.secondary }}
                                </a>
                                <Link
                                    v-else
                                    :href="resolveHref(welcomeContent.cta?.secondary_href)"
                                    class="rounded-sm border border-white/40 px-4 py-2 text-sm font-semibold text-white hover:bg-white/10"
                                >
                                    {{ welcomeContent.cta?.secondary }}
                                </Link>
                            </template>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <PublicFooterMenu :menu="footerMenu" :section="footerSection" :copy="welcomeContent.footer?.copy || ''" />
    </div>
</template>

<style scoped>
.welcome-page {
    --public-shell-width: 88rem;
    --public-shell-gutter: 1.25rem;
    --public-site-header-height: 5.75rem;
    --welcome-ink: #0f172a;
    --welcome-muted: #475569;
    --welcome-accent: #16a34a;
    font-family: var(--page-font-body);
    background: #ffffff;
}

.welcome-title {
    font-family: var(--page-font-heading);
    letter-spacing: -0.03em;
}

.welcome-container {
    width: min(var(--public-shell-width), 100%);
    margin: 0 auto;
    padding-left: var(--public-shell-gutter);
    padding-right: var(--public-shell-gutter);
}

.welcome-section {
    width: 100%;
    padding-block: var(--section-pad, clamp(3.25rem, 6vw, 7rem));
}

.welcome-split {
    column-gap: clamp(2rem, 6vw, 5rem);
    row-gap: clamp(2.5rem, 6vw, 4rem);
}

.welcome-hero {
    background:
        radial-gradient(circle at top center, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0) 24%),
        linear-gradient(135deg, #0d1d35 0%, #0d3137 48%, #0d5a46 100%);
    color: #ecfdf5;
    --section-pad: 0;
}

.welcome-hero-shell {
    width: min(var(--public-shell-width), 100%);
    margin: 0 auto;
    padding-left: var(--public-shell-gutter);
    padding-right: var(--public-shell-gutter);
    display: grid;
    gap: clamp(1.5rem, 4vw, 2.75rem);
    align-items: center;
}

.welcome-hero-shell--with-visual {
    width: 100%;
    max-width: none;
    padding-left: 0;
    padding-right: 0;
    gap: 0;
    grid-template-columns: minmax(0, 1.02fr) minmax(0, 0.98fr);
    min-height: clamp(28rem, calc(100svh - var(--public-site-header-height, 5.75rem) - 0.75rem), 34rem);
    align-items: stretch;
}

.welcome-hero-copy {
    padding-top: 0;
    padding-bottom: 0;
}

.welcome-hero-copy--left {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: center;
    text-align: left;
    min-width: 0;
    max-width: 42rem;
    background:
        radial-gradient(circle at top center, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0) 24%),
        linear-gradient(135deg, #0d1d35 0%, #0d3137 48%, #0d5a46 100%);
    color: #ecfdf5;
}

.welcome-hero-shell--with-visual .welcome-hero-copy--left {
    width: 100%;
    max-width: none;
    min-height: 100%;
    padding-top: clamp(2rem, 3vw, 3rem);
    padding-bottom: clamp(2rem, 3vw, 3rem);
    padding-right: clamp(1.5rem, 2.4vw, 2.5rem);
    padding-left: max(var(--public-shell-gutter), calc((100vw - var(--public-shell-width)) / 2 + var(--public-shell-gutter)));
}

.welcome-hero-title-wrap {
    position: relative;
    display: inline-block;
    max-width: 34rem;
    padding-top: 0.15rem;
    padding-bottom: 0.9rem;
    isolation: isolate;
}

.welcome-hero-title-accent {
    position: absolute;
    left: 0;
    bottom: 0.35rem;
    width: clamp(4.5rem, 7vw, 7.5rem);
    height: 0.28rem;
    border-radius: 999px;
    background: linear-gradient(90deg, rgba(110, 231, 183, 0.95), rgba(45, 212, 191, 0.5));
    box-shadow: none;
    z-index: 0;
    transform-origin: left center;
    animation: welcomeHeroAccentIn 0.72s cubic-bezier(0.22, 1, 0.36, 1) both;
    animation-delay: 0.22s;
}

.welcome-hero-headline {
    position: relative;
    z-index: 1;
    text-wrap: balance;
}

.welcome-hero-title-intro {
    opacity: 0;
    transform: translateY(22px);
    clip-path: inset(0 0 100% 0);
    animation: welcomeHeroTitleIn 0.95s cubic-bezier(0.22, 1, 0.36, 1) both;
    animation-delay: 0.12s;
    will-change: opacity, transform, clip-path;
}

.welcome-trust {
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    border-top: 1px solid #e2e8f0;
    border-bottom: 1px solid #e2e8f0;
    --section-pad: clamp(2.5rem, 4.5vw, 5rem);
}

.welcome-features {
    background: linear-gradient(135deg, #0f172a 0%, #064e3b 100%);
    color: #ecfdf3;
    --section-pad: clamp(4rem, 8vw, 8.5rem);
}

.welcome-workflow {
    background: radial-gradient(circle at top right, #e2e8f0 0%, #f8fafc 45%, #ffffff 100%);
    --section-pad: clamp(4rem, 8vw, 8.5rem);
}

.welcome-field {
    background: linear-gradient(180deg, #ffffff 0%, #fef9f4 60%, #ffffff 100%);
    --section-pad: clamp(4rem, 8vw, 8.5rem);
}

.welcome-cta {
    background: linear-gradient(120deg, #0f172a 0%, #0f766e 100%);
    --section-pad: clamp(3.5rem, 7vw, 7.5rem);
}

.welcome-kicker {
    display: inline-block;
    padding: 0;
    color: #a7f3d0;
    font-size: 0.95rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.welcome-hero .welcome-kicker {
    background: transparent;
    border: 0;
    color: #a7f3d0;
}

.welcome-hero-kicker-intro {
    opacity: 0;
    transform: translateX(-16px);
    animation: welcomeHeroKickerIn 0.7s cubic-bezier(0.22, 1, 0.36, 1) both;
    animation-delay: 0.02s;
}

.welcome-hero .welcome-title {
    color: #f8fafc;
    max-width: 11ch;
    line-height: 0.92;
}

.welcome-hero-copy--left .welcome-rich {
    max-width: 31rem;
    color: rgba(236, 253, 245, 0.84);
    line-height: 1.55;
}

.welcome-hero-body-intro {
    opacity: 0;
    transform: translateY(16px);
    filter: blur(10px);
    animation: welcomeHeroBodyIn 0.8s cubic-bezier(0.22, 1, 0.36, 1) both;
    will-change: opacity, transform, filter;
}

.welcome-hero-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.7rem;
}

.welcome-hero-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 3rem;
    padding: 0.72rem 1.2rem;
    border-radius: 0.125rem;
    font-size: 0.9rem;
    font-weight: 700;
    line-height: 1;
    text-decoration: none;
    transition: transform 0.18s ease, background-color 0.18s ease, color 0.18s ease;
}

.welcome-hero-button:hover {
    transform: translateY(-1px);
}

.welcome-hero-button--primary {
    background: #1f2937;
    color: #ffffff;
    box-shadow: 0 18px 30px -24px rgba(15, 23, 42, 0.42);
}

.welcome-hero-button--secondary {
    background: rgba(255, 255, 255, 0.08);
    color: #f8fafc;
    border: 1px solid rgba(255, 255, 255, 0.18);
}

.welcome-hero-note {
    max-width: 31rem;
    font-size: 0.82rem;
    line-height: 1.5;
    color: rgba(236, 253, 245, 0.72);
}

.welcome-hero-metrics {
    display: grid;
    grid-template-columns: repeat(1, minmax(0, 1fr));
    gap: 0.65rem;
    width: min(100%, 31rem);
}

.welcome-hero-metric {
    min-width: 0;
    min-height: 4.15rem;
    border-radius: 0.125rem;
    border: 1px solid rgba(148, 163, 184, 0.26);
    background: rgba(255, 255, 255, 0.08);
    box-shadow: 0 24px 46px -40px rgba(0, 0, 0, 0.45);
    backdrop-filter: blur(10px);
}

.welcome-hero-metric {
    padding: 1rem 1rem 0.95rem;
    text-align: center;
}

.welcome-hero-metric__value {
    color: #f8fafc;
    font-size: 1.05rem;
    font-weight: 700;
    line-height: 1;
}

.welcome-hero-metric__label {
    margin-top: 0.42rem;
    color: rgba(236, 253, 245, 0.72);
    font-size: 0.7rem;
    line-height: 1.35;
}

.welcome-hero-visual {
    position: relative;
    display: block;
    align-self: stretch;
    min-width: 0;
    min-height: 100%;
    overflow: hidden;
    background: #dbe4f0;
    isolation: isolate;
}

.welcome-hero-visual::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 1;
    pointer-events: none;
    background:
        linear-gradient(180deg, rgba(9, 16, 24, 0.08) 0%, rgba(9, 16, 24, 0.02) 36%, rgba(9, 16, 24, 0.12) 100%);
}

.welcome-hero-visual::after {
    content: '';
    position: absolute;
    inset: -8% -12%;
    z-index: 1;
    pointer-events: none;
    background: radial-gradient(circle at 22% 24%, rgba(255, 255, 255, 0.18) 0%, rgba(255, 255, 255, 0) 42%);
    opacity: 0.75;
}

.welcome-hero-visual-image {
    display: block;
    position: absolute;
    inset: 0;
    z-index: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center center;
    transform: scale(1.015);
    filter: saturate(0.98) contrast(1.02);
    will-change: opacity, transform, filter;
}

.welcome-hero-slide-enter-active,
.welcome-hero-slide-leave-active {
    transition:
        opacity 1.25s cubic-bezier(0.22, 1, 0.36, 1),
        transform 1.8s cubic-bezier(0.22, 1, 0.36, 1),
        filter 1.25s cubic-bezier(0.22, 1, 0.36, 1);
}

.welcome-hero-slide-enter-from {
    opacity: 0;
    transform: scale(1.04);
    filter: saturate(0.94) contrast(1) brightness(1.02);
}

.welcome-hero-slide-leave-to {
    opacity: 0;
    transform: scale(1.01);
    filter: saturate(1.02) contrast(1.03) brightness(0.98);
}

.welcome-hero-slide-enter-active {
    z-index: 0;
}

.welcome-hero-slide-leave-active {
    z-index: 0;
}

.welcome-pill {
    border: 1px solid #e2e8f0;
    border-radius: 0.125rem;
    padding: 0.4rem 0.8rem;
    background: #f8fafc;
}

.welcome-feature-card {
    border-radius: 0.125rem;
    padding: 1.25rem;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.16);
    backdrop-filter: blur(8px);
}

.welcome-feature-card--new {
    border-color: rgba(16, 185, 129, 0.4);
    background: rgba(15, 23, 42, 0.35);
}

.welcome-feature-badge {
    display: inline-flex;
    align-items: center;
    align-self: flex-start;
    padding: 0.2rem 0.6rem;
    border-radius: 0.125rem;
    background: rgba(16, 185, 129, 0.2);
    color: #a7f3d0;
    font-size: 0.65rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin-bottom: 0.5rem;
}

.welcome-feature-title {
    font-size: 0.95rem;
    font-weight: 600;
    color: #f8fafc;
}

.welcome-feature-desc {
    margin-top: 0.5rem;
    color: #d1fae5;
    font-size: 0.85rem;
}

.welcome-step {
    border-radius: 0.125rem;
    padding: 0.75rem;
    background: #ffffff;
    border: 1px solid #e2e8f0;
}

.welcome-step-title {
    font-weight: 600;
    color: #0f172a;
}

@media (min-width: 1024px) {
    .welcome-hero-metrics {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

}

@media (min-width: 640px) and (max-width: 1023px) {
    .welcome-hero-metrics {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 1023px) {
    .welcome-hero-shell--with-visual {
        width: min(var(--public-shell-width), 100%);
        padding-left: var(--public-shell-gutter);
        padding-right: var(--public-shell-gutter);
        gap: clamp(1.5rem, 4vw, 2.75rem);
        grid-template-columns: minmax(0, 1fr);
        min-height: auto;
    }

    .welcome-hero-shell--with-visual .welcome-hero-copy--left {
        padding: 0;
    }

    .welcome-hero-copy--left {
        padding: clamp(2.5rem, 6vw, 3.5rem);
    }

    .welcome-hero-visual {
        width: 100%;
        min-height: clamp(18rem, 60vw, 24rem);
    }

    .welcome-hero-visual-image {
        width: 100%;
        height: 100%;
    }

    .welcome-hero-title-accent {
        bottom: 0.8rem;
        width: clamp(4rem, 12vw, 5.5rem);
        height: 0.24rem;
    }

    .welcome-hero .welcome-title {
        max-width: 11.5ch;
    }
}

.welcome-rich :deep(p),
.welcome-rich :deep(div) {
    margin: 0.35rem 0;
}

.welcome-rich :deep(ul),
.welcome-rich :deep(ol) {
    margin: 0.4rem 0;
    padding-left: 1.1rem;
}

.welcome-rich :deep(li) {
    margin: 0.2rem 0;
}

.welcome-rich :deep(a) {
    text-decoration: underline;
    font-weight: 600;
}

.welcome-rich :deep(img) {
    max-width: 100%;
    border-radius: 0.75rem;
    margin: 0.5rem 0;
}

.welcome-rich--inverse :deep(a) {
    color: #a7f3d0;
}

.welcome-bullet {
    position: relative;
    padding-left: 1.25rem;
}

.welcome-bullet::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0.5rem;
    width: 0.45rem;
    height: 0.45rem;
    border-radius: 999px;
    background: #16a34a;
}

.welcome-fade-up {
    animation: welcomeFadeUp 0.8s ease-out both;
}

.welcome-fade-in {
    animation: welcomeFadeIn 0.9s ease-out both;
}

@keyframes welcomeFadeUp {
    from {
        opacity: 0;
        transform: translateY(18px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes welcomeHeroKickerIn {
    from {
        opacity: 0;
        transform: translateX(-16px);
        letter-spacing: 0.12em;
    }
    to {
        opacity: 1;
        transform: translateX(0);
        letter-spacing: 0.04em;
    }
}

@keyframes welcomeHeroAccentIn {
    from {
        opacity: 0;
        transform: scaleX(0);
    }
    to {
        opacity: 1;
        transform: scaleX(1);
    }
}

@keyframes welcomeHeroTitleIn {
    from {
        opacity: 0;
        transform: translateY(22px);
        clip-path: inset(0 0 100% 0);
    }
    to {
        opacity: 1;
        transform: translateY(0);
        clip-path: inset(0 0 0 0);
    }
}

@keyframes welcomeHeroBodyIn {
    from {
        opacity: 0;
        transform: translateY(16px);
        filter: blur(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
        filter: blur(0);
    }
}

@keyframes welcomeFadeIn {
    from {
        opacity: 0;
        transform: scale(0.98);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

@media (prefers-reduced-motion: reduce) {
    .welcome-fade-up,
    .welcome-fade-in,
    .welcome-hero-kicker-intro,
    .welcome-hero-title-intro,
    .welcome-hero-body-intro,
    .welcome-hero-title-accent,
    .welcome-hero-slide-enter-active,
    .welcome-hero-slide-leave-active {
        animation: none;
        transition: none;
    }

    .welcome-hero-kicker-intro,
    .welcome-hero-title-intro,
    .welcome-hero-body-intro {
        opacity: 1;
        transform: none;
        filter: none;
        clip-path: none;
    }
}

</style>
