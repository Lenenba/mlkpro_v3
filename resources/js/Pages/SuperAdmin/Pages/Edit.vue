<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DateTimePicker from '@/Components/DateTimePicker.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingNumberInput from '@/Components/FloatingNumberInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import Checkbox from '@/Components/Checkbox.vue';
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
    page: { type: Object, default: () => ({ id: null, slug: '', title: '', is_active: true }) },
    locales: { type: Array, default: () => ['fr', 'en'] },
    default_locale: { type: String, default: 'fr' },
    content: { type: Object, default: () => ({}) },
    theme: { type: Object, default: () => ({}) },
    meta: { type: Object, default: () => ({ updated_at: null, updated_by: null }) },
    dashboard_url: { type: String, required: true },
    index_url: { type: String, required: true },
    public_url: { type: String, default: null },
    library_sections: { type: Array, default: () => [] },
    footer_section: { type: Object, default: () => ({}) },
    library_index_url: { type: String, default: '' },
    asset_list_url: { type: String, default: '' },
    ai_enabled: { type: Boolean, default: false },
    ai_image_generate_url: { type: String, default: '' },
});

const { t } = useI18n();

const themeDefaults = {
    primary_color: '#16a34a',
    primary_soft_color: '#dcfce7',
    primary_contrast_color: '#ffffff',
    background_style: 'gradient',
    background_color: '#f8fafc',
    background_alt_color: '#ecfdf5',
    surface_color: '#ffffff',
    text_color: '#0f172a',
    muted_color: '#64748b',
    border_color: '#e2e8f0',
    font_body: 'montserrat',
    font_heading: 'montserrat',
    radius: 'sm',
    shadow: 'soft',
    button_style: 'solid',
};

const ensureTheme = (theme) => ({ ...themeDefaults, ...(theme || {}) });

const clone = (value) => JSON.parse(JSON.stringify(value ?? {}));
const currentLocale = ref(props.default_locale || props.locales[0] || 'fr');
const isCreateMode = computed(() => props.mode === 'create');
const isWelcomePage = computed(() => props.page.slug === 'welcome');

const form = useForm({
    slug: props.page.slug || '',
    title: props.page.title || '',
    is_active: props.page.is_active ?? true,
    locale: currentLocale.value,
    content: {},
    theme: ensureTheme(props.theme),
});

const sectionItemsLines = ref({});
const sectionAsideItemsLines = ref({});
const visibilityRoleLines = ref({});
const visibilityPlanLines = ref({});
const sectionEditorOpen = ref({});
const assetPickerOpen = ref(false);
const assetTarget = ref(null);
const removedSectionIds = ref([]);
const contentByLocale = ref({});

const localeOptions = computed(() =>
    (props.locales || []).map((locale) => ({ value: locale, label: locale.toUpperCase() }))
);
const localeList = computed(() => props.locales || []);

const templates = computed(() => [
    {
        id: 'pricing',
        label: t('super_admin.pages.templates.pricing.label'),
        description: t('super_admin.pages.templates.pricing.description'),
        content: {
            page_title: t('super_admin.pages.templates.pricing.page_title'),
            page_subtitle: t('super_admin.pages.templates.pricing.page_subtitle'),
            sections: [
                {
                    layout: 'split',
                    kicker: t('super_admin.pages.templates.pricing.sections.hero.kicker'),
                    title: t('super_admin.pages.templates.pricing.sections.hero.title'),
                    body: t('super_admin.pages.templates.pricing.sections.hero.body'),
                    items: [
                        t('super_admin.pages.templates.pricing.sections.hero.items.one'),
                        t('super_admin.pages.templates.pricing.sections.hero.items.two'),
                        t('super_admin.pages.templates.pricing.sections.hero.items.three'),
                    ],
                    primary_label: t('super_admin.pages.templates.pricing.sections.hero.primary_label'),
                    primary_href: '#pricing',
                    secondary_label: t('super_admin.pages.templates.pricing.sections.hero.secondary_label'),
                    secondary_href: '#contact',
                },
                {
                    layout: 'stack',
                    alignment: 'center',
                    tone: 'muted',
                    kicker: t('super_admin.pages.templates.pricing.sections.plans.kicker'),
                    title: t('super_admin.pages.templates.pricing.sections.plans.title'),
                    body: t('super_admin.pages.templates.pricing.sections.plans.body'),
                    items: [
                        t('super_admin.pages.templates.pricing.sections.plans.items.one'),
                        t('super_admin.pages.templates.pricing.sections.plans.items.two'),
                        t('super_admin.pages.templates.pricing.sections.plans.items.three'),
                    ],
                },
            ],
        },
    },
    {
        id: 'about',
        label: t('super_admin.pages.templates.about.label'),
        description: t('super_admin.pages.templates.about.description'),
        content: {
            page_title: t('super_admin.pages.templates.about.page_title'),
            page_subtitle: t('super_admin.pages.templates.about.page_subtitle'),
            sections: [
                {
                    layout: 'split',
                    kicker: t('super_admin.pages.templates.about.sections.mission.kicker'),
                    title: t('super_admin.pages.templates.about.sections.mission.title'),
                    body: t('super_admin.pages.templates.about.sections.mission.body'),
                },
                {
                    layout: 'split',
                    alignment: 'left',
                    kicker: t('super_admin.pages.templates.about.sections.values.kicker'),
                    title: t('super_admin.pages.templates.about.sections.values.title'),
                    body: t('super_admin.pages.templates.about.sections.values.body'),
                    items: [
                        t('super_admin.pages.templates.about.sections.values.items.one'),
                        t('super_admin.pages.templates.about.sections.values.items.two'),
                        t('super_admin.pages.templates.about.sections.values.items.three'),
                    ],
                },
                {
                    layout: 'stack',
                    alignment: 'center',
                    tone: 'contrast',
                    kicker: t('super_admin.pages.templates.about.sections.team.kicker'),
                    title: t('super_admin.pages.templates.about.sections.team.title'),
                    body: t('super_admin.pages.templates.about.sections.team.body'),
                    primary_label: t('super_admin.pages.templates.about.sections.team.primary_label'),
                    primary_href: '#contact',
                },
            ],
        },
    },
]);

const templateOptions = computed(() => [
    { value: '', label: t('super_admin.pages.templates.select') },
    ...templates.value.map((template) => ({ value: template.id, label: template.label })),
]);

const selectedTemplate = ref('');
const selectedTemplateMeta = computed(() =>
    templates.value.find((template) => template.id === selectedTemplate.value) || null
);

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

const layoutOptions = computed(() => [
    { value: 'split', label: t('super_admin.pages.layouts.split') },
    { value: 'duo', label: t('super_admin.pages.layouts.duo') },
    { value: 'testimonial', label: t('super_admin.pages.layouts.testimonial') },
    { value: 'feature_pairs', label: t('super_admin.pages.layouts.feature_pairs') },
    { value: 'showcase_cta', label: t('super_admin.pages.layouts.showcase_cta') },
    { value: 'industry_grid', label: t('super_admin.pages.layouts.industry_grid') },
    { value: 'story_grid', label: t('super_admin.pages.layouts.story_grid') },
    { value: 'feature_tabs', label: t('super_admin.pages.layouts.feature_tabs') },
    { value: 'testimonial_grid', label: t('super_admin.pages.layouts.testimonial_grid') },
    { value: 'stack', label: t('super_admin.pages.layouts.stack') },
    { value: 'contact', label: t('super_admin.pages.layouts.contact') },
]);

const alignmentOptions = computed(() => [
    { value: 'left', label: t('super_admin.pages.alignments.left') },
    { value: 'center', label: t('super_admin.pages.alignments.center') },
    { value: 'right', label: t('super_admin.pages.alignments.right') },
]);

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

