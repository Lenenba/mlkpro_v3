<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';

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
});

const editingId = ref(null);
const fileInputKey = ref(0);

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

const placementOptions = computed(() =>
    (props.placements || [])
        .filter((placement) => placement === 'internal')
        .map((placement) => ({
            value: placement,
            label: 'Internal dashboard',
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

const form = useForm({
    title: '',
    body: '',
    status: statusOptions.value[0]?.value ?? 'draft',
    audience: audienceOptions.value[0]?.value ?? 'all',
    placement: placementOptions.value[0]?.value ?? 'internal',
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

const isEditing = computed(() => editingId.value !== null);
const editingAnnouncement = computed(
    () => props.announcements.find((item) => item.id === editingId.value) || null
);
const hasExistingMedia = computed(() => Boolean(editingAnnouncement.value?.media_url));

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

const resetForm = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.tenant_ids = [];
    form.media_file = null;
    form.clear_media = false;
    fileInputKey.value += 1;
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
        onSuccess: () => resetForm(),
    };

    const transformPayload = (data) => {
        const payload = { ...data };

        payload.starts_at = payload.starts_at || null;
        payload.ends_at = payload.ends_at || null;
        payload.new_tenant_days = payload.new_tenant_days || null;
        payload.media_url = payload.media_url || null;
        payload.link_label = payload.link_label || null;
        payload.link_url = payload.link_url || null;

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
        <div class="mx-auto w-full max-w-6xl space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-neutral-100">Client dashboard announcements</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-neutral-400">
                    Manage videos or flyers shown on tenant/client dashboards.
                </p>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <div class="rounded-sm border border-gray-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800 lg:col-span-2">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">
                            {{ isEditing ? 'Edit announcement' : 'New announcement' }}
                        </h2>
                        <button v-if="isEditing" type="button" @click="resetForm"
                            class="text-xs font-semibold text-gray-500 hover:text-gray-700 dark:text-neutral-400">
                            Cancel
                        </button>
                    </div>

                    <form class="mt-4 space-y-4" @submit.prevent="submit">
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-neutral-400">Title</label>
                                <input v-model="form.title" type="text"
                                    class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                                <InputError class="mt-1" :message="form.errors.title" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-neutral-400">Status</label>
                                <select v-model="form.status"
                                    class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                    <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                                <InputError class="mt-1" :message="form.errors.status" />
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs text-gray-500 dark:text-neutral-400">Message</label>
                            <textarea v-model="form.body" rows="3"
                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"></textarea>
                            <InputError class="mt-1" :message="form.errors.body" />
                        </div>

                        <div class="grid gap-3 md:grid-cols-3">
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-neutral-400">Audience</label>
                                <select v-model="form.audience"
                                    class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                    <option v-for="option in audienceOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                                <InputError class="mt-1" :message="form.errors.audience" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-neutral-400">Placement</label>
                                <select v-model="form.placement"
                                    class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                    <option v-for="option in placementOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                                <InputError class="mt-1" :message="form.errors.placement" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-neutral-400">Priority</label>
                                <input v-model.number="form.priority" type="number" min="0"
                                    class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                                <InputError class="mt-1" :message="form.errors.priority" />
                            </div>
                        </div>

                        <div v-if="form.audience === 'tenants'">
                            <label class="block text-xs text-gray-500 dark:text-neutral-400">Target tenants</label>
                            <select v-model="form.tenant_ids" multiple
                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">
                                    {{ tenant.label }} ({{ tenant.email }})
                                </option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.tenant_ids" />
                        </div>

                        <div v-if="form.audience === 'new_tenants'">
                            <label class="block text-xs text-gray-500 dark:text-neutral-400">New tenant window (days)</label>
                            <input v-model.number="form.new_tenant_days" type="number" min="1" max="365"
                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                            <InputError class="mt-1" :message="form.errors.new_tenant_days" />
                        </div>

                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-neutral-400">Start date</label>
                                <input v-model="form.starts_at" type="date"
                                    class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                                <InputError class="mt-1" :message="form.errors.starts_at" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-neutral-400">End date</label>
                                <input v-model="form.ends_at" type="date"
                                    class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                                <InputError class="mt-1" :message="form.errors.ends_at" />
                            </div>
                        </div>

                        <div class="rounded-sm border border-gray-200 p-3 dark:border-neutral-700">
                            <div class="text-xs font-semibold text-gray-600 dark:text-neutral-300">Media</div>
                            <div class="mt-3 grid gap-3 md:grid-cols-3">
                                <div>
                                    <label class="block text-xs text-gray-500 dark:text-neutral-400">Media type</label>
                                    <select v-model="form.media_type"
                                        class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                        <option v-for="option in mediaTypeOptions" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <InputError class="mt-1" :message="form.errors.media_type" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-500 dark:text-neutral-400">Media URL</label>
                                    <input v-model="form.media_url" type="url" placeholder="https://"
                                        class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                                    <InputError class="mt-1" :message="form.errors.media_url" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-500 dark:text-neutral-400">Upload file</label>
                                    <input :key="fileInputKey" type="file" @change="form.media_file = $event.target.files?.[0] || null"
                                        class="mt-1 block w-full text-xs text-gray-600 dark:text-neutral-200"
                                        accept="image/*,video/*" />
                                    <InputError class="mt-1" :message="form.errors.media_file" />
                                </div>
                            </div>
                            <label v-if="isEditing && hasExistingMedia" class="mt-3 flex items-center gap-2 text-xs text-gray-600 dark:text-neutral-300">
                                <input v-model="form.clear_media" type="checkbox" class="rounded-sm border-gray-300 text-green-600 focus:ring-green-600" />
                                Remove existing media
                            </label>
                            <div v-if="isEditing && hasExistingMedia" class="mt-3 rounded-sm border border-gray-200 p-2 dark:border-neutral-700">
                                <div class="text-xs text-gray-500 dark:text-neutral-400">Current media</div>
                                <div class="mt-2 overflow-hidden rounded-sm border border-gray-200 dark:border-neutral-700">
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
                                <label class="block text-xs text-gray-500 dark:text-neutral-400">Link label</label>
                                <input v-model="form.link_label" type="text"
                                    class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                                <InputError class="mt-1" :message="form.errors.link_label" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-neutral-400">Link URL</label>
                                <input v-model="form.link_url" type="url" placeholder="https://"
                                    class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                                <InputError class="mt-1" :message="form.errors.link_url" />
                            </div>
                        </div>

                        <div class="flex justify-end gap-2">
                            <button type="button" @click="resetForm"
                                class="py-2 px-3 text-xs font-medium rounded-sm border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                Reset
                            </button>
                            <button type="submit" :disabled="form.processing"
                                class="py-2 px-3 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                                {{ isEditing ? 'Save changes' : 'Create announcement' }}
                            </button>
                        </div>
                    </form>
                </div>

                <div class="rounded-sm border border-gray-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">Announcements</h2>
                    <div v-if="!announcements.length" class="mt-4 text-sm text-gray-500 dark:text-neutral-400">
                        No announcements yet.
                    </div>
                    <div v-else class="mt-4 space-y-3">
                        <div v-for="announcement in announcements" :key="announcement.id"
                            class="rounded-sm border border-gray-200 p-3 text-sm dark:border-neutral-700">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-gray-800 dark:text-neutral-100">
                                        {{ announcement.title }}
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500 dark:text-neutral-400">
                                        {{ audienceLabel(announcement.audience) }} - {{ placementLabel(announcement.placement) }}
                                    </div>
                                    <div v-if="announcementWindow(announcement)"
                                        class="mt-1 text-xs text-gray-500 dark:text-neutral-400">
                                        {{ announcementWindow(announcement) }}
                                    </div>
                                    <div v-if="announcement.audience === 'tenants' && announcement.tenant_labels?.length"
                                        class="mt-2 text-xs text-gray-500 dark:text-neutral-400">
                                        Targets: {{ announcement.tenant_labels.join(', ') }}
                                    </div>
                                    <div v-if="announcement.audience === 'new_tenants' && announcement.new_tenant_days"
                                        class="mt-2 text-xs text-gray-500 dark:text-neutral-400">
                                        New tenants: {{ announcement.new_tenant_days }} days
                                    </div>
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-2">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusClass(announcement.status)">
                                        {{ announcement.status }}
                                    </span>
                                    <button type="button" @click="startEdit(announcement)"
                                        class="text-xs font-semibold text-green-600 hover:text-green-700">
                                        Edit
                                    </button>
                                    <button type="button" @click="deleteAnnouncement(announcement)"
                                        class="text-xs font-semibold text-red-600 hover:text-red-700">
                                        Delete
                                    </button>
                                </div>
                            </div>
                            <div class="mt-2 text-xs text-gray-500 dark:text-neutral-400">
                                Media: {{ announcement.media_type || 'none' }} - Priority {{ announcement.priority ?? 0 }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
