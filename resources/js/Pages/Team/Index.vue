<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    teamMembers: Array,
    availablePermissions: Array,
});

const createForm = useForm({
    name: '',
    email: '',
    password: '',
    role: 'member',
    title: '',
    phone: '',
    permissions: ['jobs.view', 'tasks.view', 'tasks.edit'],
});

const submitCreate = () => {
    createForm.post(route('team.store'), {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset('name', 'email', 'password', 'title', 'phone');
            createForm.role = 'member';
        },
    });
};

const memberForms = reactive({});

const initMemberForms = () => {
    (props.teamMembers ?? []).forEach((member) => {
        memberForms[member.id] = useForm({
            name: member.user?.name ?? '',
            email: member.user?.email ?? '',
            password: '',
            role: member.role ?? 'member',
            title: member.title ?? '',
            phone: member.phone ?? '',
            permissions: member.permissions ?? [],
            is_active: Boolean(member.is_active),
        });
    });
};

initMemberForms();

const saveMember = (memberId) => {
    const form = memberForms[memberId];
    if (!form) {
        return;
    }

    form.put(route('team.update', memberId), {
        preserveScroll: true,
        onSuccess: () => {
            form.password = '';
        },
    });
};

const deactivateMember = (memberId) => {
    router.delete(route('team.destroy', memberId), { preserveScroll: true });
};
</script>

<template>
    <Head title="Team" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-5">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-gray-800 dark:text-neutral-100">Team</h1>
            </div>

            <div
                class="flex flex-col bg-white border border-gray-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                <div class="py-3 px-4 border-b border-gray-200 dark:border-neutral-700">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">Add a team member</h2>
                </div>
                <div class="p-4 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <FloatingInput v-model="createForm.name" label="Name" />
                        <FloatingInput v-model="createForm.email" label="Email" />
                        <FloatingInput v-model="createForm.password" label="Password (optional)" />
                        <div class="md:col-span-2">
                            <label class="block text-xs text-gray-500 dark:text-neutral-500">Role</label>
                            <select v-model="createForm.role"
                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option value="admin">Administrator</option>
                                <option value="member">Team member</option>
                            </select>
                        </div>
                        <FloatingInput v-model="createForm.title" label="Title (optional)" />
                        <FloatingInput v-model="createForm.phone" label="Phone (optional)" />
                    </div>

                    <div>
                        <p class="text-xs text-gray-500 dark:text-neutral-500">Permissions</p>
                        <div class="mt-2 space-y-2">
                            <label v-for="permission in availablePermissions" :key="permission.id"
                                class="flex items-center gap-2 text-sm text-gray-700 dark:text-neutral-200">
                                <Checkbox v-model:checked="createForm.permissions" :value="permission.id" />
                                <span>{{ permission.name }}</span>
                            </label>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="button" @click="submitCreate" :disabled="createForm.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            Add member
                        </button>
                    </div>
                </div>
            </div>

            <div
                class="flex flex-col bg-white border border-gray-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                <div class="py-3 px-4 border-b border-gray-200 dark:border-neutral-700">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">Members</h2>
                </div>

                <div class="p-4">
                    <div v-if="!teamMembers?.length" class="text-sm text-gray-600 dark:text-neutral-400">
                        No team members yet.
                    </div>

                    <div v-else class="space-y-4">
                        <div v-for="member in teamMembers" :key="member.id"
                            class="border border-gray-200 rounded-sm p-4 dark:border-neutral-700">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <FloatingInput v-model="memberForms[member.id].name" label="Name" />
                                <FloatingInput v-model="memberForms[member.id].email" label="Email" />
                                <FloatingInput v-model="memberForms[member.id].password" label="New password (optional)" />
                                <div class="md:col-span-2">
                                    <label class="block text-xs text-gray-500 dark:text-neutral-500">Role</label>
                                    <select v-model="memberForms[member.id].role"
                                        class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                        <option value="admin">Administrator</option>
                                        <option value="member">Team member</option>
                                    </select>
                                </div>
                                <FloatingInput v-model="memberForms[member.id].title" label="Title" />
                                <FloatingInput v-model="memberForms[member.id].phone" label="Phone" />
                                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-neutral-200">
                                    <Checkbox v-model:checked="memberForms[member.id].is_active" />
                                    <span>Active</span>
                                </label>
                            </div>

                            <div class="mt-4">
                                <p class="text-xs text-gray-500 dark:text-neutral-500">Permissions</p>
                                <div class="mt-2 space-y-2">
                                    <label v-for="permission in availablePermissions" :key="permission.id"
                                        class="flex items-center gap-2 text-sm text-gray-700 dark:text-neutral-200">
                                        <Checkbox v-model:checked="memberForms[member.id].permissions"
                                            :value="permission.id" />
                                        <span>{{ permission.name }}</span>
                                    </label>
                                </div>
                            </div>

                            <div class="mt-4 flex justify-end gap-2">
                                <button type="button" @click="deactivateMember(member.id)"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-red-200 bg-white text-red-700 hover:bg-red-50 dark:bg-neutral-900 dark:border-red-900/50 dark:text-red-300 dark:hover:bg-red-900/20">
                                    Deactivate
                                </button>
                                <button type="button" @click="saveMember(member.id)"
                                    :disabled="memberForms[member.id].processing"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                                    Save
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
