<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const passwordInput = ref(null);
const currentPasswordInput = ref(null);

const page = usePage();
const { t } = useI18n();
const usernameValue = page.props.auth?.user?.email || page.props.auth?.user?.name || '';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value.focus();
            }
            if (form.errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value.focus();
            }
        },
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-stone-900 dark:text-neutral-100">
                {{ t('account.profile_page.password.title') }}
            </h2>

            <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                {{ t('account.profile_page.password.subtitle') }}
            </p>
        </header>

        <form @submit.prevent="updatePassword" class="mt-6 space-y-6" :aria-busy="form.processing">
            <input
                type="text"
                class="sr-only"
                tabindex="-1"
                autocomplete="username"
                :value="usernameValue"
                aria-hidden="true"
            />
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <InputLabel for="current_password" :value="t('account.profile_page.password.current')" />

                    <TextInput
                        id="current_password"
                        ref="currentPasswordInput"
                        v-model="form.current_password"
                        type="password"
                        class="mt-1 block w-full"
                        autocomplete="current-password"
                    />

                    <InputError
                        :message="form.errors.current_password"
                        class="mt-2"
                    />
                </div>

                <div>
                    <InputLabel for="password" :value="t('account.profile_page.password.new')" />

                    <TextInput
                        id="password"
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        class="mt-1 block w-full"
                        autocomplete="new-password"
                    />

                    <InputError :message="form.errors.password" class="mt-2" />
                </div>

                <div class="md:col-span-2">
                    <InputLabel
                        for="password_confirmation"
                        :value="t('account.profile_page.password.confirm')"
                    />

                    <TextInput
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        class="mt-1 block w-full"
                        autocomplete="new-password"
                    />

                    <InputError
                        :message="form.errors.password_confirmation"
                        class="mt-2"
                    />
                </div>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">
                    {{ form.processing ? t('account.profile_page.actions.saving') : t('account.profile_page.actions.save') }}
                </PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-sm text-stone-600 dark:text-neutral-300"
                        role="status"
                        aria-live="polite"
                    >
                        {{ t('account.profile_page.actions.saved') }}
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
