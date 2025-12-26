<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { prepareMediaFile, MEDIA_LIMITS } from '@/utils/media';

const props = defineProps({
    announcements: {
        type: Array,
        default: () => [],
    },
    tenants: {
        type: Array,
        default: () => [],
    },
    audiences: {
        type: Array,
        default: () => [],
    },
    placements: {
        type: Array,
        default: () => [],
    },
    statuses: {
        type: Array,
        default: () => [],
    },
    media_types: {
        type: Array,
        default: () => [],
    },
    display_styles: {
        type: Array,
        default: () => [],
    },
});

const showForm = ref(false);
const showFilters = ref(false);
const editingId = ref(null);
const fileInputKey = ref(0);
const filters = reactive({
    search: '',
    status: '',
    audience: '',
    placement: '',
    media: '',
});

const statusOptions = computed(() =>
    (props.statuses || []).map((status) => ({
        value: status,
        label: status === 'active' ? 'Active' : 'Draft',
    }))
);

const audienceOptions = computed(() =>
    (props.audiences || []).map((audience) => ({
        value: audience,
        label:
            audience === 'tenants'
                ? 'Specific tenants'
                : audience === 'new_tenants'
                    ? 'New tenants'
                    : 'All tenants',
    }))
);

const placementLabels = {
    internal: 'Dashboard top',
    quick_actions: 'Quick actions slot',
};

const placementOptions = computed(() =>
    (props.placements || []).map((placement) => ({
        value: placement,
        label: placementLabels[placement] || placement,
    }))
);

const mediaTypeOptions = computed(() =>
    (props.media_types || []).map((type) => ({
        value: type,
        label:
            type === 'image'
                ? 'Image'
                : type === 'video'
                    ? 'Video'
                    : 'No media',
    }))
);

const displayStyleOptions = computed(() => {
    const styles = props.display_styles?.length ? props.display_styles : ['standard', 'media_only'];

    return styles.map((style) => ({
        value: style,
        label: style === 'media_only' ? 'Media only' : 'Standard card',
    }));
});

const form = useForm({
    title: '',
    body: '',
    status: statusOptions.value[0]?.value ?? 'draft',
    audience: audienceOptions.value[0]?.value ?? 'all',
    placement: placementOptions.value[0]?.value ?? 'internal',
    display_style: displayStyleOptions.value[0]?.value ?? 'standard',
    background_color: '',
    priority: 0,
    starts_at: '',
    ends_at: '',
    new_tenant_days: '',
    media_type: mediaTypeOptions.value[0]?.value ?? 'none',
    media_url: '',
    media_file: null,
    clear_media: false,
    link_label: '',
    link_url: '',
    tenant_ids: [],
});

const handleMediaFile = async (event) => {
    const file = event.target.files?.[0] || null;
    form.clearErrors('media_file');
    if (!file) {
        form.media_file = null;
        return;
    }
    const result = await prepareMediaFile(file, {
        maxImageBytes: MEDIA_LIMITS.maxImageBytes,
        maxVideoBytes: MEDIA_LIMITS.maxVideoBytes,
    });
    if (result.error) {
        form.setError('media_file', result.error);
        form.media_file = null;
        return;
    }
    form.media_file = result.file;
};

const isEditing = computed(() => editingId.value !== null);
const editingAnnouncement = computed(
    () => props.announcements.find((item) => item.id === editingId.value) || null
);
const hasExistingMedia = computed(() => Boolean(editingAnnouncement.value?.media_url));
const totalCount = computed(() => props.announcements.length);
const activeCount = computed(() => props.announcements.filter((item) => item.status === 'active').length);
const draftCount = computed(() => props.announcements.filter((item) => item.status !== 'active').length);
const mediaCount = computed(() => props.announcements.filter((item) => item.media_type && item.media_type !== 'none').length);
const targetedCount = computed(() => props.announcements.filter((item) => item.audience !== 'all').length);

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const statusClass = (status) => {
    if (status === 'active') {
        return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
    }
    return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
};

const resolveLabel = (options, value) => {
    const match = options.find((option) => option.value === value);
    return match?.label || value;
};

const audienceLabel = (value) => resolveLabel(audienceOptions.value, value);
const placementLabel = (value) => resolveLabel(placementOptions.value, value);
const mediaLabel = (value) => resolveLabel(mediaTypeOptions.value, value || 'none');

const announcementWindow = (item) => {
    if (item.starts_at && item.ends_at) {
        return `${item.starts_at} to ${item.ends_at}`;
    }
    if (item.ends_at) {
        return `Until ${item.ends_at}`;
    }
    if (item.starts_at) {
        return `From ${item.starts_at}`;
    }
    return '';
};

