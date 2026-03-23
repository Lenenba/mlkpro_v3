<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import FeatureTabsShowcaseSection from '@/Components/Public/FeatureTabsShowcaseSection.vue';
import PublicFooterMenu from '@/Components/Public/PublicFooterMenu.vue';
import PublicSiteHeader from '@/Components/Public/PublicSiteHeader.vue';
import { Head, Link } from '@inertiajs/vue3';
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

const sectionStyle = (color) => {
    const value = String(color || '').trim();
    return value ? { background: value } : {};
};

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

const customSections = computed(() =>
    (welcomeContent.value.custom_sections || []).filter((section) => section && section.enabled !== false)
);

const heroLayoutRef = ref(null);
const heroVisibleHeight = ref(null);
let heroLayoutObserver = null;

const syncHeroVisibleHeight = () => {
    if (typeof window !== 'undefined' && !window.matchMedia('(min-width: 1024px)').matches) {
        heroVisibleHeight.value = null;
        return;
    }

    if (!heroLayoutRef.value) {
        return;
    }

    const nextHeight = Math.max(Math.round(heroLayoutRef.value.getBoundingClientRect().height), 0);
    heroVisibleHeight.value = nextHeight ? `${nextHeight}px` : null;
};

const normalizeHeroSlides = (value) => {
    if (!Array.isArray(value)) {
        return [];
    }

    return value
        .map((item) => {
            const src = String(item?.image_url || '').trim();
            if (!src) {
                return null;
            }

            return {
                src,
                alt: String(item?.image_alt || '').trim(),
            };
        })
        .filter(Boolean);
};

const defaultHeroSlides = computed(() => {
    const baseImage = welcomeContent.value.hero?.image_url || '/images/landing/hero-dashboard.svg';
    const baseAlt = welcomeContent.value.hero?.image_alt || t('welcome.images.hero_alt');

    if (normalizedLocale.value === 'fr') {
        return [
            { src: baseImage, alt: baseAlt },
            { src: '/images/mega-menu/operations-suite.svg', alt: 'Suite operations terrain' },
            { src: '/images/mega-menu/sales-crm-suite.svg', alt: 'Suite ventes et CRM' },
            { src: '/images/mega-menu/reservations-suite.svg', alt: 'Suite reservations' },
            { src: '/images/mega-menu/ai-automation-suite.svg', alt: 'Suite IA et automatisation' },
            { src: '/images/mega-menu/commerce-suite.svg', alt: 'Suite commerce' },
            { src: '/images/mega-menu/marketing-loyalty-suite.svg', alt: 'Suite marketing et fidelisation' },
            { src: '/images/mega-menu/platform-command-center.svg', alt: 'Centre de commandement plateforme' },
        ];
    }

    return [
        { src: baseImage, alt: baseAlt },
        { src: '/images/mega-menu/operations-suite.svg', alt: 'Field operations suite' },
        { src: '/images/mega-menu/sales-crm-suite.svg', alt: 'Sales and CRM suite' },
        { src: '/images/mega-menu/reservations-suite.svg', alt: 'Reservations suite' },
        { src: '/images/mega-menu/ai-automation-suite.svg', alt: 'AI and automation suite' },
        { src: '/images/mega-menu/commerce-suite.svg', alt: 'Commerce suite' },
        { src: '/images/mega-menu/marketing-loyalty-suite.svg', alt: 'Marketing and loyalty suite' },
        { src: '/images/mega-menu/platform-command-center.svg', alt: 'Platform command center' },
    ];
});

const heroSlides = computed(() => {
    const configuredSlides = normalizeHeroSlides(welcomeContent.value.hero?.hero_images);

    return configuredSlides.length ? configuredSlides : defaultHeroSlides.value;
});

const heroSlidesLoop = computed(() => [...heroSlides.value, ...heroSlides.value]);

const heroSliderStyle = computed(() => ({
    '--welcome-slide-count': String(heroSlides.value.length),
    '--welcome-slider-height': heroVisibleHeight.value || 'clamp(24rem, 38vw, 34rem)',
}));

