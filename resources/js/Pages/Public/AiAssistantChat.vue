<script setup>
import { Head } from '@inertiajs/vue3';
import PublicChatWidget from '@/Components/AiAssistant/PublicChatWidget.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const props = defineProps({
    company: {
        type: Object,
        required: true,
    },
    assistant: {
        type: Object,
        default: () => ({}),
    },
    endpoints: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <GuestLayout :show-platform-logo="false" card-class="w-full">
        <Head :title="assistant.name || 'Assistant IA'" />

        <div class="min-h-screen bg-stone-50 px-4 py-6 text-stone-900 sm:px-6 lg:px-8">
            <div class="mx-auto flex w-full max-w-5xl flex-col gap-5">
                <header class="flex flex-wrap items-center justify-between gap-4 rounded-sm border border-stone-200 bg-white px-4 py-3 shadow-sm">
                    <div class="flex min-w-0 items-center gap-3">
                        <img
                            v-if="company.logo_url"
                            :src="company.logo_url"
                            :alt="company.name"
                            class="size-12 rounded-sm object-cover"
                        >
                        <div v-else class="flex size-12 items-center justify-center rounded-sm bg-emerald-700 text-base font-semibold text-white">
                            {{ String(company.name || 'M').slice(0, 1) }}
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-stone-500">{{ company.name }}</p>
                            <h1 class="truncate text-xl font-semibold text-stone-950">{{ assistant.name || 'Malikia AI Assistant' }}</h1>
                        </div>
                    </div>
                </header>

                <PublicChatWidget
                    :company-name="company.name"
                    :company-slug="company.slug"
                    :company-logo-url="company.logo_url || ''"
                    :assistant-name="assistant.name || 'Malikia AI Assistant'"
                    :endpoints="endpoints"
                    channel="web_chat"
                    mode="page"
                />
            </div>
        </div>
    </GuestLayout>
</template>
