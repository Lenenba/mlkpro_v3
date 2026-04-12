<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import MegaMenuDisplay from '@/Components/MegaMenu/MegaMenuDisplay.vue';

const props = defineProps({
    menu: { type: Object, required: true },
    dashboard_url: { type: String, required: true },
    index_url: { type: String, required: true },
    edit_url: { type: String, required: true },
});

const page = usePage();
const { t } = useI18n();
const currentLocaleCode = computed(() => String(page.props.locale || 'fr').toUpperCase());
const tx = (key, params = {}) => t(`mega_menu.admin.${key}`, params);
const headTitle = computed(() => tx('preview.head_title', { title: props.menu?.title || tx('common.mega_menu') }));

const previewMedia = computed(() => {
    const items = Array.isArray(props.menu?.items) ? props.menu.items : [];

    for (const item of items) {
        for (const column of item.columns || []) {
            for (const block of column.blocks || []) {
                if (block.type === 'product_showcase') {
                    const showcaseItem = Array.isArray(block.payload?.items) ? block.payload.items[0] : null;

                    if (showcaseItem?.image_url) {
                        return {
                            imageUrl: showcaseItem.image_url,
                            alt: showcaseItem.image_alt || showcaseItem.label || tx('preview.image_alt'),
                            label: showcaseItem.label || tx('preview.image_label'),
                        };
                    }
                }

                if (block.type === 'promo_banner' && block.payload?.image_url) {
                    return {
                        imageUrl: block.payload.image_url,
                        alt: block.payload.image_alt || block.payload.title || tx('preview.image_alt'),
                        label: block.payload.title || tx('preview.image_label'),
                    };
                }

                if (block.type === 'featured_content' && block.payload?.image_url) {
                    return {
                        imageUrl: block.payload.image_url,
                        alt: block.payload.image_alt || block.payload.title || tx('preview.image_alt'),
                        label: block.payload.title || tx('preview.image_label'),
                    };
                }

                if (block.type === 'image' && block.payload?.image_url) {
                    return {
                        imageUrl: block.payload.image_url,
                        alt: block.payload.image_alt || block.payload.caption || tx('preview.image_alt'),
                        label: block.payload.caption || tx('preview.image_label'),
                    };
                }
            }
        }
    }

    return null;
});
</script>

<template>
    <Head :title="headTitle" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ tx('preview.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ tx('preview.description') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link :href="dashboard_url"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ t('nav.dashboard') }}
                        </Link>
                        <Link :href="index_url"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ tx('common.mega_menus') }}
                        </Link>
                        <Link :href="edit_url"
                            class="rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700">
                            {{ tx('preview.back_to_builder') }}
                        </Link>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-gradient-to-br from-stone-100 via-white to-emerald-50 p-5 shadow-sm dark:border-neutral-700 dark:from-neutral-900 dark:via-neutral-900 dark:to-neutral-800">
                <div class="rounded-sm border border-stone-200 bg-white shadow-xl dark:border-neutral-700 dark:bg-neutral-950">
                    <div class="border-b border-stone-200 dark:border-neutral-700">
                        <div class="mx-auto flex w-full max-w-[88rem] items-center gap-5 px-5 py-5 xl:px-8">
                            <div class="flex shrink-0 items-center">
                                <ApplicationLogo class="h-10 w-36 sm:h-11 sm:w-40" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <MegaMenuDisplay :menu="menu" preview default-open-first-panel />
                            </div>
                            <button
                                type="button"
                                class="inline-flex shrink-0 items-center gap-2 rounded-sm border border-stone-200 bg-white px-4 py-2 text-sm font-semibold text-stone-800 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                            >
                                <span>{{ currentLocaleCode }}</span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="m6 9 6 6 6-6" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="grid gap-6 px-5 py-8 lg:grid-cols-[1.1fr_0.9fr]">
                        <div class="space-y-4">
                            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                                {{ tx('preview.frontend_shell') }}
                            </div>
                            <h2 class="text-3xl font-semibold tracking-tight text-stone-900 dark:text-white">
                                {{ tx('preview.headline') }}
                            </h2>
                            <p class="text-sm text-stone-600 dark:text-neutral-300">
                                {{ tx('preview.body') }}
                            </p>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="overflow-hidden rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-950">
                                <img
                                    v-if="previewMedia?.imageUrl"
                                    :src="previewMedia.imageUrl"
                                    :alt="previewMedia.alt"
                                    class="h-[220px] w-full object-cover"
                                />
                                <div v-else class="flex h-[220px] items-center justify-center px-6 text-sm text-stone-500 dark:text-neutral-400">
                                    {{ tx('preview.image_placeholder') }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
