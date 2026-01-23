<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import SettingsTabs from '@/Components/SettingsTabs.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import InputError from '@/Components/InputError.vue';
import DropzoneInput from '@/Components/DropzoneInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';

const props = defineProps({
    company: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        default: () => [],
    },
    suppliers: {
        type: Array,
        default: () => [],
    },
    supplier_preferences: {
        type: Object,
        default: () => ({ enabled: [], preferred: [] }),
    },
    usage_limits: {
        type: Object,
        default: () => ({ items: [] }),
    },
    warehouses: {
        type: Array,
        default: () => [],
    },
    api_tokens: {
        type: Array,
        default: () => [],
    },
    preferred_limit: {
        type: Number,
        default: 4,
    },
});

const { t } = useI18n();

const countryOptions = computed(() => [
    { id: '', name: t('settings.company.select.country') },
    { id: 'Canada', name: t('settings.company.countries.canada') },
    { id: 'France', name: t('settings.company.countries.france') },
    { id: 'Belgique', name: t('settings.company.countries.belgium') },
    { id: 'Suisse', name: t('settings.company.countries.switzerland') },
    { id: 'Maroc', name: t('settings.company.countries.morocco') },
    { id: 'Tunisie', name: t('settings.company.countries.tunisia') },
    { id: '__other__', name: t('settings.company.select.other') },
]);
const timezoneFallback = [
    'UTC',
    'America/Toronto',
    'America/New_York',
    'America/Chicago',
    'America/Los_Angeles',
    'Europe/Paris',
    'Europe/London',
    'Europe/Brussels',
    'Europe/Zurich',
    'Africa/Casablanca',
    'Africa/Tunis',
];
const TIMEZONE_ALIASES = {
    'America/Toronto': ['Montreal', 'Quebec', 'Ottawa'],
};
const buildTimezoneSearch = (zone) => {
    const parts = String(zone || '').split('/');
    const region = (parts[0] || '').replace(/_/g, ' ');
    const city = parts.slice(1).join(' ').replace(/_/g, ' ');
    const aliases = TIMEZONE_ALIASES[zone] || [];

    return [zone, region, city, ...aliases]
        .map((item) => String(item || '').trim())
        .filter(Boolean)
        .join(' ')
        .toLowerCase();
};
const timezoneOptions = computed(() => {
    if (typeof Intl !== 'undefined' && typeof Intl.supportedValuesOf === 'function') {
        return Intl.supportedValuesOf('timeZone').map((zone) => ({
            id: zone,
            name: zone,
            search: buildTimezoneSearch(zone),
        }));
    }
    return timezoneFallback.map((zone) => ({
        id: zone,
        name: zone,
        search: buildTimezoneSearch(zone),
    }));
});
const apiTokenTypeOptions = computed(() => ([
    { value: 'public', label: t('settings.company.api.fields.type_public') },
    { value: 'private', label: t('settings.company.api.fields.type_private') },
]));

const PROVINCES_BY_COUNTRY = {
    Canada: [
        'Quebec',
        'Ontario',
        'British Columbia',
        'Alberta',
        'Manitoba',
        'Saskatchewan',
        'Nova Scotia',
        'New Brunswick',
        'Newfoundland and Labrador',
        'Prince Edward Island',
        'Northwest Territories',
        'Yukon',
        'Nunavut',
    ],
    France: [
        'Ile-de-France',
        'Auvergne-Rhone-Alpes',
        'Provence-Alpes-Cote dAzur',
        'Occitanie',
        'Nouvelle-Aquitaine',
        'Hauts-de-France',
        'Grand Est',
        'Bretagne',
        'Normandie',
        'Pays de la Loire',
        'Centre-Val de Loire',
        'Bourgogne-Franche-Comte',
        'Corse',
    ],
    Belgique: ['Bruxelles-Capitale', 'Wallonie', 'Flandre'],
    Suisse: ['Zurich', 'Vaud', 'Geneve', 'Berne', 'Bale-Ville', 'Valais', 'Tessin'],
    Maroc: ['Casablanca-Settat', 'Rabat-Sale-Kenitra', 'Marrakech-Safi', 'Tanger-Tetouan-Al Hoceima'],
    Tunisie: ['Tunis', 'Ariana', 'Ben Arous', 'Sfax', 'Sousse'],
};

const CITIES_BY_COUNTRY_AND_PROVINCE = {
    Canada: {
        Quebec: ['Montreal', 'Quebec City', 'Laval', 'Gatineau', 'Sherbrooke'],
        Ontario: ['Toronto', 'Ottawa', 'Mississauga', 'Hamilton', 'London'],
        'British Columbia': ['Vancouver', 'Victoria', 'Surrey', 'Burnaby'],
        Alberta: ['Calgary', 'Edmonton'],
    },
    France: {
        'Ile-de-France': ['Paris', 'Boulogne-Billancourt', 'Saint-Denis'],
        'Auvergne-Rhone-Alpes': ['Lyon', 'Grenoble', 'Saint-Etienne'],
        'Provence-Alpes-Cote dAzur': ['Marseille', 'Nice', 'Toulon'],
        Occitanie: ['Toulouse', 'Montpellier'],
    },
    Belgique: {
        'Bruxelles-Capitale': ['Bruxelles'],
        Wallonie: ['Liege', 'Namur', 'Charleroi'],
        Flandre: ['Anvers', 'Gand', 'Bruges'],
    },
    Suisse: {
        Zurich: ['Zurich'],
        Vaud: ['Lausanne'],
        Geneve: ['Geneve'],
        Berne: ['Berne'],
    },
    Maroc: {
        'Casablanca-Settat': ['Casablanca', 'Mohammedia'],
        'Rabat-Sale-Kenitra': ['Rabat', 'Sale'],
        'Marrakech-Safi': ['Marrakech'],
    },
    Tunisie: {
        Tunis: ['Tunis'],
        Ariana: ['Ariana'],
        Sfax: ['Sfax'],
        Sousse: ['Sousse'],
    },
};

