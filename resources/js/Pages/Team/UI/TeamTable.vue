<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Modal from '@/Components/UI/Modal.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    teamMembers: {
        type: Array,
        default: () => [],
    },
    availablePermissions: {
        type: Array,
        default: () => [],
    },
});

const query = ref('');

const normalize = (value) => String(value || '').toLowerCase();
const filteredMembers = computed(() => {
    const term = normalize(query.value).trim();
    if (!term) {
        return props.teamMembers || [];
    }

    return (props.teamMembers || []).filter((member) => {
        const fields = [
            member.user?.name,
            member.user?.email,
            member.role,
            member.title,
            member.phone,
        ].map(normalize);

        return fields.some((field) => field.includes(term));
    });
});

const closeOverlay = (overlayId) => {
    if (window.HSOverlay) {
        window.HSOverlay.close(overlayId);
    }
};

const createForm = useForm({
    name: '',
    email: '',
    role: 'member',
    title: '',
    phone: '',
    permissions: ['jobs.view', 'tasks.view', 'tasks.edit'],
});

const submitCreate = () => {
    if (createForm.processing) {
        return;
    }

    createForm.post(route('team.store'), {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset('name', 'email', 'title', 'phone');
            createForm.role = 'member';
            closeOverlay('#hs-team-create');
        },
    });
};

const editingMemberId = ref(null);
const editForm = useForm({
    name: '',
    email: '',
    password: '',
    role: 'member',
    title: '',
    phone: '',
    permissions: [],
    is_active: true,
});

const openEditMember = (member) => {
    editingMemberId.value = member.id;
    editForm.clearErrors();

    editForm.name = member.user?.name || '';
    editForm.email = member.user?.email || '';
    editForm.password = '';
    editForm.role = member.role || 'member';
    editForm.title = member.title || '';
    editForm.phone = member.phone || '';
    editForm.permissions = Array.isArray(member.permissions) ? member.permissions : [];
    editForm.is_active = Boolean(member.is_active);

    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-team-edit');
    }
};

const submitEdit = () => {
    if (!editingMemberId.value || editForm.processing) {
        return;
    }

    editForm.put(route('team.update', editingMemberId.value), {
        preserveScroll: true,
        onSuccess: () => {
            editForm.password = '';
            closeOverlay('#hs-team-edit');
        },
    });
};

const deactivateMember = (member) => {
    if (!confirm(`Deactivate ${member.user?.name || 'this member'}?`)) {
        return;
    }
    router.delete(route('team.destroy', member.id), { preserveScroll: true });
};

const activateMember = (member) => {
    router.put(route('team.update', member.id), { is_active: true }, { preserveScroll: true });
};

const statusBadge = (member) =>
    member.is_active
        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400'
        : 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';

const roleBadge = (member) => {
    if (member.role === 'admin') {
        return 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-400';
    }
    if (member.role === 'seller') {
        return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300';
    }
    return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
};

