<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Facebook, Instagram, Linkedin, Mail, Phone, Play, Youtube } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    menu: {
        type: Object,
        default: () => ({}),
    },
    copy: {
        type: String,
        default: '',
    },
    section: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const { t } = useI18n();
const currentLocale = computed(() => String(page.props.locale || 'fr').toLowerCase());
const isFrench = computed(() => currentLocale.value.startsWith('fr'));

const safeRoute = (name, params = {}, fallback = '#') => {
    try {
        return route(name, params);
    } catch (error) {
        return fallback;
    }
};

const normalizeHref = (href) => String(href || '').trim() || '#';
const shouldUseAnchor = (href) => /^(https?:|mailto:|tel:|#)/i.test(normalizeHref(href));
const isExternalHttpHref = (href) => /^https?:/i.test(normalizeHref(href));
const linkTarget = (link) => (link?.target === '_blank' || isExternalHttpHref(link?.href) ? '_blank' : undefined);
const linkRel = (link) => (linkTarget(link) === '_blank' ? 'noopener noreferrer' : undefined);

const toLink = (label, href, extras = {}) => ({
    label: String(label || '').trim(),
    href: normalizeHref(href),
    note: String(extras.note || '').trim(),
    target: extras.target || '_self',
});

const blockLinks = (block) => {
    const payload = block?.payload || {};

    if (block?.type === 'navigation_group') {
        return (payload.links || [])
            .map((link) => toLink(link.label, link.resolved_href || link.href, {
                note: link.note || link.badge,
                target: link.target,
            }))
            .filter((link) => link.label);
    }

    if (block?.type === 'category_list') {
        return (payload.categories || [])
            .map((category) => toLink(category.label, category.resolved_href || category.href, {
                note: category.meta,
            }))
            .filter((link) => link.label);
    }

    if (block?.type === 'quick_links') {
        return (payload.links || [])
            .map((link) => toLink(link.label, link.resolved_href || link.href, {
                target: link.target,
            }))
            .filter((link) => link.label);
    }

    if (block?.type === 'module_shortcut') {
        return (payload.shortcuts || [])
            .map((shortcut) => toLink(shortcut.label, shortcut.resolved_href || '', {
                note: shortcut.description,
            }))
            .filter((link) => link.label && link.href !== '#');
    }

    if (block?.type === 'cards') {
        return (payload.cards || [])
            .map((card) => toLink(card.title, card.resolved_href || card.href, {
                note: card.body || card.badge,
            }))
            .filter((link) => link.label);
    }

    return [];
};

const hasBlockLinks = (block) => blockLinks(block).length > 0;
const blockHeading = (block) => String(block?.title || block?.payload?.title || block?.payload?.eyebrow || '').trim();
const blockBody = (block) => {
    if (block?.type === 'text') {
        return String(block?.payload?.body || '').trim();
    }

    if (block?.type === 'html') {
        return String(block?.payload?.html || '').trim();
    }

    return '';
};

const blockImage = (block) => {
    const payload = block?.payload || {};

    if (block?.type === 'image' && payload.image_url) {
        return {
            src: payload.image_url,
            alt: payload.image_alt || payload.caption || '',
            title: payload.caption || '',
            href: normalizeHref(payload.resolved_href || payload.href || ''),
        };
    }

    return null;
};

const blockCallout = (block) => {
    const payload = block?.payload || {};

    if (block?.type === 'featured_content') {
        return {
            eyebrow: payload.eyebrow || '',
            title: payload.title || '',
            body: payload.body || '',
            ctaLabel: payload.cta_label || '',
            ctaHref: normalizeHref(payload.resolved_cta_href || payload.cta_href || ''),
        };
    }

    if (block?.type === 'promo_banner') {
        return {
            eyebrow: payload.badge || '',
            title: payload.title || '',
            body: payload.body || '',
            ctaLabel: payload.cta_label || '',
            ctaHref: normalizeHref(payload.resolved_cta_href || payload.cta_href || ''),
        };
    }

    if (block?.type === 'cta') {
        return {
            eyebrow: '',
            title: payload.title || '',
            body: payload.body || '',
            ctaLabel: payload.button_label || '',
            ctaHref: normalizeHref(payload.resolved_button_href || payload.button_href || ''),
        };
    }

    return null;
};

const footerSection = computed(() => (props.section && typeof props.section === 'object' ? props.section : {}));

const footerBrand = computed(() => {
    const section = footerSection.value || {};
    const href = String(section.brand_href || '').trim() || safeRoute('welcome');

    return {
        href,
        src: String(section.brand_logo_url || '').trim() || '/1.svg',
        alt: String(section.brand_logo_alt || '').trim() || 'Malikia Pro',
    };
});

const customGroups = computed(() =>
    (props.menu?.items || [])
        .filter((item) => item && item.is_visible !== false)
        .map((item, index) => ({
            id: item.id || `footer-item-${index}`,
            title: String(item.label || '').trim(),
            kind: item.panel_type === 'mega' ? 'mega' : 'classic',
            links: (item.children || [])
                .filter((child) => child && child.is_visible !== false)
                .map((child) => toLink(child.label, child.resolved_href, {
                    note: child.description,
                    target: child.link_target,
                }))
                .filter((link) => link.label && link.href !== '#'),
            columns: Array.isArray(item.columns) ? item.columns : [],
        }))
        .filter((group) => group.title || group.links.length || group.columns.length)
);

const menuProvidesNavigation = computed(() =>
    customGroups.value.length >= 3 || customGroups.value.some((group) => group.kind === 'mega' && group.columns.length > 0)
);

const fallbackGroups = computed(() => {
    if (isFrench.value) {
        return [
            {
                id: 'industries',
                title: 'Industries desservies',
                links: [
                    toLink('Plomberie', '/pages/industry-plumbing'),
                    toLink('HVAC', '/pages/industry-hvac'),
                    toLink('Électricité', '/pages/industry-electrical'),
                    toLink('Entretien ménager', '/pages/industry-cleaning'),
                    toLink('Salon & beauté', '/pages/industry-salon-beauty'),
                    toLink('Restaurant', '/pages/industry-restaurant'),
                ],
            },
            {
                id: 'products',
                title: 'Produits',
                links: [
                    toLink('Sales & CRM', '/pages/sales-crm'),
                    toLink('Reservations', '/pages/reservations'),
                    toLink('Operations', '/pages/operations'),
                    toLink('Commerce', '/pages/commerce'),
                    toLink('Marketing & Loyalty', '/pages/marketing-loyalty'),
                    toLink('AI & Automation', '/pages/ai-automation'),
                    toLink('Command Center', '/pages/command-center'),
                ],
            },
            {
                id: 'resources',
                title: 'Ressources',
                links: [
                    toLink('Tarification', safeRoute('pricing')),
                    toLink('Conditions', safeRoute('terms')),
                    toLink('Confidentialité', safeRoute('privacy')),
                    toLink('Remboursement', safeRoute('refund')),
                    toLink('Contact us', '/pages/contact-us'),
                ],
            },
            {
                id: 'solutions',
                title: 'Solutions',
                links: [
                    toLink('Services terrain', '/pages/solution-field-services'),
                    toLink('Réservations & files', '/pages/solution-reservations-queues'),
                    toLink('Ventes & devis', '/pages/solution-sales-quoting'),
                    toLink('Commerce & catalogue', '/pages/solution-commerce-catalog'),
                    toLink('Marketing & fidélisation', '/pages/solution-marketing-loyalty'),
                    toLink('Supervision multi-entité', '/pages/solution-multi-entity-oversight'),
                ],
            },
        ];
    }

    return [
        {
            id: 'industries',
            title: 'Industries We Serve',
            links: [
                toLink('Plumbing', '/pages/industry-plumbing'),
                toLink('HVAC', '/pages/industry-hvac'),
                toLink('Electrical', '/pages/industry-electrical'),
                toLink('Cleaning', '/pages/industry-cleaning'),
                toLink('Salon & Beauty', '/pages/industry-salon-beauty'),
                toLink('Restaurant', '/pages/industry-restaurant'),
            ],
        },
        {
            id: 'products',
            title: 'Products',
            links: [
                toLink('Sales & CRM', '/pages/sales-crm'),
                toLink('Reservations', '/pages/reservations'),
                toLink('Operations', '/pages/operations'),
                toLink('Commerce', '/pages/commerce'),
                toLink('Marketing & Loyalty', '/pages/marketing-loyalty'),
                toLink('AI & Automation', '/pages/ai-automation'),
                toLink('Command Center', '/pages/command-center'),
            ],
        },
        {
            id: 'resources',
            title: 'Resources',
            links: [
                toLink('Pricing', safeRoute('pricing')),
                toLink('Terms', safeRoute('terms')),
                toLink('Privacy', safeRoute('privacy')),
                toLink('Refund', safeRoute('refund')),
                toLink('Contact us', '/pages/contact-us'),
            ],
        },
        {
            id: 'solutions',
            title: 'Solutions',
            links: [
                toLink('Field services', '/pages/solution-field-services'),
                toLink('Reservations & queues', '/pages/solution-reservations-queues'),
                toLink('Sales & quoting', '/pages/solution-sales-quoting'),
                toLink('Commerce & catalog', '/pages/solution-commerce-catalog'),
                toLink('Marketing & loyalty', '/pages/solution-marketing-loyalty'),
                toLink('Multi-entity oversight', '/pages/solution-multi-entity-oversight'),
            ],
        },
    ];
});

const sectionGroups = computed(() => {
    const groups = footerSection.value?.footer_groups;
    if (!Array.isArray(groups)) {
        return [];
    }

    return groups
        .map((group, index) => ({
            id: group?.id || `footer-section-group-${index}`,
            title: String(group?.title || '').trim(),
            kind: 'classic',
            layout: String(group?.layout || '').trim() === 'split' ? 'split' : 'stack',
            links: (group?.links || [])
                .map((link) => toLink(link?.label, link?.href, {
                    note: link?.note,
                }))
                .filter((link) => link.label && link.href !== '#'),
            columns: [],
        }))
        .filter((group) => group.title || group.links.length);
});

const displayGroups = computed(() => {
    if (Array.isArray(footerSection.value?.footer_groups)) {
        return sectionGroups.value;
    }

    return menuProvidesNavigation.value ? customGroups.value : fallbackGroups.value;
});

const defaultLegalLinks = computed(() => [
    toLink(t('legal.links.pricing'), safeRoute('pricing')),
    toLink(t('legal.links.terms'), safeRoute('terms')),
    toLink(t('legal.links.privacy'), safeRoute('privacy')),
    toLink(t('legal.links.refund'), safeRoute('refund')),
]);

const footerMenuLegalLinks = computed(() => {
    const preferredGroup = customGroups.value.find((group) => /legal/i.test(group.title)) || customGroups.value[0];

    return preferredGroup?.links?.length ? preferredGroup.links : [];
});

const sectionLegalLinks = computed(() => {
    const links = footerSection.value?.legal_links;
    if (!Array.isArray(links)) {
        return [];
    }

    return links
        .map((link) => toLink(link?.label, link?.href, {
            note: link?.note,
        }))
        .filter((link) => link.label && link.href !== '#');
});

const legalLinks = computed(() => {
    if (Array.isArray(footerSection.value?.legal_links)) {
        return sectionLegalLinks.value;
    }

    return footerMenuLegalLinks.value.length ? footerMenuLegalLinks.value : defaultLegalLinks.value;
});

const defaultSupportCard = computed(() => ({
    kicker: isFrench.value ? 'Accompagnement' : 'Support',
    title: isFrench.value ? 'Parlez a notre equipe' : 'Talk to our team',
    body: isFrench.value
        ? '<p>Besoin d un parcours produit plus precis ou d une page publique sur mesure ? On peut vous guider.</p>'
        : '<p>Need a sharper product journey or a custom public page setup? Our team can help.</p>',
    actions: [
        toLink(isFrench.value ? 'Nous contacter' : 'Contact us', '/pages/contact-us'),
        toLink(isFrench.value ? 'Voir les tarifs' : 'View pricing', safeRoute('pricing')),
    ],
    meta: [
        { label: isFrench.value ? 'Parcours public et modules metier' : 'Public pages and business modules' },
        { label: isFrench.value ? 'Support produit et accompagnement' : 'Product support and enablement' },
        { label: isFrench.value ? 'Disponible en francais et en anglais' : 'Available in French and English' },
    ],
}));

const supportCard = computed(() => {
    const fallback = defaultSupportCard.value;
    const section = footerSection.value || {};
    const customActions = [
        section.primary_label
            ? toLink(section.primary_label, section.primary_href)
            : null,
        section.secondary_label
            ? toLink(section.secondary_label, section.secondary_href)
            : null,
    ].filter(Boolean);
    const customMeta = Array.isArray(section.items)
        ? section.items
            .map((item) => String(item || '').trim())
            .filter((item) => item.length > 0)
            .map((label) => ({ label }))
        : [];

    return {
        kicker: String(section.kicker || fallback.kicker || '').trim(),
        title: String(section.title || fallback.title || '').trim(),
        body: String(section.body || fallback.body || '').trim(),
        actions: customActions.length ? customActions : fallback.actions,
        meta: customMeta.length ? customMeta : fallback.meta,
    };
});

const footerContact = computed(() => {
    const section = footerSection.value || {};
    const phone = String(section.contact_phone || '').trim();
    const email = String(section.contact_email || '').trim();
    const socials = [
        { key: 'facebook', href: String(section.social_facebook_href || '').trim(), label: 'Facebook', icon: Facebook },
        { key: 'x', href: String(section.social_x_href || '').trim(), label: 'X', text: 'X' },
        { key: 'instagram', href: String(section.social_instagram_href || '').trim(), label: 'Instagram', icon: Instagram },
        { key: 'youtube', href: String(section.social_youtube_href || '').trim(), label: 'YouTube', icon: Youtube },
        { key: 'linkedin', href: String(section.social_linkedin_href || '').trim(), label: 'LinkedIn', icon: Linkedin },
    ].map((item) => ({ ...item, disabled: !item.href }));
    const stores = [
        {
            key: 'google-play',
            href: String(section.google_play_href || '').trim(),
            eyebrow: isFrench.value ? 'Disponible sur' : 'Get it on',
            label: 'Google Play',
            icon: Play,
        },
        {
            key: 'app-store',
            href: String(section.app_store_href || '').trim(),
            eyebrow: isFrench.value ? 'Telecharger sur' : 'Download on the',
            label: 'App Store',
            text: 'A',
        },
    ].map((item) => ({ ...item, disabled: !item.href }));

    return {
        phone,
        phoneHref: phone ? `tel:${phone.replace(/[^\d+]/g, '')}` : '',
        email,
        emailHref: email ? `mailto:${email}` : '',
        socials,
        stores,
    };
});

const hasFooterContact = computed(() => true);

const footerStyles = computed(() => {
    const backgroundColor = String(footerSection.value?.background_color || '').trim();

    if (!backgroundColor) {
        return {};
    }

    return {
        '--public-site-footer-background': backgroundColor,
        '--public-site-footer-background-end': backgroundColor,
    };
});

const footerCopy = computed(() => (
    String(props.copy || footerSection.value?.copy || '').trim() || t('welcome.footer.copy')
));
</script>

<template>
    <footer class="public-site-footer" :style="footerStyles">
        <div class="public-container public-site-footer__inner">
            <div class="public-site-footer__brand-row">
                <component
                    :is="shouldUseAnchor(footerBrand.href) ? 'a' : Link"
                    :href="footerBrand.href"
                    class="public-site-footer__brand"
                >
                    <img :src="footerBrand.src" :alt="footerBrand.alt" class="public-site-footer__brand-logo">
                </component>
            </div>

            <div class="public-site-footer__grid">
                <aside v-if="hasFooterContact" class="public-site-footer__contact">
                    <div class="public-site-footer__eyebrow">
                        {{ isFrench ? 'Contact' : 'Contact' }}
                    </div>

                    <div class="public-site-footer__contact-stack">
                        <a
                            v-if="footerContact.phone"
                            :href="footerContact.phoneHref"
                            class="public-site-footer__contact-line"
                        >
                            <Phone class="public-site-footer__contact-icon" />
                            <span>{{ footerContact.phone }}</span>
                        </a>
                        <a
                            v-if="footerContact.email"
                            :href="footerContact.emailHref"
                            class="public-site-footer__contact-line"
                        >
                            <Mail class="public-site-footer__contact-icon" />
                            <span>{{ footerContact.email }}</span>
                        </a>
                    </div>

                    <div v-if="footerContact.socials.length" class="public-site-footer__socials">
                        <component
                            v-for="social in footerContact.socials"
                            :key="social.key"
                            :is="social.href ? 'a' : 'span'"
                            :href="social.href || undefined"
                            :target="social.href ? '_blank' : undefined"
                            :rel="social.href ? 'noopener noreferrer' : undefined"
                            class="public-site-footer__social-link"
                            :class="{ 'public-site-footer__social-link--disabled': !social.href }"
                            :aria-label="social.label"
                        >
                            <component v-if="social.icon" :is="social.icon" class="public-site-footer__social-icon" />
                            <span v-else class="public-site-footer__social-glyph">{{ social.text }}</span>
                        </component>
                    </div>

                    <div v-if="footerContact.stores.length" class="public-site-footer__store-links">
                        <component
                            v-for="store in footerContact.stores"
                            :key="store.key"
                            :is="store.href ? 'a' : 'span'"
                            :href="store.href || undefined"
                            :target="store.href ? '_blank' : undefined"
                            :rel="store.href ? 'noopener noreferrer' : undefined"
                            class="public-site-footer__store-badge"
                            :class="{ 'public-site-footer__store-badge--disabled': !store.href }"
                        >
                            <div class="public-site-footer__store-icon-box" aria-hidden="true">
                                <component v-if="store.icon" :is="store.icon" class="public-site-footer__store-icon" />
                                <span v-else class="public-site-footer__store-glyph">{{ store.text }}</span>
                            </div>
                            <div class="public-site-footer__store-copy">
                                <span class="public-site-footer__store-eyebrow">{{ store.eyebrow }}</span>
                                <span class="public-site-footer__store-title">{{ store.label }}</span>
                            </div>
                        </component>
                    </div>
                </aside>

                <div class="public-site-footer__nav">
                    <section
                        v-for="group in displayGroups"
                        :key="group.id"
                        class="public-site-footer__group"
                        :class="{
                            'public-site-footer__group--wide': group.kind === 'mega',
                            'public-site-footer__group--split': group.layout === 'split',
                        }"
                    >
                        <h2 v-if="group.title" class="public-site-footer__group-title">
                            {{ group.title }}
                        </h2>

                        <div v-if="group.kind === 'mega'" class="public-site-footer__mega-columns">
                            <section
                                v-for="(column, columnIndex) in group.columns"
                                :key="column.id || `column-${columnIndex}`"
                                class="public-site-footer__column"
                            >
                                <h3 v-if="column.title" class="public-site-footer__column-title">
                                    {{ column.title }}
                                </h3>

                                <div
                                    v-for="(block, blockIndex) in column.blocks || []"
                                    :key="block.id || `block-${blockIndex}`"
                                    class="public-site-footer__block"
                                >
                                    <h4 v-if="blockHeading(block)" class="public-site-footer__block-title">
                                        {{ blockHeading(block) }}
                                    </h4>

                                    <ul v-if="hasBlockLinks(block)" class="public-site-footer__links">
                                        <li
                                            v-for="(entry, entryIndex) in blockLinks(block)"
                                            :key="`${block.id || blockIndex}-${entryIndex}`"
                                        >
                                            <component
                                                :is="shouldUseAnchor(entry.href) ? 'a' : Link"
                                                :href="entry.href"
                                                :target="linkTarget(entry)"
                                                :rel="linkRel(entry)"
                                                class="public-site-footer__link"
                                            >
                                                {{ entry.label }}
                                            </component>
                                            <div v-if="entry.note" class="public-site-footer__note">
                                                {{ entry.note }}
                                            </div>
                                        </li>
                                    </ul>

                                    <div v-else-if="blockImage(block)" class="public-site-footer__image-block">
                                        <component
                                            :is="blockImage(block)?.href !== '#' ? (shouldUseAnchor(blockImage(block)?.href) ? 'a' : Link) : 'div'"
                                            :href="blockImage(block)?.href !== '#' ? blockImage(block)?.href : undefined"
                                            class="public-site-footer__image-link"
                                        >
                                            <img
                                                :src="blockImage(block)?.src"
                                                :alt="blockImage(block)?.alt || blockHeading(block)"
                                                class="public-site-footer__image"
                                            >
                                        </component>
                                        <div v-if="blockImage(block)?.title" class="public-site-footer__note">
                                            {{ blockImage(block)?.title }}
                                        </div>
                                    </div>

                                    <div v-else-if="blockCallout(block)" class="public-site-footer__callout">
                                        <div v-if="blockCallout(block)?.eyebrow" class="public-site-footer__eyebrow">
                                            {{ blockCallout(block)?.eyebrow }}
                                        </div>
                                        <div v-if="blockCallout(block)?.title" class="public-site-footer__callout-title">
                                            {{ blockCallout(block)?.title }}
                                        </div>
                                        <div v-if="blockCallout(block)?.body" class="public-site-footer__callout-body">
                                            {{ blockCallout(block)?.body }}
                                        </div>
                                        <component
                                            v-if="blockCallout(block)?.ctaLabel"
                                            :is="shouldUseAnchor(blockCallout(block)?.ctaHref) ? 'a' : Link"
                                            :href="blockCallout(block)?.ctaHref"
                                            class="public-site-footer__callout-link"
                                        >
                                            {{ blockCallout(block)?.ctaLabel }}
                                        </component>
                                    </div>

                                    <div
                                        v-else-if="blockBody(block)"
                                        class="public-site-footer__rich-text"
                                        v-html="blockBody(block)"
                                    />
                                </div>
                            </section>
                        </div>

                        <ul v-else class="public-site-footer__links">
                            <li v-for="entry in group.links" :key="`${group.id}-${entry.label}`">
                                <component
                                    :is="shouldUseAnchor(entry.href) ? 'a' : Link"
                                    :href="entry.href"
                                    :target="linkTarget(entry)"
                                    :rel="linkRel(entry)"
                                    class="public-site-footer__link"
                                >
                                    {{ entry.label }}
                                </component>
                                <div v-if="entry.note" class="public-site-footer__note">
                                    {{ entry.note }}
                                </div>
                            </li>
                        </ul>
                    </section>
                </div>

                <aside class="public-site-footer__support">
                    <div v-if="supportCard.kicker" class="public-site-footer__eyebrow">
                        {{ supportCard.kicker }}
                    </div>
                    <h2 class="public-site-footer__group-title">
                        {{ supportCard.title }}
                    </h2>
                    <div class="public-site-footer__support-body" v-html="supportCard.body"></div>
                    <ul class="public-site-footer__support-list">
                        <li v-for="entry in supportCard.meta" :key="entry.label" class="public-site-footer__support-item">
                            <span class="public-site-footer__support-dot" aria-hidden="true"></span>
                            <span>{{ entry.label }}</span>
                        </li>
                    </ul>
                    <div class="public-site-footer__support-actions">
                        <component
                            v-for="action in supportCard.actions"
                            :key="action.label"
                            :is="shouldUseAnchor(action.href) ? 'a' : Link"
                            :href="action.href"
                            class="public-site-footer__support-link"
                        >
                            <span>{{ action.label }}</span>
                            <ArrowRight class="public-site-footer__support-link-icon" />
                        </component>
                    </div>
                </aside>
            </div>

            <div class="public-site-footer__bottom">
                <div class="public-site-footer__copy">
                    {{ footerCopy }} {{ new Date().getFullYear() }}
                </div>

                <div class="public-site-footer__legal">
                    <component
                        v-for="link in legalLinks"
                        :key="`legal-${link.label}`"
                        :is="shouldUseAnchor(link.href) ? 'a' : Link"
                        :href="link.href"
                        :target="linkTarget(link)"
                        :rel="linkRel(link)"
                        class="public-site-footer__legal-link"
                    >
                        {{ link.label }}
                    </component>
                </div>
            </div>
        </div>
    </footer>
