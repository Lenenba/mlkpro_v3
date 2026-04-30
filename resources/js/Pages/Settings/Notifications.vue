<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import SettingsTabs from '@/Components/SettingsTabs.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import {
    applyAccessibilityPreferences,
    readAccessibilityPreferences,
    writeAccessibilityPreferences,
} from '@/utils/accessibility';

const props = defineProps({
    settings: {
        type: Object,
        required: true,
    },
    can_manage_company_notifications: {
        type: Boolean,
        default: false,
    },
    company_notification_settings: {
        type: Object,
        default: () => ({}),
    },
    whatsapp_configured: {
        type: Boolean,
        default: false,
    },
});

const { t } = useI18n();
const companyNotificationSettings = props.company_notification_settings || {};
const notificationChannelKeys = ['email', 'sms', 'whatsapp'];
const notificationAlertKeys = [
    'task_day',
    'task_updates',
    'reservations',
    'orders',
    'sales',
    'stock',
    'planning',
    'billing',
    'expenses',
    'crm',
    'support',
    'security',
    'emails_mirror',
    'system',
];
const normalizeNotificationChannels = (value = {}) => ({
    email: value?.email ?? true,
    sms: value?.sms ?? false,
    whatsapp: value?.whatsapp ?? false,
});
const buildNotificationAlerts = (settings = {}) => {
    const alerts = settings?.alerts || {};

    return notificationAlertKeys.reduce((carry, key) => {
        const legacyValue = ['task_day', 'task_updates'].includes(key) ? settings?.[key] : null;
        carry[key] = normalizeNotificationChannels(alerts?.[key] || legacyValue || {});

        return carry;
    }, {});
};
const notificationChannelOptions = computed(() => notificationChannelKeys.map((key) => ({
    key,
    label: t(`settings.company.notifications.channels.${key}`),
    description: t(`settings.company.notifications.channel_descriptions.${key}`),
})));
const notificationAlertOptions = computed(() => notificationAlertKeys.map((key) => ({
    key,
    label: t(`settings.company.notifications.alerts.${key}.label`),
    description: t(`settings.company.notifications.alerts.${key}.description`),
})));

const form = useForm({
    channels: {
        in_app: Boolean(props.settings?.channels?.in_app ?? true),
        push: Boolean(props.settings?.channels?.push ?? true),
    },
    categories: {
        orders: Boolean(props.settings?.categories?.orders ?? true),
        sales: Boolean(props.settings?.categories?.sales ?? true),
        stock: Boolean(props.settings?.categories?.stock ?? true),
        planning: Boolean(props.settings?.categories?.planning ?? true),
        billing: Boolean(props.settings?.categories?.billing ?? true),
        crm: Boolean(props.settings?.categories?.crm ?? true),
        support: Boolean(props.settings?.categories?.support ?? true),
        security: Boolean(props.settings?.categories?.security ?? true),
        emails_mirror: Boolean(props.settings?.categories?.emails_mirror ?? true),
        system: Boolean(props.settings?.categories?.system ?? true),
    },
    company_preferred_channel: companyNotificationSettings.preferred_channel || 'email',
    company_alerts: buildNotificationAlerts(companyNotificationSettings),
    company_security_two_factor_sms:
        companyNotificationSettings?.security?.two_factor_sms ?? false,
});

const channelOptions = [
    {
        key: 'in_app',
        label: 'Dans la plateforme',
        description: 'Alertes visibles dans le centre de notifications.',
    },
    {
        key: 'push',
        label: 'Notifications push',
        description: 'Alertes mobiles pour rester informe.',
    },
];

const categoryOptions = [
    {
        key: 'orders',
        label: 'Commandes',
        description: 'Nouvelles commandes, mise a jour, confirmation.',
    },
    {
        key: 'sales',
        label: 'Ventes',
        description: 'Ventes payees et activite POS.',
    },
    {
        key: 'stock',
        label: 'Stock',
        description: 'Alertes de stock bas et ruptures.',
    },
    {
        key: 'planning',
        label: 'Planning & RH',
        description: 'Absences, rappels de shift, retards, conflits.',
    },
    {
        key: 'billing',
        label: 'Facturation',
        description: 'Paiements recus, factures, acomptes.',
    },
    {
        key: 'crm',
        label: 'CRM & Leads',
        description: 'Relances de leads, devis, suivi commercial.',
    },
    {
        key: 'support',
        label: 'Support',
        description: 'Tickets et messages support.',
    },
    {
        key: 'security',
        label: 'Securite',
        description: 'Alertes critiques de securite (toujours active).',
    },
    {
        key: 'emails_mirror',
        label: 'Miroir des emails',
        description: 'Copie des emails envoyes dans les notifications.',
    },
    {
        key: 'system',
        label: 'Systeme',
        description: 'Alertes importantes sur le compte.',
    },
];

