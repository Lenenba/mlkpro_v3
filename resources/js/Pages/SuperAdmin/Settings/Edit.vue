<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Checkbox from '@/Components/Checkbox.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    maintenance: {
        type: Object,
        default: () => ({ enabled: false, message: '' }),
    },
    templates: {
        type: Object,
        default: () => ({ email_default: '', quote_default: '', invoice_default: '' }),
    },
});

const form = useForm({
    maintenance: {
        enabled: props.maintenance?.enabled ?? false,
        message: props.maintenance?.message ?? '',
    },
    templates: {
        email_default: props.templates?.email_default ?? '',
        quote_default: props.templates?.quote_default ?? '',
        invoice_default: props.templates?.invoice_default ?? '',
    },
});

const submit = () => {
    form.put(route('superadmin.settings.update'), { preserveScroll: true });
};
</script>

<template>
    <Head title="Platform Settings" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-4xl space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-neutral-100">Platform settings</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-neutral-400">
                    Configure global platform preferences.
                </p>
            </div>

            <div class="rounded-sm border border-gray-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">Maintenance mode</h2>
                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-neutral-200">
                        <Checkbox v-model:checked="form.maintenance.enabled" :value="true" />
                        <span>Enable maintenance banner</span>
                    </label>
                    <div>
                        <FloatingInput v-model="form.maintenance.message" label="Maintenance message" />
                        <InputError class="mt-1" :message="form.errors['maintenance.message']" />
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" :disabled="form.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            Save settings
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-sm border border-gray-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">Global templates</h2>
                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Default email template</label>
                        <textarea v-model="form.templates.email_default" rows="3"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"></textarea>
                        <InputError class="mt-1" :message="form.errors['templates.email_default']" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Default quote template</label>
                        <textarea v-model="form.templates.quote_default" rows="3"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"></textarea>
                        <InputError class="mt-1" :message="form.errors['templates.quote_default']" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Default invoice template</label>
                        <textarea v-model="form.templates.invoice_default" rows="3"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"></textarea>
                        <InputError class="mt-1" :message="form.errors['templates.invoice_default']" />
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" :disabled="form.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            Save templates
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
