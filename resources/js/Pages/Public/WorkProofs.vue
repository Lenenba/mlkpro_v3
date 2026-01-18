<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { humanizeDate } from '@/utils/date';
import { prepareMediaFile, MEDIA_LIMITS } from '@/utils/media';

const props = defineProps({
    work: Object,
    company: Object,
    customer: Object,
    tasks: {
        type: Array,
        default: () => [],
    },
    allowUpload: Boolean,
    uploadBlockedMessage: String,
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);
const { t } = useI18n();

const proofOpen = ref(false);
const proofTask = ref(null);
const form = useForm({
    type: 'execution',
    file: null,
    note: '',
});

const openProof = (task) => {
    if (!props.allowUpload || !task?.upload_url) {
        return;
    }
    proofTask.value = task;
    form.reset();
    form.clearErrors();
    proofOpen.value = true;
};

const closeProof = () => {
    proofOpen.value = false;
    proofTask.value = null;
};

const handleFile = async (event) => {
    const file = event.target.files?.[0] || null;
    form.clearErrors('file');
    if (!file) {
        form.file = null;
        return;
    }
    const result = await prepareMediaFile(file, {
        maxImageBytes: MEDIA_LIMITS.maxImageBytes,
        maxVideoBytes: MEDIA_LIMITS.maxVideoBytes,
    });
    if (result.error) {
        form.setError('file', result.error);
        form.file = null;
        return;
    }
    form.file = result.file;
};

const submitProof = () => {
    const uploadUrl = proofTask.value?.upload_url;
    if (!uploadUrl || form.processing) {
        return;
    }
    form.post(uploadUrl, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            closeProof();
        },
    });
};

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

const statusLabel = (status) => {
    const normalized = status || 'todo';
    const labels = {
        todo: t('work_proofs.status.todo'),
        in_progress: t('work_proofs.status.in_progress'),
        done: t('work_proofs.status.done'),
    };
    return labels[normalized] || normalized.replace(/_/g, ' ');
};
const statusClass = (status) => {
    switch (status) {
        case 'done':
            return 'bg-emerald-100 text-emerald-800';
        case 'in_progress':
            return 'bg-blue-100 text-blue-800';
        default:
            return 'bg-stone-100 text-stone-700';
    }
};
const proofSource = (source) => {
    if (source === 'client' || source === 'client-public') {
        return t('work_proofs.source.client');
    }
    if (source === 'team') {
        return t('work_proofs.source.team');
    }
    return t('work_proofs.source.internal');
};
const proofType = (type) => {
    if (type === 'execution') {
        return t('work_proofs.type.execution');
    }
    if (type === 'completion') {
        return t('work_proofs.type.completion');
    }
    return t('work_proofs.type.other');
};
</script>

