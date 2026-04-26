<script setup>
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SocialWorkspaceHeader from '@/Pages/Social/Components/SocialWorkspaceHeader.vue';
import SocialAutomationManager from '@/Pages/Social/Components/SocialAutomationManager.vue';

const props = defineProps({
    rules: {
        type: Array,
        default: () => ([]),
    },
    summary: {
        type: Object,
        default: () => ({}),
    },
    recent_runs: {
        type: Array,
        default: () => ([]),
    },
    content_source_catalog: {
        type: Array,
        default: () => ([]),
    },
    target_connections: {
        type: Array,
        default: () => ([]),
    },
    frequency_options: {
        type: Array,
        default: () => ([]),
    },
    approval_mode_options: {
        type: Array,
        default: () => ([]),
    },
    locale_options: {
        type: Array,
        default: () => ([]),
    },
    timezone_options: {
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
    <Head :title="t('social.automations_page.head_title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <SocialWorkspaceHeader
                active-tab="autopilot"
                :title="t('social.automations_page.page_title')"
                :description="t('social.automations_page.page_description')"
                :stats="props.workspace_stats"
            />

            <SocialAutomationManager
                :initial-rules="props.rules"
                :initial-summary="props.summary"
                :initial-recent-runs="props.recent_runs"
                :initial-content-source-catalog="props.content_source_catalog"
                :initial-target-connections="props.target_connections"
                :initial-frequency-options="props.frequency_options"
                :initial-approval-mode-options="props.approval_mode_options"
                :initial-locale-options="props.locale_options"
                :initial-timezone-options="props.timezone_options"
                :initial-access="props.access"
            />
        </div>
    </AuthenticatedLayout>
</template>
