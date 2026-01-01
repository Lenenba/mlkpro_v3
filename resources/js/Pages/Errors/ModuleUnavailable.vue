<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    feature: String,
    featureLabel: String,
    isOwner: Boolean,
    upgradeUrl: String,
});
</script>

<template>
    <Head title="Module unavailable" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-3xl space-y-4">
            <div class="rounded-sm border border-stone-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                    Module unavailable
                </div>
                <h1 class="mt-2 text-xl font-semibold text-stone-800 dark:text-neutral-100">
                    {{ featureLabel || 'Module' }} is not available on your plan.
                </h1>
                <p class="mt-2 text-sm text-stone-600 dark:text-neutral-400">
                    This module is currently disabled for your subscription. Upgrade to unlock it.
                </p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <Link
                        v-if="isOwner && upgradeUrl"
                        :href="upgradeUrl"
                        class="rounded-sm border border-emerald-600 bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700"
                    >
                        Upgrade plan
                    </Link>
                    <Link
                        :href="route('dashboard')"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                    >
                        Back to dashboard
                    </Link>
                </div>
            </div>
            <div
                v-if="!isOwner"
                class="rounded-sm border border-stone-200 bg-stone-50 px-4 py-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
            >
                Contact your account owner to enable this module.
            </div>
        </div>
    </AuthenticatedLayout>
</template>
