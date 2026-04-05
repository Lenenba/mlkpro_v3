const normalizeLocale = (locale = 'fr') => (
    String(locale || 'fr').toLowerCase().startsWith('fr') ? 'fr' : 'en'
);

const toCount = (value) => {
    const parsed = Number(value || 0);
    return Number.isFinite(parsed) ? parsed : 0;
};

const firstNonEmpty = (...values) => (
    values
        .map((value) => String(value || '').trim())
        .find((value) => value.length > 0) || ''
);

const countLabel = (count, locale, singular, plural) => {
    const safeCount = toCount(count);
    const label = safeCount === 1 ? singular : plural;
    return `${safeCount} ${label}`;
};

const normalizeImageCandidates = (...groups) => (
    groups
        .flatMap((group) => (Array.isArray(group) ? group : [group]))
        .map((value) => String(value || '').trim())
        .filter((value) => value.length > 0)
);

const createUniqueImagePicker = ({ excluded = [] } = {}) => {
    const used = new Set(normalizeImageCandidates(excluded));

    return (...candidates) => {
        const normalized = normalizeImageCandidates(candidates);
        const nextImage = normalized.find((candidate) => !used.has(candidate)) || normalized[0] || '';

        if (nextImage) {
            used.add(nextImage);
        }

        return nextImage;
    };
};

const storeStock = {
    hero: '/images/landing/stock/store-worker.jpg',
    inventory: '/images/landing/stock/store-boxes.jpg',
    checkout: '/images/landing/stock/store-payment.jpg',
    warehouse: '/images/landing/stock/warehouse-worker.jpg',
    payments: '/images/landing/stock/payments-terminal.jpg',
    desk: '/images/landing/stock/desk-phone-laptop.jpg',
    catalog: '/images/landing/stock/collab-laptop-desk.jpg',
    planning: '/images/landing/stock/meeting-room-laptops.jpg',
    tablet: '/images/landing/stock/hero-tablet.jpg',
};

const servicesStock = {
    hero: '/images/landing/stock/service-team.jpg',
    planning: '/images/landing/stock/service-tablet.jpg',
    install: '/images/landing/stock/service-install.jpg',
    workflow: '/images/landing/stock/workflow-plan.jpg',
    field: '/images/landing/stock/field-checklist.jpg',
    office: '/images/landing/stock/team-laptop-window.jpg',
    meeting: '/images/landing/stock/meeting-room-laptops.jpg',
    desk: '/images/landing/stock/marketing-desk.jpg',
    tabletHero: '/images/landing/stock/hero-tablet.jpg',
    collab: '/images/landing/stock/collab-laptop-desk.jpg',
};

export const publicCatalogStockImages = {
    store: {
        ...storeStock,
        heroSlides: [
            storeStock.hero,
            storeStock.catalog,
            storeStock.payments,
        ],
    },
    services: {
        ...servicesStock,
        heroSlides: [
            servicesStock.hero,
            servicesStock.install,
            servicesStock.office,
        ],
    },
};

export const buildPublicCatalogTheme = ({ accent, variant = 'store' } = {}) => {
    const isStore = variant === 'store';
    const primaryColor = String(accent || '').trim() || (isStore ? '#15803d' : '#b45309');

    return {
        primary_color: primaryColor,
        primary_soft_color: isStore ? '#dcfce7' : '#fef3c7',
        primary_contrast_color: '#ffffff',
        background_color: '#ffffff',
        background_alt_color: isStore ? '#f5fdf7' : '#fffaf1',
        surface_color: '#ffffff',
        text_color: '#0f172a',
        muted_color: '#475569',
        border_color: '#dbe4ee',
        radius: 'xl',
        shadow: 'soft',
        button_style: 'solid',
    };
};

const storeFulfillmentSummary = (fulfillment, locale) => {
    const deliveryEnabled = Boolean(fulfillment?.delivery_enabled);
    const pickupEnabled = Boolean(fulfillment?.pickup_enabled);

    if (locale === 'fr') {
        if (deliveryEnabled && pickupEnabled) {
            return 'Livraison et retrait rapide disponibles depuis la meme page.';
        }
        if (deliveryEnabled) {
            return 'Livraison disponible depuis le panier public.';
        }
        if (pickupEnabled) {
            return 'Retrait rapide disponible avec preparation planifiee.';
        }

        return 'Panier, resume et validation restent prets a l activation du fulfillment.';
    }

    if (deliveryEnabled && pickupEnabled) {
        return 'Delivery and quick pickup are both available from the same page.';
    }
    if (deliveryEnabled) {
        return 'Delivery is available directly from the public cart.';
    }
    if (pickupEnabled) {
        return 'Quick pickup is available with scheduled preparation windows.';
    }

    return 'Cart, summary, and checkout stay ready as fulfillment is configured.';
};

const resolveShowcasePrimaryCta = ({ locale, requestUrl, phone, email, servicesHref = '#services' }) => {
    if (requestUrl) {
        return {
            label: locale === 'fr' ? 'Demander un devis' : 'Request a quote',
            href: requestUrl,
        };
    }

    if (phone) {
        return {
            label: locale === 'fr' ? 'Nous appeler' : 'Call us',
            href: `tel:${phone}`,
        };
    }

    if (email) {
        return {
            label: locale === 'fr' ? 'Envoyer un email' : 'Send an email',
            href: `mailto:${email}`,
        };
    }

    return {
        label: locale === 'fr' ? 'Voir les services' : 'See services',
        href: servicesHref,
    };
};

