<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    page: { type: Object, required: true },
    content: { type: Object, default: () => ({ page_title: '', page_subtitle: '', sections: [] }) },
    plan_key: { type: String, default: null },
});

const page = usePage();
const currentLocale = computed(() => page.props.locale || 'fr');
const availableLocales = computed(() => page.props.locales || ['fr', 'en']);
const planKey = computed(() => {
    const raw = props.plan_key || page.props.plan_key || null;
    return raw ? String(raw).toLowerCase() : null;
});
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const userRoles = computed(() => {
    const roles = [];
    const account = page.props.auth?.account || {};
    if (account.is_superadmin) roles.push('superadmin');
    if (account.is_platform_admin) roles.push('platform_admin');
    if (account.is_owner) roles.push('owner');
    if (account.is_client) roles.push('client');
    if (account.team?.role) roles.push(account.team.role);
    return roles;
});
const isMobile = ref(false);
const langMenuOpen = ref(false);
const langMenuRef = ref(null);

const setLocale = (locale) => {
    if (locale === currentLocale.value) return;
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
    if (!langMenuRef.value) return;
    if (!langMenuRef.value.contains(event.target)) {
        langMenuOpen.value = false;
    }
};

const updateDevice = () => {
    if (typeof window === 'undefined') return;
    isMobile.value = window.innerWidth < 768;
};

const theme = computed(() => props.content?.theme || {});
const themeStyle = computed(() => {
    const palette = theme.value || {};
    const radiusMap = { sm: '4px', md: '8px', lg: '12px', xl: '20px' };
    const shadowMap = {
        none: 'none',
        soft: '0 18px 40px -30px rgba(15, 23, 42, 0.4)',
        deep: '0 24px 60px -32px rgba(15, 23, 42, 0.55)',
    };
    const fontMap = {
        'work-sans': "'Work Sans', 'Figtree', sans-serif",
        'space-grotesk': "'Space Grotesk', 'Figtree', sans-serif",
        'sora': "'Sora', 'Figtree', sans-serif",
        'dm-sans': "'DM Sans', 'Figtree', sans-serif",
    };
    const bg = palette.background_color || '#f8fafc';
    const bgAlt = palette.background_alt_color || bg;
    const background =
        palette.background_style === 'solid'
            ? bg
            : `linear-gradient(180deg, ${bg} 0%, ${bgAlt} 100%)`;

    return {
        '--page-primary': palette.primary_color || '#16a34a',
        '--page-primary-soft': palette.primary_soft_color || '#dcfce7',
        '--page-primary-contrast': palette.primary_contrast_color || '#ffffff',
        '--page-background': bg,
        '--page-background-alt': bgAlt,
        '--page-surface': palette.surface_color || '#ffffff',
        '--page-text': palette.text_color || '#0f172a',
        '--page-muted': palette.muted_color || '#64748b',
        '--page-border': palette.border_color || '#e2e8f0',
        '--page-radius': radiusMap[palette.radius] || radiusMap.sm,
        '--page-shadow': shadowMap[palette.shadow] || shadowMap.soft,
        '--page-font-body': fontMap[palette.font_body] || fontMap['work-sans'],
        '--page-font-heading': fontMap[palette.font_heading] || fontMap['space-grotesk'],
        '--page-bg': background,
    };
});

const primaryButtonClass = computed(() => `public-button--${theme.value?.button_style || 'solid'}`);

onMounted(() => {
    document.addEventListener('click', handleLangOutsideClick);
    updateDevice();
    window.addEventListener('resize', updateDevice);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleLangOutsideClick);
    window.removeEventListener('resize', updateDevice);
});

