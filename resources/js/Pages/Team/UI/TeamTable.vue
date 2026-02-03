<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/UI/Modal.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
import DropzoneInput from '@/Components/DropzoneInput.vue';
import { humanizeDate } from '@/utils/date';
import { avatarIconPresets, defaultAvatarIcon } from '@/utils/iconPresets';

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

const { t } = useI18n();
const query = ref('');
const isAvatarIcon = (value) => avatarIconPresets.includes(value);
const roleOptions = [
    { id: 'admin', name: 'Administrator' },
    { id: 'member', name: 'Team member' },
    { id: 'seller', name: 'Seller (POS)' },
    { id: 'sales_manager', name: 'Sales manager' },
];

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

const detailMember = ref(null);
const detailLoading = ref(false);
let detailLoadingTimer = null;

const openDetailMember = (member) => {
    detailMember.value = member;
    detailLoading.value = true;
    if (detailLoadingTimer) {
        clearTimeout(detailLoadingTimer);
    }
    detailLoadingTimer = setTimeout(() => {
        detailLoading.value = false;
    }, 320);
    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-team-detail');
    }
};

const openEditFromDetail = () => {
    if (!detailMember.value) {
        return;
    }
    closeOverlay('#hs-team-detail');
    openEditMember(detailMember.value);
};

const memberPerformanceUrl = (member) => {
    const userId = member?.user?.id || member?.user_id;
    if (!userId) {
        return null;
    }
    return route('performance.employee.show', userId);
};

const createForm = useForm({
    name: '',
    email: '',
    role: 'member',
    title: '',
    phone: '',
    permissions: ['jobs.view', 'tasks.view', 'tasks.edit'],
    planning_rules: {
        break_minutes: '',
        min_hours_day: '',
        max_hours_day: '',
        max_hours_week: '',
    },
    profile_picture: null,
    avatar_icon: defaultAvatarIcon,
});

