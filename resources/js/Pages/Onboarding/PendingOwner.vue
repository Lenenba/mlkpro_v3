<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <GuestLayout>
        <Head :title="t('onboarding.title')" />

        <div class="space-y-3">
            <h1 class="text-lg font-semibold text-stone-900 dark:text-neutral-100">{{ t('onboarding.pending_owner.title') }}</h1>
            <p class="text-sm text-stone-600 dark:text-neutral-400">
                {{ t('onboarding.pending_owner.description') }}
            </p>

            <div class="flex items-center justify-between pt-2">
                <Link :href="route('logout')" method="post" as="button"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                    {{ t('onboarding.pending_owner.logout') }}
                </Link>
                <Link :href="route('dashboard')"
                    class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">
                    {{ t('onboarding.pending_owner.dashboard') }}
                </Link>
            </div>
        </div>
    </GuestLayout>
</template>
