<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PlatformAsset;
use App\Models\PlatformPage;
use App\Models\PlatformSection;
use App\Services\PlatformPageContentService;
use App\Services\PlatformSectionContentService;

$userId = 1;
$page = PlatformPage::where('slug', 'welcome')->firstOrFail();
$pageService = app(PlatformPageContentService::class);
$sectionService = app(PlatformSectionContentService::class);

$ensureAsset = static function (string $name, string $relativePath, array $tags, string $alt, int $userId): void {
    $fullPath = storage_path('app/public/'.ltrim($relativePath, '/'));

    if (! is_file($fullPath)) {
        return;
    }

    PlatformAsset::query()->firstOrCreate(
        ['path' => $relativePath],
        [
            'name' => $name,
            'mime' => mime_content_type($fullPath) ?: 'image/png',
            'size' => (int) filesize($fullPath),
            'tags' => $tags,
            'alt' => $alt,
            'uploaded_by' => $userId,
        ]
    );
};

$ensureAsset(
    'Dashboard mobile screenshot',
    'assets/dashboard-mobile-main.png',
    ['module-screenshot', 'public-pages', 'mobile', 'dashboard'],
    'Capture mobile du tableau de bord',
    $userId
);
$ensureAsset(
    'Quotes mobile screenshot',
    'assets/quotes-mobile-main.png',
    ['module-screenshot', 'public-pages', 'mobile', 'quotes'],
    'Capture mobile du module Devis',
    $userId
);
$ensureAsset(
    'Jobs mobile screenshot',
    'assets/jobs-mobile-main.png',
    ['module-screenshot', 'public-pages', 'mobile', 'jobs'],
    'Capture mobile du module Chantiers',
    $userId
);
$ensureAsset(
    'Loyalty mobile screenshot',
    'assets/loyalty-mobile-main.png',
    ['module-screenshot', 'public-pages', 'mobile', 'loyalty'],
    'Capture mobile du module Fidelite',
    $userId
);
$ensureAsset(
    'Campaign create mobile screenshot',
    'assets/campaign-create-mobile-main.png',
    ['module-screenshot', 'public-pages', 'mobile', 'campaigns'],
    'Capture mobile de creation de campagne marketing',
    $userId
);

$assets = [
    'sales' => 'http://mlkpro.test/storage/assets/module-sales-crm-quotes.png',
    'reservations' => 'http://mlkpro.test/storage/assets/module-reservations-calendar.png',
    'operations' => 'http://mlkpro.test/storage/assets/module-operations-jobs.png',
    'commerce' => 'http://mlkpro.test/storage/assets/module-commerce-products.png',
    'dashboard' => 'http://mlkpro.test/storage/assets/module-command-center-dashboard.png',
    'loyalty' => 'http://mlkpro.test/storage/assets/module-marketing-loyalty-loyalty.png',
    'campaigns' => 'http://mlkpro.test/storage/assets/module-marketing-loyalty-campaign-create.png',
    'dashboard_mobile' => 'http://mlkpro.test/storage/assets/dashboard-mobile-main.png',
    'quotes_mobile' => 'http://mlkpro.test/storage/assets/quotes-mobile-main.png',
    'jobs_mobile' => 'http://mlkpro.test/storage/assets/jobs-mobile-main.png',
    'loyalty_mobile' => 'http://mlkpro.test/storage/assets/loyalty-mobile-main.png',
    'campaigns_mobile' => 'http://mlkpro.test/storage/assets/campaign-create-mobile-main.png',
];

