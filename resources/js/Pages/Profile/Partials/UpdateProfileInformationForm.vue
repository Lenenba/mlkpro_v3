<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import DropzoneInput from '@/Components/DropzoneInput.vue';
import { avatarIconPresets, defaultAvatarIcon } from '@/utils/iconPresets';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { watch } from 'vue';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const user = usePage().props.auth.user;
const isAvatarIcon = (value) => avatarIconPresets.includes(value);
const initialAvatarPath = user.profile_picture_url || user.profile_picture || '';
const initialAvatarIcon = isAvatarIcon(user.profile_picture)
    ? user.profile_picture
    : (isAvatarIcon(initialAvatarPath) ? initialAvatarPath : '');
const initialAvatarPreview = initialAvatarIcon ? '' : initialAvatarPath;

const form = useForm({
    name: user.name,
    email: user.email,
    phone_number: user.phone_number || '',
    profile_picture: initialAvatarPreview || null,
    avatar_icon: initialAvatarIcon || '',
});

const submit = () => {
    form
        .transform((data) => {
            const payload = { ...data };
            if (data.profile_picture instanceof File) {
                payload.profile_picture = data.profile_picture;
            } else {
                delete payload.profile_picture;
            }
            if (!payload.avatar_icon) {
                delete payload.avatar_icon;
            }
            return payload;
        })
        .patch(route('profile.update'));
};

const selectAvatarIcon = (icon) => {
    form.avatar_icon = icon;
    form.profile_picture = null;
};

const clearAvatarIcon = () => {
    form.avatar_icon = '';
};

watch(() => form.profile_picture, (value) => {
    if (value instanceof File) {
        form.avatar_icon = '';
    }
});
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-stone-900">
                Profile Information
            </h2>

            <p class="mt-1 text-sm text-stone-600">
                Update your account's profile information and email address.
            </p>
        </header>

        <form
            @submit.prevent="submit"
            class="mt-6 space-y-6"
        >
            <div class="space-y-2">
                <InputLabel value="Profile photo or icon" />
                <DropzoneInput v-model="form.profile_picture" label="Upload photo" />
                <InputError class="mt-2" :message="form.errors.profile_picture" />
                <p class="text-xs text-stone-500 dark:text-neutral-400">
                    Or choose an avatar icon
                </p>
                <div class="grid grid-cols-4 gap-2">
                    <button
                        v-for="icon in avatarIconPresets"
                        :key="icon"
                        type="button"
                        @click="selectAvatarIcon(icon)"
                        class="relative flex items-center justify-center rounded-full border border-stone-200 bg-white p-2 transition hover:border-green-500 dark:border-neutral-700 dark:bg-neutral-900"
                        :class="form.avatar_icon === icon ? 'ring-2 ring-green-500 border-green-500' : ''"
                    >
                        <img :src="icon" alt="Avatar icon" class="size-10" loading="lazy" decoding="async" />
                        <span
                            v-if="icon === defaultAvatarIcon"
                            class="absolute -top-1 -right-1 rounded-full bg-green-600 px-1.5 py-0.5 text-[10px] font-semibold text-white"
                        >
                            Default
                        </span>
                    </button>
                </div>
                <div v-if="form.avatar_icon" class="flex justify-end">
                    <button type="button" @click="clearAvatarIcon"
                        class="text-xs font-semibold text-stone-600 hover:text-stone-800 dark:text-neutral-400 dark:hover:text-neutral-200">
                        Clear icon
                    </button>
                </div>
                <InputError class="mt-2" :message="form.errors.avatar_icon" />
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <InputLabel for="name" value="Name" />

                    <TextInput
                        id="name"
                        type="text"
                        class="mt-1 block w-full"
                        v-model="form.name"
                        required
                        autofocus
                        autocomplete="name"
                    />

                    <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <div>
                    <InputLabel for="email" value="Email" />

                    <TextInput
                        id="email"
                        type="email"
                        class="mt-1 block w-full"
                        v-model="form.email"
                        required
                        autocomplete="username"
                    />

                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <div>
                    <InputLabel for="phone_number" value="Phone" />

                    <TextInput
                        id="phone_number"
                        type="tel"
                        class="mt-1 block w-full"
                        v-model="form.phone_number"
                        autocomplete="tel"
                        placeholder="+15145550000"
                    />

                    <InputError class="mt-2" :message="form.errors.phone_number" />
                </div>
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="mt-2 text-sm text-stone-800">
                    Your email address is unverified.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="rounded-sm text-sm text-stone-600 underline hover:text-stone-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >
                        Click here to re-send the verification email.
                    </Link>
                </p>

                <div
                    v-show="status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    A new verification link has been sent to your email address.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">Save</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-sm text-stone-600"
                    >
                        Saved.
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