const tabPrefix = 'settings-notifications';
const tabs = [
    { id: 'channels', label: 'Canaux', description: 'Outils de reception' },
    { id: 'categories', label: 'Categories', description: 'Types d alertes' },
    ...(props.can_manage_company_notifications
        ? [{ id: 'company_delivery', label: 'Entreprise', description: 'Email, SMS, WhatsApp' }]
        : []),
    { id: 'accessibility', label: 'Accessibilite', description: 'Confort visuel' },
];

const resolveInitialTab = () => {
    if (typeof window === 'undefined') {
        return tabs[0].id;
    }
    const stored = window.sessionStorage.getItem(`${tabPrefix}-tab`);
    return tabs.some((tab) => tab.id === stored) ? stored : tabs[0].id;
};

const activeTab = ref(resolveInitialTab());

watch(activeTab, (value) => {
    if (typeof window === 'undefined') {
        return;
    }
    window.sessionStorage.setItem(`${tabPrefix}-tab`, value);
});

const accessibilityDefaults = readAccessibilityPreferences();
const textSize = ref(accessibilityDefaults.textSize);
const highContrast = ref(accessibilityDefaults.contrast === 'high');
const reduceMotion = ref(accessibilityDefaults.reduceMotion);

const textSizeOptions = [
    { value: 'sm', label: 'Petit' },
    { value: 'md', label: 'Normal' },
    { value: 'lg', label: 'Grand' },
];

const applyAccessibility = () => {
    const prefs = {
        textSize: textSize.value,
        contrast: highContrast.value ? 'high' : 'normal',
        reduceMotion: reduceMotion.value,
    };
    writeAccessibilityPreferences(prefs);
    applyAccessibilityPreferences(prefs);
};

watch([textSize, highContrast, reduceMotion], () => {
    applyAccessibility();
}, { immediate: true });

const saveLabel = computed(() => (form.processing ? 'Enregistrement...' : 'Enregistrer'));
const isDisabled = computed(() => form.processing || !form.isDirty);
const whatsappStatusLabel = computed(() => (
    props.whatsapp_configured ? 'WhatsApp configure' : 'WhatsApp non configure'
));
const whatsappStatusClass = computed(() => (
    props.whatsapp_configured
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200'
        : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200'
));

const submit = () => {
    form
        .transform((data) => {
            const payload = {
                channels: data.channels,
                categories: data.categories,
            };

            if (props.can_manage_company_notifications) {
                const notificationAlerts = notificationAlertKeys.reduce((carry, key) => {
                    const channels = data.company_alerts?.[key] || {};
                    carry[key] = {
                        email: Boolean(channels.email),
                        sms: Boolean(channels.sms),
                        whatsapp: Boolean(channels.whatsapp),
                    };

                    return carry;
                }, {});

                payload.company_notification_settings = {
                    preferred_channel: data.company_preferred_channel || 'email',
                    alerts: notificationAlerts,
                    task_day: notificationAlerts.task_day,
                    task_updates: notificationAlerts.task_updates,
                    security: {
                        two_factor_sms: Boolean(data.company_security_two_factor_sms),
                    },
                };
            }

            return payload;
        })
        .put(route('settings.notifications.update'), { preserveScroll: true });
};
</script>

