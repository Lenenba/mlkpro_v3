<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import FeatureTabsShowcaseSection from '@/Components/Public/FeatureTabsShowcaseSection.vue';
import PublicFooterMenu from '@/Components/Public/PublicFooterMenu.vue';
import PublicFrontHero from '@/Components/Public/PublicFrontHero.vue';
import PublicSiteHeader from '@/Components/Public/PublicSiteHeader.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { buildBackgroundStyle, buildBackgroundToneStyle } from '@/utils/backgroundPresets';
import { useI18n } from 'vue-i18n';
import { resolveIndustryIconComponent } from '@/utils/industryGrid';

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
    footerMenu: { type: Object, default: () => ({}) },
    footerSection: { type: Object, default: () => ({}) },
});

const page = usePage();
const { t } = useI18n();
const currentLocale = computed(() => page.props.locale || 'fr');
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
        '--page-font-body': 'var(--front-font-body)',
        '--page-font-heading': 'var(--front-font-heading)',
        '--page-bg': background,
    };
});

const primaryButtonClass = computed(() => `public-button--${theme.value?.button_style || 'solid'}`);
const embeddedFrameRefs = ref({});
const embeddedFrameHeights = ref({});
const openFeatureTabIds = ref({});
const activeFeaturePanelIds = ref({});

const pageHeader = computed(() => ({
    background_type: props.content?.header?.background_type || 'none',
    background_color: props.content?.header?.background_color || '',
    background_image_url: props.content?.header?.background_image_url || '',
    background_image_alt: props.content?.header?.background_image_alt || '',
    alignment: props.content?.header?.alignment || 'center',
}));

const alignedHeroProductSlugs = new Set([
    'ai-automation',
    'command-center',
    'commerce',
    'marketing-loyalty',
    'operations',
    'reservations',
    'sales-crm',
]);

const usesWelcomeAlignedHero = computed(() => {
    const slug = String(props.page?.slug || '').trim();

    return slug === 'contact-us'
        || slug.startsWith('solution-')
        || slug.startsWith('industry-')
        || alignedHeroProductSlugs.has(slug);
});

const frontHeroEyebrow = computed(() => {
    const slug = String(props.page?.slug || '').trim();
    const isFrench = String(currentLocale.value || 'fr').toLowerCase().startsWith('fr');

    if (slug === 'contact-us') {
        return isFrench ? 'Contact' : 'Contact us';
    }

    if (slug.startsWith('solution-')) {
        return isFrench ? 'Solutions' : 'Solutions';
    }

    if (slug.startsWith('industry-')) {
        return isFrench ? 'Industries' : 'Industries';
    }

    return isFrench ? 'Produits' : 'Products';
});

const firstHeroSectionWithImage = computed(() => {
    const sections = Array.isArray(props.content?.sections) ? props.content.sections : [];

    return sections.find((section) => String(section?.image_url || section?.aside_image_url || '').trim()) || null;
});

const frontHeroImage = computed(() => {
    const headerImage = String(pageHeader.value.background_image_url || '').trim();
    if (headerImage) {
        return headerImage;
    }

    const section = firstHeroSectionWithImage.value;
    const sectionImage = String(section?.image_url || section?.aside_image_url || '').trim();
    if (sectionImage) {
        return sectionImage;
    }

    return '/images/landing/stock/team-laptop-window.jpg';
});

const frontHeroImageAlt = computed(() => {
    const headerAlt = String(pageHeader.value.background_image_alt || '').trim();
    if (headerAlt) {
        return headerAlt;
    }

    const section = firstHeroSectionWithImage.value;
    const sectionAlt = String(section?.image_alt || section?.aside_title || section?.title || '').trim();
    if (sectionAlt) {
        return sectionAlt;
    }

    return String(props.content?.page_title || props.page?.title || 'Page hero').trim();
});

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
    window.addEventListener('message', handleEmbeddedFrameMessage);
    updateDevice();
    window.addEventListener('resize', updateDevice);
});

onBeforeUnmount(() => {
    window.removeEventListener('message', handleEmbeddedFrameMessage);
    window.removeEventListener('resize', updateDevice);
});

const sectionStyle = (section) => {
    if (section?.layout === 'duo' || section?.layout === 'showcase_cta') {
        return {};
    }

    return buildBackgroundStyle(section);
};

const duoPanelStyle = (section) => {
    return buildBackgroundStyle(section);
};

const showcaseShellStyle = (section) => {
    return {
        ...buildBackgroundStyle(section),
        ...buildBackgroundToneStyle(section),
    };
};

const showcaseCopyStyle = () => {
    return {};
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

const testimonialCardsForSection = (section) => (
    Array.isArray(section?.testimonial_cards) ? section.testimonial_cards : []
);

const storyCardsForSection = (section) => (
    Array.isArray(section?.story_cards) ? section.story_cards : []
);

const testimonialCardInitials = (card) => {
    const parts = String(card?.author_name || '')
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2);

    const initials = parts.map((part) => part.charAt(0).toUpperCase()).join('');
    return initials || '?';
};

const testimonialCardMetaLine = (card) => (
    [card?.author_role, card?.author_company]
        .map((value) => String(value || '').trim())
        .filter((value) => value.length > 0)
        .join(' - ')
);

const featureTabsForSection = (section) => (
    Array.isArray(section?.feature_tabs) ? section.feature_tabs : []
);

const featureTabsStyle = (section) => {
    const size = Number(section?.feature_tabs_font_size);

    if (!Number.isFinite(size) || size <= 0) {
        return {};
    }

    return {
        '--feature-tabs-trigger-label-size': `${Math.min(Math.max(size, 18), 40)}px`,
    };
};

const featureTabChildren = (tab) => (
    Array.isArray(tab?.children) ? tab.children : []
);

const featurePanelKeyForTab = (tab) => (
    tab?.id ? `tab:${tab.id}` : ''
);

const featurePanelKeyForChild = (tab, child) => (
    tab?.id && child?.id ? `child:${tab.id}:${child.id}` : ''
);

const buildFeaturePanelFromTab = (tab) => (
    tab
        ? {
            key: featurePanelKeyForTab(tab),
            tabId: tab.id,
            childId: null,
            label: tab.label || '',
            title: tab.title || tab.label || '',
            body: tab.body || '',
            image_url: tab.image_url || '',
            image_alt: tab.image_alt || '',
            cta_label: tab.cta_label || '',
            cta_href: tab.cta_href || '',
            source: 'tab',
        }
        : null
);

const buildFeaturePanelFromChild = (tab, child) => (
    tab && child
        ? {
            key: featurePanelKeyForChild(tab, child),
            tabId: tab.id,
            childId: child.id,
            label: child.label || '',
            title: child.title || tab.title || child.label || tab.label || '',
            body: child.body || '',
            image_url: child.image_url || tab.image_url || '',
            image_alt: child.image_alt || tab.image_alt || '',
            cta_label: child.cta_label || tab.cta_label || '',
            cta_href: child.cta_href || tab.cta_href || '',
            source: 'child',
        }
        : null
);

const resolveFeaturePanel = (section, key) => {
    const tabs = featureTabsForSection(section);
    if (!tabs.length) {
        return null;
    }

    if (!key) {
        return null;
    }

    for (const tab of tabs) {
        if (featurePanelKeyForTab(tab) === key) {
            return buildFeaturePanelFromTab(tab);
        }

        for (const child of featureTabChildren(tab)) {
            if (featurePanelKeyForChild(tab, child) === key) {
                return buildFeaturePanelFromChild(tab, child);
            }
        }
    }

    return null;
};

const defaultFeaturePanel = (section) => {
    const tabs = featureTabsForSection(section);
    for (const tab of tabs) {
        const firstChild = featureTabChildren(tab)[0];
        if (firstChild) {
            return buildFeaturePanelFromChild(tab, firstChild);
        }
    }

    return tabs[0] ? buildFeaturePanelFromTab(tabs[0]) : null;
};

const openFeatureTabId = (section, index) => {
    const tabs = featureTabsForSection(section);
    if (!tabs.length) {
        return null;
    }

    const sectionKey = resolveSectionKey(section, index);
    if (Object.prototype.hasOwnProperty.call(openFeatureTabIds.value, sectionKey)) {
        return openFeatureTabIds.value[sectionKey];
    }

    return tabs[0]?.id || null;
};

const activeFeaturePanel = (section, index) => {
    const sectionKey = resolveSectionKey(section, index);
    const storedKey = activeFeaturePanelIds.value[sectionKey];

    return resolveFeaturePanel(section, storedKey) || defaultFeaturePanel(section);
};

const isFeatureTabOpen = (section, index, tab) => (
    openFeatureTabId(section, index) === tab?.id
);

const isCurrentFeatureTab = (section, index, tab) => (
    Boolean(openFeatureTabId(section, index)) && activeFeaturePanel(section, index)?.tabId === tab?.id
);

const isActiveFeatureTabChild = (section, index, tab, child) => {
    const panel = activeFeaturePanel(section, index);
    return panel?.tabId === tab?.id && panel?.childId === child?.id;
};

