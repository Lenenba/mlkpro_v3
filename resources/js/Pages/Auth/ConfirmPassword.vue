<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const page = usePage();
const usernameValue = page.props.auth?.user?.email || page.props.auth?.user?.name || '';
const { t } = useI18n();

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head :title="t('auth_pages.confirm_password.title')" />

        <div class="mb-4 text-sm text-stone-600 dark:text-neutral-400">
            {{ t('auth_pages.confirm_password.description') }}
        </div>

        <form @submit.prevent="submit">
            <input
                type="text"
                class="sr-only"
                tabindex="-1"
                autocomplete="username"
                :value="usernameValue"
                aria-hidden="true"
            />
            <div>
                <InputLabel for="password" :value="t('auth_pages.confirm_password.password')" />
                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4 flex justify-end">
                <PrimaryButton
                    class="ms-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ t('auth_pages.confirm_password.submit') }}
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
