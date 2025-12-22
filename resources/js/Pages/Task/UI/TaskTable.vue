<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import Modal from '@/Components/UI/Modal.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';

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
        router.get(route('task.index'), filterPayload(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
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
        case 'done':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'in_progress':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
        default:
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
    }
};

const formatDate = (value) => {
    if (!value) {
        return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }
    return date.toLocaleDateString();
};

const canChangeStatus = computed(() => props.canManage || props.canEditStatus);

const createForm = useForm({
    title: '',
    description: '',
    status: 'todo',
    due_date: '',
    assigned_team_member_id: '',
});

const closeOverlay = (overlayId) => {
    if (window.HSOverlay) {
        window.HSOverlay.close(overlayId);
    }
};

const submitCreate = () => {
    if (createForm.processing) {
        return;
    }

    createForm.post(route('task.store'), {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset('title', 'description', 'due_date', 'assigned_team_member_id');
            createForm.status = 'todo';
            closeOverlay('#hs-task-create');
        },
    });
};

const editingTaskId = ref(null);
const editForm = useForm({
    title: '',
    description: '',
    status: 'todo',
    due_date: '',
    assigned_team_member_id: '',
    customer_id: null,
    product_id: null,
});

const openEditTask = (task) => {
    if (!props.canManage) {
        return;
    }

    editingTaskId.value = task.id;
    editForm.clearErrors();

    editForm.title = task.title || '';
    editForm.description = task.description || '';
    editForm.status = task.status || 'todo';
    editForm.due_date = task.due_date || '';
    editForm.assigned_team_member_id = task.assigned_team_member_id || '';
    editForm.customer_id = task.customer_id ?? null;
    editForm.product_id = task.product_id ?? null;

    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-task-edit');
    }
};

const submitEdit = () => {
    if (!editingTaskId.value || editForm.processing) {
        return;
    }

    editForm.put(route('task.update', editingTaskId.value), {
        preserveScroll: true,
        onSuccess: () => {
            closeOverlay('#hs-task-edit');
        },
    });
};

const setTaskStatus = (task, status) => {
    if (!canChangeStatus.value || task.status === status) {
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
                            class="py-[7px] ps-10 pe-8 block w-full bg-stone-100 border-transparent rounded-lg text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:border-transparent dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                            placeholder="Search tasks">
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 justify-end">
                    <select v-model="filterForm.status"
                        class="py-2 ps-3 pe-8 bg-stone-100 border-transparent rounded-lg text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200 dark:focus:ring-neutral-600">
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
                        <tr v-for="task in tasks.data" :key="task.id">
                            <td class="size-px whitespace-nowrap px-4 py-2 text-start">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                                        {{ task.title }}
                                    </span>
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
                                        class="size-7 inline-flex justify-center items-center gap-x-2 rounded-lg border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                        aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                            height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="1" />
                                            <circle cx="12" cy="5" r="1" />
                                            <circle cx="12" cy="19" r="1" />
                                        </svg>
                                    </button>

                                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-44 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                        role="menu" aria-orientation="vertical">
                                        <div class="p-1">
                                            <div class="px-2 py-1 text-[11px] uppercase tracking-wide text-stone-400 dark:text-neutral-500">
                                                Set status
                                            </div>
                                            <button type="button" :disabled="!canChangeStatus || task.status === 'todo'"
                                                @click="setTaskStatus(task, 'todo')"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                To do
                                            </button>
                                            <button type="button" :disabled="!canChangeStatus || task.status === 'in_progress'"
                                                @click="setTaskStatus(task, 'in_progress')"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                In progress
                                            </button>
                                            <button type="button" :disabled="!canChangeStatus || task.status === 'done'"
                                                @click="setTaskStatus(task, 'done')"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                Done
                                            </button>

                                            <template v-if="canManage || canDelete">
                                                <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                            </template>

                                            <button v-if="canManage" type="button" @click="openEditTask(task)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                Edit
                                            </button>
                                            <button v-if="canDelete" type="button" @click="deleteTask(task)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800">
                                                Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
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
                        class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-lg text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
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
                <FloatingInput v-model="createForm.title" label="Title" />
                <InputError class="mt-1" :message="createForm.errors.title" />
            </div>

            <div>
                <FloatingTextarea v-model="createForm.description" label="Description (optional)" />
                <InputError class="mt-1" :message="createForm.errors.description" />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-gray-500 dark:text-neutral-400">Status</label>
                    <select v-model="createForm.status"
                        class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option v-for="status in statuses" :key="status" :value="status">
                            {{ statusLabel(status) }}
                        </option>
                    </select>
                    <InputError class="mt-1" :message="createForm.errors.status" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500 dark:text-neutral-400">Due date</label>
                    <input type="date" v-model="createForm.due_date"
                        class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                    <InputError class="mt-1" :message="createForm.errors.due_date" />
                </div>
                <div v-if="teamMembers.length" class="md:col-span-2">
                    <label class="block text-xs text-gray-500 dark:text-neutral-400">Assignee</label>
                    <select v-model="createForm.assigned_team_member_id"
                        class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">Unassigned</option>
                        <option v-for="member in teamMembers" :key="member.id" :value="member.id">
                            {{ member.user?.name || `Member #${member.id}` }} ({{ member.role }})
                        </option>
                    </select>
                    <InputError class="mt-1" :message="createForm.errors.assigned_team_member_id" />
                </div>
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
                <FloatingInput v-model="editForm.title" label="Title" />
                <InputError class="mt-1" :message="editForm.errors.title" />
            </div>

            <div>
                <FloatingTextarea v-model="editForm.description" label="Description (optional)" />
                <InputError class="mt-1" :message="editForm.errors.description" />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-gray-500 dark:text-neutral-400">Status</label>
                    <select v-model="editForm.status"
                        class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option v-for="status in statuses" :key="status" :value="status">
                            {{ statusLabel(status) }}
                        </option>
                    </select>
                    <InputError class="mt-1" :message="editForm.errors.status" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500 dark:text-neutral-400">Due date</label>
                    <input type="date" v-model="editForm.due_date"
                        class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                    <InputError class="mt-1" :message="editForm.errors.due_date" />
                </div>
                <div v-if="teamMembers.length" class="md:col-span-2">
                    <label class="block text-xs text-gray-500 dark:text-neutral-400">Assignee</label>
                    <select v-model="editForm.assigned_team_member_id"
                        class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">Unassigned</option>
                        <option v-for="member in teamMembers" :key="member.id" :value="member.id">
                            {{ member.user?.name || `Member #${member.id}` }} ({{ member.role }})
                        </option>
                    </select>
                    <InputError class="mt-1" :message="editForm.errors.assigned_team_member_id" />
                </div>
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
</template>

