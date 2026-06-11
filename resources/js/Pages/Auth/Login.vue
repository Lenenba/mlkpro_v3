<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SocialAuthButtons from '@/Components/Auth/SocialAuthButtons.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { nextTick, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    authContext: {
        type: Object,
        default: () => ({
            source: 'login',
            plan: null,
            billing_period: null,
        }),
    },
    socialCreatePrompt: {
        type: Object,
        default: null,
    },
});

const { t } = useI18n();

const form = useForm({
    email: '',
    password: '',
    remember: false,
    source: props.authContext?.source || 'login',
    plan: props.authContext?.plan || null,
    billing_period: props.authContext?.billing_period || null,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};

// Social login: when no account matches the verified provider profile, the
// backend stashes a candidate and sends this prompt so the user can confirm.
// The Modal opens on a false -> true transition, so we flip it after mount
// (it can't be initialised to true, the dialog would never call showModal()).
const showSocialPrompt = ref(false);

onMounted(async () => {
    if (props.socialCreatePrompt) {
        await nextTick();
        showSocialPrompt.value = true;
    }
});

const confirmForm = useForm({
    token: props.socialCreatePrompt?.token || '',
});

const confirmSocialCreate = () => {
    if (!props.socialCreatePrompt) {
        return;
    }

    confirmForm.post(props.socialCreatePrompt.confirm_url);
};

const cancelSocialCreate = () => {
    showSocialPrompt.value = false;
};
</script>

<template>
    <GuestLayout>
        <Head :title="t('auth_pages.login.title')" />

        <div v-if="props.status" class="mb-4 text-sm font-medium text-green-600 dark:text-green-400">
            {{ props.status }}
        </div>

        <SocialAuthButtons source="login" :query="props.authContext" />

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="email" :value="t('auth_pages.login.email')" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" :value="t('auth_pages.login.password')" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4 block">
                <label class="flex items-center">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="ms-2 text-sm text-stone-600 dark:text-neutral-400"
                        >{{ t('auth_pages.login.remember') }}</span
                    >
                </label>
            </div>

            <div class="mt-4 flex items-center justify-end">
                <Link
                    v-if="props.canResetPassword"
                    :href="route('password.request')"
                    class="rounded-sm text-sm text-stone-600 underline hover:text-stone-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-stone-100 dark:text-neutral-400 dark:hover:text-neutral-200 dark:focus:ring-indigo-400 dark:focus:ring-offset-neutral-900"
                >
                    {{ t('auth_pages.login.forgot_password') }}
                </Link>

                <PrimaryButton
                    class="ms-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ t('auth_pages.login.submit') }}
                </PrimaryButton>
            </div>
        </form>

        <Modal
            v-if="props.socialCreatePrompt"
            :show="showSocialPrompt"
            max-width="md"
            position="center"
            @close="cancelSocialCreate"
        >
            <div class="p-6">
                <h2 class="text-lg font-semibold text-stone-900 dark:text-neutral-100">
                    {{ t('auth_pages.social.confirm_create.title') }}
                </h2>
                <p class="mt-2 text-sm text-stone-600 dark:text-neutral-400">
                    {{ t('auth_pages.social.confirm_create.description', {
                        email: props.socialCreatePrompt.email,
                        provider: props.socialCreatePrompt.provider_label,
                    }) }}
                </p>

                <div class="mt-6 flex items-center justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-sm px-3 py-2 text-sm font-medium text-stone-600 hover:text-stone-900 dark:text-neutral-400 dark:hover:text-neutral-200"
                        :disabled="confirmForm.processing"
                        @click="cancelSocialCreate"
                    >
                        {{ t('auth_pages.social.confirm_create.cancel') }}
                    </button>
                    <PrimaryButton
                        :class="{ 'opacity-25': confirmForm.processing }"
                        :disabled="confirmForm.processing"
                        @click="confirmSocialCreate"
                    >
                        {{ t('auth_pages.social.confirm_create.create') }}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    </GuestLayout>
</template>
