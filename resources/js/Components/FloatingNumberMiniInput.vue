<script setup>
import { ref, computed } from 'vue';

// Props pour recevoir un label et une valeur liée
const props = defineProps({
    label: {
        type: String,
        default: 'Select quantity', // Label par défaut
    },
    modelValue: {
        type: [Number, String],
        default: 0, // Valeur par défaut pour le modèle
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

// Émettre l'événement pour synchroniser avec le parent
const emit = defineEmits(['update:modelValue']);

// Propriété calculée pour synchroniser avec le parent
const value = computed({
    get: () => props.modelValue, // Lecture de la valeur depuis le parent
    set: (newValue) => emit('update:modelValue', newValue), // Mise à jour du parent
});

// Référence pour l'élément input
const input = ref(null);

// Méthodes pour gérer l'incrémentation et la décrémentation
const increment = () => {
    if (props.disabled) {
        return;
    }
    value.value += 1; // Incr‚mente la valeur
};

const decrement = () => {
    if (props.disabled) {
        return;
    }
    if (value.value > 0) {
        value.value -= 1; // D‚cr‚mente uniquement si la valeur est sup‚rieure … 0
    }
};

// Exposer la méthode focus pour accéder à l'élément input
defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <!-- Input Number -->
    <div
        class="py-3.5 px-3 inline-block bg-white border border-stone-200 rounded-sm dark:bg-neutral-900 dark:border-neutral-700"
        data-hs-input-number="{}"
    >
        <div class="flex items-center gap-x-1.5">
            <slot name="logo" />
            <!-- Bouton décrémentation -->
            <button
                type="button"
                @click="decrement"
                :disabled="disabled"
                class="size-6 inline-flex justify-center items-center gap-x-2 text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-white dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                tabindex="-1"
                aria-label="Decrease"
                data-hs-input-number-decrement="true"
            >
                <svg
                    class="shrink-0 size-3.5"
                    xmlns="http://www.w3.org/2000/svg"
                    width="24"
                    height="24"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M5 12h14"></path>
                </svg>
            </button>

            <!-- Champ d'entrée -->
            <input
                ref="input"
                v-model="value"
                :disabled="disabled"
                class="p-0 w-6 bg-transparent border-0 text-stone-800 text-center focus:ring-0 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none dark:text-white"
                style="-moz-appearance: textfield;"
                type="number"
                aria-roledescription="Number field"
                data-hs-input-number-input="true"
            />

            <!-- Bouton incrémentation -->
            <button
                type="button"
                @click="increment"
                :disabled="disabled"
                class="size-6 inline-flex justify-center items-center gap-x-2 text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-white dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                tabindex="-1"
                aria-label="Increase"
                data-hs-input-number-increment="true"
            >
                <svg
                    class="shrink-0 size-3.5"
                    xmlns="http://www.w3.org/2000/svg"
                    width="24"
                    height="24"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M5 12h14"></path>
                    <path d="M12 5v14"></path>
                </svg>
            </button>
        </div>
    </div>
    <!-- End Input Number -->
</template>
