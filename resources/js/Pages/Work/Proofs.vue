<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    viewer: {
        type: String,
        default: 'team',
    },
    work: Object,
    customer: Object,
    tasks: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const companyName = computed(() => page.props.auth?.account?.company?.name || 'Entreprise');
const companyLogo = computed(() => page.props.auth?.account?.company?.logo_url || null);
const isClient = computed(() => props.viewer === 'client');

const formatDate = (value) => humanizeDate(value) || '-';

const formatTime = (value) => {
    if (!value) {
        return '-';
    }
    const text = String(value);
    return text.length >= 5 ? text.slice(0, 5) : text;
};

const formatTimeRange = (start, end) => {
    const startLabel = formatTime(start);
    const endLabel = formatTime(end);
    if (startLabel === '-' && endLabel === '-') {
        return '-';
    }
    if (endLabel === '-') {
        return startLabel;
    }
    return `${startLabel} - ${endLabel}`;
};

const statusLabel = (status) => (status || 'todo').replace(/_/g, ' ');

const statusClass = (status) => {
    switch (status) {
        case 'done':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'in_progress':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const proofSource = (source) => {
    if (source === 'client' || source === 'client-public') {
        return 'Client';
    }
    if (source === 'team') {
        return 'Equipe';
    }
    return 'Interne';
};

const proofType = (type) => {
    if (type === 'execution') {
        return 'Execution';
    }
    if (type === 'completion') {
        return 'Finalisation';
    }
    return 'Autre';
};
</script>

<template>
    <Head :title="$t('jobs.proofs_title')" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-5">
            <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <img v-if="companyLogo"
                            :src="companyLogo"
                            :alt="companyName"
                            class="h-12 w-12 rounded-sm border border-stone-200 object-cover dark:border-neutral-700" />
                        <div>
                            <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                {{ companyName }}
                            </p>
                            <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                                Preuves du job
                            </h1>
                            <p class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ work?.job_title || work?.number || 'Job' }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link v-if="!isClient" :href="route('work.show', work.id)"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                            Voir le job
                        </Link>
                        <Link v-else :href="route('dashboard')"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                            Retour au tableau de bord
                        </Link>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">Client</div>
                        <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">
                            {{ customer?.company_name || `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim() || '-' }}
                        </div>
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ customer?.email || '-' }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">Periode</div>
                        <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">
                            {{ formatDate(work?.start_date) }} - {{ formatDate(work?.end_date) }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">Statut</div>
                        <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">
                            {{ statusLabel(work?.status) }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div v-for="task in tasks" :key="task.id"
                    class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ task.title || 'Tache' }}
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ formatDate(task.due_date) }} • {{ formatTimeRange(task.start_time, task.end_time) }}
                            </div>
                            <div v-if="task.assignee" class="text-xs text-stone-500 dark:text-neutral-400">
                                Assigne : {{ task.assignee }}
                            </div>
                        </div>
                        <span class="px-2 py-0.5 text-xs font-medium rounded-full" :class="statusClass(task.status)">
                            {{ statusLabel(task.status) }}
                        </span>
                    </div>

                    <div v-if="task.materials?.length" class="mt-4">
                        <div class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            Materiel
                        </div>
                        <div class="mt-2 space-y-2">
                            <div v-for="material in task.materials" :key="material.id"
                                class="flex flex-wrap items-center justify-between gap-2 rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="text-sm text-stone-700 dark:text-neutral-200">
                                    {{ material.label }}
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ material.quantity }} {{ material.unit || '' }}
                                </div>
                                <div v-if="!isClient" class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ Number(material.unit_price || 0).toFixed(2) }}
                                </div>
                                <span v-if="!isClient && material.billable"
                                    class="rounded-sm bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                                    Billable
                                </span>
                            </div>
                        </div>
                    </div>

                    <div v-if="task.media?.length" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="media in task.media" :key="media.id"
                            class="rounded-sm border border-stone-200 bg-white p-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <img v-if="media.media_type !== 'video'"
                                :src="media.url"
                                :alt="media.note || 'Preuve'"
                                class="h-40 w-full rounded-sm border border-stone-200 object-cover dark:border-neutral-700" />
                            <video v-else controls class="h-40 w-full rounded-sm border border-stone-200 dark:border-neutral-700">
                                <source :src="media.url" />
                            </video>
                            <div class="mt-3 space-y-1 text-xs text-stone-500 dark:text-neutral-400">
                                <div>
                                    <span class="text-stone-700 dark:text-neutral-200">Type :</span>
                                    {{ proofType(media.type) }}
                                </div>
                                <div>
                                    <span class="text-stone-700 dark:text-neutral-200">Source :</span>
                                    {{ proofSource(media.source) }}
                                </div>
                                <div v-if="media.uploaded_by">
                                    <span class="text-stone-700 dark:text-neutral-200">Par :</span>
                                    {{ media.uploaded_by }}
                                </div>
                                <div v-if="media.note">
                                    <span class="text-stone-700 dark:text-neutral-200">Note :</span>
                                    {{ media.note }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                        Aucune preuve disponible pour cette tache.
                    </div>
                </div>

                <div v-if="!tasks.length"
                    class="rounded-sm border border-stone-200 bg-white p-5 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                    Aucune tache disponible.
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
