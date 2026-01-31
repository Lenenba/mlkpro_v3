<script setup>
import { onMounted, ref, watch } from 'vue';

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        default: '',
    },
    placeholder: {
        type: String,
        default: '',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    linkPrompt: {
        type: String,
        default: 'Enter a link URL',
    },
    imagePrompt: {
        type: String,
        default: 'Enter an image URL',
    },
    aiEnabled: {
        type: Boolean,
        default: false,
    },
    aiContext: {
        type: String,
        default: '',
    },
    aiAllowed: {
        type: Boolean,
        default: true,
    },
    aiGenerateUrl: {
        type: String,
        default: '',
    },
    aiPrompt: {
        type: String,
        default: 'Describe the image to generate',
    },
    aiBusyLabel: {
        type: String,
        default: 'Generating...',
    },
    labels: {
        type: Object,
        default: () => ({}),
    },
});

const emit = defineEmits(['update:modelValue', 'ai-generated']);

const editorRef = ref(null);
const isFocused = ref(false);
const isGenerating = ref(false);
const aiError = ref('');

const updateValue = () => {
    if (!editorRef.value) {
        return;
    }
    emit('update:modelValue', editorRef.value.innerHTML);
};

const setContent = (value) => {
    if (!editorRef.value) {
        return;
    }
    editorRef.value.innerHTML = value || '';
};

const focusEditor = () => {
    if (props.disabled || !editorRef.value) {
        return;
    }
    editorRef.value.focus();
};

const runCommand = (command, value = null) => {
    if (props.disabled) {
        return;
    }
    focusEditor();
    document.execCommand(command, false, value);
    updateValue();
};

const formatBlock = (tag) => runCommand('formatBlock', tag);

const insertLink = () => {
    if (props.disabled) {
        return;
    }
    const url = window.prompt(props.linkPrompt);
    if (!url) {
        return;
    }
    runCommand('createLink', url);
};

const insertImage = () => {
    if (props.disabled) {
        return;
    }
    const url = window.prompt(props.imagePrompt);
    if (!url) {
        return;
    }
    runCommand('insertImage', url);
};

const removeFormatting = () => runCommand('removeFormat');

const resolveCsrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

const generateImage = async () => {
    if (props.disabled || !props.aiEnabled || !props.aiGenerateUrl) {
        return;
    }
    const selectedText = window.getSelection()?.toString()?.trim() || '';
    const prompt = window.prompt(props.aiPrompt, selectedText);
    if (!prompt) {
        return;
    }

    isGenerating.value = true;
    aiError.value = '';

    try {
        const token = resolveCsrfToken();
        const body = { prompt };
        if (props.aiContext) {
            body.context = props.aiContext;
        }

        const response = await fetch(props.aiGenerateUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            },
            body: JSON.stringify(body),
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(payload?.message || 'Image generation failed.');
        }

        if (!payload?.url) {
            throw new Error('Image generation failed.');
        }

        runCommand('insertImage', payload.url);
        emit('ai-generated', payload);
    } catch (error) {
        aiError.value = error?.message || 'Image generation failed.';
    } finally {
        isGenerating.value = false;
    }
};

onMounted(() => {
    try {
        document.execCommand('defaultParagraphSeparator', false, 'p');
    } catch (error) {
        // Ignore if the command is not supported.
    }
    setContent(props.modelValue);
});

watch(
    () => props.modelValue,
    (value) => {
        if (isFocused.value && value) {
            return;
        }
        if (editorRef.value && editorRef.value.innerHTML !== (value || '')) {
            editorRef.value.innerHTML = value || '';
        }
    }
);
</script>

