<script setup>
import { computed, ref, watch } from 'vue';
import { prepareMediaFile, MEDIA_LIMITS } from '@/utils/media';
import { Link, router, useForm } from '@inertiajs/vue3';
import Modal from '@/Components/UI/Modal.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingNumberInput from '@/Components/FloatingNumberInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import DatePicker from '@/Components/DatePicker.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    tasks: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    statuses: {
        type: Array,
        default: () => [],
    },
    teamMembers: {
        type: Array,
        default: () => [],
    },
    works: {
        type: Array,
        default: () => [],
    },
    materialProducts: {
        type: Array,
        default: () => [],
    },
    count: {
        type: Number,
        default: null,
    },
    canCreate: {
        type: Boolean,
        default: false,
    },
    canManage: {
        type: Boolean,
        default: false,
    },
    canDelete: {
        type: Boolean,
        default: false,
    },
    canEditStatus: {
        type: Boolean,
        default: false,
    },
});

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
});
const isLoading = ref(false);

const filterPayload = () => {
    const payload = {
        search: filterForm.search,
        status: filterForm.status,
    };

    Object.keys(payload).forEach((key) => {
        const value = payload[key];
        if (value === '' || value === null || value === undefined) {
            delete payload[key];
        }
    });

    return payload;
};

let filterTimeout;
const autoFilter = () => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }
    filterTimeout = setTimeout(() => {
        isLoading.value = true;
        router.get(route('task.index'), filterPayload(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                isLoading.value = false;
            },
        });
    }, 300);
};

watch(() => filterForm.search, autoFilter);
watch(() => filterForm.status, autoFilter);

const clearFilters = () => {
    filterForm.search = '';
    filterForm.status = '';
    autoFilter();
};

const statusLabel = (status) => {
    if (!status) {
        return '';
    }
    return String(status).replace('_', ' ');
};

const statusClasses = (status) => {
    switch (status) {
        case 'todo':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
        case 'in_progress':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
        case 'done':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        default:
            return 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
    }
};

const formatDate = (value) => humanizeDate(value) || String(value || '');

const canChangeStatus = computed(() => props.canManage || props.canEditStatus);

const workLabel = (work) => {
    if (!work) {
        return '';
    }
    const title = work.job_title || work.number || `Job #${work.id}`;
    const customerName = work.customer?.company_name
        || `${work.customer?.first_name || ''} ${work.customer?.last_name || ''}`.trim();
    return customerName ? `${title} - ${customerName}` : title;
};

const workOptions = computed(() =>
    (props.works || []).map((work) => ({
        id: work.id,
        name: workLabel(work),
    }))
);

const materialOptions = computed(() => [
    { id: '', name: 'Custom' },
    ...props.materialProducts.map((product) => ({
        id: product.id,
        name: product.name,
    })),
]);

const materialProductMap = computed(() => {
    const map = new Map();
    props.materialProducts.forEach((product) => {
        map.set(product.id, product);
    });
    return map;
});

const buildMaterial = (material = {}, index = 0) => ({
    id: material.id ?? null,
    product_id: material.product_id ?? '',
    label: material.label ?? '',
    description: material.description ?? '',
    unit: material.unit ?? '',
    quantity: material.quantity ?? 1,
    unit_price: material.unit_price ?? 0,
    billable: material.billable ?? true,
    sort_order: material.sort_order ?? index,
    source_service_id: material.source_service_id ?? null,
});

const mapTaskMaterials = (materials = []) =>
    materials.map((material, index) => buildMaterial(material, index));

const addMaterial = (form) => {
    form.materials.push(buildMaterial({}, form.materials.length));
};

const removeMaterial = (form, index) => {
    form.materials.splice(index, 1);
};

const applyMaterialDefaults = (material) => {
    if (!material.product_id) {
        return;
    }
    const product = materialProductMap.value.get(Number(material.product_id));
    if (!product) {
        return;
    }
    if (!material.label) {
        material.label = product.name;
    }
    if (!material.unit) {
        material.unit = product.unit || '';
    }
    if (!material.unit_price) {
        material.unit_price = product.price || 0;
    }
};

