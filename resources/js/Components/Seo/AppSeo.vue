<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const page = usePage();
const { t, locale } = useI18n();

const INDEXABLE_COMPONENTS = new Set([
    'Welcome',
    'Pricing',
    'Terms',
    'Privacy',
    'Refund',
    'Public/Page',
    'Public/Showcase',
    'Public/Store',
]);

const trimValue = (value) => String(value || '').trim();

const stripHtml = (value) => trimValue(String(value || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' '));

const firstNonEmpty = (...values) => values.find((value) => trimValue(value) !== '') || '';

const clampText = (value, limit = 180) => {
    const text = trimValue(value);
    if (text.length <= limit) {
        return text;
    }

    const sliced = text.slice(0, limit + 1);
    const boundary = sliced.lastIndexOf(' ');

    return `${(boundary > 80 ? sliced.slice(0, boundary) : sliced.slice(0, limit)).trim()}...`;
};

const normalizeLocale = (value) => {
    const normalized = trimValue(value).toLowerCase();

    if (normalized.startsWith('fr')) return 'fr';
    if (normalized.startsWith('es')) return 'es';

    return 'en';
};

const absoluteUrl = (value, baseUrl) => {
    const input = trimValue(value);
    if (!input) {
        return '';
    }

    try {
        return new URL(input, baseUrl || undefined).toString();
    } catch (error) {
        return input;
    }
};

const resolveFirstArrayImage = (items) => {
    if (!Array.isArray(items)) {
        return '';
    }

    for (const item of items) {
        const candidate = typeof item === 'string' ? item : item?.image_url;
        if (trimValue(candidate) !== '') {
            return trimValue(candidate);
        }
    }

    return '';
};

const sections = computed(() => (
    Array.isArray(page.props.content?.sections) ? page.props.content.sections : []
));

const firstSectionText = computed(() => {
    for (const section of sections.value) {
        const candidate = stripHtml(firstNonEmpty(
            section?.body,
            section?.aside_body,
            section?.title,
            section?.aside_title,
        ));

        if (candidate) {
            return candidate;
        }
    }

    return '';
});

const firstSectionImage = computed(() => {
    for (const section of sections.value) {
        const candidate = trimValue(firstNonEmpty(section?.image_url, section?.aside_image_url));
        if (candidate) {
            return candidate;
        }
    }

    return '';
});

const componentName = computed(() => trimValue(page.component));
const currentLocale = computed(() => normalizeLocale(locale.value || page.props.locale || 'fr'));
const siteName = computed(() => trimValue(page.props.branding?.site_name) || 'Malikia Pro');
const siteUrl = computed(() => {
    const configured = trimValue(page.props.branding?.site_url);

    if (configured) {
        return configured;
    }

    if (typeof window !== 'undefined' && window.location?.origin) {
        return window.location.origin;
    }

    return '';
});

const currentUrl = computed(() => {
    if (typeof window !== 'undefined' && window.location?.href) {
        return window.location.href.split('#')[0];
    }

    return absoluteUrl(page.url || '/', siteUrl.value);
});

const defaultDescription = computed(() => clampText(firstNonEmpty(
    page.props.welcomeContent?.hero?.subtitle,
    t('welcome.hero.subtitle'),
    'Manage quotes, scheduling, jobs, and invoicing from one connected platform.',
)));

const companyName = computed(() => trimValue(
    page.props.company?.name
    || page.props.page?.title
));

const pricingAudience = computed(() => (
    trimValue(page.props.defaultAudience).toLowerCase() === 'solo' ? 'solo' : 'team'
));

const metaTitle = computed(() => {
    switch (componentName.value) {
    case 'Welcome':
        return trimValue(t('welcome.meta.title'));
    case 'Pricing':
        return trimValue(t('pricing.meta.title'));
    case 'Terms':
        return trimValue(t('terms.meta.title'));
    case 'Privacy':
        return trimValue(t('privacy.meta.title'));
    case 'Refund':
        return trimValue(t('refund.meta.title'));
    case 'Public/Page':
        return trimValue(firstNonEmpty(page.props.content?.page_title, page.props.page?.title));
    case 'Public/Showcase':
        return trimValue(t('public_showcase.title', { company: companyName.value || t('public_showcase.company_fallback') }));
    case 'Public/Store':
        return trimValue(t('public_store.title', { company: companyName.value || t('public_store.company_fallback') }));
    default:
        return siteName.value;
    }
});

const metaTitleWithBrand = computed(() => {
    const title = metaTitle.value;
    if (!title) {
        return siteName.value;
    }

    return title.toLowerCase().includes(siteName.value.toLowerCase())
        ? title
        : `${title} - ${siteName.value}`;
});

const metaDescription = computed(() => {
    switch (componentName.value) {
    case 'Welcome':
        return clampText(firstNonEmpty(page.props.welcomeContent?.hero?.subtitle, t('welcome.hero.subtitle'), defaultDescription.value));
    case 'Pricing':
        return clampText(firstNonEmpty(
            t(`pricing.hero.${pricingAudience.value}.subtitle`),
            defaultDescription.value,
        ));
    case 'Terms':
        return clampText(firstNonEmpty(
            t('terms.sections.scope.body'),
            defaultDescription.value,
        ));
    case 'Privacy':
        return clampText(firstNonEmpty(
            t('privacy.intro.summary'),
            defaultDescription.value,
        ));
    case 'Refund':
        return clampText(firstNonEmpty(
            t('refund.intro.summary'),
            defaultDescription.value,
        ));
    case 'Public/Page':
        return clampText(firstNonEmpty(
            stripHtml(page.props.content?.page_subtitle),
            firstSectionText.value,
            defaultDescription.value,
        ));
    case 'Public/Showcase':
        return clampText(firstNonEmpty(
            page.props.company?.description,
            t('public_showcase.subheadline'),
            defaultDescription.value,
        ));
    case 'Public/Store':
        return clampText(firstNonEmpty(
            page.props.company?.description,
            t('public_store.subtitle'),
            defaultDescription.value,
        ));
    default:
        return defaultDescription.value;
    }
});

