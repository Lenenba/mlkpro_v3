<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import MegaMenuDisplay from '@/Components/MegaMenu/MegaMenuDisplay.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    page: { type: Object, required: true },
    content: {
        type: Object,
        default: () => ({
            page_title: '',
            page_subtitle: '',
            header: {
                background_type: 'none',
                background_color: '',
                background_image_url: '',
                background_image_alt: '',
                alignment: 'center',
            },
            sections: [],
        }),
    },
    plan_key: { type: String, default: null },
    megaMenu: { type: Object, default: () => ({}) },
});

const page = usePage();
const { t } = useI18n();
const currentLocale = computed(() => page.props.locale || 'fr');
const currentLocaleCode = computed(() => String(currentLocale.value || 'fr').toUpperCase());
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
const embeddedFrameRefs = ref({});
const embeddedFrameHeights = ref({});

const pageHeader = computed(() => ({
    background_type: props.content?.header?.background_type || 'none',
    background_color: props.content?.header?.background_color || '',
    background_image_url: props.content?.header?.background_image_url || '',
    background_image_alt: props.content?.header?.background_image_alt || '',
    alignment: props.content?.header?.alignment || 'center',
}));

const isDarkHexColor = (value) => {
    const input = String(value || '').trim().replace('#', '');
    if (!/^[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/.test(input)) {
        return false;
    }

    const normalized = input.length === 3
        ? input.split('').map((char) => `${char}${char}`).join('')
        : input;
    const red = Number.parseInt(normalized.slice(0, 2), 16);
    const green = Number.parseInt(normalized.slice(2, 4), 16);
    const blue = Number.parseInt(normalized.slice(4, 6), 16);
    const luminance = (0.2126 * red + 0.7152 * green + 0.0722 * blue) / 255;

    return luminance < 0.55;
};

const heroUsesContrast = computed(() => {
    if (pageHeader.value.background_type === 'image' && pageHeader.value.background_image_url) {
        return true;
    }

    if (pageHeader.value.background_type === 'color' && pageHeader.value.background_color) {
        return isDarkHexColor(pageHeader.value.background_color);
    }

    return false;
});

const escapeCssUrl = (value) =>
    String(value || '')
        .replace(/\\/g, '\\\\')
        .replace(/'/g, '%27')
        .replace(/"/g, '%22');

const heroStyle = computed(() => {
    if (pageHeader.value.background_type === 'image' && pageHeader.value.background_image_url) {
        return {
            backgroundImage: `linear-gradient(rgba(15, 23, 42, 0.42), rgba(15, 23, 42, 0.42)), url('${escapeCssUrl(pageHeader.value.background_image_url)}')`,
            backgroundPosition: 'center',
            backgroundRepeat: 'no-repeat',
            backgroundSize: 'cover',
        };
    }

    if (pageHeader.value.background_type === 'color' && pageHeader.value.background_color) {
        return {
            background: pageHeader.value.background_color,
        };
    }

    return {};
});

const heroContentClass = computed(() => {
    if (pageHeader.value.alignment === 'left') {
        return 'mr-auto max-w-4xl text-left';
    }

    if (pageHeader.value.alignment === 'right') {
        return 'ml-auto max-w-4xl text-right';
    }

    return 'mx-auto max-w-4xl text-center';
});

const heroSubtitleClass = computed(() => {
    if (pageHeader.value.alignment === 'left') {
        return 'max-w-2xl';
    }

    if (pageHeader.value.alignment === 'right') {
        return 'ml-auto max-w-2xl';
    }

    return 'mx-auto max-w-3xl';
});

const resolveSectionKey = (section, index) => section?.id || `section-${index}`;

const setEmbeddedFrameRef = (sectionKey, element) => {
    if (!sectionKey) {
        return;
    }

    if (element) {
        embeddedFrameRefs.value[sectionKey] = element;
        return;
    }

    delete embeddedFrameRefs.value[sectionKey];
};

const updateEmbeddedFrameHeight = (sectionKey, height, fallback = 760) => {
    const numericHeight = Number(height);
    if (!Number.isFinite(numericHeight) || numericHeight <= 0) {
        return;
    }

    embeddedFrameHeights.value = {
        ...embeddedFrameHeights.value,
        [sectionKey]: Math.max(Math.ceil(numericHeight), fallback),
    };
};

const resolvedEmbedHeight = (section, index) => {
    const sectionKey = resolveSectionKey(section, index);
    return embeddedFrameHeights.value[sectionKey] || sectionEmbedHeight(section);
};

const handleEmbeddedFrameLoad = (section, index, event) => {
    const frame = event?.target;
    if (!(frame instanceof HTMLIFrameElement)) {
        return;
    }

    const fallbackHeight = sectionEmbedHeight(section);
    const sectionKey = resolveSectionKey(section, index);

    try {
        const body = frame.contentWindow?.document?.body;
        const root = frame.contentWindow?.document?.documentElement;
        const height = Math.max(
            body?.scrollHeight ?? 0,
            body?.offsetHeight ?? 0,
            root?.scrollHeight ?? 0,
            root?.offsetHeight ?? 0,
            root?.clientHeight ?? 0
        );

        updateEmbeddedFrameHeight(sectionKey, height, fallbackHeight);
    } catch (error) {
        updateEmbeddedFrameHeight(sectionKey, fallbackHeight, fallbackHeight);
    }
};

const handleEmbeddedFrameMessage = (event) => {
    if (!event?.data || event.data.type !== 'public-lead-form-height') {
        return;
    }

    const match = Object.entries(embeddedFrameRefs.value).find(([, frame]) => frame?.contentWindow === event.source);
    if (!match) {
        return;
    }

    const [sectionKey, frame] = match;
    updateEmbeddedFrameHeight(sectionKey, event.data.height, frame?.clientHeight || 760);
};

onMounted(() => {
    document.addEventListener('click', handleLangOutsideClick);
    window.addEventListener('message', handleEmbeddedFrameMessage);
    updateDevice();
    window.addEventListener('resize', updateDevice);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleLangOutsideClick);
    window.removeEventListener('message', handleEmbeddedFrameMessage);
    window.removeEventListener('resize', updateDevice);
});

const sectionStyle = (section) => {
    const value = String(section?.background_color || '').trim();
    if (!value || section?.layout === 'duo') {
        return {};
    }

    return { background: value };
};

const duoPanelStyle = (section) => {
    const value = String(section?.background_color || '').trim();
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

const sectionEmbedUrl = (section) => resolveHref(section?.embed_url || '');
const sectionEmbedTitle = (section) => String(section?.embed_title || section?.title || 'Embedded form');
const sectionEmbedHeight = (section) => {
    const height = Number(section?.embed_height);
    if (!Number.isFinite(height) || height < 420) {
        return 760;
    }

    return Math.min(height, 1600);
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

const sectionContainerClass = (section) => {
    if (section?.layout === 'duo') {
        return 'public-container public-container--duo';
    }

    return section?.embed_url
        ? 'public-container public-container--embed'
        : 'public-container';
};

const layoutClass = (section) => {
    const layout = section?.layout;
    if (layout === 'testimonial') {
        return 'public-testimonial-shell';
    }
    if (layout === 'duo') {
        return 'public-duo-grid';
    }
    if (layout === 'contact') {
        return 'public-contact-grid';
    }
    if (layout === 'stack') {
        return 'mx-auto flex max-w-3xl flex-col gap-5';
    }
    if (section?.embed_url) {
        return 'grid grid-cols-1 items-start gap-8 lg:grid-cols-[minmax(0,0.62fr)_minmax(0,1.48fr)] xl:grid-cols-[minmax(0,0.56fr)_minmax(0,1.64fr)]';
    }
    return 'grid grid-cols-1 items-center gap-8 lg:grid-cols-2';
};

const sectionHasContactAside = (section) =>
    Boolean(
        section?.aside_kicker ||
        section?.aside_title ||
        section?.aside_body ||
        section?.aside_items?.length ||
        section?.aside_link_label
    );

const sectionHasTestimonialMeta = (section) =>
    Boolean(
        section?.testimonial_author ||
        section?.testimonial_role ||
        section?.secondary_label
    );

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

const headerMenuItems = computed(() => ([
    {
        label: t('public_pages.actions.home'),
        resolved_href: route('welcome'),
        link_target: '_self',
        panel_type: 'link',
    },
    {
        label: t('public_pages.actions.login'),
        resolved_href: route('login'),
        link_target: '_self',
        panel_type: 'link',
    },
]));
</script>

<template>
    <Head :title="content.page_title || page.title" />

    <div class="public-page" :style="themeStyle">
        <header class="public-header">
            <div class="mx-auto flex w-full max-w-[88rem] items-center gap-5 px-5 py-5 xl:px-8">
                <Link :href="route('welcome')" class="flex shrink-0 items-center">
                    <ApplicationLogo class="h-10 w-36 sm:h-11 sm:w-40" />
                </Link>

                <div class="min-w-0 flex-1">
                    <MegaMenuDisplay :menu="megaMenu" :fallback-items="headerMenuItems" />
                </div>

                <div class="flex shrink-0 items-center gap-3">
                    <Link
                        v-if="!isAuthenticated"
                        :href="route('login')"
                        class="hidden rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-800 hover:bg-stone-50 md:inline-flex"
                    >
                        {{ $t('legal.actions.sign_in') }}
                    </Link>
                    <Link
                        v-if="!isAuthenticated"
                        :href="route('onboarding.index')"
                        class="hidden rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-semibold text-white hover:bg-green-700 xl:inline-flex"
                    >
                        {{ $t('legal.actions.create_account') }}
                    </Link>
                    <div ref="langMenuRef" class="public-lang">
                        <button type="button" class="public-lang__toggle" aria-haspopup="listbox"
                            :aria-label="$t('account.language')" :aria-expanded="langMenuOpen" @click="toggleLangMenu" @keydown.escape="closeLangMenu">
                            <span>{{ currentLocaleCode }}</span>
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
                </div>
            </div>
        </header>

        <main>
            <section
                class="public-section public-hero"
                :class="{ 'public-hero--contrast': heroUsesContrast }"
                :style="heroStyle"
            >
                <div class="public-hero-container">
                    <div :class="['flex flex-col gap-4', heroContentClass]">
                        <h1 class="public-title text-4xl font-semibold tracking-tight sm:text-5xl">
                            {{ content.page_title || page.title }}
                        </h1>
                        <div v-if="content.page_subtitle" :class="['public-rich text-base sm:text-lg', heroSubtitleClass]"
                            v-html="content.page_subtitle"></div>
                    </div>
                </div>
            </section>

            <section v-for="(section, index) in sections" :key="section.id || index"
                :class="['public-section public-block', densityClass(section.density), toneClass(section.tone), {
                    'public-block--duo': section.layout === 'duo',
                    'public-block--testimonial': section.layout === 'testimonial',
                }]"
                :style="sectionStyle(section)">
                <div :class="sectionContainerClass(section)">
                    <div :class="layoutClass(section)">
                        <template v-if="section.layout === 'contact'">
                            <div class="space-y-4" :class="alignmentClass(section.alignment)">
                                <div v-if="section.kicker" class="public-kicker">{{ section.kicker }}</div>
                                <h2 class="public-title text-3xl font-semibold">{{ section.title }}</h2>
                                <div v-if="section.body" class="public-rich text-sm sm:text-base" v-html="section.body"></div>

                                <ul v-if="section.items?.length" class="space-y-2 text-sm">
                                    <li v-for="(item, itemIndex) in section.items" :key="itemIndex" class="public-bullet">
                                        {{ item }}
                                    </li>
                                </ul>

                                <div class="flex flex-wrap gap-3">
                                    <template v-if="section.primary_label">
                                        <a
                                            v-if="isExternalHref(resolveHref(section.primary_href))"
                                            :href="resolveHref(section.primary_href)"
                                            class="public-inline-link"
                                            rel="noopener noreferrer"
                                            target="_blank"
                                        >
                                            {{ section.primary_label }}
                                        </a>
                                        <Link
                                            v-else
                                            :href="resolveHref(section.primary_href)"
                                            class="public-inline-link"
                                        >
                                            {{ section.primary_label }}
                                        </Link>
                                    </template>
                                    <template v-if="section.secondary_label">
                                        <a
                                            v-if="isExternalHref(resolveHref(section.secondary_href))"
                                            :href="resolveHref(section.secondary_href)"
                                            class="public-inline-link public-inline-link--muted"
                                            rel="noopener noreferrer"
                                            target="_blank"
                                        >
                                            {{ section.secondary_label }}
                                        </a>
                                        <Link
                                            v-else
                                            :href="resolveHref(section.secondary_href)"
                                            class="public-inline-link public-inline-link--muted"
                                        >
                                            {{ section.secondary_label }}
                                        </Link>
                                    </template>
                                </div>
                            </div>

                            <div v-if="section.image_url" class="public-media public-contact-media">
                                <div class="public-media-card public-contact-map-card">
                                    <img
                                        :src="section.image_url"
                                        :alt="section.image_alt || section.title"
                                        class="h-full w-full rounded-sm object-cover"
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </div>
                            </div>

                            <div v-if="sectionHasContactAside(section)" class="public-contact-aside space-y-4 text-left">
                                <div v-if="section.aside_kicker" class="public-kicker">{{ section.aside_kicker }}</div>
                                <h3 v-if="section.aside_title" class="public-title text-2xl font-semibold">
                                    {{ section.aside_title }}
                                </h3>
                                <div
                                    v-if="section.aside_body"
                                    class="public-rich text-sm sm:text-base"
                                    v-html="section.aside_body"
                                ></div>

                                <ul v-if="section.aside_items?.length" class="space-y-2 text-sm">
                                    <li
                                        v-for="(item, itemIndex) in section.aside_items"
                                        :key="`aside-${itemIndex}`"
                                        class="public-bullet"
                                    >
                                        {{ item }}
                                    </li>
                                </ul>

                                <template v-if="section.aside_link_label">
                                    <a
                                        v-if="isExternalHref(resolveHref(section.aside_link_href))"
                                        :href="resolveHref(section.aside_link_href)"
                                        class="public-inline-link"
                                        rel="noopener noreferrer"
                                        target="_blank"
                                    >
                                        {{ section.aside_link_label }}
                                    </a>
                                    <Link
                                        v-else
                                        :href="resolveHref(section.aside_link_href)"
                                        class="public-inline-link"
                                    >
                                        {{ section.aside_link_label }}
                                    </Link>
                                </template>
                            </div>
                        </template>

                        <template v-else-if="section.layout === 'testimonial'">
                            <div
                                class="public-testimonial-card"
                                :class="{ 'public-testimonial-card--image-left': section.image_position === 'left' }"
                            >
                                <div class="public-testimonial-panel">
                                    <div class="public-testimonial-copy">
                                        <div class="public-testimonial-mark" aria-hidden="true">
                                            <svg viewBox="0 0 40 32" fill="none" class="h-8 w-10">
                                                <path
                                                    d="M17.6 0H6.4L0 12.8V32h17.6V14.4H8.8L17.6 0Z"
                                                    fill="currentColor"
                                                />
                                                <path
                                                    d="M40 0H28.8L22.4 12.8V32H40V14.4H31.2L40 0Z"
                                                    fill="currentColor"
                                                />
                                            </svg>
                                        </div>
                                        <div v-if="section.kicker" class="public-kicker public-testimonial-kicker">{{ section.kicker }}</div>
                                        <p v-if="section.title" class="public-testimonial-title">{{ section.title }}</p>
                                        <div
                                            v-else-if="section.body"
                                            class="public-rich public-testimonial-title public-testimonial-title--rich"
                                            v-html="section.body"
                                        ></div>
                                        <div
                                            v-if="section.title && section.body"
                                            class="public-rich public-testimonial-body"
                                            v-html="section.body"
                                        ></div>

                                        <div v-if="sectionHasTestimonialMeta(section)" class="public-testimonial-meta">
                                            <div v-if="section.testimonial_author" class="public-testimonial-author">
                                                {{ section.testimonial_author }}
                                            </div>
                                            <div v-if="section.testimonial_role" class="public-testimonial-role">
                                                {{ section.testimonial_role }}
                                            </div>

                                            <template v-if="section.secondary_label">
                                                <a
                                                    v-if="isExternalHref(resolveHref(section.secondary_href))"
                                                    :href="resolveHref(section.secondary_href)"
                                                    class="public-testimonial-link"
                                                    rel="noopener noreferrer"
                                                    target="_blank"
                                                >
                                                    {{ section.secondary_label }}
                                                </a>
                                                <Link
                                                    v-else
                                                    :href="resolveHref(section.secondary_href)"
                                                    class="public-testimonial-link"
                                                >
                                                    {{ section.secondary_label }}
                                                </Link>
                                            </template>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    class="public-testimonial-media"
                                    :class="{ 'public-testimonial-media--empty': !section.image_url }"
                                >
                                    <img
                                        v-if="section.image_url"
                                        :src="section.image_url"
                                        :alt="section.image_alt || section.title"
                                        class="h-full w-full object-cover"
                                        loading="lazy"
                                        decoding="async"
                                    />

                                    <template v-if="section.primary_label">
                                        <a
                                            v-if="isExternalHref(resolveHref(section.primary_href))"
                                            :href="resolveHref(section.primary_href)"
                                            class="public-testimonial-play"
                                            rel="noopener noreferrer"
                                            target="_blank"
                                        >
                                            <span class="public-testimonial-play__icon" aria-hidden="true">
                                                <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4">
                                                    <path d="M9 7.5v9l7-4.5-7-4.5Z" fill="currentColor" />
                                                </svg>
                                            </span>
                                            <span>{{ section.primary_label }}</span>
                                        </a>
                                        <Link
                                            v-else
                                            :href="resolveHref(section.primary_href)"
                                            class="public-testimonial-play"
                                        >
                                            <span class="public-testimonial-play__icon" aria-hidden="true">
                                                <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4">
                                                    <path d="M9 7.5v9l7-4.5-7-4.5Z" fill="currentColor" />
                                                </svg>
                                            </span>
                                            <span>{{ section.primary_label }}</span>
                                        </Link>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <template v-else-if="section.layout === 'duo'">
                            <div
                                class="public-duo-media"
                                :class="{
                                    'public-duo-media--empty': !section.image_url,
                                    'public-duo-media--image-right': section.image_position === 'right',
                                }"
                            >
                                <img
                                    v-if="section.image_url"
                                    :src="section.image_url"
                                    :alt="section.image_alt || section.title"
                                    class="h-full w-full object-cover"
                                    loading="lazy"
                                    decoding="async"
                                />
                            </div>

                            <div
                                class="public-duo-panel"
                                :class="{ 'public-duo-panel--image-right': section.image_position === 'right' }"
                                :style="duoPanelStyle(section)"
                            >
                                <div class="public-duo-content public-duo-panel-inner" :class="alignmentClass(section.alignment)">
                                    <div v-if="section.kicker" class="public-kicker">{{ section.kicker }}</div>
                                    <h2 class="public-title public-duo-title">{{ section.title }}</h2>
                                    <div v-if="section.body" class="public-rich public-duo-body" v-html="section.body"></div>

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
                            </div>
                        </template>

                        <template v-else>
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

                            <div
                                v-if="section.embed_url || section.image_url"
                                :class="section.embed_url ? 'public-media public-media--embed' : 'public-media'"
                            >
                                <div v-if="section.embed_url" class="public-media-card public-media-card--embed overflow-hidden">
                                    <iframe
                                        :src="sectionEmbedUrl(section)"
                                        :title="sectionEmbedTitle(section)"
                                        :ref="(element) => setEmbeddedFrameRef(resolveSectionKey(section, index), element)"
                                        class="w-full border-0 bg-white"
                                        :style="{ height: `${resolvedEmbedHeight(section, index)}px` }"
                                        loading="lazy"
                                        scrolling="no"
                                        @load="handleEmbeddedFrameLoad(section, index, $event)"
                                    />
                                </div>
                                <div v-else class="public-media-card">
                                    <img :src="section.image_url" :alt="section.image_alt || section.title"
                                        class="h-auto w-full rounded-sm object-cover" loading="lazy" decoding="async" />
                                </div>
                            </div>
                        </template>
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
@import url('https://fonts.bunny.net/css?family=Cal+Sans:400&family=DM+Sans:400,500,600,700&family=Space+Grotesk:400,500,600,700&family=Sora:400,500,600,700&family=Work+Sans:400,500,600,700&display=swap');

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

.public-hero-container {
    width: min(88rem, 100%);
    margin-inline: auto;
    padding-inline: 1.25rem;
}

.public-container--embed {
    width: min(1100px, 92vw);
}

.public-container--duo {
    width: 100%;
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

.public-hero--contrast .public-title {
    color: #ffffff;
}

.public-hero--contrast .public-rich {
    color: rgba(255, 255, 255, 0.9);
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

.public-block--duo {
    padding-block: 0;
    border-top: 0;
}

.public-block--testimonial {
    border-top: 0;
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
    border-radius: 0.125rem;
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

.public-contact-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 2rem;
    align-items: start;
}

.public-testimonial-card {
    display: grid;
    grid-template-columns: 1fr;
    overflow: hidden;
    border-radius: var(--page-radius, 4px);
    background: var(--page-surface, #ffffff);
    box-shadow: 0 30px 70px -55px rgba(15, 23, 42, 0.45);
}

.public-testimonial-panel {
    background: #ffffff;
}

.public-testimonial-copy {
    display: flex;
    flex-direction: column;
    min-height: 100%;
    padding: clamp(2rem, 4vw, 3rem);
}

.public-testimonial-mark {
    color: #ffcb05;
}

.public-testimonial-kicker {
    margin-bottom: 1rem;
}

.public-testimonial-title {
    margin: 1.5rem 0 1rem;
    color: #23282b;
    font-family: 'Cal Sans', var(--page-font-heading, 'Space Grotesk', sans-serif);
    font-size: 28px;
    font-weight: 400;
    line-height: 1.12;
    letter-spacing: -0.03em;
    width: 100%;
    max-width: 100%;
}

.public-testimonial-title--rich {
    margin: 1.5rem 0 1rem;
}

.public-testimonial-title--rich :deep(p),
.public-testimonial-title--rich :deep(div) {
    margin: 0;
    color: #23282b;
    font-family: 'Cal Sans', var(--page-font-heading, 'Space Grotesk', sans-serif);
    font-size: 28px;
    font-weight: 400;
    line-height: 1.12;
    letter-spacing: -0.03em;
    width: 100%;
    max-width: 100%;
}

.public-testimonial-card .public-rich.public-testimonial-body {
    margin-top: 1.25rem;
    color: #4b5563;
    font-size: 1rem;
    line-height: 1.65;
    max-width: 34rem;
}

.public-testimonial-body :deep(p),
.public-testimonial-body :deep(div) {
    margin: 0 0 1rem;
}

.public-testimonial-body :deep(p:last-child),
.public-testimonial-body :deep(div:last-child) {
    margin-bottom: 0;
}

.public-testimonial-meta {
    margin-top: auto;
    padding-top: clamp(2rem, 4vw, 4rem);
}

.public-testimonial-author {
    color: #23282b;
    font-size: 1.125rem;
    font-weight: 700;
}

.public-testimonial-role {
    margin-top: 0.2rem;
    color: #4b5563;
    font-size: 1rem;
}

.public-testimonial-link {
    display: inline-flex;
    margin-top: 0.95rem;
    color: #23282b;
    font-size: 0.95rem;
    font-weight: 600;
    text-decoration: none;
}

.public-testimonial-link::after {
    content: '->';
    margin-left: 0.35rem;
    font-size: 0.85em;
}

.public-testimonial-link:hover {
    color: var(--page-primary, #16a34a);
}

.public-testimonial-media {
    position: relative;
    min-height: clamp(20rem, 44vw, 28rem);
    background: linear-gradient(135deg, rgba(148, 163, 184, 0.18), rgba(226, 232, 240, 0.75));
}

.public-testimonial-media--empty {
    background:
        radial-gradient(circle at top left, rgba(255, 255, 255, 0.7), rgba(255, 255, 255, 0) 35%),
        linear-gradient(135deg, rgba(254, 240, 138, 0.55), rgba(148, 163, 184, 0.18));
}

.public-testimonial-play {
    position: absolute;
    left: 1.25rem;
    bottom: 1.25rem;
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    border-radius: var(--page-radius, 4px);
    background: rgba(24, 24, 27, 0.66);
    color: #ffffff;
    padding: 0.55rem 1rem 0.55rem 0.55rem;
    font-size: 0.95rem;
    font-weight: 500;
    text-decoration: none;
    backdrop-filter: blur(8px);
    transition: background 0.2s ease, transform 0.2s ease;
}

.public-testimonial-play:hover {
    background: rgba(24, 24, 27, 0.78);
    transform: translateY(-1px);
}

.public-testimonial-play__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.5rem;
    height: 2.5rem;
    border-radius: var(--page-radius, 4px);
    border: 1.5px solid rgba(255, 255, 255, 0.62);
    background: rgba(255, 255, 255, 0.06);
}

.public-duo-grid {
    display: grid;
    grid-template-columns: 1fr;
    overflow: hidden;
    border-radius: 0;
    box-shadow: none;
}

.public-duo-media {
    min-height: clamp(18rem, 45vw, 34rem);
    background: linear-gradient(135deg, rgba(148, 163, 184, 0.18), rgba(226, 232, 240, 0.75));
}

.public-duo-media--empty {
    background:
        radial-gradient(circle at top left, rgba(255, 255, 255, 0.6), rgba(255, 255, 255, 0) 35%),
        linear-gradient(135deg, var(--page-primary-soft, #dcfce7), rgba(148, 163, 184, 0.26));
}

.public-duo-panel {
    display: flex;
    align-items: center;
    min-height: 100%;
    padding:
        clamp(2rem, 5vw, 4rem)
        max(1.25rem, calc((100vw - min(1100px, 92vw)) / 2))
        clamp(2rem, 5vw, 4rem)
        clamp(1.5rem, 4vw, 3rem);
    background: var(--page-surface, #ffffff);
}

.public-duo-panel--image-right {
    padding:
        clamp(2rem, 5vw, 4rem)
        clamp(1.5rem, 4vw, 3rem)
        clamp(2rem, 5vw, 4rem)
        max(1.25rem, calc((100vw - min(1100px, 92vw)) / 2));
}

.public-duo-panel-inner {
    width: min(100%, 32rem);
}

.public-duo-content > * {
    margin: 0;
}

.public-duo-content > .public-kicker {
    margin-bottom: 1rem;
}

.public-duo-title {
    margin-bottom: 1.5rem;
    color: #23282b;
    font-family: 'Cal Sans', var(--page-font-heading, 'Space Grotesk', sans-serif);
    font-size: clamp(2.125rem, 1.75rem + 1vw, 2.625rem);
    line-height: 1.05;
    letter-spacing: -0.04em;
}

.public-duo-body {
    margin-bottom: 1rem;
    color: #23282b;
    font-family: 'Google Sans Flex', 'DM Sans', var(--page-font-body, 'Work Sans', sans-serif);
    font-size: clamp(1.05rem, 0.95rem + 0.35vw, 1.25rem);
    line-height: 1.55;
}

.public-duo-body :deep(p),
.public-duo-body :deep(div) {
    margin: 0 0 1rem;
}

.public-duo-body :deep(p:last-child),
.public-duo-body :deep(div:last-child) {
    margin-bottom: 0;
}

.public-duo-content > ul {
    margin-bottom: 1.5rem;
}

.public-tone--contrast .public-duo-title {
    color: var(--page-primary-contrast, #ffffff);
}

.public-tone--contrast .public-duo-body {
    color: var(--page-primary-contrast, #e2e8f0);
}

.public-media {
    display: flex;
    justify-content: center;
}

.public-contact-media {
    justify-content: stretch;
}

.public-media--embed {
    width: 100%;
    justify-content: stretch;
}

.public-media-card {
    border-radius: var(--page-radius, 4px);
    border: 1px solid var(--page-border, #e2e8f0);
    background: var(--page-surface, #ffffff);
    padding: 1rem;
    box-shadow: var(--page-shadow, 0 18px 40px -30px rgba(15, 23, 42, 0.4));
}

.public-media-card--embed {
    width: 100%;
    padding: 0;
}

.public-media-card--embed iframe {
    display: block;
}

.public-contact-map-card {
    width: 100%;
    padding: 0.75rem;
}

.public-contact-map-card img {
    aspect-ratio: 1 / 1;
}

.public-contact-aside {
    min-width: 0;
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

.public-inline-link {
    color: var(--page-text, #0f172a);
    font-size: 0.95rem;
    font-weight: 600;
    text-decoration: none;
    transition: color 0.2s ease;
}

.public-inline-link::after {
    content: '->';
    margin-left: 0.35rem;
    font-size: 0.85em;
}

.public-inline-link:hover {
    color: var(--page-primary, #16a34a);
}

.public-inline-link--muted {
    color: var(--page-muted, #64748b);
}

@media (min-width: 1280px) {
    .public-hero-container {
        padding-inline: 2rem;
    }
}

@media (min-width: 1024px) {
    .public-contact-grid {
        grid-template-columns: minmax(0, 0.9fr) minmax(300px, 1fr) minmax(0, 0.95fr);
        gap: 2.5rem;
    }

    .public-testimonial-card {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        min-height: 24.5rem;
    }

    .public-testimonial-card--image-left .public-testimonial-media {
        order: -1;
    }

    .public-duo-grid {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }

    .public-duo-media--image-right {
        order: 2;
    }
}
</style>
