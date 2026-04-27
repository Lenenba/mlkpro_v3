<script setup>
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SocialWorkspaceHeader from '@/Pages/Social/Components/SocialWorkspaceHeader.vue';
import SocialCampaignManager from '@/Pages/Social/Components/SocialCampaignManager.vue';

const props = defineProps({
    connected_accounts: {
        type: Array,
        default: () => ([]),
    },
    recent_batches: {
        type: Array,
        default: () => ([]),
    },
    intention_options: {
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
    access: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
</script>

<template>
    <Head :title="t('social.campaigns_page.head_title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <SocialWorkspaceHeader
                active-tab="campaigns"
                :title="t('social.campaigns_page.page_title')"
                :description="t('social.campaigns_page.page_description')"
                :stats="props.workspace_stats"
            />

            <SocialCampaignManager
                :initial-connected-accounts="props.connected_accounts"
                :initial-recent-batches="props.recent_batches"
                :initial-intention-options="props.intention_options"
                :initial-summary="props.summary"
                :initial-access="props.access"
            />
        </div>
    </AuthenticatedLayout>
</template>
