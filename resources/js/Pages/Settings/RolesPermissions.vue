<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    roles: {
        type: Array,
        default: () => [],
    },
    permissions: {
        type: Array,
        default: () => [],
    },
    teamMembers: {
        type: Array,
        default: () => [],
    },
});

const selectedRoleId = ref(props.roles[0]?.id ?? null);
const selectedGroup = ref('all');
const mode = ref('edit');

const selectedRole = computed(() => props.roles.find((role) => Number(role.id) === Number(selectedRoleId.value)) || null);
const customRoles = computed(() => props.roles.filter((role) => !role.is_system));
const systemRoles = computed(() => props.roles.filter((role) => role.is_system));
const activeRoles = computed(() => props.roles.filter((role) => role.is_active));
const permissionCount = computed(() => props.permissions.reduce((total, group) => total + (group.permissions?.length || 0), 0));
const groupTabs = computed(() => [
    { id: 'all', label: 'Tous' },
    ...props.permissions.map((group) => ({ id: group.group, label: group.label || group.group })),
]);
const visiblePermissionGroups = computed(() => (
    selectedGroup.value === 'all'
        ? props.permissions
        : props.permissions.filter((group) => group.group === selectedGroup.value)
));
const assignedMembers = computed(() => (
    selectedRole.value
        ? props.teamMembers.filter((member) => Number(member.company_role?.id) === Number(selectedRole.value.id))
        : []
));

const form = useForm({
    name: '',
    description: '',
    is_active: true,
    permissions: [],
});

const syncFormFromRole = (role) => {
    if (!role) {
        form.defaults({
            name: '',
            description: '',
            is_active: true,
            permissions: [],
        });
        form.reset();
        return;
    }

    form.defaults({
        name: role.name || '',
        description: role.description || '',
        is_active: Boolean(role.is_active),
        permissions: Array.isArray(role.permissions) ? [...role.permissions] : [],
    });
    form.reset();
    form.clearErrors();
};

watch(selectedRole, (role) => {
    if (mode.value === 'edit') {
        syncFormFromRole(role);
    }
}, { immediate: true });

const startCreate = () => {
    mode.value = 'create';
    selectedRoleId.value = null;
    form.defaults({
        name: '',
        description: '',
        is_active: true,
        permissions: [],
    });
    form.reset();
    form.clearErrors();
};

const startEdit = (role) => {
    mode.value = 'edit';
    selectedRoleId.value = role.id;
    syncFormFromRole(role);
};

const submitRole = () => {
    if (form.processing) {
        return;
    }

    if (mode.value === 'create') {
        form.post(route('settings.roles_permissions.roles.store'), {
            preserveScroll: true,
        });
        return;
    }

    if (!selectedRole.value || !selectedRole.value.is_editable) {
        return;
    }

    form.put(route('settings.roles_permissions.roles.update', selectedRole.value.id), {
        preserveScroll: true,
    });
};

const duplicateRole = (role) => {
    router.post(route('settings.roles_permissions.roles.duplicate', role.id), {
        name: `${role.name} personnalisé`,
    }, {
        preserveScroll: true,
    });
};

const toggleRole = (role) => {
    router.patch(route('settings.roles_permissions.roles.toggle', role.id), {
        is_active: !role.is_active,
    }, {
        preserveScroll: true,
    });
};

const deleteRole = (role) => {
    if (!confirm(`Supprimer le rôle "${role.name}" ?`)) {
        return;
    }

    router.delete(route('settings.roles_permissions.roles.destroy', role.id), {
        preserveScroll: true,
    });
};

const roleTone = (role) => {
    if (role.is_system) {
        return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200';
    }

    return role.is_active
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200'
        : 'border-stone-200 bg-stone-50 text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300';
};
</script>

