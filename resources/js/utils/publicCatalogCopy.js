const normalizeCatalogCopyLocale = (locale = 'fr') => {
    const value = String(locale || 'fr').toLowerCase();

    if (value.startsWith('fr')) {
        return 'fr';
    }

    if (value.startsWith('es')) {
        return 'es';
    }

    return 'en';
};

export const buildStoreFulfillmentSummaryCopy = ({ locale = 'fr', fulfillment = {} } = {}) => {
    const resolvedLocale = normalizeCatalogCopyLocale(locale);
    const deliveryEnabled = Boolean(fulfillment?.delivery_enabled);
    const pickupEnabled = Boolean(fulfillment?.pickup_enabled);

    if (resolvedLocale === 'fr') {
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

    if (resolvedLocale === 'es') {
        if (deliveryEnabled && pickupEnabled) {
            return 'Entrega y recogida rapida disponibles desde la misma pagina.';
        }
        if (deliveryEnabled) {
            return 'La entrega esta disponible directamente desde el carrito publico.';
        }
        if (pickupEnabled) {
            return 'La recogida rapida esta disponible con preparacion programada.';
        }

        return 'Carrito, resumen y checkout siguen listos mientras se configura el fulfillment.';
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

export const buildShowcasePrimaryCtaCopy = ({
    locale = 'fr',
    requestUrl,
    phone,
    email,
    servicesHref = '#services',
} = {}) => {
    const resolvedLocale = normalizeCatalogCopyLocale(locale);

    if (requestUrl) {
        return {
            label: resolvedLocale === 'fr'
                ? 'Demander un devis'
                : (resolvedLocale === 'es' ? 'Solicitar un presupuesto' : 'Request a quote'),
            href: requestUrl,
        };
    }

    if (phone) {
        return {
            label: resolvedLocale === 'fr'
                ? 'Nous appeler'
                : (resolvedLocale === 'es' ? 'Llamarnos' : 'Call us'),
            href: `tel:${phone}`,
        };
    }

    if (email) {
        return {
            label: resolvedLocale === 'fr'
                ? 'Envoyer un email'
                : (resolvedLocale === 'es' ? 'Enviar un email' : 'Send an email'),
            href: `mailto:${email}`,
        };
    }

    return {
        label: resolvedLocale === 'fr'
            ? 'Voir les services'
            : (resolvedLocale === 'es' ? 'Ver servicios' : 'See services'),
        href: servicesHref,
    };
};

export const buildShowcaseSecondaryCtaCopy = ({
    locale = 'fr',
    requestUrl,
    servicesHref = '#services',
} = {}) => {
    const resolvedLocale = normalizeCatalogCopyLocale(locale);

    if (requestUrl) {
        return {
            label: resolvedLocale === 'fr'
                ? 'Voir les services'
                : (resolvedLocale === 'es' ? 'Ver servicios' : 'See services'),
            href: servicesHref,
        };
    }

    return {
        label: resolvedLocale === 'fr'
            ? 'Revenir a la liste'
            : (resolvedLocale === 'es' ? 'Volver a la lista' : 'Back to the list'),
        href: servicesHref,
    };
};

export const buildStoreFeatureTabsCopy = ({
    locale = 'fr',
    productCount = 0,
    categoryCount = 0,
    bestSellerCount = 0,
    promoCount = 0,
    newArrivalCount = 0,
    fulfillmentSummary = '',
    catalogHref = '#catalog',
    images = {},
    countLabel,
} = {}) => {
    const resolvedLocale = normalizeCatalogCopyLocale(locale);
    const visuals = {
        discover: images.discover || '',
        compare: images.compare || '',
        checkout: images.checkout || '',
        fulfill: images.fulfill || '',
    };

    if (resolvedLocale === 'fr') {
        return [
            {
                id: 'store-flow-discover',
                label: 'Decouvrir',
                title: 'Orientez le client des la premiere visite',
                body: '<p>Le produit phare, les meilleures ventes et les categories visibles evitent la grille anonyme et donnent une vraie entree dans le catalogue.</p>',
                image_url: visuals.discover,
                image_alt: 'Collaborateur en entrepot avec une tablette et un clipboard',
                metric: countLabel(productCount, resolvedLocale, 'produit actif', 'produits actifs'),
                items: [
                    countLabel(categoryCount, resolvedLocale, 'categorie visible', 'categories visibles'),
                    countLabel(bestSellerCount, resolvedLocale, 'best-seller mis en avant', 'best-sellers mis en avant'),
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
                    ? countLabel(promoCount, resolvedLocale, 'promotion active', 'promotions actives')
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
                    ? countLabel(newArrivalCount, resolvedLocale, 'nouvel arrivage visible', 'nouveaux arrivages visibles')
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

    if (resolvedLocale === 'es') {
        return [
            {
                id: 'store-flow-discover',
                label: 'Descubrir',
                title: 'Orienta al cliente desde la primera visita',
                body: '<p>El producto destacado, los mas vendidos y las categorias visibles evitan una cuadricula anonima y dan una entrada real al catalogo.</p>',
                image_url: visuals.discover,
                image_alt: 'Colaborador en almacen con una tableta y un portapapeles',
                metric: countLabel(productCount, resolvedLocale, 'producto activo', 'productos activos'),
                items: [
                    countLabel(categoryCount, resolvedLocale, 'categoria visible', 'categorias visibles'),
                    countLabel(bestSellerCount, resolvedLocale, 'superventas destacado', 'superventas destacados'),
                    'El scroll lleva despues directamente al catalogo completo.',
                ],
                cta_label: 'Ver el catalogo',
                cta_href: catalogHref,
            },
            {
                id: 'store-flow-compare',
                label: 'Comparar',
                title: 'Deja que el cliente filtre sin romper el ritmo',
                body: '<p>Busqueda, orden, categorias y estado del stock siguen claros para pasar de la necesidad a la referencia correcta en pocos gestos.</p>',
                image_url: visuals.compare,
                image_alt: 'Pila de cajas listas para prepararse en la zona de almacen',
                metric: promoCount > 0
                    ? countLabel(promoCount, resolvedLocale, 'promocion activa', 'promociones activas')
                    : 'El catalogo esta listo para recibir tus proximas promociones',
                items: [
                    'Los filtros se mantienen durante la navegacion.',
                    'Las promociones y el bajo stock siguen visibles sin abrir cada ficha.',
                    'El producto adecuado aparece mas rapido, incluso en movil.',
                ],
                cta_label: 'Explorar categorias',
                cta_href: '#categories',
            },
            {
                id: 'store-flow-checkout',
                label: 'Comprar',
                title: 'Pasa del catalogo al carrito sin friccion',
                body: '<p>La adicion rapida, el resumen y la creacion de cuenta cliente permiten convertir la visita en un pedido seguido.</p>',
                image_url: visuals.checkout,
                image_alt: 'Pago con terminal en el momento del checkout',
                metric: newArrivalCount > 0
                    ? countLabel(newArrivalCount, resolvedLocale, 'nueva llegada visible', 'nuevas llegadas visibles')
                    : 'Carrito y checkout siempre al alcance',
                items: [
                    'Cada tarjeta puede abrir la ficha o anadir al carrito.',
                    'El resumen sigue coherente con promociones, impuestos y cantidades.',
                    'La cuenta cliente se crea para seguir el pedido despues.',
                ],
                cta_label: 'Ir al carrito y al checkout',
                cta_href: catalogHref,
            },
            {
                id: 'store-flow-fulfillment',
                label: 'Recibir',
                title: 'Mantiene la promesa de servicio hasta la entrega',
                body: `<p>${fulfillmentSummary}</p>`,
                image_url: visuals.fulfill,
                image_alt: 'Paquetes ordenados y listos para recogida o entrega',
                metric: fulfillmentSummary,
                items: [
                    'El cliente conserva el contexto del pedido sin salir de la pagina.',
                    'Entrega, recogida y notas especiales viven en el mismo flujo.',
                    'El carrito sigue siendo util aunque el fulfillment siga evolucionando.',
                ],
                cta_label: 'Ver productos disponibles',
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
            metric: countLabel(productCount, resolvedLocale, 'active product', 'active products'),
            items: [
                countLabel(categoryCount, resolvedLocale, 'visible category', 'visible categories'),
                countLabel(bestSellerCount, resolvedLocale, 'highlighted best seller', 'highlighted best sellers'),
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
                ? countLabel(promoCount, resolvedLocale, 'active promotion', 'active promotions')
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
                ? countLabel(newArrivalCount, resolvedLocale, 'new arrival visible', 'new arrivals visible')
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

export const buildShowcaseFeatureTabsCopy = ({
    locale = 'fr',
    serviceCount = 0,
    categoryCount = 0,
    requestUrl,
    servicesHref = '#services',
    primaryCta = { label: '', href: '' },
    images = {},
    countLabel,
} = {}) => {
    const resolvedLocale = normalizeCatalogCopyLocale(locale);
    const visuals = {
        qualify: images.qualify || '',
        plan: images.plan || '',
        deliver: images.deliver || '',
        follow: images.follow || '',
    };

    if (resolvedLocale === 'fr') {
        return [
            {
                id: 'service-flow-qualify',
                label: 'Qualifier',
                title: 'Captez un besoin clair avant meme l intervention',
                body: '<p>Le visiteur comprend rapidement ce que vous faites, sur quels types de demandes vous intervenez et comment vous contacter sans chercher ailleurs.</p>',
                image_url: visuals.qualify,
                image_alt: 'Equipe qui relit des informations sur une tablette',
                metric: countLabel(serviceCount, resolvedLocale, 'service visible', 'services visibles'),
                items: [
                    countLabel(categoryCount, resolvedLocale, 'categorie de service', 'categories de service'),
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

    if (resolvedLocale === 'es') {
        return [
            {
                id: 'service-flow-qualify',
                label: 'Calificar',
                title: 'Capta una necesidad clara antes incluso de la visita',
                body: '<p>El visitante entiende rapido que haces, que tipos de solicitudes atiendes y como contactarte sin buscar en otro sitio.</p>',
                image_url: visuals.qualify,
                image_alt: 'Equipo revisando informacion en una tableta',
                metric: countLabel(serviceCount, resolvedLocale, 'servicio visible', 'servicios visibles'),
                items: [
                    countLabel(categoryCount, resolvedLocale, 'categoria de servicio', 'categorias de servicio'),
                    'El servicio destacado ofrece un punto de entrada concreto.',
                    'La solicitud de presupuesto sigue accesible desde el hero y en toda la pagina.',
                ],
                cta_label: primaryCta.label,
                cta_href: primaryCta.href,
            },
            {
                id: 'service-flow-plan',
                label: 'Planificar',
                title: 'Muestra que la solicitud avanza con un proceso real',
                body: '<p>La pagina deja de ser una simple lista. Cuenta la captura de la necesidad, la preparacion y la puesta en marcha del equipo.</p>',
                image_url: visuals.plan,
                image_alt: 'Profesionales validando un plan de trabajo',
                metric: 'Una narrativa mas clara entre solicitud, preparacion e intervencion',
                items: [
                    'Las categorias estructuran la lectura en lugar de recargarla.',
                    'Las descripciones resultan mas utiles para elegir el servicio correcto.',
                    'El CTA de contacto vuelve en el momento adecuado sin saturar la pagina.',
                ],
                cta_label: 'Ver servicios',
                cta_href: servicesHref,
            },
            {
                id: 'service-flow-deliver',
                label: 'Intervenir',
                title: 'Ancla la pagina en la realidad del terreno',
                body: '<p>Los visuales UHD libres de derechos refuerzan la credibilidad del oficio mientras las tarjetas de servicios mantienen el detalle util para vender.</p>',
                image_url: visuals.deliver,
                image_alt: 'Tecnico trabajando en una instalacion interior',
                metric: 'La ejecucion en terreno se muestra con un contexto mas creible',
                items: [
                    'El visitante ve a la vez el contexto humano y la oferta detallada.',
                    'Las tarjetas conservan precio, categoria y siguiente accion.',
                    'El relato editorial introduce las pruebas antes de la cuadricula.',
                ],
                cta_label: primaryCta.label,
                cta_href: primaryCta.href,
            },
            {
                id: 'service-flow-follow',
                label: 'Seguir',
                title: 'Mantiene claro el siguiente paso hasta el final',
                body: '<p>Ya sea un presupuesto, una llamada o un email, la pagina termina con un camino evidente para continuar la conversacion.</p>',
                image_url: visuals.follow,
                image_alt: 'Tecnico de campo con una checklist antes de la visita',
                metric: requestUrl
                    ? 'La solicitud de presupuesto sigue siendo prioritaria en toda la pagina'
                    : 'Telefono y email siguen conectados directamente con la pagina de servicios',
                items: [
                    'El contacto nunca desaparece detras de la cuadricula.',
                    'Los visitantes pueden volver a la lista o seguir contactando.',
                    'La pagina sigue clara tanto en movil como en desktop.',
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
            metric: countLabel(serviceCount, resolvedLocale, 'visible service', 'visible services'),
            items: [
                countLabel(categoryCount, resolvedLocale, 'service category', 'service categories'),
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

export const buildStorePublicSectionsCopy = ({
    locale = 'fr',
    companyName = '',
    featuredName = '',
    featuredCategory = '',
    productCount = 0,
    categoryCount = 0,
    bestSellerCount = 0,
    promoCount = 0,
    fulfillmentSummary = '',
    catalogHref = '#catalog',
    categoriesHref = '#categories',
    bestSellersHref = '#best-sellers',
    storeVisuals = {},
    featureTabs = [],
    countLabel,
} = {}) => {
    const resolvedLocale = normalizeCatalogCopyLocale(locale);

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
                feature_tabs: featureTabs,
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

    if (resolvedLocale === 'es') {
        return [
            {
                layout: 'feature_pairs',
                background_color: '#ffffff',
                kicker: 'Pagina de productos redisenada',
                title: `La tienda ${companyName || 'de productos'} sigue ahora el mismo ritmo que la renovacion publica.`,
                body: `<p>La pagina arranca con una promesa clara, muestra el contexto de compra y luego deja que los bloques ecommerce existentes hagan el trabajo. Tienes ${productCount} productos activos, ${categoryCount} categorias y una entrada directa a los mas vendidos, las promociones y al catalogo completo.</p>`,
                items: [
                    bestSellerCount > 0
                        ? countLabel(bestSellerCount, resolvedLocale, 'seleccion destacada', 'selecciones destacadas')
                        : 'El bloque de superventas ya esta listo para guiar las compras recurrentes.',
                    promoCount > 0
                        ? countLabel(promoCount, resolvedLocale, 'promocion visible', 'promociones visibles')
                        : 'Las promociones apareceran aqui en cuanto esten activas.',
                    fulfillmentSummary,
                ],
                primary_label: 'Ver el catalogo',
                primary_href: catalogHref,
                secondary_label: 'Ver categorias',
                secondary_href: categoriesHref,
                image_url: storeVisuals.intro,
                image_alt: 'Colaborador logistico en un espacio de preparacion',
                aside_kicker: 'De la eleccion a la validacion',
                aside_title: 'Las acciones utiles siguen visibles sin romper el storytelling.',
                aside_body: '<p>Las secciones reutilizables introducen la pagina y luego busqueda, filtros, carrito y checkout toman el relevo en el momento adecuado.</p>',
                aside_items: [
                    'Producto destacado visible desde la apertura.',
                    'Anadir rapido al carrito desde las tarjetas o la ficha detallada.',
                    'Entrega, recogida y resumen disponibles en el mismo flujo.',
                ],
                aside_link_label: 'Ir a los mas vendidos',
                aside_link_href: bestSellersHref,
                aside_image_url: storeVisuals.introAside,
                aside_image_alt: 'Pago con terminal para cerrar un pedido',
            },
            {
                layout: 'feature_tabs',
                background_color: '#f8fafc',
                kicker: 'Recorrido de compra',
                title: 'Pasa del descubrimiento al pedido sin volver a una simple cuadricula.',
                body: '<p>Este acordeon retoma el flujo deseado en el documento de rediseno: una narrativa corta, pruebas concretas y luego un paso natural hacia las tarjetas de producto y el carrito.</p>',
                feature_tabs_style: 'workflow',
                primary_label: 'Explorar productos',
                primary_href: catalogHref,
                feature_tabs: featureTabs,
            },
            {
                layout: 'story_grid',
                background_color: '#ffffff',
                kicker: 'Pruebas visibles',
                title: 'Lo que la nueva estructura deja mas claro para tus clientes.',
                body: '<p>Antes incluso de desplazarse por el catalogo, la pagina explica que esta disponible, que es popular y como se entregara el pedido.</p>',
                primary_label: 'Abrir el catalogo',
                primary_href: catalogHref,
                story_cards: [
                    {
                        id: 'store-story-bestsellers',
                        title: 'Referencias claras antes del primer clic',
                        body: `<p>${bestSellerCount > 0 ? `${countLabel(bestSellerCount, resolvedLocale, 'superventas aparece', 'superventas aparecen')} al inicio de la pagina para reforzar la compra.` : 'El bloque de superventas ya esta listo para guiar las compras recurrentes.'}</p>`,
                        image_url: storeVisuals.storyConfidence,
                        image_alt: 'Cajas de productos listas para prepararse',
                    },
                    {
                        id: 'store-story-promos',
                        title: 'Las ofertas activas siguen siendo faciles de leer',
                        body: `<p>${promoCount > 0 ? `${countLabel(promoCount, resolvedLocale, 'promocion activa sigue visible', 'promociones activas siguen visibles')} sin abrir cada ficha de producto.` : 'Los badges de precio y promocion mantienen la lectura simple en cuanto hay ofertas activas.'}</p>`,
                        image_url: storeVisuals.storyPromos,
                        image_alt: 'Pago con terminal en mostrador',
                    },
                    {
                        id: 'store-story-fulfillment',
                        title: 'La promesa continua despues del carrito',
                        body: `<p>${fulfillmentSummary}</p>`,
                        image_url: storeVisuals.storyFulfillment,
                        image_alt: 'Operador logistico listo para preparar un pedido',
                    },
                ],
            },
            {
                layout: 'showcase_cta',
                background_preset: 'midnight-cobalt',
                tone: 'contrast',
                kicker: 'Listo para convertir',
                title: 'El storytelling introduce la pagina y luego el catalogo toma el relevo para vender.',
                body: `<p>${featuredName ? `El producto destacado del momento es ${featuredName}${featuredCategory ? ` en ${featuredCategory}` : ''}. ` : ''}Los filtros, el carrito y el checkout siguen accesibles sin romper el recorrido.</p>`,
                primary_label: 'Ver todos los productos',
                primary_href: catalogHref,
                secondary_label: bestSellerCount > 0 ? 'Empezar por los mas vendidos' : 'Ver categorias',
                secondary_href: bestSellerCount > 0 ? bestSellersHref : categoriesHref,
                image_url: storeVisuals.showcase,
                image_alt: featuredName || 'Producto destacado de la tienda',
                aside_image_url: storeVisuals.showcaseAside,
                aside_image_alt: 'Pago al final del recorrido de compra',
                aside_link_label: 'Acceso directo al catalogo',
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
            feature_tabs: featureTabs,
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

export const buildShowcasePublicSectionsCopy = ({
    locale = 'fr',
    companyName = '',
    locationLine = '',
    featuredName = '',
    featuredCategory = '',
    serviceCount = 0,
    categoryCount = 0,
    requestUrl,
    phone,
    email,
    primaryCta = { label: '', href: '' },
    secondaryCta = { label: '', href: '' },
    servicesHref = '#services',
    serviceVisuals = {},
    featureTabs = [],
} = {}) => {
    const resolvedLocale = normalizeCatalogCopyLocale(locale);

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
                    feature_tabs: featureTabs,
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

    if (resolvedLocale === 'es') {
        return {
            intro: [
                {
                    layout: 'feature_pairs',
                    background_color: '#ffffff',
                    kicker: 'Pagina de servicios redisenada',
                    title: `${companyName || 'Tu empresa'} presenta ahora sus servicios con el mismo lenguaje visual que la home.`,
                    body: `<p>La pagina presenta primero la promesa, conecta esa promesa con un contexto real de terreno y luego deja que la cuadricula de servicios tome el relevo. ${locationLine ? `${companyName || 'El equipo'} opera desde ${locationLine}. ` : ''}Actualmente tienes ${serviceCount} servicios visibles en ${categoryCount} categorias.</p>`,
                    items: [
                        featuredName
                            ? `Servicio destacado visible: ${featuredName}${featuredCategory ? ` (${featuredCategory})` : ''}.`
                            : 'El hero destaca el primer servicio disponible.',
                        requestUrl
                            ? 'El presupuesto sigue accesible sin salir de la pagina.'
                            : 'Telefono y email siguen conectados al recorrido publico.',
                        'Las secciones editoriales introducen la prueba antes de la cuadricula de tarjetas.',
                    ],
                    primary_label: primaryCta.label,
                    primary_href: primaryCta.href,
                    secondary_label: secondaryCta.label,
                    secondary_href: secondaryCta.href,
                    image_url: serviceVisuals.intro,
                    image_alt: 'Equipo de servicio coordinandose en el sitio',
                    aside_kicker: 'Contexto del oficio',
                    aside_title: 'Una pagina mas creible tanto para la oficina como para el terreno.',
                    aside_body: '<p>El relato editorial enmarca la solicitud, los visuales aportan materia del oficio y luego la cuadricula de servicios mantiene el detalle necesario para convertir.</p>',
                    aside_items: [
                        'El hero conserva el servicio destacado como prueba concreta.',
                        'Las categorias se convierten en una navegacion real.',
                        'El CTA principal vuelve en los momentos que importan.',
                    ],
                    aside_link_label: 'Ver todos los servicios',
                    aside_link_href: servicesHref,
                    aside_image_url: serviceVisuals.introAside,
                    aside_image_alt: 'Tecnico interviniendo en una instalacion',
                },
                {
                    layout: 'feature_tabs',
                    background_color: '#f8fafc',
                    kicker: 'Flujo de servicio',
                    title: 'Estructura la pagina como un recorrido claro entre necesidad, planificacion e intervencion.',
                    body: '<p>Este acordeon reutiliza los bloques del rediseno para mostrar un flujo de trabajo real en lugar de una sucesion de tarjetas sin jerarquia.</p>',
                    feature_tabs_style: 'workflow',
                    primary_label: primaryCta.label,
                    primary_href: primaryCta.href,
                    feature_tabs: featureTabs,
                },
                {
                    layout: 'story_grid',
                    background_color: '#ffffff',
                    kicker: 'Momentos que tranquilizan',
                    title: 'Lo que la nueva maquetacion aclara antes incluso de la lista de servicios.',
                    body: '<p>Cada bloque anade contexto del oficio y luego la cuadricula mantiene el detalle practico para elegir y pedir un presupuesto.</p>',
                    primary_label: 'Explorar servicios',
                    primary_href: servicesHref,
                    story_cards: [
                        {
                            id: 'showcase-story-featured',
                            title: 'Un punto de entrada concreto',
                            body: `<p>${featuredName ? `El hero ancla la pagina alrededor de ${featuredName}${featuredCategory ? ` en ${featuredCategory}` : ''}, lo que da enseguida un ejemplo real del nivel de servicio.` : 'El servicio destacado da enseguida un ejemplo concreto del trabajo ofrecido.'}</p>`,
                            image_url: serviceVisuals.storyFeatured,
                            image_alt: 'Equipo de servicio conversando alrededor de una tableta',
                        },
                        {
                            id: 'showcase-story-field',
                            title: 'El terreno sigue visible',
                            body: '<p>Las imagenes UHD libres de derechos muestran la intervencion, la preparacion y la coordinacion, lo que hace la pagina mas creible para los oficios de servicio.</p>',
                            image_url: serviceVisuals.storyField,
                            image_alt: 'Tecnico durante una instalacion',
                        },
                        {
                            id: 'showcase-story-contact',
                            title: 'La siguiente accion sigue siendo evidente',
                            body: `<p>${requestUrl ? 'El presupuesto sigue a un clic durante toda la pagina.' : 'Los canales de contacto siguen visibles para continuar la conversacion sin friccion.'}</p>`,
                            image_url: serviceVisuals.storyContact,
                            image_alt: 'Equipo de campo hablando sobre el siguiente paso',
                        },
                    ],
                },
                {
                    layout: 'showcase_cta',
                    background_preset: 'deep-ocean',
                    tone: 'contrast',
                    kicker: 'Listo para lanzar una solicitud',
                    title: 'El contexto del oficio tranquiliza y luego la pagina de servicios convierte.',
                    body: `<p>${featuredName ? `El servicio destacado ${featuredName} aporta una prueba inmediata mientras la lista completa sigue accesible justo debajo.` : 'El servicio destacado aporta una prueba inmediata mientras la lista completa sigue accesible justo debajo.'}</p>`,
                    primary_label: primaryCta.label,
                    primary_href: primaryCta.href,
                    secondary_label: secondaryCta.label,
                    secondary_href: secondaryCta.href,
                    image_url: serviceVisuals.showcase,
                    image_alt: featuredName || 'Servicio destacado en vitrina',
                    aside_image_url: serviceVisuals.showcaseAside,
                    aside_image_alt: 'Equipo de servicio revisando detalles en una tableta',
                    aside_link_label: 'Ver la lista completa',
                    aside_link_href: servicesHref,
                },
            ],
            contact: [
                {
                    layout: 'contact',
                    background_color: '#ffffff',
                    kicker: 'Contacto',
                    title: 'Hablanos de tu proyecto con un canal simple y directo.',
                    body: '<p>La pagina mantiene el mismo formato que la renovacion y luego se cierra con un bloque de contacto reutilizable para ayudar al visitante a pasar a la accion.</p>',
                    items: [
                        phone ? `Telefono: ${phone}` : 'Telefono: por confirmar',
                        email ? `Email: ${email}` : 'Email: por confirmar',
                        locationLine ? `Zona: ${locationLine}` : 'Zona: informacion de ubicacion por definir',
                    ],
                    primary_label: primaryCta.label,
                    primary_href: primaryCta.href,
                    secondary_label: 'Volver a los servicios',
                    secondary_href: servicesHref,
                    image_url: serviceVisuals.contact,
                    image_alt: 'Equipo de servicio coordinandose',
                    aside_kicker: 'Siguiente paso',
                    aside_title: 'Un bloque de contacto mas util que repetir el hero.',
                    aside_body: '<p>Este ultimo bloque recuerda el canal adecuado mientras conserva la misma gramatica visual que las demas paginas publicas.</p>',
                    aside_items: [
                        requestUrl ? 'El enlace de presupuesto sigue siendo prioritario.' : 'El mejor canal de contacto sigue destacado.',
                        'El visitante puede volver a la lista completa sin perder el contexto.',
                        'El bloque sigue siendo legible en movil gracias a la misma estructura reutilizable.',
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
                feature_tabs: featureTabs,
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
