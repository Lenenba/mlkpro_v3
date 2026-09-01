<script setup>
import AppFooter from '@/Components/UI/AppFooter.vue';
import CookieBanner from '@/Components/UI/CookieBanner.vue';
</script>

<template>
    <div class="flex min-h-screen flex-col bg-stone-50 text-stone-900">
        <div class="flex min-h-0 flex-1 flex-col">
            <slot />
        </div>

        <div class="w-full px-2 pb-3 pt-4 sm:px-5 sm:pb-5 sm:pt-5">
            <AppFooter
                class="mx-auto w-full max-w-[1680px]"
                variant="powered-by"
            />
        </div>

        <CookieBanner />
    </div>
</template>
