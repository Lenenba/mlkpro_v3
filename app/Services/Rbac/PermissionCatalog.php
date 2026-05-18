<?php

namespace App\Services\Rbac;

use App\Models\Prospect;

class PermissionCatalog
{
    /**
     * @return array<int, array{group: string, name: string, slug: string, description: string|null}>
     */
    public function permissions(): array
    {
        return [
            $this->permission('clients', 'view_clients', 'View clients'),
            $this->permission('clients', 'create_clients', 'Create clients'),
            $this->permission('clients', 'update_clients', 'Update clients'),
            $this->permission('clients', 'delete_clients', 'Delete clients'),
            $this->permission('clients', 'export_clients', 'Export clients'),
            $this->permission('clients', 'view_client_notes', 'View client notes'),
            $this->permission('clients', 'manage_client_notes', 'Manage client notes'),

            $this->permission('reservations', 'view_reservations', 'View reservations'),
            $this->permission('reservations', 'create_reservations', 'Create reservations'),
            $this->permission('reservations', 'update_reservations', 'Update reservations'),
            $this->permission('reservations', 'cancel_reservations', 'Cancel reservations'),
            $this->permission('reservations', 'manage_reservation_calendar', 'Manage reservation calendar'),
            $this->permission('reservations', 'manage_reservation_queue', 'Manage reservation queue'),
            $this->permission('reservations', 'assign_reservations', 'Assign reservations'),
            $this->permission('reservations', 'view_all_reservations', 'View all reservations'),
            $this->permission('reservations', 'view_own_reservations', 'View own reservations'),

            $this->permission('services', 'view_services', 'View services'),
            $this->permission('services', 'create_services', 'Create services'),
            $this->permission('services', 'update_services', 'Update services'),
            $this->permission('services', 'delete_services', 'Delete services'),
            $this->permission('services', 'manage_service_categories', 'Manage service categories'),

            $this->permission('products', 'view_products', 'View products'),
            $this->permission('products', 'create_products', 'Create products'),
            $this->permission('products', 'update_products', 'Update products'),
            $this->permission('products', 'delete_products', 'Delete products'),
            $this->permission('products', 'manage_inventory', 'Manage inventory'),
            $this->permission('products', 'adjust_stock', 'Adjust stock'),

            $this->permission('sales', 'view_sales', 'View sales'),
            $this->permission('sales', 'create_sales', 'Create sales'),
            $this->permission('sales', 'refund_sales', 'Refund sales'),
            $this->permission('sales', 'apply_discount', 'Apply discount'),
            $this->permission('sales', 'view_sales_reports', 'View sales reports'),
            $this->permission('sales', 'manage_cash_register', 'Manage cash register'),

            $this->permission('team', 'view_team_members', 'View team members'),
            $this->permission('team', 'create_team_members', 'Create team members'),
            $this->permission('team', 'update_team_members', 'Update team members'),
            $this->permission('team', 'deactivate_team_members', 'Deactivate team members'),
            $this->permission('team', 'assign_roles', 'Assign roles'),
            $this->permission('team', 'manage_team_schedule', 'Manage team schedule'),

            $this->permission('presence', 'view_presence', 'View presence'),
            $this->permission('presence', 'manage_own_presence', 'Manage own presence'),
            $this->permission('presence', 'manage_team_presence', 'Manage team presence'),
            $this->permission('presence', 'view_presence_reports', 'View presence reports'),

            $this->permission('chairs', 'view_chairs', 'View chairs'),
            $this->permission('chairs', 'manage_chairs', 'Manage chairs'),
            $this->permission('chairs', 'assign_chairs_to_members', 'Assign chairs to members'),
            $this->permission('chairs', 'activate_chair_on_check_in', 'Activate chair on check-in'),

            $this->permission('finance', 'view_invoices', 'View invoices'),
            $this->permission('finance', 'create_invoices', 'Create invoices'),
            $this->permission('finance', 'update_invoices', 'Update invoices'),
            $this->permission('finance', 'approve_invoices', 'Approve invoices'),
            $this->permission('finance', 'approve_high_value_invoices', 'Approve high value invoices'),
            $this->permission('finance', 'view_expenses', 'View expenses'),
            $this->permission('finance', 'create_expenses', 'Create expenses'),
            $this->permission('finance', 'update_expenses', 'Update expenses'),
            $this->permission('finance', 'approve_expenses', 'Approve expenses'),
            $this->permission('finance', 'approve_high_value_expenses', 'Approve high value expenses'),
            $this->permission('finance', 'view_financial_reports', 'View financial reports'),
            $this->permission('finance', 'view_accounting', 'View accounting'),
            $this->permission('finance', 'manage_accounting', 'Manage accounting'),

            $this->permission('settings', 'view_settings', 'View settings'),
            $this->permission('settings', 'manage_company_settings', 'Manage company settings'),
            $this->permission('settings', 'manage_billing_settings', 'Manage billing settings'),
            $this->permission('settings', 'manage_integrations', 'Manage integrations'),
            $this->permission('settings', 'manage_roles_permissions', 'Manage roles and permissions'),

            $this->permission('reports', 'view_reports', 'View reports'),
            $this->permission('reports', 'view_team_reports', 'View team reports'),
            $this->permission('reports', 'export_reports', 'Export reports'),

            $this->permission('campaigns', 'view_campaigns', 'View campaigns'),
            $this->permission('campaigns', 'create_campaigns', 'Create campaigns'),
            $this->permission('campaigns', 'update_campaigns', 'Update campaigns'),
            $this->permission('campaigns', 'send_campaigns', 'Send campaigns'),
            $this->permission('campaigns', 'manage_campaign_templates', 'Manage campaign templates'),

            $this->permission('storefront', 'view_storefront', 'View storefront'),
            $this->permission('storefront', 'manage_storefront', 'Manage storefront'),
            $this->permission('storefront', 'manage_public_services', 'Manage public services'),
            $this->permission('storefront', 'manage_public_products', 'Manage public products'),

            $this->permission('jobs', 'view_jobs', 'View jobs'),
            $this->permission('jobs', 'create_jobs', 'Create jobs'),
            $this->permission('jobs', 'update_jobs', 'Update jobs'),
            $this->permission('jobs', 'delete_jobs', 'Delete jobs'),

            $this->permission('tasks', 'view_tasks', 'View tasks'),
            $this->permission('tasks', 'create_tasks', 'Create tasks'),
            $this->permission('tasks', 'update_tasks', 'Update tasks'),
            $this->permission('tasks', 'delete_tasks', 'Delete tasks'),

            $this->permission('quotes', 'view_quotes', 'View quotes'),
            $this->permission('quotes', 'create_quotes', 'Create quotes'),
            $this->permission('quotes', 'update_quotes', 'Update quotes'),
            $this->permission('quotes', 'send_quotes', 'Send quotes'),

            $this->permission('prospects', 'view_prospects', 'View prospects'),
            $this->permission('prospects', 'create_prospects', 'Create prospects'),
            $this->permission('prospects', 'update_prospects', 'Update prospects'),
            $this->permission('prospects', 'assign_prospects', 'Assign prospects'),
            $this->permission('prospects', 'convert_prospects', 'Convert prospects'),
            $this->permission('prospects', 'merge_prospects', 'Merge prospects'),
            $this->permission('prospects', 'export_prospects', 'Export prospects'),

            $this->permission('social', 'view_social', 'View social'),
            $this->permission('social', 'manage_social', 'Manage social'),
            $this->permission('social', 'publish_social', 'Publish social'),
            $this->permission('social', 'approve_social', 'Approve social'),
        ];
    }

