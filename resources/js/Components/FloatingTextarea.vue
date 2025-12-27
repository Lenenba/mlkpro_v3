<script setup>
import { onMounted, ref, computed } from 'vue';

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

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="relative">
        <textarea
            :id="textareaId"
            :disabled="disabled"
            v-model="model"
            ref="input"
            class="peer p-4 block w-full border-stone-200 rounded-sm text-sm placeholder:text-transparent focus:border-green-500 focus:ring-green-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-400 dark:focus:ring-neutral-600
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
            class="absolute top-0 left-0 p-4 h-full text-sm truncate pointer-events-none transition ease-in-out duration-100 origin-[0_0] dark:text-white peer-disabled:opacity-50 peer-disabled:pointer-events-none
                scale-90
                translate-x-0.5
                -translate-y-1.5
                text-stone-500 dark:text-neutral-500
                peer-placeholder-shown:scale-100
                peer-placeholder-shown:translate-x-0
                peer-placeholder-shown:translate-y-0
                peer-placeholder-shown:text-stone-500 dark:peer-placeholder-shown:text-neutral-500
                peer-focus:scale-90
                peer-focus:translate-x-0.5
                peer-focus:-translate-y-1.5
                peer-focus:text-stone-500 dark:peer-focus:text-neutral-500"
        >
            <span>{{ label }}</span>
            <span v-if="required" class="text-red-500 dark:text-red-400"> *</span>
        </label>
    </div>
</template>
