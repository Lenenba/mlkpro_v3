<script setup>
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
    modelValue: {
        type: String,
        required: true,
    },
    type: {
        type: String,
        default: 'text',
    },
    id: {
        type: String,
        default: null,
    },
    label: {
        type: String,
        required: true,
    },
    autocomplete: {
        type: String,
        default: null,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);

const input = ref(null);
const generatedId = `floating-input-${Math.random().toString(36).slice(2, 10)}`;

const value = computed({
    get: () => props.modelValue,
    set: (newValue) => {
        emit('update:modelValue', newValue);
    },
});

const inputId = computed(() => props.id || generatedId);
const resolvedAutocomplete = computed(() => {
    if (props.autocomplete) {
        return props.autocomplete;
    }

    if (props.type === 'password') {
        return 'current-password';
    }

    return 'off';
});

onMounted(() => {
    if (input.value && input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="relative">
        <input
            :id="inputId"
            v-model="value"
            ref="input"
            :type="type"
            :disabled="disabled"
            :autocomplete="resolvedAutocomplete"
            class="peer p-4 block w-full border-gray-200 rounded-sm text-sm placeholder-transparent focus:border-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-400 dark:focus:ring-neutral-600
                focus:pt-6
                focus:pb-2
                [&:not(:placeholder-shown)]:pt-6
                [&:not(:placeholder-shown)]:pb-2
                autofill:pt-6
                autofill:pb-2"
            :placeholder="label"
        />
        <label
            :for="inputId"
            class="absolute top-0 left-0 p-4 h-full text-sm truncate pointer-events-none transition ease-in-out duration-100 origin-[0_0] dark:text-white peer-disabled:opacity-50 peer-disabled:pointer-events-none
                scale-90
                translate-x-0.5
                -translate-y-1.5
                text-gray-500 dark:text-neutral-500
                peer-placeholder-shown:scale-100
                peer-placeholder-shown:translate-x-0
                peer-placeholder-shown:translate-y-0
                peer-placeholder-shown:text-gray-500 dark:peer-placeholder-shown:text-neutral-500
                peer-focus:scale-90
                peer-focus:translate-x-0.5
                peer-focus:-translate-y-1.5
                peer-focus:text-gray-500 dark:peer-focus:text-neutral-500"
        >
            {{ label }}
        </label>
    </div>
</template>