const filteredAnnouncements = computed(() => {
    const searchValue = filters.search?.trim().toLowerCase();

    return props.announcements.filter((item) => {
        if (filters.status && item.status !== filters.status) {
            return false;
        }
        if (filters.audience && item.audience !== filters.audience) {
            return false;
        }
        if (filters.placement && item.placement !== filters.placement) {
            return false;
        }
        if (filters.media && (item.media_type || 'none') !== filters.media) {
            return false;
        }
        if (searchValue) {
            const haystack = `${item.title || ''} ${item.body || ''} ${item.link_label || ''}`.toLowerCase();
            return haystack.includes(searchValue);
        }
        return true;
    });
});

const resetForm = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.tenant_ids = [];
    form.media_file = null;
    form.clear_media = false;
    fileInputKey.value += 1;
};

const applyFilters = () => {
    showFilters.value = false;
};

const resetFilters = () => {
    filters.search = '';
    filters.status = '';
    filters.audience = '';
    filters.placement = '';
    filters.media = '';
    showFilters.value = false;
};

const openCreate = () => {
    resetForm();
    showForm.value = true;
};

const openEdit = (announcement) => {
    startEdit(announcement);
    showForm.value = true;
};

const closeForm = () => {
    showForm.value = false;
    resetForm();
};

const startEdit = (announcement) => {
    editingId.value = announcement.id;
    form.title = announcement.title || '';
    form.body = announcement.body || '';
    form.status = announcement.status || statusOptions.value[0]?.value || 'draft';
    form.audience = announcement.audience || audienceOptions.value[0]?.value || 'all';
    form.placement = placementOptions.value.some((option) => option.value === announcement.placement)
        ? announcement.placement
        : placementOptions.value[0]?.value || 'internal';
    form.display_style = displayStyleOptions.value.some((option) => option.value === announcement.display_style)
        ? announcement.display_style
        : displayStyleOptions.value[0]?.value || 'standard';
    form.background_color = announcement.background_color || '';
    form.priority = announcement.priority ?? 0;
    form.starts_at = announcement.starts_at || '';
    form.ends_at = announcement.ends_at || '';
    form.new_tenant_days = announcement.new_tenant_days ?? '';
    form.media_type = announcement.media_type || 'none';
    form.media_url = announcement.media_external_url || '';
    form.link_label = announcement.link_label || '';
    form.link_url = announcement.link_url || '';
    form.tenant_ids = announcement.tenant_ids ? [...announcement.tenant_ids] : [];
    form.clear_media = false;
    form.media_file = null;
    fileInputKey.value += 1;
};

const submit = () => {
    const options = {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => closeForm(),
    };

    const transformPayload = (data) => {
        const payload = { ...data };

        payload.starts_at = payload.starts_at || null;
        payload.ends_at = payload.ends_at || null;
        payload.new_tenant_days = payload.new_tenant_days || null;
        payload.media_url = payload.media_url || null;
        payload.link_label = payload.link_label || null;
        payload.link_url = payload.link_url || null;
        payload.display_style = payload.display_style || 'standard';
        payload.background_color = payload.background_color ? payload.background_color.trim() : null;

        if (payload.audience !== 'tenants') {
            payload.tenant_ids = [];
        }

        if (!(payload.media_file instanceof File)) {
            delete payload.media_file;
        }

        return payload;
    };

    const request = form.transform(transformPayload);

    if (isEditing.value) {
        request.put(route('superadmin.announcements.update', editingId.value), options);
        return;
    }

    request.post(route('superadmin.announcements.store'), options);
};

const deleteAnnouncement = (announcement) => {
    if (form.processing) {
        return;
    }
    if (!window.confirm('Delete this announcement?')) {
        return;
    }
    router.delete(route('superadmin.announcements.destroy', announcement.id), {
        preserveScroll: true,
    });
};

watch(
    () => form.audience,
    (value) => {
        if (value !== 'tenants') {
            form.tenant_ids = [];
        }
        if (value !== 'new_tenants') {
            form.new_tenant_days = '';
        }
    }
);
</script>

