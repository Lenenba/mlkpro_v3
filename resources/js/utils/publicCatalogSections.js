import {
    buildShowcaseFeatureTabsCopy,
    buildShowcasePrimaryCtaCopy,
    buildShowcasePublicSectionsCopy,
    buildShowcaseSecondaryCtaCopy,
    buildStoreFeatureTabsCopy,
    buildStoreFulfillmentSummaryCopy,
    buildStorePublicSectionsCopy,
} from './publicCatalogCopy';

const normalizeLocale = (locale = 'fr') => (
    String(locale || 'fr').toLowerCase().startsWith('fr')
        ? 'fr'
        : (String(locale || 'fr').toLowerCase().startsWith('es') ? 'es' : 'en')
);

const toCount = (value) => {
    const parsed = Number(value || 0);
    return Number.isFinite(parsed) ? parsed : 0;
};

const firstNonEmpty = (...values) => (
    values
        .map((value) => String(value || '').trim())
        .find((value) => value.length > 0) || ''
);

const countLabel = (count, locale, singular, plural) => {
    const safeCount = toCount(count);
    const label = safeCount === 1 ? singular : plural;
    return `${safeCount} ${label}`;
};

const normalizeImageCandidates = (...groups) => (
    groups
        .flatMap((group) => (Array.isArray(group) ? group : [group]))
        .map((value) => String(value || '').trim())
        .filter((value) => value.length > 0)
);

const createUniqueImagePicker = ({ excluded = [] } = {}) => {
    const used = new Set(normalizeImageCandidates(excluded));

    return (...candidates) => {
        const normalized = normalizeImageCandidates(candidates);
        const nextImage = normalized.find((candidate) => !used.has(candidate)) || normalized[0] || '';

        if (nextImage) {
            used.add(nextImage);
        }

        return nextImage;
    };
};

const storeStock = {
    hero: '/images/landing/stock/store-worker.jpg',
    inventory: '/images/landing/stock/store-boxes.jpg',
    checkout: '/images/landing/stock/store-payment.jpg',
    warehouse: '/images/landing/stock/warehouse-worker.jpg',
    payments: '/images/landing/stock/payments-terminal.jpg',
    desk: '/images/landing/stock/desk-phone-laptop.jpg',
    catalog: '/images/landing/stock/collab-laptop-desk.jpg',
    planning: '/images/landing/stock/meeting-room-laptops.jpg',
    tablet: '/images/landing/stock/hero-tablet.jpg',
};

const servicesStock = {
    hero: '/images/landing/stock/service-team.jpg',
    planning: '/images/landing/stock/service-tablet.jpg',
    install: '/images/landing/stock/service-install.jpg',
    workflow: '/images/landing/stock/workflow-plan.jpg',
    field: '/images/landing/stock/field-checklist.jpg',
    office: '/images/landing/stock/team-laptop-window.jpg',
    meeting: '/images/landing/stock/meeting-room-laptops.jpg',
    desk: '/images/landing/stock/marketing-desk.jpg',
    tabletHero: '/images/landing/stock/hero-tablet.jpg',
    collab: '/images/landing/stock/collab-laptop-desk.jpg',
};

export const publicCatalogStockImages = {
    store: {
        ...storeStock,
        heroSlides: [storeStock.hero, storeStock.catalog, storeStock.payments],
    },
    services: {
        ...servicesStock,
        heroSlides: [servicesStock.hero, servicesStock.install, servicesStock.office],
    },
};

export const buildPublicCatalogTheme = ({ accent, variant = 'store' } = {}) => {
    const isStore = variant === 'store';
    const primaryColor = String(accent || '').trim() || (isStore ? '#15803d' : '#b45309');

    return {
        primary_color: primaryColor,
        primary_soft_color: isStore ? '#dcfce7' : '#fef3c7',
        primary_contrast_color: '#ffffff',
        background_color: '#ffffff',
        background_alt_color: isStore ? '#f5fdf7' : '#fffaf1',
        surface_color: '#ffffff',
        text_color: '#0f172a',
        muted_color: '#475569',
        border_color: '#dbe4ee',
        radius: 'xl',
        shadow: 'soft',
        button_style: 'solid',
    };
};

