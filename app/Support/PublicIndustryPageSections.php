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
        $copy = self::showcaseCopy($slug, $locale);

        return self::baseSection('industry-showcase', 'feature_tabs', [
            'background_color' => '#ffffff',
            'alignment' => 'center',
            'image_position' => 'left',
            'kicker' => $copy['kicker'],
            'title' => $copy['title'],
            'body' => $copy['body'],
            'feature_tabs_style' => 'editorial',
            'feature_tabs_font_size' => 28,
            'feature_tabs' => self::showcaseTabs($slug, $locale, $getNoticedImage, $winJobsImage, $workSmarterImage, $boostProfitsImage),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function editorialCtaSection(string $slug, string $locale): array
    {
        $visual = PublicPageStockImages::visual('collab-laptop-desk', $locale);
        $asideVisual = PublicPageStockImages::visual(self::industryCtaAsideKey($slug), $locale);
        $copy = self::editorialCtaCopy($slug, $locale);

        return self::baseSection('industry-editorial-cta', 'showcase_cta', [
            'tone' => 'contrast',
            'background_color' => '#202322',
            'background_preset' => 'welcome-hero',
            'alignment' => 'left',
            'image_position' => 'right',
            'title' => $copy['title'],
            'body' => $copy['body'],
            'image_url' => $visual['image_url'],
            'image_alt' => $visual['image_alt'],
            'aside_image_url' => $asideVisual['image_url'],
            'aside_image_alt' => $asideVisual['image_alt'],
            'primary_label' => self::localizedLabel($locale, 'Nous contacter', 'Talk to us', 'Contactanos'),
            'primary_href' => '/pages/contact-us',
            'aside_link_label' => self::localizedLabel($locale, 'Voir la visite produit', 'See the product tour', 'Ver la visita del producto'),
            'aside_link_href' => '/demo',
            'showcase_divider_style' => 'round',
        ]);
    }

    /**
     * @return array{body:string,kicker:string,title:string}
     */
    private static function showcaseCopy(string $slug, string $locale): array
    {
        $copy = self::localizedIndustryCopy(self::SHOWCASE_COPY, $slug, $locale);

        return [
            'kicker' => $copy['kicker'],
            'title' => $copy['title'],
            'body' => $copy['body'],
        ];
    }

    /**
     * @return array{body:string,title:string}
     */
    private static function editorialCtaCopy(string $slug, string $locale): array
    {
        return self::localizedIndustryCopy(self::EDITORIAL_CTA_COPY, $slug, $locale);
    }

    /**
     * @param  array<string, array<string, array<string, string>>>  $copySet
     * @return array<string, string>
     */
    private static function localizedIndustryCopy(array $copySet, string $slug, string $locale): array
    {
        $primary = $copySet[$slug] ?? $copySet['industry-plumbing'] ?? [];

        return $primary[$locale]
            ?? $primary['en']
            ?? $copySet['industry-plumbing'][$locale]
            ?? $copySet['industry-plumbing']['en'];
    }

    private static function localizedLabel(string $locale, string $french, string $english, ?string $spanish = null): string
    {
        if ($locale === 'fr') {
            return $french;
        }

        if ($locale === 'es' && $spanish !== null) {
            return $spanish;
        }

        return $english;
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
                    ? 'Gardez votre marque visible là où les clients vous cherchent'
                    : 'Keep your brand visible where customers are already searching',
                'body' => $isFrench
                    ? '<p>Pages publiques, formulaires de demande, campagnes et relances restent cohérents du premier clic jusqu\'au suivi.</p>'
                    : '<p>Public pages, intake forms, campaigns, and follow-up stay aligned from the first click through the next step.</p>',
                'metric' => $isFrench ? '44 % de croissance moyenne la première année' : '44% average growth in the first year',
                'story' => $isFrench
                    ? '<p>Nous avons clarifié notre présence en ligne, automatisé les suivis, et augmenté le volume de demandes qualifiées sans ajouter de friction.</p>'
                    : '<p>We clarified our online presence, automated follow-up, and increased qualified demand without adding friction.</p>',
                'person' => $isFrench ? 'Équipe croissance' : 'Growth team',
                'role' => $isFrench ? 'Opérations locales' : 'Local operations',
                'cta_label' => $isFrench ? 'Voir Marketing & fidélisation' : 'See Marketing & Loyalty',
                'cta_href' => '/pages/marketing-loyalty',
                'image_url' => $getNoticedImage['image_url'],
                'image_alt' => $getNoticedImage['image_alt'],
                'avatar_url' => '/images/presets/avatar-1.svg',
                'avatar_alt' => $isFrench ? 'Portrait équipe croissance' : 'Growth team portrait',
                'children' => [
                    [
                        'id' => 'feature-tab-get-noticed-reviews',
                        'label' => $isFrench ? 'Demandes d\'avis' : 'Review requests',
                        'title' => $isFrench ? 'Obtenez plus d\'avis sans relances manuelles' : 'Collect more reviews without manual chasing',
                        'body' => $isFrench
                            ? '<p>Déclenchez des demandes d\'avis au bon moment et facilitez la collecte pendant que l\'expérience client est encore fraîche.</p>'
                            : '<p>Trigger review requests at the right time and keep customer momentum while the experience is still fresh.</p>',
                        'cta_label' => $isFrench ? 'Voir les avis' : 'See reviews',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['get_noticed'][0]['image_url'],
                        'image_alt' => $childVisuals['get_noticed'][0]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-get-noticed-responses',
                        'label' => $isFrench ? 'Réponses rapides' : 'Fast responses',
                        'title' => $isFrench ? 'Répondez plus vite aux nouvelles demandes' : 'Reply faster to new demand',
                        'body' => $isFrench
                            ? '<p>Automatisez les messages d\'accueil et gardez un délai de réponse court pour montrer que votre entreprise est réactive.</p>'
                            : '<p>Automate welcome messages and keep your response time tight so your company feels responsive from the start.</p>',
                        'cta_label' => $isFrench ? 'Voir la messagerie' : 'See messaging',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['get_noticed'][1]['image_url'],
                        'image_alt' => $childVisuals['get_noticed'][1]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-get-noticed-campaigns',
                        'label' => $isFrench ? 'Marketing automatisé' : 'Automated marketing',
                        'title' => $isFrench ? 'Restez visible sans campagnes compliquées' : 'Stay visible without running complicated campaigns',
                        'body' => $isFrench
                            ? '<p>Programmez des suivis clients, des rappels saisonniers, et des campagnes simples pour revenir en tête au bon moment.</p>'
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
                            ? '<p>Partagez vos formulaires, pages, et devis avec des liens propres pour accélérer le bouche-à-oreille et les recommandations.</p>'
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
                'title' => $isFrench ? 'Transformez plus vite une demande entrante en devis signé' : 'Turn inbound demand into approved quotes faster',
                'body' => $isFrench
                    ? '<p>Qualification, devis, relances, et historique client restent reliés pour faire avancer chaque opportunité sans perte de contexte.</p>'
                    : '<p>Qualification, quoting, follow-up, and customer history stay connected so each opportunity keeps moving.</p>',
                'metric' => $isFrench ? 'Des devis plus rapides, mieux suivis' : 'Cleaner quotes with better follow-through',
                'story' => $isFrench
                    ? '<p>Les modèles, les options, et les relances automatiques nous ont aidés à envoyer des propositions propres plus tôt dans la journée.</p>'
                    : '<p>Templates, options, and automated follow-up helped us send polished proposals earlier in the day.</p>',
                'person' => $isFrench ? 'Équipe commerciale' : 'Commercial team',
                'role' => $isFrench ? 'Vente et qualification' : 'Sales and qualification',
                'cta_label' => $isFrench ? 'Voir Sales & CRM' : 'See Sales & CRM',
                'cta_href' => '/pages/sales-crm',
                'image_url' => $winJobsImage['image_url'],
                'image_alt' => $winJobsImage['image_alt'],
                'avatar_url' => '/images/presets/avatar-2.svg',
                'avatar_alt' => $isFrench ? 'Portrait équipe commerciale' : 'Commercial team portrait',
                'children' => [
                    [
                        'id' => 'feature-tab-win-jobs-booking',
                        'label' => $isFrench ? 'Réservations en ligne et formulaires' : 'Online booking and forms',
                        'title' => $isFrench ? 'Capturez plus de demandes sans friction' : 'Capture more demand without friction',
                        'body' => $isFrench
                            ? '<p>Ajoutez des formulaires de demande simples, acceptez les réservations en ligne, et faites entrer les leads directement dans votre pipeline.</p>'
                            : '<p>Add simple intake forms, accept online bookings, and push new demand straight into your pipeline.</p>',
                        'cta_label' => $isFrench ? 'Voir la capture de leads' : 'See lead capture',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['win_jobs'][0]['image_url'],
                        'image_alt' => $childVisuals['win_jobs'][0]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-win-jobs-templates',
                        'label' => $isFrench ? 'Modèles de devis' : 'Quote templates',
                        'title' => $isFrench ? 'Envoyez des devis cohérents en moins de temps' : 'Send consistent quotes in less time',
                        'body' => $isFrench
                            ? '<p>Préchargez vos services, prix, et options fréquentes pour sortir des devis clairs et uniformes depuis le bureau ou le terrain.</p>'
                            : '<p>Preload services, prices, and options so your team can send clearer proposals from the office or the field.</p>',
                        'cta_label' => $isFrench ? 'Voir les modèles de devis' : 'See quote templates',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['win_jobs'][1]['image_url'],
                        'image_alt' => $childVisuals['win_jobs'][1]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-win-jobs-line-items',
                        'label' => $isFrench ? 'Lignes optionnelles' : 'Optional line items',
                        'title' => $isFrench ? 'Augmentez la valeur moyenne de chaque devis' : 'Increase the average value of each quote',
                        'body' => $isFrench
                            ? '<p>Ajoutez facilement des options, des extras, et des services complémentaires pour proposer plus de valeur sans refaire vos devis à la main.</p>'
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
                            ? '<p>Programmez des rappels et des suivis automatiques pour faire avancer vos opportunités sans laisser de leads en attente.</p>'
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
                'title' => $isFrench ? 'Passez du bureau au terrain avec le même niveau de clarté' : 'Move from office to field with the same level of clarity',
                'body' => $isFrench
                    ? '<p>Planning, dispatch, fiches job, checklists, et historique client restent visibles pour que les équipes interviennent avec le bon contexte.</p>'
                    : '<p>Scheduling, dispatch, job sheets, checklists, and customer history stay visible so crews always arrive with context.</p>',
                'metric' => $isFrench ? 'Moins d\'aller-retour entre le bureau et le terrain' : 'Less back and forth between office and field',
                'story' => $isFrench
                    ? '<p>Le planning et les détails d\'intervention sont enfin partagés dans le même outil, ce qui réduit les appels de clarification pendant la journée.</p>'
                    : '<p>Scheduling and job details finally live in one place, which cuts the number of clarification calls during the day.</p>',
                'person' => $isFrench ? 'Équipe terrain' : 'Field team',
                'role' => $isFrench ? 'Dispatch et exécution' : 'Dispatch and execution',
                'cta_label' => $isFrench ? 'Voir Operations' : 'See Operations',
                'cta_href' => '/pages/operations',
                'image_url' => $workSmarterImage['image_url'],
                'image_alt' => $workSmarterImage['image_alt'],
                'avatar_url' => '/images/presets/avatar-3.svg',
                'avatar_alt' => $isFrench ? 'Portrait équipe terrain' : 'Field team portrait',
                'children' => [
                    [
                        'id' => 'feature-tab-work-smarter-schedule',
                        'label' => $isFrench ? 'Calendrier glisser-déposer' : 'Drag-and-drop calendar',
                        'title' => $isFrench ? 'Déplacez vos horaires sans rebâtir la journée' : 'Shift schedules without rebuilding the whole day',
                        'body' => $isFrench
                            ? '<p>Replanifiez en quelques secondes, assignez les bonnes équipes, et gardez tout le monde aligné avec des mises à jour instantanées.</p>'
                            : '<p>Reschedule in seconds, assign the right crews, and keep everyone aligned with real-time updates.</p>',
                        'cta_label' => $isFrench ? 'Voir la planification' : 'See scheduling',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['work_smarter'][0]['image_url'],
                        'image_alt' => $childVisuals['work_smarter'][0]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-work-smarter-dispatch',
                        'label' => $isFrench ? 'Dispatch d\'équipe' : 'Team dispatch',
                        'title' => $isFrench ? 'Affectez la bonne équipe au bon job' : 'Send the right crew to the right job',
                        'body' => $isFrench
                            ? '<p>Visualisez la disponibilité, l\'emplacement, et la charge de travail pour envoyer les bonnes personnes sans perdre de temps.</p>'
                            : '<p>See availability, location, and workload so you can dispatch the right people without wasting time.</p>',
                        'cta_label' => $isFrench ? 'Voir le dispatch' : 'See dispatch',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['work_smarter'][1]['image_url'],
                        'image_alt' => $childVisuals['work_smarter'][1]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-work-smarter-checklists',
                        'label' => $isFrench ? 'Checklists terrain' : 'Field checklists',
                        'title' => $isFrench ? 'Standardisez l\'exécution de chaque intervention' : 'Standardize execution across every visit',
                        'body' => $isFrench
                            ? '<p>Ajoutez des étapes, des formulaires, et des contrôles de qualité pour que le travail soit bien fait du premier coup.</p>'
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
                            ? '<p>Gardez les notes, photos, demandes, et anciens jobs au même endroit pour que vos équipes arrivent préparées chez le client.</p>'
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
                'title' => $isFrench ? 'Facturez plus vite et raccourcissez le cycle d\'encaissement' : 'Invoice faster and shorten the cash collection cycle',
                'body' => $isFrench
                    ? '<p>Factures, paiements sur place, rappels, et suivi de marge restent connectés au travail réalisé pour protéger vos revenus.</p>'
                    : '<p>Invoices, field payments, reminders, and margin visibility stay tied to completed work so revenue is easier to protect.</p>',
                'metric' => $isFrench ? 'Une meilleure visibilité sur la marge et la trésorerie' : 'Clearer visibility on margin and cash flow',
                'story' => $isFrench
                    ? '<p>Nos équipes clôturent plus vite les jobs et les rappels partent automatiquement, donc le cash rentre plus tôt.</p>'
                    : '<p>Teams close work faster and reminders go out automatically, so cash lands earlier.</p>',
                'person' => $isFrench ? 'Équipe finance' : 'Finance team',
                'role' => $isFrench ? 'Facturation et paiements' : 'Billing and payments',
                'cta_label' => $isFrench ? 'Voir Commerce' : 'See Commerce',
                'cta_href' => '/pages/commerce',
                'image_url' => $boostProfitsImage['image_url'],
                'image_alt' => $boostProfitsImage['image_alt'],
                'avatar_url' => '/images/presets/avatar-4.svg',
                'avatar_alt' => $isFrench ? 'Portrait équipe finance' : 'Finance team portrait',
                'children' => [
                    [
                        'id' => 'feature-tab-boost-profits-invoicing',
                        'label' => $isFrench ? 'Factures rapides' : 'Fast invoicing',
                        'title' => $isFrench ? 'Transformez un job terminé en facture en quelques clics' : 'Turn completed work into an invoice in a few clicks',
                        'body' => $isFrench
                            ? '<p>Générez vos factures sans refaire les informations du job, puis envoyez-les immédiatement au client.</p>'
                            : '<p>Generate invoices without re-entering job details, then send them to the customer immediately.</p>',
                        'cta_label' => $isFrench ? 'Voir la facturation' : 'See invoicing',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['boost_profits'][0]['image_url'],
                        'image_alt' => $childVisuals['boost_profits'][0]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-boost-profits-payments',
                        'label' => $isFrench ? 'Paiements sur place' : 'Field payments',
                        'title' => $isFrench ? 'Encaissez pendant que l\'équipe est encore chez le client' : 'Collect payment while the crew is still onsite',
                        'body' => $isFrench
                            ? '<p>Acceptez plusieurs moyens de paiement sur mobile pour réduire les délais et limiter les comptes à recevoir.</p>'
                            : '<p>Accept multiple payment methods on mobile to reduce delays and keep receivables under control.</p>',
                        'cta_label' => $isFrench ? 'Voir les paiements mobiles' : 'See mobile payments',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['boost_profits'][1]['image_url'],
                        'image_alt' => $childVisuals['boost_profits'][1]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-boost-profits-reminders',
                        'label' => $isFrench ? 'Rappels automatiques' : 'Automatic reminders',
                        'title' => $isFrench ? 'Relancez sans courir après chaque facture' : 'Follow up without chasing every invoice manually',
                        'body' => $isFrench
                            ? '<p>Automatisez vos rappels pour garder le cap sur les paiements en retard sans monopoliser votre équipe admin.</p>'
                            : '<p>Automate reminders so your admin team keeps momentum on overdue balances without getting buried.</p>',
                        'cta_label' => $isFrench ? 'Voir les rappels' : 'See reminders',
                        'cta_href' => '#',
                        'image_url' => $childVisuals['boost_profits'][2]['image_url'],
                        'image_alt' => $childVisuals['boost_profits'][2]['image_alt'],
                    ],
                    [
                        'id' => 'feature-tab-boost-profits-reporting',
                        'label' => $isFrench ? 'Rapports de marge' : 'Margin reporting',
                        'title' => $isFrench ? 'Voyez où vous gagnez et où vous perdez' : 'See where you win and where you lose',
                        'body' => $isFrench
                            ? '<p>Suivez vos revenus, vos services les plus profitables, et vos tendances de performance pour prendre de meilleures décisions.</p>'
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
        $quoteSet = self::TESTIMONIAL_QUOTES[$slug] ?? self::TESTIMONIAL_QUOTES['industry-plumbing'];
        $quote = $quoteSet[$locale] ?? $quoteSet['en'] ?? self::TESTIMONIAL_QUOTES['industry-plumbing']['en'];

        return self::baseSection('industry-testimonial', 'testimonial', [
            'background_color' => '#e5ecef',
            'alignment' => 'left',
            'image_position' => 'right',
            'body' => $quote,
            'image_url' => $visual['image_url'],
            'image_alt' => $visual['image_alt'],
            'testimonial_author' => 'Jules BILITIK',
            'testimonial_role' => self::localizedLabel($locale, 'Cofondateur', 'Co-founder', 'Cofundador'),
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
            'fr' => '<p>Ce n\'est pas juste une app de gestion des interventions. C\'est une façon plus fiable de piloter demandes, devis, interventions, et paiements en plomberie.</p>',
            'en' => '<p>This is more than a work management app. It is a simple, customizable solution for running plumbing requests, quotes, and payments in one flow.</p>',
        ],
        'industry-hvac' => [
            'fr' => '<p>Ce n\'est pas juste un outil de planification HVAC. C\'est une façon plus claire de coordonner maintenance, interventions, et facturation dans un même flux.</p>',
            'en' => '<p>This is more than HVAC scheduling software. It is a clearer way to coordinate maintenance, field visits, and billing inside one operating flow.</p>',
        ],
        'industry-electrical' => [
            'fr' => '<p>Ce n\'est pas juste un outil pour gérer des jobs électriques. C\'est une façon plus claire de relier demande, devis, exécution, et encaissement.</p>',
            'en' => '<p>This is more than an electrical work tracker. It is a clearer way to connect demand, quoting, execution, and collection.</p>',
        ],
        'industry-cleaning' => [
            'fr' => '<p>Ce n\'est pas juste une app pour assigner des tâches. C\'est une façon plus fiable de piloter sites récurrents, équipes terrain, et suivi client dans un même flux.</p>',
            'en' => '<p>This is more than a task assignment app. It is a more reliable way to run recurring sites, field teams, and customer follow-up in one flow.</p>',
        ],
        'industry-salon-beauty' => [
            'fr' => '<p>Ce n\'est pas juste un agenda de rendez-vous. C\'est une façon plus fluide de gérer réservations, rappels, no-show fees, et fidélisation dans une même expérience.</p>',
            'en' => '<p>This is more than a booking calendar. It is a smoother way to run bookings, reminders, no-show handling, and loyalty in one connected experience.</p>',
        ],
        'industry-restaurant' => [
            'fr' => '<p>Ce n\'est pas juste un outil de réservation. C\'est une façon plus claire de gérer disponibilités, arrivées, flux client, et retour des habitués dans une même expérience connectée.</p>',
            'en' => '<p>This is more than a reservation tool. It is a clearer way to run availability, arrivals, guest flow, and return visits in one connected experience.</p>',
        ],
    ];

    private const SHOWCASE_COPY = [
        'industry-plumbing' => [
            'fr' => [
                'kicker' => 'Un système sur tout le cycle plomberie',
                'title' => 'Le système connecté pour les équipes plomberie',
                'body' => '<p>De la demande entrante et du devis jusqu\'à l\'intervention, à la preuve de travail, puis au paiement, Malikia Pro aide les entreprises plomberie à garder le flux plus clair.</p>',
            ],
            'en' => [
                'kicker' => 'One system across the full plumbing workflow',
                'title' => 'The connected operating system for plumbing teams',
                'body' => '<p>From inbound demand and quoting through field work, proof of work, and payment, Malikia Pro helps plumbing businesses keep the flow clearer from start to finish.</p>',
            ],
        ],
        'industry-hvac' => [
            'fr' => [
                'kicker' => 'Un système sur tout le cycle HVAC',
                'title' => 'Le système connecté pour les équipes HVAC',
                'body' => '<p>Des appels de service et de la planification d\'entretien jusqu\'au dispatch technicien, au compte rendu de visite, puis à la facturation finale, Malikia Pro aide les entreprises HVAC à faire tenir le flux dans un cadre plus clair.</p>',
            ],
            'en' => [
                'kicker' => 'One system across the full HVAC workflow',
                'title' => 'The connected operating system for HVAC teams',
                'body' => '<p>From service requests and maintenance scheduling to technician dispatch, visit reporting, and final billing, Malikia Pro helps HVAC businesses run a clearer operating flow.</p>',
            ],
        ],
        'industry-electrical' => [
            'fr' => [
                'kicker' => 'Un système sur tout le cycle électrique',
                'title' => 'Le système connecté pour les équipes électriques',
                'body' => '<p>De la demande et du devis jusqu\'à la planification, à l\'exécution, puis à la facturation, Malikia Pro aide les entreprises électriques à garder un flux plus lisible entre bureau et terrain.</p>',
            ],
            'en' => [
                'kicker' => 'One system across the full electrical workflow',
                'title' => 'The connected operating system for electrical teams',
                'body' => '<p>From demand and quoting through planning, execution, and billing, Malikia Pro helps electrical businesses keep a clearer flow between the office and the field.</p>',
            ],
        ],
        'industry-cleaning' => [
            'fr' => [
                'kicker' => 'Un système sur toutes les opérations récurrentes',
                'title' => 'Le système connecté pour les entreprises de nettoyage',
                'body' => '<p>De la planification des sites récurrents et de la présence jusqu\'au suivi qualité, à la communication client, puis à la facturation, Malikia Pro aide les équipes de nettoyage à garder l\'opération plus constante dans le temps.</p>',
            ],
            'en' => [
                'kicker' => 'One system across recurring cleaning operations',
                'title' => 'The connected operating system for cleaning businesses',
                'body' => '<p>From recurring site planning and attendance to quality follow-up, customer communication, and invoicing, Malikia Pro helps cleaning teams keep the operation consistent over time.</p>',
            ],
        ],
        'industry-salon-beauty' => [
            'fr' => [
                'kicker' => 'Un système sur tout le parcours à rendez-vous',
                'title' => 'Le système connecté pour les salons et équipes beauté',
                'body' => '<p>De la réservation en ligne et des rappels jusqu\'au flux d\'accueil, au suivi de visite, puis à la fidélisation, Malikia Pro aide les activités beauté à garder l\'expérience plus fluide et plus simple à piloter.</p>',
            ],
            'en' => [
                'kicker' => 'One system across the full appointment-led journey',
                'title' => 'The connected operating system for salons and beauty teams',
                'body' => '<p>From online booking and reminders to front-desk flow, service follow-through, and customer loyalty, Malikia Pro helps beauty businesses keep the full experience more fluid and easier to run.</p>',
            ],
        ],
        'industry-restaurant' => [
            'fr' => [
                'kicker' => 'Un système sur tout le parcours client',
                'title' => 'Le système connecté pour les restaurants et équipes d\'accueil',
                'body' => '<p>Des réservations en ligne et des dépôts jusqu\'au flux d\'arrivée, à la gestion de la file, à la communication client, puis au suivi après la visite, Malikia Pro aide les restaurants à garder l\'expérience plus fluide et plus simple à piloter.</p>',
            ],
            'en' => [
                'kicker' => 'One system across the full guest journey',
                'title' => 'The connected operating system for restaurants and front-of-house teams',
                'body' => '<p>From online reservations and deposits to arrival flow, waitlist handling, guest communication, and follow-up after the visit, Malikia Pro helps restaurants keep the full experience more fluid and easier to run.</p>',
            ],
        ],
    ];

    private const EDITORIAL_CTA_COPY = [
        'industry-plumbing' => [
            'fr' => [
                'title' => 'Vérifiez si Malikia Pro correspond à la façon dont votre activité plomberie fonctionne vraiment',
                'body' => '<p>Découvrez comment demandes, devis, planification, preuve de travail, et paiements peuvent rester reliés dans un même système au lieu d\'être dispersés entre messages, feuilles de calcul, et outils séparés.</p>',
            ],
            'en' => [
                'title' => 'See if Malikia Pro fits the way your plumbing business actually runs',
                'body' => '<p>Explore how requests, quotes, scheduling, proof of work, and payments can stay connected in one system instead of being split across messages, spreadsheets, and disconnected tools.</p>',
            ],
        ],
        'industry-hvac' => [
            'fr' => [
                'title' => 'Vérifiez si Malikia Pro correspond à la façon dont votre activité HVAC fonctionne vraiment',
                'body' => '<p>Découvrez comment appels de service, planification d\'entretien, dispatch technicien, contexte de visite, et facturation peuvent rester reliés dans un même système au lieu d\'être dispersés entre outils bureau, messages terrain, et suivi manuel.</p>',
            ],
            'en' => [
                'title' => 'See if Malikia Pro fits the way your HVAC business actually runs',
                'body' => '<p>Explore how service calls, maintenance planning, technician dispatch, visit context, and billing can stay connected in one system instead of being split across office tools and manual follow-up.</p>',
            ],
        ],
        'industry-electrical' => [
            'fr' => [
                'title' => 'Vérifiez si Malikia Pro correspond à la façon dont votre activité électrique fonctionne vraiment',
                'body' => '<p>Découvrez comment demandes, devis, planification, exécution, et facturation peuvent rester reliés dans un même système au lieu d\'être dispersés entre échanges bureau, suivi chantier, et relances manuelles.</p>',
            ],
            'en' => [
                'title' => 'See if Malikia Pro fits the way your electrical business actually runs',
                'body' => '<p>Explore how demand, quoting, planning, execution, and billing can stay connected in one system instead of being split across office coordination, project follow-up, and manual billing steps.</p>',
            ],
        ],
        'industry-cleaning' => [
            'fr' => [
                'title' => 'Vérifiez si Malikia Pro correspond à la façon dont votre activité nettoyage fonctionne vraiment',
                'body' => '<p>Découvrez comment sites récurrents, présence, notes terrain, qualité de service, et suivi client peuvent rester reliés dans un même système au lieu d\'être dispersés entre feuilles de calcul, messages, et contrôles manuels.</p>',
            ],
            'en' => [
                'title' => 'See if Malikia Pro fits the way your cleaning operation actually runs',
                'body' => '<p>Explore how recurring sites, attendance, field notes, service quality, and customer follow-up can stay connected in one system instead of being split across spreadsheets, messages, and manual checks.</p>',
            ],
        ],
        'industry-salon-beauty' => [
            'fr' => [
                'title' => 'Vérifiez si Malikia Pro correspond à la façon dont votre expérience salon fonctionne vraiment',
                'body' => '<p>Découvrez comment réservations, rappels, flux d\'accueil, gestion des no-shows, et fidélisation peuvent rester reliés dans un même système au lieu d\'être dispersés entre messages, notes, et outils déconnectés.</p>',
            ],
            'en' => [
                'title' => 'See if Malikia Pro fits the way your salon experience actually runs',
                'body' => '<p>Explore how bookings, reminders, front-desk flow, no-show management, and loyalty can stay connected in one system instead of being split across inboxes, paper notes, and disconnected tools.</p>',
            ],
        ],
        'industry-restaurant' => [
            'fr' => [
                'title' => 'Vérifiez si Malikia Pro correspond à la façon dont votre service restaurant fonctionne vraiment',
                'body' => '<p>Découvrez comment réservations, dépôts, file d\'attente, check-in, et suivi client peuvent rester reliés dans un même système au lieu d\'être dispersés entre outils déconnectés, messages, et solutions de contournement à l\'accueil.</p>',
            ],
            'en' => [
                'title' => 'See if Malikia Pro fits the way your restaurant service actually runs',
                'body' => '<p>Explore how bookings, deposits, waitlist flow, check-in, and guest follow-up can stay connected in one system instead of being split across disconnected tools, inboxes, and front-desk workarounds.</p>',
            ],
        ],
    ];
}
