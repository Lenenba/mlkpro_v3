import {
    CalendarDays,
    CircleDollarSign,
    ClipboardCheck,
    ClipboardList,
    FileText,
    Wrench,
} from 'lucide-vue-next';

export const featureTabIconMap = {
    'calendar-days': CalendarDays,
    'file-text': FileText,
    'clipboard-check': ClipboardCheck,
    'clipboard-list': ClipboardList,
    'circle-dollar-sign': CircleDollarSign,
    wrench: Wrench,
};

export const featureTabIconOptions = [
    { value: 'calendar-days', label: 'Calendar Days' },
    { value: 'file-text', label: 'File Text' },
    { value: 'clipboard-check', label: 'Clipboard Check' },
    { value: 'clipboard-list', label: 'Clipboard List' },
    { value: 'circle-dollar-sign', label: 'Circle Dollar Sign' },
    { value: 'wrench', label: 'Wrench' },
];

export const defaultFeatureTabsTriggerFontSize = 28;

export const normalizeFeatureTabsTriggerFontSize = (value) => {
    const parsed = Number.parseInt(value, 10);
    if (!Number.isFinite(parsed)) {
        return defaultFeatureTabsTriggerFontSize;
    }

    return Math.min(Math.max(parsed, 18), 40);
};

export const sanitizeFeatureTabIconKey = (value) => (
    Object.prototype.hasOwnProperty.call(featureTabIconMap, value) ? value : ''
);

const normalizeItems = (items) => (
    Array.isArray(items)
        ? items
            .map((item) => String(item || '').trim())
            .filter((item) => item.length > 0)
        : []
);

export const createFeatureTabChild = (overrides = {}) => ({
    id: overrides.id || `feature-tab-child-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    label: overrides.label || '',
    title: overrides.title || '',
    body: overrides.body || '',
    image_url: overrides.image_url || '',
    image_alt: overrides.image_alt || '',
    cta_label: overrides.cta_label || '',
    cta_href: overrides.cta_href || '',
});

const normalizeChildren = (children) => (
    Array.isArray(children) ? children.map((child) => createFeatureTabChild(child)) : []
);

export const createFeatureTab = (overrides = {}) => ({
    id: overrides.id || `feature-tab-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    label: overrides.label || '',
    icon: sanitizeFeatureTabIconKey(overrides.icon || ''),
    items: normalizeItems(overrides.items),
    children: normalizeChildren(overrides.children),
    title: overrides.title || '',
    body: overrides.body || '',
    image_url: overrides.image_url || '',
    image_alt: overrides.image_alt || '',
    cta_label: overrides.cta_label || '',
    cta_href: overrides.cta_href || '',
    metric: overrides.metric || '',
    story: overrides.story || '',
    person: overrides.person || '',
    role: overrides.role || '',
    avatar_url: overrides.avatar_url || '',
    avatar_alt: overrides.avatar_alt || '',
});

export const ensureFeatureTabs = (tabs) => (
    Array.isArray(tabs) ? tabs.map((tab) => createFeatureTab(tab)) : []
);

export const resolveFeatureTabIconComponent = (tab) => (
    featureTabIconMap[sanitizeFeatureTabIconKey(tab?.icon) || 'wrench'] || Wrench
);