</template>

<style scoped>
.public-site-footer {
    background:
        radial-gradient(circle at top center, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0) 24%),
        linear-gradient(
            135deg,
            var(--public-site-footer-background, #0d1d35) 0%,
            var(--public-site-footer-background-end, #0d5a46) 100%
        );
    color: rgba(255, 255, 255, 0.9);
}

.public-site-footer__inner {
    width: min(var(--public-shell-width, 88rem), 100%);
    margin-inline: auto;
    padding-inline: var(--public-shell-gutter, 1.25rem);
    padding-top: clamp(4rem, 8vw, 6rem);
    padding-bottom: 2.25rem;
}

.public-site-footer__brand-row {
    margin-bottom: 2.75rem;
    display: flex;
    justify-content: flex-start;
    padding-inline-start: 1.25rem;
}

.public-site-footer__brand {
    display: inline-flex;
    align-items: center;
}

.public-site-footer__brand-logo {
    width: 12rem;
    max-width: 100%;
    height: auto;
}

.public-site-footer__grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
}

.public-site-footer__nav {
    display: grid;
    gap: 1rem;
    min-width: 0;
}

.public-site-footer__group,
.public-site-footer__contact {
    min-width: 0;
    padding: 1.25rem;
}

.public-site-footer__support {
    min-width: 0;
    padding: 1.25rem;
    border: 1px solid rgba(148, 163, 184, 0.24);
    border-radius: 0.125rem;
    background: rgba(255, 255, 255, 0.06);
    box-shadow: 0 26px 42px -38px rgba(0, 0, 0, 0.4);
    backdrop-filter: blur(10px);
}

