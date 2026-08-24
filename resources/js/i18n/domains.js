export const translationModules = Object.freeze([
    'account',
    'accounting',
    'actions',
    'alerts',
    'auth_pages',
    'client_dashboard',
    'client_loyalty',
    'client_orders',
    'client_packages',
    'cookies',
    'crm_manager_dashboard',
    'crm_next_actions',
    'crm_sales_inbox',
    'customer_index',
    'customers',
    'dashboard',
    'dashboard_products',
    'dashboard_tasks',
    'datatable',
    'expenses',
    'finance_approvals',
    'global_search',
    'invoices',
    'jobs',
    'language',
    'legal',
    'loyalty_module',
    'marketing',
    'mega_menu',
    'nav',
    'notifications_center',
    'notifications_panel',
    'onboarding',
    'offer_packages',
    'performance',
    'planning',
    'portal_order',
    'portal_shop',
    'presence',
    'pricing',
    'privacy',
    'products',
    'promotions',
    'public_footer',
    'public_invoice',
    'public_pages',
    'public_quote',
    'public_showcase',
    'public_store',
    'public_work',
    'quick_create',
    'quotes',
    'refund',
    'requests',
    'reservations',
    'sales',
    'service_requests',
    'services',
    'session',
    'shared_ui',
    'settings',
    'sidebar',
    'social',
    'super_admin',
    'support_portal',
    'tasks',
    'team',
    'terms',
    'tips_reports',
    'two_factor',
    'welcome',
    'work_proofs',
    'workspace_hub',
]);

const authenticatedShellDomains = [
    'account',
    'actions',
    'alerts',
    'cookies',
    'customers',
    'datatable',
    'dashboard',
    'global_search',
    'language',
    'nav',
    'notifications_panel',
    'products',
    'quick_create',
    'requests',
    'services',
    'session',
    'shared_ui',
    'sidebar',
    'settings',
    'welcome',
    'workspace_hub',
];

const authShellDomains = [
    'account',
    'alerts',
    'auth_pages',
    'cookies',
    'language',
    'session',
    'welcome',
];

const publicShellDomains = [
    'account',
    'alerts',
    'cookies',
    'language',
    'legal',
    'mega_menu',
    'pricing',
    'privacy',
    'public_footer',
    'public_pages',
    'public_showcase',
    'public_store',
    'refund',
    'session',
    'terms',
    'welcome',
];

const publicRootPages = new Set([
    'Pricing',
    'Privacy',
    'Refund',
    'Terms',
    'Welcome',
]);

const exactPageDomains = {
    Dashboard: ['dashboard'],
    DashboardAdmin: ['dashboard', 'dashboard_tasks'],
    DashboardClient: ['client_dashboard', 'dashboard', 'public_invoice', 'sales'],
    DashboardMember: ['dashboard', 'dashboard_tasks'],
    DashboardProductsClient: ['client_orders', 'portal_shop'],
    DashboardProductsOwner: ['client_orders', 'dashboard', 'dashboard_products'],
    DashboardProductsTeam: ['client_orders', 'dashboard', 'dashboard_products'],
    Pricing: ['legal', 'pricing', 'public_pages'],
    Privacy: ['legal', 'privacy', 'public_pages'],
    Refund: ['legal', 'public_pages', 'refund'],
    Terms: ['legal', 'public_pages', 'terms'],
    Welcome: ['welcome'],
    'Portal/InvoiceShow': ['invoices'],
    'Portal/Loyalty/Index': ['client_loyalty', 'datatable', 'loyalty_module'],
    'Portal/Packages/Index': ['client_packages'],
    'Portal/Products/OrderShow': ['client_orders', 'portal_order', 'portal_shop', 'public_invoice', 'sales'],
    'Portal/Products/Shop': ['client_orders', 'portal_shop'],
    'AiAssistant/Settings': ['settings'],
    'Customer/Index': ['customer_index', 'customers', 'marketing'],
    'Customer/Show': ['customers', 'dashboard', 'invoices', 'jobs', 'marketing', 'quotes', 'service_requests'],
    'Public/InvoicePay': ['public_invoice'],
    'Public/Page': ['public_pages'],
    'Public/QuoteAction': ['public_quote'],
    'Public/RequestForm': ['requests'],
    'Public/ReservationKiosk': ['reservations'],
    'Public/Showcase': ['public_showcase'],
    'Public/Store': ['public_store'],
    'Public/WorkAction': ['public_work'],
    'Public/WorkProofs': ['work_proofs'],
    'SuperAdmin/Announcements/Preview': ['dashboard', 'mega_menu', 'super_admin'],
    'Work/Proofs': ['jobs', 'work_proofs'],
};