<template>
    <Head title="Rôles et permissions" />

    <SettingsLayout active="roles">
        <div class="space-y-4">
            <header class="border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.08em] text-emerald-700 dark:text-emerald-300">
                            Équipe et accès
                        </p>
                        <h1 class="mt-1 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                            Rôles et permissions
                        </h1>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-stone-600 dark:text-neutral-400">
                            Configurez les rôles métiers de l’entreprise. Les permissions pilotent les pages visibles, les actions possibles et les accès backend.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-sm border border-transparent bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
                        @click="startCreate"
                    >
                        Nouveau rôle
                    </button>
                </div>
            </header>

            <section class="grid gap-3 md:grid-cols-4">
                <div class="border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">Rôles actifs</p>
                    <p class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">{{ activeRoles.length }}</p>
                </div>
                <div class="border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">Rôles système</p>
                    <p class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">{{ systemRoles.length }}</p>
                </div>
                <div class="border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">Rôles personnalisés</p>
                    <p class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">{{ customRoles.length }}</p>
                </div>
                <div class="border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">Permissions</p>
                    <p class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">{{ permissionCount }}</p>
                </div>
            </section>

            <section class="grid gap-4 lg:grid-cols-[360px_minmax(0,1fr)]">
                <aside class="space-y-3">
                    <div class="border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="border-b border-stone-200 px-4 py-3 dark:border-neutral-800">
                            <h2 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">Rôles disponibles</h2>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">Sélectionnez un rôle pour consulter sa configuration.</p>
                        </div>
                        <div class="divide-y divide-stone-200 dark:divide-neutral-800">
                            <button
                                v-for="role in roles"
                                :key="role.id"
                                type="button"
                                class="block w-full px-4 py-3 text-left transition hover:bg-stone-50 dark:hover:bg-neutral-800"
                                :class="selectedRole?.id === role.id && mode === 'edit' ? 'bg-emerald-50 dark:bg-emerald-500/10' : ''"
                                @click="startEdit(role)"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-stone-900 dark:text-neutral-100">{{ role.name }}</p>
                                        <p class="mt-1 truncate text-xs text-stone-500 dark:text-neutral-400">{{ role.description || 'Aucune description.' }}</p>
                                    </div>
                                    <span class="shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-semibold" :class="roleTone(role)">
                                        {{ role.is_system ? 'Système' : (role.is_active ? 'Actif' : 'Inactif') }}
                                    </span>
                                </div>
                                <div class="mt-3 flex items-center justify-between text-[11px] text-stone-500 dark:text-neutral-400">
                                    <span>{{ role.permissions.length }} permission(s)</span>
                                    <span>{{ role.members_count }} membre(s)</span>
                                </div>
                            </button>
                        </div>
                    </div>

                    <div class="border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">Membres du rôle</h2>
                        <div v-if="assignedMembers.length" class="mt-3 space-y-2">
                            <div
                                v-for="member in assignedMembers"
                                :key="member.id"
                                class="border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700"
                            >
                                <p class="font-medium text-stone-800 dark:text-neutral-100">{{ member.name || member.email || `#${member.id}` }}</p>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">{{ member.title || member.email || 'Membre équipe' }}</p>
                            </div>
                        </div>
                        <p v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                            Aucun membre assigné au rôle sélectionné.
                        </p>
                    </div>
                </aside>

                <form class="border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" @submit.prevent="submitRole">
                    <div class="flex flex-col gap-3 border-b border-stone-200 pb-4 dark:border-neutral-800 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.08em] text-stone-500 dark:text-neutral-400">
                                {{ mode === 'create' ? 'Création' : 'Configuration' }}
                            </p>
                            <h2 class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                {{ mode === 'create' ? 'Nouveau rôle personnalisé' : (selectedRole?.name || 'Sélectionnez un rôle') }}
                            </h2>
                            <p v-if="selectedRole?.is_system" class="mt-2 max-w-2xl text-sm text-stone-600 dark:text-neutral-400">
                                Ce rôle système sert de modèle. Dupliquez-le pour créer une version personnalisée propre à cette entreprise.
                            </p>
                        </div>
                        <div v-if="selectedRole && mode === 'edit'" class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                @click="duplicateRole(selectedRole)"
                            >
                                Dupliquer
                            </button>
                            <button
                                v-if="selectedRole.is_editable"
                                type="button"
                                class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                @click="toggleRole(selectedRole)"
                            >
                                {{ selectedRole.is_active ? 'Désactiver' : 'Activer' }}
                            </button>
                            <button
                                v-if="selectedRole.is_deletable"
                                type="button"
                                class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-100 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200"
                                :disabled="selectedRole.members_count > 0"
                                @click="deleteRole(selectedRole)"
                            >
                                Supprimer
                            </button>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-4 lg:grid-cols-2">
                        <div>
                            <FloatingInput v-model="form.name" label="Nom du rôle" :disabled="mode === 'edit' && !selectedRole?.is_editable" />
                            <InputError class="mt-1" :message="form.errors.name" />
                        </div>
                        <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                            <Checkbox v-model:checked="form.is_active" :disabled="mode === 'edit' && !selectedRole?.is_editable" />
                            <span>Rôle actif</span>
                        </label>
                        <div class="lg:col-span-2">
                            <FloatingTextarea v-model="form.description" label="Description" :disabled="mode === 'edit' && !selectedRole?.is_editable" />
                            <InputError class="mt-1" :message="form.errors.description" />
                        </div>
                    </div>

                    <div class="mt-6">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">Permissions</h3>
                                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">Cochez les permissions incluses dans ce rôle.</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="group in groupTabs"
                                    :key="group.id"
                                    type="button"
                                    class="rounded-sm border px-3 py-1.5 text-xs font-semibold"
                                    :class="selectedGroup === group.id
                                        ? 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200'
                                        : 'border-stone-200 bg-white text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                                    @click="selectedGroup = group.id"
                                >
                                    {{ group.label }}
                                </button>
                            </div>
                        </div>

                        <div class="mt-4 space-y-3">
                            <section
                                v-for="group in visiblePermissionGroups"
                                :key="group.group"
                                class="border border-stone-200 dark:border-neutral-700"
                            >
                                <div class="border-b border-stone-200 bg-stone-50 px-4 py-2 dark:border-neutral-800 dark:bg-neutral-800">
                                    <h4 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ group.label }}</h4>
                                </div>
                                <div class="grid gap-0 sm:grid-cols-2 xl:grid-cols-3">
                                    <label
                                        v-for="permission in group.permissions"
                                        :key="permission.slug"
                                        class="flex min-h-16 items-start gap-3 border-b border-stone-100 px-4 py-3 text-sm dark:border-neutral-800"
                                    >
                                        <Checkbox
                                            v-model:checked="form.permissions"
                                            :value="permission.slug"
                                            :disabled="mode === 'edit' && !selectedRole?.is_editable"
                                        />
                                        <span>
                                            <span class="block font-medium text-stone-800 dark:text-neutral-100">{{ permission.name }}</span>
                                            <span class="mt-0.5 block text-[11px] text-stone-500 dark:text-neutral-400">{{ permission.slug }}</span>
                                        </span>
                                    </label>
                                </div>
                            </section>
                        </div>
                        <InputError class="mt-2" :message="form.errors.permissions" />
                    </div>

                    <div class="mt-6 flex justify-end gap-2">
                        <button
                            v-if="mode === 'create'"
                            type="button"
                            class="rounded-sm border border-stone-200 bg-white px-4 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                            @click="selectedRole ? startEdit(selectedRole) : startCreate()"
                        >
                            Annuler
                        </button>
                        <button
                            type="submit"
                            class="rounded-sm border border-transparent bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                            :disabled="form.processing || (mode === 'edit' && !selectedRole?.is_editable)"
                        >
                            {{ form.processing ? 'Enregistrement...' : (mode === 'create' ? 'Créer le rôle' : 'Enregistrer') }}
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </SettingsLayout>
</template>