<template>
    <Head :title="$t('work_proofs.title')" />

    <GuestLayout :card-class="'mt-6 w-full max-w-5xl rounded-sm border border-stone-200 bg-white px-6 py-6 shadow-md'">
        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <img
                        v-if="company?.logo_url"
                        :src="company.logo_url"
                        :alt="company?.name || $t('work_proofs.company_fallback')"
                        class="h-10 w-10 rounded-sm border border-stone-200 object-cover"
                    />
                    <div>
                        <div class="text-xs uppercase tracking-wide text-stone-500">{{ $t('work_proofs.title') }}</div>
                        <div class="text-lg font-semibold text-stone-800">
                            {{ company?.name || $t('work_proofs.company_fallback') }}
                        </div>
                        <div class="text-sm text-stone-600">
                            {{ work?.job_title || work?.number || `#${work?.id}` }}
                        </div>
                    </div>
                </div>
                <span class="rounded-sm bg-stone-100 px-2 py-1 text-xs font-semibold text-stone-700">
                    {{ statusLabel(work?.status) }}
                </span>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm">
                    <div class="text-xs uppercase text-stone-500">{{ $t('work_proofs.labels.customer') }}</div>
                    <div class="mt-1 text-sm text-stone-800">
                        {{ customer?.company_name || `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim() || '-' }}
                    </div>
                    <div class="text-xs text-stone-500">{{ customer?.email || '-' }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm">
                    <div class="text-xs uppercase text-stone-500">{{ $t('work_proofs.labels.period') }}</div>
                    <div class="mt-1 text-sm text-stone-800">
                        {{ formatDate(work?.start_date) }} - {{ formatDate(work?.end_date) }}
                    </div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm">
                    <div class="text-xs uppercase text-stone-500">{{ $t('work_proofs.labels.status') }}</div>
                    <div class="mt-1 text-sm text-stone-800">{{ statusLabel(work?.status) }}</div>
                </div>
            </div>

            <div v-if="flashSuccess" class="rounded-sm border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                {{ flashSuccess }}
            </div>
            <div v-if="flashError" class="rounded-sm border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
                {{ flashError }}
            </div>
            <div v-if="uploadBlockedMessage" class="rounded-sm border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                {{ uploadBlockedMessage }}
            </div>

            <div class="space-y-4">
                <div v-for="task in tasks" :key="task.id"
                    class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-stone-800">
                                {{ task.title || $t('work_proofs.labels.task') }}
                            </div>
                            <div class="text-xs text-stone-500">
                                {{ formatDate(task.due_date) }} - {{ formatTimeRange(task.start_time, task.end_time) }}
                            </div>
                            <div v-if="task.assignee" class="text-xs text-stone-500">
                                {{ $t('work_proofs.labels.assigned') }}: {{ task.assignee }}
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 text-xs font-medium rounded-full" :class="statusClass(task.status)">
                                {{ statusLabel(task.status) }}
                            </span>
                            <button
                                v-if="allowUpload && task.upload_url"
                                type="button"
                                @click="openProof(task)"
                                class="rounded-sm border border-stone-200 bg-white px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-50"
                            >
                                {{ $t('work_proofs.actions.upload_proof') }}
                            </button>
                        </div>
                    </div>

                    <div v-if="task.media?.length" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="media in task.media" :key="media.id"
                            class="rounded-sm border border-stone-200 bg-white p-3 text-sm">
                            <img v-if="media.media_type !== 'video'"
                                :src="media.url"
                                :alt="media.note || $t('work_proofs.labels.proof')"
                                class="h-40 w-full rounded-sm border border-stone-200 object-cover" />
                            <video v-else controls class="h-40 w-full rounded-sm border border-stone-200">
                                <source :src="media.url" />
                            </video>
                            <div class="mt-3 space-y-1 text-xs text-stone-500">
                                <div>
                                    <span class="text-stone-700">{{ $t('work_proofs.labels.type') }}:</span>
                                    {{ proofType(media.type) }}
                                </div>
                                <div>
                                    <span class="text-stone-700">{{ $t('work_proofs.labels.source') }}:</span>
                                    {{ proofSource(media.source) }}
                                </div>
                                <div v-if="media.uploaded_by">
                                    <span class="text-stone-700">{{ $t('work_proofs.labels.by') }}:</span>
                                    {{ media.uploaded_by }}
                                </div>
                                <div v-if="media.note">
                                    <span class="text-stone-700">{{ $t('work_proofs.labels.note') }}:</span>
                                    {{ media.note }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="mt-3 text-sm text-stone-500">
                        {{ $t('work_proofs.empty.task_proofs') }}
                    </div>
                </div>

                <div v-if="!tasks.length"
                    class="rounded-sm border border-stone-200 bg-white p-5 text-sm text-stone-500">
                    {{ $t('work_proofs.empty.tasks') }}
                </div>
            </div>
        </div>
    </GuestLayout>

    <div v-if="proofOpen" class="fixed inset-0 z-50 flex items-center justify-center px-4 py-6">
        <div class="absolute inset-0 bg-stone-900/60" @click="closeProof"></div>
        <div class="relative w-full max-w-lg rounded-sm border border-stone-200 bg-white p-5 shadow-lg">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-base font-semibold text-stone-800">
                        {{ $t('work_proofs.proof.title') }}
                    </h3>
                    <p class="text-sm text-stone-500">
                        {{ proofTask?.title || $t('work_proofs.labels.task') }}
                    </p>
                </div>
                <button type="button" @click="closeProof"
                    class="py-1.5 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50">
                    {{ $t('work_proofs.actions.close') }}
                </button>
            </div>

            <form class="mt-4 space-y-4" @submit.prevent="submitProof">
                <div>
                    <FloatingSelect
                        v-model="form.type"
                        :label="$t('work_proofs.proof.type')"
                        :options="[
                            { id: 'execution', name: $t('work_proofs.type.execution') },
                            { id: 'completion', name: $t('work_proofs.type.completion') },
                            { id: 'other', name: $t('work_proofs.type.other') },
                        ]"
                    />
                    <div v-if="form.errors.type" class="mt-1 text-xs text-red-600">
                        {{ form.errors.type }}
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-stone-500">{{ $t('work_proofs.proof.file_label') }}</label>
                    <input type="file" @change="handleFile" accept="image/*,video/*"
                        class="mt-1 block w-full text-sm text-stone-600 file:mr-4 file:py-2 file:px-3 file:rounded-sm file:border-0 file:text-sm file:font-medium file:bg-stone-100 file:text-stone-700 hover:file:bg-stone-200" />
                    <div v-if="form.errors.file" class="mt-1 text-xs text-red-600">
                        {{ form.errors.file }}
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-stone-500">{{ $t('work_proofs.proof.note_optional') }}</label>
                    <input v-model="form.note" type="text"
                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-emerald-600 focus:ring-emerald-600" />
                    <div v-if="form.errors.note" class="mt-1 text-xs text-red-600">
                        {{ form.errors.note }}
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" @click="closeProof"
                        class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50">
                        {{ $t('work_proofs.actions.cancel') }}
                    </button>
                    <button type="submit" :disabled="form.processing"
                        class="py-2 px-3 text-xs font-medium rounded-sm border border-transparent bg-emerald-600 text-white hover:bg-emerald-700 disabled:pointer-events-none disabled:opacity-50">
                        {{ $t('work_proofs.actions.upload') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