<template>
    <div class="space-y-2">
        <label v-if="label" class="block text-xs text-stone-500 dark:text-neutral-400">
            {{ label }}
        </label>
        <div class="rounded-sm border border-stone-200 bg-white text-stone-700 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
            <div class="flex flex-wrap items-center gap-1 border-b border-stone-200 bg-stone-50 p-2 dark:border-neutral-700 dark:bg-neutral-800">
                <button type="button" class="editor-btn" :title="labels.heading2" :aria-label="labels.heading2" :disabled="disabled" @click="formatBlock('<h2>')">H2</button>
                <button type="button" class="editor-btn" :title="labels.heading3" :aria-label="labels.heading3" :disabled="disabled" @click="formatBlock('<h3>')">H3</button>
                <span class="h-4 w-px bg-stone-200 dark:bg-neutral-700"></span>
                <button type="button" class="editor-btn" :title="labels.bold" :aria-label="labels.bold" :disabled="disabled" @click="runCommand('bold')">B</button>
                <button type="button" class="editor-btn italic" :title="labels.italic" :aria-label="labels.italic" :disabled="disabled" @click="runCommand('italic')">I</button>
                <button type="button" class="editor-btn underline" :title="labels.underline" :aria-label="labels.underline" :disabled="disabled" @click="runCommand('underline')">U</button>
                <span class="h-4 w-px bg-stone-200 dark:bg-neutral-700"></span>
                <button type="button" class="editor-btn" :title="labels.unorderedList" :aria-label="labels.unorderedList" :disabled="disabled" @click="runCommand('insertUnorderedList')">
                    UL
                </button>
                <button type="button" class="editor-btn" :title="labels.orderedList" :aria-label="labels.orderedList" :disabled="disabled" @click="runCommand('insertOrderedList')">
                    OL
                </button>
                <span class="h-4 w-px bg-stone-200 dark:bg-neutral-700"></span>
                <button type="button" class="editor-btn" :title="labels.quote" :aria-label="labels.quote" :disabled="disabled" @click="formatBlock('<blockquote>')">"</button>
                <button type="button" class="editor-btn" :title="labels.codeBlock" :aria-label="labels.codeBlock" :disabled="disabled" @click="formatBlock('<pre>')">{ }</button>
                <button type="button" class="editor-btn" :title="labels.horizontalRule" :aria-label="labels.horizontalRule" :disabled="disabled" @click="runCommand('insertHorizontalRule')">HR</button>
                <span class="h-4 w-px bg-stone-200 dark:bg-neutral-700"></span>
                <button type="button" class="editor-btn" :title="labels.link" :aria-label="labels.link" :disabled="disabled" @click="insertLink">Link</button>
                <button type="button" class="editor-btn" :title="labels.image" :aria-label="labels.image" :disabled="disabled" @click="insertImage">Img</button>
                <button
                    v-if="aiEnabled"
                    type="button"
                    class="editor-btn"
                    :title="labels.aiImage || 'AI image'"
                    :aria-label="labels.aiImage || 'AI image'"
                    :disabled="disabled || isGenerating || !aiAllowed"
                    @click="generateImage"
                >
                    {{ isGenerating ? aiBusyLabel : 'AI' }}
                </button>
                <button type="button" class="editor-btn" :title="labels.clear" :aria-label="labels.clear" :disabled="disabled" @click="removeFormatting">Clear</button>
            </div>
            <div
                ref="editorRef"
                class="editor-body min-h-[120px] px-3 py-2 text-sm focus:outline-none"
                :contenteditable="!disabled"
                :data-placeholder="placeholder"
                :aria-label="label"
                spellcheck="true"
                @input="updateValue"
                @focus="isFocused = true"
                @blur="isFocused = false"
            ></div>
        </div>
        <p v-if="aiError" class="text-xs font-semibold text-red-600">
            {{ aiError }}
        </p>
    </div>
</template>

<style scoped>
.editor-btn {
    border-radius: 0.125rem;
    border: 1px solid transparent;
    padding: 0.25rem 0.4rem;
    font-size: 0.7rem;
    font-weight: 600;
    color: inherit;
}

.editor-btn:hover {
    background-color: rgba(120, 113, 108, 0.12);
}

.editor-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.editor-btn.italic {
    font-style: italic;
}

.editor-btn.underline {
    text-decoration: underline;
}

.editor-body:empty:before {
    content: attr(data-placeholder);
    color: #94a3b8;
}
</style>
