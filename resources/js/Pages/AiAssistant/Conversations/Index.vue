<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    filters: {
        type: Object,
        default: () => ({}),
    },
    conversations: {
        type: Object,
        default: () => ({ data: [] }),
    },
    options: {
        type: Object,
        default: () => ({ statuses: [], channels: [] }),
    },
});

const rows = computed(() => props.conversations?.data || []);
const filterState = computed(() => ({
    status: props.filters?.status || '',
    channel: props.filters?.channel || '',
    intent: props.filters?.intent || '',
    date: props.filters?.date || '',
}));

const applyFilter = (key, value) => {
    router.get(route('admin.ai-assistant.conversations.index'), {
        ...filterState.value,
        [key]: value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const clearFilters = () => {
    router.get(route('admin.ai-assistant.conversations.index'), {}, {
        preserveScroll: true,
        replace: true,
    });
};
</script>

<template>
    <Head title="Conversations IA" />

    <AuthenticatedLayout>
        <div class="mx-auto flex w-[1200px] max-w-full flex-col gap-4">
            <header class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                    Malikia AI Assistant
                </p>
                <div class="mt-1 flex flex-wrap items-center justify-between gap-3">
                    <h1 class="text-xl font-semibold text-stone-900 dark:text-neutral-50">
                        Inbox IA
                    </h1>
                    <Link
                        :href="route('admin.ai-assistant.settings.edit')"
                        class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    >
                        Reglages
                    </Link>
                </div>
            </header>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid gap-3 md:grid-cols-5">
                    <select
                        class="rounded-sm border-stone-200 text-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        :value="filterState.status"
                        @change="applyFilter('status', $event.target.value)"
                    >
                        <option value="">Tous les statuts</option>
                        <option v-for="status in options.statuses" :key="status" :value="status">
                            {{ status }}
                        </option>
                    </select>
                    <select
                        class="rounded-sm border-stone-200 text-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        :value="filterState.channel"
                        @change="applyFilter('channel', $event.target.value)"
                    >
                        <option value="">Tous les canaux</option>
                        <option v-for="channel in options.channels" :key="channel" :value="channel">
                            {{ channel }}
                        </option>
                    </select>
                    <input
                        class="rounded-sm border-stone-200 text-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        :value="filterState.intent"
                        placeholder="Intent"
                        @change="applyFilter('intent', $event.target.value)"
                    />
                    <input
                        type="date"
                        class="rounded-sm border-stone-200 text-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        :value="filterState.date"
                        @change="applyFilter('date', $event.target.value)"
                    />
                    <button
                        type="button"
                        class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        @click="clearFilters"
                    >
                        Reinitialiser
                    </button>
                </div>
            </section>

            <section class="overflow-hidden rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500 dark:bg-neutral-800 dark:text-neutral-400">
                            <tr>
                                <th class="px-4 py-3 text-left">Visiteur</th>
                                <th class="px-4 py-3 text-left">Canal</th>
                                <th class="px-4 py-3 text-left">Statut</th>
                                <th class="px-4 py-3 text-left">Intent</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr v-for="conversation in rows" :key="conversation.id" class="text-stone-700 dark:text-neutral-200">
                                <td class="px-4 py-3">
                                    <div class="font-semibold">{{ conversation.visitor_name || 'Visiteur' }}</div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ conversation.visitor_email || conversation.visitor_phone || conversation.public_uuid }}
                                    </div>
                                </td>
                                <td class="px-4 py-3">{{ conversation.channel }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full bg-stone-100 px-2 py-1 text-xs font-semibold text-stone-700 dark:bg-neutral-800 dark:text-neutral-200">
                                        {{ conversation.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">{{ conversation.intent || '-' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <Link
                                        :href="route('admin.ai-assistant.conversations.show', conversation.id)"
                                        class="rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700"
                                    >
                                        Ouvrir
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-stone-500 dark:text-neutral-400">
                                    Aucune conversation.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