const toggleFeatureTab = (section, index, tab) => {
    if (!tab?.id) {
        return;
    }

    const sectionKey = resolveSectionKey(section, index);
    const isOpen = openFeatureTabId(section, index) === tab.id;

    openFeatureTabIds.value = {
        ...openFeatureTabIds.value,
        [sectionKey]: isOpen ? null : tab.id,
    };

    if (isOpen) {
        return;
    }

    const currentPanel = activeFeaturePanel(section, index);
    if (currentPanel?.tabId === tab.id) {
        return;
    }

    const nextPanel = featureTabChildren(tab)[0]
        ? buildFeaturePanelFromChild(tab, featureTabChildren(tab)[0])
        : buildFeaturePanelFromTab(tab);

    if (!nextPanel) {
        return;
    }

    activeFeaturePanelIds.value = {
        ...activeFeaturePanelIds.value,
        [sectionKey]: nextPanel.key,
    };
};

const setActiveFeatureTabChild = (section, index, tab, child) => {
    const panel = buildFeaturePanelFromChild(tab, child);
    if (!panel) {
        return;
    }

    const sectionKey = resolveSectionKey(section, index);
    openFeatureTabIds.value = {
        ...openFeatureTabIds.value,
        [sectionKey]: tab.id,
    };
    activeFeaturePanelIds.value = {
        ...activeFeaturePanelIds.value,
        [sectionKey]: panel.key,
    };
};

const showFeatureTabChildren = (section, index, tab) => (
    isFeatureTabOpen(section, index, tab) && featureTabChildren(tab).length > 0
);