const sectionStyle = (color) => {
    const value = String(color || '').trim();
    return value ? { background: value } : {};
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
        return `/${value}`;
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

const parseDate = (value) => {
    if (!value) return null;
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
};

const matchesVisibility = (section) => {
    const visibility = section?.visibility || {};

    if (visibility.locales?.length && !visibility.locales.includes(currentLocale.value)) {
        return false;
    }

    if (visibility.auth === 'auth' && !isAuthenticated.value) {
        return false;
    }
    if (visibility.auth === 'guest' && isAuthenticated.value) {
        return false;
    }

    if (visibility.roles?.length) {
        const matchesRole = visibility.roles.some((role) => userRoles.value.includes(role));
        if (!matchesRole) {
            return false;
        }
    }

    if (visibility.plans?.length) {
        if (!planKey.value || !visibility.plans.includes(planKey.value)) {
            return false;
        }
    }

    if (visibility.device === 'mobile' && !isMobile.value) {
        return false;
    }
    if (visibility.device === 'desktop' && isMobile.value) {
        return false;
    }

    const startAt = parseDate(visibility.start_at);
    if (startAt && new Date() < startAt) {
        return false;
    }
    const endAt = parseDate(visibility.end_at);
    if (endAt && new Date() > endAt) {
        return false;
    }

    return true;
};

const sections = computed(() =>
    (props.content.sections || []).filter((section) => section && section.enabled !== false && matchesVisibility(section))
);

const layoutClass = (layout) => {
    if (layout === 'stack') {
        return 'mx-auto flex max-w-3xl flex-col gap-5';
    }
    return 'grid grid-cols-1 items-center gap-8 lg:grid-cols-2';
};

const alignmentClass = (alignment) => {
    if (alignment === 'center') {
        return 'text-center';
    }
    if (alignment === 'right') {
        return 'text-right';
    }
    return 'text-left';
};

const densityClass = (density) => {
    if (density === 'compact') {
        return 'public-density--compact';
    }
    if (density === 'spacious') {
        return 'public-density--spacious';
    }
    return 'public-density--normal';
};

const toneClass = (tone) => {
    if (tone === 'muted') {
        return 'public-tone--muted';
    }
    if (tone === 'contrast') {
        return 'public-tone--contrast';
    }
    return 'public-tone--default';
};
</script>

<template>
    <Head :title="content.page_title || page.title" />

    <div class="public-page" :style="themeStyle">
        <header class="public-header">
            <div class="public-container flex items-center justify-between py-6">
                <Link :href="route('welcome')" class="flex items-center gap-3">
                    <ApplicationLogo class="h-8 w-28 sm:h-10 sm:w-32" />
                    <div class="leading-tight">
                        <div class="text-sm font-semibold">MLK Pro</div>
                        <div class="public-muted text-xs">/pages/{{ page.slug }}</div>
                    </div>
                </Link>

                <div class="flex items-center gap-3">
                    <div ref="langMenuRef" class="public-lang">
                        <button type="button" class="public-lang__toggle" aria-haspopup="listbox"
                            :aria-expanded="langMenuOpen" @click="toggleLangMenu" @keydown.escape="closeLangMenu">
                            <span>{{ $t('account.language') }}</span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                class="public-lang__chevron">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                        <div v-if="langMenuOpen" class="public-lang__menu" role="listbox"
                            :aria-activedescendant="`lang-${currentLocale}`" @keydown.escape="closeLangMenu">
                            <button v-for="locale in availableLocales" :id="`lang-${locale}`" :key="locale"
                                type="button" role="option" class="public-lang__item"
                                :class="currentLocale === locale ? 'is-active' : ''"
                                :aria-selected="currentLocale === locale" @click="setLocale(locale)">
                                {{ $t(`language.${locale}`) }}
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <Link :href="route('welcome')"
                            class="public-button public-button--secondary">
                            {{ $t('public_pages.actions.home') }}
                        </Link>
                        <Link :href="route('login')"
                            :class="['public-button', 'public-button--primary', primaryButtonClass]">
                            {{ $t('public_pages.actions.login') }}
                        </Link>
                    </div>
                </div>
            </div>
        </header>

        <main>
            <section class="public-section public-hero">
                <div class="public-container">
                    <div class="mx-auto flex max-w-4xl flex-col gap-4 text-center">
                        <h1 class="public-title text-4xl font-semibold tracking-tight sm:text-5xl">
                            {{ content.page_title || page.title }}
                        </h1>
                        <div v-if="content.page_subtitle" class="public-rich text-base sm:text-lg"
                            v-html="content.page_subtitle"></div>
                    </div>
                </div>
            </section>

            <section v-for="(section, index) in sections" :key="section.id || index"
                :class="['public-section public-block', densityClass(section.density), toneClass(section.tone)]"
                :style="sectionStyle(section.background_color)">
                <div class="public-container">
                    <div :class="layoutClass(section.layout)">
                        <div class="space-y-4" :class="alignmentClass(section.alignment)">
                            <div v-if="section.kicker" class="public-kicker">{{ section.kicker }}</div>
                            <h2 class="public-title text-3xl font-semibold">{{ section.title }}</h2>
                            <div v-if="section.body" class="public-rich text-sm sm:text-base"
                                v-html="section.body"></div>

                            <ul v-if="section.items?.length" class="space-y-2 text-sm">
                                <li v-for="(item, itemIndex) in section.items" :key="itemIndex" class="public-bullet">
                                    {{ item }}
                                </li>
                            </ul>

                            <div class="flex flex-wrap gap-2">
                                <template v-if="section.primary_label">
                                    <a v-if="isExternalHref(resolveHref(section.primary_href))"
                                        :href="resolveHref(section.primary_href)"
                                        :class="['public-button', 'public-button--primary', primaryButtonClass]"
                                        rel="noopener noreferrer" target="_blank">
                                        {{ section.primary_label }}
                                    </a>
                                    <Link v-else :href="resolveHref(section.primary_href)"
                                        :class="['public-button', 'public-button--primary', primaryButtonClass]">
                                        {{ section.primary_label }}
                                    </Link>
                                </template>
                                <template v-if="section.secondary_label">
                                    <a v-if="isExternalHref(resolveHref(section.secondary_href))"
                                        :href="resolveHref(section.secondary_href)"
                                        class="public-button public-button--secondary"
                                        rel="noopener noreferrer" target="_blank">
                                        {{ section.secondary_label }}
                                    </a>
                                    <Link v-else :href="resolveHref(section.secondary_href)"
                                        class="public-button public-button--secondary">
                                        {{ section.secondary_label }}
                                    </Link>
                                </template>
                            </div>
                        </div>

                        <div v-if="section.image_url" class="public-media">
                            <div class="public-media-card">
                                <img :src="section.image_url" :alt="section.image_alt || section.title"
                                    class="h-auto w-full rounded-sm object-cover" loading="lazy" decoding="async" />
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <footer class="public-footer">
            <div class="public-container py-8 text-center text-xs">
                <div class="flex flex-wrap items-center justify-center gap-4">
                    <Link :href="route('welcome')" class="public-footer-link">
                        {{ $t('public_pages.actions.home') }}
                    </Link>
                    <Link :href="route('pricing')" class="public-footer-link">
                        {{ $t('legal.links.pricing') }}
                    </Link>
                    <Link :href="route('terms')" class="public-footer-link">
                        {{ $t('legal.links.terms') }}
                    </Link>
                </div>
                <div class="mt-2">{{ $t('welcome.footer.copy') }} {{ new Date().getFullYear() }}</div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&family=Sora:wght@400;500;600;700&family=Work+Sans:wght@400;500;600;700&display=swap');

.public-page {
    min-height: 100vh;
    background: var(--page-bg, linear-gradient(180deg, #f8fafc 0%, #ffffff 40%, #ecfdf5 100%));
    color: var(--page-text, #0f172a);
    font-family: var(--page-font-body, 'Work Sans', sans-serif);
}

.public-container {
    width: min(1100px, 92vw);
    margin-inline: auto;
}

.public-header {
    background: var(--page-surface, #ffffff);
    border-bottom: 1px solid var(--page-border, #e2e8f0);
    backdrop-filter: blur(12px);
    position: sticky;
    top: 0;
    z-index: 30;
}

.public-section {
    padding-block: clamp(3rem, 7vw, 7rem);
}

.public-hero {
    padding-block: clamp(3.5rem, 9vw, 8rem);
}

.public-section.public-density--compact {
    padding-block: clamp(2.25rem, 5vw, 4.5rem);
}

.public-section.public-density--spacious {
    padding-block: clamp(4.5rem, 10vw, 9rem);
}

.public-block {
    border-top: 1px solid var(--page-border, rgba(226, 232, 240, 0.65));
}

.public-title {
    color: var(--page-text, #0f172a);
    font-family: var(--page-font-heading, 'Space Grotesk', sans-serif);
}

.public-tone--muted .public-rich {
    color: var(--page-muted, #64748b);
}

.public-tone--contrast {
    color: var(--page-primary-contrast, #f8fafc);
}

.public-tone--contrast .public-title {
    color: var(--page-primary-contrast, #ffffff);
}

.public-tone--contrast .public-rich {
    color: var(--page-primary-contrast, #e2e8f0);
}

.public-tone--contrast .public-kicker {
    background: rgba(255, 255, 255, 0.18);
    color: var(--page-primary-contrast, #ffffff);
}

.public-rich :deep(p),
.public-rich :deep(div) {
    margin: 0.35rem 0;
}

.public-rich {
    color: var(--page-muted, #64748b);
}

.public-muted {
    color: var(--page-muted, #64748b);
}

.public-rich :deep(ul),
.public-rich :deep(ol) {
    margin: 0.4rem 0;
    padding-left: 1.1rem;
}

.public-rich :deep(li) {
    margin: 0.2rem 0;
}

.public-rich :deep(a) {
    text-decoration: underline;
    font-weight: 600;
    color: var(--page-primary, #16a34a);
}

.public-rich :deep(img) {
    max-width: 100%;
    border-radius: var(--page-radius, 0.75rem);
    margin: 0.5rem 0;
}

.public-kicker {
    display: inline-flex;
    align-items: center;
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    background: var(--page-primary-soft, rgba(16, 185, 129, 0.12));
    color: var(--page-primary, #065f46);
    font-size: 0.75rem;
    font-weight: 600;
}

.public-bullet {
    position: relative;
    padding-left: 1.25rem;
}

.public-bullet::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0.5rem;
    width: 0.45rem;
    height: 0.45rem;
    border-radius: 999px;
    background: var(--page-primary, #16a34a);
}

.public-media {
    display: flex;
    justify-content: center;
}

.public-media-card {
    border-radius: var(--page-radius, 4px);
    border: 1px solid var(--page-border, #e2e8f0);
    background: var(--page-surface, #ffffff);
    padding: 1rem;
    box-shadow: var(--page-shadow, 0 18px 40px -30px rgba(15, 23, 42, 0.4));
}

.public-footer {
    background: var(--page-surface, #ffffff);
    border-top: 1px solid var(--page-border, #e2e8f0);
    color: var(--page-muted, #64748b);
}

.public-footer-link {
    transition: color 0.2s ease;
}

.public-footer-link:hover {
    color: var(--page-text, #0f172a);
}

.public-lang {
    position: relative;
}

.public-lang__toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 0.75rem;
    border-radius: var(--page-radius, 0.125rem);
    border: 1px solid var(--page-border, #e2e8f0);
    background: var(--page-surface, #ffffff);
    color: var(--page-text, #0f172a);
    font-size: 0.85rem;
    font-weight: 600;
}

.public-lang__chevron {
    transition: transform 0.2s ease;
}

.public-lang__menu {
    position: absolute;
    right: 0;
    margin-top: 0.35rem;
    min-width: 10.5rem;
    padding: 0.4rem;
    border-radius: var(--page-radius, 0.125rem);
    border: 1px solid var(--page-border, #e2e8f0);
    background: var(--page-surface, #ffffff);
    box-shadow: 0 16px 36px -24px rgba(15, 23, 42, 0.6);
    z-index: 40;
}

.public-lang__item {
    width: 100%;
    padding: 0.45rem 0.75rem;
    border-radius: var(--page-radius, 0.125rem);
    text-align: left;
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--page-text, #0f172a);
    transition: background 0.2s ease, color 0.2s ease;
}

.public-lang__item:hover {
    background: rgba(148, 163, 184, 0.15);
}

.public-lang__item.is-active {
    background: var(--page-primary, #16a34a);
    color: var(--page-primary-contrast, #ffffff);
}

.public-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    padding: 0.5rem 1rem;
    border-radius: var(--page-radius, 4px);
    font-size: 0.875rem;
    font-weight: 600;
    border: 1px solid transparent;
    transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, color 0.2s ease;
}

.public-button:active {
    transform: translateY(1px);
}

.public-button--primary.public-button--solid {
    background: var(--page-primary, #16a34a);
    color: var(--page-primary-contrast, #ffffff);
    border-color: transparent;
}

.public-button--primary.public-button--solid:hover {
    filter: brightness(0.96);
}

.public-button--primary.public-button--outline {
    background: transparent;
    color: var(--page-primary, #16a34a);
    border-color: var(--page-primary, #16a34a);
}

.public-button--primary.public-button--outline:hover {
    background: var(--page-primary-soft, rgba(16, 185, 129, 0.08));
}

.public-button--primary.public-button--soft {
    background: var(--page-primary-soft, rgba(16, 185, 129, 0.12));
    color: var(--page-primary, #16a34a);
}

.public-button--primary.public-button--soft:hover {
    filter: brightness(0.97);
}

.public-button--primary.public-button--ghost {
    background: transparent;
    color: var(--page-primary, #16a34a);
}

.public-button--primary.public-button--ghost:hover {
    background: var(--page-primary-soft, rgba(16, 185, 129, 0.08));
}

.public-button--secondary {
    background: var(--page-surface, #ffffff);
    color: var(--page-text, #0f172a);
    border-color: var(--page-border, #e2e8f0);
}

.public-button--secondary:hover {
    background: rgba(148, 163, 184, 0.12);
}
</style>