$sectionPayloads = [
    2 => [
        'fr' => [
            'layout' => 'stack',
            'alignment' => 'center',
            'density' => 'compact',
            'tone' => 'muted',
            'title' => 'Pour les pros qui ne veulent plus jongler entre quatre outils',
            'items' => ['Plomberie', 'HVAC', 'Electricite', 'Nettoyage', 'Salon & beaute', 'Restaurant'],
        ],
        'en' => [
            'layout' => 'stack',
            'alignment' => 'center',
            'density' => 'compact',
            'tone' => 'muted',
            'title' => 'For teams that no longer want to juggle four disconnected tools',
            'items' => ['Plumbing', 'HVAC', 'Electrical', 'Cleaning', 'Salon & beauty', 'Restaurant'],
        ],
    ],
    3 => [
        'fr' => [
            'layout' => 'feature_tabs',
            'kicker' => 'Un systeme clair du premier contact jusqu au paiement',
            'title' => 'Suivez tout le parcours client sans changer d outil a chaque etape',
            'body' => '<p>Au lieu d empiler marketing, devis, planning et facturation, MALIKIA relie les moments qui font vraiment avancer un business terrain.</p>',
            'feature_tabs_font_size' => 28,
            'feature_tabs' => [
                [
                    'id' => 'welcome-fr-convert',
                    'label' => 'Convertir la demande',
                    'icon' => 'file-text',
                    'title' => 'Passez du contact entrant au devis sans recopie',
                    'body' => '<p>Centralisez les demandes, cadrez le besoin, proposez un devis propre et gardez le suivi visible.</p>',
                    'items' => ['Demandes et qualification', 'Devis clairs', 'Relances simples', 'Historique client'],
                    'image_url' => $assets['sales'],
                    'image_alt' => 'Capture du module Devis',
                    'cta_label' => 'Voir Sales & CRM',
                    'cta_href' => '/pages/sales-crm',
                    'metric' => 'Lead -> devis -> suivi',
                    'story' => '<p>Quand une demande arrive, l equipe ne perd plus le fil entre message, estimation et validation.</p>',
                    'person' => 'Equipe commerciale',
                    'role' => 'Bureau et suivi client',
                ],
                [
                    'id' => 'welcome-fr-schedule',
                    'label' => 'Planifier sans friction',
                    'icon' => 'calendar-days',
                    'title' => 'Gardez vos reservations et vos capacites visibles',
                    'body' => '<p>Le planning, les disponibilites et les rendez-vous restent lisibles pour le bureau comme pour l accueil.</p>',
                    'items' => ['Calendrier vivant', 'Capacite visible', 'Reservations confirmees', 'Flux plus fluide'],
                    'image_url' => $assets['reservations'],
                    'image_alt' => 'Capture du module Reservations',
                    'cta_label' => 'Voir Reservations',
                    'cta_href' => '/pages/reservations',
                    'metric' => 'Planning, capacite, rendez-vous',
                    'story' => '<p>Les equipes savent ce qui arrive, ce qui change, et ce qui doit etre confirme sans appeler partout.</p>',
                    'person' => 'Accueil et coordination',
                    'role' => 'Planning et capacite',
                ],
                [
                    'id' => 'welcome-fr-deliver',
                    'label' => 'Livrer avec clarte',
                    'icon' => 'clipboard-check',
                    'title' => 'Pilotez le terrain avec un vrai suivi d execution',
                    'body' => '<p>Statuts, priorites, preuves et contexte restent attaches au meme flux de travail du bureau au terrain.</p>',
                    'items' => ['Dispatch lisible', 'Statuts coherents', 'Preuves d execution', 'Suivi client'],
                    'image_url' => $assets['operations'],
                    'image_alt' => 'Capture du module Chantiers',
                    'cta_label' => 'Voir Operations',
                    'cta_href' => '/pages/operations',
                    'metric' => 'Dispatch, statut, execution',
                    'story' => '<p>Le chantier avance avec des informations actionnables, pas avec des notes perdues entre messages et appels.</p>',
                    'person' => 'Equipe terrain',
                    'role' => 'Execution et validation',
                ],
                [
                    'id' => 'welcome-fr-cash',
                    'label' => 'Encaisser proprement',
                    'icon' => 'circle-dollar-sign',
                    'title' => 'Reliez catalogue, marge et encaissement',
                    'body' => '<p>Les offres, les prix et le suivi commercial restent connectes a la realite du stock et du revenu.</p>',
                    'items' => ['Catalogue et prix', 'Stock lisible', 'Marge visible', 'Suivi du revenu'],
                    'image_url' => $assets['commerce'],
                    'image_alt' => 'Capture du module Produits',
                    'cta_label' => 'Voir Commerce',
                    'cta_href' => '/pages/commerce',
                    'metric' => 'Catalogue, prix, encaissement',
                    'story' => '<p>Le travail realise, les produits proposes et les montants encaisses restent dans le meme systeme.</p>',
                    'person' => 'Gestion et revenu',
                    'role' => 'Offres, marge et encaissement',
                ],
            ],
        ],
        'en' => [
            'layout' => 'feature_tabs',
            'kicker' => 'One clear system from first contact to final payment',
            'title' => 'Follow the full customer journey without switching tools at every step',
            'body' => '<p>Instead of stacking marketing, quotes, scheduling, and billing in separate apps, MALIKIA connects the moments that actually move a field business forward.</p>',
            'feature_tabs_font_size' => 28,
            'feature_tabs' => [
                [
                    'id' => 'welcome-en-convert',
                    'label' => 'Convert demand',
                    'icon' => 'file-text',
                    'title' => 'Move from inbound request to quote without re-entry',
                    'body' => '<p>Centralize requests, frame the need, send a clean quote, and keep follow-up visible.</p>',
                    'items' => ['Requests and qualification', 'Clean quotes', 'Simple follow-up', 'Customer history'],
                    'image_url' => $assets['sales'],
                    'image_alt' => 'Screenshot of the Quotes module',
                    'cta_label' => 'See Sales & CRM',
                    'cta_href' => '/pages/sales-crm',
                    'metric' => 'Lead -> quote -> follow-up',
                    'story' => '<p>When a new request lands, the team no longer loses the thread between message, estimate, and approval.</p>',
                    'person' => 'Sales team',
                    'role' => 'Office and customer follow-up',
                ],
                [
                    'id' => 'welcome-en-schedule',
                    'label' => 'Schedule clearly',
                    'icon' => 'calendar-days',
                    'title' => 'Keep bookings and capacity visible',
                    'body' => '<p>Scheduling, availability, and reservations stay readable for the office and the front desk.</p>',
                    'items' => ['Live calendar', 'Visible capacity', 'Confirmed bookings', 'Smoother flow'],
                    'image_url' => $assets['reservations'],
                    'image_alt' => 'Screenshot of the Reservations module',
                    'cta_label' => 'See Reservations',
                    'cta_href' => '/pages/reservations',
                    'metric' => 'Scheduling, capacity, bookings',
                    'story' => '<p>Teams know what is coming, what changed, and what still needs confirmation without chasing information.</p>',
                    'person' => 'Front desk and coordination',
                    'role' => 'Planning and capacity',
                ],
                [
                    'id' => 'welcome-en-deliver',
                    'label' => 'Deliver with clarity',
                    'icon' => 'clipboard-check',
                    'title' => 'Run field work with a real execution view',
                    'body' => '<p>Status, priorities, proof, and customer context stay tied to the same workflow from office to field.</p>',
                    'items' => ['Readable dispatch', 'Clear statuses', 'Proof of work', 'Customer follow-through'],
                    'image_url' => $assets['operations'],
                    'image_alt' => 'Screenshot of the Jobs module',
                    'cta_label' => 'See Operations',
                    'cta_href' => '/pages/operations',
                    'metric' => 'Dispatch, status, execution',
                    'story' => '<p>The job moves forward with actionable information, not with context scattered across calls and messages.</p>',
                    'person' => 'Field team',
                    'role' => 'Execution and validation',
                ],
                [
                    'id' => 'welcome-en-cash',
                    'label' => 'Protect revenue',
                    'icon' => 'circle-dollar-sign',
                    'title' => 'Connect catalog, margin, and cash collection',
                    'body' => '<p>Offers, pricing, and commercial follow-through stay connected to inventory and revenue reality.</p>',
                    'items' => ['Catalog and pricing', 'Visible inventory', 'Margin awareness', 'Revenue tracking'],
                    'image_url' => $assets['commerce'],
                    'image_alt' => 'Screenshot of the Products module',
                    'cta_label' => 'See Commerce',
                    'cta_href' => '/pages/commerce',
                    'metric' => 'Catalog, pricing, cash collection',
                    'story' => '<p>Completed work, proposed products, and collected amounts remain in the same operating system.</p>',
                    'person' => 'Management and revenue',
                    'role' => 'Offers, margin, and cash collection',
                ],
            ],
        ],
    ],
    7 => [
        'fr' => [
            'layout' => 'stack',
            'title' => 'Pret a mettre un vrai systeme derriere vos operations ?',
            'body' => 'Creez votre espace en quelques minutes ou parlez a notre equipe si vous voulez un parcours plus guide.',
            'primary_label' => 'Creer un espace',
            'primary_href' => 'onboarding.index',
            'secondary_label' => 'Parler a l equipe',
            'secondary_href' => '/pages/contact-us',
        ],
        'en' => [
            'layout' => 'stack',
            'title' => 'Ready to put a real system behind your operations?',
            'body' => 'Create your workspace in minutes or talk to our team if you want a more guided rollout.',
            'primary_label' => 'Create workspace',
            'primary_href' => 'onboarding.index',
            'secondary_label' => 'Talk to the team',
            'secondary_href' => '/pages/contact-us',
        ],
    ],
];