const hasOption = (options, value) => {
    return (options || []).some((option) => option.id === value);
};

const resolveSelectValue = (value, options) => {
    const trimmed = String(value || '').trim();
    if (!trimmed) {
        return { select: '', other: '' };
    }
    if (hasOption(options, trimmed)) {
        return { select: trimmed, other: '' };
    }
    return { select: '__other__', other: trimmed };
};

const baseSuppliers = computed(() => (props.suppliers || []).filter((supplier) => !supplier.is_custom));
const initialCustomSuppliers = (props.suppliers || []).filter((supplier) => supplier.is_custom);
const supplierKeys = (props.suppliers || []).map((supplier) => supplier.key);
const initialEnabledSuppliers = props.supplier_preferences?.enabled?.length
    ? props.supplier_preferences.enabled
    : supplierKeys;
const initialPreferredSuppliers = props.supplier_preferences?.preferred?.length
    ? props.supplier_preferences.preferred
    : initialEnabledSuppliers.slice(0, 2);

const form = useForm({
    company_name: props.company.company_name || '',
    company_logo: props.company.company_logo || null,
    company_description: props.company.company_description || '',
    company_country: '',
    company_country_other: '',
    company_province: '',
    company_province_other: '',
    company_city: '',
    company_city_other: '',
    company_timezone: props.company.company_timezone || '',
    company_type: props.company.company_type || 'services',
    fulfillment_delivery_enabled: props.company.fulfillment?.delivery_enabled ?? true,
    fulfillment_pickup_enabled: props.company.fulfillment?.pickup_enabled ?? true,
    fulfillment_delivery_fee: props.company.fulfillment?.delivery_fee ?? '',
    fulfillment_delivery_zone: props.company.fulfillment?.delivery_zone ?? '',
    fulfillment_pickup_address: props.company.fulfillment?.pickup_address ?? '',
    fulfillment_prep_time_minutes: props.company.fulfillment?.prep_time_minutes ?? 30,
    fulfillment_delivery_notes: props.company.fulfillment?.delivery_notes ?? '',
    fulfillment_pickup_notes: props.company.fulfillment?.pickup_notes ?? '',
    notification_task_day_email: props.company.company_notification_settings?.task_day?.email ?? true,
    notification_task_day_sms: props.company.company_notification_settings?.task_day?.sms ?? false,
    notification_task_day_whatsapp: props.company.company_notification_settings?.task_day?.whatsapp ?? false,
    supplier_enabled: initialEnabledSuppliers,
    supplier_preferred: initialPreferredSuppliers,
    custom_suppliers: initialCustomSuppliers,
});

const categoryForm = useForm({
    name: '',
});

const warehouseForm = ref({
    name: '',
    code: '',
    address: '',
    city: '',
    state: '',
    postal_code: '',
    country: '',
    is_default: false,
    is_active: true,
});
const warehouseErrors = ref({});
const warehouseSaving = ref(false);
const editingWarehouseId = ref(null);
const warehouseEditForm = ref({
    name: '',
    code: '',
    address: '',
    city: '',
    state: '',
    postal_code: '',
    country: '',
    is_active: true,
});
const warehouseEditErrors = ref({});
const warehouseEditSaving = ref(false);

const resetWarehouseForm = () => {
    warehouseForm.value = {
        name: '',
        code: '',
        address: '',
        city: '',
        state: '',
        postal_code: '',
        country: '',
        is_default: false,
        is_active: true,
    };
    warehouseErrors.value = {};
};

const createWarehouse = async () => {
    warehouseSaving.value = true;
    warehouseErrors.value = {};
    try {
        await axios.post(route('settings.warehouses.store'), warehouseForm.value, {
            headers: { Accept: 'application/json' },
        });
        resetWarehouseForm();
        router.reload({ only: ['warehouses'] });
    } catch (error) {
        warehouseErrors.value = error?.response?.data?.errors || { form: [t('settings.company.errors.create_warehouse')] };
    } finally {
        warehouseSaving.value = false;
    }
};

const startWarehouseEdit = (warehouse) => {
    editingWarehouseId.value = warehouse.id;
    warehouseEditErrors.value = {};
    warehouseEditForm.value = {
        name: warehouse.name || '',
        code: warehouse.code || '',
        address: warehouse.address || '',
        city: warehouse.city || '',
        state: warehouse.state || '',
        postal_code: warehouse.postal_code || '',
        country: warehouse.country || '',
        is_active: warehouse.is_active !== false,
    };
};

const cancelWarehouseEdit = () => {
    editingWarehouseId.value = null;
    warehouseEditErrors.value = {};
};

const saveWarehouseEdit = async (warehouseId) => {
    warehouseEditSaving.value = true;
    warehouseEditErrors.value = {};
    try {
        await axios.put(route('settings.warehouses.update', warehouseId), warehouseEditForm.value, {
            headers: { Accept: 'application/json' },
        });
        editingWarehouseId.value = null;
        router.reload({ only: ['warehouses'] });
    } catch (error) {
        warehouseEditErrors.value = error?.response?.data?.errors || { form: [t('settings.company.errors.update_warehouse')] };
    } finally {
        warehouseEditSaving.value = false;
    }
};

const setDefaultWarehouse = async (warehouseId) => {
    try {
        await axios.patch(route('settings.warehouses.default', warehouseId), {}, {
            headers: { Accept: 'application/json' },
        });
        router.reload({ only: ['warehouses'] });
    } catch (error) {
        warehouseErrors.value = error?.response?.data?.errors || { form: [t('settings.company.errors.set_default_warehouse')] };
    }
};

