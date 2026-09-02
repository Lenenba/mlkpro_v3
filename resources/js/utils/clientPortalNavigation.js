const allows = (capabilities, domain, action) => Boolean(capabilities?.[domain]?.[action]);

export const resolveClientPortalMode = (capabilities = {}) => {
    const hasService = [
        ['reservations', 'view'],
        ['quotes', 'view'],
        ['works', 'view'],
        ['tasks', 'view'],
    ].some(([domain, action]) => allows(capabilities, domain, action));
    const hasProduct = allows(capabilities, 'orders', 'view');

    if (hasService && hasProduct) {
        return 'hybrid';
    }
    if (hasService) {
        return 'service';
    }
    if (hasProduct) {
        return 'product';
    }

    return 'minimal';
};

export const buildClientPortalNavigation = (capabilities = {}, mode = null) => {
    const resolvedMode = mode || resolveClientPortalMode(capabilities);
    const reservationsRoute = allows(capabilities, 'reservations', 'view')
        ? 'client.reservations.index'
        : 'client.reservations.book';
    const reservations = (allows(capabilities, 'reservations', 'view') || allows(capabilities, 'reservations', 'book'))
        ? {
            key: 'reservations',
            labelKey: 'nav.reservations',
            routeName: reservationsRoute,
            activePatterns: ['client.reservations.*'],
            tone: 'planning',
            icon: 'calendar',
        }
        : null;
    const orders = allows(capabilities, 'orders', 'view')
        ? {
            key: 'orders',
            labelKey: 'nav.orders',
            routeName: 'portal.orders.index',
            activePatterns: ['portal.orders.*'],
            tone: 'orders',
            icon: 'orders',
        }
        : null;

    const primaryItems = resolvedMode === 'product'
        ? [orders, reservations]
        : [reservations, orders];

    return [
        {
            key: 'dashboard',
            labelKey: 'nav.dashboard',
            routeName: 'dashboard',
            activePatterns: ['dashboard'],
            tone: 'dashboard',
            icon: 'dashboard',
        },
        ...primaryItems,
        allows(capabilities, 'invoices', 'history') ? {
            key: 'invoices',
            labelKey: 'nav.invoices',
            routeName: 'portal.invoices.index',
            activePatterns: ['portal.invoices.*'],
            tone: 'invoices',
            icon: 'invoices',
        } : null,
        allows(capabilities, 'packages', 'view') ? {
            key: 'packages',
            labelKey: 'nav.my_packages',
            routeName: 'portal.packages.index',
            activePatterns: ['portal.packages.*'],
            tone: 'products',
            icon: 'packages',
        } : null,
        allows(capabilities, 'loyalty', 'view') ? {
            key: 'loyalty',
            labelKey: 'nav.loyalty',
            routeName: 'portal.loyalty.index',
            activePatterns: ['portal.loyalty.*'],
            tone: 'loyalty',
            icon: 'loyalty',
        } : null,
    ].filter(Boolean);
};