const normalizeMaterials = (materials) =>
    materials
        .map((material, index) => ({
            ...material,
            product_id: material.product_id || null,
            sort_order: index,
        }))
        .filter((material) => material.label || material.product_id);

const isTaskLocked = (task) => task?.status === 'done';

const createForm = useForm({
    work_id: '',
    standalone: false,
    title: '',
    description: '',
    status: 'todo',
    due_date: '',
    assigned_team_member_id: '',
    materials: [],
});

const normalizeWorkSelection = (form) => {
    if (form.standalone) {
        form.work_id = null;
    }

    if (!form.work_id) {
        form.work_id = null;
    }
};

watch(
    () => createForm.standalone,
    (value) => {
        if (value) {
            createForm.work_id = '';
        }
    }
);

watch(
    () => createForm.work_id,
    (value) => {
        if (value) {
            createForm.standalone = false;
        }
    }
);

const closeOverlay = (overlayId) => {
    if (window.HSOverlay) {
        window.HSOverlay.close(overlayId);
    }
};

const submitCreate = () => {
    if (createForm.processing) {
        return;
    }

    createForm.materials = normalizeMaterials(createForm.materials);
    normalizeWorkSelection(createForm);

    createForm.post(route('task.store'), {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset('work_id', 'standalone', 'title', 'description', 'due_date', 'assigned_team_member_id');
            createForm.status = 'todo';
            createForm.materials = [];
            closeOverlay('#hs-task-create');
        },
    });
};

const editingTaskId = ref(null);
const editForm = useForm({
    work_id: '',
    standalone: false,
    title: '',
    description: '',
    status: 'todo',
    due_date: '',
    assigned_team_member_id: '',
    customer_id: null,
    product_id: null,
    materials: [],
});

watch(
    () => editForm.standalone,
    (value) => {
        if (value) {
            editForm.work_id = '';
        }
    }
);

watch(
    () => editForm.work_id,
    (value) => {
        if (value) {
            editForm.standalone = false;
        }
    }
);

const openEditTask = (task) => {
    if (!props.canManage) {
        return;
    }
    if (isTaskLocked(task)) {
        return;
    }

    editingTaskId.value = task.id;
    editForm.clearErrors();

    editForm.title = task.title || '';
    editForm.description = task.description || '';
    editForm.status = task.status || 'todo';
    editForm.due_date = task.due_date || '';
    editForm.assigned_team_member_id = task.assigned_team_member_id || '';
    editForm.work_id = task.work_id ?? '';
    editForm.standalone = !task.work_id;
    editForm.customer_id = task.customer_id ?? null;
    editForm.product_id = task.product_id ?? null;
    editForm.materials = mapTaskMaterials(task.materials || []);

    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-task-edit');
    }
};

const submitEdit = () => {
    if (!editingTaskId.value || editForm.processing) {
        return;
    }

    editForm.materials = normalizeMaterials(editForm.materials);
    normalizeWorkSelection(editForm);

    editForm.put(route('task.update', editingTaskId.value), {
        preserveScroll: true,
        onSuccess: () => {
            closeOverlay('#hs-task-edit');
        },
    });
};

const setTaskStatus = (task, status) => {
    if (!canChangeStatus.value || task.status === status || isTaskLocked(task)) {
        return;
    }

    if (props.canManage) {
        router.put(
            route('task.update', task.id),
            {
                title: task.title || '',
                description: task.description || '',
                status,
                due_date: task.due_date || null,
                assigned_team_member_id: task.assigned_team_member_id ?? null,
                work_id: task.work_id ?? null,
                standalone: !task.work_id,
                customer_id: task.customer_id ?? null,
                product_id: task.product_id ?? null,
            },
            { preserveScroll: true, only: ['tasks', 'flash'] }
        );
        return;
    }

    router.put(
        route('task.update', task.id),
        { status },
        { preserveScroll: true, only: ['tasks', 'flash'] }
    );
};

const deleteTask = (task) => {
    if (!props.canDelete) {
        return;
    }
    if (!confirm(`Delete "${task.title}"?`)) {
        return;
    }

    router.delete(route('task.destroy', task.id), { preserveScroll: true });
};

const displayAssignee = (task) => task?.assignee?.user?.name || '-';

const proofTaskId = ref(null);
const proofForm = useForm({
    type: 'execution',
    file: null,
    note: '',
});

