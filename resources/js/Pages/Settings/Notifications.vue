<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
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
});

const form = useForm({
    channels: {
        in_app: Boolean(props.settings?.channels?.in_app ?? true),
        push: Boolean(props.settings?.channels?.push ?? true),
    },
    categories: {
        orders: Boolean(props.settings?.categories?.orders ?? true),
        sales: Boolean(props.settings?.categories?.sales ?? true),
        stock: Boolean(props.settings?.categories?.stock ?? true),
        system: Boolean(props.settings?.categories?.system ?? true),
    },
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
        key: 'system',
        label: 'Systeme',
        description: 'Alertes importantes sur le compte.',
    },
];

const tabPrefix = 'settings-notifications';
const tabs = [
    { id: 'channels', label: 'Canaux', description: 'Outils de reception' },
    { id: 'categories', label: 'Categories', description: 'Types d alertes' },
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

const submit = () => {
    form.put(route('settings.notifications.update'), { preserveScroll: true });
};
</script>

<template>
    <Head title="Notifications" />

    <SettingsLayout active="notifications" content-class="w-full max-w-6xl">
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
                        <input type="checkbox" v-model="form.categories[category.key]" class="mt-1" />
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
