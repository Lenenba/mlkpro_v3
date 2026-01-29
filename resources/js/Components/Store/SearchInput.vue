<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    label: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    buttonLabel: { type: String, default: '' },
    debounce: { type: Number, default: 250 },
    id: { type: String, default: 'store-search' },
});

const emit = defineEmits(['update:modelValue', 'submit', 'clear']);

const inputValue = ref(props.modelValue || '');
let timer = null;

watch(
    () => props.modelValue,
    (value) => {
        if (value !== inputValue.value) {
            inputValue.value = value || '';
        }
    }
);

watch(inputValue, (value) => {
    if (timer) {
        clearTimeout(timer);
    }
    timer = setTimeout(() => {
        emit('update:modelValue', value || '');
    }, props.debounce);
});

const submit = () => {
    emit('update:modelValue', inputValue.value || '');
    emit('submit', inputValue.value || '');
};

const clear = () => {
    inputValue.value = '';
    emit('update:modelValue', '');
    emit('clear');
};
</script>

<template>
    <div class="flex w-full flex-col gap-1.5">
        <label v-if="label" class="text-xs font-semibold uppercase tracking-wide text-slate-400" :for="id">
            {{ label }}
        </label>
        <div class="flex items-center gap-2 rounded-sm border border-slate-200 bg-white px-3 py-2 focus-within:border-emerald-400 focus-within:ring-2 focus-within:ring-emerald-100">
            <input
                :id="id"
                v-model="inputValue"
                type="text"
                class="w-full border-0 bg-transparent text-sm text-slate-700 placeholder:text-slate-400 focus:ring-0"
                :placeholder="placeholder"
                @keydown.enter.prevent="submit"
            >
            <button
                v-if="inputValue"
                type="button"
                class="text-xs font-semibold text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                aria-label="Clear search"
                @click="clear"
            >
                x
            </button>
            <button
                v-if="buttonLabel"
                type="button"
                class="rounded-sm bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                @click="submit"
            >
                {{ buttonLabel }}
            </button>
        </div>
    </div>
</template>
