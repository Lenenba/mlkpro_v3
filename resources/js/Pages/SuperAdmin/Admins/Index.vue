<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import Modal from '@/Components/Modal.vue';

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
    stats: {
        type: Object,
        default: () => ({}),
    },
});

const showCreate = ref(false);
const createForm = useForm({
    name: '',
    email: '',
    role: props.roles[0] || 'support',
    permissions: [],
    require_2fa: false,
});

const editingAdmin = ref(null);
const showEdit = computed(() => Boolean(editingAdmin.value));
const editForm = useForm({
    role: '',
    permissions: [],
    is_active: true,
    require_2fa: false,
});

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const openCreate = () => {
    showCreate.value = true;
};

const closeCreate = () => {
    showCreate.value = false;
    createForm.reset('name', 'email', 'permissions', 'require_2fa');
    createForm.clearErrors();
};

const openEdit = (admin) => {
    editingAdmin.value = admin;
    editForm.role = admin.platform?.role || props.roles[0] || 'support';
    editForm.permissions = Array.isArray(admin.platform?.permissions) ? admin.platform.permissions : [];
    editForm.is_active = admin.platform?.is_active ?? true;
    editForm.require_2fa = admin.platform?.require_2fa ?? false;
};

const closeEdit = () => {
    editingAdmin.value = null;
    editForm.clearErrors();
};

const submitCreate = () => {
    createForm.post(route('superadmin.admins.store'), {
        preserveScroll: true,
        onSuccess: () => closeCreate(),
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
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Platform admins</h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            Create and manage delegated admin accounts.
                        </p>
                    </div>
                    <button type="button" @click="openCreate"
                        class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                        Add admin
                    </button>
                </div>
            </section>

            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-2 md:gap-3 lg:gap-5">
                <div class="p-4 bg-white border border-t-4 border-t-emerald-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Total admins</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.total) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-blue-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Active</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.active) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-rose-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Inactive</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.inactive) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-amber-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Require 2FA</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.require_2fa) }}
                    </p>
                </div>
            </div>

            <div
                class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:border-neutral-700 dark:bg-neutral-800">
                <div
                    class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                    <table class="min-w-full divide-y divide-stone-200 text-sm text-left text-stone-600 dark:divide-neutral-700 dark:text-neutral-300">
                        <thead class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                            <tr>
                                <th class="px-4 py-3">Name</th>
                                <th class="px-4 py-3">Email</th>
                                <th class="px-4 py-3">Role</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr v-for="admin in admins" :key="admin.id">
                                <td class="px-4 py-3 font-medium text-stone-800 dark:text-neutral-100">{{ admin.name }}</td>
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
                                    <div class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                                        <button type="button"
                                            class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                            aria-haspopup="menu" aria-expanded="false" aria-label="Actions">
                                            <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="1" />
                                                <circle cx="12" cy="5" r="1" />
                                                <circle cx="12" cy="19" r="1" />
                                            </svg>
                                        </button>
                                        <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-32 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                            role="menu" aria-orientation="vertical">
                                            <div class="p-1">
                                                <button type="button" @click="openEdit(admin)"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                    Edit
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="admins.length === 0">
                                <td colspan="5" class="px-4 py-6 text-center text-sm text-stone-500 dark:text-neutral-400">
                                    No platform admins yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <Modal :show="showCreate" @close="closeCreate">
                <div class="p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Add admin</h2>
                        <button type="button" @click="closeCreate" class="text-sm text-stone-500 dark:text-neutral-400">
                            Close
                        </button>
                    </div>
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
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Role</label>
                            <select v-model="createForm.role"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
                            </select>
                            <InputError class="mt-1" :message="createForm.errors.role" />
                        </div>
                        <div>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">Permissions</p>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <label v-for="(label, key) in permissions" :key="key"
                                    class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                    <Checkbox v-model:checked="createForm.permissions" :value="key" />
                                    <span>{{ label }}</span>
                                </label>
                            </div>
                            <InputError class="mt-1" :message="createForm.errors.permissions" />
                        </div>
                        <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                            <Checkbox v-model:checked="createForm.require_2fa" :value="true" />
                            <span>Require 2FA</span>
                        </label>
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="closeCreate"
                                class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                Cancel
                            </button>
                            <button type="submit"
                                class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                Create admin
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>

            <Modal :show="showEdit" @close="closeEdit">
                <div class="p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Edit admin</h2>
                        <button type="button" @click="closeEdit" class="text-sm text-stone-500 dark:text-neutral-400">
                            Close
                        </button>
                    </div>
                    <form class="mt-4 space-y-3" @submit.prevent="submitEdit">
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Role</label>
                            <select v-model="editForm.role"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
                            </select>
                            <InputError class="mt-1" :message="editForm.errors.role" />
                        </div>
                        <div>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">Permissions</p>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <label v-for="(label, key) in permissions" :key="key"
                                    class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                    <Checkbox v-model:checked="editForm.permissions" :value="key" />
                                    <span>{{ label }}</span>
                                </label>
                            </div>
                            <InputError class="mt-1" :message="editForm.errors.permissions" />
                        </div>
                        <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                            <Checkbox v-model:checked="editForm.is_active" :value="true" />
                            <span>Active</span>
                        </label>
                        <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                            <Checkbox v-model:checked="editForm.require_2fa" :value="true" />
                            <span>Require 2FA</span>
                        </label>
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="closeEdit"
                                class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                Cancel
                            </button>
                            <button type="submit"
                                class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                Save changes
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>
        </div>
    </AuthenticatedLayout>
</template>
