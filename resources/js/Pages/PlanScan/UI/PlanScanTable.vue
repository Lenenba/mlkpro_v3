<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    scans: {
        type: Object,
        required: true,
    },
});

const formatDate = (value) => humanizeDate(value);

const displayCustomer = (customer) =>
    customer?.company_name ||
    `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim() ||
    'Unknown';

const statusLabel = (status) => {
    switch (status) {
        case 'ready':
            return 'Ready';
        case 'processing':
            return 'Processing';
        case 'failed':
            return 'Failed';
        case 'new':
            return 'New';
        default:
            return status || 'Unknown';
    }
};

const statusClass = (status) => {
    switch (status) {
        case 'ready':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'processing':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'failed':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-400';
        case 'new':
            return 'bg-stone-100 text-stone-800 dark:bg-neutral-700 dark:text-neutral-200';
        default:
            return 'bg-stone-100 text-stone-800 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const isScanStale = (scan) => {
    if (scan?.status !== 'processing' || !scan?.updated_at) {
        return false;
    }

    const updatedAt = new Date(scan.updated_at);
    if (Number.isNaN(updatedAt.getTime())) {
        return false;
    }

    return Date.now() - updatedAt.getTime() >= 5 * 60 * 1000;
};

const openMenuScanId = ref(null);
const actionButtonRefs = ref({});
const menuRef = ref(null);
const menuStyle = ref({});
const pendingActionScanId = ref(null);
let listenersBound = false;

const activeMenuScan = computed(() =>
    (props.scans?.data || []).find((scan) => scan.id === openMenuScanId.value) || null
);
const scanRows = computed(() => (Array.isArray(props.scans?.data) ? props.scans.data : []));
const scanLinks = computed(() => props.scans?.links || []);
const currentPerPage = computed(() => resolveDataTablePerPage(props.scans?.per_page));
const scanResultsLabel = computed(() => `Showing ${props.scans?.from || 0}-${props.scans?.to || 0}`);

const setActionButtonRef = (scanId, element) => {
    if (element) {
        actionButtonRefs.value[scanId] = element;
        return;
    }

    delete actionButtonRefs.value[scanId];
};

const isActionPending = (scanId) => pendingActionScanId.value === scanId;

const updateMenuPosition = async () => {
    if (!openMenuScanId.value) {
        return;
    }

    await nextTick();

    const button = actionButtonRefs.value[openMenuScanId.value];
    if (!button || !menuRef.value) {
        return;
    }

    const rect = button.getBoundingClientRect();
    const menuRect = menuRef.value.getBoundingClientRect();
    const padding = 12;
    let left = rect.right - menuRect.width;
    left = Math.max(padding, left);

    if (left + menuRect.width > window.innerWidth - padding) {
        left = Math.max(padding, window.innerWidth - menuRect.width - padding);
    }

    let top = rect.bottom + 8;
    const maxTop = window.innerHeight - menuRect.height - padding;
    if (top > maxTop) {
        top = Math.max(padding, rect.top - menuRect.height - 8);
    }

    menuStyle.value = {
        left: `${left}px`,
        top: `${top}px`,
    };
};

const closeActionMenu = () => {
    openMenuScanId.value = null;
    menuStyle.value = {};
    teardownMenuListeners();
};

const toggleActionMenu = async (scanId) => {
    if (openMenuScanId.value === scanId) {
        closeActionMenu();
        return;
    }

    openMenuScanId.value = scanId;
    await updateMenuPosition();

    if (!listenersBound) {
        document.addEventListener('click', handleDocumentClick, true);
        document.addEventListener('keydown', handleKeydown, true);
        window.addEventListener('resize', updateMenuPosition);
        window.addEventListener('scroll', updateMenuPosition, true);
        listenersBound = true;
    }
};

const teardownMenuListeners = () => {
    if (!listenersBound) {
        return;
    }

    document.removeEventListener('click', handleDocumentClick, true);
    document.removeEventListener('keydown', handleKeydown, true);
    window.removeEventListener('resize', updateMenuPosition);
    window.removeEventListener('scroll', updateMenuPosition, true);
    listenersBound = false;
};

const handleDocumentClick = (event) => {
    const target = event.target;
    const button = openMenuScanId.value ? actionButtonRefs.value[openMenuScanId.value] : null;

    if (button?.contains(target) || menuRef.value?.contains(target)) {
        return;
    }

    closeActionMenu();
};

const handleKeydown = (event) => {
    if (event.key === 'Escape') {
        closeActionMenu();
    }
};

const finishAction = () => {
    pendingActionScanId.value = null;
};

const startAction = (scanId) => {
    pendingActionScanId.value = scanId;
    closeActionMenu();
};

const reanalyze = (scan, mode = 'retry') => {
    startAction(scan.id);

    router.post(route('plan-scans.reanalyze', scan.id), {
        mode,
    }, {
        preserveScroll: true,
        onFinish: finishAction,
    });
};

const deleteScan = (scan) => {
    closeActionMenu();

    if (!window.confirm(`Delete "${scan.job_title || `Plan scan #${scan.id}`}"?`)) {
        return;
    }

    startAction(scan.id);

    router.delete(route('plan-scans.destroy', scan.id), {
        preserveScroll: true,
        onFinish: finishAction,
    });
};

onBeforeUnmount(() => {
    teardownMenuListeners();
});
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700"
    >
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            <div class="space-y-1">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Plan scans</h2>
                <p class="text-xs text-stone-500 dark:text-neutral-400">
                    Scan a plan and generate quote variants with benchmarks.
                </p>
            </div>
            <Link
                :href="route('plan-scans.create')"
                class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700"
            >
                New scan
            </Link>
        </div>

        <AdminDataTable
            embedded
            :rows="scanRows"
            :links="scanLinks"
            :show-pagination="scanRows.length > 0"
            show-per-page
            :per-page="currentPerPage"
        >
            <template #empty>
                <div class="px-5 py-6 text-center text-sm text-stone-500 dark:text-neutral-400">
                    No plan scans found.
                </div>
            </template>

            <template #head>
                <tr>
                    <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                        Project
                    </th>
                    <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                        Customer
                    </th>
                    <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                        Trade
                    </th>
                    <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                        Status
                    </th>
                    <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                        Confidence
                    </th>
                    <th class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                        Updated
                    </th>
                    <th class="px-5 py-2.5 text-end text-sm font-normal text-stone-500 dark:text-neutral-500">
                        Actions
                    </th>
                </tr>
            </template>

            <template #body="{ rows }">
                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                    <tr v-for="scan in rows" :key="scan.id">
                        <td class="px-5 py-3">
                            <div class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                                {{ scan.job_title || `Plan scan #${scan.id}` }}
                            </div>
                            <div v-if="scan.plan_file_name" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ scan.plan_file_name }}
                            </div>
                        </td>
                        <td class="px-5 py-3 text-sm text-stone-700 dark:text-neutral-300">
                            <div v-if="scan.customer">
                                {{ displayCustomer(scan.customer) }}
                            </div>
                            <div v-else class="text-xs text-stone-500 dark:text-neutral-400">
                                Unassigned
                            </div>
                        </td>
                        <td class="px-5 py-3 text-sm text-stone-700 dark:text-neutral-300">
                            {{ scan.trade_type || '-' }}
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-sm px-2 py-0.5 text-xs font-medium" :class="statusClass(scan.status)">
                                    {{ statusLabel(scan.status) }}
                                </span>
                                <span
                                    v-if="isScanStale(scan)"
                                    class="inline-flex items-center rounded-sm bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-300"
                                >
                                    Stuck
                                </span>
                            </div>
                        </td>
                        <td class="px-5 py-3 text-sm text-stone-700 dark:text-neutral-300">
                            {{ scan.confidence_score ? `${scan.confidence_score}%` : '--' }}
                        </td>
                        <td class="px-5 py-3 text-sm text-stone-700 dark:text-neutral-300">
                            {{ formatDate(scan.updated_at) }}
                        </td>
                        <td class="px-5 py-3 text-end">
                            <button
                                :ref="(element) => setActionButtonRef(scan.id, element)"
                                type="button"
                                class="inline-flex size-8 items-center justify-center rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                :disabled="isActionPending(scan.id)"
                                :aria-expanded="openMenuScanId === scan.id ? 'true' : 'false'"
                                aria-label="Open actions menu"
                                @click="toggleActionMenu(scan.id)"
                            >
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="1" />
                                    <circle cx="12" cy="5" r="1" />
                                    <circle cx="12" cy="19" r="1" />
                                </svg>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </template>

            <template #pagination_prefix>
                <span class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ scanResultsLabel }}
                </span>
            </template>
        </AdminDataTable>

        <Teleport to="body">
            <div
                v-if="activeMenuScan"
                ref="menuRef"
                class="fixed z-[90] w-52 rounded-sm border border-stone-200 bg-white p-1 shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                :style="menuStyle"
                role="menu"
                aria-orientation="vertical"
            >
                <Link
                    :href="route('plan-scans.show', activeMenuScan.id)"
                    class="flex w-full items-center rounded-sm px-3 py-2 text-left text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    @click="closeActionMenu"
                >
                    View details
                </Link>
                <button
                    type="button"
                    class="flex w-full items-center rounded-sm px-3 py-2 text-left text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    role="menuitem"
                    @click="reanalyze(activeMenuScan, 'retry')"
                >
                    Retry AI
                </button>
                <button
                    type="button"
                    class="flex w-full items-center rounded-sm px-3 py-2 text-left text-[13px] text-sky-700 hover:bg-sky-50 dark:text-sky-300 dark:hover:bg-sky-950/20"
                    role="menuitem"
                    @click="reanalyze(activeMenuScan, 'escalate')"
                >
                    Escalate AI
                </button>
                <div class="my-1 h-px bg-stone-200 dark:bg-neutral-700"></div>
                <button
                    type="button"
                    class="flex w-full items-center rounded-sm px-3 py-2 text-left text-[13px] text-rose-700 hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-rose-950/20"
                    role="menuitem"
                    @click="deleteScan(activeMenuScan)"
                >
                    Delete scan
                </button>
            </div>
        </Teleport>

    </div>
</template>
