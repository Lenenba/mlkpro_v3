<script setup>
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SocialWorkspaceHeader from '@/Pages/Social/Components/SocialWorkspaceHeader.vue';
import SocialTemplateManager from '@/Pages/Social/Components/SocialTemplateManager.vue';

const props = defineProps({
    connected_accounts: {
        type: Array,
        default: () => ([]),
    },
    templates: {
        type: Array,
        default: () => ([]),
    },
    media_assets: {
        type: Array,
        default: () => ([]),
    },
    summary: {
        type: Object,
        default: () => ({}),
    },
    workspace_stats: {
        type: Object,
        default: () => ({}),
    },
    selected_template_id: {
        type: Number,
        default: null,
    },
    access: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
</script>

<template>
    <Head :title="t('social.templates_page.head_title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <SocialWorkspaceHeader
                active-tab="templates"
                :title="t('social.templates_page.page_title')"
                :description="t('social.templates_page.page_description')"
                :stats="props.workspace_stats"
            />

            <SocialTemplateManager
                :initial-connected-accounts="props.connected_accounts"
                :initial-templates="props.templates"
                :initial-media-assets="props.media_assets"
                :initial-summary="props.summary"
                :initial-access="props.access"
                :selected-template-id="props.selected_template_id"
            />
        </div>
    </AuthenticatedLayout>
</template>
