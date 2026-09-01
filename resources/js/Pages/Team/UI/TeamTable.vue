<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import AdminPaginationLinks from '@/Components/DataTable/AdminPaginationLinks.vue';
import Modal from '@/Components/UI/Modal.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
import DropzoneInput from '@/Components/DropzoneInput.vue';
import {
    DATA_TABLE_PER_PAGE_OPTIONS,
    normalizeDataTablePerPage,
    resolveDataTablePerPage,
} from '@/Components/DataTable/pagination';
import { humanizeDate } from '@/utils/date';
import { avatarIconPresets, defaultAvatarIcon } from '@/utils/iconPresets';
import { usePermissions } from '@/Composables/usePermissions';
import { crmSegmentedControlButtonClass, crmSegmentedControlClass } from '@/utils/crmButtonStyles';

const props = defineProps({
    teamMembers: {
        type: Object,
        default: () => ({}),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    availablePermissions: {
        type: Array,
        default: () => [],
    },
    companyRoles: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();
const page = usePage();
const { hasAnyPermission, hasPermission } = usePermissions();
const canAssignRoles = computed(() => hasPermission('assign_roles'));
const canCreateTeamMembers = computed(() => hasPermission('create_team_members'));
const canUpdateTeamMembers = computed(() => hasPermission('update_team_members'));
const canDeactivateTeamMembers = computed(() => hasPermission('deactivate_team_members'));
const translateOrFallback = (key, fallback, params = {}) => {
    const translated = t(key, params);

    return translated === key ? fallback : translated;
};
const filterForm = useForm({
    search: props.filters?.search ?? '',
});
const isLoading = ref(false);
const teamViewModes = ['table', 'cards'];
const viewMode = ref('table');
if (typeof window !== 'undefined') {
    const storedViewMode = window.localStorage.getItem('team_view_mode');
    if (teamViewModes.includes(storedViewMode)) {
        viewMode.value = storedViewMode;
    }
}
const isAvatarIcon = (value) => avatarIconPresets.includes(value);
const roleOptions = computed(() => ([
    { id: 'admin', name: t('team.roles.admin') },
    { id: 'member', name: t('team.roles.member') },
    { id: 'seller', name: t('team.roles.seller') },
    { id: 'sales_manager', name: t('team.roles.sales_manager') },
]));
const defaultAccessRoleId = computed(() => {
    const roles = props.companyRoles || [];
    const defaultRole = roles.find((role) => role.slug === 'employe_standard')
        || roles.find((role) => role.slug === 'coiffeur')
        || roles.find((role) => role.slug !== 'owner')
        || roles[0];

    return canAssignRoles.value && defaultRole?.id ? String(defaultRole.id) : '';
});
const companyRoleOptions = computed(() => [
    { id: '', name: t('team.forms.no_access_role') },
    ...(props.companyRoles || []).map((role) => ({
        id: String(role.id),
        name: `${role.name}${role.is_system ? ` · ${t('team.forms.system_role_suffix')}` : ''}`,
    })),
]);
const teamRows = computed(() => (Array.isArray(props.teamMembers?.data) ? props.teamMembers.data : []));
const teamLinks = computed(() => (Array.isArray(props.teamMembers?.links) ? props.teamMembers.links : []));
const currentPerPage = computed(() => resolveDataTablePerPage(props.teamMembers?.per_page, props.filters?.per_page));
const teamPaginationPageCount = computed(() => teamLinks.value
    .map((link) => String(link?.label ?? '').replace(/<[^>]*>/g, '').trim())
    .filter((label) => /^\d+$/u.test(label))
    .length);
const hasMultipleTeamPages = computed(() => teamPaginationPageCount.value > 1);
const teamResultsLabel = computed(() => t('datatable.shared.results_count', {
    count: props.teamMembers?.total ?? teamRows.value.length ?? 0,
}));

const filterPayload = () => {
    const payload = {
        search: filterForm.search,
        per_page: currentPerPage.value,
    };

    Object.keys(payload).forEach((key) => {
        const value = payload[key];
        if (value === '' || value === null || value === undefined) {
            delete payload[key];
        }
    });

    return payload;
};

let filterTimeout;
const autoFilter = () => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }

    filterTimeout = setTimeout(() => {
        isLoading.value = true;
        router.get(route('team.index'), filterPayload(), {
            only: ['teamMembers', 'filters', 'stats'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                isLoading.value = false;
            },
        });
    }, 300);
};

const clearFilters = () => {
    filterForm.search = '';
};

const updatePerPage = (event) => {
    const nextPerPage = normalizeDataTablePerPage(event?.target?.value, currentPerPage.value);

    isLoading.value = true;
    router.get(route('team.index'), {
        ...filterPayload(),
        per_page: nextPerPage,
    }, {
        only: ['teamMembers', 'filters', 'stats'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
            isLoading.value = false;
        },
    });
};

const setViewMode = (mode) => {
    if (!teamViewModes.includes(mode) || viewMode.value === mode) {
        return;
    }

    viewMode.value = mode;

    if (typeof window !== 'undefined') {
        window.localStorage.setItem('team_view_mode', mode);
    }
};

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

const accountFeatures = computed(() => page.props.auth?.account?.features || {});
const performanceMode = computed(() => {
    const features = accountFeatures.value;

    if (features.reservations) {
        return 'reservations';
    }

    if (features.jobs || features.tasks) {
        return 'services';
    }

    return features.sales ? 'products' : null;
});
const canViewTeamPerformance = computed(() => (
    Boolean(page.props.auth?.account?.is_owner)
    || page.props.auth?.account?.team?.role === 'admin'
    || hasAnyPermission(['reports.team', 'reports.view', 'view_team_reports', 'view_reports'])
    || (performanceMode.value === 'products' && hasAnyPermission(['sales.manage', 'view_sales_reports']))
));
const canViewOwnPerformance = computed(() => {
    if (performanceMode.value === 'reservations') {
        return hasAnyPermission(['reservations.view', 'reservations.queue', 'reservations.manage']);
    }

    if (performanceMode.value === 'services') {
        return hasAnyPermission(['jobs.view', 'jobs.edit', 'tasks.view', 'tasks.edit']);
    }

    return performanceMode.value === 'products'
        && hasAnyPermission(['sales.pos', 'sales.manage', 'view_sales_reports']);
});

const memberPerformanceUrl = (member) => {
    const bypassesFeatureMiddleware = Boolean(page.props.auth?.account?.is_superadmin);
    if ((!accountFeatures.value.performance && !bypassesFeatureMiddleware) || !performanceMode.value) {
        return null;
    }

    const userId = member?.user?.id || member?.user_id;
    if (!userId) {
        return null;
    }

    const isCurrentUser = Number(userId) === Number(page.props.auth?.user?.id);
    if (!canViewTeamPerformance.value && !(isCurrentUser && canViewOwnPerformance.value)) {
        return null;
    }

    return route('performance.employee.show', userId);
};

const createForm = useForm({
    name: '',
    email: '',
    role: 'member',
    company_role_id: defaultAccessRoleId.value,
    title: '',
    phone: '',
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
    if (!canCreateTeamMembers.value || createForm.processing) {
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
                createForm.company_role_id = defaultAccessRoleId.value;
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
    company_role_id: '',
    title: '',
    phone: '',
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
    if (!canUpdateTeamMembers.value) {
        return;
    }

    editingMemberId.value = member.id;
    editForm.clearErrors();

    editForm.name = member.user?.name || '';
    editForm.email = member.user?.email || '';
    editForm.password = '';
    editForm.role = member.role || 'member';
    editForm.company_role_id = member.company_role_id ? String(member.company_role_id) : '';
    editForm.title = member.title || '';
    editForm.phone = member.phone || '';
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
    if (!canUpdateTeamMembers.value || !editingMemberId.value || editForm.processing) {
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
    if (!canDeactivateTeamMembers.value) {
        return;
    }

    if (!confirm(t('team.confirm.deactivate', { name: memberDisplayName(member) }))) {
        return;
    }
    router.delete(route('team.destroy', member.id), { preserveScroll: true });
};

const activateMember = (member) => {
    if (!canUpdateTeamMembers.value) {
        return;
    }

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
    const entry = roleOptions.value.find((option) => option.id === role);
    if (entry) {
        return entry.name;
    }
    return translateOrFallback(`team.roles.${role}`, String(role || '').replace(/_/g, ' '));
};
const companyRoleLabel = (member) => member?.company_role?.name || t('team.forms.no_access_role');

const formatDate = (value) => humanizeDate(value) || String(value || '');

const memberAvatarUrl = (member) => member.user?.profile_picture_url || member.user?.profile_picture || '';
const memberDisplayName = (member) => member.user?.name || t('team.table.member_fallback', { id: member?.id || '?' });
const statusLabel = (member) => t(`team.status.${member?.is_active ? 'active' : 'inactive'}`);

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

const permissionTranslationKey = (permissionId) => `team.permissions.options.${String(permissionId || '').replace(/\./g, '_')}`;
const localizedPermissionName = (permission) => translateOrFallback(
    permissionTranslationKey(permission?.id),
    permission?.name || permission?.id || '',
);

const permissionMap = computed(() => {
    const map = new Map();
    (props.availablePermissions || []).forEach((permission) => {
        map.set(permission.id, localizedPermissionName(permission));
    });
    return map;
});

const availablePermissionIds = computed(() => new Set(
    (props.availablePermissions || []).map((permission) => permission.id)
));

const permissionLabels = (member) => {
    const directPermissions = Array.isArray(member?.permissions)
        ? member.permissions.filter((permission) => availablePermissionIds.value.has(permission))
        : [];
    const rolePermissions = Array.isArray(member?.company_role?.permissions)
        ? member.company_role.permissions
        : [];
    const labels = [
        ...rolePermissions.map((permission) => permission?.name || permission?.slug || ''),
        ...directPermissions.map((permission) => permissionMap.value.get(permission) || permission),
    ].filter(Boolean);

    return [...new Set(labels)];
};
const visiblePermissionLabels = (member) => permissionLabels(member).slice(0, 3);
const hiddenPermissionLabelCount = (member) => Math.max(0, permissionLabels(member).length - 3);

watch(() => filterForm.search, () => {
    autoFilter();
});

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
        <AdminDataTableToolbar
            :busy="isLoading"
            :show-apply="false"
            :clear-label="t('team.actions.clear')"
            @clear="clearFilters"
        >
            <template #search>
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
                    <input type="text" v-model="filterForm.search"
                        class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400"
                        :placeholder="t('team.table.search_placeholder')">
                </div>
            </template>

            <template #actions>
                <div
                    :class="crmSegmentedControlClass()"
                    role="group"
                    :aria-label="t('team.view.label')"
                >
                    <button
                        type="button"
                        data-testid="team-view-table"
                        :class="crmSegmentedControlButtonClass(viewMode === 'table')"
                        :aria-pressed="viewMode === 'table'"
                        @click="setViewMode('table')"
                    >
                        <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M3 3h18v6H3z" />
                            <path d="M3 13h18v8H3z" />
                        </svg>
                        {{ t('team.view.table') }}
                    </button>
                    <button
                        type="button"
                        data-testid="team-view-cards"
                        :class="crmSegmentedControlButtonClass(viewMode === 'cards')"
                        :aria-pressed="viewMode === 'cards'"
                        @click="setViewMode('cards')"
                    >
                        <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <rect x="3" y="3" width="7" height="7" rx="1" />
                            <rect x="14" y="3" width="7" height="7" rx="1" />
                            <rect x="3" y="14" width="7" height="7" rx="1" />
                            <rect x="14" y="14" width="7" height="7" rx="1" />
                        </svg>
                        {{ t('team.view.cards') }}
                    </button>
                </div>
                <button v-if="canCreateTeamMembers" type="button" data-hs-overlay="#hs-team-create"
                    class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                    + {{ t('team.actions.add_member') }}
                </button>
            </template>
        </AdminDataTableToolbar>

        <AdminDataTable
            v-if="viewMode === 'table'"
            embedded
            :rows="teamRows"
            :links="teamLinks"
            :loading="isLoading && teamRows.length === 0"
            :result-label="teamResultsLabel"
            :show-pagination="teamRows.length > 0"
            show-per-page
            :per-page="currentPerPage"
        >
            <template #empty>
                <div class="px-4 py-6 text-sm text-stone-600 dark:text-neutral-400">
                    {{ t('team.table.empty') }}
                </div>
            </template>

            <template #head>
                <tr>
                    <th scope="col" class="min-w-[260px]">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ t('team.table.headings.member') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-28">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ t('team.table.headings.role') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-40">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ t('team.table.headings.title') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-40">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ t('team.table.headings.phone') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-28">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ t('team.table.headings.status') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-32">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ t('team.table.headings.added') }}
                        </div>
                    </th>
                    <th scope="col"></th>
                </tr>
            </template>

            <template #body="{ rows }">
                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                    <tr v-for="member in rows" :key="member.id">
                        <td class="size-px whitespace-nowrap px-4 py-2 text-start">
                            <div class="flex items-center gap-3">
                                <div class="size-10 rounded-full bg-stone-100 text-stone-600 flex items-center justify-center overflow-hidden dark:bg-neutral-700 dark:text-neutral-200">
                                    <img
                                        v-if="memberAvatarUrl(member)"
                                        :src="memberAvatarUrl(member)"
                                        :alt="t('team.table.member_avatar_alt')"
                                        class="h-full w-full object-cover"
                                        loading="lazy"
                                        decoding="async"
                                    >
                                    <span v-else class="text-xs font-semibold">
                                        {{ memberInitials(member) }}
                                    </span>
                                </div>
                                <div class="flex flex-col">
                                    <button
                                        type="button"
                                        class="rounded-sm text-start text-sm font-medium text-stone-800 hover:text-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 dark:text-neutral-200 dark:hover:text-emerald-300 dark:focus-visible:ring-offset-neutral-800"
                                        :aria-label="t('team.actions.view_member', { name: memberDisplayName(member) })"
                                        @click="openDetailMember(member)"
                                    >
                                        {{ memberDisplayName(member) }}
                                    </button>
                                    <span class="text-xs text-stone-500 dark:text-neutral-500">
                                        {{ member.user?.email || '-' }}
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td class="size-px whitespace-nowrap px-4 py-2">
                            <div class="flex flex-col gap-1">
                                <span class="py-1.5 px-2 inline-flex w-fit items-center text-xs font-medium rounded-full"
                                    :class="roleBadge(member)">
                                    {{ roleLabel(member.role) }}
                                </span>
                                <span class="text-[11px] text-stone-500 dark:text-neutral-400">
                                    {{ companyRoleLabel(member) }}
                                </span>
                            </div>
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
                                {{ statusLabel(member) }}
                            </span>
                        </td>
                        <td class="size-px whitespace-nowrap px-4 py-2">
                            <span class="text-xs text-stone-500 dark:text-neutral-500">
                                {{ formatDate(member.created_at) }}
                            </span>
                        </td>
                        <td class="size-px whitespace-nowrap px-4 py-2 text-end">
                            <AdminDataTableActions :label="t('team.actions.member_actions', { name: memberDisplayName(member) })">
                                <button type="button" @click="openDetailMember(member)"
                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                    {{ t('team.actions.details') }}
                                </button>
                                <Link
                                    v-if="memberPerformanceUrl(member)"
                                    :href="memberPerformanceUrl(member)"
                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                >
                                    {{ t('performance.employees.view_employee') }}
                                </Link>
                                <button v-if="canUpdateTeamMembers" type="button" @click="openEditMember(member)"
                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                    {{ t('team.actions.edit') }}
                                </button>
                                <div
                                    v-if="(canDeactivateTeamMembers && member.is_active) || (canUpdateTeamMembers && !member.is_active)"
                                    class="my-1 border-t border-stone-200 dark:border-neutral-800"
                                ></div>
                                <button v-if="canDeactivateTeamMembers && member.is_active" type="button" @click="deactivateMember(member)"
                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800">
                                    {{ t('team.actions.deactivate') }}
                                </button>
                                <button v-else-if="canUpdateTeamMembers && !member.is_active" type="button" @click="activateMember(member)"
                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800">
                                    {{ t('team.actions.activate') }}
                                </button>
                            </AdminDataTableActions>
                        </td>
                    </tr>
                </tbody>
            </template>
        </AdminDataTable>

        <div v-else class="space-y-3" data-testid="team-card-view">
            <div v-if="isLoading && !teamRows.length" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="row in 6"
                    :key="`team-card-skeleton-${row}`"
                    class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <div class="animate-pulse space-y-4">
                        <div class="flex items-center gap-3">
                            <div class="size-11 rounded-full bg-stone-200 dark:bg-neutral-700" />
                            <div class="flex-1 space-y-2">
                                <div class="h-3 w-3/4 rounded-sm bg-stone-200 dark:bg-neutral-700" />
                                <div class="h-3 w-1/2 rounded-sm bg-stone-200 dark:bg-neutral-700" />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div v-for="item in 4" :key="`team-card-skeleton-${row}-${item}`" class="h-12 rounded-sm bg-stone-200 dark:bg-neutral-700" />
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-else-if="!teamRows.length"
                class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-4 py-10 text-center text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400"
            >
                {{ t('team.table.empty') }}
            </div>

            <div v-else class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3" data-testid="team-card-grid">
                <article
                    v-for="member in teamRows"
                    :key="`team-card-${member.id}`"
                    class="flex h-full flex-col overflow-hidden rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    :aria-labelledby="`team-card-title-${member.id}`"
                >
                    <header class="flex items-start justify-between gap-3 border-b border-stone-100 px-4 py-3 dark:border-neutral-800">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-200">
                                <img
                                    v-if="memberAvatarUrl(member)"
                                    :src="memberAvatarUrl(member)"
                                    :alt="t('team.table.member_avatar_alt')"
                                    class="h-full w-full object-cover"
                                    loading="lazy"
                                    decoding="async"
                                >
                                <span v-else class="text-xs font-semibold">
                                    {{ memberInitials(member) }}
                                </span>
                            </div>
                            <div class="min-w-0">
                                <p :id="`team-card-title-${member.id}`" class="truncate text-sm font-semibold text-stone-900 dark:text-white">
                                    {{ memberDisplayName(member) }}
                                </p>
                                <p class="mt-0.5 truncate text-xs text-stone-500 dark:text-neutral-400" :title="member.user?.email || '-'">
                                    {{ member.user?.email || '-' }}
                                </p>
                            </div>
                        </div>
                        <span
                            class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-semibold"
                            :class="statusBadge(member)"
                        >
                            {{ statusLabel(member) }}
                        </span>
                    </header>

                    <div class="flex-1 space-y-3 p-4">
                        <div class="flex flex-wrap gap-1.5">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="roleBadge(member)">
                                {{ roleLabel(member.role) }}
                            </span>
                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200">
                                {{ companyRoleLabel(member) }}
                            </span>
                        </div>

                        <dl class="grid grid-cols-2 gap-3">
                            <div class="min-w-0 rounded-sm bg-stone-50 p-2.5 dark:bg-neutral-800">
                                <dt class="text-[0.6875rem] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ t('team.detail.title') }}
                                </dt>
                                <dd class="mt-1 truncate text-xs font-medium text-stone-700 dark:text-neutral-200" :title="member.title || '-'">
                                    {{ member.title || '-' }}
                                </dd>
                            </div>
                            <div class="min-w-0 rounded-sm bg-stone-50 p-2.5 dark:bg-neutral-800">
                                <dt class="text-[0.6875rem] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ t('team.detail.phone') }}
                                </dt>
                                <dd class="mt-1 truncate text-xs font-medium text-stone-700 dark:text-neutral-200" :title="member.phone || '-'">
                                    {{ member.phone || '-' }}
                                </dd>
                            </div>
                            <div class="min-w-0 rounded-sm border border-stone-200 px-3 py-2.5 dark:border-neutral-700">
                                <dt class="text-[0.6875rem] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ t('team.detail.joined') }}
                                </dt>
                                <dd class="mt-1 text-xs font-medium text-stone-700 dark:text-neutral-200">
                                    {{ formatDate(member.created_at) }}
                                </dd>
                            </div>
                            <div class="min-w-0 rounded-sm border border-stone-200 px-3 py-2.5 dark:border-neutral-700">
                                <dt class="text-[0.6875rem] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ t('team.detail.member_id') }}
                                </dt>
                                <dd class="mt-1 text-xs font-medium text-stone-700 dark:text-neutral-200">
                                    #{{ member.id }}
                                </dd>
                            </div>
                        </dl>

                        <div class="rounded-sm border border-stone-200 px-3 py-2.5 dark:border-neutral-700">
                            <p class="text-[0.6875rem] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                {{ t('team.detail.permissions') }}
                            </p>
                            <div v-if="visiblePermissionLabels(member).length" class="mt-2 flex flex-wrap gap-1.5">
                                <span
                                    v-for="permission in visiblePermissionLabels(member)"
                                    :key="`${member.id}-${permission}`"
                                    class="inline-flex max-w-full items-center truncate rounded-sm bg-stone-100 px-2 py-1 text-[11px] font-medium text-stone-700 dark:bg-neutral-800 dark:text-neutral-200"
                                    :title="permission"
                                >
                                    {{ permission }}
                                </span>
                                <span
                                    v-if="hiddenPermissionLabelCount(member)"
                                    class="inline-flex items-center rounded-sm bg-stone-100 px-2 py-1 text-[11px] font-semibold text-stone-500 dark:bg-neutral-800 dark:text-neutral-400"
                                >
                                    +{{ hiddenPermissionLabelCount(member) }}
                                </span>
                            </div>
                            <p v-else class="mt-1 text-xs text-stone-400 dark:text-neutral-500">
                                {{ t('team.detail.no_permissions') }}
                            </p>
                        </div>
                    </div>

                    <footer class="flex items-center justify-between gap-2 border-t border-stone-100 bg-stone-50 px-4 py-2.5 dark:border-neutral-800 dark:bg-neutral-950">
                        <button
                            type="button"
                            class="inline-flex min-h-8 items-center justify-center gap-1.5 rounded-sm bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:text-neutral-950 dark:hover:bg-emerald-400 dark:focus-visible:ring-offset-neutral-950"
                            :aria-label="t('team.actions.view_member', { name: memberDisplayName(member) })"
                            :data-testid="`team-card-open-${member.id}`"
                            @click="openDetailMember(member)"
                        >
                            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M2.1 12a10.5 10.5 0 0 1 19.8 0 10.5 10.5 0 0 1-19.8 0Z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                            {{ t('team.actions.details') }}
                        </button>

                        <AdminDataTableActions :label="t('team.actions.member_actions', { name: memberDisplayName(member) })">
                            <button
                                type="button"
                                class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                @click="openDetailMember(member)"
                            >
                                {{ t('team.actions.details') }}
                            </button>
                            <Link
                                v-if="memberPerformanceUrl(member)"
                                :href="memberPerformanceUrl(member)"
                                class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                            >
                                {{ t('performance.employees.view_employee') }}
                            </Link>
                            <button
                                v-if="canUpdateTeamMembers"
                                type="button"
                                class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                @click="openEditMember(member)"
                            >
                                {{ t('team.actions.edit') }}
                            </button>
                            <div
                                v-if="(canDeactivateTeamMembers && member.is_active) || (canUpdateTeamMembers && !member.is_active)"
                                class="my-1 border-t border-stone-200 dark:border-neutral-800"
                            />
                            <button
                                v-if="canDeactivateTeamMembers && member.is_active"
                                type="button"
                                class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800"
                                @click="deactivateMember(member)"
                            >
                                {{ t('team.actions.deactivate') }}
                            </button>
                            <button
                                v-else-if="canUpdateTeamMembers && !member.is_active"
                                type="button"
                                class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800"
                                @click="activateMember(member)"
                            >
                                {{ t('team.actions.activate') }}
                            </button>
                        </AdminDataTableActions>
                    </footer>
                </article>
            </div>

            <div
                class="border-t border-stone-200 pt-4 dark:border-neutral-700"
                data-testid="team-card-footer"
            >
                <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] md:items-center">
                    <p class="min-w-0 text-sm text-stone-500 dark:text-neutral-400 md:col-start-1">
                        {{ teamResultsLabel }}
                    </p>
                    <div v-if="hasMultipleTeamPages" class="flex justify-start md:col-start-2 md:justify-center">
                        <AdminPaginationLinks :links="teamLinks" />
                    </div>
                    <div class="flex justify-start md:col-start-3 md:justify-end">
                        <label class="inline-flex items-center gap-2 whitespace-nowrap text-xs text-stone-500 dark:text-neutral-400">
                            <span>{{ t('datatable.shared.rows_per_page') }}</span>
                            <span class="relative inline-flex">
                                <select
                                    :value="currentPerPage"
                                    class="appearance-none rounded-sm border border-stone-200 bg-none bg-white py-1 pl-2 pr-7 text-xs text-stone-700 focus:border-green-500 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                    data-testid="team-card-per-page"
                                    @change="updatePerPage"
                                >
                                    <option
                                        v-for="option in DATA_TABLE_PER_PAGE_OPTIONS"
                                        :key="`team-card-per-page-${option}`"
                                        :value="option"
                                    >
                                        {{ option }}
                                    </option>
                                </select>
                                <span class="pointer-events-none absolute inset-y-0 right-2 flex items-center text-stone-400 dark:text-neutral-500">
                                    <svg class="size-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="m5 7.5 5 5 5-5" />
                                    </svg>
                                </span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <Modal :title="t('team.dialogs.member_details')" :id="'hs-team-detail'">
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
                        :alt="t('team.table.member_avatar_alt')"
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
                        {{ memberDisplayName(detailMember) }}
                    </p>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ detailMember.user?.email || '-' }}
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span class="py-1 px-2 inline-flex items-center text-xs font-medium rounded-full"
                            :class="statusBadge(detailMember)">
                            {{ statusLabel(detailMember) }}
                        </span>
                        <span class="py-1 px-2 inline-flex items-center text-xs font-medium rounded-full"
                            :class="roleBadge(detailMember)">
                            {{ roleLabel(detailMember.role) }}
                        </span>
                        <span class="py-1 px-2 inline-flex items-center text-xs font-medium rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200">
                            {{ companyRoleLabel(detailMember) }}
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
                        <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ t('team.detail.title') }}</p>
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
                        <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ t('team.detail.phone') }}</p>
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
                        <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ t('team.detail.joined') }}</p>
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
                        <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ t('team.detail.member_id') }}</p>
                        <p class="text-sm text-stone-800 dark:text-neutral-200">#{{ detailMember.id }}</p>
                    </div>
                </div>
            </div>

            <div>
                <p class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ t('team.detail.permissions') }}</p>
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
                    {{ t('team.detail.no_permissions') }}
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
                <button v-if="canUpdateTeamMembers" type="button" @click="openEditFromDetail"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                    {{ t('team.actions.edit') }}
                </button>
                <button type="button" data-hs-overlay="#hs-team-detail"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-stone-800 text-white hover:bg-stone-700 dark:bg-neutral-200 dark:text-neutral-900">
                    {{ t('team.actions.close') }}
                </button>
            </div>
        </div>
        <div v-else class="text-sm text-stone-500 dark:text-neutral-400">
            {{ t('team.detail.none_selected') }}
        </div>
    </Modal>

    <Modal v-if="canCreateTeamMembers" :title="t('team.dialogs.add_member')" :id="'hs-team-create'">
        <form class="space-y-4" @submit.prevent="submitCreate">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <FloatingInput v-model="createForm.name" :label="t('team.forms.name')" />
                    <InputError class="mt-1" :message="createForm.errors.name" />
                </div>
                <div>
                    <FloatingInput v-model="createForm.email" :label="t('team.forms.email')" />
                    <InputError class="mt-1" :message="createForm.errors.email" />
                </div>
                <div class="md:col-span-2 space-y-2">
                    <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ t('team.forms.photo_or_icon') }}</label>
                    <DropzoneInput v-model="createForm.profile_picture" :label="t('team.forms.upload_photo')" />
                    <InputError class="mt-1" :message="createForm.errors.profile_picture" />
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ t('team.forms.or_choose_avatar_icon') }}
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
                            <img :src="icon" :alt="t('team.forms.avatar_icon_alt')" class="size-10" loading="lazy" decoding="async" />
                            <span
                                v-if="icon === defaultAvatarIcon"
                                class="absolute -top-1 -right-1 rounded-full bg-green-600 px-1.5 py-0.5 text-[10px] font-semibold text-white"
                            >
                                {{ t('team.forms.default') }}
                            </span>
                        </button>
                    </div>
                    <div v-if="createForm.avatar_icon" class="flex justify-end">
                        <button type="button" @click="clearAvatarIcon(createForm)"
                            class="text-xs font-semibold text-stone-600 hover:text-stone-800 dark:text-neutral-400 dark:hover:text-neutral-200">
                            {{ t('team.forms.clear_icon') }}
                        </button>
                    </div>
                    <InputError class="mt-1" :message="createForm.errors.avatar_icon" />
                </div>
                <div>
                    <FloatingSelect v-model="createForm.role" :label="t('team.forms.role')" :options="roleOptions" />
                    <InputError class="mt-1" :message="createForm.errors.role" />
                </div>
                <div v-if="canAssignRoles">
                    <FloatingSelect v-model="createForm.company_role_id" :label="t('team.forms.access_role')" :options="companyRoleOptions" />
                    <InputError class="mt-1" :message="createForm.errors.company_role_id" />
                </div>
                <p v-if="canAssignRoles" class="md:col-span-2 text-xs text-stone-500 dark:text-neutral-400">
                    {{ t('team.forms.access_role_help') }}
                </p>
                <div>
                    <FloatingInput v-model="createForm.title" :label="t('team.forms.title_optional')" />
                    <InputError class="mt-1" :message="createForm.errors.title" />
                </div>
                <div>
                    <FloatingInput v-model="createForm.phone" :label="t('team.forms.phone_optional')" />
                    <InputError class="mt-1" :message="createForm.errors.phone" />
                </div>
                <div class="md:col-span-2 space-y-2 rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ t('team.planning.title_optional') }}
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-2">
                        <div>
                            <FloatingInput v-model="createForm.planning_rules.break_minutes" type="number" :label="t('team.planning.break_minutes')" />
                            <InputError class="mt-1" :message="createForm.errors['planning_rules.break_minutes']" />
                        </div>
                        <div>
                            <FloatingInput v-model="createForm.planning_rules.min_hours_day" type="number" step="0.25" :label="t('team.planning.min_hours_day')" />
                            <InputError class="mt-1" :message="createForm.errors['planning_rules.min_hours_day']" />
                        </div>
                        <div>
                            <FloatingInput v-model="createForm.planning_rules.max_hours_day" type="number" step="0.25" :label="t('team.planning.max_hours_day')" />
                            <InputError class="mt-1" :message="createForm.errors['planning_rules.max_hours_day']" />
                        </div>
                        <div>
                            <FloatingInput v-model="createForm.planning_rules.max_hours_week" type="number" step="0.25" :label="t('team.planning.max_hours_week')" />
                            <InputError class="mt-1" :message="createForm.errors['planning_rules.max_hours_week']" />
                        </div>
                    </div>
                </div>
            </div>
            <p class="text-xs text-stone-500 dark:text-neutral-400">
                {{ t('team.messages.invite_email') }}
            </p>

            <div class="flex justify-end gap-2">
                <button type="button" data-hs-overlay="#hs-team-create"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                    {{ t('team.actions.cancel') }}
                </button>
                <button type="submit" :disabled="createForm.processing"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                    {{ t('team.actions.add_member') }}
                </button>
            </div>
        </form>
    </Modal>

    <Modal v-if="canUpdateTeamMembers" :title="t('team.dialogs.edit_member')" :id="'hs-team-edit'">
        <form class="space-y-4" @submit.prevent="submitEdit">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <FloatingInput v-model="editForm.name" :label="t('team.forms.name')" />
                    <InputError class="mt-1" :message="editForm.errors.name" />
                </div>
                <div>
                    <FloatingInput v-model="editForm.email" :label="t('team.forms.email')" />
                    <InputError class="mt-1" :message="editForm.errors.email" />
                </div>
                <div class="md:col-span-2 space-y-2">
                    <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ t('team.forms.photo_or_icon') }}</label>
                    <DropzoneInput v-model="editForm.profile_picture" :label="t('team.forms.upload_photo')" />
                    <InputError class="mt-1" :message="editForm.errors.profile_picture" />
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ t('team.forms.or_choose_avatar_icon') }}
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
                            <img :src="icon" :alt="t('team.forms.avatar_icon_alt')" class="size-10" loading="lazy" decoding="async" />
                            <span
                                v-if="icon === defaultAvatarIcon"
                                class="absolute -top-1 -right-1 rounded-full bg-green-600 px-1.5 py-0.5 text-[10px] font-semibold text-white"
                            >
                                {{ t('team.forms.default') }}
                            </span>
                        </button>
                    </div>
                    <div v-if="editForm.avatar_icon" class="flex justify-end">
                        <button type="button" @click="clearAvatarIcon(editForm)"
                            class="text-xs font-semibold text-stone-600 hover:text-stone-800 dark:text-neutral-400 dark:hover:text-neutral-200">
                            {{ t('team.forms.clear_icon') }}
                        </button>
                    </div>
                    <InputError class="mt-1" :message="editForm.errors.avatar_icon" />
                </div>
                <div>
                    <FloatingInput v-model="editForm.password" :label="t('team.forms.new_password_optional')" />
                    <InputError class="mt-1" :message="editForm.errors.password" />
                </div>
                <div>
                    <FloatingSelect v-model="editForm.role" :label="t('team.forms.role')" :options="roleOptions" />
                    <InputError class="mt-1" :message="editForm.errors.role" />
                </div>
                <div v-if="canAssignRoles">
                    <FloatingSelect v-model="editForm.company_role_id" :label="t('team.forms.access_role')" :options="companyRoleOptions" />
                    <InputError class="mt-1" :message="editForm.errors.company_role_id" />
                </div>
                <p v-if="canAssignRoles" class="md:col-span-2 text-xs text-stone-500 dark:text-neutral-400">
                    {{ t('team.forms.access_role_help') }}
                </p>
                <div>
                    <FloatingInput v-model="editForm.title" :label="t('team.forms.title_optional')" />
                    <InputError class="mt-1" :message="editForm.errors.title" />
                </div>
                <div>
                    <FloatingInput v-model="editForm.phone" :label="t('team.forms.phone_optional')" />
                    <InputError class="mt-1" :message="editForm.errors.phone" />
                </div>
                <div class="md:col-span-2 space-y-2 rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ t('team.planning.title_optional') }}
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-2">
                        <div>
                            <FloatingInput v-model="editForm.planning_rules.break_minutes" type="number" :label="t('team.planning.break_minutes')" />
                            <InputError class="mt-1" :message="editForm.errors['planning_rules.break_minutes']" />
                        </div>
                        <div>
                            <FloatingInput v-model="editForm.planning_rules.min_hours_day" type="number" step="0.25" :label="t('team.planning.min_hours_day')" />
                            <InputError class="mt-1" :message="editForm.errors['planning_rules.min_hours_day']" />
                        </div>
                        <div>
                            <FloatingInput v-model="editForm.planning_rules.max_hours_day" type="number" step="0.25" :label="t('team.planning.max_hours_day')" />
                            <InputError class="mt-1" :message="editForm.errors['planning_rules.max_hours_day']" />
                        </div>
                        <div>
                            <FloatingInput v-model="editForm.planning_rules.max_hours_week" type="number" step="0.25" :label="t('team.planning.max_hours_week')" />
                            <InputError class="mt-1" :message="editForm.errors['planning_rules.max_hours_week']" />
                        </div>
                    </div>
                </div>
                <div class="md:col-span-2">
                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                        <Checkbox v-model:checked="editForm.is_active" />
                        <span>{{ t('team.forms.active') }}</span>
                    </label>
                    <InputError class="mt-1" :message="editForm.errors.is_active" />
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" data-hs-overlay="#hs-team-edit"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                    {{ t('team.actions.cancel') }}
                </button>
                <button type="submit" :disabled="editForm.processing"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                    {{ t('team.actions.save') }}
                </button>
            </div>
        </form>
    </Modal>
</template>
