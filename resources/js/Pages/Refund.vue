<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link } from '@inertiajs/vue3';

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

const lastUpdated = '2025-01-01';
</script>

<template>
    <Head :title="$t('refund.meta.title')" />

    <div class="min-h-screen bg-stone-50 text-stone-900 dark:bg-neutral-900 dark:text-neutral-100">
        <header class="mx-auto flex w-full max-w-6xl items-center justify-between px-4 py-6">
            <Link :href="route('welcome')" class="flex items-center gap-3">
                <ApplicationLogo class="h-8 w-28 sm:h-10 sm:w-32" />
                <div class="leading-tight">
                    <div class="text-sm font-semibold">MLK Pro</div>
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('legal.header.tagline') }}</div>
                </div>
            </Link>

            <div class="flex items-center gap-2">
                <Link v-if="canLogin" :href="route('login')"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-800 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                    {{ $t('legal.actions.sign_in') }}
                </Link>
                <Link v-if="canRegister" :href="route('onboarding.index')"
                    class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">
                    {{ $t('legal.actions.create_account') }}
                </Link>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl px-4 pb-16 pt-8">
            <section
                class="rounded-sm border border-stone-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <div class="space-y-6">
                    <div class="space-y-2">
                        <div class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            {{ $t('refund.intro.eyebrow') }}
                        </div>
                        <h1 class="text-2xl font-semibold tracking-tight text-stone-900 dark:text-neutral-100">
                            {{ $t('refund.intro.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('refund.intro.updated', { date: lastUpdated }) }}
                        </p>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('refund.intro.summary') }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div class="space-y-6">
                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('refund.sections.scope.title') }}</h2>
                                <p class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ $t('refund.sections.scope.body') }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('refund.sections.eligibility.title') }}</h2>
                                <p class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ $t('refund.sections.eligibility.body') }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('refund.sections.exclusions.title') }}</h2>
                                <ul class="space-y-2 text-sm text-stone-600 dark:text-neutral-300">
                                    <li>{{ $t('refund.sections.exclusions.bullets.one') }}</li>
                                    <li>{{ $t('refund.sections.exclusions.bullets.two') }}</li>
                                    <li>{{ $t('refund.sections.exclusions.bullets.three') }}</li>
                                </ul>
                            </div>
                        </div>

                        <div class="space-y-6">
                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('refund.sections.process.title') }}</h2>
                                <p class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ $t('refund.sections.process.body') }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('refund.sections.timeline.title') }}</h2>
                                <p class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ $t('refund.sections.timeline.body') }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('refund.sections.chargebacks.title') }}</h2>
                                <p class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ $t('refund.sections.chargebacks.body') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        {{ $t('refund.note') }}
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-stone-200 dark:border-neutral-800">
            <div class="mx-auto w-full max-w-6xl px-4 py-6 text-xs text-stone-500 dark:text-neutral-400">
                <div class="flex flex-wrap items-center justify-center gap-4">
                    <Link :href="route('terms')" class="hover:text-stone-900 dark:hover:text-neutral-100">
                        {{ $t('legal.links.terms') }}
                    </Link>
                    <Link :href="route('privacy')" class="hover:text-stone-900 dark:hover:text-neutral-100">
                        {{ $t('legal.links.privacy') }}
                    </Link>
                    <Link :href="route('refund')" class="hover:text-stone-900 dark:hover:text-neutral-100">
                        {{ $t('legal.links.refund') }}
                    </Link>
                    <Link :href="route('pricing')" class="hover:text-stone-900 dark:hover:text-neutral-100">
                        {{ $t('legal.links.pricing') }}
                    </Link>
                </div>
                <div class="mt-2 text-center">
                    {{ $t('legal.footer', { year: new Date().getFullYear() }) }}
                </div>
            </div>
        </footer>
    </div>
</template>
