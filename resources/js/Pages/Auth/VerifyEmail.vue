<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    status: {
        type: String,
    },
});

const { t } = useI18n();

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <GuestLayout>
        <Head :title="t('auth_pages.verify_email.title')" />

        <div class="mb-4 text-sm text-stone-600 dark:text-neutral-400">
            {{ t('auth_pages.verify_email.description') }}
        </div>

        <div
            class="mb-4 text-sm font-medium text-green-600 dark:text-green-400"
            v-if="verificationLinkSent"
        >
            {{ t('auth_pages.verify_email.resent') }}
        </div>

        <form @submit.prevent="submit">
            <div class="mt-4 flex items-center justify-between">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ t('auth_pages.verify_email.submit') }}
                </PrimaryButton>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="rounded-sm text-sm text-stone-600 underline hover:text-stone-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-stone-100 dark:text-neutral-400 dark:hover:text-neutral-200 dark:focus:ring-indigo-400 dark:focus:ring-offset-neutral-900"
                    >{{ t('auth_pages.verify_email.logout') }}</Link
                >
            </div>
        </form>
    </GuestLayout>
</template>
