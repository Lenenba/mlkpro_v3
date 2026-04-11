const normalizeStoryLocale = (locale = 'fr') => {
    const value = String(locale || 'fr').toLowerCase();

    if (value.startsWith('fr')) {
        return 'fr';
    }

    if (value.startsWith('es')) {
        return 'es';
    }

    return 'en';
};

const STORY_CARD_COPY = {
    fr: [
        {
            id: 'story-grid-card-1',
            title: 'Concue pour le terrain',
            body: '<p>Notre IA comprend le quotidien des equipes terrain. Elle aide a mieux qualifier, chiffrer et reperer les opportunites sans alourdir les operations.</p>',
            image_url: '/images/landing/stock/field-checklist.jpg',
            image_alt: 'Technicien terrain avec checklist',
        },
        {
            id: 'story-grid-card-2',
            title: 'S adapte a votre facon de travailler',
            body: '<p>Plus vous l utilisez, plus elle suit votre logique de devis, de planification et de description de service pour garder un rendu naturel.</p>',
            image_url: '/images/landing/stock/workflow-plan.jpg',
            image_alt: 'Equipe qui relit un plan de travail',
        },
        {
            id: 'story-grid-card-3',
            title: 'Intervient au bon moment',
            body: '<p>Elle apparait quand il faut: pour completer un devis, suggerer un ajout utile ou relancer une etape importante sans casser le rythme.</p>',
            image_url: '/images/landing/stock/collab-laptop-desk.jpg',
            image_alt: 'Collegues qui collaborent autour d un ordinateur',
        },
    ],
    es: [
        {
            id: 'story-grid-card-1',
            title: 'Pensada para el trabajo de campo',
            body: '<p>Nuestra IA entiende la realidad de los equipos en terreno. Ayuda a calificar mejor, cotizar y detectar oportunidades sin hacer mas pesadas las operaciones.</p>',
            image_url: '/images/landing/stock/field-checklist.jpg',
            image_alt: 'Tecnico de campo con una checklist',
        },
        {
            id: 'story-grid-card-2',
            title: 'Se adapta a tu forma de trabajar',
            body: '<p>Cuanto mas la usas, mejor sigue tu logica de cotizacion, planificacion y descripcion del servicio para mantener un resultado natural.</p>',
            image_url: '/images/landing/stock/workflow-plan.jpg',
            image_alt: 'Equipo revisando un plan de trabajo',
        },
        {
            id: 'story-grid-card-3',
            title: 'Aparece en el momento adecuado',
            body: '<p>Aparece cuando hace falta: para completar una cotizacion, sugerir un extra util o reactivar un paso importante sin romper el ritmo.</p>',
            image_url: '/images/landing/stock/collab-laptop-desk.jpg',
            image_alt: 'Companeros colaborando alrededor de un ordenador',
        },
    ],
    en: [
        {
            id: 'story-grid-card-1',
            title: 'Built for field teams',
            body: '<p>Our AI understands how blue-collar teams actually work. It helps you qualify, price, and spot opportunities without slowing down operations.</p>',
            image_url: '/images/landing/stock/field-checklist.jpg',
            image_alt: 'Field technician with a checklist',
        },
        {
            id: 'story-grid-card-2',
            title: 'Adjusts to your workflow',
            body: '<p>The more you use it, the better it follows your quoting, scheduling, and service language so everything feels natural and consistent.</p>',
            image_url: '/images/landing/stock/workflow-plan.jpg',
            image_alt: 'Team reviewing a workflow plan',
        },
        {
            id: 'story-grid-card-3',
            title: 'Shows up when timing matters',
            body: '<p>It appears at the right moment to finish a quote, suggest an upsell, or keep an important next step moving without breaking momentum.</p>',
            image_url: '/images/landing/stock/collab-laptop-desk.jpg',
            image_alt: 'Teammates collaborating around a laptop',
        },
    ],
};

export const createStoryCard = (overrides = {}) => ({
    id: overrides.id || `story-card-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    title: overrides.title || '',
    body: overrides.body || '',
    image_url: overrides.image_url || '',
    image_alt: overrides.image_alt || '',
});

export const ensureStoryCards = (cards) => (
    Array.isArray(cards) ? cards.map((card) => createStoryCard(card)) : []
);

export const defaultStoryCards = (locale = 'fr') => (
    ensureStoryCards(STORY_CARD_COPY[normalizeStoryLocale(locale)] || STORY_CARD_COPY.en)
);