const buildStoreFeatureTabs = ({
    locale,
    productCount,
    categoryCount,
    bestSellerCount,
    promoCount,
    newArrivalCount,
    fulfillment,
    catalogHref,
    images = {},
}) => {
    const fulfillmentSummary = storeFulfillmentSummary(fulfillment, locale);
    const visuals = {
        discover: images.discover || storeStock.hero,
        compare: images.compare || storeStock.inventory,
        checkout: images.checkout || storeStock.checkout,
        fulfill: images.fulfill || storeStock.warehouse || storeStock.inventory,
    };

    if (locale === 'fr') {
        return [
            {
                id: 'store-flow-discover',
                label: 'Decouvrir',
                title: 'Orientez le client des la premiere visite',
                body: '<p>Le produit phare, les meilleures ventes et les categories visibles evitent la grille anonyme et donnent une vraie entree dans le catalogue.</p>',
                image_url: visuals.discover,
                image_alt: 'Collaborateur en entrepot avec une tablette et un clipboard',
                metric: countLabel(productCount, locale, 'produit actif', 'produits actifs'),
                items: [
                    countLabel(categoryCount, locale, 'categorie visible', 'categories visibles'),
                    countLabel(bestSellerCount, locale, 'best-seller mis en avant', 'best-sellers mis en avant'),
                    'Le scroll emmene ensuite directement vers le catalogue complet.',
                ],
                cta_label: 'Voir le catalogue',
                cta_href: catalogHref,
            },
            {
                id: 'store-flow-compare',
                label: 'Comparer',
                title: 'Laissez le client filtrer sans casser le rythme',
                body: '<p>Recherche, tri, categories et etat du stock restent lisibles pour aller du besoin a la bonne reference en quelques gestes.</p>',
                image_url: visuals.compare,
                image_alt: 'Pile de cartons prets a etre prepares dans un espace de stockage',
                metric: promoCount > 0
                    ? countLabel(promoCount, locale, 'promotion active', 'promotions actives')
                    : 'Catalogue pret a accueillir vos prochaines promotions',
                items: [
                    'Les filtres restent memorises pendant la navigation.',
                    'Les promotions et le stock faible restent visibles sans ouvrir chaque fiche.',
                    'Le produit pertinent remonte plus vite, meme sur mobile.',
                ],
                cta_label: 'Parcourir les categories',
                cta_href: '#categories',
            },
            {
                id: 'store-flow-checkout',
                label: 'Commander',
                title: 'Passez du catalogue au panier sans friction',
                body: '<p>L ajout rapide, le recapitulatif et la creation de compte client permettent de transformer la visite en commande suivie.</p>',
                image_url: visuals.checkout,
                image_alt: 'Paiement sur terminal au moment du passage en caisse',
                metric: newArrivalCount > 0
                    ? countLabel(newArrivalCount, locale, 'nouvel arrivage visible', 'nouveaux arrivages visibles')
                    : 'Panier et validation toujours a portee de main',
                items: [
                    'Chaque carte produit peut ouvrir la fiche detail ou alimenter le panier.',
                    'Le recapitulatif reste coherent avec promotions, taxes et quantites.',
                    'Le compte client est cree pour suivre la commande ensuite.',
                ],
                cta_label: 'Aller au panier et au checkout',
                cta_href: catalogHref,
            },
            {
                id: 'store-flow-fulfillment',
                label: 'Recevoir',
                title: 'Gardez la promesse de service jusqu a la remise',
                body: `<p>${fulfillmentSummary}</p>`,
                image_url: visuals.fulfill,
                image_alt: 'Colis ranges et prets pour la remise ou la livraison',
                metric: fulfillmentSummary,
                items: [
                    'Le client garde le contexte de sa commande sans sortir de la page.',
                    'Livraison, retrait et notes speciales vivent dans le meme flux.',
                    'Le panier reste utile meme quand le fulfillment evolue encore.',
                ],
                cta_label: 'Voir les produits disponibles',
                cta_href: catalogHref,
            },
        ];
    }

    return [
        {
            id: 'store-flow-discover',
            label: 'Discover',
            title: 'Guide shoppers from the very first visit',
            body: '<p>The featured product, top sellers, and visible categories turn the storefront into a guided path instead of a flat product wall.</p>',
            image_url: visuals.discover,
            image_alt: 'Warehouse team member holding a clipboard and tablet',
            metric: countLabel(productCount, locale, 'active product', 'active products'),
            items: [
                countLabel(categoryCount, locale, 'visible category', 'visible categories'),
                countLabel(bestSellerCount, locale, 'highlighted best seller', 'highlighted best sellers'),
                'The page then hands shoppers straight to the full catalog.',
            ],
            cta_label: 'View the catalog',
            cta_href: catalogHref,
        },
        {
            id: 'store-flow-compare',
            label: 'Compare',
            title: 'Let buyers filter without losing momentum',
            body: '<p>Search, sorting, categories, and stock status stay clear so buyers can move from need to item quickly.</p>',
            image_url: visuals.compare,
            image_alt: 'Stack of packaged boxes ready for fulfillment',
            metric: promoCount > 0
                ? countLabel(promoCount, locale, 'active promotion', 'active promotions')
                : 'The catalog is ready for the next promotion drop',
            items: [
                'Filters stay persisted while customers browse.',
                'Promotions and low-stock cues stay visible without opening every card.',
                'The right item surfaces faster, especially on mobile.',
            ],
            cta_label: 'Browse categories',
            cta_href: '#categories',
        },
        {
            id: 'store-flow-checkout',
            label: 'Checkout',
            title: 'Move from catalog to cart without friction',
            body: '<p>Quick add, a clear summary, and client account creation help turn browsing into trackable orders.</p>',
            image_url: visuals.checkout,
            image_alt: 'Card payment on a checkout terminal',
            metric: newArrivalCount > 0
                ? countLabel(newArrivalCount, locale, 'new arrival visible', 'new arrivals visible')
                : 'Cart and checkout remain within reach',
            items: [
                'Every product card can open details or feed the cart immediately.',
                'The summary stays aligned with promotions, taxes, and quantities.',
                'A client account is created so order tracking continues after purchase.',
            ],
            cta_label: 'Open catalog and cart',
            cta_href: catalogHref,
        },
        {
            id: 'store-flow-fulfillment',
            label: 'Fulfill',
            title: 'Keep the service promise through handoff',
            body: `<p>${fulfillmentSummary}</p>`,
            image_url: visuals.fulfill,
            image_alt: 'Prepared boxes ready for pickup or delivery',
            metric: fulfillmentSummary,
            items: [
                'Customers keep the order context without leaving the page.',
                'Delivery, pickup, and special notes stay inside the same flow.',
                'The cart remains useful even while fulfillment evolves.',
            ],
            cta_label: 'See available products',
            cta_href: catalogHref,
        },
    ];
};

