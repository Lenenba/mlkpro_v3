<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import axios from 'axios';
import DropzoneInput from '@/Components/DropzoneInput.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import EmailBodyEditor from '@/Pages/Campaigns/Components/EmailBodyEditor.vue';

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({}),
    },
    presets: {
        type: Array,
        default: () => [],
    },
    blockLibrary: {
        type: Array,
        default: () => [],
    },
    supportedTokens: {
        type: Array,
        default: () => [],
    },
    language: {
        type: String,
        default: '',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    brandProfile: {
        type: Object,
        default: () => ({}),
    },
    tokenInsertRequest: {
        type: Object,
        default: () => null,
    },
});

const emit = defineEmits(['update:modelValue', 'focus-field']);

const clone = (value) => JSON.parse(JSON.stringify(value ?? {}));

const sectionDefinitions = [
    {
        key: 'header',
        label: 'Section 1',
        description: 'First full-width content section shown below the automatic brand header.',
    },
    {
        key: 'body',
        label: 'Section 2',
        description: 'Second full-width content section for your offer, arguments, services, or products.',
    },
    {
        key: 'footer',
        label: 'Section 3',
        description: 'Third full-width content section shown before the automatic company footer.',
    },
];

const modeOptions = [
    { value: 'builder', label: 'Simple builder' },
    { value: 'html', label: 'HTML' },
];

const columnOptions = [
    { value: 1, label: '1 column' },
    { value: 2, label: '2 columns' },
    { value: 3, label: '3 columns' },
];

const colorFields = [
    { key: 'primary_color', label: 'Primary' },
    { key: 'secondary_color', label: 'Secondary' },
    { key: 'accent_color', label: 'Accent' },
    { key: 'surface_color', label: 'Surface' },
];
const sectionBackgroundOptions = [
    { value: 'white', label: 'White' },
    { value: 'soft', label: 'Soft' },
    { value: 'highlight', label: 'Highlight' },
];
const sectionTextAlignOptions = [
    { value: 'left', label: 'Left' },
    { value: 'center', label: 'Center' },
];
const sectionSpacingOptions = [
    { value: 'compact', label: 'Compact' },
    { value: 'normal', label: 'Normal' },
    { value: 'spacious', label: 'Spacious' },
];
const sectionCtaStyleOptions = [
    { value: 'solid', label: 'Solid button' },
    { value: 'outline', label: 'Outline button' },
    { value: 'soft', label: 'Soft button' },
];

const createBlock = () => ({
    id: `block-${Math.random().toString(36).slice(2, 10)}`,
    kicker: '',
    title: '',
    body: '',
    image_url: '',
    button_label: '',
    button_url: '',
});

const clampColumnCount = (value) => {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) {
        return 1;
    }

    return Math.max(1, Math.min(3, Math.trunc(parsed)));
};

const normalizeSectionBackgroundMode = (value) => (
    ['white', 'soft', 'highlight'].includes(String(value || '').toLowerCase())
        ? String(value).toLowerCase()
        : 'white'
);

const normalizeSectionTextAlign = (value) => (
    ['left', 'center'].includes(String(value || '').toLowerCase())
        ? String(value).toLowerCase()
        : 'left'
);

const normalizeSectionSpacing = (value) => (
    ['compact', 'normal', 'spacious'].includes(String(value || '').toLowerCase())
        ? String(value).toLowerCase()
        : 'normal'
);

const normalizeSectionCtaStyle = (value) => (
    ['solid', 'outline', 'soft'].includes(String(value || '').toLowerCase())
        ? String(value).toLowerCase()
        : 'solid'
);

const fillColumns = (columns, count) => {
    const next = Array.isArray(columns)
        ? columns.slice(0, count).map((column) => ({
            ...createBlock(),
            ...clone(column),
        }))
        : [];

    while (next.length < count) {
        next.push(createBlock());
    }

    return next;
};

const createSection = (key, columnCount = 1) => ({
    key,
    enabled: true,
    background_mode: 'white',
    text_align: 'left',
    spacing_top: 'normal',
    spacing_bottom: 'normal',
    cta_style: 'solid',
    column_count: clampColumnCount(columnCount),
    columns: fillColumns([], clampColumnCount(columnCount)),
});

