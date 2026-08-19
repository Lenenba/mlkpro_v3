<script setup>
const model = defineModel({
    type: Boolean,
    default: false,
});

defineProps({
    id: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
    describedBy: {
        type: String,
        default: '',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <label
        :for="id"
        class="flex items-start justify-between gap-4 rounded-lg border border-stone-200 bg-white px-3 py-3 transition-colors dark:border-neutral-700 dark:bg-neutral-900"
        :class="disabled
            ? 'cursor-not-allowed opacity-60'
            : 'cursor-pointer hover:border-stone-300 hover:bg-stone-50 dark:hover:border-neutral-600 dark:hover:bg-neutral-800/70'"
    >
        <span class="min-w-0 grow">
            <span
                :id="`${id}-label`"
                class="block text-sm font-medium text-stone-800 dark:text-neutral-200"
            >
                {{ label }}
            </span>
            <span
                v-if="description"
                :id="`${id}-description`"
                class="mt-1 block text-xs leading-5 text-stone-500 dark:text-neutral-400"
            >
                {{ description }}
            </span>
        </span>

        <span class="relative mt-0.5 block h-6 w-11 shrink-0">
            <input
                :id="id"
                v-model="model"
                type="checkbox"
                role="switch"
                class="peer sr-only"
                :disabled="disabled"
                :aria-labelledby="`${id}-label`"
                :aria-describedby="[
                    description ? `${id}-description` : '',
                    describedBy,
                ].filter(Boolean).join(' ') || undefined"
            />
            <span
                aria-hidden="true"
                class="block h-6 w-11 rounded-full border border-stone-300 bg-stone-200 transition-colors peer-checked:border-green-600 peer-checked:bg-green-600 peer-focus-visible:outline-none peer-focus-visible:ring-2 peer-focus-visible:ring-green-500 peer-focus-visible:ring-offset-2 peer-disabled:bg-stone-100 dark:border-neutral-600 dark:bg-neutral-700 dark:peer-checked:border-green-500 dark:peer-checked:bg-green-500 dark:peer-focus-visible:ring-offset-neutral-900 dark:peer-disabled:bg-neutral-800"
            ></span>
            <span
                aria-hidden="true"
                class="pointer-events-none absolute start-0.5 top-0.5 size-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5 dark:bg-neutral-200"
            ></span>
        </span>
    </label>
</template>
