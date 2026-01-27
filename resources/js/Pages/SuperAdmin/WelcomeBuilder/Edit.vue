<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';

const props = defineProps({
    locales: { type: Array, default: () => ['fr', 'en'] },
    default_locale: { type: String, default: 'fr' },
    content: { type: Object, default: () => ({}) },
    meta: { type: Object, default: () => ({ updated_at: null, updated_by: null }) },
    preview_url: { type: String, default: '/' },
    ai_enabled: { type: Boolean, default: false },
    ai_image_generate_url: { type: String, default: '' },
});

const { t } = useI18n();

const clone = (value) => JSON.parse(JSON.stringify(value ?? {}));
const currentLocale = ref(props.default_locale || props.locales[0] || 'fr');

const form = useForm({
    locale: currentLocale.value,
    content: {},
    hero_image: null,
    hero_image_remove: false,
    workflow_image: null,
    workflow_image_remove: false,
    field_image: null,
    field_image_remove: false,
});

const heroHighlightsLines = ref('');
const trustItemsLines = ref('');
const fieldItemsLines = ref('');

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

const buttonTone = (style) => {
    if (style === 'solid') {
        return 'bg-green-600 text-white border-transparent hover:bg-green-700';
    }
    if (style === 'ghost') {
        return 'bg-transparent text-stone-700 border-transparent hover:bg-stone-100 dark:text-neutral-200 dark:hover:bg-neutral-800';
    }
    return 'bg-white text-stone-800 border-stone-200 hover:bg-stone-50 dark:bg-neutral-900 dark:text-neutral-200 dark:border-neutral-700 dark:hover:bg-neutral-800';
};

const ensureStructure = (content) => {
    const next = clone(content);
    next.nav ||= { tagline: '', menu: [] };
    next.nav.menu ||= [];
    next.hero ||= {};
    next.hero.enabled = next.hero.enabled ?? true;
    next.hero.background_color = next.hero.background_color ?? '';
    next.hero.stats ||= [];
    next.hero.highlights ||= [];
    next.hero.preview_cards ||= [];
    next.trust ||= { enabled: true, title: '', items: [] };
    next.trust.background_color = next.trust.background_color ?? '';
    next.trust.items ||= [];
    next.features ||= { enabled: true, kicker: '', title: '', subtitle: '', items: [], new_features: {} };
    next.features.background_color = next.features.background_color ?? '';
    next.features.items ||= [];
    next.features.new_features ||= { enabled: true, kicker: '', title: '', subtitle: '', badge: '', items: [] };
    next.features.new_features.background_color = next.features.new_features.background_color ?? '';
    next.features.new_features.items ||= [];
    next.workflow ||= { enabled: true, kicker: '', title: '', subtitle: '', steps: [] };
    next.workflow.background_color = next.workflow.background_color ?? '';
    next.workflow.steps ||= [];
    next.field ||= { enabled: true, kicker: '', title: '', subtitle: '', items: [] };
    next.field.background_color = next.field.background_color ?? '';
    next.field.items ||= [];
    next.cta ||= { enabled: true, title: '', subtitle: '' };
    next.cta.background_color = next.cta.background_color ?? '';
    next.custom_sections ||= [];
    next.custom_sections = next.custom_sections.map((section) => ({
        background_color: '',
        ...section,
        background_color: section?.background_color ?? '',
    }));
    next.footer ||= { terms_label: '', terms_href: '', copy: '' };
    return next;
};

const linesToArray = (value) =>
    String(value || '')
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line.length > 0);

const syncLinesFromContent = () => {
    heroHighlightsLines.value = (form.content.hero?.highlights || []).join('\n');
    trustItemsLines.value = (form.content.trust?.items || []).join('\n');
    fieldItemsLines.value = (form.content.field?.items || []).join('\n');
};

const syncFormFromProps = (locale = currentLocale.value) => {
    const incoming = props.content?.[locale] || {};
    form.locale = locale;
    form.content = ensureStructure(incoming);
    form.hero_image = null;
    form.hero_image_remove = false;
    form.workflow_image = null;
    form.workflow_image_remove = false;
    form.field_image = null;
    form.field_image_remove = false;
    syncLinesFromContent();
};

watch(
    () => props.content,
    () => syncFormFromProps(currentLocale.value),
    { deep: true }
);

watch(currentLocale, (locale) => syncFormFromProps(locale));
watch(heroHighlightsLines, (value) => { form.content.hero.highlights = linesToArray(value); });
watch(trustItemsLines, (value) => { form.content.trust.items = linesToArray(value); });
watch(fieldItemsLines, (value) => { form.content.field.items = linesToArray(value); });