const defaultImage = computed(() => absoluteUrl(
    firstNonEmpty(page.props.branding?.social_image_url, '/brand/social-card.png'),
    siteUrl.value,
));

const welcomeImage = computed(() => firstNonEmpty(
    resolveFirstArrayImage(page.props.welcomeContent?.hero?.hero_images),
    page.props.welcomeContent?.hero?.image_url,
));

const pageImage = computed(() => firstNonEmpty(
    page.props.content?.header?.background_image_url,
    firstSectionImage.value,
));

const showcaseImage = computed(() => firstNonEmpty(
    page.props.hero_service?.image_url,
    page.props.company?.logo_url,
));

const storeImage = computed(() => firstNonEmpty(
    page.props.hero_product?.image_url,
    page.props.company?.logo_url,
));

const metaImage = computed(() => {
    switch (componentName.value) {
    case 'Welcome':
        return absoluteUrl(firstNonEmpty(welcomeImage.value, defaultImage.value), siteUrl.value);
    case 'Public/Page':
        return absoluteUrl(firstNonEmpty(pageImage.value, defaultImage.value), siteUrl.value);
    case 'Public/Showcase':
        return absoluteUrl(firstNonEmpty(showcaseImage.value, defaultImage.value), siteUrl.value);
    case 'Public/Store':
        return absoluteUrl(firstNonEmpty(storeImage.value, defaultImage.value), siteUrl.value);
    default:
        return defaultImage.value;
    }
});

const metaImageAlt = computed(() => {
    switch (componentName.value) {
    case 'Public/Showcase':
    case 'Public/Store':
        return clampText(firstNonEmpty(companyName.value, metaTitle.value, siteName.value), 110);
    default:
        return clampText(firstNonEmpty(metaTitle.value, siteName.value), 110);
    }
});

const isIndexable = computed(() => INDEXABLE_COMPONENTS.has(componentName.value));
const robots = computed(() => (
    isIndexable.value ? 'index,follow,max-image-preview:large' : 'noindex,follow'
));

const ogLocale = computed(() => {
    switch (currentLocale.value) {
    case 'fr':
        return 'fr_CA';
    case 'es':
        return 'es_ES';
    default:
        return 'en_US';
    }
});

const structuredData = computed(() => {
    if (!isIndexable.value) {
        return [];
    }

    const organization = {
        '@context': 'https://schema.org',
        '@type': 'Organization',
        name: siteName.value,
        url: siteUrl.value || currentUrl.value,
        logo: absoluteUrl(firstNonEmpty(page.props.branding?.logo_icon_url, '/brand/bimi-logo.svg'), siteUrl.value),
        image: metaImage.value || defaultImage.value,
    };

    const website = {
        '@context': 'https://schema.org',
        '@type': 'WebSite',
        name: siteName.value,
        url: siteUrl.value || currentUrl.value,
    };

    const webpage = {
        '@context': 'https://schema.org',
        '@type': 'WebPage',
        name: metaTitleWithBrand.value,
        url: currentUrl.value,
        description: metaDescription.value,
        isPartOf: {
            '@type': 'WebSite',
            name: siteName.value,
            url: siteUrl.value || currentUrl.value,
        },
        about: {
            '@type': 'Organization',
            name: siteName.value,
            url: siteUrl.value || currentUrl.value,
        },
        primaryImageOfPage: metaImage.value || defaultImage.value,
    };

    return [organization, website, webpage].map((item) => JSON.stringify(item));
});
</script>

<template>
    <Head>
        <meta head-key="meta:description" name="description" :content="metaDescription">
        <meta head-key="meta:robots" name="robots" :content="robots">
        <meta head-key="meta:application-name" name="application-name" :content="siteName">
        <meta head-key="meta:apple-mobile-web-app-title" name="apple-mobile-web-app-title" :content="siteName">
        <meta head-key="og:type" property="og:type" content="website">
        <meta head-key="og:title" property="og:title" :content="metaTitleWithBrand">
        <meta head-key="og:description" property="og:description" :content="metaDescription">
        <meta head-key="og:url" property="og:url" :content="currentUrl">
        <meta head-key="og:site_name" property="og:site_name" :content="siteName">
        <meta head-key="og:locale" property="og:locale" :content="ogLocale">
        <meta head-key="og:image" property="og:image" :content="metaImage">
        <meta head-key="og:image:alt" property="og:image:alt" :content="metaImageAlt">
        <meta head-key="twitter:card" name="twitter:card" content="summary_large_image">
        <meta head-key="twitter:title" name="twitter:title" :content="metaTitleWithBrand">
        <meta head-key="twitter:description" name="twitter:description" :content="metaDescription">
        <meta head-key="twitter:image" name="twitter:image" :content="metaImage">
        <link v-if="isIndexable" head-key="link:canonical" rel="canonical" :href="currentUrl">
        <component
            :is="'script'"
            v-for="(schema, index) in structuredData"
            :key="`schema-${index}`"
            :head-key="`ldjson:${index}`"
            type="application/ld+json"
            v-text="schema"
        ></component>
    </Head>
</template>