const deleteWarehouse = async (warehouse) => {
    if (!confirm(t('settings.company.confirm.delete_warehouse', { name: warehouse.name }))) {
        return;
    }
    try {
        await axios.delete(route('settings.warehouses.destroy', warehouse.id), {
            headers: { Accept: 'application/json' },
        });
        router.reload({ only: ['warehouses'] });
    } catch (error) {
        warehouseErrors.value = error?.response?.data?.errors || { form: [t('settings.company.errors.delete_warehouse')] };
    }
};

const apiTokenForm = ref({
    name: '',
    type: 'public',
    expires_at: '',
});
const apiTokenErrors = ref({});
const apiTokenSaving = ref(false);
const apiTokenPlain = ref('');

const createApiToken = async () => {
    apiTokenSaving.value = true;
    apiTokenErrors.value = {};
    apiTokenPlain.value = '';
    try {
        const response = await axios.post(route('settings.api-tokens.store'), apiTokenForm.value, {
            headers: { Accept: 'application/json' },
        });
        apiTokenPlain.value = response?.data?.plain_text_token || '';
        apiTokenForm.value = { name: '', type: 'public', expires_at: '' };
        router.reload({ only: ['api_tokens'] });
    } catch (error) {
        apiTokenErrors.value = error?.response?.data?.errors || { form: [t('settings.company.errors.create_token')] };
    } finally {
        apiTokenSaving.value = false;
    }
};

const revokeApiToken = async (tokenId) => {
    if (!confirm(t('settings.company.confirm.revoke_token'))) {
        return;
    }
    try {
        await axios.delete(route('settings.api-tokens.destroy', tokenId), {
            headers: { Accept: 'application/json' },
        });
        router.reload({ only: ['api_tokens'] });
    } catch (error) {
        apiTokenErrors.value = error?.response?.data?.errors || { form: [t('settings.company.errors.revoke_token')] };
    }
};

const formatTokenDate = (value) => {
    if (!value) {
        return '--';
    }
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '--' : date.toLocaleDateString();
};

const formatAbilities = (abilities) => {
    if (!Array.isArray(abilities) || !abilities.length) {
        return '--';
    }
    return abilities.join(', ');
};

const countryPreset = resolveSelectValue(props.company.company_country, countryOptions.value);
form.company_country = countryPreset.select || '';
form.company_country_other = countryPreset.other;

const effectiveCountry = computed(() => {
    return form.company_country === '__other__'
        ? String(form.company_country_other || '').trim()
        : form.company_country;
});

const provinceOptions = computed(() => {
    const provinces = PROVINCES_BY_COUNTRY[effectiveCountry.value] || [];
    return [
        { id: '', name: t('settings.company.select.province') },
        ...provinces.map((value) => ({ id: value, name: value })),
        { id: '__other__', name: t('settings.company.select.other') },
    ];
});

const provincePreset = resolveSelectValue(props.company.company_province, provinceOptions.value);
form.company_province = provincePreset.select;
form.company_province_other = provincePreset.other;

const effectiveProvince = computed(() => {
    return form.company_province === '__other__'
        ? String(form.company_province_other || '').trim()
        : form.company_province;
});

const cityOptions = computed(() => {
    const country = effectiveCountry.value;
    const province = effectiveProvince.value;
    const cities = CITIES_BY_COUNTRY_AND_PROVINCE[country]?.[province] || [];

    return [
        { id: '', name: t('settings.company.select.city') },
        ...cities.map((value) => ({ id: value, name: value })),
        { id: '__other__', name: t('settings.company.select.other') },
    ];
});

const cityPreset = resolveSelectValue(props.company.company_city, cityOptions.value);
form.company_city = cityPreset.select;
form.company_city_other = cityPreset.other;

watch(
    () => form.company_country,
    () => {
        form.company_province = '';
        form.company_province_other = '';
        form.company_city = '';
        form.company_city_other = '';
    }
);

watch(
    () => form.company_province,
    () => {
        form.company_city = '';
        form.company_city_other = '';
    }
);

watch(
    () => form.supplier_enabled,
    (enabled) => {
        form.supplier_preferred = (form.supplier_preferred || []).filter((key) => enabled.includes(key));
    },
    { deep: true }
);

const suppliersList = computed(() => {
    const list = [...(baseSuppliers.value || []), ...(form.custom_suppliers || [])];
    const byKey = {};
    list.forEach((supplier) => {
        if (!supplier?.key) {
            return;
        }
        byKey[supplier.key] = supplier;
    });
    return Object.values(byKey);
});

const customSupplierForm = ref({
    name: '',
    url: '',
});

const canAddCustomSupplier = computed(() => {
    return customSupplierForm.value.name.trim().length > 0
        && customSupplierForm.value.url.trim().length > 0;
});

const normalizeSupplierUrl = (value) => {
    const trimmed = String(value || '').trim();
    if (!trimmed) {
        return '';
    }
    if (/^https?:\/\//i.test(trimmed)) {
        return trimmed;
    }
    return `https://${trimmed}`;
};

const buildCustomSupplierKey = () => {
    return `custom_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`;
};

const addCustomSupplier = () => {
    if (!canAddCustomSupplier.value) {
        return;
    }

    let key = buildCustomSupplierKey();
    while (suppliersList.value.some((supplier) => supplier.key === key)) {
        key = buildCustomSupplierKey();
    }

    const name = customSupplierForm.value.name.trim();
    const url = normalizeSupplierUrl(customSupplierForm.value.url);
    const nextSupplier = {
        key,
        name,
        url,
        is_custom: true,
    };

    form.custom_suppliers = [...(form.custom_suppliers || []), nextSupplier];

    if (!form.supplier_enabled.includes(key)) {
        form.supplier_enabled = [...form.supplier_enabled, key];
    }

    customSupplierForm.value = { name: '', url: '' };
};