const normalizedHeroTitleSize = computed(() => {
    const raw = Number(welcomeContent.value.hero?.title_font_size || 0);
    if (!Number.isFinite(raw) || raw <= 0) {
        return 0;
    }

    return Math.max(40, Math.min(Math.round(raw), 96));
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

onMounted(() => {
    syncHeroVisibleHeight();

    if (typeof ResizeObserver !== 'undefined' && heroLayoutRef.value) {
        heroLayoutObserver = new ResizeObserver(() => {
            syncHeroVisibleHeight();
        });
        heroLayoutObserver.observe(heroLayoutRef.value);
    }

    window.addEventListener('resize', syncHeroVisibleHeight);
});

onBeforeUnmount(() => {
    if (heroLayoutObserver) {
        heroLayoutObserver.disconnect();
        heroLayoutObserver = null;
    }

    window.removeEventListener('resize', syncHeroVisibleHeight);
});
</script>

<template>
    <Head :title="$t('welcome.meta.title')" />

    <div class="welcome-page text-stone-900 dark:text-neutral-100">
        <PublicSiteHeader
            :mega-menu="megaMenu"
            :fallback-items="headerMenuItems"
            :can-login="canLogin"
            :can-register="canRegister"
        />

        <main>
            <section v-if="welcomeContent.hero?.enabled !== false" class="welcome-section welcome-hero"
                :style="sectionStyle(welcomeContent.hero?.background_color)">
                <div class="welcome-container">
                    <div class="grid grid-cols-1 items-center lg:grid-cols-2 lg:items-stretch welcome-split welcome-hero-layout">
                        <div ref="heroLayoutRef" class="space-y-6 welcome-hero-copy">
                            <div class="welcome-kicker welcome-fade-up">
                                {{ welcomeContent.hero?.eyebrow || $t('welcome.hero.eyebrow') }}
                            </div>
                            <h1
                                class="welcome-title text-4xl font-semibold tracking-tight sm:text-5xl welcome-fade-up"
                                :style="[{ animationDelay: '0.1s' }, heroTitleStyle]"
                            >
                                {{ welcomeContent.hero?.title || $t('welcome.hero.title') }}
                            </h1>
                            <div
                                class="welcome-rich text-base text-stone-600 sm:text-lg welcome-fade-up"
                                :style="[{ animationDelay: '0.2s' }, heroBodyStyle]"
                                v-html="welcomeContent.hero?.subtitle || $t('welcome.hero.subtitle')"></div>

                            <div class="flex flex-wrap gap-3 welcome-fade-up" style="animation-delay: 0.3s;">
                                <template v-if="welcomeContent.hero?.primary_cta && isHrefAllowed(welcomeContent.hero?.primary_href)">
                                    <a
                                        v-if="isExternalHref(resolveHref(welcomeContent.hero?.primary_href))"
                                        :href="resolveHref(welcomeContent.hero?.primary_href)"
                                        class="rounded-sm border border-transparent bg-green-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-green-700"
                                        rel="noopener noreferrer"
                                        target="_blank"
                                    >
                                        {{ welcomeContent.hero?.primary_cta }}
                                    </a>
                                    <Link
                                        v-else
                                        :href="resolveHref(welcomeContent.hero?.primary_href)"
                                        class="rounded-sm border border-transparent bg-green-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-green-700"
                                    >
                                        {{ welcomeContent.hero?.primary_cta }}
                                    </Link>
                                </template>
                                <template v-if="welcomeContent.hero?.secondary_cta && isHrefAllowed(welcomeContent.hero?.secondary_href)">
                                    <a
                                        v-if="isExternalHref(resolveHref(welcomeContent.hero?.secondary_href))"
                                        :href="resolveHref(welcomeContent.hero?.secondary_href)"
                                        class="rounded-sm border border-stone-300 bg-white px-5 py-2.5 text-sm font-semibold text-stone-900 shadow-sm hover:bg-stone-100"
                                        rel="noopener noreferrer"
                                        target="_blank"
                                    >
                                        {{ welcomeContent.hero?.secondary_cta }}
                                    </a>
                                    <Link
                                        v-else
                                        :href="resolveHref(welcomeContent.hero?.secondary_href)"
                                        class="rounded-sm border border-stone-200 bg-white px-5 py-2.5 text-sm font-semibold text-stone-800 hover:bg-stone-50"
                                    >
                                        {{ welcomeContent.hero?.secondary_cta }}
                                    </Link>
                                </template>
                            </div>

                            <div class="grid gap-3 text-sm text-stone-700 sm:grid-cols-3 welcome-fade-up" style="animation-delay: 0.4s;">
                                <div
                                    v-for="(stat, statIndex) in (welcomeContent.hero?.stats || [])"
                                    :key="stat.id || statIndex"
                                    class="rounded-sm border border-stone-200 bg-white/80 px-3 py-3"
                                >
                                    <div class="text-lg font-semibold text-stone-900">{{ stat.value }}</div>
                                    <div class="text-xs text-stone-500">{{ stat.label }}</div>
                                </div>
                            </div>

                            <div
                                class="grid gap-2 text-sm text-stone-600 welcome-fade-up"
                                :style="[{ animationDelay: '0.5s' }, heroBodyStyle]"
                            >
                                <div v-for="(item, highlightIndex) in (welcomeContent.hero?.highlights || [])" :key="highlightIndex" class="flex items-start gap-2">
                                    <span class="mt-1 size-1.5 rounded-full bg-green-600"></span>
                                    <span>{{ item }}</span>
                                </div>
                            </div>

                            <div
                                class="welcome-rich text-xs text-stone-500 welcome-fade-up"
                                :style="[{ animationDelay: '0.6s' }, heroBodyStyle]"
                                v-html="welcomeContent.hero?.note || $t('welcome.hero.note')"></div>
                        </div>

                        <div class="relative welcome-fade-in welcome-hero-visual">
                            <div class="welcome-hero-slider" :style="heroSliderStyle">
                                <div class="welcome-hero-track">
                                    <article
                                        v-for="(slide, slideIndex) in heroSlidesLoop"
                                        :key="`${slide.src}-${slideIndex}`"
                                        class="welcome-hero-slide"
                                    >
                                        <div class="welcome-hero-slide-frame">
                                            <img
                                                :src="slide.src"
                                                :alt="slide.alt"
                                                class="welcome-hero-slide-image"
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>
                                    </article>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="welcomeContent.trust?.enabled !== false" class="welcome-section welcome-trust"
                :style="sectionStyle(welcomeContent.trust?.background_color)">
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
                :style="sectionStyle(welcomeContent.features?.background_color)">
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
                        :style="sectionStyle(welcomeContent.features?.new_features?.background_color)">
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
                :style="sectionStyle(welcomeContent.workflow?.background_color)">
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
                :style="sectionStyle(welcomeContent.field?.background_color)">
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

            <section v-if="customSections.length" class="welcome-section welcome-custom">
                <div class="welcome-container space-y-10">
                    <article v-for="(section, customIndex) in customSections" :key="section.id || customIndex"
                        class="welcome-custom-card" :style="sectionStyle(section.background_color)">
                        <div class="grid grid-cols-1 gap-8 lg:grid-cols-2 lg:items-center">
                            <div class="space-y-4">
                                <div v-if="section.kicker" class="welcome-kicker">{{ section.kicker }}</div>
                                <h2 class="welcome-title text-3xl font-semibold">{{ section.title }}</h2>
                                <div v-if="section.body" class="welcome-rich text-sm text-stone-600" v-html="section.body"></div>

                                <div class="flex flex-wrap gap-2">
                                    <template v-if="section.primary_label && isHrefAllowed(section.primary_href)">
                                        <a
                                            v-if="isExternalHref(resolveHref(section.primary_href))"
                                            :href="resolveHref(section.primary_href)"
                                            class="rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700"
                                            rel="noopener noreferrer"
                                            target="_blank"
                                        >
                                            {{ section.primary_label }}
                                        </a>
                                        <Link
                                            v-else
                                            :href="resolveHref(section.primary_href)"
                                            class="rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700"
                                        >
                                            {{ section.primary_label }}
                                        </Link>
                                    </template>
                                    <template v-if="section.secondary_label && isHrefAllowed(section.secondary_href)">
                                        <a
                                            v-if="isExternalHref(resolveHref(section.secondary_href))"
                                            :href="resolveHref(section.secondary_href)"
                                            class="rounded-sm border border-stone-200 bg-white px-4 py-2 text-sm font-semibold text-stone-800 hover:bg-stone-50"
                                            rel="noopener noreferrer"
                                            target="_blank"
                                        >
                                            {{ section.secondary_label }}
                                        </a>
                                        <Link
                                            v-else
                                            :href="resolveHref(section.secondary_href)"
                                            class="rounded-sm border border-stone-200 bg-white px-4 py-2 text-sm font-semibold text-stone-800 hover:bg-stone-50"
                                        >
                                            {{ section.secondary_label }}
                                        </Link>
                                    </template>
                                </div>
                            </div>

                            <div v-if="section.image_url" class="welcome-custom-media">
                                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-lg">
                                    <img
                                        :src="section.image_url"
                                        :alt="section.image_alt || section.title"
                                        class="h-auto w-full rounded-sm"
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section v-if="welcomeContent.cta?.enabled !== false" class="welcome-section welcome-cta"
                :style="sectionStyle(welcomeContent.cta?.background_color)">
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
@import url('https://fonts.bunny.net/css?family=Montserrat:400,500,600,700,800&display=swap');

.welcome-page {
    --public-shell-width: 88rem;
    --public-shell-gutter: 1.25rem;
    --welcome-ink: #0f172a;
    --welcome-muted: #475569;
    --welcome-accent: #16a34a;
    --page-font-body: 'Montserrat', 'Figtree', sans-serif;
    --page-font-heading: 'Montserrat', 'Figtree', sans-serif;
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

.welcome-hero-layout {
    column-gap: clamp(1.75rem, 4vw, 3.25rem);
}

.welcome-hero {
    background: linear-gradient(180deg, #f8fafc 0%, #ffffff 55%, #ecfdf5 100%);
    padding-top: 0;
    padding-bottom: 0;
}

.welcome-hero-copy {
    padding-top: clamp(1.5rem, 2.5vw, 2.35rem);
    padding-bottom: clamp(2.75rem, 5vw, 4.5rem);
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

.welcome-custom {
    background: linear-gradient(180deg, #ffffff 0%, #ecfdf5 100%);
    --section-pad: clamp(3.5rem, 7vw, 7.5rem);
}

.welcome-cta {
    background: linear-gradient(120deg, #0f172a 0%, #0f766e 100%);
    --section-pad: clamp(3.5rem, 7vw, 7.5rem);
}

.welcome-kicker {
    display: inline-flex;
    align-items: center;
    padding: 0.35rem 0.75rem;
    border-radius: 0.125rem;
    background: rgba(16, 185, 129, 0.12);
    color: #065f46;
    font-size: 0.75rem;
    font-weight: 600;
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

.welcome-custom-card {
    border: 1px solid #e2e8f0;
    border-radius: 0.125rem;
    padding: clamp(1.5rem, 4vw, 2.75rem);
    background: #ffffff;
    box-shadow: 0 24px 45px -38px rgba(15, 23, 42, 0.35);
}

.welcome-custom-media {
    display: flex;
    justify-content: center;
}

.welcome-hero-visual {
    position: relative;
    display: flex;
    align-self: stretch;
    width: 100%;
    height: 100%;
    min-width: 0;
    max-width: none;
}

.welcome-hero-slider {
    --welcome-slider-height: clamp(24rem, 38vw, 34rem);
    --welcome-slide-height: clamp(11.5rem, 17vw, 14rem);
    --welcome-slide-gap: 1.15rem;
    width: 100%;
    flex: 1 1 auto;
    height: var(--welcome-slider-height);
    overflow: hidden;
}

.welcome-hero-track {
    display: flex;
    flex-direction: column;
    gap: var(--welcome-slide-gap);
    transform: translateY(calc(-1 * (var(--welcome-slide-height) + var(--welcome-slide-gap)) * var(--welcome-slide-count)));
    animation: welcomeHeroVerticalSlider 34s linear infinite;
    will-change: transform;
}

.welcome-hero-slider:hover .welcome-hero-track {
    animation-play-state: paused;
}

.welcome-hero-slide {
    height: var(--welcome-slide-height);
}

.welcome-hero-slide-frame {
    display: flex;
    align-items: stretch;
    justify-content: stretch;
    height: 100%;
    padding: 0;
    overflow: hidden;
    border-radius: 0.125rem;
    background: #f8fafc;
    box-shadow: 0 18px 40px -34px rgba(15, 23, 42, 0.18);
}

.welcome-hero-slide-image {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center;
}

@media (min-width: 1024px) {
    .welcome-hero-slider {
        --welcome-slide-height: calc((var(--welcome-slider-height) - var(--welcome-slide-gap)) / 2);
    }
}

@media (max-width: 1023px) {
    .welcome-hero-visual {
        max-width: none;
    }

    .welcome-hero-slider {
        --welcome-slide-height: clamp(12rem, 60vw, 15.5rem);
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

@keyframes welcomeHeroVerticalSlider {
    from {
        transform: translateY(calc(-1 * (var(--welcome-slide-height) + var(--welcome-slide-gap)) * var(--welcome-slide-count)));
    }
    to {
        transform: translateY(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .welcome-fade-up,
    .welcome-fade-in,
    .welcome-hero-track {
        animation: none;
    }
}

</style>