const buildShowcaseFeatureTabs = ({
    locale,
    serviceCount,
    categoryCount,
    requestUrl,
    phone,
    email,
    servicesHref,
    images = {},
}) => {
    const primaryCta = resolveShowcasePrimaryCta({
        locale,
        requestUrl,
        phone,
        email,
        servicesHref,
    });
    const visuals = {
        qualify: images.qualify || servicesStock.planning,
        plan: images.plan || servicesStock.workflow,
        deliver: images.deliver || servicesStock.install,
        follow: images.follow || servicesStock.field,
    };

    if (locale === 'fr') {
        return [
            {
                id: 'service-flow-qualify',
                label: 'Qualifier',
                title: 'Captez un besoin clair avant meme l intervention',
                body: '<p>Le visiteur comprend rapidement ce que vous faites, sur quels types de demandes vous intervenez et comment vous contacter sans chercher ailleurs.</p>',
                image_url: visuals.qualify,
                image_alt: 'Equipe qui relit des informations sur une tablette',
                metric: countLabel(serviceCount, locale, 'service visible', 'services visibles'),
                items: [
                    countLabel(categoryCount, locale, 'categorie de service', 'categories de service'),
                    'Le service phare donne un point d entree concret.',
                    'La demande de devis reste accessible des le hero et dans le corps de page.',
                ],
                cta_label: primaryCta.label,
                cta_href: primaryCta.href,
            },
            {
                id: 'service-flow-plan',
                label: 'Planifier',
                title: 'Montrez que la demande avance avec un vrai process',
                body: '<p>La page n est plus une simple liste. Elle raconte la prise de besoin, la preparation et la mise en mouvement des equipes.</p>',
                image_url: visuals.plan,
                image_alt: 'Professionnels qui valident un plan de travail',
                metric: 'Une narration plus claire entre demande, preparation et intervention',
                items: [
                    'Les categories structurent la lecture au lieu de l alourdir.',
                    'Les descriptions deviennent plus utiles pour choisir le bon service.',
                    'Le CTA de contact revient au bon moment sans saturer la page.',
                ],
                cta_label: 'Voir les services',
                cta_href: servicesHref,
            },
            {
                id: 'service-flow-deliver',
                label: 'Intervenir',
                title: 'Ancrez la page dans la realite du terrain',
                body: '<p>Les visuels UHD libres de droits renforcent la credibilite metier pendant que les cartes services gardent le detail utile pour vendre.</p>',
                image_url: visuals.deliver,
                image_alt: 'Technicien en intervention sur une installation interieure',
                metric: 'Execution terrain mise en avant avec un contexte metier plus credible',
                items: [
                    'Le visiteur voit a la fois le contexte humain et l offre detaillee.',
                    'Les cartes conservent le prix, la categorie et le point d action.',
                    'Le recit editorial introduit les preuves avant la grille.',
                ],
                cta_label: primaryCta.label,
                cta_href: primaryCta.href,
            },
            {
                id: 'service-flow-follow',
                label: 'Suivre',
                title: 'Gardez un point de contact clair jusqu a la prochaine etape',
                body: '<p>Qu il s agisse d un devis, d un appel ou d un email, la page finit avec un chemin evident vers l echange.</p>',
                image_url: visuals.follow,
                image_alt: 'Technicien terrain avec une checklist avant intervention',
                metric: requestUrl
                    ? 'La demande de devis reste prioritaire sur toute la page'
                    : 'Telephone et email restent relies a la page service',
                items: [
                    'Le contact ne disparait jamais derriere la grille.',
                    'Les visiteurs peuvent revenir a la liste ou poursuivre la prise de contact.',
                    'La page reste aussi lisible sur mobile que sur desktop.',
                ],
                cta_label: primaryCta.label,
                cta_href: primaryCta.href,
            },
        ];
    }

    return [
        {
            id: 'service-flow-qualify',
            label: 'Qualify',
            title: 'Capture a clear need before the visit even starts',
            body: '<p>Visitors quickly understand what you do, which requests you handle, and how to contact you without leaving the page.</p>',
            image_url: visuals.qualify,
            image_alt: 'Team reviewing details on a tablet outside',
            metric: countLabel(serviceCount, locale, 'visible service', 'visible services'),
            items: [
                countLabel(categoryCount, locale, 'service category', 'service categories'),
                'The featured service gives visitors a concrete starting point.',
                'Quote requests stay accessible from the hero and throughout the page.',
            ],
            cta_label: primaryCta.label,
            cta_href: primaryCta.href,
        },
        {
            id: 'service-flow-plan',
            label: 'Plan',
            title: 'Show that a request moves through a real process',
            body: '<p>The page is no longer a flat list. It tells the story from intake to preparation to field execution.</p>',
            image_url: visuals.plan,
            image_alt: 'Professionals reviewing a work plan together',
            metric: 'A clearer narrative between request, planning, and delivery',
            items: [
                'Categories organize the page instead of weighing it down.',
                'Descriptions become more useful when picking the right service.',
                'The contact CTA comes back at the right moments without taking over.',
            ],
            cta_label: 'See services',
            cta_href: servicesHref,
        },
        {
            id: 'service-flow-deliver',
            label: 'Deliver',
            title: 'Anchor the page in real field work',
            body: '<p>Royalty-free UHD visuals add industry credibility while service cards keep the useful selling detail.</p>',
            image_url: visuals.deliver,
            image_alt: 'Technician performing an indoor installation',
            metric: 'Field execution is framed with stronger service context',
            items: [
                'Visitors see both the human context and the detailed offer.',
                'Cards still carry pricing, category, and the next action.',
                'The editorial story introduces proof before the grid appears.',
            ],
            cta_label: primaryCta.label,
            cta_href: primaryCta.href,
        },
        {
            id: 'service-flow-follow',
            label: 'Follow up',
            title: 'Keep the next contact step obvious all the way through',
            body: '<p>Whether the goal is a quote, call, or email, the page closes with a clear path to continue the conversation.</p>',
            image_url: visuals.follow,
            image_alt: 'Field technician holding a checklist before a visit',
            metric: requestUrl
                ? 'Quote requests remain primary throughout the page'
                : 'Phone and email stay tied directly to the service page',
            items: [
                'Contact never disappears behind the service grid.',
                'Visitors can jump back to the list or continue reaching out.',
                'The page stays readable on both mobile and desktop.',
            ],
            cta_label: primaryCta.label,
            cta_href: primaryCta.href,
        },
    ];
};

