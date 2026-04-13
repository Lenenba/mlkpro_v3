<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import Modal from '@/Components/Modal.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';

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

const { t } = useI18n();

const showCreate = ref(false);
const roleOptions = computed(() =>
    (props.roles || []).map((role) => ({
        value: role,
        label: role,
    }))
);
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

const adminRows = computed(() => props.admins || []);
const adminsResultsLabel = computed(() => t('super_admin.admins.filters.results', { count: adminRows.value.length }));

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
    <Head :title="$t('super_admin.admins.page_title')" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.admins.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('super_admin.admins.subtitle') }}
                        </p>
                    </div>
                    <button type="button" @click="openCreate"
                        class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                        {{ $t('super_admin.admins.actions.add') }}
                    </button>
                </div>
            </section>

            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-2 md:gap-3 lg:gap-5">
                <div class="p-4 bg-white border border-t-4 border-t-emerald-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.admins.stats.total') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.total) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-blue-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.admins.stats.active') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.active) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-rose-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.admins.stats.inactive') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.inactive) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-amber-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.admins.stats.require_2fa') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.require_2fa) }}
                    </p>
                </div>
            </div>

            <AdminDataTable
                :rows="adminRows"
                :result-label="adminsResultsLabel"
                :empty-description="$t('super_admin.admins.empty')"
                container-class="border-t-4 border-t-zinc-600"
            >
                <template #head>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-neutral-300">
                        <th class="px-4 py-3">{{ $t('super_admin.admins.table.name') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.admins.table.email') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.admins.table.role') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.admins.table.status') }}</th>
                        <th class="px-4 py-3 text-right"></th>
                    </tr>
                </template>

                <template #row="{ row: admin }">
                    <tr class="align-top">
                        <td class="px-4 py-3 font-medium text-stone-800 dark:text-neutral-100">{{ admin.name }}</td>
                        <td class="px-4 py-3">{{ admin.email }}</td>
                        <td class="px-4 py-3">{{ admin.platform?.role || $t('super_admin.common.not_available') }}</td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs"
                                :class="admin.platform?.is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300'"
                            >
                                {{ admin.platform?.is_active ? $t('super_admin.admins.status.active') : $t('super_admin.admins.status.inactive') }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <AdminDataTableActions :label="$t('super_admin.common.actions')">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                    @click="openEdit(admin)"
                                >
                                    {{ $t('super_admin.common.edit') }}
                                </button>
                            </AdminDataTableActions>
                        </td>
                    </tr>
                </template>
            </AdminDataTable>

            <Modal :show="showCreate" @close="closeCreate">
                <div class="p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.admins.actions.add') }}
                        </h2>
                        <button type="button" @click="closeCreate" class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.common.close') }}
                        </button>
                    </div>
                    <form class="mt-4 space-y-3" @submit.prevent="submitCreate">
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <FloatingInput v-model="createForm.name" :label="$t('super_admin.admins.form.name')" />
                                <InputError class="mt-1" :message="createForm.errors.name" />
                            </div>
                            <div>
                                <FloatingInput v-model="createForm.email" :label="$t('super_admin.admins.form.email')" />
                                <InputError class="mt-1" :message="createForm.errors.email" />
                            </div>
                        </div>
                        <div>
                            <FloatingSelect
                                v-model="createForm.role"
                                :label="$t('super_admin.admins.form.role')"
                                :options="roleOptions"
                            />
                            <InputError class="mt-1" :message="createForm.errors.role" />
                        </div>
                        <div>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.admins.form.permissions') }}
                            </p>
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
                            <span>{{ $t('super_admin.admins.form.require_2fa') }}</span>
                        </label>
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="closeCreate"
                                class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                {{ $t('super_admin.common.cancel') }}
                            </button>
                            <button type="submit"
                                class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                {{ $t('super_admin.admins.actions.create') }}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>

            <Modal :show="showEdit" @close="closeEdit">
                <div class="p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.admins.actions.edit') }}
                        </h2>
                        <button type="button" @click="closeEdit" class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.common.close') }}
                        </button>
                    </div>
                    <form class="mt-4 space-y-3" @submit.prevent="submitEdit">
                        <div>
                            <FloatingSelect
                                v-model="editForm.role"
                                :label="$t('super_admin.admins.form.role')"
                                :options="roleOptions"
                            />
                            <InputError class="mt-1" :message="editForm.errors.role" />
                        </div>
                        <div>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.admins.form.permissions') }}
                            </p>
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
                            <span>{{ $t('super_admin.admins.status.active') }}</span>
                        </label>
                        <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                            <Checkbox v-model:checked="editForm.require_2fa" :value="true" />
                            <span>{{ $t('super_admin.admins.form.require_2fa') }}</span>
                        </label>
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="closeEdit"
                                class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                {{ $t('super_admin.common.cancel') }}
                            </button>
                            <button type="submit"
                                class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                {{ $t('super_admin.common.save_changes') }}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>
        </div>
    </AuthenticatedLayout>
</template>
