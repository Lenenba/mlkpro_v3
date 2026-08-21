<script setup>
import { computed, onMounted, ref, useAttrs } from 'vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
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
    placeholder: {
        type: String,
        default: '',
    },
    autocomplete: {
        type: String,
        default: null,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    readonly: {
        type: Boolean,
        default: false,
    },
    required: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);

const input = ref(null);
const generatedId = `floating-input-${Math.random().toString(36).slice(2, 10)}`;
const attrs = useAttrs();

const value = computed({
    get: () => props.modelValue,
    set: (newValue) => {
        emit('update:modelValue', newValue);
    },
});

const inputId = computed(() => props.id || generatedId);
const resolvedPlaceholder = computed(() => props.placeholder || props.label);
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
    <div class="relative w-full min-w-0">
        <input
            :id="inputId"
            v-model="value"
            ref="input"
            v-bind="attrs"
            :type="type"
            :disabled="disabled"
            :readonly="readonly"
            :required="required"
            :aria-required="required ? 'true' : undefined"
            :autocomplete="resolvedAutocomplete"
            class="app-field-control peer"
            :placeholder="resolvedPlaceholder"
        />
        <label
            :for="inputId"
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