const headerBackgroundTypeOptions = computed(() => [
    { value: 'none', label: t('super_admin.pages.header.background.none') },
    { value: 'color', label: t('super_admin.pages.header.background.color') },
    { value: 'image', label: t('super_admin.pages.header.background.image') },
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

const fontOptions = computed(() => [
    { value: 'montserrat', label: t('super_admin.pages.theme.fonts.montserrat') },
]);

const radiusOptions = computed(() => [
    { value: 'sm', label: t('super_admin.pages.theme.radius.sm') },
    { value: 'md', label: t('super_admin.pages.theme.radius.md') },
    { value: 'lg', label: t('super_admin.pages.theme.radius.lg') },
    { value: 'xl', label: t('super_admin.pages.theme.radius.xl') },
]);

const shadowOptions = computed(() => [
    { value: 'none', label: t('super_admin.pages.theme.shadow.none') },
    { value: 'soft', label: t('super_admin.pages.theme.shadow.soft') },
    { value: 'deep', label: t('super_admin.pages.theme.shadow.deep') },
]);

const buttonStyleOptions = computed(() => [
    { value: 'solid', label: t('super_admin.pages.theme.buttons.solid') },
    { value: 'outline', label: t('super_admin.pages.theme.buttons.outline') },
    { value: 'soft', label: t('super_admin.pages.theme.buttons.soft') },
    { value: 'ghost', label: t('super_admin.pages.theme.buttons.ghost') },
]);

const backgroundStyleOptions = computed(() => [
    { value: 'solid', label: t('super_admin.pages.theme.background.solid') },
    { value: 'gradient', label: t('super_admin.pages.theme.background.gradient') },
]);

const libraryOptions = computed(() => [
    { value: '', label: t('super_admin.pages.library.select') },
    ...(props.library_sections || []).map((section) => ({
        value: String(section.id),
        label: `${section.name} · ${t(`super_admin.sections.types.${section.type}`)}${section.is_active ? '' : ` (${t('super_admin.pages.library.draft')})`}`,
    })),
]);

const footerSection = computed(() => (
    props.footer_section && typeof props.footer_section === 'object' && props.footer_section.id
        ? props.footer_section
        : null
));

const footerPreview = computed(() => {
    if (!footerSection.value?.content || typeof footerSection.value.content !== 'object') {
        return null;
    }

    return (
        footerSection.value.content?.[currentLocale.value]
        || footerSection.value.content?.[props.default_locale]
        || Object.values(footerSection.value.content)[0]
        || null
    );
});

const footerSummary = computed(() => ({
    groups: Array.isArray(footerPreview.value?.footer_groups) ? footerPreview.value.footer_groups.length : 0,
    legalLinks: Array.isArray(footerPreview.value?.legal_links) ? footerPreview.value.legal_links.length : 0,
}));

const visibilityAuthOptions = computed(() => [
    { value: 'any', label: t('super_admin.pages.visibility.auth_any') },
    { value: 'auth', label: t('super_admin.pages.visibility.auth_only') },
    { value: 'guest', label: t('super_admin.pages.visibility.guest_only') },
]);

const visibilityDeviceOptions = computed(() => [
    { value: 'all', label: t('super_admin.pages.visibility.device_all') },
    { value: 'desktop', label: t('super_admin.pages.visibility.device_desktop') },
    { value: 'mobile', label: t('super_admin.pages.visibility.device_mobile') },
]);

const themeColorFields = computed(() => [
    { key: 'primary_color', label: t('super_admin.pages.theme.colors.primary') },
    { key: 'primary_soft_color', label: t('super_admin.pages.theme.colors.primary_soft') },
    { key: 'primary_contrast_color', label: t('super_admin.pages.theme.colors.primary_contrast') },
    { key: 'background_color', label: t('super_admin.pages.theme.colors.background') },
    { key: 'background_alt_color', label: t('super_admin.pages.theme.colors.background_alt'), optional: true },
    { key: 'surface_color', label: t('super_admin.pages.theme.colors.surface') },
    { key: 'text_color', label: t('super_admin.pages.theme.colors.text') },
    { key: 'muted_color', label: t('super_admin.pages.theme.colors.muted') },
    { key: 'border_color', label: t('super_admin.pages.theme.colors.border') },
]);

const showBackgroundAlt = computed(() => form.theme.background_style === 'gradient');
const visibleThemeColorFields = computed(() =>
    themeColorFields.value.filter((field) => !field.optional || showBackgroundAlt.value)
);

const selectedLibraryId = ref('');
const selectedSectionLayout = ref('split');
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

const linesToArray = (value) =>
    String(value || '')
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line.length > 0);

const createLocalId = (prefix) => `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;

const createStatItem = (overrides = {}) => ({
    id: overrides.id || createLocalId('page-stat'),
    value: overrides.value || '',
    label: overrides.label || '',
});

const ensureStatItems = (items) => (
    Array.isArray(items) ? items.map((item) => createStatItem(item)) : []
);

const createHeroImage = (overrides = {}) => ({
    id: overrides.id || createLocalId('page-hero-image'),
    image_url: overrides.image_url || '',
    image_alt: overrides.image_alt || '',
});

const ensureHeroImages = (items) => (
    Array.isArray(items) ? items.map((item) => createHeroImage(item)) : []
);

const stripHtml = (value) =>
    String(value || '')
        .replace(/<[^>]*>/g, ' ')
        .replace(/&nbsp;/gi, ' ')
        .replace(/\s+/g, ' ')
        .trim();

const truncateText = (value, max = 180) => {
    const text = String(value || '').trim();
    if (text.length <= max) {
        return text;
    }

    return `${text.slice(0, max - 1).trimEnd()}…`;
};

const formatVisibilityDate = (value) => {
    if (!value) return '';
    const text = String(value);
    return text.length >= 16 ? text.substring(0, 16) : text;
};

const ensureVisibility = (visibility) => ({
    locales: Array.isArray(visibility?.locales) ? visibility.locales : [],
    auth: visibility?.auth || 'any',
    roles: Array.isArray(visibility?.roles) ? visibility.roles : [],
    plans: Array.isArray(visibility?.plans) ? visibility.plans : [],
    device: visibility?.device || 'all',
    start_at: formatVisibilityDate(visibility?.start_at),
    end_at: formatVisibilityDate(visibility?.end_at),
});

const ensureHeader = (header) => ({
    background_type: header?.background_type || 'none',
    background_color: header?.background_color || '',
    background_image_url: header?.background_image_url || '',
    background_image_alt: header?.background_image_alt || '',
    alignment: header?.alignment || 'center',
});

const sectionPreset = (layout) => {
    if (layout === 'duo') {
        return {
            layout: 'duo',
            image_position: 'left',
            alignment: 'left',
            tone: 'contrast',
            background_color: '#0f172a',
        };
    }

    if (layout === 'testimonial') {
        return {
            layout: 'testimonial',
            image_position: 'right',
            alignment: 'left',
            background_color: '#e5ecef',
        };
    }

    if (layout === 'feature_pairs') {
        return {
            layout: 'feature_pairs',
            alignment: 'left',
        };
    }

    if (layout === 'showcase_cta') {
        return {
            layout: 'showcase_cta',
            image_position: 'right',
            showcase_divider_style: 'diagonal',
            alignment: 'left',
            tone: 'contrast',
            background_color: '#202322',
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

    if (layout === 'industry_grid') {
        return {
            layout: 'industry_grid',
            alignment: 'center',
            background_color: '#f7f2e8',
            title: currentLocale.value === 'fr'
                ? 'Fier partenaire des services a domicile dans plus de 50 industries.'
                : 'Proud partner to home services in over 50 industries.',
            primary_label: currentLocale.value === 'fr' ? 'Voir toutes les industries' : 'See All Industries',
            industry_cards: defaultIndustryCards(currentLocale.value),
        };
    }

    if (layout === 'story_grid') {
        return {
            layout: 'story_grid',
            alignment: 'center',
            background_color: '#f7f2e8',
            title: currentLocale.value === 'fr'
                ? 'Une IA pensee pour les entreprises de terrain.'
                : 'AI built for blue-collar businesses',
            story_cards: defaultStoryCards(currentLocale.value),
        };
    }

    if (layout === 'feature_tabs') {
        const showcaseSection = defaultFeatureTabsShowcaseSection(currentLocale.value);

        return {
            ...showcaseSection,
        };
    }

    if (layout === 'testimonial_grid') {
        return {
            layout: 'testimonial_grid',
            alignment: 'center',
            background_color: '#f7f2e8',
            title: currentLocale.value === 'fr'
                ? 'Approuve par les meilleures equipes d entretien.'
                : 'Trusted by the best cleaning teams.',
            body: currentLocale.value === 'fr'
                ? '<p>Les pros de l entretien utilisent MLK Pro pour simplifier la planification, suivre les preferences clients et mieux coordonner leur equipe.</p>'
                : '<p>Cleaning pros use MLK Pro to simplify scheduling, track client preferences, and coordinate their crews with less friction.</p>',
            testimonial_cards: defaultTestimonialCards(currentLocale.value),
        };
    }

    if (layout === 'stack') {
        return {
            layout: 'stack',
            alignment: 'center',
        };
    }

    if (layout === 'contact') {
        return {
            layout: 'contact',
            alignment: 'left',
        };
    }

    return {
        layout: 'split',
        alignment: 'left',
    };
};

const parseCommaList = (value) =>
    String(value || '')
        .split(',')
        .map((item) => item.trim())
        .filter((item) => item.length > 0);

const ensureSection = (section, index) => ({
    id: section?.id || `section-${index + 1}`,
    enabled: section?.enabled ?? true,
    source_id: section?.source_id ? String(section.source_id) : '',
    use_source: section?.use_source ?? false,
    override_items: section?.override_items ?? false,
    override_note: section?.override_note ?? false,
    override_stats: section?.override_stats ?? false,
    background_color: section?.background_color ?? '',
    background_preset: section?.background_preset ?? '',
    title_color: section?.title_color ?? '',
    body_color: section?.body_color ?? '',
    layout: section?.layout || 'split',
    image_position: section?.image_position || 'left',
    alignment: section?.alignment || 'left',
    density: section?.density || 'normal',
    tone: section?.tone || 'default',
    visibility: ensureVisibility(section?.visibility),
    kicker: section?.kicker || '',
    title: section?.title || '',
    body: section?.body || '',
    note: section?.note || '',
    title_font_size: Number(section?.title_font_size) > 0 ? Number(section.title_font_size) : 0,
    industry_cards: ensureIndustryCards(section?.industry_cards),
    story_cards: ensureStoryCards(section?.story_cards),
    feature_tabs: ensureFeatureTabs(section?.feature_tabs),
    feature_tabs_style: normalizeFeatureTabsStyle(section?.feature_tabs_style),
    feature_tabs_font_size: normalizeFeatureTabsTriggerFontSize(section?.feature_tabs_font_size),
    testimonial_cards: ensureTestimonialCards(section?.testimonial_cards),
    stats: ensureStatItems(section?.stats),
    hero_images: ensureHeroImages(section?.hero_images),
    items: Array.isArray(section?.items) ? section.items : [],
    testimonial_author: section?.testimonial_author || '',
    testimonial_role: section?.testimonial_role || '',
    aside_kicker: section?.aside_kicker || '',
    aside_title: section?.aside_title || '',
    aside_body: section?.aside_body || '',
    aside_items: Array.isArray(section?.aside_items) ? section.aside_items : [],
    aside_link_label: section?.aside_link_label || '',
    aside_link_href: section?.aside_link_href || '',
    aside_image_url: section?.aside_image_url || '',
    aside_image_alt: section?.aside_image_alt || '',
    image_url: section?.image_url || '',
    image_alt: section?.image_alt || '',
    embed_url: section?.embed_url || '',
    embed_title: section?.embed_title || '',
    embed_height: Number(section?.embed_height) > 0 ? Number(section.embed_height) : 760,
    primary_label: section?.primary_label || '',
    primary_href: section?.primary_href || '',
    secondary_label: section?.secondary_label || '',
    secondary_href: section?.secondary_href || '',
    showcase_badge_label: section?.showcase_badge_label || '',
    showcase_badge_value: section?.showcase_badge_value || '',
    showcase_badge_note: section?.showcase_badge_note || '',
    showcase_divider_style: section?.showcase_divider_style || 'diagonal',
});

const ensureStructure = (content) => {
    const next = clone(content);
    next.page_title = next.page_title ?? props.page.title ?? '';
    next.page_subtitle = next.page_subtitle ?? '';
    next.header = ensureHeader(next.header);
    next.sections = Array.isArray(next.sections) ? next.sections : [];
    next.sections = next.sections.map((section, index) => ensureSection(section, index));

    return next;
};

const applyPendingSectionRemovals = (content) => {
    const removed = new Set(
        (removedSectionIds.value || [])
            .map((value) => String(value || '').trim())
            .filter((value) => value.length > 0)
    );

    if (!removed.size) {
        return content;
    }

    return {
        ...content,
        sections: Array.isArray(content?.sections)
            ? content.sections.filter((section) => !removed.has(String(section?.id || '').trim()))
            : [],
    };
};

const buildLocaleContentDrafts = (source = props.content) => {
    const drafts = {};

    localeList.value.forEach((locale) => {
        drafts[locale] = applyPendingSectionRemovals(ensureStructure(source?.[locale] || {}));
    });

    return drafts;
};

const storeLocaleDraft = (locale = currentLocale.value) => {
    if (!locale) {
        return;
    }

    contentByLocale.value = {
        ...contentByLocale.value,
        [locale]: ensureStructure(clone(form.content)),
    };
};

const syncSectionStructureAcrossLocales = (sections, sourceLocale = currentLocale.value) => {
    const canonicalSections = Array.isArray(sections)
        ? sections.map((section, index) => ensureSection(clone(section), index))
        : [];

    const nextDrafts = { ...contentByLocale.value };

    localeList.value.forEach((locale) => {
        const base = ensureStructure(nextDrafts[locale] || props.content?.[locale] || {});
        const existingSections = new Map(
            (base.sections || [])
                .map((section, index) => [String(section?.id || '').trim(), ensureSection(section, index)])
                .filter(([sectionId]) => sectionId.length > 0)
        );

        nextDrafts[locale] = {
            ...base,
            sections: canonicalSections.map((section, index) => (
                ensureSection(clone(existingSections.get(section.id) || section), index)
            )),
        };
    });

    if (sourceLocale) {
        nextDrafts[sourceLocale] = ensureStructure({
            ...(nextDrafts[sourceLocale] || {}),
            ...clone(form.content),
            sections: canonicalSections,
        });
    }

    contentByLocale.value = nextDrafts;
};

const rememberRemovedSection = (sectionId) => {
    const normalized = String(sectionId || '').trim();
    if (!normalized || removedSectionIds.value.includes(normalized)) {
        return;
    }

    removedSectionIds.value = [...removedSectionIds.value, normalized];
};

const syncSectionEditorState = ({ openSectionId = null } = {}) => {
    const previous = sectionEditorOpen.value || {};
    const shouldOpenFirst = !Object.keys(previous).length && isCreateMode.value;
    const next = {};

    (form.content.sections || []).forEach((section, index) => {
        next[section.id] = section.id === openSectionId
            ? true
            : previous[section.id] ?? (shouldOpenFirst && index === 0);
    });

    sectionEditorOpen.value = next;
};

const rebuildItemsLines = () => {
    const map = {};
    const asideMap = {};
    (form.content.sections || []).forEach((section) => {
        map[section.id] = (section.items || []).join('\n');
        asideMap[section.id] = (section.aside_items || []).join('\n');
    });
    sectionItemsLines.value = map;
    sectionAsideItemsLines.value = asideMap;
    const rolesMap = {};
    const plansMap = {};
    (form.content.sections || []).forEach((section) => {
        rolesMap[section.id] = (section.visibility?.roles || []).join(', ');
        plansMap[section.id] = (section.visibility?.plans || []).join(', ');
    });
    visibilityRoleLines.value = rolesMap;
    visibilityPlanLines.value = plansMap;
};

const syncFormFromProps = (locale = currentLocale.value) => {
    const incoming = ensureStructure(contentByLocale.value?.[locale] || props.content?.[locale] || {});
    form.locale = locale;
    form.content = clone(incoming);
    rebuildItemsLines();
    syncSectionEditorState();
};

watch(
    () => props.content,
    () => {
        contentByLocale.value = buildLocaleContentDrafts(props.content);
        syncFormFromProps(currentLocale.value);
    },
    { deep: true }
);

watch(
    () => props.theme,
    (theme) => {
        form.theme = ensureTheme(theme);
    },
    { deep: true }
);

watch(currentLocale, (locale, previousLocale) => {
    if (previousLocale) {
        storeLocaleDraft(previousLocale);
    }

    syncFormFromProps(locale);
});

const updateSectionItems = (section, value) => {
    sectionItemsLines.value = { ...sectionItemsLines.value, [section.id]: value };
    section.override_items = true;
    section.items = linesToArray(value);
};

const updateSectionAsideItems = (section, value) => {
    sectionAsideItemsLines.value = { ...sectionAsideItemsLines.value, [section.id]: value };
    section.aside_items = linesToArray(value);
};

const updateSectionNote = (section, value) => {
    if (!section) return;
    section.override_note = true;
    section.note = value;
};

const addSectionStat = (section) => {
    if (!section) return;
    section.override_stats = true;
    section.stats = [...(section.stats || []), createStatItem()];
};

const moveSectionStat = (section, index, direction) => {
    if (!section?.stats?.length) return;
    section.override_stats = true;
    moveItem(section.stats, index, direction);
};

const removeSectionStat = (section, index) => {
    if (!section?.stats) return;
    section.override_stats = true;
    section.stats.splice(index, 1);
};

const updateSectionStatField = (section, item, key, value) => {
    if (!section || !item) return;
    section.override_stats = true;
    item[key] = value;
};

const addSectionHeroImage = (section) => {
    if (!section) return;
    section.hero_images = [...(section.hero_images || []), createHeroImage()];
};

const moveSectionHeroImage = (section, index, direction) => {
    if (!section?.hero_images?.length) return;
    moveItem(section.hero_images, index, direction);
};

const removeSectionHeroImage = (section, index) => {
    if (!section?.hero_images) return;
    section.hero_images.splice(index, 1);
};

const updateVisibilityList = (section, key, value) => {
    if (!section?.visibility) {
        section.visibility = ensureVisibility({});
    }
    if (key === 'roles') {
        visibilityRoleLines.value = { ...visibilityRoleLines.value, [section.id]: value };
    }
    if (key === 'plans') {
        visibilityPlanLines.value = { ...visibilityPlanLines.value, [section.id]: value };
    }
    section.visibility[key] = parseCommaList(value);
};

const backgroundFieldLabel = (section) => (
    section?.layout === 'duo'
        ? t('super_admin.pages.common.panel_background_hex')
        : t('super_admin.pages.common.background_hex')
);

const backgroundHeadingLabel = (section) => (
    section?.layout === 'duo'
        ? t('super_admin.pages.common.panel_background')
        : t('super_admin.pages.common.background')
);

const openAssetPicker = (target, urlKey = 'image_url', altKey = 'image_alt') => {
    if (!target) return;
    assetTarget.value = { target, urlKey, altKey };
    assetPickerOpen.value = true;
};

const closeAssetPicker = () => {
    assetPickerOpen.value = false;
    assetTarget.value = null;
};

const handleAssetSelect = (asset) => {
    if (!assetTarget.value?.target || !asset) {
        return;
    }
    const { target, urlKey, altKey } = assetTarget.value;
    target[urlKey] = asset.url || '';
    if (!target[altKey]) {
        target[altKey] = asset.alt || asset.name || '';
    }
    closeAssetPicker();
};

const findLibrarySection = (id) =>
    (props.library_sections || []).find((section) => String(section.id) === String(id));

const sectionSourceType = (section) => findLibrarySection(section?.source_id)?.type || '';
const isWelcomeHeroSection = (section) => sectionSourceType(section) === 'welcome_hero';

const sectionLayoutLabel = (layout) =>
    layoutOptions.value.find((option) => option.value === layout)?.label || layout;

const sectionPreviewHeading = (section, index) => (
    section?.title ||
    section?.story_cards?.[0]?.title ||
    section?.feature_tabs?.[0]?.children?.[0]?.label ||
    section?.feature_tabs?.[0]?.label ||
    section?.feature_tabs?.[0]?.title ||
    section?.testimonial_cards?.[0]?.author_name ||
    section?.aside_title ||
    section?.kicker ||
    sectionLayoutLabel(section?.layout) ||
    `${t('super_admin.pages.sections.section_label')} #${index + 1}`
);

