<script setup>
import PublicFooterMenu from '@/Components/Public/PublicFooterMenu.vue';
import PublicSiteHeader from '@/Components/Public/PublicSiteHeader.vue';
import TermsContent from '@/Components/Legal/TermsContent.vue';
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
</script>

<template>
    <Head :title="$t('terms.meta.title')" />

    <div class="front-public-page min-h-screen bg-stone-50 text-stone-900 dark:bg-neutral-900 dark:text-neutral-100">
        <PublicSiteHeader
            :mega-menu="props.megaMenu"
            :fallback-items="headerMenuItems"
            :can-login="props.canLogin"
            :can-register="props.canRegister"
        />

        <main class="mx-auto w-full max-w-[88rem] px-5 pb-16 pt-8">
            <section
                class="rounded-sm border border-stone-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <TermsContent />
            </section>
        </main>

        <PublicFooterMenu :menu="props.footerMenu" :section="props.footerSection" />
    </div>
</template>
