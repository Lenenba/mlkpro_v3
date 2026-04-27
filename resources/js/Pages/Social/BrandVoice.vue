<script setup>
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SocialWorkspaceHeader from '@/Pages/Social/Components/SocialWorkspaceHeader.vue';
import SocialBrandVoiceManager from '@/Pages/Social/Components/SocialBrandVoiceManager.vue';

const props = defineProps({
    brand_voice: {
        type: Object,
        default: () => ({}),
    },
    tone_options: {
        type: Array,
        default: () => ([]),
    },
    language_options: {
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
    <Head :title="t('social.brand_voice_page.head_title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <SocialWorkspaceHeader
                active-tab="brand_voice"
                :title="t('social.brand_voice_page.page_title')"
                :description="t('social.brand_voice_page.page_description')"
                :stats="props.workspace_stats"
            />

            <SocialBrandVoiceManager
                :initial-brand-voice="props.brand_voice"
                :initial-tone-options="props.tone_options"
                :initial-language-options="props.language_options"
                :initial-access="props.access"
            />
        </div>
    </AuthenticatedLayout>
</template>
