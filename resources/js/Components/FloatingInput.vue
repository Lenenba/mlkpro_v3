<script setup>
import { ref, computed, onMounted } from 'vue';

// Définir les props et les événements
const props = defineProps({
    modelValue: {
        type: String,
        required: true, // La valeur liée est obligatoire
    },
    label: {
        type: String,
        required: true, // Le label est obligatoire
    },
});

const emit = defineEmits(['update:modelValue']); // Émettre l'événement pour synchroniser les données avec le parent

const input = ref(null);

// Propriété calculée pour lier `modelValue` à une valeur locale
const value = computed({
    get: () => props.modelValue, // Lecture de la prop
    set: (newValue) => {
        emit('update:modelValue', newValue); // Émettre l'événement pour mettre à jour le parent
    },
});

// Gestion du focus automatique
onMounted(() => {
    if (input.value && input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

// Exposer une méthode publique pour forcer le focus
defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <div class="relative">
        <input
            v-model="value"
            ref="input"
            id="floating-input"
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
            for="floating-input"
            class="absolute top-0 left-0 p-4 h-full text-sm truncate pointer-events-none transition ease-in-out duration-100 origin-[0_0] dark:text-white peer-disabled:opacity-50 peer-disabled:pointer-events-none
                scale-90
                translate-x-0.5
                -translate-y-1.5
                text-gray-500 dark:peer-focus:text-neutral-500
                peer-[not(:placeholder-shown)]:scale-90
                peer-[not(:placeholder-shown)]:translate-x-0.5
                peer-[not(:placeholder-shown)]:-translate-y-1.5
                peer-[not(:placeholder-shown)]:text-gray-500 dark:peer-[not(:placeholder-shown)]:text-neutral-500 dark:text-neutral-500">
            {{ label }}
        </label>
    </div>
</template>
