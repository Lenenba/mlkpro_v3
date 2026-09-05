<?php

namespace App\Services\Rbac;

use Illuminate\Support\Str;

final readonly class CompanyRoleTemplateCatalog
{
    private const SERVICE_ROLE_NAMES = [
        'salon' => [
            'manager' => 'Gestionnaire de salon',
            'specialist' => 'Professionnel coiffure et beauté',
            'coordinator' => 'Accueil et réception',
        ],
        'restaurant' => [
            'manager' => 'Gestionnaire de restaurant',
            'specialist' => 'Équipe de service',
            'coordinator' => 'Accueil et caisse',
        ],
        'menuiserie' => [
            'manager' => 'Gestionnaire des opérations',
            'specialist' => 'Menuisier et installateur',
            'coordinator' => 'Coordination des interventions',
        ],
        'plomberie' => [
            'manager' => 'Gestionnaire des opérations',
            'specialist' => 'Plombier et technicien',
            'coordinator' => 'Coordination des interventions',
        ],
        'electricite' => [
            'manager' => 'Gestionnaire des opérations',
            'specialist' => 'Électricien et technicien',
            'coordinator' => 'Coordination des interventions',
        ],
        'peinture' => [
            'manager' => 'Gestionnaire des opérations',
            'specialist' => 'Peintre et applicateur',
            'coordinator' => 'Coordination des interventions',
        ],
        'toiture' => [
            'manager' => 'Gestionnaire des opérations',
            'specialist' => 'Couvreur et technicien',
            'coordinator' => 'Coordination des interventions',
        ],
        'renovation' => [
            'manager' => 'Gestionnaire des opérations',
            'specialist' => 'Intervenant de chantier',
            'coordinator' => 'Coordination des interventions',
        ],
        'paysagisme' => [
            'manager' => 'Gestionnaire des opérations',
            'specialist' => 'Paysagiste et technicien',
            'coordinator' => 'Coordination des interventions',
        ],
        'climatisation' => [
            'manager' => 'Gestionnaire des opérations',
            'specialist' => 'Technicien CVAC',
            'coordinator' => 'Coordination des interventions',
        ],
        'nettoyage' => [
            'manager' => 'Gestionnaire des opérations',
            'specialist' => 'Préposé à l’entretien',
            'coordinator' => 'Coordination des interventions',
        ],
        'service_general' => [
            'manager' => 'Gestionnaire des opérations',
            'specialist' => 'Prestataire de service',
            'coordinator' => 'Coordination clients',
        ],
    ];

    private const PRODUCT_ROLE_NAMES = [
        'retail' => [
            'manager' => 'Gestionnaire de boutique',
            'sales' => 'Vente et caisse',
            'inventory' => 'Inventaire et approvisionnement',
        ],
        'wholesale' => [
            'manager' => 'Gestionnaire commercial',
            'sales' => 'Ventes commerciales',
            'inventory' => 'Inventaire et expédition',
        ],
        'grocery' => [
            'manager' => 'Gestionnaire d’épicerie',
            'sales' => 'Vente et caisse',
            'inventory' => 'Stock et réapprovisionnement',
        ],
        'convenience' => [
            'manager' => 'Gestionnaire de commerce',
            'sales' => 'Vente et caisse',
            'inventory' => 'Stock et réapprovisionnement',
        ],
        'specialty' => [
            'manager' => 'Gestionnaire de boutique spécialisée',
            'sales' => 'Conseil et vente',
            'inventory' => 'Inventaire et approvisionnement',
        ],
        'pharmacy' => [
            'manager' => 'Gestionnaire de pharmacie',
            'sales' => 'Conseil et caisse',
            'inventory' => 'Inventaire et approvisionnement',
        ],
        'electronics' => [
            'manager' => 'Gestionnaire de magasin',
            'sales' => 'Conseil et vente',
            'inventory' => 'Inventaire et approvisionnement',
        ],
        'home_hardware' => [
            'manager' => 'Gestionnaire de quincaillerie',
            'sales' => 'Conseil et caisse',
            'inventory' => 'Inventaire et approvisionnement',
        ],
    ];

    public function __construct(private PermissionCatalog $permissionCatalog) {}

    /**
     * @return array<int, array{
     *     slug: string,
     *     name: string,
     *     description: string,
     *     permissions: array<int, string>,
     *     invitation_roles: array<int, string>
     * }>
     */
    public function templatesFor(?string $companyType, ?string $sector): array
    {
        $normalizedType = $this->normalize($companyType);
        $normalizedSector = $this->normalize($sector);

        if ($normalizedType === 'products') {
            return $this->productTemplates($normalizedSector, $sector);
        }

        return $this->serviceTemplates($normalizedSector, $sector);
    }

    /**
     * @return array<string, string>
     */
    public function invitationRoleSlugs(?string $companyType, ?string $sector): array
    {
        $roleSlugs = [];

        foreach ($this->templatesFor($companyType, $sector) as $template) {
            foreach ($template['invitation_roles'] as $invitationRole) {
                $roleSlugs[$invitationRole] = $template['slug'];
            }
        }

        return $roleSlugs;
    }

    /**
     * @return array<int, array{
     *     slug: string,
     *     name: string,
     *     description: string,
     *     permissions: array<int, string>,
     *     invitation_roles: array<int, string>
     * }>
     */
    private function serviceTemplates(string $sector, ?string $originalSector): array
    {
        $names = self::SERVICE_ROLE_NAMES[$sector] ?? [
            'manager' => 'Gestionnaire des opérations',
            'specialist' => 'Spécialiste de service',
            'coordinator' => 'Coordination clients',
        ];
        $sectorLabel = $this->sectorLabel($originalSector, 'services');
        $appointmentLed = in_array($sector, ['salon', 'wellness', 'restaurant'], true);

        return [
            $this->template(
                'standard_manager',
                $names['manager'],
                "Supervise les opérations, l’équipe et le suivi client pour le secteur {$sectorLabel}.",
                $this->serviceManagerPermissions($sector, $appointmentLed),
                ['admin'],
            ),
            $this->template(
                'standard_specialist',
                $names['specialist'],
                "Exécute les services quotidiens et consulte les dossiers nécessaires pour le secteur {$sectorLabel}.",
                $this->serviceSpecialistPermissions($sector),
                ['member'],
            ),
            $this->template(
                'standard_coordinator',
                $names['coordinator'],
                "Coordonne les clients, les demandes et les activités opérationnelles du secteur {$sectorLabel}.",
                $this->serviceCoordinatorPermissions($sector, $appointmentLed),
            ),
            $this->template(
                'standard_accounting',
                'Comptabilité',
                'Suit la facturation, les dépenses, la comptabilité et les rapports financiers.',
                $this->systemRolePermissions('comptable'),
            ),
        ];
    }

    /**
     * @return array<int, array{
     *     slug: string,
     *     name: string,
     *     description: string,
     *     permissions: array<int, string>,
     *     invitation_roles: array<int, string>
     * }>
     */
    private function productTemplates(string $sector, ?string $originalSector): array
    {
        $names = self::PRODUCT_ROLE_NAMES[$sector] ?? [
            'manager' => 'Gestionnaire de commerce',
            'sales' => 'Vente et service client',
            'inventory' => 'Inventaire et approvisionnement',
        ];
        $sectorLabel = $this->sectorLabel($originalSector, 'commerce');

        return [
            $this->template(
                'standard_manager',
                $names['manager'],
                "Supervise l’équipe, les ventes et les opérations pour le secteur {$sectorLabel}.",
                $this->productManagerPermissions(),
                ['admin'],
            ),
            $this->template(
                'standard_sales',
                $names['sales'],
                "Accompagne les clients et réalise les ventes quotidiennes du secteur {$sectorLabel}.",
                $this->productSalesPermissions(),
                ['member'],
            ),
            $this->template(
                'standard_inventory',
                $names['inventory'],
                'Gère le catalogue, les stocks, les ajustements et le réapprovisionnement.',
                $this->productInventoryPermissions(),
            ),
            $this->template(
                'standard_accounting',
                'Comptabilité',
                'Suit la facturation, les dépenses, la comptabilité et les rapports financiers.',
                $this->systemRolePermissions('comptable'),
            ),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function serviceManagerPermissions(string $sector, bool $appointmentLed): array
    {
        $common = [
            'view_clients',
            'create_clients',
            'update_clients',
            'export_clients',
            'view_client_notes',
            'manage_client_notes',
            'view_services',
            'create_services',
            'update_services',
            'delete_services',
            'manage_service_categories',
            'view_team_members',
            'manage_team_schedule',
            'view_presence',
            'manage_own_presence',
            'manage_team_presence',
            'view_presence_reports',
            'view_reports',
            'view_team_reports',
            'export_reports',
        ];

        if ($appointmentLed) {
            $appointmentPermissions = [
                'view_reservations',
                'create_reservations',
                'update_reservations',
                'cancel_reservations',
                'manage_reservation_calendar',
                'manage_reservation_queue',
                'assign_reservations',
                'view_all_reservations',
                'view_sales',
                'create_sales',
                'apply_discount',
                'view_sales_reports',
                'manage_cash_register',
            ];

            if (in_array($sector, ['salon', 'wellness'], true)) {
                $appointmentPermissions = [
                    ...$appointmentPermissions,
                    'view_chairs',
                    'manage_chairs',
                    'assign_chairs_to_members',
                    'activate_chair_on_check_in',
                ];
            }

            return $this->permissions($common, $appointmentPermissions);
        }

        return $this->permissions($common, [
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
            'view_invoices',
            'create_invoices',
            'update_invoices',
            'view_expenses',
            'create_expenses',
            'update_expenses',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function serviceSpecialistPermissions(string $sector): array
    {
        if (in_array($sector, ['salon', 'wellness'], true)) {
            return $this->systemRolePermissions('coiffeur');
        }

        if ($sector === 'restaurant') {
            return $this->permissions($this->systemRolePermissions('employe_standard'), [
                'view_reservations',
                'view_own_reservations',
                'update_reservations',
                'manage_reservation_queue',
                'view_sales',
                'create_sales',
            ]);
        }

        return $this->permissions($this->systemRolePermissions('employe_standard'), [
            'view_jobs',
            'update_jobs',
            'view_quotes',
            'view_prospects',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function serviceCoordinatorPermissions(string $sector, bool $appointmentLed): array
    {
        if ($appointmentLed) {
            $permissions = $this->systemRolePermissions('receptionniste');

            if ($sector === 'restaurant') {
                $permissions = $this->permissions($permissions, [
                    'view_sales',
                    'create_sales',
                    'manage_cash_register',
                ]);
            }

            return $permissions;
        }

        return $this->permissions([
            'view_clients',
            'create_clients',
            'update_clients',
            'view_client_notes',
            'manage_client_notes',
            'view_services',
            'view_jobs',
            'create_jobs',
            'update_jobs',
            'view_tasks',
            'create_tasks',
            'update_tasks',
            'view_quotes',
            'create_quotes',
            'update_quotes',
            'send_quotes',
            'view_prospects',
            'create_prospects',
            'update_prospects',
            'assign_prospects',
            'convert_prospects',
            'view_invoices',
            'create_invoices',
            'update_invoices',
            'view_team_members',
            'view_presence',
            'manage_own_presence',
            'view_reports',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function productManagerPermissions(): array
    {
        return $this->permissions([
            'view_clients',
            'create_clients',
            'update_clients',
            'export_clients',
            'view_client_notes',
            'manage_client_notes',
            'view_products',
            'create_products',
            'update_products',
            'delete_products',
            'manage_inventory',
            'adjust_stock',
            'view_sales',
            'create_sales',
            'refund_sales',
            'apply_discount',
            'view_sales_reports',
            'manage_cash_register',
            'view_storefront',
            'manage_storefront',
            'manage_public_products',
            'view_team_members',
            'manage_team_schedule',
            'view_presence',
            'manage_own_presence',
            'manage_team_presence',
            'view_presence_reports',
            'view_invoices',
            'create_invoices',
            'update_invoices',
            'view_expenses',
            'create_expenses',
            'update_expenses',
            'view_reports',
            'view_team_reports',
            'export_reports',
            'view_tasks',
            'create_tasks',
            'update_tasks',
            'delete_tasks',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function productInventoryPermissions(): array
    {
        return $this->permissions([
            'view_products',
            'create_products',
            'update_products',
            'delete_products',
            'manage_inventory',
            'adjust_stock',
            'view_sales',
            'view_sales_reports',
            'view_storefront',
            'view_presence',
            'manage_own_presence',
            'view_tasks',
            'update_tasks',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function productSalesPermissions(): array
    {
        return $this->permissions([
            'view_clients',
            'create_clients',
            'update_clients',
            'view_products',
            'view_sales',
            'create_sales',
            'manage_cash_register',
            'view_storefront',
            'view_presence',
            'manage_own_presence',
            'view_tasks',
            'update_tasks',
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     * @param  array<int, string>  $invitationRoles
     * @return array{
     *     slug: string,
     *     name: string,
     *     description: string,
     *     permissions: array<int, string>,
     *     invitation_roles: array<int, string>
     * }
     */
    private function template(
        string $slug,
        string $name,
        string $description,
        array $permissions,
        array $invitationRoles = [],
    ): array {
        return [
            'slug' => $slug,
            'name' => Str::limit($name, 255, ''),
            'description' => $description,
            'permissions' => $this->permissions($permissions),
            'invitation_roles' => array_values(array_unique($invitationRoles)),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function systemRolePermissions(string $roleSlug): array
    {
        foreach ($this->permissionCatalog->defaultRoles() as $role) {
            if ($role['slug'] === $roleSlug) {
                return $this->permissions($role['permissions']);
            }
        }

        return [];
    }

    /**
     * @param  array<int, string>  ...$permissionSets
     * @return array<int, string>
     */
    private function permissions(array ...$permissionSets): array
    {
        $allowedPermissions = array_fill_keys($this->permissionCatalog->permissionSlugs(), true);
        $permissions = [];

        foreach ($permissionSets as $permissionSet) {
            foreach ($permissionSet as $permission) {
                if (isset($allowedPermissions[$permission])) {
                    $permissions[$permission] = true;
                }
            }
        }

        return array_keys($permissions);
    }

    private function normalize(?string $value): string
    {
        return Str::of((string) $value)
            ->lower()
            ->trim()
            ->replace([' ', '-'], '_')
            ->toString();
    }

    private function sectorLabel(?string $sector, string $fallback): string
    {
        $label = Str::of((string) $sector)
            ->replace(['_', '-'], ' ')
            ->squish()
            ->toString();

        if ($label === '') {
            return $fallback;
        }

        return Str::limit($label, 80, '');
    }
}
