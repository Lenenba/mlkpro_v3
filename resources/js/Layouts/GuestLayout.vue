<script setup>
import CompanyBrandLogo from '@/Components/CompanyBrandLogo.vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import ValidationSummary from '@/Components/ValidationSummary.vue';
import FlashToaster from '@/Components/UI/FlashToaster.vue';
import CookieBanner from '@/Components/UI/CookieBanner.vue';
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

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
});

const page = usePage();
const { t } = useI18n();
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
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-stone-50 pt-6 text-stone-900 sm:justify-center sm:pt-0 dark:bg-neutral-950 dark:text-neutral-100"
    >
        <FlashToaster />
        <CookieBanner />
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

        <p
            v-if="showTenantAttribution"
            class="mt-4 text-center text-xs text-stone-500 dark:text-neutral-400"
        >
            {{ t('account.branding.powered_by') }}
        </p>
    </div>
</template>
