<script setup>
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SocialWorkspaceHeader from '@/Pages/Social/Components/SocialWorkspaceHeader.vue';
import SocialPostComposer from '@/Pages/Social/Components/SocialPostComposer.vue';

const props = defineProps({
    connected_accounts: {
        type: Array,
        default: () => ([]),
    },
    drafts: {
        type: Array,
        default: () => ([]),
    },
    templates: {
        type: Array,
        default: () => ([]),
    },
    prefill: {
        type: Object,
        default: () => null,
    },
    summary: {
        type: Object,
        default: () => ({}),
    },
    workspace_stats: {
        type: Object,
        default: () => ({}),
    },
    selected_draft_id: {
        type: Number,
        default: null,
    },
    selected_template_id: {
        type: Number,
        default: null,
    },
    initial_media_url: {
        type: String,
        default: '',
    },
    brand_voice: {
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
    <Head :title="t('social.composer_page.head_title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <SocialWorkspaceHeader
                active-tab="composer"
                :title="t('social.composer_page.page_title')"
                :description="t('social.composer_page.page_description')"
                :stats="props.workspace_stats"
            />

            <SocialPostComposer
                :initial-connected-accounts="props.connected_accounts"
                :initial-drafts="props.drafts"
                :initial-templates="props.templates"
                :initial-prefill="props.prefill"
                :initial-summary="props.summary"
                :initial-access="props.access"
                :selected-draft-id="props.selected_draft_id"
                :selected-template-id="props.selected_template_id"
                :initial-media-url="props.initial_media_url"
                :brand-voice="props.brand_voice"
            />
        </div>
    </AuthenticatedLayout>
</template>