    /**
     * @return array<int, array{name: string, slug: string, description: string, is_system: bool, is_default: bool, is_editable: bool, is_deletable: bool, is_active: bool, permissions: array<int, string>}>
     */
    public function defaultRoles(): array
    {
        $all = $this->permissionSlugs();

        return [
            $this->role('Owner', 'owner', 'Full company control.', $all, false, false),
            $this->role('Manager', 'manager', 'Operational management role.', [
                'view_clients',
                'create_clients',
                'update_clients',
                'view_client_notes',
                'manage_client_notes',
                'view_reservations',
                'create_reservations',
                'update_reservations',
                'cancel_reservations',
                'manage_reservation_calendar',
                'manage_reservation_queue',
                'assign_reservations',
                'view_all_reservations',
                'view_services',
                'view_products',
                'view_sales',
                'create_sales',
                'view_sales_reports',
                'view_team_members',
                'create_team_members',
                'update_team_members',
                'deactivate_team_members',
                'assign_roles',
                'manage_team_schedule',
                'view_presence',
                'manage_own_presence',
                'manage_team_presence',
                'view_presence_reports',
                'view_chairs',
                'manage_chairs',
                'assign_chairs_to_members',
                'activate_chair_on_check_in',
                'view_reports',
                'view_team_reports',
                'export_reports',
                'view_jobs',
                'create_jobs',
                'update_jobs',
                'delete_jobs',
                'view_tasks',
                'create_tasks',
                'update_tasks',
                'delete_tasks',
                'view_quotes',
                'create_quotes',
                'update_quotes',
                'send_quotes',
                'view_prospects',
                'create_prospects',
                'update_prospects',
                'assign_prospects',
                'convert_prospects',
                'merge_prospects',
                'export_prospects',
            ]),
            $this->role('Coiffeur', 'coiffeur', 'Salon service provider role.', [
                'view_clients',
                'view_client_notes',
                'view_reservations',
                'view_own_reservations',
                'update_reservations',
                'view_services',
                'view_team_members',
                'view_presence',
                'manage_own_presence',
                'view_chairs',
                'activate_chair_on_check_in',
                'view_jobs',
                'view_tasks',
                'update_tasks',
            ]),
            $this->role('Vendeur', 'vendeur', 'Sales and point-of-sale role.', [
                'view_clients',
                'create_clients',
                'update_clients',
                'view_products',
                'manage_inventory',
                'view_sales',
                'create_sales',
                'apply_discount',
                'manage_cash_register',
                'view_storefront',
                'view_presence',
                'manage_own_presence',
                'view_tasks',
                'update_tasks',
            ]),
            $this->role('Receptionniste', 'receptionniste', 'Front desk and reservations role.', [
                'view_clients',
                'create_clients',
                'update_clients',
                'view_reservations',
                'create_reservations',
                'update_reservations',
                'cancel_reservations',
                'manage_reservation_calendar',
                'manage_reservation_queue',
                'assign_reservations',
                'view_all_reservations',
                'view_services',
                'view_team_members',
                'view_presence',
                'manage_own_presence',
                'view_chairs',
                'view_tasks',
                'create_tasks',
                'update_tasks',
            ]),
            $this->role('Comptable', 'comptable', 'Finance and accounting role.', [
                'view_clients',
                'view_invoices',
                'create_invoices',
                'update_invoices',
                'approve_invoices',
                'approve_high_value_invoices',
                'view_expenses',
                'create_expenses',
                'update_expenses',
                'approve_expenses',
                'approve_high_value_expenses',
                'view_financial_reports',
                'view_accounting',
                'manage_accounting',
                'view_presence',
                'manage_own_presence',
                'view_reports',
                'export_reports',
            ]),
            $this->role('Employe standard', 'employe_standard', 'Default employee role.', [
                'view_clients',
                'view_services',
                'view_own_reservations',
                'view_presence',
                'manage_own_presence',
                'view_tasks',
                'update_tasks',
            ]),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function permissionSlugs(): array
    {
        return array_values(array_unique(array_column($this->permissions(), 'slug')));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function aliases(): array
    {
        return array_merge(Prospect::permissionAliases(), [
            'customers.view' => ['view_clients'],
            'view_clients' => ['customers.view'],
            'customers.create' => ['create_clients'],
            'create_clients' => ['customers.create'],
            'customers.edit' => ['update_clients'],
            'update_clients' => ['customers.edit'],
            'customers.delete' => ['delete_clients'],
            'delete_clients' => ['customers.delete'],
            'customers.export' => ['export_clients'],
            'export_clients' => ['customers.export'],

            'reservations.view' => ['view_reservations', 'view_all_reservations', 'view_own_reservations'],
            'view_reservations' => ['reservations.view'],
            'view_all_reservations' => ['reservations.view'],
            'view_own_reservations' => ['reservations.view'],
            'reservations.queue' => ['manage_reservation_queue'],
            'manage_reservation_queue' => ['reservations.queue'],
            'reservations.manage' => [
                'create_reservations',
                'update_reservations',
                'cancel_reservations',
                'manage_reservation_calendar',
                'manage_reservation_queue',
                'assign_reservations',
                'view_all_reservations',
            ],
            'manage_reservation_calendar' => ['reservations.manage'],

            'services.view' => ['view_services'],
            'view_services' => ['services.view'],
            'services.create' => ['create_services'],
            'create_services' => ['services.create'],
            'services.edit' => ['update_services'],
            'update_services' => ['services.edit'],
            'services.delete' => ['delete_services'],
            'delete_services' => ['services.delete'],

            'products.view' => ['view_products'],
            'view_products' => ['products.view'],
            'products.create' => ['create_products'],
            'create_products' => ['products.create'],
            'products.edit' => ['update_products'],
            'update_products' => ['products.edit'],
            'products.delete' => ['delete_products'],
            'delete_products' => ['products.delete'],
            'products.inventory' => ['manage_inventory'],
            'manage_inventory' => ['products.inventory'],
            'products.stock' => ['adjust_stock'],
            'adjust_stock' => ['products.stock'],

            'sales.manage' => [
                'view_sales',
                'create_sales',
                'refund_sales',
                'apply_discount',
                'view_sales_reports',
                'manage_cash_register',
            ],
            'sales.pos' => ['view_sales', 'create_sales', 'manage_cash_register'],
            'create_sales' => ['sales.pos'],
            'manage_cash_register' => ['sales.pos'],
            'view_sales_reports' => ['sales.manage'],

            'team.view' => ['view_team_members'],
            'view_team_members' => ['team.view'],
            'team.create' => ['create_team_members'],
            'create_team_members' => ['team.create'],
            'team.edit' => ['update_team_members'],
            'update_team_members' => ['team.edit'],
            'team.deactivate' => ['deactivate_team_members'],
            'deactivate_team_members' => ['team.deactivate'],
            'team.schedule' => ['manage_team_schedule'],
            'manage_team_schedule' => ['team.schedule'],

            'presence.view' => ['view_presence'],
            'view_presence' => ['presence.view'],
            'presence.manage_own' => ['manage_own_presence'],
            'manage_own_presence' => ['presence.manage_own'],
            'presence.manage_team' => ['manage_team_presence'],
            'manage_team_presence' => ['presence.manage_team'],

            'chairs.view' => ['view_chairs'],
            'view_chairs' => ['chairs.view'],
            'chairs.manage' => ['manage_chairs'],
            'manage_chairs' => ['chairs.manage'],
            'chairs.assign' => ['assign_chairs_to_members'],
            'assign_chairs_to_members' => ['chairs.assign'],

            'invoices.view' => ['view_invoices'],
            'view_invoices' => ['invoices.view'],
            'invoices.create' => ['create_invoices'],
            'create_invoices' => ['invoices.create'],
            'invoices.edit' => ['update_invoices'],
            'update_invoices' => ['invoices.edit'],
            'invoices.approve' => ['approve_invoices'],
            'approve_invoices' => ['invoices.approve'],
            'invoices.approve_high' => ['approve_high_value_invoices'],
            'approve_high_value_invoices' => ['invoices.approve_high'],

            'expenses.view' => ['view_expenses'],
            'view_expenses' => ['expenses.view'],
            'expenses.create' => ['create_expenses'],
            'create_expenses' => ['expenses.create'],
            'expenses.edit' => ['update_expenses'],
            'update_expenses' => ['expenses.edit'],
            'expenses.approve' => ['approve_expenses'],
            'approve_expenses' => ['expenses.approve'],
            'expenses.approve_high' => ['approve_high_value_expenses'],
            'approve_high_value_expenses' => ['expenses.approve_high'],
            'expenses.pay' => ['approve_expenses'],

            'accounting.view' => ['view_accounting'],
            'view_accounting' => ['accounting.view'],
            'accounting.manage' => ['manage_accounting'],
            'manage_accounting' => ['accounting.manage'],

            'settings.view' => ['view_settings'],
            'view_settings' => ['settings.view'],
            'settings.manage' => ['manage_company_settings'],
            'manage_company_settings' => ['settings.manage'],
            'settings.billing' => ['manage_billing_settings'],
            'manage_billing_settings' => ['settings.billing'],
            'settings.integrations' => ['manage_integrations'],
            'manage_integrations' => ['settings.integrations'],
            'settings.roles' => ['manage_roles_permissions'],
            'manage_roles_permissions' => ['settings.roles'],

            'reports.view' => ['view_reports'],
            'view_reports' => ['reports.view'],
            'reports.team' => ['view_team_reports'],
            'view_team_reports' => ['reports.team'],
            'reports.sales' => ['view_sales_reports'],
            'reports.financial' => ['view_financial_reports'],
            'view_financial_reports' => ['reports.financial'],
            'reports.export' => ['export_reports'],
            'export_reports' => ['reports.export'],

            'campaigns.view' => ['view_campaigns'],
            'view_campaigns' => ['campaigns.view'],
            'campaigns.manage' => ['create_campaigns', 'update_campaigns', 'manage_campaign_templates'],
            'update_campaigns' => ['campaigns.manage'],
            'manage_campaign_templates' => ['campaigns.manage'],
            'campaigns.send' => ['send_campaigns'],
            'send_campaigns' => ['campaigns.send'],

            'storefront.view' => ['view_storefront'],
            'view_storefront' => ['storefront.view'],
            'storefront.manage' => ['manage_storefront'],
            'manage_storefront' => ['storefront.manage'],

            'jobs.view' => ['view_jobs'],
            'view_jobs' => ['jobs.view'],
            'jobs.create' => ['create_jobs'],
            'create_jobs' => ['jobs.edit'],
            'jobs.edit' => ['create_jobs', 'update_jobs', 'delete_jobs'],
            'update_jobs' => ['jobs.edit'],
            'jobs.delete' => ['delete_jobs'],
            'delete_jobs' => ['jobs.delete', 'jobs.edit'],

            'tasks.view' => ['view_tasks'],
            'view_tasks' => ['tasks.view'],
            'tasks.create' => ['create_tasks'],
            'create_tasks' => ['tasks.create'],
            'tasks.edit' => ['update_tasks'],
            'update_tasks' => ['tasks.edit'],
            'tasks.delete' => ['delete_tasks'],
            'delete_tasks' => ['tasks.delete'],

            'quotes.view' => ['view_quotes'],
            'view_quotes' => ['quotes.view'],
            'quotes.create' => ['create_quotes'],
            'create_quotes' => ['quotes.create'],
            'quotes.edit' => ['update_quotes'],
            'update_quotes' => ['quotes.edit'],
            'quotes.send' => ['send_quotes'],
            'send_quotes' => ['quotes.send'],

            'prospects.view' => ['view_prospects'],
            'view_prospects' => ['prospects.view', 'requests.view'],
            'prospects.create' => ['create_prospects'],
            'create_prospects' => ['prospects.create', 'requests.create'],
            'prospects.edit' => ['update_prospects'],
            'update_prospects' => ['prospects.edit', 'requests.edit'],
            'prospects.assign' => ['assign_prospects'],
            'assign_prospects' => ['prospects.assign', 'requests.assign'],
            'prospects.convert' => ['convert_prospects'],
            'convert_prospects' => ['prospects.convert', 'requests.convert'],
            'prospects.merge' => ['merge_prospects'],
            'merge_prospects' => ['prospects.merge', 'requests.merge'],
            'prospects.export' => ['export_prospects'],
            'export_prospects' => ['prospects.export', 'requests.export'],

            'requests.view' => ['view_prospects'],
            'requests.create' => ['create_prospects'],
            'requests.edit' => ['update_prospects'],
            'requests.assign' => ['assign_prospects'],
            'requests.convert' => ['convert_prospects'],
            'requests.merge' => ['merge_prospects'],
            'requests.export' => ['export_prospects'],

            'social.view' => ['view_social'],
            'view_social' => ['social.view'],
            'social.manage' => ['manage_social'],
            'manage_social' => ['social.manage'],
            'social.publish' => ['publish_social'],
            'publish_social' => ['social.publish'],
            'social.approve' => ['approve_social'],
            'approve_social' => ['social.approve'],
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function candidates(string $permission): array
    {
        $aliases = $this->aliases();
        $seen = [];
        $queue = [$permission];

        while ($queue !== []) {
            $candidate = array_shift($queue);

            if (! is_string($candidate) || $candidate === '' || isset($seen[$candidate])) {
                continue;
            }

            $seen[$candidate] = true;

            foreach ($aliases[$candidate] ?? [] as $alias) {
                if (! isset($seen[$alias])) {
                    $queue[] = $alias;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array<int, string>
     */
    public function expand(array $permissions): array
    {
        $expanded = [];

        foreach ($permissions as $permission) {
            if (! is_string($permission) || $permission === '') {
                continue;
            }

            $expanded = [
                ...$expanded,
                ...$this->candidates($permission),
            ];
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @return array{group: string, name: string, slug: string, description: string|null}
     */
    private function permission(string $group, string $slug, string $name, ?string $description = null): array
    {
        return [
            'group' => $group,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
        ];
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array{name: string, slug: string, description: string, is_system: bool, is_default: bool, is_editable: bool, is_deletable: bool, is_active: bool, permissions: array<int, string>}
     */
    private function role(
        string $name,
        string $slug,
        string $description,
        array $permissions,
        bool $isEditable = true,
        bool $isDeletable = false,
    ): array {
        return [
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'is_system' => true,
            'is_default' => true,
            'is_editable' => $isEditable,
            'is_deletable' => $isDeletable,
            'is_active' => true,
            'permissions' => array_values(array_unique($permissions)),
        ];
    }
}
