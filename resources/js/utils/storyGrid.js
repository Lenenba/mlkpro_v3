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

export const defaultStoryCards = (locale = 'fr') => {
    if (locale === 'fr') {
        return [
            createStoryCard({
                id: 'story-grid-card-1',
                title: 'Concue pour le terrain',
                body: '<p>Notre IA comprend le quotidien des equipes terrain. Elle aide a mieux qualifier, chiffrer et reperer les opportunites sans alourdir les operations.</p>',
                image_url: '/images/landing/mobile-field.svg',
                image_alt: 'Apercu mobile terrain',
            }),
            createStoryCard({
                id: 'story-grid-card-2',
                title: 'S adapte a votre facon de travailler',
                body: '<p>Plus vous l utilisez, plus elle suit votre logique de devis, de planification et de description de service pour garder un rendu naturel.</p>',
                image_url: '/images/landing/workflow-board.svg',
                image_alt: 'Apercu workflow et planification',
            }),
            createStoryCard({
                id: 'story-grid-card-3',
                title: 'Intervient au bon moment',
                body: '<p>Elle apparait quand il faut: pour completer un devis, suggerer un ajout utile ou relancer une etape importante sans casser le rythme.</p>',
                image_url: '/images/landing/hero-dashboard.svg',
                image_alt: 'Apercu tableau de bord intelligent',
            }),
        ];
    }

    return [
        createStoryCard({
            id: 'story-grid-card-1',
            title: 'Built for field teams',
            body: '<p>Our AI understands how blue-collar teams actually work. It helps you qualify, price, and spot opportunities without slowing down operations.</p>',
            image_url: '/images/landing/mobile-field.svg',
            image_alt: 'Mobile field workflow preview',
        }),
        createStoryCard({
            id: 'story-grid-card-2',
            title: 'Adjusts to your workflow',
            body: '<p>The more you use it, the better it follows your quoting, scheduling, and service language so everything feels natural and consistent.</p>',
            image_url: '/images/landing/workflow-board.svg',
            image_alt: 'Workflow and scheduling preview',
        }),
        createStoryCard({
            id: 'story-grid-card-3',
            title: 'Shows up when timing matters',
            body: '<p>It appears at the right moment to finish a quote, suggest an upsell, or keep an important next step moving without breaking momentum.</p>',
            image_url: '/images/landing/hero-dashboard.svg',
            image_alt: 'Smart dashboard preview',
        }),
    ];
};
