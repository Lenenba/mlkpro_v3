<script setup>
import { Link, router } from '@inertiajs/vue3';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import { ref } from 'vue';

dayjs.extend(relativeTime);
const props = defineProps({
    works: Object,
});

const processingId = ref(null);

const statusLabels = {
    to_schedule: 'À planifier',
    scheduled: 'Planifié',
    en_route: 'En route',
    in_progress: 'En cours',
    tech_complete: 'Tech terminé',
    pending_review: 'En attente de validation',
    validated: 'Validé',
    auto_validated: 'Auto validé',
    dispute: 'Litige',
    closed: 'Clôturé',
    cancelled: 'Annulé',
    completed: 'Terminé (ancien)',
};

const formatStatus = (status) => statusLabels[status] || status || '-';

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

const workTitle = (work) => `${formatStatus(work?.status)} - ${work?.job_title || 'Job'}`;

const workMeta = (work) => {
    if (work?.start_date) {
        return `Starts ${dayjs(work.start_date).fromNow()}`;
    }
    return `Created ${dayjs(work.created_at).fromNow()}`;
};

const isProcessing = (work) => processingId.value === work?.id;
</script>


<template>
    <div class="grid grid-cols-2 gap-5 lg:gap-1 ">
        <!-- Card -->
        <div v-for="work in works" :key="work.id"
            class="relative group bg-white border border-stone-200 -mt-px first:mt-0 first:rounded-t-xl last:rounded-b-xl dark:bg-neutral-900 dark:border-neutral-700">
            <Link
                :href="route('work.show', work.id)"
                class="group p-3 flex items-center gap-x-4 group-first:rounded-t-sm group-last:rounded-b-xl hover:bg-stone-100 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
            >

                <div class="grow pe-12">
                    <p class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                        {{ workTitle(work) }}
                    </p>
                    <ul class="mt-1 text-xs text-stone-500 dark:text-neutral-500">
                        <li
                            class="inline-block relative pe-3 last:pe-0 first-of-type:before:hidden before:absolute before:top-1/2 before:-start-2 before:-translate-y-1/2 before:w-px before:h-3 before:bg-stone-300 before:rounded-full dark:before:bg-neutral-600">
                            {{ workMeta(work) }}
                        </li>
                        <li
                            class="hidden sm:inline-block relative pe-3 last:pe-0 first-of-type:before:hidden before:absolute before:top-1/2 before:-start-2 before:-translate-y-1/2 before:w-px before:h-3 before:bg-stone-300 before:rounded-full dark:before:bg-neutral-600">
                            #{{ work.number || work.id }}
                        </li>
                    </ul>
                </div>
            </Link>

            <!-- More Dropdown -->
            <div class="absolute top-3 end-3">
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
            <!-- End More Dropdown -->
        </div>
        <!-- End Card -->
    </div>
</template>