const createSchema = () => ({
    primary_color: '',
    secondary_color: '',
    accent_color: '',
    surface_color: '',
    hero_background_color: '',
    footer_background_color: '',
    text_color: '',
    muted_color: '',
    sections: sectionDefinitions.map((section) => createSection(section.key, 1)),
});

const extractTextFromHtml = (value) => String(value || '')
    .replace(/<\s*br\s*\/?\s*>/gi, '\n')
    .replace(/<\/p>/gi, '\n\n')
    .replace(/<[^>]*>/g, ' ')
    .replace(/\n{3,}/g, '\n\n')
    .replace(/[ \t]+\n/g, '\n')
    .trim();

const isLegacySectionList = (sections) => Array.isArray(sections) && sections.some((section) => {
    if (!section || typeof section !== 'object') {
        return false;
    }

    return 'type' in section || 'placement' in section || !('key' in section);
});

const mergeBlocks = (current, incoming) => ({
    ...current,
    kicker: current.kicker || incoming.kicker,
    title: current.title || incoming.title,
    body: [current.body, incoming.title, incoming.body].filter(Boolean).join('\n\n'),
    image_url: current.image_url || incoming.image_url,
    button_label: current.button_label || incoming.button_label,
    button_url: current.button_url || incoming.button_url,
});

const legacySectionToBlock = (section) => {
    const type = String(section?.type || 'rich_text').toLowerCase();
    const itemLines = Array.isArray(section?.items)
        ? section.items
            .map((item) => {
                const first = item?.value || item?.title || '';
                const second = item?.label || item?.text || item?.price || '';
                return [first, second].filter(Boolean).join(' - ');
            })
            .filter(Boolean)
        : [];

    const body = [
        section?.text || '',
        section?.note || '',
        type === 'rich_text' ? extractTextFromHtml(section?.html || '') : '',
        section?.price_label || '',
        section?.discount_label || '',
        section?.deadline || '',
        section?.date_label || '',
        section?.time_label || '',
        section?.location_label || '',
        ...(type === 'social_proof' && section?.quote ? [`"${section.quote}"`, [section?.quote_author, section?.quote_role].filter(Boolean).join(' - ')] : []),
        ...itemLines,
    ].filter(Boolean).join('\n\n');

    return {
        id: String(section?.id || `legacy-${Math.random().toString(36).slice(2, 10)}`),
        kicker: String(section?.eyebrow || section?.badge || ''),
        title: String(section?.title || (type === 'social_proof' ? 'Testimonial' : '')),
        body,
        image_url: String(section?.image_url || section?.items?.[0]?.image_url || ''),
        button_label: String(section?.button_label || section?.items?.[0]?.button_label || ''),
        button_url: String(section?.button_url || section?.items?.[0]?.button_url || ''),
    };
};

const legacySectionTarget = (section) => {
    const type = String(section?.type || '').toLowerCase();
    const placement = String(section?.placement || 'content').toLowerCase();

    if (['cta_banner', 'social_proof'].includes(type)) {
        return 'footer';
    }

    if (placement === 'hero' || ['hero', 'metrics'].includes(type)) {
        return 'header';
    }

    return 'body';
};

const normalizeLegacySections = (sections) => {
    const grouped = {
        header: [],
        body: [],
        footer: [],
    };

    sections.forEach((section) => {
        const block = legacySectionToBlock(section);
        const target = legacySectionTarget(section);
        const hasContent = [block.kicker, block.title, block.body, block.image_url, block.button_label, block.button_url].some(Boolean);

        if (!hasContent) {
            return;
        }

        if (grouped[target].length < 3) {
            grouped[target].push(block);
            return;
        }

        grouped[target][grouped[target].length - 1] = mergeBlocks(grouped[target][grouped[target].length - 1], block);
    });

    return sectionDefinitions.map((section) => {
        const columns = grouped[section.key];
        const count = Math.max(1, Math.min(3, columns.length || 1));

        return {
            key: section.key,
            enabled: true,
            background_mode: 'white',
            text_align: 'left',
            spacing_top: 'normal',
            spacing_bottom: 'normal',
            cta_style: 'solid',
            column_count: count,
            columns: fillColumns(columns, count),
        };
    });
};

