<script setup>
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});
</script>

<template>
    <Head title="Profil" />

    <SettingsLayout active="profile">
        <div class="w-full max-w-4xl space-y-5">
            <div>
                <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Profil</h1>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    Gere vos informations de compte et la securite.
                </p>
            </div>

            <div class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                <div class="p-4">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>
            </div>

            <div class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                <div class="p-4">
                    <UpdatePasswordForm class="max-w-xl" />
                </div>
            </div>

            <div class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                <div class="p-4">
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </SettingsLayout>
</template>
