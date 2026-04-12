import { storyCardCopy } from './publicCopy';

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
    ensureStoryCards(storyCardCopy[normalizeStoryLocale(locale)] || storyCardCopy.en)
);