const normalizeSchema = (schemaValue = {}) => {
    const source = clone(schemaValue);
    const next = {
        ...createSchema(),
        primary_color: String(source.primary_color || ''),
        secondary_color: String(source.secondary_color || ''),
        accent_color: String(source.accent_color || ''),
        surface_color: String(source.surface_color || ''),
        hero_background_color: String(source.hero_background_color || ''),
        footer_background_color: String(source.footer_background_color || ''),
        text_color: String(source.text_color || ''),
        muted_color: String(source.muted_color || ''),
    };

    const rawSections = Array.isArray(source.sections) ? source.sections : [];
    next.sections = isLegacySectionList(rawSections)
        ? normalizeLegacySections(rawSections)
        : sectionDefinitions.map((definition, index) => {
            const section = rawSections.find((entry) => String(entry?.key || '') === definition.key) || rawSections[index] || {};
            const count = clampColumnCount(section?.column_count ?? section?.columns_count ?? section?.columns?.length ?? 1);

            return {
                key: definition.key,
                enabled: section?.enabled !== false,
                background_mode: normalizeSectionBackgroundMode(section?.background_mode),
                text_align: normalizeSectionTextAlign(section?.text_align),
                spacing_top: normalizeSectionSpacing(section?.spacing_top),
                spacing_bottom: normalizeSectionSpacing(section?.spacing_bottom),
                cta_style: normalizeSectionCtaStyle(section?.cta_style),
                column_count: count,
                columns: fillColumns(section?.columns, count),
            };
        });

    return next;
};

const normalize = (value = {}) => {
    const source = clone(value);

    return {
        subject: String(source.subject || ''),
        previewText: String(source.previewText || source.preview_text || ''),
        editorMode: ['builder', 'html'].includes(String(source.editorMode || source.editor_mode || '').toLowerCase())
            ? String(source.editorMode || source.editor_mode).toLowerCase()
            : (Array.isArray(source?.schema?.sections) ? 'builder' : 'html'),
        templateKey: String(source.templateKey || source.template_key || ''),
        html: String(source.html || source.body || ''),
        schema: normalizeSchema(source.schema || {}),
    };
};

const state = ref(normalize(props.modelValue));
const uploadState = ref({});
const activeField = ref(null);

watch(() => props.modelValue, (next) => {
    state.value = normalize(next);
    uploadState.value = {};
}, { deep: true });

watch(state, (next) => {
    emit('update:modelValue', normalize(next));
}, { deep: true });

watch(() => props.tokenInsertRequest?.nonce, async () => {
    const token = String(props.tokenInsertRequest?.token || '').trim();
    if (token === '' || !activeField.value) {
        return;
    }

    await insertTokenIntoActiveField(token);
});

const presetOptions = computed(() => props.presets
    .filter((preset) => String(preset?.channel || '').toUpperCase() === 'EMAIL')
    .filter((preset) => {
        const currentLanguage = String(props.language || '').trim().toUpperCase();
        const presetLanguage = String(preset?.language || '').trim().toUpperCase();

        return currentLanguage === '' || presetLanguage === '' || presetLanguage === currentLanguage;
    })
    .map((preset) => ({
        value: String(preset.key || ''),
        label: `${preset.name}${preset.description ? ` - ${preset.description}` : ''}`,
    })));

const getSection = (key) => state.value.schema.sections.find((section) => section.key === key) || createSection(key, 1);

const inputId = (sectionKey, columnId, field) => `email-builder-${sectionKey}-${columnId}-${field}`;

const setSectionEnabled = (section, enabled) => {
    section.enabled = Boolean(enabled);
};

const updateColumnCount = (section, value) => {
    const count = clampColumnCount(value);
    section.column_count = count;
    section.columns = fillColumns(section.columns, count);
};

