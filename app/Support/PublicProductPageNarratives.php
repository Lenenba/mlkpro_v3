<?php

namespace App\Support;

class PublicProductPageNarratives
{
    /**
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return [
            'sales-crm',
            'reservations',
            'operations',
            'commerce',
            'marketing-loyalty',
            'ai-automation',
            'command-center',
        ];
    }

    public static function has(string $slug): bool
    {
        return in_array($slug, self::slugs(), true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(string $slug, ?string $locale = 'fr'): array
    {
        $locale = PublicPageStockImages::normalizeLocale($locale);

        return match ($slug) {
            'sales-crm' => self::salesCrmSections($locale),
            'reservations' => self::reservationsSections($locale),
            'operations' => self::operationsSections($locale),
            'commerce' => self::commerceSections($locale),
            'marketing-loyalty' => self::marketingLoyaltySections($locale),
            'ai-automation' => self::aiAutomationSections($locale),
            'command-center' => self::commandCenterSections($locale),
            default => [],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function salesCrmSections(string $locale): array
    {
        $isFrench = $locale === 'fr';

        return [
            self::featureTabsSection('sales-crm-flow', $locale, [
                'background_color' => '#ffffff',
                'kicker' => $isFrench ? 'Un systeme qui couvre tout le cycle client' : 'One system across the full customer journey',
                'title' => $isFrench ? 'La solution tout-en-un pour les pros du service a domicile' : 'The all-in-one platform for home service teams',
                'body' => $isFrench
                    ? 'De la visibilite locale jusqu au paiement final, chaque etape reste dans un meme flux plutot que dans quatre outils separes.'
                    : 'From local visibility to final payment, each step stays in one operating flow instead of being split across separate tools.',
                'tabs' => [
                    [
                        'label' => $isFrench ? 'Se faire remarquer' : 'Get Noticed',
                        'icon' => 'clipboard-check',
                        'title' => '',
                        'body' => '<p>&nbsp;</p>',
                        'story' => $isFrench
                            ? 'Nous avons clarifie notre presence en ligne, automatise les suivis et augmente le volume de demandes qualifiees sans ajouter de friction.'
                            : 'We clarified our online presence, automated follow-up, and increased qualified demand without adding friction.',
                        'cta_label' => '',
                        'cta_href' => '/pages/marketing-loyalty',
                        'avatar_url' => '/images/presets/avatar-1.svg',
                        'avatar_alt' => $isFrench ? 'Portrait equipe croissance' : 'Growth team portrait',
                        'children' => [
                            [
                                'label' => $isFrench ? 'Demandes d avis' : 'Review requests',
                                'title' => $isFrench ? 'Obtenez plus d avis sans relances manuelles' : 'Win more reviews without manual chasing',
                                'body' => $isFrench
                                    ? 'Declenchez des demandes au bon moment et facilitez la collecte d avis pendant que l experience client est encore fraiche.'
                                    : 'Trigger review requests at the right moment and make feedback collection easier while the customer experience is still fresh.',
                                'cta_label' => $isFrench ? 'Voir les avis' : 'See reviews',
                                'cta_href' => '#',
                            ],
                            [
                                'label' => $isFrench ? 'Reponses rapides' : 'Quick responses',
                                'title' => $isFrench ? 'Repondez plus vite aux nouvelles demandes' : 'Reply faster to new requests',
                                'body' => $isFrench
                                    ? 'Automatisez les messages d accueil et gardez un delai de reponse court pour montrer que votre entreprise est reactive.'
                                    : 'Automate greeting messages and keep response times short so your business feels responsive from the first touch.',
                                'cta_label' => $isFrench ? 'Voir la messagerie' : 'See messaging',
                                'cta_href' => '#',
                            ],
                            [
                                'label' => $isFrench ? 'Marketing automatise' : 'Automated marketing',
                                'title' => $isFrench ? 'Restez visible sans campagnes compliquees' : 'Stay visible without complicated campaigns',
                                'body' => $isFrench
                                    ? 'Programmez des suivis clients, des rappels saisonniers et des campagnes simples pour revenir en tete au bon moment.'
                                    : 'Schedule simple follow-up, seasonal reminders, and lightweight campaigns so you stay top of mind at the right time.',
                                'cta_label' => $isFrench ? 'Voir le marketing' : 'See marketing',
                                'cta_href' => '#',
                            ],
                            [
                                'label' => $isFrench ? 'Liens de partage' : 'Shareable links',
                                'title' => $isFrench ? 'Diffusez plus facilement votre offre' : 'Make your offer easier to share',
                                'body' => $isFrench
                                    ? 'Partagez vos formulaires, pages et devis avec des liens propres pour accelerer le bouche-a-oreille et les recommandations.'
                                    : 'Share forms, pages, and quotes through clean links that make word of mouth and referrals easier to trigger.',
                                'cta_label' => $isFrench ? 'Voir les liens' : 'See links',
                                'cta_href' => '#',
                            ],
                        ],
                    ],
                    [
                        'label' => $isFrench ? 'Gagner des jobs' : 'Win Jobs',
                        'icon' => 'file-text',
                        'role' => $isFrench ? 'Vente et qualification' : 'Sales and qualification',
                        'title' => $isFrench ? 'Transformez plus vite une demande entrante en devis signe' : 'Turn inbound demand into approved quotes faster',
                        'body' => $isFrench
                            ? 'Qualification, devis, relances et historique client sont relies pour faire avancer chaque opportunite sans perte de contexte.'
                            : 'Qualification, quotes, follow-ups, and customer history stay connected so every opportunity moves forward without losing context.',
                        'story' => $isFrench
                            ? 'Les modeles, les options et les relances automatiques nous ont aide a envoyer des propositions propres plus tot dans la journee.'
                            : 'Templates, options, and automated follow-up helped our team send cleaner proposals earlier in the day.',
                        'metric' => $isFrench ? 'Des devis plus rapides, mieux suivis' : 'Faster quotes with clearer follow-up',
                        'person' => $isFrench ? 'Equipe commerciale' : 'Sales team',
                        'cta_label' => $isFrench ? 'Voir Sales & CRM' : 'See Sales & CRM',
                        'cta_href' => '/pages/sales-crm',
                        'image_key' => 'workflow-plan',
                        'children' => [
                            [
                                'label' => $isFrench ? 'Reservations en ligne et formulaires' : 'Booking forms and intake',
                                'title' => $isFrench ? 'Capturez plus de demandes sans friction' : 'Capture more demand without friction',
                                'body' => $isFrench
                                    ? 'Ajoutez des formulaires de demande simples, acceptez les reservations en ligne et faites entrer les leads directement dans votre pipeline.'
                                    : 'Add simple request forms, accept online booking, and move new demand directly into your pipeline.',
                                'cta_label' => $isFrench ? 'Voir la capture de leads' : 'See lead capture',
                                'cta_href' => '#',
                            ],
                            [
                                'label' => $isFrench ? 'Modeles de devis' : 'Quote templates',
                                'title' => $isFrench ? 'Envoyez des devis coherents en moins de temps' : 'Send consistent quotes in less time',
                                'body' => $isFrench
                                    ? 'Prechargez vos services, prix et options frequentes pour sortir des devis clairs et uniformes depuis le bureau ou le terrain.'
                                    : 'Preload services, pricing, and frequent options so office and field teams can send clear quotes without rebuilding them each time.',
                                'cta_label' => $isFrench ? 'Voir les modeles de devis' : 'See quote templates',
                                'cta_href' => '#',
                            ],
                            [
                                'label' => $isFrench ? 'Lignes optionnelles' : 'Optional line items',
                                'title' => $isFrench ? 'Augmentez la valeur moyenne de chaque devis' : 'Increase the average value of every quote',
                                'body' => $isFrench
                                    ? 'Ajoutez facilement des options, des extras et des services complementaires pour proposer plus de valeur sans refaire vos devis a la main.'
                                    : 'Add options, extras, and complementary services quickly so you can propose more value without rebuilding the quote by hand.',
                                'cta_label' => $isFrench ? 'Voir les options de devis' : 'See quote options',
                                'cta_href' => '#',
                            ],
                            [
                                'label' => $isFrench ? 'Suivis automatiques' : 'Automated follow-ups',
                                'title' => $isFrench ? 'Relancez au bon moment sans suivi manuel' : 'Follow up at the right moment without manual chasing',
                                'body' => $isFrench
                                    ? 'Programmez des rappels et des suivis automatiques pour faire avancer vos opportunites sans laisser de leads en attente.'
                                    : 'Schedule reminders and follow-up sequences so opportunities keep moving without leaving leads untouched.',
                                'cta_label' => $isFrench ? 'Voir les suivis' : 'See follow-ups',
                                'cta_href' => '#',
                            ],
                        ],
                    ],
                    [
                        'label' => $isFrench ? 'Travailler mieux' : 'Work Smarter',
                        'icon' => 'calendar-days',
                        'role' => $isFrench ? 'Dispatch et execution' : 'Dispatch and execution',
                        'title' => $isFrench ? 'Passez du bureau au terrain avec le meme niveau de clarte' : 'Move from office to field with the same level of clarity',
                        'body' => $isFrench
                            ? 'Planning, dispatch, fiches job, checklists et historique client restent visibles pour que les equipes interviennent avec le bon contexte.'
                            : 'Scheduling, dispatch, job records, checklists, and customer history stay visible so crews always arrive with the right context.',
                        'story' => $isFrench
                            ? 'Le planning et les details d intervention sont enfin partages dans le meme outil, ce qui reduit les appels de clarification pendant la journee.'
                            : 'Scheduling and job details are finally shared inside one tool, which cuts down on clarification calls during the day.',
                        'metric' => $isFrench ? 'Moins d aller-retour entre le bureau et le terrain' : 'Less back and forth between office and field',
                        'person' => $isFrench ? 'Equipe terrain' : 'Field team',
                        'cta_label' => $isFrench ? 'Voir Operations' : 'See Operations',
                        'cta_href' => '/pages/operations',
                        'image_key' => 'field-checklist',
                        'children' => [
                            [
                                'label' => $isFrench ? 'Calendrier glisser-deposer' : 'Drag-and-drop schedule',
                                'title' => $isFrench ? 'Deplacez vos horaires sans rebatir la journee' : 'Move schedules without rebuilding the whole day',
                                'body' => $isFrench
                                    ? 'Replanifiez en quelques secondes, assignez les bonnes equipes et gardez tout le monde aligne avec des mises a jour instantanees.'
                                    : 'Reschedule in seconds, assign the right team, and keep everyone aligned with instant updates.',
                                'cta_label' => $isFrench ? 'Voir la planification' : 'See scheduling',
                                'cta_href' => '#',
                            ],
                            [
                                'label' => $isFrench ? 'Dispatch d equipe' : 'Team dispatch',
                                'title' => $isFrench ? 'Affectez la bonne equipe au bon job' : 'Assign the right team to the right job',
                                'body' => $isFrench
                                    ? 'Visualisez la disponibilite, l emplacement et la charge de travail pour envoyer les bonnes personnes sans perdre de temps.'
                                    : 'See availability, location, and workload so you can send the right people without losing time.',
                                'cta_label' => $isFrench ? 'Voir le dispatch' : 'See dispatch',
                                'cta_href' => '#',
                            ],
                            [
                                'label' => $isFrench ? 'Checklists terrain' : 'Field checklists',
                                'title' => $isFrench ? 'Standardisez l execution de chaque intervention' : 'Standardize how every intervention gets executed',
                                'body' => $isFrench
                                    ? 'Ajoutez des etapes, des formulaires et des controles de qualite pour que le travail soit bien fait du premier coup.'
                                    : 'Add steps, forms, and quality checks so the work gets done right the first time.',
                                'cta_label' => $isFrench ? 'Voir les checklists' : 'See checklists',
                                'cta_href' => '#',
                            ],
                            [
                                'label' => $isFrench ? 'Historique client' : 'Customer history',
                                'title' => $isFrench ? 'Retrouvez le contexte complet avant chaque visite' : 'Recover the full context before every visit',
                                'body' => $isFrench
                                    ? 'Gardez les notes, photos, demandes et anciens jobs au meme endroit pour que vos equipes arrivent preparees chez le client.'
                                    : 'Keep notes, photos, requests, and previous jobs in one place so teams arrive prepared at the customer site.',
                                'cta_label' => $isFrench ? 'Voir les fiches client' : 'See customer records',
                                'cta_href' => '#',
                            ],
                        ],
                    ],
                    [
                        'label' => $isFrench ? 'Booster les profits' : 'Boost Profits',
                        'icon' => 'circle-dollar-sign',
                        'role' => $isFrench ? 'Facturation et paiements' : 'Invoicing and payments',
                        'title' => $isFrench ? 'Facturez plus vite et raccourcissez le cycle d encaissement' : 'Invoice faster and shorten the time between work and cash',
                        'body' => $isFrench
                            ? 'Factures, paiements sur place, rappels et suivi de marge restent connectes au travail realise pour proteger vos revenus.'
                            : 'Invoices, on-site payments, reminders, and margin follow-up stay tied to completed work so revenue is easier to protect.',
                        'story' => $isFrench
                            ? 'Nos equipes cloturent plus vite les jobs et les rappels partent automatiquement, donc le cash rentre plus tot.'
                            : 'Teams close jobs faster and reminders go out automatically, so cash comes in sooner.',
                        'metric' => $isFrench ? 'Une meilleure visibilite sur la marge et la tresorerie' : 'Clearer visibility into margin and cash',
                        'person' => $isFrench ? 'Equipe finance' : 'Finance team',
                        'cta_label' => $isFrench ? 'Voir Commerce' : 'See Commerce',
                        'cta_href' => '/pages/commerce',
                        'image_key' => 'payments-terminal',
                        'children' => [
                            [
                                'label' => $isFrench ? 'Factures rapides' : 'Fast invoicing',
                                'title' => $isFrench ? 'Transformez un job termine en facture en quelques clics' : 'Turn a completed job into an invoice in a few clicks',
                                'body' => $isFrench
                                    ? 'Generez vos factures sans refaire les informations du job, puis envoyez-les immediatement au client.'
                                    : 'Generate invoices without re-entering job information and send them to the customer immediately.',
                                'cta_label' => $isFrench ? 'Voir la facturation' : 'See invoicing',
                                'cta_href' => '#',
                            ],
                            [
                                'label' => $isFrench ? 'Paiements sur place' : 'On-site payments',
                                'title' => $isFrench ? 'Encaissez pendant que l equipe est encore chez le client' : 'Collect payment while the team is still on site',
                                'body' => $isFrench
                                    ? 'Acceptez plusieurs moyens de paiement sur mobile pour reduire les delais et limiter les comptes a recevoir.'
                                    : 'Accept multiple payment methods from mobile devices to reduce delay and shrink accounts receivable.',
                                'cta_label' => $isFrench ? 'Voir les paiements mobiles' : 'See mobile payments',
                                'cta_href' => '#',
                            ],
                            [
                                'label' => $isFrench ? 'Rappels automatiques' : 'Automated reminders',
                                'title' => $isFrench ? 'Relancez sans courir apres chaque facture' : 'Follow up without chasing every invoice by hand',
                                'body' => $isFrench
                                    ? 'Automatisez vos rappels pour garder le cap sur les paiements en retard sans monopoliser votre equipe admin.'
                                    : 'Automate reminders so overdue payments stay visible without consuming your admin team.',
                                'cta_label' => $isFrench ? 'Voir les rappels' : 'See reminders',
                                'cta_href' => '#',
                            ],
                            [
                                'label' => $isFrench ? 'Rapports de marge' : 'Margin reporting',
                                'title' => $isFrench ? 'Voyez ou vous gagnez et ou vous perdez' : 'See where you win and where you lose',
                                'body' => $isFrench
                                    ? 'Suivez vos revenus, vos services les plus profitables et vos tendances de performance pour prendre de meilleures decisions.'
                                    : 'Track revenue, profitable services, and performance trends so better decisions are easier to make.',
                                'cta_label' => $isFrench ? 'Voir les rapports' : 'See reporting',
                                'cta_href' => '#',
                            ],
                        ],
                    ],
                ],
            ]),
            self::showcaseSection('sales-crm-cta', $locale, [
                'kicker' => $isFrench ? 'Pret a structurer la conversion' : 'Ready to structure conversion',
                'title' => $isFrench
                    ? 'Sales & CRM suit maintenant le meme format narratif que les autres pages modules'
                    : 'Sales & CRM now follows the same narrative format as the other module pages',
                'body' => $isFrench
                    ? 'Promesse, workflow, preuves courtes puis CTA: la page raconte mieux le role du module dans le parcours public.'
                    : 'Promise, workflow, short proof, then CTA: the page explains the role of the module much more clearly in the public journey.',
                'primary_label' => $isFrench ? 'Voir la solution Vente & devis' : 'See the Sales & Quoting solution',
                'primary_href' => '/pages/solution-sales-quoting',
                'secondary_label' => $isFrench ? 'Voir les tarifs' : 'View pricing',
                'secondary_href' => '/pricing',
                'aside_link_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                'aside_link_href' => '/pages/command-center',
                'image_key' => 'meeting-room-laptops',
                'aside_image_key' => 'meeting-room-laptops',
                'badge_label' => 'Module',
                'badge_value' => 'Sales & CRM',
                'badge_note' => $isFrench
                    ? 'Qualification, devis et relance dans un meme flux'
                    : 'Qualification, quoting, and follow-up inside one shared flow',
            ]),
            self::storyGridSection('sales-crm-proof', $locale, [
                'title' => $isFrench ? 'Une IA pensee pour les entreprises de terrain.' : 'AI designed for field businesses.',
                'cards' => [
                    [
                        'title' => $isFrench ? 'Concue pour le terrain' : 'Built for field reality',
                        'body' => $isFrench
                            ? 'Notre IA comprend le quotidien des equipes terrain. Elle aide a mieux qualifier, chiffrer et reperer les opportunites sans alourdir les operations.'
                            : 'Our AI understands the day-to-day rhythm of field teams. It helps qualify, price, and spot opportunities without making operations heavier.',
                    ],
                    [
                        'title' => $isFrench ? 'S adapte a votre facon de travailler' : 'Adapts to how your team already works',
                        'body' => $isFrench
                            ? 'Plus vous l utilisez, plus elle suit votre logique de devis, de planification et de description de service pour garder un rendu naturel.'
                            : 'The more you use it, the more it follows your quoting, scheduling, and service language so the output stays natural.',
                    ],
                    [
                        'title' => $isFrench ? 'Intervient au bon moment' : 'Shows up at the right moment',
                        'body' => $isFrench
                            ? 'Elle apparait quand il faut: pour completer un devis, suggerer un ajout utile ou relancer une etape importante sans casser le rythme.'
                            : 'It appears when it matters most: to complete a quote, suggest a useful add-on, or restart an important step without breaking momentum.',
                        'image_key' => 'collab-laptop-desk',
                    ],
                ],
            ]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function reservationsSections(string $locale): array
    {
        $isFrench = $locale === 'fr';

        return [
            self::featureTabsSection('reservations-flow', $locale, [
                'background_color' => '#ffffff',
                'kicker' => $isFrench ? 'Workflow du module' : 'Module workflow',
                'title' => $isFrench ? 'Montrez la reservation comme un parcours complet' : 'Show booking as a complete journey',
                'body' => $isFrench
                    ? 'La page ne s arrete plus au calendrier et montre aussi confirmation, file et prolongement de la relation.'
                    : 'The page no longer stops at the calendar and now shows confirmation, queue handling, and the next step in the relationship.',
                'primary_label' => $isFrench ? 'Voir la solution Reservations & files' : 'See the Reservations & Queues solution',
                'primary_href' => '/pages/solution-reservations-queues',
                'tabs' => [
                    [
                        'label' => $isFrench ? 'Proposer' : 'Offer',
                        'icon' => 'calendar-days',
                        'title' => $isFrench ? 'Rendre les creneaux plus lisibles' : 'Make slots easier to understand',
                        'body' => $isFrench
                            ? 'La disponibilite devient une vraie porte d entree commerciale dans le parcours.'
                            : 'Availability becomes a real acquisition entry point instead of just a passive schedule.',
                        'cta_label' => $isFrench ? 'Voir la solution' : 'See the solution',
                        'cta_href' => '/pages/solution-reservations-queues',
                        'image_key' => 'marketing-desk',
                    ],
                    [
                        'label' => $isFrench ? 'Confirmer' : 'Confirm',
                        'icon' => 'clipboard-check',
                        'title' => $isFrench ? 'Stabiliser la visite avant l arrivee' : 'Stabilize the visit before arrival',
                        'body' => $isFrench
                            ? 'Rappels, recapitulatif et preparation restent visibles avant le rendez-vous.'
                            : 'Reminders, recap, and preparation stay visible before the appointment actually starts.',
                        'cta_label' => $isFrench ? 'Voir Marketing & Loyalty' : 'See Marketing & Loyalty',
                        'cta_href' => '/pages/marketing-loyalty',
                        'image_key' => 'desk-phone-laptop',
                    ],
                    [
                        'label' => $isFrench ? 'Accueillir' : 'Welcome',
                        'icon' => 'clipboard-list',
                        'title' => $isFrench ? 'Absorber l arrivee et la file plus proprement' : 'Handle arrivals and queue activity more cleanly',
                        'body' => $isFrench
                            ? 'La page montre mieux comment accueil, attente et passage vers le service restent relies.'
                            : 'The page shows more clearly how reception, waiting, and handoff into service still belong to one connected moment.',
                        'cta_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                        'cta_href' => '/pages/command-center',
                        'image_key' => 'service-tablet',
                    ],
                    [
                        'label' => $isFrench ? 'Suivre' : 'Follow Up',
                        'icon' => 'file-text',
                        'title' => $isFrench ? 'Prolonger la relation apres visite' : 'Extend the relationship after the visit',
                        'body' => $isFrench
                            ? 'La reservation garde une suite logique vers avis, relance ou prochain rendez-vous.'
                            : 'Booking naturally leads into reviews, reactivation, or the next appointment instead of ending at confirmation.',
                        'cta_label' => $isFrench ? 'Voir la solution marketing' : 'See the marketing solution',
                        'cta_href' => '/pages/solution-marketing-loyalty',
                        'image_key' => 'meeting-room-laptops',
                    ],
                ],
            ]),
            self::showcaseSection('reservations-cta', $locale, [
                'kicker' => $isFrench ? 'Pret a fluidifier la visite' : 'Ready to smooth the visit',
                'title' => $isFrench
                    ? 'Reservations suit maintenant le meme format narratif que les autres pages modules'
                    : 'Reservations now follows the same narrative format as the other module pages',
                'body' => $isFrench
                    ? 'La page guide mieux vers la solution, les tarifs ou le module utile suivant.'
                    : 'The page now guides visitors more clearly toward the solution, pricing, or the next useful module.',
                'primary_label' => $isFrench ? 'Voir la solution Reservations & files' : 'See the Reservations & Queues solution',
                'primary_href' => '/pages/solution-reservations-queues',
                'secondary_label' => $isFrench ? 'Voir les tarifs' : 'View pricing',
                'secondary_href' => '/pricing',
                'aside_link_label' => $isFrench ? 'Voir Marketing & Loyalty' : 'See Marketing & Loyalty',
                'aside_link_href' => '/pages/marketing-loyalty',
                'image_key' => 'marketing-desk',
                'aside_image_key' => 'service-tablet',
                'badge_label' => 'Module',
                'badge_value' => 'Reservations',
                'badge_note' => $isFrench
                    ? 'Disponibilite, confirmation et accueil dans un meme flux'
                    : 'Availability, confirmation, and reception in one shared flow',
            ]),
            self::storyGridSection('reservations-proof', $locale, [
                'kicker' => $isFrench ? 'Moments visibles' : 'Visible moments',
                'title' => $isFrench
                    ? 'Le module Reservations devient plus lisible quand ses moments clefs restent distincts'
                    : 'Reservations becomes easier to understand when its key moments stay distinct',
                'body' => $isFrench
                    ? 'Le visiteur voit mieux ce qui se passe avant, pendant et apres la visite.'
                    : 'Visitors can now see more clearly what happens before, during, and after the visit.',
                'primary_label' => $isFrench ? 'Voir la solution Reservations & files' : 'See the Reservations & Queues solution',
                'primary_href' => '/pages/solution-reservations-queues',
                'cards' => [
                    [
                        'title' => $isFrench ? 'Un choix plus simple' : 'An easier choice',
                        'body' => $isFrench
                            ? 'Le visiteur comprend vite comment choisir le bon moment.'
                            : 'Visitors quickly understand how to choose the right moment.',
                        'image_key' => 'marketing-desk',
                    ],
                    [
                        'title' => $isFrench ? 'Une arrivee plus fluide' : 'A smoother arrival',
                        'body' => $isFrench
                            ? 'La file et l accueil ont maintenant une vraie place dans le recit.'
                            : 'Queue handling and reception now have a clear place in the story instead of feeling hidden.',
                        'image_key' => 'service-tablet',
                    ],
                    [
                        'title' => $isFrench ? 'Un vrai suivi de visite' : 'A real post-visit follow-up',
                        'body' => $isFrench
                            ? 'Le rendez-vous ne s arrete plus a la simple confirmation.'
                            : 'The appointment no longer stops at confirmation alone.',
                        'image_key' => 'desk-phone-laptop',
                    ],
                ],
            ]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function operationsSections(string $locale): array
    {
        $isFrench = $locale === 'fr';

        return [
            self::featureTabsSection('operations-flow', $locale, [
                'background_color' => '#ffffff',
                'kicker' => $isFrench ? 'Workflow du module' : 'Module workflow',
                'title' => $isFrench ? 'Suivez le module du plan de charge jusqu a la preuve terrain' : 'Follow the module from workload planning to field proof',
                'body' => $isFrench
                    ? 'Chaque onglet rend visible un moment metier concret entre bureau, coordination et terrain.'
                    : 'Each tab makes one concrete business moment visible between office planning, coordination, and field execution.',
                'primary_label' => $isFrench ? 'Voir la solution Services terrain' : 'See the Field Services solution',
                'primary_href' => '/pages/solution-field-services',
                'tabs' => [
                    [
                        'label' => $isFrench ? 'Planifier' : 'Plan',
                        'icon' => 'calendar-days',
                        'title' => $isFrench ? 'Lire la charge avant de partir' : 'Read the workload before crews leave',
                        'body' => $isFrench
                            ? 'Le bureau voit priorites, ressources et points de tension avant la journee.'
                            : 'The office sees priorities, resources, and pressure points before the day starts.',
                        'cta_label' => $isFrench ? 'Voir la solution' : 'See the solution',
                        'cta_href' => '/pages/solution-field-services',
                        'image_key' => 'workflow-plan',
                    ],
                    [
                        'label' => $isFrench ? 'Dispatcher' : 'Dispatch',
                        'icon' => 'clipboard-list',
                        'title' => $isFrench ? 'Donner le bon contexte a la bonne equipe' : 'Give the right context to the right team',
                        'body' => $isFrench
                            ? 'Affectation et preparation restent visibles dans un meme moment de coordination.'
                            : 'Assignment and preparation stay visible inside one coordination moment instead of scattered updates.',
                        'cta_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                        'cta_href' => '/pages/command-center',
                        'image_key' => 'service-tablet',
                    ],
                    [
                        'label' => $isFrench ? 'Intervenir' : 'Execute',
                        'icon' => 'wrench',
                        'title' => $isFrench ? 'Executer avec une lecture plus nette du job' : 'Execute with a clearer read of the job',
                        'body' => $isFrench
                            ? 'Le terrain voit mieux les statuts, le contexte client et les preuves a reunir.'
                            : 'Field teams can see status, customer context, and the proof they need to collect with less ambiguity.',
                        'cta_label' => $isFrench ? 'Voir les services terrain' : 'See field services',
                        'cta_href' => '/pages/solution-field-services',
                        'image_key' => 'service-install',
                    ],
                    [
                        'label' => $isFrench ? 'Cloturer' : 'Close',
                        'icon' => 'file-text',
                        'title' => $isFrench ? 'Fermer la boucle avec un suivi plus propre' : 'Close the loop with cleaner follow-up',
                        'body' => $isFrench
                            ? 'Validation, lecture du revenu et prochaine action restent dans le meme flux.'
                            : 'Validation, revenue visibility, and the next action stay inside the same operating flow.',
                        'cta_label' => $isFrench ? 'Voir Commerce' : 'See Commerce',
                        'cta_href' => '/pages/commerce',
                        'image_key' => 'field-checklist',
                    ],
                ],
            ]),
            self::showcaseSection('operations-cta', $locale, [
                'kicker' => $isFrench ? 'Pret a structurer l execution' : 'Ready to structure execution',
                'title' => $isFrench
                    ? 'Operations suit maintenant le meme format narratif que les autres pages modules'
                    : 'Operations now follows the same narrative format as the other module pages',
                'body' => $isFrench
                    ? 'La page donne une impression de profondeur produit sans devenir une doc interne.'
                    : 'The page gives a deeper product impression without turning into internal documentation.',
                'primary_label' => $isFrench ? 'Voir la solution Services terrain' : 'See the Field Services solution',
                'primary_href' => '/pages/solution-field-services',
                'secondary_label' => $isFrench ? 'Voir les tarifs' : 'View pricing',
                'secondary_href' => '/pricing',
                'aside_link_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                'aside_link_href' => '/pages/command-center',
                'image_key' => 'service-install',
                'aside_image_key' => 'service-tablet',
                'badge_label' => 'Module',
                'badge_value' => 'Operations',
                'badge_note' => $isFrench
                    ? 'Planning, dispatch et preuve terrain dans un meme rythme'
                    : 'Planning, dispatch, and field proof in one rhythm',
            ]),
            self::storyGridSection('operations-proof', $locale, [
                'kicker' => $isFrench ? 'Moments visibles' : 'Visible moments',
                'title' => $isFrench
                    ? 'Le module Operations devient plus lisible quand ses moments clefs restent distincts'
                    : 'Operations becomes easier to read when its key moments stay distinct',
                'body' => $isFrench
                    ? 'Le module se lit mieux quand bureau, dispatch et terrain restent dans la meme histoire.'
                    : 'The module reads better when office planning, dispatch, and field work stay inside the same story.',
                'primary_label' => $isFrench ? 'Voir la solution Services terrain' : 'See the Field Services solution',
                'primary_href' => '/pages/solution-field-services',
                'cards' => [
                    [
                        'title' => $isFrench ? 'Une meilleure lecture du plan de charge' : 'A clearer view of the workload',
                        'body' => $isFrench
                            ? 'Le bureau garde une vision plus nette avant d engager les ressources.'
                            : 'The office keeps a cleaner view before committing people and resources.',
                        'image_key' => 'workflow-plan',
                    ],
                    [
                        'title' => $isFrench ? 'Un vrai temps de dispatch' : 'A real dispatch moment',
                        'body' => $isFrench
                            ? 'Les informations utiles arrivent plus clairement avant le depart.'
                            : 'Useful information reaches the team more clearly before departure.',
                        'image_key' => 'service-tablet',
                    ],
                    [
                        'title' => $isFrench ? 'La preuve reste dans le meme flux' : 'Proof stays in the same flow',
                        'body' => $isFrench
                            ? 'La page raconte mieux la fin de l intervention et la fermeture du job.'
                            : 'The page explains the end of the intervention and job closure far more clearly.',
                        'image_key' => 'field-checklist',
                    ],
                ],
            ]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function commerceSections(string $locale): array
    {
        $isFrench = $locale === 'fr';

        return [
            self::featureTabsSection('commerce-flow', $locale, [
                'background_color' => '#ffffff',
                'kicker' => $isFrench ? 'Workflow du module' : 'Module workflow',
                'title' => $isFrench ? 'Suivez le module par etapes visibles plutot que par menu' : 'Follow the module through visible stages instead of menu labels',
                'body' => $isFrench
                    ? 'Chaque onglet replace le module dans un moment de vente concret: rendre l offre visible, guider la commande, sortir la facture et securiser l encaissement.'
                    : 'Each tab puts the module back into a concrete selling moment: make the offer visible, guide the order, issue the invoice, and secure collection.',
                'primary_label' => $isFrench ? 'Voir la solution commerce & catalogue' : 'See the Commerce & Catalog solution',
                'primary_href' => '/pages/solution-commerce-catalog',
                'tabs' => [
                    [
                        'label' => $isFrench ? 'Catalogue visible' : 'Visible catalog',
                        'icon' => 'clipboard-check',
                        'title' => $isFrench ? 'Le catalogue devient une entree claire plutot qu une simple base de donnees' : 'The catalog becomes a clear entry point instead of a passive database',
                        'body' => $isFrench
                            ? 'Produits, services et categories se presentent dans un ordre plus rassurant pour lancer la vente avec de vrais reperes.'
                            : 'Products, services, and categories appear in a more reassuring order so the sale can start with real context.',
                        'cta_label' => $isFrench ? 'Voir la solution commerce' : 'See the commerce solution',
                        'cta_href' => '/pages/solution-commerce-catalog',
                        'image_key' => 'store-worker',
                    ],
                    [
                        'label' => $isFrench ? 'Commande guidee' : 'Guided order',
                        'icon' => 'clipboard-list',
                        'title' => $isFrench ? 'La vente garde une progression lisible du choix jusqu au recapitulatif' : 'The sale keeps a readable progression from choice to recap',
                        'body' => $isFrench
                            ? 'Le panier, les quantites et les meilleures ventes restent dans le meme recit visuel pour limiter les allers-retours.'
                            : 'Cart, quantities, and best-selling items stay in the same visual story so customers and teams make fewer back-and-forth moves.',
                        'cta_label' => $isFrench ? 'Voir la boutique' : 'See the storefront',
                        'cta_href' => '/pages/solution-commerce-catalog',
                        'image_key' => 'store-boxes',
                    ],
                    [
                        'label' => $isFrench ? 'Facture sans rupture' : 'Invoice without friction',
                        'icon' => 'file-text',
                        'title' => $isFrench ? 'La facturation reprend le bon contexte au lieu de repartir de zero' : 'Invoicing keeps the right context instead of starting from zero',
                        'body' => $isFrench
                            ? 'Le module garde la logique commerciale, les lignes utiles et la validation interne dans un meme fil.'
                            : 'The module keeps the commercial logic, useful line items, and internal validation inside one continuous thread.',
                        'cta_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                        'cta_href' => '/pages/command-center',
                        'image_key' => 'team-laptop-window',
                    ],
                    [
                        'label' => $isFrench ? 'Encaissement protege' : 'Protected collection',
                        'icon' => 'circle-dollar-sign',
                        'title' => $isFrench ? 'Le paiement et le suivi de revenu restent connectes a la transaction' : 'Payment and revenue follow-up stay tied to the transaction',
                        'body' => $isFrench
                            ? 'Encaissement, rappel et lecture du revenu s enchainent sans casser le flux de vente initial.'
                            : 'Collection, reminders, and revenue visibility follow naturally without breaking the original selling flow.',
                        'cta_label' => $isFrench ? 'Voir Commerce' : 'See Commerce',
                        'cta_href' => '/pages/commerce',
                        'image_key' => 'warehouse-worker',
                    ],
                ],
            ]),
            self::showcaseSection('commerce-cta', $locale, [
                'kicker' => $isFrench ? 'Pret a monetiser' : 'Ready to monetize',
                'title' => $isFrench
                    ? 'Le module Commerce cadre la vente, puis laisse l execution prendre le relai'
                    : 'Commerce frames the sale, then lets execution take over',
                'body' => $isFrench
                    ? 'Cette version suit maintenant le meme format que la refonte publique: une promesse, un workflow, des preuves courtes, puis un CTA logique vers pricing ou solution.'
                    : 'This version now follows the same public redesign format: one promise, one workflow, short proof, then a logical CTA toward pricing or the connected solution.',
                'primary_label' => $isFrench ? 'Voir les tarifs' : 'View pricing',
                'primary_href' => '/pricing#commerce',
                'secondary_label' => $isFrench ? 'Voir la solution commerce & catalogue' : 'See the Commerce & Catalog solution',
                'secondary_href' => '/pages/solution-commerce-catalog',
                'aside_link_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                'aside_link_href' => '/pages/command-center',
                'image_key' => 'store-payment',
                'aside_image_key' => 'store-boxes',
                'badge_label' => 'Module',
                'badge_value' => 'Commerce',
                'badge_note' => $isFrench
                    ? 'Catalogue, commande, facture et paiement dans un meme flux'
                    : 'Catalog, order, invoice, and payment in one shared flow',
            ]),
            self::storyGridSection('commerce-proof', $locale, [
                'kicker' => $isFrench ? 'Ce que la nouvelle page clarifie' : 'What the new page clarifies',
                'title' => $isFrench
                    ? 'Un module plus lisible pour la direction, l equipe et le client final'
                    : 'A module that reads more clearly for leadership, teams, and end customers',
                'body' => $isFrench
                    ? 'Le module gagne en profondeur sans se transformer en documentation produit. Chaque carte explique un moment utile de la chaine commerce.'
                    : 'The module gains depth without becoming product documentation. Each card explains one useful moment inside the commerce chain.',
                'primary_label' => $isFrench ? 'Voir la solution commerce & catalogue' : 'See the Commerce & Catalog solution',
                'primary_href' => '/pages/solution-commerce-catalog',
                'cards' => [
                    [
                        'title' => $isFrench ? 'Le catalogue redevient un point d entree' : 'The catalog becomes an entry point again',
                        'body' => $isFrench
                            ? 'La page montre mieux comment l offre s organise avant meme la premiere commande.'
                            : 'The page shows more clearly how the offer gets organized before the first order even exists.',
                        'image_key' => 'store-worker',
                    ],
                    [
                        'title' => $isFrench ? 'La logistique reste visible dans le recit' : 'Logistics stays visible in the story',
                        'body' => $isFrench
                            ? 'Le visiteur comprend plus vite que stock, preparation et livraison font partie du meme module.'
                            : 'Visitors understand faster that stock, preparation, and delivery all belong to the same module.',
                        'image_key' => 'warehouse-worker',
                    ],
                    [
                        'title' => $isFrench ? 'Le revenu apparait comme la suite naturelle de la vente' : 'Revenue feels like the natural continuation of the sale',
                        'body' => $isFrench
                            ? 'Facture et encaissement ferment la boucle au lieu de sembler deconnectes du catalogue et de la commande.'
                            : 'Invoicing and collection close the loop instead of feeling disconnected from catalog and ordering.',
                        'image_key' => 'store-payment',
                    ],
                ],
            ]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function marketingLoyaltySections(string $locale): array
    {
        $isFrench = $locale === 'fr';

        return [
            self::featureTabsSection('marketing-loyalty-flow', $locale, [
                'background_color' => '#ffffff',
                'kicker' => $isFrench ? 'Workflow du module' : 'Module workflow',
                'title' => $isFrench ? 'Suivez le module du signal client jusqu au retour en revenu' : 'Follow the module from customer signal to returning revenue',
                'body' => $isFrench
                    ? 'La page montre comment le module ecoute, cible, active puis ramene le client vers la bonne suite.'
                    : 'The page shows how the module listens, segments, activates, and then brings the customer back toward the right next step.',
                'primary_label' => $isFrench ? 'Voir la solution Marketing & fidelisation' : 'See the Marketing & Loyalty solution',
                'primary_href' => '/pages/solution-marketing-loyalty',
                'tabs' => [
                    [
                        'label' => $isFrench ? 'Ecouter' : 'Listen',
                        'icon' => 'clipboard-check',
                        'title' => $isFrench ? 'Faire remonter les signaux utiles' : 'Surface the signals that matter',
                        'body' => $isFrench
                            ? 'Avis, historique et retours concrets nourrissent mieux la prochaine action.'
                            : 'Reviews, history, and concrete feedback feed the next action much more effectively.',
                        'cta_label' => $isFrench ? 'Voir Sales & CRM' : 'See Sales & CRM',
                        'cta_href' => '/pages/sales-crm',
                        'image_key' => 'desk-phone-laptop',
                    ],
                    [
                        'label' => $isFrench ? 'Segmenter' : 'Segment',
                        'icon' => 'clipboard-list',
                        'title' => $isFrench ? 'Cibler a partir d un contexte plus reel' : 'Target from a more concrete context',
                        'body' => $isFrench
                            ? 'La segmentation se comprend mieux quand elle part d une vraie situation client.'
                            : 'Segmentation makes more sense when it starts from a real customer situation instead of abstract tags alone.',
                        'cta_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                        'cta_href' => '/pages/command-center',
                        'image_key' => 'team-laptop-window',
                    ],
                    [
                        'label' => $isFrench ? 'Activer' : 'Activate',
                        'icon' => 'file-text',
                        'title' => $isFrench ? 'Lancer des campagnes plus credibles' : 'Launch campaigns that feel more credible',
                        'body' => $isFrench
                            ? 'Relance, campagne et prochaine action restent lisibles et connectees a la promesse produit.'
                            : 'Follow-up, campaigns, and next actions stay readable and tied back to the product promise.',
                        'cta_label' => $isFrench ? 'Voir la solution' : 'See the solution',
                        'cta_href' => '/pages/solution-marketing-loyalty',
                        'image_key' => 'marketing-desk',
                    ],
                    [
                        'label' => $isFrench ? 'Fideliser' : 'Retain',
                        'icon' => 'calendar-days',
                        'title' => $isFrench ? 'Fermer la boucle avec un retour utile' : 'Close the loop with a useful return',
                        'body' => $isFrench
                            ? 'La page relie mieux reactivation, panier et prochaine visite ou prochain achat.'
                            : 'The page ties reactivation, basket value, and the next visit or order together more clearly.',
                        'cta_label' => $isFrench ? 'Voir Commerce' : 'See Commerce',
                        'cta_href' => '/pages/commerce',
                        'image_key' => 'meeting-room-laptops',
                    ],
                ],
            ]),
            self::showcaseSection('marketing-loyalty-cta', $locale, [
                'kicker' => $isFrench ? 'Pret a relancer plus finement' : 'Ready to reactivate with more precision',
                'title' => $isFrench
                    ? 'Marketing & Loyalty suit maintenant le meme format narratif que les autres pages modules'
                    : 'Marketing & Loyalty now follows the same narrative format as the other module pages',
                'body' => $isFrench
                    ? 'La page raconte mieux comment la retention reste branchee sur la plateforme et pas a cote.'
                    : 'The page now explains more clearly how retention stays connected to the platform instead of living beside it.',
                'primary_label' => $isFrench ? 'Voir la solution Marketing & fidelisation' : 'See the Marketing & Loyalty solution',
                'primary_href' => '/pages/solution-marketing-loyalty',
                'secondary_label' => $isFrench ? 'Voir les tarifs' : 'View pricing',
                'secondary_href' => '/pricing',
                'aside_link_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                'aside_link_href' => '/pages/command-center',
                'image_key' => 'marketing-desk',
                'aside_image_key' => 'team-laptop-window',
                'badge_label' => 'Module',
                'badge_value' => 'Marketing & Loyalty',
                'badge_note' => $isFrench
                    ? 'Signaux, campagnes et retour en revenu dans un meme flux'
                    : 'Signals, campaigns, and returning revenue in one connected flow',
            ]),
            self::storyGridSection('marketing-loyalty-proof', $locale, [
                'kicker' => $isFrench ? 'Moments visibles' : 'Visible moments',
                'title' => $isFrench
                    ? 'Le module Marketing & Loyalty devient plus lisible quand ses moments clefs restent distincts'
                    : 'Marketing & Loyalty becomes easier to read when its key moments stay distinct',
                'body' => $isFrench
                    ? 'Les cartes montrent des moments distincts de retention au lieu de repeter une promesse generique.'
                    : 'The cards show distinct retention moments instead of repeating one generic promise.',
                'primary_label' => $isFrench ? 'Voir la solution Marketing & fidelisation' : 'See the Marketing & Loyalty solution',
                'primary_href' => '/pages/solution-marketing-loyalty',
                'cards' => [
                    [
                        'title' => $isFrench ? 'Les signaux remontent mieux' : 'Signals come up more clearly',
                        'body' => $isFrench
                            ? 'Le module reste branche sur les retours reels au lieu de travailler a vide.'
                            : 'The module stays tied to real feedback instead of working in a vacuum.',
                        'image_key' => 'desk-phone-laptop',
                    ],
                    [
                        'title' => $isFrench ? 'Les campagnes ont plus de contexte' : 'Campaigns carry more context',
                        'body' => $isFrench
                            ? 'Une campagne prend plus de sens quand elle suit une vraie situation client.'
                            : 'A campaign makes more sense when it follows a real customer situation.',
                        'image_key' => 'marketing-desk',
                    ],
                    [
                        'title' => $isFrench ? 'Le retour en revenu est plus lisible' : 'The revenue return becomes easier to read',
                        'body' => $isFrench
                            ? 'La page montre mieux le lien entre fidelisation et prochaine transaction.'
                            : 'The page makes the link between loyalty and the next transaction much easier to understand.',
                        'image_key' => 'meeting-room-laptops',
                    ],
                ],
            ]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function aiAutomationSections(string $locale): array
    {
        $isFrench = $locale === 'fr';

        return [
            self::featureTabsSection('ai-automation-flow', $locale, [
                'background_color' => '#ffffff',
                'kicker' => $isFrench ? 'Workflow du module' : 'Module workflow',
                'title' => $isFrench ? 'Montrez l IA comme un cycle de travail utile et verifiable' : 'Show AI as a useful and verifiable work cycle',
                'body' => $isFrench
                    ? 'Les onglets replacent l IA dans des gestes concrets au lieu d une promesse vague.'
                    : 'The tabs put AI back into concrete operating gestures instead of a vague technology promise.',
                'primary_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                'primary_href' => '/pages/command-center',
                'tabs' => [
                    [
                        'label' => $isFrench ? 'Reperer' : 'Spot',
                        'icon' => 'clipboard-check',
                        'title' => $isFrench ? 'Faire remonter les patterns utiles' : 'Surface the patterns that matter',
                        'body' => $isFrench
                            ? 'Le module aide d abord a voir des repetitions et signaux faibles plus utiles.'
                            : 'The module first helps teams notice recurring patterns and weak signals that actually matter.',
                        'cta_label' => $isFrench ? 'Voir Operations' : 'See Operations',
                        'cta_href' => '/pages/operations',
                        'image_key' => 'team-laptop-window',
                    ],
                    [
                        'label' => $isFrench ? 'Suggester' : 'Suggest',
                        'icon' => 'file-text',
                        'title' => $isFrench ? 'Aider sans casser le contexte source' : 'Help without breaking the source context',
                        'body' => $isFrench
                            ? 'Les suggestions restent plus credibles quand elles se branchent sur un workflow compris par l equipe.'
                            : 'Suggestions feel more credible when they plug into a workflow the team already understands.',
                        'cta_label' => $isFrench ? 'Voir Sales & CRM' : 'See Sales & CRM',
                        'cta_href' => '/pages/sales-crm',
                        'image_key' => 'collab-laptop-desk',
                    ],
                    [
                        'label' => $isFrench ? 'Automatiser' : 'Automate',
                        'icon' => 'calendar-days',
                        'title' => $isFrench ? 'Fluidifier certaines transitions utiles' : 'Smooth useful transitions',
                        'body' => $isFrench
                            ? 'La page raconte mieux comment routage, relance ou preparation peuvent gagner du temps.'
                            : 'The page explains more clearly how routing, follow-up, and preparation can save time in real workflows.',
                        'cta_label' => $isFrench ? 'Voir la plateforme' : 'See the platform',
                        'cta_href' => '/pages/command-center',
                        'image_key' => 'workflow-plan',
                    ],
                    [
                        'label' => $isFrench ? 'Garder le controle' : 'Keep control',
                        'icon' => 'clipboard-list',
                        'title' => $isFrench ? 'Laisser la validation humaine au bon endroit' : 'Leave human validation in the right place',
                        'body' => $isFrench
                            ? 'Le module garde un espace visible pour l exception, la revue et les decisions sensibles.'
                            : 'The module keeps visible space for exceptions, review, and sensitive decisions.',
                        'cta_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                        'cta_href' => '/pages/command-center',
                        'image_key' => 'meeting-room-laptops',
                    ],
                ],
            ]),
            self::showcaseSection('ai-automation-cta', $locale, [
                'kicker' => $isFrench ? 'Pret a gagner du temps sans perdre la main' : 'Ready to save time without losing control',
                'title' => $isFrench
                    ? 'AI & Automation suit maintenant le meme format narratif que les autres pages modules'
                    : 'AI & Automation now follows the same narrative format as the other module pages',
                'body' => $isFrench
                    ? 'Le module se lit maintenant comme une aide situee dans les workflows plutot qu un bloc technologique isole.'
                    : 'The module now reads like help embedded in real workflows instead of an isolated technology block.',
                'primary_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                'primary_href' => '/pages/command-center',
                'secondary_label' => $isFrench ? 'Voir les tarifs' : 'View pricing',
                'secondary_href' => '/pricing',
                'aside_link_label' => $isFrench ? 'Voir Operations' : 'See Operations',
                'aside_link_href' => '/pages/operations',
                'image_key' => 'collab-laptop-desk',
                'aside_image_key' => 'team-laptop-window',
                'badge_label' => 'Module',
                'badge_value' => 'AI & Automation',
                'badge_note' => $isFrench
                    ? 'Suggestions, automatisation et validation dans un meme flux'
                    : 'Suggestions, automation, and validation in one shared flow',
            ]),
            self::storyGridSection('ai-automation-proof', $locale, [
                'kicker' => $isFrench ? 'Moments visibles' : 'Visible moments',
                'title' => $isFrench
                    ? 'Le module AI & Automation devient plus lisible quand ses moments clefs restent distincts'
                    : 'AI & Automation becomes easier to read when its key moments stay distinct',
                'body' => $isFrench
                    ? 'La page montre des moments de suggestion, de revue et de gain de temps plus credibles.'
                    : 'The page now shows suggestion, review, and time-saving moments that feel more credible.',
                'primary_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                'primary_href' => '/pages/command-center',
                'cards' => [
                    [
                        'title' => $isFrench ? 'Reperer plus vite' : 'Spot useful patterns faster',
                        'body' => $isFrench
                            ? 'Le module aide d abord a lire les bons signaux.'
                            : 'The module first helps teams read the right signals instead of drowning in noise.',
                        'image_key' => 'team-laptop-window',
                    ],
                    [
                        'title' => $isFrench ? 'Suggester sans perdre le contexte' : 'Suggest without losing context',
                        'body' => $isFrench
                            ? 'Les suggestions restent branchees sur un workflow reel.'
                            : 'Suggestions stay attached to a real workflow instead of floating as generic ideas.',
                        'image_key' => 'collab-laptop-desk',
                    ],
                    [
                        'title' => $isFrench ? 'Garder une vraie validation humaine' : 'Keep real human validation',
                        'body' => $isFrench
                            ? 'La page montre mieux ou la decision doit rester partagee.'
                            : 'The page makes it clearer where the decision still needs to stay shared and human.',
                        'image_key' => 'meeting-room-laptops',
                    ],
                ],
            ]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function commandCenterSections(string $locale): array
    {
        $isFrench = $locale === 'fr';

        return [
            self::featureTabsSection('command-center-flow', $locale, [
                'background_color' => '#ffffff',
                'kicker' => $isFrench ? 'Workflow du module' : 'Module workflow',
                'title' => $isFrench ? 'Suivez le pilotage comme une boucle entre signal, priorite et decision' : 'Follow leadership as a loop between signal, priority, and decision',
                'body' => $isFrench
                    ? 'Les onglets montrent comment le module remonte un signal, compare, priorise puis redescend vers les bonnes equipes.'
                    : 'The tabs show how the module raises a signal, compares, prioritizes, and then sends the next move back toward the right teams.',
                'primary_label' => $isFrench ? 'Voir la solution Pilotage multi-entreprise' : 'See the Multi-Entity Oversight solution',
                'primary_href' => '/pages/solution-multi-entity-oversight',
                'tabs' => [
                    [
                        'label' => $isFrench ? 'Remonter' : 'Raise',
                        'icon' => 'clipboard-check',
                        'title' => $isFrench ? 'Faire emerger les signaux importants' : 'Bring important signals to the surface',
                        'body' => $isFrench
                            ? 'Le module agrandit les signaux utiles pour aider la direction a voir ce qui doit remonter en premier.'
                            : 'The module amplifies useful signals so leadership can see what needs attention first.',
                        'cta_label' => $isFrench ? 'Voir Operations' : 'See Operations',
                        'cta_href' => '/pages/operations',
                        'image_key' => 'team-laptop-window',
                    ],
                    [
                        'label' => $isFrench ? 'Comparer' : 'Compare',
                        'icon' => 'clipboard-list',
                        'title' => $isFrench ? 'Lire les ecarts sans casser la vue commune' : 'Read gaps without breaking the shared view',
                        'body' => $isFrench
                            ? 'Le module montre mieux ou se trouvent les goulets, charges et variations importantes.'
                            : 'The module shows more clearly where the bottlenecks, pressure, and important variations live.',
                        'cta_label' => $isFrench ? 'Voir la solution' : 'See the solution',
                        'cta_href' => '/pages/solution-multi-entity-oversight',
                        'image_key' => 'meeting-room-laptops',
                    ],
                    [
                        'label' => $isFrench ? 'Prioriser' : 'Prioritize',
                        'icon' => 'calendar-days',
                        'title' => $isFrench ? 'Redonner un cap commun aux equipes' : 'Give teams a shared direction again',
                        'body' => $isFrench
                            ? 'Une fois la lecture faite, la page montre mieux comment la priorite redescend vers les bonnes personnes.'
                            : 'Once the reading is clear, the page shows better how priority flows back toward the right people.',
                        'cta_label' => $isFrench ? 'Voir Sales & CRM' : 'See Sales & CRM',
                        'cta_href' => '/pages/sales-crm',
                        'image_key' => 'workflow-plan',
                    ],
                    [
                        'label' => $isFrench ? 'Arbitrer' : 'Arbitrate',
                        'icon' => 'file-text',
                        'title' => $isFrench ? 'Fermer la boucle sur une decision exploitable' : 'Close the loop with a usable decision',
                        'body' => $isFrench
                            ? 'Le pilotage se lit maintenant comme un poste de commande transversal avec une suite plus nette.'
                            : 'Leadership now reads like a cross-functional command space with a much clearer follow-through.',
                        'cta_label' => $isFrench ? 'Voir Commerce' : 'See Commerce',
                        'cta_href' => '/pages/commerce',
                        'image_key' => 'warehouse-worker',
                    ],
                ],
            ]),
            self::showcaseSection('command-center-cta', $locale, [
                'kicker' => $isFrench ? 'Pret a piloter plus lucidement' : 'Ready to lead with more clarity',
                'title' => $isFrench
                    ? 'Command Center suit maintenant le meme format narratif que les autres pages modules'
                    : 'Command Center now follows the same narrative format as the other module pages',
                'body' => $isFrench
                    ? 'La page montre mieux pourquoi ce module existe et comment il relie les autres blocs de la plateforme.'
                    : 'The page explains more clearly why this module exists and how it connects the rest of the platform.',
                'primary_label' => $isFrench ? 'Voir la solution Pilotage multi-entreprise' : 'See the Multi-Entity Oversight solution',
                'primary_href' => '/pages/solution-multi-entity-oversight',
                'secondary_label' => $isFrench ? 'Voir les tarifs' : 'View pricing',
                'secondary_href' => '/pricing',
                'aside_link_label' => $isFrench ? 'Voir Operations' : 'See Operations',
                'aside_link_href' => '/pages/operations',
                'image_key' => 'meeting-room-laptops',
                'aside_image_key' => 'team-laptop-window',
                'badge_label' => 'Module',
                'badge_value' => 'Command Center',
                'badge_note' => $isFrench
                    ? 'Signal, priorite et decision dans un meme poste de pilotage'
                    : 'Signal, priority, and decision inside one shared command view',
            ]),
            self::storyGridSection('command-center-proof', $locale, [
                'kicker' => $isFrench ? 'Moments visibles' : 'Visible moments',
                'title' => $isFrench
                    ? 'Le module Command Center devient plus lisible quand ses moments clefs restent distincts'
                    : 'Command Center becomes easier to read when its key moments stay distinct',
                'body' => $isFrench
                    ? 'Les cartes montrent des usages differents du pilotage au lieu de le reduire a un simple dashboard.'
                    : 'The cards show different leadership uses instead of reducing the module to a simple dashboard.',
                'primary_label' => $isFrench ? 'Voir la solution Pilotage multi-entreprise' : 'See the Multi-Entity Oversight solution',
                'primary_href' => '/pages/solution-multi-entity-oversight',
                'cards' => [
                    [
                        'title' => $isFrench ? 'Voir plus vite ce qui compte' : 'See what matters faster',
                        'body' => $isFrench
                            ? 'Le module aide a faire remonter les signaux utiles au bon niveau.'
                            : 'The module helps the most useful signals rise to the right level more quickly.',
                        'image_key' => 'team-laptop-window',
                    ],
                    [
                        'title' => $isFrench ? 'Comparer sans casser la lecture' : 'Compare without breaking the reading flow',
                        'body' => $isFrench
                            ? 'La comparaison reste dans une vue plus simple a comprendre.'
                            : 'Comparison stays inside a view that is much easier to understand.',
                        'image_key' => 'meeting-room-laptops',
                    ],
                    [
                        'title' => $isFrench ? 'Rendre la prochaine action plus nette' : 'Make the next action clearer',
                        'body' => $isFrench
                            ? 'La page montre mieux comment une decision revient vers les equipes.'
                            : 'The page shows more clearly how a decision flows back toward the teams who need to act on it.',
                        'image_key' => 'workflow-plan',
                    ],
                ],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function featureTabsSection(string $id, string $locale, array $config): array
    {
        return [
            'id' => $id,
            'enabled' => true,
            'source_id' => null,
            'use_source' => false,
            'background_color' => (string) ($config['background_color'] ?? ''),
            'background_preset' => '',
            'layout' => 'feature_tabs',
            'image_position' => 'left',
            'alignment' => 'center',
            'density' => 'normal',
            'tone' => 'default',
            'visibility' => self::visibility(),
            'kicker' => (string) ($config['kicker'] ?? ''),
            'title' => (string) ($config['title'] ?? ''),
            'body' => self::html($config['body'] ?? ''),
            'note' => '',
            'feature_tabs_style' => 'workflow',
            'feature_tabs_font_size' => 28,
            'feature_tabs' => array_values(array_map(
                fn ($tab, $index) => self::featureTab(is_array($tab) ? $tab : [], $locale, $id.'-tab-'.($index + 1)),
                is_array($config['tabs'] ?? null) ? $config['tabs'] : [],
                array_keys(is_array($config['tabs'] ?? null) ? $config['tabs'] : [])
            )),
            'primary_label' => (string) ($config['primary_label'] ?? ''),
            'primary_href' => (string) ($config['primary_href'] ?? ''),
            'secondary_label' => (string) ($config['secondary_label'] ?? ''),
            'secondary_href' => (string) ($config['secondary_href'] ?? ''),
            'industry_cards' => [],
            'story_cards' => [],
            'testimonial_cards' => [],
            'stats' => [],
            'hero_images' => [],
            'items' => [],
            'image_url' => '',
            'image_alt' => '',
            'aside_kicker' => '',
            'aside_title' => '',
            'aside_body' => '',
            'aside_items' => [],
            'aside_link_label' => '',
            'aside_link_href' => '',
            'aside_image_url' => '',
            'aside_image_alt' => '',
            'showcase_badge_label' => '',
            'showcase_badge_value' => '',
            'showcase_badge_note' => '',
            'showcase_divider_style' => 'diagonal',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function featureTab(array $config, string $locale, string $fallbackId): array
    {
        $image = isset($config['image_key'])
            ? PublicPageStockImages::visual((string) $config['image_key'], $locale)
            : ['image_url' => '', 'image_alt' => ''];

        return [
            'id' => (string) ($config['id'] ?? $fallbackId),
            'label' => (string) ($config['label'] ?? ''),
            'icon' => (string) ($config['icon'] ?? 'clipboard-check'),
            'role' => (string) ($config['role'] ?? ''),
            'title' => (string) ($config['title'] ?? ''),
            'body' => self::html($config['body'] ?? ''),
            'story' => self::html($config['story'] ?? ''),
            'metric' => (string) ($config['metric'] ?? ''),
            'person' => (string) ($config['person'] ?? ''),
            'items' => array_values(is_array($config['items'] ?? null) ? $config['items'] : []),
            'cta_label' => (string) ($config['cta_label'] ?? ''),
            'cta_href' => (string) ($config['cta_href'] ?? ''),
            'image_url' => $image['image_url'],
            'image_alt' => $image['image_alt'],
            'avatar_url' => (string) ($config['avatar_url'] ?? ''),
            'avatar_alt' => (string) ($config['avatar_alt'] ?? ''),
            'children' => array_values(array_map(
                fn ($child, $index) => self::featureTabChild(is_array($child) ? $child : [], $locale, ((string) ($config['id'] ?? $fallbackId)).'-child-'.($index + 1)),
                is_array($config['children'] ?? null) ? $config['children'] : [],
                array_keys(is_array($config['children'] ?? null) ? $config['children'] : [])
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function featureTabChild(array $config, string $locale, string $fallbackId): array
    {
        $image = isset($config['image_key'])
            ? PublicPageStockImages::visual((string) $config['image_key'], $locale)
            : ['image_url' => '', 'image_alt' => ''];

        return [
            'id' => (string) ($config['id'] ?? $fallbackId),
            'label' => (string) ($config['label'] ?? ''),
            'title' => (string) ($config['title'] ?? ''),
            'body' => self::html($config['body'] ?? ''),
            'cta_label' => (string) ($config['cta_label'] ?? ''),
            'cta_href' => (string) ($config['cta_href'] ?? ''),
            'image_url' => $image['image_url'],
            'image_alt' => $image['image_alt'],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function showcaseSection(string $id, string $locale, array $config): array
    {
        $image = isset($config['image_key'])
            ? PublicPageStockImages::visual((string) $config['image_key'], $locale)
            : ['image_url' => '', 'image_alt' => ''];
        $asideImage = isset($config['aside_image_key'])
            ? PublicPageStockImages::visual((string) $config['aside_image_key'], $locale)
            : ['image_url' => '', 'image_alt' => ''];

        return [
            'id' => $id,
            'enabled' => true,
            'source_id' => null,
            'use_source' => false,
            'background_color' => '',
            'background_preset' => 'deep-ocean',
            'layout' => 'showcase_cta',
            'image_position' => 'right',
            'alignment' => 'left',
            'density' => 'normal',
            'tone' => 'contrast',
            'visibility' => self::visibility(),
            'kicker' => (string) ($config['kicker'] ?? ''),
            'title' => (string) ($config['title'] ?? ''),
            'body' => self::html($config['body'] ?? ''),
            'note' => '',
            'primary_label' => (string) ($config['primary_label'] ?? ''),
            'primary_href' => (string) ($config['primary_href'] ?? ''),
            'secondary_label' => (string) ($config['secondary_label'] ?? ''),
            'secondary_href' => (string) ($config['secondary_href'] ?? ''),
            'aside_link_label' => (string) ($config['aside_link_label'] ?? ''),
            'aside_link_href' => (string) ($config['aside_link_href'] ?? ''),
            'image_url' => $image['image_url'],
            'image_alt' => $image['image_alt'],
            'aside_image_url' => $asideImage['image_url'],
            'aside_image_alt' => $asideImage['image_alt'],
            'aside_kicker' => '',
            'aside_title' => '',
            'aside_body' => '',
            'aside_items' => [],
            'showcase_badge_label' => (string) ($config['badge_label'] ?? ''),
            'showcase_badge_value' => (string) ($config['badge_value'] ?? ''),
            'showcase_badge_note' => (string) ($config['badge_note'] ?? ''),
            'showcase_divider_style' => 'notch',
            'feature_tabs' => [],
            'feature_tabs_style' => 'editorial',
            'feature_tabs_font_size' => 18,
            'industry_cards' => [],
            'story_cards' => [],
            'testimonial_cards' => [],
            'stats' => [],
            'hero_images' => [],
            'items' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function storyGridSection(string $id, string $locale, array $config): array
    {
        return [
            'id' => $id,
            'enabled' => true,
            'source_id' => null,
            'use_source' => false,
            'background_color' => '#ffffff',
            'background_preset' => '',
            'layout' => 'story_grid',
            'image_position' => 'left',
            'alignment' => 'center',
            'density' => 'normal',
            'tone' => 'default',
            'visibility' => self::visibility(),
            'kicker' => (string) ($config['kicker'] ?? ''),
            'title' => (string) ($config['title'] ?? ''),
            'body' => self::html($config['body'] ?? ''),
            'note' => '',
            'primary_label' => (string) ($config['primary_label'] ?? ''),
            'primary_href' => (string) ($config['primary_href'] ?? ''),
            'secondary_label' => '',
            'secondary_href' => '',
            'story_cards' => array_values(array_map(
                fn ($card, $index) => self::storyCard(is_array($card) ? $card : [], $locale, $id.'-card-'.($index + 1)),
                is_array($config['cards'] ?? null) ? $config['cards'] : [],
                array_keys(is_array($config['cards'] ?? null) ? $config['cards'] : [])
            )),
            'feature_tabs' => [],
            'feature_tabs_style' => 'editorial',
            'feature_tabs_font_size' => 28,
            'industry_cards' => [],
            'testimonial_cards' => [],
            'stats' => [],
            'hero_images' => [],
            'items' => [],
            'image_url' => '',
            'image_alt' => '',
            'aside_kicker' => '',
            'aside_title' => '',
            'aside_body' => '',
            'aside_items' => [],
            'aside_link_label' => '',
            'aside_link_href' => '',
            'aside_image_url' => '',
            'aside_image_alt' => '',
            'showcase_badge_label' => '',
            'showcase_badge_value' => '',
            'showcase_badge_note' => '',
            'showcase_divider_style' => 'diagonal',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function storyCard(array $config, string $locale, string $fallbackId): array
    {
        $image = isset($config['image_key'])
            ? PublicPageStockImages::visual((string) $config['image_key'], $locale)
            : ['image_url' => '', 'image_alt' => ''];

        return [
            'id' => (string) ($config['id'] ?? $fallbackId),
            'title' => (string) ($config['title'] ?? ''),
            'body' => self::html($config['body'] ?? ''),
            'image_url' => $image['image_url'],
            'image_alt' => $image['image_alt'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function visibility(): array
    {
        return [
            'locales' => [],
            'auth' => 'any',
            'roles' => [],
            'plans' => [],
            'device' => 'all',
            'start_at' => null,
            'end_at' => null,
        ];
    }

    private static function html($value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        if (str_starts_with($text, '<')) {
            return $text;
        }

        return '<p>'.$text.'</p>';
    }
}
