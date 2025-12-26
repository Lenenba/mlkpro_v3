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
    <Head :title="$t('terms.meta.title')" />

    <div class="min-h-screen bg-stone-50 text-stone-900 dark:bg-neutral-900 dark:text-neutral-100">
        <header class="mx-auto flex w-full max-w-6xl items-center justify-between px-4 py-6">
            <Link :href="route('welcome')" class="flex items-center gap-3">
                <ApplicationLogo class="h-8 w-28 sm:h-10 sm:w-32" />
                <div class="leading-tight">
                    <div class="text-sm font-semibold">MLK Pro</div>
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('terms.header.tagline') }}</div>
                </div>
            </Link>

            <div class="flex items-center gap-2">
                <Link v-if="canLogin" :href="route('login')"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-800 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                    {{ $t('terms.actions.sign_in') }}
                </Link>
                <Link v-if="canRegister" :href="route('register')"
                    class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">
                    {{ $t('terms.actions.create_account') }}
                </Link>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl px-4 pb-16 pt-8">
            <section
                class="rounded-sm border border-stone-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <div class="space-y-6">
                    <div class="space-y-2">
                        <div class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            {{ $t('terms.intro.eyebrow') }}
                        </div>
                        <h1 class="text-2xl font-semibold tracking-tight text-stone-900 dark:text-neutral-100">
                            {{ $t('terms.intro.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('terms.intro.updated', { date: lastUpdated }) }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div class="space-y-6">
                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('terms.sections.scope.title') }}</h2>
                                <p class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ $t('terms.sections.scope.body') }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('terms.sections.accounts.title') }}</h2>
                                <ul class="space-y-2 text-sm text-stone-600 dark:text-neutral-300">
                                    <li>{{ $t('terms.sections.accounts.bullets.one') }}</li>
                                    <li>{{ $t('terms.sections.accounts.bullets.two') }}</li>
                                    <li>{{ $t('terms.sections.accounts.bullets.three') }}</li>
                                </ul>
                            </div>

                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('terms.sections.data.title') }}</h2>
                                <p class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ $t('terms.sections.data.body') }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('terms.sections.pricing.title') }}</h2>
                                <ul class="space-y-2 text-sm text-stone-600 dark:text-neutral-300">
                                    <li>{{ $t('terms.sections.pricing.bullets.one') }}</li>
                                    <li>{{ $t('terms.sections.pricing.bullets.two') }}</li>
                                    <li>{{ $t('terms.sections.pricing.bullets.three') }}</li>
                                </ul>
                            </div>
                        </div>

                        <div class="space-y-6">
                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('terms.sections.billing.title') }}</h2>
                                <p class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ $t('terms.sections.billing.body') }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('terms.sections.acceptable.title') }}</h2>
                                <ul class="space-y-2 text-sm text-stone-600 dark:text-neutral-300">
                                    <li>{{ $t('terms.sections.acceptable.bullets.one') }}</li>
                                    <li>{{ $t('terms.sections.acceptable.bullets.two') }}</li>
                                    <li>{{ $t('terms.sections.acceptable.bullets.three') }}</li>
                                </ul>
                            </div>

                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('terms.sections.availability.title') }}</h2>
                                <p class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ $t('terms.sections.availability.body') }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('terms.sections.liability.title') }}</h2>
                                <p class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ $t('terms.sections.liability.body') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div class="space-y-2">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('terms.sections.termination.title') }}</h2>
                            <p class="text-sm text-stone-600 dark:text-neutral-300">
                                {{ $t('terms.sections.termination.body') }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('terms.sections.changes.title') }}</h2>
                            <p class="text-sm text-stone-600 dark:text-neutral-300">
                                {{ $t('terms.sections.changes.body') }}
                            </p>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        {{ $t('terms.note') }}
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-stone-200 py-8 text-center text-xs text-stone-500 dark:border-neutral-800 dark:text-neutral-400">
            {{ $t('terms.footer', { year: new Date().getFullYear() }) }}
        </footer>
    </div>
</template>
