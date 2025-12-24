<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    cardClass: {
        type: String,
        default: 'mt-6 w-full overflow-hidden rounded-sm border border-stone-200 bg-white px-6 py-4 shadow-md sm:max-w-md dark:border-neutral-700 dark:bg-neutral-900',
    },
});
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-stone-50 pt-6 text-stone-900 sm:justify-center sm:pt-0 dark:bg-neutral-950 dark:text-neutral-100"
    >
        <div>
            <Link href="/">
                <ApplicationLogo class="h-20 w-20 fill-current text-stone-500 dark:text-neutral-400" />
            </Link>
        </div>

        <div :class="props.cardClass">
            <slot />
        </div>
    </div>
</template>