const moveItem = (list, index, direction) => {
    const nextIndex = index + direction;
    if (nextIndex < 0 || nextIndex >= list.length) return;
    const cloneList = [...list];
    const [item] = cloneList.splice(index, 1);
    cloneList.splice(nextIndex, 0, item);
    list.splice(0, list.length, ...cloneList);
};

const addMenuItem = () => {
    form.content.nav.menu.push({
        id: `menu-${Date.now()}`,
        label: '',
        href: '',
        style: 'outline',
        enabled: true,
    });
};

const removeMenuItem = (index) => {
    form.content.nav.menu.splice(index, 1);
};

const addCustomSection = () => {
    form.content.custom_sections.push({
        id: `section-${Date.now()}`,
        enabled: true,
        background_color: '',
        kicker: '',
        title: '',
        body: '',
        image_url: '',
        image_alt: '',
        primary_label: '',
        primary_href: '',
        secondary_label: '',
        secondary_href: '',
    });
};

const removeCustomSection = (index) => {
    form.content.custom_sections.splice(index, 1);
};

const handleImageFile = (key, event) => {
    const file = event.target.files?.[0] || null;
    form[key] = file;
    const removeKey = `${key}_remove`;
    if (file && removeKey in form) form[removeKey] = false;
};

const markImageRemoved = (key) => {
    form[key] = null;
    const removeKey = `${key}_remove`;
    if (removeKey in form) form[removeKey] = true;
};

const submit = () => {
    form.put(route('superadmin.welcome.update'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => syncFormFromProps(currentLocale.value),
    });
};

const updatedAtLabel = computed(() => {
    if (!props.meta?.updated_at) return t('super_admin.welcome_builder.meta.never');
    const date = new Date(props.meta.updated_at);
    if (Number.isNaN(date.getTime())) return props.meta.updated_at;
    return date.toLocaleString();
});

const updatedByLabel = computed(() => props.meta?.updated_by?.name || props.meta?.updated_by?.email || '');

syncFormFromProps(currentLocale.value);
</script>

