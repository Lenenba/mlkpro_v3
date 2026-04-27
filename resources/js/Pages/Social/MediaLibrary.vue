<script setup>
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SocialWorkspaceHeader from '@/Pages/Social/Components/SocialWorkspaceHeader.vue';
import SocialMediaLibraryManager from '@/Pages/Social/Components/SocialMediaLibraryManager.vue';

const props = defineProps({
    assets: {
        type: Array,
        default: () => ([]),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    summary: {
        type: Object,
        default: () => ({}),
    },
    source_options: {
        type: Array,
        default: () => ([]),
    },
    origin_options: {
        type: Array,
        default: () => ([]),
    },
    workspace_stats: {
        type: Object,
        default: () => ({}),
    },
    access: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
</script>

<template>
    <Head :title="t('social.media_page.head_title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <SocialWorkspaceHeader
                active-tab="media"
                :title="t('social.media_page.page_title')"
                :description="t('social.media_page.page_description')"
                :stats="props.workspace_stats"
            />

            <SocialMediaLibraryManager
                :initial-assets="props.assets"
                :initial-filters="props.filters"
                :initial-summary="props.summary"
                :initial-source-options="props.source_options"
                :initial-origin-options="props.origin_options"
                :initial-access="props.access"
            />
        </div>
    </AuthenticatedLayout>
</template>