export const defaultFeatureTabs = (locale = 'fr') => {
    if (locale === 'fr') {
        return [
            createFeatureTab({
                id: 'feature-tab-get-noticed',
                label: 'Se faire remarquer',
                icon: 'clipboard-check',
                title: 'Gardez votre marque visible la ou les clients vous cherchent',
                body: '<p>Pages publiques, formulaires de demande, campagnes et relances restent coherents du premier clic jusqu au suivi.</p>',
                cta_label: 'Voir Marketing & Loyalty',
                cta_href: '/pages/marketing-loyalty',
                image_url: '/images/landing/hero-dashboard.svg',
                image_alt: 'Apercu marketing et tableau de bord',
                metric: '44 % de croissance moyenne la premiere annee',
                story: '<p>Nous avons clarifie notre presence en ligne, automatise les suivis et augmente le volume de demandes qualifiees sans ajouter de friction.</p>',
                person: 'Equipe croissance',
                role: 'Operations locales',
                avatar_url: '/images/presets/avatar-1.svg',
                avatar_alt: 'Portrait equipe croissance',
                children: [
                    {
                        id: 'feature-tab-get-noticed-reviews',
                        label: 'Demandes d avis',
                        title: 'Obtenez plus d avis sans relances manuelles',
                        body: '<p>Declenchez des demandes au bon moment et facilitez la collecte d avis pendant que l experience client est encore fraiche.</p>',
                        cta_label: 'Voir les avis',
                        cta_href: '#',
                    },
                    {
                        id: 'feature-tab-get-noticed-responses',
                        label: 'Reponses rapides',
                        title: 'Repondez plus vite aux nouvelles demandes',
                        body: '<p>Automatisez les messages d accueil et gardez un delai de reponse court pour montrer que votre entreprise est reactive.</p>',
                        cta_label: 'Voir la messagerie',
                        cta_href: '#',
                    },
                    {
                        id: 'feature-tab-get-noticed-campaigns',
                        label: 'Marketing automatise',
                        title: 'Restez visible sans campagnes compliquees',
                        body: '<p>Programmez des suivis clients, des rappels saisonniers et des campagnes simples pour revenir en tete au bon moment.</p>',
                        cta_label: 'Voir le marketing',
                        cta_href: '#',
                    },
                    {
                        id: 'feature-tab-get-noticed-sharing',
                        label: 'Liens de partage',
                        title: 'Diffusez plus facilement votre offre',
                        body: '<p>Partagez vos formulaires, pages et devis avec des liens propres pour accelerer le bouche-a-oreille et les recommandations.</p>',
                        cta_label: 'Voir les liens',
                        cta_href: '#',
                    },
                ],
            }),
            createFeatureTab({
                id: 'feature-tab-win-jobs',
                label: 'Gagner des jobs',
                icon: 'file-text',
                title: 'Transformez plus vite une demande entrante en devis signe',
                body: '<p>Qualification, devis, relances et historique client sont relies pour faire avancer chaque opportunite sans perte de contexte.</p>',
                cta_label: 'Voir Sales & CRM',
                cta_href: '/pages/sales-crm',
                image_url: '/images/landing/workflow-board.svg',
                image_alt: 'Vue pipeline et workflow commercial',
                metric: 'Des devis plus rapides, mieux suivis',
                story: '<p>Les modeles, les options et les relances automatiques nous ont aide a envoyer des propositions propres plus tot dans la journee.</p>',
                person: 'Equipe commerciale',
                role: 'Vente et qualification',
                avatar_url: '/images/presets/avatar-2.svg',
                avatar_alt: 'Portrait equipe commerciale',
                children: [
                    {
                        id: 'feature-tab-win-jobs-booking',
                        label: 'Reservations en ligne et formulaires',
                        title: 'Capturez plus de demandes sans friction',
                        body: '<p>Ajoutez des formulaires de demande simples, acceptez les reservations en ligne et faites entrer les leads directement dans votre pipeline.</p>',
                        cta_label: 'Voir la capture de leads',
                        cta_href: '#',
                    },
                    {
                        id: 'feature-tab-win-jobs-templates',
                        label: 'Modeles de devis',
                        title: 'Envoyez des devis coherents en moins de temps',
                        body: '<p>Prechargez vos services, prix et options frequentes pour sortir des devis clairs et uniformes depuis le bureau ou le terrain.</p>',
                        cta_label: 'Voir les modeles de devis',
                        cta_href: '#',
                    },
                    {
                        id: 'feature-tab-win-jobs-line-items',
                        label: 'Lignes optionnelles',
                        title: 'Augmentez la valeur moyenne de chaque devis',
                        body: '<p>Ajoutez facilement des options, des extras et des services complementaires pour proposer plus de valeur sans refaire vos devis a la main.</p>',
                        cta_label: 'Voir les options de devis',
                        cta_href: '#',
                    },
                    {
                        id: 'feature-tab-win-jobs-follow-ups',
                        label: 'Suivis automatiques',
                        title: 'Relancez au bon moment sans suivi manuel',
                        body: '<p>Programmez des rappels et des suivis automatiques pour faire avancer vos opportunites sans laisser de leads en attente.</p>',
                        cta_label: 'Voir les suivis',
                        cta_href: '#',
                    },
                ],
            }),
            createFeatureTab({
                id: 'feature-tab-work-smarter',
                label: 'Travailler mieux',
                icon: 'calendar-days',
                title: 'Passez du bureau au terrain avec le meme niveau de clarte',
                body: '<p>Planning, dispatch, fiches job, checklists et historique client restent visibles pour que les equipes interviennent avec le bon contexte.</p>',
                cta_label: 'Voir Operations',
                cta_href: '/pages/operations',
                image_url: '/images/landing/mobile-field.svg',
                image_alt: 'Apercu mobile pour equipes terrain',
                metric: 'Moins d aller-retour entre le bureau et le terrain',
                story: '<p>Le planning et les details d intervention sont enfin partages dans le meme outil, ce qui reduit les appels de clarification pendant la journee.</p>',
                person: 'Equipe terrain',
                role: 'Dispatch et execution',
                avatar_url: '/images/presets/avatar-3.svg',
                avatar_alt: 'Portrait equipe terrain',
                children: [
                    {
                        id: 'feature-tab-work-smarter-schedule',
                        label: 'Calendrier glisser-deposer',
                        title: 'Deplacez vos horaires sans rebatir la journee',
                        body: '<p>Replanifiez en quelques secondes, assignez les bonnes equipes et gardez tout le monde aligne avec des mises a jour instantanees.</p>',
                        cta_label: 'Voir la planification',
                        cta_href: '#',
                    },
                    {
                        id: 'feature-tab-work-smarter-dispatch',
                        label: 'Dispatch d equipe',
                        title: 'Affectez la bonne equipe au bon job',
                        body: '<p>Visualisez la disponibilite, l emplacement et la charge de travail pour envoyer les bonnes personnes sans perdre de temps.</p>',
                        cta_label: 'Voir le dispatch',
                        cta_href: '#',
                    },
                    {
                        id: 'feature-tab-work-smarter-checklists',
                        label: 'Checklists terrain',
                        title: 'Standardisez l execution de chaque intervention',
                        body: '<p>Ajoutez des etapes, des formulaires et des controles de qualite pour que le travail soit bien fait du premier coup.</p>',
                        cta_label: 'Voir les checklists',
                        cta_href: '#',
                    },
                    {
                        id: 'feature-tab-work-smarter-history',
                        label: 'Historique client',
                        title: 'Retrouvez le contexte complet avant chaque visite',
                        body: '<p>Gardez les notes, photos, demandes et anciens jobs au meme endroit pour que vos equipes arrivent preparees chez le client.</p>',
                        cta_label: 'Voir les fiches client',
                        cta_href: '#',
                    },
                ],
            }),
            createFeatureTab({
                id: 'feature-tab-boost-profits',
                label: 'Booster les profits',
                icon: 'circle-dollar-sign',
                title: 'Facturez plus vite et raccourcissez le cycle d encaissement',
                body: '<p>Factures, paiements sur place, rappels et suivi de marge restent connectes au travail realise pour proteger vos revenus.</p>',
                cta_label: 'Voir Commerce',
                cta_href: '/pages/commerce',
                image_url: '/images/mega-menu/commerce-suite.svg',
                image_alt: 'Illustration commerce et paiements',
                metric: 'Une meilleure visibilite sur la marge et la tresorerie',
                story: '<p>Nos equipes cloturent plus vite les jobs et les rappels partent automatiquement, donc le cash rentre plus tot.</p>',
                person: 'Equipe finance',
                role: 'Facturation et paiements',
                avatar_url: '/images/presets/avatar-4.svg',
                avatar_alt: 'Portrait equipe finance',
                children: [
                    {
                        id: 'feature-tab-boost-profits-invoicing',
                        label: 'Factures rapides',
                        title: 'Transformez un job termine en facture en quelques clics',
                        body: '<p>Generez vos factures sans refaire les informations du job, puis envoyez-les immediatement au client.</p>',
                        cta_label: 'Voir la facturation',
                        cta_href: '#',
                    },
                    {
                        id: 'feature-tab-boost-profits-payments',
                        label: 'Paiements sur place',
                        title: 'Encaissez pendant que l equipe est encore chez le client',
                        body: '<p>Acceptez plusieurs moyens de paiement sur mobile pour reduire les delais et limiter les comptes a recevoir.</p>',
                        cta_label: 'Voir les paiements mobiles',
                        cta_href: '#',
                    },
                    {
                        id: 'feature-tab-boost-profits-reminders',
                        label: 'Rappels automatiques',
                        title: 'Relancez sans courir apres chaque facture',
                        body: '<p>Automatisez vos rappels pour garder le cap sur les paiements en retard sans monopoliser votre equipe admin.</p>',
                        cta_label: 'Voir les rappels',
                        cta_href: '#',
                    },
                    {
                        id: 'feature-tab-boost-profits-reporting',
                        label: 'Rapports de marge',
                        title: 'Voyez ou vous gagnez et ou vous perdez',
                        body: '<p>Suivez vos revenus, vos services les plus profitables et vos tendances de performance pour prendre de meilleures decisions.</p>',
                        cta_label: 'Voir les rapports',
                        cta_href: '#',
                    },
                ],
            }),
        ];
    }

    return [
        createFeatureTab({
            id: 'feature-tab-get-noticed',
            label: 'Get Noticed',
            icon: 'clipboard-check',
            title: 'Keep your brand visible where customers are already searching',
            body: '<p>Public pages, intake forms, campaigns, and follow-ups stay aligned from the first click through long-term retention.</p>',
            cta_label: 'See Marketing & Loyalty',
            cta_href: '/pages/marketing-loyalty',
            image_url: '/images/landing/hero-dashboard.svg',
            image_alt: 'Marketing dashboard preview',
            metric: '44% revenue growth on average in year one',
            story: '<p>We clarified our public presence, automated follow-ups, and increased qualified demand without adding more busywork.</p>',
            person: 'Growth team',
            role: 'Local operations',
            avatar_url: '/images/presets/avatar-1.svg',
            avatar_alt: 'Growth team portrait',
            children: [
                {
                    id: 'feature-tab-get-noticed-reviews',
                    label: 'Review requests',
                    title: 'Collect more reviews without manual outreach',
                    body: '<p>Ask at the right moment and make it easy for customers to leave feedback while the experience is still fresh.</p>',
                    cta_label: 'See review requests',
                    cta_href: '#',
                },
                {
                    id: 'feature-tab-get-noticed-responses',
                    label: 'Fast responses',
                    title: 'Reply to new leads faster',
                    body: '<p>Automate first-touch messages and reduce response time so your business feels responsive from the start.</p>',
                    cta_label: 'See messaging',
                    cta_href: '#',
                },
                {
                    id: 'feature-tab-get-noticed-campaigns',
                    label: 'Automated marketing',
                    title: 'Stay visible without complex campaigns',
                    body: '<p>Schedule seasonal reminders, customer follow-ups, and simple campaigns that keep your brand top of mind.</p>',
                    cta_label: 'See marketing',
                    cta_href: '#',
                },
                {
                    id: 'feature-tab-get-noticed-sharing',
                    label: 'Shareable links',
                    title: 'Make it easier to share and recommend your business',
                    body: '<p>Send clean links to forms, pages, and quotes so referrals and repeat business are easier to capture.</p>',
                    cta_label: 'See sharing tools',
                    cta_href: '#',
                },
            ],
        }),
        createFeatureTab({
            id: 'feature-tab-win-jobs',
            label: 'Win Jobs',
            icon: 'file-text',
            title: 'Turn inbound demand into approved quotes faster',
            body: '<p>Qualification, quotes, follow-ups, and customer history stay connected so each opportunity moves with less friction.</p>',
            cta_label: 'See Sales & CRM',
            cta_href: '/pages/sales-crm',
            image_url: '/images/landing/workflow-board.svg',
            image_alt: 'Sales workflow and pipeline preview',
            metric: 'Faster quotes with cleaner follow-through',
            story: '<p>Templates, option sets, and automatic follow-ups helped us send polished proposals earlier in the day and keep them moving.</p>',
            person: 'Sales team',
            role: 'Qualification and quoting',
            avatar_url: '/images/presets/avatar-2.svg',
            avatar_alt: 'Sales team portrait',
            children: [
                {
                    id: 'feature-tab-win-jobs-booking',
                    label: 'Online booking and request forms',
                    title: 'Capture more demand without extra admin work',
                    body: '<p>Add simple request forms, accept online bookings, and move leads directly into your pipeline.</p>',
                    cta_label: 'See lead capture',
                    cta_href: '#',
                },
                {
                    id: 'feature-tab-win-jobs-templates',
                    label: 'Quote templates',
                    title: 'Send consistent quotes in less time',
                    body: '<p>Preload your most common services, pricing, and upsells so crews can send polished quotes from anywhere.</p>',
                    cta_label: 'See quote templates',
                    cta_href: '#',
                },
                {
                    id: 'feature-tab-win-jobs-line-items',
                    label: 'Optional line items',
                    title: 'Raise quote value with cleaner upsells',
                    body: '<p>Add optional services and upgrades without rebuilding the quote from scratch each time.</p>',
                    cta_label: 'See quote options',
                    cta_href: '#',
                },
                {
                    id: 'feature-tab-win-jobs-follow-ups',
                    label: 'Automatic follow-ups',
                    title: 'Keep deals moving without manual chasing',
                    body: '<p>Schedule follow-ups and reminders automatically so opportunities keep progressing even on busy days.</p>',
                    cta_label: 'See follow-ups',
                    cta_href: '#',
                },
            ],
        }),
        createFeatureTab({
            id: 'feature-tab-work-smarter',
            label: 'Work Smarter',
            icon: 'calendar-days',
            title: 'Move from office to field with the same level of clarity',
            body: '<p>Scheduling, dispatch, job records, checklists, and customer history stay visible so crews arrive prepared.</p>',
            cta_label: 'See Operations',
            cta_href: '/pages/operations',
            image_url: '/images/landing/mobile-field.svg',
            image_alt: 'Field mobile workspace preview',
            metric: 'Less back-and-forth between office and crews',
            story: '<p>Scheduling and job details finally live in one place, which cuts down on clarification calls during the day.</p>',
            person: 'Field team',
            role: 'Dispatch and execution',
            avatar_url: '/images/presets/avatar-3.svg',
            avatar_alt: 'Field team portrait',
            children: [
                {
                    id: 'feature-tab-work-smarter-schedule',
                    label: 'Drag-and-drop calendar',
                    title: 'Reschedule faster without rebuilding the day',
                    body: '<p>Move appointments quickly, assign the right crew, and keep the whole team aligned with instant updates.</p>',
                    cta_label: 'See scheduling',
                    cta_href: '#',
                },
                {
                    id: 'feature-tab-work-smarter-dispatch',
                    label: 'Team dispatching',
                    title: 'Send the right crew to the right job',
                    body: '<p>Balance availability, location, and workload so dispatch decisions stay fast and practical.</p>',
                    cta_label: 'See dispatching',
                    cta_href: '#',
                },
                {
                    id: 'feature-tab-work-smarter-checklists',
                    label: 'Field checklists',
                    title: 'Standardize execution across every visit',
                    body: '<p>Add forms, steps, and quality checks so jobs are completed the right way every time.</p>',
                    cta_label: 'See checklists',
                    cta_href: '#',
                },
                {
                    id: 'feature-tab-work-smarter-history',
                    label: 'Customer history',
                    title: 'Give crews the full context before they arrive',
                    body: '<p>Keep notes, photos, requests, and past jobs together so technicians show up prepared.</p>',
                    cta_label: 'See customer records',
                    cta_href: '#',
                },
            ],
        }),
        createFeatureTab({
            id: 'feature-tab-boost-profits',
            label: 'Boost Profits',
            icon: 'circle-dollar-sign',
            title: 'Invoice faster and shorten the time between work and cash',
            body: '<p>Invoices, on-site payments, reminders, and margin tracking stay tied to completed work so revenue is easier to protect.</p>',
            cta_label: 'See Commerce',
            cta_href: '/pages/commerce',
            image_url: '/images/mega-menu/commerce-suite.svg',
            image_alt: 'Commerce and payment illustration',
            metric: 'Sharper visibility on margin and cash flow',
            story: '<p>Teams close jobs faster and reminders go out automatically, so cash arrives sooner with less manual chasing.</p>',
            person: 'Finance team',
            role: 'Billing and payments',
            avatar_url: '/images/presets/avatar-4.svg',
            avatar_alt: 'Finance team portrait',
            children: [
                {
                    id: 'feature-tab-boost-profits-invoicing',
                    label: 'Fast invoicing',
                    title: 'Turn completed work into an invoice in seconds',
                    body: '<p>Generate invoices from job details without re-entering information, then send them immediately.</p>',
                    cta_label: 'See invoicing',
                    cta_href: '#',
                },
                {
                    id: 'feature-tab-boost-profits-payments',
                    label: 'On-site payments',
                    title: 'Collect while the crew is still on-site',
                    body: '<p>Accept multiple payment methods in the field to reduce delays and shrink receivables.</p>',
                    cta_label: 'See mobile payments',
                    cta_href: '#',
                },
                {
                    id: 'feature-tab-boost-profits-reminders',
                    label: 'Automatic reminders',
                    title: 'Follow up without chasing every invoice manually',
                    body: '<p>Automate payment reminders so your admin team spends less time nudging overdue accounts.</p>',
                    cta_label: 'See reminders',
                    cta_href: '#',
                },
                {
                    id: 'feature-tab-boost-profits-reporting',
                    label: 'Margin reporting',
                    title: 'See where profit is growing or slipping',
                    body: '<p>Track revenue trends and profitable services so decisions are based on clear numbers instead of guesswork.</p>',
                    cta_label: 'See reporting',
                    cta_href: '#',
                },
            ],
        }),
    ];
};

