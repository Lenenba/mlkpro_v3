<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    source: {
        type: String,
        required: true,
    },
    query: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const { t } = useI18n();

const providers = computed(() => {
    const available = page.props.socialAuth?.providers || [];

    return available.filter((provider) => provider?.ready && provider?.contexts?.[props.source]);
});

const routeParams = (provider) => {
    const query = Object.fromEntries(
        Object.entries(props.query || {}).filter(([, value]) => value !== null && value !== '')
    );

    return {
        provider: provider.key,
        source: props.source,
        ...query,
    };
};

const providerButtonClass = (provider) => {
    const key = String(provider?.key || '').toLowerCase();

    if (key === 'google') {
        return 'border-stone-200 bg-white text-stone-700 hover:border-stone-300 hover:bg-stone-50';
    }

    if (key === 'microsoft') {
        return 'border-stone-200 bg-white text-stone-700 hover:border-stone-300 hover:bg-stone-50';
    }

    if (key === 'facebook') {
        return 'border-blue-200 bg-blue-50 text-blue-700 hover:border-blue-300 hover:bg-blue-100';
    }

    return 'border-stone-200 bg-white text-stone-700 hover:border-stone-300 hover:bg-stone-50';
};
</script>

<template>
    <div v-if="providers.length" class="mb-6 flex flex-col items-center space-y-3">
        <div class="flex flex-wrap items-center justify-center gap-3">
            <a
                v-for="provider in providers"
                :key="provider.key"
                :href="route('auth.social.redirect', routeParams(provider))"
                :title="t('auth_pages.social.continue_with', { provider: provider.label })"
                :aria-label="t('auth_pages.social.continue_with', { provider: provider.label })"
                class="group inline-flex h-12 w-12 items-center justify-center rounded-full border shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-stone-300 focus:ring-offset-2 focus:ring-offset-white dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:ring-neutral-500 dark:focus:ring-offset-neutral-950"
                :class="providerButtonClass(provider)"
            >
                <span class="sr-only">
                    {{ t('auth_pages.social.continue_with', { provider: provider.label }) }}
                </span>

                <svg
                    v-if="provider.key === 'google'"
                    aria-hidden="true"
                    viewBox="0 0 24 24"
                    class="h-5 w-5"
                >
                    <path
                        fill="#4285F4"
                        d="M23.49 12.27c0-.79-.07-1.54-.2-2.27H12v4.3h6.44a5.51 5.51 0 0 1-2.39 3.61l3.67 2.85c2.15-1.98 3.77-4.89 3.77-8.49Z"
                    />
                    <path
                        fill="#34A853"
                        d="M12 24c3.24 0 5.96-1.07 7.95-2.91l-3.67-2.85c-1.02.68-2.33 1.08-4.28 1.08-3.29 0-6.08-2.22-7.08-5.2l-3.8 2.93A12 12 0 0 0 12 24Z"
                    />
                    <path
                        fill="#FBBC05"
                        d="M4.92 14.12A7.18 7.18 0 0 1 4.52 12c0-.74.14-1.45.4-2.12L1.12 6.95A12 12 0 0 0 0 12c0 1.93.46 3.76 1.12 5.05l3.8-2.93Z"
                    />
                    <path
                        fill="#EA4335"
                        d="M12 4.77c1.77 0 3.35.61 4.6 1.8l3.45-3.45C17.95 1.16 15.23 0 12 0A12 12 0 0 0 1.12 6.95l3.8 2.93c1-2.98 3.79-5.11 7.08-5.11Z"
                    />
                </svg>

                <svg
                    v-else-if="provider.key === 'microsoft'"
                    aria-hidden="true"
                    viewBox="0 0 24 24"
                    class="h-5 w-5"
                >
                    <rect x="2" y="2" width="9" height="9" fill="#F25022" />
                    <rect x="13" y="2" width="9" height="9" fill="#7FBA00" />
                    <rect x="2" y="13" width="9" height="9" fill="#00A4EF" />
                    <rect x="13" y="13" width="9" height="9" fill="#FFB900" />
                </svg>

                <svg
                    v-else-if="provider.key === 'facebook'"
                    aria-hidden="true"
                    viewBox="0 0 24 24"
                    class="h-5 w-5"
                >
                    <circle cx="12" cy="12" r="10" fill="#1877F2" />
                    <path
                        fill="#fff"
                        d="M13.12 19v-6.16h2.07l.31-2.4h-2.38V8.92c0-.7.19-1.17 1.19-1.17H15.5V5.6c-.2-.03-.9-.08-1.72-.08-1.7 0-2.86 1.04-2.86 2.96v1.96H9v2.4h1.92V19h2.2Z"
                    />
                </svg>

                <span
                    v-else
                    aria-hidden="true"
                    class="text-sm font-semibold uppercase tracking-wide"
                >
                    {{ String(provider.label || '?').slice(0, 1) }}
                </span>
            </a>
        </div>

        <div class="flex w-full max-w-xs items-center gap-3 text-xs uppercase tracking-wide text-stone-400 dark:text-neutral-500">
            <span class="h-px flex-1 bg-stone-200 dark:bg-neutral-700" />
            <span>{{ t('auth_pages.social.separator') }}</span>
            <span class="h-px flex-1 bg-stone-200 dark:bg-neutral-700" />
        </div>
    </div>
</template>
