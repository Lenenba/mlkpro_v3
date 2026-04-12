import { testimonialCardCopy } from './publicCopy';

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
    ensureTestimonialCards(testimonialCardCopy[normalizeTestimonialLocale(locale)] || testimonialCardCopy.en)
);
