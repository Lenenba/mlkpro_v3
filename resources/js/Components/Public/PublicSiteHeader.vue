<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import MegaMenuDisplay from '@/Components/MegaMenu/MegaMenuDisplay.vue';
import LocaleFlag from '@/Components/UI/LocaleFlag.vue';
import { Link, router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    megaMenu: {
        type: Object,
        default: () => ({}),
    },
    fallbackItems: {
        type: Array,
        default: () => [],
    },
    canLogin: {
        type: Boolean,
        default: true,
    },
    canRegister: {
        type: Boolean,
        default: true,
    },
    isAuthenticated: {
        type: Boolean,
        default: false,
    },
    availableCurrencies: {
        type: Array,
        default: () => [],
    },
    selectedCurrencyCode: {
        type: String,
        default: null,
    },
    currencyRouteName: {
        type: String,
        default: null,
    },
    currencyQuery: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const langMenuOpen = ref(false);
const langMenuRef = ref(null);
const currencyMenuOpen = ref(false);
const currencyMenuRef = ref(null);
const headerRef = ref(null);
const isScrolled = ref(false);
const headerHeight = ref(null);
let headerResizeObserver = null;

const safeRoute = (name, fallback = '#') => {
    try {
        return route(name);
    } catch (error) {
        return fallback;
    }
};

const currentLocale = computed(() => String(page.props.locale || 'fr').toLowerCase());
const availableLocales = computed(() => (
    Array.isArray(page.props.locales) && page.props.locales.length
        ? page.props.locales
        : ['fr', 'en', 'es']
));
const availableCurrencies = computed(() => {
    const currencies = Array.isArray(props.availableCurrencies) ? props.availableCurrencies : [];

    return Array.from(new Set(
        currencies
            .map((currency) => String(currency || '').trim().toUpperCase())
            .filter((currency) => currency !== '')
    ));
});
const currentCurrencyCode = computed(() => {
    const selected = String(props.selectedCurrencyCode || '').trim().toUpperCase();

    if (selected !== '') {
        return selected;
    }

    return availableCurrencies.value[0] || '';
});
const showCurrencySwitcher = computed(() =>
    Boolean(props.currencyRouteName && currentCurrencyCode.value && availableCurrencies.value.length > 1)
);

const showLogin = computed(() => !props.isAuthenticated && props.canLogin);
const showRegister = computed(() => !props.isAuthenticated && props.canRegister);

const setLocale = (locale) => {
    if (!locale || locale === currentLocale.value) {
        return;
    }

    langMenuOpen.value = false;
    router.post(route('locale.update'), { locale }, { preserveScroll: true });
};

const toggleLangMenu = () => {
    currencyMenuOpen.value = false;
    langMenuOpen.value = !langMenuOpen.value;
};

const closeLangMenu = () => {
    langMenuOpen.value = false;
};

const setCurrency = (currency) => {
    const normalizedCurrency = String(currency || '').trim().toUpperCase();

    if (!normalizedCurrency || normalizedCurrency === currentCurrencyCode.value || !props.currencyRouteName) {
        return;
    }

    currencyMenuOpen.value = false;

    const query = Object.fromEntries(
        Object.entries({
            ...props.currencyQuery,
            currency: normalizedCurrency,
        }).filter(([, value]) => value !== null && value !== undefined && value !== '')
    );

    router.get(route(props.currencyRouteName), query, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

const toggleCurrencyMenu = () => {
    langMenuOpen.value = false;
    currencyMenuOpen.value = !currencyMenuOpen.value;
};

const closeCurrencyMenu = () => {
    currencyMenuOpen.value = false;
};

const handleOutsideClick = (event) => {
    if (langMenuRef.value && !langMenuRef.value.contains(event.target)) {
        langMenuOpen.value = false;
    }

    if (currencyMenuRef.value && !currencyMenuRef.value.contains(event.target)) {
        currencyMenuOpen.value = false;
    }
};

const syncScrollState = () => {
    if (typeof window === 'undefined') {
        return;
    }

    isScrolled.value = window.scrollY > 12;
};

const syncHeaderHeight = () => {
    if (!headerRef.value) {
        return;
    }

    const nextHeight = Math.max(Math.round(headerRef.value.getBoundingClientRect().height), 0);
    const resolvedHeight = nextHeight ? `${nextHeight}px` : '5.75rem';
    headerHeight.value = resolvedHeight;

    if (typeof document !== 'undefined') {
        document.documentElement.style.setProperty('--public-site-header-height', resolvedHeight);
    }
};

const headerShellStyle = computed(() => ({
    '--public-site-header-height': headerHeight.value || '5.75rem',
}));

onMounted(() => {
    document.addEventListener('click', handleOutsideClick);
    window.addEventListener('scroll', syncScrollState, { passive: true });
    syncScrollState();
    syncHeaderHeight();

    if (typeof ResizeObserver !== 'undefined' && headerRef.value) {
        headerResizeObserver = new ResizeObserver(() => {
            syncHeaderHeight();
        });
        headerResizeObserver.observe(headerRef.value);
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleOutsideClick);
    window.removeEventListener('scroll', syncScrollState);

    if (headerResizeObserver) {
        headerResizeObserver.disconnect();
        headerResizeObserver = null;
    }

    if (typeof document !== 'undefined') {
        document.documentElement.style.removeProperty('--public-site-header-height');
    }
});
</script>

<template>
    <div class="public-site-header-shell" :style="headerShellStyle">
        <header ref="headerRef" class="public-site-header" :class="{ 'is-scrolled': isScrolled }">
            <div class="public-site-header__inner">
                <Link :href="safeRoute('welcome', '/')" class="public-site-header__brand">
                    <ApplicationLogo class="h-10 w-36 sm:h-11 sm:w-40" />
                </Link>

                <div class="public-site-header__menu">
                    <MegaMenuDisplay :menu="megaMenu" :fallback-items="fallbackItems">
                        <template #mobile-footer="{ closeMobileMenu }">
                            <div v-if="showLogin || showRegister" class="public-site-header__mobile-auth">
                                <Link
                                    v-if="showLogin"
                                    :href="safeRoute('login', '/login')"
                                    class="public-site-header__mobile-auth-link public-site-header__mobile-auth-link--secondary"
                                    @click="closeMobileMenu"
                                >
                                    {{ $t('legal.actions.sign_in') }}
                                </Link>

                                <Link
                                    v-if="showRegister"
                                    :href="safeRoute('onboarding.index', '/onboarding')"
                                    class="public-site-header__mobile-auth-link public-site-header__mobile-auth-link--primary"
                                    @click="closeMobileMenu"
                                >
                                    {{ $t('legal.actions.create_account') }}
                                </Link>
                            </div>
                        </template>
                    </MegaMenuDisplay>
                </div>

                <div class="public-site-header__actions">
                    <Link
                        v-if="showLogin"
                        :href="safeRoute('login', '/login')"
                        class="public-site-header__button public-site-header__button--secondary"
                    >
                        {{ $t('legal.actions.sign_in') }}
                    </Link>

                    <Link
                        v-if="showRegister"
                        :href="safeRoute('onboarding.index', '/onboarding')"
                        class="public-site-header__button public-site-header__button--primary"
                    >
                        {{ $t('legal.actions.create_account') }}
                    </Link>

                    <div
                        v-if="showCurrencySwitcher"
                        ref="currencyMenuRef"
                        class="public-site-header__locale"
                    >
                        <button
                            type="button"
                            class="public-site-header__locale-toggle"
                            aria-haspopup="listbox"
                            :aria-label="$t('pricing.currency.label')"
                            :aria-expanded="currencyMenuOpen"
                            @click="toggleCurrencyMenu"
                            @keydown.escape="closeCurrencyMenu"
                        >
                            <span>{{ currentCurrencyCode }}</span>
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="16"
                                height="16"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="public-site-header__locale-chevron"
                            >
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>

                        <div
                            v-if="currencyMenuOpen"
                            class="public-site-header__locale-menu"
                            role="listbox"
                            :aria-activedescendant="`currency-${currentCurrencyCode}`"
                            @keydown.escape="closeCurrencyMenu"
                        >
                            <button
                                v-for="currencyCode in availableCurrencies"
                                :id="`currency-${currencyCode}`"
                                :key="currencyCode"
                                type="button"
                                role="option"
                                class="public-site-header__locale-item"
                                :class="{ 'is-active': currentCurrencyCode === currencyCode }"
                                :aria-selected="currentCurrencyCode === currencyCode"
                                @click="setCurrency(currencyCode)"
                            >
                                {{ currencyCode }}
                            </button>
                        </div>
                    </div>

                    <div ref="langMenuRef" class="public-site-header__locale">
                        <button
                            type="button"
                            class="public-site-header__locale-toggle"
                            aria-haspopup="listbox"
                            :aria-label="$t('account.language')"
                            :aria-expanded="langMenuOpen"
                            @click="toggleLangMenu"
                            @keydown.escape="closeLangMenu"
                        >
                            <LocaleFlag :locale="currentLocale" class="size-5 rounded-[3px] shadow-sm ring-1 ring-black/10" />
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="16"
                                height="16"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="public-site-header__locale-chevron"
                            >
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>

                        <div
                            v-if="langMenuOpen"
                            class="public-site-header__locale-menu"
                            role="listbox"
                            :aria-activedescendant="`lang-${currentLocale}`"
                            @keydown.escape="closeLangMenu"
                        >
                            <button
                                v-for="locale in availableLocales"
                                :id="`lang-${locale}`"
                                :key="locale"
                                type="button"
                                role="option"
                                class="public-site-header__locale-item"
                                :class="{ 'is-active': currentLocale === locale }"
                                :aria-selected="currentLocale === locale"
                                @click="setLocale(locale)"
                            >
                                <LocaleFlag :locale="locale" class="size-5 rounded-[3px] shadow-sm ring-1 ring-black/10" />
                                <span>{{ $t(`language.${locale}`) }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        <div class="public-site-header__spacer" aria-hidden="true"></div>
    </div>
</template>

<style scoped>
.public-site-header-shell {
    --public-site-header-height: 5.75rem;
}

.public-site-header {
    position: fixed;
    top: 0;
    right: 0;
    left: 0;
    z-index: 60;
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid #e2e8f0;
    transition: background-color 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}

.public-site-header.is-scrolled {
    background: rgba(255, 255, 255, 0.96);
    box-shadow: 0 18px 35px -30px rgba(15, 23, 42, 0.45);
}

.public-site-header__inner {
    width: min(var(--public-shell-width, 88rem), 100%);
    margin-inline: auto;
    padding: 1.25rem var(--public-shell-gutter, 1.25rem);
    display: flex;
    align-items: center;
    gap: 1.25rem;
    transition: padding 0.2s ease, gap 0.2s ease;
}

.public-site-header.is-scrolled .public-site-header__inner {
    padding-top: 0.9rem;
    padding-bottom: 0.9rem;
}

.public-site-header__spacer {
    height: var(--public-site-header-height);
}

.public-site-header__brand {
    display: flex;
    flex-shrink: 0;
    align-items: center;
}

.public-site-header__brand :deep(img) {
    object-position: left center;
}

.public-site-header__menu {
    min-width: 0;
    flex: 1 1 auto;
}

.public-site-header__actions {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    gap: 0.75rem;
}

.public-site-header__button {
    display: none;
    border-radius: 0.125rem;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease;
}

.public-site-header__button--secondary {
    border: 1px solid #e7e5e4;
    background: #ffffff;
    color: #292524;
}

.public-site-header__button--secondary:hover {
    background: #fafaf9;
}

.public-site-header__button--primary {
    border: 1px solid transparent;
    background: #16a34a;
    color: #ffffff;
}

.public-site-header__button--primary:hover {
    background: #15803d;
}

.public-site-header__mobile-auth {
    display: grid;
    gap: 0.75rem;
}

.public-site-header__mobile-auth-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 3rem;
    border-radius: 0.125rem;
    padding: 0.8rem 1rem;
    font-size: 0.95rem;
    font-weight: 700;
    text-decoration: none;
    transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
}

.public-site-header__mobile-auth-link--secondary {
    border: 1px solid #d6d3d1;
    background: #ffffff;
    color: #292524;
    box-shadow: 0 12px 24px -20px rgba(15, 23, 42, 0.4);
}

.public-site-header__mobile-auth-link--secondary:hover {
    background: #fafaf9;
}

.public-site-header__mobile-auth-link--primary {
    border: 1px solid transparent;
    background: #16a34a;
    color: #ffffff;
    box-shadow: 0 18px 32px -24px rgba(22, 163, 74, 0.65);
}

.public-site-header__mobile-auth-link--primary:hover {
    background: #15803d;
}

.public-site-header__locale {
    position: relative;
}

.public-site-header__locale-toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    padding: 0.5rem 1rem;
    border-radius: 0.125rem;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    font-size: 0.85rem;
    font-weight: 600;
    color: #0f172a;
    box-shadow: 0 12px 24px -20px rgba(15, 23, 42, 0.5);
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.public-site-header__locale-toggle:hover {
    border-color: #16a34a;
    box-shadow: 0 16px 30px -22px rgba(15, 23, 42, 0.6);
}

.public-site-header__locale-toggle:focus-visible {
    outline: 2px solid rgba(16, 185, 129, 0.5);
    outline-offset: 2px;
}

.public-site-header__locale-chevron {
    color: #0f172a;
}

.public-site-header__locale-menu {
    position: absolute;
    right: 0;
    top: calc(100% + 0.5rem);
    min-width: 10.5rem;
    padding: 0.4rem;
    border-radius: 0.125rem;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    box-shadow: 0 16px 36px -24px rgba(15, 23, 42, 0.6);
    z-index: 50;
}

.public-site-header__locale-item {
    width: 100%;
    display: inline-flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.45rem 0.75rem;
    border-radius: 0.125rem;
    text-align: left;
    font-size: 0.85rem;
    font-weight: 500;
    color: #0f172a;
    transition: background 0.2s ease, color 0.2s ease;
}

.public-site-header__locale-item:hover {
    background: #f1f5f9;
}

.public-site-header__locale-item.is-active {
    background: #16a34a;
    color: #ffffff;
}

@media (min-width: 768px) {
    .public-site-header__button--secondary {
        display: inline-flex;
    }
}

@media (max-width: 1023.98px) {
    .public-site-header__inner {
        flex-wrap: wrap;
        justify-content: space-between;
        gap: 0.85rem;
        padding-top: 1rem;
        padding-bottom: 1rem;
    }

    .public-site-header.is-scrolled .public-site-header__inner {
        padding-top: 0.75rem;
        padding-bottom: 0.75rem;
    }

    .public-site-header__brand {
        min-width: 0;
        flex: 1 1 auto;
    }

    .public-site-header__menu {
        order: 3;
        flex: 0 0 100%;
        width: 100%;
        display: flex;
        justify-content: center;
    }

    .public-site-header__actions {
        margin-left: auto;
        gap: 0.5rem;
    }

    .public-site-header__locale-toggle {
        padding: 0.45rem 0.8rem;
    }
}

@media (max-width: 639.98px) {
    .public-site-header__inner {
        padding-left: 1rem;
        padding-right: 1rem;
    }

    .public-site-header__actions {
        gap: 0.4rem;
    }

    .public-site-header__locale-toggle {
        gap: 0.4rem;
        padding: 0.45rem 0.7rem;
    }

    .public-site-header__mobile-auth-link {
        width: 100%;
    }
}

@media (min-width: 1280px) {
    .public-site-header__button--primary {
        display: inline-flex;
    }
}

</style>
