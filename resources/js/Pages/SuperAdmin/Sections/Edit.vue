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
    createFeatureTabChild,
    createFeatureTab,
    defaultFeatureTabsShowcaseSection,
    ensureFeatureTabs,
    featureTabIconOptions,
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

    if (type === 'feature_tabs') {
        const showcaseSection = defaultFeatureTabsShowcaseSection(currentLocale.value);

        return {
            ...showcaseSection,
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
        image_position: content?.image_position || preset.image_position,
        alignment: content?.alignment || preset.alignment,
        density: content?.density || preset.density,
        tone: content?.tone || preset.tone,
        kicker: content?.kicker || '',
        title: content?.title ?? preset.title ?? '',
        body: content?.body || '',
        industry_cards: Array.isArray(content?.industry_cards) ? ensureIndustryCards(content.industry_cards) : ensureIndustryCards(preset.industry_cards),
        feature_tabs: Array.isArray(content?.feature_tabs) ? ensureFeatureTabs(content.feature_tabs) : ensureFeatureTabs(preset.feature_tabs),
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
        copy: content?.copy ?? preset.copy ?? '',
        contact_phone: content?.contact_phone ?? preset.contact_phone ?? '',
        contact_email: content?.contact_email ?? preset.contact_email ?? '',
        social_facebook_href: content?.social_facebook_href ?? preset.social_facebook_href ?? '',
        social_x_href: content?.social_x_href ?? preset.social_x_href ?? '',
        social_instagram_href: content?.social_instagram_href ?? preset.social_instagram_href ?? '',
        social_youtube_href: content?.social_youtube_href ?? preset.social_youtube_href ?? '',
        social_linkedin_href: content?.social_linkedin_href ?? preset.social_linkedin_href ?? '',
        google_play_href: content?.google_play_href ?? preset.google_play_href ?? '',
        app_store_href: content?.app_store_href ?? preset.app_store_href ?? '',
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
            tone: current.tone === previous.tone ? next.tone : current.tone,
            alignment: current.alignment === previous.alignment ? next.alignment : current.alignment,
            kicker: current.kicker || next.kicker || '',
            title: current.title || next.title || '',
            body: current.body || next.body || '',
            items: current.items?.length ? current.items : (Array.isArray(next.items) ? [...next.items] : []),
            primary_label: current.primary_label || next.primary_label || '',
            primary_href: current.primary_href || next.primary_href || '',
            secondary_label: current.secondary_label || next.secondary_label || '',
            secondary_href: current.secondary_href || next.secondary_href || '',
            copy: current.copy || next.copy || '',
            contact_phone: current.contact_phone || next.contact_phone || '',
            contact_email: current.contact_email || next.contact_email || '',
            social_facebook_href: current.social_facebook_href || next.social_facebook_href || '',
            social_x_href: current.social_x_href || next.social_x_href || '',
            social_instagram_href: current.social_instagram_href || next.social_instagram_href || '',
            social_youtube_href: current.social_youtube_href || next.social_youtube_href || '',
            social_linkedin_href: current.social_linkedin_href || next.social_linkedin_href || '',
            google_play_href: current.google_play_href || next.google_play_href || '',
            app_store_href: current.app_store_href || next.app_store_href || '',
            industry_cards: current.industry_cards?.length ? current.industry_cards : ensureIndustryCards(next.industry_cards),
            feature_tabs: current.feature_tabs?.length ? current.feature_tabs : ensureFeatureTabs(next.feature_tabs),
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
const isIndustryGridType = computed(() => form.type === 'industry_grid');
const isFeatureTabsType = computed(() => form.type === 'feature_tabs');
const isTestimonialGridType = computed(() => form.type === 'testimonial_grid');
const isFooterType = computed(() => form.type === 'footer');
const usesEnhancedLayout = computed(() => isDuoType.value || isTestimonialType.value || isFeaturePairsType.value || isIndustryGridType.value || isFeatureTabsType.value || isTestimonialGridType.value || isFooterType.value);
const showImagePosition = computed(() => isDuoType.value || isTestimonialType.value);
const itemsFieldLabel = computed(() => (
    isFooterType.value
        ? t('super_admin.pages.footer.items_label')
        : t('super_admin.pages.fields.items')
));

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

const addIndustryCard = () => {
    form.content.industry_cards = [...(form.content.industry_cards || []), createIndustryCard()];
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
                    <FloatingInput v-model="form.content.kicker" :label="$t('super_admin.pages.common.kicker')" />
                    <FloatingInput v-model="form.content.title" :label="$t('super_admin.pages.common.title')" />
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
                    <FloatingSelect v-model="form.content.alignment" :options="alignmentOptions"
                        :label="$t('super_admin.pages.common.alignment')" />
                    <FloatingSelect v-model="form.content.density" :options="densityOptions"
                        :label="$t('super_admin.pages.common.density')" />
                    <FloatingSelect v-model="form.content.tone" :options="toneOptions"
                        :label="$t('super_admin.pages.common.tone')" />
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

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
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

                <div v-if="!isTestimonialType && !isIndustryGridType && !isFeatureTabsType && !isTestimonialGridType">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ itemsFieldLabel }}
                    </label>
                    <textarea v-model="itemsLines" rows="4"
                        class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"></textarea>
                </div>

                <div v-if="isFooterType" class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700">
                    <div class="mb-3">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.pages.footer.title') }}
                        </h2>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.pages.footer.subtitle') }}
                        </p>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <FloatingInput v-model="form.content.contact_phone" :label="$t('super_admin.pages.common.contact_phone')" />
                        <FloatingInput v-model="form.content.contact_email" :label="$t('super_admin.pages.common.contact_email')" />
                    </div>

                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        <FloatingInput v-model="form.content.social_facebook_href" :label="$t('super_admin.pages.common.social_facebook_href')" />
                        <FloatingInput v-model="form.content.social_x_href" :label="$t('super_admin.pages.common.social_x_href')" />
                        <FloatingInput v-model="form.content.social_instagram_href" :label="$t('super_admin.pages.common.social_instagram_href')" />
                        <FloatingInput v-model="form.content.social_youtube_href" :label="$t('super_admin.pages.common.social_youtube_href')" />
                        <FloatingInput v-model="form.content.social_linkedin_href" :label="$t('super_admin.pages.common.social_linkedin_href')" />
                    </div>

                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                        <FloatingInput v-model="form.content.google_play_href" :label="$t('super_admin.pages.common.google_play_href')" />
                        <FloatingInput v-model="form.content.app_store_href" :label="$t('super_admin.pages.common.app_store_href')" />
                    </div>

                    <div class="mt-3">
                        <FloatingInput v-model="form.content.copy" :label="$t('super_admin.pages.common.copy')" />
                    </div>
                </div>

                <div v-if="isTestimonialType" class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700">
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

                <div v-if="!isIndustryGridType && !isFeatureTabsType && !isTestimonialGridType && !isFooterType" class="grid gap-3 md:grid-cols-2">
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

                <div v-if="isFeatureTabsType" class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3">
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

                    <div class="max-w-xs">
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

                <div v-if="isTestimonialGridType" class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3">
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

                    <div class="space-y-3">
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

                <div v-if="isIndustryGridType" class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3">
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

                    <div class="space-y-3">
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

                <div v-if="isFeaturePairsType" class="rounded-sm border border-dashed border-stone-200 p-3 dark:border-neutral-700 space-y-3">
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

                <div class="grid gap-3 md:grid-cols-4">
                    <FloatingInput v-model="form.content.primary_label" :label="$t('super_admin.pages.common.primary_label')" />
                    <FloatingInput v-model="form.content.primary_href" :label="$t('super_admin.pages.common.primary_href')" />
                    <FloatingInput v-model="form.content.secondary_label" :label="$t('super_admin.pages.common.secondary_label')" />
                    <FloatingInput v-model="form.content.secondary_href" :label="$t('super_admin.pages.common.secondary_href')" />
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
