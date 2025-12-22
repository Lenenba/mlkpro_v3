<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    filters: {
        type: Object,
        required: true,
    },
    tenants: {
        type: Object,
        required: true,
    },
    plans: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    search: props.filters?.search ?? '',
    company_type: props.filters?.company_type ?? '',
    status: props.filters?.status ?? '',
    plan: props.filters?.plan ?? '',
    created_from: props.filters?.created_from ?? '',
    created_to: props.filters?.created_to ?? '',
});

const submit = (event) => {
    event?.preventDefault();
    form.get(route('superadmin.tenants.index'), {
        preserveState: true,
        preserveScroll: true,
    });
};

const reset = () => {
    form.reset();
    form.get(route('superadmin.tenants.index'));
};

const statusLabel = (tenant) => {
    if (tenant.is_suspended) {
        return 'Suspended';
    }
    return 'Active';
};
</script>

<template>
    <Head title="Tenants" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-neutral-100">Tenants</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-neutral-400">
                    Search and manage all companies on the platform.
                </p>
            </div>

            <form class="rounded-sm border border-gray-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800" @submit="submit">
                <div class="grid gap-3 md:grid-cols-3">
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Search</label>
                        <input v-model="form.search" type="text"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Company type</label>
                        <select v-model="form.company_type"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option value="">All</option>
                            <option value="services">Services</option>
                            <option value="products">Products</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Status</label>
                        <select v-model="form.status"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option value="">All</option>
                            <option value="active">Active</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Plan</label>
                        <select v-model="form.plan"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option value="">All</option>
                            <option v-for="plan in plans" :key="plan.price_id" :value="plan.price_id">
                                {{ plan.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Created from</label>
                        <input v-model="form.created_from" type="date"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Created to</label>
                        <input v-model="form.created_to" type="date"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap justify-end gap-2">
                    <button type="button" @click="reset"
                        class="py-2 px-3 text-sm font-medium rounded-sm border border-gray-200 text-gray-700 hover:bg-gray-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                        Reset
                    </button>
                    <button type="submit"
                        class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                        Apply filters
                    </button>
                </div>
            </form>

            <div class="rounded-sm border border-gray-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-600 dark:text-neutral-300">
                        <thead class="text-xs uppercase text-gray-500 dark:text-neutral-400">
                            <tr>
                                <th class="px-4 py-3">Company</th>
                                <th class="px-4 py-3">Owner</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Plan</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Created</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="tenant in tenants.data" :key="tenant.id" class="border-t border-gray-200 dark:border-neutral-700">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-800 dark:text-neutral-100">
                                        {{ tenant.company_name || 'Unnamed company' }}
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-neutral-400">{{ tenant.email }}</div>
                                </td>
                                <td class="px-4 py-3">{{ tenant.name }}</td>
                                <td class="px-4 py-3">{{ tenant.company_type || 'n/a' }}</td>
                                <td class="px-4 py-3">{{ tenant.subscription?.plan_name || 'n/a' }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs"
                                        :class="tenant.is_suspended ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700'">
                                        {{ statusLabel(tenant) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">{{ new Date(tenant.created_at).toLocaleDateString() }}</td>
                                <td class="px-4 py-3 text-right">
                                    <Link :href="route('superadmin.tenants.show', tenant.id)"
                                        class="text-sm font-medium text-green-700 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300">
                                        View
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!tenants.data.length">
                                <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-neutral-400">
                                    No tenants found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="tenants.links?.length" class="p-4 flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-neutral-400">
                    <template v-for="link in tenants.links" :key="link.url || link.label">
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
        </div>
    </AuthenticatedLayout>
</template>