const sectionPreviewText = (section) => {
    const bulletText = Array.isArray(section?.items) ? section.items.join(' • ') : '';
    const asideBulletText = Array.isArray(section?.aside_items) ? section.aside_items.join(' • ') : '';
    const storyGridText = Array.isArray(section?.story_cards)
        ? section.story_cards
            .map((card) => stripHtml(card?.body) || card?.title || '')
            .find((value) => String(value || '').trim().length > 0) || ''
        : '';
    const featureTabText = Array.isArray(section?.feature_tabs)
        ? section.feature_tabs
            .map((tab) => (
                tab?.children?.[0]?.title ||
                stripHtml(tab?.children?.[0]?.body) ||
                tab?.title ||
                stripHtml(tab?.body) ||
                (Array.isArray(tab?.items) ? tab.items.join(' • ') : '')
            ))
            .find((value) => String(value || '').trim().length > 0) || ''
        : '';
    const testimonialGridText = Array.isArray(section?.testimonial_cards)
        ? section.testimonial_cards
            .map((card) => stripHtml(card?.quote) || card?.author_company || card?.author_role || '')
            .find((value) => String(value || '').trim().length > 0) || ''
        : '';
    const testimonialMeta = section?.testimonial_author
        ? `${section.testimonial_author}${section?.testimonial_role ? ` · ${section.testimonial_role}` : ''}`
        : '';

    return truncateText(
        stripHtml(section?.body) ||
        storyGridText ||
        featureTabText ||
        testimonialGridText ||
        stripHtml(section?.aside_body) ||
        bulletText ||
        asideBulletText ||
        testimonialMeta
    );
};

