<script setup>
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SocialWorkspaceHeader from '@/Pages/Social/Components/SocialWorkspaceHeader.vue';
import SocialEditorialCalendar from '@/Pages/Social/Components/SocialEditorialCalendar.vue';

const props = defineProps({
    calendar_posts: {
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
    <Head :title="t('social.calendar_page.head_title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <SocialWorkspaceHeader
                active-tab="calendar"
                :title="t('social.calendar_page.page_title')"
                :description="t('social.calendar_page.page_description')"
                :stats="props.workspace_stats"
            />

            <SocialEditorialCalendar
                :initial-posts="props.calendar_posts"
                :initial-summary="props.summary"
                :initial-access="props.access"
            />
        </div>
    </AuthenticatedLayout>
</template>