<template>
    <Head :title="$t('super_admin.welcome_builder.title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.welcome_builder.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('super_admin.welcome_builder.subtitle') }}
                        </p>
                        <div class="text-xs text-stone-500 dark:text-neutral-500">
                            {{ $t('super_admin.welcome_builder.meta.updated_at') }}: {{ updatedAtLabel }}
                            <span v-if="updatedByLabel">- {{ updatedByLabel }}</span>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Link :href="preview_url"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ $t('super_admin.welcome_builder.actions.back_dashboard') }}
                        </Link>
                        <button type="button" @click="submit"
                            class="rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                            :disabled="form.processing">
                            {{ $t('super_admin.welcome_builder.actions.save') }}
                        </button>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid gap-3 md:grid-cols-3">
                    <div class="md:col-span-1">
                        <FloatingSelect
                            v-model="currentLocale"
                            :label="$t('super_admin.welcome_builder.locale.label')"
                            :options="localeOptions"
                        />
                    </div>
                    <div class="md:col-span-2 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.welcome_builder.locale.hint') }}
                    </div>
                </div>
                <InputError class="mt-2" :message="form.errors.locale" />
                <InputError class="mt-1" :message="form.errors.content" />
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.welcome_builder.sections.navigation.title') }}
                    </h2>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <FloatingInput v-model="form.content.nav.tagline"
                        :label="$t('super_admin.welcome_builder.sections.navigation.tagline')" />
                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.welcome_builder.sections.navigation.menu_hint') }}
                    </div>
                </div>

                <div class="space-y-3">
                    <div v-for="(item, index) in form.content.nav.menu" :key="item.id || index"
                        class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                        <div class="grid gap-3 md:grid-cols-4">
                            <FloatingInput v-model="item.label"
                                :label="$t('super_admin.welcome_builder.common.label')" />
                            <FloatingInput v-model="item.href"
                                :label="$t('super_admin.welcome_builder.common.href')" />
                            <FloatingSelect v-model="item.style"
                                :label="$t('super_admin.welcome_builder.common.style')"
                                :options="[
                                    { value: 'outline', label: $t('super_admin.welcome_builder.styles.outline') },
                                    { value: 'solid', label: $t('super_admin.welcome_builder.styles.solid') },
                                    { value: 'ghost', label: $t('super_admin.welcome_builder.styles.ghost') },
                                ]" />
                            <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                <Checkbox v-model:checked="item.enabled" />
                                <span>{{ $t('super_admin.welcome_builder.common.enabled') }}</span>
                            </label>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                @click="moveItem(form.content.nav.menu, index, -1)">
                                {{ $t('super_admin.welcome_builder.common.move_up') }}
                            </button>
                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                @click="moveItem(form.content.nav.menu, index, 1)">
                                {{ $t('super_admin.welcome_builder.common.move_down') }}
                            </button>
                            <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                @click="removeMenuItem(index)">
                                {{ $t('super_admin.welcome_builder.common.remove') }}
                            </button>
                            <span class="rounded-sm border px-2 py-1" :class="buttonTone(item.style)">
                                {{ item.label || $t('super_admin.welcome_builder.common.preview') }}
                            </span>
                        </div>
                    </div>

                    <button type="button"
                        class="rounded-sm border border-dashed border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50 dark:border-neutral-600 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        @click="addMenuItem">
                        {{ $t('super_admin.welcome_builder.sections.navigation.add_menu') }}
                    </button>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.welcome_builder.sections.hero.title') }}
                    </h2>
                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                        <Checkbox v-model:checked="form.content.hero.enabled" />
                        <span>{{ $t('super_admin.welcome_builder.common.enabled') }}</span>
                    </label>
                </div>

                <div class="grid gap-2 md:grid-cols-[140px_1fr] md:items-center">
                    <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.welcome_builder.common.background') }}
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <input v-model="form.content.hero.background_color" type="color"
                            class="h-10 w-14 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                        <div class="min-w-[200px] flex-1">
                            <FloatingInput v-model="form.content.hero.background_color"
                                :label="$t('super_admin.welcome_builder.common.background_hex')" />
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <FloatingInput v-model="form.content.hero.eyebrow"
                        :label="$t('super_admin.welcome_builder.sections.hero.eyebrow')" />
                    <FloatingInput v-model="form.content.hero.title"
                        :label="$t('super_admin.welcome_builder.sections.hero.title_label')" />
                    <div class="md:col-span-2">
                        <RichTextEditor
                            v-model="form.content.hero.subtitle"
                            :label="$t('super_admin.welcome_builder.sections.hero.subtitle')"
                            :link-prompt="editorLinkPrompt"
                            :image-prompt="editorImagePrompt"
                            :ai-enabled="ai_enabled"
                            :ai-generate-url="ai_image_generate_url"
                            :ai-prompt="editorAiPrompt"
                            :labels="editorLabels"
                        />
                    </div>
                    <div class="md:col-span-2">
                        <RichTextEditor
                            v-model="form.content.hero.note"
                            :label="$t('super_admin.welcome_builder.sections.hero.note')"
                            :link-prompt="editorLinkPrompt"
                            :image-prompt="editorImagePrompt"
                            :ai-enabled="ai_enabled"
                            :ai-generate-url="ai_image_generate_url"
                            :ai-prompt="editorAiPrompt"
                            :labels="editorLabels"
                        />
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-4">
                    <FloatingInput v-model="form.content.hero.primary_cta"
                        :label="$t('super_admin.welcome_builder.sections.hero.primary_label')" />
                    <FloatingInput v-model="form.content.hero.primary_href"
                        :label="$t('super_admin.welcome_builder.sections.hero.primary_href')" />
                    <FloatingInput v-model="form.content.hero.secondary_cta"
                        :label="$t('super_admin.welcome_builder.sections.hero.secondary_label')" />
                    <FloatingInput v-model="form.content.hero.secondary_href"
                        :label="$t('super_admin.welcome_builder.sections.hero.secondary_href')" />
                </div>

                <div class="grid gap-3 md:grid-cols-3">
                    <div v-for="(stat, index) in form.content.hero.stats" :key="index"
                        class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                        <FloatingInput v-model="stat.value"
                            :label="$t('super_admin.welcome_builder.sections.hero.stat_value')" />
                        <div class="mt-2">
                            <FloatingInput v-model="stat.label"
                                :label="$t('super_admin.welcome_builder.sections.hero.stat_label')" />
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.welcome_builder.sections.hero.highlights') }}
                    </label>
                    <textarea v-model="heroHighlightsLines" rows="4"
                        class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"></textarea>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <div v-for="(card, index) in form.content.hero.preview_cards" :key="index"
                        class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                        <FloatingInput v-model="card.title"
                            :label="$t('super_admin.welcome_builder.sections.hero.preview_title')" />
                        <div class="mt-2">
                            <FloatingInput v-model="card.desc"
                                :label="$t('super_admin.welcome_builder.sections.hero.preview_desc')" />
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <div class="space-y-2">
                        <div v-if="form.content.hero.image_url" class="rounded-sm border border-stone-200 p-2 dark:border-neutral-700">
                            <img :src="form.content.hero.image_url" :alt="form.content.hero.image_alt || 'Hero'"
                                class="h-auto w-full rounded-sm object-cover" loading="lazy" decoding="async" />
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <input type="file" accept="image/*"
                                class="block w-full text-xs text-stone-600 file:mr-2 file:rounded-sm file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:text-xs file:font-semibold hover:file:bg-stone-200"
                                @change="handleImageFile('hero_image', $event)" />
                            <button type="button"
                                class="rounded-sm border border-red-200 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50"
                                @click="markImageRemoved('hero_image')">
                                {{ $t('super_admin.welcome_builder.common.remove_image') }}
                            </button>
                        </div>
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.welcome_builder.common.image_hint') }}
                        </div>
                        <InputError class="mt-1" :message="form.errors.hero_image" />
                    </div>
                    <div class="space-y-3">
                        <FloatingInput v-model="form.content.hero.image_url"
                            :label="$t('super_admin.welcome_builder.common.image_url')" />
                        <FloatingInput v-model="form.content.hero.image_alt"
                            :label="$t('super_admin.welcome_builder.common.image_alt')" />
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.welcome_builder.sections.trust.title') }}
                    </h2>
                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                        <Checkbox v-model:checked="form.content.trust.enabled" />
                        <span>{{ $t('super_admin.welcome_builder.common.enabled') }}</span>
                    </label>
                </div>

                <div class="grid gap-2 md:grid-cols-[140px_1fr] md:items-center">
                    <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.welcome_builder.common.background') }}
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <input v-model="form.content.trust.background_color" type="color"
                            class="h-10 w-14 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                        <div class="min-w-[200px] flex-1">
                            <FloatingInput v-model="form.content.trust.background_color"
                                :label="$t('super_admin.welcome_builder.common.background_hex')" />
                        </div>
                    </div>
                </div>

                <FloatingInput v-model="form.content.trust.title"
                    :label="$t('super_admin.welcome_builder.sections.trust.title_label')" />
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.welcome_builder.sections.trust.items') }}
                    </label>
                    <textarea v-model="trustItemsLines" rows="4"
                        class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"></textarea>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.welcome_builder.sections.features.title') }}
                    </h2>
                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                        <Checkbox v-model:checked="form.content.features.enabled" />
                        <span>{{ $t('super_admin.welcome_builder.common.enabled') }}</span>
                    </label>
                </div>

                <div class="grid gap-2 md:grid-cols-[140px_1fr] md:items-center">
                    <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.welcome_builder.common.background') }}
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <input v-model="form.content.features.background_color" type="color"
                            class="h-10 w-14 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                        <div class="min-w-[200px] flex-1">
                            <FloatingInput v-model="form.content.features.background_color"
                                :label="$t('super_admin.welcome_builder.common.background_hex')" />
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <FloatingInput v-model="form.content.features.kicker"
                        :label="$t('super_admin.welcome_builder.sections.features.kicker')" />
                    <FloatingInput v-model="form.content.features.title"
                        :label="$t('super_admin.welcome_builder.sections.features.title_label')" />
                    <div class="md:col-span-2">
                        <RichTextEditor
                            v-model="form.content.features.subtitle"
                            :label="$t('super_admin.welcome_builder.sections.features.subtitle')"
                            :link-prompt="editorLinkPrompt"
                            :image-prompt="editorImagePrompt"
                            :ai-enabled="ai_enabled"
                            :ai-generate-url="ai_image_generate_url"
                            :ai-prompt="editorAiPrompt"
                            :labels="editorLabels"
                        />
                    </div>
                </div>

                <div class="space-y-3">
                    <div v-for="(item, index) in form.content.features.items" :key="item.key || index"
                        class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                        <div class="grid gap-3 md:grid-cols-2">
                            <FloatingInput v-model="item.key"
                                :label="$t('super_admin.welcome_builder.common.key')" />
                            <FloatingInput v-model="item.title"
                                :label="$t('super_admin.welcome_builder.common.title')" />
                            <div class="md:col-span-2">
                                <RichTextEditor
                                    v-model="item.desc"
                                    :label="$t('super_admin.welcome_builder.common.description')"
                                    :link-prompt="editorLinkPrompt"
                                    :image-prompt="editorImagePrompt"
                                    :ai-enabled="ai_enabled"
                                    :ai-generate-url="ai_image_generate_url"
                                    :ai-prompt="editorAiPrompt"
                                    :labels="editorLabels"
                                />
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs">
                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                @click="moveItem(form.content.features.items, index, -1)">
                                {{ $t('super_admin.welcome_builder.common.move_up') }}
                            </button>
                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                @click="moveItem(form.content.features.items, index, 1)">
                                {{ $t('super_admin.welcome_builder.common.move_down') }}
                            </button>
                            <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                @click="form.content.features.items.splice(index, 1)">
                                {{ $t('super_admin.welcome_builder.common.remove') }}
                            </button>
                        </div>
                    </div>
                    <button type="button"
                        class="rounded-sm border border-dashed border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50 dark:border-neutral-600 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        @click="form.content.features.items.push({ key: '', title: '', desc: '' })">
                        {{ $t('super_admin.welcome_builder.sections.features.add_item') }}
                    </button>
                </div>

                <div class="rounded-sm border border-emerald-200/60 bg-emerald-50/60 p-3 dark:border-emerald-800/60 dark:bg-emerald-950/30 space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-emerald-900 dark:text-emerald-200">
                            {{ $t('super_admin.welcome_builder.sections.new_features.title') }}
                        </h3>
                        <label class="flex items-center gap-2 text-sm text-emerald-900 dark:text-emerald-200">
                            <Checkbox v-model:checked="form.content.features.new_features.enabled" />
                            <span>{{ $t('super_admin.welcome_builder.common.enabled') }}</span>
                        </label>
                    </div>
                    <div class="grid gap-2 md:grid-cols-[140px_1fr] md:items-center">
                        <div class="text-xs font-medium uppercase tracking-wide text-emerald-900/70 dark:text-emerald-200/80">
                            {{ $t('super_admin.welcome_builder.common.background') }}
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <input v-model="form.content.features.new_features.background_color" type="color"
                                class="h-10 w-14 rounded-sm border border-emerald-200 bg-white p-1 dark:border-emerald-800 dark:bg-neutral-900" />
                            <div class="min-w-[200px] flex-1">
                                <FloatingInput v-model="form.content.features.new_features.background_color"
                                    :label="$t('super_admin.welcome_builder.common.background_hex')" />
                            </div>
                        </div>
                    </div>
                    <div class="grid gap-3 md:grid-cols-3">
                        <FloatingInput v-model="form.content.features.new_features.kicker"
                            :label="$t('super_admin.welcome_builder.sections.new_features.kicker')" />
                        <FloatingInput v-model="form.content.features.new_features.title"
                            :label="$t('super_admin.welcome_builder.sections.new_features.title_label')" />
                        <FloatingInput v-model="form.content.features.new_features.badge"
                            :label="$t('super_admin.welcome_builder.sections.new_features.badge')" />
                    </div>
                    <div>
                        <RichTextEditor
                            v-model="form.content.features.new_features.subtitle"
                            :label="$t('super_admin.welcome_builder.sections.new_features.subtitle')"
                            :link-prompt="editorLinkPrompt"
                            :image-prompt="editorImagePrompt"
                            :ai-enabled="ai_enabled"
                            :ai-generate-url="ai_image_generate_url"
                            :ai-prompt="editorAiPrompt"
                            :labels="editorLabels"
                        />
                    </div>
                    <div class="space-y-3">
                        <div v-for="(item, index) in form.content.features.new_features.items" :key="item.key || index"
                            class="rounded-sm border border-emerald-200/70 bg-white/80 p-3 dark:border-emerald-800/70 dark:bg-neutral-900">
                            <div class="grid gap-3 md:grid-cols-2">
                                <FloatingInput v-model="item.key"
                                    :label="$t('super_admin.welcome_builder.common.key')" />
                                <FloatingInput v-model="item.title"
                                    :label="$t('super_admin.welcome_builder.common.title')" />
                                <div class="md:col-span-2">
                                    <RichTextEditor
                                        v-model="item.desc"
                                        :label="$t('super_admin.welcome_builder.common.description')"
                                        :link-prompt="editorLinkPrompt"
                                        :image-prompt="editorImagePrompt"
                                        :ai-enabled="ai_enabled"
                                        :ai-generate-url="ai_image_generate_url"
                                        :ai-prompt="editorAiPrompt"
                                        :labels="editorLabels"
                                    />
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2 text-xs">
                                <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                    @click="moveItem(form.content.features.new_features.items, index, -1)">
                                    {{ $t('super_admin.welcome_builder.common.move_up') }}
                                </button>
                                <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                    @click="moveItem(form.content.features.new_features.items, index, 1)">
                                    {{ $t('super_admin.welcome_builder.common.move_down') }}
                                </button>
                                <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                    @click="form.content.features.new_features.items.splice(index, 1)">
                                    {{ $t('super_admin.welcome_builder.common.remove') }}
                                </button>
                            </div>
                        </div>
                        <button type="button"
                            class="rounded-sm border border-dashed border-emerald-300 px-3 py-2 text-sm text-emerald-800 hover:bg-emerald-100 dark:border-emerald-700 dark:text-emerald-200 dark:hover:bg-emerald-900/50"
                            @click="form.content.features.new_features.items.push({ key: '', title: '', desc: '' })">
                            {{ $t('super_admin.welcome_builder.sections.new_features.add_item') }}
                        </button>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.welcome_builder.sections.workflow.title') }}
                    </h2>
                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                        <Checkbox v-model:checked="form.content.workflow.enabled" />
                        <span>{{ $t('super_admin.welcome_builder.common.enabled') }}</span>
                    </label>
                </div>

                <div class="grid gap-2 md:grid-cols-[140px_1fr] md:items-center">
                    <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.welcome_builder.common.background') }}
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <input v-model="form.content.workflow.background_color" type="color"
                            class="h-10 w-14 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                        <div class="min-w-[200px] flex-1">
                            <FloatingInput v-model="form.content.workflow.background_color"
                                :label="$t('super_admin.welcome_builder.common.background_hex')" />
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <FloatingInput v-model="form.content.workflow.kicker"
                        :label="$t('super_admin.welcome_builder.sections.workflow.kicker')" />
                    <FloatingInput v-model="form.content.workflow.title"
                        :label="$t('super_admin.welcome_builder.sections.workflow.title_label')" />
                    <div class="md:col-span-2">
                        <RichTextEditor
                            v-model="form.content.workflow.subtitle"
                            :label="$t('super_admin.welcome_builder.sections.workflow.subtitle')"
                            :link-prompt="editorLinkPrompt"
                            :image-prompt="editorImagePrompt"
                            :ai-enabled="ai_enabled"
                            :ai-generate-url="ai_image_generate_url"
                            :ai-prompt="editorAiPrompt"
                            :labels="editorLabels"
                        />
                    </div>
                </div>

                <div class="space-y-3">
                    <div v-for="(step, index) in form.content.workflow.steps" :key="index"
                        class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                        <div class="grid gap-3 md:grid-cols-2">
                            <FloatingInput v-model="step.title"
                                :label="$t('super_admin.welcome_builder.common.title')" />
                            <div class="md:col-span-2">
                                <RichTextEditor
                                    v-model="step.desc"
                                    :label="$t('super_admin.welcome_builder.common.description')"
                                    :link-prompt="editorLinkPrompt"
                                    :image-prompt="editorImagePrompt"
                                    :ai-enabled="ai_enabled"
                                    :ai-generate-url="ai_image_generate_url"
                                    :ai-prompt="editorAiPrompt"
                                    :labels="editorLabels"
                                />
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                @click="moveItem(form.content.workflow.steps, index, -1)">
                                {{ $t('super_admin.welcome_builder.common.move_up') }}
                            </button>
                            <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                @click="moveItem(form.content.workflow.steps, index, 1)">
                                {{ $t('super_admin.welcome_builder.common.move_down') }}
                            </button>
                            <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                @click="form.content.workflow.steps.splice(index, 1)">
                                {{ $t('super_admin.welcome_builder.common.remove') }}
                            </button>
                        </div>
                    </div>
                    <button type="button"
                        class="rounded-sm border border-dashed border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50 dark:border-neutral-600 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        @click="form.content.workflow.steps.push({ title: '', desc: '' })">
                        {{ $t('super_admin.welcome_builder.sections.workflow.add_step') }}
                    </button>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <div class="space-y-2">
                        <div v-if="form.content.workflow.image_url" class="rounded-sm border border-stone-200 p-2 dark:border-neutral-700">
                            <img :src="form.content.workflow.image_url" :alt="form.content.workflow.image_alt || 'Workflow'"
                                class="h-auto w-full rounded-sm object-cover" loading="lazy" decoding="async" />
                        </div>
                        <input type="file" accept="image/*"
                            class="block w-full text-xs text-stone-600 file:mr-2 file:rounded-sm file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:text-xs file:font-semibold hover:file:bg-stone-200"
                            @change="handleImageFile('workflow_image', $event)" />
                        <button type="button"
                            class="rounded-sm border border-red-200 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50"
                            @click="markImageRemoved('workflow_image')">
                            {{ $t('super_admin.welcome_builder.common.remove_image') }}
                        </button>
                        <InputError class="mt-1" :message="form.errors.workflow_image" />
                    </div>
                    <div class="space-y-3">
                        <FloatingInput v-model="form.content.workflow.image_url"
                            :label="$t('super_admin.welcome_builder.common.image_url')" />
                        <FloatingInput v-model="form.content.workflow.image_alt"
                            :label="$t('super_admin.welcome_builder.common.image_alt')" />
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.welcome_builder.sections.field.title') }}
                    </h2>
                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                        <Checkbox v-model:checked="form.content.field.enabled" />
                        <span>{{ $t('super_admin.welcome_builder.common.enabled') }}</span>
                    </label>
                </div>

                <div class="grid gap-2 md:grid-cols-[140px_1fr] md:items-center">
                    <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.welcome_builder.common.background') }}
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <input v-model="form.content.field.background_color" type="color"
                            class="h-10 w-14 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                        <div class="min-w-[200px] flex-1">
                            <FloatingInput v-model="form.content.field.background_color"
                                :label="$t('super_admin.welcome_builder.common.background_hex')" />
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <FloatingInput v-model="form.content.field.kicker"
                        :label="$t('super_admin.welcome_builder.sections.field.kicker')" />
                    <FloatingInput v-model="form.content.field.title"
                        :label="$t('super_admin.welcome_builder.sections.field.title_label')" />
                    <div class="md:col-span-2">
                        <RichTextEditor
                            v-model="form.content.field.subtitle"
                            :label="$t('super_admin.welcome_builder.sections.field.subtitle')"
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
                        {{ $t('super_admin.welcome_builder.sections.field.items') }}
                    </label>
                    <textarea v-model="fieldItemsLines" rows="4"
                        class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"></textarea>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <div class="space-y-2">
                        <div v-if="form.content.field.image_url" class="rounded-sm border border-stone-200 p-2 dark:border-neutral-700">
                            <img :src="form.content.field.image_url" :alt="form.content.field.image_alt || 'Field'"
                                class="h-auto w-full rounded-sm object-cover" loading="lazy" decoding="async" />
                        </div>
                        <input type="file" accept="image/*"
                            class="block w-full text-xs text-stone-600 file:mr-2 file:rounded-sm file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:text-xs file:font-semibold hover:file:bg-stone-200"
                            @change="handleImageFile('field_image', $event)" />
                        <button type="button"
                            class="rounded-sm border border-red-200 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50"
                            @click="markImageRemoved('field_image')">
                            {{ $t('super_admin.welcome_builder.common.remove_image') }}
                        </button>
                        <InputError class="mt-1" :message="form.errors.field_image" />
                    </div>
                    <div class="space-y-3">
                        <FloatingInput v-model="form.content.field.image_url"
                            :label="$t('super_admin.welcome_builder.common.image_url')" />
                        <FloatingInput v-model="form.content.field.image_alt"
                            :label="$t('super_admin.welcome_builder.common.image_alt')" />
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.welcome_builder.sections.cta.title') }}
                    </h2>
                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                        <Checkbox v-model:checked="form.content.cta.enabled" />
                        <span>{{ $t('super_admin.welcome_builder.common.enabled') }}</span>
                    </label>
                </div>

                <div class="grid gap-2 md:grid-cols-[140px_1fr] md:items-center">
                    <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.welcome_builder.common.background') }}
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <input v-model="form.content.cta.background_color" type="color"
                            class="h-10 w-14 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                        <div class="min-w-[200px] flex-1">
                            <FloatingInput v-model="form.content.cta.background_color"
                                :label="$t('super_admin.welcome_builder.common.background_hex')" />
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <FloatingInput v-model="form.content.cta.title"
                        :label="$t('super_admin.welcome_builder.sections.cta.title_label')" />
                    <div class="md:col-span-2">
                        <RichTextEditor
                            v-model="form.content.cta.subtitle"
                            :label="$t('super_admin.welcome_builder.sections.cta.subtitle')"
                            :link-prompt="editorLinkPrompt"
                            :image-prompt="editorImagePrompt"
                            :ai-enabled="ai_enabled"
                            :ai-generate-url="ai_image_generate_url"
                            :ai-prompt="editorAiPrompt"
                            :labels="editorLabels"
                        />
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-4">
                    <FloatingInput v-model="form.content.cta.primary"
                        :label="$t('super_admin.welcome_builder.sections.cta.primary_label')" />
                    <FloatingInput v-model="form.content.cta.primary_href"
                        :label="$t('super_admin.welcome_builder.sections.cta.primary_href')" />
                    <FloatingInput v-model="form.content.cta.secondary"
                        :label="$t('super_admin.welcome_builder.sections.cta.secondary_label')" />
                    <FloatingInput v-model="form.content.cta.secondary_href"
                        :label="$t('super_admin.welcome_builder.sections.cta.secondary_href')" />
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.welcome_builder.sections.custom.title') }}
                    </h2>
                    <button type="button"
                        class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        @click="addCustomSection">
                        {{ $t('super_admin.welcome_builder.sections.custom.add') }}
                    </button>
                </div>

                <div v-if="!form.content.custom_sections.length"
                    class="rounded-sm border border-dashed border-stone-300 p-4 text-sm text-stone-600 dark:border-neutral-600 dark:text-neutral-300">
                    {{ $t('super_admin.welcome_builder.sections.custom.empty') }}
                </div>

                <div class="space-y-4">
                    <div v-for="(section, index) in form.content.custom_sections" :key="section.id || index"
                        class="rounded-sm border border-stone-200 p-4 dark:border-neutral-700 space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.welcome_builder.sections.custom.section_label') }} #{{ index + 1 }}
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                    <Checkbox v-model:checked="section.enabled" />
                                    <span>{{ $t('super_admin.welcome_builder.common.enabled') }}</span>
                                </label>
                                <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                    @click="moveItem(form.content.custom_sections, index, -1)">
                                    {{ $t('super_admin.welcome_builder.common.move_up') }}
                                </button>
                                <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 hover:bg-stone-50"
                                    @click="moveItem(form.content.custom_sections, index, 1)">
                                    {{ $t('super_admin.welcome_builder.common.move_down') }}
                                </button>
                                <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                    @click="removeCustomSection(index)">
                                    {{ $t('super_admin.welcome_builder.common.remove') }}
                                </button>
                            </div>
                        </div>

                        <div class="grid gap-2 md:grid-cols-[140px_1fr] md:items-center">
                            <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.welcome_builder.common.background') }}
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <input v-model="section.background_color" type="color"
                                    class="h-10 w-14 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                                <div class="min-w-[200px] flex-1">
                                    <FloatingInput v-model="section.background_color"
                                        :label="$t('super_admin.welcome_builder.common.background_hex')" />
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-3 md:grid-cols-2">
                            <FloatingInput v-model="section.kicker" :label="$t('super_admin.welcome_builder.common.kicker')" />
                            <FloatingInput v-model="section.title" :label="$t('super_admin.welcome_builder.common.title')" />
                            <div class="md:col-span-2">
                                <RichTextEditor
                                    v-model="section.body"
                                    :label="$t('super_admin.welcome_builder.common.body')"
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
                            <FloatingInput v-model="section.image_url" :label="$t('super_admin.welcome_builder.common.image_url')" />
                            <FloatingInput v-model="section.image_alt" :label="$t('super_admin.welcome_builder.common.image_alt')" />
                        </div>
                        <div class="grid gap-3 md:grid-cols-4">
                            <FloatingInput v-model="section.primary_label" :label="$t('super_admin.welcome_builder.common.primary_label')" />
                            <FloatingInput v-model="section.primary_href" :label="$t('super_admin.welcome_builder.common.primary_href')" />
                            <FloatingInput v-model="section.secondary_label" :label="$t('super_admin.welcome_builder.common.secondary_label')" />
                            <FloatingInput v-model="section.secondary_href" :label="$t('super_admin.welcome_builder.common.secondary_href')" />
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('super_admin.welcome_builder.sections.footer.title') }}
                </h2>

                <div class="grid gap-3 md:grid-cols-3">
                    <FloatingInput v-model="form.content.footer.terms_label"
                        :label="$t('super_admin.welcome_builder.sections.footer.terms_label')" />
                    <FloatingInput v-model="form.content.footer.terms_href"
                        :label="$t('super_admin.welcome_builder.sections.footer.terms_href')" />
                    <FloatingInput v-model="form.content.footer.copy"
                        :label="$t('super_admin.welcome_builder.sections.footer.copy')" />
                </div>
            </section>

            <div class="flex justify-end">
                <button type="button" @click="submit"
                    class="rounded-sm border border-transparent bg-green-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                    :disabled="form.processing">
                    {{ $t('super_admin.welcome_builder.actions.save') }}
                </button>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
