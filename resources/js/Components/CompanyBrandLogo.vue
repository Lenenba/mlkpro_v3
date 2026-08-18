<script setup>
import { computed, ref, useAttrs, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import {
    resolveCompanyBrand,
    resolveCompanyBrandAccessibleLabel,
} from '@/utils/companyBranding';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    company: {
        type: Object,
        default: null,
    },
    name: {
        type: String,
        default: '',
    },
    logoUrl: {
        type: String,
        default: '',
    },
    hasCustomLogo: {
        type: Boolean,
        default: undefined,
    },
    showFallbackName: {
        type: Boolean,
        default: true,
    },
    href: {
        type: String,
        default: '',
    },
    linkLabel: {
        type: String,
        default: '',
    },
    containerClass: {
        type: String,
        default: 'h-16 w-52 p-2',
    },
    logoClass: {
        type: String,
        default: 'h-full w-full object-contain',
    },
    loading: {
        type: String,
        default: 'eager',
        validator: (value) => ['eager', 'lazy'].includes(value),
    },
});

const attrs = useAttrs();
const imageFailed = ref(false);

const companyPayload = computed(() => {
    const company = { ...(props.company || {}) };

    if (props.name) {
        company.name = props.name;
    }

    if (props.logoUrl) {
        company.custom_logo_url = props.logoUrl;
    }

    if (props.hasCustomLogo !== undefined) {
        company.has_custom_logo = props.hasCustomLogo;
    }

    return company;
});

const brand = computed(() => resolveCompanyBrand(companyPayload.value) || { name: '', logoUrl: '' });
const showCompanyLogo = computed(() => Boolean(brand.value.logoUrl) && !imageFailed.value);
const showFallbackTenantName = computed(() => props.showFallbackName && Boolean(brand.value.name));
const accessibleLabel = computed(() => resolveCompanyBrandAccessibleLabel(
    companyPayload.value,
    {
        fallback: !showCompanyLogo.value,
        linkLabel: props.linkLabel,
    },
));
const fallbackLogoClass = computed(() => (
    showFallbackTenantName.value
        ? 'h-5 w-full max-w-28 shrink-0'
        : props.logoClass
));
const rootClass = computed(() => [
    'relative isolate inline-flex items-center justify-center overflow-hidden rounded-sm border border-stone-200 shadow-sm dark:border-neutral-700',
    showCompanyLogo.value ? 'company-brand-logo--custom' : 'bg-white dark:bg-white',
    props.containerClass,
    attrs.class,
]);
const passthroughAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;

    return rest;
});

watch(
    () => brand.value.logoUrl,
    () => {
        imageFailed.value = false;
    }
);
</script>

<template>
    <component
        :is="props.href ? Link : 'div'"
        v-bind="passthroughAttrs"
        :href="props.href || undefined"
        :role="!props.href && !showCompanyLogo ? 'img' : undefined"
        :aria-label="props.href || !showCompanyLogo ? accessibleLabel : undefined"
        :class="rootClass"
    >
        <img
            v-if="showCompanyLogo"
            :src="brand.logoUrl"
            :alt="brand.name || 'Company'"
            :class="['company-brand-logo__image', props.logoClass]"
            :loading="props.loading"
            decoding="async"
            @error="imageFailed = true"
        />
        <span
            v-else
            class="flex h-full w-full min-w-0 flex-col items-center justify-center gap-1"
            aria-hidden="true"
        >
            <span
                v-if="showFallbackTenantName"
                class="max-w-full truncate px-1 text-xs font-bold leading-tight text-stone-800"
                :title="brand.name"
            >
                {{ brand.name }}
            </span>
            <ApplicationLogo
                :class="fallbackLogoClass"
                color-scheme="light"
            />
        </span>
    </component>
</template>

<style scoped>
.company-brand-logo--custom {
    background-color: #f5f5f4;
    background-image: linear-gradient(135deg, rgb(255 255 255 / 95%), rgb(168 162 158 / 42%));
}

.company-brand-logo__image {
    filter: drop-shadow(0 1px 1px rgb(0 0 0 / 50%)) drop-shadow(0 0 1px rgb(255 255 255 / 85%));
}
</style>
