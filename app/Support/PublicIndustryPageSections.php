<?php

namespace App\Support;

class PublicIndustryPageSections
{
    /**
     * @return array<int, string>
     */
    public static function managedSlugs(): array
    {
        return array_keys(self::TESTIMONIAL_QUOTES);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(string $slug, ?string $locale = 'fr'): array
    {
        $locale = PublicPageStockImages::normalizeLocale($locale);

        return [
            self::showcaseSection($slug, $locale),
            self::editorialCtaSection($slug, $locale),
            self::testimonialSection($slug, $locale),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function showcaseSection(string $slug, string $locale): array
    {
        $getNoticedImage = PublicPageStockImages::visual('marketing-desk', $locale);
        $winJobsImage = PublicPageStockImages::visual('desk-phone-laptop', $locale);
        $workSmarterImage = PublicPageStockImages::visual('field-checklist', $locale);
        $boostProfitsImage = PublicPageStockImages::visual('payments-terminal', $locale);

        if ($locale === 'fr') {
            return self::baseSection('industry-showcase', 'feature_tabs', [
                'background_color' => '#ffffff',
                'alignment' => 'center',
                'image_position' => 'left',
                'kicker' => 'Un systeme qui couvre tout le cycle client',
                'title' => 'La solution tout-en-un pour les pros du service a domicile',
                'body' => '<p>De la visibilite locale jusqu au paiement final, chaque etape reste dans un meme flux plutot que dans quatre outils separes.</p>',
                'feature_tabs_style' => 'editorial',
                'feature_tabs_font_size' => 28,
                'feature_tabs' => self::showcaseTabs($slug, 'fr', $getNoticedImage, $winJobsImage, $workSmarterImage, $boostProfitsImage),
            ]);
        }

        return self::baseSection('industry-showcase', 'feature_tabs', [
            'background_color' => '#ffffff',
            'alignment' => 'center',
            'image_position' => 'left',
            'kicker' => 'One system across the full customer journey',
            'title' => 'The all-in-one solution for home service pros',
            'body' => '<p>From local visibility to final payment, each step stays inside one operating flow instead of being split across disconnected tools.</p>',
            'feature_tabs_style' => 'editorial',
            'feature_tabs_font_size' => 28,
            'feature_tabs' => self::showcaseTabs($slug, 'en', $getNoticedImage, $winJobsImage, $workSmarterImage, $boostProfitsImage),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function editorialCtaSection(string $slug, string $locale): array
    {
        $visual = PublicPageStockImages::visual('collab-laptop-desk', $locale);
        $asideVisual = PublicPageStockImages::visual(self::industryCtaAsideKey($slug), $locale);

        if ($locale === 'fr') {
            return self::baseSection('industry-editorial-cta', 'showcase_cta', [
                'tone' => 'contrast',
                'background_color' => '#202322',
                'background_preset' => 'welcome-hero',
                'alignment' => 'left',
                'image_position' => 'right',
                'title' => 'Essayez-le gratuitement. Voyez si ca colle a votre operation.',
                'body' => '<p>Presentez votre plateforme, votre visite produit ou votre experience mobile avec un bloc plus editorial et plus vendeur.</p>',
                'image_url' => $visual['image_url'],
                'image_alt' => $visual['image_alt'],
                'aside_image_url' => $asideVisual['image_url'],
                'aside_image_alt' => $asideVisual['image_alt'],
                'primary_label' => 'Commencer gratuitement',
                'primary_href' => '/pages/contact-us',
                'aside_link_label' => 'Voir la visite produit',
                'aside_link_href' => '/demo',
                'showcase_divider_style' => 'round',
            ]);
        }

        return self::baseSection('industry-editorial-cta', 'showcase_cta', [
            'tone' => 'contrast',
            'background_color' => '#202322',
            'background_preset' => 'welcome-hero',
            'alignment' => 'left',
            'image_position' => 'right',
            'title' => 'Try it for free. See if it fits your operation.',
            'body' => '<p>Present your platform, your product tour, or your mobile experience with a more editorial and more persuasive block.</p>',
            'image_url' => $visual['image_url'],
            'image_alt' => $visual['image_alt'],
            'aside_image_url' => $asideVisual['image_url'],
            'aside_image_alt' => $asideVisual['image_alt'],
            'primary_label' => 'Start for free',
            'primary_href' => '/pages/contact-us',
            'aside_link_label' => 'See the product tour',
            'aside_link_href' => '/demo',
            'showcase_divider_style' => 'round',
        ]);
    }

    /**
     * @param  array{image_alt:string,image_url:string}  $getNoticedImage
     * @param  array{image_alt:string,image_url:string}  $winJobsImage
     * @param  array{image_alt:string,image_url:string}  $workSmarterImage
     * @param  array{image_alt:string,image_url:string}  $boostProfitsImage
     * @return array<int, array<string, mixed>>
     */
    private static function showcaseTabs(
        string $slug,
        string $locale,
        array $getNoticedImage,
        array $winJobsImage,
        array $workSmarterImage,
        array $boostProfitsImage
    ): array {
        $isFrench = $locale === 'fr';
        $childVisuals = self::showcaseChildVisuals($slug, $locale);

        return [
            [
                'id' => 'feature-tab-get-noticed',
                'label' => $isFrench ? 'Se faire remarquer' : 'Get Noticed',
                'icon' => 'clipboard-check',
                'title' => $isFrench
                    ? 'Gardez votre marque visible la ou les clients vous cherchent'
                    : 'Keep your brand visible where customers are already searching',
                'body' => $isFrench
                    ? '<p>Pages publiques, formulaires de demande, campagnes et relances restent coherents du premier clic jusqu au suivi.</p>'
                    : '<p>Public pages, intake forms, campaigns, and follow-up stay aligned from the first click through the next step.</p>',
                'metric' => $isFrench ? '44 % de croissance moyenne la premiere annee' : '44% average growth in the first year',
                'story' => $isFrench
                    ? '<p>Nous avons clarifie notre presence en ligne, automatise les suivis et augmente le volume de demandes qualifiees sans ajouter de friction.</p>'
                    : '<p>We clarified our online presence, automated follow-up, and increased qualified demand without adding friction.</p>',
                'person' => $isFrench ? 'Equipe croissance' : 'Growth team',
                'role' => $isFrench ? 'Operations locales' : 'Local operations',
                'cta_label' => 'See Marketing & Loyalty',
                'cta_href' => '/pages/marketing-loyalty',
                'image_url' => $getNoticedImage['image_url'],
                'image_alt' => $getNoticedImage['image_alt'],
                'avatar_url' => '/images/presets/avatar-1.svg',
                'avatar_alt' => $isFrench ? 'Portrait equipe croissance' : 'Growth team portrait',
                'children' => [
                    [
                        'id' => 'feature-tab-get-noticed-reviews',
                        'label' => $isFrench ? 'Demandes d avis' : 'Review requests',
                        'title' => $isFrench ? 'Obtenez plus d avis sans relances manuelles' : 'Collect more reviews without manual chasing',
                        'body' => $isFrench
                            ? '<p>Declenchez des demandes d avis au bon moment et facilitez la collecte pendant que l experience client est encore fraiche.</p>'
                            : '<p>Trigger review requests at the right time and keep customer momentum while the experience is still fresh.</p>',
                        'cta_label' => $isFrench ? 'Voir les avis' : 'See reviews',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['get_noticed'][0]['image_url'],
                        'image_alt' => $childVisuals['get_noticed'][0]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-get-noticed-responses',
                        'label' => $isFrench ? 'Reponses rapides' : 'Fast responses',
                        'title' => $isFrench ? 'Repondez plus vite aux nouvelles demandes' : 'Reply faster to new demand',
                        'body' => $isFrench
                            ? '<p>Automatisez les messages d accueil et gardez un delai de reponse court pour montrer que votre entreprise est reactive.</p>'
                            : '<p>Automate welcome messages and keep your response time tight so your company feels responsive from the start.</p>',
                        'cta_label' => $isFrench ? 'Voir la messagerie' : 'See messaging',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['get_noticed'][1]['image_url'],
                        'image_alt' => $childVisuals['get_noticed'][1]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-get-noticed-campaigns',
                        'label' => $isFrench ? 'Marketing automatise' : 'Automated marketing',
                        'title' => $isFrench ? 'Restez visible sans campagnes compliquees' : 'Stay visible without running complicated campaigns',
                        'body' => $isFrench
                            ? '<p>Programmez des suivis clients, des rappels saisonniers et des campagnes simples pour revenir en tete au bon moment.</p>'
                            : '<p>Schedule seasonal follow-up, simple campaigns, and customer reminders so your brand shows up at the right moment.</p>',
                        'cta_label' => $isFrench ? 'Voir le marketing' : 'See marketing',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['get_noticed'][2]['image_url'],
                        'image_alt' => $childVisuals['get_noticed'][2]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-get-noticed-sharing',
                        'label' => $isFrench ? 'Liens de partage' : 'Shareable links',
                        'title' => $isFrench ? 'Diffusez plus facilement votre offre' : 'Spread your offer more easily',
                        'body' => $isFrench
                            ? '<p>Partagez vos formulaires, pages et devis avec des liens propres pour accelerer le bouche-a-oreille et les recommandations.</p>'
                            : '<p>Share forms, pages, and quotes with cleaner links so referrals and word of mouth move faster.</p>',
                        'cta_label' => $isFrench ? 'Voir les liens' : 'See links',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['get_noticed'][3]['image_url'],
                        'image_alt' => $childVisuals['get_noticed'][3]['image_alt'],
                    ],
                ],
            ],
            [
                'id' => 'feature-tab-win-jobs',
                'label' => $isFrench ? 'Gagner des jobs' : 'Win Jobs',
                'icon' => 'file-text',
                'title' => $isFrench ? 'Transformez plus vite une demande entrante en devis signe' : 'Turn inbound demand into approved quotes faster',
                'body' => $isFrench
                    ? '<p>Qualification, devis, relances et historique client sont relies pour faire avancer chaque opportunite sans perte de contexte.</p>'
                    : '<p>Qualification, quoting, follow-up, and customer history stay connected so each opportunity keeps moving.</p>',
                'metric' => $isFrench ? 'Des devis plus rapides, mieux suivis' : 'Cleaner quotes with better follow-through',
                'story' => $isFrench
                    ? '<p>Les modeles, les options et les relances automatiques nous ont aide a envoyer des propositions propres plus tot dans la journee.</p>'
                    : '<p>Templates, options, and automated follow-up helped us send polished proposals earlier in the day.</p>',
                'person' => $isFrench ? 'Equipe commerciale' : 'Commercial team',
                'role' => $isFrench ? 'Vente et qualification' : 'Sales and qualification',
                'cta_label' => 'See Sales & CRM',
                'cta_href' => '/pages/sales-crm',
                'image_url' => $winJobsImage['image_url'],
                'image_alt' => $winJobsImage['image_alt'],
                'avatar_url' => '/images/presets/avatar-2.svg',
                'avatar_alt' => $isFrench ? 'Portrait equipe commerciale' : 'Commercial team portrait',
                'children' => [
                    [
                        'id' => 'feature-tab-win-jobs-booking',
                        'label' => $isFrench ? 'Reservations en ligne et formulaires' : 'Online booking and forms',
                        'title' => $isFrench ? 'Capturez plus de demandes sans friction' : 'Capture more demand without friction',
                        'body' => $isFrench
                            ? '<p>Ajoutez des formulaires de demande simples, acceptez les reservations en ligne et faites entrer les leads directement dans votre pipeline.</p>'
                            : '<p>Add simple intake forms, accept online bookings, and push new demand straight into your pipeline.</p>',
                        'cta_label' => $isFrench ? 'Voir la capture de leads' : 'See lead capture',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['win_jobs'][0]['image_url'],
                        'image_alt' => $childVisuals['win_jobs'][0]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-win-jobs-templates',
                        'label' => $isFrench ? 'Modeles de devis' : 'Quote templates',
                        'title' => $isFrench ? 'Envoyez des devis coherents en moins de temps' : 'Send consistent quotes in less time',
                        'body' => $isFrench
                            ? '<p>Prechargez vos services, prix et options frequentes pour sortir des devis clairs et uniformes depuis le bureau ou le terrain.</p>'
                            : '<p>Preload services, prices, and options so your team can send clearer proposals from the office or the field.</p>',
                        'cta_label' => $isFrench ? 'Voir les modeles de devis' : 'See quote templates',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['win_jobs'][1]['image_url'],
                        'image_alt' => $childVisuals['win_jobs'][1]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-win-jobs-line-items',
                        'label' => $isFrench ? 'Lignes optionnelles' : 'Optional line items',
                        'title' => $isFrench ? 'Augmentez la valeur moyenne de chaque devis' : 'Increase the average value of each quote',
                        'body' => $isFrench
                            ? '<p>Ajoutez facilement des options, des extras et des services complementaires pour proposer plus de valeur sans refaire vos devis a la main.</p>'
                            : '<p>Add upgrades, extras, and complementary services without rebuilding each proposal from scratch.</p>',
                        'cta_label' => $isFrench ? 'Voir les options de devis' : 'See quote options',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['win_jobs'][2]['image_url'],
                        'image_alt' => $childVisuals['win_jobs'][2]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-win-jobs-follow-ups',
                        'label' => $isFrench ? 'Suivis automatiques' : 'Automated follow-up',
                        'title' => $isFrench ? 'Relancez au bon moment sans suivi manuel' : 'Follow up at the right moment without manual tracking',
                        'body' => $isFrench
                            ? '<p>Programmez des rappels et des suivis automatiques pour faire avancer vos opportunites sans laisser de leads en attente.</p>'
                            : '<p>Schedule reminders and follow-up automatically so opportunities do not stall in the pipeline.</p>',
                        'cta_label' => $isFrench ? 'Voir les suivis' : 'See follow-ups',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['win_jobs'][3]['image_url'],
                        'image_alt' => $childVisuals['win_jobs'][3]['image_alt'],
                    ],
                ],
            ],
            [
                'id' => 'feature-tab-work-smarter',
                'label' => $isFrench ? 'Travailler mieux' : 'Work Smarter',
                'icon' => 'calendar-days',
                'title' => $isFrench ? 'Passez du bureau au terrain avec le meme niveau de clarte' : 'Move from office to field with the same level of clarity',
                'body' => $isFrench
                    ? '<p>Planning, dispatch, fiches job, checklists et historique client restent visibles pour que les equipes interviennent avec le bon contexte.</p>'
                    : '<p>Scheduling, dispatch, job sheets, checklists, and customer history stay visible so crews always arrive with context.</p>',
                'metric' => $isFrench ? 'Moins d aller-retour entre le bureau et le terrain' : 'Less back and forth between office and field',
                'story' => $isFrench
                    ? '<p>Le planning et les details d intervention sont enfin partages dans le meme outil, ce qui reduit les appels de clarification pendant la journee.</p>'
                    : '<p>Scheduling and job details finally live in one place, which cuts the number of clarification calls during the day.</p>',
                'person' => $isFrench ? 'Equipe terrain' : 'Field team',
                'role' => $isFrench ? 'Dispatch et execution' : 'Dispatch and execution',
                'cta_label' => 'See Operations',
                'cta_href' => '/pages/operations',
                'image_url' => $workSmarterImage['image_url'],
                'image_alt' => $workSmarterImage['image_alt'],
                'avatar_url' => '/images/presets/avatar-3.svg',
                'avatar_alt' => $isFrench ? 'Portrait equipe terrain' : 'Field team portrait',
                'children' => [
                    [
                        'id' => 'feature-tab-work-smarter-schedule',
                        'label' => $isFrench ? 'Calendrier glisser-deposer' : 'Drag-and-drop calendar',
                        'title' => $isFrench ? 'Deplacez vos horaires sans rebatir la journee' : 'Shift schedules without rebuilding the whole day',
                        'body' => $isFrench
                            ? '<p>Replanifiez en quelques secondes, assignez les bonnes equipes et gardez tout le monde aligne avec des mises a jour instantanees.</p>'
                            : '<p>Reschedule in seconds, assign the right crews, and keep everyone aligned with real-time updates.</p>',
                        'cta_label' => $isFrench ? 'Voir la planification' : 'See scheduling',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['work_smarter'][0]['image_url'],
                        'image_alt' => $childVisuals['work_smarter'][0]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-work-smarter-dispatch',
                        'label' => $isFrench ? 'Dispatch d equipe' : 'Team dispatch',
                        'title' => $isFrench ? 'Affectez la bonne equipe au bon job' : 'Send the right crew to the right job',
                        'body' => $isFrench
                            ? '<p>Visualisez la disponibilite, l emplacement et la charge de travail pour envoyer les bonnes personnes sans perdre de temps.</p>'
                            : '<p>See availability, location, and workload so you can dispatch the right people without wasting time.</p>',
                        'cta_label' => $isFrench ? 'Voir le dispatch' : 'See dispatch',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['work_smarter'][1]['image_url'],
                        'image_alt' => $childVisuals['work_smarter'][1]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-work-smarter-checklists',
                        'label' => $isFrench ? 'Checklists terrain' : 'Field checklists',
                        'title' => $isFrench ? 'Standardisez l execution de chaque intervention' : 'Standardize execution across every visit',
                        'body' => $isFrench
                            ? '<p>Ajoutez des etapes, des formulaires et des controles de qualite pour que le travail soit bien fait du premier coup.</p>'
                            : '<p>Add steps, forms, and quality controls so the work is done right the first time.</p>',
                        'cta_label' => $isFrench ? 'Voir les checklists' : 'See checklists',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['work_smarter'][2]['image_url'],
                        'image_alt' => $childVisuals['work_smarter'][2]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-work-smarter-history',
                        'label' => $isFrench ? 'Historique client' : 'Customer history',
                        'title' => $isFrench ? 'Retrouvez le contexte complet avant chaque visite' : 'See the full context before each visit',
                        'body' => $isFrench
                            ? '<p>Gardez les notes, photos, demandes et anciens jobs au meme endroit pour que vos equipes arrivent preparees chez le client.</p>'
                            : '<p>Keep notes, photos, requests, and past jobs together so your team arrives prepared.</p>',
                        'cta_label' => $isFrench ? 'Voir les fiches client' : 'See customer records',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['work_smarter'][3]['image_url'],
                        'image_alt' => $childVisuals['work_smarter'][3]['image_alt'],
                    ],
                ],
            ],
            [
                'id' => 'feature-tab-boost-profits',
                'label' => $isFrench ? 'Booster les profits' : 'Boost Profits',
                'icon' => 'circle-dollar-sign',
                'title' => $isFrench ? 'Facturez plus vite et raccourcissez le cycle d encaissement' : 'Invoice faster and shorten the cash collection cycle',
                'body' => $isFrench
                    ? '<p>Factures, paiements sur place, rappels et suivi de marge restent connectes au travail realise pour proteger vos revenus.</p>'
                    : '<p>Invoices, field payments, reminders, and margin visibility stay tied to completed work so revenue is easier to protect.</p>',
                'metric' => $isFrench ? 'Une meilleure visibilite sur la marge et la tresorerie' : 'Clearer visibility on margin and cash flow',
                'story' => $isFrench
                    ? '<p>Nos equipes cloturent plus vite les jobs et les rappels partent automatiquement, donc le cash rentre plus tot.</p>'
                    : '<p>Teams close work faster and reminders go out automatically, so cash lands earlier.</p>',
                'person' => $isFrench ? 'Equipe finance' : 'Finance team',
                'role' => $isFrench ? 'Facturation et paiements' : 'Billing and payments',
                'cta_label' => 'See Commerce',
                'cta_href' => '/pages/commerce',
                'image_url' => $boostProfitsImage['image_url'],
                'image_alt' => $boostProfitsImage['image_alt'],
                'avatar_url' => '/images/presets/avatar-4.svg',
                'avatar_alt' => $isFrench ? 'Portrait equipe finance' : 'Finance team portrait',
                'children' => [
                    [
                        'id' => 'feature-tab-boost-profits-invoicing',
                        'label' => $isFrench ? 'Factures rapides' : 'Fast invoicing',
                        'title' => $isFrench ? 'Transformez un job termine en facture en quelques clics' : 'Turn completed work into an invoice in a few clicks',
                        'body' => $isFrench
                            ? '<p>Generez vos factures sans refaire les informations du job, puis envoyez-les immediatement au client.</p>'
                            : '<p>Generate invoices without re-entering job details, then send them to the customer immediately.</p>',
                        'cta_label' => $isFrench ? 'Voir la facturation' : 'See invoicing',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['boost_profits'][0]['image_url'],
                        'image_alt' => $childVisuals['boost_profits'][0]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-boost-profits-payments',
                        'label' => $isFrench ? 'Paiements sur place' : 'Field payments',
                        'title' => $isFrench ? 'Encaissez pendant que l equipe est encore chez le client' : 'Collect payment while the crew is still onsite',
                        'body' => $isFrench
                            ? '<p>Acceptez plusieurs moyens de paiement sur mobile pour reduire les delais et limiter les comptes a recevoir.</p>'
                            : '<p>Accept multiple payment methods on mobile to reduce delays and keep receivables under control.</p>',
                        'cta_label' => $isFrench ? 'Voir les paiements mobiles' : 'See mobile payments',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['boost_profits'][1]['image_url'],
                        'image_alt' => $childVisuals['boost_profits'][1]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-boost-profits-reminders',
                        'label' => $isFrench ? 'Rappels automatiques' : 'Automatic reminders',
                        'title' => $isFrench ? 'Relancez sans courir apres chaque facture' : 'Follow up without chasing every invoice manually',
                        'body' => $isFrench
                            ? '<p>Automatisez vos rappels pour garder le cap sur les paiements en retard sans monopoliser votre equipe admin.</p>'
                            : '<p>Automate reminders so your admin team keeps momentum on overdue balances without getting buried.</p>',
                        'cta_label' => $isFrench ? 'Voir les rappels' : 'See reminders',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['boost_profits'][2]['image_url'],
                        'image_alt' => $childVisuals['boost_profits'][2]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-boost-profits-reporting',
                        'label' => $isFrench ? 'Rapports de marge' : 'Margin reporting',
                        'title' => $isFrench ? 'Voyez ou vous gagnez et ou vous perdez' : 'See where you win and where you lose',
                        'body' => $isFrench
                            ? '<p>Suivez vos revenus, vos services les plus profitables et vos tendances de performance pour prendre de meilleures decisions.</p>'
                            : '<p>Track revenue, best-performing services, and margin trends so you can make stronger decisions faster.</p>',
                        'cta_label' => $isFrench ? 'Voir les rapports' : 'See reports',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['boost_profits'][3]['image_url'],
                        'image_alt' => $childVisuals['boost_profits'][3]['image_alt'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     get_noticed: array<int, array{image_alt:string,image_url:string}>,
     *     win_jobs: array<int, array{image_alt:string,image_url:string}>,
     *     work_smarter: array<int, array{image_alt:string,image_url:string}>,
     *     boost_profits: array<int, array{image_alt:string,image_url:string}>
     * }
     */
    private static function showcaseChildVisuals(string $slug, string $locale): array
    {
        [$leadVisualKey, $salesVisualKey, $operationsVisualKey, $billingVisualKey] = self::industryVisualPool($slug);

        return [
            'get_noticed' => [
                PublicPageStockImages::visual($leadVisualKey, $locale),
                PublicPageStockImages::visual('office-collaboration', $locale),
                PublicPageStockImages::visual('marketing-desk', $locale),
                PublicPageStockImages::visual('salon-front-desk', $locale),
            ],
            'win_jobs' => [
                PublicPageStockImages::visual($salesVisualKey, $locale),
                PublicPageStockImages::visual('workflow-plan', $locale),
                PublicPageStockImages::visual('service-install', $locale),
                PublicPageStockImages::visual('desk-phone-laptop', $locale),
            ],
            'work_smarter' => [
                PublicPageStockImages::visual($operationsVisualKey, $locale),
                PublicPageStockImages::visual('field-checklist', $locale),
                PublicPageStockImages::visual('service-tablet', $locale),
                PublicPageStockImages::visual('cleaning-team-office', $locale),
            ],
            'boost_profits' => [
                PublicPageStockImages::visual($billingVisualKey, $locale),
                PublicPageStockImages::visual('payments-terminal', $locale),
                PublicPageStockImages::visual('store-payment', $locale),
                PublicPageStockImages::visual('restaurant-service', $locale),
            ],
        ];
    }

    private static function industryCtaAsideKey(string $slug): string
    {
        return match ($slug) {
            'industry-plumbing' => 'plumbing-pipe-repair',
            'industry-hvac' => 'hvac-maintenance',
            'industry-electrical' => 'electrician-panel',
            'industry-cleaning' => 'cleaning-team-office',
            'industry-salon-beauty' => 'beauty-treatment',
            'industry-restaurant' => 'restaurant-service',
            default => 'service-install',
        };
    }

    /**
     * @return array{0:string,1:string,2:string,3:string}
     */
    private static function industryVisualPool(string $slug): array
    {
        return match ($slug) {
            'industry-plumbing' => ['plumbing-pipe-repair', 'plumbing-pipe-repair', 'service-install', 'payments-terminal'],
            'industry-hvac' => ['office-collaboration', 'hvac-maintenance', 'hvac-maintenance', 'payments-terminal'],
            'industry-electrical' => ['office-collaboration', 'electrician-panel', 'electrician-panel', 'payments-terminal'],
            'industry-cleaning' => ['office-collaboration', 'cleaning-team-office', 'cleaning-team-office', 'store-payment'],
            'industry-salon-beauty' => ['salon-front-desk', 'salon-front-desk', 'beauty-treatment', 'payments-terminal'],
            'industry-restaurant' => ['restaurant-service', 'restaurant-service', 'restaurant-service', 'restaurant-service'],
            default => ['office-collaboration', 'service-install', 'field-checklist', 'payments-terminal'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function testimonialSection(string $slug, string $locale): array
    {
        $visual = PublicPageStockImages::slot($slug, 'header', $locale);
        $quote = self::TESTIMONIAL_QUOTES[$slug][$locale] ?? self::TESTIMONIAL_QUOTES['industry-plumbing'][$locale];

        return self::baseSection('industry-testimonial', 'testimonial', [
            'background_color' => '#e5ecef',
            'alignment' => 'left',
            'image_position' => 'right',
            'body' => $quote,
            'image_url' => $visual['image_url'],
            'image_alt' => $visual['image_alt'],
            'testimonial_author' => 'Jules BILITIK',
            'testimonial_role' => $locale === 'fr' ? 'Cofondateur' : 'Co-founder',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function baseSection(string $id, string $layout, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'enabled' => true,
            'source_id' => null,
            'use_source' => false,
            'background_color' => '',
            'background_preset' => '',
            'layout' => $layout,
            'image_position' => 'left',
            'alignment' => 'left',
            'density' => 'normal',
            'tone' => 'default',
            'visibility' => [
                'locales' => [],
                'auth' => 'any',
                'roles' => [],
                'plans' => [],
                'device' => 'all',
                'start_at' => null,
                'end_at' => null,
            ],
            'kicker' => '',
            'title' => '',
            'body' => '',
            'note' => '',
            'stats' => [],
            'items' => [],
            'aside_kicker' => '',
            'aside_title' => '',
            'aside_body' => '',
            'aside_items' => [],
            'aside_link_label' => '',
            'aside_link_href' => '',
            'aside_image_url' => '',
            'aside_image_alt' => '',
            'image_url' => '',
            'image_alt' => '',
            'embed_url' => '',
            'embed_title' => '',
            'embed_height' => 760,
            'primary_label' => '',
            'primary_href' => '',
            'secondary_label' => '',
            'secondary_href' => '',
            'hero_images' => [],
            'story_cards' => [],
            'feature_tabs' => [],
            'feature_tabs_style' => 'editorial',
            'feature_tabs_font_size' => 28,
            'industry_cards' => [],
            'testimonial_cards' => [],
            'title_color' => '',
            'body_color' => '',
            'title_font_size' => 0,
            'testimonial_author' => '',
            'testimonial_role' => '',
            'showcase_badge_label' => '',
            'showcase_badge_value' => '',
            'showcase_badge_note' => '',
            'showcase_divider_style' => 'diagonal',
            'override_note' => false,
            'override_items' => false,
            'override_stats' => false,
        ], $overrides);
    }

    private const TESTIMONIAL_QUOTES = [
        'industry-plumbing' => [
            'fr' => '<p>Ce n est pas juste une app de gestion des interventions. C est une solution simple et personnalisable pour piloter les demandes, les devis et les paiements en plomberie.</p>',
            'en' => '<p>This is more than a work management app. It is a simple, customizable solution for running plumbing requests, quotes, and payments in one flow.</p>',
        ],
        'industry-hvac' => [
            'fr' => '<p>Ce n est pas juste un outil de planification HVAC. C est une solution simple et adaptable pour coordonner maintenance, interventions et facturation dans le meme flux.</p>',
            'en' => '<p>This is more than HVAC scheduling software. It is a simple, adaptable solution for coordinating maintenance, field work, and billing in one operating flow.</p>',
        ],
        'industry-electrical' => [
            'fr' => '<p>Ce n est pas juste un outil pour gerer des jobs electriques. C est une solution claire et flexible pour suivre demandes, devis, execution et encaissement.</p>',
            'en' => '<p>This is more than an electrical work tracker. It is a clear, flexible solution for managing demand, quotes, execution, and cash collection.</p>',
        ],
        'industry-cleaning' => [
            'fr' => '<p>Ce n est pas juste une app pour assigner des taches. C est une solution simple et fiable pour piloter sites recurrents, equipes terrain et suivi client.</p>',
            'en' => '<p>This is more than a task assignment app. It is a simple, reliable solution for running recurring sites, field teams, and customer follow-up.</p>',
        ],
        'industry-salon-beauty' => [
            'fr' => '<p>Ce n est pas juste un agenda de rendez-vous. C est une solution fluide et personnalisable pour gerer reservations, rappels, no-show fees et fidelisation.</p>',
            'en' => '<p>This is more than a booking calendar. It is a smooth, customizable solution for managing appointments, reminders, no-show fees, and retention.</p>',
        ],
        'industry-restaurant' => [
            'fr' => '<p>Ce n est pas juste un outil de reservation. C est une solution simple et moderne pour gerer l attente, l accueil en salle et le suivi client.</p>',
            'en' => '<p>This is more than a reservation tool. It is a simple, modern solution for handling wait flow, front-of-house arrival, and guest follow-up.</p>',
        ],
    ];
}
