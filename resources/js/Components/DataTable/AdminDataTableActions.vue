<script setup>
defineProps({
    label: {
        type: String,
        default: 'Actions',
    },
    menuWidthClass: {
        type: String,
        default: 'w-36',
    },
});
</script>

<template>
    <div class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
        <button
            type="button"
            class="inline-flex size-7 items-center justify-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:bg-stone-50 focus:outline-none disabled:pointer-events-none disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
            aria-haspopup="menu"
            aria-expanded="false"
            :aria-label="label"
        >
            <svg class="size-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="1" />
                <circle cx="12" cy="5" r="1" />
                <circle cx="12" cy="19" r="1" />
            </svg>
        </button>

        <div
            class="hs-dropdown-menu hs-dropdown-open:opacity-100 hidden z-10 rounded-sm bg-white opacity-0 shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] transition-[opacity,margin] duration dark:bg-neutral-900 dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)]"
            :class="menuWidthClass"
            role="menu"
            aria-orientation="vertical"
        >
            <div class="p-1">
                <slot />
            </div>
        </div>
    </div>
</template>