export const buildStorePublicSections = ({
    locale,
    companyName,
    heroProduct,
    heroImages = [],
    categories,
    products,
    bestSellers,
    promotions,
    newArrivals,
    fulfillment,
    catalogHref = '#catalog',
    categoriesHref = '#categories',
    bestSellersHref = '#best-sellers',
} = {}) => {
    const resolvedLocale = normalizeLocale(locale);
    const productCount = toCount(products?.length);
    const categoryCount = toCount(categories?.length);
    const bestSellerCount = toCount(bestSellers?.length);
    const promoCount = toCount(promotions?.length);
    const newArrivalCount = toCount(newArrivals?.length);
    const featuredName = firstNonEmpty(heroProduct?.name);
    const featuredCategory = firstNonEmpty(heroProduct?.category_name);
    const featuredImage = firstNonEmpty(heroProduct?.image_url, storeStock.hero);
    const fulfillmentSummary = buildStoreFulfillmentSummaryCopy({ locale: resolvedLocale, fulfillment });
    const pickStoreImage = createUniqueImagePicker({
        excluded: [...normalizeImageCandidates(heroImages), featuredImage],
    });
    const storeVisuals = {
        intro: pickStoreImage(storeStock.warehouse, storeStock.catalog, storeStock.planning, storeStock.desk),
        introAside: pickStoreImage(storeStock.payments, storeStock.desk, storeStock.tablet, storeStock.catalog),
        flowDiscover: pickStoreImage(storeStock.catalog, storeStock.warehouse, storeStock.tablet, storeStock.planning),
        flowCompare: pickStoreImage(storeStock.planning, storeStock.warehouse, storeStock.desk, storeStock.catalog),
        flowCheckout: pickStoreImage(storeStock.payments, storeStock.tablet, storeStock.desk, storeStock.catalog),
        flowFulfill: pickStoreImage(storeStock.warehouse, storeStock.planning, storeStock.catalog, storeStock.desk),
        storyConfidence: pickStoreImage(storeStock.tablet, storeStock.catalog, storeStock.desk, storeStock.planning),
        storyPromos: pickStoreImage(storeStock.payments, storeStock.desk, storeStock.tablet, storeStock.catalog),
        storyFulfillment: pickStoreImage(storeStock.warehouse, storeStock.planning, storeStock.catalog, storeStock.desk),
        showcase: pickStoreImage(storeStock.catalog, storeStock.warehouse, storeStock.tablet, storeStock.planning),
        showcaseAside: pickStoreImage(storeStock.desk, storeStock.payments, storeStock.tablet, storeStock.catalog),
    };
    const featureTabs = buildStoreFeatureTabsCopy({
        locale: resolvedLocale,
        productCount,
        categoryCount,
        bestSellerCount,
        promoCount,
        newArrivalCount,
        fulfillmentSummary,
        catalogHref,
        images: {
            discover: storeVisuals.flowDiscover,
            compare: storeVisuals.flowCompare,
            checkout: storeVisuals.flowCheckout,
            fulfill: storeVisuals.flowFulfill,
        },
        countLabel,
    });

    return buildStorePublicSectionsCopy({
        locale: resolvedLocale,
        companyName,
        featuredName,
        featuredCategory,
        productCount,
        categoryCount,
        bestSellerCount,
        promoCount,
        fulfillmentSummary,
        catalogHref,
        categoriesHref,
        bestSellersHref,
        storeVisuals,
        featureTabs,
        countLabel,
    });
};

export const buildShowcasePublicSections = ({
    locale,
    companyName,
    companyLocation,
    heroService,
    heroImages = [],
    services,
    categories,
    requestUrl,
    phone,
    email,
    servicesHref = '#services',
} = {}) => {
    const resolvedLocale = normalizeLocale(locale);
    const serviceCount = toCount(services?.length);
    const categoryCount = toCount(categories?.length);
    const primaryCta = buildShowcasePrimaryCtaCopy({ locale: resolvedLocale, requestUrl, phone, email, servicesHref });
    const secondaryCta = buildShowcaseSecondaryCtaCopy({ locale: resolvedLocale, requestUrl, servicesHref });
    const featuredName = firstNonEmpty(heroService?.name);
    const featuredCategory = firstNonEmpty(heroService?.category_name);
    const featuredImage = firstNonEmpty(heroService?.image_url, servicesStock.hero);
    const locationLine = firstNonEmpty(companyLocation);
    const pickServiceImage = createUniqueImagePicker({
        excluded: [...normalizeImageCandidates(heroImages), featuredImage],
    });
    const serviceVisuals = {
        intro: pickServiceImage(servicesStock.office, servicesStock.meeting, servicesStock.collab, servicesStock.desk),
        introAside: pickServiceImage(servicesStock.field, servicesStock.workflow, servicesStock.tabletHero, servicesStock.collab),
        flowQualify: pickServiceImage(servicesStock.collab, servicesStock.office, servicesStock.meeting, servicesStock.desk),
        flowPlan: pickServiceImage(servicesStock.workflow, servicesStock.meeting, servicesStock.tabletHero, servicesStock.collab),
        flowDeliver: pickServiceImage(servicesStock.field, servicesStock.office, servicesStock.meeting, servicesStock.desk),
        flowFollow: pickServiceImage(servicesStock.tabletHero, servicesStock.desk, servicesStock.office, servicesStock.collab),
        storyFeatured: pickServiceImage(servicesStock.meeting, servicesStock.collab, servicesStock.office, servicesStock.desk),
        storyField: pickServiceImage(servicesStock.field, servicesStock.workflow, servicesStock.tabletHero, servicesStock.office),
        storyContact: pickServiceImage(servicesStock.desk, servicesStock.tabletHero, servicesStock.office, servicesStock.collab),
        showcase: pickServiceImage(servicesStock.office, servicesStock.collab, servicesStock.meeting, servicesStock.desk),
        showcaseAside: pickServiceImage(servicesStock.tabletHero, servicesStock.workflow, servicesStock.collab, servicesStock.desk),
        contact: pickServiceImage(servicesStock.office, servicesStock.meeting, servicesStock.collab, servicesStock.desk),
    };
    const featureTabs = buildShowcaseFeatureTabsCopy({
        locale: resolvedLocale,
        serviceCount,
        categoryCount,
        requestUrl,
        servicesHref,
        primaryCta,
        images: {
            qualify: serviceVisuals.flowQualify,
            plan: serviceVisuals.flowPlan,
            deliver: serviceVisuals.flowDeliver,
            follow: serviceVisuals.flowFollow,
        },
        countLabel,
    });

    return buildShowcasePublicSectionsCopy({
        locale: resolvedLocale,
        companyName,
        locationLine,
        featuredName,
        featuredCategory,
        serviceCount,
        categoryCount,
        requestUrl,
        phone,
        email,
        primaryCta,
        secondaryCta,
        servicesHref,
        serviceVisuals,
        featureTabs,
    });
};