const removeCustomSupplier = (key) => {
    form.custom_suppliers = (form.custom_suppliers || []).filter((supplier) => supplier.key !== key);
    form.supplier_enabled = (form.supplier_enabled || []).filter((id) => id !== key);
    form.supplier_preferred = (form.supplier_preferred || []).filter((id) => id !== key);
};

const dispatchDemoEvent = (eventName) => {
    if (typeof window === 'undefined') {
        return;
    }
    window.dispatchEvent(new CustomEvent(eventName));
};

const submit = () => {
    const normalizeText = (value) => {
        const trimmed = String(value || '').trim();
        return trimmed.length ? trimmed : null;
    };

    form
        .transform((data) => {
            const country = data.company_country === '__other__' ? data.company_country_other : data.company_country;
            const province = data.company_province === '__other__' ? data.company_province_other : data.company_province;
            const city = data.company_city === '__other__' ? data.company_city_other : data.company_city;

            const payload = {
                ...data,
                company_country: normalizeText(country),
                company_province: normalizeText(province),
                company_city: normalizeText(city),
                company_timezone: normalizeText(data.company_timezone),
            };

            payload.custom_suppliers = data.custom_suppliers || [];

            payload.company_fulfillment = {
                delivery_enabled: Boolean(data.fulfillment_delivery_enabled),
                pickup_enabled: Boolean(data.fulfillment_pickup_enabled),
                delivery_fee: data.fulfillment_delivery_fee !== '' ? Number(data.fulfillment_delivery_fee) : null,
                delivery_zone: normalizeText(data.fulfillment_delivery_zone),
                pickup_address: normalizeText(data.fulfillment_pickup_address),
                prep_time_minutes: data.fulfillment_prep_time_minutes !== ''
                    ? Number(data.fulfillment_prep_time_minutes)
                    : null,
                delivery_notes: normalizeText(data.fulfillment_delivery_notes),
                pickup_notes: normalizeText(data.fulfillment_pickup_notes),
            };

            payload.company_notification_settings = {
                task_day: {
                    email: Boolean(data.notification_task_day_email),
                    sms: Boolean(data.notification_task_day_sms),
                    whatsapp: Boolean(data.notification_task_day_whatsapp),
                },
            };

            delete payload.fulfillment_delivery_enabled;
            delete payload.fulfillment_pickup_enabled;
            delete payload.fulfillment_delivery_fee;
            delete payload.fulfillment_delivery_zone;
            delete payload.fulfillment_pickup_address;
            delete payload.fulfillment_prep_time_minutes;
            delete payload.fulfillment_delivery_notes;
            delete payload.fulfillment_pickup_notes;
            delete payload.notification_task_day_email;
            delete payload.notification_task_day_sms;
            delete payload.notification_task_day_whatsapp;

            if (data.company_logo instanceof File) {
                payload.company_logo = data.company_logo;
            } else {
                delete payload.company_logo;
            }

            return payload;
        })
        .put(route('settings.company.update'), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                dispatchDemoEvent('demo:settings_saved');
            },
        });
};

const canAddCategory = computed(() => categoryForm.name.trim().length > 0);

const addCategory = () => {
    if (!canAddCategory.value) {
        return;
    }

    categoryForm.post(route('settings.categories.store'), {
        preserveScroll: true,
        onSuccess: () => categoryForm.reset('name'),
    });
};

const usageItems = computed(() => props.usage_limits?.items || []);
const planName = computed(() =>
    props.usage_limits?.plan_name || props.usage_limits?.plan_key || t('settings.company.limits.plan_fallback')
);
const hasUsageAlert = computed(() => usageItems.value.some((item) => item.status !== 'ok'));
const limitLabelMap = computed(() => ({
    quotes: t('settings.company.limits.labels.quotes'),
    requests: t('settings.company.limits.labels.requests'),
    plan_scan_quotes: t('settings.company.limits.labels.plan_scan_quotes'),
    invoices: t('settings.company.limits.labels.invoices'),
    jobs: t('settings.company.limits.labels.jobs'),
    products: t('settings.company.limits.labels.products'),
    services: t('settings.company.limits.labels.services'),
    tasks: t('settings.company.limits.labels.tasks'),
    team_members: t('settings.company.limits.labels.team_members'),
    assistant_requests: t('settings.company.limits.labels.assistant_requests'),
}));

const displayLimitLabel = (item) => limitLabelMap.value[item.key] || item.label || item.key;
const displayLimitValue = (item) => {
    if (item.limit === null || item.limit === undefined) {
        return t('dashboard.usage.unlimited');
    }
    if (Number(item.limit) <= 0) {
        return t('dashboard.usage.not_available');
    }
    return item.limit;
};

const usageStatusClass = (status) => {
    if (status === 'over') {
        return 'text-red-600';
    }
    if (status === 'warning') {
        return 'text-amber-600';
    }
    return 'text-emerald-600';
};

const isProductCompany = computed(() => form.company_type === 'products');

const preferredLimit = computed(() => {
    const limit = Number(props.preferred_limit) || 4;
    return limit > 0 ? limit : 4;
});
const isPreferredDisabled = (key) => {
    if (form.supplier_preferred.includes(key)) {
        return false;
    }
    return form.supplier_preferred.length >= preferredLimit.value;
};

