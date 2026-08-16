<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { formatBytes, prepareMediaFile, MEDIA_LIMITS } from '@/utils/media';
import Checkbox from '@/Components/Checkbox.vue';
import FloatingFileInput from '@/Components/FloatingFileInput.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import DatePicker from '@/Components/DatePicker.vue';
import {
    createAnnouncementTranslations,
    emptyAnnouncementTranslation,
    normalizeAnnouncementTranslations,
} from './announcementForm';

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
    content_locales: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { t } = useI18n();

const localeList = computed(() => {
    const supported = props.content_locales?.length
        ? props.content_locales
        : (Array.isArray(page.props.locales) ? page.props.locales : ['fr', 'en', 'es']);
    const normalized = supported
        .map((locale) => String(locale || '').trim().toLowerCase())
        .filter((locale, index, locales) => locale && locales.indexOf(locale) === index);

    return normalized.length ? normalized : ['fr', 'en', 'es'];
});
const defaultContentLocale = computed(() => (
    localeList.value.includes('fr') ? 'fr' : localeList.value[0]
));
const editorLocale = ref(defaultContentLocale.value);
const createEmptyTranslations = () => createAnnouncementTranslations(localeList.value);
const normalizeTranslations = (translations = {}, legacy = {}) => normalizeAnnouncementTranslations({
    locales: localeList.value,
    defaultLocale: defaultContentLocale.value,
    translations,
    legacy,
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
        label: status === 'active' ? t('super_admin.announcements.status.active') : t('super_admin.announcements.status.draft'),
    }))
);

const audienceOptions = computed(() =>
    (props.audiences || []).map((audience) => ({
        value: audience,
        label:
            audience === 'tenants'
                ? t('super_admin.announcements.audience.tenants')
                : audience === 'new_tenants'
                    ? t('super_admin.announcements.audience.new_tenants')
                    : t('super_admin.announcements.audience.all'),
    }))
);

const placementLabels = computed(() => ({
    internal: t('super_admin.announcements.placement.internal'),
    quick_actions: t('super_admin.announcements.placement.quick_actions'),
}));

const placementOptions = computed(() =>
    (props.placements || []).map((placement) => ({
        value: placement,
        label: placementLabels.value[placement] || placement,
    }))
);

const tenantOptions = computed(() =>
    (props.tenants || []).map((tenant) => {
        const baseLabel = tenant.label || tenant.company_name || tenant.email || '';
        const label = tenant.email && baseLabel && baseLabel !== tenant.email
            ? `${baseLabel} (${tenant.email})`
            : (baseLabel || tenant.email || '');
        return {
            value: String(tenant.id),
            label,
        };
    })
);

const mediaTypeOptions = computed(() =>
    (props.media_types || []).map((type) => ({
        value: type,
        label:
            type === 'image'
                ? t('super_admin.announcements.media.image')
                : type === 'video'
                    ? t('super_admin.announcements.media.video')
                    : t('super_admin.announcements.media.none'),
    }))
);

const displayStyleOptions = computed(() => {
    const styles = props.display_styles?.length ? props.display_styles : ['standard', 'media_only'];

    return styles.map((style) => ({
        value: style,
        label: style === 'media_only' ? t('super_admin.announcements.display_style.media_only') : t('super_admin.announcements.display_style.standard'),
    }));
});

