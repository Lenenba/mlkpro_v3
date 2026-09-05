<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import KpiMetricGrid from '@/Components/Dashboard/KpiMetricGrid.vue';
import ModuleKpiSection from '@/Components/Dashboard/ModuleKpiSection.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    filters: {
        type: Object,
        default: () => ({}),
    },
    summary: {
        type: Object,
        default: () => ({
            total: 0,
            needs_review: 0,
            open: 0,
            resolved: 0,
        }),
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
const paginationLinks = computed(() => props.conversations?.links || []);
const filterState = computed(() => ({
    q: props.filters?.q || '',
    status: props.filters?.status || '',
    channel: props.filters?.channel || '',
    intent: props.filters?.intent || '',
    date: props.filters?.date || '',
    queue: props.filters?.queue || '',
}));
const searchQuery = ref(filterState.value.q);
const activeQueue = computed(() => filterState.value.queue || (filterState.value.status || 'all'));

watch(() => props.filters?.q, (value) => {
    searchQuery.value = value || '';
});

const statusLabels = {
    open: 'En cours',
    waiting_human: 'A valider',
    resolved: 'Resolue',
    abandoned: 'Abandonnee',
};

const statusClasses = {
    open: 'bg-sky-50 text-sky-700 border-sky-200 dark:bg-sky-500/10 dark:text-sky-200 dark:border-sky-500/30',
    waiting_human: 'bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-500/10 dark:text-amber-100 dark:border-amber-500/30',
    resolved: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-100 dark:border-emerald-500/30',
    abandoned: 'bg-stone-100 text-stone-600 border-stone-200 dark:bg-neutral-800 dark:text-neutral-300 dark:border-neutral-700',
};

const channelLabels = {
    web_chat: 'Chat public',
    public_reservation: 'Reservation publique',
    sms: 'SMS',
    email: 'Email',
    whatsapp: 'WhatsApp',
    voice: 'Voix',
};

const reservationStatusLabels = {
    pending: 'En attente',
    confirmed: 'Confirmee',
    cancelled: 'Annulee',
    rescheduled: 'Replanifiee',
    completed: 'Terminee',
    no_show: 'Absence',
    expired: 'Expiree',
};

const quickFilters = computed(() => [
    {
        key: 'review',
        label: 'À valider',
        value: props.summary?.needs_review || 0,
        params: { queue: 'review', status: undefined },
        tone: 'amber',
        interactive: true,
        active: activeQueue.value === 'review',
    },
    {
        key: 'open',
        label: 'En cours',
        value: props.summary?.open || 0,
        params: { queue: undefined, status: 'open' },
        tone: 'sky',
        interactive: true,
        active: activeQueue.value === 'open',
    },
    {
        key: 'resolved',
        label: 'Résolues',
        value: props.summary?.resolved || 0,
        params: { queue: undefined, status: 'resolved' },
        tone: 'emerald',
        interactive: true,
        active: activeQueue.value === 'resolved',
    },
    {
        key: 'all',
        label: 'Toutes',
        value: props.summary?.total || 0,
        params: { queue: undefined, status: undefined },
        tone: 'stone',
        interactive: true,
        active: activeQueue.value === 'all',
    },
]);

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

const applyQuickFilter = (item) => {
    router.get(route('admin.ai-assistant.conversations.index'), {
        ...filterState.value,
        ...item.params,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const applySearch = () => {
    applyFilter('q', searchQuery.value.trim());
};

const clearFilters = () => {
    router.get(route('admin.ai-assistant.conversations.index'), {}, {
        preserveScroll: true,
        replace: true,
    });
};

const labelForStatus = (status) => statusLabels[status] || status || '-';
const classForStatus = (status) => statusClasses[status] || statusClasses.abandoned;
const labelForChannel = (channel) => channelLabels[channel] || channel || '-';
const labelForReservationStatus = (status) => reservationStatusLabels[status] || status || '-';

const formatDate = (value) => {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('fr-CA', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
};

const paginationLabel = (label) => String(label || '')
    .replace('&laquo; Previous', 'Precedent')
    .replace('Next &raquo;', 'Suivant')
    .replace('&laquo;', '')
    .replace('&raquo;', '')
    .trim();
</script>

<template>
    <Head title="Conversations IA" />

    <AuthenticatedLayout>
        <div class="mx-auto flex w-[1200px] max-w-full flex-col gap-4">
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                        Malikia AI Assistant
                    </p>
                    <h1 class="mt-1 text-xl font-semibold text-stone-900 dark:text-neutral-50">
                        Inbox IA
                    </h1>
                    <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                        File courte des conversations et actions qui demandent une decision.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        :href="route('admin.ai-assistant.settings.edit')"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    >
                        Reglages
                    </Link>
                    <Link
                        :href="route('admin.ai-assistant.knowledge.index')"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    >
                        Base de connaissance
                    </Link>
                </div>
            </header>

            <ModuleKpiSection module-key="ai-assistant">
                <KpiMetricGrid
                    :metrics="quickFilters"
                    grid-class="grid-cols-1 md:grid-cols-4"
                    @activate="applyQuickFilter"
                />
            </ModuleKpiSection>

            <section class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <form
                    class="grid gap-2 md:grid-cols-4 lg:grid-cols-[minmax(280px,2fr)_1fr_1fr_1fr_auto]"
                    role="search"
                    @submit.prevent="applySearch"
                >
                    <div class="flex min-w-0 md:col-span-4 lg:col-span-1">
                        <label class="sr-only" for="ai-conversation-search">Rechercher une conversation</label>
                        <input
                            id="ai-conversation-search"
                            v-model="searchQuery"
                            data-ai-conversation-search
                            type="search"
                            autocomplete="off"
                            class="min-w-0 flex-1 rounded-l-sm border-stone-200 text-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                            placeholder="Nom, contact, UUID, reservation, message ou service"
                        />
                        <button
                            type="submit"
                            class="rounded-r-sm bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
                        >
                            Rechercher
                        </button>
                    </div>
                    <select
                        class="rounded-sm border-stone-200 text-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        :value="filterState.channel"
                        @change="applyFilter('channel', $event.target.value)"
                    >
                        <option value="">Tous les canaux</option>
                        <option v-for="channel in options.channels" :key="channel" :value="channel">
                            {{ labelForChannel(channel) }}
                        </option>
                    </select>
                    <select
                        class="rounded-sm border-stone-200 text-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        :value="filterState.status"
                        @change="applyFilter('status', $event.target.value)"
                    >
                        <option value="">Tous les statuts</option>
                        <option v-for="status in options.statuses" :key="status" :value="status">
                            {{ labelForStatus(status) }}
                        </option>
                    </select>
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
                </form>
            </section>

            <section class="space-y-3">
                <article
                    v-for="conversation in rows"
                    :key="conversation.id"
                    class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="truncate text-base font-semibold text-stone-900 dark:text-neutral-50">
                                    {{ conversation.title || 'Visiteur' }}
                                </h2>
                                <span
                                    class="rounded-full border px-2 py-0.5 text-[11px] font-semibold"
                                    :class="classForStatus(conversation.status)"
                                >
                                    {{ labelForStatus(conversation.status) }}
                                </span>
                                <span
                                    v-if="conversation.pending_actions_count"
                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100"
                                >
                                    {{ conversation.pending_actions_count }} action(s)
                                </span>
                            </div>
                            <p class="mt-1 line-clamp-2 text-sm text-stone-600 dark:text-neutral-300">
                                {{ conversation.short_summary }}
                            </p>
                            <div class="mt-2 flex flex-wrap gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                <span>{{ labelForChannel(conversation.channel) }}</span>
                                <span v-if="conversation.intent">· {{ conversation.intent }}</span>
                                <span>· Activite {{ formatDate(conversation.last_activity_at || conversation.created_at) }}</span>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-stone-500 dark:text-neutral-400">
                                <span v-if="conversation.visitor_email">{{ conversation.visitor_email }}</span>
                                <span v-if="conversation.visitor_phone">{{ conversation.visitor_phone }}</span>
                                <span class="max-w-full truncate" :title="conversation.public_uuid">
                                    UUID {{ conversation.public_uuid }}
                                </span>
                            </div>
                        </div>
                        <Link
                            :href="route('admin.ai-assistant.conversations.show', conversation.id)"
                            class="rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700"
                        >
                            Traiter
                        </Link>
                    </div>

                    <div
                        v-if="conversation.reservation"
                        data-ai-reservation-preview
                        class="mt-3 rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm dark:border-emerald-500/30 dark:bg-emerald-500/10"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-semibold text-emerald-900 dark:text-emerald-100">
                                Reservation #{{ conversation.reservation.id }}
                            </span>
                            <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-200">
                                {{ labelForReservationStatus(conversation.reservation.status) }}
                            </span>
                        </div>
                        <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-emerald-800 dark:text-emerald-200">
                            <span>{{ conversation.reservation.service_name || 'Service non renseigne' }}</span>
                            <span>{{ formatDate(conversation.reservation.starts_at) }}</span>
                        </div>
                    </div>

                    <div v-if="conversation.pending_actions?.length" class="mt-3 grid gap-2 md:grid-cols-2">
                        <div
                            v-for="action in conversation.pending_actions"
                            :key="action.id"
                            class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800"
                        >
                            <div class="font-semibold text-stone-800 dark:text-neutral-100">{{ action.label }}</div>
                            <div class="mt-0.5 truncate text-xs text-stone-500 dark:text-neutral-400">{{ action.preview }}</div>
                        </div>
                    </div>
                </article>

                <div
                    v-if="!rows.length"
                    class="rounded-sm border border-stone-200 bg-white px-4 py-8 text-center text-sm text-stone-500 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400"
                >
                    Aucune conversation pour ce filtre.
                </div>
            </section>

            <nav v-if="paginationLinks.length > 3" class="flex flex-wrap justify-end gap-2">
                <Link
                    v-for="link in paginationLinks"
                    :key="`${link.label}-${link.url}`"
                    :href="link.url || '#'"
                    class="rounded-sm border px-3 py-2 text-xs font-semibold"
                    :class="[
                        link.active
                            ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-100'
                            : 'border-stone-200 bg-white text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300',
                        !link.url ? 'pointer-events-none opacity-50' : 'hover:bg-stone-50 dark:hover:bg-neutral-800',
                    ]"
                    preserve-scroll
                >
                    {{ paginationLabel(link.label) }}
                </Link>
            </nav>
        </div>
    </AuthenticatedLayout>
</template>
