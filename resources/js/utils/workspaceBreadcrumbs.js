import { buildWorkspaceHubCategories } from '@/utils/workspaceHub';

const moduleRoutePatterns = {
    customers: ['customer.index', 'customer.create', 'customer.show', 'customer.edit'],
    prospects: ['prospects.*', 'request.*'],
    requests: ['request.*'],
    quotes: ['quote.index', 'customer.quote.*'],
    orders: ['orders.*'],
    sales: ['sales.*'],
    promotions: ['promotions.*'],
    campaigns: ['campaigns.*', 'campaign-automations.*', 'campaign-runs.*'],
    social: ['social.*'],
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

const nonEmptyString = (value) => {
    if (value === null || value === undefined) {
        return null;
    }

    const normalized = String(value).trim();

    return normalized === '' ? null : normalized;
};

const getValue = (source, path) => path.split('.').reduce(
    (value, segment) => (value && value[segment] !== undefined ? value[segment] : null),
    source,
);

const pickLabel = (source, paths = []) => {
    for (const path of paths) {
        const label = nonEmptyString(getValue(source, path));
        if (label) {
            return label;
        }
    }

    return null;
};

const safeRoute = (name, params = undefined) => {
    if (!name) {
        return null;
    }

    try {
        return params === undefined ? route(name) : route(name, params);
    } catch {
        return null;
    }
};

const makeItem = (key, label, href = null) => {
    if (!label) {
        return null;
    }

    return href ? { key, label, href } : { key, label };
};

const fallbackIdLabel = (id) => {
    const normalizedId = nonEmptyString(id);

    return normalizedId ? `#${normalizedId}` : null;
};

const personLabel = (source) => {
    const explicit = pickLabel(source, ['name', 'full_name', 'display_name']);
    if (explicit) {
        return explicit;
    }

    const fullName = nonEmptyString(`${source?.first_name || ''} ${source?.last_name || ''}`);
    if (fullName) {
        return fullName;
    }

    return pickLabel(source, ['email']);
};

const customerLabel = (customer) => (
    pickLabel(customer, ['company_name', 'name', 'display_name'])
    || personLabel(customer)
    || fallbackIdLabel(customer?.id)
);

const leadLabel = (lead) => (
    pickLabel(lead, ['title', 'service_type', 'contact_name'])
    || customerLabel(lead?.customer)
    || fallbackIdLabel(lead?.id)
);

const quoteLabel = (quote) => (
    pickLabel(quote, ['number', 'job_title', 'title'])
    || fallbackIdLabel(quote?.id)
);

const workLabel = (work) => (
    pickLabel(work, ['number', 'job_title', 'title'])
    || fallbackIdLabel(work?.id)
);

const taskLabel = (task) => (
    pickLabel(task, ['title', 'name'])
    || fallbackIdLabel(task?.id)
);

const invoiceLabel = (invoice) => (
    pickLabel(invoice, ['number', 'title'])
    || fallbackIdLabel(invoice?.id)
);

const expenseLabel = (expense) => (
    pickLabel(expense, ['reference_number', 'title', 'supplier_name'])
    || fallbackIdLabel(expense?.id)
);

const productLabel = (product) => (
    pickLabel(product, ['name', 'sku'])
    || fallbackIdLabel(product?.id)
);

const saleLabel = (sale) => (
    pickLabel(sale, ['number', 'reference', 'title'])
    || fallbackIdLabel(sale?.id)
);

const campaignLabel = (campaign) => (
    pickLabel(campaign, ['name', 'title'])
    || fallbackIdLabel(campaign?.id)
);

const employeeLabel = (employee) => (
    personLabel(employee)
    || fallbackIdLabel(employee?.id)
);

const planScanLabel = (scan) => (
    pickLabel(scan, ['name', 'title', 'original_name', 'original_filename', 'filename'])
    || fallbackIdLabel(scan?.id)
);

const actionLabel = (t, actionKey) => {
    const translationKey = `workspace_hub.breadcrumbs.${actionKey}`;
    const translated = t(translationKey);

    return translated === translationKey ? actionKey : translated;
};

const customerBreadcrumbItem = (customer, { href = null, suffix = 'current' } = {}) => {
    const label = customerLabel(customer);
    if (!label) {
        return null;
    }

    const targetHref = href ?? (customer?.id ? safeRoute('customer.show', customer.id) : null);

    return makeItem(`customer-${customer?.id ?? suffix}`, label, targetHref);
};

const buildCustomerModuleTail = (pageProps, t) => {
    if (route().current('customer.create')) {
        return [makeItem('customer-create', actionLabel(t, 'create'))];
    }

    const customer = pageProps.customer;
    if (!customer) {
        return [];
    }

    if (route().current('customer.edit')) {
        return [
            customerBreadcrumbItem(customer),
            makeItem('customer-edit', actionLabel(t, 'edit')),
        ].filter(Boolean);
    }

    if (route().current('customer.show')) {
        return [
            customerBreadcrumbItem(customer, { href: null }),
        ].filter(Boolean);
    }

    return [];
};

const buildRequestsModuleTail = (pageProps) => {
    if (!route().current('prospects.show') && !route().current('request.show')) {
        return [];
    }

    const lead = pageProps.lead;
    if (!lead) {
        return [];
    }

    return [
        customerBreadcrumbItem(lead.customer),
        makeItem(`request-${lead.id ?? 'current'}`, leadLabel(lead)),
    ].filter(Boolean);
};

const buildQuotesModuleTail = (pageProps, t) => {
    const customer = pageProps.customer || pageProps.quote?.customer || null;

    if (route().current('customer.quote.create')) {
        return [
            customerBreadcrumbItem(customer),
            makeItem('quote-create', actionLabel(t, 'create')),
        ].filter(Boolean);
    }

    const quote = pageProps.quote;
    if (!quote) {
        return [];
    }

    if (route().current('customer.quote.edit')) {
        return [
            customerBreadcrumbItem(customer),
            makeItem(`quote-${quote.id ?? 'current'}`, quoteLabel(quote), quote.id ? safeRoute('customer.quote.show', quote.id) : null),
            makeItem('quote-edit', actionLabel(t, 'edit')),
        ].filter(Boolean);
    }

    if (route().current('customer.quote.show')) {
        return [
            customerBreadcrumbItem(customer),
            makeItem(`quote-${quote.id ?? 'current'}`, quoteLabel(quote)),
        ].filter(Boolean);
    }

    return [];
};

const buildSalesModuleTail = (pageProps, t) => {
    if (route().current('sales.create')) {
        return [makeItem('sale-create', actionLabel(t, 'create'))];
    }

    const sale = pageProps.sale;
    if (!sale) {
        return [];
    }

    if (route().current('sales.edit')) {
        return [
            customerBreadcrumbItem(sale.customer),
            makeItem(`sale-${sale.id ?? 'current'}`, saleLabel(sale), sale.id ? safeRoute('sales.show', sale.id) : null),
            makeItem('sale-edit', actionLabel(t, 'edit')),
        ].filter(Boolean);
    }

    if (route().current('sales.show')) {
        return [
            customerBreadcrumbItem(sale.customer),
            makeItem(`sale-${sale.id ?? 'current'}`, saleLabel(sale)),
        ].filter(Boolean);
    }

    return [];
};

const buildCampaignsModuleTail = (pageProps, t) => {
    const campaign = pageProps.campaign;
    if (!campaign) {
        return [];
    }

    if (route().current('campaigns.edit')) {
        return [
            makeItem(`campaign-${campaign.id ?? 'current'}`, campaignLabel(campaign), campaign.id ? safeRoute('campaigns.show', campaign.id) : null),
            makeItem('campaign-edit', actionLabel(t, 'edit')),
        ].filter(Boolean);
    }

    if (route().current('campaigns.show')) {
        return [
            makeItem(`campaign-${campaign.id ?? 'current'}`, campaignLabel(campaign)),
        ].filter(Boolean);
    }

    return [];
};

const buildPromotionsModuleTail = (t) => {
    if (!route().current('promotions.*')) {
        return [];
    }

    // Promotions currently uses a single index page with modal CRUD.
    // The module breadcrumb already points to "Promotions", so adding
    // another tail item produces a duplicate "Promotions > Promotions".
    return [];
};

const buildPerformanceModuleTail = (pageProps) => {
    if (!route().current('performance.employee.show')) {
        return [];
    }

    return [
        makeItem(`employee-${pageProps.employee?.id ?? 'current'}`, employeeLabel(pageProps.employee)),
    ].filter(Boolean);
};

const buildJobsModuleTail = (pageProps, t) => {
    const customer = pageProps.customer || pageProps.work?.customer || null;

    if (route().current('work.create')) {
        return [
            customerBreadcrumbItem(customer),
            makeItem('work-create', actionLabel(t, 'create')),
        ].filter(Boolean);
    }

    const work = pageProps.work;
    if (!work) {
        return [];
    }

    if (route().current('work.edit')) {
        return [
            customerBreadcrumbItem(customer),
            makeItem(`work-${work.id ?? 'current'}`, workLabel(work), work.id ? safeRoute('work.show', work.id) : null),
            makeItem('work-edit', actionLabel(t, 'edit')),
        ].filter(Boolean);
    }

    if (route().current('work.proofs')) {
        return [
            customerBreadcrumbItem(customer),
            makeItem(`work-${work.id ?? 'current'}`, workLabel(work), work.id ? safeRoute('work.show', work.id) : null),
            makeItem('work-proofs', actionLabel(t, 'proofs')),
        ].filter(Boolean);
    }

    if (route().current('work.show')) {
        return [
            customerBreadcrumbItem(customer),
            makeItem(`work-${work.id ?? 'current'}`, workLabel(work)),
        ].filter(Boolean);
    }

    return [];
};

const buildTasksModuleTail = (pageProps) => {
    if (!route().current('task.show')) {
        return [];
    }

    const task = pageProps.task;
    if (!task) {
        return [];
    }

    return [
        makeItem(`task-work-${task.work?.id ?? 'none'}`, workLabel(task.work), task.work?.id ? safeRoute('work.show', task.work.id) : null),
        makeItem(`task-${task.id ?? 'current'}`, taskLabel(task)),
    ].filter(Boolean);
};

const buildInvoicesModuleTail = (pageProps) => {
    if (!route().current('invoice.show')) {
        return [];
    }

    const invoice = pageProps.invoice;
    if (!invoice) {
        return [];
    }

    return [
        customerBreadcrumbItem(invoice.customer),
        makeItem(`invoice-${invoice.id ?? 'current'}`, invoiceLabel(invoice)),
    ].filter(Boolean);
};

const buildExpensesModuleTail = (pageProps) => {
    if (!route().current('expense.show')) {
        return [];
    }

    const expense = pageProps.expense;
    if (!expense) {
        return [];
    }

    return [
        makeItem(`expense-${expense.id ?? 'current'}`, expenseLabel(expense)),
    ].filter(Boolean);
};

const buildProductsModuleTail = (pageProps, t) => {
    if (route().current('product.create')) {
        return [makeItem('product-create', actionLabel(t, 'create'))];
    }

    const product = pageProps.product;
    if (!product) {
        return [];
    }

    if (route().current('product.edit')) {
        return [
            makeItem(`product-${product.id ?? 'current'}`, productLabel(product), product.id ? safeRoute('product.show', product.id) : null),
            makeItem('product-edit', actionLabel(t, 'edit')),
        ].filter(Boolean);
    }

    if (route().current('product.show')) {
        return [
            makeItem(`product-${product.id ?? 'current'}`, productLabel(product)),
        ].filter(Boolean);
    }

    return [];
};

const buildPlanScansModuleTail = (pageProps, t) => {
    if (route().current('plan-scans.create')) {
        return [makeItem('plan-scan-create', actionLabel(t, 'create'))];
    }

    if (!route().current('plan-scans.show')) {
        return [];
    }

    return [
        makeItem(`plan-scan-${pageProps.scan?.id ?? 'current'}`, planScanLabel(pageProps.scan)),
    ].filter(Boolean);
};

const resolveModuleTail = ({ moduleKey, pageProps, t }) => {
    switch (moduleKey) {
        case 'customers':
            return buildCustomerModuleTail(pageProps, t);
        case 'prospects':
            return buildRequestsModuleTail(pageProps);
        case 'requests':
            return buildRequestsModuleTail(pageProps);
        case 'quotes':
            return buildQuotesModuleTail(pageProps, t);
        case 'sales':
            return buildSalesModuleTail(pageProps, t);
        case 'promotions':
            return buildPromotionsModuleTail(t);
        case 'campaigns':
            return buildCampaignsModuleTail(pageProps, t);
        case 'performance':
            return buildPerformanceModuleTail(pageProps);
        case 'jobs':
            return buildJobsModuleTail(pageProps, t);
        case 'tasks':
            return buildTasksModuleTail(pageProps);
        case 'invoices':
            return buildInvoicesModuleTail(pageProps);
        case 'expenses':
            return buildExpensesModuleTail(pageProps);
        case 'products':
            return buildProductsModuleTail(pageProps, t);
        case 'plan_scans':
            return buildPlanScansModuleTail(pageProps, t);
        default:
            return [];
    }
};

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

export function resolveWorkspaceBreadcrumbItems({
    account = null,
    planningPendingCount = 0,
    pageComponent = null,
    pageProps = {},
    t = (value) => value,
} = {}) {
    const { currentCategory, currentModule } = resolveWorkspaceBreadcrumbContext({
        account,
        planningPendingCount,
        pageComponent,
        pageProps,
    });

    if (!currentCategory) {
        return [];
    }

    const items = [
        {
            key: 'dashboard',
            label: t('nav.dashboard'),
            href: safeRoute('dashboard'),
            icon: 'home',
        },
    ];

    if (!currentModule) {
        items.push({
            key: currentCategory.key,
            label: t(currentCategory.labelKey),
        });

        return items;
    }

    items.push({
        key: currentCategory.key,
        label: t(currentCategory.labelKey),
        href: safeRoute(currentCategory.routeName, currentCategory.routeParams),
    });

    items.push({
        key: currentModule.key,
        label: t(currentModule.labelKey),
        href: safeRoute(currentModule.routeName, currentModule.routeParams),
    });

    return [
        ...items,
        ...resolveModuleTail({
            moduleKey: currentModule.key,
            pageProps,
            t,
        }),
    ];
}
