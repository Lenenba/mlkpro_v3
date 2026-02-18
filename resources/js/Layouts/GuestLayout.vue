<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import ValidationSummary from '@/Components/ValidationSummary.vue';
import FlashToaster from '@/Components/UI/FlashToaster.vue';
import CookieBanner from '@/Components/UI/CookieBanner.vue';
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    cardClass: {
        type: String,
        default: 'mt-6 w-full overflow-hidden rounded-sm border border-stone-200 bg-white px-6 py-4 shadow-md sm:max-w-md dark:border-neutral-700 dark:bg-neutral-900',
    },
    logoUrl: {
        type: String,
        default: '',
    },
    logoAlt: {
        type: String,
        default: '',
    },
    logoHref: {
        type: String,
        default: '/',
    },
    showPlatformLogo: {
        type: Boolean,
        default: true,
    },
});

const page = usePage();
const validationErrors = computed(() => page.props.errors || {});
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-stone-50 pt-6 text-stone-900 sm:justify-center sm:pt-0 dark:bg-neutral-950 dark:text-neutral-100"
    >
        <FlashToaster />
        <CookieBanner />
        <div v-if="props.logoUrl || props.showPlatformLogo">
            <component :is="props.logoHref ? Link : 'div'" v-bind="props.logoHref ? { href: props.logoHref } : {}">
                <img
                    v-if="props.logoUrl"
                    :src="props.logoUrl"
                    :alt="props.logoAlt || 'Company logo'"
                    class="h-14 w-auto object-contain sm:h-16"
                />
                <ApplicationLogo v-else-if="props.showPlatformLogo" class="h-14 w-44 sm:h-16 sm:w-52" />
            </component>
        </div>

        <div :class="props.cardClass">
            <ValidationSummary :errors="validationErrors" />
            <slot />
        </div>
    </div>
</template>
