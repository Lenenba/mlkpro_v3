<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    canLogin: {
        type: Boolean,
        default: true,
    },
    canRegister: {
        type: Boolean,
        default: true,
    },
    welcomeContent: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const currentLocale = computed(() => page.props.locale || 'fr');
const availableLocales = computed(() => page.props.locales || ['fr', 'en']);
const welcomeContent = computed(() => props.welcomeContent || {});
const langMenuOpen = ref(false);
const langMenuRef = ref(null);

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

const navButtonClass = (style) => {
    if (style === 'solid') {
        return 'rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700';
    }
    if (style === 'ghost') {
        return 'rounded-sm border border-transparent px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-100';
    }
    return 'rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-800 hover:bg-stone-50';
};

const sectionStyle = (color) => {
    const value = String(color || '').trim();
    return value ? { background: value } : {};
};

const navMenuItems = computed(() =>
    (welcomeContent.value.nav?.menu || []).filter((item) => item && item.enabled !== false && isHrefAllowed(item.href))
);

const customSections = computed(() =>
    (welcomeContent.value.custom_sections || []).filter((section) => section && section.enabled !== false)
);

const setLocale = (locale) => {
    if (locale === currentLocale.value) {
        return;
    }

    langMenuOpen.value = false;
    router.post(route('locale.update'), { locale }, { preserveScroll: true });
};

const toggleLangMenu = () => {
    langMenuOpen.value = !langMenuOpen.value;
};

const closeLangMenu = () => {
    langMenuOpen.value = false;
};

const handleLangOutsideClick = (event) => {
    if (!langMenuRef.value) {
        return;
    }

    if (!langMenuRef.value.contains(event.target)) {
        langMenuOpen.value = false;
    }
};

onMounted(() => {
    document.addEventListener('click', handleLangOutsideClick);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleLangOutsideClick);
});
</script>

<template>
    <Head :title="$t('welcome.meta.title')" />

    <div class="welcome-page text-stone-900 dark:text-neutral-100">
        <header class="welcome-header">
            <div class="welcome-container flex items-center justify-between py-6">
                <Link :href="route('welcome')" class="flex items-center gap-3">
                    <ApplicationLogo class="h-8 w-28 sm:h-10 sm:w-32" />
                    <div class="leading-tight">
                        <div class="text-sm font-semibold">MLK Pro</div>
                        <div class="text-xs text-stone-500">{{ welcomeContent.nav?.tagline || $t('welcome.nav.tagline') }}</div>
                    </div>
                </Link>

                <div class="flex items-center gap-3">
                    <div ref="langMenuRef" class="welcome-lang">
                        <button
                            type="button"
                            class="welcome-lang__toggle"
                            aria-haspopup="listbox"
                            :aria-expanded="langMenuOpen"
                            @click="toggleLangMenu"
                            @keydown.escape="closeLangMenu"
                        >
                            <span>{{ $t('account.language') }}</span>
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="16"
                                height="16"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="welcome-lang__chevron"
                            >
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                        <div
                            v-if="langMenuOpen"
                            class="welcome-lang__menu"
                            role="listbox"
                            :aria-activedescendant="`lang-${currentLocale}`"
                            @keydown.escape="closeLangMenu"
                        >
                            <button
                                v-for="locale in availableLocales"
                                :id="`lang-${locale}`"
                                :key="locale"
                                type="button"
                                role="option"
                                class="welcome-lang__item"
                                :class="currentLocale === locale ? 'is-active' : ''"
                                :aria-selected="currentLocale === locale"
                                @click="setLocale(locale)"
                            >
                                {{ $t(`language.${locale}`) }}
                            </button>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <template v-for="item in navMenuItems" :key="item.id || item.label || item.href">
                            <a
                                v-if="isExternalHref(resolveHref(item.href))"
                                :href="resolveHref(item.href)"
                                class="inline-flex items-center"
                                :class="navButtonClass(item.style)"
                                rel="noopener noreferrer"
                                target="_blank"
                            >
                                {{ item.label || item.href }}
                            </a>
                            <Link
                                v-else
                                :href="resolveHref(item.href)"
                                class="inline-flex items-center"
                                :class="navButtonClass(item.style)"
                            >
                                {{ item.label || item.href }}
                            </Link>
                        </template>
                    </div>
                </div>
            </div>
        </header>

        <main>
            <section v-if="welcomeContent.hero?.enabled !== false" class="welcome-section welcome-hero"
                :style="sectionStyle(welcomeContent.hero?.background_color)">
                <div class="welcome-container">
                    <div class="grid grid-cols-1 items-center lg:grid-cols-2 welcome-split">
                        <div class="space-y-6">
                            <div class="welcome-kicker welcome-fade-up">
                                {{ welcomeContent.hero?.eyebrow || $t('welcome.hero.eyebrow') }}
                            </div>
                            <h1 class="welcome-title text-4xl font-semibold tracking-tight sm:text-5xl welcome-fade-up" style="animation-delay: 0.1s;">
                                {{ welcomeContent.hero?.title || $t('welcome.hero.title') }}
                            </h1>
                            <div class="welcome-rich text-base text-stone-600 sm:text-lg welcome-fade-up" style="animation-delay: 0.2s;"
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
                                        class="rounded-sm border border-stone-200 bg-white px-5 py-2.5 text-sm font-semibold text-stone-800 hover:bg-stone-50"
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

                            <div class="grid gap-2 text-sm text-stone-600 welcome-fade-up" style="animation-delay: 0.5s;">
                                <div v-for="(item, highlightIndex) in (welcomeContent.hero?.highlights || [])" :key="highlightIndex" class="flex items-start gap-2">
                                    <span class="mt-1 size-1.5 rounded-full bg-green-600"></span>
                                    <span>{{ item }}</span>
                                </div>
                            </div>

                            <div class="welcome-rich text-xs text-stone-500 welcome-fade-up" style="animation-delay: 0.6s;"
                                v-html="welcomeContent.hero?.note || $t('welcome.hero.note')"></div>
                        </div>

                        <div class="relative welcome-fade-in">
                            <div class="rounded-sm border border-stone-200 bg-white p-3 shadow-xl">
                                <img
                                    :src="welcomeContent.hero?.image_url || '/images/landing/hero-dashboard.svg'"
                                    :alt="welcomeContent.hero?.image_alt || $t('welcome.images.hero_alt')"
                                    class="h-auto w-full rounded-sm"
                                    loading="lazy"
                                    decoding="async"
                                />
                            </div>
                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                <div
                                    v-for="(card, cardIndex) in (welcomeContent.hero?.preview_cards || [])"
                                    :key="card.id || cardIndex"
                                    class="rounded-sm border border-stone-200 bg-white/90 p-3 text-xs text-stone-600 shadow-sm"
                                >
                                    <div class="text-sm font-semibold text-stone-900">{{ card.title }}</div>
                                    <div>{{ card.desc }}</div>
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

        <footer class="welcome-footer">
            <div class="welcome-container py-8 text-center text-xs text-stone-500">
                <div class="flex flex-col items-center gap-2">
                    <div class="flex flex-wrap items-center justify-center gap-4 text-stone-600">
                        <Link :href="route('pricing')" class="hover:text-stone-900">
                            {{ $t('legal.links.pricing') }}
                        </Link>
                        <a
                            v-if="isExternalHref(resolveHref(welcomeContent.footer?.terms_href || 'terms'))"
                            :href="resolveHref(welcomeContent.footer?.terms_href || 'terms')"
                            class="hover:text-stone-900"
                            rel="noopener noreferrer"
                            target="_blank"
                        >
                            {{ welcomeContent.footer?.terms_label || $t('legal.links.terms') }}
                        </a>
                        <Link
                            v-else
                            :href="resolveHref(welcomeContent.footer?.terms_href || 'terms')"
                            class="hover:text-stone-900"
                        >
                            {{ welcomeContent.footer?.terms_label || $t('legal.links.terms') }}
                        </Link>
                        <Link :href="route('privacy')" class="hover:text-stone-900">
                            {{ $t('legal.links.privacy') }}
                        </Link>
                        <Link :href="route('refund')" class="hover:text-stone-900">
                            {{ $t('legal.links.refund') }}
                        </Link>
                    </div>
                    <div>{{ welcomeContent.footer?.copy || $t('welcome.footer.copy') }} {{ new Date().getFullYear() }}</div>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Work+Sans:wght@400;500;600&display=swap');

