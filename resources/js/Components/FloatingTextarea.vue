<script setup>
import { computed, onMounted, ref, useAttrs } from 'vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    id: {
        type: String,
        default: null,
    },
    label: {
        type: String,
        default: '',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    required: {
        type: Boolean,
        default: false,
    },
});

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);
const generatedId = `floating-textarea-${Math.random().toString(36).slice(2, 10)}`;
const textareaId = computed(() => props.id || generatedId);
const attrs = useAttrs();
const textareaAttrs = computed(() => {
    const inputAttrs = { ...attrs };
    delete inputAttrs.class;

    return inputAttrs;
});

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="relative w-full min-w-0" :class="attrs.class">
        <textarea
            :id="textareaId"
            :disabled="disabled"
            :required="required"
            v-model="model"
            ref="input"
            v-bind="textareaAttrs"
            class="peer p-4 block w-full border-stone-200 rounded-sm text-sm placeholder:text-transparent focus:border-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-400 dark:focus:ring-neutral-600
                focus:pt-6
                focus:pb-2
                [&:not(:placeholder-shown)]:pt-6
                [&:not(:placeholder-shown)]:pb-2
                autofill:pt-6
                autofill:pb-2"
            :placeholder="label || ' '"
            data-hs-textarea-auto-height=""
        ></textarea>
        <label
            :for="textareaId"
            :title="label"
            class="app-floating-label
                scale-90
                translate-x-0.5
                -translate-y-1.5
                peer-placeholder-shown:scale-100
                peer-placeholder-shown:translate-x-0
                peer-placeholder-shown:translate-y-0
                peer-focus:scale-90
                peer-focus:translate-x-0.5
                peer-focus:-translate-y-1.5"
        >
            <span class="app-floating-label-content">
                {{ label }}<span v-if="required" class="text-red-500 dark:text-red-400"> *</span>
            </span>
        </label>
    </div>
</template>
