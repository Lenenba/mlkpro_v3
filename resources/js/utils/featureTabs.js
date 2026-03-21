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
            {
                id: 'feature-tab-win-jobs',
                label: 'Gagner des jobs',
                icon: 'file-text',
                title: 'Convertissez plus de demandes en jobs signes',
                body: '<p>Regroupez vos formulaires, vos devis et vos suivis dans un seul flux pour repondre plus vite et gagner plus de mandats.</p>',
                cta_label: 'Voir les outils de vente',
                cta_href: '#',
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
            },
            {
                id: 'feature-tab-work-smarter',
                label: 'Travailler plus intelligemment',
                icon: 'calendar-days',
                title: 'Donnez a vos equipes un flux plus net du bureau au terrain',
                body: '<p>Centralisez l horaire, les details de job et les checklists pour que chaque technicien sache quoi faire et quand le faire.</p>',
                cta_label: 'Voir les operations',
                cta_href: '#',
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
            },
            {
                id: 'feature-tab-get-noticed',
                label: 'Se faire remarquer',
                icon: 'clipboard-check',
                title: 'Developpez votre reputation apres chaque intervention',
                body: '<p>Activez des demandes d avis et des suivis plus consistants pour rester top of mind et transformer un bon service en bouche-a-oreille.</p>',
                cta_label: 'Voir la croissance',
                cta_href: '#',
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
            },
            {
                id: 'feature-tab-boost-profits',
                label: 'Augmenter les profits',
                icon: 'circle-dollar-sign',
                title: 'Protegez vos marges et raccourcissez le cycle d encaissement',
                body: '<p>Facturez plus vite, encaissez sur place et automatisez les rappels pour convertir le travail termine en revenu plus rapidement.</p>',
                cta_label: 'Voir les paiements',
                cta_href: '#',
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
            },
        ];
    }

    return [
        {
            id: 'feature-tab-win-jobs',
            label: 'Win Jobs',
            icon: 'file-text',
            title: 'Turn more requests into signed work',
            body: '<p>Keep forms, quotes, and follow-ups inside one clean workflow so your team can respond faster and win more work.</p>',
            cta_label: 'See sales tools',
            cta_href: '#',
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
        },
        {
            id: 'feature-tab-work-smarter',
            label: 'Work Smarter',
            icon: 'calendar-days',
            title: 'Give every team a cleaner workflow from office to field',
            body: '<p>Centralize scheduling, job details, and checklists so every crew knows what to do and when to do it.</p>',
            cta_label: 'See operations',
            cta_href: '#',
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
        },
        {
            id: 'feature-tab-get-noticed',
            label: 'Get Noticed',
            icon: 'clipboard-check',
            title: 'Grow your reputation after every visit',
            body: '<p>Trigger review requests and cleaner follow-ups so a great service experience turns into referrals and repeat work.</p>',
            cta_label: 'See growth tools',
            cta_href: '#',
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
        },
        {
            id: 'feature-tab-boost-profits',
            label: 'Boost Profits',
            icon: 'circle-dollar-sign',
            title: 'Protect margins and shorten your payment cycle',
            body: '<p>Invoice faster, collect on-site, and automate reminders so completed work turns into revenue faster.</p>',
            cta_label: 'See payments',
            cta_href: '#',
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
        },
    ];
};
