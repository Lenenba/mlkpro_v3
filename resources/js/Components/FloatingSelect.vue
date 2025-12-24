<script setup>
import { onMounted, ref } from 'vue';

const props = defineProps({
    label: {
        type: String,
    },
    options: {
        type: Array,
        required: true,
    },
});
const model = defineModel({
    type: [String, Number],
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <!-- Floating Select -->
    <div class="relative">
        <select v-model="model" ref="input" class="peer p-4 pe-9 block w-full border-stone-200 rounded-sm text-sm focus:border-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-400 dark:focus:ring-neutral-600
            focus:pt-6
            focus:pb-2
            [&:not(:placeholder-shown)]:pt-6
            [&:not(:placeholder-shown)]:pb-2
            autofill:pt-6
            autofill:pb-2">
            <option class=" rounded-sm" v-for="option in options" :key="option.id" :value="option.id"> {{ option.name }}</option>
        </select>
        <label
            class="absolute top-0 start-0 p-4 h-full truncate pointer-events-none transition ease-in-out duration-100 border border-transparent dark:text-white peer-disabled:opacity-50 peer-disabled:pointer-events-none
            peer-focus:text-xs
            peer-focus:-translate-y-1.5
            peer-focus:text-stone-500 dark:peer-focus:text-neutral-500
            peer-[:not(:placeholder-shown)]:text-xs
            peer-[:not(:placeholder-shown)]:-translate-y-1.5
            peer-[:not(:placeholder-shown)]:text-stone-500 dark:peer-[:not(:placeholder-shown)]:text-neutral-500 dark:text-neutral-500">{{ label }}</label>
    </div>
    <!-- End Floating Select -->
</template>
