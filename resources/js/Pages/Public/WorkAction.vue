<script setup>
import { computed } from 'vue';
import { Head, usePage, router } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    work: Object,
    company: Object,
    allow: Object,
    actions: Object,
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);

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

const statusLabel = (status) => (status || 'pending').replace(/_/g, ' ');

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
</script>

<template>
    <Head title="Job review" />

    <GuestLayout :card-class="'mt-6 w-full max-w-4xl rounded-sm border border-stone-200 bg-white px-6 py-6 shadow-md'">
        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <img
                        v-if="company?.logo_url"
                        :src="company.logo_url"
                        :alt="company?.name || 'Company'"
                        class="h-10 w-10 rounded-sm border border-stone-200 object-cover"
                    />
                    <div>
                        <div class="text-xs uppercase tracking-wide text-stone-500">Job</div>
                        <div class="text-lg font-semibold text-stone-800">
                            {{ company?.name || 'Company' }}
                        </div>
                    </div>
                </div>
                <span class="rounded-sm bg-stone-100 px-2 py-1 text-xs font-semibold text-stone-700">
                    {{ statusLabel(work?.status) }}
                </span>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-sm border border-stone-200 p-3 text-sm text-stone-700">
                    <div class="text-xs text-stone-500">Job</div>
                    <div class="font-semibold">{{ work?.job_title || work?.number || `#${work?.id}` }}</div>
                    <div class="mt-2 text-xs text-stone-500">Schedule</div>
                    <div class="text-sm">{{ formatDate(work?.start_date) }} - {{ formatDate(work?.end_date) }}</div>
                    <div class="text-xs text-stone-500">{{ formatTimeRange(work?.start_time, work?.end_time) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 p-3 text-sm text-stone-700">
                    <div class="text-xs text-stone-500">Customer</div>
                    <div class="font-semibold">
                        {{ work?.customer?.company_name || `${work?.customer?.first_name || ''} ${work?.customer?.last_name || ''}`.trim() || 'Customer' }}
                    </div>
                    <div class="mt-2 text-xs text-stone-500">Contact</div>
                    <div class="text-sm">{{ work?.customer?.email || '-' }}</div>
                    <div class="text-sm">{{ work?.customer?.phone || '-' }}</div>
                </div>
            </div>

            <div v-if="flashSuccess" class="rounded-sm border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                {{ flashSuccess }}
            </div>
            <div v-if="flashError" class="rounded-sm border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
                {{ flashError }}
            </div>

            <div class="rounded-sm border border-stone-200 p-4">
                <div class="text-sm font-semibold text-stone-800">Actions</div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button
                        type="button"
                        :disabled="!allow?.validate"
                        @click="validateWork"
                        class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                    >
                        Validate job
                    </button>
                    <button
                        type="button"
                        :disabled="!allow?.dispute"
                        @click="disputeWork"
                        class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-50"
                    >
                        Dispute job
                    </button>
                    <a
                        v-if="allow?.proofs && actions?.proofsUrl"
                        :href="actions.proofsUrl"
                        class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50"
                    >
                        View proofs
                    </a>
                </div>
                <div v-if="!allow?.validate && !allow?.dispute" class="mt-2 text-xs text-stone-500">
                    No validation actions are available for this job.
                </div>
            </div>

            <div v-if="allow?.schedule" class="rounded-sm border border-stone-200 p-4">
                <div class="text-sm font-semibold text-stone-800">Schedule confirmation</div>
                <p class="mt-2 text-xs text-stone-500">
                    Accepting the schedule will generate tasks for each visit.
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button
                        type="button"
                        @click="rejectSchedule"
                        class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50"
                    >
                        Request changes
                    </button>
                    <button
                        type="button"
                        @click="confirmSchedule"
                        class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-xs font-medium text-white hover:bg-emerald-700"
                    >
                        Accept schedule
                    </button>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
