<script setup>
import { computed, ref, useAttrs, watch } from 'vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: {
        type: [Object, String],
        default: null,
    },
    label: {
        type: String,
        required: true,
    },
    placeholder: {
        type: String,
        default: '',
    },
    accept: {
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

const emit = defineEmits(['update:modelValue', 'select']);
const attrs = useAttrs();
const input = ref(null);

const displayName = computed(() => {
    if (typeof File !== 'undefined' && props.modelValue instanceof File) {
        return props.modelValue.name;
    }

    if (typeof props.modelValue === 'string' && props.modelValue.trim()) {
        return props.modelValue.split('/').pop() || props.modelValue;
    }

    return props.placeholder || props.label;
});

const openPicker = () => {
    if (!props.disabled) {
        input.value?.click();
    }
};

const handleChange = (event) => {
    const file = event.target.files?.[0] || null;
    emit('update:modelValue', file);
    emit('select', file);
};

watch(
    () => props.modelValue,
    (value) => {
        if (!value && input.value) {
            input.value.value = '';
        }
    },
);

defineExpose({ focus: openPicker, open: openPicker });
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="group relative flex min-h-14 w-full items-end rounded-sm border border-stone-200 bg-white px-4 pb-2 pt-6 text-left text-sm text-stone-800 transition hover:border-stone-300 focus:border-green-600 focus:outline-none focus:ring-1 focus:ring-green-600 disabled:pointer-events-none disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:border-neutral-600 dark:focus:border-green-600 dark:focus:ring-green-600"
            :disabled="disabled"
            :aria-label="label"
            @click="openPicker"
        >
            <span class="absolute start-4 top-2 max-w-[calc(100%-4rem)] truncate text-xs text-stone-500 dark:text-neutral-500">
                {{ label }}
                <span v-if="required" class="text-red-500 dark:text-red-400"> *</span>
            </span>
            <span class="min-w-0 flex-1 truncate">
                {{ displayName }}
            </span>
            <svg
                class="ms-3 size-4 shrink-0 text-stone-400 transition group-hover:text-stone-600 dark:text-neutral-500 dark:group-hover:text-neutral-300"
                xmlns="http://www.w3.org/2000/svg"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                <polyline points="17 8 12 3 7 8" />
                <line x1="12" x2="12" y1="3" y2="15" />
            </svg>
        </button>
        <input
            ref="input"
            v-bind="attrs"
            type="file"
            :accept="accept"
            :disabled="disabled"
            tabindex="-1"
            aria-hidden="true"
            class="sr-only"
            @change="handleChange"
        />
    </div>
</template>
