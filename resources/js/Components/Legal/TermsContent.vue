<script setup>
defineProps({
    lastUpdated: {
        type: String,
        default: '2025-01-01',
    },
});
</script>

<template>
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
            <p class="text-sm text-stone-600 dark:text-neutral-400">
                {{ $t('terms.intro.legal') }}
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
</template>