const tabPrefix = 'settings-company';
const tabs = computed(() => [
    { id: 'company', label: t('settings.company.tabs.company.label'), description: t('settings.company.tabs.company.description') },
    { id: 'suppliers', label: t('settings.company.tabs.suppliers.label'), description: t('settings.company.tabs.suppliers.description') },
    { id: 'categories', label: t('settings.company.tabs.categories.label'), description: t('settings.company.tabs.categories.description') },
    { id: 'warehouses', label: t('settings.company.tabs.warehouses.label'), description: t('settings.company.tabs.warehouses.description') },
    { id: 'api', label: t('settings.company.tabs.api.label'), description: t('settings.company.tabs.api.description') },
    { id: 'limits', label: t('settings.company.tabs.limits.label'), description: t('settings.company.tabs.limits.description') },
]);

const resolveInitialTab = () => {
    if (typeof window === 'undefined') {
        return tabs.value[0].id;
    }
    const stored = window.sessionStorage.getItem(`${tabPrefix}-tab`);
    return tabs.value.some((tab) => tab.id === stored) ? stored : tabs.value[0].id;
};

const activeTab = ref(resolveInitialTab());

watch(activeTab, (value) => {
    if (typeof window === 'undefined') {
        return;
    }
    window.sessionStorage.setItem(`${tabPrefix}-tab`, value);
});
</script>

