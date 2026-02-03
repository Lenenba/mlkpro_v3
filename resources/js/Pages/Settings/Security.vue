<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import SettingsTabs from '@/Components/SettingsTabs.vue';
import InputError from '@/Components/InputError.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    two_factor: {
        type: Object,
        required: true,
    },
    activity: {
        type: Array,
        required: true,
    },
    can_view_team: {
        type: Boolean,
        default: false,
    },
    rate_limit: {
        type: [Number, String],
        default: null,
    },
});

const { t, locale } = useI18n();

const tabs = computed(() => {
    locale.value;
    return [
        {
            id: 'auth',
            label: t('settings.security.tabs.auth.label'),
            description: t('settings.security.tabs.auth.description'),
        },
        {
            id: 'activity',
            label: t('settings.security.tabs.activity.label'),
            description: t('settings.security.tabs.activity.description'),
        },
    ];
});

const tabPrefix = 'settings-security';
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

const twoFactorRequired = computed(() => Boolean(props.two_factor?.required));
const twoFactorEnabled = computed(() => Boolean(props.two_factor?.enabled));
const twoFactorMethod = computed(() => props.two_factor?.method || 'email');
const twoFactorHasApp = computed(() => Boolean(props.two_factor?.has_app));
const twoFactorCanConfigure = computed(() => Boolean(props.two_factor?.can_configure));
const twoFactorSetup = computed(() => props.two_factor?.app_setup || null);
const twoFactorDisplayMethod = computed(() => (twoFactorSetup.value ? 'app' : twoFactorMethod.value));

const startAppForm = useForm({});
const confirmAppForm = useForm({ code: '' });
const cancelAppForm = useForm({});
const switchEmailForm = useForm({});

const qrImageUrl = computed(() => {
    if (!twoFactorSetup.value?.otpauth_url) {
        return null;
    }
    return `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encodeURIComponent(
        twoFactorSetup.value.otpauth_url
    )}`;
});

const twoFactorStatus = computed(() => {
    if (twoFactorRequired.value && twoFactorEnabled.value) {
        return {
            label: t('settings.security.two_factor.status_required'),
            class: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200',
        };
    }
    if (twoFactorEnabled.value) {
        return {
            label: t('settings.security.two_factor.status_enabled'),
            class: 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200',
        };
    }
    return {
        label: t('settings.security.two_factor.status_disabled'),
        class: 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200',
    };
});

const resolveTranslation = (key, fallback) => {
    const translated = t(key);
    return translated === key ? fallback : translated;
};

const actionLabel = (entry) => {
    const key = `settings.security.activity.actions[\"${entry.action}\"]`;
    return resolveTranslation(key, entry.action);
};

const channelLabel = (entry) => {
    if (!entry.channel) {
        return '-';
    }
    const key = `settings.security.activity.channels.${entry.channel}`;
    return resolveTranslation(key, entry.channel);
};

const formatUserAgent = (value) => {
    if (!value) {
        return '';
    }
    const cleaned = String(value).replace(/\s+/g, ' ').trim();
    if (cleaned.length <= 60) {
        return cleaned;
    }
    return `${cleaned.slice(0, 57)}...`;
};

const deviceLabel = (entry) => {
    if (entry.device) {
        return entry.device;
    }
    if (entry.user_agent) {
        return formatUserAgent(entry.user_agent);
    }
    return t('settings.security.activity.unknown_device');
};

const formatDate = (value) => humanizeDate(value) || '-';

const rateLimitLabel = computed(() => {
    if (!props.rate_limit) {
        return t('settings.security.rate_limit.unavailable');
    }
    return t('settings.security.rate_limit.value', { limit: props.rate_limit });
});

const startAuthenticatorSetup = () => {
    startAppForm.post(route('settings.security.2fa.app.start'), { preserveScroll: true });
};

const confirmAuthenticatorSetup = () => {
    confirmAppForm.post(route('settings.security.2fa.app.confirm'), {
        preserveScroll: true,
        onFinish: () => confirmAppForm.reset('code'),
    });
};

const cancelAuthenticatorSetup = () => {
    cancelAppForm.post(route('settings.security.2fa.app.cancel'), { preserveScroll: true });
};