const setActiveBuilderField = (sectionDefinition, column, columnIndex, field, label) => {
    activeField.value = {
        sectionKey: sectionDefinition.key,
        columnId: column.id,
        field,
        id: inputId(sectionDefinition.key, column.id, field),
    };

    emit('focus-field', {
        scope: 'builder',
        label: `${sectionDefinition.label} - Column ${columnIndex + 1} - ${label}`,
    });
};

const insertTokenIntoActiveField = async (token) => {
    if (!activeField.value) {
        return;
    }

    const section = state.value.schema.sections.find((entry) => entry.key === activeField.value.sectionKey);
    const column = section?.columns?.find((entry) => entry.id === activeField.value.columnId);
    if (!column) {
        return;
    }

    const wrappedToken = `{${token}}`;
    const currentValue = String(column?.[activeField.value.field] || '');
    const element = typeof document !== 'undefined'
        ? document.getElementById(activeField.value.id)
        : null;

    let nextValue = `${currentValue}${wrappedToken}`;
    let nextCursor = nextValue.length;

    if (element && typeof element.selectionStart === 'number' && typeof element.selectionEnd === 'number') {
        const start = element.selectionStart;
        const end = element.selectionEnd;
        nextValue = `${currentValue.slice(0, start)}${wrappedToken}${currentValue.slice(end)}`;
        nextCursor = start + wrappedToken.length;
    }

    column[activeField.value.field] = nextValue;

    await nextTick();

    if (element && typeof element.focus === 'function') {
        element.focus();
        if (typeof element.setSelectionRange === 'function') {
            element.setSelectionRange(nextCursor, nextCursor);
        }
    }
};

const getUploadMeta = (columnId) => uploadState.value[columnId] || { busy: false, error: '', version: 0 };

const setUploadMeta = (columnId, patch) => {
    uploadState.value = {
        ...uploadState.value,
        [columnId]: {
            ...getUploadMeta(columnId),
            ...patch,
        },
    };
};

const clearUploadMeta = (columnId) => {
    const next = { ...uploadState.value };
    delete next[columnId];
    uploadState.value = next;
};

