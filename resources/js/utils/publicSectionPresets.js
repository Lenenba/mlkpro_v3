import { defaultIndustryCards } from '@/utils/industryGrid';
import { defaultTestimonialCards } from '@/utils/testimonialGrid';
import { defaultStoryCards } from '@/utils/storyGrid';
import { defaultFeatureTabsShowcaseSection } from '@/utils/featureTabs';

const normalizeLocale = (locale = 'fr') => (
    String(locale || 'fr').toLowerCase().startsWith('fr')
        ? 'fr'
        : (String(locale || 'fr').toLowerCase().startsWith('es') ? 'es' : 'en')
);

const createPresetId = (prefix) => `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;

const createHeroImage = (image_url, image_alt) => ({
    id: createPresetId('hero-image'),
    image_url,
    image_alt,
});

const createFooterLink = (label = '', href = '', note = '') => ({
    id: createPresetId('footer-link'),
    label,
    href,
    note,
});

const createFooterGroup = ({ title = '', layout = 'stack', links = [] } = {}) => ({
    id: createPresetId('footer-group'),
    title,
    layout,
    links: links.map((link) => createFooterLink(link.label, link.href, link.note || '')),
});

const welcomeHeroSlidesCopy = {
    fr: [
        ['/images/landing/stock/team-laptop-window.jpg', 'Equipe qui prepare l accueil et la planification'],
        ['/images/landing/stock/service-team.jpg', 'Equipe service en coordination avant une intervention'],
        ['/images/landing/stock/desk-phone-laptop.jpg', 'Responsable commerciale avec telephone et ordinateur sur son bureau'],
        ['/images/landing/stock/service-tablet.jpg', 'Equipe qui coordonne les rendez-vous sur tablette'],
        ['/images/landing/stock/collab-laptop-desk.jpg', 'Deux collegues collaborent autour d un ordinateur portable'],
        ['/images/landing/stock/store-worker.jpg', 'Collaborateur logistique dans un espace de preparation'],
        ['/images/landing/stock/marketing-desk.jpg', 'Professionnelle qui gere messages et campagnes depuis son poste'],
        ['/images/landing/stock/meeting-room-laptops.jpg', 'Equipe en reunion autour de plusieurs ordinateurs'],
    ],
    es: [
        ['/images/landing/stock/team-laptop-window.jpg', 'Equipo preparando la planificacion y la atencion al cliente'],
        ['/images/landing/stock/service-team.jpg', 'Equipo de servicio coordinandose antes del trabajo de campo'],
        ['/images/landing/stock/desk-phone-laptop.jpg', 'Responsable comercial trabajando en un escritorio con telefono y portatil'],
        ['/images/landing/stock/service-tablet.jpg', 'Equipo que coordina citas desde una tableta'],
        ['/images/landing/stock/collab-laptop-desk.jpg', 'Dos colegas colaborando alrededor de un portatil'],
        ['/images/landing/stock/store-worker.jpg', 'Miembro del equipo logistico dentro de un espacio de preparacion'],
        ['/images/landing/stock/marketing-desk.jpg', 'Profesional gestionando campanas y mensajes desde un escritorio'],
        ['/images/landing/stock/meeting-room-laptops.jpg', 'Equipo en reunion alrededor de varios portatiles'],
    ],
    en: [
        ['/images/landing/stock/team-laptop-window.jpg', 'Team preparing customer scheduling and reception'],
        ['/images/landing/stock/service-team.jpg', 'Service team coordinating before field work'],
        ['/images/landing/stock/desk-phone-laptop.jpg', 'Sales lead working from a desk with phone and laptop'],
        ['/images/landing/stock/service-tablet.jpg', 'Team coordinating appointments on a tablet'],
        ['/images/landing/stock/collab-laptop-desk.jpg', 'Two teammates collaborating around a laptop'],
        ['/images/landing/stock/store-worker.jpg', 'Fulfillment team member inside a preparation space'],
        ['/images/landing/stock/marketing-desk.jpg', 'Professional managing campaigns and messages from a desk'],
        ['/images/landing/stock/meeting-room-laptops.jpg', 'Team in a meeting around multiple laptops'],
    ],
};

const footerGroupCopy = {
    fr: [
        {
            title: 'Industries desservies',
            links: [
                { label: 'Plomberie', href: '/pages/industry-plumbing' },
                { label: 'HVAC', href: '/pages/industry-hvac' },
                { label: 'Electricite', href: '/pages/industry-electrical' },
                { label: 'Entretien menager', href: '/pages/industry-cleaning' },
                { label: 'Salon & beaute', href: '/pages/industry-salon-beauty' },
                { label: 'Restaurant', href: '/pages/industry-restaurant' },
            ],
        },
        {
            title: 'Produits',
            links: [
                { label: 'Sales & CRM', href: '/pages/sales-crm' },
                { label: 'Reservations', href: '/pages/reservations' },
                { label: 'Operations', href: '/pages/operations' },
                { label: 'Commerce', href: '/pages/commerce' },
                { label: 'Marketing & Loyalty', href: '/pages/marketing-loyalty' },
                { label: 'AI & Automation', href: '/pages/ai-automation' },
                { label: 'Command Center', href: '/pages/command-center' },
            ],
        },
        {
            title: 'Ressources',
            links: [
                { label: 'Tarification', href: '/pricing' },
                { label: 'Conditions', href: '/terms' },
                { label: 'Confidentialite', href: '/privacy' },
                { label: 'Remboursement', href: '/refund' },
                { label: 'Contact', href: '/pages/contact-us' },
            ],
        },
    ],
    es: [
        {
            title: 'Sectores que atendemos',
            links: [
                { label: 'Fontaneria', href: '/pages/industry-plumbing' },
                { label: 'HVAC', href: '/pages/industry-hvac' },
                { label: 'Electricidad', href: '/pages/industry-electrical' },
                { label: 'Limpieza', href: '/pages/industry-cleaning' },
                { label: 'Salon y belleza', href: '/pages/industry-salon-beauty' },
                { label: 'Restaurantes', href: '/pages/industry-restaurant' },
            ],
        },
        {
            title: 'Productos',
            links: [
                { label: 'Sales & CRM', href: '/pages/sales-crm' },
                { label: 'Reservas', href: '/pages/reservations' },
                { label: 'Operations', href: '/pages/operations' },
                { label: 'Commerce', href: '/pages/commerce' },
                { label: 'Marketing & Loyalty', href: '/pages/marketing-loyalty' },
                { label: 'AI & Automation', href: '/pages/ai-automation' },
                { label: 'Command Center', href: '/pages/command-center' },
            ],
        },
        {
            title: 'Recursos',
            links: [
                { label: 'Precios', href: '/pricing' },
                { label: 'Terminos', href: '/terms' },
                { label: 'Privacidad', href: '/privacy' },
                { label: 'Reembolso', href: '/refund' },
                { label: 'Contacto', href: '/pages/contact-us' },
            ],
        },
    ],
    en: [
        {
            title: 'Industries We Serve',
            links: [
                { label: 'Plumbing', href: '/pages/industry-plumbing' },
                { label: 'HVAC', href: '/pages/industry-hvac' },
                { label: 'Electrical', href: '/pages/industry-electrical' },
                { label: 'Cleaning', href: '/pages/industry-cleaning' },
                { label: 'Salon & Beauty', href: '/pages/industry-salon-beauty' },
                { label: 'Restaurant', href: '/pages/industry-restaurant' },
            ],
        },
        {
            title: 'Products',
            links: [
                { label: 'Sales & CRM', href: '/pages/sales-crm' },
                { label: 'Reservations', href: '/pages/reservations' },
                { label: 'Operations', href: '/pages/operations' },
                { label: 'Commerce', href: '/pages/commerce' },
                { label: 'Marketing & Loyalty', href: '/pages/marketing-loyalty' },
                { label: 'AI & Automation', href: '/pages/ai-automation' },
                { label: 'Command Center', href: '/pages/command-center' },
            ],
        },
        {
            title: 'Resources',
            links: [
                { label: 'Pricing', href: '/pricing' },
                { label: 'Terms', href: '/terms' },
                { label: 'Privacy', href: '/privacy' },
                { label: 'Refund', href: '/refund' },
                { label: 'Contact us', href: '/pages/contact-us' },
            ],
        },
    ],
};

const footerLegalLinkCopy = {
    fr: [
        { label: 'Tarification', href: '/pricing' },
        { label: 'Conditions', href: '/terms' },
        { label: 'Confidentialite', href: '/privacy' },
        { label: 'Remboursement', href: '/refund' },
    ],
    es: [
        { label: 'Precios', href: '/pricing' },
        { label: 'Terminos', href: '/terms' },
        { label: 'Privacidad', href: '/privacy' },
        { label: 'Reembolso', href: '/refund' },
    ],
    en: [
        { label: 'Pricing', href: '/pricing' },
        { label: 'Terms', href: '/terms' },
        { label: 'Privacy', href: '/privacy' },
        { label: 'Refund', href: '/refund' },
    ],
};

const showcaseCtaCopy = {
    fr: {
        title: 'Demarrez un essai. Voyez si cela colle a votre operation.',
        body: '<p>Presentez votre plateforme, votre visite produit ou votre experience mobile avec un bloc plus editorial et plus vendeur.</p>',
        primary_label: "Demarrer l'essai",
        aside_link_label: 'Voir la visite produit',
    },
    es: {
        title: 'Empieza una prueba. Mira como encaja con tu operacion.',
        body: '<p>Muestra tu plataforma, tu visita de producto o tu experiencia movil con un bloque de conversion mas editorial y mas convincente.</p>',
        primary_label: 'Iniciar prueba',
        aside_link_label: 'Ver la visita del producto',
    },
    en: {
        title: 'Start a trial. See how it fits your operation.',
        body: '<p>Showcase your platform, product tour, or mobile experience with a more editorial conversion block.</p>',
        primary_label: 'Start trial',
        aside_link_label: 'Watch product tour',
    },
};

const industryGridCopy = {
    fr: {
        title: 'Fier partenaire des services a domicile dans plus de 50 industries.',
        primary_label: 'Voir toutes les industries',
    },
    es: {
        title: 'Socio orgulloso de negocios de servicios en mas de 50 sectores.',
        primary_label: 'Ver todos los sectores',
    },
    en: {
        title: 'Proud partner to home services in over 50 industries.',
        primary_label: 'See All Industries',
    },
};

const storyGridCopy = {
    fr: {
        title: 'Une IA pensee pour les entreprises de terrain.',
    },
    es: {
        title: 'IA pensada para negocios de campo.',
    },
    en: {
        title: 'AI built for blue-collar businesses',
    },
};

const testimonialGridCopy = {
    fr: {
        title: 'Approuve par les meilleures equipes d entretien.',
        body: '<p>Les pros de l entretien utilisent MLK Pro pour simplifier la planification, suivre les preferences clients et mieux coordonner leur equipe.</p>',
    },
    es: {
        title: 'Aprobado por los mejores equipos de limpieza.',
        body: '<p>Los profesionales de limpieza usan MLK Pro para simplificar la planificacion, seguir las preferencias de los clientes y coordinar mejor a sus equipos.</p>',
    },
    en: {
        title: 'Trusted by the best cleaning teams.',
        body: '<p>Cleaning pros use MLK Pro to simplify scheduling, track client preferences, and coordinate their crews with less friction.</p>',
    },
};

const footerCopy = {
    fr: {
        kicker: 'Accompagnement',
        title: 'Parlez a notre equipe',
        body: '<p>Besoin d un parcours produit plus precis ou d une page publique sur mesure ? On peut vous guider.</p>',
        items: [
            'Parcours public et modules metier',
            'Support produit et accompagnement',
            'Disponible en francais, en anglais et en espagnol',
        ],
        primary_label: 'Nous contacter',
        secondary_label: 'Voir les tarifs',
        copy: 'Tous droits reserves.',
    },
    es: {
        kicker: 'Acompanamiento',
        title: 'Habla con nuestro equipo',
        body: '<p>Necesitas un recorrido de producto mas claro o una pagina publica mas personalizada? Podemos ayudarte.</p>',
        items: [
            'Paginas publicas y modulos del negocio',
            'Soporte de producto y acompanamiento',
            'Disponible en frances, ingles y espanol',
        ],
        primary_label: 'Contactanos',
        secondary_label: 'Ver precios',
        copy: 'Todos los derechos reservados.',
    },
    en: {
        kicker: 'Support',
        title: 'Talk to our team',
        body: '<p>Need a sharper product journey or a custom public page setup? Our team can help.</p>',
        items: [
            'Public pages and business modules',
            'Product support and enablement',
            'Available in French, English, and Spanish',
        ],
        primary_label: 'Contact us',
        secondary_label: 'View pricing',
        copy: 'All rights reserved.',
    },
};

export const defaultWelcomeHeroSlides = (locale = 'fr') => (
    (welcomeHeroSlidesCopy[normalizeLocale(locale)] || welcomeHeroSlidesCopy.en)
        .map(([image_url, image_alt]) => createHeroImage(image_url, image_alt))
);

export const defaultFooterGroups = (locale = 'fr') => (
    (footerGroupCopy[normalizeLocale(locale)] || footerGroupCopy.en)
        .map((group) => createFooterGroup(group))
);

export const defaultFooterLegalLinks = (locale = 'fr') => (
    (footerLegalLinkCopy[normalizeLocale(locale)] || footerLegalLinkCopy.en)
        .map((link) => createFooterLink(link.label, link.href, link.note || ''))
);

export const defaultSectionLayoutPreset = (type = 'split', locale = 'fr') => {
    const resolvedLocale = normalizeLocale(locale);

    if (type === 'duo') {
        return {
            layout: 'duo',
            background_color: '#0f172a',
            image_position: 'left',
            alignment: 'left',
            density: 'normal',
            tone: 'contrast',
        };
    }

    if (type === 'testimonial') {
        return {
            layout: 'testimonial',
            background_color: '#e5ecef',
            image_position: 'right',
            alignment: 'left',
            density: 'normal',
            tone: 'default',
        };
    }

    if (type === 'feature_pairs') {
        return {
            layout: 'feature_pairs',
            background_color: '',
            image_position: 'left',
            alignment: 'left',
            density: 'normal',
            tone: 'default',
        };
    }

    if (type === 'showcase_cta') {
        return {
            layout: 'showcase_cta',
            background_color: '#202322',
            image_position: 'right',
            showcase_divider_style: 'diagonal',
            alignment: 'left',
            density: 'normal',
            tone: 'contrast',
            ...showcaseCtaCopy[resolvedLocale],
        };
    }

    if (type === 'industry_grid') {
        return {
            layout: 'industry_grid',
            background_color: '#f7f2e8',
            image_position: 'left',
            alignment: 'center',
            density: 'normal',
            tone: 'default',
            ...industryGridCopy[resolvedLocale],
            industry_cards: defaultIndustryCards(resolvedLocale),
        };
    }

    if (type === 'story_grid') {
        return {
            layout: 'story_grid',
            background_color: '#f7f2e8',
            image_position: 'left',
            alignment: 'center',
            density: 'normal',
            tone: 'default',
            ...storyGridCopy[resolvedLocale],
            story_cards: defaultStoryCards(resolvedLocale),
        };
    }

    if (type === 'feature_tabs') {
        return {
            ...defaultFeatureTabsShowcaseSection(resolvedLocale),
        };
    }

    if (type === 'welcome_hero') {
        return {
            layout: 'split',
            background_color: '',
            background_preset: 'welcome-hero',
            image_position: 'right',
            alignment: 'left',
            density: 'normal',
            tone: 'default',
            note: '',
            stats: [],
            hero_images: defaultWelcomeHeroSlides(resolvedLocale),
            preview_cards: [],
        };
    }

    if (type === 'welcome_trust') {
        return {
            layout: 'stack',
            background_color: '',
            image_position: 'left',
            alignment: 'center',
            density: 'compact',
            tone: 'muted',
        };
    }

    if (type === 'welcome_features') {
        return {
            layout: 'stack',
            background_color: '',
            image_position: 'left',
            alignment: 'center',
            density: 'normal',
            tone: 'contrast',
            feature_items: [],
            secondary_enabled: true,
            secondary_background_color: '',
            secondary_kicker: '',
            secondary_title: '',
            secondary_body: '',
            secondary_badge: '',
            secondary_feature_items: [],
        };
    }

    if (type === 'welcome_workflow') {
        return {
            layout: 'split',
            background_color: '',
            image_position: 'right',
            alignment: 'left',
            density: 'normal',
            tone: 'default',
            preview_cards: [],
        };
    }

    if (type === 'welcome_field') {
        return {
            layout: 'split',
            background_color: '',
            image_position: 'left',
            alignment: 'left',
            density: 'normal',
            tone: 'default',
        };
    }

    if (type === 'welcome_cta') {
        return {
            layout: 'stack',
            background_color: '',
            image_position: 'left',
            alignment: 'center',
            density: 'normal',
            tone: 'contrast',
        };
    }

    if (type === 'welcome_custom') {
        return {
            layout: 'split',
            background_color: '',
            image_position: 'right',
            alignment: 'left',
            density: 'normal',
            tone: 'default',
        };
    }

    if (type === 'footer') {
        return {
            layout: 'footer',
            background_color: '#062f3f',
            image_position: 'left',
            alignment: 'left',
            density: 'normal',
            tone: 'default',
            brand_logo_url: '/1.svg',
            brand_logo_alt: 'Malikia Pro',
            brand_href: '/',
            ...footerCopy[resolvedLocale],
            primary_href: '/pages/contact-us',
            secondary_href: '/pricing',
            contact_phone: '',
            contact_email: '',
            social_facebook_href: '',
            social_x_href: '',
            social_instagram_href: '',
            social_youtube_href: '',
            social_linkedin_href: '',
            google_play_href: '',
            app_store_href: '',
            footer_groups: defaultFooterGroups(resolvedLocale),
            legal_links: defaultFooterLegalLinks(resolvedLocale),
        };
    }

    if (type === 'testimonial_grid') {
        return {
            layout: 'testimonial_grid',
            background_color: '#f7f2e8',
            image_position: 'left',
            alignment: 'center',
            density: 'normal',
            tone: 'default',
            ...testimonialGridCopy[resolvedLocale],
            testimonial_cards: defaultTestimonialCards(resolvedLocale),
        };
    }

    if (type === 'stack') {
        return {
            layout: 'stack',
            alignment: 'center',
        };
    }

    if (type === 'contact') {
        return {
            layout: 'contact',
            alignment: 'left',
        };
    }

    return {
        layout: 'split',
        background_color: '',
        image_position: 'left',
        alignment: 'left',
        density: 'normal',
        tone: 'default',
    };
};
