<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    email: {
        type: String,
        default: '',
    },
    method: {
        type: String,
        default: 'email',
    },
    phone_hint: {
        type: String,
        default: '',
    },
    expires_at: {
        type: String,
        default: null,
    },
    status: {
        type: String,
        default: '',
    },
});

const { t } = useI18n();

const form = useForm({
    code: '',
});

const resendForm = useForm({});

const isAppMethod = computed(() => props.method === 'app');
const isSmsMethod = computed(() => props.method === 'sms');

const expiresInMinutes = computed(() => {
    if (!props.expires_at) {
        return null;
    }
    const expires = new Date(props.expires_at);
    if (Number.isNaN(expires.getTime())) {
        return null;
    }
    const diff = Math.max(0, Math.ceil((expires.getTime() - Date.now()) / 60000));
    return diff || 1;
});

const submit = () => {
    form.post(route('two-factor.verify'), {
        onFinish: () => form.reset('code'),
    });
};

const resend = () => {
    resendForm.post(route('two-factor.resend'));
};
</script>

<template>
    <GuestLayout>
        <Head :title="t('two_factor.title')" />

        <div class="space-y-4">
            <div>
                <h1 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                    {{ t('two_factor.title') }}
                </h1>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    {{
                        isAppMethod
                            ? t('two_factor.app_prompt')
                            : isSmsMethod
                                ? t('two_factor.sms_sent', { phone: phone_hint || 'phone' })
                                : t('two_factor.sent', { email: email || 'email' })
                    }}
                </p>
                <p v-if="expiresInMinutes" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                    {{ t('two_factor.expires', { minutes: expiresInMinutes }) }}
                </p>
            </div>

            <div v-if="status" class="rounded-sm bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                {{ status }}
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <InputLabel for="code" :value="t('two_factor.code_label')" />
                    <TextInput
                        id="code"
                        v-model="form.code"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        class="mt-1 block w-full"
                        :placeholder="t('two_factor.code_placeholder')"
                        autofocus
                    />
                    <InputError class="mt-2" :message="form.errors.code" />
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <PrimaryButton :disabled="form.processing" :class="{ 'opacity-25': form.processing }">
                        {{ t('two_factor.submit') }}
                    </PrimaryButton>
                    <button
                        v-if="!isAppMethod"
                        type="button"
                        @click="resend"
                        class="text-sm font-medium text-stone-600 underline hover:text-stone-900 dark:text-neutral-400 dark:hover:text-neutral-200"
                        :disabled="resendForm.processing"
                    >
                        {{ t('two_factor.resend') }}
                    </button>
                </div>
            </form>
        </div>
    </GuestLayout>
</template>
