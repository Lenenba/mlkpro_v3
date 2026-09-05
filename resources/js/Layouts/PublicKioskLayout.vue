<script setup>
import PublicBrandBar from '@/Components/Public/PublicBrandBar.vue';
import AppFooter from '@/Components/UI/AppFooter.vue';
import CookieBanner from '@/Components/UI/CookieBanner.vue';

const props = defineProps({
    company: {
        type: Object,
        default: null,
    },
    logoHref: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <div class="flex min-h-screen min-w-0 flex-col overflow-x-hidden bg-stone-50 text-stone-900">
        <header class="w-full pt-4 sm:pt-5">
            <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
                <PublicBrandBar
                    :company="props.company"
                    :logo-href="props.logoHref"
                >
                    <slot name="brand-actions" />
                </PublicBrandBar>
            </div>
        </header>

        <div class="flex min-h-0 flex-1 flex-col">
            <slot />
        </div>

        <div class="mx-auto w-full min-w-0 max-w-7xl px-4 pb-3 pt-4 sm:px-6 sm:pb-5 sm:pt-5 lg:px-8">
            <AppFooter
                class="w-full !rounded-sm"
                variant="powered-by"
            />
        </div>

        <CookieBanner />
    </div>
</template>
