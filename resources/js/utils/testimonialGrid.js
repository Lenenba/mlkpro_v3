const normalizeTestimonialLocale = (locale = 'fr') => {
    const value = String(locale || 'fr').toLowerCase();

    if (value.startsWith('fr')) {
        return 'fr';
    }

    if (value.startsWith('es')) {
        return 'es';
    }

    return 'en';
};

const TESTIMONIAL_CARD_COPY = {
    fr: [
        {
            id: 'testimonial-grid-card-1',
            quote: '<p>"Nos checklists de job rassurent les clients et montrent clairement ce qui a ete fait a chaque visite."</p>',
            author_name: 'Julie Morin',
            author_company: 'Maison Claire Montreal',
        },
        {
            id: 'testimonial-grid-card-2',
            quote: '<p>"Mon equipe a tous les details du job sur son telephone. On perd beaucoup moins de temps entre le bureau et le terrain."</p>',
            author_name: 'Cynthia Gagnon',
            author_company: 'Nordik Clean',
        },
        {
            id: 'testimonial-grid-card-3',
            quote: '<p>"J ai enfin une vision complete de mon entreprise. Ca me permet de deleguer plus facilement et de voir plus grand."</p>',
            author_name: 'Mylene Fortin',
            author_company: 'Entretien Signature',
        },
    ],
    es: [
        {
            id: 'testimonial-grid-card-1',
            quote: '<p>"Nuestras checklists de trabajo tranquilizan a los clientes y muestran claramente lo que se hizo en cada visita."</p>',
            author_name: 'Julie Morin',
            author_company: 'Maison Claire Montreal',
        },
        {
            id: 'testimonial-grid-card-2',
            quote: '<p>"Mi equipo tiene todos los detalles del trabajo en el telefono. Perdemos mucho menos tiempo entre la oficina y el terreno."</p>',
            author_name: 'Cynthia Gagnon',
            author_company: 'Nordik Clean',
        },
        {
            id: 'testimonial-grid-card-3',
            quote: '<p>"Por fin tengo una vision completa de mi empresa. Eso me permite delegar con mas facilidad y pensar en grande."</p>',
            author_name: 'Mylene Fortin',
            author_company: 'Entretien Signature',
        },
    ],
    en: [
        {
            id: 'testimonial-grid-card-1',
            quote: '<p>"Job checklists help our clients see exactly what was done, and that builds trust visit after visit."</p>',
            author_name: 'Julie Morris',
            author_company: 'Maison Claire Montreal',
        },
        {
            id: 'testimonial-grid-card-2',
            quote: '<p>"My field team has every job detail on their phone, so the whole day runs smoother from the office to the jobsite."</p>',
            author_name: 'Cynthia Gagnon',
            author_company: 'Nordik Clean',
        },
        {
            id: 'testimonial-grid-card-3',
            quote: '<p>"I finally feel like I can see the full business clearly, delegate with confidence, and grow without guessing."</p>',
            author_name: 'Mylene Fortin',
            author_company: 'Signature Cleaning Co.',
        },
    ],
};

export const createTestimonialCard = (overrides = {}) => ({
    id: overrides.id || `testimonial-card-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    quote: overrides.quote || '',
    author_name: overrides.author_name || '',
    author_role: overrides.author_role || '',
    author_company: overrides.author_company || '',
    image_url: overrides.image_url || '',
    image_alt: overrides.image_alt || '',
});

export const ensureTestimonialCards = (cards) => (
    Array.isArray(cards) ? cards.map((card) => createTestimonialCard(card)) : []
);

export const defaultTestimonialCards = (locale = 'fr') => (
    ensureTestimonialCards(TESTIMONIAL_CARD_COPY[normalizeTestimonialLocale(locale)] || TESTIMONIAL_CARD_COPY.en)
);