<template>
    <Head :title="$t('settings.company.meta_title')" />

    <SettingsLayout active="company" content-class="w-full max-w-6xl">
        <div class="w-full space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('settings.company.title') }}
                    </h1>
                    <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('settings.company.subtitle') }}
                    </p>
                </div>
            </div>

            <SettingsTabs
                v-model="activeTab"
                :tabs="tabs"
                :id-prefix="tabPrefix"
                :aria-label="$t('settings.company.aria_sections')"
            />

            <div
                v-show="activeTab === 'company'"
                :id="`${tabPrefix}-panel-company`"
                role="tabpanel"
                :aria-labelledby="`${tabPrefix}-tab-company`"
                class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700"
            >
                <div class="p-4 space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('settings.company.profile.title') }}
                        </h2>
                        <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('settings.company.profile.description') }}
                        </p>
                    </div>
                    <div>
                        <FloatingInput v-model="form.company_name" :label="$t('settings.company.fields.name')" />
                        <InputError class="mt-1" :message="form.errors.company_name" />
                    </div>

                    <div class="space-y-2">
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('settings.company.fields.logo_optional') }}
                        </p>
                        <DropzoneInput v-model="form.company_logo" :label="$t('settings.company.fields.logo_upload')" />
                        <InputError class="mt-1" :message="form.errors.company_logo" />
                    </div>

                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('settings.company.fields.description_optional') }}
                        </label>
                        <textarea v-model="form.company_description"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                            rows="3" />
                        <InputError class="mt-1" :message="form.errors.company_description" />
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div>
                            <FloatingSelect
                                v-model="form.company_country"
                                :label="$t('settings.company.fields.country_optional')"
                                :options="countryOptions"
                            />
                            <InputError class="mt-1" :message="form.errors.company_country" />
                            <div v-if="form.company_country === '__other__'" class="mt-2">
                                <FloatingInput v-model="form.company_country_other" :label="$t('settings.company.fields.country_other')" />
                            </div>
                        </div>

                        <div>
                            <FloatingSelect
                                v-model="form.company_province"
                                :label="$t('settings.company.fields.province_optional')"
                                :options="provinceOptions"
                            />
                            <InputError class="mt-1" :message="form.errors.company_province" />
                            <div v-if="form.company_province === '__other__'" class="mt-2">
                                <FloatingInput v-model="form.company_province_other" :label="$t('settings.company.fields.province_other')" />
                            </div>
                        </div>

                        <div class="md:col-span-2">
                            <FloatingSelect
                                v-model="form.company_city"
                                :label="$t('settings.company.fields.city_optional')"
                                :options="cityOptions"
                            />
                            <InputError class="mt-1" :message="form.errors.company_city" />
                            <div v-if="form.company_city === '__other__'" class="mt-2">
                                <FloatingInput v-model="form.company_city_other" :label="$t('settings.company.fields.city_other')" />
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <FloatingSelect
                                v-model="form.company_timezone"
                                :label="$t('settings.company.fields.timezone')"
                                :options="timezoneOptions"
                                :placeholder="$t('settings.company.fields.timezone_placeholder')"
                                :filterable="true"
                                :filter-placeholder="$t('settings.company.fields.timezone_search_placeholder')"
                            />
                            <InputError class="mt-1" :message="form.errors.company_timezone" />
                        </div>
                    </div>

                    <div>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('settings.company.fields.company_type') }}
                        </p>
                        <div class="mt-2 space-y-2">
                            <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                <input type="radio" name="company_type" value="services" v-model="form.company_type" />
                                <span>{{ $t('settings.company.fields.type_services') }}</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                <input type="radio" name="company_type" value="products" v-model="form.company_type" />
                                <span>{{ $t('settings.company.fields.type_products') }}</span>
                            </label>
                        </div>
                        <InputError class="mt-1" :message="form.errors.company_type" />
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 space-y-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div>
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-200">
                                {{ $t('settings.company.notifications.title') }}
                            </h3>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('settings.company.notifications.description') }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-4 text-sm text-stone-700 dark:text-neutral-200">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" v-model="form.notification_task_day_email" />
                                <span>{{ $t('settings.company.notifications.email') }}</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" v-model="form.notification_task_day_sms" />
                                <span>{{ $t('settings.company.notifications.sms') }}</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" v-model="form.notification_task_day_whatsapp" />
                                <span>{{ $t('settings.company.notifications.whatsapp') }}</span>
                            </label>
                        </div>
                    </div>

                    <div v-if="isProductCompany" class="rounded-sm border border-stone-200 bg-stone-50 p-4 space-y-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div>
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-200">
                                {{ $t('settings.company.delivery.title') }}
                            </h3>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('settings.company.delivery.description') }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-4 text-sm text-stone-700 dark:text-neutral-200">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" v-model="form.fulfillment_delivery_enabled" />
                                <span>{{ $t('settings.company.delivery.delivery_active') }}</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" v-model="form.fulfillment_pickup_enabled" />
                                <span>{{ $t('settings.company.delivery.pickup_active') }}</span>
                            </label>
                        </div>
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <FloatingInput v-model="form.fulfillment_delivery_fee" :label="$t('settings.company.delivery.delivery_fee_optional')" />
                                <InputError class="mt-1" :message="form.errors['company_fulfillment.delivery_fee']" />
                            </div>
                            <div>
                                <FloatingInput v-model="form.fulfillment_prep_time_minutes" :label="$t('settings.company.delivery.prep_time_minutes')" />
                                <InputError class="mt-1" :message="form.errors['company_fulfillment.prep_time_minutes']" />
                            </div>
                            <div class="md:col-span-2">
                                <FloatingInput v-model="form.fulfillment_delivery_zone" :label="$t('settings.company.delivery.delivery_zone_optional')" />
                                <InputError class="mt-1" :message="form.errors['company_fulfillment.delivery_zone']" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('settings.company.delivery.pickup_address') }}
                                </label>
                                <textarea v-model="form.fulfillment_pickup_address"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                    rows="2" />
                                <InputError class="mt-1" :message="form.errors['company_fulfillment.pickup_address']" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('settings.company.delivery.delivery_notes_optional') }}
                                </label>
                                <textarea v-model="form.fulfillment_delivery_notes"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                    rows="2" />
                                <InputError class="mt-1" :message="form.errors['company_fulfillment.delivery_notes']" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('settings.company.delivery.pickup_notes_optional') }}
                                </label>
                                <textarea v-model="form.fulfillment_pickup_notes"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                    rows="2" />
                                <InputError class="mt-1" :message="form.errors['company_fulfillment.pickup_notes']" />
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="button" @click="submit" :disabled="form.processing" data-testid="demo-settings-save"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            {{ $t('settings.company.actions.save') }}
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-show="activeTab === 'categories'"
                :id="`${tabPrefix}-panel-categories`"
                role="tabpanel"
                :aria-labelledby="`${tabPrefix}-tab-categories`"
                class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700"
            >
                <div class="p-4 space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('settings.company.categories.title') }}
                        </h2>
                        <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('settings.company.categories.description') }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <span v-for="category in props.categories" :key="category.id"
                            class="rounded-full bg-stone-100 px-3 py-1 text-xs text-stone-700 dark:bg-neutral-900 dark:text-neutral-200">
                            {{ category.name }}
                        </span>
                        <span v-if="!props.categories.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('settings.company.categories.empty') }}
                        </span>
                    </div>

                    <div class="flex flex-col gap-3 md:flex-row md:items-end">
                        <div class="flex-1">
                            <FloatingInput v-model="categoryForm.name" :label="$t('settings.company.categories.new_label')" />
                            <InputError class="mt-1" :message="categoryForm.errors.name" />
                        </div>
                        <button type="button" @click="addCategory" :disabled="!canAddCategory || categoryForm.processing"
                            class="w-full md:w-auto py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            {{ $t('settings.company.categories.add') }}
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-show="activeTab === 'api'"
                :id="`${tabPrefix}-panel-api`"
                role="tabpanel"
                :aria-labelledby="`${tabPrefix}-tab-api`"
                class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700"
            >
                <div class="p-4 space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('settings.company.api.title') }}
                        </h2>
                        <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('settings.company.api.description') }}
                        </p>
                    </div>

                    <div v-if="apiTokenPlain" class="rounded-sm border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-700">
                        {{ $t('settings.company.api.token_new') }} <span class="font-semibold">{{ apiTokenPlain }}</span>
                        <div class="mt-1 text-[11px]">{{ $t('settings.company.api.token_hint') }}</div>
                    </div>

                    <div v-if="apiTokenErrors.form" class="text-xs text-red-600">{{ apiTokenErrors.form[0] }}</div>

                    <div class="space-y-3">
                        <div v-if="!props.api_tokens.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('settings.company.api.empty') }}
                        </div>
                        <div v-for="token in props.api_tokens" :key="token.id"
                            class="rounded-sm border border-stone-200 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:text-neutral-300">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                                    {{ token.name }}
                                </div>
                                <button type="button"
                                    class="text-xs font-semibold text-red-600 hover:text-red-700"
                                    @click="revokeApiToken(token.id)">
                                    {{ $t('settings.company.api.revoke') }}
                                </button>
                            </div>
                            <div class="mt-2 grid grid-cols-1 md:grid-cols-3 gap-2">
                                <div>{{ $t('settings.company.api.scopes', { scopes: formatAbilities(token.abilities) }) }}</div>
                                <div>{{ $t('settings.company.api.created', { date: formatTokenDate(token.created_at) }) }}</div>
                                <div>{{ $t('settings.company.api.expires', { date: formatTokenDate(token.expires_at) }) }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-stone-200 pt-4 dark:border-neutral-700">
                        <h3 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
                            {{ $t('settings.company.api.create_title') }}
                        </h3>
                        <div class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div>
                                <FloatingInput v-model="apiTokenForm.name" :label="$t('settings.company.api.fields.name')" />
                                <InputError class="mt-1" :message="apiTokenErrors.name?.[0]" />
                            </div>
                            <div>
                                <FloatingSelect
                                    v-model="apiTokenForm.type"
                                    :label="$t('settings.company.api.fields.type')"
                                    :options="apiTokenTypeOptions"
                                />
                                <InputError class="mt-1" :message="apiTokenErrors.type?.[0]" />
                            </div>
                            <div>
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('settings.company.api.fields.expires') }}
                                </label>
                                <input type="date" v-model="apiTokenForm.expires_at"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                                <InputError class="mt-1" :message="apiTokenErrors.expires_at?.[0]" />
                            </div>
                        </div>
                        <div class="mt-3 flex justify-end">
                            <button type="button"
                                class="inline-flex items-center rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                                :disabled="apiTokenSaving"
                                @click="createApiToken">
                                {{ $t('settings.company.api.generate') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-show="activeTab === 'warehouses'"
                :id="`${tabPrefix}-panel-warehouses`"
                role="tabpanel"
                :aria-labelledby="`${tabPrefix}-tab-warehouses`"
                class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700"
            >
                <div class="p-4 space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('settings.company.warehouses.title') }}
                        </h2>
                        <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('settings.company.warehouses.description') }}
                        </p>
                    </div>

                    <div v-if="warehouseErrors.form" class="text-xs text-red-600">{{ warehouseErrors.form[0] }}</div>

                    <div class="space-y-3">
                        <div v-if="!props.warehouses.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('settings.company.warehouses.empty') }}
                        </div>
                        <div v-for="warehouse in props.warehouses" :key="warehouse.id"
                            class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-stone-800 dark:text-neutral-200">
                                            {{ warehouse.name }}
                                        </span>
                                        <span v-if="warehouse.is_default"
                                            class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                                            {{ $t('settings.company.warehouses.default_badge') }}
                                        </span>
                                    </div>
                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ warehouse.code || $t('settings.company.warehouses.no_code') }}
                                        <span v-if="warehouse.city"> - {{ warehouse.city }}</span>
                                        <span v-if="warehouse.country"> - {{ warehouse.country }}</span>
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button v-if="!warehouse.is_default" type="button"
                                        class="text-xs font-semibold text-green-700 hover:text-green-800 dark:text-green-400"
                                        @click="setDefaultWarehouse(warehouse.id)">
                                        {{ $t('settings.company.warehouses.set_default') }}
                                    </button>
                                    <button type="button"
                                        class="text-xs font-semibold text-stone-600 hover:text-stone-800 dark:text-neutral-300"
                                        @click="startWarehouseEdit(warehouse)">
                                        {{ $t('settings.company.warehouses.edit') }}
                                    </button>
                                    <button type="button"
                                        class="text-xs font-semibold text-red-600 hover:text-red-700"
                                        @click="deleteWarehouse(warehouse)">
                                        {{ $t('settings.company.warehouses.delete') }}
                                    </button>
                                </div>
                            </div>

                            <div v-if="editingWarehouseId === warehouse.id" class="mt-4 space-y-3">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div>
                                        <FloatingInput v-model="warehouseEditForm.name" :label="$t('settings.company.warehouses.fields.name')" />
                                        <InputError class="mt-1" :message="warehouseEditErrors.name?.[0]" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="warehouseEditForm.code" :label="$t('settings.company.warehouses.fields.code')" />
                                        <InputError class="mt-1" :message="warehouseEditErrors.code?.[0]" />
                                    </div>
                                    <FloatingInput v-model="warehouseEditForm.address" :label="$t('settings.company.warehouses.fields.address')" />
                                    <FloatingInput v-model="warehouseEditForm.city" :label="$t('settings.company.warehouses.fields.city')" />
                                    <FloatingInput v-model="warehouseEditForm.state" :label="$t('settings.company.warehouses.fields.state')" />
                                    <FloatingInput v-model="warehouseEditForm.postal_code" :label="$t('settings.company.warehouses.fields.postal_code')" />
                                    <FloatingInput v-model="warehouseEditForm.country" :label="$t('settings.company.warehouses.fields.country')" />
                                </div>
                                <label class="flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-400">
                                    <input type="checkbox" v-model="warehouseEditForm.is_active" />
                                    {{ $t('settings.company.warehouses.active') }}
                                </label>
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" class="text-xs text-stone-500 hover:text-stone-700"
                                        @click="cancelWarehouseEdit">
                                        {{ $t('settings.company.warehouses.cancel') }}
                                    </button>
                                    <button type="button"
                                        class="inline-flex items-center rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                                        :disabled="warehouseEditSaving"
                                        @click="saveWarehouseEdit(warehouse.id)">
                                        {{ $t('settings.company.warehouses.save') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-stone-200 pt-4 dark:border-neutral-700">
                        <h3 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
                            {{ $t('settings.company.warehouses.add_title') }}
                        </h3>
                        <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <FloatingInput v-model="warehouseForm.name" :label="$t('settings.company.warehouses.fields.name')" />
                                <InputError class="mt-1" :message="warehouseErrors.name?.[0]" />
                            </div>
                            <div>
                                <FloatingInput v-model="warehouseForm.code" :label="$t('settings.company.warehouses.fields.code')" />
                                <InputError class="mt-1" :message="warehouseErrors.code?.[0]" />
                            </div>
                            <FloatingInput v-model="warehouseForm.address" :label="$t('settings.company.warehouses.fields.address')" />
                            <FloatingInput v-model="warehouseForm.city" :label="$t('settings.company.warehouses.fields.city')" />
                            <FloatingInput v-model="warehouseForm.state" :label="$t('settings.company.warehouses.fields.state')" />
                            <FloatingInput v-model="warehouseForm.postal_code" :label="$t('settings.company.warehouses.fields.postal_code')" />
                            <FloatingInput v-model="warehouseForm.country" :label="$t('settings.company.warehouses.fields.country')" />
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-4 text-xs text-stone-600 dark:text-neutral-400">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" v-model="warehouseForm.is_default" />
                                {{ $t('settings.company.warehouses.default_checkbox') }}
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" v-model="warehouseForm.is_active" />
                                {{ $t('settings.company.warehouses.active') }}
                            </label>
                        </div>
                        <div class="mt-3 flex justify-end">
                            <button type="button"
                                class="inline-flex items-center rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                                :disabled="warehouseSaving"
                                @click="createWarehouse">
                                {{ $t('settings.company.warehouses.add') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-show="activeTab === 'suppliers'"
                :id="`${tabPrefix}-panel-suppliers`"
                role="tabpanel"
                :aria-labelledby="`${tabPrefix}-tab-suppliers`"
                class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700"
            >
                <div class="p-4 space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('settings.company.suppliers.title') }}
                        </h2>
                        <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('settings.company.suppliers.description', { limit: preferredLimit }) }}
                        </p>
                    </div>

                    <div v-if="!suppliersList.length" class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('settings.company.suppliers.empty') }}
                    </div>
                    <div v-else class="space-y-3">
                        <div v-for="supplier in suppliersList" :key="supplier.key"
                            class="flex flex-col gap-2 rounded-sm border border-stone-200 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:text-neutral-200">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        :value="supplier.key"
                                        v-model="form.supplier_enabled"
                                    />
                                    <span class="font-medium">{{ supplier.name }}</span>
                                    <span v-if="supplier.is_custom"
                                        class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                                        {{ $t('settings.company.suppliers.custom_badge') }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <a v-if="supplier.url" :href="supplier.url" target="_blank" rel="noopener"
                                        class="text-xs text-green-700 hover:underline dark:text-green-400">
                                        {{ $t('settings.company.suppliers.visit_site') }}
                                    </a>
                                    <button v-if="supplier.is_custom" type="button"
                                        class="text-xs font-semibold text-red-600 hover:text-red-700"
                                        @click="removeCustomSupplier(supplier.key)">
                                        {{ $t('settings.company.suppliers.remove') }}
                                    </button>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 text-xs text-stone-500 dark:text-neutral-400">
                                <label class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        :value="supplier.key"
                                        v-model="form.supplier_preferred"
                                        :disabled="isPreferredDisabled(supplier.key) || !form.supplier_enabled.includes(supplier.key)"
                                    />
                                    <span>{{ $t('settings.company.suppliers.preferred') }}</span>
                                </label>
                                <span v-if="!form.supplier_enabled.includes(supplier.key)">
                                    {{ $t('settings.company.suppliers.enable_hint') }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-stone-200 pt-4 dark:border-neutral-700">
                        <h3 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
                            {{ $t('settings.company.suppliers.add_title') }}
                        </h3>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('settings.company.suppliers.add_description') }}
                        </p>
                        <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                            <FloatingInput v-model="customSupplierForm.name" :label="$t('settings.company.suppliers.fields.name')" />
                            <FloatingInput v-model="customSupplierForm.url" :label="$t('settings.company.suppliers.fields.url')" />
                        </div>
                        <div class="mt-3 flex justify-end">
                            <button
                                type="button"
                                class="inline-flex items-center rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                                :disabled="!canAddCustomSupplier"
                                @click="addCustomSupplier"
                            >
                                {{ $t('settings.company.suppliers.add') }}
                            </button>
                        </div>
                    </div>
                    <InputError class="mt-1" :message="form.errors.supplier_preferred" />

                    <div class="flex justify-end">
                        <button
                            type="button"
                            class="inline-flex items-center rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                            :disabled="form.processing"
                            @click="submit"
                        >
                            {{ $t('settings.company.actions.save') }}
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-show="activeTab === 'limits'"
                :id="`${tabPrefix}-panel-limits`"
                role="tabpanel"
                :aria-labelledby="`${tabPrefix}-tab-limits`"
                class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700"
            >
                <div class="p-4 space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('settings.company.limits.title') }}
                        </h2>
                        <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('settings.company.limits.current_plan', { plan: planName }) }}
                        </p>
                    </div>

                    <div
                        class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                        <table class="min-w-full divide-y divide-stone-200 text-sm text-left text-stone-600 dark:divide-neutral-700 dark:text-neutral-300">
                            <thead class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                <tr>
                                    <th class="py-2">{{ $t('settings.company.limits.table.module') }}</th>
                                    <th class="py-2">{{ $t('settings.company.limits.table.used') }}</th>
                                    <th class="py-2">{{ $t('settings.company.limits.table.limit') }}</th>
                                    <th class="py-2">{{ $t('settings.company.limits.table.remaining') }}</th>
                                    <th class="py-2">{{ $t('settings.company.limits.table.usage') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                <tr v-for="item in usageItems" :key="item.key">
                                    <td class="py-2">{{ displayLimitLabel(item) }}</td>
                                    <td class="py-2">{{ item.used }}</td>
                                    <td class="py-2">{{ displayLimitValue(item) }}</td>
                                    <td class="py-2">
                                        <span v-if="item.remaining !== null">{{ item.remaining }}</span>
                                        <span v-else class="text-stone-400">--</span>
                                    </td>
                                    <td class="py-2">
                                        <span v-if="item.percent !== null" :class="usageStatusClass(item.status)">
                                            {{ item.percent }}%
                                        </span>
                                        <span v-else class="text-stone-400">--</span>
                                    </td>
                                </tr>
                                <tr v-if="!usageItems.length">
                                    <td colspan="5" class="py-3 text-center text-sm text-stone-500 dark:text-neutral-400">
                                        {{ $t('settings.company.limits.table.empty') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="hasUsageAlert"
                        class="rounded-sm border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700">
                        {{ $t('settings.company.limits.alert') }}
                    </div>
                </div>
            </div>
        </div>
    </SettingsLayout>
</template>
