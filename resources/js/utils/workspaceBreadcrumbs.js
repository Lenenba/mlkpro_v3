import { buildWorkspaceHubCategories } from '@/utils/workspaceHub';

const moduleRoutePatterns = {
    customers: ['customer.*'],
    requests: ['request.*'],
    quotes: ['quote.index', 'customer.quote.*'],
    orders: ['orders.*'],
    sales: ['sales.*'],
    campaigns: ['campaigns.*', 'campaign-automations.*', 'campaign-runs.*'],
    loyalty: ['loyalty.*', 'settings.loyalty.*'],
    performance: ['performance.*'],
    jobs: ['jobs.index', 'work.*'],
    tasks: ['task.*'],
    reservations: ['reservation.*', 'settings.reservations.*'],
    planning: ['planning.*'],
    presence: ['presence.*'],
    team: ['team.*'],
    invoices: ['invoice.*'],
    expenses: ['expense.*'],
    accounting: ['accounting.*'],
    finance_approvals: ['finance-approvals.*'],
    tips_owner: ['payments.tips.*'],
    tips_member: ['my-earnings.tips.*'],
    categories: ['service.categories'],
    services: ['service.index', 'service.create', 'service.store', 'service.show', 'service.edit', 'service.update', 'service.destroy'],
    products: ['product.*'],
    plan_scans: ['plan-scans.*'],
    company_settings: ['settings.company.*'],
    billing: ['settings.billing.*'],
    profile: ['profile.edit'],
};

const routeMatches = (patterns = []) => patterns.some((pattern) => route().current(pattern));

export function resolveWorkspaceBreadcrumbContext({
    account = null,
    planningPendingCount = 0,
    pageComponent = null,
    pageProps = {},
} = {}) {
    const categories = buildWorkspaceHubCategories({
        account,
        planningPendingCount,
    });

    const currentCategory = pageComponent === 'Workspace/CategoryHub' && pageProps?.category
        ? (categories.find((category) => category.key === pageProps.category) || null)
        : (categories.find((category) => routeMatches(category.match || [])) || null);

    if (!currentCategory || pageComponent === 'Workspace/CategoryHub') {
        return {
            currentCategory,
            currentModule: null,
        };
    }

    const currentModule = currentCategory.modules.find((module) => (
        routeMatches(moduleRoutePatterns[module.key] || [module.routeName])
    )) || null;

    return {
        currentCategory,
        currentModule,
    };
}