const sectionContainerClass = (section) => {
    if (section?.layout === 'duo') {
        return 'public-container public-container--duo';
    }

    if (section?.layout === 'feature_pairs') {
        return 'public-container public-container--feature-pairs';
    }

    if (section?.layout === 'industry_grid') {
        return 'public-container public-container--industry-grid';
    }

    if (section?.layout === 'story_grid') {
        return 'public-container public-container--story-grid';
    }

    if (section?.layout === 'testimonial_grid') {
        return 'public-container public-container--testimonial-grid';
    }

    if (section?.layout === 'feature_tabs') {
        return 'public-container public-container--feature-tabs';
    }

    if (section?.layout === 'showcase_cta') {
        return 'public-container public-container--showcase-cta';
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
    if (layout === 'feature_pairs') {
        return 'public-feature-pairs-shell';
    }
    if (layout === 'showcase_cta') {
        return 'public-showcase-grid';
    }
    if (layout === 'industry_grid') {
        return 'public-industry-grid-shell';
    }
    if (layout === 'story_grid') {
        return 'public-story-grid-shell';
    }
    if (layout === 'testimonial_grid') {
        return 'public-testimonial-grid-shell';
    }
    if (layout === 'feature_tabs') {
        return 'public-feature-tabs-shell';
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

const sectionHasFeaturePairsAside = (section) =>
    Boolean(
        section?.aside_kicker ||
        section?.aside_title ||
        section?.aside_body ||
        section?.aside_items?.length ||
        section?.aside_link_label ||
        section?.aside_image_url
    );

const showcaseHasMedia = (section) =>
    Boolean(
        String(section?.image_url || '').trim() ||
        String(section?.aside_image_url || '').trim()
    );

const showcaseDividerStyle = (section) => {
    const value = String(section?.showcase_divider_style || '').trim().toLowerCase();

    if (value === 'curve' || value === 'round') {
        return 'round';
    }

    return ['vertical', 'glow', 'notch'].includes(value) ? value : 'diagonal';
};

const showcaseMainImageUrl = (section) =>
    String(section?.image_url || '').trim() || String(section?.aside_image_url || '').trim();

const showcaseMainImageAlt = (section) =>
    String(section?.image_url || '').trim()
        ? (section?.image_alt || section?.title)
        : (section?.aside_image_alt || section?.image_alt || section?.title);

const showcaseHasBadge = (section) =>
    Boolean(
        String(section?.showcase_badge_label || '').trim() ||
        String(section?.showcase_badge_value || '').trim() ||
        String(section?.showcase_badge_note || '').trim()
    );

const showcaseUsesInlineAsideLink = (section) => Boolean(String(section?.aside_link_label || '').trim());

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
        label: 'Industries',
        panel_type: 'classic',
        children: [
            {
                label: 'Plomberie',
                resolved_href: '/pages/industry-plumbing',
                link_target: '_self',
                panel_type: 'link',
            },
            {
                label: 'HVAC',
                resolved_href: '/pages/industry-hvac',
                link_target: '_self',
                panel_type: 'link',
            },
            {
                label: 'Electricite',
                resolved_href: '/pages/industry-electrical',
                link_target: '_self',
                panel_type: 'link',
            },
            {
                label: 'Nettoyage',
                resolved_href: '/pages/industry-cleaning',
                link_target: '_self',
                panel_type: 'link',
            },
            {
                label: 'Salon & beaute',
                resolved_href: '/pages/industry-salon-beauty',
                link_target: '_self',
                panel_type: 'link',
            },
            {
                label: 'Restaurant',
                resolved_href: '/pages/industry-restaurant',
                link_target: '_self',
                panel_type: 'link',
            },
        ],
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

    <div class="public-page front-public-page" :style="themeStyle">
        <PublicSiteHeader
            :mega-menu="megaMenu"
            :fallback-items="headerMenuItems"
            :can-login="!isAuthenticated"
            :can-register="!isAuthenticated"
            :is-authenticated="isAuthenticated"
        />

        <main>
            <PublicFrontHero
                v-if="usesWelcomeAlignedHero"
                :eyebrow="frontHeroEyebrow"
                :title="content.page_title || page.title"
                :subtitle-html="content.page_subtitle"
                :image-src="frontHeroImage"
                :image-alt="frontHeroImageAlt"
            />
            <section
                v-else
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
                :id="section.id || null"
                :class="['public-section public-block', densityClass(section.density), toneClass(section.tone), {
                    'public-block--duo': section.layout === 'duo',
                    'public-block--testimonial': section.layout === 'testimonial',
                    'public-block--feature-pairs': section.layout === 'feature_pairs',
                    'public-block--showcase-cta': section.layout === 'showcase_cta',
                    'public-block--industry-grid': section.layout === 'industry_grid',
                    'public-block--story-grid': section.layout === 'story_grid',
                    'public-block--testimonial-grid': section.layout === 'testimonial_grid',
                    'public-block--feature-tabs': section.layout === 'feature_tabs',
                }]"
                :style="sectionStyle(section)">
                <div :class="sectionContainerClass(section)">
                    <div
                        :class="[
                            layoutClass(section),
                            { 'public-showcase-grid--no-media': section.layout === 'showcase_cta' && !showcaseHasMedia(section) },
                        ]"
                    >
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
                                <div class="public-media-card public-media-card--visual public-contact-map-card">
                                    <img
                                        :src="section.image_url"
                                        :alt="section.image_alt || section.title"
                                        class="public-contact-map-card__image"
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
                                        class="public-testimonial-media__image"
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

                        <template v-else-if="section.layout === 'testimonial_grid'">
                            <div class="public-testimonial-grid">
                                <div
                                    v-if="section.kicker || section.title || section.body || section.primary_label || section.secondary_label"
                                    class="public-testimonial-grid__header"
                                    :class="alignmentClass(section.alignment)"
                                >
                                    <div v-if="section.kicker" class="public-kicker">{{ section.kicker }}</div>
                                    <h2 v-if="section.title" class="public-testimonial-grid__title">{{ section.title }}</h2>
                                    <div
                                        v-if="section.body"
                                        class="public-rich public-testimonial-grid__body"
                                        v-html="section.body"
                                    ></div>

                                    <div v-if="section.primary_label || section.secondary_label" class="public-testimonial-grid__actions">
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

                                <div v-if="testimonialCardsForSection(section).length" class="public-testimonial-grid__cards">
                                    <article
                                        v-for="card in testimonialCardsForSection(section)"
                                        :key="card.id"
                                        class="public-testimonial-grid__card"
                                    >
                                        <div
                                            v-if="card.quote"
                                            class="public-rich public-testimonial-grid__quote"
                                            v-html="card.quote"
                                        ></div>

                                        <div class="public-testimonial-grid__meta">
                                            <div class="public-testimonial-grid__avatar" :class="{ 'is-empty': !card.image_url }">
                                                <img
                                                    v-if="card.image_url"
                                                    :src="card.image_url"
                                                    :alt="card.image_alt || card.author_name"
                                                    class="h-full w-full object-cover"
                                                    loading="lazy"
                                                    decoding="async"
                                                />
                                                <span v-else>{{ testimonialCardInitials(card) }}</span>
                                            </div>

                                            <div class="public-testimonial-grid__person">
                                                <div v-if="card.author_name" class="public-testimonial-grid__name">{{ card.author_name }}</div>
                                                <div v-if="testimonialCardMetaLine(card)" class="public-testimonial-grid__role">
                                                    {{ testimonialCardMetaLine(card) }}
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                </div>
                            </div>
                        </template>

                        <template v-else-if="section.layout === 'feature_pairs'">
                            <div class="public-feature-pairs">
                                <article class="public-feature-pairs__row">
                                    <div
                                        class="public-feature-pairs__media"
                                        :class="{ 'public-feature-pairs__media--empty': !section.image_url }"
                                    >
                                        <img
                                            v-if="section.image_url"
                                            :src="section.image_url"
                                            :alt="section.image_alt || section.title"
                                            class="public-feature-pairs__image"
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>

                                    <div class="public-feature-pairs__copy" :class="alignmentClass(section.alignment)">
                                        <div v-if="section.kicker" class="public-feature-pairs__kicker">{{ section.kicker }}</div>
                                        <h2 v-if="section.title" class="public-feature-pairs__title">{{ section.title }}</h2>
                                        <div
                                            v-if="section.body"
                                            class="public-rich public-feature-pairs__body"
                                            v-html="section.body"
                                        ></div>

                                        <ul v-if="section.items?.length" class="public-feature-pairs__list">
                                            <li v-for="(item, itemIndex) in section.items" :key="itemIndex" class="public-bullet">
                                                {{ item }}
                                            </li>
                                        </ul>

                                        <div v-if="section.primary_label || section.secondary_label" class="flex flex-wrap gap-3">
                                            <template v-if="section.primary_label">
                                                <a
                                                    v-if="isExternalHref(resolveHref(section.primary_href))"
                                                    :href="resolveHref(section.primary_href)"
                                                    class="public-feature-pairs__link"
                                                    rel="noopener noreferrer"
                                                    target="_blank"
                                                >
                                                    {{ section.primary_label }}
                                                </a>
                                                <Link
                                                    v-else
                                                    :href="resolveHref(section.primary_href)"
                                                    class="public-feature-pairs__link"
                                                >
                                                    {{ section.primary_label }}
                                                </Link>
                                            </template>

                                            <template v-if="section.secondary_label">
                                                <a
                                                    v-if="isExternalHref(resolveHref(section.secondary_href))"
                                                    :href="resolveHref(section.secondary_href)"
                                                    class="public-feature-pairs__link public-feature-pairs__link--muted"
                                                    rel="noopener noreferrer"
                                                    target="_blank"
                                                >
                                                    {{ section.secondary_label }}
                                                </a>
                                                <Link
                                                    v-else
                                                    :href="resolveHref(section.secondary_href)"
                                                    class="public-feature-pairs__link public-feature-pairs__link--muted"
                                                >
                                                    {{ section.secondary_label }}
                                                </Link>
                                            </template>
                                        </div>
                                    </div>
                                </article>

                                <article v-if="sectionHasFeaturePairsAside(section)" class="public-feature-pairs__row public-feature-pairs__row--reverse">
                                    <div class="public-feature-pairs__copy" :class="alignmentClass(section.alignment)">
                                        <div v-if="section.aside_kicker" class="public-feature-pairs__kicker">{{ section.aside_kicker }}</div>
                                        <h2 v-if="section.aside_title" class="public-feature-pairs__title">{{ section.aside_title }}</h2>
                                        <div
                                            v-if="section.aside_body"
                                            class="public-rich public-feature-pairs__body"
                                            v-html="section.aside_body"
                                        ></div>

                                        <ul v-if="section.aside_items?.length" class="public-feature-pairs__list">
                                            <li v-for="(item, itemIndex) in section.aside_items" :key="`feature-aside-${itemIndex}`" class="public-bullet">
                                                {{ item }}
                                            </li>
                                        </ul>

                                        <template v-if="section.aside_link_label">
                                            <a
                                                v-if="isExternalHref(resolveHref(section.aside_link_href))"
                                                :href="resolveHref(section.aside_link_href)"
                                                class="public-feature-pairs__link"
                                                rel="noopener noreferrer"
                                                target="_blank"
                                            >
                                                {{ section.aside_link_label }}
                                            </a>
                                            <Link
                                                v-else
                                                :href="resolveHref(section.aside_link_href)"
                                                class="public-feature-pairs__link"
                                            >
                                                {{ section.aside_link_label }}
                                            </Link>
                                        </template>
                                    </div>

                                    <div
                                        class="public-feature-pairs__media"
                                        :class="{ 'public-feature-pairs__media--empty': !section.aside_image_url }"
                                    >
                                        <img
                                            v-if="section.aside_image_url"
                                            :src="section.aside_image_url"
                                            :alt="section.aside_image_alt || section.aside_title || section.title"
                                            class="public-feature-pairs__image"
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                </article>
                            </div>
                        </template>

                        <template v-else-if="section.layout === 'industry_grid'">
                            <div class="public-industry-grid">
                                <div v-if="section.kicker || section.title || section.body" class="public-industry-grid__header">
                                    <div v-if="section.kicker" class="public-kicker">{{ section.kicker }}</div>
                                    <h2 v-if="section.title" class="public-industry-grid__title">{{ section.title }}</h2>
                                    <div v-if="section.body" class="public-rich public-industry-grid__body" v-html="section.body"></div>
                                </div>

                                <div v-if="section.industry_cards?.length" class="public-industry-grid__cards">
                                    <template v-for="card in section.industry_cards" :key="card.id || card.label">
                                        <a
                                            v-if="card.href && isExternalHref(resolveHref(card.href))"
                                            :href="resolveHref(card.href)"
                                            class="public-industry-grid__card"
                                            rel="noopener noreferrer"
                                            target="_blank"
                                        >
                                            <span class="public-industry-grid__icon" aria-hidden="true">
                                                <component :is="resolveIndustryIconComponent(card)" class="h-full w-full" />
                                            </span>
                                            <span class="public-industry-grid__label">{{ card.label }}</span>
                                        </a>
                                        <Link
                                            v-else-if="card.href"
                                            :href="resolveHref(card.href)"
                                            class="public-industry-grid__card"
                                        >
                                            <span class="public-industry-grid__icon" aria-hidden="true">
                                                <component :is="resolveIndustryIconComponent(card)" class="h-full w-full" />
                                            </span>
                                            <span class="public-industry-grid__label">{{ card.label }}</span>
                                        </Link>
                                        <div v-else class="public-industry-grid__card">
                                            <span class="public-industry-grid__icon" aria-hidden="true">
                                                <component :is="resolveIndustryIconComponent(card)" class="h-full w-full" />
                                            </span>
                                            <span class="public-industry-grid__label">{{ card.label }}</span>
                                        </div>
                                    </template>
                                </div>

                                <div v-if="section.primary_label" class="public-industry-grid__footer">
                                    <a
                                        v-if="section.primary_href && isExternalHref(resolveHref(section.primary_href))"
                                        :href="resolveHref(section.primary_href)"
                                        class="public-industry-grid__cta"
                                        rel="noopener noreferrer"
                                        target="_blank"
                                    >
                                        {{ section.primary_label }}
                                    </a>
                                    <Link
                                        v-else-if="section.primary_href"
                                        :href="resolveHref(section.primary_href)"
                                        class="public-industry-grid__cta"
                                    >
                                        {{ section.primary_label }}
                                    </Link>
                                    <span v-else class="public-industry-grid__cta">
                                        {{ section.primary_label }}
                                    </span>
                                </div>
                            </div>
                        </template>

                        <template v-else-if="section.layout === 'story_grid'">
                            <div class="public-story-grid">
                                <div
                                    v-if="section.kicker || section.title || section.body || section.primary_label || section.secondary_label"
                                    class="public-story-grid__header"
                                    :class="alignmentClass(section.alignment)"
                                >
                                    <div v-if="section.kicker" class="public-kicker">{{ section.kicker }}</div>
                                    <h2 v-if="section.title" class="public-story-grid__title">{{ section.title }}</h2>
                                    <div
                                        v-if="section.body"
                                        class="public-rich public-story-grid__body"
                                        v-html="section.body"
                                    ></div>

                                    <div v-if="section.primary_label || section.secondary_label" class="public-story-grid__actions">
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

                                <div v-if="storyCardsForSection(section).length" class="public-story-grid__cards">
                                    <article
                                        v-for="card in storyCardsForSection(section)"
                                        :key="card.id"
                                        class="public-story-grid__card"
                                    >
                                        <div class="public-story-grid__visual" :class="{ 'public-story-grid__visual--empty': !card.image_url }">
                                            <img
                                                v-if="card.image_url"
                                                :src="card.image_url"
                                                :alt="card.image_alt || card.title || section.title"
                                                class="public-story-grid__image"
                                                loading="lazy"
                                                decoding="async"
                                            />
                                        </div>

                                        <div class="public-story-grid__copy" :class="alignmentClass(section.alignment)">
                                            <h3 v-if="card.title" class="public-story-grid__card-title">{{ card.title }}</h3>
                                            <div
                                                v-if="card.body"
                                                class="public-rich public-story-grid__card-body"
                                                v-html="card.body"
                                            ></div>
                                        </div>
                                    </article>
                                </div>
                            </div>
                        </template>

                        <template v-else-if="section.layout === 'feature_tabs'">
                            <FeatureTabsShowcaseSection v-if="featureTabsForSection(section).length" :section="section" />
                        </template>

                        <template v-else-if="section.layout === 'showcase_cta'">
                            <article
                                class="public-showcase-shell"
                                :class="[
                                    `public-showcase-shell--media-${section.image_position === 'left' ? 'left' : 'right'}`,
                                    `public-showcase-shell--divider-${showcaseDividerStyle(section)}`,
                                    { 'public-showcase-shell--no-media': !showcaseHasMedia(section) },
                                ]"
                                :style="showcaseShellStyle(section)"
                            >
                                <div class="public-showcase-shell__copy" :style="showcaseCopyStyle(section)">
                                    <div class="public-showcase-copy-inner" :class="alignmentClass(section.alignment)">
                                        <h2 class="public-title public-showcase-title">{{ section.title }}</h2>
                                        <div v-if="section.body" class="public-rich public-showcase-body" v-html="section.body"></div>

                                        <div
                                            v-if="section.primary_label || section.secondary_label || showcaseUsesInlineAsideLink(section)"
                                            class="public-showcase-actions"
                                        >
                                            <template v-if="section.primary_label">
                                                <a
                                                    v-if="isExternalHref(resolveHref(section.primary_href))"
                                                    :href="resolveHref(section.primary_href)"
                                                    :class="['public-button', 'public-button--primary', primaryButtonClass]"
                                                    rel="noopener noreferrer"
                                                    target="_blank"
                                                >
                                                    {{ section.primary_label }}
                                                </a>
                                                <Link
                                                    v-else
                                                    :href="resolveHref(section.primary_href)"
                                                    :class="['public-button', 'public-button--primary', primaryButtonClass]"
                                                >
                                                    {{ section.primary_label }}
                                                </Link>
                                            </template>

                                            <template v-if="section.secondary_label">
                                                <a
                                                    v-if="isExternalHref(resolveHref(section.secondary_href))"
                                                    :href="resolveHref(section.secondary_href)"
                                                    class="public-button public-button--secondary"
                                                    rel="noopener noreferrer"
                                                    target="_blank"
                                                >
                                                    {{ section.secondary_label }}
                                                </a>
                                                <Link
                                                    v-else
                                                    :href="resolveHref(section.secondary_href)"
                                                    class="public-button public-button--secondary"
                                                >
                                                    {{ section.secondary_label }}
                                                </Link>
                                            </template>

                                            <template v-if="showcaseUsesInlineAsideLink(section)">
                                                <a
                                                    v-if="section.aside_link_href && isExternalHref(resolveHref(section.aside_link_href))"
                                                    :href="resolveHref(section.aside_link_href)"
                                                    class="public-inline-link public-inline-link--muted"
                                                    rel="noopener noreferrer"
                                                    target="_blank"
                                                >
                                                    {{ section.aside_link_label }}
                                                </a>
                                                <Link
                                                    v-else-if="section.aside_link_href"
                                                    :href="resolveHref(section.aside_link_href)"
                                                    class="public-inline-link public-inline-link--muted"
                                                >
                                                    {{ section.aside_link_label }}
                                                </Link>
                                                <span v-else class="public-inline-link public-inline-link--muted">
                                                    {{ section.aside_link_label }}
                                                </span>
                                            </template>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="showcaseHasMedia(section)" class="public-showcase-shell__media">
                                    <div class="public-showcase-visual">
                                        <img
                                            :src="showcaseMainImageUrl(section)"
                                            :alt="showcaseMainImageAlt(section)"
                                            class="public-showcase-visual__image"
                                            loading="lazy"
                                            decoding="async"
                                        />
                                        <div class="public-showcase-visual__veil"></div>
                                    </div>
                                </div>
                            </article>
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
                                    class="public-duo-media__image"
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
                                <div v-else class="public-media-card public-media-card--visual">
                                    <img :src="section.image_url" :alt="section.image_alt || section.title"
                                        class="public-media-card__image" loading="lazy" decoding="async" />
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </section>
        </main>

        <PublicFooterMenu :menu="footerMenu" :section="footerSection" />
    </div>