const openProofUpload = (task) => {
    if (!canChangeStatus.value) {
        return;
    }

    proofTaskId.value = task.id;
    proofForm.reset();
    proofForm.clearErrors();

    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-task-proof');
    }
};

const handleProofFile = async (event) => {
    const file = event.target.files?.[0] || null;
    proofForm.clearErrors('file');
    if (!file) {
        proofForm.file = null;
        return;
    }
    const result = await prepareMediaFile(file, {
        maxImageBytes: MEDIA_LIMITS.maxImageBytes,
        maxVideoBytes: MEDIA_LIMITS.maxVideoBytes,
    });
    if (result.error) {
        proofForm.setError('file', result.error);
        proofForm.file = null;
        return;
    }
    proofForm.file = result.file;
};

const submitProof = () => {
    if (!proofTaskId.value || proofForm.processing) {
        return;
    }

    proofForm.post(route('task.media.store', proofTaskId.value), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            closeOverlay('#hs-task-proof');
        },
    });
};
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
        <div class="space-y-3">
            <div class="flex flex-col lg:flex-row lg:items-center gap-2">
                <div class="flex-1">
                    <div class="relative">
                        <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                            <svg class="shrink-0 size-4 text-stone-500 dark:text-neutral-400"
                                xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8" />
                                <path d="m21 21-4.3-4.3" />
                            </svg>
                        </div>
                        <input type="text" v-model="filterForm.search"
                            class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                            placeholder="Search tasks">
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 justify-end">
                    <select v-model="filterForm.status"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:focus:ring-neutral-600">
                        <option value="">All statuses</option>
                        <option v-for="status in statuses" :key="status" :value="status">
                            {{ statusLabel(status) }}
                        </option>
                    </select>

                    <button type="button" @click="clearFilters"
                        class="py-2 px-3 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                        Clear
                    </button>

                    <button v-if="canCreate" type="button" data-hs-overlay="#hs-task-create"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                        + Add task
                    </button>
                </div>
            </div>
        </div>

        <div
            class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
            <div class="min-w-full inline-block align-middle">
                <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                    <thead>
                        <tr>
                            <th scope="col" class="min-w-[260px]">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Task
                                </div>
                            </th>
                            <th scope="col" class="min-w-36">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Status
                                </div>
                            </th>
                            <th scope="col" class="min-w-32">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Due
                                </div>
                            </th>
                            <th scope="col" class="min-w-44">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Assignee
                                </div>
                            </th>
                            <th scope="col" class="min-w-32">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Created
                                </div>
                            </th>
                            <th scope="col"></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                        <template v-if="isLoading">
                            <tr v-for="row in 6" :key="`skeleton-${row}`">
                                <td colspan="7" class="px-4 py-3">
                                    <div class="grid grid-cols-5 gap-4 animate-pulse">
                                        <div class="h-3 w-32 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-28 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <template v-else>
                        <tr v-for="task in tasks.data" :key="task.id">
                            <td class="size-px whitespace-nowrap px-4 py-2 text-start">
                                <div class="flex flex-col">
                                    <Link
                                        :href="`/tasks/${task.id}`"
                                        class="text-sm font-medium text-stone-800 hover:text-emerald-700 dark:text-neutral-200 dark:hover:text-emerald-300"
                                    >
                                        {{ task.title }}
                                    </Link>
                                    <span v-if="task.description" class="text-xs text-stone-500 dark:text-neutral-500 line-clamp-1">
                                        {{ task.description }}
                                    </span>
                                </div>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="py-1.5 px-2 inline-flex items-center text-xs font-medium rounded-full"
                                    :class="statusClasses(task.status)">
                                    {{ statusLabel(task.status) || 'todo' }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ formatDate(task.due_date) || '-' }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ displayAssignee(task) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ formatDate(task.created_at) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-end">
                                <div
                                    class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                                    <button type="button"
                                        class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                        aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                            height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="1" />
                                            <circle cx="12" cy="5" r="1" />
                                            <circle cx="12" cy="19" r="1" />
                                        </svg>
                                    </button>

                                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-44 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                        role="menu" aria-orientation="vertical">
                                        <div class="p-1">
                                            <div class="px-2 py-1 text-[11px] uppercase tracking-wide text-stone-400 dark:text-neutral-500">
                                                Set status
                                            </div>
                                            <button type="button" :disabled="!canChangeStatus || task.status === 'todo' || isTaskLocked(task)"
                                                @click="setTaskStatus(task, 'todo')"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                To do
                                            </button>
                                            <button type="button" :disabled="!canChangeStatus || task.status === 'in_progress' || isTaskLocked(task)"
                                                @click="setTaskStatus(task, 'in_progress')"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                In progress
                                            </button>
                                            <button type="button" :disabled="!canChangeStatus || task.status === 'done' || isTaskLocked(task)"
                                                @click="setTaskStatus(task, 'done')"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                Done
                                            </button>

                                            <template v-if="canManage || canDelete">
                                                <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                            </template>

                                            <button v-if="canManage" type="button" @click="openEditTask(task)"
                                                :disabled="isTaskLocked(task)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                Edit
                                            </button>
                                            <button v-if="canChangeStatus" type="button" @click="openProofUpload(task)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                Add proof
                                            </button>
                                            <button v-if="canDelete" type="button" @click="deleteTask(task)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800">
                                                Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="tasks.data.length > 0" class="mt-5 flex flex-wrap justify-between items-center gap-2">
            <p class="text-sm text-stone-800 dark:text-neutral-200">
                <span class="font-medium"> {{ count ?? tasks.data.length }} </span>
                <span class="text-stone-500 dark:text-neutral-500"> results</span>
            </p>

            <nav class="flex justify-end items-center gap-x-1" aria-label="Pagination">
                <Link :href="tasks.prev_page_url" v-if="tasks.prev_page_url">
                    <button type="button"
                        class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                        aria-label="Previous">
                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="m15 18-6-6 6-6" />
                        </svg>
                        <span class="sr-only">Previous</span>
                    </button>
                </Link>
                <div class="flex items-center gap-x-1">
                    <span
                        class="min-h-[38px] min-w-[38px] flex justify-center items-center bg-stone-100 text-stone-800 py-2 px-3 text-sm rounded-sm disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:text-white"
                        aria-current="page">{{ tasks.from }}</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">of</span>
                    <span class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">
                        {{ tasks.to }}
                    </span>
                </div>

                <Link :href="tasks.next_page_url" v-if="tasks.next_page_url">
                    <button type="button"
                        class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                        aria-label="Next">
                        <span class="sr-only">Next</span>
                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="m9 18 6-6-6-6" />
                        </svg>
                    </button>
                </Link>
            </nav>
        </div>
    </div>

    <Modal v-if="canCreate" :title="'Add task'" :id="'hs-task-create'">
        <form class="space-y-4" @submit.prevent="submitCreate">
            <div>
                <FloatingInput v-model="createForm.title" label="Title" :required="true" />
                <InputError class="mt-1" :message="createForm.errors.title" />
            </div>

            <div>
                <FloatingTextarea v-model="createForm.description" label="Description (optional)" />
                <InputError class="mt-1" :message="createForm.errors.description" />
            </div>

            <div>
                <label class="block text-xs text-stone-500 dark:text-neutral-400">Job</label>
                <select
                    v-model="createForm.work_id"
                    :disabled="createForm.standalone"
                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 disabled:bg-stone-100 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:disabled:bg-neutral-800"
                >
                    <option value="">Select a job</option>
                    <option v-for="work in workOptions" :key="work.id" :value="work.id">
                        {{ work.name }}
                    </option>
                </select>
                <InputError class="mt-1" :message="createForm.errors.work_id" />
                <label class="mt-2 flex items-center gap-2">
                    <Checkbox v-model:checked="createForm.standalone" />
                    <span class="text-xs text-stone-600 dark:text-neutral-400">Task ponctuelle (sans job)</span>
                </label>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-stone-500 dark:text-neutral-400">Status</label>
                    <select v-model="createForm.status"
                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option v-for="status in statuses" :key="status" :value="status">
                            {{ statusLabel(status) }}
                        </option>
                    </select>
                    <InputError class="mt-1" :message="createForm.errors.status" />
                </div>
                <div>
                    <DatePicker v-model="createForm.due_date" label="Due date" />
                    <InputError class="mt-1" :message="createForm.errors.due_date" />
                </div>
                <div v-if="teamMembers.length" class="md:col-span-2">
                    <label class="block text-xs text-stone-500 dark:text-neutral-400">Assignee</label>
                    <select v-model="createForm.assigned_team_member_id"
                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">Unassigned</option>
                        <option v-for="member in teamMembers" :key="member.id" :value="member.id">
                            {{ member.user?.name || `Member #${member.id}` }} ({{ member.role }})
                        </option>
                    </select>
                    <InputError class="mt-1" :message="createForm.errors.assigned_team_member_id" />
                </div>
            </div>

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-500">Materials</p>
                    <button type="button" @click="addMaterial(createForm)"
                        class="py-1.5 px-2.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                        Add material
                    </button>
                </div>
                <div v-if="createForm.materials.length" class="space-y-3">
                    <div v-for="(material, index) in createForm.materials" :key="material.id || index"
                        class="rounded-sm border border-stone-200 bg-stone-50 p-3 space-y-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <FloatingSelect
                                v-model="material.product_id"
                                :options="materialOptions"
                                label="Product"
                                @update:modelValue="applyMaterialDefaults(material)"
                            />
                            <FloatingInput v-model="material.label" label="Label" />
                            <FloatingNumberInput v-model="material.quantity" label="Quantity" :step="0.01" />
                            <FloatingNumberInput v-model="material.unit_price" label="Unit price" :step="0.01" />
                            <FloatingInput v-model="material.unit" label="Unit" />
                            <div class="flex items-center gap-2 p-2 rounded-sm border border-stone-200 bg-white dark:bg-neutral-900 dark:border-neutral-700">
                                <Checkbox v-model:checked="material.billable" />
                                <span class="text-sm text-stone-600 dark:text-neutral-400">Billable</span>
                            </div>
                        </div>
                        <FloatingTextarea v-model="material.description" label="Description (optional)" />
                        <div class="flex justify-end">
                            <button type="button" @click="removeMaterial(createForm, index)"
                                class="py-1.5 px-2.5 text-xs font-medium rounded-sm border border-red-200 bg-white text-red-600 hover:bg-red-50 dark:bg-neutral-800 dark:border-red-500/40 dark:text-red-400">
                                Remove
                            </button>
                        </div>
                    </div>
                </div>
                <p v-else class="text-xs text-stone-500 dark:text-neutral-500">
                    No materials yet.
                </p>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" data-hs-overlay="#hs-task-create"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                    Cancel
                </button>
                <button type="submit" :disabled="createForm.processing"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                    Create
                </button>
            </div>
        </form>
    </Modal>

    <Modal v-if="canManage" :title="'Edit task'" :id="'hs-task-edit'">
        <form class="space-y-4" @submit.prevent="submitEdit">
            <div>
                <FloatingInput v-model="editForm.title" label="Title" :required="true" />
                <InputError class="mt-1" :message="editForm.errors.title" />
            </div>

            <div>
                <FloatingTextarea v-model="editForm.description" label="Description (optional)" />
                <InputError class="mt-1" :message="editForm.errors.description" />
            </div>

            <div>
                <label class="block text-xs text-stone-500 dark:text-neutral-400">Job</label>
                <select
                    v-model="editForm.work_id"
                    :disabled="editForm.standalone"
                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 disabled:bg-stone-100 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:disabled:bg-neutral-800"
                >
                    <option value="">Select a job</option>
                    <option v-for="work in workOptions" :key="work.id" :value="work.id">
                        {{ work.name }}
                    </option>
                </select>
                <InputError class="mt-1" :message="editForm.errors.work_id" />
                <label class="mt-2 flex items-center gap-2">
                    <Checkbox v-model:checked="editForm.standalone" />
                    <span class="text-xs text-stone-600 dark:text-neutral-400">Task ponctuelle (sans job)</span>
                </label>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-stone-500 dark:text-neutral-400">Status</label>
                    <select v-model="editForm.status"
                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option v-for="status in statuses" :key="status" :value="status">
                            {{ statusLabel(status) }}
                        </option>
                    </select>
                    <InputError class="mt-1" :message="editForm.errors.status" />
                </div>
                <div>
                    <DatePicker v-model="editForm.due_date" label="Due date" />
                    <InputError class="mt-1" :message="editForm.errors.due_date" />
                </div>
                <div v-if="teamMembers.length" class="md:col-span-2">
                    <label class="block text-xs text-stone-500 dark:text-neutral-400">Assignee</label>
                    <select v-model="editForm.assigned_team_member_id"
                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">Unassigned</option>
                        <option v-for="member in teamMembers" :key="member.id" :value="member.id">
                            {{ member.user?.name || `Member #${member.id}` }} ({{ member.role }})
                        </option>
                    </select>
                    <InputError class="mt-1" :message="editForm.errors.assigned_team_member_id" />
                </div>
            </div>

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-500">Materials</p>
                    <button type="button" @click="addMaterial(editForm)"
                        class="py-1.5 px-2.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                        Add material
                    </button>
                </div>
                <div v-if="editForm.materials.length" class="space-y-3">
                    <div v-for="(material, index) in editForm.materials" :key="material.id || index"
                        class="rounded-sm border border-stone-200 bg-stone-50 p-3 space-y-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <FloatingSelect
                                v-model="material.product_id"
                                :options="materialOptions"
                                label="Product"
                                @update:modelValue="applyMaterialDefaults(material)"
                            />
                            <FloatingInput v-model="material.label" label="Label" />
                            <FloatingNumberInput v-model="material.quantity" label="Quantity" :step="0.01" />
                            <FloatingNumberInput v-model="material.unit_price" label="Unit price" :step="0.01" />
                            <FloatingInput v-model="material.unit" label="Unit" />
                            <div class="flex items-center gap-2 p-2 rounded-sm border border-stone-200 bg-white dark:bg-neutral-900 dark:border-neutral-700">
                                <Checkbox v-model:checked="material.billable" />
                                <span class="text-sm text-stone-600 dark:text-neutral-400">Billable</span>
                            </div>
                        </div>
                        <FloatingTextarea v-model="material.description" label="Description (optional)" />
                        <div class="flex justify-end">
                            <button type="button" @click="removeMaterial(editForm, index)"
                                class="py-1.5 px-2.5 text-xs font-medium rounded-sm border border-red-200 bg-white text-red-600 hover:bg-red-50 dark:bg-neutral-800 dark:border-red-500/40 dark:text-red-400">
                                Remove
                            </button>
                        </div>
                    </div>
                </div>
                <p v-else class="text-xs text-stone-500 dark:text-neutral-500">
                    No materials yet.
                </p>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" data-hs-overlay="#hs-task-edit"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                    Cancel
                </button>
                <button type="submit" :disabled="editForm.processing"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                    Save
                </button>
            </div>
        </form>
    </Modal>

    <Modal v-if="canChangeStatus" :title="'Add task proof'" :id="'hs-task-proof'">
        <form class="space-y-4" @submit.prevent="submitProof">
            <div>
                <label class="block text-xs text-stone-500 dark:text-neutral-400">Type</label>
                <select v-model="proofForm.type"
                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                    <option value="execution">Execution</option>
                    <option value="completion">Completion</option>
                    <option value="other">Other</option>
                </select>
                <InputError class="mt-1" :message="proofForm.errors.type" />
            </div>

            <div>
                <label class="block text-xs text-stone-500 dark:text-neutral-400">File (photo or video)</label>
                <input type="file" @change="handleProofFile" accept="image/*,video/*"
                    class="mt-1 block w-full text-sm text-stone-600 file:mr-4 file:py-2 file:px-3 file:rounded-sm file:border-0 file:text-sm file:font-medium file:bg-stone-100 file:text-stone-700 hover:file:bg-stone-200 dark:text-neutral-300 dark:file:bg-neutral-800 dark:file:text-neutral-200" />
                <InputError class="mt-1" :message="proofForm.errors.file" />
            </div>

            <div>
                <FloatingInput v-model="proofForm.note" label="Note (optional)" />
                <InputError class="mt-1" :message="proofForm.errors.note" />
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" data-hs-overlay="#hs-task-proof"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                    Cancel
                </button>
                <button type="submit" :disabled="proofForm.processing"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                    Upload
                </button>
            </div>
        </form>
    </Modal>
</template>


