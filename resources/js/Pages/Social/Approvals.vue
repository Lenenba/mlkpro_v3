<script setup>
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SocialWorkspaceHeader from '@/Pages/Social/Components/SocialWorkspaceHeader.vue';
import SocialApprovalInbox from '@/Pages/Social/Components/SocialApprovalInbox.vue';

const props = defineProps({
    posts: {
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
    rule_filters: {
        type: Array,
        default: () => ([]),
    },
    source_filters: {
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
    <Head :title="t('social.approvals_page.head_title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <SocialWorkspaceHeader
                active-tab="approvals"
                :title="t('social.approvals_page.page_title')"
                :description="t('social.approvals_page.page_description')"
                :stats="props.workspace_stats"
            />

            <SocialApprovalInbox
                :initial-posts="props.posts"
                :initial-filters="props.filters"
                :initial-summary="props.summary"
                :initial-rule-filters="props.rule_filters"
                :initial-source-filters="props.source_filters"
                :initial-access="props.access"
            />
        </div>
    </AuthenticatedLayout>
</template>
