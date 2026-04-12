<?php

namespace App\Support;

class WelcomeShowcaseSection
{
    private const SECTION_BASE = [
        'layout' => 'feature_tabs',
        'background_color' => '#f7f2e8',
        'image_position' => 'left',
        'alignment' => 'center',
        'density' => 'normal',
        'tone' => 'default',
        'feature_tabs_style' => 'workflow',
        'feature_tabs_font_size' => 28,
    ];

    private const COPY = [
        'fr' => [
            'kicker' => 'Une plateforme sur tout le parcours client',
            'title' => 'Voyez comment Malikia Pro soutient la croissance du premier clic jusqu’au paiement final',
            'body' => '<p>Chaque étape du business reste connectée pour que marketing, devis, exécution et revenus ne vivent pas dans des outils séparés.</p>',
            'feature_tabs' => [
                [
                    'id' => 'welcome-showcase-fr-1',
                    'label' => 'Se faire remarquer',
                    'icon' => 'clipboard-check',
                    'title' => 'Transformez votre visibilité en demandes qualifiées sans casser le parcours client',
                    'body' => '<p>Pages publiques, formulaires, campagnes et suivi restent alignés du premier clic jusqu’au premier vrai échange.</p>',
                    'items' => ['Avis', 'Demandes', 'Campagnes', 'Liens'],
                    'cta_label' => 'Explorer Marketing & Loyalty',
                    'cta_href' => '/pages/marketing-loyalty',
                    'image_key' => 'get_noticed',
                ],
                [
                    'id' => 'welcome-showcase-fr-2',
                    'label' => 'Gagner des jobs',
                    'icon' => 'file-text',
                    'title' => 'Envoyez plus vite vos devis, relancez mieux et convertissez plus de demandes',
                    'body' => '<p>Contexte client, modèles, options et validations restent dans un même flux commercial que l’équipe peut vraiment piloter.</p>',
                    'items' => ['Qualification', 'Modèles', 'Options', 'Relances'],
                    'cta_label' => 'Explorer Sales & CRM',
                    'cta_href' => '/pages/sales-crm',
                    'image_key' => 'win_jobs',
                ],
                [
                    'id' => 'welcome-showcase-fr-3',
                    'label' => 'Faire tourner les opérations',
                    'icon' => 'calendar-days',
                    'title' => 'Gardez coordination, planning et exécution connectés',
                    'body' => '<p>Dispatch, jobs, checklists, mises à jour et historique restent visibles pour toute l’équipe au lieu de se perdre dans des canaux parallèles.</p>',
                    'items' => ['Planning', 'Dispatch', 'Checklists', 'Historique'],
                    'cta_label' => 'Explorer Operations',
                    'cta_href' => '/pages/operations',
                    'image_key' => 'work_smarter',
                ],
                [
                    'id' => 'welcome-showcase-fr-4',
                    'label' => 'Protéger les revenus',
                    'icon' => 'circle-dollar-sign',
                    'title' => 'Passez du travail réalisé à la facturation avec moins d’administration',
                    'body' => '<p>Factures, rappels et flux de paiement restent liés au travail effectué pour raccourcir le délai d’encaissement et mieux protéger le revenu.</p>',
                    'items' => ['Factures', 'Paiements', 'Rappels', 'Rapports'],
                    'cta_label' => 'Explorer Commerce',
                    'cta_href' => '/pages/commerce',
                    'image_key' => 'boost_profits',
                ],
            ],
        ],
        'es' => [
            'kicker' => 'Un solo sistema para todo el recorrido del cliente',
            'title' => 'Descubre como Malikia Pro impulsa el crecimiento desde el primer clic hasta el pago final',
            'body' => '<p>Cada etapa del negocio permanece conectada para que marketing, cotizaciones, ejecucion e ingresos no vivan en herramientas separadas.</p>',
            'feature_tabs' => [
                [
                    'id' => 'welcome-showcase-es-1',
                    'label' => 'Hazte visible',
                    'icon' => 'clipboard-check',
                    'title' => 'Convierte la visibilidad en solicitudes calificadas sin romper el recorrido del cliente',
                    'body' => '<p>Paginas publicas, formularios, campanas y seguimiento permanecen alineados desde el primer clic hasta la primera conversacion real.</p>',
                    'items' => ['Resenas', 'Solicitudes', 'Campanas', 'Enlaces'],
                    'cta_label' => 'Explorar Marketing & Loyalty',
                    'cta_href' => '/pages/marketing-loyalty',
                    'image_key' => 'get_noticed',
                ],
                [
                    'id' => 'welcome-showcase-es-2',
                    'label' => 'Gana trabajos',
                    'icon' => 'file-text',
                    'title' => 'Cotiza mas rapido, haz mejor seguimiento y convierte mas demanda en aprobaciones',
                    'body' => '<p>El contexto del cliente, las plantillas, las opciones y las aprobaciones permanecen dentro de un flujo comercial que tu equipo puede gestionar de verdad.</p>',
                    'items' => ['Calificacion', 'Plantillas', 'Opciones', 'Seguimiento'],
                    'cta_label' => 'Explorar Sales & CRM',
                    'cta_href' => '/pages/sales-crm',
                    'image_key' => 'win_jobs',
                ],
                [
                    'id' => 'welcome-showcase-es-3',
                    'label' => 'Haz funcionar las operaciones',
                    'icon' => 'calendar-days',
                    'title' => 'Mantiene conectadas la coordinacion, la planificacion y la ejecucion',
                    'body' => '<p>Dispatch, trabajos, listas de control, actualizaciones e historial permanecen visibles para todo el equipo en lugar de perderse en canales paralelos.</p>',
                    'items' => ['Planificacion', 'Dispatch', 'Listas', 'Historial'],
                    'cta_label' => 'Explorar Operations',
                    'cta_href' => '/pages/operations',
                    'image_key' => 'work_smarter',
                ],
                [
                    'id' => 'welcome-showcase-es-4',
                    'label' => 'Protege los ingresos',
                    'icon' => 'circle-dollar-sign',
                    'title' => 'Convierte el trabajo completado en facturas y pagos con menos carga administrativa',
                    'body' => '<p>Las facturas, los recordatorios y el flujo de pagos permanecen vinculados al trabajo realizado para que sea mas facil cobrar y proteger los ingresos.</p>',
                    'items' => ['Facturas', 'Pagos', 'Recordatorios', 'Informes'],
                    'cta_label' => 'Explorar Commerce',
                    'cta_href' => '/pages/commerce',
                    'image_key' => 'boost_profits',
                ],
            ],
        ],
        'en' => [
            'kicker' => 'One system across the full customer journey',
            'title' => 'See how Malikia Pro supports growth from first click to final payment',
            'body' => '<p>Every stage of the business stays connected so marketing, quoting, execution, and revenue do not live in separate tools.</p>',
            'feature_tabs' => [
                [
                    'id' => 'welcome-showcase-en-1',
                    'label' => 'Get Noticed',
                    'icon' => 'clipboard-check',
                    'title' => 'Turn visibility into qualified requests without breaking the customer journey',
                    'body' => '<p>Public pages, intake forms, campaigns, and follow-up stay aligned from the first click to the first real conversation.</p>',
                    'items' => ['Reviews', 'Requests', 'Campaigns', 'Links'],
                    'cta_label' => 'Explore Marketing & Loyalty',
                    'cta_href' => '/pages/marketing-loyalty',
                    'image_key' => 'get_noticed',
                ],
                [
                    'id' => 'welcome-showcase-en-2',
                    'label' => 'Win work',
                    'icon' => 'file-text',
                    'title' => 'Quote faster, follow up better, and move more demand to approval',
                    'body' => '<p>Customer context, templates, options, and approvals stay inside one commercial workflow your team can actually manage.</p>',
                    'items' => ['Qualification', 'Templates', 'Upsells', 'Follow-ups'],
                    'cta_label' => 'Explore Sales & CRM',
                    'cta_href' => '/pages/sales-crm',
                    'image_key' => 'win_jobs',
                ],
                [
                    'id' => 'welcome-showcase-en-3',
                    'label' => 'Run operations',
                    'icon' => 'calendar-days',
                    'title' => 'Keep office coordination, scheduling, and execution connected',
                    'body' => '<p>Dispatch, jobs, checklists, updates, and history stay visible to the whole team instead of getting lost in side channels.</p>',
                    'items' => ['Scheduling', 'Dispatch', 'Checklists', 'History'],
                    'cta_label' => 'Explore Operations',
                    'cta_href' => '/pages/operations',
                    'image_key' => 'work_smarter',
                ],
                [
                    'id' => 'welcome-showcase-en-4',
                    'label' => 'Protect revenue',
                    'icon' => 'circle-dollar-sign',
                    'title' => 'Turn completed work into invoices and payments with less admin overhead',
                    'body' => '<p>Invoices, reminders, and payment flow stay linked to the work that was delivered so revenue is easier to collect and protect.</p>',
                    'items' => ['Invoices', 'Payments', 'Reminders', 'Reporting'],
                    'cta_label' => 'Explore Commerce',
                    'cta_href' => '/pages/commerce',
                    'image_key' => 'boost_profits',
                ],
            ],
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function payload(string $locale): array
    {
        $resolvedLocale = WelcomeStockImages::normalizeLocale($locale);
        $copy = self::copyForLocale($resolvedLocale);

        return array_replace(self::SECTION_BASE, [
            'kicker' => (string) ($copy['kicker'] ?? ''),
            'title' => (string) ($copy['title'] ?? ''),
            'body' => (string) ($copy['body'] ?? ''),
            'feature_tabs' => array_values(array_map(
                fn ($tab) => self::featureTab(is_array($tab) ? $tab : [], $resolvedLocale),
                is_array($copy['feature_tabs'] ?? null) ? $copy['feature_tabs'] : []
            )),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function copyForLocale(string $locale): array
    {
        return array_replace_recursive(
            self::COPY['en'],
            self::COPY[$locale] ?? []
        );
    }

    /**
     * @param  array<string, mixed>  $tab
     * @return array<string, mixed>
     */
    private static function featureTab(array $tab, string $locale): array
    {
        $image = WelcomeStockImages::showcaseImage((string) ($tab['image_key'] ?? 'default'), $locale);

        unset($tab['image_key']);

        return array_replace($tab, [
            'image_url' => $image['image_url'],
            'image_alt' => $image['image_alt'],
        ]);
    }
}
