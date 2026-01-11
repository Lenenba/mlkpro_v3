<script setup>
import { Link, router } from '@inertiajs/vue3';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import { computed, ref } from 'vue';

dayjs.extend(relativeTime);
const props = defineProps({
    works: {
        type: Array,
        default: () => [],
    },
});

const processingId = ref(null);
const workItems = computed(() => (Array.isArray(props.works) ? props.works : []));

const statusLabels = {
    to_schedule: 'To schedule',
    scheduled: 'Scheduled',
    en_route: 'En route',
    in_progress: 'In progress',
    tech_complete: 'Tech complete',
    pending_review: 'Pending review',
    validated: 'Validated',
    auto_validated: 'Auto validated',
    dispute: 'Dispute',
    closed: 'Closed',
    cancelled: 'Cancelled',
    completed: 'Completed',
};

const formatStatus = (status) => statusLabels[status] || status || '-';

const statusTone = (status) => {
    if (['validated', 'auto_validated', 'closed'].includes(status)) {
        return 'success';
    }
    if (['dispute', 'cancelled'].includes(status)) {
        return 'danger';
    }
    if (['pending_review', 'tech_complete'].includes(status)) {
        return 'warning';
    }
    if (['scheduled', 'en_route', 'in_progress'].includes(status)) {
        return 'info';
    }
    return 'neutral';
};

