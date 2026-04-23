<script setup>
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SocialWorkspaceHeader from '@/Pages/Social/Components/SocialWorkspaceHeader.vue';
import SocialPostHistory from '@/Pages/Social/Components/SocialPostHistory.vue';

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
    platform_filters: {
        type: Array,
        default: () => ([]),
    },
    status_filters: {
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
    <Head :title="t('social.history_page.head_title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <SocialWorkspaceHeader
                active-tab="history"
                :title="t('social.history_page.page_title')"
                :description="t('social.history_page.page_description')"
                :stats="props.workspace_stats"
            />

            <SocialPostHistory
                :initial-posts="props.posts"
                :initial-filters="props.filters"
                :initial-summary="props.summary"
                :initial-platform-filters="props.platform_filters"
                :initial-status-filters="props.status_filters"
                :initial-access="props.access"
            />
        </div>
    </AuthenticatedLayout>
</template>
