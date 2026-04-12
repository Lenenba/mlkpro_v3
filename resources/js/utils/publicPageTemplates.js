import { localeMessages, normalizeLocale } from '../i18n/catalog';

const getMessageValue = (locale, path) => {
    const segments = String(path || '')
        .split('.')
        .map((segment) => segment.trim())
        .filter((segment) => segment.length > 0);

    if (!segments.length) {
        return '';
    }

    const resolvedLocale = normalizeLocale(String(locale || 'fr').toLowerCase().slice(0, 2));
    const sources = [
        localeMessages[resolvedLocale],
        localeMessages.en,
    ];

    for (const source of sources) {
        let cursor = source;

        for (const segment of segments) {
            cursor = cursor?.[segment];
        }

        if (typeof cursor === 'string') {
            return cursor;
        }
    }

    return '';
};

const getMessages = (locale, paths = []) => paths.map((path) => getMessageValue(locale, path));

export const buildPageTemplateContent = (templateId, locale = 'fr') => {
    const resolvedLocale = normalizeLocale(locale);

    if (templateId === 'pricing') {
        const [heroOne, heroTwo, heroThree] = getMessages(resolvedLocale, [
            'super_admin.pages.templates.pricing.sections.hero.items.one',
            'super_admin.pages.templates.pricing.sections.hero.items.two',
            'super_admin.pages.templates.pricing.sections.hero.items.three',
        ]);
        const [plansOne, plansTwo, plansThree] = getMessages(resolvedLocale, [
            'super_admin.pages.templates.pricing.sections.plans.items.one',
            'super_admin.pages.templates.pricing.sections.plans.items.two',
            'super_admin.pages.templates.pricing.sections.plans.items.three',
        ]);

        return {
            page_title: getMessageValue(resolvedLocale, 'super_admin.pages.templates.pricing.page_title'),
            page_subtitle: getMessageValue(resolvedLocale, 'super_admin.pages.templates.pricing.page_subtitle'),
            sections: [
                {
                    layout: 'split',
                    kicker: getMessageValue(resolvedLocale, 'super_admin.pages.templates.pricing.sections.hero.kicker'),
                    title: getMessageValue(resolvedLocale, 'super_admin.pages.templates.pricing.sections.hero.title'),
                    body: getMessageValue(resolvedLocale, 'super_admin.pages.templates.pricing.sections.hero.body'),
                    items: [heroOne, heroTwo, heroThree].filter((item) => item.length > 0),
                    primary_label: getMessageValue(resolvedLocale, 'super_admin.pages.templates.pricing.sections.hero.primary_label'),
                    primary_href: '#pricing',
                    secondary_label: getMessageValue(resolvedLocale, 'super_admin.pages.templates.pricing.sections.hero.secondary_label'),
                    secondary_href: '#contact',
                },
                {
                    layout: 'stack',
                    alignment: 'center',
                    tone: 'muted',
                    kicker: getMessageValue(resolvedLocale, 'super_admin.pages.templates.pricing.sections.plans.kicker'),
                    title: getMessageValue(resolvedLocale, 'super_admin.pages.templates.pricing.sections.plans.title'),
                    body: getMessageValue(resolvedLocale, 'super_admin.pages.templates.pricing.sections.plans.body'),
                    items: [plansOne, plansTwo, plansThree].filter((item) => item.length > 0),
                },
            ],
        };
    }

    if (templateId === 'about') {
        const [valuesOne, valuesTwo, valuesThree] = getMessages(resolvedLocale, [
            'super_admin.pages.templates.about.sections.values.items.one',
            'super_admin.pages.templates.about.sections.values.items.two',
            'super_admin.pages.templates.about.sections.values.items.three',
        ]);

        return {
            page_title: getMessageValue(resolvedLocale, 'super_admin.pages.templates.about.page_title'),
            page_subtitle: getMessageValue(resolvedLocale, 'super_admin.pages.templates.about.page_subtitle'),
            sections: [
                {
                    layout: 'split',
                    kicker: getMessageValue(resolvedLocale, 'super_admin.pages.templates.about.sections.mission.kicker'),
                    title: getMessageValue(resolvedLocale, 'super_admin.pages.templates.about.sections.mission.title'),
                    body: getMessageValue(resolvedLocale, 'super_admin.pages.templates.about.sections.mission.body'),
                },
                {
                    layout: 'split',
                    alignment: 'left',
                    kicker: getMessageValue(resolvedLocale, 'super_admin.pages.templates.about.sections.values.kicker'),
                    title: getMessageValue(resolvedLocale, 'super_admin.pages.templates.about.sections.values.title'),
                    body: getMessageValue(resolvedLocale, 'super_admin.pages.templates.about.sections.values.body'),
                    items: [valuesOne, valuesTwo, valuesThree].filter((item) => item.length > 0),
                },
                {
                    layout: 'stack',
                    alignment: 'center',
                    tone: 'contrast',
                    kicker: getMessageValue(resolvedLocale, 'super_admin.pages.templates.about.sections.team.kicker'),
                    title: getMessageValue(resolvedLocale, 'super_admin.pages.templates.about.sections.team.title'),
                    body: getMessageValue(resolvedLocale, 'super_admin.pages.templates.about.sections.team.body'),
                    primary_label: getMessageValue(resolvedLocale, 'super_admin.pages.templates.about.sections.team.primary_label'),
                    primary_href: '#contact',
                },
            ],
        };
    }

    return null;
};
