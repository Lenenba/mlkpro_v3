<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TemplateManager from '@/Pages/Campaigns/Components/TemplateManager.vue';

defineProps({
    enums: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
</script>

<template>
    <Head :title="t('marketing.template_page.head_title')" />

    <AuthenticatedLayout>
        <div class="space-y-4">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-green-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="inline-flex items-center gap-2 text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            <svg class="size-5 text-green-600 dark:text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M4 7h16" />
                                <path d="M4 12h10" />
                                <path d="M4 17h7" />
                                <path d="M18 14v6" />
                                <path d="M15 17h6" />
                            </svg>
                            <span>{{ t('marketing.template_page.page_title') }}</span>
                        </h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.template_page.page_description') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Link :href="route('settings.marketing.edit')">
                            <SecondaryButton type="button">
                                {{ t('marketing.template_page.actions.back_to_settings') }}
                            </SecondaryButton>
                        </Link>
                        <Link :href="route('campaigns.index')">
                            <PrimaryButton type="button">
                                {{ t('marketing.template_page.actions.back_to_campaigns') }}
                            </PrimaryButton>
                        </Link>
                    </div>
                </div>
            </section>

            <section class="space-y-4">
                <TemplateManager :enums="enums" />
            </section>
        </div>
    </AuthenticatedLayout>
</template>