export const buildStorePublicSections = ({
    locale,
    companyName,
    heroProduct,
    heroImages = [],
    categories,
    products,
    bestSellers,
    promotions,
    newArrivals,
    fulfillment,
    catalogHref = '#catalog',
    categoriesHref = '#categories',
    bestSellersHref = '#best-sellers',
} = {}) => {
    const resolvedLocale = normalizeLocale(locale);
    const productCount = toCount(products?.length);
    const categoryCount = toCount(categories?.length);
    const bestSellerCount = toCount(bestSellers?.length);
    const promoCount = toCount(promotions?.length);
    const newArrivalCount = toCount(newArrivals?.length);
    const featuredName = firstNonEmpty(heroProduct?.name);
    const featuredCategory = firstNonEmpty(heroProduct?.category_name);
    const featuredImage = firstNonEmpty(heroProduct?.image_url, storeStock.hero);
    const fulfillmentSummary = storeFulfillmentSummary(fulfillment, resolvedLocale);
    const pickStoreImage = createUniqueImagePicker({
        excluded: [...normalizeImageCandidates(heroImages), featuredImage],
    });
    const storeVisuals = {
        intro: pickStoreImage(storeStock.warehouse, storeStock.catalog, storeStock.planning, storeStock.desk),
        introAside: pickStoreImage(storeStock.payments, storeStock.desk, storeStock.tablet, storeStock.catalog),
        flowDiscover: pickStoreImage(storeStock.catalog, storeStock.warehouse, storeStock.tablet, storeStock.planning),
        flowCompare: pickStoreImage(storeStock.planning, storeStock.warehouse, storeStock.desk, storeStock.catalog),
        flowCheckout: pickStoreImage(storeStock.payments, storeStock.tablet, storeStock.desk, storeStock.catalog),
        flowFulfill: pickStoreImage(storeStock.warehouse, storeStock.planning, storeStock.catalog, storeStock.desk),
        storyConfidence: pickStoreImage(storeStock.tablet, storeStock.catalog, storeStock.desk, storeStock.planning),
        storyPromos: pickStoreImage(storeStock.payments, storeStock.desk, storeStock.tablet, storeStock.catalog),
        storyFulfillment: pickStoreImage(storeStock.warehouse, storeStock.planning, storeStock.catalog, storeStock.desk),
        showcase: pickStoreImage(storeStock.catalog, storeStock.warehouse, storeStock.tablet, storeStock.planning),
        showcaseAside: pickStoreImage(storeStock.desk, storeStock.payments, storeStock.tablet, storeStock.catalog),
    };

    if (resolvedLocale === 'fr') {
        return [
            {
                layout: 'feature_pairs',
                background_color: '#ffffff',
                kicker: 'Page produits repensee',
                title: `La boutique ${companyName || 'produits'} suit maintenant le meme rythme que la refonte publique.`,
                body: `<p>On demarre par une promesse claire, on montre le contexte d achat, puis on laisse les blocs ecommerce existants faire le travail. Vous avez ${productCount} produits actifs, ${categoryCount} categories et une entree directe vers les meilleures ventes, les promos et le catalogue complet.</p>`,
                items: [
                    bestSellerCount > 0
                        ? countLabel(bestSellerCount, resolvedLocale, 'selection rassurante', 'selections rassurantes')
                        : 'Le bloc meilleures ventes est pret a guider les achats recurrents.',
                    promoCount > 0
                        ? countLabel(promoCount, resolvedLocale, 'promotion visible', 'promotions visibles')
                        : 'Les promos apparaitront ici des qu elles seront actives.',
                    fulfillmentSummary,
                ],
                primary_label: 'Voir le catalogue',
                primary_href: catalogHref,
                secondary_label: 'Voir les categories',
                secondary_href: categoriesHref,
                image_url: storeVisuals.intro,
                image_alt: 'Collaborateur logistique dans un espace de preparation',
                aside_kicker: 'Du choix a la validation',
                aside_title: 'Les actions utiles restent visibles sans casser le storytelling.',
                aside_body: '<p>Les sections reusables introduisent la page, puis la recherche, les filtres, le panier et le checkout prennent le relai au bon moment.</p>',
                aside_items: [
                    'Produit phare visible des l ouverture.',
                    'Ajout rapide au panier depuis les cartes ou la fiche detail.',
                    'Livraison, retrait et recapitulatif disponibles dans le meme flux.',
                ],
                aside_link_label: 'Aller vers les meilleures ventes',
                aside_link_href: bestSellersHref,
                aside_image_url: storeVisuals.introAside,
                aside_image_alt: 'Paiement sur terminal pour finaliser une commande',
            },
            {
                layout: 'feature_tabs',
                background_color: '#f8fafc',
                kicker: 'Parcours d achat',
                title: 'Passez de la decouverte a la commande sans retomber dans une simple grille.',
                body: '<p>Cet accordeon reprend le flux souhaite dans le doc de refonte: une narration courte, des preuves concretes, puis un passage naturel vers les cartes produit et le panier.</p>',
                feature_tabs_style: 'workflow',
                primary_label: 'Explorer les produits',
                primary_href: catalogHref,
                feature_tabs: buildStoreFeatureTabs({
                    locale: resolvedLocale,
                    productCount,
                    categoryCount,
                    bestSellerCount,
                    promoCount,
                    newArrivalCount,
                    fulfillment,
                    catalogHref,
                    images: {
                        discover: storeVisuals.flowDiscover,
                        compare: storeVisuals.flowCompare,
                        checkout: storeVisuals.flowCheckout,
                        fulfill: storeVisuals.flowFulfill,
                    },
                }),
            },
            {
                layout: 'story_grid',
                background_color: '#ffffff',
                kicker: 'Preuves visibles',
                title: 'Ce que la nouvelle structure rend plus clair pour vos clients.',
                body: '<p>Avant meme de scroller dans le catalogue, la page explique ce qui est disponible, ce qui est populaire et comment la commande sera remise.</p>',
                primary_label: 'Ouvrir le catalogue',
                primary_href: catalogHref,
                story_cards: [
                    {
                        id: 'store-story-bestsellers',
                        title: 'Des reperes avant le premier clic',
                        body: `<p>${bestSellerCount > 0 ? `${countLabel(bestSellerCount, resolvedLocale, 'best-seller remonte', 'best-sellers remontent')} en tete de page pour rassurer l achat.` : 'Le bloc meilleures ventes est deja pret pour guider les achats recurrents.'}</p>`,
                        image_url: storeVisuals.storyConfidence,
                        image_alt: 'Cartons de produits prets a etre prepares',
                    },
                    {
                        id: 'store-story-promos',
                        title: 'Les offres actives restent immediatement lisibles',
                        body: `<p>${promoCount > 0 ? `${countLabel(promoCount, resolvedLocale, 'promo active reste visible', 'promotions actives restent visibles')} sans devoir ouvrir chaque fiche produit.` : 'Les badges de prix et de promo gardent la lecture simple des que des offres sont actives.'}</p>`,
                        image_url: storeVisuals.storyPromos,
                        image_alt: 'Paiement sur terminal au comptoir',
                    },
                    {
                        id: 'store-story-fulfillment',
                        title: 'La promesse continue apres le panier',
                        body: `<p>${fulfillmentSummary}</p>`,
                        image_url: storeVisuals.storyFulfillment,
                        image_alt: 'Operateur logistique pret a preparer une commande',
                    },
                ],
            },
            {
                layout: 'showcase_cta',
                background_preset: 'midnight-cobalt',
                tone: 'contrast',
                kicker: 'Pret a convertir',
                title: 'Le storytelling introduit la page, puis le catalogue prend le relai pour vendre.',
                body: `<p>${featuredName ? `Le produit phare du moment est ${featuredName}${featuredCategory ? ` dans ${featuredCategory}` : ''}. ` : ''}Les filtres, le panier et le checkout restent accessibles sans casser le parcours.</p>`,
                primary_label: 'Voir tous les produits',
                primary_href: catalogHref,
                secondary_label: bestSellerCount > 0 ? 'Commencer par les meilleures ventes' : 'Voir les categories',
                secondary_href: bestSellerCount > 0 ? bestSellersHref : categoriesHref,
                image_url: storeVisuals.showcase,
                image_alt: featuredName || 'Produit phare de la boutique',
                aside_image_url: storeVisuals.showcaseAside,
                aside_image_alt: 'Paiement en fin de parcours d achat',
                aside_link_label: 'Acces direct au catalogue',
                aside_link_href: catalogHref,
            },
        ];
    }

    return [
        {
            layout: 'feature_pairs',
            background_color: '#ffffff',
            kicker: 'Redesigned product page',
            title: `${companyName || 'This storefront'} now follows the same public redesign rhythm.`,
            body: `<p>The page opens with a clear promise, shows the buying context, and then hands off to the existing ecommerce blocks. You currently have ${productCount} active products, ${categoryCount} categories, and a direct path to best sellers, promotions, and the full catalog.</p>`,
            items: [
                bestSellerCount > 0
                    ? countLabel(bestSellerCount, resolvedLocale, 'confidence-building selection', 'confidence-building selections')
                    : 'The best seller block is ready to guide repeat buying.',
                promoCount > 0
                    ? countLabel(promoCount, resolvedLocale, 'promotion in view', 'promotions in view')
                    : 'Promotions will surface here as soon as they are active.',
                fulfillmentSummary,
            ],
            primary_label: 'View the catalog',
            primary_href: catalogHref,
            secondary_label: 'Browse categories',
            secondary_href: categoriesHref,
            image_url: storeVisuals.intro,
            image_alt: 'Logistics team member inside a fulfillment space',
            aside_kicker: 'From browse to checkout',
            aside_title: 'Useful actions stay visible without breaking the page story.',
            aside_body: '<p>Reusable sections introduce the page, then search, filters, cart, and checkout take over exactly when shoppers need them.</p>',
            aside_items: [
                'The featured product stays visible right away.',
                'Quick add to cart works from cards and detail views.',
                'Delivery, pickup, and order summary stay inside the same flow.',
            ],
            aside_link_label: 'Jump to best sellers',
            aside_link_href: bestSellersHref,
            aside_image_url: storeVisuals.introAside,
            aside_image_alt: 'Checkout payment terminal ready to close an order',
        },
        {
            layout: 'feature_tabs',
            background_color: '#f8fafc',
            kicker: 'Buying journey',
            title: 'Move from discovery to checkout without falling back into a flat grid.',
            body: '<p>This accordion mirrors the redesign brief: a short narrative, concrete proof, and then a natural handoff to product cards and the cart.</p>',
            feature_tabs_style: 'workflow',
            primary_label: 'Explore products',
            primary_href: catalogHref,
            feature_tabs: buildStoreFeatureTabs({
                locale: resolvedLocale,
                productCount,
                categoryCount,
                bestSellerCount,
                promoCount,
                newArrivalCount,
                fulfillment,
                catalogHref,
                images: {
                    discover: storeVisuals.flowDiscover,
                    compare: storeVisuals.flowCompare,
                    checkout: storeVisuals.flowCheckout,
                    fulfill: storeVisuals.flowFulfill,
                },
            }),
        },
        {
            layout: 'story_grid',
            background_color: '#ffffff',
            kicker: 'Visible proof',
            title: 'What the new structure makes clearer for shoppers.',
            body: '<p>Before anyone reaches the catalog, the page already explains what is available, what is popular, and how the order will be fulfilled.</p>',
            primary_label: 'Open the catalog',
            primary_href: catalogHref,
            story_cards: [
                {
                    id: 'store-story-bestsellers',
                    title: 'A clear starting point before the first click',
                    body: `<p>${bestSellerCount > 0 ? `${countLabel(bestSellerCount, resolvedLocale, 'best seller rises', 'best sellers rise')} to the top of the page to build trust faster.` : 'The best seller block is already ready to guide repeat buying.'}</p>`,
                    image_url: storeVisuals.storyConfidence,
                    image_alt: 'Packed boxes ready for fulfillment',
                },
                {
                    id: 'store-story-promos',
                    title: 'Active offers stay easy to scan',
                    body: `<p>${promoCount > 0 ? `${countLabel(promoCount, resolvedLocale, 'active offer stays visible', 'active offers stay visible')} without opening every product detail.` : 'Price and promotion badges keep the page readable as offers come online.'}</p>`,
                    image_url: storeVisuals.storyPromos,
                    image_alt: 'Card payment during checkout',
                },
                {
                    id: 'store-story-fulfillment',
                    title: 'The promise continues after the cart',
                    body: `<p>${fulfillmentSummary}</p>`,
                    image_url: storeVisuals.storyFulfillment,
                    image_alt: 'Fulfillment operator ready to prepare an order',
                },
            ],
        },
        {
            layout: 'showcase_cta',
            background_preset: 'midnight-cobalt',
            tone: 'contrast',
            kicker: 'Ready to convert',
            title: 'The story introduces the page, then the catalog takes over to sell.',
            body: `<p>${featuredName ? `The current featured product is ${featuredName}${featuredCategory ? ` in ${featuredCategory}` : ''}. ` : ''}Filters, cart, and checkout remain accessible without breaking the path.</p>`,
            primary_label: 'View all products',
            primary_href: catalogHref,
            secondary_label: bestSellerCount > 0 ? 'Start with best sellers' : 'Browse categories',
            secondary_href: bestSellerCount > 0 ? bestSellersHref : categoriesHref,
            image_url: storeVisuals.showcase,
            image_alt: featuredName || 'Featured storefront product',
            aside_image_url: storeVisuals.showcaseAside,
            aside_image_alt: 'Checkout payment terminal at the end of the buying journey',
            aside_link_label: 'Direct access to the catalog',
            aside_link_href: catalogHref,
        },
    ];
};