.public-site-footer__contact {
    display: grid;
    align-content: start;
    gap: 1.25rem;
}

.public-site-footer__contact-stack {
    display: grid;
    gap: 0.6rem;
}

.public-site-footer__contact-line {
    display: inline-flex;
    align-items: center;
    gap: 0.65rem;
    color: #ffffff;
    font-size: 1rem;
    line-height: 1.45;
    text-decoration: none;
}

.public-site-footer__contact-icon {
    width: 1rem;
    height: 1rem;
    color: rgba(255, 255, 255, 0.72);
}

.public-site-footer__socials {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
}

.public-site-footer__social-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: var(--page-radius, 4px);
    color: rgba(255, 255, 255, 0.86);
    text-decoration: none;
    transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
}

.public-site-footer__social-link:hover {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(255, 255, 255, 0.28);
    transform: translateY(-1px);
}

.public-site-footer__social-link--disabled {
    opacity: 0.42;
    cursor: default;
    pointer-events: none;
}

.public-site-footer__social-icon {
    width: 1rem;
    height: 1rem;
}

.public-site-footer__social-glyph {
    font-size: 0.92rem;
    font-weight: 700;
    line-height: 1;
}

.public-site-footer__store-links {
    display: grid;
    gap: 0.8rem;
    max-width: 12rem;
}

