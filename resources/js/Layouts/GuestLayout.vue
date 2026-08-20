<script setup>
import CompanyBrandLogo from '@/Components/CompanyBrandLogo.vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import ValidationSummary from '@/Components/ValidationSummary.vue';
import FlashToaster from '@/Components/UI/FlashToaster.vue';
import CookieBanner from '@/Components/UI/CookieBanner.vue';
import AppFooter from '@/Components/UI/AppFooter.vue';
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    cardClass: {
        type: String,
        default: 'mt-6 w-full overflow-hidden rounded-sm border border-stone-200 bg-white px-6 py-4 shadow-md sm:max-w-md dark:border-neutral-700 dark:bg-neutral-900',
    },
    company: {
        type: Object,
        default: null,
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
        default: null,
    },
    showPlatformLogo: {
        type: Boolean,
        default: true,
    },
    showPoweredBy: {
        type: Boolean,
        default: true,
    },
    showFooter: {
        type: Boolean,
        default: true,
    },
    footerFloatingActionReserve: {
        type: String,
        default: 'none',
        validator: (value) => ['none', 'compact', 'wide'].includes(value),
    },
});

const page = usePage();
const validationErrors = computed(() => page.props.errors || {});
const tenantCompany = computed(() => {
    if (props.company) {
        return props.company;
    }

    if (props.logoUrl) {
        return {
            name: props.logoAlt,
            logo_url: props.logoUrl,
        };
    }

    return null;
});
const resolvedLogoHref = computed(() => {
    if (props.logoHref !== null) {
        return props.logoHref;
    }

    return tenantCompany.value ? '' : '/';
});
const showTenantAttribution = computed(() => Boolean(tenantCompany.value) && props.showPoweredBy);
const shouldShowFooter = computed(() => (
    props.showFooter
    && (!tenantCompany.value || props.showPoweredBy)
));
</script>

<template>
    <div class="flex min-h-screen flex-col bg-stone-50 text-stone-900 dark:bg-neutral-950 dark:text-neutral-100">
        <FlashToaster />
        <CookieBanner />
        <div class="flex w-full flex-1 flex-col items-center justify-center pt-6 sm:pt-0">
            <CompanyBrandLogo
                v-if="tenantCompany"
                :company="tenantCompany"
                :href="resolvedLogoHref"
                :link-label="tenantCompany?.name || 'Malikia Pro'"
                container-class="h-16 w-52 p-2 sm:h-[4.5rem] sm:w-60"
            />
            <component
                :is="resolvedLogoHref ? Link : 'div'"
                v-else-if="props.showPlatformLogo"
                v-bind="resolvedLogoHref ? { href: resolvedLogoHref } : {}"
                :aria-label="resolvedLogoHref ? 'Malikia Pro' : undefined"
            >
                <ApplicationLogo class="h-14 w-44 sm:h-16 sm:w-52" />
            </component>

            <div :class="props.cardClass">
                <ValidationSummary :errors="validationErrors" />
                <slot />
            </div>
        </div>

        <div v-if="shouldShowFooter" class="w-full px-2 pb-3 pt-5 sm:px-5 sm:pb-5">
            <AppFooter
                class="mx-auto w-full max-w-6xl"
                :floating-action-reserve="props.footerFloatingActionReserve"
                :variant="showTenantAttribution ? 'powered-by' : 'platform'"
            />
        </div>
    </div>
</template>