const areaPageDomains = {
    Accounting: ['accounting', 'settings'],
    AiAssistant: [],
    Auth: ['auth_pages', 'two_factor'],
    Campaigns: ['marketing', 'social'],
    CRM: ['crm_manager_dashboard', 'crm_next_actions', 'crm_sales_inbox'],
    Customer: ['customers', 'marketing'],
    Demo: [],
    Errors: [],
    Expense: ['expenses', 'finance_approvals'],
    FinanceApprovals: ['finance_approvals'],
    Invoice: ['finance_approvals', 'invoices', 'public_invoice', 'sales'],
    Loyalty: ['datatable', 'loyalty_module'],
    Notifications: ['notifications_center'],
    Onboarding: ['onboarding', 'shared_ui', 'terms'],
    OfferPackages: ['offer_packages', 'products', 'quotes', 'sales', 'settings'],
    Orders: ['client_orders', 'portal_order', 'portal_shop', 'sales'],
    Performance: ['performance', 'planning'],
    Pipeline: ['crm_manager_dashboard', 'crm_next_actions', 'crm_sales_inbox'],
    PlanScan: ['dashboard'],
    Planning: ['planning'],
    Portal: ['client_loyalty', 'client_orders', 'client_packages', 'invoices', 'loyalty_module', 'portal_order', 'portal_shop', 'public_invoice', 'sales'],
    Presence: ['presence'],
    Product: ['products', 'social'],
    Profile: ['account', 'settings'],
    Promotions: ['promotions', 'social'],
    Public: ['public_invoice', 'public_quote', 'public_work', 'requests', 'reservations', 'work_proofs'],
    Quote: ['marketing', 'quotes'],
    Request: ['marketing', 'requests', 'tasks'],
    Reservation: ['planning', 'quotes', 'reservations', 'settings'],
    Sales: ['public_invoice', 'sales', 'settings'],
    Service: ['services', 'social'],
    ServiceRequests: ['service_requests'],
    Settings: ['dashboard', 'jobs', 'loyalty_module', 'marketing', 'planning', 'settings', 'tasks'],
    Social: ['social'],
    SuperAdmin: ['mega_menu', 'super_admin'],
    Support: ['settings', 'support_portal'],
    Task: ['tasks'],
    Team: ['datatable', 'performance', 'team'],
    Tips: ['datatable', 'tips_reports'],
    Work: ['jobs'],
    Workspace: ['workspace_hub'],
};

const hasOwn = (object, key) => Object.prototype.hasOwnProperty.call(object, key);

const uniqueDomains = (domains) => [...new Set(domains.filter((domain) => translationModules.includes(domain)))];

export const normalizePageComponent = (component) => String(component || '')
    .replace(/\\/g, '/')
    .replace(/^\/+|\/+$/g, '')
    .replace(/\.vue$/i, '');

const shellDomainsFor = (component) => {
    if (component.startsWith('Auth/') || component.startsWith('Onboarding/')) {
        return authShellDomains;
    }

    if (component.startsWith('Public/') || publicRootPages.has(component)) {
        return publicShellDomains;
    }

    return authenticatedShellDomains;
};

export const getDomainsForPage = (component) => {
    const normalizedComponent = normalizePageComponent(component);

    if (! normalizedComponent) {
        return [...translationModules];
    }

    if (hasOwn(exactPageDomains, normalizedComponent)) {
        return uniqueDomains([
            ...shellDomainsFor(normalizedComponent),
            ...exactPageDomains[normalizedComponent],
        ]);
    }

    const [area] = normalizedComponent.split('/');
    if (hasOwn(areaPageDomains, area)) {
        return uniqueDomains([
            ...shellDomainsFor(normalizedComponent),
            ...areaPageDomains[area],
        ]);
    }

    // Toute nouvelle page non décrite garde le catalogue complet. Cela évite une
    // clé absente en production et rend l’ajout de son domaine explicite au review.
    return [...translationModules];
};