foreach ($sectionPayloads as $sectionId => $locales) {
    $section = PlatformSection::findOrFail($sectionId);
    foreach ($locales as $locale => $payload) {
        $sectionService->updateLocale($section, $locale, $payload, $userId);
        $section->refresh();
    }
}

$welcomeLayouts = [
    'fr' => [
        [
            'id' => 'welcome-section-1',
            'enabled' => true,
            'source_id' => 1,
            'use_source' => true,
            'layout' => 'split',
        ],
        [
            'id' => 'welcome-section-2',
            'enabled' => true,
            'source_id' => 2,
            'use_source' => true,
            'layout' => 'stack',
        ],
        [
            'id' => 'welcome-section-3',
            'enabled' => true,
            'source_id' => 3,
            'use_source' => true,
            'layout' => 'feature_tabs',
        ],
        [
            'id' => 'welcome-section-4',
            'enabled' => false,
            'source_id' => 4,
            'use_source' => true,
            'layout' => 'stack',
        ],
        [
            'id' => 'welcome-section-5',
            'enabled' => false,
            'source_id' => 5,
            'use_source' => true,
            'layout' => 'split',
        ],
        [
            'id' => 'welcome-section-6',
            'enabled' => false,
            'source_id' => 6,
            'use_source' => true,
            'layout' => 'split',
        ],
        [
            'id' => 'welcome-industry-grid',
            'enabled' => true,
            'layout' => 'industry_grid',
            'background_color' => '#f7f2e8',
            'alignment' => 'center',
            'kicker' => 'Par metier',
            'title' => 'Choisissez la page qui parle deja votre langage',
            'body' => '<p>Commencez par votre contexte metier, puis descendez vers les modules et les solutions qui collent vraiment a votre facon de travailler.</p>',
            'industry_cards' => [
                ['id' => 'industry-plumbing', 'label' => 'Plomberie', 'href' => '/pages/industry-plumbing', 'icon' => 'shower-head'],
                ['id' => 'industry-hvac', 'label' => 'HVAC', 'href' => '/pages/industry-hvac', 'icon' => 'fan'],
                ['id' => 'industry-electrical', 'label' => 'Electricite', 'href' => '/pages/industry-electrical', 'icon' => 'plug-zap'],
                ['id' => 'industry-cleaning', 'label' => 'Nettoyage', 'href' => '/pages/industry-cleaning', 'icon' => 'brush-cleaning'],
                ['id' => 'industry-salon-beauty', 'label' => 'Salon & beaute', 'href' => '/pages/industry-salon-beauty', 'icon' => 'sparkles'],
                ['id' => 'industry-restaurant', 'label' => 'Restaurant', 'href' => '/pages/industry-restaurant', 'icon' => 'house'],
            ],
        ],
        [
            'id' => 'welcome-office-field',
            'enabled' => true,
            'layout' => 'feature_pairs',
            'background_color' => '#ffffff',
            'alignment' => 'left',
            'kicker' => 'Bureau et terrain',
            'title' => 'Le bureau garde la vue d ensemble, le terrain garde le contexte',
            'body' => '<p>Devis, planning, execution et suivi client reposent sur la meme base d information. Moins de double saisie, moins d oublis, plus de contexte utile.</p>',
            'items' => [
                'Une meme base du lead au chantier',
                'Des statuts lisibles pour tout le monde',
                'Des relances, rendez-vous et validations relies',
            ],
            'image_url' => $assets['dashboard'],
            'image_alt' => 'Capture du tableau de bord Command Center',
            'primary_label' => 'Voir le Command Center',
            'primary_href' => '/pages/command-center',
            'aside_kicker' => 'Execution',
            'aside_title' => 'Les equipes avancent aussi sur mobile avec des priorites, des statuts et des preuves visibles',
            'aside_body' => '<p>Sur place, chacun retrouve le bon rendez-vous, le bon contexte et les bonnes validations depuis une vue mobile plus directe.</p>',
            'aside_items' => [
                'Vue mobile pour le terrain',
                'Chantiers et priorites visibles',
                'Validation client et progression claires',
            ],
            'aside_link_label' => 'Voir Operations',
            'aside_link_href' => '/pages/operations',
            'aside_image_url' => $assets['jobs_mobile'],
            'aside_image_alt' => 'Capture mobile du module Chantiers',
        ],
        [
            'id' => 'welcome-growth-loop',
            'enabled' => true,
            'layout' => 'showcase_cta',
            'background_color' => '#f6efe5',
            'alignment' => 'left',
            'image_position' => 'right',
            'kicker' => 'Apres l intervention',
            'title' => 'Le travail termine peut encore nourrir la croissance',
            'body' => '<p>Relance, fidelisation et campagnes restent branches sur les bons signaux plutot que dans un outil marketing isole. Vous continuez a faire vivre la relation client apres la vente ou le chantier.</p>',
            'primary_label' => 'Voir Marketing & Loyalty',
            'primary_href' => '/pages/marketing-loyalty',
            'secondary_label' => 'Voir Sales & CRM',
            'secondary_href' => '/pages/sales-crm',
            'image_url' => $assets['campaigns'],
            'image_alt' => 'Capture de creation de campagne marketing',
            'aside_link_label' => 'Voir la solution marketing complete',
            'aside_link_href' => '/pages/solution-marketing-loyalty',
            'aside_image_url' => $assets['loyalty_mobile'],
            'aside_image_alt' => 'Capture mobile du module Fidelite',
        ],
        [
            'id' => 'welcome-section-7',
            'enabled' => true,
            'source_id' => 7,
            'use_source' => true,
            'layout' => 'stack',
        ],
    ],
    'en' => [
        [
            'id' => 'welcome-section-1',
            'enabled' => true,
            'source_id' => 1,
            'use_source' => true,
            'layout' => 'split',
        ],
        [
            'id' => 'welcome-section-2',
            'enabled' => true,
            'source_id' => 2,
            'use_source' => true,
            'layout' => 'stack',
        ],
        [
            'id' => 'welcome-section-3',
            'enabled' => true,
            'source_id' => 3,
            'use_source' => true,
            'layout' => 'feature_tabs',
        ],
        [
            'id' => 'welcome-section-4',
            'enabled' => false,
            'source_id' => 4,
            'use_source' => true,
            'layout' => 'stack',
        ],
        [
            'id' => 'welcome-section-5',
            'enabled' => false,
            'source_id' => 5,
            'use_source' => true,
            'layout' => 'split',
        ],
        [
            'id' => 'welcome-section-6',
            'enabled' => false,
            'source_id' => 6,
            'use_source' => true,
            'layout' => 'split',
        ],
        [
            'id' => 'welcome-industry-grid',
            'enabled' => true,
            'layout' => 'industry_grid',
            'background_color' => '#f7f2e8',
            'alignment' => 'center',
            'kicker' => 'By trade',
            'title' => 'Choose the page that already speaks your language',
            'body' => '<p>Start from your business context, then move into the modules and solutions that actually match how you operate.</p>',
            'industry_cards' => [
                ['id' => 'industry-plumbing', 'label' => 'Plumbing', 'href' => '/pages/industry-plumbing', 'icon' => 'shower-head'],
                ['id' => 'industry-hvac', 'label' => 'HVAC', 'href' => '/pages/industry-hvac', 'icon' => 'fan'],
                ['id' => 'industry-electrical', 'label' => 'Electrical', 'href' => '/pages/industry-electrical', 'icon' => 'plug-zap'],
                ['id' => 'industry-cleaning', 'label' => 'Cleaning', 'href' => '/pages/industry-cleaning', 'icon' => 'brush-cleaning'],
                ['id' => 'industry-salon-beauty', 'label' => 'Salon & beauty', 'href' => '/pages/industry-salon-beauty', 'icon' => 'sparkles'],
                ['id' => 'industry-restaurant', 'label' => 'Restaurant', 'href' => '/pages/industry-restaurant', 'icon' => 'house'],
            ],
        ],
        [
            'id' => 'welcome-office-field',
            'enabled' => true,
            'layout' => 'feature_pairs',
            'background_color' => '#ffffff',
            'alignment' => 'left',
            'kicker' => 'Office and field',
            'title' => 'The office keeps the full picture, the field keeps the right context',
            'body' => '<p>Quotes, scheduling, execution, and customer follow-through sit on the same operating layer. Less re-entry, fewer misses, more useful context.</p>',
            'items' => [
                'One base from lead to job',
                'Readable status for everyone',
                'Follow-up, bookings, and validation connected',
            ],
            'image_url' => $assets['dashboard'],
            'image_alt' => 'Screenshot of the Command Center dashboard',
            'primary_label' => 'See Command Center',
            'primary_href' => '/pages/command-center',
            'aside_kicker' => 'Execution',
            'aside_title' => 'Teams also move forward on mobile with visible priorities, statuses, and proof',
            'aside_body' => '<p>On site, crews can find the right booking, the right context, and the right customer validation from a more direct mobile view.</p>',
            'aside_items' => [
                'Mobile view for field teams',
                'Jobs and priorities stay visible',
                'Clear customer validation and progress',
            ],
            'aside_link_label' => 'See Operations',
            'aside_link_href' => '/pages/operations',
            'aside_image_url' => $assets['jobs_mobile'],
            'aside_image_alt' => 'Mobile screenshot of the Jobs module',
        ],
        [
            'id' => 'welcome-growth-loop',
            'enabled' => true,
            'layout' => 'showcase_cta',
            'background_color' => '#f6efe5',
            'alignment' => 'left',
            'image_position' => 'right',
            'kicker' => 'After the job',
            'title' => 'Completed work can still drive the next wave of growth',
            'body' => '<p>Follow-up, loyalty, and campaigns stay connected to the right business signals instead of living in a disconnected marketing tool. The customer relationship keeps moving after the sale or the job is done.</p>',
            'primary_label' => 'See Marketing & Loyalty',
            'primary_href' => '/pages/marketing-loyalty',
            'secondary_label' => 'See Sales & CRM',
            'secondary_href' => '/pages/sales-crm',
            'image_url' => $assets['campaigns'],
            'image_alt' => 'Screenshot of the campaign creation flow',
            'aside_link_label' => 'See the full marketing solution',
            'aside_link_href' => '/pages/solution-marketing-loyalty',
            'aside_image_url' => $assets['loyalty_mobile'],
            'aside_image_alt' => 'Mobile screenshot of the Loyalty module',
        ],
        [
            'id' => 'welcome-section-7',
            'enabled' => true,
            'source_id' => 7,
            'use_source' => true,
            'layout' => 'stack',
        ],
    ],
];

foreach (['fr', 'en'] as $locale) {
    $resolved = $pageService->resolveForLocale($page, $locale);

    $pageService->updateLocale($page, $locale, [
        'page_title' => $resolved['page_title'] ?? '',
        'page_subtitle' => $resolved['page_subtitle'] ?? '',
        'header' => $resolved['header'] ?? [],
        'sections' => $welcomeLayouts[$locale],
    ], $userId);

    $page->refresh();
}

echo "welcome updated\n";