export const buildShowcasePublicSections = ({
    locale,
    companyName,
    companyLocation,
    heroService,
    heroImages = [],
    services,
    categories,
    requestUrl,
    phone,
    email,
    servicesHref = '#services',
} = {}) => {
    const resolvedLocale = normalizeLocale(locale);
    const serviceCount = toCount(services?.length);
    const categoryCount = toCount(categories?.length);
    const primaryCta = resolveShowcasePrimaryCta({
        locale: resolvedLocale,
        requestUrl,
        phone,
        email,
        servicesHref,
    });
    const secondaryCta = requestUrl
        ? {
            label: resolvedLocale === 'fr' ? 'Voir les services' : 'See services',
            href: servicesHref,
        }
        : {
            label: resolvedLocale === 'fr' ? 'Revenir a la liste' : 'Back to the list',
            href: servicesHref,
        };
    const featuredName = firstNonEmpty(heroService?.name);
    const featuredCategory = firstNonEmpty(heroService?.category_name);
    const featuredImage = firstNonEmpty(heroService?.image_url, servicesStock.hero);
    const locationLine = firstNonEmpty(companyLocation);
    const pickServiceImage = createUniqueImagePicker({
        excluded: [...normalizeImageCandidates(heroImages), featuredImage],
    });
    const serviceVisuals = {
        intro: pickServiceImage(servicesStock.office, servicesStock.meeting, servicesStock.collab, servicesStock.desk),
        introAside: pickServiceImage(servicesStock.field, servicesStock.workflow, servicesStock.tabletHero, servicesStock.collab),
        flowQualify: pickServiceImage(servicesStock.collab, servicesStock.office, servicesStock.meeting, servicesStock.desk),
        flowPlan: pickServiceImage(servicesStock.workflow, servicesStock.meeting, servicesStock.tabletHero, servicesStock.collab),
        flowDeliver: pickServiceImage(servicesStock.field, servicesStock.office, servicesStock.meeting, servicesStock.desk),
        flowFollow: pickServiceImage(servicesStock.tabletHero, servicesStock.desk, servicesStock.office, servicesStock.collab),
        storyFeatured: pickServiceImage(servicesStock.meeting, servicesStock.collab, servicesStock.office, servicesStock.desk),
        storyField: pickServiceImage(servicesStock.field, servicesStock.workflow, servicesStock.tabletHero, servicesStock.office),
        storyContact: pickServiceImage(servicesStock.desk, servicesStock.tabletHero, servicesStock.office, servicesStock.collab),
        showcase: pickServiceImage(servicesStock.office, servicesStock.collab, servicesStock.meeting, servicesStock.desk),
        showcaseAside: pickServiceImage(servicesStock.tabletHero, servicesStock.workflow, servicesStock.collab, servicesStock.desk),
        contact: pickServiceImage(servicesStock.office, servicesStock.meeting, servicesStock.collab, servicesStock.desk),
    };

    if (resolvedLocale === 'fr') {
        return {
            intro: [
                {
                    layout: 'feature_pairs',
                    background_color: '#ffffff',
                    kicker: 'Page services refondue',
                    title: `${companyName || 'Votre entreprise'} presente maintenant ses services avec le meme langage visuel que la home.`,
                    body: `<p>La page introduit d abord la promesse, relie cette promesse a un vrai contexte terrain, puis laisse la grille services prendre le relai. ${locationLine ? `${companyName || 'L equipe'} opere depuis ${locationLine}. ` : ''}Vous avez actuellement ${serviceCount} services visibles sur ${categoryCount} categories.</p>`,
                    items: [
                        featuredName
                            ? `Service phare visible: ${featuredName}${featuredCategory ? ` (${featuredCategory})` : ''}.`
                            : 'Le hero met en avant le premier service disponible.',
                        requestUrl
                            ? 'Le devis reste accessible sans quitter la page.'
                            : 'Telephone et email restent relies au parcours public.',
                        'Les sections editoriales introduisent la preuve avant la grille de cartes.',
                    ],
                    primary_label: primaryCta.label,
                    primary_href: primaryCta.href,
                    secondary_label: secondaryCta.label,
                    secondary_href: secondaryCta.href,
                    image_url: serviceVisuals.intro,
                    image_alt: 'Equipe service en coordination sur site',
                    aside_kicker: 'Contexte metier',
                    aside_title: 'Une page plus credible pour le bureau comme pour le terrain.',
                    aside_body: '<p>Le recit editorial cadre la demande, les visuels donnent de la matiere metier, puis la grille services apporte le detail necessaire pour convertir.</p>',
                    aside_items: [
                        'Le hero garde le service phare comme preuve concrete.',
                        'Les categories deviennent une vraie navigation.',
                        'Le CTA principal revient dans les moments qui comptent.',
                    ],
                    aside_link_label: 'Voir tous les services',
                    aside_link_href: servicesHref,
                    aside_image_url: serviceVisuals.introAside,
                    aside_image_alt: 'Technicien en intervention sur une installation',
                },
                {
                    layout: 'feature_tabs',
                    background_color: '#f8fafc',
                    kicker: 'Flux de service',
                    title: 'Structurez la page comme un parcours clair entre besoin, planification et intervention.',
                    body: '<p>Cet accordeon reutilise les blocs de la refonte pour montrer un vrai flux de travail au lieu d une suite de cartes sans hierarchie.</p>',
                    feature_tabs_style: 'workflow',
                    primary_label: primaryCta.label,
                    primary_href: primaryCta.href,
                    feature_tabs: buildShowcaseFeatureTabs({
                        locale: resolvedLocale,
                        serviceCount,
                        categoryCount,
                        requestUrl,
                        phone,
                        email,
                        servicesHref,
                        images: {
                            qualify: serviceVisuals.flowQualify,
                            plan: serviceVisuals.flowPlan,
                            deliver: serviceVisuals.flowDeliver,
                            follow: serviceVisuals.flowFollow,
                        },
                    }),
                },
                {
                    layout: 'story_grid',
                    background_color: '#ffffff',
                    kicker: 'Moments qui rassurent',
                    title: 'Ce que la nouvelle mise en page clarifie avant meme la liste des services.',
                    body: '<p>Chaque bloc ajoute du contexte metier, puis la grille conserve le detail pratique pour choisir et demander un devis.</p>',
                    primary_label: 'Parcourir les services',
                    primary_href: servicesHref,
                    story_cards: [
                        {
                            id: 'showcase-story-featured',
                            title: 'Un point d entree concret',
                            body: `<p>${featuredName ? `Le hero ancre la page autour de ${featuredName}${featuredCategory ? ` dans ${featuredCategory}` : ''}, ce qui donne tout de suite un exemple reel du niveau de service.` : 'Le service mis en avant donne tout de suite un exemple concret du travail propose.'}</p>`,
                            image_url: serviceVisuals.storyFeatured,
                            image_alt: 'Equipe service qui echange autour d une tablette',
                        },
                        {
                            id: 'showcase-story-field',
                            title: 'Le terrain reste visible',
                            body: '<p>Les images UHD libres de droits montrent l intervention, la preparation et la coordination, ce qui rend la page plus credible pour les metiers de service.</p>',
                            image_url: serviceVisuals.storyField,
                            image_alt: 'Technicien en cours d installation',
                        },
                        {
                            id: 'showcase-story-contact',
                            title: 'Le prochain geste reste evident',
                            body: `<p>${requestUrl ? 'Le devis reste a un clic tout au long de la page.' : 'Les canaux de contact restent visibles pour poursuivre l echange sans friction.'}</p>`,
                            image_url: serviceVisuals.storyContact,
                            image_alt: 'Equipe terrain en discussion sur site',
                        },
                    ],
                },
                {
                    layout: 'showcase_cta',
                    background_preset: 'deep-ocean',
                    tone: 'contrast',
                    kicker: 'Pret a lancer une demande',
                    title: 'Le contexte metier rassure, puis la page services convertit.',
                    body: `<p>${featuredName ? `Le service phare ${featuredName} donne une preuve immediate, pendant que la liste complete reste accessible juste en dessous.` : 'Le service phare donne une preuve immediate, pendant que la liste complete reste accessible juste en dessous.'}</p>`,
                    primary_label: primaryCta.label,
                    primary_href: primaryCta.href,
                    secondary_label: secondaryCta.label,
                    secondary_href: secondaryCta.href,
                    image_url: serviceVisuals.showcase,
                    image_alt: featuredName || 'Service phare presente en vitrine',
                    aside_image_url: serviceVisuals.showcaseAside,
                    aside_image_alt: 'Equipe de service en echange autour d une tablette',
                    aside_link_label: 'Voir la liste complete',
                    aside_link_href: servicesHref,
                },
            ],
            contact: [
                {
                    layout: 'contact',
                    background_color: '#ffffff',
                    kicker: 'Contact',
                    title: 'Parlez-nous de votre projet avec un canal simple et direct.',
                    body: '<p>La page garde le meme format que la refonte, puis se ferme avec un bloc de contact reutilisable pour aider le visiteur a passer a l action.</p>',
                    items: [
                        phone ? `Telephone: ${phone}` : 'Telephone: a confirmer',
                        email ? `Email: ${email}` : 'Email: a confirmer',
                        locationLine ? `Zone: ${locationLine}` : 'Zone: informations de localisation a venir',
                    ],
                    primary_label: primaryCta.label,
                    primary_href: primaryCta.href,
                    secondary_label: 'Revenir aux services',
                    secondary_href: servicesHref,
                    image_url: serviceVisuals.contact,
                    image_alt: 'Equipe de service en coordination',
                    aside_kicker: 'Prochaine etape',
                    aside_title: 'Un bloc de contact plus utile que la simple repetition du hero.',
                    aside_body: '<p>Ce dernier bloc rappelle le bon canal de contact, tout en gardant la meme grammaire visuelle que les autres pages publiques.</p>',
                    aside_items: [
                        requestUrl ? 'Le lien de devis reste prioritaire.' : 'Le meilleur canal de contact reste mis en avant.',
                        'Le visiteur peut revenir a la liste complete sans perdre le contexte.',
                        'Le bloc reste lisible sur mobile grace a la meme structure reusable.',
                    ],
                    aside_link_label: primaryCta.label,
                    aside_link_href: primaryCta.href,
                },
            ],
        };
    }

    return {
        intro: [
            {
                layout: 'feature_pairs',
                background_color: '#ffffff',
                kicker: 'Redesigned service page',
                title: `${companyName || 'Your company'} now presents services with the same visual language as the homepage.`,
                body: `<p>The page now introduces the promise first, ties that promise to real field context, and then hands off to the service grid. ${locationLine ? `${companyName || 'The team'} operates from ${locationLine}. ` : ''}You currently have ${serviceCount} visible services across ${categoryCount} categories.</p>`,
                items: [
                    featuredName
                        ? `Featured service in view: ${featuredName}${featuredCategory ? ` (${featuredCategory})` : ''}.`
                        : 'The hero highlights the first available service.',
                    requestUrl
                        ? 'Quote requests stay accessible without leaving the page.'
                        : 'Phone and email stay connected to the public journey.',
                    'Editorial sections now introduce proof before the service cards.',
                ],
                primary_label: primaryCta.label,
                primary_href: primaryCta.href,
                secondary_label: secondaryCta.label,
                secondary_href: secondaryCta.href,
                image_url: serviceVisuals.intro,
                image_alt: 'Service team coordinating on site',
                aside_kicker: 'Industry context',
                aside_title: 'A page that feels more credible for both office teams and field crews.',
                aside_body: '<p>The editorial story frames the request, UHD visuals add industry texture, and the service grid still carries the detail needed to convert.</p>',
                aside_items: [
                    'The hero keeps the featured service as concrete proof.',
                    'Categories now act like real navigation.',
                    'The main CTA returns at the right moments without taking over.',
                ],
                aside_link_label: 'See all services',
                aside_link_href: servicesHref,
                aside_image_url: serviceVisuals.introAside,
                aside_image_alt: 'Technician performing an installation',
            },
            {
                layout: 'feature_tabs',
                background_color: '#f8fafc',
                kicker: 'Service flow',
                title: 'Structure the page like a clear path between need, planning, and field work.',
                body: '<p>This accordion reuses the redesign blocks to show a real workflow instead of a string of equal-weight cards.</p>',
                feature_tabs_style: 'workflow',
                primary_label: primaryCta.label,
                primary_href: primaryCta.href,
                feature_tabs: buildShowcaseFeatureTabs({
                    locale: resolvedLocale,
                    serviceCount,
                    categoryCount,
                    requestUrl,
                    phone,
                    email,
                    servicesHref,
                    images: {
                        qualify: serviceVisuals.flowQualify,
                        plan: serviceVisuals.flowPlan,
                        deliver: serviceVisuals.flowDeliver,
                        follow: serviceVisuals.flowFollow,
                    },
                }),
            },
            {
                layout: 'story_grid',
                background_color: '#ffffff',
                kicker: 'Confidence moments',
                title: 'What the new layout clarifies before the service list even starts.',
                body: '<p>Each block adds useful industry context, then the grid keeps the practical detail needed to choose and request a quote.</p>',
                primary_label: 'Browse services',
                primary_href: servicesHref,
                story_cards: [
                    {
                        id: 'showcase-story-featured',
                        title: 'A concrete starting point',
                        body: `<p>${featuredName ? `The hero anchors the page around ${featuredName}${featuredCategory ? ` in ${featuredCategory}` : ''}, giving visitors a real example of the service level right away.` : 'The featured service gives visitors a concrete example of the work on offer right away.'}</p>`,
                        image_url: serviceVisuals.storyFeatured,
                        image_alt: 'Service team reviewing a tablet outside',
                    },
                    {
                        id: 'showcase-story-field',
                        title: 'Field work stays visible',
                        body: '<p>Royalty-free UHD imagery shows planning, execution, and coordination, making the page feel more credible for service industries.</p>',
                        image_url: serviceVisuals.storyField,
                        image_alt: 'Technician performing an installation task',
                    },
                    {
                        id: 'showcase-story-contact',
                        title: 'The next action stays obvious',
                        body: `<p>${requestUrl ? 'The quote request remains one click away throughout the page.' : 'Contact channels stay visible so the conversation can continue without friction.'}</p>`,
                        image_url: serviceVisuals.storyContact,
                        image_alt: 'Field team discussing next steps on site',
                    },
                ],
            },
            {
                layout: 'showcase_cta',
                background_preset: 'deep-ocean',
                tone: 'contrast',
                kicker: 'Ready to start a request',
                title: 'Industry context builds trust, then the service page converts.',
                body: `<p>${featuredName ? `The featured service ${featuredName} gives immediate proof while the full list stays accessible right below.` : 'The featured service gives immediate proof while the full list stays accessible right below.'}</p>`,
                primary_label: primaryCta.label,
                primary_href: primaryCta.href,
                secondary_label: secondaryCta.label,
                secondary_href: secondaryCta.href,
                image_url: serviceVisuals.showcase,
                image_alt: featuredName || 'Featured service preview',
                aside_image_url: serviceVisuals.showcaseAside,
                aside_image_alt: 'Service team reviewing work details on a tablet',
                aside_link_label: 'Open the full list',
                aside_link_href: servicesHref,
            },
        ],
        contact: [
            {
                layout: 'contact',
                background_color: '#ffffff',
                kicker: 'Contact',
                title: 'Talk to us about your project through a clear, direct channel.',
                body: '<p>The page keeps the same redesign format and then closes with a reusable contact block that helps visitors take the next step.</p>',
                items: [
                    phone ? `Phone: ${phone}` : 'Phone: to be confirmed',
                    email ? `Email: ${email}` : 'Email: to be confirmed',
                    locationLine ? `Area: ${locationLine}` : 'Area: location details coming soon',
                ],
                primary_label: primaryCta.label,
                primary_href: primaryCta.href,
                secondary_label: 'Back to services',
                secondary_href: servicesHref,
                image_url: serviceVisuals.contact,
                image_alt: 'Service team coordinating together',
                aside_kicker: 'Next step',
                aside_title: 'A contact block that does more than repeat the hero.',
                aside_body: '<p>This final block surfaces the right contact channel while keeping the same reusable grammar as the rest of the public pages.</p>',
                aside_items: [
                    requestUrl ? 'The quote link stays primary.' : 'The strongest contact channel stays up front.',
                    'Visitors can return to the full list without losing context.',
                    'The block stays readable on mobile thanks to the shared reusable structure.',
                ],
                aside_link_label: primaryCta.label,
                aside_link_href: primaryCta.href,
            },
        ],
    };
};
