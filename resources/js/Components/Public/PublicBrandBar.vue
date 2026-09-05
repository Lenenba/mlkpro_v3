<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import CompanyBrandLogo from '@/Components/CompanyBrandLogo.vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    company: {
        type: Object,
        default: null,
    },
    logoHref: {
        type: String,
        default: '',
    },
    showPlatformLogo: {
        type: Boolean,
        default: true,
    },
});
</script>

<template>
    <div
        class="public-brand-bar flex min-h-16 min-w-0 items-center justify-between gap-3 rounded-sm border border-stone-200 bg-white px-4 py-2 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
        data-testid="public-brand-bar"
    >
        <CompanyBrandLogo
            v-if="props.company"
            :company="props.company"
            :href="props.logoHref"
            :link-label="props.company?.name || 'Malikia Pro'"
            container-class="h-11 w-24 p-0 sm:h-12 sm:w-44"
            logo-class="h-full w-auto max-w-full object-contain object-left"
            class="min-w-0 shrink-0 justify-start"
        />
        <component
            :is="props.logoHref ? Link : 'div'"
            v-else-if="props.showPlatformLogo"
            v-bind="props.logoHref ? { href: props.logoHref } : {}"
            :aria-label="props.logoHref ? 'Malikia Pro' : undefined"
            class="min-w-0 shrink-0"
        >
            <ApplicationLogo class="h-10 w-32 sm:h-12 sm:w-40" />
        </component>

        <div class="ml-auto flex min-w-0 shrink-0 items-center justify-end gap-2">
            <slot />
        </div>
    </div>
</template>

<style scoped>
.public-brand-bar :deep(.company-brand-logo--custom) {
    justify-content: flex-start;
    border: 0;
    background-color: transparent;
    background-image: none;
    box-shadow: none;
}
</style>
