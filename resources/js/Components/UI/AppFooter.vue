<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'platform',
        validator: (value) => ['platform', 'powered-by'].includes(value),
    },
    floatingActionReserve: {
        type: String,
        default: 'none',
        validator: (value) => ['none', 'compact', 'wide'].includes(value),
    },
});

const page = usePage();
const { t } = useI18n();

const currentYear = new Date().getFullYear();
const isPlatformAdmin = computed(() => Boolean(
    page.props.auth?.account?.is_superadmin
    || page.props.auth?.account?.is_platform_admin
));
const isClient = computed(() => Boolean(page.props.auth?.account?.is_client));

const safeRoute = (name, fallback = '') => {
    try {
        if (typeof route !== 'function') {
            return fallback;
        }

        const router = route();
        if (typeof router?.has === 'function' && !router.has(name)) {
            return fallback;
        }

        return route(name);
    } catch {
        return fallback;
    }
};

const brandHref = computed(() => safeRoute('welcome', '/'));
const legalLinks = computed(() => ([
    {
        key: 'terms',
        label: t('account.branding.footer.terms'),
        href: safeRoute('terms', '/terms'),
    },
    {
        key: 'privacy',
        label: t('account.branding.footer.privacy'),
        href: safeRoute('privacy', '/privacy'),
    },
]));
const supportHref = computed(() => {
    if (!page.props.auth?.user || isClient.value) {
        return '';
    }

    return isPlatformAdmin.value
        ? safeRoute('superadmin.support.index')
        : safeRoute('settings.support.index');
});
const signature = computed(() => (
    props.variant === 'powered-by'
        ? t('account.branding.powered_by')
        : t('account.branding.footer.copyright', { year: currentYear })
));
const floatingActionClass = computed(() => ({
    compact: 'pe-14',
    wide: 'pe-32',
}[props.floatingActionReserve] || ''));

const openCookiePreferences = () => {
    if (typeof window === 'undefined') {
        return;
    }

    window.dispatchEvent(new CustomEvent('mlk-cookie-preferences'));
};
</script>

<template>
    <footer
        :aria-label="t('account.branding.footer.aria_label')"
        :data-variant="props.variant"
        data-testid="app-footer"
        class="relative overflow-hidden rounded-xl border border-stone-200 bg-white/95 px-4 py-3 shadow-sm backdrop-blur print:hidden dark:border-neutral-700 dark:bg-neutral-900/95"
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-5">
            <div class="flex min-w-0 items-center gap-3">
                <a
                    v-if="props.variant === 'platform'"
                    :href="brandHref"
                    aria-label="Malikia Pro"
                    class="inline-flex min-h-10 shrink-0 items-center rounded-md px-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-neutral-900"
                >
                    <ApplicationLogo class="h-7 w-24" />
                </a>
                <span
                    v-if="props.variant === 'platform'"
                    class="h-6 w-px shrink-0 bg-stone-200 dark:bg-neutral-700"
                    aria-hidden="true"
                />
                <component
                    :is="props.variant === 'powered-by' ? 'a' : 'p'"
                    v-bind="props.variant === 'powered-by' ? { href: brandHref } : {}"
                    class="min-w-0 text-xs leading-5 text-stone-500 dark:text-neutral-400"
                    :class="props.variant === 'powered-by'
                        ? 'inline-flex min-h-10 items-center rounded-sm px-1 font-medium transition hover:text-stone-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2 dark:hover:text-white dark:focus-visible:ring-offset-neutral-900'
                        : ''"
                >
                    {{ signature }}
                </component>
            </div>

            <nav
                :aria-label="t('account.branding.footer.navigation_aria_label')"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs font-medium text-stone-500 dark:text-neutral-400"
                :class="floatingActionClass"
            >
                <a
                    v-for="link in legalLinks"
                    :key="link.key"
                    :href="link.href"
                    :data-testid="`app-footer-${link.key}`"
                    class="inline-flex min-h-10 items-center rounded-sm px-1 py-1 transition hover:text-stone-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2 dark:hover:text-white dark:focus-visible:ring-offset-neutral-900"
                >
                    {{ link.label }}
                </a>
                <Link
                    v-if="supportHref"
                    :href="supportHref"
                    class="inline-flex min-h-10 items-center rounded-sm px-1 py-1 transition hover:text-stone-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2 dark:hover:text-white dark:focus-visible:ring-offset-neutral-900"
                >
                    {{ t('account.branding.footer.support') }}
                </Link>
                <button
                    type="button"
                    data-testid="app-footer-cookie-preferences"
                    class="inline-flex min-h-10 items-center rounded-sm px-1 py-1 text-left transition hover:text-stone-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2 dark:hover:text-white dark:focus-visible:ring-offset-neutral-900"
                    @click="openCookiePreferences"
                >
                    {{ t('account.branding.footer.cookie_preferences') }}
                </button>
            </nav>
        </div>
    </footer>
</template>
