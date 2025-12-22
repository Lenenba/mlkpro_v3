<script setup>
import { reactive, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    tasks: Object,
    filters: Object,
    statuses: Array,
    teamMembers: Array,
    canCreate: Boolean,
    canManage: Boolean,
});

const createForm = useForm({
    title: '',
    description: '',
    status: 'todo',
    due_date: '',
    assigned_team_member_id: '',
});

const submitCreate = () => {
    createForm.post(route('task.store'), {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset('title', 'description', 'due_date', 'assigned_team_member_id');
            createForm.status = 'todo';
        },
    });
};

const taskForms = reactive({});

const initTaskForms = (rows) => {
    (rows || []).forEach((task) => {
        if (taskForms[task.id]) {
            return;
        }

        taskForms[task.id] = useForm({
            title: task.title || '',
            description: task.description || '',
            status: task.status || 'todo',
            due_date: task.due_date || '',
            assigned_team_member_id: task.assigned_team_member_id || '',
        });
    });
};

watch(
    () => props.tasks?.data,
    (rows) => initTaskForms(rows),
    { immediate: true }
);

const saveTask = (taskId) => {
    const form = taskForms[taskId];
    if (!form) return;

    form.put(route('task.update', taskId), { preserveScroll: true });
};

const updateStatusOnly = (taskId) => {
    const form = taskForms[taskId];
    if (!form) return;

    form.put(
        route('task.update', taskId),
        { preserveScroll: true, only: ['tasks', 'flash'] }
    );
};

const deleteTask = (taskId) => {
    router.delete(route('task.destroy', taskId), { preserveScroll: true });
};
</script>

<template>
    <Head title="Tasks" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-5">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-gray-800 dark:text-neutral-100">Tasks</h1>
            </div>

            <div v-if="canCreate"
                class="flex flex-col bg-white border border-gray-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                <div class="py-3 px-4 border-b border-gray-200 dark:border-neutral-700">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">Create a task</h2>
                </div>
                <div class="p-4 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="md:col-span-2">
                            <FloatingInput v-model="createForm.title" label="Title" />
                            <InputError class="mt-1" :message="createForm.errors.title" />
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs text-gray-500 dark:text-neutral-400">Description (optional)</label>
                            <textarea v-model="createForm.description"
                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                rows="3" />
                            <InputError class="mt-1" :message="createForm.errors.description" />
                        </div>

                        <div>
                            <label class="block text-xs text-gray-500 dark:text-neutral-400">Status</label>
                            <select v-model="createForm.status"
                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option v-for="status in statuses" :key="status" :value="status">
                                    {{ status }}
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

                        <div v-if="canManage" class="md:col-span-2">
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

                    <div class="flex justify-end">
                        <button type="button" @click="submitCreate" :disabled="createForm.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            Create
                        </button>
                    </div>
                </div>
            </div>

            <div
                class="flex flex-col bg-white border border-gray-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                <div class="py-3 px-4 border-b border-gray-200 dark:border-neutral-700">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">Tasks</h2>
                </div>

                <div class="p-4">
                    <div v-if="!tasks?.data?.length" class="text-sm text-gray-600 dark:text-neutral-400">
                        No tasks yet.
                    </div>

                    <div v-else class="space-y-3">
                        <div v-for="task in tasks.data" :key="task.id"
                            class="border border-gray-200 rounded-sm p-4 dark:border-neutral-700">
                            <div class="flex items-start justify-between gap-4">
                                <div class="w-full space-y-3">
                                    <div v-if="canManage">
                                        <FloatingInput v-model="taskForms[task.id].title" label="Title" />
                                        <InputError class="mt-1" :message="taskForms[task.id].errors.title" />
                                    </div>
                                    <div v-else class="text-sm font-medium text-gray-900 dark:text-neutral-100">
                                        {{ task.title }}
                                    </div>

                                    <div v-if="canManage">
                                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Description</label>
                                        <textarea v-model="taskForms[task.id].description"
                                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                            rows="2" />
                                        <InputError class="mt-1" :message="taskForms[task.id].errors.description" />
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                        <div>
                                            <label class="block text-xs text-gray-500 dark:text-neutral-400">Status</label>
                                            <select v-model="taskForms[task.id].status"
                                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                                @change="canManage ? null : updateStatusOnly(task.id)">
                                                <option v-for="status in statuses" :key="status" :value="status">
                                                    {{ status }}
                                                </option>
                                            </select>
                                            <InputError class="mt-1" :message="taskForms[task.id].errors.status" />
                                        </div>

                                        <div v-if="canManage">
                                            <label class="block text-xs text-gray-500 dark:text-neutral-400">Due date</label>
                                            <input type="date" v-model="taskForms[task.id].due_date"
                                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                                            <InputError class="mt-1" :message="taskForms[task.id].errors.due_date" />
                                        </div>
                                        <div v-else class="text-sm text-gray-600 dark:text-neutral-400">
                                            <span class="text-xs text-gray-500 dark:text-neutral-500">Due</span>
                                            <div class="mt-1">{{ task.due_date || '-' }}</div>
                                        </div>

                                        <div v-if="canManage">
                                            <label class="block text-xs text-gray-500 dark:text-neutral-400">Assignee</label>
                                            <select v-model="taskForms[task.id].assigned_team_member_id"
                                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                                <option value="">Unassigned</option>
                                                <option v-for="member in teamMembers" :key="member.id" :value="member.id">
                                                    {{ member.user?.name || `Member #${member.id}` }} ({{ member.role }})
                                                </option>
                                            </select>
                                            <InputError class="mt-1" :message="taskForms[task.id].errors.assigned_team_member_id" />
                                        </div>
                                        <div v-else class="text-sm text-gray-600 dark:text-neutral-400">
                                            <span class="text-xs text-gray-500 dark:text-neutral-500">Assignee</span>
                                            <div class="mt-1">{{ task.assignee?.user?.name || '-' }}</div>
                                        </div>
                                    </div>

                                    <div v-if="canManage" class="flex justify-end gap-2">
                                        <button type="button" @click="deleteTask(task.id)"
                                            class="py-2 px-3 text-sm font-medium rounded-sm border border-red-200 bg-white text-red-700 hover:bg-red-50 dark:bg-neutral-900 dark:border-red-900/50 dark:text-red-300 dark:hover:bg-red-900/20">
                                            Delete
                                        </button>
                                        <button type="button" @click="saveTask(task.id)"
                                            :disabled="taskForms[task.id].processing"
                                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                                            Save
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="tasks?.next_page_url || tasks?.prev_page_url" class="flex items-center justify-between pt-2">
                            <button type="button" :disabled="!tasks.prev_page_url"
                                @click="tasks.prev_page_url && router.get(tasks.prev_page_url, {}, { preserveScroll: true })"
                                class="rounded-sm border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                                Previous
                            </button>
                            <button type="button" :disabled="!tasks.next_page_url"
                                @click="tasks.next_page_url && router.get(tasks.next_page_url, {}, { preserveScroll: true })"
                                class="rounded-sm border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                                Next
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