const sectionPreviewImages = (section) =>
    [
        ...(Array.isArray(section?.hero_images) ? section.hero_images.map((item) => item?.image_url) : []),
        section?.image_url,
        section?.aside_image_url,
        ...(Array.isArray(section?.story_cards) ? section.story_cards.map((card) => card?.image_url) : []),
        ...(Array.isArray(section?.feature_tabs) ? section.feature_tabs.map((tab) => tab?.image_url) : []),
        ...(Array.isArray(section?.feature_tabs)
            ? section.feature_tabs.flatMap((tab) => Array.isArray(tab?.children) ? tab.children.map((child) => child?.image_url) : [])
            : []),
        ...(Array.isArray(section?.testimonial_cards) ? section.testimonial_cards.map((card) => card?.image_url) : []),
    ]
        .filter((value) => String(value || '').trim().length > 0)
        .slice(0, 3);

const sectionPreviewBadges = (section) => {
    const badges = [sectionLayoutLabel(section?.layout)];
    const sourceName = section?.source_id ? findLibrarySection(section.source_id)?.name : '';
    const bulletCount = (section?.items?.length || 0) + (section?.aside_items?.length || 0);
    const imageCount = sectionPreviewImages(section).length;

    if (sourceName) {
        badges.push(sourceName);
    }

    if (section?.layout === 'industry_grid' && section?.industry_cards?.length) {
        badges.push(t('super_admin.pages.sections.cards_count', { count: section.industry_cards.length }));
    }

    if (section?.layout === 'story_grid' && section?.story_cards?.length) {
        badges.push(t('super_admin.pages.sections.cards_count', { count: section.story_cards.length }));
    }

    if (section?.layout === 'testimonial_grid' && section?.testimonial_cards?.length) {
        badges.push(t('super_admin.pages.sections.cards_count', { count: section.testimonial_cards.length }));
    }

    if (section?.layout === 'feature_tabs' && section?.feature_tabs?.length) {
        badges.push(t('super_admin.pages.sections.tabs_count', { count: section.feature_tabs.length }));
    }

    if (!['industry_grid', 'story_grid', 'feature_tabs', 'testimonial_grid'].includes(section?.layout) && bulletCount) {
        badges.push(t('super_admin.pages.sections.items_count', { count: bulletCount }));
    }

    if (imageCount) {
        badges.push(t('super_admin.pages.sections.images_count', { count: imageCount }));
    }

    return badges;
};

const isSectionEditorOpen = (sectionId) => Boolean(sectionEditorOpen.value?.[sectionId]);

const toggleSectionEditor = (sectionId, force = null) => {
    if (!sectionId) {
        return;
    }

    sectionEditorOpen.value = {
        ...sectionEditorOpen.value,
        [sectionId]: force === null ? !sectionEditorOpen.value?.[sectionId] : Boolean(force),
    };
};

const resolveLibraryContent = (section) => {
    if (!section?.source_id) {
        return null;
    }
    const library = findLibrarySection(section.source_id);
    if (!library?.content) {
        return null;
    }
    const locale = currentLocale.value;
    return library.content?.[locale] || library.content?.[props.default_locale] || null;
};

const applyLibraryToSection = (section) => {
    const content = resolveLibraryContent(section);
    if (!content) {
        return;
    }
    section.use_source = true;
    section.background_color = content.background_color ?? section.background_color ?? '';
    section.background_preset = content.background_preset ?? section.background_preset ?? '';
    section.title_color = content.title_color ?? section.title_color ?? '';
    section.body_color = content.body_color ?? section.body_color ?? '';
    section.layout = content.layout ?? section.layout ?? 'split';
    section.image_position = content.image_position ?? section.image_position ?? 'left';
    section.alignment = content.alignment ?? section.alignment ?? 'left';
    section.density = content.density ?? section.density ?? 'normal';
    section.tone = content.tone ?? section.tone ?? 'default';
    section.kicker = content.kicker ?? '';
    section.title = content.title ?? '';
    section.body = content.body ?? '';
    section.note = content.note ?? '';
    section.title_font_size = Number(content.title_font_size) > 0 ? Number(content.title_font_size) : 0;
    section.industry_cards = ensureIndustryCards(content.industry_cards);
    section.story_cards = ensureStoryCards(content.story_cards);
    section.feature_tabs = ensureFeatureTabs(content.feature_tabs);
    section.feature_tabs_style = normalizeFeatureTabsStyle(content.feature_tabs_style);
    section.feature_tabs_font_size = normalizeFeatureTabsTriggerFontSize(content.feature_tabs_font_size);
    section.testimonial_cards = ensureTestimonialCards(content.testimonial_cards);
    section.stats = ensureStatItems(content.stats);
    section.hero_images = ensureHeroImages(content.hero_images);
    section.items = Array.isArray(content.items) ? content.items : [];
    section.override_items = false;
    section.override_note = false;
    section.override_stats = false;
    section.testimonial_author = content.testimonial_author ?? '';
    section.testimonial_role = content.testimonial_role ?? '';
    section.aside_kicker = content.aside_kicker ?? '';
    section.aside_title = content.aside_title ?? '';
    section.aside_body = content.aside_body ?? '';
    section.aside_items = Array.isArray(content.aside_items) ? content.aside_items : [];
    section.aside_link_label = content.aside_link_label ?? '';
    section.aside_link_href = content.aside_link_href ?? '';
    section.aside_image_url = content.aside_image_url ?? '';
    section.aside_image_alt = content.aside_image_alt ?? '';
    section.image_url = content.image_url ?? '';
    section.image_alt = content.image_alt ?? '';
    section.embed_url = content.embed_url ?? '';
    section.embed_title = content.embed_title ?? '';
    section.embed_height = Number(content.embed_height) > 0 ? Number(content.embed_height) : 760;
    section.primary_label = content.primary_label ?? '';
    section.primary_href = content.primary_href ?? '';
    section.secondary_label = content.secondary_label ?? '';
    section.secondary_href = content.secondary_href ?? '';
    section.showcase_badge_label = content.showcase_badge_label ?? '';
    section.showcase_badge_value = content.showcase_badge_value ?? '';
    section.showcase_badge_note = content.showcase_badge_note ?? '';
    section.showcase_divider_style = content.showcase_divider_style ?? 'diagonal';
    rebuildItemsLines();
};

const addFromLibrary = () => {
    const id = selectedLibraryId.value;
    if (!id) {
        return;
    }
    const nextIndex = (form.content.sections || []).length;
    const section = ensureSection(
        {
            id: `section-${Date.now()}`,
            source_id: String(id),
            use_source: true,
        },
        nextIndex
    );
    form.content.sections.push(section);
    applyLibraryToSection(section);
    syncSectionStructureAcrossLocales(form.content.sections);
    selectedLibraryId.value = '';
    syncSectionEditorState({ openSectionId: section.id });
};

const moveItem = (list, index, direction) => {
    const nextIndex = index + direction;
    if (nextIndex < 0 || nextIndex >= list.length) return;
    const cloneList = [...list];
    const [item] = cloneList.splice(index, 1);
    cloneList.splice(nextIndex, 0, item);
    list.splice(0, list.length, ...cloneList);
    if (list === form.content.sections) {
        syncSectionStructureAcrossLocales(form.content.sections);
    }
    rebuildItemsLines();
};

const addIndustryCard = (section) => {
    if (!section) return;
    section.industry_cards = [...(section.industry_cards || []), createIndustryCard()];
};

const addStoryCard = (section) => {
    if (!section) return;
    section.story_cards = [...(section.story_cards || []), createStoryCard()];
};

const addTestimonialCard = (section) => {
    if (!section) return;
    section.testimonial_cards = [...(section.testimonial_cards || []), createTestimonialCard()];
};

const moveIndustryCard = (section, index, direction) => {
    if (!section?.industry_cards?.length) return;
    moveItem(section.industry_cards, index, direction);
};

const moveStoryCard = (section, index, direction) => {
    if (!section?.story_cards?.length) return;
    moveItem(section.story_cards, index, direction);
};

const moveTestimonialCard = (section, index, direction) => {
    if (!section?.testimonial_cards?.length) return;
    moveItem(section.testimonial_cards, index, direction);
};

const removeIndustryCard = (section, index) => {
    if (!section?.industry_cards) return;
    section.industry_cards.splice(index, 1);
};

const removeStoryCard = (section, index) => {
    if (!section?.story_cards) return;
    section.story_cards.splice(index, 1);
};

const removeTestimonialCard = (section, index) => {
    if (!section?.testimonial_cards) return;
    section.testimonial_cards.splice(index, 1);
};

const addFeatureTab = (section) => {
    if (!section) return;
    section.feature_tabs = [...(section.feature_tabs || []), createFeatureTab()];
};

const addFeatureTabChild = (tab) => {
    if (!tab) return;
    tab.children = [...(tab.children || []), createFeatureTabChild()];
};

const moveFeatureTab = (section, index, direction) => {
    if (!section?.feature_tabs?.length) return;
    moveItem(section.feature_tabs, index, direction);
};

const moveFeatureTabChild = (tab, index, direction) => {
    if (!tab?.children?.length) return;
    moveItem(tab.children, index, direction);
};

const removeFeatureTab = (section, index) => {
    if (!section?.feature_tabs) return;
    section.feature_tabs.splice(index, 1);
};

const removeFeatureTabChild = (tab, index) => {
    if (!tab?.children) return;
    tab.children.splice(index, 1);
};

const updateFeatureTabItems = (tab, value) => {
    tab.items = linesToArray(value);
};

const addSection = () => {
    const nextIndex = (form.content.sections || []).length;
    const section = ensureSection(
        {
            id: `section-${Date.now()}`,
            ...sectionPreset(selectedSectionLayout.value),
        },
        nextIndex
    );
    form.content.sections.push(section);
    syncSectionStructureAcrossLocales(form.content.sections);
    rebuildItemsLines();
    syncSectionEditorState({ openSectionId: section.id });
};

