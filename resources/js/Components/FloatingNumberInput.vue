<script setup>
import { ref, watch } from 'vue';

// Props pour recevoir un label
const props = defineProps({
    label: {
        type: String,
        default: 'Select quantity',
    },
});

// Modèle réactif pour la valeur de l'entrée
const model = defineModel({
    type: [Number, String],
    required: true,
});

// Référence pour l'élément input
const input = ref(null);

// Méthodes pour gérer l'incrémentation et la décrémentation
const toNumber = (value) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

const increment = () => {
    model.value = toNumber(model.value) + 1; // Incr‚mente la valeur
};

const decrement = () => {
    const currentValue = toNumber(model.value);
    if (currentValue > 0) {
        model.value = currentValue - 1; // D‚cr‚mente uniquement si la valeur est sup‚rieure … 0
    }
};

// Exposer la méthode focus pour accéder à l'élément input
defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <div
        class="py-2 px-3 w-full bg-white border border-stone-200 rounded-sm dark:bg-neutral-900 dark:border-neutral-700">
        <div class="w-full flex justify-between items-center gap-x-3" data-hs-input-number="">
            <div>
                <span class="block text-xs text-stone-500 dark:text-neutral-400">
                    {{ label }}
                </span>
                <!-- Input -->
                <input ref="input" v-model="model"
                    class="p-0 bg-transparent border-0 text-stone-800 focus:ring-0 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none dark:text-white"
                    style="-moz-appearance: textfield;" type="number" aria-roledescription="Number field"
                    data-hs-input-number-input="" />
            </div>
            <!-- Buttons -->
            <div class="flex justify-end items-center gap-x-1.5">
                <!-- Decrement Button -->
                <button type="button" @click="decrement"
                    class="size-6 inline-flex justify-center items-center gap-x-2 text-sm font-medium rounded-full border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-white dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                    tabindex="-1" aria-label="Decrease">
                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M5 12h14"></path>
                    </svg>
                </button>

                <!-- Increment Button -->
                <button type="button" @click="increment"
                    class="size-6 inline-flex justify-center items-center gap-x-2 text-sm font-medium rounded-full border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-white dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                    tabindex="-1" aria-label="Increase">
                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M5 12h14"></path>
                        <path d="M12 5v14"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>
</template>
