<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    company: {
        type: Object,
        required: true,
    },
    submit_url: {
        type: String,
        required: true,
    },
});

const { t } = useI18n();
const contactPhone = computed(() => (props.company?.phone || '').trim());
const phoneHref = computed(() => {
    if (!contactPhone.value) {
        return '';
    }
    const sanitized = contactPhone.value.replace(/[^\d+]/g, '');
    return sanitized ? `tel:${sanitized}` : '';
});
const hasPhone = computed(() => contactPhone.value.length > 0 && phoneHref.value.length > 0);
const form = useForm({
    contact_name: '',
    contact_email: '',
    contact_phone: '',
    title: '',
    service_type: '',
    description: '',
    urgency: '',
    budget: '',
    street1: '',
    city: '',
    state: '',
    postal_code: '',
    country: '',
});

const urgencyOptions = computed(() => ([
    { id: '', name: t('requests.form.urgency_placeholder') },
    { id: 'urgent', name: t('requests.urgency.urgent') },
    { id: 'high', name: t('requests.urgency.high') },
    { id: 'medium', name: t('requests.urgency.medium') },
    { id: 'low', name: t('requests.urgency.low') },
]));

const submit = () => {
    form.post(props.submit_url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        },
    });
};
</script>

<template>
    <GuestLayout :card-class="'mt-6 w-full max-w-2xl rounded-sm border border-stone-200 bg-white px-6 py-6 shadow-md dark:border-neutral-700 dark:bg-neutral-900'">
        <Head :title="$t('requests.form.title')" />

        <div class="flex flex-col items-center gap-2 text-center">
            <img v-if="company.logo_url" :src="company.logo_url" :alt="company.name" class="h-12 w-12 rounded-sm object-contain" loading="lazy" decoding="async" />
            <div class="text-sm text-stone-500 dark:text-neutral-400">{{ company.name }}</div>
            <h1 class="text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                {{ $t('requests.form.title') }}
            </h1>
            <p class="text-sm text-stone-500 dark:text-neutral-400">
                {{ $t('requests.form.subtitle') }}
            </p>
            <div class="mt-2 flex flex-wrap items-center justify-center gap-2">
                <a
                    v-if="hasPhone"
                    :href="phoneHref"
                    class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                >
                    <span>{{ $t('requests.form.contact_phone_label') }}</span>
                    <span class="text-stone-800 dark:text-neutral-100">{{ contactPhone }}</span>
                </a>
                <a
                    href="#lead-form"
                    class="inline-flex items-center rounded-sm bg-stone-900 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-white hover:bg-stone-800 dark:bg-white dark:text-stone-900"
                >
                    {{ $t('requests.form.contact_button') }}
                </a>
            </div>
        </div>

        <form id="lead-form" class="mt-6 space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <FloatingInput v-model="form.contact_name" :label="$t('requests.form.contact_name')" />
                    <InputError class="mt-1" :message="form.errors.contact_name" />
                </div>
                <div>
                    <FloatingInput v-model="form.contact_email" type="email" :label="$t('requests.form.contact_email')" />
                    <InputError class="mt-1" :message="form.errors.contact_email" />
                </div>
                <div>
                    <FloatingInput v-model="form.contact_phone" :label="$t('requests.form.contact_phone')" />
                    <InputError class="mt-1" :message="form.errors.contact_phone" />
                </div>
                <div>
                    <FloatingInput v-model="form.service_type" :label="$t('requests.form.service_type')" />
                    <InputError class="mt-1" :message="form.errors.service_type" />
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <FloatingInput v-model="form.title" :label="$t('requests.form.title_label')" />
                <FloatingSelect v-model="form.urgency" :label="$t('requests.form.urgency')" :options="urgencyOptions" />
            </div>

            <FloatingTextarea v-model="form.description" :label="$t('requests.form.description')" />
            <InputError class="mt-1" :message="form.errors.description" />

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <FloatingInput v-model="form.budget" type="number" step="0.01" :label="$t('requests.form.budget')" />
                <div />
            </div>

            <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('requests.form.address_title') }}
                </h3>
                <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                    <FloatingInput v-model="form.street1" :label="$t('requests.form.street1')" />
                    <FloatingInput v-model="form.city" :label="$t('requests.form.city')" />
                    <FloatingInput v-model="form.state" :label="$t('requests.form.state')" />
                    <FloatingInput v-model="form.postal_code" :label="$t('requests.form.postal_code')" />
                    <FloatingInput v-model="form.country" :label="$t('requests.form.country')" />
                </div>
            </div>

            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center rounded-sm bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                >
                    {{ $t('requests.form.submit') }}
                </button>
            </div>
        </form>
    </GuestLayout>
</template>
