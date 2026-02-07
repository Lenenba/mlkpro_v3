<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const consentCookie = 'mlk_cookie_prefs_v1';
const legacyConsentCookie = 'mlk_cookie_consent';
const consentMaxAge = 60 * 60 * 24 * 365;
const gaId = import.meta.env.VITE_GA_ID;
const gaScriptId = 'mlk-ga-script';

const bannerVisible = ref(false);
const preferencesVisible = ref(false);
const consentLoaded = ref(false);

const consent = ref({
    essential: true,
    analytics: false,
});

const consentDraft = ref({
    essential: true,
    analytics: false,
});

const { locale } = useI18n();
const isFrench = computed(() => (locale.value || 'fr').toLowerCase().startsWith('fr'));

const bannerTitle = computed(() => (isFrench.value ? 'Cookies' : 'Cookies'));
const bannerBody = computed(() =>
    isFrench.value
        ? 'Nous utilisons des cookies essentiels pour faire fonctionner la plateforme. Vous pouvez accepter ou refuser les cookies de mesure.'
        : 'We use essential cookies to run the platform. You can accept or reject analytics cookies.'
);
const acceptAllLabel = computed(() => (isFrench.value ? 'Accepter tout' : 'Accept all'));
const rejectAllLabel = computed(() => (isFrench.value ? 'Refuser tout' : 'Reject all'));
const customizeLabel = computed(() => (isFrench.value ? 'Personnaliser' : 'Customize'));
const saveLabel = computed(() => (isFrench.value ? 'Enregistrer' : 'Save'));

const modalTitle = computed(() => (isFrench.value ? 'Parametres des cookies' : 'Cookie settings'));
const modalDescription = computed(() =>
    isFrench.value
        ? 'Choisissez les cookies que vous souhaitez activer. Les cookies essentiels sont toujours actifs.'
        : 'Choose the cookies you want to enable. Essential cookies are always on.'
);
const essentialTitle = computed(() => (isFrench.value ? 'Essentiels' : 'Essential'));
const essentialDescription = computed(() =>
    isFrench.value
        ? 'Necessaires pour la connexion, la securite et le fonctionnement du site.'
        : 'Required for login, security, and core site features.'
);
const analyticsTitle = computed(() => (isFrench.value ? 'Mesure (Google Analytics)' : 'Analytics (Google Analytics)'));
const analyticsDescription = computed(() =>
    isFrench.value
        ? 'Aide a comprendre l usage pour ameliorer la plateforme.'
        : 'Helps us understand usage to improve the platform.'
);

const parseCookieValue = (value) => {
    try {
        const decoded = decodeURIComponent(value || '');
        const parsed = JSON.parse(decoded);
        if (typeof parsed !== 'object' || parsed === null) {
            return null;
        }
        return {
            essential: true,
            analytics: Boolean(parsed.analytics),
        };
    } catch (error) {
        return null;
    }
};

const readCookie = (name) => {
    if (typeof document === 'undefined') {
        return null;
    }
    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith(`${name}=`));
    if (!match) {
        return null;
    }
    return match.split('=')[1] || null;
};

const writeConsentCookie = (value) => {
    if (typeof document === 'undefined') {
        return;
    }
    const payload = encodeURIComponent(JSON.stringify(value));
    document.cookie = `${consentCookie}=${payload}; Path=/; Max-Age=${consentMaxAge}; SameSite=Lax`;
};

const applyAnalyticsConsent = (enabled) => {
    if (!gaId || typeof window === 'undefined') {
        return;
    }

    window[`ga-disable-${gaId}`] = !enabled;

    if (enabled) {
        loadAnalytics();
        if (typeof window.gtag === 'function') {
            window.gtag('consent', 'update', { analytics_storage: 'granted' });
        }
    } else if (typeof window.gtag === 'function') {
        window.gtag('consent', 'update', { analytics_storage: 'denied' });
    }
};

