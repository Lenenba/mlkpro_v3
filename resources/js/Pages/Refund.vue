<script setup>
import PublicFooterMenu from '@/Components/Public/PublicFooterMenu.vue';
import PublicSiteHeader from '@/Components/Public/PublicSiteHeader.vue';
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    canLogin: {
        type: Boolean,
        default: true,
    },
    canRegister: {
        type: Boolean,
        default: true,
    },
    megaMenu: {
        type: Object,
        default: () => ({}),
    },
    footerMenu: {
        type: Object,
        default: () => ({}),
    },
    footerSection: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
const headerMenuItems = computed(() => ([
    {
        label: t('public_pages.actions.home'),
        resolved_href: route('welcome'),
        link_target: '_self',
        panel_type: 'link',
    },
    {
        label: t('legal.links.pricing'),
        resolved_href: route('pricing'),
        link_target: '_self',
        panel_type: 'link',
    },
]));

const lastUpdated = '2025-01-01';
</script>

<template>
    <Head :title="$t('refund.meta.title')" />

    <div class="min-h-screen bg-stone-50 text-stone-900 dark:bg-neutral-900 dark:text-neutral-100">
        <PublicSiteHeader
            :mega-menu="props.megaMenu"
            :fallback-items="headerMenuItems"
            :can-login="props.canLogin"
            :can-register="props.canRegister"
        />

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

        <PublicFooterMenu :menu="props.footerMenu" :section="props.footerSection" />
    </div>
</template>
