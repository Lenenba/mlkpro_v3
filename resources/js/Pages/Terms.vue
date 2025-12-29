<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import TermsContent from '@/Components/Legal/TermsContent.vue';
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

</script>

<template>
    <Head :title="$t('terms.meta.title')" />

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
                <TermsContent />
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
