<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import { neutralizeTemplateAssetSources } from '@/utils/templatePlaceholderHtml';

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        default: '',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    placeholder: {
        type: String,
        default: '',
    },
    compact: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

const mode = ref('visual');

const value = computed({
    get: () => String(props.modelValue || ''),
    set: (next) => emit('update:modelValue', String(next || '')),
});

const toolbarLabels = computed(() => ({
    heading2: t('marketing.rich_text.toolbar.heading2'),
    heading3: t('marketing.rich_text.toolbar.heading3'),
    bold: t('marketing.rich_text.toolbar.bold'),
    italic: t('marketing.rich_text.toolbar.italic'),
    underline: t('marketing.rich_text.toolbar.underline'),
    unorderedList: t('marketing.rich_text.toolbar.unordered_list'),
    orderedList: t('marketing.rich_text.toolbar.ordered_list'),
    quote: t('marketing.rich_text.toolbar.quote'),
    codeBlock: t('marketing.rich_text.toolbar.code_block'),
    horizontalRule: t('marketing.rich_text.toolbar.horizontal_rule'),
    link: t('marketing.rich_text.toolbar.link'),
    image: t('marketing.rich_text.toolbar.image'),
    clear: t('marketing.rich_text.toolbar.clear'),
    linkShort: t('marketing.rich_text.toolbar.link_short'),
    imageShort: t('marketing.rich_text.toolbar.image_short'),
    clearShort: t('marketing.rich_text.toolbar.clear_short'),
}));

const editorPlaceholder = computed(() => {
    const custom = String(props.placeholder || '').trim();
    if (custom !== '') {
        return custom;
    }
    return t('marketing.rich_text.placeholder');
});

const previewMarkup = computed(() => neutralizeTemplateAssetSources(value.value));

const tabClass = (tab) => {
    if (mode.value === tab) {
        return 'bg-green-600 text-white border-green-600';
    }
    return 'bg-white text-stone-700 border-stone-200 hover:bg-stone-50 dark:bg-neutral-900 dark:text-neutral-200 dark:border-neutral-700 dark:hover:bg-neutral-800';
};
</script>

<template>
    <div class="space-y-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <label v-if="label" class="block text-xs text-stone-500 dark:text-neutral-400">
                {{ label }}
            </label>
            <div class="inline-flex items-center rounded-sm">
                <button
                    type="button"
                    class="rounded-l-sm border px-2 py-1 text-[11px] font-semibold transition"
                    :class="tabClass('visual')"
                    @click="mode = 'visual'"
                >
                    {{ t('marketing.rich_text.modes.visual') }}
                </button>
                <button
                    type="button"
                    class="border-y border-r px-2 py-1 text-[11px] font-semibold transition"
                    :class="tabClass('html')"
                    @click="mode = 'html'"
                >
                    {{ t('marketing.rich_text.modes.html') }}
                </button>
                <button
                    type="button"
                    class="rounded-r-sm border-y border-r px-2 py-1 text-[11px] font-semibold transition"
                    :class="tabClass('preview')"
                    @click="mode = 'preview'"
                >
                    {{ t('marketing.rich_text.modes.preview') }}
                </button>
            </div>
        </div>

        <div v-if="mode === 'visual'" :class="props.compact ? 'email-body-editor-compact' : ''">
            <RichTextEditor
                v-model="value"
                :label="''"
                :disabled="disabled"
                :placeholder="editorPlaceholder"
                :link-prompt="t('marketing.rich_text.link_prompt')"
                :image-prompt="t('marketing.rich_text.image_prompt')"
                :labels="toolbarLabels"
            />
        </div>

        <div v-else-if="mode === 'html'">
            <FloatingTextarea
                v-model="value"
                :label="t('marketing.rich_text.html_source')"
                :disabled="disabled"
            />
        </div>

        <div
            v-else
            class="rounded-sm border border-stone-200 bg-white p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
            :class="props.compact ? 'min-h-[96px]' : 'min-h-[130px]'"
        >
            <div v-if="value.trim() !== ''" class="wysiwyg-preview leading-6" v-html="previewMarkup"></div>
            <p v-else class="text-xs text-stone-500 dark:text-neutral-400">
                {{ t('marketing.rich_text.empty_preview') }}
            </p>
        </div>
    </div>
</template>

<style scoped>
.wysiwyg-preview :deep(img) {
    max-width: 100%;
    height: auto;
}

.wysiwyg-preview :deep(a) {
    color: #166534;
    text-decoration: underline;
}

.wysiwyg-preview :deep(p) {
    margin: 0 0 0.6rem;
}

.wysiwyg-preview :deep(ul),
.wysiwyg-preview :deep(ol) {
    margin: 0 0 0.6rem;
    padding-left: 1.3rem;
}

.email-body-editor-compact :deep(.editor-body) {
    min-height: 90px;
}
</style>
