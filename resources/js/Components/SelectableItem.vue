<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    // Array of values to loop through
    LoopValue: {
        type: Array,
        required: true,
    },
});

// Define model binding
const model = defineModel({
    type: Array,
    required: true,
    default: () => [],
});

// Emit events when model changes
const emit = defineEmits(['update:modelValue']);

// Watch for model changes
watch(model, (newValue) => {
    emit('update:modelValue', newValue);
});
</script>

<template>
    <!-- Checkbox Grid -->
    <div class="grid lg:grid-cols-5 gap-1 lg:gap-5 mx-1">
        <!-- Checkbox -->
        <label v-for="day in LoopValue" :key="day" :for="'hs-pro-esdo' + day"
            class="py-1.5 px-2 flex text-sm bg-white text-gray-800 rounded-sm cursor-pointer ring-1 ring-gray-200 has-[:checked]:ring-2 has-[:checked]:ring-green-600 dark:bg-neutral-800 dark:text-neutral-200 dark:ring-neutral-700 dark:has-[:checked]:ring-green-500">

            <input
                type="checkbox"
                :id="'hs-pro-esdo' + day"
                v-model="model"
                :value="day"
                class="hidden bg-transparent border-gray-200 text-green-600 rounded-full focus:ring-white focus:ring-offset-0 dark:text-green-500 dark:border-neutral-700 dark:focus:ring-neutral-800"
            >

            <span class="grow px-1">
                <span class="block font-medium">
                    {{ day }}
                </span>
            </span>
        </label>
        <!-- End Checkbox -->
    </div>
    <!-- End Checkbox Grid -->
</template>
