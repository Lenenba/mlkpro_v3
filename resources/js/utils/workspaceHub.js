import { isFeatureEnabled } from '@/utils/features';

const hasAnyPermission = (permissions, expected = []) => expected.some((permission) => permissions.includes(permission));

export function buildWorkspaceHubCategories({ account, planningPendingCount = 0 } = {}) {
    const features = account?.features || {};
    const teamPermissions = account?.team?.permissions || [];
    const teamRole = account?.team?.role || null;
    const companyType = account?.company?.type ?? null;

    const isOwner = Boolean(account?.is_owner);
    const isClient = Boolean(account?.is_client);
    const isPlatformUser = Boolean(account?.is_superadmin) || Boolean(account?.is_platform_admin);
    const isSeller = teamRole === 'seller';
    const isTeamMember = Boolean(teamRole);
    const showServices = companyType !== 'products';
    const showProducts = true;
    const hasFeature = (key) => isFeatureEnabled(features, key);

    const canSales = isOwner || hasAnyPermission(teamPermissions, ['sales.manage', 'sales.pos']);
    const canSalesManage = isOwner || teamPermissions.includes('sales.manage');
    const canJobs = isOwner || hasAnyPermission(teamPermissions, ['jobs.view', 'jobs.edit']);
    const canTasks = isOwner || hasAnyPermission(teamPermissions, ['tasks.view', 'tasks.create', 'tasks.edit', 'tasks.delete']);
    const canService = isOwner || hasAnyPermission(teamPermissions, ['jobs.view', 'tasks.view', 'jobs.edit', 'tasks.edit']);
    const canServiceManage = isOwner || hasAnyPermission(teamPermissions, ['jobs.edit', 'tasks.edit']);
    const canLoyaltyManage = isOwner || canSalesManage || canServiceManage;
    const canCampaigns = isOwner || hasAnyPermission(teamPermissions, ['campaigns.view', 'campaigns.manage', 'campaigns.send']);
    const canReservations = isOwner || hasAnyPermission(teamPermissions, ['reservations.view', 'reservations.queue', 'reservations.manage']);
    const hasServiceOps = showServices && (hasFeature('jobs') || hasFeature('tasks'));
    const canQuotes = isOwner || hasAnyPermission(teamPermissions, ['quotes.view', 'quotes.edit']);
    const canExpensesNav = isOwner || hasAnyPermission(teamPermissions, [
        'expenses.view',
        'expenses.create',
        'expenses.edit',
        'expenses.approve',
        'expenses.approve_high',
        'expenses.pay',
    ]);
    const canAccountingNav = isOwner || teamPermissions.includes('accounting.view');
    const canInvoicesNav = isOwner || hasAnyPermission(teamPermissions, [
        'invoices.view',
        'invoices.create',
        'invoices.edit',
        'invoices.approve',
        'invoices.approve_high',
    ]);
    const canFinanceApprovals = isOwner || hasAnyPermission(teamPermissions, [
        'expenses.approve',
        'expenses.approve_high',
        'invoices.approve',
        'invoices.approve_high',
    ]);

    const unavailableCategory = (category) => ({
        ...category,
        modules: [],
        visible: false,
    });

    if (isClient || isPlatformUser) {
        return [
            unavailableCategory({
                key: 'revenue',
                labelKey: 'nav.revenue',
                descriptionKey: 'workspace_hub.categories.revenue.description',
                titleKey: 'workspace_hub.categories.revenue.title',
                icon: 'revenue',
                tone: 'revenue',
                match: [],
            }),
            unavailableCategory({
                key: 'growth',
                labelKey: 'nav.growth',
                descriptionKey: 'workspace_hub.categories.growth.description',
                titleKey: 'workspace_hub.categories.growth.title',
                icon: 'growth',
                tone: 'growth',
                match: [],
            }),
            unavailableCategory({
                key: 'operations',
                labelKey: 'nav.operations',
                descriptionKey: 'workspace_hub.categories.operations.description',
                titleKey: 'workspace_hub.categories.operations.title',
                icon: 'operations',
                tone: 'operations',
                match: [],
            }),
            unavailableCategory({
                key: 'finance',
                labelKey: 'nav.finance',
                descriptionKey: 'workspace_hub.categories.finance.description',
                titleKey: 'workspace_hub.categories.finance.title',
                icon: 'finance',
                tone: 'finance',
                match: [],
            }),
            unavailableCategory({
                key: 'catalog',
                labelKey: 'nav.catalog',
                descriptionKey: 'workspace_hub.categories.catalog.description',
                titleKey: 'workspace_hub.categories.catalog.title',
                icon: 'catalog',
                tone: 'catalog',
                match: [],
            }),
            unavailableCategory({
                key: 'workspace',
                labelKey: 'nav.workspace',
                descriptionKey: 'workspace_hub.categories.workspace.description',
                titleKey: 'workspace_hub.categories.workspace.title',
                icon: 'workspace',
                tone: 'workspace',
                match: [],
            }),
        ];
    }

    const modules = {
        customers: {
            key: 'customers',
            labelKey: 'nav.customers',
            descriptionKey: 'workspace_hub.modules.customers',
            routeName: 'customer.index',
            tone: 'customers',
            visible: ((showServices && isOwner) || (companyType === 'products' && hasFeature('sales') && canSales)) && !isSeller,
        },
        requests: {
            key: 'requests',
            labelKey: 'nav.requests',
            descriptionKey: 'workspace_hub.modules.requests',
            routeName: 'request.index',
            tone: 'requests',
            visible: showServices && hasFeature('requests') && isOwner && !isSeller,
        },
        quotes: {
            key: 'quotes',
            labelKey: 'nav.quotes',
            descriptionKey: 'workspace_hub.modules.quotes',
            routeName: 'quote.index',
            tone: 'quotes',
            visible: showServices && hasFeature('quotes') && canQuotes && !isSeller,
        },
        next_actions: {
            key: 'next_actions',
            labelKey: 'workspace_hub.modules.next_actions_label',
            descriptionKey: 'workspace_hub.modules.next_actions',
            routeName: 'crm.next-actions.index',
            tone: 'next_actions',
            visible: showServices && hasFeature('sales') && !isClient && !isSeller,
        },
        sales_inbox: {
            key: 'sales_inbox',
            labelKey: 'workspace_hub.modules.sales_inbox_label',
            descriptionKey: 'workspace_hub.modules.sales_inbox',
            routeName: 'crm.sales-inbox.index',
            tone: 'quotes',
            visible: showServices && hasFeature('sales') && canSalesManage && !isClient && !isSeller,
        },
        manager_dashboard: {
            key: 'manager_dashboard',
            labelKey: 'workspace_hub.modules.manager_dashboard_label',
            descriptionKey: 'workspace_hub.modules.manager_dashboard',
            routeName: 'crm.manager-dashboard.index',
            tone: 'performance',
            visible: showServices && hasFeature('sales') && canSalesManage && !isClient && !isSeller,
        },
        orders: {
            key: 'orders',
            labelKey: 'nav.orders',
            descriptionKey: 'workspace_hub.modules.orders',
            routeName: 'orders.index',
            tone: 'orders',
            visible: companyType === 'products' && hasFeature('sales') && canSales,
        },
        sales: {
            key: 'sales',
            labelKey: 'nav.sales',
            descriptionKey: 'workspace_hub.modules.sales',
            routeName: isSeller ? 'sales.create' : 'sales.index',
            tone: 'sales',
            visible: companyType === 'products' && hasFeature('sales') && canSales,
        },
        campaigns: {
            key: 'campaigns',
            labelKey: 'nav.campaigns',
            descriptionKey: 'workspace_hub.modules.campaigns',
            routeName: 'campaigns.index',
            tone: 'campaigns',
            visible: hasFeature('campaigns') && canCampaigns && !isClient && !isSeller,
        },
        loyalty: {
            key: 'loyalty',
            labelKey: 'nav.loyalty',
            descriptionKey: 'workspace_hub.modules.loyalty',
            routeName: 'loyalty.index',
            tone: 'loyalty',
            visible: canLoyaltyManage && hasFeature('loyalty') && !isClient && !isSeller,
        },
        performance: {
            key: 'performance',
            labelKey: 'nav.performance',
            descriptionKey: 'workspace_hub.modules.performance',
            routeName: 'performance.index',
            tone: 'performance',
            visible: ((companyType === 'products' && hasFeature('sales') && hasFeature('performance') && canSalesManage)
                || (hasServiceOps && hasFeature('performance') && canServiceManage)),
        },
        jobs: {
            key: 'jobs',
            labelKey: 'nav.jobs',
            descriptionKey: 'workspace_hub.modules.jobs',
            routeName: 'jobs.index',
            tone: 'jobs',
            visible: showServices && hasFeature('jobs') && canJobs && !isClient && !isSeller,
        },
        tasks: {
            key: 'tasks',
            labelKey: 'nav.tasks',
            descriptionKey: 'workspace_hub.modules.tasks',
            routeName: 'task.index',
            tone: 'tasks',
            visible: showServices && hasFeature('tasks') && canTasks && !isClient && !isSeller,
        },
        reservations: {
            key: 'reservations',
            labelKey: 'nav.reservations',
            descriptionKey: 'workspace_hub.modules.reservations',
            routeName: 'reservation.index',
            tone: 'planning',
            visible: showServices && hasFeature('reservations') && canReservations && !isClient && !isSeller,
        },
        planning: {
            key: 'planning',
            labelKey: 'nav.planning',
            descriptionKey: 'workspace_hub.modules.planning',
            routeName: 'planning.index',
            tone: 'planning',
            visible: ((companyType === 'products' && hasFeature('sales') && hasFeature('planning') && (canSales || isTeamMember))
                || (hasServiceOps && hasFeature('planning') && (canService || isTeamMember))),
            badge: planningPendingCount > 0 ? {
                value: planningPendingCount,
                labelKey: 'workspace_hub.badges.pending',
            } : null,
        },
        presence: {
            key: 'presence',
            labelKey: 'nav.presence',
            descriptionKey: 'workspace_hub.modules.presence',
            routeName: 'presence.index',
            tone: 'presence',
            visible: ((companyType === 'products' && hasFeature('sales') && hasFeature('presence') && canSales)
                || (hasServiceOps && hasFeature('presence') && canService)),
        },
        team: {
            key: 'team',
            labelKey: 'nav.team',
            descriptionKey: 'workspace_hub.modules.team',
            routeName: 'team.index',
            tone: 'team',
            visible: hasFeature('team_members') && isOwner && !isSeller,
        },
        invoices: {
            key: 'invoices',
            labelKey: 'nav.invoices',
            descriptionKey: 'workspace_hub.modules.invoices',
            routeName: 'invoice.index',
            tone: 'invoices',
            visible: hasFeature('invoices') && canInvoicesNav && !isSeller,
        },
        expenses: {
            key: 'expenses',
            labelKey: 'nav.expenses',
            descriptionKey: 'workspace_hub.modules.expenses',
            routeName: 'expense.index',
            tone: 'expenses',
            visible: hasFeature('expenses') && canExpensesNav && !isSeller,
        },
        accounting: {
            key: 'accounting',
            labelKey: 'nav.accounting',
            descriptionKey: 'workspace_hub.modules.accounting',
            routeName: 'accounting.index',
            tone: 'accounting',
            visible: hasFeature('accounting') && canAccountingNav && !isSeller,
        },
        finance_approvals: {
            key: 'finance_approvals',
            labelKey: 'nav.finance_approvals',
            descriptionKey: 'workspace_hub.modules.finance_approvals',
            routeName: 'finance-approvals.index',
            tone: 'finance',
            visible: canFinanceApprovals && !isSeller,
        },
        tips_owner: {
            key: 'tips_owner',
            labelKey: 'nav.tips',
            descriptionKey: 'workspace_hub.modules.tips_owner',
            routeName: 'payments.tips.index',
            tone: 'invoices',
            visible: showServices && hasFeature('invoices') && isOwner && !isSeller,
        },
        tips_member: {
            key: 'tips_member',
            labelKey: 'nav.tips',
            descriptionKey: 'workspace_hub.modules.tips_member',
            routeName: 'my-earnings.tips.index',
            tone: 'invoices',
            visible: showServices && hasFeature('invoices') && isTeamMember && !isClient && !isSeller,
        },
        services: {
            key: 'services',
            labelKey: 'nav.services',
            descriptionKey: 'workspace_hub.modules.services',
            routeName: 'service.index',
            tone: 'services',
            visible: showServices && hasFeature('services') && isOwner && !isSeller,
        },
        categories: {
            key: 'categories',
            labelKey: 'nav.categories',
            descriptionKey: 'workspace_hub.modules.categories',
            routeName: 'service.categories',
            tone: 'categories',
            visible: showServices && hasFeature('services') && isOwner,
        },
        products: {
            key: 'products',
            labelKey: 'nav.products',
            descriptionKey: 'workspace_hub.modules.products',
            routeName: 'product.index',
            tone: 'products',
            visible: showProducts && hasFeature('products') && (isOwner || canSales) && !isSeller,
        },
        plan_scans: {
            key: 'plan_scans',
            labelKey: 'nav.plan_scans',
            descriptionKey: 'workspace_hub.modules.plan_scans',
            routeName: 'plan-scans.index',
            tone: 'plan_scans',
            visible: showServices && hasFeature('plan_scans') && isOwner && !isSeller,
        },
        company_settings: {
            key: 'company_settings',
            labelKey: 'workspace_hub.modules.company_settings_label',
            descriptionKey: 'workspace_hub.modules.company_settings',
            routeName: 'settings.company.edit',
            tone: 'workspace',
            visible: isOwner,
        },
        billing: {
            key: 'billing',
            labelKey: 'workspace_hub.modules.billing_label',
            descriptionKey: 'workspace_hub.modules.billing',
            routeName: 'settings.billing.edit',
            tone: 'workspace',
            visible: isOwner,
        },
        profile: {
            key: 'profile',
            labelKey: 'workspace_hub.modules.profile_label',
            descriptionKey: 'workspace_hub.modules.profile',
            routeName: 'profile.edit',
            tone: 'workspace',
            visible: true,
        },
    };

    const categories = [
        {
            key: 'revenue',
            labelKey: 'nav.revenue',
            descriptionKey: 'workspace_hub.categories.revenue.description',
            titleKey: 'workspace_hub.categories.revenue.title',
            icon: 'revenue',
            tone: 'revenue',
            match: ['customer.*', 'request.*', 'quote.*', 'crm.next-actions.*', 'crm.sales-inbox.*', 'crm.manager-dashboard.*', 'orders.*', 'sales.*'],
            moduleKeys: ['customers', 'requests', 'quotes', 'manager_dashboard', 'sales_inbox', 'next_actions', 'orders', 'sales'],
        },
        {
            key: 'growth',
            labelKey: 'nav.growth',
            descriptionKey: 'workspace_hub.categories.growth.description',
            titleKey: 'workspace_hub.categories.growth.title',
            icon: 'growth',
            tone: 'growth',
            match: ['campaigns.*', 'campaign-automations.*', 'campaign-runs.*', 'loyalty.*', 'settings.loyalty.*', 'performance.*'],
            moduleKeys: ['campaigns', 'loyalty', 'performance'],
        },
        {
            key: 'operations',
            labelKey: 'nav.operations',
            descriptionKey: 'workspace_hub.categories.operations.description',
            titleKey: 'workspace_hub.categories.operations.title',
            icon: 'operations',
            tone: 'operations',
            match: ['jobs.*', 'work.*', 'task.*', 'reservation.*', 'planning.*', 'presence.*', 'team.*'],
            moduleKeys: ['jobs', 'tasks', 'reservations', 'planning', 'presence', 'team'],
        },
        {
            key: 'finance',
            labelKey: 'nav.finance',
            descriptionKey: 'workspace_hub.categories.finance.description',
            titleKey: 'workspace_hub.categories.finance.title',
            icon: 'finance',
            tone: 'finance',
            match: ['invoice.*', 'expense.*', 'accounting.*', 'finance-approvals.*', 'payments.tips.*', 'my-earnings.tips.*'],
            moduleKeys: ['invoices', 'expenses', 'accounting', 'finance_approvals', 'tips_owner', 'tips_member'],
        },
        {
            key: 'catalog',
            labelKey: 'nav.catalog',
            descriptionKey: 'workspace_hub.categories.catalog.description',
            titleKey: 'workspace_hub.categories.catalog.title',
            icon: 'catalog',
            tone: 'catalog',
            match: ['service.*', 'product.*', 'plan-scans.*'],
            moduleKeys: ['services', 'categories', 'products', 'plan_scans'],
        },
        {
            key: 'workspace',
            labelKey: 'nav.workspace',
            descriptionKey: 'workspace_hub.categories.workspace.description',
            titleKey: 'workspace_hub.categories.workspace.title',
            icon: 'workspace',
            tone: 'workspace',
            match: ['settings.company.*', 'settings.billing.*', 'profile.edit'],
            moduleKeys: ['company_settings', 'billing', 'profile'],
        },
    ];

    return categories.map((category) => {
        const categoryModules = category.moduleKeys
            .map((moduleKey) => modules[moduleKey])
            .filter((module) => Boolean(module?.visible));

        return {
            ...category,
            routeName: 'workspace.hubs.show',
            routeParams: { category: category.key },
            modules: categoryModules,
            visible: categoryModules.length > 0,
        };
    });
}
