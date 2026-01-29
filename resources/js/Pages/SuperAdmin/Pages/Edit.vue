<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DateTimePicker from '@/Components/DateTimePicker.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import Checkbox from '@/Components/Checkbox.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import AssetPickerModal from '@/Components/AssetPickerModal.vue';

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
    font_body: 'work-sans',
    font_heading: 'space-grotesk',
    radius: 'sm',
    shadow: 'soft',
    button_style: 'solid',
};

const ensureTheme = (theme) => ({ ...themeDefaults, ...(theme || {}) });

const clone = (value) => JSON.parse(JSON.stringify(value ?? {}));
const currentLocale = ref(props.default_locale || props.locales[0] || 'fr');
const isCreateMode = computed(() => props.mode === 'create');

const form = useForm({
    slug: props.page.slug || '',
    title: props.page.title || '',
    is_active: props.page.is_active ?? true,
    locale: currentLocale.value,
    content: {},
    theme: ensureTheme(props.theme),
});

const sectionItemsLines = ref({});
const visibilityRoleLines = ref({});
const visibilityPlanLines = ref({});
const assetPickerOpen = ref(false);
const assetTarget = ref(null);

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
    { value: 'stack', label: t('super_admin.pages.layouts.stack') },
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

const fontOptions = computed(() => [
    { value: 'work-sans', label: t('super_admin.pages.theme.fonts.work_sans') },
    { value: 'space-grotesk', label: t('super_admin.pages.theme.fonts.space_grotesk') },
    { value: 'sora', label: t('super_admin.pages.theme.fonts.sora') },
    { value: 'dm-sans', label: t('super_admin.pages.theme.fonts.dm_sans') },
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

const linesToArray = (value) =>
    String(value || '')
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line.length > 0);

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
    background_color: section?.background_color ?? '',
    layout: section?.layout || 'split',
    alignment: section?.alignment || 'left',
    density: section?.density || 'normal',
    tone: section?.tone || 'default',
    visibility: ensureVisibility(section?.visibility),
    kicker: section?.kicker || '',
    title: section?.title || '',
    body: section?.body || '',
    items: Array.isArray(section?.items) ? section.items : [],
    image_url: section?.image_url || '',
    image_alt: section?.image_alt || '',
    primary_label: section?.primary_label || '',
    primary_href: section?.primary_href || '',
    secondary_label: section?.secondary_label || '',
    secondary_href: section?.secondary_href || '',
});

const ensureStructure = (content) => {
    const next = clone(content);
    next.page_title = next.page_title ?? props.page.title ?? '';
    next.page_subtitle = next.page_subtitle ?? '';
    next.sections = Array.isArray(next.sections) ? next.sections : [];
    next.sections = next.sections.map((section, index) => ensureSection(section, index));

    if (!next.sections.length) {
        next.sections.push(ensureSection({}, 0));
    }

    return next;
};

const rebuildItemsLines = () => {
    const map = {};
    (form.content.sections || []).forEach((section) => {
        map[section.id] = (section.items || []).join('\n');
    });
    sectionItemsLines.value = map;
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
    const incoming = props.content?.[locale] || {};
    form.locale = locale;
    form.content = ensureStructure(incoming);
    rebuildItemsLines();
};

watch(
    () => props.content,
    () => syncFormFromProps(currentLocale.value),
    { deep: true }
);

watch(
    () => props.theme,
    (theme) => {
        form.theme = ensureTheme(theme);
    },
    { deep: true }
);

watch(currentLocale, (locale) => syncFormFromProps(locale));

const updateSectionItems = (section, value) => {
    sectionItemsLines.value = { ...sectionItemsLines.value, [section.id]: value };
    section.items = linesToArray(value);
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

const openAssetPicker = (section) => {
    if (!section) return;
    assetTarget.value = section;
    assetPickerOpen.value = true;
};

const closeAssetPicker = () => {
    assetPickerOpen.value = false;
    assetTarget.value = null;
};

const handleAssetSelect = (asset) => {
    if (!assetTarget.value || !asset) {
        return;
    }
    assetTarget.value.image_url = asset.url || '';
    if (!assetTarget.value.image_alt) {
        assetTarget.value.image_alt = asset.alt || asset.name || '';
    }
    closeAssetPicker();
};

const findLibrarySection = (id) =>
    (props.library_sections || []).find((section) => String(section.id) === String(id));

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
    section.kicker = content.kicker ?? '';
    section.title = content.title ?? '';
    section.body = content.body ?? '';
    section.items = Array.isArray(content.items) ? content.items : [];
    section.image_url = content.image_url ?? '';
    section.image_alt = content.image_alt ?? '';
    section.primary_label = content.primary_label ?? '';
    section.primary_href = content.primary_href ?? '';
    section.secondary_label = content.secondary_label ?? '';
    section.secondary_href = content.secondary_href ?? '';
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
    selectedLibraryId.value = '';
};

