<script setup>
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    work: Object,
    company: Object,
    allow: Object,
    actions: Object,
});

const { t, te } = useI18n();

const formatDate = (value) => humanizeDate(value) || '-';
const formatTime = (value) => {
    if (!value) {
        return '-';
    }
    const text = String(value);
    return text.length >= 5 ? text.slice(0, 5) : text;
};
const formatTimeRange = (start, end) => {
    const startLabel = formatTime(start);
    const endLabel = formatTime(end);
    if (startLabel === '-' && endLabel === '-') {
        return '-';
    }
    if (endLabel === '-') {
        return startLabel;
    }
    return `${startLabel} - ${endLabel}`;
};

const translateStatus = (status) => {
    const normalized = String(status || 'pending').trim().toLowerCase();
    const key = `public_work.status.${normalized}`;

    return te(key) ? t(key) : normalized.replace(/_/g, ' ');
};

const validateWork = () => {
    if (!props.allow?.validate) {
        return;
    }
    router.post(props.actions?.validateUrl, {}, { preserveScroll: true });
};

const disputeWork = () => {
    if (!props.allow?.dispute) {
        return;
    }
    router.post(props.actions?.disputeUrl, {}, { preserveScroll: true });
};

const confirmSchedule = () => {
    if (!props.allow?.schedule) {
        return;
    }
    router.post(props.actions?.confirmScheduleUrl, {}, { preserveScroll: true });
};

const rejectSchedule = () => {
    if (!props.allow?.schedule) {
        return;
    }
    router.post(props.actions?.rejectScheduleUrl, {}, { preserveScroll: true });
};

const companyName = () => props.company?.name || t('public_work.company_fallback');
const customerName = () => {
    const customer = props.work?.customer;
    if (!customer) {
        return t('public_work.customer_fallback');
    }

    return customer.company_name
        || `${customer.first_name || ''} ${customer.last_name || ''}`.trim()
        || t('public_work.customer_fallback');
};
</script>

<template>
    <Head :title="t('public_work.meta_title')" />

    <GuestLayout :card-class="'mt-6 w-full max-w-4xl rounded-sm border border-stone-200 bg-white px-6 py-6 shadow-md'">
        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <img
                        v-if="company?.logo_url"
                        :src="company.logo_url"
                        :alt="companyName()"
                        class="h-10 w-10 rounded-sm border border-stone-200 object-cover"
                        loading="lazy"
                        decoding="async"
                    />
                    <div>
                        <div class="text-xs uppercase tracking-wide text-stone-500">{{ t('public_work.document') }}</div>
                        <div class="text-lg font-semibold text-stone-800">
                            {{ companyName() }}
                        </div>
                    </div>
                </div>
                <span class="rounded-sm bg-stone-100 px-2 py-1 text-xs font-semibold text-stone-700">
                    {{ translateStatus(work?.status) }}
                </span>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-sm border border-stone-200 p-3 text-sm text-stone-700">
                    <div class="text-xs text-stone-500">{{ t('public_work.document') }}</div>
                    <div class="font-semibold">{{ work?.job_title || work?.number || `#${work?.id}` }}</div>
                    <div class="mt-2 text-xs text-stone-500">{{ t('public_work.schedule') }}</div>
                    <div class="text-sm">{{ formatDate(work?.start_date) }} - {{ formatDate(work?.end_date) }}</div>
                    <div class="text-xs text-stone-500">{{ formatTimeRange(work?.start_time, work?.end_time) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 p-3 text-sm text-stone-700">
                    <div class="text-xs text-stone-500">{{ t('public_work.customer') }}</div>
                    <div class="font-semibold">
                        {{ customerName() }}
                    </div>
                    <div class="mt-2 text-xs text-stone-500">{{ t('public_work.contact') }}</div>
                    <div class="text-sm">{{ work?.customer?.email || '-' }}</div>
                    <div class="text-sm">{{ work?.customer?.phone || '-' }}</div>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 p-4">
                <div class="text-sm font-semibold text-stone-800">{{ t('public_work.actions.title') }}</div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button
                        type="button"
                        :disabled="!allow?.validate"
                        @click="validateWork"
                        class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                    >
                        {{ t('public_work.actions.validate') }}
                    </button>
                    <button
                        type="button"
                        :disabled="!allow?.dispute"
                        @click="disputeWork"
                        class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-50"
                    >
                        {{ t('public_work.actions.dispute') }}
                    </button>
                    <a
                        v-if="allow?.proofs && actions?.proofsUrl"
                        :href="actions.proofsUrl"
                        class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50"
                    >
                        {{ t('public_work.actions.proofs') }}
                    </a>
                </div>
                <div v-if="!allow?.validate && !allow?.dispute" class="mt-2 text-xs text-stone-500">
                    {{ t('public_work.actions.none') }}
                </div>
            </div>

            <div v-if="allow?.schedule" class="rounded-sm border border-stone-200 p-4">
                <div class="text-sm font-semibold text-stone-800">{{ t('public_work.schedule_confirmation.title') }}</div>
                <p class="mt-2 text-xs text-stone-500">
                    {{ t('public_work.schedule_confirmation.description') }}
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button
                        type="button"
                        @click="rejectSchedule"
                        class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50"
                    >
                        {{ t('public_work.schedule_confirmation.request_changes') }}
                    </button>
                    <button
                        type="button"
                        @click="confirmSchedule"
                        class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-xs font-medium text-white hover:bg-emerald-700"
                    >
                        {{ t('public_work.schedule_confirmation.accept') }}
                    </button>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