.public-site-footer__store-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.7rem 0.9rem;
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 0.125rem;
    background: rgba(255, 255, 255, 0.06);
    color: #ffffff;
    text-decoration: none;
    transition: transform 0.2s ease, border-color 0.2s ease, background 0.2s ease;
}

.public-site-footer__store-badge:hover {
    transform: translateY(-1px);
    border-color: rgba(255, 255, 255, 0.34);
    background: rgba(6, 9, 12, 1);
}

.public-site-footer__store-badge--disabled {
    opacity: 0.42;
    cursor: default;
    pointer-events: none;
}

.public-site-footer__store-icon-box {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.6rem;
    height: 1.6rem;
    flex-shrink: 0;
}

.public-site-footer__store-icon {
    width: 1.25rem;
    height: 1.25rem;
}

.public-site-footer__store-glyph {
    font-size: 1.15rem;
    font-weight: 700;
    line-height: 1;
}

.public-site-footer__store-copy {
    display: grid;
    gap: 0.1rem;
}

.public-site-footer__store-eyebrow {
    font-size: 0.58rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.72);
}

.public-site-footer__store-title {
    font-size: 1.05rem;
    font-weight: 700;
    line-height: 1.05;
}

.public-site-footer__group-title {
    margin: 0 0 1.15rem;
    color: #ffffff;
    font-family: 'Montserrat', var(--page-font-heading, 'Space Grotesk', sans-serif);
    font-size: 1.25rem;
    line-height: 1.1;
    letter-spacing: -0.03em;
}

