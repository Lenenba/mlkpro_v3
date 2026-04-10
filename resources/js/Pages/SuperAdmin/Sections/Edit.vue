<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingNumberInput from '@/Components/FloatingNumberInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import AssetPickerModal from '@/Components/AssetPickerModal.vue';
import { backgroundPresetKeys } from '@/utils/backgroundPresets';
import {
    createIndustryCard,
    defaultIndustryCards,
    ensureIndustryCards,
    industryIconOptions,
    resolveIndustryIconComponent,
} from '@/utils/industryGrid';
import {
    createTestimonialCard,
    defaultTestimonialCards,
    ensureTestimonialCards,
} from '@/utils/testimonialGrid';
import {
    createStoryCard,
    defaultStoryCards,
    ensureStoryCards,
} from '@/utils/storyGrid';
import {
    createFeatureTabChild,
    createFeatureTab,
    defaultFeatureTabsShowcaseSection,
    ensureFeatureTabs,
    featureTabIconOptions,
    normalizeFeatureTabsStyle,
    normalizeFeatureTabsTriggerFontSize,
    resolveFeatureTabIconComponent,
} from '@/utils/featureTabs';

const props = defineProps({
    mode: { type: String, default: 'edit' },
    section: { type: Object, default: () => ({ id: null, name: '', type: 'generic', is_active: true }) },
    locales: { type: Array, default: () => ['fr', 'en'] },
    default_locale: { type: String, default: 'fr' },
    content: { type: Object, default: () => ({}) },
    meta: { type: Object, default: () => ({ updated_at: null, updated_by: null }) },
    dashboard_url: { type: String, required: true },
    index_url: { type: String, required: true },
    types: { type: Array, default: () => [] },
    asset_list_url: { type: String, default: '' },
    ai_enabled: { type: Boolean, default: false },
    ai_image_generate_url: { type: String, default: '' },
});

const { t } = useI18n();
const currentLocale = ref(props.default_locale || props.locales[0] || 'fr');

const form = useForm({
    name: props.section.name || '',
    type: props.section.type || 'generic',
    is_active: props.section.is_active ?? true,
    locale: currentLocale.value,
    content: {},
});

const itemsLines = ref('');
const assetPickerOpen = ref(false);
const assetPickerTarget = ref({ target: null, urlKey: 'image_url', altKey: 'image_alt' });
const industryCardIconOptions = computed(() => [
    { value: '', label: t('super_admin.pages.industry_grid.icon_auto') },
    ...industryIconOptions,
]);
const featureTabIconSelectOptions = computed(() => [
    { value: '', label: t('super_admin.pages.feature_tabs.icon_auto') },
    ...featureTabIconOptions,
]);
const featureTabStyleSelectOptions = computed(() => [
    { value: 'editorial', label: t('super_admin.pages.feature_tabs.styles.editorial') },
    { value: 'workflow', label: t('super_admin.pages.feature_tabs.styles.workflow') },
]);

const typeOptions = computed(() =>
    (props.types || []).map((type) => ({
        value: type,
        label: t(`super_admin.sections.types.${type}`),
    }))
);

const localeOptions = computed(() =>
    (props.locales || []).map((locale) => ({ value: locale, label: locale.toUpperCase() }))
);

const imagePositionOptions = computed(() => [
    { value: 'left', label: t('super_admin.pages.image_positions.left') },
    { value: 'right', label: t('super_admin.pages.image_positions.right') },
]);

const showcaseDividerStyleOptions = computed(() => [
    { value: 'diagonal', label: t('super_admin.pages.showcase_divider_styles.diagonal') },
    { value: 'vertical', label: t('super_admin.pages.showcase_divider_styles.vertical') },
    { value: 'round', label: t('super_admin.pages.showcase_divider_styles.round') },
    { value: 'notch', label: t('super_admin.pages.showcase_divider_styles.notch') },
    { value: 'glow', label: t('super_admin.pages.showcase_divider_styles.glow') },
]);

const alignmentOptions = computed(() => [
    { value: 'left', label: t('super_admin.pages.alignments.left') },
    { value: 'center', label: t('super_admin.pages.alignments.center') },
    { value: 'right', label: t('super_admin.pages.alignments.right') },
]);

const densityOptions = computed(() => [
    { value: 'compact', label: t('super_admin.pages.densities.compact') },
    { value: 'normal', label: t('super_admin.pages.densities.normal') },
    { value: 'spacious', label: t('super_admin.pages.densities.spacious') },
]);

const toneOptions = computed(() => [
    { value: 'default', label: t('super_admin.pages.tones.default') },
    { value: 'muted', label: t('super_admin.pages.tones.muted') },
    { value: 'contrast', label: t('super_admin.pages.tones.contrast') },
]);

const backgroundPresetOptions = computed(() => [
    { value: '', label: t('super_admin.pages.common.background_presets.none') },
    ...backgroundPresetKeys.map((preset) => ({
        value: preset,
        label: t(`super_admin.pages.common.background_presets.${preset.replace(/-/g, '_')}`),
    })),
]);

const footerGroupLayoutOptions = computed(() => [
    { value: 'stack', label: t('super_admin.pages.footer.group_layout_stack') },
    { value: 'split', label: t('super_admin.pages.footer.group_layout_split') },
]);

const editorLabels = computed(() => ({
    heading2: t('super_admin.support.editor.heading_2'),
    heading3: t('super_admin.support.editor.heading_3'),
    bold: t('super_admin.support.editor.bold'),
    italic: t('super_admin.support.editor.italic'),
    underline: t('super_admin.support.editor.underline'),
    unorderedList: t('super_admin.support.editor.unordered_list'),
    orderedList: t('super_admin.support.editor.ordered_list'),
    quote: t('super_admin.support.editor.quote'),
    codeBlock: t('super_admin.support.editor.code_block'),
    horizontalRule: t('super_admin.support.editor.horizontal_rule'),
    link: t('super_admin.support.editor.link'),
    image: t('super_admin.support.editor.image'),
    aiImage: t('super_admin.support.editor.ai_image'),
    clear: t('super_admin.support.editor.clear'),
}));

const editorLinkPrompt = computed(() => t('super_admin.support.editor.link_prompt'));
const editorImagePrompt = computed(() => t('super_admin.support.editor.image_prompt'));
const editorAiPrompt = computed(() => t('super_admin.support.editor.ai_prompt'));
const parseLines = (value) =>
    String(value || '')
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line.length > 0);