const loadAnalytics = () => {
    if (!gaId || typeof document === 'undefined') {
        return;
    }
    if (document.getElementById(gaScriptId)) {
        return;
    }

    const script = document.createElement('script');
    script.id = gaScriptId;
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtag/js?id=${gaId}`;
    document.head.appendChild(script);

    window.dataLayer = window.dataLayer || [];
    if (typeof window.gtag !== 'function') {
        window.gtag = function gtag() {
            window.dataLayer.push(arguments);
        };
    }
    window.gtag('js', new Date());
    window.gtag('config', gaId, { anonymize_ip: true });
};

const setConsent = (value) => {
    const next = {
        essential: true,
        analytics: Boolean(value.analytics),
    };
    consent.value = next;
    writeConsentCookie(next);
    consentLoaded.value = true;
    bannerVisible.value = false;
    preferencesVisible.value = false;
    applyAnalyticsConsent(next.analytics);
};

const acceptAll = () => setConsent({ essential: true, analytics: true });
const rejectAll = () => setConsent({ essential: true, analytics: false });

const openPreferences = () => {
    consentDraft.value = { ...consent.value };
    preferencesVisible.value = true;
    bannerVisible.value = false;
};

const closePreferences = () => {
    preferencesVisible.value = false;
    if (!consentLoaded.value) {
        bannerVisible.value = true;
    }
};

const savePreferences = () => {
    setConsent({ ...consentDraft.value });
};

const openPreferencesFromEvent = () => {
    openPreferences();
};

const hydrateConsent = () => {
    const stored = parseCookieValue(readCookie(consentCookie));
    if (stored) {
        consent.value = stored;
        consentLoaded.value = true;
        applyAnalyticsConsent(stored.analytics);
        return;
    }

    const legacy = readCookie(legacyConsentCookie);
    if (legacy) {
        setConsent({ essential: true, analytics: false });
        return;
    }

    bannerVisible.value = true;
};

onMounted(() => {
    hydrateConsent();
    if (typeof window !== 'undefined') {
        window.addEventListener('mlk-cookie-preferences', openPreferencesFromEvent);
    }
});

onBeforeUnmount(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('mlk-cookie-preferences', openPreferencesFromEvent);
    }
});
</script>

<template>
    <div
        v-if="bannerVisible"
        class="fixed inset-x-0 bottom-0 z-[110] border-t border-stone-200 bg-white/95 px-4 py-4 text-sm text-stone-700 shadow-lg backdrop-blur"
        role="status"
        aria-live="polite"
    >
        <div class="mx-auto flex max-w-5xl flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div class="space-y-1">
                <div class="text-sm font-semibold text-stone-800">
                    {{ bannerTitle }}
                </div>
                <p class="text-xs text-stone-600">
                    {{ bannerBody }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-stone-700 hover:bg-stone-50"
                    @click="rejectAll"
                >
                    {{ rejectAllLabel }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-stone-700 hover:bg-stone-50"
                    @click="openPreferences"
                >
                    {{ customizeLabel }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-white hover:bg-emerald-700"
                    @click="acceptAll"
                >
                    {{ acceptAllLabel }}
                </button>
            </div>
        </div>
    </div>

    <div v-if="preferencesVisible" class="fixed inset-0 z-[120]">
        <div class="absolute inset-0 bg-black/40" @click="closePreferences"></div>
        <div class="relative mx-auto mt-16 w-[92%] max-w-xl rounded-sm bg-white p-6 shadow-xl">
            <div class="space-y-1">
                <div class="text-base font-semibold text-stone-900">
                    {{ modalTitle }}
                </div>
                <p class="text-xs text-stone-600">
                    {{ modalDescription }}
                </p>
            </div>

            <div class="mt-5 space-y-4">
                <div class="flex items-start justify-between gap-4 rounded-sm border border-stone-200 bg-stone-50 px-4 py-3">
                    <div>
                        <div class="text-sm font-semibold text-stone-800">{{ essentialTitle }}</div>
                        <div class="text-xs text-stone-600">{{ essentialDescription }}</div>
                    </div>
                    <input type="checkbox" checked disabled class="mt-1 h-4 w-4 rounded border-stone-300" />
                </div>

                <div class="flex items-start justify-between gap-4 rounded-sm border border-stone-200 px-4 py-3">
                    <div>
                        <div class="text-sm font-semibold text-stone-800">{{ analyticsTitle }}</div>
                        <div class="text-xs text-stone-600">{{ analyticsDescription }}</div>
                    </div>
                    <input v-model="consentDraft.analytics" type="checkbox" class="mt-1 h-4 w-4 rounded border-stone-300" />
                </div>
            </div>

            <div class="mt-6 flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-stone-700 hover:bg-stone-50"
                    @click="rejectAll"
                >
                    {{ rejectAllLabel }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-stone-700 hover:bg-stone-50"
                    @click="acceptAll"
                >
                    {{ acceptAllLabel }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-white hover:bg-emerald-700"
                    @click="savePreferences"
                >
                    {{ saveLabel }}
                </button>
            </div>
        </div>
    </div>

</template>