const form = useForm({
    title: '',
    body: '',
    translations: createEmptyTranslations(),
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

const localeHasError = (locale) => Object.keys(form.errors).some(
    (key) => key.startsWith(`translations.${locale}.`),
);
const localeOptions = computed(() => localeList.value.map((locale) => ({
    value: locale,
    label: localeHasError(locale)
        ? `${t(`super_admin.announcements.form.locales.${locale}`)} — ${t('super_admin.announcements.form.locale_error')}`
        : t(`super_admin.announcements.form.locales.${locale}`),
})));
const availableMediaTypeOptions = computed(() => (
    form.display_style === 'media_only'
        ? mediaTypeOptions.value.filter((option) => option.value === 'image' || option.value === 'video')
        : mediaTypeOptions.value
));
const activeTranslation = computed(() => (
    form.translations?.[editorLocale.value] || emptyAnnouncementTranslation()
));
const translationError = (field) => form.errors[`translations.${editorLocale.value}.${field}`];

const localizedMediaError = (error) => {
    if (error === 'Image processing failed.') {
        return t('super_admin.announcements.form.image_processing_error');
    }
    if (error?.startsWith('Image too large.')) {
        return t('super_admin.announcements.form.image_too_large', {
            size: formatBytes(MEDIA_LIMITS.maxImageBytes),
        });
    }
    if (error?.startsWith('Video too large.')) {
        return t('super_admin.announcements.form.video_too_large', {
            size: formatBytes(MEDIA_LIMITS.maxVideoBytes),
        });
    }
    if (error === 'Unsupported file type.') {
        return t('super_admin.announcements.form.unsupported_file_type');
    }

    return error;
};

const handleMediaFile = async (file) => {
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
        form.setError('media_file', localizedMediaError(result.error));
        form.media_file = null;
        return;
    }
    form.media_file = result.file;
    form.clear_media = false;
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
        return t('super_admin.announcements.window.range', { start: item.starts_at, end: item.ends_at });
    }
    if (item.ends_at) {
        return t('super_admin.announcements.window.until', { end: item.ends_at });
    }
    if (item.starts_at) {
        return t('super_admin.announcements.window.from', { start: item.starts_at });
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
            const translatedValues = Object.values(item.translations || {})
                .flatMap((localized) => Object.values(localized || {}));
            const haystack = [
                item.localized_title,
                item.localized_body,
                item.localized_link_label,
                item.title,
                item.body,
                item.link_label,
                ...translatedValues,
            ].filter(Boolean).join(' ').toLowerCase();
            return haystack.includes(searchValue);
        }
        return true;
    });
});
const filteredAnnouncementsResultsLabel = computed(() => t('super_admin.announcements.filters.results', { count: filteredAnnouncements.value.length }));