.public-site-footer__links {
    display: grid;
    gap: 0.8rem 1.25rem;
}

.public-site-footer__group--split .public-site-footer__links {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.public-site-footer__link,
.public-site-footer__legal-link,
.public-site-footer__callout-link {
    color: rgba(255, 255, 255, 0.84);
    text-decoration: none;
    transition: color 0.2s ease, opacity 0.2s ease;
}

.public-site-footer__link {
    display: inline-flex;
    font-size: 0.98rem;
    line-height: 1.35;
}

.public-site-footer__link:hover,
.public-site-footer__legal-link:hover,
.public-site-footer__callout-link:hover {
    color: #ffffff;
}

.public-site-footer__note {
    margin-top: 0.28rem;
    color: rgba(255, 255, 255, 0.58);
    font-size: 0.82rem;
    line-height: 1.45;
}

.public-site-footer__mega-columns {
    display: grid;
    gap: 1.4rem;
}

.public-site-footer__column {
    display: grid;
    gap: 1.05rem;
}

.public-site-footer__column-title,
.public-site-footer__block-title {
    margin: 0;
    color: #a7f3d0;
    font-size: 0.74rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.public-site-footer__block {
    display: grid;
    gap: 0.8rem;
}

.public-site-footer__image-block {
    display: grid;
    gap: 0.7rem;
}

.public-site-footer__image-link {
    display: block;
    overflow: hidden;
    border-radius: var(--page-radius, 4px);
    background: rgba(255, 255, 255, 0.05);
}

.public-site-footer__image {
    display: block;
    width: 100%;
    height: auto;
}

.public-site-footer__callout {
    padding: 1rem;
    border: 1px solid rgba(148, 163, 184, 0.22);
    border-radius: 0.125rem;
    background: rgba(255, 255, 255, 0.05);
}

.public-site-footer__eyebrow {
    margin-bottom: 0.45rem;
    color: #a7f3d0;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.public-site-footer__callout-title {
    color: #ffffff;
    font-family: 'Cal Sans', var(--page-font-heading, 'Space Grotesk', sans-serif);
    font-size: 1.05rem;
    line-height: 1.1;
}

.public-site-footer__callout-body,
.public-site-footer__rich-text {
    color: rgba(255, 255, 255, 0.76);
    font-size: 0.9rem;
    line-height: 1.6;
}

.public-site-footer__callout-link {
    display: inline-flex;
    margin-top: 0.25rem;
    font-size: 0.9rem;
    font-weight: 700;
}

.public-site-footer__rich-text :deep(p),
.public-site-footer__rich-text :deep(div) {
    margin: 0 0 0.8rem;
}

.public-site-footer__rich-text :deep(p:last-child),
.public-site-footer__rich-text :deep(div:last-child) {
    margin-bottom: 0;
}

.public-site-footer__support {
    min-width: 0;
    width: 100%;
}

.public-site-footer__support-body {
    margin: 0;
    color: rgba(255, 255, 255, 0.78);
    font-size: 0.96rem;
    line-height: 1.65;
}

.public-site-footer__support-body :deep(p),
.public-site-footer__support-body :deep(div) {
    margin: 0 0 1rem;
}

.public-site-footer__support-body :deep(p:last-child),
.public-site-footer__support-body :deep(div:last-child) {
    margin-bottom: 0;
}

.public-site-footer__support-list {
    display: grid;
    gap: 0.85rem;
    margin-top: 1.25rem;
}

.public-site-footer__support-item {
    display: flex;
    align-items: flex-start;
    gap: 0.7rem;
    color: rgba(255, 255, 255, 0.84);
    font-size: 0.92rem;
    line-height: 1.5;
}

.public-site-footer__support-dot {
    width: 0.55rem;
    height: 0.55rem;
    margin-top: 0.42rem;
    border-radius: 999px;
    flex-shrink: 0;
    color: #8df08d;
    background: #8df08d;
}

.public-site-footer__support-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.85rem;
    margin-top: 1.35rem;
}

.public-site-footer__support-link {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.78rem 1rem;
    border: 1px solid rgba(148, 163, 184, 0.24);
    border-radius: 0.125rem;
    color: #ffffff;
    font-size: 0.9rem;
    font-weight: 700;
    text-decoration: none;
    transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
}

.public-site-footer__support-link:hover {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(255, 255, 255, 0.28);
    transform: translateY(-1px);
}

.public-site-footer__support-link-icon {
    width: 0.95rem;
    height: 0.95rem;
}

.public-site-footer__bottom {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    margin-top: 2.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid rgba(255, 255, 255, 0.14);
}

.public-site-footer__copy {
    color: rgba(255, 255, 255, 0.62);
    font-size: 0.88rem;
    line-height: 1.5;
}

.public-site-footer__legal {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem 1.1rem;
}

.public-site-footer__legal-link {
    font-size: 0.88rem;
}

@media (min-width: 768px) {
    .public-site-footer__nav {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .public-site-footer__mega-columns {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .public-site-footer__bottom {
        align-items: center;
        justify-content: space-between;
        flex-direction: row;
    }
}

@media (min-width: 1200px) {
    .public-site-footer__grid {
        grid-template-columns:
            minmax(220px, 16rem)
            minmax(0, 1fr)
            minmax(360px, 24rem);
        align-items: start;
        gap: 1rem;
    }

    .public-site-footer__nav {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .public-site-footer__group--wide {
        grid-column: span 2;
    }

    .public-site-footer__contact {
        padding-right: 0.5rem;
    }

    .public-site-footer__support {
        grid-column: auto;
    }
}
</style>
