<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import FloatingInput from '@/Components/FloatingInput.vue';

const props = defineProps({
    settings: {
        type: Object,
        required: true,
    },
    available_channels: {
        type: Array,
        default: () => [],
    },
    available_categories: {
        type: Array,
        default: () => [],
    },
    digest_options: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    channels: props.settings.channels || [],
    categories: props.settings.categories || [],
    digest_frequency: props.settings.digest_frequency || 'daily',
    quiet_hours_start: props.settings.quiet_hours_start || '',
    quiet_hours_end: props.settings.quiet_hours_end || '',
    rules: {
        error_spike: props.settings.rules?.error_spike ?? 10,
        payment_failed: props.settings.rules?.payment_failed ?? 3,
        churn_risk: props.settings.rules?.churn_risk ?? 5,
    },
});

const submit = () => {
    form.put(route('superadmin.notifications.update'), { preserveScroll: true });
};
</script>

<template>
    <Head :title="$t('super_admin.notifications.page_title')" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.notifications.title') }}
                    </h1>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('super_admin.notifications.subtitle') }}
                    </p>
                </div>
            </section>

            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <form class="space-y-4" @submit.prevent="submit">
                    <div>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.notifications.channels') }}
                        </p>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <label v-for="channel in available_channels" :key="channel"
                                class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                <Checkbox v-model:checked="form.channels" :value="channel" />
                                <span>{{ channel }}</span>
                            </label>
                        </div>
                        <InputError class="mt-1" :message="form.errors.channels" />
                    </div>

                    <div>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.notifications.categories') }}
                        </p>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <label v-for="category in available_categories" :key="category"
                                class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                <Checkbox v-model:checked="form.categories" :value="category" />
                                <span>{{ category }}</span>
                            </label>
                        </div>
                        <InputError class="mt-1" :message="form.errors.categories" />
                    </div>

                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.notifications.digest_frequency') }}
                        </label>
                        <select v-model="form.digest_frequency"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option v-for="option in digest_options" :key="option" :value="option">{{ option }}</option>
                        </select>
                        <InputError class="mt-1" :message="form.errors.digest_frequency" />
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <FloatingInput v-model="form.quiet_hours_start" :label="$t('super_admin.notifications.quiet_start')" />
                            <InputError class="mt-1" :message="form.errors.quiet_hours_start" />
                        </div>
                        <div>
                            <FloatingInput v-model="form.quiet_hours_end" :label="$t('super_admin.notifications.quiet_end')" />
                            <InputError class="mt-1" :message="form.errors.quiet_hours_end" />
                        </div>
                    </div>

                    <div>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.notifications.alert_thresholds') }}
                        </p>
                        <div class="mt-2 grid gap-3 md:grid-cols-3">
                            <div>
                                <FloatingInput v-model="form.rules.error_spike" :label="$t('super_admin.notifications.rules.error_spike')" type="number" />
                                <InputError class="mt-1" :message="form.errors['rules.error_spike']" />
                            </div>
                            <div>
                                <FloatingInput v-model="form.rules.payment_failed" :label="$t('super_admin.notifications.rules.payment_failed')" type="number" />
                                <InputError class="mt-1" :message="form.errors['rules.payment_failed']" />
                            </div>
                            <div>
                                <FloatingInput v-model="form.rules.churn_risk" :label="$t('super_admin.notifications.rules.churn_risk')" type="number" />
                                <InputError class="mt-1" :message="form.errors['rules.churn_risk']" />
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" :disabled="form.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            {{ $t('super_admin.notifications.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
