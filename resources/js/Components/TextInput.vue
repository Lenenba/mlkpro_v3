<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
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
    <input
        class="rounded-sm border-stone-200 bg-white text-stone-900 shadow-sm focus:border-primary-line focus:ring-primary-line dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:border-primary-line dark:focus:ring-primary-line"
        v-model="model"
        ref="input"
    />
</template>