const removeSection = (index) => {
    const section = form.content.sections[index];
    if (section?.id) {
        rememberRemovedSection(section.id);
    }
    form.content.sections.splice(index, 1);
    if (section?.id) {
        const next = { ...sectionItemsLines.value };
        delete next[section.id];
        sectionItemsLines.value = next;
    }
    if (section?.id) {
        const nextAside = { ...sectionAsideItemsLines.value };
        delete nextAside[section.id];
        sectionAsideItemsLines.value = nextAside;

        const nextRoles = { ...visibilityRoleLines.value };
        delete nextRoles[section.id];
        visibilityRoleLines.value = nextRoles;

        const nextPlans = { ...visibilityPlanLines.value };
        delete nextPlans[section.id];
        visibilityPlanLines.value = nextPlans;
    }
    syncSectionStructureAcrossLocales(form.content.sections);
    syncSectionEditorState();
};

const updatedAtLabel = computed(() => {
    if (!props.meta?.updated_at) return t('super_admin.pages.meta.never');
    const date = new Date(props.meta.updated_at);
    return Number.isNaN(date.getTime()) ? props.meta.updated_at : date.toLocaleString();
});

const updatedByLabel = computed(() => {
    const user = props.meta?.updated_by;
    if (!user) return '';
    return user.name || user.email || '';
});

const titleLabel = computed(() =>
    props.mode === 'create' ? t('super_admin.pages.edit.title_create') : t('super_admin.pages.edit.title_edit')
);

const buildSubmitPayload = () => ({
    slug: form.slug,
    title: form.title,
    is_active: form.is_active,
    locale: form.locale,
    content: {
        ...clone(form.content),
        sections_present: true,
    },
    theme: clone(form.theme),
});

const submit = () => {
    storeLocaleDraft(currentLocale.value);
    const payload = buildSubmitPayload();

    if (props.mode === 'create') {
        form.transform(() => payload).post(route('superadmin.pages.store'), { preserveScroll: true });
        return;
    }

    if (!props.page?.id) return;
    form.transform(() => payload).put(route('superadmin.pages.update', props.page.id), { preserveScroll: true });
};

const applyTemplate = () => {
    const template = templates.value.find((item) => item.id === selectedTemplate.value);
    if (!template) return;
    form.content = ensureStructure(template.content);
    syncSectionStructureAcrossLocales(form.content.sections);
    rebuildItemsLines();
};