.welcome-page {
    --welcome-ink: #0f172a;
    --welcome-muted: #475569;
    --welcome-accent: #16a34a;
    font-family: 'Work Sans', 'Figtree', sans-serif;
    background: #ffffff;
}

.welcome-title {
    font-family: 'Space Grotesk', 'Figtree', sans-serif;
    letter-spacing: -0.02em;
}

.welcome-container {
    width: 100%;
    max-width: 72rem;
    margin: 0 auto;
    padding-left: 1.25rem;
    padding-right: 1.25rem;
}

.welcome-header {
    position: sticky;
    top: 0;
    z-index: 40;
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid #e2e8f0;
}

.welcome-lang {
    position: relative;
}

.welcome-lang__toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    padding: 0.5rem 1rem;
    border-radius: 0.125rem;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    font-size: 0.85rem;
    font-weight: 600;
    color: #0f172a;
    box-shadow: 0 12px 24px -20px rgba(15, 23, 42, 0.5);
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.welcome-lang__toggle:hover {
    border-color: #16a34a;
    box-shadow: 0 16px 30px -22px rgba(15, 23, 42, 0.6);
}

.welcome-lang__toggle:focus-visible {
    outline: 2px solid rgba(16, 185, 129, 0.5);
    outline-offset: 2px;
}

.welcome-lang__chevron {
    color: #0f172a;
}

.welcome-lang__menu {
    position: absolute;
    right: 0;
    top: calc(100% + 0.5rem);
    min-width: 10.5rem;
    padding: 0.4rem;
    border-radius: 0.125rem;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    box-shadow: 0 16px 36px -24px rgba(15, 23, 42, 0.6);
    z-index: 50;
}

.welcome-lang__item {
    width: 100%;
    padding: 0.45rem 0.75rem;
    border-radius: 0.125rem;
    text-align: left;
    font-size: 0.85rem;
    font-weight: 500;
    color: #0f172a;
    transition: background 0.2s ease, color 0.2s ease;
}

.welcome-lang__item:hover {
    background: #f1f5f9;
}

.welcome-lang__item.is-active {
    background: #16a34a;
    color: #ffffff;
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
    background: linear-gradient(180deg, #f8fafc 0%, #ffffff 55%, #ecfdf5 100%);
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

.welcome-footer {
    background: #ffffff;
    border-top: 1px solid #e2e8f0;
}

.welcome-kicker {
    display: inline-flex;
    align-items: center;
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    background: rgba(16, 185, 129, 0.12);
    color: #065f46;
    font-size: 0.75rem;
    font-weight: 600;
}

.welcome-pill {
    border: 1px solid #e2e8f0;
    border-radius: 999px;
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
    border-radius: 999px;
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

@media (prefers-reduced-motion: reduce) {
    .welcome-fade-up,
    .welcome-fade-in {
        animation: none;
    }
}

</style>