const createLocalId = (prefix) => `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;

const createStatItem = (overrides = {}) => ({
    value: overrides.value || '',
    label: overrides.label || '',
});

const ensureStatItems = (items) => (
    Array.isArray(items) ? items.map((item) => createStatItem(item)) : []
);

const createHeroImage = (overrides = {}) => ({
    id: overrides.id || createLocalId('hero-image'),
    image_url: overrides.image_url || '',
    image_alt: overrides.image_alt || '',
});

const ensureHeroImages = (items) => (
    Array.isArray(items) ? items.map((item) => createHeroImage(item)) : []
);

const defaultWelcomeHeroSlides = (locale) => {
    const isFrench = locale === 'fr';

    return ensureHeroImages([
        {
            image_url: '/images/landing/stock/team-laptop-window.jpg',
            image_alt: isFrench ? 'Equipe qui prepare l accueil et la planification' : 'Team preparing scheduling and reception',
        },
        {
            image_url: '/images/landing/stock/service-team.jpg',
            image_alt: isFrench ? 'Equipe service en coordination' : 'Service team coordinating',
        },
        {
            image_url: '/images/landing/stock/desk-phone-laptop.jpg',
            image_alt: isFrench ? 'Responsable commerciale au bureau' : 'Sales lead at a desk',
        },
        {
            image_url: '/images/landing/stock/service-tablet.jpg',
            image_alt: isFrench ? 'Coordination des rendez vous sur tablette' : 'Scheduling coordination on a tablet',
        },
        {
            image_url: '/images/landing/stock/collab-laptop-desk.jpg',
            image_alt: isFrench ? 'Collegues en atelier autour d un ordinateur' : 'Colleagues collaborating around a laptop',
        },
        {
            image_url: '/images/landing/stock/store-worker.jpg',
            image_alt: isFrench ? 'Preparation des commandes et du stock' : 'Order and inventory preparation',
        },
        {
            image_url: '/images/landing/stock/marketing-desk.jpg',
            image_alt: isFrench ? 'Gestion des campagnes et messages' : 'Campaign and messaging management',
        },
        {
            image_url: '/images/landing/stock/meeting-room-laptops.jpg',
            image_alt: isFrench ? 'Equipe en reunion autour de plusieurs ecrans' : 'Team meeting around multiple screens',
        },
    ]);
};

const createPreviewCard = (overrides = {}) => ({
    title: overrides.title || '',
    desc: overrides.desc || '',
});

const ensurePreviewCards = (items) => (
    Array.isArray(items) ? items.map((item) => createPreviewCard(item)) : []
);

const createFeatureItem = (overrides = {}) => ({
    key: overrides.key || createLocalId('feature-item'),
    title: overrides.title || '',
    desc: overrides.desc || '',
});

const ensureFeatureItems = (items) => (
    Array.isArray(items) ? items.map((item) => createFeatureItem(item)) : []
);

const createFooterLink = (label = '', href = '', note = '') => ({
    id: createLocalId('footer-link'),
    label,
    href,
    note,
});

const ensureFooterLinks = (items) => {
    if (!Array.isArray(items)) {
        return [];
    }

    return items.map((item) => ({
        id: item?.id || createLocalId('footer-link'),
        label: item?.label || '',
        href: item?.href || '',
        note: item?.note || '',
    }));
};

const defaultFooterGroups = (locale) => {
    if (locale === 'fr') {
        return [
            {
                id: createLocalId('footer-group'),
                title: 'Industries desservies',
                layout: 'stack',
                links: ensureFooterLinks([
                    createFooterLink('Plomberie', '/pages/industry-plumbing'),
                    createFooterLink('HVAC', '/pages/industry-hvac'),
                    createFooterLink('Electricite', '/pages/industry-electrical'),
                    createFooterLink('Entretien menager', '/pages/industry-cleaning'),
                    createFooterLink('Salon & beaute', '/pages/industry-salon-beauty'),
                    createFooterLink('Restaurant', '/pages/industry-restaurant'),
                ]),
            },
            {
                id: createLocalId('footer-group'),
                title: 'Produits',
                layout: 'stack',
                links: ensureFooterLinks([
                    createFooterLink('Sales & CRM', '/pages/sales-crm'),
                    createFooterLink('Reservations', '/pages/reservations'),
                    createFooterLink('Operations', '/pages/operations'),
                    createFooterLink('Commerce', '/pages/commerce'),
                    createFooterLink('Marketing & Loyalty', '/pages/marketing-loyalty'),
                    createFooterLink('AI & Automation', '/pages/ai-automation'),
                    createFooterLink('Command Center', '/pages/command-center'),
                ]),
            },
            {
                id: createLocalId('footer-group'),
                title: 'Ressources',
                layout: 'stack',
                links: ensureFooterLinks([
                    createFooterLink('Tarification', '/pricing'),
                    createFooterLink('Conditions', '/terms'),
                    createFooterLink('Confidentialite', '/privacy'),
                    createFooterLink('Remboursement', '/refund'),
                    createFooterLink('Contact', '/pages/contact-us'),
                ]),
            },
        ];
    }

    return [
        {
            id: createLocalId('footer-group'),
            title: 'Industries We Serve',
            layout: 'stack',
            links: ensureFooterLinks([
                createFooterLink('Plumbing', '/pages/industry-plumbing'),
                createFooterLink('HVAC', '/pages/industry-hvac'),
                createFooterLink('Electrical', '/pages/industry-electrical'),
                createFooterLink('Cleaning', '/pages/industry-cleaning'),
                createFooterLink('Salon & Beauty', '/pages/industry-salon-beauty'),
                createFooterLink('Restaurant', '/pages/industry-restaurant'),
            ]),
        },
        {
            id: createLocalId('footer-group'),
            title: 'Products',
            layout: 'stack',
            links: ensureFooterLinks([
                createFooterLink('Sales & CRM', '/pages/sales-crm'),
                createFooterLink('Reservations', '/pages/reservations'),
                createFooterLink('Operations', '/pages/operations'),
                createFooterLink('Commerce', '/pages/commerce'),
                createFooterLink('Marketing & Loyalty', '/pages/marketing-loyalty'),
                createFooterLink('AI & Automation', '/pages/ai-automation'),
                createFooterLink('Command Center', '/pages/command-center'),
            ]),
        },
        {
            id: createLocalId('footer-group'),
            title: 'Resources',
            layout: 'stack',
            links: ensureFooterLinks([
                createFooterLink('Pricing', '/pricing'),
                createFooterLink('Terms', '/terms'),
                createFooterLink('Privacy', '/privacy'),
                createFooterLink('Refund', '/refund'),
                createFooterLink('Contact us', '/pages/contact-us'),
            ]),
        },
    ];
};

const defaultFooterLegalLinks = (locale) => (
    locale === 'fr'
        ? ensureFooterLinks([
            createFooterLink('Tarification', '/pricing'),
            createFooterLink('Conditions', '/terms'),
            createFooterLink('Confidentialite', '/privacy'),
            createFooterLink('Remboursement', '/refund'),
        ])
        : ensureFooterLinks([
            createFooterLink('Pricing', '/pricing'),
            createFooterLink('Terms', '/terms'),
            createFooterLink('Privacy', '/privacy'),
            createFooterLink('Refund', '/refund'),
        ])
);

const createFooterGroup = () => ({
    id: createLocalId('footer-group'),
    title: '',
    layout: 'stack',
    links: [createFooterLink()],
});

const ensureFooterGroups = (items) => {
    if (!Array.isArray(items)) {
        return [];
    }

    return items.map((item) => ({
        id: item?.id || createLocalId('footer-group'),
        title: item?.title || '',
        layout: item?.layout === 'split' ? 'split' : 'stack',
        links: ensureFooterLinks(item?.links),
    }));
};

const sectionTypePreset = (type) => {
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
            title: currentLocale.value === 'fr'
                ? 'Demarrez un essai. Voyez si cela colle a votre operation.'
                : 'Start a trial. See how it fits your operation.',
            body: currentLocale.value === 'fr'
                ? '<p>Presentez votre plateforme, votre visite produit ou votre experience mobile avec un bloc plus editorial et plus vendeur.</p>'
                : '<p>Showcase your platform, product tour, or mobile experience with a more editorial conversion block.</p>',
            primary_label: currentLocale.value === 'fr' ? "Demarrer l'essai" : 'Start trial',
            aside_link_label: currentLocale.value === 'fr' ? 'Voir la visite produit' : 'Watch product tour',
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
            title: currentLocale.value === 'fr'
                ? 'Fier partenaire des services a domicile dans plus de 50 industries.'
                : 'Proud partner to home services in over 50 industries.',
            primary_label: currentLocale.value === 'fr' ? 'Voir toutes les industries' : 'See All Industries',
            industry_cards: defaultIndustryCards(currentLocale.value),
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
            title: currentLocale.value === 'fr'
                ? 'Une IA pensee pour les entreprises de terrain.'
                : 'AI built for blue-collar businesses',
            story_cards: defaultStoryCards(currentLocale.value),
        };
    }

    if (type === 'feature_tabs') {
        const showcaseSection = defaultFeatureTabsShowcaseSection(currentLocale.value);

        return {
            ...showcaseSection,
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
            hero_images: defaultWelcomeHeroSlides(currentLocale.value),
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
            kicker: currentLocale.value === 'fr' ? 'Accompagnement' : 'Support',
            title: currentLocale.value === 'fr' ? 'Parlez a notre equipe' : 'Talk to our team',
            body: currentLocale.value === 'fr'
                ? '<p>Besoin d un parcours produit plus precis ou d une page publique sur mesure ? On peut vous guider.</p>'
                : '<p>Need a sharper product journey or a custom public page setup? Our team can help.</p>',
            items: currentLocale.value === 'fr'
                ? [
                    'Parcours public et modules metier',
                    'Support produit et accompagnement',
                    'Disponible en francais et en anglais',
                ]
                : [
                    'Public pages and business modules',
                    'Product support and enablement',
                    'Available in French and English',
                ],
            primary_label: currentLocale.value === 'fr' ? 'Nous contacter' : 'Contact us',
            primary_href: '/pages/contact-us',
            secondary_label: currentLocale.value === 'fr' ? 'Voir les tarifs' : 'View pricing',
            secondary_href: '/pricing',
            copy: currentLocale.value === 'fr' ? 'Tous droits reserves.' : 'All rights reserved.',
            contact_phone: '',
            contact_email: '',
            social_facebook_href: '',
            social_x_href: '',
            social_instagram_href: '',
            social_youtube_href: '',
            social_linkedin_href: '',
            google_play_href: '',
            app_store_href: '',
            footer_groups: defaultFooterGroups(currentLocale.value),
            legal_links: defaultFooterLegalLinks(currentLocale.value),
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
            title: currentLocale.value === 'fr'
                ? 'Approuve par les meilleures equipes d entretien.'
                : 'Trusted by the best cleaning teams.',
            body: currentLocale.value === 'fr'
                ? '<p>Les pros de l entretien utilisent MLK Pro pour simplifier la planification, suivre les preferences clients et mieux coordonner leur equipe.</p>'
                : '<p>Cleaning pros use MLK Pro to simplify scheduling, track client preferences, and coordinate their crews with less friction.</p>',
            testimonial_cards: defaultTestimonialCards(currentLocale.value),
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

const ensureStructure = (content, type = form.type) => {
    const preset = sectionTypePreset(type);

    return {
        layout: content?.layout || preset.layout,
        background_color: content?.background_color ?? preset.background_color,
        background_preset: content?.background_preset ?? preset.background_preset ?? '',
        title_color: content?.title_color ?? preset.title_color ?? '',
        body_color: content?.body_color ?? preset.body_color ?? '',
        image_position: content?.image_position || preset.image_position,
        alignment: content?.alignment || preset.alignment,
        density: content?.density || preset.density,
        tone: content?.tone || preset.tone,
        kicker: content?.kicker || '',
        title: content?.title ?? preset.title ?? '',
        body: content?.body || '',
        note: content?.note || '',
        title_font_size: Number(content?.title_font_size) > 0 ? Number(content.title_font_size) : Number(preset.title_font_size || 0),
        stats: Array.isArray(content?.stats) ? ensureStatItems(content.stats) : ensureStatItems(preset.stats),
        hero_images: Array.isArray(content?.hero_images) ? ensureHeroImages(content.hero_images) : ensureHeroImages(preset.hero_images),
        preview_cards: Array.isArray(content?.preview_cards) ? ensurePreviewCards(content.preview_cards) : ensurePreviewCards(preset.preview_cards),
        feature_items: Array.isArray(content?.feature_items) ? ensureFeatureItems(content.feature_items) : ensureFeatureItems(preset.feature_items),
        secondary_enabled: content?.secondary_enabled ?? preset.secondary_enabled ?? false,
        secondary_background_color: content?.secondary_background_color ?? preset.secondary_background_color ?? '',
        secondary_kicker: content?.secondary_kicker ?? preset.secondary_kicker ?? '',
        secondary_title: content?.secondary_title ?? preset.secondary_title ?? '',
        secondary_body: content?.secondary_body ?? preset.secondary_body ?? '',
        secondary_badge: content?.secondary_badge ?? preset.secondary_badge ?? '',
        secondary_feature_items: Array.isArray(content?.secondary_feature_items) ? ensureFeatureItems(content.secondary_feature_items) : ensureFeatureItems(preset.secondary_feature_items),
        industry_cards: Array.isArray(content?.industry_cards) ? ensureIndustryCards(content.industry_cards) : ensureIndustryCards(preset.industry_cards),
        story_cards: Array.isArray(content?.story_cards) ? ensureStoryCards(content.story_cards) : ensureStoryCards(preset.story_cards),
        feature_tabs: Array.isArray(content?.feature_tabs) ? ensureFeatureTabs(content.feature_tabs) : ensureFeatureTabs(preset.feature_tabs),
        feature_tabs_style: normalizeFeatureTabsStyle(content?.feature_tabs_style ?? preset.feature_tabs_style),
        feature_tabs_font_size: normalizeFeatureTabsTriggerFontSize(content?.feature_tabs_font_size ?? preset.feature_tabs_font_size),
        testimonial_cards: Array.isArray(content?.testimonial_cards) ? ensureTestimonialCards(content.testimonial_cards) : ensureTestimonialCards(preset.testimonial_cards),
        items: Array.isArray(content?.items) ? content.items : [],
        testimonial_author: content?.testimonial_author || '',
        testimonial_role: content?.testimonial_role || '',
        aside_kicker: content?.aside_kicker || '',
        aside_title: content?.aside_title || '',
        aside_body: content?.aside_body || '',
        aside_items: Array.isArray(content?.aside_items) ? content.aside_items : [],
        aside_link_label: content?.aside_link_label || '',
        aside_link_href: content?.aside_link_href || '',
        aside_image_url: content?.aside_image_url || '',
        aside_image_alt: content?.aside_image_alt || '',
        image_url: content?.image_url || '',
        image_alt: content?.image_alt || '',
        primary_label: content?.primary_label ?? preset.primary_label ?? '',
        primary_href: content?.primary_href || '',
        secondary_label: content?.secondary_label || '',
        secondary_href: content?.secondary_href || '',
        showcase_badge_label: content?.showcase_badge_label ?? preset.showcase_badge_label ?? '',
        showcase_badge_value: content?.showcase_badge_value ?? preset.showcase_badge_value ?? '',
        showcase_badge_note: content?.showcase_badge_note ?? preset.showcase_badge_note ?? '',
        showcase_divider_style: content?.showcase_divider_style ?? preset.showcase_divider_style ?? 'diagonal',
        copy: content?.copy ?? preset.copy ?? '',
        brand_logo_url: content?.brand_logo_url ?? preset.brand_logo_url ?? '',
        brand_logo_alt: content?.brand_logo_alt ?? preset.brand_logo_alt ?? '',
        brand_href: content?.brand_href ?? preset.brand_href ?? '',
        contact_phone: content?.contact_phone ?? preset.contact_phone ?? '',
        contact_email: content?.contact_email ?? preset.contact_email ?? '',
        social_facebook_href: content?.social_facebook_href ?? preset.social_facebook_href ?? '',
        social_x_href: content?.social_x_href ?? preset.social_x_href ?? '',
        social_instagram_href: content?.social_instagram_href ?? preset.social_instagram_href ?? '',
        social_youtube_href: content?.social_youtube_href ?? preset.social_youtube_href ?? '',
        social_linkedin_href: content?.social_linkedin_href ?? preset.social_linkedin_href ?? '',
        google_play_href: content?.google_play_href ?? preset.google_play_href ?? '',
        app_store_href: content?.app_store_href ?? preset.app_store_href ?? '',
        footer_groups: Array.isArray(content?.footer_groups) ? ensureFooterGroups(content.footer_groups) : ensureFooterGroups(preset.footer_groups),
        legal_links: Array.isArray(content?.legal_links) ? ensureFooterLinks(content.legal_links) : ensureFooterLinks(preset.legal_links),
    };
};

const syncLinesFromContent = () => {
    itemsLines.value = (form.content.items || []).join('\n');
};

const syncFormFromProps = (locale = currentLocale.value) => {
    const incoming = props.content?.[locale] || {};
    form.locale = locale;
    form.content = ensureStructure(incoming, form.type);
    syncLinesFromContent();
};

watch(
    () => props.content,
    () => syncFormFromProps(currentLocale.value),
    { deep: true }
);

watch(currentLocale, (locale) => syncFormFromProps(locale));
watch(itemsLines, (value) => {
    form.content.items = parseLines(value);
});

watch(
    () => form.type,
    (type, previousType) => {
        const current = ensureStructure(form.content, type);
        const previous = sectionTypePreset(previousType);
        const next = sectionTypePreset(type);

        form.content = {
            ...current,
            layout: next.layout,
            image_position: current.image_position === previous.image_position ? next.image_position : current.image_position,
            background_color: current.background_color === previous.background_color ? next.background_color : current.background_color,
            background_preset: current.background_preset === (previous.background_preset ?? '') ? (next.background_preset ?? '') : current.background_preset,
            title_color: current.title_color || next.title_color || '',
            body_color: current.body_color || next.body_color || '',
            tone: current.tone === previous.tone ? next.tone : current.tone,
            alignment: current.alignment === previous.alignment ? next.alignment : current.alignment,
            kicker: current.kicker || next.kicker || '',
            title: current.title || next.title || '',
            body: current.body || next.body || '',
            note: current.note || next.note || '',
            title_font_size: current.title_font_size || next.title_font_size || 0,
            items: current.items?.length ? current.items : (Array.isArray(next.items) ? [...next.items] : []),
            stats: current.stats?.length ? current.stats : ensureStatItems(next.stats),
            hero_images: current.hero_images?.length ? current.hero_images : ensureHeroImages(next.hero_images),
            preview_cards: current.preview_cards?.length ? current.preview_cards : ensurePreviewCards(next.preview_cards),
            feature_items: current.feature_items?.length ? current.feature_items : ensureFeatureItems(next.feature_items),
            secondary_enabled: current.secondary_enabled ?? next.secondary_enabled ?? false,
            secondary_background_color: current.secondary_background_color || next.secondary_background_color || '',
            secondary_kicker: current.secondary_kicker || next.secondary_kicker || '',
            secondary_title: current.secondary_title || next.secondary_title || '',
            secondary_body: current.secondary_body || next.secondary_body || '',
            secondary_badge: current.secondary_badge || next.secondary_badge || '',
            secondary_feature_items: current.secondary_feature_items?.length ? current.secondary_feature_items : ensureFeatureItems(next.secondary_feature_items),
            primary_label: current.primary_label || next.primary_label || '',
            primary_href: current.primary_href || next.primary_href || '',
            secondary_label: current.secondary_label || next.secondary_label || '',
            secondary_href: current.secondary_href || next.secondary_href || '',
            showcase_badge_label: current.showcase_badge_label || next.showcase_badge_label || '',
            showcase_badge_value: current.showcase_badge_value || next.showcase_badge_value || '',
            showcase_badge_note: current.showcase_badge_note || next.showcase_badge_note || '',
            showcase_divider_style: current.showcase_divider_style === (previous.showcase_divider_style ?? 'diagonal')
                ? (next.showcase_divider_style ?? 'diagonal')
                : (current.showcase_divider_style || 'diagonal'),
            copy: current.copy || next.copy || '',
            brand_logo_url: current.brand_logo_url || next.brand_logo_url || '',
            brand_logo_alt: current.brand_logo_alt || next.brand_logo_alt || '',
            brand_href: current.brand_href || next.brand_href || '',
            contact_phone: current.contact_phone || next.contact_phone || '',
            contact_email: current.contact_email || next.contact_email || '',
            social_facebook_href: current.social_facebook_href || next.social_facebook_href || '',
            social_x_href: current.social_x_href || next.social_x_href || '',
            social_instagram_href: current.social_instagram_href || next.social_instagram_href || '',
            social_youtube_href: current.social_youtube_href || next.social_youtube_href || '',
            social_linkedin_href: current.social_linkedin_href || next.social_linkedin_href || '',
            google_play_href: current.google_play_href || next.google_play_href || '',
            app_store_href: current.app_store_href || next.app_store_href || '',
            footer_groups: current.footer_groups?.length ? current.footer_groups : ensureFooterGroups(next.footer_groups),
            legal_links: current.legal_links?.length ? current.legal_links : ensureFooterLinks(next.legal_links),
            industry_cards: current.industry_cards?.length ? current.industry_cards : ensureIndustryCards(next.industry_cards),
            story_cards: current.story_cards?.length ? current.story_cards : ensureStoryCards(next.story_cards),
            feature_tabs: current.feature_tabs?.length ? current.feature_tabs : ensureFeatureTabs(next.feature_tabs),
            feature_tabs_style: current.feature_tabs_style === previous.feature_tabs_style
                ? normalizeFeatureTabsStyle(next.feature_tabs_style)
                : normalizeFeatureTabsStyle(current.feature_tabs_style),
            feature_tabs_font_size: current.feature_tabs_font_size === previous.feature_tabs_font_size
                ? normalizeFeatureTabsTriggerFontSize(next.feature_tabs_font_size)
                : normalizeFeatureTabsTriggerFontSize(current.feature_tabs_font_size),
            testimonial_cards: current.testimonial_cards?.length ? current.testimonial_cards : ensureTestimonialCards(next.testimonial_cards),
        };

        syncLinesFromContent();
    }
);

const isDuoType = computed(() => form.type === 'duo');
const isTestimonialType = computed(() => form.type === 'testimonial');
const isFeaturePairsType = computed(() => form.type === 'feature_pairs');
const isShowcaseCtaType = computed(() => form.type === 'showcase_cta');
const isIndustryGridType = computed(() => form.type === 'industry_grid');
const isStoryGridType = computed(() => form.type === 'story_grid');
const isFeatureTabsType = computed(() => form.type === 'feature_tabs');
const isTestimonialGridType = computed(() => form.type === 'testimonial_grid');
const isFooterType = computed(() => form.type === 'footer');
const isWelcomeHeroType = computed(() => form.type === 'welcome_hero');
const isWelcomeTrustType = computed(() => form.type === 'welcome_trust');
const isWelcomeFeaturesType = computed(() => form.type === 'welcome_features');
const isWelcomeWorkflowType = computed(() => form.type === 'welcome_workflow');
const isWelcomeFieldType = computed(() => form.type === 'welcome_field');
const isWelcomeCtaType = computed(() => form.type === 'welcome_cta');
const isWelcomeCustomType = computed(() => form.type === 'welcome_custom');
const usesEnhancedLayout = computed(() => (
    isDuoType.value
    || isTestimonialType.value
    || isFeaturePairsType.value
    || isShowcaseCtaType.value
    || isIndustryGridType.value
    || isStoryGridType.value
    || isFeatureTabsType.value
    || isTestimonialGridType.value
    || isWelcomeHeroType.value
    || isWelcomeTrustType.value
    || isWelcomeFeaturesType.value
    || isWelcomeWorkflowType.value
    || isWelcomeFieldType.value
    || isWelcomeCtaType.value
    || isWelcomeCustomType.value
));
const showImagePosition = computed(() => isDuoType.value || isTestimonialType.value || isShowcaseCtaType.value);
const showItemsField = computed(() => (
    !isFooterType.value
    && !isTestimonialType.value
    && !isShowcaseCtaType.value
    && !isIndustryGridType.value
    && !isStoryGridType.value
    && !isFeatureTabsType.value
    && !isTestimonialGridType.value
    && !isWelcomeFeaturesType.value
    && !isWelcomeWorkflowType.value
    && !isWelcomeCtaType.value
));
const itemsFieldLabel = computed(() => (
    isFooterType.value
        ? t('super_admin.pages.footer.items_label')
        : isWelcomeHeroType.value
            ? t('super_admin.sections.welcome.hero.highlights')
        : t('super_admin.pages.fields.items')
));
const showImageFields = computed(() => (
    !isShowcaseCtaType.value
    && !isIndustryGridType.value
    && !isStoryGridType.value
    && !isFeatureTabsType.value
    && !isTestimonialGridType.value
    && !isFooterType.value
    && !isWelcomeFeaturesType.value
    && !isWelcomeTrustType.value
    && !isWelcomeCtaType.value
));
const showActionFields = computed(() => (
    !isFooterType.value
    && !isWelcomeTrustType.value
    && !isWelcomeFeaturesType.value
    && !isWelcomeWorkflowType.value
));
const footerSocialCount = computed(() => (
    [
        'social_facebook_href',
        'social_x_href',
        'social_instagram_href',
        'social_youtube_href',
        'social_linkedin_href',
    ].filter((key) => String(form.content?.[key] || '').trim().length > 0).length
));
const footerStoreCount = computed(() => (
    ['google_play_href', 'app_store_href']
        .filter((key) => String(form.content?.[key] || '').trim().length > 0)
        .length
));
const footerNavLinkCount = computed(() => (
    (Array.isArray(form.content?.footer_groups) ? form.content.footer_groups : []).reduce(
        (total, group) => total + (Array.isArray(group?.links) ? group.links.length : 0),
        0
    )
));
const footerSupportItemCount = computed(() => (Array.isArray(form.content?.items) ? form.content.items.length : 0));

const backgroundFieldLabel = computed(() => (
    isDuoType.value
        ? t('super_admin.pages.common.panel_background_hex')
        : t('super_admin.pages.common.background_hex')
));

const backgroundHeadingLabel = computed(() => (
    isDuoType.value
        ? t('super_admin.pages.common.panel_background')
        : t('super_admin.pages.common.background')
));

const updatedAtLabel = computed(() => {
    if (!props.meta?.updated_at) return t('super_admin.sections.meta.never');
    const date = new Date(props.meta.updated_at);
    return Number.isNaN(date.getTime()) ? props.meta.updated_at : date.toLocaleString();
});

const updatedByLabel = computed(() => props.meta?.updated_by?.name || props.meta?.updated_by?.email || '');

const titleLabel = computed(() =>
    props.mode === 'create' ? t('super_admin.sections.edit.title_create') : t('super_admin.sections.edit.title_edit')
);

const submit = () => {
    if (props.mode === 'create') {
        form.post(route('superadmin.sections.store'), { preserveScroll: true });
        return;
    }
    if (!props.section?.id) return;
    form.put(route('superadmin.sections.update', props.section.id), { preserveScroll: true });
};

const openAssetPicker = (target = form.content, urlKey = 'image_url', altKey = 'image_alt') => {
    if (!props.asset_list_url) return;
    assetPickerTarget.value = { target, urlKey, altKey };
    assetPickerOpen.value = true;
};

const closeAssetPicker = () => {
    assetPickerOpen.value = false;
};

const handleAssetSelect = (asset) => {
    if (!asset) {
        return;
    }
    const { target, urlKey, altKey } = assetPickerTarget.value || { target: form.content, urlKey: 'image_url', altKey: 'image_alt' };
    const destination = target || form.content;
    destination[urlKey] = asset.url || '';
    if (!destination[altKey]) {
        destination[altKey] = asset.alt || asset.name || '';
    }
    closeAssetPicker();
};

const addFooterGroup = () => {
    form.content.footer_groups = [...(form.content.footer_groups || []), createFooterGroup()];
};

const moveFooterGroup = (index, direction) => {
    const nextIndex = index + direction;
    if (!form.content.footer_groups || nextIndex < 0 || nextIndex >= form.content.footer_groups.length) {
        return;
    }

    const groups = [...form.content.footer_groups];
    const [group] = groups.splice(index, 1);
    groups.splice(nextIndex, 0, group);
    form.content.footer_groups = groups;
};

const removeFooterGroup = (index) => {
    if (!form.content.footer_groups) {
        return;
    }

    form.content.footer_groups.splice(index, 1);
};

const addFooterGroupLink = (group) => {
    if (!group) {
        return;
    }

    group.links = [...(group.links || []), createFooterLink()];
};

const moveFooterGroupLink = (group, index, direction) => {
    const nextIndex = index + direction;
    if (!group?.links || nextIndex < 0 || nextIndex >= group.links.length) {
        return;
    }

    const links = [...group.links];
    const [link] = links.splice(index, 1);
    links.splice(nextIndex, 0, link);
    group.links = links;
};

const removeFooterGroupLink = (group, index) => {
    if (!group?.links) {
        return;
    }

    group.links.splice(index, 1);
};

const addFooterLegalLink = () => {
    form.content.legal_links = [...(form.content.legal_links || []), createFooterLink()];
};

const moveFooterLegalLink = (index, direction) => {
    const nextIndex = index + direction;
    if (!form.content.legal_links || nextIndex < 0 || nextIndex >= form.content.legal_links.length) {
        return;
    }

    const links = [...form.content.legal_links];
    const [link] = links.splice(index, 1);
    links.splice(nextIndex, 0, link);
    form.content.legal_links = links;
};

const removeFooterLegalLink = (index) => {
    if (!form.content.legal_links) {
        return;
    }

    form.content.legal_links.splice(index, 1);
};

const addIndustryCard = () => {
    form.content.industry_cards = [...(form.content.industry_cards || []), createIndustryCard()];
};

const addStoryCard = () => {
    form.content.story_cards = [...(form.content.story_cards || []), createStoryCard()];
};

const addTestimonialCard = () => {
    form.content.testimonial_cards = [...(form.content.testimonial_cards || []), createTestimonialCard()];
};

const moveIndustryCard = (index, direction) => {
    const nextIndex = index + direction;
    if (!form.content.industry_cards || nextIndex < 0 || nextIndex >= form.content.industry_cards.length) {
        return;
    }

    const cards = [...form.content.industry_cards];
    const [card] = cards.splice(index, 1);
    cards.splice(nextIndex, 0, card);
    form.content.industry_cards = cards;
};

const moveStoryCard = (index, direction) => {
    const nextIndex = index + direction;
    if (!form.content.story_cards || nextIndex < 0 || nextIndex >= form.content.story_cards.length) {
        return;
    }

    const cards = [...form.content.story_cards];
    const [card] = cards.splice(index, 1);
    cards.splice(nextIndex, 0, card);
    form.content.story_cards = cards;
};

const moveTestimonialCard = (index, direction) => {
    const nextIndex = index + direction;
    if (!form.content.testimonial_cards || nextIndex < 0 || nextIndex >= form.content.testimonial_cards.length) {
        return;
    }

    const cards = [...form.content.testimonial_cards];
    const [card] = cards.splice(index, 1);
    cards.splice(nextIndex, 0, card);
    form.content.testimonial_cards = cards;
};

const removeIndustryCard = (index) => {
    if (!form.content.industry_cards) {
        return;
    }

    form.content.industry_cards.splice(index, 1);
};

const removeStoryCard = (index) => {
    if (!form.content.story_cards) {
        return;
    }

    form.content.story_cards.splice(index, 1);
};

const removeTestimonialCard = (index) => {
    if (!form.content.testimonial_cards) {
        return;
    }

    form.content.testimonial_cards.splice(index, 1);
};

const addFeatureTab = () => {
    form.content.feature_tabs = [...(form.content.feature_tabs || []), createFeatureTab()];
};

const addFeatureTabChild = (tab) => {
    if (!tab) {
        return;
    }

    tab.children = [...(tab.children || []), createFeatureTabChild()];
};

const moveFeatureTab = (index, direction) => {
    const nextIndex = index + direction;
    if (!form.content.feature_tabs || nextIndex < 0 || nextIndex >= form.content.feature_tabs.length) {
        return;
    }

    const tabs = [...form.content.feature_tabs];
    const [tab] = tabs.splice(index, 1);
    tabs.splice(nextIndex, 0, tab);
    form.content.feature_tabs = tabs;
};

const moveFeatureTabChild = (tab, index, direction) => {
    const nextIndex = index + direction;
    if (!tab?.children || nextIndex < 0 || nextIndex >= tab.children.length) {
        return;
    }

    const children = [...tab.children];
    const [child] = children.splice(index, 1);
    children.splice(nextIndex, 0, child);
    tab.children = children;
};

const removeFeatureTab = (index) => {
    if (!form.content.feature_tabs) {
        return;
    }

    form.content.feature_tabs.splice(index, 1);
};

const removeFeatureTabChild = (tab, index) => {
    if (!tab?.children) {
        return;
    }

    tab.children.splice(index, 1);
};

const updateFeatureTabItems = (tab, value) => {
    tab.items = parseLines(value);
};

const addHeroStat = () => {
    form.content.stats = [...(form.content.stats || []), createStatItem()];
};

const moveHeroStat = (index, direction) => {
    const nextIndex = index + direction;
    if (!form.content.stats || nextIndex < 0 || nextIndex >= form.content.stats.length) {
        return;
    }

    const items = [...form.content.stats];
    const [item] = items.splice(index, 1);
    items.splice(nextIndex, 0, item);
    form.content.stats = items;
};

const removeHeroStat = (index) => {
    if (!form.content.stats) {
        return;
    }

    form.content.stats.splice(index, 1);
};

const addHeroImage = () => {
    form.content.hero_images = [...(form.content.hero_images || []), createHeroImage()];
};

const moveHeroImage = (index, direction) => {
    const nextIndex = index + direction;
    if (!form.content.hero_images || nextIndex < 0 || nextIndex >= form.content.hero_images.length) {
        return;
    }

    const items = [...form.content.hero_images];
    const [item] = items.splice(index, 1);
    items.splice(nextIndex, 0, item);
    form.content.hero_images = items;
};

const removeHeroImage = (index) => {
    if (!form.content.hero_images) {
        return;
    }

    form.content.hero_images.splice(index, 1);
};

const addPreviewCard = (targetKey = 'preview_cards') => {
    form.content[targetKey] = [...(form.content[targetKey] || []), createPreviewCard()];
};

const movePreviewCard = (targetKey, index, direction) => {
    const list = form.content[targetKey];
    const nextIndex = index + direction;
    if (!list || nextIndex < 0 || nextIndex >= list.length) {
        return;
    }

    const items = [...list];
    const [item] = items.splice(index, 1);
    items.splice(nextIndex, 0, item);
    form.content[targetKey] = items;
};

const removePreviewCard = (targetKey, index) => {
    if (!form.content[targetKey]) {
        return;
    }

    form.content[targetKey].splice(index, 1);
};

const addFeatureItemBlock = (targetKey = 'feature_items') => {
    form.content[targetKey] = [...(form.content[targetKey] || []), createFeatureItem()];
};

const moveFeatureItemBlock = (targetKey, index, direction) => {
    const list = form.content[targetKey];
    const nextIndex = index + direction;
    if (!list || nextIndex < 0 || nextIndex >= list.length) {
        return;
    }

    const items = [...list];
    const [item] = items.splice(index, 1);
    items.splice(nextIndex, 0, item);
    form.content[targetKey] = items;
};

const removeFeatureItemBlock = (targetKey, index) => {
    if (!form.content[targetKey]) {
        return;
    }

    form.content[targetKey].splice(index, 1);
};

syncFormFromProps(currentLocale.value);
</script>

<template>
    <Head :title="titleLabel" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ titleLabel }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('super_admin.sections.subtitle') }}
                        </p>
                        <div class="text-xs text-stone-500 dark:text-neutral-500">
                            {{ $t('super_admin.sections.meta.updated_at') }}: {{ updatedAtLabel }}
                            <span v-if="updatedByLabel">- {{ updatedByLabel }}</span>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Link :href="dashboard_url"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ $t('super_admin.sections.actions.back_dashboard') }}
                        </Link>
                        <Link :href="index_url"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ $t('super_admin.sections.actions.back_list') }}
                        </Link>
                        <button type="button" @click="submit"
                            class="rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                            :disabled="form.processing">
                            {{ $t('super_admin.sections.actions.save') }}
                        </button>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid gap-3 md:grid-cols-3">
                    <FloatingInput v-model="form.name" :label="$t('super_admin.sections.fields.name')" />
                    <FloatingSelect v-model="form.type" :options="typeOptions"
                        :label="$t('super_admin.sections.fields.type')" />
                    <div class="flex items-center gap-3 rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                        <Checkbox v-model:checked="form.is_active" />
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ $t('super_admin.sections.fields.is_active') }}
                        </div>
                    </div>
                </div>
                <div v-if="form.errors.name" class="mt-1 text-xs font-semibold text-red-600">
                    {{ form.errors.name }}
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid gap-3 md:grid-cols-3">
                    <FloatingSelect v-model="currentLocale" :options="localeOptions"
                        :label="$t('super_admin.sections.locale.label')" />
                    <template v-if="!isFooterType">
                        <FloatingInput v-model="form.content.kicker" :label="$t('super_admin.pages.common.kicker')" />
                        <FloatingInput v-model="form.content.title" :label="$t('super_admin.pages.common.title')" />
                    </template>
                </div>
                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('super_admin.sections.locale.hint') }}
                </div>
                <InputError class="mt-2" :message="form.errors.locale" />
                <InputError class="mt-1" :message="form.errors.content" />
            </section>

            <section v-if="usesEnhancedLayout"
                class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                <div>
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ backgroundHeadingLabel }}
                    </h2>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.sections.layout.subtitle') }}
                    </p>
                </div>

                <div class="grid gap-3 md:grid-cols-4">
                    <FloatingSelect v-if="showImagePosition" v-model="form.content.image_position" :options="imagePositionOptions"
                        :label="$t('super_admin.pages.common.image_position')" />
                    <FloatingSelect v-if="isShowcaseCtaType" v-model="form.content.showcase_divider_style" :options="showcaseDividerStyleOptions"
                        :label="$t('super_admin.pages.common.showcase_divider_style')" />
                    <FloatingSelect v-model="form.content.alignment" :options="alignmentOptions"
                        :label="$t('super_admin.pages.common.alignment')" />
                    <FloatingSelect v-model="form.content.density" :options="densityOptions"
                        :label="$t('super_admin.pages.common.density')" />
                    <FloatingSelect v-model="form.content.tone" :options="toneOptions"
                        :label="$t('super_admin.pages.common.tone')" />
                    <FloatingSelect v-model="form.content.background_preset" :options="backgroundPresetOptions"
                        :label="$t('super_admin.pages.common.background_preset')" />
                </div>

                <div class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ backgroundHeadingLabel }}
                    </label>
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <input v-model="form.content.background_color" type="color"
                            class="h-11 w-14 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                        <div class="min-w-[220px] flex-1">
                            <FloatingInput v-model="form.content.background_color"
                                :label="backgroundFieldLabel" />
                        </div>
                    </div>
                </div>
            </section>

            <section
                :class="[
                    'rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900',
                    isFooterType
                        ? 'space-y-4'
                        : 'space-y-4 xl:grid xl:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.9fr)] xl:items-start xl:gap-4 xl:space-y-0',
                ]"
            >
                <div v-if="!isFooterType" class="space-y-4 xl:col-start-1">
                    <RichTextEditor
                        v-model="form.content.body"
                        :label="$t('super_admin.pages.common.body')"
                        :link-prompt="editorLinkPrompt"
                        :image-prompt="editorImagePrompt"
                        :ai-enabled="ai_enabled"
                        :ai-generate-url="ai_image_generate_url"
                        :ai-prompt="editorAiPrompt"
                        :labels="editorLabels"
                    />

                    <div v-if="showItemsField">
                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            {{ itemsFieldLabel }}
                        </label>
                        <textarea v-model="itemsLines" rows="4"
                            class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"></textarea>
                    </div>
                </div>

                <div v-if="showImageFields" class="grid gap-3 md:grid-cols-2 xl:col-start-2 xl:row-start-1">
                    <div class="space-y-2">
                        <FloatingInput v-model="form.content.image_url" :label="$t('super_admin.pages.common.image_url')" />
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <button v-if="asset_list_url" type="button"
                                class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50"
                                @click="openAssetPicker(form.content)">
                                {{ $t('super_admin.pages.assets.choose') }}
                            </button>
                            <span v-if="form.content.image_url" class="text-stone-500">
                                {{ $t('super_admin.pages.assets.preview') }}
                            </span>
                        </div>
                        <div v-if="form.content.image_url" class="overflow-hidden rounded-sm border border-stone-200 bg-white">
                            <img :src="form.content.image_url" :alt="form.content.image_alt || form.content.title" class="h-36 w-full object-cover" loading="lazy" decoding="async" />
                        </div>
                    </div>
                    <FloatingInput v-model="form.content.image_alt" :label="$t('super_admin.pages.common.image_alt')" />
                </div>

                <div v-if="showActionFields" class="grid gap-3 md:grid-cols-2 xl:col-start-2">
                    <FloatingInput v-model="form.content.primary_label" :label="$t('super_admin.pages.common.primary_label')" />
                    <FloatingInput v-model="form.content.primary_href" :label="$t('super_admin.pages.common.primary_href')" />
                    <FloatingInput v-model="form.content.secondary_label" :label="$t('super_admin.pages.common.secondary_label')" />
                    <FloatingInput v-model="form.content.secondary_href" :label="$t('super_admin.pages.common.secondary_href')" />
                </div>

                <div v-if="isWelcomeHeroType" class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-4 xl:col-span-2">
                    <div class="space-y-3">
                        <div>
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.sections.welcome.hero.typography_title') }}
                            </h2>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.sections.welcome.hero.typography_subtitle') }}
                            </p>
                        </div>

                        <div class="grid gap-3 md:grid-cols-2">
                            <div class="rounded-sm border border-stone-200 bg-stone-50/70 p-3 dark:border-neutral-700 dark:bg-neutral-900/60 space-y-3">
                                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.common.title') }}
                                </div>

                                <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_150px]">
                                    <div class="space-y-2">
                                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                            {{ $t('super_admin.sections.welcome.hero.title_color') }}
                                        </label>
                                        <div class="flex items-center gap-3">
                                            <input v-model="form.content.title_color" type="color"
                                                class="h-11 w-14 shrink-0 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                                            <input
                                                v-model="form.content.title_color"
                                                type="text"
                                                class="block w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-200"
                                                placeholder="#111827"
                                            />
                                        </div>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                            {{ $t('super_admin.sections.welcome.hero.title_font_size') }}
                                        </label>
                                        <input
                                            v-model="form.content.title_font_size"
                                            type="number"
                                            min="0"
                                            step="1"
                                            class="block w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-200"
                                            placeholder="0"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-sm border border-stone-200 bg-stone-50/70 p-3 dark:border-neutral-700 dark:bg-neutral-900/60 space-y-3">
                                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.common.body') }}
                                </div>

                                <div class="space-y-2">
                                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ $t('super_admin.sections.welcome.hero.body_color') }}
                                    </label>
                                    <div class="flex items-center gap-3">
                                        <input v-model="form.content.body_color" type="color"
                                            class="h-11 w-14 shrink-0 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                                        <input
                                            v-model="form.content.body_color"
                                            type="text"
                                            class="block w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-200"
                                            placeholder="#475569"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <RichTextEditor
                        v-model="form.content.note"
                        :label="$t('super_admin.sections.welcome.hero.note')"
                        :link-prompt="editorLinkPrompt"
                        :image-prompt="editorImagePrompt"
                        :ai-enabled="ai_enabled"
                        :ai-generate-url="ai_image_generate_url"
                        :ai-prompt="editorAiPrompt"
                        :labels="editorLabels"
                    />

                    <div class="space-y-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('super_admin.sections.welcome.hero.stats_title') }}
                                </h2>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.sections.welcome.hero.stats_subtitle') }}
                                </p>
                            </div>
                            <button type="button"
                                class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                @click="addHeroStat">
                                {{ $t('super_admin.sections.welcome.hero.add_stat') }}
                            </button>
                        </div>

                        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            <div v-for="(item, index) in form.content.stats" :key="`hero-stat-${index}`"
                                class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                                <div class="flex flex-wrap justify-end gap-2 text-xs">
                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                        @click="moveHeroStat(index, -1)">
                                        {{ $t('super_admin.pages.common.move_up') }}
                                    </button>
                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                        @click="moveHeroStat(index, 1)">
                                        {{ $t('super_admin.pages.common.move_down') }}
                                    </button>
                                    <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                        @click="removeHeroStat(index)">
                                        {{ $t('super_admin.pages.common.remove') }}
                                    </button>
                                </div>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <FloatingInput v-model="item.value" :label="$t('super_admin.sections.welcome.hero.stat_value')" />
                                    <FloatingInput v-model="item.label" :label="$t('super_admin.sections.welcome.hero.stat_label')" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('super_admin.sections.welcome.hero.preview_title') }}
                                </h2>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.sections.welcome.hero.preview_subtitle') }}
                                </p>
                            </div>
                            <button type="button"
                                class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                @click="addPreviewCard('preview_cards')">
                                {{ $t('super_admin.sections.welcome.hero.add_preview') }}
                            </button>
                        </div>

                        <div class="grid gap-3 xl:grid-cols-2">
                            <div v-for="(item, index) in form.content.preview_cards" :key="`hero-preview-${index}`"
                                class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                                <div class="flex flex-wrap justify-end gap-2 text-xs">
                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                        @click="movePreviewCard('preview_cards', index, -1)">
                                        {{ $t('super_admin.pages.common.move_up') }}
                                    </button>
                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                        @click="movePreviewCard('preview_cards', index, 1)">
                                        {{ $t('super_admin.pages.common.move_down') }}
                                    </button>
                                    <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                        @click="removePreviewCard('preview_cards', index)">
                                        {{ $t('super_admin.pages.common.remove') }}
                                    </button>
                                </div>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <FloatingInput v-model="item.title" :label="$t('super_admin.pages.common.title')" />
                                    <RichTextEditor
                                        v-model="item.desc"
                                        :label="$t('super_admin.pages.common.description')"
                                        :link-prompt="editorLinkPrompt"
                                        :image-prompt="editorImagePrompt"
                                        :ai-enabled="ai_enabled"
                                        :ai-generate-url="ai_image_generate_url"
                                        :ai-prompt="editorAiPrompt"
                                        :labels="editorLabels"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('super_admin.sections.welcome.hero.slides_title') }}
                                </h2>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.sections.welcome.hero.slides_subtitle') }}
                                </p>
                            </div>
                            <button type="button"
                                class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                @click="addHeroImage">
                                {{ $t('super_admin.sections.welcome.hero.add_slide') }}
                            </button>
                        </div>

                        <div class="grid gap-3 xl:grid-cols-2">
                            <div v-for="(item, index) in form.content.hero_images" :key="item.id || `hero-slide-${index}`"
                                class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                                <div class="flex flex-wrap justify-between gap-2 text-xs">
                                    <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                        {{ $t('super_admin.sections.welcome.hero.slide_label', { number: index + 1 }) }}
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                            @click="moveHeroImage(index, -1)">
                                            {{ $t('super_admin.pages.common.move_up') }}
                                        </button>
                                        <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                            @click="moveHeroImage(index, 1)">
                                            {{ $t('super_admin.pages.common.move_down') }}
                                        </button>
                                        <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                            @click="removeHeroImage(index)">
                                            {{ $t('super_admin.pages.common.remove') }}
                                        </button>
                                    </div>
                                </div>

                                <div class="grid gap-3 xl:grid-cols-[minmax(0,1fr)_240px]">
                                    <div class="space-y-3">
                                        <FloatingInput
                                            v-model="item.image_url"
                                            :label="$t('super_admin.sections.welcome.hero.slide_image_url')"
                                        />
                                        <FloatingInput
                                            v-model="item.image_alt"
                                            :label="$t('super_admin.sections.welcome.hero.slide_image_alt')"
                                        />
                                        <button type="button"
                                            class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                            @click="openAssetPicker(item, 'image_url', 'image_alt')">
                                            {{ $t('super_admin.sections.welcome.hero.choose_asset') }}
                                        </button>
                                    </div>

                                    <div v-if="item.image_url" class="overflow-hidden rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-950">
                                        <img
                                            :src="item.image_url"
                                            :alt="item.image_alt || `${$t('super_admin.sections.welcome.hero.slide_image_alt')} ${index + 1}`"
                                            class="h-36 w-full object-cover"
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="isWelcomeFeaturesType" class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-4 xl:col-span-2">
                    <div class="space-y-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('super_admin.sections.welcome.features.items_title') }}
                                </h2>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.sections.welcome.features.items_subtitle') }}
                                </p>
                            </div>
                            <button type="button"
                                class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                @click="addFeatureItemBlock('feature_items')">
                                {{ $t('super_admin.sections.welcome.features.add_item') }}
                            </button>
                        </div>

                        <div class="grid gap-3 xl:grid-cols-2">
                            <div v-for="(item, index) in form.content.feature_items" :key="item.key || `feature-item-${index}`"
                                class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                                <div class="flex flex-wrap justify-end gap-2 text-xs">
                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                        @click="moveFeatureItemBlock('feature_items', index, -1)">
                                        {{ $t('super_admin.pages.common.move_up') }}
                                    </button>
                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                        @click="moveFeatureItemBlock('feature_items', index, 1)">
                                        {{ $t('super_admin.pages.common.move_down') }}
                                    </button>
                                    <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                        @click="removeFeatureItemBlock('feature_items', index)">
                                        {{ $t('super_admin.pages.common.remove') }}
                                    </button>
                                </div>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <FloatingInput v-model="item.key" :label="$t('super_admin.pages.common.key')" />
                                    <FloatingInput v-model="item.title" :label="$t('super_admin.pages.common.title')" />
                                    <div class="md:col-span-2">
                                        <RichTextEditor
                                            v-model="item.desc"
                                            :label="$t('super_admin.pages.common.description')"
                                            :link-prompt="editorLinkPrompt"
                                            :image-prompt="editorImagePrompt"
                                            :ai-enabled="ai_enabled"
                                            :ai-generate-url="ai_image_generate_url"
                                            :ai-prompt="editorAiPrompt"
                                            :labels="editorLabels"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                        <div class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                            <Checkbox v-model:checked="form.content.secondary_enabled" />
                            <span>{{ $t('super_admin.sections.welcome.features.secondary_enabled') }}</span>
                        </div>

                        <div class="grid gap-3 md:grid-cols-2">
                            <FloatingInput v-model="form.content.secondary_kicker" :label="$t('super_admin.pages.common.kicker')" />
                            <FloatingInput v-model="form.content.secondary_title" :label="$t('super_admin.pages.common.title')" />
                            <FloatingInput v-model="form.content.secondary_badge" :label="$t('super_admin.sections.welcome.features.secondary_badge')" />
                            <FloatingInput v-model="form.content.secondary_background_color" :label="$t('super_admin.pages.common.background_hex')" />
                            <div class="md:col-span-2">
                                <RichTextEditor
                                    v-model="form.content.secondary_body"
                                    :label="$t('super_admin.sections.welcome.features.secondary_body')"
                                    :link-prompt="editorLinkPrompt"
                                    :image-prompt="editorImagePrompt"
                                    :ai-enabled="ai_enabled"
                                    :ai-generate-url="ai_image_generate_url"
                                    :ai-prompt="editorAiPrompt"
                                    :labels="editorLabels"
                                />
                            </div>
                        </div>

                        <div class="flex flex-wrap justify-end">
                            <button type="button"
                                class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                @click="addFeatureItemBlock('secondary_feature_items')">
                                {{ $t('super_admin.sections.welcome.features.add_secondary_item') }}
                            </button>
                        </div>

                        <div class="grid gap-3 xl:grid-cols-2">
                            <div v-for="(item, index) in form.content.secondary_feature_items" :key="item.key || `feature-secondary-item-${index}`"
                                class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                                <div class="flex flex-wrap justify-end gap-2 text-xs">
                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                        @click="moveFeatureItemBlock('secondary_feature_items', index, -1)">
                                        {{ $t('super_admin.pages.common.move_up') }}
                                    </button>
                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                        @click="moveFeatureItemBlock('secondary_feature_items', index, 1)">
                                        {{ $t('super_admin.pages.common.move_down') }}
                                    </button>
                                    <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                        @click="removeFeatureItemBlock('secondary_feature_items', index)">
                                        {{ $t('super_admin.pages.common.remove') }}
                                    </button>
                                </div>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <FloatingInput v-model="item.key" :label="$t('super_admin.pages.common.key')" />
                                    <FloatingInput v-model="item.title" :label="$t('super_admin.pages.common.title')" />
                                    <div class="md:col-span-2">
                                        <RichTextEditor
                                            v-model="item.desc"
                                            :label="$t('super_admin.pages.common.description')"
                                            :link-prompt="editorLinkPrompt"
                                            :image-prompt="editorImagePrompt"
                                            :ai-enabled="ai_enabled"
                                            :ai-generate-url="ai_image_generate_url"
                                            :ai-prompt="editorAiPrompt"
                                            :labels="editorLabels"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="isWelcomeWorkflowType" class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3 xl:col-span-2">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.sections.welcome.workflow.steps_title') }}
                            </h2>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.sections.welcome.workflow.steps_subtitle') }}
                            </p>
                        </div>
                        <button type="button"
                            class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            @click="addPreviewCard('preview_cards')">
                            {{ $t('super_admin.sections.welcome.workflow.add_step') }}
                        </button>
                    </div>

                    <div class="grid gap-3 xl:grid-cols-2">
                        <div v-for="(item, index) in form.content.preview_cards" :key="`workflow-step-${index}`"
                            class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                            <div class="flex flex-wrap justify-end gap-2 text-xs">
                                <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                    @click="movePreviewCard('preview_cards', index, -1)">
                                    {{ $t('super_admin.pages.common.move_up') }}
                                </button>
                                <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                    @click="movePreviewCard('preview_cards', index, 1)">
                                    {{ $t('super_admin.pages.common.move_down') }}
                                </button>
                                <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                    @click="removePreviewCard('preview_cards', index)">
                                    {{ $t('super_admin.pages.common.remove') }}
                                </button>
                            </div>
                            <div class="grid gap-3 md:grid-cols-2">
                                <FloatingInput v-model="item.title" :label="$t('super_admin.pages.common.title')" />
                                <RichTextEditor
                                    v-model="item.desc"
                                    :label="$t('super_admin.pages.common.description')"
                                    :link-prompt="editorLinkPrompt"
                                    :image-prompt="editorImagePrompt"
                                    :ai-enabled="ai_enabled"
                                    :ai-generate-url="ai_image_generate_url"
                                    :ai-prompt="editorAiPrompt"
                                    :labels="editorLabels"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="isFooterType" class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-4">
                    <div>
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.pages.footer.title') }}
                        </h2>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.pages.footer.subtitle') }}
                        </p>
                    </div>

                    <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                        <div>
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.pages.footer.preview_title') }}
                            </h3>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.pages.footer.preview_subtitle') }}
                            </p>
                        </div>

                        <div class="grid gap-3 lg:grid-cols-5">
                            <div class="rounded-sm bg-stone-50 p-3 dark:bg-neutral-800/70">
                                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    1. {{ $t('super_admin.pages.footer.order_brand') }}
                                </div>
                                <div class="mt-1 text-sm font-semibold text-stone-900 dark:text-white">
                                    {{ form.content.brand_logo_alt || form.name || $t('super_admin.pages.footer.order_brand') }}
                                </div>
                            </div>
                            <div class="rounded-sm bg-stone-50 p-3 dark:bg-neutral-800/70">
                                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    2. {{ $t('super_admin.pages.footer.order_contact') }}
                                </div>
                                <div class="mt-1 text-sm font-semibold text-stone-900 dark:text-white">
                                    {{ footerSocialCount }} {{ $t('super_admin.pages.footer.preview_socials') }}
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ footerStoreCount }} {{ $t('super_admin.pages.footer.preview_stores') }}
                                </div>
                            </div>
                            <div class="rounded-sm bg-stone-50 p-3 dark:bg-neutral-800/70">
                                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    3. {{ $t('super_admin.pages.footer.order_navigation') }}
                                </div>
                                <div class="mt-1 text-sm font-semibold text-stone-900 dark:text-white">
                                    {{ (form.content.footer_groups || []).length }} {{ $t('super_admin.pages.footer.preview_groups') }}
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ footerNavLinkCount }} {{ $t('super_admin.pages.footer.preview_links') }}
                                </div>
                            </div>
                            <div class="rounded-sm bg-stone-50 p-3 dark:bg-neutral-800/70">
                                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    4. {{ $t('super_admin.pages.footer.order_support') }}
                                </div>
                                <div class="mt-1 text-sm font-semibold text-stone-900 dark:text-white">
                                    {{ form.content.title || $t('super_admin.pages.footer.order_support') }}
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ footerSupportItemCount }} {{ $t('super_admin.pages.footer.preview_items') }}
                                </div>
                            </div>
                            <div class="rounded-sm bg-stone-50 p-3 dark:bg-neutral-800/70">
                                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    5. {{ $t('super_admin.pages.footer.order_bottom') }}
                                </div>
                                <div class="mt-1 text-sm font-semibold text-stone-900 dark:text-white">
                                    {{ form.content.copy || $t('super_admin.pages.common.copy') }}
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ (form.content.legal_links || []).length }} {{ $t('super_admin.pages.footer.preview_links') }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <details open class="rounded-sm border border-stone-200 dark:border-neutral-700">
                        <summary class="cursor-pointer px-4 py-3">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('super_admin.pages.footer.appearance_title') }}
                                </h3>
                                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.footer.appearance_subtitle') }}
                                </p>
                            </div>
                        </summary>
                        <div class="border-t border-stone-200 p-4 dark:border-neutral-700 space-y-4">
                            <FloatingSelect v-model="form.content.background_preset" :options="backgroundPresetOptions"
                                :label="$t('super_admin.pages.common.background_preset')" />
                            <div class="grid gap-2 md:grid-cols-[160px_1fr] md:items-center">
                                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.common.background_hex') }}
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <input v-model="form.content.background_color" type="color"
                                        class="h-10 w-14 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                                    <div class="min-w-[220px] flex-1">
                                        <FloatingInput v-model="form.content.background_color"
                                            :label="$t('super_admin.pages.common.background_hex')" />
                                    </div>
                                </div>
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                <div class="space-y-2">
                                    <FloatingInput v-model="form.content.brand_logo_url" :label="$t('super_admin.pages.common.brand_logo_url')" />
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <button v-if="asset_list_url" type="button"
                                            class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50"
                                            @click="openAssetPicker(form.content, 'brand_logo_url', 'brand_logo_alt')">
                                            {{ $t('super_admin.pages.assets.choose') }}
                                        </button>
                                        <span v-if="form.content.brand_logo_url" class="text-stone-500">
                                            {{ $t('super_admin.pages.assets.preview') }}
                                        </span>
                                    </div>
                                    <div v-if="form.content.brand_logo_url" class="overflow-hidden rounded-sm border border-stone-200 bg-white p-3">
                                        <img :src="form.content.brand_logo_url" :alt="form.content.brand_logo_alt || 'Footer logo'" class="h-16 max-w-full object-contain" loading="lazy" decoding="async" />
                                    </div>
                                </div>
                                <div class="space-y-3">
                                    <FloatingInput v-model="form.content.brand_logo_alt" :label="$t('super_admin.pages.common.brand_logo_alt')" />
                                    <FloatingInput v-model="form.content.brand_href" :label="$t('super_admin.pages.common.brand_href')" />
                                </div>
                            </div>
                        </div>
                    </details>

                    <details open class="rounded-sm border border-stone-200 dark:border-neutral-700">
                        <summary class="cursor-pointer px-4 py-3">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('super_admin.pages.footer.contact_title') }}
                                </h3>
                                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.footer.contact_subtitle') }}
                                </p>
                            </div>
                        </summary>
                        <div class="border-t border-stone-200 p-4 dark:border-neutral-700 space-y-4">
                            <div class="grid gap-3 md:grid-cols-2">
                                <FloatingInput v-model="form.content.contact_phone" :label="$t('super_admin.pages.common.contact_phone')" />
                                <FloatingInput v-model="form.content.contact_email" :label="$t('super_admin.pages.common.contact_email')" />
                            </div>

                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                <FloatingInput v-model="form.content.social_facebook_href" :label="$t('super_admin.pages.common.social_facebook_href')" />
                                <FloatingInput v-model="form.content.social_x_href" :label="$t('super_admin.pages.common.social_x_href')" />
                                <FloatingInput v-model="form.content.social_instagram_href" :label="$t('super_admin.pages.common.social_instagram_href')" />
                                <FloatingInput v-model="form.content.social_youtube_href" :label="$t('super_admin.pages.common.social_youtube_href')" />
                                <FloatingInput v-model="form.content.social_linkedin_href" :label="$t('super_admin.pages.common.social_linkedin_href')" />
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                <FloatingInput v-model="form.content.google_play_href" :label="$t('super_admin.pages.common.google_play_href')" />
                                <FloatingInput v-model="form.content.app_store_href" :label="$t('super_admin.pages.common.app_store_href')" />
                            </div>
                        </div>
                    </details>

                    <details open class="rounded-sm border border-stone-200 dark:border-neutral-700">
                        <summary class="cursor-pointer px-4 py-3">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('super_admin.pages.footer.navigation_title') }}
                                </h3>
                                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.footer.navigation_subtitle') }}
                                </p>
                            </div>
                        </summary>
                        <div class="border-t border-stone-200 p-4 dark:border-neutral-700 space-y-3">
                            <div class="flex flex-wrap justify-end">
                                <button type="button"
                                    class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    @click="addFooterGroup">
                                    {{ $t('super_admin.pages.footer.add_group') }}
                                </button>
                            </div>

                            <div class="space-y-3">
                                <div v-for="(group, groupIndex) in form.content.footer_groups" :key="group.id"
                                    class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                        <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                            {{ $t('super_admin.pages.footer.group_label', { number: groupIndex + 1 }) }}
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                @click="moveFooterGroup(groupIndex, -1)">
                                                {{ $t('super_admin.pages.common.move_up') }}
                                            </button>
                                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                @click="moveFooterGroup(groupIndex, 1)">
                                                {{ $t('super_admin.pages.common.move_down') }}
                                            </button>
                                            <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                                @click="removeFooterGroup(groupIndex)">
                                                {{ $t('super_admin.pages.common.remove') }}
                                            </button>
                                        </div>
                                    </div>

                                    <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_220px]">
                                        <FloatingInput v-model="group.title" :label="$t('super_admin.pages.common.footer_group_title')" />
                                        <FloatingSelect v-model="group.layout" :options="footerGroupLayoutOptions"
                                            :label="$t('super_admin.pages.common.footer_group_layout')" />
                                    </div>

                                    <div class="space-y-3">
                                        <div v-for="(link, linkIndex) in group.links" :key="link.id"
                                            class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                                <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                                    {{ $t('super_admin.pages.footer.link_label', { number: linkIndex + 1 }) }}
                                                </div>
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                        @click="moveFooterGroupLink(group, linkIndex, -1)">
                                                        {{ $t('super_admin.pages.common.move_up') }}
                                                    </button>
                                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                        @click="moveFooterGroupLink(group, linkIndex, 1)">
                                                        {{ $t('super_admin.pages.common.move_down') }}
                                                    </button>
                                                    <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                                        @click="removeFooterGroupLink(group, linkIndex)">
                                                        {{ $t('super_admin.pages.common.remove') }}
                                                    </button>
                                                </div>
                                            </div>

                                            <div class="grid gap-3 md:grid-cols-2">
                                                <FloatingInput v-model="link.label" :label="$t('super_admin.pages.common.footer_link_label')" />
                                                <FloatingInput v-model="link.href" :label="$t('super_admin.pages.common.footer_link_href')" />
                                                <div class="md:col-span-2">
                                                    <FloatingInput v-model="link.note" :label="$t('super_admin.pages.common.footer_link_note')" />
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <button type="button"
                                        class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                        @click="addFooterGroupLink(group)">
                                        {{ $t('super_admin.pages.footer.add_link') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </details>

                    <details open class="rounded-sm border border-stone-200 dark:border-neutral-700">
                        <summary class="cursor-pointer px-4 py-3">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('super_admin.pages.footer.support_title') }}
                                </h3>
                                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.footer.support_subtitle') }}
                                </p>
                            </div>
                        </summary>
                        <div class="border-t border-stone-200 p-4 dark:border-neutral-700 space-y-4">
                            <div class="grid gap-3 md:grid-cols-2">
                                <FloatingInput v-model="form.content.kicker" :label="$t('super_admin.pages.common.kicker')" />
                                <FloatingInput v-model="form.content.title" :label="$t('super_admin.pages.common.title')" />
                            </div>

                            <RichTextEditor
                                v-model="form.content.body"
                                :label="$t('super_admin.pages.common.body')"
                                :link-prompt="editorLinkPrompt"
                                :image-prompt="editorImagePrompt"
                                :ai-enabled="ai_enabled"
                                :ai-generate-url="ai_image_generate_url"
                                :ai-prompt="editorAiPrompt"
                                :labels="editorLabels"
                            />

                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ itemsFieldLabel }}
                                </label>
                                <textarea v-model="itemsLines" rows="4"
                                    class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"></textarea>
                            </div>

                            <div class="grid gap-3 md:grid-cols-4">
                                <FloatingInput v-model="form.content.primary_label" :label="$t('super_admin.pages.common.primary_label')" />
                                <FloatingInput v-model="form.content.primary_href" :label="$t('super_admin.pages.common.primary_href')" />
                                <FloatingInput v-model="form.content.secondary_label" :label="$t('super_admin.pages.common.secondary_label')" />
                                <FloatingInput v-model="form.content.secondary_href" :label="$t('super_admin.pages.common.secondary_href')" />
                            </div>
                        </div>
                    </details>

                    <details open class="rounded-sm border border-stone-200 dark:border-neutral-700">
                        <summary class="cursor-pointer px-4 py-3">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('super_admin.pages.footer.bottom_title') }}
                                </h3>
                                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.footer.bottom_subtitle') }}
                                </p>
                            </div>
                        </summary>
                        <div class="border-t border-stone-200 p-4 dark:border-neutral-700 space-y-4">
                            <FloatingInput v-model="form.content.copy" :label="$t('super_admin.pages.common.copy')" />

                            <div class="space-y-3">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                            {{ $t('super_admin.pages.footer.legal_title') }}
                                        </h3>
                                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ $t('super_admin.pages.footer.legal_subtitle') }}
                                        </p>
                                    </div>
                                    <button type="button"
                                        class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                        @click="addFooterLegalLink">
                                        {{ $t('super_admin.pages.footer.add_legal_link') }}
                                    </button>
                                </div>

                                <div class="space-y-3">
                                    <div v-for="(link, linkIndex) in form.content.legal_links" :key="link.id"
                                        class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                            <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                                {{ $t('super_admin.pages.footer.legal_label', { number: linkIndex + 1 }) }}
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                    @click="moveFooterLegalLink(linkIndex, -1)">
                                                    {{ $t('super_admin.pages.common.move_up') }}
                                                </button>
                                                <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                    @click="moveFooterLegalLink(linkIndex, 1)">
                                                    {{ $t('super_admin.pages.common.move_down') }}
                                                </button>
                                                <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                                    @click="removeFooterLegalLink(linkIndex)">
                                                    {{ $t('super_admin.pages.common.remove') }}
                                                </button>
                                            </div>
                                        </div>

                                        <div class="grid gap-3 md:grid-cols-2">
                                            <FloatingInput v-model="link.label" :label="$t('super_admin.pages.common.footer_link_label')" />
                                            <FloatingInput v-model="link.href" :label="$t('super_admin.pages.common.footer_link_href')" />
                                            <div class="md:col-span-2">
                                                <FloatingInput v-model="link.note" :label="$t('super_admin.pages.common.footer_link_note')" />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </details>
                </div>

                <div v-if="isTestimonialType" class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 xl:col-span-2">
                    <div class="mb-3">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.pages.testimonial.title') }}
                        </h2>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.pages.testimonial.subtitle') }}
                        </p>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2">
                        <FloatingInput v-model="form.content.testimonial_author"
                            :label="$t('super_admin.pages.common.testimonial_author')" />
                        <FloatingInput v-model="form.content.testimonial_role"
                            :label="$t('super_admin.pages.common.testimonial_role')" />
                    </div>
                </div>

                <div v-if="isStoryGridType" class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3 xl:col-span-2">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.pages.story_grid.title') }}
                            </h2>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.pages.story_grid.subtitle') }}
                            </p>
                        </div>
                        <button type="button"
                            class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            @click="addStoryCard">
                            {{ $t('super_admin.pages.story_grid.add_card') }}
                        </button>
                    </div>

                    <div class="grid gap-3 xl:grid-cols-2">
                        <div v-for="(card, cardIndex) in form.content.story_cards" :key="card.id"
                            class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ $t('super_admin.pages.story_grid.card_label', { number: cardIndex + 1 }) }}
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                        @click="moveStoryCard(cardIndex, -1)">
                                        {{ $t('super_admin.pages.common.move_up') }}
                                    </button>
                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                        @click="moveStoryCard(cardIndex, 1)">
                                        {{ $t('super_admin.pages.common.move_down') }}
                                    </button>
                                    <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                        @click="removeStoryCard(cardIndex)">
                                        {{ $t('super_admin.pages.common.remove') }}
                                    </button>
                                </div>
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                <FloatingInput v-model="card.title" :label="$t('super_admin.pages.common.story_card_title')" />
                                <FloatingInput v-model="card.image_alt" :label="$t('super_admin.pages.common.story_card_image_alt')" />
                            </div>

                            <RichTextEditor
                                v-model="card.body"
                                :label="$t('super_admin.pages.common.story_card_body')"
                                :link-prompt="editorLinkPrompt"
                                :image-prompt="editorImagePrompt"
                                :ai-enabled="ai_enabled"
                                :ai-generate-url="ai_image_generate_url"
                                :ai-prompt="editorAiPrompt"
                                :labels="editorLabels"
                            />

                            <div class="space-y-2">
                                <FloatingInput v-model="card.image_url" :label="$t('super_admin.pages.common.story_card_image_url')" />
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <button v-if="asset_list_url" type="button"
                                        class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50"
                                        @click="openAssetPicker(card, 'image_url', 'image_alt')">
                                        {{ $t('super_admin.pages.assets.choose') }}
                                    </button>
                                    <span v-if="card.image_url" class="text-stone-500">
                                        {{ $t('super_admin.pages.assets.preview') }}
                                    </span>
                                </div>
                                <div v-if="card.image_url" class="overflow-hidden rounded-sm border border-stone-200 bg-white">
                                    <img :src="card.image_url" :alt="card.image_alt || card.title" class="h-36 w-full object-cover" loading="lazy" decoding="async" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="isFeatureTabsType" class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3 xl:col-span-2">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.pages.feature_tabs.title') }}
                            </h2>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.pages.feature_tabs.subtitle') }}
                            </p>
                        </div>
                        <button type="button"
                            class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            @click="addFeatureTab">
                            {{ $t('super_admin.pages.feature_tabs.add_tab') }}
                        </button>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <FloatingSelect
                            v-model="form.content.feature_tabs_style"
                            :options="featureTabStyleSelectOptions"
                            :label="$t('super_admin.pages.feature_tabs.style_label')"
                        />
                        <FloatingNumberInput
                            v-model="form.content.feature_tabs_font_size"
                            :label="$t('super_admin.pages.feature_tabs.font_size_label')"
                            :step="1"
                        />
                    </div>

                    <div class="space-y-3">
                        <div v-for="(tab, tabIndex) in form.content.feature_tabs" :key="tab.id"
                            class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ $t('super_admin.pages.feature_tabs.tab_label', { number: tabIndex + 1 }) }}
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                        @click="moveFeatureTab(tabIndex, -1)">
                                        {{ $t('super_admin.pages.common.move_up') }}
                                    </button>
                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                        @click="moveFeatureTab(tabIndex, 1)">
                                        {{ $t('super_admin.pages.common.move_down') }}
                                    </button>
                                    <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                        @click="removeFeatureTab(tabIndex)">
                                        {{ $t('super_admin.pages.common.remove') }}
                                    </button>
                                </div>
                            </div>

                            <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_220px]">
                                <FloatingInput v-model="tab.label" :label="$t('super_admin.pages.common.tab_label')" />
                                <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_44px] md:items-end">
                                    <FloatingSelect v-model="tab.icon" :options="featureTabIconSelectOptions"
                                        :label="$t('super_admin.pages.common.tab_icon')" />
                                    <div class="flex h-11 items-center justify-center rounded-sm border border-stone-200 bg-stone-50 text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                                        <component :is="resolveFeatureTabIconComponent(tab)" class="h-5 w-5" />
                                    </div>
                                </div>
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                <FloatingInput v-model="tab.title" :label="$t('super_admin.pages.common.tab_title')" />
                                <FloatingInput v-model="tab.cta_label" :label="$t('super_admin.pages.common.tab_cta_label')" />
                                <div class="md:col-span-2">
                                    <FloatingInput v-model="tab.cta_href" :label="$t('super_admin.pages.common.tab_cta_href')" />
                                </div>
                            </div>

                            <RichTextEditor
                                v-model="tab.body"
                                :label="$t('super_admin.pages.common.tab_body')"
                                :link-prompt="editorLinkPrompt"
                                :image-prompt="editorImagePrompt"
                                :ai-enabled="ai_enabled"
                                :ai-generate-url="ai_image_generate_url"
                                :ai-prompt="editorAiPrompt"
                                :labels="editorLabels"
                            />

                            <div class="grid gap-3 md:grid-cols-3">
                                <FloatingInput v-model="tab.metric" :label="$t('super_admin.pages.common.tab_metric')" />
                                <FloatingInput v-model="tab.person" :label="$t('super_admin.pages.common.tab_person')" />
                                <FloatingInput v-model="tab.role" :label="$t('super_admin.pages.common.tab_role')" />
                            </div>

                            <RichTextEditor
                                v-model="tab.story"
                                :label="$t('super_admin.pages.common.tab_story')"
                                :link-prompt="editorLinkPrompt"
                                :image-prompt="editorImagePrompt"
                                :ai-enabled="ai_enabled"
                                :ai-generate-url="ai_image_generate_url"
                                :ai-prompt="editorAiPrompt"
                                :labels="editorLabels"
                            />

                            <div class="grid gap-3 md:grid-cols-2">
                                <div class="space-y-2">
                                    <FloatingInput v-model="tab.avatar_url" :label="$t('super_admin.pages.common.tab_avatar_url')" />
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <button v-if="asset_list_url" type="button"
                                            class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50"
                                            @click="openAssetPicker(tab, 'avatar_url', 'avatar_alt')">
                                            {{ $t('super_admin.pages.assets.choose') }}
                                        </button>
                                        <span v-if="tab.avatar_url" class="text-stone-500">
                                            {{ $t('super_admin.pages.assets.preview') }}
                                        </span>
                                    </div>
                                    <div v-if="tab.avatar_url" class="overflow-hidden rounded-sm border border-stone-200 bg-white p-3">
                                        <img :src="tab.avatar_url" :alt="tab.avatar_alt || tab.person || tab.label" class="h-24 w-24 rounded-full object-cover" loading="lazy" decoding="async" />
                                    </div>
                                </div>
                                <FloatingInput v-model="tab.avatar_alt" :label="$t('super_admin.pages.common.tab_avatar_alt')" />
                            </div>

                            <div class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                            {{ $t('super_admin.pages.feature_tabs.children_title') }}
                                        </h3>
                                        <p class="text-xs text-stone-500 dark:text-neutral-500">
                                            {{ $t('super_admin.pages.feature_tabs.children_subtitle') }}
                                        </p>
                                    </div>
                                    <button type="button"
                                        class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                        @click="addFeatureTabChild(tab)">
                                        {{ $t('super_admin.pages.feature_tabs.add_child') }}
                                    </button>
                                </div>

                                <div v-if="tab.children?.length" class="space-y-3">
                                    <div v-for="(child, childIndex) in tab.children" :key="child.id"
                                        class="rounded-sm border border-stone-200 bg-stone-50/60 p-3 dark:border-neutral-700 dark:bg-neutral-950/40 space-y-3">
                                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                            <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                                {{ $t('super_admin.pages.feature_tabs.child_label', { number: childIndex + 1 }) }}
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                    @click="moveFeatureTabChild(tab, childIndex, -1)">
                                                    {{ $t('super_admin.pages.common.move_up') }}
                                                </button>
                                                <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                    @click="moveFeatureTabChild(tab, childIndex, 1)">
                                                    {{ $t('super_admin.pages.common.move_down') }}
                                                </button>
                                                <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                                    @click="removeFeatureTabChild(tab, childIndex)">
                                                    {{ $t('super_admin.pages.common.remove') }}
                                                </button>
                                            </div>
                                        </div>

                                        <div class="grid gap-3 md:grid-cols-2">
                                            <FloatingInput v-model="child.label" :label="$t('super_admin.pages.common.feature_item_label')" />
                                            <FloatingInput v-model="child.title" :label="$t('super_admin.pages.common.feature_item_title')" />
                                            <FloatingInput v-model="child.cta_label" :label="$t('super_admin.pages.common.feature_item_cta_label')" />
                                            <FloatingInput v-model="child.cta_href" :label="$t('super_admin.pages.common.feature_item_cta_href')" />
                                        </div>

                                        <RichTextEditor
                                            v-model="child.body"
                                            :label="$t('super_admin.pages.common.feature_item_body')"
                                            :link-prompt="editorLinkPrompt"
                                            :image-prompt="editorImagePrompt"
                                            :ai-enabled="ai_enabled"
                                            :ai-generate-url="ai_image_generate_url"
                                            :ai-prompt="editorAiPrompt"
                                            :labels="editorLabels"
                                        />

                                        <div class="grid gap-3 md:grid-cols-2">
                                            <div class="space-y-2">
                                                <FloatingInput v-model="child.image_url" :label="$t('super_admin.pages.common.feature_item_image_url')" />
                                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                                    <button v-if="asset_list_url" type="button"
                                                        class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50"
                                                        @click="openAssetPicker(child, 'image_url', 'image_alt')">
                                                        {{ $t('super_admin.pages.assets.choose') }}
                                                    </button>
                                                    <span v-if="child.image_url" class="text-stone-500">
                                                        {{ $t('super_admin.pages.assets.preview') }}
                                                    </span>
                                                </div>
                                                <div v-if="child.image_url" class="overflow-hidden rounded-sm border border-stone-200 bg-white">
                                                    <img :src="child.image_url" :alt="child.image_alt || child.title || child.label" class="h-36 w-full object-cover" loading="lazy" decoding="async" />
                                                </div>
                                            </div>
                                            <FloatingInput v-model="child.image_alt" :label="$t('super_admin.pages.common.feature_item_image_alt')" />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.common.tab_items') }}
                                </label>
                                <textarea :value="(tab.items || []).join('\n')" rows="4"
                                    class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                    @input="updateFeatureTabItems(tab, $event.target.value)"></textarea>
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                <div class="space-y-2">
                                    <FloatingInput v-model="tab.image_url" :label="$t('super_admin.pages.common.tab_image_url')" />
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <button v-if="asset_list_url" type="button"
                                            class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50"
                                            @click="openAssetPicker(tab, 'image_url', 'image_alt')">
                                            {{ $t('super_admin.pages.assets.choose') }}
                                        </button>
                                        <span v-if="tab.image_url" class="text-stone-500">
                                            {{ $t('super_admin.pages.assets.preview') }}
                                        </span>
                                    </div>
                                    <div v-if="tab.image_url" class="overflow-hidden rounded-sm border border-stone-200 bg-white">
                                        <img :src="tab.image_url" :alt="tab.image_alt || tab.title || tab.label" class="h-36 w-full object-cover" loading="lazy" decoding="async" />
                                    </div>
                                </div>
                                <FloatingInput v-model="tab.image_alt" :label="$t('super_admin.pages.common.tab_image_alt')" />
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="isTestimonialGridType" class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3 xl:col-span-2">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.pages.testimonial_grid.title') }}
                            </h2>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.pages.testimonial_grid.subtitle') }}
                            </p>
                        </div>
                        <button type="button"
                            class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            @click="addTestimonialCard">
                            {{ $t('super_admin.pages.testimonial_grid.add_card') }}
                        </button>
                    </div>

                    <div class="grid gap-3 xl:grid-cols-2">
                        <div v-for="(card, cardIndex) in form.content.testimonial_cards" :key="card.id"
                            class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ $t('super_admin.pages.testimonial_grid.card_label', { number: cardIndex + 1 }) }}
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                        @click="moveTestimonialCard(cardIndex, -1)">
                                        {{ $t('super_admin.pages.common.move_up') }}
                                    </button>
                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                        @click="moveTestimonialCard(cardIndex, 1)">
                                        {{ $t('super_admin.pages.common.move_down') }}
                                    </button>
                                    <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                        @click="removeTestimonialCard(cardIndex)">
                                        {{ $t('super_admin.pages.common.remove') }}
                                    </button>
                                </div>
                            </div>

                            <RichTextEditor
                                v-model="card.quote"
                                :label="$t('super_admin.pages.common.testimonial_card_quote')"
                                :link-prompt="editorLinkPrompt"
                                :image-prompt="editorImagePrompt"
                                :ai-enabled="ai_enabled"
                                :ai-generate-url="ai_image_generate_url"
                                :ai-prompt="editorAiPrompt"
                                :labels="editorLabels"
                            />

                            <div class="grid gap-3 md:grid-cols-3">
                                <FloatingInput v-model="card.author_name" :label="$t('super_admin.pages.common.testimonial_card_author_name')" />
                                <FloatingInput v-model="card.author_role" :label="$t('super_admin.pages.common.testimonial_card_author_role')" />
                                <FloatingInput v-model="card.author_company" :label="$t('super_admin.pages.common.testimonial_card_author_company')" />
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                <div class="space-y-2">
                                    <FloatingInput v-model="card.image_url" :label="$t('super_admin.pages.common.testimonial_card_image_url')" />
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <button v-if="asset_list_url" type="button"
                                            class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50"
                                            @click="openAssetPicker(card, 'image_url', 'image_alt')">
                                            {{ $t('super_admin.pages.assets.choose') }}
                                        </button>
                                        <span v-if="card.image_url" class="text-stone-500">
                                            {{ $t('super_admin.pages.assets.preview') }}
                                        </span>
                                    </div>
                                    <div v-if="card.image_url" class="overflow-hidden rounded-sm border border-stone-200 bg-white p-3">
                                        <img :src="card.image_url" :alt="card.image_alt || card.author_name" class="h-24 w-24 rounded-full object-cover" loading="lazy" decoding="async" />
                                    </div>
                                </div>
                                <FloatingInput v-model="card.image_alt" :label="$t('super_admin.pages.common.testimonial_card_image_alt')" />
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="isIndustryGridType" class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3 xl:col-span-2">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.pages.industry_grid.cards_title') }}
                            </h2>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.pages.industry_grid.cards_subtitle') }}
                            </p>
                        </div>
                        <button type="button"
                            class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            @click="addIndustryCard">
                            {{ $t('super_admin.pages.industry_grid.add_card') }}
                        </button>
                    </div>

                    <div class="grid gap-3 xl:grid-cols-2">
                        <div v-for="(card, cardIndex) in form.content.industry_cards" :key="card.id"
                            class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ $t('super_admin.pages.industry_grid.card_label', { number: cardIndex + 1 }) }}
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                        @click="moveIndustryCard(cardIndex, -1)">
                                        {{ $t('super_admin.pages.common.move_up') }}
                                    </button>
                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                        @click="moveIndustryCard(cardIndex, 1)">
                                        {{ $t('super_admin.pages.common.move_down') }}
                                    </button>
                                    <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                        @click="removeIndustryCard(cardIndex)">
                                        {{ $t('super_admin.pages.common.remove') }}
                                    </button>
                                </div>
                            </div>

                            <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_220px]">
                                <FloatingInput v-model="card.label" :label="$t('super_admin.pages.common.card_label')" />
                                <FloatingInput v-model="card.href" :label="$t('super_admin.pages.common.card_href')" />
                                <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_44px] md:items-end">
                                    <FloatingSelect v-model="card.icon" :options="industryCardIconOptions"
                                        :label="$t('super_admin.pages.common.card_icon')" />
                                    <div class="flex h-11 items-center justify-center rounded-sm border border-stone-200 bg-stone-50 text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                                        <component :is="resolveIndustryIconComponent(card)" class="h-5 w-5" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="isShowcaseCtaType" class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-4 xl:col-span-2">
                    <div>
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.pages.showcase_cta.title') }}
                        </h2>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.pages.showcase_cta.subtitle') }}
                        </p>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="space-y-2">
                            <FloatingInput v-model="form.content.image_url" :label="$t('super_admin.pages.common.image_url')" />
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <button v-if="asset_list_url" type="button"
                                    class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50"
                                    @click="openAssetPicker(form.content)">
                                    {{ $t('super_admin.pages.assets.choose') }}
                                </button>
                                <span v-if="form.content.image_url" class="text-stone-500">
                                    {{ $t('super_admin.pages.assets.preview') }}
                                </span>
                            </div>
                            <div v-if="form.content.image_url" class="overflow-hidden rounded-sm border border-stone-200 bg-white">
                                <img :src="form.content.image_url" :alt="form.content.image_alt || form.content.title" class="h-36 w-full object-cover" loading="lazy" decoding="async" />
                            </div>
                        </div>
                        <FloatingInput v-model="form.content.image_alt" :label="$t('super_admin.pages.common.image_alt')" />
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <FloatingInput v-model="form.content.aside_link_label" :label="$t('super_admin.pages.common.showcase_overlay_label')" />
                        <FloatingInput v-model="form.content.aside_link_href" :label="$t('super_admin.pages.common.showcase_overlay_href')" />
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="space-y-2">
                            <FloatingInput v-model="form.content.aside_image_url" :label="$t('super_admin.pages.common.showcase_floating_image_url')" />
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <button v-if="asset_list_url" type="button"
                                    class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50"
                                    @click="openAssetPicker(form.content, 'aside_image_url', 'aside_image_alt')">
                                    {{ $t('super_admin.pages.assets.choose') }}
                                </button>
                                <span v-if="form.content.aside_image_url" class="text-stone-500">
                                    {{ $t('super_admin.pages.assets.preview') }}
                                </span>
                            </div>
                            <div v-if="form.content.aside_image_url" class="overflow-hidden rounded-sm border border-stone-200 bg-white">
                                <img :src="form.content.aside_image_url" :alt="form.content.aside_image_alt || form.content.title" class="h-36 w-full object-cover" loading="lazy" decoding="async" />
                            </div>
                        </div>
                        <FloatingInput v-model="form.content.aside_image_alt" :label="$t('super_admin.pages.common.showcase_floating_image_alt')" />
                    </div>
                </div>

                <div v-if="isFeaturePairsType" class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3 xl:col-span-2">
                    <div>
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.pages.feature_pairs.secondary_title') }}
                        </h2>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.pages.feature_pairs.secondary_subtitle') }}
                        </p>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <FloatingInput v-model="form.content.aside_kicker" :label="$t('super_admin.pages.common.aside_kicker')" />
                        <FloatingInput v-model="form.content.aside_title" :label="$t('super_admin.pages.common.aside_title')" />
                        <div class="md:col-span-2">
                            <RichTextEditor
                                v-model="form.content.aside_body"
                                :label="$t('super_admin.pages.common.aside_body')"
                                :link-prompt="editorLinkPrompt"
                                :image-prompt="editorImagePrompt"
                                :ai-enabled="ai_enabled"
                                :ai-generate-url="ai_image_generate_url"
                                :ai-prompt="editorAiPrompt"
                                :labels="editorLabels"
                            />
                        </div>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <FloatingInput v-model="form.content.aside_link_label" :label="$t('super_admin.pages.common.aside_link_label')" />
                        <FloatingInput v-model="form.content.aside_link_href" :label="$t('super_admin.pages.common.aside_link_href')" />
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="space-y-2">
                            <FloatingInput v-model="form.content.aside_image_url" :label="$t('super_admin.pages.common.aside_image_url')" />
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <button v-if="asset_list_url" type="button"
                                    class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50"
                                    @click="openAssetPicker(form.content, 'aside_image_url', 'aside_image_alt')">
                                    {{ $t('super_admin.pages.assets.choose') }}
                                </button>
                                <span v-if="form.content.aside_image_url" class="text-stone-500">
                                    {{ $t('super_admin.pages.assets.preview') }}
                                </span>
                            </div>
                            <div v-if="form.content.aside_image_url" class="overflow-hidden rounded-sm border border-stone-200 bg-white">
                                <img :src="form.content.aside_image_url" :alt="form.content.aside_image_alt || form.content.aside_title || form.content.title" class="h-36 w-full object-cover" loading="lazy" decoding="async" />
                            </div>
                        </div>
                        <FloatingInput v-model="form.content.aside_image_alt" :label="$t('super_admin.pages.common.aside_image_alt')" />
                    </div>
                </div>

            </section>
        </div>

        <AssetPickerModal
            :show="assetPickerOpen"
            :list-url="asset_list_url"
            :title="$t('super_admin.pages.assets.title')"
            @close="closeAssetPicker"
            @select="handleAssetSelect"
        />
    </AuthenticatedLayout>
</template>