const switchToEmail = () => {
    switchEmailForm.post(route('settings.security.2fa.email'), { preserveScroll: true });
};
</script>

<template>
    <Head :title="t('settings.security.meta_title')" />

    <SettingsLayout active="security" content-class="w-full max-w-6xl">
        <div class="w-full space-y-4">
            <div>
                <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                    {{ t('settings.security.title') }}
                </h1>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    {{ t('settings.security.subtitle') }}
                </p>
            </div>

            <SettingsTabs
                v-model="activeTab"
                :tabs="tabs"
                :id-prefix="tabPrefix"
                aria-label="Sections de securite"
            />

            <div
                v-show="activeTab === 'auth'"
                :id="`${tabPrefix}-panel-auth`"
                role="tabpanel"
                :aria-labelledby="`${tabPrefix}-tab-auth`"
                class="space-y-4"
            >
                <div class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="border-b border-stone-200 px-4 py-3 dark:border-neutral-700">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ t('settings.security.two_factor.title') }}
                            </h2>
                            <span
                                class="rounded-full px-2 py-1 text-[11px] font-semibold"
                                :class="twoFactorStatus.class"
                            >
                                {{ twoFactorStatus.label }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('settings.security.two_factor.description') }}
                        </p>
                    </div>
                    <div class="space-y-3 p-4 text-sm text-stone-700 dark:text-neutral-200">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ t('settings.security.two_factor.email_label') }}
                                </div>
                                <div class="font-semibold">{{ two_factor.email || '-' }}</div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ t('settings.security.two_factor.last_sent') }}
                                </div>
                                <div class="font-semibold">
                                    {{ two_factor.last_sent_at ? formatDate(two_factor.last_sent_at) : t('settings.security.two_factor.last_sent_empty') }}
                                </div>
                            </div>
                        </div>
                        <div v-if="twoFactorCanConfigure" class="space-y-3 rounded-sm border border-stone-100 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300">
                            <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                {{ t('settings.security.two_factor.method_title') }}
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <span
                                    class="rounded-full px-2 py-1 text-[11px] font-semibold"
                                    :class="twoFactorDisplayMethod === 'app'
                                        ? 'bg-purple-100 text-purple-700 dark:bg-purple-500/20 dark:text-purple-200'
                                        : 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-200'"
                                >
                                    {{ twoFactorDisplayMethod === 'app' ? t('settings.security.two_factor.method_app') : t('settings.security.two_factor.method_email') }}
                                </span>
                                <span v-if="twoFactorMethod === 'app' && twoFactorHasApp" class="text-[11px] text-emerald-600">
                                    {{ t('settings.security.two_factor.method_ready') }}
                                </span>
                            </div>

                            <div v-if="twoFactorSetup" class="space-y-3">
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ t('settings.security.two_factor.app_setup_hint') }}
                                </div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <img v-if="qrImageUrl" :src="qrImageUrl" :alt="t('settings.security.two_factor.app_qr_alt')" class="h-28 w-28 rounded-sm border border-stone-200 bg-white" />
                                    <div class="text-xs">
                                        <div class="text-stone-500 dark:text-neutral-400">{{ t('settings.security.two_factor.app_secret_label') }}</div>
                                        <div class="mt-1 font-mono text-sm text-stone-700 dark:text-neutral-200">{{ twoFactorSetup.secret }}</div>
                                    </div>
                                </div>
                                <form @submit.prevent="confirmAuthenticatorSetup" class="space-y-2">
                                    <input
                                        v-model="confirmAppForm.code"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="one-time-code"
                                        class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                        :placeholder="t('settings.security.two_factor.app_code_placeholder')"
                                    />
                                    <InputError class="mt-1" :message="confirmAppForm.errors.code" />
                                    <div class="flex flex-wrap gap-2">
                                        <button type="submit"
                                            class="rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700">
                                            {{ t('settings.security.two_factor.app_confirm') }}
                                        </button>
                                        <button type="button" @click="cancelAuthenticatorSetup"
                                            class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-600 hover:bg-stone-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                            {{ t('settings.security.two_factor.app_cancel') }}
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <div v-else class="flex flex-wrap gap-2">
                                <button
                                    v-if="twoFactorMethod !== 'app'"
                                    type="button"
                                    @click="startAuthenticatorSetup"
                                    class="rounded-sm bg-purple-600 px-3 py-2 text-xs font-semibold text-white hover:bg-purple-700"
                                >
                                    {{ t('settings.security.two_factor.app_start') }}
                                </button>
                                <button
                                    v-if="twoFactorMethod === 'app'"
                                    type="button"
                                    @click="switchToEmail"
                                    class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-600 hover:bg-stone-100 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                >
                                    {{ t('settings.security.two_factor.switch_email') }}
                                </button>
                            </div>
                        </div>
                        <p v-if="!twoFactorRequired" class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('settings.security.two_factor.required_hint') }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ t('settings.security.password.title') }}
                        </div>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('settings.security.password.description') }}
                        </p>
                        <Link
                            :href="route('profile.edit')"
                            class="mt-3 inline-flex items-center rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700"
                        >
                            {{ t('settings.security.password.action') }}
                        </Link>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ t('settings.security.rate_limit.title') }}
                        </div>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('settings.security.rate_limit.description') }}
                        </p>
                        <div class="mt-3 text-sm font-semibold text-stone-700 dark:text-neutral-200">
                            {{ rateLimitLabel }}
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-show="activeTab === 'activity'"
                :id="`${tabPrefix}-panel-activity`"
                role="tabpanel"
                :aria-labelledby="`${tabPrefix}-tab-activity`"
                class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            >
                <div class="border-b border-stone-200 px-4 py-3 dark:border-neutral-700">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ t('settings.security.activity.title') }}
                    </h2>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ t('settings.security.activity.description') }}
                    </p>
                </div>
                <div class="p-4">
                    <div v-if="!activity.length" class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('settings.security.activity.empty') }}
                    </div>
                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-xs uppercase text-stone-400">
                                <tr class="border-b border-stone-200 dark:border-neutral-700">
                                    <th
                                        v-if="can_view_team"
                                        scope="col"
                                        class="px-3 py-2 text-left font-semibold"
                                    >
                                        {{ t('settings.security.activity.columns.user') }}
                                    </th>
                                    <th scope="col" class="px-3 py-2 text-left font-semibold">
                                        {{ t('settings.security.activity.columns.event') }}
                                    </th>
                                    <th scope="col" class="px-3 py-2 text-left font-semibold">
                                        {{ t('settings.security.activity.columns.channel') }}
                                    </th>
                                    <th scope="col" class="px-3 py-2 text-left font-semibold">
                                        {{ t('settings.security.activity.columns.ip') }}
                                    </th>
                                    <th scope="col" class="px-3 py-2 text-left font-semibold">
                                        {{ t('settings.security.activity.columns.device') }}
                                    </th>
                                    <th scope="col" class="px-3 py-2 text-left font-semibold">
                                        {{ t('settings.security.activity.columns.date') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="entry in activity"
                                    :key="entry.id"
                                    class="border-b border-stone-100 text-stone-700 last:border-b-0 dark:border-neutral-800 dark:text-neutral-200"
                                >
                                    <td v-if="can_view_team" class="px-3 py-3">
                                        <div class="font-semibold">
                                            {{ entry.subject?.name || entry.subject?.email || '-' }}
                                        </div>
                                        <div v-if="entry.subject?.email" class="text-xs text-stone-400">
                                            {{ entry.subject.email }}
                                        </div>
                                    </td>
                                    <td class="px-3 py-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-semibold">
                                                {{ actionLabel(entry) }}
                                            </span>
                                            <span
                                                v-if="entry.two_factor"
                                                class="rounded-full bg-emerald-100 px-2 py-1 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200"
                                            >
                                                {{ t('settings.security.activity.badges.two_factor') }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3">
                                        <span class="rounded-full bg-stone-100 px-2 py-1 text-[10px] font-semibold text-stone-600 dark:bg-neutral-700 dark:text-neutral-200">
                                            {{ channelLabel(entry) }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3">
                                        {{ entry.ip || '-' }}
                                    </td>
                                    <td class="px-3 py-3">
                                        {{ deviceLabel(entry) }}
                                    </td>
                                    <td class="px-3 py-3">
                                        {{ formatDate(entry.created_at) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </SettingsLayout>
</template>
