<script setup>
import { ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import SettingsTabs from '@/Components/SettingsTabs.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const tabPrefix = 'settings-profile';
const tabs = [
    { id: 'profile', label: 'Identite', description: 'Nom et email' },
    { id: 'security', label: 'Mot de passe', description: 'Mise a jour' },
    { id: 'danger', label: 'Suppression', description: 'Fermer le compte' },
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
</script>

<template>
    <Head title="Profil" />

    <SettingsLayout active="profile" content-class="w-full max-w-6xl">
        <div class="w-full space-y-5">
            <div>
                <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Profil</h1>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    Gere vos informations de compte et la securite.
                </p>
            </div>

            <SettingsTabs
                v-model="activeTab"
                :tabs="tabs"
                :id-prefix="tabPrefix"
                aria-label="Sections du profil"
            />

            <div
                v-show="activeTab === 'profile'"
                :id="`${tabPrefix}-panel-profile`"
                role="tabpanel"
                :aria-labelledby="`${tabPrefix}-tab-profile`"
                class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700"
            >
                <div class="p-4">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="w-full"
                    />
                </div>
            </div>

            <div
                v-show="activeTab === 'security'"
                :id="`${tabPrefix}-panel-security`"
                role="tabpanel"
                :aria-labelledby="`${tabPrefix}-tab-security`"
                class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700"
            >
                <div class="p-4">
                    <UpdatePasswordForm class="w-full" />
                </div>
            </div>

            <div
                v-show="activeTab === 'danger'"
                :id="`${tabPrefix}-panel-danger`"
                role="tabpanel"
                :aria-labelledby="`${tabPrefix}-tab-danger`"
                class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700"
            >
                <div class="p-4">
                    <DeleteUserForm class="w-full" />
                </div>
            </div>
        </div>
    </SettingsLayout>
</template>
