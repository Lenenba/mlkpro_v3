<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import AssetPickerModal from '@/Components/AssetPickerModal.vue';

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

const typeOptions = computed(() =>
    (props.types || []).map((type) => ({
        value: type,
        label: t(`super_admin.sections.types.${type}`),
    }))
);

const localeOptions = computed(() =>
    (props.locales || []).map((locale) => ({ value: locale, label: locale.toUpperCase() }))
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

const ensureStructure = (content) => ({
    kicker: content?.kicker || '',
    title: content?.title || '',
    body: content?.body || '',
    items: Array.isArray(content?.items) ? content.items : [],
    image_url: content?.image_url || '',
    image_alt: content?.image_alt || '',
    primary_label: content?.primary_label || '',
    primary_href: content?.primary_href || '',
    secondary_label: content?.secondary_label || '',
    secondary_href: content?.secondary_href || '',
});

const syncLinesFromContent = () => {
    itemsLines.value = (form.content.items || []).join('\n');
};

const syncFormFromProps = (locale = currentLocale.value) => {
    const incoming = props.content?.[locale] || {};
    form.locale = locale;
    form.content = ensureStructure(incoming);
    syncLinesFromContent();
};

watch(
    () => props.content,
    () => syncFormFromProps(currentLocale.value),
    { deep: true }
);

watch(currentLocale, (locale) => syncFormFromProps(locale));
watch(itemsLines, (value) => {
    form.content.items = String(value || '')
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line.length > 0);
});

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

const openAssetPicker = () => {
    if (!props.asset_list_url) return;
    assetPickerOpen.value = true;
};

const closeAssetPicker = () => {
    assetPickerOpen.value = false;
};

const handleAssetSelect = (asset) => {
    if (!asset) {
        return;
    }
    form.content.image_url = asset.url || '';
    if (!form.content.image_alt) {
        form.content.image_alt = asset.alt || asset.name || '';
    }
    closeAssetPicker();
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

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.pages.fields.items') }}
                    </label>
                    <textarea v-model="itemsLines" rows="4"
                        class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"></textarea>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <div class="space-y-2">
                        <FloatingInput v-model="form.content.image_url" :label="$t('super_admin.pages.common.image_url')" />
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <button v-if="asset_list_url" type="button"
                                class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50"
                                @click="openAssetPicker">
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
