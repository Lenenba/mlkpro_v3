<template>
    <!-- Card -->
    <div
        :class="['p-1 bg-white border border-stone-200 rounded-sm dark:bg-neutral-900 dark:border-neutral-700', borderColor]"
    >
        <slot name="image" />
        <div class="p-4 space-y-5">
            <div class="mt-5">
                <button
                    type="button"
                    class="py-2 px-3 w-full inline-flex border-t justify-center items-center gap-x-2 text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                    data-hs-overlay="#hs-pro-chhdl"
                >
                    <slot />
                </button>
            </div>
        </div>
    </div>
    <!-- End Card -->
</template>

<script setup>
const props = defineProps({
    borderColor: {
        type: String,
        default: "green", // Couleur par défaut
    },
});
</script>
