const stockImageVariantWidths = Object.freeze([640, 1280]);

export const publicStockImageVariants = Object.freeze({
    'beauty-treatment': { width: 1600, height: 2400 },
    'cleaning-team-office': { width: 1600, height: 2400 },
    'collab-laptop-desk': { width: 2600, height: 1463 },
    'desk-phone-laptop': { width: 2600, height: 1733 },
    'electrician-panel': { width: 1600, height: 2400 },
    'field-checklist': { width: 1800, height: 1200 },
    'hero-tablet': { width: 1800, height: 1200 },
    'hero-team': { width: 1800, height: 1197 },
    'hvac-maintenance': { width: 1600, height: 1064 },
    'marketing-desk': { width: 1800, height: 1200 },
    'meeting-room-laptops': { width: 2600, height: 1950 },
    'office-collaboration': { width: 1600, height: 1068 },
    'payments-terminal': { width: 1800, height: 1200 },
    'plumbing-pipe-repair': { width: 1600, height: 2844 },
    'restaurant-service': { width: 1600, height: 1067 },
    'salon-front-desk': { width: 1600, height: 1067 },
    'service-install': { width: 1800, height: 1200 },
    'service-tablet': { width: 1800, height: 1012 },
    'service-team': { width: 1800, height: 1200 },
    'store-boxes': { width: 1800, height: 2400 },
    'store-payment': { width: 1800, height: 1202 },
    'store-worker': { width: 1800, height: 1012 },
    'team-laptop-window': { width: 2600, height: 1733 },
    'warehouse-worker': { width: 2600, height: 1733 },
    'workflow-plan': { width: 1800, height: 1200 },
});

const STOCK_IMAGE_PATH = /^\/images\/landing\/stock\/([a-z0-9-]+)\.jpe?g(?:[?#].*)?$/i;

const sourceSetFor = (key, format) => stockImageVariantWidths
    .map((width) => `/images/landing/stock/optimized/${key}-${width}w.${format} ${width}w`)
    .join(', ');

export const resolvePublicStockImage = (source) => {
    const match = String(source || '').trim().match(STOCK_IMAGE_PATH);

    if (! match) {
        return null;
    }

    const key = match[1];
    const dimensions = publicStockImageVariants[key];

    if (! dimensions) {
        return null;
    }

    return {
        key,
        ...dimensions,
        avifSrcSet: sourceSetFor(key, 'avif'),
        webpSrcSet: sourceSetFor(key, 'webp'),
    };
};

export const publicStockImageWidths = stockImageVariantWidths;