contentByLocale.value = buildLocaleContentDrafts(props.content);
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
                            {{ $t('super_admin.pages.subtitle') }}
                        </p>
                        <div class="text-xs text-stone-500 dark:text-neutral-500">
                            {{ $t('super_admin.pages.meta.updated_at') }}: {{ updatedAtLabel }}
                            <span v-if="updatedByLabel">- {{ updatedByLabel }}</span>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Link :href="dashboard_url"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ $t('super_admin.pages.actions.back_dashboard') }}
                        </Link>
                        <Link :href="index_url"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ $t('super_admin.pages.actions.back_list') }}
                        </Link>
                        <a v-if="public_url" :href="public_url" target="_blank" rel="noopener"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ $t('super_admin.pages.actions.preview') }}
                        </a>
                        <button type="button" @click="submit"
                            class="rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                            :disabled="form.processing">
                            {{ $t('super_admin.pages.actions.save') }}
                        </button>
                    </div>
                </div>
            </section>

            <section v-if="isCreateMode"
                class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid gap-3 md:grid-cols-3 md:items-end">
                    <FloatingSelect v-model="selectedTemplate" :options="templateOptions"
                        :label="$t('super_admin.pages.templates.title')" />
                    <div class="md:col-span-2 space-y-1 text-sm text-stone-600 dark:text-neutral-400">
                        <div>{{ $t('super_admin.pages.templates.hint') }}</div>
                        <div v-if="selectedTemplateMeta" class="text-xs text-stone-500 dark:text-neutral-500">
                            {{ selectedTemplateMeta.description }}
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="button" @click="applyTemplate" :disabled="!selectedTemplate"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                        {{ $t('super_admin.pages.templates.apply') }}
                    </button>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid gap-3 md:grid-cols-4">
                    <FloatingInput v-model="form.title" :label="$t('super_admin.pages.fields.title')" />
                    <FloatingInput v-model="form.slug" :label="$t('super_admin.pages.fields.slug')" :disabled="isWelcomePage" />
                    <div class="md:col-span-2 flex items-center gap-3 rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                        <Checkbox v-model:checked="form.is_active" />
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ $t('super_admin.pages.fields.is_active') }}
                        </div>
                    </div>
                </div>
                <div v-if="form.errors.slug" class="mt-1 text-xs font-semibold text-red-600">
                    {{ form.errors.slug }}
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                <div>
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.pages.theme.title') }}
                    </h2>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.pages.theme.subtitle') }}
                    </p>
                </div>

                <div class="grid gap-3 md:grid-cols-3">
                    <FloatingSelect v-model="form.theme.font_heading" :options="fontOptions"
                        :label="$t('super_admin.pages.theme.fields.font_heading')" />
                    <FloatingSelect v-model="form.theme.font_body" :options="fontOptions"
                        :label="$t('super_admin.pages.theme.fields.font_body')" />
                    <FloatingSelect v-model="form.theme.button_style" :options="buttonStyleOptions"
                        :label="$t('super_admin.pages.theme.fields.button_style')" />
                    <FloatingSelect v-model="form.theme.radius" :options="radiusOptions"
                        :label="$t('super_admin.pages.theme.fields.radius')" />
                    <FloatingSelect v-model="form.theme.shadow" :options="shadowOptions"
                        :label="$t('super_admin.pages.theme.fields.shadow')" />
                    <FloatingSelect v-model="form.theme.background_style" :options="backgroundStyleOptions"
                        :label="$t('super_admin.pages.theme.fields.background_style')" />
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <div v-for="field in visibleThemeColorFields" :key="field.key"
                        class="grid gap-2 md:grid-cols-[160px_1fr] md:items-center">
                        <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            {{ field.label }}
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <input v-model="form.theme[field.key]" type="color"
                                class="h-10 w-14 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                            <div class="min-w-[200px] flex-1">
                                <FloatingInput v-model="form.theme[field.key]" :label="field.label" />
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                <div class="space-y-1">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.pages.header.title') }}
                    </h2>
                    <p class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.pages.header.subtitle') }}
                    </p>
                </div>

                <div class="grid gap-3 md:grid-cols-3">
                    <FloatingSelect v-model="currentLocale" :options="localeOptions"
                        :label="$t('super_admin.pages.locale.label')" />
                    <FloatingSelect v-model="form.content.header.alignment" :options="alignmentOptions"
                        :label="$t('super_admin.pages.header.alignment')" />
                    <FloatingSelect v-model="form.content.header.background_type" :options="headerBackgroundTypeOptions"
                        :label="$t('super_admin.pages.header.background_type')" />
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <FloatingInput v-model="form.content.page_title" :label="$t('super_admin.pages.fields.page_title')" />
                    <div class="md:col-span-2">
                        <RichTextEditor
                            v-model="form.content.page_subtitle"
                            :label="$t('super_admin.pages.fields.page_subtitle')"
                            :link-prompt="editorLinkPrompt"
                            :image-prompt="editorImagePrompt"
                            :ai-enabled="ai_enabled"
                            :ai-generate-url="ai_image_generate_url"
                            :ai-prompt="editorAiPrompt"
                            :labels="editorLabels"
                        />
                    </div>
                </div>

                <div v-if="form.content.header.background_type === 'color'"
                    class="grid gap-2 md:grid-cols-[160px_1fr] md:items-center">
                    <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.pages.header.background_color') }}
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <input v-model="form.content.header.background_color" type="color"
                            class="h-10 w-14 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                        <div class="min-w-[220px] flex-1">
                            <FloatingInput v-model="form.content.header.background_color"
                                :label="$t('super_admin.pages.header.background_color')" />
                        </div>
                    </div>
                </div>

                <div v-if="form.content.header.background_type === 'image'" class="space-y-3">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="space-y-2">
                            <FloatingInput v-model="form.content.header.background_image_url"
                                :label="$t('super_admin.pages.header.background_image_url')" />
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <button v-if="asset_list_url" type="button"
                                    class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50"
                                    @click="openAssetPicker(form.content.header, 'background_image_url', 'background_image_alt')">
                                    {{ $t('super_admin.pages.assets.choose') }}
                                </button>
                                <span v-if="form.content.header.background_image_url" class="text-stone-500">
                                    {{ $t('super_admin.pages.assets.preview') }}
                                </span>
                            </div>
                            <div v-if="form.content.header.background_image_url"
                                class="overflow-hidden rounded-sm border border-stone-200 bg-white">
                                <img
                                    :src="form.content.header.background_image_url"
                                    :alt="form.content.header.background_image_alt || form.content.page_title"
                                    class="h-40 w-full object-cover"
                                    loading="lazy"
                                    decoding="async"
                                />
                            </div>
                        </div>
                        <FloatingInput v-model="form.content.header.background_image_alt"
                            :label="$t('super_admin.pages.header.background_image_alt')" />
                    </div>
                </div>

                <div class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('super_admin.pages.locale.hint') }}
                </div>
            </section>

            <section
                v-if="footerSection"
                class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <div class="inline-flex items-center rounded-sm bg-stone-100 px-2 py-1 text-xs font-medium text-stone-600 dark:bg-neutral-800 dark:text-neutral-300">
                            {{ $t('super_admin.sections.types.footer') }}
                        </div>
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.pages.shared_footer.title') }}
                        </h2>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.pages.shared_footer.subtitle') }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Link
                            :href="footerSection.edit_url"
                            class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            {{ $t('super_admin.pages.shared_footer.edit') }}
                        </Link>
                        <Link
                            v-if="library_index_url"
                            :href="library_index_url"
                            class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            {{ $t('super_admin.pages.shared_footer.manage') }}
                        </Link>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-[minmax(0,1.35fr)_minmax(260px,320px)]">
                    <div class="space-y-3 rounded-sm border border-stone-200 p-4 dark:border-neutral-700">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                {{ footerSection.name }}
                            </span>
                            <span
                                class="inline-flex rounded-full px-2 py-1 font-semibold"
                                :class="footerSection.is_active
                                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                                    : 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200'"
                            >
                                {{ footerSection.is_active ? $t('super_admin.sections.status.active') : $t('super_admin.sections.status.draft') }}
                            </span>
                        </div>

                        <div class="space-y-1">
                            <div class="text-lg font-semibold text-stone-900 dark:text-white">
                                {{ footerPreview?.title || $t('super_admin.pages.shared_footer.empty_title') }}
                            </div>
                            <p v-if="footerPreview?.body" class="max-w-3xl text-sm leading-6 text-stone-600 dark:text-neutral-300">
                                {{ truncateText(stripHtml(footerPreview.body), 220) }}
                            </p>
                        </div>

                        <div class="grid gap-3 md:grid-cols-3">
                            <div class="rounded-sm bg-stone-50 p-3 dark:bg-neutral-800/70">
                                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.shared_footer.navigation') }}
                                </div>
                                <div class="mt-1 text-base font-semibold text-stone-900 dark:text-white">
                                    {{ footerSummary.groups }}
                                </div>
                            </div>
                            <div class="rounded-sm bg-stone-50 p-3 dark:bg-neutral-800/70">
                                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.shared_footer.legal_links') }}
                                </div>
                                <div class="mt-1 text-base font-semibold text-stone-900 dark:text-white">
                                    {{ footerSummary.legalLinks }}
                                </div>
                            </div>
                            <div class="rounded-sm bg-stone-50 p-3 dark:bg-neutral-800/70">
                                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.shared_footer.contact') }}
                                </div>
                                <div class="mt-1 text-sm font-medium text-stone-900 dark:text-white">
                                    {{ footerPreview?.contact_email || footerPreview?.contact_phone || '-' }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-dashed border-stone-200 p-4 text-sm text-stone-600 dark:border-neutral-700 dark:text-neutral-300">
                        {{ $t('super_admin.pages.shared_footer.note') }}
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.pages.sections.title') }}
                    </h2>
                    <div class="grid w-full gap-2 sm:w-auto sm:grid-cols-[minmax(220px,15rem)_auto] sm:items-end">
                        <FloatingSelect
                            v-model="selectedSectionLayout"
                            :options="layoutOptions"
                            :label="$t('super_admin.pages.sections.add_type')"
                        />
                        <button type="button"
                            class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            @click="addSection">
                            {{ $t('super_admin.pages.sections.add') }}
                        </button>
                    </div>
                </div>

                <div class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700">
                    <div class="grid gap-3 md:grid-cols-3 md:items-end">
                        <FloatingSelect
                            v-model="selectedLibraryId"
                            :options="libraryOptions"
                            :label="$t('super_admin.pages.library.select')"
                        />
                        <button type="button"
                            class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            @click="addFromLibrary">
                            {{ $t('super_admin.pages.library.add') }}
                        </button>
                        <Link v-if="library_index_url" :href="library_index_url"
                            class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ $t('super_admin.pages.library.manage') }}
                        </Link>
                    </div>
                </div>

                <div class="space-y-4">
                    <div
                        v-if="!form.content.sections.length"
                        class="rounded-sm border border-dashed border-stone-200 bg-stone-50/70 p-6 text-center dark:border-neutral-700 dark:bg-neutral-900/60"
                    >
                        <div class="space-y-2">
                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.pages.sections.empty_title') }}
                            </div>
                            <p class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.pages.sections.empty_subtitle') }}
                            </p>
                        </div>
                        <div class="mt-4 flex flex-wrap justify-center gap-3">
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                @click="addSection"
                            >
                                {{ $t('super_admin.pages.sections.add') }}
                            </button>
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                @click="addFromLibrary"
                            >
                                {{ $t('super_admin.pages.library.add') }}
                            </button>
                        </div>
                    </div>

                    <div v-for="(section, index) in form.content.sections" :key="section.id || index"
                        class="rounded-sm border border-stone-200 p-4 dark:border-neutral-700 space-y-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 space-y-2">
                                <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('super_admin.pages.sections.section_label') }} #{{ index + 1 }}
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <span
                                        v-for="badge in sectionPreviewBadges(section)"
                                        :key="`${section.id}-${badge}`"
                                        class="inline-flex items-center rounded-sm bg-stone-100 px-2 py-1 text-xs font-medium text-stone-600 dark:bg-neutral-800 dark:text-neutral-300"
                                    >
                                        {{ badge }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                    <Checkbox v-model:checked="section.enabled" />
                                    <span>{{ $t('super_admin.pages.common.enabled') }}</span>
                                </label>
                                <button
                                    type="button"
                                    class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    @click="toggleSectionEditor(section.id)"
                                >
                                    {{
                                        isSectionEditorOpen(section.id)
                                            ? $t('super_admin.pages.sections.close_editor')
                                            : $t('super_admin.pages.sections.edit')
                                    }}
                                </button>
                                <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                    @click="moveItem(form.content.sections, index, -1)">
                                    {{ $t('super_admin.pages.common.move_up') }}
                                </button>
                                <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                    @click="moveItem(form.content.sections, index, 1)">
                                    {{ $t('super_admin.pages.common.move_down') }}
                                </button>
                                <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                    @click="removeSection(index)">
                                    {{ $t('super_admin.pages.common.remove') }}
                                </button>
                            </div>
                        </div>

                        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(220px,260px)] xl:items-start">
                            <div class="min-w-0 space-y-2">
                                <div class="text-lg font-semibold leading-tight text-stone-900 dark:text-white">
                                    {{ sectionPreviewHeading(section, index) }}
                                </div>
                                <p v-if="sectionPreviewText(section)" class="max-w-3xl text-sm leading-6 text-stone-600 dark:text-neutral-300">
                                    {{ sectionPreviewText(section) }}
                                </p>
                            </div>

                            <div
                                class="grid gap-2"
                                :class="sectionPreviewImages(section).length > 1 ? 'grid-cols-2' : 'grid-cols-1'"
                            >
                                <template v-if="sectionPreviewImages(section).length">
                                    <div
                                        v-for="(imageUrl, imageIndex) in sectionPreviewImages(section)"
                                        :key="`${section.id}-preview-${imageIndex}`"
                                        class="overflow-hidden rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900"
                                    >
                                        <img
                                            :src="imageUrl"
                                            :alt="section.image_alt || section.aside_image_alt || section.title || sectionPreviewHeading(section, index)"
                                            class="h-24 w-full object-cover"
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                </template>
                                <div
                                    v-else
                                    class="flex h-24 items-center justify-center rounded-sm border border-dashed border-stone-200 bg-stone-50 px-3 text-center text-xs font-medium uppercase tracking-wide text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400"
                                >
                                    {{ sectionLayoutLabel(section.layout) }}
                                </div>
                            </div>
                        </div>

                        <div v-if="isSectionEditorOpen(section.id)" class="space-y-4 border-t border-stone-200 pt-4 dark:border-neutral-700">

                        <div class="grid gap-3 md:grid-cols-2">
                            <FloatingSelect v-model="section.layout" :options="layoutOptions"
                                :label="$t('super_admin.pages.fields.layout')" />
                            <FloatingSelect v-if="['duo', 'testimonial', 'showcase_cta'].includes(section.layout)" v-model="section.image_position" :options="imagePositionOptions"
                                :label="$t('super_admin.pages.fields.image_position')" />
                            <FloatingSelect v-if="section.layout === 'showcase_cta'" v-model="section.showcase_divider_style" :options="showcaseDividerStyleOptions"
                                :label="$t('super_admin.pages.common.showcase_divider_style')" />
                            <FloatingSelect v-model="section.alignment" :options="alignmentOptions"
                                :label="$t('super_admin.pages.common.alignment')" />
                            <FloatingSelect v-model="section.density" :options="densityOptions"
                                :label="$t('super_admin.pages.common.density')" />
                            <FloatingSelect v-model="section.tone" :options="toneOptions"
                                :label="$t('super_admin.pages.common.tone')" />
                            <FloatingSelect v-model="section.background_preset" :options="backgroundPresetOptions"
                                :label="$t('super_admin.pages.common.background_preset')" />
                            <div class="grid gap-2 md:col-span-2 md:grid-cols-[140px_1fr] md:items-center">
                                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ backgroundHeadingLabel(section) }}
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <input v-model="section.background_color" type="color"
                                        class="h-10 w-14 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                                    <div class="min-w-[200px] flex-1">
                                        <FloatingInput v-model="section.background_color"
                                            :label="backgroundFieldLabel(section)" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-3 md:grid-cols-3">
                            <FloatingSelect
                                v-model="section.source_id"
                                :options="libraryOptions"
                                :label="$t('super_admin.pages.library.source')"
                                @update:modelValue="section.use_source = Boolean($event)"
                            />
                            <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                <Checkbox v-model:checked="section.use_source" />
                                <span>{{ $t('super_admin.pages.library.use_source') }}</span>
                            </label>
                            <button type="button"
                                class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                @click="applyLibraryToSection(section)">
                                {{ $t('super_admin.pages.library.copy') }}
                            </button>
                        </div>

                        <div class="grid gap-4 xl:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.9fr)]">
                            <div class="space-y-3">
                        <div class="grid gap-3 md:grid-cols-2">
                            <FloatingInput v-model="section.kicker" :label="$t('super_admin.pages.common.kicker')" />
                            <FloatingInput v-model="section.title" :label="$t('super_admin.pages.common.title')" />
                            <div class="md:col-span-2">
                                <RichTextEditor
                                    v-model="section.body"
                                    :label="$t('super_admin.pages.common.body')"
                                    :link-prompt="editorLinkPrompt"
                                    :image-prompt="editorImagePrompt"
                                    :ai-enabled="ai_enabled"
                                    :ai-generate-url="ai_image_generate_url"
                                    :ai-prompt="editorAiPrompt"
                                    :labels="editorLabels"
                                />
                            </div>
                        </div>

                        <div
                            v-if="isWelcomeHeroSection(section)"
                            class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3"
                        >
                            <div class="space-y-3">
                                <div>
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ $t('super_admin.sections.welcome.hero.typography_title') }}
                                    </h3>
                                    <p class="text-xs text-stone-500 dark:text-neutral-500">
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
                                                    <input
                                                        v-model="section.title_color"
                                                        type="color"
                                                        class="h-11 w-14 shrink-0 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900"
                                                    />
                                                    <input
                                                        v-model="section.title_color"
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
                                                    v-model="section.title_font_size"
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
                                                <input
                                                    v-model="section.body_color"
                                                    type="color"
                                                    class="h-11 w-14 shrink-0 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900"
                                                />
                                                <input
                                                    v-model="section.body_color"
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
                                :model-value="section.note"
                                :label="$t('super_admin.sections.welcome.hero.note')"
                                :link-prompt="editorLinkPrompt"
                                :image-prompt="editorImagePrompt"
                                :ai-enabled="ai_enabled"
                                :ai-generate-url="ai_image_generate_url"
                                :ai-prompt="editorAiPrompt"
                                :labels="editorLabels"
                                @update:modelValue="updateSectionNote(section, $event)"
                            />

                            <div class="space-y-3">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                            {{ $t('super_admin.sections.welcome.hero.stats_title') }}
                                        </h3>
                                        <p class="text-xs text-stone-500 dark:text-neutral-500">
                                            {{ $t('super_admin.sections.welcome.hero.stats_subtitle') }}
                                        </p>
                                    </div>
                                    <button type="button"
                                        class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                        @click="addSectionStat(section)">
                                        {{ $t('super_admin.sections.welcome.hero.add_stat') }}
                                    </button>
                                </div>

                                <div v-if="section.stats?.length" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                    <div v-for="(item, statIndex) in section.stats" :key="item.id || `page-hero-stat-${statIndex}`"
                                        class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                                        <div class="flex flex-wrap justify-end gap-2 text-xs">
                                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                @click="moveSectionStat(section, statIndex, -1)">
                                                {{ $t('super_admin.pages.common.move_up') }}
                                            </button>
                                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                @click="moveSectionStat(section, statIndex, 1)">
                                                {{ $t('super_admin.pages.common.move_down') }}
                                            </button>
                                            <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                                @click="removeSectionStat(section, statIndex)">
                                                {{ $t('super_admin.pages.common.remove') }}
                                            </button>
                                        </div>

                                        <div class="grid gap-3 md:grid-cols-2">
                                            <FloatingInput
                                                :model-value="item.value"
                                                :label="$t('super_admin.sections.welcome.hero.stat_value')"
                                                @update:modelValue="updateSectionStatField(section, item, 'value', $event)"
                                            />
                                            <FloatingInput
                                                :model-value="item.label"
                                                :label="$t('super_admin.sections.welcome.hero.stat_label')"
                                                @update:modelValue="updateSectionStatField(section, item, 'label', $event)"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                            {{ $t('super_admin.sections.welcome.hero.slides_title') }}
                                        </h3>
                                        <p class="text-xs text-stone-500 dark:text-neutral-500">
                                            {{ $t('super_admin.sections.welcome.hero.slides_subtitle') }}
                                        </p>
                                    </div>
                                    <button type="button"
                                        class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                        @click="addSectionHeroImage(section)">
                                        {{ $t('super_admin.sections.welcome.hero.add_slide') }}
                                    </button>
                                </div>

                                <div v-if="section.hero_images?.length" class="grid gap-3 xl:grid-cols-2">
                                    <div v-for="(item, slideIndex) in section.hero_images" :key="item.id || `page-hero-slide-${slideIndex}`"
                                        class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                                        <div class="flex flex-wrap justify-between gap-2 text-xs">
                                            <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                                {{ $t('super_admin.sections.welcome.hero.slide_label', { number: slideIndex + 1 }) }}
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                    @click="moveSectionHeroImage(section, slideIndex, -1)">
                                                    {{ $t('super_admin.pages.common.move_up') }}
                                                </button>
                                                <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                    @click="moveSectionHeroImage(section, slideIndex, 1)">
                                                    {{ $t('super_admin.pages.common.move_down') }}
                                                </button>
                                                <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                                    @click="removeSectionHeroImage(section, slideIndex)">
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
                                                    :alt="item.image_alt || `${$t('super_admin.sections.welcome.hero.slide_image_alt')} ${slideIndex + 1}`"
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

                        <div
                            v-if="section.layout === 'testimonial'"
                            class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3"
                        >
                            <div>
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.testimonial.title') }}
                                </h3>
                                <p class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ $t('super_admin.pages.testimonial.subtitle') }}
                                </p>
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                <FloatingInput v-model="section.testimonial_author" :label="$t('super_admin.pages.common.testimonial_author')" />
                                <FloatingInput v-model="section.testimonial_role" :label="$t('super_admin.pages.common.testimonial_role')" />
                            </div>
                        </div>

                        <div v-if="!['testimonial', 'industry_grid', 'story_grid', 'feature_tabs', 'testimonial_grid', 'showcase_cta'].includes(section.layout)">
                            <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.pages.fields.items') }}
                            </label>
                            <textarea :value="sectionItemsLines[section.id] ?? (section.items || []).join('\n')" rows="4"
                                class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                @input="updateSectionItems(section, $event.target.value)"></textarea>
                        </div>

                        <div
                            v-if="section.layout === 'industry_grid'"
                            class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ $t('super_admin.pages.industry_grid.cards_title') }}
                                    </h3>
                                    <p class="text-xs text-stone-500 dark:text-neutral-500">
                                        {{ $t('super_admin.pages.industry_grid.cards_subtitle') }}
                                    </p>
                                </div>
                                <button type="button"
                                    class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    @click="addIndustryCard(section)">
                                    {{ $t('super_admin.pages.industry_grid.add_card') }}
                                </button>
                            </div>

                            <div class="space-y-3">
                                <div v-for="(card, cardIndex) in section.industry_cards" :key="card.id"
                                    class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                        <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                            {{ $t('super_admin.pages.industry_grid.card_label', { number: cardIndex + 1 }) }}
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                @click="moveIndustryCard(section, cardIndex, -1)">
                                                {{ $t('super_admin.pages.common.move_up') }}
                                            </button>
                                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                @click="moveIndustryCard(section, cardIndex, 1)">
                                                {{ $t('super_admin.pages.common.move_down') }}
                                            </button>
                                            <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                                @click="removeIndustryCard(section, cardIndex)">
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

                        <div
                            v-if="section.layout === 'story_grid'"
                            class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ $t('super_admin.pages.story_grid.title') }}
                                    </h3>
                                    <p class="text-xs text-stone-500 dark:text-neutral-500">
                                        {{ $t('super_admin.pages.story_grid.subtitle') }}
                                    </p>
                                </div>
                                <button type="button"
                                    class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    @click="addStoryCard(section)">
                                    {{ $t('super_admin.pages.story_grid.add_card') }}
                                </button>
                            </div>

                            <div class="space-y-3">
                                <div v-for="(card, cardIndex) in section.story_cards" :key="card.id"
                                    class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                        <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                            {{ $t('super_admin.pages.story_grid.card_label', { number: cardIndex + 1 }) }}
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                @click="moveStoryCard(section, cardIndex, -1)">
                                                {{ $t('super_admin.pages.common.move_up') }}
                                            </button>
                                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                @click="moveStoryCard(section, cardIndex, 1)">
                                                {{ $t('super_admin.pages.common.move_down') }}
                                            </button>
                                            <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                                @click="removeStoryCard(section, cardIndex)">
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

                        <div
                            v-if="section.layout === 'testimonial_grid'"
                            class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ $t('super_admin.pages.testimonial_grid.title') }}
                                    </h3>
                                    <p class="text-xs text-stone-500 dark:text-neutral-500">
                                        {{ $t('super_admin.pages.testimonial_grid.subtitle') }}
                                    </p>
                                </div>
                                <button type="button"
                                    class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    @click="addTestimonialCard(section)">
                                    {{ $t('super_admin.pages.testimonial_grid.add_card') }}
                                </button>
                            </div>

                            <div class="space-y-3">
                                <div v-for="(card, cardIndex) in section.testimonial_cards" :key="card.id"
                                    class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                        <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                            {{ $t('super_admin.pages.testimonial_grid.card_label', { number: cardIndex + 1 }) }}
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                @click="moveTestimonialCard(section, cardIndex, -1)">
                                                {{ $t('super_admin.pages.common.move_up') }}
                                            </button>
                                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                @click="moveTestimonialCard(section, cardIndex, 1)">
                                                {{ $t('super_admin.pages.common.move_down') }}
                                            </button>
                                            <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                                @click="removeTestimonialCard(section, cardIndex)">
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
                                            <div v-if="card.image_url" class="overflow-hidden rounded-sm border border-stone-200 bg-white">
                                                <img :src="card.image_url" :alt="card.image_alt || card.author_name" class="h-28 w-28 rounded-full object-cover" loading="lazy" decoding="async" />
                                            </div>
                                        </div>
                                        <FloatingInput v-model="card.image_alt" :label="$t('super_admin.pages.common.testimonial_card_image_alt')" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="section.layout === 'feature_tabs'"
                            class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ $t('super_admin.pages.feature_tabs.title') }}
                                    </h3>
                                    <p class="text-xs text-stone-500 dark:text-neutral-500">
                                        {{ $t('super_admin.pages.feature_tabs.subtitle') }}
                                    </p>
                                </div>
                                <button type="button"
                                    class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    @click="addFeatureTab(section)">
                                    {{ $t('super_admin.pages.feature_tabs.add_tab') }}
                                </button>
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                <FloatingSelect
                                    v-model="section.feature_tabs_style"
                                    :options="featureTabStyleSelectOptions"
                                    :label="$t('super_admin.pages.feature_tabs.style_label')"
                                />
                                <FloatingNumberInput
                                    v-model="section.feature_tabs_font_size"
                                    :label="$t('super_admin.pages.feature_tabs.font_size_label')"
                                    :step="1"
                                />
                            </div>

                            <div class="space-y-3">
                                <div v-for="(tab, tabIndex) in section.feature_tabs" :key="tab.id"
                                    class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                        <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                            {{ $t('super_admin.pages.feature_tabs.tab_label', { number: tabIndex + 1 }) }}
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                @click="moveFeatureTab(section, tabIndex, -1)">
                                                {{ $t('super_admin.pages.common.move_up') }}
                                            </button>
                                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                                @click="moveFeatureTab(section, tabIndex, 1)">
                                                {{ $t('super_admin.pages.common.move_down') }}
                                            </button>
                                            <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                                @click="removeFeatureTab(section, tabIndex)">
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
                                                <h4 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                                    {{ $t('super_admin.pages.feature_tabs.children_title') }}
                                                </h4>
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

                        <div
                            v-if="section.layout === 'showcase_cta'"
                            class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3"
                        >
                            <div>
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.showcase_cta.title') }}
                                </h3>
                                <p class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ $t('super_admin.pages.showcase_cta.subtitle') }}
                                </p>
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                <div class="space-y-2">
                                    <FloatingInput v-model="section.image_url" :label="$t('super_admin.pages.common.image_url')" />
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <button v-if="asset_list_url" type="button"
                                            class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50"
                                            @click="openAssetPicker(section, 'image_url', 'image_alt')">
                                            {{ $t('super_admin.pages.assets.choose') }}
                                        </button>
                                        <span v-if="section.image_url" class="text-stone-500">
                                            {{ $t('super_admin.pages.assets.preview') }}
                                        </span>
                                    </div>
                                    <div v-if="section.image_url" class="overflow-hidden rounded-sm border border-stone-200 bg-white">
                                        <img :src="section.image_url" :alt="section.image_alt || section.title" class="h-36 w-full object-cover" loading="lazy" decoding="async" />
                                    </div>
                                </div>
                                <FloatingInput v-model="section.image_alt" :label="$t('super_admin.pages.common.image_alt')" />
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                <FloatingInput v-model="section.aside_link_label" :label="$t('super_admin.pages.common.showcase_overlay_label')" />
                                <FloatingInput v-model="section.aside_link_href" :label="$t('super_admin.pages.common.showcase_overlay_href')" />
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                <div class="space-y-2">
                                    <FloatingInput v-model="section.aside_image_url" :label="$t('super_admin.pages.common.showcase_floating_image_url')" />
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <button v-if="asset_list_url" type="button"
                                            class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50"
                                            @click="openAssetPicker(section, 'aside_image_url', 'aside_image_alt')">
                                            {{ $t('super_admin.pages.assets.choose') }}
                                        </button>
                                        <span v-if="section.aside_image_url" class="text-stone-500">
                                            {{ $t('super_admin.pages.assets.preview') }}
                                        </span>
                                    </div>
                                    <div v-if="section.aside_image_url" class="overflow-hidden rounded-sm border border-stone-200 bg-white">
                                        <img :src="section.aside_image_url" :alt="section.aside_image_alt || section.title" class="h-36 w-full object-cover" loading="lazy" decoding="async" />
                                    </div>
                                </div>
                                <FloatingInput v-model="section.aside_image_alt" :label="$t('super_admin.pages.common.showcase_floating_image_alt')" />
                            </div>
                        </div>

                        <div
                            v-if="['contact', 'feature_pairs'].includes(section.layout)"
                            class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3"
                        >
                            <div>
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{
                                        section.layout === 'feature_pairs'
                                            ? $t('super_admin.pages.feature_pairs.secondary_title')
                                            : $t('super_admin.pages.contact_aside.title')
                                    }}
                                </h3>
                                <p class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{
                                        section.layout === 'feature_pairs'
                                            ? $t('super_admin.pages.feature_pairs.secondary_subtitle')
                                            : $t('super_admin.pages.contact_aside.subtitle')
                                    }}
                                </p>
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                <FloatingInput v-model="section.aside_kicker" :label="$t('super_admin.pages.common.aside_kicker')" />
                                <FloatingInput v-model="section.aside_title" :label="$t('super_admin.pages.common.aside_title')" />
                                <div class="md:col-span-2">
                                    <RichTextEditor
                                        v-model="section.aside_body"
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

                            <div v-if="section.layout === 'contact'">
                                <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.common.aside_items') }}
                                </label>
                                <textarea
                                    :value="sectionAsideItemsLines[section.id] ?? (section.aside_items || []).join('\n')"
                                    rows="4"
                                    class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                    @input="updateSectionAsideItems(section, $event.target.value)"
                                ></textarea>
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                <FloatingInput v-model="section.aside_link_label" :label="$t('super_admin.pages.common.aside_link_label')" />
                                <FloatingInput v-model="section.aside_link_href" :label="$t('super_admin.pages.common.aside_link_href')" />
                            </div>

                            <div v-if="section.layout === 'feature_pairs'" class="grid gap-3 md:grid-cols-2">
                                <div class="space-y-2">
                                    <FloatingInput v-model="section.aside_image_url" :label="$t('super_admin.pages.common.aside_image_url')" />
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <button v-if="asset_list_url" type="button"
                                            class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50"
                                            @click="openAssetPicker(section, 'aside_image_url', 'aside_image_alt')">
                                            {{ $t('super_admin.pages.assets.choose') }}
                                        </button>
                                        <span v-if="section.aside_image_url" class="text-stone-500">
                                            {{ $t('super_admin.pages.assets.preview') }}
                                        </span>
                                    </div>
                                    <div v-if="section.aside_image_url" class="overflow-hidden rounded-sm border border-stone-200 bg-white">
                                        <img :src="section.aside_image_url" :alt="section.aside_image_alt || section.aside_title || section.title" class="h-36 w-full object-cover" loading="lazy" decoding="async" />
                                    </div>
                                </div>
                                <FloatingInput v-model="section.aside_image_alt" :label="$t('super_admin.pages.common.aside_image_alt')" />
                            </div>
                        </div>

                            </div>
                            <div class="space-y-3">
                        <div v-if="!['industry_grid', 'story_grid', 'feature_tabs', 'testimonial_grid', 'showcase_cta'].includes(section.layout)" class="grid gap-3 md:grid-cols-2">
                            <div class="space-y-2">
                                <FloatingInput v-model="section.image_url" :label="$t('super_admin.pages.common.image_url')" />
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <button v-if="asset_list_url" type="button"
                                        class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50"
                                        @click="openAssetPicker(section, 'image_url', 'image_alt')">
                                        {{ $t('super_admin.pages.assets.choose') }}
                                    </button>
                                    <span v-if="section.image_url" class="text-stone-500">
                                        {{ $t('super_admin.pages.assets.preview') }}
                                    </span>
                                </div>
                                <div v-if="section.image_url" class="overflow-hidden rounded-sm border border-stone-200 bg-white">
                                    <img :src="section.image_url" :alt="section.image_alt || section.title" class="h-36 w-full object-cover" loading="lazy" decoding="async" />
                                </div>
                            </div>
                            <FloatingInput v-model="section.image_alt" :label="$t('super_admin.pages.common.image_alt')" />
                        </div>

                        <div v-if="!['industry_grid', 'story_grid', 'feature_tabs', 'testimonial_grid', 'showcase_cta'].includes(section.layout)" class="grid gap-3 md:grid-cols-3">
                            <FloatingInput v-model="section.embed_url" :label="$t('super_admin.pages.common.embed_url')" />
                            <FloatingInput v-model="section.embed_title" :label="$t('super_admin.pages.common.embed_title')" />
                            <FloatingInput v-model="section.embed_height" type="number" :label="$t('super_admin.pages.common.embed_height')" />
                        </div>

                        <div class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3">
                            <div>
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.visibility.title') }}
                                </h3>
                                <p class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ $t('super_admin.pages.visibility.subtitle') }}
                                </p>
                            </div>
                            <div class="grid gap-3 md:grid-cols-3">
                                <FloatingSelect v-model="section.visibility.auth" :options="visibilityAuthOptions"
                                    :label="$t('super_admin.pages.visibility.auth_label')" />
                                <FloatingSelect v-model="section.visibility.device" :options="visibilityDeviceOptions"
                                    :label="$t('super_admin.pages.visibility.device_label')" />
                                <div class="space-y-2">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ $t('super_admin.pages.visibility.locales') }}
                                    </div>
                                    <div class="flex flex-wrap gap-3 text-sm text-stone-700 dark:text-neutral-200">
                                        <label v-for="locale in localeList" :key="locale" class="flex items-center gap-2">
                                            <Checkbox v-model:checked="section.visibility.locales" :value="locale" />
                                            <span>{{ locale.toUpperCase() }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="grid gap-3 md:grid-cols-2">
                                <div>
                                    <label class="block text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('super_admin.pages.visibility.roles') }}
                                    </label>
                                    <input
                                        :value="visibilityRoleLines[section.id] || ''"
                                        type="text"
                                        class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                        :placeholder="$t('super_admin.pages.visibility.roles_placeholder')"
                                        @input="updateVisibilityList(section, 'roles', $event.target.value)"
                                    />
                                </div>
                                <div>
                                    <label class="block text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('super_admin.pages.visibility.plans') }}
                                    </label>
                                    <input
                                        :value="visibilityPlanLines[section.id] || ''"
                                        type="text"
                                        class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                        :placeholder="$t('super_admin.pages.visibility.plans_placeholder')"
                                        @input="updateVisibilityList(section, 'plans', $event.target.value)"
                                    />
                                </div>
                            </div>
                            <div class="grid gap-3 md:grid-cols-2">
                                <div>
                                    <DateTimePicker
                                        v-model="section.visibility.start_at"
                                        :label="$t('super_admin.pages.visibility.start_at')"
                                    />
                                </div>
                                <div>
                                    <DateTimePicker
                                        v-model="section.visibility.end_at"
                                        :label="$t('super_admin.pages.visibility.end_at')"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-3 md:grid-cols-4">
                            <FloatingInput v-model="section.primary_label" :label="$t('super_admin.pages.common.primary_label')" />
                            <FloatingInput v-model="section.primary_href" :label="$t('super_admin.pages.common.primary_href')" />
                            <FloatingInput v-model="section.secondary_label" :label="$t('super_admin.pages.common.secondary_label')" />
                            <FloatingInput v-model="section.secondary_href" :label="$t('super_admin.pages.common.secondary_href')" />
                        </div>
                            </div>
                        </div>
                    </div>
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