const moveItem = (list, index, direction) => {
    const nextIndex = index + direction;
    if (nextIndex < 0 || nextIndex >= list.length) return;
    const cloneList = [...list];
    const [item] = cloneList.splice(index, 1);
    cloneList.splice(nextIndex, 0, item);
    list.splice(0, list.length, ...cloneList);
    rebuildItemsLines();
};

const addSection = () => {
    const nextIndex = (form.content.sections || []).length;
    form.content.sections.push(
        ensureSection(
            {
                id: `section-${Date.now()}`,
            },
            nextIndex
        )
    );
    rebuildItemsLines();
};

const removeSection = (index) => {
    const section = form.content.sections[index];
    form.content.sections.splice(index, 1);
    if (section?.id) {
        const next = { ...sectionItemsLines.value };
        delete next[section.id];
        sectionItemsLines.value = next;
    }
    if (!form.content.sections.length) {
        addSection();
    }
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

const submit = () => {
    if (props.mode === 'create') {
        form.post(route('superadmin.pages.store'), { preserveScroll: true });
        return;
    }

    if (!props.page?.id) return;
    form.put(route('superadmin.pages.update', props.page.id), { preserveScroll: true });
};

const applyTemplate = () => {
    const template = templates.value.find((item) => item.id === selectedTemplate.value);
    if (!template) return;
    form.content = ensureStructure(template.content);
    rebuildItemsLines();
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
                    <FloatingInput v-model="form.slug" :label="$t('super_admin.pages.fields.slug')" />
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

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid gap-3 md:grid-cols-2">
                    <FloatingSelect v-model="currentLocale" :options="localeOptions"
                        :label="$t('super_admin.pages.locale.label')" />
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
                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('super_admin.pages.locale.hint') }}
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.pages.sections.title') }}
                    </h2>
                    <button type="button"
                        class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        @click="addSection">
                        {{ $t('super_admin.pages.sections.add') }}
                    </button>
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
                    <div v-for="(section, index) in form.content.sections" :key="section.id || index"
                        class="rounded-sm border border-stone-200 p-4 dark:border-neutral-700 space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.pages.sections.section_label') }} #{{ index + 1 }}
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                    <Checkbox v-model:checked="section.enabled" />
                                    <span>{{ $t('super_admin.pages.common.enabled') }}</span>
                                </label>
                                <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                    @click="moveItem(form.content.sections, index, -1)">
                                    {{ $t('super_admin.pages.common.move_up') }}
                                </button>
                                <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                    @click="moveItem(form.content.sections, index, 1)">
                                    {{ $t('super_admin.pages.common.move_down') }}
                                </button>
                                <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                    @click="removeSection(index)">
                                    {{ $t('super_admin.pages.common.remove') }}
                                </button>
                            </div>
                        </div>

                        <div class="grid gap-3 md:grid-cols-2">
                            <FloatingSelect v-model="section.layout" :options="layoutOptions"
                                :label="$t('super_admin.pages.fields.layout')" />
                            <FloatingSelect v-model="section.alignment" :options="alignmentOptions"
                                :label="$t('super_admin.pages.common.alignment')" />
                            <FloatingSelect v-model="section.density" :options="densityOptions"
                                :label="$t('super_admin.pages.common.density')" />
                            <FloatingSelect v-model="section.tone" :options="toneOptions"
                                :label="$t('super_admin.pages.common.tone')" />
                            <div class="grid gap-2 md:col-span-2 md:grid-cols-[140px_1fr] md:items-center">
                                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.common.background') }}
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <input v-model="section.background_color" type="color"
                                        class="h-10 w-14 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                                    <div class="min-w-[200px] flex-1">
                                        <FloatingInput v-model="section.background_color"
                                            :label="$t('super_admin.pages.common.background_hex')" />
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

                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.pages.fields.items') }}
                            </label>
                            <textarea :value="sectionItemsLines[section.id] ?? (section.items || []).join('\n')" rows="4"
                                class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                @input="updateSectionItems(section, $event.target.value)"></textarea>
                        </div>

                        <div class="grid gap-3 md:grid-cols-2">
                            <div class="space-y-2">
                                <FloatingInput v-model="section.image_url" :label="$t('super_admin.pages.common.image_url')" />
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <button v-if="asset_list_url" type="button"
                                        class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50"
                                        @click="openAssetPicker(section)">
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
