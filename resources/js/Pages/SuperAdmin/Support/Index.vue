<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    tickets: {
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
    priorities: {
        type: Array,
        default: () => [],
    },
    tenants: {
        type: Array,
        default: () => [],
    },
});

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
    priority: props.filters?.priority ?? '',
    account_id: props.filters?.account_id ?? '',
});

const createForm = useForm({
    account_id: '',
    title: '',
    description: '',
    status: props.statuses[0] || 'open',
    priority: props.priorities[1] || 'normal',
    sla_due_at: '',
    tags: '',
});

const editingTicket = ref(null);
const editForm = useForm({
    status: '',
    priority: '',
    sla_due_at: '',
    tags: '',
});

const applyFilters = () => {
    filterForm.get(route('superadmin.support.index'), { preserveScroll: true, preserveState: true });
};

const resetFilters = () => {
    filterForm.reset();
    filterForm.get(route('superadmin.support.index'));
};

const submitTicket = () => {
    createForm.post(route('superadmin.support.store'), {
        preserveScroll: true,
        onSuccess: () => createForm.reset('account_id', 'title', 'description', 'sla_due_at', 'tags'),
    });
};

const openEdit = (ticket) => {
    editingTicket.value = ticket;
    editForm.status = ticket.status;
    editForm.priority = ticket.priority;
    editForm.sla_due_at = ticket.sla_due_at ? ticket.sla_due_at.substring(0, 16) : '';
    editForm.tags = Array.isArray(ticket.tags) ? ticket.tags.join(', ') : '';
};

const closeEdit = () => {
    editingTicket.value = null;
};

const submitEdit = () => {
    if (!editingTicket.value) {
        return;
    }
    editForm.put(route('superadmin.support.update', editingTicket.value.id), {
        preserveScroll: true,
        onSuccess: () => closeEdit(),
    });
};
</script>

<template>
    <Head title="Support Tickets" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-neutral-100">Support tickets</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-neutral-400">
                    Track SLA, status, and tags for platform support requests.
                </p>
            </div>

            <form class="rounded-sm border border-gray-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800" @submit.prevent="applyFilters">
                <div class="grid gap-3 md:grid-cols-4">
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Search</label>
                        <input v-model="filterForm.search" type="text"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Status</label>
                        <select v-model="filterForm.status"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option value="">All</option>
                            <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Priority</label>
                        <select v-model="filterForm.priority"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option value="">All</option>
                            <option v-for="priority in priorities" :key="priority" :value="priority">{{ priority }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Tenant</label>
                        <select v-model="filterForm.account_id"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option value="">All</option>
                            <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">
                                {{ tenant.company_name || tenant.email }}
                            </option>
                        </select>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap justify-end gap-2">
                    <button type="button" @click="resetFilters"
                        class="py-2 px-3 text-sm font-medium rounded-sm border border-gray-200 text-gray-700 hover:bg-gray-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                        Reset
                    </button>
                    <button type="submit"
                        class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                        Apply filters
                    </button>
                </div>
            </form>

            <div class="rounded-sm border border-gray-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">Create ticket</h2>
                <form class="mt-4 space-y-3" @submit.prevent="submitTicket">
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Tenant</label>
                        <select v-model="createForm.account_id"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option value="">Select tenant</option>
                            <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">
                                {{ tenant.company_name || tenant.email }}
                            </option>
                        </select>
                        <InputError class="mt-1" :message="createForm.errors.account_id" />
                    </div>
                    <FloatingInput v-model="createForm.title" label="Title" />
                    <InputError class="mt-1" :message="createForm.errors.title" />
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Description</label>
                        <textarea v-model="createForm.description" rows="3"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"></textarea>
                        <InputError class="mt-1" :message="createForm.errors.description" />
                    </div>
                    <div class="grid gap-3 md:grid-cols-3">
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-neutral-400">Status</label>
                            <select v-model="createForm.status"
                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-neutral-400">Priority</label>
                            <select v-model="createForm.priority"
                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option v-for="priority in priorities" :key="priority" :value="priority">{{ priority }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-neutral-400">SLA due</label>
                            <input v-model="createForm.sla_due_at" type="datetime-local"
                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                        </div>
                    </div>
                    <FloatingInput v-model="createForm.tags" label="Tags (comma separated)" />
                    <InputError class="mt-1" :message="createForm.errors.tags" />
                    <button type="submit"
                        class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                        Create ticket
                    </button>
                </form>
            </div>

            <div class="rounded-sm border border-gray-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-600 dark:text-neutral-300">
                        <thead class="text-xs uppercase text-gray-500 dark:text-neutral-400">
                            <tr>
                                <th class="px-4 py-3">Title</th>
                                <th class="px-4 py-3">Tenant</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Priority</th>
                                <th class="px-4 py-3">SLA</th>
                                <th class="px-4 py-3">Tags</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="ticket in tickets.data" :key="ticket.id" class="border-t border-gray-200 dark:border-neutral-700">
                                <td class="px-4 py-3 font-medium text-gray-800 dark:text-neutral-100">{{ ticket.title }}</td>
                                <td class="px-4 py-3">{{ ticket.account?.company_name || ticket.account?.email }}</td>
                                <td class="px-4 py-3">{{ ticket.status }}</td>
                                <td class="px-4 py-3">{{ ticket.priority }}</td>
                                <td class="px-4 py-3">{{ ticket.sla_due_at ? new Date(ticket.sla_due_at).toLocaleString() : 'n/a' }}</td>
                                <td class="px-4 py-3">{{ Array.isArray(ticket.tags) ? ticket.tags.join(', ') : '' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" @click="openEdit(ticket)"
                                        class="text-sm font-medium text-green-700 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300">
                                        Edit
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!tickets.data.length">
                                <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-neutral-400">
                                    No tickets found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="tickets.links?.length" class="p-4 flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-neutral-400">
                    <template v-for="link in tickets.links" :key="link.url || link.label">
                        <span v-if="!link.url"
                            v-html="link.label"
                            class="px-2 py-1 rounded-sm border border-gray-200 text-gray-400 dark:border-neutral-700">
                        </span>
                        <Link v-else
                            :href="link.url"
                            v-html="link.label"
                            class="px-2 py-1 rounded-sm border border-gray-200 dark:border-neutral-700"
                            :class="link.active ? 'bg-green-600 text-white border-transparent' : 'hover:bg-gray-50 dark:hover:bg-neutral-700'"
                            preserve-scroll />
                    </template>
                </div>
            </div>

            <div v-if="editingTicket" class="rounded-sm border border-gray-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">Update ticket</h2>
                    <button type="button" @click="closeEdit" class="text-sm text-gray-500 dark:text-neutral-400">Close</button>
                </div>
                <form class="mt-4 space-y-3" @submit.prevent="submitEdit">
                    <div class="grid gap-3 md:grid-cols-3">
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-neutral-400">Status</label>
                            <select v-model="editForm.status"
                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-neutral-400">Priority</label>
                            <select v-model="editForm.priority"
                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option v-for="priority in priorities" :key="priority" :value="priority">{{ priority }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-neutral-400">SLA due</label>
                            <input v-model="editForm.sla_due_at" type="datetime-local"
                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                        </div>
                    </div>
                    <FloatingInput v-model="editForm.tags" label="Tags (comma separated)" />
                    <InputError class="mt-1" :message="editForm.errors.tags" />
                    <button type="submit"
                        class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                        Save ticket
                    </button>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