<template>
    <Head title="Announcements" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Client dashboard announcements</h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            Manage videos or flyers shown on tenant/client dashboards.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Link :href="route('superadmin.announcements.preview')"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            Preview placement
                        </Link>
                        <button type="button" @click="openCreate"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                            Add announcement
                        </button>
                    </div>
                </div>
            </section>

            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-2 md:gap-3 lg:gap-5">
                <div class="p-4 bg-white border border-t-4 border-t-emerald-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Total</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(totalCount) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-blue-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Active</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(activeCount) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-rose-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Drafts</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(draftCount) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-amber-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">With media</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(mediaCount) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-sky-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Targeted</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(targetedCount) }}
                    </p>
                </div>
            </div>

            <div
                class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:border-neutral-700 dark:bg-neutral-800">
                <form class="space-y-3" @submit.prevent="applyFilters">
                    <div class="flex flex-col lg:flex-row lg:items-center gap-2">
                        <div class="flex-1">
                            <div class="relative">
                                <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                                    <svg class="shrink-0 size-4 text-stone-500 dark:text-neutral-400"
                                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="11" cy="11" r="8" />
                                        <path d="m21 21-4.3-4.3" />
                                    </svg>
                                </div>
                                <input v-model="filters.search" type="text"
                                    class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400"
                                    placeholder="Search announcements">
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 justify-end">
                            <button type="button" @click="showFilters = !showFilters"
                                class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                Filters
                            </button>
                            <button type="button" @click="resetFilters"
                                class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                Clear
                            </button>
                            <button type="submit"
                                class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                Apply filters
                            </button>
                        </div>
                    </div>

                    <div v-if="showFilters" class="grid gap-3 md:grid-cols-4">
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Status</label>
                            <select v-model="filters.status"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option value="">All</option>
                                <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Audience</label>
                            <select v-model="filters.audience"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option value="">All</option>
                                <option v-for="option in audienceOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Placement</label>
                            <select v-model="filters.placement"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option value="">All</option>
                                <option v-for="option in placementOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Media</label>
                            <select v-model="filters.media"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option value="">All</option>
                                <option v-for="option in mediaTypeOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>
                    </div>
                </form>

                <div
                    class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                    <table class="min-w-full divide-y divide-stone-200 text-sm text-left text-stone-600 dark:divide-neutral-700 dark:text-neutral-300">
                        <thead class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                            <tr>
                                <th class="px-4 py-3">Title</th>
                                <th class="px-4 py-3">Audience</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Window</th>
                                <th class="px-4 py-3">Media</th>
                                <th class="px-4 py-3">Priority</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr v-for="announcement in filteredAnnouncements" :key="announcement.id">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">{{ announcement.title }}</div>
                                    <div v-if="announcement.body" class="text-xs text-stone-500 dark:text-neutral-400 truncate max-w-xs">
                                        {{ announcement.body }}
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm text-stone-800 dark:text-neutral-100">
                                        {{ audienceLabel(announcement.audience) }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ placementLabel(announcement.placement) }}
                                    </div>
                                    <div v-if="announcement.audience === 'tenants' && announcement.tenant_labels?.length"
                                        class="text-xs text-stone-500 dark:text-neutral-400">
                                        Targets: {{ announcement.tenant_labels.join(', ') }}
                                    </div>
                                    <div v-if="announcement.audience === 'new_tenants' && announcement.new_tenant_days"
                                        class="text-xs text-stone-500 dark:text-neutral-400">
                                        New tenants: {{ announcement.new_tenant_days }} days
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :class="statusClass(announcement.status)">
                                        {{ announcement.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span v-if="announcementWindow(announcement)">{{ announcementWindow(announcement) }}</span>
                                    <span v-else class="text-xs text-stone-400 dark:text-neutral-500">-</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm text-stone-800 dark:text-neutral-100">
                                        {{ mediaLabel(announcement.media_type) }}
                                    </div>
                                    <div v-if="announcement.media_url" class="text-xs text-stone-500 dark:text-neutral-400">
                                        Attached
                                    </div>
                                </td>
                                <td class="px-4 py-3">{{ announcement.priority ?? 0 }}</td>
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
                                        <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-36 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                            role="menu" aria-orientation="vertical">
                                            <div class="p-1">
                                                <button type="button" @click="openEdit(announcement)"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                    Edit
                                                </button>
                                                <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                                <button type="button" @click="deleteAnnouncement(announcement)"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800">
                                                    Delete
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!filteredAnnouncements.length">
                                <td colspan="7" class="px-4 py-6 text-center text-sm text-stone-500 dark:text-neutral-400">
                                    No announcements found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <Modal :show="showForm" @close="closeForm" maxWidth="2xl">
            <div class="p-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ isEditing ? 'Edit announcement' : 'New announcement' }}
                    </h2>
                    <button type="button" @click="closeForm" class="text-sm text-stone-500 dark:text-neutral-400">
                        Close
                    </button>
                </div>

                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Title</label>
                            <input v-model="form.title" type="text"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                            <InputError class="mt-1" :message="form.errors.title" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Status</label>
                            <select v-model="form.status"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.status" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">Message</label>
                        <textarea v-model="form.body" rows="3"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"></textarea>
                        <InputError class="mt-1" :message="form.errors.body" />
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Display style</label>
                            <select v-model="form.display_style"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option v-for="option in displayStyleOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                Media only hides the title, message, and link.
                            </p>
                            <InputError class="mt-1" :message="form.errors.display_style" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Card background</label>
                            <div class="mt-1 flex items-center gap-2">
                                <input v-model="form.background_color" type="text" placeholder="#F8FAFC"
                                    class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                                <input type="color" :value="form.background_color || '#ffffff'"
                                    @input="form.background_color = $event.target.value"
                                    class="h-9 w-10 rounded-sm border border-stone-200 bg-white p-1 dark:bg-neutral-900 dark:border-neutral-700" />
                                <button type="button" @click="form.background_color = ''"
                                    class="py-2 px-2.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                    Clear
                                </button>
                            </div>
                            <InputError class="mt-1" :message="form.errors.background_color" />
                        </div>
                    </div>

                    <div class="grid gap-3 md:grid-cols-3">
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Audience</label>
                            <select v-model="form.audience"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option v-for="option in audienceOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.audience" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Placement</label>
                            <select v-model="form.placement"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option v-for="option in placementOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.placement" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Priority</label>
                            <input v-model.number="form.priority" type="number" min="0"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                            <InputError class="mt-1" :message="form.errors.priority" />
                        </div>
                    </div>

                    <div v-if="form.audience === 'tenants'">
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">Target tenants</label>
                        <select v-model="form.tenant_ids" multiple
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">
                                {{ tenant.label }} ({{ tenant.email }})
                            </option>
                        </select>
                        <InputError class="mt-1" :message="form.errors.tenant_ids" />
                    </div>

                    <div v-if="form.audience === 'new_tenants'">
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">New tenant window (days)</label>
                        <input v-model.number="form.new_tenant_days" type="number" min="1" max="365"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                        <InputError class="mt-1" :message="form.errors.new_tenant_days" />
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Start date</label>
                            <input v-model="form.starts_at" type="date"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                            <InputError class="mt-1" :message="form.errors.starts_at" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">End date</label>
                            <input v-model="form.ends_at" type="date"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                            <InputError class="mt-1" :message="form.errors.ends_at" />
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                        <div class="text-xs font-semibold text-stone-600 dark:text-neutral-300">Media</div>
                        <div class="mt-3 grid gap-3 md:grid-cols-3">
                            <div>
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">Media type</label>
                                <select v-model="form.media_type"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                    <option v-for="option in mediaTypeOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                                <InputError class="mt-1" :message="form.errors.media_type" />
                            </div>
                            <div>
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">Media URL</label>
                                <input v-model="form.media_url" type="url" placeholder="https://"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                                <InputError class="mt-1" :message="form.errors.media_url" />
                            </div>
                            <div>
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">Upload file</label>
                                <input :key="fileInputKey" type="file" @change="handleMediaFile"
                                    class="mt-1 block w-full text-xs text-stone-600 dark:text-neutral-200"
                                    accept="image/*,video/*" />
                                <InputError class="mt-1" :message="form.errors.media_file" />
                            </div>
                        </div>
                        <label v-if="isEditing && hasExistingMedia" class="mt-3 flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                            <input v-model="form.clear_media" type="checkbox" class="rounded-sm border-stone-300 text-green-600 focus:ring-green-600" />
                            Remove existing media
                        </label>
                        <div v-if="isEditing && hasExistingMedia" class="mt-3 rounded-sm border border-stone-200 p-2 dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">Current media</div>
                            <div class="mt-2 overflow-hidden rounded-sm border border-stone-200 dark:border-neutral-700">
                                <img v-if="editingAnnouncement?.media_type === 'image'" :src="editingAnnouncement?.media_url"
                                    alt="" class="h-32 w-full object-cover" />
                                <video v-else-if="editingAnnouncement?.media_type === 'video'" controls class="h-32 w-full">
                                    <source :src="editingAnnouncement?.media_url" />
                                </video>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Link label</label>
                            <input v-model="form.link_label" type="text"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                            <InputError class="mt-1" :message="form.errors.link_label" />
                        </div>
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Link URL</label>
                            <input v-model="form.link_url" type="url" placeholder="https://"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                            <InputError class="mt-1" :message="form.errors.link_url" />
                        </div>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="button" @click="closeForm"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            Cancel
                        </button>
                        <button type="submit" :disabled="form.processing"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                            {{ isEditing ? 'Save changes' : 'Create announcement' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