const formatDate = (value) => humanizeDate(value) || String(value || '');
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
                        <input type="text" v-model="query"
                            class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400"
                            placeholder="Search team members">
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 justify-end">
                    <button type="button" @click="query = ''"
                        class="py-2 px-3 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                        Clear
                    </button>
                    <button type="button" data-hs-overlay="#hs-team-create"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                        + Add member
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
                                    Member
                                </div>
                            </th>
                            <th scope="col" class="min-w-28">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Role
                                </div>
                            </th>
                            <th scope="col" class="min-w-40">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Title
                                </div>
                            </th>
                            <th scope="col" class="min-w-40">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Phone
                                </div>
                            </th>
                            <th scope="col" class="min-w-28">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Status
                                </div>
                            </th>
                            <th scope="col" class="min-w-32">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Added
                                </div>
                            </th>
                            <th scope="col"></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                        <tr v-if="!filteredMembers.length">
                            <td colspan="7" class="px-4 py-6 text-sm text-stone-600 dark:text-neutral-400">
                                No team members found.
                            </td>
                        </tr>
                        <tr v-for="member in filteredMembers" :key="member.id">
                            <td class="size-px whitespace-nowrap px-4 py-2 text-start">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                                        {{ member.user?.name || `Member #${member.id}` }}
                                    </span>
                                    <span class="text-xs text-stone-500 dark:text-neutral-500">
                                        {{ member.user?.email || '-' }}
                                    </span>
                                </div>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="py-1.5 px-2 inline-flex items-center text-xs font-medium rounded-full"
                                    :class="roleBadge(member)">
                                    {{ member.role }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ member.title || '-' }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ member.phone || '-' }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="py-1.5 px-2 inline-flex items-center text-xs font-medium rounded-full"
                                    :class="statusBadge(member)">
                                    {{ member.is_active ? 'active' : 'inactive' }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ formatDate(member.created_at) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-end">
                                <div class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
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

                                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                        role="menu" aria-orientation="vertical">
                                        <div class="p-1">
                                            <button type="button" @click="openEditMember(member)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                Edit
                                            </button>
                                            <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                            <button v-if="member.is_active" type="button" @click="deactivateMember(member)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800">
                                                Deactivate
                                            </button>
                                            <button v-else type="button" @click="activateMember(member)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800">
                                                Activate
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

        <div class="mt-5 flex flex-wrap justify-between items-center gap-2">
            <p class="text-sm text-stone-800 dark:text-neutral-200">
                <span class="font-medium"> {{ filteredMembers.length }} </span>
                <span class="text-stone-500 dark:text-neutral-500"> results</span>
            </p>
        </div>
    </div>

    <Modal :title="'Add team member'" :id="'hs-team-create'">
        <form class="space-y-4" @submit.prevent="submitCreate">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <FloatingInput v-model="createForm.name" label="Name" />
                    <InputError class="mt-1" :message="createForm.errors.name" />
                </div>
                <div>
                    <FloatingInput v-model="createForm.email" label="Email" />
                    <InputError class="mt-1" :message="createForm.errors.email" />
                </div>
                <div>
                    <label class="block text-xs text-stone-500 dark:text-neutral-400">Role</label>
                    <select v-model="createForm.role"
                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="admin">Administrator</option>
                        <option value="member">Team member</option>
                        <option value="seller">Seller (POS)</option>
                    </select>
                    <InputError class="mt-1" :message="createForm.errors.role" />
                </div>
                <div>
                    <FloatingInput v-model="createForm.title" label="Title (optional)" />
                    <InputError class="mt-1" :message="createForm.errors.title" />
                </div>
                <div>
                    <FloatingInput v-model="createForm.phone" label="Phone (optional)" />
                    <InputError class="mt-1" :message="createForm.errors.phone" />
                </div>
            </div>
            <p class="text-xs text-stone-500 dark:text-neutral-400">
                Un lien de connexion sera envoye par email pour definir le mot de passe.
            </p>

            <div>
                <p class="text-xs text-stone-500 dark:text-neutral-400">Permissions</p>
                <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-2">
                    <label v-for="permission in availablePermissions" :key="permission.id"
                        class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                        <Checkbox v-model:checked="createForm.permissions" :value="permission.id" />
                        <span>{{ permission.name }}</span>
                    </label>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" data-hs-overlay="#hs-team-create"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                    Cancel
                </button>
                <button type="submit" :disabled="createForm.processing"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                    Add member
                </button>
            </div>
        </form>
    </Modal>

    <Modal :title="'Edit team member'" :id="'hs-team-edit'">
        <form class="space-y-4" @submit.prevent="submitEdit">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <FloatingInput v-model="editForm.name" label="Name" />
                    <InputError class="mt-1" :message="editForm.errors.name" />
                </div>
                <div>
                    <FloatingInput v-model="editForm.email" label="Email" />
                    <InputError class="mt-1" :message="editForm.errors.email" />
                </div>
                <div>
                    <FloatingInput v-model="editForm.password" label="New password (optional)" />
                    <InputError class="mt-1" :message="editForm.errors.password" />
                </div>
                <div>
                    <label class="block text-xs text-stone-500 dark:text-neutral-400">Role</label>
                    <select v-model="editForm.role"
                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="admin">Administrator</option>
                        <option value="member">Team member</option>
                        <option value="seller">Seller (POS)</option>
                    </select>
                    <InputError class="mt-1" :message="editForm.errors.role" />
                </div>
                <div>
                    <FloatingInput v-model="editForm.title" label="Title (optional)" />
                    <InputError class="mt-1" :message="editForm.errors.title" />
                </div>
                <div>
                    <FloatingInput v-model="editForm.phone" label="Phone (optional)" />
                    <InputError class="mt-1" :message="editForm.errors.phone" />
                </div>
                <div class="md:col-span-2">
                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                        <Checkbox v-model:checked="editForm.is_active" />
                        <span>Active</span>
                    </label>
                    <InputError class="mt-1" :message="editForm.errors.is_active" />
                </div>
            </div>

            <div>
                <p class="text-xs text-stone-500 dark:text-neutral-400">Permissions</p>
                <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-2">
                    <label v-for="permission in availablePermissions" :key="permission.id"
                        class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                        <Checkbox v-model:checked="editForm.permissions" :value="permission.id" />
                        <span>{{ permission.name }}</span>
                    </label>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" data-hs-overlay="#hs-team-edit"
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
