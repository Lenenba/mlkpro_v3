<script setup>
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SocialAccountManager from '@/Pages/Social/Components/SocialAccountManager.vue';
import SocialBufferConnectionCard from '@/Pages/Social/Components/SocialBufferConnectionCard.vue';
import SocialWorkspaceHeader from '@/Pages/Social/Components/SocialWorkspaceHeader.vue';

const props = defineProps({
    provider_definitions: {
        type: Array,
        default: () => ([]),
    },
    connections: {
        type: Array,
        default: () => ([]),
    },
    summary: {
        type: Object,
        default: () => ({}),
    },
    access: {
        type: Object,
        default: () => ({}),
    },
    workspace_stats: {
        type: Object,
        default: () => ({}),
    },
    buffer_connector: {
        type: Object,
        default: null,
    },
});

const { t } = useI18n();
</script>

<template>
    <Head :title="t('social.accounts_page.head_title')" />

    <AuthenticatedLayout>
        <div class="space-y-4">
            <SocialWorkspaceHeader
                active-tab="accounts"
                :title="t('social.accounts_page.page_title')"
                :description="t('social.accounts_page.page_description')"
                :stats="props.workspace_stats"
            />

            <section class="space-y-4">
                <SocialBufferConnectionCard
                    v-if="props.buffer_connector"
                    :initial-connector="props.buffer_connector"
                    :can-manage="Boolean(props.access?.can_manage_accounts)"
                />

                <SocialAccountManager
                    :initial-definitions="props.provider_definitions"
                    :initial-connections="props.connections"
                    :initial-summary="props.summary"
                    :initial-access="props.access"
                />
            </section>
        </div>
    </AuthenticatedLayout>
</template>