const statusPillClass = (status) => {
    switch (statusTone(status)) {
        case 'success':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'danger':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-400';
        case 'warning':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'info':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-400';
        default:
            return 'bg-stone-100 text-stone-800 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const statusAccentClass = (status) => {
    switch (statusTone(status)) {
        case 'success':
            return 'border-l-emerald-400';
        case 'danger':
            return 'border-l-rose-400';
        case 'warning':
            return 'border-l-amber-400';
        case 'info':
            return 'border-l-sky-400';
        default:
            return 'border-l-stone-300 dark:border-l-neutral-600';
    }
};

const nextStatusFor = (status) => {
    switch (status) {
        case 'to_schedule':
            return 'scheduled';
        case 'scheduled':
            return 'en_route';
        case 'en_route':
            return 'in_progress';
        case 'in_progress':
            return 'tech_complete';
        case 'tech_complete':
            return 'pending_review';
        case 'pending_review':
            return 'validated';
        default:
            return null;
    }
};

const hasInvoice = (work) => Boolean(work?.invoice?.id);

const doAction = (work, callback) => {
    if (!work?.id || processingId.value) {
        return;
    }

    processingId.value = work.id;
    callback({
        preserveScroll: true,
        onFinish: () => {
            processingId.value = null;
        },
    });
};

const updateStatus = (work, status) => {
    doAction(work, (options) => {
        router.post(route('work.status', work.id), { status }, options);
    });
};

const createInvoice = (work) => {
    doAction(work, (options) => {
        router.post(route('invoice.store-from-work', work.id), {}, options);
    });
};

const destroyWork = (work) => {
    if (!confirm('Delete this job?')) {
        return;
    }

    doAction(work, (options) => {
        router.delete(route('work.destroy', work.id), options);
    });
};

const workMeta = (work) => {
    if (work?.start_date) {
        return `Starts ${dayjs(work.start_date).fromNow()}`;
    }
    return `Created ${dayjs(work.created_at).fromNow()}`;
};

const isProcessing = (work) => processingId.value === work?.id;
</script>

<template>
    <div class="space-y-3">
        <div v-for="work in workItems" :key="work.id"
            class="rounded-sm border border-stone-200 border-l-4 bg-white p-4 shadow-sm dark:bg-neutral-900 dark:border-neutral-700"
            :class="statusAccentClass(work.status)">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <Link
                        :href="route('work.show', work.id)"
                        class="text-sm font-semibold text-stone-800 hover:underline dark:text-neutral-200"
                    >
                        {{ work.job_title || 'Job' }}
                    </Link>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ workMeta(work) }}
                    </div>
                </div>
                <div class="flex flex-col items-end gap-2">
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold"
                        :class="statusPillClass(work.status)">
                        {{ formatStatus(work.status) }}
                    </span>
                    <span v-if="hasInvoice(work)"
                        class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200">
                        Invoiced
                    </span>
                </div>
            </div>

            <div class="mt-3 flex items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                <div class="flex items-center gap-2">
                    <span>#{{ work.number || work.id }}</span>
                </div>
                <div class="hs-dropdown [--placement:bottom-right] relative inline-flex">
                    <button
                        :id="`hs-work-actions-${work.id}`"
                        type="button"
                        class="sm:p-1.5 sm:ps-3 size-7 sm:w-auto sm:h-auto inline-flex justify-center items-center gap-x-1 rounded-sm border border-stone-200 bg-white text-xs text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                        aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                        <span class="hidden sm:inline-block">More</span>
                        <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round">
                            <circle cx="12" cy="12" r="1" />
                            <circle cx="12" cy="5" r="1" />
                            <circle cx="12" cy="19" r="1" />
                        </svg>
                    </button>

                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-800"
                        role="menu" aria-orientation="vertical" :aria-labelledby="`hs-work-actions-${work.id}`">
                        <div class="p-1">
                            <Link
                                :href="route('work.show', work.id)"
                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-stone-800 hover:bg-stone-100 focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                            >
                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                                Open job
                            </Link>

                            <Link
                                :href="route('work.edit', work.id)"
                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-stone-800 hover:bg-stone-100 focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                            >
                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" />
                                    <path d="m15 5 4 4" />
                                </svg>
                                Edit job
                            </Link>

                            <Link
                                v-if="hasInvoice(work)"
                                :href="route('invoice.show', work.invoice.id)"
                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-stone-800 hover:bg-stone-100 focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                            >
                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" />
                                    <path d="M14 2v4a2 2 0 0 0 2 2h4" />
                                    <path d="M10 9H8" />
                                    <path d="M16 13H8" />
                                    <path d="M16 17H8" />
                                </svg>
                                View invoice
                            </Link>

                            <button
                                v-else
                                type="button"
                                :disabled="isProcessing(work)"
                                @click="createInvoice(work)"
                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                            >
                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" />
                                    <path d="M14 2v4a2 2 0 0 0 2 2h4" />
                                    <path d="M12 10v6" />
                                    <path d="M9 13h6" />
                                </svg>
                                Generate invoice
                            </button>

                            <div class="my-1 border-t border-stone-200 dark:border-neutral-700"></div>

                            <button
                                v-if="nextStatusFor(work.status)"
                                type="button"
                                :disabled="isProcessing(work)"
                                @click="updateStatus(work, nextStatusFor(work.status))"
                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                            >
                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M5 12h14" />
                                    <path d="m12 5 7 7-7 7" />
                                </svg>
                                Next: {{ formatStatus(nextStatusFor(work.status)) }}
                            </button>

                            <button
                                v-if="work.status !== 'dispute'"
                                type="button"
                                :disabled="isProcessing(work)"
                                @click="updateStatus(work, 'dispute')"
                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                            >
                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m3 3 18 18" />
                                    <path d="M10.5 8.5a2.12 2.12 0 0 1 3 3" />
                                    <path d="M9 12a3 3 0 0 1 4.4-2.6" />
                                    <path d="M17.4 17.4A10 10 0 0 1 2 12s3-7 10-7a9.9 9.9 0 0 1 2.5.3" />
                                </svg>
                                Mark as dispute
                            </button>

                            <button
                                v-if="work.status !== 'closed'"
                                type="button"
                                :disabled="isProcessing(work)"
                                @click="updateStatus(work, 'closed')"
                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                            >
                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 6 9 17l-5-5" />
                                </svg>
                                Close job
                            </button>

                            <button
                                v-if="work.status !== 'cancelled'"
                                type="button"
                                :disabled="isProcessing(work)"
                                @click="updateStatus(work, 'cancelled')"
                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                            >
                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10" />
                                    <path d="m15 9-6 6" />
                                    <path d="m9 9 6 6" />
                                </svg>
                                Cancel job
                            </button>

                            <div class="my-1 border-t border-stone-200 dark:border-neutral-700"></div>

                            <button type="button"
                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-red-600 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-red-500 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                :disabled="isProcessing(work)"
                                @click="destroyWork(work)">
                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 6h18" />
                                    <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" />
                                    <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" />
                                    <line x1="10" x2="10" y1="11" y2="17" />
                                    <line x1="14" x2="14" y1="11" y2="17" />
                                </svg>
                                Delete job
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