export const defaultFeatureTabsShowcaseSection = (locale = 'fr') => {
    if (locale === 'fr') {
        return {
            layout: 'feature_tabs',
            background_color: '#f7f2e8',
            image_position: 'left',
            alignment: 'center',
            density: 'normal',
            tone: 'default',
            kicker: 'Un systeme qui couvre tout le cycle client',
            title: 'La solution tout-en-un pour les pros du service a domicile',
            body: '<p>De la visibilite locale jusqu au paiement final, chaque etape reste dans un meme flux plutot que dans quatre outils separes.</p>',
            feature_tabs_font_size: defaultFeatureTabsTriggerFontSize,
            feature_tabs: defaultFeatureTabs('fr'),
            primary_label: '',
            primary_href: '',
            secondary_label: '',
            secondary_href: '',
        };
    }

    return {
        layout: 'feature_tabs',
        background_color: '#f7f2e8',
        image_position: 'left',
        alignment: 'center',
        density: 'normal',
        tone: 'default',
        kicker: 'One system across the full customer journey',
        title: 'The all-in-one solution for home service pros',
        body: '<p>From local visibility to final payment, each step stays inside one operating flow instead of being split across disconnected tools.</p>',
        feature_tabs_font_size: defaultFeatureTabsTriggerFontSize,
        feature_tabs: defaultFeatureTabs('en'),
        primary_label: '',
        primary_href: '',
        secondary_label: '',
        secondary_href: '',
    };
};
