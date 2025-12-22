<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
import FloatingInput from '@/Components/FloatingInput.vue';

const props = defineProps({
    admins: {
        type: Array,
        default: () => [],
    },
    roles: {
        type: Array,
        default: () => [],
    },
    permissions: {
        type: Object,
        default: () => ({}),
    },
});

const createForm = useForm({
    name: '',
    email: '',
    role: props.roles[0] || 'support',
    permissions: [],
    require_2fa: false,
});

const editingAdmin = ref(null);
const editForm = useForm({
    role: '',
    permissions: [],
    is_active: true,
    require_2fa: false,
});

const openEdit = (admin) => {
    editingAdmin.value = admin;
    editForm.role = admin.platform?.role || props.roles[0] || 'support';
    editForm.permissions = Array.isArray(admin.platform?.permissions) ? admin.platform.permissions : [];
    editForm.is_active = admin.platform?.is_active ?? true;
    editForm.require_2fa = admin.platform?.require_2fa ?? false;
};

const closeEdit = () => {
    editingAdmin.value = null;
};

const submitCreate = () => {
    createForm.post(route('superadmin.admins.store'), {
        preserveScroll: true,
        onSuccess: () => createForm.reset('name', 'email', 'permissions', 'require_2fa'),
    });
};

const submitEdit = () => {
    if (!editingAdmin.value) {
        return;
    }
    editForm.put(route('superadmin.admins.update', editingAdmin.value.id), {
        preserveScroll: true,
        onSuccess: () => closeEdit(),
    });
};
</script>

<template>
    <Head title="Platform Admins" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-neutral-100">Platform admins</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-neutral-400">
                    Create and manage delegated admin accounts.
                </p>
            </div>

            <div class="rounded-sm border border-gray-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">Add admin</h2>
                <form class="mt-4 space-y-3" @submit.prevent="submitCreate">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <FloatingInput v-model="createForm.name" label="Name" />
                            <InputError class="mt-1" :message="createForm.errors.name" />
                        </div>
                        <div>
                            <FloatingInput v-model="createForm.email" label="Email" />
                            <InputError class="mt-1" :message="createForm.errors.email" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Role</label>
                        <select v-model="createForm.role"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
                        </select>
                        <InputError class="mt-1" :message="createForm.errors.role" />
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-neutral-400">Permissions</p>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <label v-for="(label, key) in permissions" :key="key"
                                class="flex items-center gap-2 text-sm text-gray-700 dark:text-neutral-200">
                                <Checkbox v-model:checked="createForm.permissions" :value="key" />
                                <span>{{ label }}</span>
                            </label>
                        </div>
                        <InputError class="mt-1" :message="createForm.errors.permissions" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-neutral-200">
                        <Checkbox v-model:checked="createForm.require_2fa" :value="true" />
                        <span>Require 2FA</span>
                    </label>
                    <button type="submit"
                        class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                        Create admin
                    </button>
                </form>
            </div>

            <div class="rounded-sm border border-gray-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-600 dark:text-neutral-300">
                        <thead class="text-xs uppercase text-gray-500 dark:text-neutral-400">
                            <tr>
                                <th class="px-4 py-3">Name</th>
                                <th class="px-4 py-3">Email</th>
                                <th class="px-4 py-3">Role</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="admin in admins" :key="admin.id" class="border-t border-gray-200 dark:border-neutral-700">
                                <td class="px-4 py-3 font-medium text-gray-800 dark:text-neutral-100">{{ admin.name }}</td>
                                <td class="px-4 py-3">{{ admin.email }}</td>
                                <td class="px-4 py-3">{{ admin.platform?.role || 'n/a' }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs"
                                        :class="admin.platform?.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'">
                                        {{ admin.platform?.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" @click="openEdit(admin)"
                                        class="text-sm font-medium text-green-700 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300">
                                        Edit
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="admins.length === 0">
                                <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-neutral-400">
                                    No platform admins yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="editingAdmin" class="rounded-sm border border-gray-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">Edit admin</h2>
                    <button type="button" @click="closeEdit" class="text-sm text-gray-500 dark:text-neutral-400">Close</button>
                </div>
                <form class="mt-4 space-y-3" @submit.prevent="submitEdit">
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Role</label>
                        <select v-model="editForm.role"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
                        </select>
                        <InputError class="mt-1" :message="editForm.errors.role" />
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-neutral-400">Permissions</p>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <label v-for="(label, key) in permissions" :key="key"
                                class="flex items-center gap-2 text-sm text-gray-700 dark:text-neutral-200">
                                <Checkbox v-model:checked="editForm.permissions" :value="key" />
                                <span>{{ label }}</span>
                            </label>
                        </div>
                        <InputError class="mt-1" :message="editForm.errors.permissions" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-neutral-200">
                        <Checkbox v-model:checked="editForm.is_active" :value="true" />
                        <span>Active</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-neutral-200">
                        <Checkbox v-model:checked="editForm.require_2fa" :value="true" />
                        <span>Require 2FA</span>
                    </label>
                    <button type="submit"
                        class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                        Save changes
                    </button>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
