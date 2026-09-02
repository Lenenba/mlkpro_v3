<script setup>
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const confirmingUserDeletion = ref(false);
const passwordInput = ref(null);
const { t } = useI18n();

const form = useForm({
    password: '',
});

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;

    nextTick(() => passwordInput.value.focus());
};

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    confirmingUserDeletion.value = false;

    form.clearErrors();
    form.reset();
};
</script>

<template>
    <section class="space-y-6">
        <header>
            <h2 class="text-lg font-medium text-stone-900 dark:text-neutral-100">
                {{ t('account.profile_page.deletion.title') }}
            </h2>

            <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                {{ t('account.profile_page.deletion.subtitle') }}
            </p>
        </header>

        <DangerButton @click="confirmUserDeletion">{{ t('account.profile_page.actions.delete') }}</DangerButton>

        <Modal :show="confirmingUserDeletion" @close="closeModal">
            <div class="p-6" :aria-busy="form.processing">
                <h2
                    class="text-lg font-medium text-stone-900 dark:text-neutral-100"
                >
                    {{ t('account.profile_page.deletion.confirm_title') }}
                </h2>

                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    {{ t('account.profile_page.deletion.confirm_body') }}
                </p>

                <div class="mt-6">
                    <InputLabel
                        for="password"
                        :value="t('account.profile_page.deletion.password')"
                        class="sr-only"
                    />

                    <TextInput
                        id="password"
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        class="mt-1 block w-full"
                        :placeholder="t('account.profile_page.deletion.password')"
                        :disabled="form.processing"
                        @keyup.enter="deleteUser"
                    />

                    <InputError :message="form.errors.password" class="mt-2" />
                </div>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton :disabled="form.processing" @click="closeModal">
                        {{ t('account.profile_page.actions.cancel') }}
                    </SecondaryButton>

                    <DangerButton
                        class="ms-3"
                        :class="{ 'opacity-25': form.processing }"
                        :disabled="form.processing"
                        @click="deleteUser"
                    >
                        {{ form.processing ? t('account.profile_page.actions.deleting') : t('account.profile_page.actions.delete') }}
                    </DangerButton>
                </div>
            </div>
        </Modal>
    </section>
</template>