const submitCreate = () => {
    if (createForm.processing) {
        return;
    }

    const planningRules = buildPlanningRulesPayload(createForm.planning_rules);

    createForm
        .transform((data) => {
            const payload = { ...data };
            if (data.profile_picture instanceof File) {
                payload.profile_picture = data.profile_picture;
            } else {
                delete payload.profile_picture;
            }
            if (!payload.avatar_icon) {
                delete payload.avatar_icon;
            }
            if (planningRules === null) {
                payload.planning_rules = null;
            } else if (planningRules) {
                payload.planning_rules = planningRules;
            } else {
                delete payload.planning_rules;
            }
            return payload;
        })
        .post(route('team.store'), {
            preserveScroll: true,
            onSuccess: () => {
                createForm.reset('name', 'email', 'title', 'phone');
                createForm.role = 'member';
                createForm.profile_picture = null;
                createForm.avatar_icon = defaultAvatarIcon;
                createForm.planning_rules = {
                    break_minutes: '',
                    min_hours_day: '',
                    max_hours_day: '',
                    max_hours_week: '',
                };
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
    planning_rules: {
        break_minutes: '',
        min_hours_day: '',
        max_hours_day: '',
        max_hours_week: '',
    },
    is_active: true,
    profile_picture: null,
    avatar_icon: '',
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
    editForm.planning_rules = {
        break_minutes: member.planning_rules?.break_minutes ?? '',
        min_hours_day: member.planning_rules?.min_hours_day ?? '',
        max_hours_day: member.planning_rules?.max_hours_day ?? '',
        max_hours_week: member.planning_rules?.max_hours_week ?? '',
    };
    editForm.is_active = Boolean(member.is_active);
    const avatarUrl = member.user?.profile_picture_url || member.user?.profile_picture || '';
    editForm.avatar_icon = isAvatarIcon(member.user?.profile_picture)
        ? member.user.profile_picture
        : (isAvatarIcon(avatarUrl) ? avatarUrl : '');
    editForm.profile_picture = editForm.avatar_icon ? null : avatarUrl;

    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-team-edit');
    }
};

const submitEdit = () => {
    if (!editingMemberId.value || editForm.processing) {
        return;
    }

    const planningRules = buildPlanningRulesPayload(editForm.planning_rules);

    editForm
        .transform((data) => {
            const payload = { ...data };
            if (data.profile_picture instanceof File) {
                payload.profile_picture = data.profile_picture;
            } else {
                delete payload.profile_picture;
            }
            if (!payload.avatar_icon) {
                delete payload.avatar_icon;
            }
            if (planningRules === null) {
                payload.planning_rules = null;
            } else if (planningRules) {
                payload.planning_rules = planningRules;
            } else {
                delete payload.planning_rules;
            }
            return payload;
        })
        .put(route('team.update', editingMemberId.value), {
            preserveScroll: true,
            onSuccess: () => {
                editForm.password = '';
                editForm.profile_picture = null;
                editForm.planning_rules = {
                    break_minutes: '',
                    min_hours_day: '',
                    max_hours_day: '',
                    max_hours_week: '',
                };
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
    if (member.role === 'sales_manager') {
        return 'bg-teal-100 text-teal-800 dark:bg-teal-500/10 dark:text-teal-300';
    }
    return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
};

const roleLabel = (role) => {
    const entry = roleOptions.find((option) => option.id === role);
    if (entry) {
        return entry.name;
    }
    return String(role || '').replace(/_/g, ' ');
};

const formatDate = (value) => humanizeDate(value) || String(value || '');

const memberAvatarUrl = (member) => member.user?.profile_picture_url || member.user?.profile_picture || '';

const memberInitials = (member) => {
    const name = member.user?.name || '';
    if (!name) {
        return 'TM';
    }
    const parts = name.trim().split(' ').filter(Boolean);
    const first = parts[0]?.[0] || '';
    const second = parts[1]?.[0] || '';
    return `${first}${second}`.toUpperCase();
};

const selectAvatarIcon = (form, icon) => {
    form.avatar_icon = icon;
    form.profile_picture = null;
};

const clearAvatarIcon = (form) => {
    form.avatar_icon = '';
};

const buildPlanningRulesPayload = (rules) => {
    if (!rules || typeof rules !== 'object') {
        return null;
    }

    const normalized = {};
    const fields = [
        'break_minutes',
        'min_hours_day',
        'max_hours_day',
        'max_hours_week',
    ];

    fields.forEach((field) => {
        const raw = rules[field];
        if (raw === '' || raw === null || raw === undefined) {
            return;
        }
        const numberValue = Number(raw);
        if (Number.isNaN(numberValue)) {
            return;
        }
        normalized[field] = numberValue;
    });

    if (!Object.keys(normalized).length) {
        return null;
    }

    return normalized;
};

const permissionMap = computed(() => {
    const map = new Map();
    (props.availablePermissions || []).forEach((permission) => {
        map.set(permission.id, permission.name);
    });
    return map;
});

const permissionLabels = (member) => {
    const permissions = Array.isArray(member?.permissions) ? member.permissions : [];
    return permissions.map((permission) => permissionMap.value.get(permission) || permission);
};

watch(() => createForm.profile_picture, (value) => {
    if (value instanceof File) {
        createForm.avatar_icon = '';
    }
});

watch(() => editForm.profile_picture, (value) => {
    if (value instanceof File) {
        editForm.avatar_icon = '';
    }
});
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
                                <div class="flex items-center gap-3">
                                    <div class="size-10 rounded-full bg-stone-100 text-stone-600 flex items-center justify-center overflow-hidden dark:bg-neutral-700 dark:text-neutral-200">
                                        <img
                                            v-if="memberAvatarUrl(member)"
                                            :src="memberAvatarUrl(member)"
                                            alt="Member avatar"
                                            class="h-full w-full object-cover"
                                            loading="lazy"
                                            decoding="async"
                                        >
                                        <span v-else class="text-xs font-semibold">
                                            {{ memberInitials(member) }}
                                        </span>
                                    </div>
                                    <div class="flex flex-col">
                                        <span
                                            class="text-sm font-medium text-stone-800 hover:text-stone-900 cursor-pointer dark:text-neutral-200 dark:hover:text-white"
                                            @click="openDetailMember(member)"
                                        >
                                            {{ member.user?.name || `Member #${member.id}` }}
                                        </span>
                                        <span class="text-xs text-stone-500 dark:text-neutral-500">
                                            {{ member.user?.email || '-' }}
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="py-1.5 px-2 inline-flex items-center text-xs font-medium rounded-full"
                                    :class="roleBadge(member)">
                                    {{ roleLabel(member.role) }}
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
                                            <button type="button" @click="openDetailMember(member)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                Details
                                            </button>
                                            <Link
                                                v-if="memberPerformanceUrl(member)"
                                                :href="memberPerformanceUrl(member)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                            >
                                                {{ t('performance.employees.view_employee') }}
                                            </Link>
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

    <Modal :title="'Team member details'" :id="'hs-team-detail'">
        <div v-if="detailLoading" class="space-y-4 animate-pulse">
            <div class="flex items-center gap-3">
                <div class="size-12 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                <div class="flex-1 space-y-2">
                    <div class="h-3 w-32 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                    <div class="h-3 w-40 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                    <div class="h-4 w-24 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="h-12 rounded-md bg-stone-200 dark:bg-neutral-700"></div>
                <div class="h-12 rounded-md bg-stone-200 dark:bg-neutral-700"></div>
                <div class="h-12 rounded-md bg-stone-200 dark:bg-neutral-700"></div>
                <div class="h-12 rounded-md bg-stone-200 dark:bg-neutral-700"></div>
            </div>
            <div class="h-8 rounded-md bg-stone-200 dark:bg-neutral-700"></div>
        </div>
        <div v-else-if="detailMember" class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="size-12 rounded-full bg-stone-100 text-stone-600 flex items-center justify-center overflow-hidden dark:bg-neutral-700 dark:text-neutral-200">
                    <img
                        v-if="memberAvatarUrl(detailMember)"
                        :src="memberAvatarUrl(detailMember)"
                        alt="Member avatar"
                        class="h-full w-full object-cover"
                        loading="lazy"
                        decoding="async"
                    >
                    <span v-else class="text-sm font-semibold">
                        {{ memberInitials(detailMember) }}
                    </span>
                </div>
                <div class="flex-1">
                    <p class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ detailMember.user?.name || `Member #${detailMember.id}` }}
                    </p>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ detailMember.user?.email || '-' }}
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span class="py-1 px-2 inline-flex items-center text-xs font-medium rounded-full"
                            :class="statusBadge(detailMember)">
                            {{ detailMember.is_active ? 'active' : 'inactive' }}
                        </span>
                        <span class="py-1 px-2 inline-flex items-center text-xs font-medium rounded-full"
                            :class="roleBadge(detailMember)">
                            {{ roleLabel(detailMember.role) }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="flex items-start gap-2">
                    <span class="mt-0.5 flex h-7 w-7 items-center justify-center rounded-full bg-stone-100 text-stone-500 dark:bg-neutral-800 dark:text-neutral-300">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="7" width="18" height="13" rx="2" />
                            <path d="M16 7V5a4 4 0 0 0-8 0v2" />
                        </svg>
                    </span>
                    <div>
                        <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">Title</p>
                        <p class="text-sm text-stone-800 dark:text-neutral-200">{{ detailMember.title || '-' }}</p>
                    </div>
                </div>
                <div class="flex items-start gap-2">
                    <span class="mt-0.5 flex h-7 w-7 items-center justify-center rounded-full bg-stone-100 text-stone-500 dark:bg-neutral-800 dark:text-neutral-300">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.09 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.81.3 1.6.54 2.36a2 2 0 0 1-.45 2.11L8.09 9.09a16 16 0 0 0 6 6l1.9-1.1a2 2 0 0 1 2.11-.45c.76.24 1.55.42 2.36.54A2 2 0 0 1 22 16.92z" />
                        </svg>
                    </span>
                    <div>
                        <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">Phone</p>
                        <p class="text-sm text-stone-800 dark:text-neutral-200">{{ detailMember.phone || '-' }}</p>
                    </div>
                </div>
                <div class="flex items-start gap-2">
                    <span class="mt-0.5 flex h-7 w-7 items-center justify-center rounded-full bg-stone-100 text-stone-500 dark:bg-neutral-800 dark:text-neutral-300">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" />
                            <path d="M8 2v4" />
                            <path d="M16 2v4" />
                            <path d="M3 10h18" />
                        </svg>
                    </span>
                    <div>
                        <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">Joined</p>
                        <p class="text-sm text-stone-800 dark:text-neutral-200">{{ formatDate(detailMember.created_at) }}</p>
                    </div>
                </div>
                <div class="flex items-start gap-2">
                    <span class="mt-0.5 flex h-7 w-7 items-center justify-center rounded-full bg-stone-100 text-stone-500 dark:bg-neutral-800 dark:text-neutral-300">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10 7h4" />
                            <path d="M10 11h4" />
                            <path d="M5 4h14v16H5z" />
                        </svg>
                    </span>
                    <div>
                        <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">Member ID</p>
                        <p class="text-sm text-stone-800 dark:text-neutral-200">#{{ detailMember.id }}</p>
                    </div>
                </div>
            </div>

            <div>
                <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">Permissions</p>
                <div v-if="permissionLabels(detailMember).length" class="mt-2 flex flex-wrap gap-2">
                    <span
                        v-for="permission in permissionLabels(detailMember)"
                        :key="permission"
                        class="rounded-full bg-stone-100 px-2 py-1 text-[11px] font-semibold text-stone-700 dark:bg-neutral-800 dark:text-neutral-200"
                    >
                        {{ permission }}
                    </span>
                </div>
                <p v-else class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                    No specific permissions.
                </p>
            </div>

            <div class="flex justify-end gap-2">
                <Link
                    v-if="memberPerformanceUrl(detailMember)"
                    :href="memberPerformanceUrl(detailMember)"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                >
                    {{ t('performance.employees.view_employee') }}
                </Link>
                <button type="button" @click="openEditFromDetail"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                    Edit
                </button>
                <button type="button" data-hs-overlay="#hs-team-detail"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-stone-800 text-white hover:bg-stone-700 dark:bg-neutral-200 dark:text-neutral-900">
                    Close
                </button>
            </div>
        </div>
        <div v-else class="text-sm text-stone-500 dark:text-neutral-400">
            No team member selected.
        </div>
    </Modal>

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
                <div class="md:col-span-2 space-y-2">
                    <label class="block text-xs text-stone-500 dark:text-neutral-400">Photo or icon</label>
                    <DropzoneInput v-model="createForm.profile_picture" label="Upload photo" />
                    <InputError class="mt-1" :message="createForm.errors.profile_picture" />
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        Or choose an avatar icon
                    </p>
                    <div class="grid grid-cols-4 gap-2">
                        <button
                            v-for="icon in avatarIconPresets"
                            :key="`create-${icon}`"
                            type="button"
                            @click="selectAvatarIcon(createForm, icon)"
                            class="relative flex items-center justify-center rounded-full border border-stone-200 bg-white p-2 transition hover:border-green-500 dark:border-neutral-700 dark:bg-neutral-900"
                            :class="createForm.avatar_icon === icon ? 'ring-2 ring-green-500 border-green-500' : ''"
                        >
                            <img :src="icon" alt="Avatar icon" class="size-10" loading="lazy" decoding="async" />
                            <span
                                v-if="icon === defaultAvatarIcon"
                                class="absolute -top-1 -right-1 rounded-full bg-green-600 px-1.5 py-0.5 text-[10px] font-semibold text-white"
                            >
                                Default
                            </span>
                        </button>
                    </div>
                    <div v-if="createForm.avatar_icon" class="flex justify-end">
                        <button type="button" @click="clearAvatarIcon(createForm)"
                            class="text-xs font-semibold text-stone-600 hover:text-stone-800 dark:text-neutral-400 dark:hover:text-neutral-200">
                            Clear icon
                        </button>
                    </div>
                    <InputError class="mt-1" :message="createForm.errors.avatar_icon" />
                </div>
                <div>
                    <FloatingSelect v-model="createForm.role" label="Role" :options="roleOptions" />
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
                <div class="md:col-span-2 space-y-2 rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        Planning rules (optional)
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-2">
                        <div>
                            <FloatingInput v-model="createForm.planning_rules.break_minutes" type="number" label="Break (min)" />
                            <InputError class="mt-1" :message="createForm.errors['planning_rules.break_minutes']" />
                        </div>
                        <div>
                            <FloatingInput v-model="createForm.planning_rules.min_hours_day" type="number" step="0.25" label="Min hours/day" />
                            <InputError class="mt-1" :message="createForm.errors['planning_rules.min_hours_day']" />
                        </div>
                        <div>
                            <FloatingInput v-model="createForm.planning_rules.max_hours_day" type="number" step="0.25" label="Max hours/day" />
                            <InputError class="mt-1" :message="createForm.errors['planning_rules.max_hours_day']" />
                        </div>
                        <div>
                            <FloatingInput v-model="createForm.planning_rules.max_hours_week" type="number" step="0.25" label="Max hours/week" />
                            <InputError class="mt-1" :message="createForm.errors['planning_rules.max_hours_week']" />
                        </div>
                    </div>
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
                <div class="md:col-span-2 space-y-2">
                    <label class="block text-xs text-stone-500 dark:text-neutral-400">Photo or icon</label>
                    <DropzoneInput v-model="editForm.profile_picture" label="Upload photo" />
                    <InputError class="mt-1" :message="editForm.errors.profile_picture" />
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        Or choose an avatar icon
                    </p>
                    <div class="grid grid-cols-4 gap-2">
                        <button
                            v-for="icon in avatarIconPresets"
                            :key="`edit-${icon}`"
                            type="button"
                            @click="selectAvatarIcon(editForm, icon)"
                            class="relative flex items-center justify-center rounded-full border border-stone-200 bg-white p-2 transition hover:border-green-500 dark:border-neutral-700 dark:bg-neutral-900"
                            :class="editForm.avatar_icon === icon ? 'ring-2 ring-green-500 border-green-500' : ''"
                        >
                            <img :src="icon" alt="Avatar icon" class="size-10" loading="lazy" decoding="async" />
                            <span
                                v-if="icon === defaultAvatarIcon"
                                class="absolute -top-1 -right-1 rounded-full bg-green-600 px-1.5 py-0.5 text-[10px] font-semibold text-white"
                            >
                                Default
                            </span>
                        </button>
                    </div>
                    <div v-if="editForm.avatar_icon" class="flex justify-end">
                        <button type="button" @click="clearAvatarIcon(editForm)"
                            class="text-xs font-semibold text-stone-600 hover:text-stone-800 dark:text-neutral-400 dark:hover:text-neutral-200">
                            Clear icon
                        </button>
                    </div>
                    <InputError class="mt-1" :message="editForm.errors.avatar_icon" />
                </div>
                <div>
                    <FloatingInput v-model="editForm.password" label="New password (optional)" />
                    <InputError class="mt-1" :message="editForm.errors.password" />
                </div>
                <div>
                    <FloatingSelect v-model="editForm.role" label="Role" :options="roleOptions" />
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
                <div class="md:col-span-2 space-y-2 rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        Planning rules (optional)
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-2">
                        <div>
                            <FloatingInput v-model="editForm.planning_rules.break_minutes" type="number" label="Break (min)" />
                            <InputError class="mt-1" :message="editForm.errors['planning_rules.break_minutes']" />
                        </div>
                        <div>
                            <FloatingInput v-model="editForm.planning_rules.min_hours_day" type="number" step="0.25" label="Min hours/day" />
                            <InputError class="mt-1" :message="editForm.errors['planning_rules.min_hours_day']" />
                        </div>
                        <div>
                            <FloatingInput v-model="editForm.planning_rules.max_hours_day" type="number" step="0.25" label="Max hours/day" />
                            <InputError class="mt-1" :message="editForm.errors['planning_rules.max_hours_day']" />
                        </div>
                        <div>
                            <FloatingInput v-model="editForm.planning_rules.max_hours_week" type="number" step="0.25" label="Max hours/week" />
                            <InputError class="mt-1" :message="editForm.errors['planning_rules.max_hours_week']" />
                        </div>
                    </div>
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