</template>

<style scoped>
.public-page {
    --public-shell-width: 88rem;
    --public-shell-gutter: 1.25rem;
    min-height: 100vh;
    background: var(--page-bg, linear-gradient(180deg, #f8fafc 0%, #ffffff 40%, #ecfdf5 100%));
    color: var(--page-text, #0f172a);
    font-family: var(--page-font-body, var(--front-font-body));
}

.public-container {
    width: min(var(--public-shell-width), 100%);
    margin-inline: auto;
    padding-inline: var(--public-shell-gutter);
}

.public-hero-container {
    width: min(var(--public-shell-width), 100%);
    margin-inline: auto;
    padding-inline: var(--public-shell-gutter);
}

.public-container--embed {
    width: min(var(--public-shell-width), 100%);
}

.public-container--duo {
    width: 100%;
    padding-inline: 0;
}

.public-container--feature-pairs {
    width: min(var(--public-shell-width), 100%);
}

.public-container--industry-grid {
    width: min(var(--public-shell-width), 100%);
}

.public-container--story-grid {
    width: min(var(--public-shell-width), 100%);
}

.public-container--feature-tabs {
    width: 100%;
    padding-inline: 0;
}

.public-container--showcase-cta {
    width: 100%;
    max-width: none;
    padding-inline: 0;
}

.public-section {
    padding-block: clamp(1.5rem, 4vw, 3rem);
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
    padding-block: clamp(1rem, 2.8vw, 2rem);
}

.public-section.public-density--spacious {
    padding-block: clamp(2rem, 5vw, 4rem);
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

.public-block--feature-pairs {
    border-top: 0;
}

.public-block--showcase-cta {
    padding-block: 0;
    border-top: 0;
}

.public-block--industry-grid {
    border-top: 0;
}

.public-block--story-grid {
    border-top: 0;
}

.public-block--feature-tabs {
    padding-block: 0;
    border-top: 0;
}

.public-title {
    color: var(--page-text, #0f172a);
    font-family: var(--page-font-heading, var(--front-font-heading));
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
    background: rgba(22, 163, 74, 0.12);
    color: #15803d;
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
    color: var(--page-primary, #16a34a);
}

.public-testimonial-kicker {
    margin-bottom: 1rem;
}

.public-testimonial-title {
    margin: 1.5rem 0 1rem;
    color: #23282b;
    font-family: var(--page-font-heading, var(--front-font-heading));
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
    font-family: var(--page-font-heading, var(--front-font-heading));
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
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    min-height: clamp(22rem, 48vw, 31rem);
    background: linear-gradient(135deg, rgba(148, 163, 184, 0.18), rgba(226, 232, 240, 0.75));
}

.public-testimonial-media--empty {
    background:
        radial-gradient(circle at top left, rgba(255, 255, 255, 0.7), rgba(255, 255, 255, 0) 35%),
        linear-gradient(135deg, rgba(254, 240, 138, 0.55), rgba(148, 163, 184, 0.18));
}

.public-testimonial-media__image {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center top;
    border-radius: inherit;
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

.public-testimonial-grid-shell {
    display: block;
}

.public-testimonial-grid {
    display: flex;
    flex-direction: column;
    gap: clamp(2rem, 5vw, 3.1rem);
}

.public-testimonial-grid__header {
    max-width: 58rem;
    margin-inline: auto;
}

.public-testimonial-grid__header.text-left {
    margin-inline: 0 auto;
}

.public-testimonial-grid__header.text-right {
    margin-inline: auto 0;
}

.public-testimonial-grid__title {
    margin: 0;
    color: #083a5c;
    font-family: var(--page-font-heading, var(--front-font-heading));
    font-size: clamp(2.15rem, 1.72rem + 1vw, 3.55rem);
    line-height: 1.03;
    letter-spacing: -0.05em;
}

.public-testimonial-grid__body {
    margin-top: 1rem;
    color: #334155;
    font-size: 1rem;
    line-height: 1.7;
}

.public-testimonial-grid__body :deep(p),
.public-testimonial-grid__body :deep(div) {
    margin: 0 0 1rem;
}

.public-testimonial-grid__body :deep(p:last-child),
.public-testimonial-grid__body :deep(div:last-child) {
    margin-bottom: 0;
}

.public-testimonial-grid__actions {
    margin-top: 1.15rem;
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.9rem 1.2rem;
}

.public-testimonial-grid__header.text-left .public-testimonial-grid__actions {
    justify-content: flex-start;
}

.public-testimonial-grid__header.text-right .public-testimonial-grid__actions {
    justify-content: flex-end;
}

.public-testimonial-grid__cards {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.1rem;
}

.public-testimonial-grid__card {
    display: flex;
    flex-direction: column;
    min-height: 100%;
    padding: clamp(1.5rem, 3vw, 2rem);
    border-radius: var(--page-radius, 4px);
    background: #e9e2d6;
    box-shadow: 0 24px 52px -42px rgba(15, 23, 42, 0.28);
}

.public-testimonial-grid__quote {
    color: #082c45;
    font-size: 1.04rem;
    line-height: 1.72;
}

.public-testimonial-grid__quote :deep(p),
.public-testimonial-grid__quote :deep(div) {
    margin: 0 0 0.95rem;
}

.public-testimonial-grid__quote :deep(p:last-child),
.public-testimonial-grid__quote :deep(div:last-child) {
    margin-bottom: 0;
}

.public-testimonial-grid__quote :deep(strong) {
    font-weight: 800;
}

.public-testimonial-grid__meta {
    display: flex;
    align-items: center;
    gap: 0.95rem;
    margin-top: auto;
    padding-top: 1.6rem;
}

.public-testimonial-grid__avatar {
    width: 3.7rem;
    height: 3.7rem;
    border-radius: 0.125rem;
    overflow: hidden;
    flex-shrink: 0;
    background: #d7d0c3;
    color: #083a5c;
    font-size: 1rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.public-testimonial-grid__avatar.is-empty {
    border: 1px solid rgba(8, 58, 92, 0.08);
}

.public-testimonial-grid__person {
    min-width: 0;
}

.public-testimonial-grid__name {
    color: #082c45;
    font-size: 1.08rem;
    font-weight: 800;
}

.public-testimonial-grid__role {
    margin-top: 0.15rem;
    color: #0f3550;
    font-size: 0.96rem;
}

.public-feature-pairs {
    display: flex;
    flex-direction: column;
    gap: clamp(3rem, 8vw, 5.5rem);
}

.public-feature-pairs__row {
    display: grid;
    grid-template-columns: 1fr;
    gap: clamp(1.5rem, 4vw, 4.5rem);
    align-items: center;
}

.public-feature-pairs__media {
    position: relative;
    display: flex;
    align-items: stretch;
    justify-content: stretch;
    overflow: hidden;
    min-height: clamp(14rem, 28vw, 19rem);
    border-radius: var(--page-radius, 4px);
    background: linear-gradient(135deg, rgba(191, 219, 254, 0.85), rgba(219, 234, 254, 0.55));
    box-shadow: 0 22px 52px -40px rgba(15, 23, 42, 0.4);
}

.public-feature-pairs__media--empty {
    background:
        radial-gradient(circle at top left, rgba(255, 255, 255, 0.72), rgba(255, 255, 255, 0) 38%),
        linear-gradient(135deg, rgba(191, 219, 254, 0.85), rgba(226, 232, 240, 0.85));
}

.public-feature-pairs__image {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center;
    border-radius: inherit;
}

.public-feature-pairs__copy {
    min-width: 0;
    max-width: 24rem;
}

.public-feature-pairs__copy.text-center {
    margin-inline: auto;
}

.public-feature-pairs__copy.text-right {
    margin-left: auto;
}

.public-feature-pairs__kicker {
    margin-bottom: 0.7rem;
    color: #16a34a;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.public-feature-pairs__title {
    margin: 0 0 0.95rem;
    color: #23282b;
    font-family: var(--page-font-heading, var(--front-font-heading));
    font-size: clamp(1.75rem, 1.45rem + 0.55vw, 2.1rem);
    line-height: 1.08;
    letter-spacing: -0.03em;
}

.public-feature-pairs__body {
    color: #4b5563;
    font-size: 0.96rem;
    line-height: 1.6;
}

.public-feature-pairs__body :deep(p),
.public-feature-pairs__body :deep(div) {
    margin: 0 0 1rem;
}

.public-feature-pairs__body :deep(p:last-child),
.public-feature-pairs__body :deep(div:last-child) {
    margin-bottom: 0;
}

.public-feature-pairs__list {
    margin-top: 1.1rem;
    display: grid;
    gap: 0.55rem;
    color: #4b5563;
    font-size: 0.94rem;
}

.public-feature-pairs__link {
    display: inline-flex;
    align-items: center;
    color: #23282b;
    font-size: 0.95rem;
    font-weight: 600;
    text-decoration: none;
    transition: color 0.2s ease;
}

.public-feature-pairs__link::after {
    content: '\2197';
    margin-left: 0.35rem;
    font-size: 0.9em;
}

.public-feature-pairs__link:hover {
    color: var(--page-primary, #2563eb);
}

.public-feature-pairs__link--muted {
    color: var(--page-muted, #64748b);
}

.public-feature-tabs-shell {
    display: block;
}

.public-feature-tabs {
    display: flex;
    flex-direction: column;
    gap: clamp(2rem, 5vw, 3.25rem);
}

.public-feature-tabs__header {
    max-width: 48rem;
    margin-inline: auto;
    text-align: center;
}

.public-feature-tabs__header .public-kicker {
    margin-bottom: 1rem;
}

.public-feature-tabs__title {
    margin: 0;
    color: #083a5c;
    font-family: var(--page-font-heading, var(--front-font-heading));
    font-size: clamp(2.1rem, 1.75rem + 0.9vw, 3.2rem);
    line-height: 1.02;
    letter-spacing: -0.04em;
}

.public-feature-tabs__body {
    margin-top: 1rem;
    color: #334155;
    font-size: 1rem;
    line-height: 1.65;
}

.public-feature-tabs__body :deep(p),
.public-feature-tabs__body :deep(div) {
    margin: 0 0 1rem;
}

.public-feature-tabs__body :deep(p:last-child),
.public-feature-tabs__body :deep(div:last-child) {
    margin-bottom: 0;
}

.public-feature-tabs__header-actions {
    margin-top: 1.1rem;
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.9rem 1.2rem;
}

.public-feature-tabs__grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.5rem;
    align-items: start;
}

.public-feature-tabs__nav {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.public-feature-tabs__nav-item {
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
}

.public-feature-tabs__trigger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    width: 100%;
    padding: 1rem 1.15rem;
    border: 1px solid transparent;
    border-radius: var(--page-radius, 4px);
    background: #0b3446;
    color: #ffffff;
    font-family: var(--page-font-heading, var(--front-font-heading));
    font-size: 1.15rem;
    line-height: 1;
    text-align: left;
    box-shadow: 0 20px 42px -34px rgba(15, 23, 42, 0.45);
    transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
}

.public-feature-tabs__trigger:hover,
.public-feature-tabs__trigger:focus-visible {
    background: #0a2d3d;
    transform: translateY(-1px);
    box-shadow: 0 24px 48px -34px rgba(15, 23, 42, 0.52);
}

.public-feature-tabs__trigger.is-open {
    background: #0a2d3d;
    box-shadow: 0 24px 48px -34px rgba(15, 23, 42, 0.52);
}

.public-feature-tabs__trigger.is-current {
    border-color: var(--page-primary-soft, #dcfce7);
}

.public-feature-tabs__trigger-main {
    display: inline-flex;
    align-items: center;
    gap: 0.8rem;
    min-width: 0;
}

.public-feature-tabs__trigger-label {
    font-size: var(--feature-tabs-trigger-label-size, 1.15rem);
    line-height: 1.05;
    text-decoration-line: underline;
    text-decoration-thickness: 0.22rem;
    text-decoration-color: transparent;
    text-underline-offset: 0.34rem;
    transition: text-decoration-color 0.2s ease;
}

.public-feature-tabs__trigger.is-open .public-feature-tabs__trigger-label,
.public-feature-tabs__trigger.is-current .public-feature-tabs__trigger-label {
    text-decoration-color: var(--page-primary, #84cc16);
}

.public-feature-tabs__trigger-icon {
    display: inline-flex;
    width: 1.2rem;
    height: 1.2rem;
    color: var(--page-primary, #84cc16);
    flex-shrink: 0;
}

.public-feature-tabs__trigger-icon :deep(svg) {
    width: 100%;
    height: 100%;
}

.public-feature-tabs__sublist {
    display: grid;
    gap: 0.8rem;
    padding: 0 0 0.1rem 1.15rem;
}

.public-feature-tabs__sublist--static {
    padding-right: 1.1rem;
}

.public-feature-tabs__subitem {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    width: 100%;
    padding: 0.7rem 0.85rem;
    border-radius: calc(var(--page-radius, 4px) - 1px);
    text-align: left;
    color: #0f172a;
    font-size: 0.98rem;
    line-height: 1.45;
    transition: background 0.18s ease, color 0.18s ease, transform 0.18s ease;
}

.public-feature-tabs__subitem-label {
    min-width: 0;
}

button.public-feature-tabs__subitem {
    background: transparent;
}

button.public-feature-tabs__subitem:hover,
button.public-feature-tabs__subitem:focus-visible {
    color: #083a5c;
    background: rgba(132, 204, 22, 0.12);
    transform: translateX(2px);
}

button.public-feature-tabs__subitem.is-active {
    color: #072b41;
    background: var(--page-primary, #84cc16);
    box-shadow: 0 16px 34px -28px rgba(132, 204, 22, 0.55);
}

ul .public-feature-tabs__subitem {
    display: block;
    padding: 0 0 0 1.45rem;
}

ul .public-feature-tabs__subitem::before {
    content: '->';
    position: absolute;
    left: 0;
    top: 0;
    color: #0b3446;
    font-weight: 700;
}

.public-feature-tabs__panel {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.public-feature-tabs__panel-media {
    position: relative;
    display: flex;
    align-items: stretch;
    overflow: hidden;
    min-height: clamp(16rem, 36vw, 26rem);
    border-radius: var(--page-radius, 4px);
    background: #d8e2ea;
    box-shadow: 0 24px 56px -42px rgba(15, 23, 42, 0.38);
}

.public-feature-tabs__panel-image {
    position: absolute;
    inset: 0;
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: inherit;
}

.public-feature-tabs__panel-media--empty {
    background:
        radial-gradient(circle at top left, rgba(255, 255, 255, 0.72), rgba(255, 255, 255, 0) 35%),
        linear-gradient(135deg, rgba(216, 226, 234, 0.95), rgba(191, 219, 254, 0.82));
}

.public-feature-tabs__panel-copy {
    max-width: 32rem;
}

.public-feature-tabs__panel-title {
    margin: 0;
    color: #083a5c;
    font-family: var(--page-font-heading, var(--front-font-heading));
    font-size: clamp(1.7rem, 1.48rem + 0.55vw, 2.2rem);
    line-height: 1.05;
    letter-spacing: -0.03em;
}

.public-feature-tabs__panel-body {
    margin-top: 0.9rem;
    color: #334155;
    font-size: 0.98rem;
    line-height: 1.65;
}

.public-feature-tabs__panel-body :deep(p),
.public-feature-tabs__panel-body :deep(div) {
    margin: 0 0 1rem;
}

.public-feature-tabs__panel-body :deep(p:last-child),
.public-feature-tabs__panel-body :deep(div:last-child) {
    margin-bottom: 0;
}

.public-feature-tabs__panel-link {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    margin-top: 1.2rem;
    padding: 0.7rem 1rem;
    border: 1px solid #0b3446;
    color: #0b3446;
    font-size: 0.92rem;
    font-weight: 700;
    text-decoration: none;
    transition: background 0.2s ease, color 0.2s ease;
}

.public-feature-tabs__panel-link:hover {
    background: #0b3446;
    color: #ffffff;
}

.public-section[id] {
    scroll-margin-top: calc(var(--public-site-header-height, 5.75rem) + 1.5rem);
}

.public-industry-grid {
    display: flex;
    flex-direction: column;
    gap: clamp(1.5rem, 3vw, 2.25rem);
    text-align: center;
}

.public-industry-grid__header {
    max-width: 72rem;
    margin-inline: auto;
}

.public-industry-grid__header .public-kicker {
    margin-bottom: 1rem;
}

.public-industry-grid__title {
    margin: 0;
    color: #083a5c;
    font-family: var(--page-font-heading, var(--front-font-heading));
    font-size: clamp(2.5rem, 2rem + 1.55vw, 4.1rem);
    line-height: 0.98;
    letter-spacing: -0.04em;
}

.public-industry-grid__body {
    margin-top: 1rem;
    color: #4b5563;
    font-size: 1rem;
    line-height: 1.65;
}

.public-industry-grid__body :deep(p),
.public-industry-grid__body :deep(div) {
    margin: 0 0 1rem;
}

.public-industry-grid__body :deep(p:last-child),
.public-industry-grid__body :deep(div:last-child) {
    margin-bottom: 0;
}

.public-industry-grid__cards {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.85rem;
}

.public-industry-grid__card {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: flex-start;
    gap: 0.8rem;
    min-height: 6.1rem;
    padding: 1rem 1rem 0.95rem;
    border-radius: 0.45rem;
    border: 1px solid rgba(8, 58, 92, 0.12);
    background: transparent;
    color: #083a5c;
    text-decoration: none;
    text-align: left;
    box-shadow: none;
    transition: border-color 0.2s ease, color 0.2s ease;
}

.public-industry-grid__card:hover,
.public-industry-grid__card:focus-visible {
    border-color: rgba(8, 58, 92, 0.22);
    outline: none;
}

.public-industry-grid__icon {
    display: inline-flex;
    width: 1.35rem;
    height: 1.35rem;
    color: #083a5c;
    transition: color 0.2s ease;
}

.public-industry-grid__icon :deep(svg) {
    width: 100%;
    height: 100%;
}

.public-industry-grid__label {
    color: #083a5c;
    font-family: var(--page-font-heading, var(--front-font-heading));
    font-size: 1.05rem;
    font-weight: 700;
    line-height: 1.2;
    letter-spacing: -0.02em;
    transition: color 0.2s ease;
}

.public-industry-grid__card:hover .public-industry-grid__icon,
.public-industry-grid__card:focus-visible .public-industry-grid__icon,
.public-industry-grid__card:hover .public-industry-grid__label,
.public-industry-grid__card:focus-visible .public-industry-grid__label {
    color: var(--page-primary, #16a34a);
}

.public-industry-grid__footer {
    display: flex;
    justify-content: center;
}

.public-industry-grid__cta {
    display: inline-flex;
    align-items: center;
    color: #083a5c;
    font-family: var(--page-font-heading, var(--front-font-heading));
    font-size: 1.25rem;
    text-decoration: none;
}

.public-industry-grid__cta::after {
    content: '->';
    margin-left: 0.45rem;
    font-size: 0.9em;
}

.public-industry-grid__cta:hover {
    color: var(--page-primary, #16a34a);
}

.public-story-grid-shell {
    display: block;
}

.public-story-grid {
    display: flex;
    flex-direction: column;
    gap: clamp(2rem, 4vw, 3rem);
}

.public-story-grid__header {
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
    max-width: 56rem;
    margin-inline: auto;
}

.public-story-grid__header.text-left {
    align-items: flex-start;
    margin-inline: 0;
}

.public-story-grid__header.text-right {
    align-items: flex-end;
    margin-inline: auto 0;
}

.public-story-grid__header.text-center {
    align-items: center;
}

.public-story-grid__title {
    margin: 0;
    color: #083a5c;
    font-family: var(--page-font-heading, var(--front-font-heading));
    font-size: clamp(2rem, 1.55rem + 1.2vw, 3.25rem);
    line-height: 1.03;
    letter-spacing: -0.045em;
}

.public-story-grid__body {
    max-width: 46rem;
    font-size: 1.02rem;
    line-height: 1.75;
}

.public-story-grid__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
}

.public-story-grid__header.text-center .public-story-grid__actions {
    justify-content: center;
}

.public-story-grid__header.text-right .public-story-grid__actions {
    justify-content: flex-end;
}

.public-story-grid__cards {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.75rem;
}

.public-story-grid__card {
    display: grid;
    gap: 1.25rem;
    min-width: 0;
    align-content: start;
}

.public-story-grid__visual {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    min-height: clamp(15rem, 30vw, 18.75rem);
    border-radius: var(--page-radius, 4px);
    border: 1px solid rgba(8, 58, 92, 0.08);
    background:
        radial-gradient(circle at top left, rgba(255, 255, 255, 0.96), rgba(255, 255, 255, 0) 44%),
        linear-gradient(180deg, rgba(255, 251, 245, 0.96) 0%, rgba(243, 236, 225, 0.92) 100%);
    box-shadow: 0 28px 68px -50px rgba(15, 23, 42, 0.38);
}

.public-story-grid__visual::before {
    content: '';
    position: absolute;
    inset: auto 14% -1rem 14%;
    height: 1.9rem;
    border-radius: 999px;
    background: rgba(8, 58, 92, 0.16);
    filter: blur(18px);
    opacity: 0.28;
}

.public-story-grid__card:nth-child(2) .public-story-grid__visual {
    background:
        radial-gradient(circle at top left, rgba(255, 255, 255, 0.96), rgba(255, 255, 255, 0) 44%),
        linear-gradient(180deg, rgba(249, 250, 251, 0.96) 0%, rgba(231, 240, 243, 0.9) 100%);
}

.public-story-grid__card:nth-child(3) .public-story-grid__visual {
    background:
        radial-gradient(circle at top left, rgba(255, 255, 255, 0.96), rgba(255, 255, 255, 0) 44%),
        linear-gradient(180deg, rgba(255, 248, 242, 0.96) 0%, rgba(244, 231, 219, 0.92) 100%);
}

.public-story-grid__visual--empty {
    background:
        radial-gradient(circle at top left, rgba(255, 255, 255, 0.72), rgba(255, 255, 255, 0) 42%),
        linear-gradient(135deg, rgba(220, 252, 231, 0.7), rgba(226, 232, 240, 0.85));
}

.public-story-grid__image {
    position: absolute;
    inset: 0;
    z-index: 1;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center;
    border-radius: inherit;
}

.public-story-grid__copy {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    width: 100%;
    align-items: flex-start;
    text-align: left;
}

.public-story-grid__copy.text-center {
    align-items: center;
    text-align: center;
}

.public-story-grid__copy.text-right {
    align-items: flex-end;
    text-align: right;
}

.public-story-grid__card-title {
    margin: 0;
    color: #083a5c;
    font-family: var(--page-font-heading, var(--front-font-heading));
    font-size: clamp(1.45rem, 1.2rem + 0.45vw, 1.85rem);
    line-height: 1.08;
    letter-spacing: -0.03em;
}

.public-story-grid__card-body {
    max-width: 22rem;
    font-size: 1rem;
    line-height: 1.72;
}

.public-story-grid__card-body :deep(p),
.public-story-grid__card-body :deep(div) {
    margin: 0;
}

.public-duo-grid {
    display: grid;
    grid-template-columns: 1fr;
    overflow: hidden;
    border-radius: 0;
    box-shadow: none;
}

.public-duo-media {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    min-height: clamp(18rem, 45vw, 34rem);
    background: linear-gradient(135deg, rgba(148, 163, 184, 0.18), rgba(226, 232, 240, 0.75));
}

.public-duo-media--empty {
    background:
        radial-gradient(circle at top left, rgba(255, 255, 255, 0.6), rgba(255, 255, 255, 0) 35%),
        linear-gradient(135deg, var(--page-primary-soft, #dcfce7), rgba(148, 163, 184, 0.26));
}

.public-duo-media__image {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center;
    border-radius: inherit;
}

.public-duo-panel {
    display: flex;
    align-items: center;
    min-height: 100%;
    padding:
        clamp(2rem, 5vw, 4rem)
        max(var(--public-shell-gutter), calc((100vw - min(var(--public-shell-width), 100vw)) / 2 + var(--public-shell-gutter)))
        clamp(2rem, 5vw, 4rem)
        clamp(1.5rem, 4vw, 3rem);
    background: var(--page-surface, #ffffff);
}

.public-duo-panel--image-right {
    padding:
        clamp(2rem, 5vw, 4rem)
        clamp(1.5rem, 4vw, 3rem)
        clamp(2rem, 5vw, 4rem)
        max(var(--public-shell-gutter), calc((100vw - min(var(--public-shell-width), 100vw)) / 2 + var(--public-shell-gutter)));
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
    font-family: var(--page-font-heading, var(--front-font-heading));
    font-size: clamp(2.125rem, 1.75rem + 1vw, 2.625rem);
    line-height: 1.05;
    letter-spacing: -0.04em;
}

.public-duo-body {
    margin-bottom: 1rem;
    color: #23282b;
    font-family: var(--page-font-body, var(--front-font-body));
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

.public-showcase-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: clamp(2rem, 5vw, 4rem);
    align-items: center;
}

.public-showcase-copy-inner {
    width: min(100%, 40rem);
}

.public-showcase-title {
    margin: 0;
    color: var(--showcase-copy-text, var(--page-text, #0f172a));
    font-family: var(--page-font-heading, var(--front-font-heading));
    font-size: clamp(2.45rem, 2rem + 1.6vw, 4.6rem);
    line-height: 0.98;
    letter-spacing: -0.055em;
}

.public-showcase-shell__copy .public-kicker {
    margin-bottom: 1rem;
    padding: 0;
    border-radius: 0;
    background: none;
    color: var(--showcase-copy-kicker-text, var(--page-primary, #16a34a));
}

.public-showcase-badge {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.75rem;
    width: fit-content;
    max-width: 100%;
    margin-bottom: 1.5rem;
    padding: 0;
    border-radius: 0;
    background: none;
    color: var(--showcase-copy-text, var(--page-text, #0f172a));
    box-shadow: none;
}

.public-showcase-badge__label {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--showcase-copy-muted, var(--page-muted, #64748b));
}

.public-showcase-badge__value {
    font-size: 1rem;
    font-weight: 700;
    color: var(--showcase-copy-text, var(--page-text, #0f172a));
}

.public-showcase-badge__note {
    flex-basis: 100%;
    font-size: 0.92rem;
    line-height: 1.5;
    color: var(--showcase-copy-muted, var(--page-muted, #64748b));
}

.public-showcase-body {
    margin-top: 1.75rem;
    max-width: 34rem;
    color: var(--showcase-copy-muted, var(--page-muted, #64748b));
    font-size: clamp(1rem, 0.95rem + 0.3vw, 1.28rem);
    line-height: 1.62;
}

.public-showcase-body :deep(p),
.public-showcase-body :deep(div) {
    margin: 0 0 1rem;
}

.public-showcase-body :deep(p:last-child),
.public-showcase-body :deep(div:last-child) {
    margin-bottom: 0;
}

.public-showcase-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.875rem;
    margin-top: 2.25rem;
}

.public-showcase-actions .public-button,
.public-showcase-actions .public-inline-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.95rem 1.4rem;
    border: 1px solid var(--showcase-copy-soft-border, rgba(255, 255, 255, 0.16));
    border-radius: max(0px, calc(var(--page-radius, 4px) - 1px));
    background: var(--showcase-copy-soft, rgba(255, 255, 255, 0.08));
    box-shadow: none;
    color: var(--showcase-copy-text, var(--page-text, #0f172a));
    font-size: 0.96rem;
    font-weight: 700;
    line-height: 1;
    text-decoration: none;
}

.public-showcase-actions .public-button:hover,
.public-showcase-actions .public-button:focus-visible,
.public-showcase-actions .public-inline-link:hover,
.public-showcase-actions .public-inline-link:focus-visible {
    background: color-mix(in srgb, var(--showcase-copy-soft, rgba(255, 255, 255, 0.08)) 82%, white 18%);
    border-color: color-mix(in srgb, var(--showcase-copy-soft-border, rgba(255, 255, 255, 0.16)) 78%, white 22%);
    color: var(--showcase-copy-text, var(--page-text, #0f172a));
    filter: none;
    text-decoration: none;
}

.public-showcase-actions .public-button:active,
.public-showcase-actions .public-inline-link:active {
    transform: none;
}

.public-showcase-actions .public-button--secondary {
    color: var(--showcase-copy-text, var(--page-text, #0f172a));
}

.public-showcase-actions .public-button--secondary:hover,
.public-showcase-actions .public-button--secondary:focus-visible {
    color: var(--showcase-copy-text, var(--page-text, #0f172a));
}

.public-showcase-actions .public-inline-link {
    color: var(--showcase-copy-text, var(--page-text, #0f172a));
}

.public-showcase-actions .public-inline-link--muted {
    color: var(--showcase-copy-text, var(--page-text, #0f172a));
}

.public-showcase-grid--no-media .public-showcase-copy-inner {
    width: min(100%, 50rem);
}

.public-showcase-grid--no-media .public-showcase-body {
    max-width: 48rem;
}

.public-showcase-stage {
    position: relative;
    width: min(100%, 42rem);
    margin-inline: auto;
    padding: clamp(1rem, 2vw, 1.5rem) 0 clamp(2rem, 4vw, 3.75rem);
}

.public-showcase-frame {
    position: relative;
    overflow: hidden;
    border-radius: var(--page-radius, 4px);
    border: 2px solid rgba(255, 255, 255, 0.18);
    background:
        linear-gradient(180deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0.04)),
        rgba(15, 23, 42, 0.46);
    box-shadow:
        0 36px 70px -36px rgba(0, 0, 0, 0.7),
        0 24px 36px -24px rgba(15, 23, 42, 0.65);
}

.public-showcase-frame--empty .public-showcase-screen {
    background:
        radial-gradient(circle at top left, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0) 26%),
        linear-gradient(135deg, rgba(250, 204, 21, 0.28), rgba(59, 130, 246, 0.22));
}

.public-showcase-browser {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.9rem 1rem;
    background: rgba(255, 255, 255, 0.08);
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.public-showcase-browser-dot {
    width: 0.7rem;
    height: 0.7rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.4);
}

.public-showcase-browser-pill {
    display: inline-flex;
    align-items: center;
    min-width: 0;
    margin-left: 0.35rem;
    padding: 0.3rem 0.65rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.14);
    color: rgba(248, 250, 252, 0.9);
    font-size: 0.72rem;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.public-showcase-screen {
    aspect-ratio: 1.28 / 1;
    overflow: hidden;
    border-radius: inherit;
    background: rgba(15, 23, 42, 0.18);
}

.public-showcase-screen img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: inherit;
}

.public-showcase-overlay {
    position: absolute;
    left: 50%;
    top: 52%;
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1.15rem;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.68);
    color: #f8fafc;
    font-size: 0.98rem;
    font-weight: 600;
    backdrop-filter: blur(10px);
    transform: translate(-50%, -50%);
    box-shadow: 0 16px 36px -22px rgba(15, 23, 42, 0.75);
}

.public-showcase-overlay::before {
    content: '>';
    display: inline-grid;
    place-items: center;
    width: 2rem;
    height: 2rem;
    border-radius: 999px;
    background:
        linear-gradient(135deg, rgba(255, 255, 255, 0.28), rgba(255, 255, 255, 0.08)),
        rgba(255, 255, 255, 0.14);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.16);
    font-size: 0.78rem;
    line-height: 1;
}

.public-showcase-floating {
    position: absolute;
    right: 0;
    bottom: 0;
    width: clamp(9rem, 26vw, 12.5rem);
    overflow: hidden;
    border-radius: var(--page-radius, 4px);
    border: 4px solid rgba(255, 255, 255, 0.92);
    background: #ffffff;
    box-shadow: 0 26px 42px -26px rgba(0, 0, 0, 0.75);
}

.public-showcase-floating img {
    display: block;
    width: 100%;
    aspect-ratio: 9 / 18;
    object-fit: cover;
    border-radius: inherit;
}

.public-showcase-grid {
    width: 100%;
}

.public-showcase-shell {
    --showcase-divider-size: clamp(4rem, 9vw, 6.75rem);
    --showcase-divider-overlap: calc(var(--showcase-divider-size) * 0.78);
    --showcase-shell-edge-offset: max(var(--public-shell-gutter), calc((100vw - var(--public-shell-width)) / 2 + var(--public-shell-gutter)));
    position: relative;
    display: grid;
    min-height: clamp(22rem, 40vw, 32rem);
    overflow: hidden;
    border-radius: var(--page-radius, 4px);
    background: transparent;
    box-shadow: none;
    isolation: isolate;
}

.public-showcase-shell__copy,
.public-showcase-shell__media {
    min-width: 0;
}

.public-showcase-shell__copy {
    position: relative;
    display: flex;
    align-items: center;
    min-height: inherit;
    padding: clamp(2rem, 5vw, 4rem);
    overflow: visible;
}

.public-showcase-shell__copy::before {
    display: none;
}

.public-showcase-shell__copy::after {
    display: none;
}

.public-showcase-shell--media-left .public-showcase-shell__copy::after {
    left: calc(var(--showcase-divider-size) * -0.52);
    right: auto;
}

.public-showcase-copy-inner {
    position: relative;
    z-index: 2;
    width: min(100%, 40rem);
}

.public-showcase-copy-inner.text-center {
    margin-inline: auto;
}

.public-showcase-copy-inner.text-right {
    margin-left: auto;
}

.public-showcase-shell--media-left .public-showcase-copy-inner {
    margin-left: auto;
}

.public-showcase-shell--no-media .public-showcase-shell__copy::after {
    display: none;
}

.public-showcase-shell__media {
    position: relative;
    min-height: clamp(22rem, 40vw, 32rem);
    z-index: 1;
}

.public-showcase-visual {
    position: relative;
    width: 100%;
    height: 100%;
    overflow: hidden;
    background: transparent;
}

.public-showcase-visual__image {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center;
}

.public-showcase-visual__veil {
    display: none;
}

.public-showcase-media-tag {
    position: absolute;
    top: 1rem;
    left: 1rem;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    padding: 0;
    border-radius: 0;
    background: none;
    color: var(--showcase-media-tag-text, #ffffff);
    font-size: 0.82rem;
    font-weight: 800;
    text-decoration: none;
    box-shadow: none;
}

.public-showcase-floating {
    z-index: 2;
}

.public-showcase-shell--media-left .public-showcase-floating {
    left: 0;
    right: auto;
}

.public-showcase-floating__image {
    display: block;
    width: 100%;
    aspect-ratio: 9 / 18;
    object-fit: cover;
    object-position: center;
    border-radius: inherit;
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

.public-media-card--visual {
    padding: 0;
    overflow: hidden;
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
}

.public-media-card__image {
    display: block;
    width: 100%;
    height: auto;
    object-fit: cover;
    object-position: center;
    border-radius: inherit;
}

.public-contact-map-card__image {
    display: block;
    width: 100%;
    aspect-ratio: 1 / 1;
    object-fit: cover;
    object-position: center;
    border-radius: inherit;
}

.public-contact-aside {
    min-width: 0;
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

@media (min-width: 1024px) {
    .public-industry-grid__cards {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .public-story-grid__cards {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 2rem;
    }

    .public-contact-grid {
        grid-template-columns: minmax(0, 0.9fr) minmax(300px, 1fr) minmax(0, 0.95fr);
        gap: 2.5rem;
    }

    .public-testimonial-card {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        min-height: 29.5rem;
    }

    .public-testimonial-card--image-left .public-testimonial-media {
        order: -1;
    }

    .public-feature-pairs__row {
        grid-template-columns: minmax(0, 1.02fr) minmax(0, 0.88fr);
    }

    .public-feature-pairs__row--reverse {
        grid-template-columns: minmax(0, 0.88fr) minmax(0, 1.02fr);
    }

    .public-testimonial-grid__cards {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .public-showcase-shell {
        grid-template-columns: minmax(0, 1.02fr) minmax(0, 0.98fr);
    }

    .public-showcase-shell--media-right .public-showcase-shell__copy {
        margin-right: calc(var(--showcase-divider-overlap) * -1);
        padding-left: var(--showcase-shell-edge-offset);
        padding-right: calc(clamp(2rem, 5vw, 4rem) + var(--showcase-divider-overlap));
    }

    .public-showcase-shell--media-left .public-showcase-shell__copy {
        margin-left: calc(var(--showcase-divider-overlap) * -1);
        padding-right: var(--showcase-shell-edge-offset);
        padding-left: calc(clamp(2rem, 5vw, 4rem) + var(--showcase-divider-overlap));
    }

    .public-showcase-shell--media-left .public-showcase-shell__media {
        order: -1;
    }

    .public-showcase-shell--media-right.public-showcase-shell--divider-diagonal .public-showcase-shell__media {
        clip-path: polygon(12% 0, 100% 0, 100% 100%, 0 100%);
    }

    .public-showcase-shell--media-left.public-showcase-shell--divider-diagonal .public-showcase-shell__media {
        clip-path: polygon(0 0, 100% 0, 88% 100%, 0 100%);
    }

    .public-showcase-shell--media-right.public-showcase-shell--divider-notch .public-showcase-shell__media {
        clip-path: polygon(12% 0, 100% 0, 100% 100%, 12% 100%, 0 50%);
    }

    .public-showcase-shell--media-left.public-showcase-shell--divider-notch .public-showcase-shell__media {
        clip-path: polygon(0 0, 100% 0, 100% 50%, 88% 100%, 0 100%);
    }

    .public-showcase-shell--media-right.public-showcase-shell--divider-round .public-showcase-shell__media,
    .public-showcase-shell--media-right.public-showcase-shell--divider-curve .public-showcase-shell__media {
        -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100' preserveAspectRatio='none'%3E%3Cpath fill='white' d='M18 0H100V100H18Q2 50 18 0Z'/%3E%3C/svg%3E");
        mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100' preserveAspectRatio='none'%3E%3Cpath fill='white' d='M18 0H100V100H18Q2 50 18 0Z'/%3E%3C/svg%3E");
        -webkit-mask-repeat: no-repeat;
        mask-repeat: no-repeat;
        -webkit-mask-position: center;
        mask-position: center;
        -webkit-mask-size: 100% 100%;
        mask-size: 100% 100%;
    }

    .public-showcase-shell--media-left.public-showcase-shell--divider-round .public-showcase-shell__media,
    .public-showcase-shell--media-left.public-showcase-shell--divider-curve .public-showcase-shell__media {
        -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100' preserveAspectRatio='none'%3E%3Cpath fill='white' d='M0 0H82Q98 50 82 100H0V0Z'/%3E%3C/svg%3E");
        mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100' preserveAspectRatio='none'%3E%3Cpath fill='white' d='M0 0H82Q98 50 82 100H0V0Z'/%3E%3C/svg%3E");
        -webkit-mask-repeat: no-repeat;
        mask-repeat: no-repeat;
        -webkit-mask-position: center;
        mask-position: center;
        -webkit-mask-size: 100% 100%;
        mask-size: 100% 100%;
    }

    .public-showcase-shell--media-right.public-showcase-shell--divider-glow .public-showcase-shell__media::before,
    .public-showcase-shell--media-left.public-showcase-shell--divider-glow .public-showcase-shell__media::before {
        content: '';
        position: absolute;
        top: 0;
        bottom: 0;
        width: clamp(0.9rem, 1.9vw, 1.4rem);
        pointer-events: none;
        z-index: 3;
        filter: blur(10px);
        opacity: 0.92;
    }

    .public-showcase-shell--media-right.public-showcase-shell--divider-glow .public-showcase-shell__media::before {
        left: 0;
        background: linear-gradient(90deg, rgba(255, 255, 255, 0.86), rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0));
    }

    .public-showcase-shell--media-left.public-showcase-shell--divider-glow .public-showcase-shell__media::before {
        right: 0;
        background: linear-gradient(270deg, rgba(255, 255, 255, 0.86), rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0));
    }

    .public-showcase-grid--no-media .public-showcase-shell {
        grid-template-columns: minmax(0, 1fr);
    }

    .public-feature-tabs__grid {
        grid-template-columns: minmax(280px, 0.8fr) minmax(0, 1.2fr);
        gap: 2rem;
    }

    .public-duo-grid {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }

    .public-duo-media--image-right {
        order: 2;
    }
}

@media (min-width: 640px) and (max-width: 1023px) {
    .public-showcase-shell__copy::after {
        display: none;
    }

    .public-showcase-shell--media-right .public-showcase-shell__copy,
    .public-showcase-shell--media-left .public-showcase-shell__copy {
        margin-inline: 0;
        padding-inline: clamp(2rem, 5vw, 4rem);
    }

    .public-industry-grid__cards {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .public-story-grid__cards {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .public-testimonial-grid__cards {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 639px) {
    .public-showcase-shell {
        min-height: 20rem;
    }

    .public-showcase-shell__copy {
        margin-inline: 0;
        padding: 1.6rem;
    }

    .public-showcase-shell__copy::after {
        display: none;
    }

    .public-showcase-stage {
        padding-bottom: 1.5rem;
    }

    .public-showcase-overlay {
        width: max-content;
        max-width: calc(100% - 2rem);
        padding-inline: 0.95rem;
        font-size: 0.88rem;
    }

    .public-showcase-floating {
        width: 7.6rem;
    }

}
</style>
