<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

defineProps({
    canLogin: {
        type: Boolean,
        default: true,
    },
    canRegister: {
        type: Boolean,
        default: true,
    },
});

const page = usePage();
const currentLocale = computed(() => page.props.locale || 'fr');
const availableLocales = computed(() => page.props.locales || ['fr', 'en']);
const langMenuOpen = ref(false);
const langMenuRef = ref(null);

const setLocale = (locale) => {
    if (locale === currentLocale.value) {
        return;
    }

    langMenuOpen.value = false;
    router.post(route('locale.update'), { locale }, { preserveScroll: true });
};

const toggleLangMenu = () => {
    langMenuOpen.value = !langMenuOpen.value;
};

const closeLangMenu = () => {
    langMenuOpen.value = false;
};

const handleLangOutsideClick = (event) => {
    if (!langMenuRef.value) {
        return;
    }

    if (!langMenuRef.value.contains(event.target)) {
        langMenuOpen.value = false;
    }
};

onMounted(() => {
    document.addEventListener('click', handleLangOutsideClick);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleLangOutsideClick);
});
</script>

<template>
    <Head :title="$t('welcome.meta.title')" />

    <div class="welcome-page text-stone-900 dark:text-neutral-100">
        <header class="welcome-header">
            <div class="welcome-container flex items-center justify-between py-6">
                <Link :href="route('welcome')" class="flex items-center gap-3">
                    <ApplicationLogo class="h-8 w-28 sm:h-10 sm:w-32" />
                    <div class="leading-tight">
                        <div class="text-sm font-semibold">MLK Pro</div>
                        <div class="text-xs text-stone-500">{{ $t('welcome.nav.tagline') }}</div>
                    </div>
                </Link>

                <div class="flex items-center gap-3">
                    <div ref="langMenuRef" class="welcome-lang">
                        <button
                            type="button"
                            class="welcome-lang__toggle"
                            aria-haspopup="listbox"
                            :aria-expanded="langMenuOpen"
                            @click="toggleLangMenu"
                            @keydown.escape="closeLangMenu"
                        >
                            <span>{{ $t('account.language') }}</span>
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
                                class="welcome-lang__chevron"
                            >
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                        <div
                            v-if="langMenuOpen"
                            class="welcome-lang__menu"
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
                                class="welcome-lang__item"
                                :class="currentLocale === locale ? 'is-active' : ''"
                                :aria-selected="currentLocale === locale"
                                @click="setLocale(locale)"
                            >
                                {{ $t(`language.${locale}`) }}
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <Link v-if="canLogin" :href="route('login')"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-800 hover:bg-stone-50">
                            {{ $t('welcome.hero.secondary_cta') }}
                        </Link>
                        <Link v-if="canRegister" :href="route('register')"
                            class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">
                            {{ $t('welcome.hero.primary_cta') }}
                        </Link>
                    </div>
                </div>
            </div>
        </header>

        <main>
            <section class="welcome-section welcome-hero">
                <div class="welcome-container">
                    <div class="grid grid-cols-1 items-center lg:grid-cols-2 welcome-split">
                        <div class="space-y-6">
                            <div class="welcome-kicker welcome-fade-up">
                                {{ $t('welcome.hero.eyebrow') }}
                            </div>
                            <h1 class="welcome-title text-4xl font-semibold tracking-tight sm:text-5xl welcome-fade-up" style="animation-delay: 0.1s;">
                                {{ $t('welcome.hero.title') }}
                            </h1>
                            <p class="text-base text-stone-600 sm:text-lg welcome-fade-up" style="animation-delay: 0.2s;">
                                {{ $t('welcome.hero.subtitle') }}
                            </p>

                            <div class="flex flex-wrap gap-3 welcome-fade-up" style="animation-delay: 0.3s;">
                                <Link v-if="canRegister" :href="route('register')"
                                    class="rounded-sm border border-transparent bg-green-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-green-700">
                                    {{ $t('welcome.hero.primary_cta') }}
                                </Link>
                                <Link v-if="canLogin" :href="route('login')"
                                    class="rounded-sm border border-stone-200 bg-white px-5 py-2.5 text-sm font-semibold text-stone-800 hover:bg-stone-50">
                                    {{ $t('welcome.hero.secondary_cta') }}
                                </Link>
                            </div>

                            <div class="grid gap-3 text-sm text-stone-700 sm:grid-cols-3 welcome-fade-up" style="animation-delay: 0.4s;">
                                <div class="rounded-sm border border-stone-200 bg-white/80 px-3 py-3">
                                    <div class="text-lg font-semibold text-stone-900">8</div>
                                    <div class="text-xs text-stone-500">{{ $t('welcome.hero.stats.one_label') }}</div>
                                </div>
                                <div class="rounded-sm border border-stone-200 bg-white/80 px-3 py-3">
                                    <div class="text-lg font-semibold text-stone-900">2</div>
                                    <div class="text-xs text-stone-500">{{ $t('welcome.hero.stats.two_label') }}</div>
                                </div>
                                <div class="rounded-sm border border-stone-200 bg-white/80 px-3 py-3">
                                    <div class="text-lg font-semibold text-stone-900">24/7</div>
                                    <div class="text-xs text-stone-500">{{ $t('welcome.hero.stats.three_label') }}</div>
                                </div>
                            </div>

                            <div class="grid gap-2 text-sm text-stone-600 welcome-fade-up" style="animation-delay: 0.5s;">
                                <div class="flex items-start gap-2">
                                    <span class="mt-1 size-1.5 rounded-full bg-green-600"></span>
                                    <span>{{ $t('welcome.hero.highlights.one') }}</span>
                                </div>
                                <div class="flex items-start gap-2">
                                    <span class="mt-1 size-1.5 rounded-full bg-green-600"></span>
                                    <span>{{ $t('welcome.hero.highlights.two') }}</span>
                                </div>
                                <div class="flex items-start gap-2">
                                    <span class="mt-1 size-1.5 rounded-full bg-green-600"></span>
                                    <span>{{ $t('welcome.hero.highlights.three') }}</span>
                                </div>
                            </div>

                            <p class="text-xs text-stone-500 welcome-fade-up" style="animation-delay: 0.6s;">
                                {{ $t('welcome.hero.note') }}
                            </p>
                        </div>

                        <div class="relative welcome-fade-in">
                            <div class="rounded-sm border border-stone-200 bg-white p-3 shadow-xl">
                                <img
                                    src="/images/landing/hero-dashboard.svg"
                                    :alt="$t('welcome.images.hero_alt')"
                                    class="h-auto w-full rounded-sm"
                                />
                            </div>
                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                <div class="rounded-sm border border-stone-200 bg-white/90 p-3 text-xs text-stone-600 shadow-sm">
                                    <div class="text-sm font-semibold text-stone-900">{{ $t('welcome.hero.preview.one') }}</div>
                                    <div>{{ $t('welcome.hero.preview.one_desc') }}</div>
                                </div>
                                <div class="rounded-sm border border-stone-200 bg-white/90 p-3 text-xs text-stone-600 shadow-sm">
                                    <div class="text-sm font-semibold text-stone-900">{{ $t('welcome.hero.preview.two') }}</div>
                                    <div>{{ $t('welcome.hero.preview.two_desc') }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="welcome-section welcome-trust">
                <div class="welcome-container">
                    <div class="flex flex-col gap-3 text-center">
                        <div class="text-sm font-semibold text-stone-700">
                            {{ $t('welcome.trust.title') }}
                        </div>
                        <div class="grid grid-cols-2 gap-3 text-xs text-stone-600 sm:grid-cols-3 lg:grid-cols-6">
                            <div class="welcome-pill">{{ $t('welcome.trust.items.one') }}</div>
                            <div class="welcome-pill">{{ $t('welcome.trust.items.two') }}</div>
                            <div class="welcome-pill">{{ $t('welcome.trust.items.three') }}</div>
                            <div class="welcome-pill">{{ $t('welcome.trust.items.four') }}</div>
                            <div class="welcome-pill">{{ $t('welcome.trust.items.five') }}</div>
                            <div class="welcome-pill">{{ $t('welcome.trust.items.six') }}</div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="welcome-section welcome-features">
                <div class="welcome-container">
                    <div class="flex flex-col gap-2 text-center">
                        <div class="text-xs uppercase tracking-wide text-emerald-200">{{ $t('welcome.features.kicker') }}</div>
                        <h2 class="welcome-title text-3xl font-semibold">{{ $t('welcome.features.title') }}</h2>
                        <p class="text-sm text-emerald-100">{{ $t('welcome.features.subtitle') }}</p>
                    </div>

                    <div class="mt-10 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                        <div class="welcome-feature-card">
                            <div class="welcome-feature-title">{{ $t('welcome.features.items.quotes.title') }}</div>
                            <p class="welcome-feature-desc">{{ $t('welcome.features.items.quotes.desc') }}</p>
                        </div>
                        <div class="welcome-feature-card">
                            <div class="welcome-feature-title">{{ $t('welcome.features.items.plans.title') }}</div>
                            <p class="welcome-feature-desc">{{ $t('welcome.features.items.plans.desc') }}</p>
                        </div>
                        <div class="welcome-feature-card">
                            <div class="welcome-feature-title">{{ $t('welcome.features.items.catalog.title') }}</div>
                            <p class="welcome-feature-desc">{{ $t('welcome.features.items.catalog.desc') }}</p>
                        </div>
                        <div class="welcome-feature-card">
                            <div class="welcome-feature-title">{{ $t('welcome.features.items.ops.title') }}</div>
                            <p class="welcome-feature-desc">{{ $t('welcome.features.items.ops.desc') }}</p>
                        </div>
                        <div class="welcome-feature-card">
                            <div class="welcome-feature-title">{{ $t('welcome.features.items.portal.title') }}</div>
                            <p class="welcome-feature-desc">{{ $t('welcome.features.items.portal.desc') }}</p>
                        </div>
                        <div class="welcome-feature-card">
                            <div class="welcome-feature-title">{{ $t('welcome.features.items.multi.title') }}</div>
                            <p class="welcome-feature-desc">{{ $t('welcome.features.items.multi.desc') }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="welcome-section welcome-workflow">
                <div class="welcome-container">
                    <div class="grid grid-cols-1 lg:grid-cols-2 lg:items-center welcome-split">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-stone-500">{{ $t('welcome.workflow.kicker') }}</div>
                            <h2 class="welcome-title mt-2 text-3xl font-semibold">{{ $t('welcome.workflow.title') }}</h2>
                            <p class="mt-3 text-sm text-stone-600">{{ $t('welcome.workflow.subtitle') }}</p>

                            <ol class="mt-6 space-y-3 text-sm text-stone-700">
                                <li class="welcome-step">
                                    <div class="welcome-step-title">{{ $t('welcome.workflow.steps.one.title') }}</div>
                                    <div>{{ $t('welcome.workflow.steps.one.desc') }}</div>
                                </li>
                                <li class="welcome-step">
                                    <div class="welcome-step-title">{{ $t('welcome.workflow.steps.two.title') }}</div>
                                    <div>{{ $t('welcome.workflow.steps.two.desc') }}</div>
                                </li>
                                <li class="welcome-step">
                                    <div class="welcome-step-title">{{ $t('welcome.workflow.steps.three.title') }}</div>
                                    <div>{{ $t('welcome.workflow.steps.three.desc') }}</div>
                                </li>
                                <li class="welcome-step">
                                    <div class="welcome-step-title">{{ $t('welcome.workflow.steps.four.title') }}</div>
                                    <div>{{ $t('welcome.workflow.steps.four.desc') }}</div>
                                </li>
                                <li class="welcome-step">
                                    <div class="welcome-step-title">{{ $t('welcome.workflow.steps.five.title') }}</div>
                                    <div>{{ $t('welcome.workflow.steps.five.desc') }}</div>
                                </li>
                            </ol>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-white pl-4 p-4 shadow-lg">
                            <img
                                src="/images/landing/workflow-board.svg"
                                :alt="$t('welcome.images.workflow_alt')"
                                class="h-auto w-full rounded-sm"
                                loading="lazy"
                            />
                        </div>
                    </div>
                </div>
            </section>

            <section class="welcome-section welcome-field">
                <div class="welcome-container">
                    <div class="grid grid-cols-1 lg:grid-cols-2 lg:items-center welcome-split">
                        <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-lg">
                            <img
                                src="/images/landing/mobile-field.svg"
                                :alt="$t('welcome.images.mobile_alt')"
                                class="h-auto w-full rounded-sm"
                                loading="lazy"
                            />
                        </div>

                        <div>
                            <div class="text-xs uppercase tracking-wide text-stone-500">{{ $t('welcome.field.kicker') }}</div>
                            <h2 class="welcome-title mt-2 text-3xl font-semibold">{{ $t('welcome.field.title') }}</h2>
                            <p class="mt-3 text-sm text-stone-600">{{ $t('welcome.field.subtitle') }}</p>

                            <ul class="mt-6 space-y-3 text-sm text-stone-700">
                                <li class="welcome-bullet">{{ $t('welcome.field.items.one') }}</li>
                                <li class="welcome-bullet">{{ $t('welcome.field.items.two') }}</li>
                                <li class="welcome-bullet">{{ $t('welcome.field.items.three') }}</li>
                                <li class="welcome-bullet">{{ $t('welcome.field.items.four') }}</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>

            <section class="welcome-section welcome-cta">
                <div class="welcome-container">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="welcome-title text-3xl font-semibold text-white">{{ $t('welcome.cta.title') }}</h2>
                            <p class="mt-2 text-sm text-emerald-50">{{ $t('welcome.cta.subtitle') }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Link v-if="canRegister" :href="route('register')"
                                class="rounded-sm bg-white px-4 py-2 text-sm font-semibold text-stone-900 hover:bg-stone-100">
                                {{ $t('welcome.cta.primary') }}
                            </Link>
                            <Link v-if="canLogin" :href="route('login')"
                                class="rounded-sm border border-white/40 px-4 py-2 text-sm font-semibold text-white hover:bg-white/10">
                                {{ $t('welcome.cta.secondary') }}
                            </Link>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <footer class="welcome-footer">
            <div class="welcome-container py-8 text-center text-xs text-stone-500">
                <div class="flex flex-col items-center gap-2">
                    <div class="flex flex-wrap items-center justify-center gap-4 text-stone-600">
                        <Link :href="route('pricing')" class="hover:text-stone-900">
                            {{ $t('legal.links.pricing') }}
                        </Link>
                        <Link :href="route('terms')" class="hover:text-stone-900">
                            {{ $t('legal.links.terms') }}
                        </Link>
                        <Link :href="route('privacy')" class="hover:text-stone-900">
                            {{ $t('legal.links.privacy') }}
                        </Link>
                        <Link :href="route('refund')" class="hover:text-stone-900">
                            {{ $t('legal.links.refund') }}
                        </Link>
                    </div>
                    <div>{{ $t('welcome.footer.copy') }} {{ new Date().getFullYear() }}</div>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Work+Sans:wght@400;500;600&display=swap');

.welcome-page {
    --welcome-ink: #0f172a;
    --welcome-muted: #475569;
    --welcome-accent: #16a34a;
    font-family: 'Work Sans', 'Figtree', sans-serif;
    background: #ffffff;
}

.welcome-title {
    font-family: 'Space Grotesk', 'Figtree', sans-serif;
    letter-spacing: -0.02em;
}

.welcome-container {
    width: 100%;
    max-width: 72rem;
    margin: 0 auto;
    padding-left: 1.25rem;
    padding-right: 1.25rem;
}

.welcome-header {
    position: sticky;
    top: 0;
    z-index: 40;
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid #e2e8f0;
}

.welcome-lang {
    position: relative;
}

.welcome-lang__toggle {
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

.welcome-lang__toggle:hover {
    border-color: #16a34a;
    box-shadow: 0 16px 30px -22px rgba(15, 23, 42, 0.6);
}

.welcome-lang__toggle:focus-visible {
    outline: 2px solid rgba(16, 185, 129, 0.5);
    outline-offset: 2px;
}

.welcome-lang__chevron {
    color: #0f172a;
}

.welcome-lang__menu {
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

.welcome-lang__item {
    width: 100%;
    padding: 0.45rem 0.75rem;
    border-radius: 0.125rem;
    text-align: left;
    font-size: 0.85rem;
    font-weight: 500;
    color: #0f172a;
    transition: background 0.2s ease, color 0.2s ease;
}

.welcome-lang__item:hover {
    background: #f1f5f9;
}

.welcome-lang__item.is-active {
    background: #16a34a;
    color: #ffffff;
}

.welcome-section {
    width: 100%;
    padding-block: var(--section-pad, clamp(3.25rem, 6vw, 7rem));
}

.welcome-split {
    column-gap: clamp(2rem, 6vw, 5rem);
    row-gap: clamp(2.5rem, 6vw, 4rem);
}

.welcome-hero {
    background: linear-gradient(180deg, #f8fafc 0%, #ffffff 55%, #ecfdf5 100%);
}

.welcome-trust {
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    border-top: 1px solid #e2e8f0;
    border-bottom: 1px solid #e2e8f0;
    --section-pad: clamp(2.5rem, 4.5vw, 5rem);
}

.welcome-features {
    background: linear-gradient(135deg, #0f172a 0%, #064e3b 100%);
    color: #ecfdf3;
    --section-pad: clamp(4rem, 8vw, 8.5rem);
}

.welcome-workflow {
    background: radial-gradient(circle at top right, #e2e8f0 0%, #f8fafc 45%, #ffffff 100%);
    --section-pad: clamp(4rem, 8vw, 8.5rem);
}

.welcome-field {
    background: linear-gradient(180deg, #ffffff 0%, #fef9f4 60%, #ffffff 100%);
    --section-pad: clamp(4rem, 8vw, 8.5rem);
}

.welcome-cta {
    background: linear-gradient(120deg, #0f172a 0%, #0f766e 100%);
    --section-pad: clamp(3.5rem, 7vw, 7.5rem);
}

.welcome-footer {
    background: #ffffff;
    border-top: 1px solid #e2e8f0;
}

.welcome-kicker {
    display: inline-flex;
    align-items: center;
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    background: rgba(16, 185, 129, 0.12);
    color: #065f46;
    font-size: 0.75rem;
    font-weight: 600;
}

.welcome-pill {
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    padding: 0.4rem 0.8rem;
    background: #f8fafc;
}

.welcome-feature-card {
    border-radius: 0.125rem;
    padding: 1.25rem;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.16);
    backdrop-filter: blur(8px);
}

.welcome-feature-title {
    font-size: 0.95rem;
    font-weight: 600;
    color: #f8fafc;
}

.welcome-feature-desc {
    margin-top: 0.5rem;
    color: #d1fae5;
    font-size: 0.85rem;
}

.welcome-step {
    border-radius: 0.125rem;
    padding: 0.75rem;
    background: #ffffff;
    border: 1px solid #e2e8f0;
}

.welcome-step-title {
    font-weight: 600;
    color: #0f172a;
}

.welcome-bullet {
    position: relative;
    padding-left: 1.25rem;
}

.welcome-bullet::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0.5rem;
    width: 0.45rem;
    height: 0.45rem;
    border-radius: 999px;
    background: #16a34a;
}

.welcome-fade-up {
    animation: welcomeFadeUp 0.8s ease-out both;
}

.welcome-fade-in {
    animation: welcomeFadeIn 0.9s ease-out both;
}

@keyframes welcomeFadeUp {
    from {
        opacity: 0;
        transform: translateY(18px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes welcomeFadeIn {
    from {
        opacity: 0;
        transform: scale(0.98);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

@media (prefers-reduced-motion: reduce) {
    .welcome-fade-up,
    .welcome-fade-in {
        animation: none;
    }
}

</style>