const updateColumnImage = async (column, value) => {
    if (value === null || value === '') {
        column.image_url = '';
        clearUploadMeta(column.id);
        return;
    }

    if (typeof value === 'string') {
        column.image_url = value;
        clearUploadMeta(column.id);
        return;
    }

    if (!(value instanceof File)) {
        return;
    }

    setUploadMeta(column.id, { busy: true, error: '' });

    try {
        const payload = new FormData();
        payload.append('image', value);

        const response = await axios.post(route('marketing.templates.upload-image'), payload, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        const uploadedUrl = String(response.data?.url || '').trim();
        if (uploadedUrl === '') {
            throw new Error('Upload response did not include an image URL.');
        }

        column.image_url = uploadedUrl;
        clearUploadMeta(column.id);
    } catch (requestError) {
        setUploadMeta(column.id, {
            busy: false,
            error: requestError?.response?.data?.message || requestError?.message || 'Unable to upload image.',
            version: getUploadMeta(column.id).version + 1,
        });
    }
};

const usePreset = (key) => {
    const preset = props.presets.find((entry) => String(entry?.key || '') === String(key || ''));
    if (!preset?.content) {
        return;
    }

    state.value = normalize(preset.content);
};

const applyBrandColorPreset = () => {
    state.value.schema.primary_color = props.brandProfile?.primary_color || '#0F766E';
    state.value.schema.secondary_color = props.brandProfile?.secondary_color || '#0F172A';
    state.value.schema.accent_color = props.brandProfile?.accent_color || '#F59E0B';
    state.value.schema.surface_color = props.brandProfile?.surface_color || '#F8FAFC';
    state.value.schema.hero_background_color = props.brandProfile?.hero_background_color || '#ECFEFF';
    state.value.schema.footer_background_color = props.brandProfile?.footer_background_color || '#0F172A';
    state.value.schema.text_color = props.brandProfile?.text_color || '#0F172A';
    state.value.schema.muted_color = props.brandProfile?.muted_color || '#475569';
};
</script>

<template>
    <div class="space-y-4">
        <div class="grid grid-cols-1 gap-3 rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800 xl:grid-cols-[1fr_1fr_auto]">
            <FloatingSelect
                v-model="state.editorMode"
                label="Email editor mode"
                :options="modeOptions"
                option-value="value"
                option-label="label"
            />
            <FloatingSelect
                :model-value="state.templateKey"
                label="Start from preset"
                :options="[{ value: '', label: 'Choose a preset' }, ...presetOptions]"
                option-value="value"
                option-label="label"
                @update:modelValue="usePreset"
            />
            <div class="flex items-end">
                <SecondaryButton type="button" :disabled="disabled" @click="applyBrandColorPreset">
                    Sync company colors
                </SecondaryButton>
            </div>
        </div>

        <div v-if="state.editorMode === 'html'" class="space-y-3">
            <EmailBodyEditor v-model="state.html" label="HTML email body" />
        </div>

        <div v-else class="space-y-4">
            <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Simple builder</p>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            Fixed structure with 3 full-width content sections. Each section can use 1 to 3 columns, and each column contains one simple block.
                        </p>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                    <label v-for="field in colorFields" :key="field.key" class="space-y-1 text-xs text-stone-600 dark:text-neutral-300">
                        <span>{{ field.label }}</span>
                        <input v-model="state.schema[field.key]" type="color" class="h-10 w-full rounded border border-stone-300 bg-white dark:border-neutral-600 dark:bg-neutral-900">
                    </label>
                </div>
            </div>

            <div
                v-for="sectionDefinition in sectionDefinitions"
                :key="sectionDefinition.key"
                class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="max-w-2xl">
                        <p class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ sectionDefinition.label }}</p>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ sectionDefinition.description }}</p>
                    </div>
                    <div class="flex w-full flex-wrap items-end justify-end gap-2 sm:w-auto">
                        <button
                            v-if="getSection(sectionDefinition.key).enabled"
                            type="button"
                            class="inline-flex items-center rounded-sm border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-50 dark:border-rose-800 dark:bg-neutral-900 dark:text-rose-300 dark:hover:bg-rose-950/40"
                            @click="setSectionEnabled(getSection(sectionDefinition.key), false)"
                        >
                            Remove section
                        </button>
                        <button
                            v-else
                            type="button"
                            class="inline-flex items-center rounded-sm border border-stone-300 bg-white px-3 py-2 text-xs font-semibold text-stone-700 transition hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            @click="setSectionEnabled(getSection(sectionDefinition.key), true)"
                        >
                            Restore section
                        </button>
                    </div>
                    <div v-if="getSection(sectionDefinition.key).enabled" class="w-full sm:w-40">
                        <FloatingSelect
                            :model-value="getSection(sectionDefinition.key).column_count"
                            label="Columns"
                            :options="columnOptions"
                            option-value="value"
                            option-label="label"
                            @update:modelValue="(value) => updateColumnCount(getSection(sectionDefinition.key), value)"
                        />
                    </div>
                </div>

                <div
                    v-if="!getSection(sectionDefinition.key).enabled"
                    class="mt-4 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-5 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400"
                >
                    This section is removed from the email until you restore it.
                </div>

                <div v-else class="mt-4 space-y-4">
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5">
                        <FloatingSelect
                            :model-value="getSection(sectionDefinition.key).background_mode"
                            label="Background"
                            :options="sectionBackgroundOptions"
                            option-value="value"
                            option-label="label"
                            @update:modelValue="(value) => { getSection(sectionDefinition.key).background_mode = normalizeSectionBackgroundMode(value); }"
                        />
                        <FloatingSelect
                            :model-value="getSection(sectionDefinition.key).text_align"
                            label="Text align"
                            :options="sectionTextAlignOptions"
                            option-value="value"
                            option-label="label"
                            @update:modelValue="(value) => { getSection(sectionDefinition.key).text_align = normalizeSectionTextAlign(value); }"
                        />
                        <FloatingSelect
                            :model-value="getSection(sectionDefinition.key).spacing_top"
                            label="Top space"
                            :options="sectionSpacingOptions"
                            option-value="value"
                            option-label="label"
                            @update:modelValue="(value) => { getSection(sectionDefinition.key).spacing_top = normalizeSectionSpacing(value); }"
                        />
                        <FloatingSelect
                            :model-value="getSection(sectionDefinition.key).spacing_bottom"
                            label="Bottom space"
                            :options="sectionSpacingOptions"
                            option-value="value"
                            option-label="label"
                            @update:modelValue="(value) => { getSection(sectionDefinition.key).spacing_bottom = normalizeSectionSpacing(value); }"
                        />
                        <FloatingSelect
                            :model-value="getSection(sectionDefinition.key).cta_style"
                            label="CTA style"
                            :options="sectionCtaStyleOptions"
                            option-value="value"
                            option-label="label"
                            @update:modelValue="(value) => { getSection(sectionDefinition.key).cta_style = normalizeSectionCtaStyle(value); }"
                        />
                    </div>

                    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                    <div
                        v-for="(column, columnIndex) in getSection(sectionDefinition.key).columns"
                        :key="column.id"
                        class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800"
                    >
                        <p class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            Column {{ columnIndex + 1 }}
                        </p>

                        <div class="mt-3 grid grid-cols-1 gap-3">
                            <FloatingInput
                                :id="inputId(sectionDefinition.key, column.id, 'kicker')"
                                v-model="column.kicker"
                                label="Kicker"
                                @focusin="setActiveBuilderField(sectionDefinition, column, columnIndex, 'kicker', 'Kicker')"
                            />
                            <FloatingInput
                                :id="inputId(sectionDefinition.key, column.id, 'title')"
                                v-model="column.title"
                                label="Title"
                                @focusin="setActiveBuilderField(sectionDefinition, column, columnIndex, 'title', 'Title')"
                            />
                            <FloatingTextarea
                                :id="inputId(sectionDefinition.key, column.id, 'body')"
                                v-model="column.body"
                                label="Body"
                                @focusin="setActiveBuilderField(sectionDefinition, column, columnIndex, 'body', 'Body')"
                            />
                            <div class="space-y-2">
                                <DropzoneInput
                                    :key="`${column.id}-${getUploadMeta(column.id).version}`"
                                    :model-value="column.image_url"
                                    label="Block image"
                                    @update:modelValue="(value) => updateColumnImage(column, value)"
                                />
                                <p v-if="getUploadMeta(column.id).busy" class="text-xs text-stone-500 dark:text-neutral-400">
                                    Uploading image...
                                </p>
                                <p v-else-if="getUploadMeta(column.id).error" class="text-xs text-rose-600">
                                    {{ getUploadMeta(column.id).error }}
                                </p>
                                <p v-else class="text-xs text-stone-500 dark:text-neutral-400">
                                    Upload, replace, or remove the image here. You can also paste a direct image URL below.
                                </p>
                            </div>
                            <FloatingInput
                                :id="inputId(sectionDefinition.key, column.id, 'image_url')"
                                :model-value="column.image_url"
                                label="Image URL"
                                @focusin="setActiveBuilderField(sectionDefinition, column, columnIndex, 'image_url', 'Image URL')"
                                @update:modelValue="(value) => updateColumnImage(column, value)"
                            />
                            <FloatingInput
                                :id="inputId(sectionDefinition.key, column.id, 'button_label')"
                                v-model="column.button_label"
                                label="CTA label"
                                @focusin="setActiveBuilderField(sectionDefinition, column, columnIndex, 'button_label', 'CTA label')"
                            />
                            <FloatingInput
                                :id="inputId(sectionDefinition.key, column.id, 'button_url')"
                                v-model="column.button_url"
                                label="CTA URL"
                                @focusin="setActiveBuilderField(sectionDefinition, column, columnIndex, 'button_url', 'CTA URL')"
                            />
                        </div>
                    </div>
                </div>
                </div>
            </div>

        </div>
    </div>
</template>