const resetForm = () => {
    editingId.value = null;
    form.reset();
    form.translations = createEmptyTranslations();
    form.clearErrors();
    form.tenant_ids = [];
    form.media_file = null;
    form.clear_media = false;
    editorLocale.value = defaultContentLocale.value;
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
    form.translations = normalizeTranslations(announcement.translations, announcement);
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
    editorLocale.value = defaultContentLocale.value;
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

        payload.translations = Object.fromEntries(localeList.value.map((locale) => {
            const localized = data.translations?.[locale] || {};

            return [locale, {
                title: String(localized.title || '').trim(),
                body: String(localized.body || '').trim(),
                link_label: String(localized.link_label || '').trim(),
            }];
        }));
        delete payload.title;
        delete payload.body;
        delete payload.link_label;

        payload.starts_at = payload.starts_at || null;
        payload.ends_at = payload.ends_at || null;
        payload.new_tenant_days = payload.new_tenant_days || null;
        payload.media_url = payload.media_url || null;
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
    if (!window.confirm(t('super_admin.announcements.actions.confirm_delete'))) {
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

watch(
    () => form.display_style,
    (value) => {
        if (value === 'media_only') {
            form.background_color = '';
            if (!['image', 'video'].includes(form.media_type)) {
                form.media_type = 'image';
            }
        }
    }
);

watch(
    () => form.errors,
    (errors) => {
        const localeWithError = localeList.value.find((locale) => Object.keys(errors).some(
            (key) => key.startsWith(`translations.${locale}.`),
        ));

        if (localeWithError) {
            editorLocale.value = localeWithError;
        }
    },
    { deep: true },
);
</script>

<template>
    <Head :title="$t('super_admin.announcements.page_title')" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.announcements.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('super_admin.announcements.subtitle') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Link :href="route('superadmin.announcements.preview')"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            {{ $t('super_admin.announcements.actions.preview') }}
                        </Link>
                        <button type="button" @click="openCreate"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                            {{ $t('super_admin.announcements.actions.add') }}
                        </button>
                    </div>
                </div>
            </section>

            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-2 md:gap-3 lg:gap-5">
                <div class="p-4 bg-white border border-t-4 border-t-emerald-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.announcements.stats.total') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(totalCount) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-blue-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.announcements.stats.active') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(activeCount) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-rose-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.announcements.stats.drafts') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(draftCount) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-amber-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.announcements.stats.with_media') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(mediaCount) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-sky-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.announcements.stats.targeted') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(targetedCount) }}
                    </p>
                </div>
            </div>

            <AdminDataTable
                :rows="filteredAnnouncements"
                :result-label="filteredAnnouncementsResultsLabel"
                :empty-description="$t('super_admin.announcements.empty')"
                container-class="border-t-4 border-t-zinc-600"
            >
                <template #toolbar>
                    <AdminDataTableToolbar
                        :show-filters="showFilters"
                        :search-placeholder="$t('super_admin.announcements.filters.search_placeholder')"
                        :filters-label="$t('super_admin.common.filters')"
                        :clear-label="$t('super_admin.common.clear')"
                        :apply-label="$t('super_admin.common.apply_filters')"
                        @toggle-filters="showFilters = !showFilters"
                        @apply="applyFilters"
                        @clear="resetFilters"
                    >
                        <template #search="{ searchPlaceholder }">
                            <div class="relative">
                                <div class="pointer-events-none absolute inset-y-0 start-0 z-20 flex items-center ps-3.5">
                                    <svg class="size-4 shrink-0 text-stone-500 dark:text-neutral-400" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="11" cy="11" r="8" />
                                        <path d="m21 21-4.3-4.3" />
                                    </svg>
                                </div>
                                <input
                                    v-model="filters.search"
                                    type="text"
                                    :placeholder="searchPlaceholder"
                                    class="block w-full rounded-sm border border-stone-200 bg-white py-[7px] ps-10 pe-8 text-sm text-stone-700 placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:placeholder:text-neutral-400"
                                >
                            </div>
                        </template>

                        <template #filters>
                            <div>
                                <FloatingSelect
                                    v-model="filters.status"
                                    :label="$t('super_admin.announcements.filters.status')"
                                    :options="statusOptions"
                                    :placeholder="$t('super_admin.common.all')"
                                    dense
                                />
                            </div>
                            <div>
                                <FloatingSelect
                                    v-model="filters.audience"
                                    :label="$t('super_admin.announcements.filters.audience')"
                                    :options="audienceOptions"
                                    :placeholder="$t('super_admin.common.all')"
                                    dense
                                />
                            </div>
                            <div>
                                <FloatingSelect
                                    v-model="filters.placement"
                                    :label="$t('super_admin.announcements.filters.placement')"
                                    :options="placementOptions"
                                    :placeholder="$t('super_admin.common.all')"
                                    dense
                                />
                            </div>
                            <div>
                                <FloatingSelect
                                    v-model="filters.media"
                                    :label="$t('super_admin.announcements.filters.media')"
                                    :options="mediaTypeOptions"
                                    :placeholder="$t('super_admin.common.all')"
                                    dense
                                />
                            </div>
                        </template>
                    </AdminDataTableToolbar>
                </template>

                <template #head>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-neutral-300">
                        <th class="px-4 py-3">{{ $t('super_admin.announcements.table.title') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.announcements.table.audience') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.announcements.table.status') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.announcements.table.window') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.announcements.table.media') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.announcements.table.priority') }}</th>
                        <th class="px-4 py-3 text-right"></th>
                    </tr>
                </template>

                <template #row="{ row: announcement }">
                    <tr class="align-top">
                        <td class="px-4 py-3">
                            <div class="font-medium text-stone-800 dark:text-neutral-100">
                                {{ announcement.localized_title || announcement.title }}
                            </div>
                            <div v-if="announcement.localized_body || announcement.body" class="max-w-xs truncate text-xs text-stone-500 dark:text-neutral-400">
                                {{ announcement.localized_body || announcement.body }}
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-sm text-stone-800 dark:text-neutral-100">
                                {{ audienceLabel(announcement.audience) }}
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ placementLabel(announcement.placement) }}
                            </div>
                            <div
                                v-if="announcement.audience === 'tenants' && announcement.tenant_labels?.length"
                                class="text-xs text-stone-500 dark:text-neutral-400"
                            >
                                {{ $t('super_admin.announcements.table.targets') }}: {{ announcement.tenant_labels.join(', ') }}
                            </div>
                            <div
                                v-if="announcement.audience === 'new_tenants' && announcement.new_tenant_days"
                                class="text-xs text-stone-500 dark:text-neutral-400"
                            >
                                {{ $t('super_admin.announcements.table.new_tenants', { days: announcement.new_tenant_days }) }}
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusClass(announcement.status)">
                                {{ announcement.status === 'active' ? $t('super_admin.announcements.status.active') : $t('super_admin.announcements.status.draft') }}
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
                                {{ $t('super_admin.announcements.table.attached') }}
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ announcement.priority ?? 0 }}</td>
                        <td class="px-4 py-3 text-right">
                            <AdminDataTableActions :label="$t('super_admin.common.actions')">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                    @click="openEdit(announcement)"
                                >
                                    {{ $t('super_admin.common.edit') }}
                                </button>
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800"
                                    @click="deleteAnnouncement(announcement)"
                                >
                                    {{ $t('super_admin.common.delete') }}
                                </button>
                            </AdminDataTableActions>
                        </td>
                    </tr>
                </template>
            </AdminDataTable>
        </div>

        <Modal :show="showForm" @close="closeForm" maxWidth="3xl">
            <div class="p-5 sm:p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-stone-800 dark:text-neutral-100">
                        {{ isEditing ? $t('super_admin.announcements.form.edit_title') : $t('super_admin.announcements.form.new_title') }}
                    </h2>
                    <button type="button" @click="closeForm" class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.common.close') }}
                    </button>
                </div>

                <form class="mt-5 space-y-5" @submit.prevent="submit">
                    <section class="space-y-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('super_admin.announcements.form.content_section') }}
                                </h3>
                                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.announcements.form.content_hint') }}
                                </p>
                            </div>
                            <div class="w-full sm:w-64">
                                <FloatingSelect
                                    v-model="editorLocale"
                                    :label="$t('super_admin.announcements.form.editing_locale')"
                                    :options="localeOptions"
                                />
                            </div>
                        </div>

                        <div>
                            <FloatingInput
                                v-model="activeTranslation.title"
                                :label="$t('super_admin.announcements.form.title')"
                                maxlength="255"
                            />
                            <InputError class="mt-1" :message="translationError('title') || form.errors.translations || form.errors.title" />
                        </div>

                        <div v-if="form.display_style !== 'media_only'">
                            <FloatingTextarea
                                v-model="activeTranslation.body"
                                :label="$t('super_admin.announcements.form.message')"
                            />
                            <InputError class="mt-1" :message="translationError('body')" />
                        </div>

                        <div v-if="form.display_style !== 'media_only'" class="grid gap-4 md:grid-cols-2">
                            <div>
                                <FloatingInput
                                    v-model="activeTranslation.link_label"
                                    :label="$t('super_admin.announcements.form.link_label')"
                                    maxlength="120"
                                />
                                <InputError class="mt-1" :message="translationError('link_label')" />
                            </div>
                            <div>
                                <FloatingInput
                                    v-model="form.link_url"
                                    type="url"
                                    :label="$t('super_admin.announcements.form.link_url')"
                                    placeholder="https://"
                                />
                                <InputError class="mt-1" :message="form.errors.link_url" />
                            </div>
                        </div>
                    </section>

                    <section class="space-y-4 border-t border-stone-200 pt-5 dark:border-neutral-700">
                        <div>
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.announcements.form.delivery_section') }}
                            </h3>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.announcements.form.delivery_hint') }}
                            </p>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <FloatingSelect
                                    v-model="form.status"
                                    :label="$t('super_admin.announcements.form.status')"
                                    :options="statusOptions"
                                />
                                <InputError class="mt-1" :message="form.errors.status" />
                            </div>
                            <div>
                                <FloatingSelect
                                    v-model="form.display_style"
                                    :label="$t('super_admin.announcements.form.display_style')"
                                    :options="displayStyleOptions"
                                />
                                <InputError class="mt-1" :message="form.errors.display_style" />
                            </div>
                            <div>
                                <FloatingSelect
                                    v-model="form.audience"
                                    :label="$t('super_admin.announcements.form.audience')"
                                    :options="audienceOptions"
                                />
                                <InputError class="mt-1" :message="form.errors.audience" />
                            </div>
                            <div>
                                <FloatingSelect
                                    v-model="form.placement"
                                    :label="$t('super_admin.announcements.form.placement')"
                                    :options="placementOptions"
                                />
                                <InputError class="mt-1" :message="form.errors.placement" />
                            </div>
                            <div>
                                <FloatingInput
                                    v-model="form.priority"
                                    type="number"
                                    min="0"
                                    :label="$t('super_admin.announcements.form.priority')"
                                />
                                <InputError class="mt-1" :message="form.errors.priority" />
                            </div>
                            <div v-if="form.audience === 'new_tenants'">
                                <FloatingInput
                                    v-model="form.new_tenant_days"
                                    type="number"
                                    min="1"
                                    max="365"
                                    :label="$t('super_admin.announcements.form.new_tenant_window')"
                                />
                                <InputError class="mt-1" :message="form.errors.new_tenant_days" />
                            </div>
                        </div>

                        <div v-if="form.audience === 'tenants'">
                            <FloatingSelect
                                v-model="form.tenant_ids"
                                :label="$t('super_admin.announcements.form.target_tenants')"
                                :options="tenantOptions"
                                multiple
                            />
                            <InputError class="mt-1" :message="form.errors.tenant_ids" />
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <DatePicker v-model="form.starts_at" :label="$t('super_admin.announcements.form.start_date')" />
                                <InputError class="mt-1" :message="form.errors.starts_at" />
                            </div>
                            <div>
                                <DatePicker v-model="form.ends_at" :label="$t('super_admin.announcements.form.end_date')" />
                                <InputError class="mt-1" :message="form.errors.ends_at" />
                            </div>
                        </div>
                    </section>

                    <section class="space-y-4 border-t border-stone-200 pt-5 dark:border-neutral-700">
                        <div>
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.announcements.form.media_section') }}
                            </h3>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.announcements.form.display_style_hint') }}
                            </p>
                        </div>

                        <div v-if="form.display_style !== 'media_only'">
                            <div class="flex items-stretch gap-2">
                                <div class="min-w-0 flex-1">
                                    <FloatingInput
                                        v-model="form.background_color"
                                        :label="$t('super_admin.announcements.form.card_background')"
                                        placeholder="#F8FAFC"
                                    />
                                </div>
                                <input
                                    type="color"
                                    :value="form.background_color || '#ffffff'"
                                    :aria-label="$t('super_admin.announcements.form.color_picker')"
                                    class="h-14 w-14 shrink-0 cursor-pointer rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900"
                                    @input="form.background_color = $event.target.value"
                                />
                                <button
                                    type="button"
                                    class="h-14 shrink-0 rounded-sm border border-stone-200 bg-white px-3 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    @click="form.background_color = ''"
                                >
                                    {{ $t('super_admin.common.clear') }}
                                </button>
                            </div>
                            <InputError class="mt-1" :message="form.errors.background_color" />
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <FloatingSelect
                                    v-model="form.media_type"
                                    :label="$t('super_admin.announcements.form.media_type')"
                                    :options="availableMediaTypeOptions"
                                />
                                <InputError class="mt-1" :message="form.errors.media_type" />
                            </div>
                            <div>
                                <FloatingInput
                                    v-model="form.media_url"
                                    type="url"
                                    :label="$t('super_admin.announcements.form.media_url')"
                                    placeholder="https://"
                                />
                                <InputError class="mt-1" :message="form.errors.media_url" />
                            </div>
                        </div>

                        <div>
                            <FloatingFileInput
                                :key="fileInputKey"
                                v-model="form.media_file"
                                :label="$t('super_admin.announcements.form.upload_file')"
                                :placeholder="$t('super_admin.announcements.form.upload_placeholder')"
                                accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm,video/ogg"
                                @select="handleMediaFile"
                            />
                            <InputError class="mt-1" :message="form.errors.media_file" />
                        </div>

                        <div v-if="isEditing && hasExistingMedia" class="space-y-3">
                            <label class="flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                                <Checkbox v-model:checked="form.clear_media" />
                                {{ $t('super_admin.announcements.form.remove_media') }}
                            </label>
                            <figure v-if="!form.clear_media && !form.media_file" class="overflow-hidden rounded-sm bg-stone-100 dark:bg-neutral-800">
                                <figcaption class="px-3 py-2 text-xs font-medium text-stone-600 dark:text-neutral-300">
                                    {{ $t('super_admin.announcements.form.current_media') }}
                                </figcaption>
                                <img
                                    v-if="editingAnnouncement?.media_type === 'image'"
                                    :src="editingAnnouncement?.media_url"
                                    alt=""
                                    class="max-h-56 w-full object-contain"
                                    loading="lazy"
                                    decoding="async"
                                />
                                <video
                                    v-else-if="editingAnnouncement?.media_type === 'video'"
                                    controls
                                    class="max-h-56 w-full object-contain"
                                >
                                    <source :src="editingAnnouncement?.media_url" />
                                </video>
                            </figure>
                        </div>
                    </section>

                    <div class="flex justify-end gap-2 border-t border-stone-200 pt-5 dark:border-neutral-700">
                        <button type="button" @click="closeForm"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            {{ $t('super_admin.common.cancel') }}
                        </button>
                        <button type="submit" :disabled="form.processing"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                            {{ isEditing ? $t('super_admin.common.save_changes') : $t('super_admin.announcements.actions.create') }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
