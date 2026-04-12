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
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    private static function localizedEnglishSections(string $slug, string $locale, array $sections): array
    {
        $overrides = PublicProductPageLocalizedOverrides::for($slug, $locale);

        return $overrides === []
            ? $sections
            : self::withOverrides($sections, $overrides);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function salesCrmSections(string $locale): array
    {
        if ($locale === 'es') {
            return self::localizedEnglishSections('sales-crm', $locale, self::salesCrmSections('en'));
        }

        $isFrench = $locale === 'fr';

        return [
            self::featureTabsSection('sales-crm-flow', $locale, [
                'background_color' => '#ffffff',
                'kicker' => $isFrench ? 'Un système sur tout le cycle commercial' : 'One system across the full sales workflow',
                'title' => $isFrench ? 'Transformez la demande entrante en travail approuvé avec moins de friction' : 'Turn inbound demand into approved work with less friction',
                'body' => $isFrench
                    ? 'Sales & CRM garde la capture de demande, la qualification, le devis, le contexte client et le suivi commercial dans un même flux pour que l’équipe avance plus vite sans perdre la prochaine action.'
                    : 'Sales & CRM keeps request capture, qualification, quoting, customer context, and follow-up connected so your team can move faster without losing track of the next step.',
                'tabs' => [
                    [
                        'label' => $isFrench ? 'Capter la demande' : 'Capture Demand',
                        'icon' => 'clipboard-check',
                        'role' => $isFrench ? 'Acquisition et premier contact' : 'Acquisition and first response',
                        'title' => $isFrench ? 'Facilitez l’entrée des bonnes demandes dans votre pipeline' : 'Make it easier for the right prospects to reach you',
                        'body' => $isFrench
                            ? 'Rassemblez formulaires, demandes web, avis et premiers messages dans une même couche d’acquisition pour que la demande arrive plus proprement et reste visible.'
                            : 'Bring inbound forms, online requests, reviews, and first-response workflows into one connected intake layer so demand starts clean and visible.',
                        'story' => $isFrench
                            ? 'Les premiers échanges restent plus propres dès l’arrivée de la demande, ce qui facilite la qualification commerciale et réduit les pertes de contexte.'
                            : 'The first interactions stay cleaner from the moment demand enters, which makes qualification easier and reduces context loss.',
                        'metric' => $isFrench ? 'Une entrée plus claire dans le pipeline' : 'A cleaner entry into the pipeline',
                        'person' => $isFrench ? 'Équipe acquisition' : 'Acquisition team',
                        'cta_label' => $isFrench ? 'Explorer Marketing & Loyalty' : 'Explore Marketing & Loyalty',
                        'cta_href' => '/pages/marketing-loyalty',
                        'image_key' => 'marketing-desk',
                        'avatar_url' => '/images/presets/avatar-1.svg',
                        'avatar_alt' => $isFrench ? 'Portrait équipe croissance' : 'Growth team portrait',
                        'children' => [
                            [
                                'label' => $isFrench ? 'Formulaires entrants' : 'Inbound forms',
                                'title' => $isFrench ? 'Faites entrer la demande avec un meilleur niveau de détail' : 'Bring demand in with the right level of detail',
                                'body' => $isFrench
                                    ? 'Centralisez les formulaires et demandes web pour éviter les pertes d’information dès le premier contact.'
                                    : 'Centralize forms and web requests so less information gets lost from the first touch.',
                                'cta_label' => $isFrench ? 'Voir la capture de demande' : 'See request capture',
                                'cta_href' => '#',
                                'image_key' => 'office-collaboration',
                            ],
                            [
                                'label' => $isFrench ? 'Premières réponses' : 'First responses',
                                'title' => $isFrench ? 'Répondez plus vite aux nouvelles demandes' : 'Respond faster to new requests',
                                'body' => $isFrench
                                    ? 'Gardez un premier délai de réponse court pour montrer une entreprise réactive dès les premiers échanges.'
                                    : 'Keep first-response times short so your business feels responsive from the first interaction.',
                                'cta_label' => $isFrench ? 'Voir la réponse rapide' : 'See fast response',
                                'cta_href' => '#',
                                'image_key' => 'desk-phone-laptop',
                            ],
                            [
                                'label' => $isFrench ? 'Avis et réputation' : 'Reviews and reputation',
                                'title' => $isFrench ? 'Renforcez la confiance avant même le devis' : 'Build trust before the quote is even sent',
                                'body' => $isFrench
                                    ? 'Utilisez les avis et signaux de réputation pour mieux convertir la demande déjà qualifiée.'
                                    : 'Use reviews and reputation signals to convert qualified demand more effectively.',
                                'cta_label' => $isFrench ? 'Voir la réputation' : 'See reputation',
                                'cta_href' => '#',
                                'image_key' => 'marketing-desk',
                            ],
                            [
                                'label' => $isFrench ? 'Liens de partage' : 'Shareable links',
                                'title' => $isFrench ? 'Diffusez plus facilement votre offre et vos points d’entrée' : 'Make your offer and entry points easier to share',
                                'body' => $isFrench
                                    ? 'Partagez formulaires, pages et liens utiles avec une présentation plus claire pour accélérer recommandations et bouche-à-oreille.'
                                    : 'Share forms, pages, and useful links more cleanly so referrals and word of mouth are easier to trigger.',
                                'cta_label' => $isFrench ? 'Voir les liens' : 'See links',
                                'cta_href' => '#',
                                'image_key' => 'salon-front-desk',
                            ],
                        ],
                    ],
                    [
                        'label' => $isFrench ? 'Devis et relance' : 'Quote and Follow Up',
                        'icon' => 'file-text',
                        'role' => $isFrench ? 'Qualification, devis et suivi' : 'Qualification, quoting, and follow-up',
                        'title' => $isFrench ? 'Passez plus vite de la demande au devis sans perdre le contexte client' : 'Move faster from request to quote without losing the customer context',
                        'body' => $isFrench
                            ? 'Qualifiez la demande, préparez le devis, ajoutez des options, et faites avancer le suivi depuis un même espace commercial au lieu de disperser l’information entre notes et boîtes mail.'
                            : 'Qualify the request, build the quote, add options, and keep follow-up moving from one commercial workspace instead of scattered notes and inboxes.',
                        'story' => $isFrench
                            ? 'Les devis sortent plus vite, les options restent cohérentes, et les relances ne dépendent plus d’un suivi manuel dispersé.'
                            : 'Quotes go out faster, options stay consistent, and follow-up no longer depends on scattered manual reminders.',
                        'metric' => $isFrench ? 'Des devis plus propres, mieux suivis' : 'Cleaner quotes with clearer follow-up',
                        'person' => $isFrench ? 'Équipe commerciale' : 'Sales team',
                        'cta_label' => $isFrench ? 'Explorer Sales & CRM' : 'Explore Sales & CRM',
                        'cta_href' => '/pages/sales-crm',
                        'image_key' => 'workflow-plan',
                        'children' => [
                            [
                                'label' => $isFrench ? 'Capture et qualification' : 'Capture and qualification',
                                'title' => $isFrench ? 'Faites entrer la demande avec le bon niveau de détail' : 'Bring demand in with the right level of detail',
                                'body' => $isFrench
                                    ? 'Ajoutez des points d’entrée simples qui aident l’équipe à qualifier le besoin plus tôt et à orienter plus vite la suite.'
                                    : 'Add simple intake points that help the team qualify the need earlier and route the next step faster.',
                                'cta_label' => $isFrench ? 'Voir la capture de leads' : 'See lead capture',
                                'cta_href' => '#',
                                'image_key' => 'salon-front-desk',
                            ],
                            [
                                'label' => $isFrench ? 'Modèles de devis' : 'Quote templates',
                                'title' => $isFrench ? 'Envoyez des devis cohérents en moins de temps' : 'Send consistent quotes in less time',
                                'body' => $isFrench
                                    ? 'Préchargez services, prix et options fréquentes pour sortir des propositions claires sans repartir de zéro.'
                                    : 'Preload services, pricing, and common options so teams can send clear proposals without rebuilding them every time.',
                                'cta_label' => $isFrench ? 'Voir les modèles de devis' : 'See quote templates',
                                'cta_href' => '#',
                                'image_key' => 'workflow-plan',
                            ],
                            [
                                'label' => $isFrench ? 'Options et extras' : 'Options and extras',
                                'title' => $isFrench ? 'Ajoutez plus de valeur sans alourdir le devis' : 'Add more value without making the quote heavier',
                                'body' => $isFrench
                                    ? 'Ajoutez des options, des extras, et des services complémentaires pour mieux structurer la proposition commerciale.'
                                    : 'Add options, extras, and complementary services to strengthen the commercial proposal without manual rework.',
                                'cta_label' => $isFrench ? 'Voir les options de devis' : 'See quote options',
                                'cta_href' => '#',
                                'image_key' => 'service-install',
                            ],
                            [
                                'label' => $isFrench ? 'Relances visibles' : 'Visible follow-ups',
                                'title' => $isFrench ? 'Relancez au bon moment sans perdre d’opportunité' : 'Follow up at the right time without losing opportunities',
                                'body' => $isFrench
                                    ? 'Gardez les rappels et relances liés à la même opportunité pour que le prochain geste commercial reste évident.'
                                    : 'Keep reminders and follow-up tied to the same opportunity so the next commercial action stays obvious.',
                                'cta_label' => $isFrench ? 'Voir les suivis' : 'See follow-ups',
                                'cta_href' => '#',
                                'image_key' => 'office-collaboration',
                            ],
                        ],
                    ],
                    [
                        'label' => $isFrench ? 'Coordonner l’exécution' : 'Coordinate Delivery',
                        'icon' => 'calendar-days',
                        'role' => $isFrench ? 'Passage vers les opérations' : 'Handoff to operations',
                        'title' => $isFrench ? 'Transmettez le travail approuvé aux opérations avec moins de confusion' : 'Hand off approved work to operations with less confusion',
                        'body' => $isFrench
                            ? 'Une fois l’opportunité approuvée, le planning, les détails du job, les affectations, et l’exécution terrain peuvent continuer depuis le même contexte opérationnel.'
                            : 'Once the opportunity is approved, scheduling, job details, assignments, and field execution can continue from the same operating context.',
                        'story' => $isFrench
                            ? 'Le passage du bureau au terrain reste plus propre parce que le contexte client, les détails du job, et les prochaines actions voyagent ensemble.'
                            : 'The handoff from office to field stays cleaner because customer context, job details, and next steps travel together.',
                        'metric' => $isFrench ? 'Un meilleur passage du commercial à l’exécution' : 'A cleaner handoff from sales to delivery',
                        'person' => $isFrench ? 'Équipe opérations' : 'Operations team',
                        'cta_label' => $isFrench ? 'Explorer Operations' : 'Explore Operations',
                        'cta_href' => '/pages/operations',
                        'image_key' => 'field-checklist',
                        'children' => [
                            [
                                'label' => $isFrench ? 'Planning' : 'Scheduling',
                                'title' => $isFrench ? 'Gardez le bon contexte quand le travail entre au planning' : 'Keep the right context when work enters the schedule',
                                'body' => $isFrench
                                    ? 'Faites passer le travail approuvé vers le planning sans perdre les détails qui comptent pour la suite.'
                                    : 'Move approved work into the schedule without losing the details that matter for execution.',
                                'cta_label' => $isFrench ? 'Voir la planification' : 'See scheduling',
                                'cta_href' => '#',
                                'image_key' => 'service-tablet',
                            ],
                            [
                                'label' => $isFrench ? 'Affectation d équipe' : 'Team assignment',
                                'title' => $isFrench ? 'Affectez le bon job à la bonne équipe' : 'Assign the right job to the right team',
                                'body' => $isFrench
                                    ? 'Gardez les bonnes informations visibles pour affecter plus vite et avec moins d’allers-retours.'
                                    : 'Keep the right information visible so assignments happen faster and with less back and forth.',
                                'cta_label' => $isFrench ? 'Voir le dispatch' : 'See dispatch',
                                'cta_href' => '#',
                                'image_key' => 'service-team',
                            ],
                            [
                                'label' => $isFrench ? 'Exécution terrain' : 'Field execution',
                                'title' => $isFrench ? 'Arrivez sur site avec une lecture plus nette du job' : 'Arrive on site with a clearer read of the job',
                                'body' => $isFrench
                                    ? 'Gardez statuts, contexte client et points d’attention reliés au même flux une fois l’équipe sur place.'
                                    : 'Keep status, customer context, and key attention points tied to the same flow once the team is on site.',
                                'cta_label' => $isFrench ? 'Voir l’exécution terrain' : 'See field execution',
                                'cta_href' => '#',
                                'image_key' => 'field-checklist',
                            ],
                            [
                                'label' => $isFrench ? 'Historique client' : 'Customer history',
                                'title' => $isFrench ? 'Retrouvez le contexte complet avant chaque visite' : 'Recover the full context before every visit',
                                'body' => $isFrench
                                    ? 'Gardez notes, photos, demandes, et anciens jobs au même endroit pour que les équipes arrivent préparées chez le client.'
                                    : 'Keep notes, photos, requests, and previous jobs in one place so teams arrive prepared at the customer site.',
                                'cta_label' => $isFrench ? 'Voir les fiches client' : 'See customer records',
                                'cta_href' => '#',
                                'image_key' => 'team-laptop-window',
                            ],
                        ],
                    ],
                    [
                        'label' => $isFrench ? 'Protéger le revenu' : 'Protect Revenue',
                        'icon' => 'circle-dollar-sign',
                        'role' => $isFrench ? 'Facturation, paiements, et revenu' : 'Billing, payments, and revenue',
                        'title' => $isFrench ? 'Transformez le travail approuvé en facturation et paiements avec plus de visibilité' : 'Turn approved work into invoicing and payments with better visibility',
                        'body' => $isFrench
                            ? 'Gardez la facturation, les rappels, l’encaissement, et le suivi du revenu reliés à la demande d’origine pour que le cycle commercial se termine proprement.'
                            : 'Keep billing, reminders, payment collection, and revenue tracking connected to the original request so the commercial cycle ends cleanly.',
                        'story' => $isFrench
                            ? 'La fin du cycle reste plus propre quand factures, rappels et paiements restent liés au même travail approuvé.'
                            : 'The end of the cycle stays cleaner when invoices, reminders, and payments remain tied to the same approved work.',
                        'metric' => $isFrench ? 'Une meilleure visibilité sur le revenu' : 'Better revenue visibility',
                        'person' => $isFrench ? 'Équipe finance' : 'Finance team',
                        'cta_label' => $isFrench ? 'Explorer Commerce' : 'Explore Commerce',
                        'cta_href' => '/pages/commerce',
                        'image_key' => 'payments-terminal',
                        'children' => [
                            [
                                'label' => $isFrench ? 'Facturation' : 'Invoicing',
                                'title' => $isFrench ? 'Faites sortir la facture plus vite après validation' : 'Get the invoice out faster after approval',
                                'body' => $isFrench
                                    ? 'Générez la facture sans ressaisir les informations déjà présentes dans le flux commercial et opérationnel.'
                                    : 'Generate the invoice without re-entering information already present in the sales and operations flow.',
                                'cta_label' => $isFrench ? 'Voir la facturation' : 'See invoicing',
                                'cta_href' => '#',
                                'image_key' => 'office-collaboration',
                            ],
                            [
                                'label' => $isFrench ? 'Paiements' : 'Payments',
                                'title' => $isFrench ? 'Raccourcissez le délai entre travail et encaissement' : 'Shorten the time between completed work and cash collection',
                                'body' => $isFrench
                                    ? 'Gardez l’encaissement relié au travail approuvé pour réduire les délais et limiter les oublis.'
                                    : 'Keep payment collection tied to approved work so delays and missed steps are reduced.',
                                'cta_label' => $isFrench ? 'Voir les paiements' : 'See payments',
                                'cta_href' => '#',
                                'image_key' => 'payments-terminal',
                            ],
                            [
                                'label' => $isFrench ? 'Rappels' : 'Reminders',
                                'title' => $isFrench ? 'Relancez sans disperser le suivi' : 'Follow up without scattering the process',
                                'body' => $isFrench
                                    ? 'Gardez les rappels de paiement visibles dans le même flux pour que la suite reste claire.'
                                    : 'Keep payment reminders visible in the same flow so the next step stays clear.',
                                'cta_label' => $isFrench ? 'Voir les rappels' : 'See reminders',
                                'cta_href' => '#',
                                'image_key' => 'desk-phone-laptop',
                            ],
                            [
                                'label' => $isFrench ? 'Lecture du revenu' : 'Revenue visibility',
                                'title' => $isFrench ? 'Gardez une lecture plus claire de ce qui a été gagné' : 'Keep a clearer view of what was earned',
                                'body' => $isFrench
                                    ? 'Reliez les revenus et la clôture du travail pour mieux comprendre ce qui avance proprement dans l’activité.'
                                    : 'Connect revenue and work closure so it is easier to understand what is moving cleanly through the business.',
                                'cta_label' => $isFrench ? 'Voir la lecture du revenu' : 'See revenue visibility',
                                'cta_href' => '#',
                                'image_key' => 'warehouse-worker',
                            ],
                        ],
                    ],
                ],
            ]),
            self::showcaseSection('sales-crm-cta', $locale, [
                'kicker' => $isFrench ? 'Prêt à structurer la conversion' : 'Ready to structure conversion',
                'title' => $isFrench
                    ? 'Convertissez davantage la demande que vous générez déjà'
                    : 'Start converting more of the demand you already generate',
                'body' => $isFrench
                    ? 'Remplacez une capture de demande, des devis, et des relances fragmentées par un même espace commercial qui aide votre équipe à aller plus vite, à rester plus cohérente, et à mieux piloter le passage du premier contact au travail approuvé.'
                    : 'Replace fragmented intake, quoting, and follow-up with one commercial workspace that helps your team stay faster, more consistent, and easier to manage from first contact to approved work.',
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
                    ? 'Capture, devis, et relance dans un même flux connecté'
                    : 'Intake, quoting, and follow-up in one connected flow',
            ]),
            self::storyGridSection('sales-crm-proof', $locale, [
                'title' => $isFrench ? 'Un pipeline plus clair et des devis plus rapides à faire avancer' : 'Built for clearer pipelines and faster quote turnaround',
                'cards' => [
                    [
                        'title' => $isFrench ? 'Gardez chaque opportunité visible' : 'Keep every opportunity visible',
                        'body' => $isFrench
                            ? 'Donnez à l’équipe une vue partagée des demandes entrantes, des changements de statut, des prochaines actions, et de l’avancement du pipeline pour laisser moins d’opportunités se refroidir.'
                            : 'Give the team one shared view of incoming requests, status changes, next actions, and deal movement so fewer opportunities go cold.',
                        'image_key' => 'service-team',
                    ],
                    [
                        'title' => $isFrench ? 'Devis plus cohérents, plus faciles à envoyer' : 'Quote with more consistency',
                        'body' => $isFrench
                            ? 'Réutilisez le contexte client, les services, les options, et la logique commerciale pour envoyer des propositions plus propres sans refaire le même travail à chaque fois.'
                            : 'Reuse customer context, services, options, and sales logic to send cleaner proposals without rebuilding the same work every time.',
                        'image_key' => 'team-laptop-window',
                    ],
                    [
                        'title' => $isFrench ? 'Relancez sans perdre l’élan commercial' : 'Follow up without losing momentum',
                        'body' => $isFrench
                            ? 'Gardez rappels, messages, et handoffs reliés à la même fiche client pour que la prochaine action reste évidente jusqu’à l’approbation du travail.'
                            : 'Keep reminders, messages, and handoffs tied to the same customer record so the next step stays obvious until the work is approved.',
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
        if ($locale === 'es') {
            return self::localizedEnglishSections('reservations', $locale, self::reservationsSections('en'));
        }

        $isFrench = $locale === 'fr';

        return [
            self::featureTabsSection('reservations-flow', $locale, [
                'background_color' => '#ffffff',
                'kicker' => $isFrench ? 'Un parcours de réservation de la disponibilité au suivi' : 'One booking workflow from availability to follow-up',
                'title' => $isFrench ? 'Faites de la réservation un parcours client complet' : 'Turn booking into a complete customer journey',
                'body' => $isFrench
                    ? 'Reservations relie le choix du créneau, la confirmation, l’accueil, la gestion de file, et le suivi après visite pour que l’expérience reste claire du premier rendez-vous jusqu’au prochain.'
                    : 'Reservations connects slot selection, confirmation, arrival handling, queue flow, and post-visit follow-up so the experience stays clear from first booking to the next visit.',
                'primary_label' => $isFrench ? 'Voir la solution Reservations & files' : 'See the Reservations & Queues solution',
                'primary_href' => '/pages/solution-reservations-queues',
                'tabs' => [
                    [
                        'label' => $isFrench ? 'Proposer' : 'Offer',
                        'icon' => 'calendar-days',
                        'title' => $isFrench ? 'Rendez les disponibilités plus faciles à comprendre et à réserver' : 'Make availability easier to understand and easier to book',
                        'body' => $isFrench
                            ? 'Transformez la disponibilité en un point d’entrée plus clair pour que le client puisse choisir le bon créneau sans friction.'
                            : 'Turn live availability into a clearer entry point so customers can choose the right slot without friction.',
                        'cta_label' => $isFrench ? 'Voir la solution' : 'See the solution',
                        'cta_href' => '/pages/solution-reservations-queues',
                        'image_key' => 'marketing-desk',
                    ],
                    [
                        'label' => $isFrench ? 'Confirmer' : 'Confirm',
                        'icon' => 'clipboard-check',
                        'title' => $isFrench ? 'Stabilisez la visite avant l’arrivée du client' : 'Stabilize the visit before the customer arrives',
                        'body' => $isFrench
                            ? 'Gardez rappels, récapitulatif, et préparation visibles avant le rendez-vous pour que moins de visites glissent dans l’incertitude.'
                            : 'Keep reminders, recap, and preparation visible before the appointment so fewer visits drift into uncertainty.',
                        'cta_label' => $isFrench ? 'Voir Marketing & Loyalty' : 'See Marketing & Loyalty',
                        'cta_href' => '/pages/marketing-loyalty',
                        'image_key' => 'desk-phone-laptop',
                    ],
                    [
                        'label' => $isFrench ? 'Accueillir' : 'Welcome',
                        'icon' => 'clipboard-list',
                        'title' => $isFrench ? 'Absorbez les arrivées et la file plus fluidement sur place' : 'Absorb arrivals and queues more smoothly on site',
                        'body' => $isFrench
                            ? 'Gardez l’accueil, la gestion de file, et le passage vers le service reliés pour que le flux sur place reste plus maîtrisé.'
                            : 'Keep reception, queue handling, and handoff into service connected so on-site flow feels more controlled.',
                        'cta_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                        'cta_href' => '/pages/command-center',
                        'image_key' => 'service-tablet',
                    ],
                    [
                        'label' => $isFrench ? 'Suivre' : 'Follow Up',
                        'icon' => 'file-text',
                        'title' => $isFrench ? 'Prolongez la relation après la visite' : 'Extend the relationship after the visit',
                        'body' => $isFrench
                            ? 'Gardez la réservation reliée aux avis, rappels, offres, et prochains rendez-vous pour que la visite ne s’arrête pas à la simple confirmation.'
                            : 'Keep the booking connected to reviews, reminders, offers, and the next appointment so the visit does not end at confirmation alone.',
                        'cta_label' => $isFrench ? 'Voir la solution marketing' : 'See the marketing solution',
                        'cta_href' => '/pages/solution-marketing-loyalty',
                        'image_key' => 'meeting-room-laptops',
                    ],
                ],
            ]),
            self::showcaseSection('reservations-cta', $locale, [
                'kicker' => $isFrench ? 'Prêt à fluidifier la visite' : 'Ready to smooth the visit',
                'title' => $isFrench
                    ? 'Offrez une réservation plus pratique sans perdre le contrôle opérationnel'
                    : 'Offer convenient booking without losing operational control',
                'body' => $isFrench
                    ? 'Remplacez une prise de rendez-vous, des confirmations, et un accueil déconnectés par un même parcours qui aide les clients à réserver plus facilement et aide l’équipe à rester alignée avant, pendant, et après la visite.'
                    : 'Replace disconnected scheduling, confirmations, and arrival handling with one workflow that helps customers book more easily and helps teams stay aligned before, during, and after the visit.',
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
                    ? 'Disponibilité, confirmation, et accueil dans un même flux connecté'
                    : 'Availability, confirmation, and reception in one connected flow',
            ]),
            self::storyGridSection('reservations-proof', $locale, [
                'kicker' => $isFrench ? 'Moments clairs autour de la visite' : 'Clear moments across the visit',
                'title' => $isFrench
                    ? 'Conçu pour rendre la réservation, l’arrivée, et le suivi plus fluides'
                    : 'Built to make booking, arrival, and follow-up feel smoother',
                'body' => $isFrench
                    ? 'Gardez les moments clés avant, pendant, et après la visite visibles comme une même expérience pour que le client se sente guidé et que l’équipe reste en contrôle.'
                    : 'Keep the key moments before, during, and after the visit visible as part of the same experience so customers feel guided and teams stay in control.',
                'primary_label' => $isFrench ? 'Voir la solution Reservations & files' : 'See the Reservations & Queues solution',
                'primary_href' => '/pages/solution-reservations-queues',
                'cards' => [
                    [
                        'title' => $isFrench ? 'Un choix plus simple au moment de réserver' : 'A simpler choice at booking time',
                        'body' => $isFrench
                            ? 'Aidez le client à comprendre comment choisir le bon moment sans friction ni hésitation.'
                            : 'Help the customer understand how to choose the right moment without friction or uncertainty.',
                        'image_key' => 'marketing-desk',
                    ],
                    [
                        'title' => $isFrench ? 'Une arrivée plus fluide sur place' : 'A smoother arrival on site',
                        'body' => $isFrench
                            ? 'Donnez à l’accueil, à la file, et au passage vers le service une place plus claire dans l’expérience opérationnelle.'
                            : 'Give reception, queue flow, and handoff a clearer place in the operating experience.',
                        'image_key' => 'service-tablet',
                    ],
                    [
                        'title' => $isFrench ? 'Un vrai suivi après la visite' : 'A real follow-up after the visit',
                        'body' => $isFrench
                            ? 'Gardez la visite reliée au prochain message, au prochain rappel, ou au prochain rendez-vous au lieu d’arrêter le parcours trop tôt.'
                            : 'Keep the visit connected to the next message, the next reminder, or the next appointment instead of ending the journey too early.',
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
        if ($locale === 'es') {
            return self::localizedEnglishSections('operations', $locale, self::operationsSections('en'));
        }

        $isFrench = $locale === 'fr';

        return [
            self::featureTabsSection('operations-flow', $locale, [
                'background_color' => '#ffffff',
                'kicker' => $isFrench ? 'Un flux opérationnel du plan à la preuve' : 'One operating workflow from plan to proof',
                'title' => $isFrench ? 'Planifiez, affectez, exécutez, et clôturez le travail depuis une même vue opérationnelle' : 'Plan, assign, execute, and close work from one shared operational view',
                'body' => $isFrench
                    ? 'Operations garde le plan de charge, le dispatch, les détails du job, l’exécution terrain, et la preuve de complétion alignés pour que le bureau et le terrain ne travaillent pas avec deux versions différentes de la réalité.'
                    : 'Operations keeps workload planning, dispatch, job details, field execution, and proof of completion aligned so the office and the field are not working from different versions of reality.',
                'primary_label' => $isFrench ? 'Voir la solution Services terrain' : 'See the Field Services solution',
                'primary_href' => '/pages/solution-field-services',
                'tabs' => [
                    [
                        'label' => $isFrench ? 'Planifier' : 'Plan',
                        'icon' => 'calendar-days',
                        'title' => $isFrench ? 'Lisez la charge et les priorités avant le début de la journée' : 'Read workload and priorities before the day starts',
                        'body' => $isFrench
                            ? 'Donnez aux planificateurs une meilleure lecture de la capacité, de l’urgence, et de la pression de planning avant d’engager les ressources.'
                            : 'Give planners a clearer view of capacity, urgency, and scheduling pressure before resources are committed.',
                        'cta_label' => $isFrench ? 'Voir la solution' : 'See the solution',
                        'cta_href' => '/pages/solution-field-services',
                        'image_key' => 'workflow-plan',
                    ],
                    [
                        'label' => $isFrench ? 'Dispatcher' : 'Dispatch',
                        'icon' => 'clipboard-list',
                        'title' => $isFrench ? 'Donnez le bon contexte à la bonne équipe avant le départ' : 'Give the right team the right context before they leave',
                        'body' => $isFrench
                            ? 'Gardez les affectations, la préparation, et les détails du job visibles dans un même moment de coordination pour améliorer la qualité du handoff.'
                            : 'Keep assignments, preparation, and job details visible in the same coordination moment so handoff quality improves.',
                        'cta_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                        'cta_href' => '/pages/command-center',
                        'image_key' => 'service-tablet',
                    ],
                    [
                        'label' => $isFrench ? 'Intervenir' : 'Execute',
                        'icon' => 'wrench',
                        'title' => $isFrench ? 'Aidez le terrain à travailler avec une lecture plus nette du job' : 'Help field teams work with a clearer read of the job',
                        'body' => $isFrench
                            ? 'Rendez le statut, le contexte client, les checklists, et les preuves attendues plus faciles à suivre une fois l’équipe sur place.'
                            : 'Make status, customer context, checklists, and required proof easier to follow once the team is on site.',
                        'cta_label' => $isFrench ? 'Voir les services terrain' : 'See field services',
                        'cta_href' => '/pages/solution-field-services',
                        'image_key' => 'service-install',
                    ],
                    [
                        'label' => $isFrench ? 'Clôturer' : 'Close',
                        'icon' => 'file-text',
                        'title' => $isFrench ? 'Fermez la boucle avec une clôture plus propre et un meilleur suivi' : 'Close the loop with cleaner completion and better follow-through',
                        'body' => $isFrench
                            ? 'Gardez la validation, la preuve de travail, la lecture du revenu, et les prochaines actions connectées pour que le travail se termine de façon maîtrisée plutôt que précipitée.'
                            : 'Keep validation, proof of work, revenue visibility, and next steps connected so work ends in a controlled way instead of a rushed one.',
                        'cta_label' => $isFrench ? 'Voir Commerce' : 'See Commerce',
                        'cta_href' => '/pages/commerce',
                        'image_key' => 'field-checklist',
                    ],
                ],
            ]),
            self::showcaseSection('operations-cta', $locale, [
                'kicker' => $isFrench ? 'Prêt à structurer l’exécution' : 'Ready to structure execution',
                'title' => $isFrench
                    ? 'Donnez à chaque équipe la même source de vérité opérationnelle'
                    : 'Give every team the same source of operational truth',
                'body' => $isFrench
                    ? 'Remplacez un planning fragmenté, un dispatch éclaté, et un suivi terrain déconnecté par un même espace qui aide planificateurs, coordinateurs, et équipes terrain à rester alignés de l’affectation à la complétion.'
                    : 'Replace fragmented planning, side-channel dispatch, and disconnected field follow-up with one workspace that helps planners, dispatchers, and field teams stay aligned from assignment to completion.',
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
                    ? 'Planning, dispatch, et preuve terrain dans un même rythme connecté'
                    : 'Planning, dispatch, and field proof in one connected rhythm',
            ]),
            self::storyGridSection('operations-proof', $locale, [
                'kicker' => $isFrench ? 'Moments opérationnels visibles' : 'Clear operational moments',
                'title' => $isFrench
                    ? 'Conçu pour une exécution plus propre dans le réel'
                    : 'Built for cleaner execution in the real world',
                'body' => $isFrench
                    ? 'Gardez la planification, le handoff, et la complétion visibles comme des moments distincts pour que les équipes se préparent mieux, interviennent avec plus de contexte, et clôturent le travail avec moins de manques.'
                    : 'Keep planning, handoff, and completion visible as distinct moments so teams can prepare better, execute with more context, and close work with fewer gaps.',
                'primary_label' => $isFrench ? 'Voir la solution Services terrain' : 'See the Field Services solution',
                'primary_href' => '/pages/solution-field-services',
                'cards' => [
                    [
                        'title' => $isFrench ? 'Une lecture plus claire du plan de charge avant engagement' : 'A clearer read of workload before commitment',
                        'body' => $isFrench
                            ? 'Donnez au bureau une meilleure vue de la charge et des points de tension avant de verrouiller les ressources sur la journée.'
                            : 'Give the office a stronger view of workload and pressure points before resources are locked into the day.',
                        'image_key' => 'workflow-plan',
                    ],
                    [
                        'title' => $isFrench ? 'Un vrai moment de dispatch avant le départ' : 'A real dispatch moment before the team leaves',
                        'body' => $isFrench
                            ? 'Faites remonter les détails utiles avant le départ pour que l’équipe parte avec un meilleur contexte et moins de surprises.'
                            : 'Surface the details that matter before departure so the team leaves with better context and fewer surprises.',
                        'image_key' => 'service-tablet',
                    ],
                    [
                        'title' => $isFrench ? 'La preuve reste reliée au même flux' : 'Proof stays connected to the same workflow',
                        'body' => $isFrench
                            ? 'Gardez notes, checklists, photos, et preuves de complétion reliés au même job pour une clôture plus propre et plus facile à vérifier.'
                            : 'Keep notes, checklists, photos, and completion proof tied to the same job so closure is cleaner and easier to review.',
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
        if ($locale === 'es') {
            return self::localizedEnglishSections('commerce', $locale, self::commerceSections('en'));
        }

        $isFrench = $locale === 'fr';

        return [
            self::featureTabsSection('commerce-flow', $locale, [
                'background_color' => '#ffffff',
                'kicker' => $isFrench ? 'Un parcours commerce du catalogue à l’encaissement' : 'One commerce workflow from catalog to collection',
                'title' => $isFrench ? 'Transformez votre catalogue en revenu sans fragmenter l’expérience' : 'Turn your catalog into revenue without fragmenting the experience',
                'body' => $isFrench
                    ? 'Commerce relie la visibilité de l’offre, la commande guidée, la facturation et l’encaissement pour que la vente reste cohérente du premier clic jusqu’au revenu collecté.'
                    : 'Commerce connects offer visibility, guided ordering, invoicing, and payment collection so the sale stays coherent from first click to collected revenue.',
                'primary_label' => $isFrench ? 'Voir la solution commerce & catalogue' : 'See the Commerce & Catalog solution',
                'primary_href' => '/pages/solution-commerce-catalog',
                'tabs' => [
                    [
                        'label' => $isFrench ? 'Catalogue visible' : 'Visible catalog',
                        'icon' => 'clipboard-check',
                        'title' => $isFrench ? 'Rendez l’offre plus simple à parcourir et plus facile à comprendre' : 'Make the offer easier to browse and easier to trust',
                        'body' => $isFrench
                            ? 'Présentez produits, services et catégories dans une structure plus claire pour que le client comprenne ce qui est disponible avant même de commander.'
                            : 'Present products, services, and categories in a clearer structure so the customer understands what is available before the order starts.',
                        'cta_label' => $isFrench ? 'Voir la solution commerce' : 'See the commerce solution',
                        'cta_href' => '/pages/solution-commerce-catalog',
                        'image_key' => 'store-worker',
                    ],
                    [
                        'label' => $isFrench ? 'Commande guidée' : 'Guided order',
                        'icon' => 'clipboard-list',
                        'title' => $isFrench ? 'Gardez la commande lisible du choix jusqu’au récapitulatif' : 'Keep the order readable from selection to recap',
                        'body' => $isFrench
                            ? 'Aidez le client comme l’équipe à avancer dans le panier, les quantités et les choix produits sans casser le flux commercial.'
                            : 'Help the customer and the team move through cart, quantities, and product choices without breaking the commercial flow.',
                        'cta_label' => $isFrench ? 'Voir la boutique' : 'See the storefront',
                        'cta_href' => '/pages/solution-commerce-catalog',
                        'image_key' => 'store-boxes',
                    ],
                    [
                        'label' => $isFrench ? 'Facture sans rupture' : 'Invoice without friction',
                        'icon' => 'file-text',
                        'title' => $isFrench ? 'Laissez la facturation repartir du bon contexte au lieu de repartir de zéro' : 'Let invoicing pick up the right context instead of starting over',
                        'body' => $isFrench
                            ? 'Gardez la logique commerciale, les lignes utiles et la validation interne dans le même fil pour que la facture ressemble à la suite naturelle de la vente.'
                            : 'Keep the commercial logic, useful line items, and internal validation tied to the same thread so billing feels like the continuation of the sale.',
                        'cta_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                        'cta_href' => '/pages/command-center',
                        'image_key' => 'team-laptop-window',
                    ],
                    [
                        'label' => $isFrench ? 'Encaissement protégé' : 'Protected collection',
                        'icon' => 'circle-dollar-sign',
                        'title' => $isFrench ? 'Gardez paiement et lecture du revenu reliés à la transaction' : 'Keep payment and revenue visibility tied to the transaction',
                        'body' => $isFrench
                            ? 'Reliez l’encaissement, les rappels et le suivi du revenu à la vente d’origine pour que la facturation et le paiement ne dérivent pas dans des flux séparés.'
                            : 'Connect collection, reminders, and revenue tracking to the original sale so invoicing and payment do not drift into separate workflows.',
                        'cta_label' => $isFrench ? 'Voir Commerce' : 'See Commerce',
                        'cta_href' => '/pages/commerce',
                        'image_key' => 'warehouse-worker',
                    ],
                ],
            ]),
            self::showcaseSection('commerce-cta', $locale, [
                'kicker' => $isFrench ? 'Prêt à monétiser' : 'Ready to monetize',
                'title' => $isFrench
                    ? 'Vendez, facturez et encaissez depuis une même plateforme'
                    : 'Sell, invoice, and collect from one platform',
                'body' => $isFrench
                    ? 'Remplacez une boutique, une administration et des parcours de paiement déconnectés par un système qui rend le parcours commercial plus simple à piloter, plus rassurant et plus lisible du catalogue jusqu’au paiement collecté.'
                    : 'Replace disconnected storefront, admin, and payment workflows with a system that keeps the commercial journey easier to manage, easier to trust, and easier to monitor from catalog to collected payment.',
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
                    ? 'Catalogue, commande, facture et paiement dans un même flux connecté'
                    : 'Catalog, order, invoice, and payment in one connected flow',
            ]),
            self::storyGridSection('commerce-proof', $locale, [
                'kicker' => $isFrench ? 'Continuité commerciale' : 'Commercial continuity',
                'title' => $isFrench
                    ? 'Conçu pour les entreprises qui veulent une chaîne commerciale plus propre'
                    : 'Built for businesses that want better commercial continuity',
                'body' => $isFrench
                    ? 'Gardez la vente reliée du premier clic jusqu’au paiement collecté pour que catalogue, commande, facture et revenu ressemblent à un même système commercial plutôt qu’à des outils déconnectés.'
                    : 'Keep the sale connected from first click to collected payment so the catalog, the order, the invoice, and the revenue feel like one commercial system instead of disconnected tools.',
                'primary_label' => $isFrench ? 'Voir la solution commerce & catalogue' : 'See the Commerce & Catalog solution',
                'primary_href' => '/pages/solution-commerce-catalog',
                'cards' => [
                    [
                        'title' => $isFrench ? 'Le catalogue redevient une vraie porte d’entrée commerciale' : 'The catalog becomes a clearer commercial entry point',
                        'body' => $isFrench
                            ? 'Structurez l’offre pour que le client comprenne plus vite ce qu’il peut acheter, réserver ou ajouter avant même que la transaction commence.'
                            : 'Structure the offer so the customer understands faster what can be bought, booked, or added before the transaction starts.',
                        'image_key' => 'store-worker',
                    ],
                    [
                        'title' => $isFrench ? 'La logistique reste reliée à la vente' : 'Logistics stays connected to the sale',
                        'body' => $isFrench
                            ? 'Gardez stock, préparation et exécution visibles dans la même histoire pour que l’équipe ne pilote pas le revenu à part de la livraison.'
                            : 'Keep stock, preparation, and fulfillment visible in the same story so the team does not manage revenue separately from delivery.',
                        'image_key' => 'warehouse-worker',
                    ],
                    [
                        'title' => $isFrench ? 'Le revenu devient la suite naturelle de la commande' : 'Revenue feels like the natural continuation of the order',
                        'body' => $isFrench
                            ? 'Laissez facture et encaissement fermer la boucle pour que le paiement ne paraisse pas déconnecté de l’achat d’origine.'
                            : 'Let invoicing and collection close the loop so payment does not feel disconnected from the original purchase.',
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
        if ($locale === 'es') {
            return self::localizedEnglishSections('marketing-loyalty', $locale, self::marketingLoyaltySections('en'));
        }

        $isFrench = $locale === 'fr';

        return [
            self::featureTabsSection('marketing-loyalty-flow', $locale, [
                'background_color' => '#ffffff',
                'kicker' => $isFrench ? 'Un flux de rétention du signal client au retour en revenu' : 'One retention workflow from customer signal to returning revenue',
                'title' => $isFrench ? 'Transformez l’activité client en actions de rétention qui font vraiment revenir' : 'Turn customer activity into retention actions that actually bring people back',
                'body' => $isFrench
                    ? 'Marketing & Loyalty relie signaux, segmentation, campagnes, et parcours de fidélisation pour aider les équipes à agir au bon moment et à protéger le revenu futur.'
                    : 'Marketing & Loyalty connects signals, segmentation, campaigns, and loyalty journeys so teams can respond at the right moment and protect future revenue.',
                'primary_label' => $isFrench ? 'Voir la solution Marketing & fidélisation' : 'See the Marketing & Loyalty solution',
                'primary_href' => '/pages/solution-marketing-loyalty',
                'tabs' => [
                    [
                        'label' => $isFrench ? 'Écouter' : 'Listen',
                        'icon' => 'clipboard-check',
                        'title' => $isFrench ? 'Faites remonter les signaux client qui méritent une action' : 'Surface the customer signals that deserve action',
                        'body' => $isFrench
                            ? 'Appuyez-vous sur les avis, l’historique de visites, l’inactivité, et les changements de comportement pour savoir quand il faut relancer.'
                            : 'Use reviews, visit history, inactivity, and behavioral changes to decide when a customer should hear from you again.',
                        'cta_label' => $isFrench ? 'Voir Sales & CRM' : 'See Sales & CRM',
                        'cta_href' => '/pages/sales-crm',
                        'image_key' => 'desk-phone-laptop',
                    ],
                    [
                        'label' => $isFrench ? 'Segmenter' : 'Segment',
                        'icon' => 'clipboard-list',
                        'title' => $isFrench ? 'Construisez les segments à partir du vrai comportement, pas d’hypothèses' : 'Build segments from real behavior instead of guesswork',
                        'body' => $isFrench
                            ? 'Regroupez les clients selon leur valeur, leur rythme, leur historique, ou leur activité récente pour que le ciblage soit précis avant même le lancement d’une campagne.'
                            : 'Group customers by value, rhythm, visit history, or recent activity so targeting feels specific before a campaign is ever launched.',
                        'cta_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                        'cta_href' => '/pages/command-center',
                        'image_key' => 'team-laptop-window',
                    ],
                    [
                        'label' => $isFrench ? 'Activer' : 'Activate',
                        'icon' => 'file-text',
                        'title' => $isFrench ? 'Lancez des campagnes qui arrivent au bon moment et avec le bon message' : 'Launch follow-up campaigns that feel timely and relevant',
                        'body' => $isFrench
                            ? 'Reliez la bonne audience, le bon message, et la bonne offre pour que la campagne ressemble à une relance utile plutôt qu’à un bruit générique.'
                            : 'Connect the right audience, message, and offer so campaigns feel like useful follow-up instead of generic noise.',
                        'cta_label' => $isFrench ? 'Voir la solution' : 'See the solution',
                        'cta_href' => '/pages/solution-marketing-loyalty',
                        'image_key' => 'marketing-desk',
                    ],
                    [
                        'label' => $isFrench ? 'Fidéliser' : 'Retain',
                        'icon' => 'calendar-days',
                        'title' => $isFrench ? 'Transformez la fidélisation en prochaine visite, prochain achat, ou renouvellement' : 'Turn loyalty into the next visit, order, or renewal',
                        'body' => $isFrench
                            ? 'Gardez réactivation, avantages, et prochaine transaction dans la même histoire pour que la fidélisation se voie dans le revenu récurrent, pas seulement dans les taux d’ouverture.'
                            : 'Keep reactivation, rewards, and the next transaction in the same story so retention can be felt in repeat business, not just in open rates.',
                        'cta_label' => $isFrench ? 'Voir Commerce' : 'See Commerce',
                        'cta_href' => '/pages/commerce',
                        'image_key' => 'meeting-room-laptops',
                    ],
                ],
            ]),
            self::showcaseSection('marketing-loyalty-cta', $locale, [
                'kicker' => $isFrench ? 'Prêt à faire revenir les clients plus régulièrement' : 'Ready to bring customers back with more consistency',
                'title' => $isFrench
                    ? 'Transformez l’activité client en campagnes et fidélisation qui génèrent du revenu récurrent'
                    : 'Turn customer activity into campaigns and loyalty that drive repeat revenue',
                'body' => $isFrench
                    ? 'Remplacez des outils de mailing déconnectés et un ciblage au hasard par un système où signaux, audience, campagnes, et résultats de fidélisation restent reliés à la fiche client.'
                    : 'Replace disconnected mailing tools and guesswork with a system where signals, audience, campaigns, and loyalty outcomes stay tied to the customer record.',
                'primary_label' => $isFrench ? 'Voir la solution Marketing & fidélisation' : 'See the Marketing & Loyalty solution',
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
                    ? 'Signaux, campagnes, fidélisation, et retour en revenu dans un même flux connecté'
                    : 'Signals, campaigns, and returning revenue in one connected flow',
            ]),
            self::storyGridSection('marketing-loyalty-proof', $locale, [
                'kicker' => $isFrench ? 'Une rétention ancrée dans l’activité réelle' : 'Retention that stays grounded in real activity',
                'title' => $isFrench
                    ? 'Conçu pour les équipes qui veulent un marketing client utile, opportun, et mesurable'
                    : 'Built for teams that want customer marketing to feel timely, useful, and measurable',
                'body' => $isFrench
                    ? 'Gardez les campagnes reliées au vrai parcours client pour que la relance soit plus pertinente, que la fidélisation paraisse plus naturelle, et que le revenu récurrent soit plus facile à comprendre.'
                    : 'Keep campaigns tied to the real customer journey so follow-up feels more relevant, loyalty feels more earned, and repeat revenue becomes easier to understand.',
                'primary_label' => $isFrench ? 'Voir la solution Marketing & fidélisation' : 'See the Marketing & Loyalty solution',
                'primary_href' => '/pages/solution-marketing-loyalty',
                'cards' => [
                    [
                        'title' => $isFrench ? 'Les signaux deviennent plus faciles à exploiter' : 'Signals become easier to act on',
                        'body' => $isFrench
                            ? 'Donnez à l’équipe une façon plus claire de voir les avis, les périodes d’absence, les changements de comportement, et les retours qui méritent la prochaine action.'
                            : 'Give teams a clearer way to spot the reviews, lapses, behavior shifts, and return patterns that deserve the next message.',
                        'image_key' => 'desk-phone-laptop',
                    ],
                    [
                        'title' => $isFrench ? 'Les campagnes partent d’un vrai contexte' : 'Campaigns start from real context',
                        'body' => $isFrench
                            ? 'Lancez les campagnes à partir de l’historique, de la valeur, et de l’activité du client pour que le message paraisse relié à ce qui s’est réellement passé.'
                            : 'Launch campaigns from customer history, value, and activity so the message feels connected to what actually happened.',
                        'image_key' => 'marketing-desk',
                    ],
                    [
                        'title' => $isFrench ? 'La fidélisation se traduit en revenu récurrent visible' : 'Loyalty turns into visible repeat revenue',
                        'body' => $isFrench
                            ? 'Gardez le lien entre les actions de rétention et la prochaine visite, le prochain achat, ou la prochaine montée en gamme assez lisible pour savoir ce qui fait revenir.'
                            : 'Keep the link between retention actions and the next visit, order, or upgrade clear enough to measure what brings people back.',
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
        if ($locale === 'es') {
            return self::localizedEnglishSections('ai-automation', $locale, self::aiAutomationSections('en'));
        }

        $isFrench = $locale === 'fr';

        return [
            self::featureTabsSection('ai-automation-flow', $locale, [
                'background_color' => '#ffffff',
                'kicker' => $isFrench ? 'Un parcours IA du signal jusqu’à l’exécution assistée' : 'One AI workflow from signal to assisted execution',
                'title' => $isFrench ? 'Mettez l’IA là où les équipes ont déjà besoin d’aide, de vitesse, et de contexte' : 'Put AI to work where teams already need help, speed, and context',
                'body' => $isFrench
                    ? 'AI & Automation relie détection de signaux, suggestions, automatisation du travail, et revue humaine pour aider les équipes à aller plus vite sans perdre la visibilité ni le jugement.'
                    : 'AI & Automation connects pattern detection, suggestions, workflow automation, and human review so teams can move faster without losing visibility or judgment.',
                'primary_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                'primary_href' => '/pages/command-center',
                'tabs' => [
                    [
                        'label' => $isFrench ? 'Repérer' : 'Spot',
                        'icon' => 'clipboard-check',
                        'title' => $isFrench ? 'Faites remonter les signaux et répétitions qui méritent l’attention' : 'Surface the signals and repetitions that deserve attention',
                        'body' => $isFrench
                            ? 'Aidez les équipes à voir plus tôt les patterns, signaux faibles, et frictions récurrentes pour que la prochaine action devienne plus claire avant de perdre du temps.'
                            : 'Help teams notice patterns, weak signals, and recurring friction earlier so the next action becomes clearer before time is wasted.',
                        'cta_label' => $isFrench ? 'Voir Operations' : 'See Operations',
                        'cta_href' => '/pages/operations',
                        'image_key' => 'team-laptop-window',
                    ],
                    [
                        'label' => $isFrench ? 'Suggérer' : 'Suggest',
                        'icon' => 'file-text',
                        'title' => $isFrench ? 'Suggérez des brouillons et actions utiles sans perdre le contexte source' : 'Suggest useful drafts and actions without losing source context',
                        'body' => $isFrench
                            ? 'Gardez résumés, brouillons, et recommandations reliés au client, au job, à la demande, ou au dossier dont ils partent pour que l’aide reste crédible.'
                            : 'Keep summaries, drafts, and recommendations tied to the customer, job, request, or record they came from so assistance stays credible.',
                        'cta_label' => $isFrench ? 'Voir Sales & CRM' : 'See Sales & CRM',
                        'cta_href' => '/pages/sales-crm',
                        'image_key' => 'collab-laptop-desk',
                    ],
                    [
                        'label' => $isFrench ? 'Automatiser' : 'Automate',
                        'icon' => 'calendar-days',
                        'title' => $isFrench ? 'Retirez des étapes utiles du travail répétitif sans casser le flux' : 'Remove useful steps from repeat work without breaking the workflow',
                        'body' => $isFrench
                            ? 'Automatisez routage, relance, préparation, et transitions répétitives là où l’équipe gagne en vitesse, en cohérence, et en charge manuelle réduite.'
                            : 'Automate routing, follow-up, preparation, and repetitive transitions where teams gain speed, consistency, and less manual overhead.',
                        'cta_label' => $isFrench ? 'Voir la plateforme' : 'See the platform',
                        'cta_href' => '/pages/command-center',
                        'image_key' => 'workflow-plan',
                    ],
                    [
                        'label' => $isFrench ? 'Garder le contrôle' : 'Keep control',
                        'icon' => 'clipboard-list',
                        'title' => $isFrench ? 'Laissez la revue humaine là où le jugement compte encore' : 'Leave human review where judgment still matters',
                        'body' => $isFrench
                            ? 'Gardez validations, exceptions, et décisions sensibles visibles pour que l’automatisation soutienne l’équipe au lieu de prendre silencieusement la mauvaise place.'
                            : 'Keep approval, exceptions, and sensitive decisions visible so automation supports the team instead of quietly taking over the wrong step.',
                        'cta_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                        'cta_href' => '/pages/command-center',
                        'image_key' => 'meeting-room-laptops',
                    ],
                ],
            ]),
            self::showcaseSection('ai-automation-cta', $locale, [
                'kicker' => $isFrench ? 'Prêt à gagner du temps sans abandonner le contrôle' : 'Ready to save time without giving up control',
                'title' => $isFrench
                    ? 'Utilisez l’IA et l’automatisation pour faire avancer le travail avec moins de friction'
                    : 'Use AI and automation to move work forward with less friction',
                'body' => $isFrench
                    ? 'Remplacez des assistants déconnectés et des promesses d’automatisation floues par un système où suggestions, résumés, étapes du parcours, et revue humaine restent reliés au travail lui-même.'
                    : 'Replace disconnected assistants and vague automation promises with a system where suggestions, summaries, workflow steps, and human review stay connected to the work itself.',
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
                    ? 'Suggestions, automatisation, et revue humaine dans un même flux connecté'
                    : 'Suggestions, automation, and human review in one connected flow',
            ]),
            self::storyGridSection('ai-automation-proof', $locale, [
                'kicker' => $isFrench ? 'Une IA qui reste branchée sur le vrai travail' : 'AI that stays tied to real work',
                'title' => $isFrench
                    ? 'Conçu pour les équipes qui veulent une aide utile, crédible, et contrôlable'
                    : 'Built for teams that want assistance to feel useful, credible, and controllable',
                'body' => $isFrench
                    ? 'Gardez l’IA reliée au bon contexte, aux bons moments de revue, et aux bons parcours pour que le gain de temps soit réel sans transformer les décisions en approximation.'
                    : 'Keep AI tied to the right context, the right review moments, and the right workflows so time savings feel real without turning decisions into guesswork.',
                'primary_label' => $isFrench ? 'Voir Command Center' : 'See Command Center',
                'primary_href' => '/pages/command-center',
                'cards' => [
                    [
                        'title' => $isFrench ? 'Les patterns utiles deviennent plus faciles à repérer' : 'Useful patterns become easier to spot',
                        'body' => $isFrench
                            ? 'Aidez les équipes à voir les signaux répétés, les blocages, et les schémas faibles qui méritent une action avant qu’ils se perdent dans le bruit du quotidien.'
                            : 'Help teams see the repeated signals, blockers, and weak patterns worth acting on before they disappear into day-to-day noise.',
                        'image_key' => 'team-laptop-window',
                    ],
                    [
                        'title' => $isFrench ? 'Les suggestions restent ancrées dans le contexte' : 'Suggestions stay grounded in context',
                        'body' => $isFrench
                            ? 'Générez brouillons, résumés, et actions proposées à partir du dossier déjà ouvert devant l’équipe pour que le résultat paraisse pertinent plutôt que générique.'
                            : 'Generate drafts, summaries, and proposed actions from the record already in front of the team so the output feels relevant instead of generic.',
                        'image_key' => 'collab-laptop-desk',
                    ],
                    [
                        'title' => $isFrench ? 'La revue humaine reste visible là où elle compte' : 'Human review stays visible where it matters',
                        'body' => $isFrench
                            ? 'Laissez validations, exceptions, et étapes sensibles bien visibles pour que l’équipe sache exactement où l’automatisation aide et où le jugement doit encore mener.'
                            : 'Leave approvals, exceptions, and sensitive steps in clear view so the team knows exactly where automation helps and where judgment still leads.',
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
        if ($locale === 'es') {
            return self::localizedEnglishSections('command-center', $locale, self::commandCenterSections('en'));
        }

        $isFrench = $locale === 'fr';

        return [
            self::featureTabsSection('command-center-flow', $locale, [
                'background_color' => '#ffffff',
                'kicker' => $isFrench ? 'Un pilotage du signal à la décision' : 'One leadership workflow from signal to decision',
                'title' => $isFrench ? 'Transformez la visibilité transversale en priorités plus claires et en action plus rapide' : 'Turn cross-functional visibility into clearer priorities and faster action',
                'body' => $isFrench
                    ? 'Command Center relie signaux, comparaisons, priorisation, et suivi dirigeant pour aider les équipes à agir à partir d’une lecture commune plutôt que de vues fragmentées.'
                    : 'Command Center connects signals, comparisons, priority setting, and executive follow-through so teams can act from a shared reading instead of fragmented views.',
                'primary_label' => $isFrench ? 'Voir la solution Pilotage multi-entreprise' : 'See the Multi-Entity Oversight solution',
                'primary_href' => '/pages/solution-multi-entity-oversight',
                'tabs' => [
                    [
                        'label' => $isFrench ? 'Remonter' : 'Raise',
                        'icon' => 'clipboard-check',
                        'title' => $isFrench ? 'Faites remonter plus vite les signaux qui comptent' : 'Bring the signals that matter to the surface faster',
                        'body' => $isFrench
                            ? 'Aidez les responsables à voir les indicateurs, variations, et alertes qui méritent l’attention avant qu’ils se perdent dans le bruit opérationnel.'
                            : 'Help leadership see the indicators, shifts, and warnings worth attention before they disappear into operational noise.',
                        'cta_label' => $isFrench ? 'Voir Operations' : 'See Operations',
                        'cta_href' => '/pages/operations',
                        'image_key' => 'team-laptop-window',
                    ],
                    [
                        'label' => $isFrench ? 'Comparer' : 'Compare',
                        'icon' => 'clipboard-list',
                        'title' => $isFrench ? 'Comparez équipes, entités, et performance sans perdre la vue d’ensemble' : 'Compare teams, entities, and performance without losing the shared picture',
                        'body' => $isFrench
                            ? 'Lisez écarts, points de tension, et performances inégales au même endroit pour que la comparaison mène à la compréhension plutôt qu’à la fragmentation.'
                            : 'Read gaps, pressure points, and uneven performance in one place so comparison leads to understanding instead of fragmentation.',
                        'cta_label' => $isFrench ? 'Voir la solution' : 'See the solution',
                        'cta_href' => '/pages/solution-multi-entity-oversight',
                        'image_key' => 'meeting-room-laptops',
                    ],
                    [
                        'label' => $isFrench ? 'Prioriser' : 'Prioritize',
                        'icon' => 'calendar-days',
                        'title' => $isFrench ? 'Transformez la lecture en priorités que les équipes peuvent vraiment suivre' : 'Turn the reading into priorities people can actually follow',
                        'body' => $isFrench
                            ? 'Traduisez ce que voient les responsables en direction plus claire pour les bonnes équipes afin que le cap soit partagé au lieu de rester implicite.'
                            : 'Translate what leadership sees into clearer direction for the right teams so focus becomes shared instead of implied.',
                        'cta_label' => $isFrench ? 'Voir Sales & CRM' : 'See Sales & CRM',
                        'cta_href' => '/pages/sales-crm',
                        'image_key' => 'workflow-plan',
                    ],
                    [
                        'label' => $isFrench ? 'Arbitrer' : 'Arbitrate',
                        'icon' => 'file-text',
                        'title' => $isFrench ? 'Fermez la boucle avec une décision qui fait avancer l’exécution' : 'Close the loop with a decision that moves execution forward',
                        'body' => $isFrench
                            ? 'Gardez arbitrages, décisions, et prochaines actions visibles pour que le pilotage ne s’arrête pas à l’insight mais arrive là où l’équipe doit agir.'
                            : 'Keep trade-offs, decisions, and next moves visible so executive direction does not stop at insight but lands where action needs to happen.',
                        'cta_label' => $isFrench ? 'Voir Commerce' : 'See Commerce',
                        'cta_href' => '/pages/commerce',
                        'image_key' => 'warehouse-worker',
                    ],
                ],
            ]),
            self::showcaseSection('command-center-cta', $locale, [
                'kicker' => $isFrench ? 'Prêt à piloter avec plus de clarté' : 'Ready to lead with more clarity',
                'title' => $isFrench
                    ? 'Utilisez une même couche de pilotage pour aligner signaux, priorités, et prochaines actions dans toute l’activité'
                    : 'Use one command layer to align signals, priorities, and next actions across the business',
                'body' => $isFrench
                    ? 'Remplacez des dashboards déconnectés et des mises à jour dispersées par un espace de commandement partagé où revenu, opérations, et activité client peuvent être lus, priorisés, et transformés en action.'
                    : 'Replace disconnected dashboards and scattered updates with a shared command space where revenue, operations, and customer activity can be read, prioritized, and turned into action.',
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
                    ? 'Signaux, priorités, et décisions dans une même couche de pilotage'
                    : 'Signals, priorities, and decisions in one shared control layer',
            ]),
            self::storyGridSection('command-center-proof', $locale, [
                'kicker' => $isFrench ? 'Une visibilité dirigeant qui débouche sur l’action' : 'Executive visibility that leads to action',
                'title' => $isFrench
                    ? 'Conçu pour les équipes qui doivent voir plus vite, comparer plus proprement, et diriger l’action plus clairement'
                    : 'Built for teams that need to see faster, compare better, and direct action more clearly',
                'body' => $isFrench
                    ? 'Gardez les signaux transversaux assez lisibles pour que les responsables puissent agir, comparer entités ou équipes avec plus de confiance, et renvoyer des priorités plus nettes vers l’exécution.'
                    : 'Keep cross-functional signals readable enough for leadership to act on them, compare entities or teams with more confidence, and send clearer priorities back into execution.',
                'primary_label' => $isFrench ? 'Voir la solution Pilotage multi-entreprise' : 'See the Multi-Entity Oversight solution',
                'primary_href' => '/pages/solution-multi-entity-oversight',
                'cards' => [
                    [
                        'title' => $isFrench ? 'Les bons signaux remontent plus vite' : 'The right signals rise faster',
                        'body' => $isFrench
                            ? 'Faites remonter les indicateurs et alertes qui méritent l’attention pour que la direction se concentre plus tôt sur ce qui change réellement la performance.'
                            : 'Surface the indicators and warnings worth attention so leadership can focus sooner on what changes performance.',
                        'image_key' => 'team-laptop-window',
                    ],
                    [
                        'title' => $isFrench ? 'Les comparaisons restent utiles au lieu de devenir bruyantes' : 'Comparisons stay useful instead of noisy',
                        'body' => $isFrench
                            ? 'Gardez les écarts entre équipes, entités, et périodes dans une vue lisible pour que la comparaison aide la décision au lieu d’ajouter de la confusion.'
                            : 'Keep differences between teams, entities, and periods inside one readable view so comparison helps decision-making instead of multiplying confusion.',
                        'image_key' => 'meeting-room-laptops',
                    ],
                    [
                        'title' => $isFrench ? 'Les décisions deviennent plus faciles à traduire en action' : 'Decisions become easier to translate into action',
                        'body' => $isFrench
                            ? 'Laissez la prochaine action redescendre vers les bonnes équipes avec assez de clarté pour que les priorités puissent réellement être exécutées.'
                            : 'Let the next move flow back toward the right teams with enough clarity that priorities can actually be executed.',
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
    private static function withOverrides(array $sections, array $overrides): array
    {
        foreach ($overrides as $path => $value) {
            $segments = explode('.', (string) $path);
            $target =& $sections;

            foreach ($segments as $index => $segment) {
                if ($index === count($segments) - 1) {
                    $target[$segment] = $value;

                    continue 2;
                }

                if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                    $target[$segment] = [];
                }

                $target =& $target[$segment];
            }
        }

        return $sections;
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