<template>
    <Head title="Notifications" />

    <SettingsLayout active="notifications" content-class="w-[1400px] max-w-full">
        <div class="w-full space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        Parametres notifications
                    </h1>
                    <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                        Choisissez les alertes que vous recevez.
                    </p>
                </div>
            </div>

            <SettingsTabs
                v-model="activeTab"
                :tabs="tabs"
                :id-prefix="tabPrefix"
                aria-label="Sections des notifications"
            />

            <div
                v-show="activeTab === 'channels'"
                :id="`${tabPrefix}-panel-channels`"
                role="tabpanel"
                :aria-labelledby="`${tabPrefix}-tab-channels`"
                class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            >
                <div class="border-b border-stone-200 px-4 py-3 dark:border-neutral-700">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Canaux</h2>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        Activez ou desactivez les canaux de notification.
                    </p>
                </div>
                <div class="space-y-3 p-4">
                    <label
                        v-for="channel in channelOptions"
                        :key="channel.key"
                        class="flex items-start gap-3 rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-800 dark:bg-neutral-800/70 dark:text-neutral-200"
                    >
                        <input type="checkbox" v-model="form.channels[channel.key]" class="mt-1" />
                        <span>
                            <span class="block font-semibold">{{ channel.label }}</span>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">{{ channel.description }}</span>
                        </span>
                    </label>
                    <div class="flex justify-end">
                        <button
                            type="button"
                            class="inline-flex items-center rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                            :disabled="isDisabled"
                            @click="submit"
                        >
                            {{ saveLabel }}
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-show="activeTab === 'categories'"
                :id="`${tabPrefix}-panel-categories`"
                role="tabpanel"
                :aria-labelledby="`${tabPrefix}-tab-categories`"
                class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            >
                <div class="border-b border-stone-200 px-4 py-3 dark:border-neutral-700">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Categories</h2>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        Selectionnez les types d alertes que vous souhaitez recevoir.
                    </p>
                </div>
                <div class="space-y-3 p-4">
                    <label
                        v-for="category in categoryOptions"
                        :key="category.key"
                        class="flex items-start gap-3 rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-800 dark:bg-neutral-800/70 dark:text-neutral-200"
                    >
                        <input
                            type="checkbox"
                            v-model="form.categories[category.key]"
                            class="mt-1"
                            :disabled="category.key === 'security'"
                        />
                        <span>
                            <span class="block font-semibold">{{ category.label }}</span>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ category.description }}
                            </span>
                        </span>
                    </label>
                    <div class="flex justify-end">
                        <button
                            type="button"
                            class="inline-flex items-center rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                            :disabled="isDisabled"
                            @click="submit"
                        >
                            {{ saveLabel }}
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-if="can_manage_company_notifications"
                v-show="activeTab === 'company_delivery'"
                :id="`${tabPrefix}-panel-company_delivery`"
                role="tabpanel"
                :aria-labelledby="`${tabPrefix}-tab-company_delivery`"
                class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            >
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-stone-200 px-4 py-3 dark:border-neutral-700">
                    <div>
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('settings.company.notifications.title') }}
                        </h2>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('settings.company.notifications.description') }}
                        </p>
                    </div>
                    <span
                        class="rounded-full border px-2.5 py-1 text-[11px] font-semibold"
                        :class="whatsappStatusClass"
                    >
                        {{ whatsappStatusLabel }}
                    </span>
                </div>

                <div class="space-y-5 p-4">
                    <div>
                        <p class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">
                            {{ $t('settings.company.notifications.preferred_title') }}
                        </p>
                        <div class="mt-2 grid gap-2 md:grid-cols-3">
                            <label
                                v-for="channel in notificationChannelOptions"
                                :key="`preferred-${channel.key}`"
                                class="flex cursor-pointer items-start gap-3 rounded-sm border bg-stone-50 p-3 text-sm transition dark:bg-neutral-800/70"
                                :class="form.company_preferred_channel === channel.key
                                    ? 'border-emerald-500 ring-1 ring-emerald-500/40 dark:border-emerald-400'
                                    : 'border-stone-200 hover:border-stone-300 dark:border-neutral-800 dark:hover:border-neutral-700'"
                            >
                                <input
                                    v-model="form.company_preferred_channel"
                                    type="radio"
                                    name="company_preferred_channel"
                                    class="mt-1 accent-emerald-600"
                                    :value="channel.key"
                                />
                                <span>
                                    <span class="block font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ channel.label }}
                                    </span>
                                    <span class="mt-1 block text-xs leading-5 text-stone-500 dark:text-neutral-400">
                                        {{ channel.description }}
                                    </span>
                                </span>
                            </label>
                        </div>
                        <p class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('settings.company.notifications.preferred_description') }}
                        </p>
                    </div>

                    <div>
                        <div class="mb-2">
                            <p class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">
                                {{ $t('settings.company.notifications.matrix_title') }}
                            </p>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('settings.company.notifications.matrix_description') }}
                            </p>
                        </div>
                        <div class="overflow-x-auto rounded-sm border border-stone-200 dark:border-neutral-800">
                            <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-800">
                                <thead class="bg-stone-100 text-left text-xs font-semibold uppercase text-stone-500 dark:bg-neutral-800 dark:text-neutral-400">
                                    <tr>
                                        <th scope="col" class="w-full px-3 py-3">
                                            {{ $t('settings.company.notifications.alert_column') }}
                                        </th>
                                        <th
                                            v-for="channel in notificationChannelOptions"
                                            :key="`head-${channel.key}`"
                                            scope="col"
                                            class="px-3 py-3 text-center"
                                        >
                                            {{ channel.label }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                                    <tr
                                        v-for="alert in notificationAlertOptions"
                                        :key="alert.key"
                                        class="align-top"
                                    >
                                        <td class="min-w-[260px] px-3 py-3">
                                            <p class="font-medium text-stone-800 dark:text-neutral-100">
                                                {{ alert.label }}
                                            </p>
                                            <p class="mt-1 text-xs leading-5 text-stone-500 dark:text-neutral-400">
                                                {{ alert.description }}
                                            </p>
                                        </td>
                                        <td
                                            v-for="channel in notificationChannelOptions"
                                            :key="`${alert.key}-${channel.key}`"
                                            class="px-3 py-3 text-center"
                                        >
                                            <input
                                                v-model="form.company_alerts[alert.key][channel.key]"
                                                type="checkbox"
                                                class="h-4 w-4 rounded border-stone-300 accent-emerald-600"
                                                :aria-label="`${alert.label} ${channel.label}`"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('settings.company.notifications.delivery_hint') }}
                        </p>
                    </div>

                    <div class="border-t border-stone-200 pt-3 dark:border-neutral-800">
                        <label class="flex items-start gap-2 text-sm text-stone-700 dark:text-neutral-200">
                            <input
                                v-model="form.company_security_two_factor_sms"
                                type="checkbox"
                                class="mt-1 accent-emerald-600"
                            />
                            <span>
                                <span class="block font-medium">
                                    {{ $t('settings.company.notifications.two_factor_sms') }}
                                </span>
                                <span class="mt-1 block text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('settings.company.notifications.two_factor_sms_hint') }}
                                </span>
                            </span>
                        </label>
                    </div>

                    <div class="flex justify-end">
                        <button
                            type="button"
                            class="inline-flex items-center rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                            :disabled="isDisabled"
                            @click="submit"
                        >
                            {{ saveLabel }}
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-show="activeTab === 'accessibility'"
                :id="`${tabPrefix}-panel-accessibility`"
                role="tabpanel"
                :aria-labelledby="`${tabPrefix}-tab-accessibility`"
                class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            >
                <div class="border-b border-stone-200 px-4 py-3 dark:border-neutral-700">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Accessibilite</h2>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        Ajustez le texte, le contraste et les animations.
                    </p>
                </div>
                <div class="space-y-3 p-4">
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-800 dark:bg-neutral-800/70 dark:text-neutral-200">
                        <FloatingSelect
                            v-model="textSize"
                            label="Taille du texte"
                            :options="textSizeOptions"
                            dense
                        />
                    </div>

                    <label class="flex items-start gap-3 rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-800 dark:bg-neutral-800/70 dark:text-neutral-200">
                        <input type="checkbox" v-model="highContrast" class="mt-1" />
                        <span>
                            <span class="block font-semibold">Contraste eleve</span>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                Ameliore la lisibilite du texte.
                            </span>
                        </span>
                    </label>

                    <label class="flex items-start gap-3 rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-800 dark:bg-neutral-800/70 dark:text-neutral-200">
                        <input type="checkbox" v-model="reduceMotion" class="mt-1" />
                        <span>
                            <span class="block font-semibold">Reduire les animations</span>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                Limite les effets visuels non essentiels.
                            </span>
                        </span>
                    </label>
                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                        Ces preferences sont appliquees automatiquement.
                    </div>
                </div>
            </div>
        </div>
    </SettingsLayout>
</template>

