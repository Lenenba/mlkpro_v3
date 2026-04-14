<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingNumberInput from '@/Components/FloatingNumberInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    expense: {
        type: Object,
        default: null,
    },
    categories: {
        type: Array,
        required: true,
    },
    paymentMethods: {
        type: Array,
        required: true,
    },
    statuses: {
        type: Array,
        required: true,
    },
    recurrenceFrequencies: {
        type: Array,
        default: () => [],
    },
    teamMembers: {
        type: Array,
        default: () => [],
    },
    id: {
        type: String,
        default: null,
    },
    tenantCurrencyCode: {
        type: String,
        default: 'CAD',
    },
});

const emit = defineEmits(['submitted']);
const { t } = useI18n();
const fileInput = ref(null);
const cameraInput = ref(null);
const overlayTarget = computed(() => (props.id ? `#${props.id}` : null));

const categoryOptions = computed(() =>
    (props.categories || []).map((item) => ({
        value: item.key,
        label: t(item.label_key),
    }))
);
const paymentMethodOptions = computed(() =>
    (props.paymentMethods || []).map((item) => ({
        value: item.key,
        label: t(item.label_key),
    }))
);
const statusOptions = computed(() =>
    (props.statuses || []).map((status) => ({
        value: status,
        label: t(`expenses.status.${status}`),
    }))
);
const recurrenceOptions = computed(() =>
    (props.recurrenceFrequencies || []).map((frequency) => ({
        value: frequency,
        label: t(`expenses.recurrence.frequency.${frequency}`),
    }))
);
const teamMemberOptions = computed(() =>
    (props.teamMembers || []).map((member) => ({
        value: member.id,
        label: member.title
            ? `${member.name} - ${member.title}`
            : member.name,
    }))
);

const form = useForm({
    title: props.expense?.title || '',
    category_key: props.expense?.category_key || '',
    supplier_name: props.expense?.supplier_name || '',
    reference_number: props.expense?.reference_number || '',
    subtotal: props.expense?.subtotal ?? '',
    tax_amount: props.expense?.tax_amount ?? 0,
    total: props.expense?.total ?? 0,
    expense_date: props.expense?.expense_date || '',
    due_date: props.expense?.due_date || '',
    paid_date: props.expense?.paid_date || '',
    payment_method: props.expense?.payment_method || '',
    status: props.expense?.status || 'draft',
    reimbursable: Boolean(props.expense?.reimbursable),
    team_member_id: props.expense?.team_member_id || '',
    is_recurring: Boolean(props.expense?.is_recurring),
    recurrence_frequency: props.expense?.recurrence_frequency || '',
    recurrence_interval: props.expense?.recurrence_interval ?? 1,
    recurrence_ends_at: props.expense?.recurrence_ends_at || '',
    description: props.expense?.description || '',
    notes: props.expense?.notes || '',
    attachments: [],
});

const selectedFiles = computed(() => Array.isArray(form.attachments) ? form.attachments : []);

const closeOverlay = () => {
    if (overlayTarget.value && window.HSOverlay) {
        window.HSOverlay.close(overlayTarget.value);
    }
};

const resetFiles = () => {
    form.attachments = [];
    if (fileInput.value) {
        fileInput.value.value = '';
    }
    if (cameraInput.value) {
        cameraInput.value.value = '';
    }
};

const mergeSelectedFiles = (files) => {
    const nextFiles = Array.isArray(files) ? files : [];
    const existingFiles = Array.isArray(form.attachments) ? form.attachments : [];
    const deduped = new Map();

    [...existingFiles, ...nextFiles].forEach((file) => {
        if (!file) {
            return;
        }

        deduped.set(`${file.name}-${file.size}-${file.lastModified}`, file);
    });

    form.attachments = Array.from(deduped.values());
};

const onFilesChange = (event) => {
    const incomingFiles = event?.target?.files ? Array.from(event.target.files) : [];
    mergeSelectedFiles(incomingFiles);

    if (event?.target) {
        event.target.value = '';
    }
};

const openFileBrowser = () => {
    fileInput.value?.click();
};

const openCameraCapture = () => {
    cameraInput.value?.click();
};

const recurrencePreview = computed(() => {
    if (!form.is_recurring || !form.expense_date || !form.recurrence_frequency) {
        return '';
    }

    const base = new Date(form.expense_date);
    if (Number.isNaN(base.getTime())) {
        return '';
    }

    const interval = Math.max(1, Number(form.recurrence_interval) || 1);
    if (form.recurrence_frequency === 'monthly') {
        base.setMonth(base.getMonth() + interval);
    } else if (form.recurrence_frequency === 'yearly') {
        base.setFullYear(base.getFullYear() + interval);
    } else {
        return '';
    }

    return base.toISOString().slice(0, 10);
});

const submit = () => {
    const method = props.expense?.id ? 'put' : 'post';
    const routeName = props.expense?.id ? 'expense.update' : 'expense.store';
    const routeParams = props.expense?.id ? props.expense.id : undefined;

    form.clearErrors('form');

    form[method](route(routeName, routeParams), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            emit('submitted');
            resetFiles();
            closeOverlay();
        },
    });
};
</script>

<template>
    <form @submit.prevent="submit" class="space-y-4">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <FloatingInput v-model="form.title" :label="$t('expenses.form.title')" :required="true" />
            <FloatingSelect
                v-model="form.category_key"
                :label="$t('expenses.form.category')"
                :options="categoryOptions"
                :placeholder="$t('expenses.form.category')"
            />
            <FloatingInput v-model="form.supplier_name" :label="$t('expenses.form.supplier_name')" />
            <FloatingInput v-model="form.reference_number" :label="$t('expenses.form.reference_number')" />
            <FloatingNumberInput v-model="form.total" :label="$t('expenses.form.total')" :required="true" :step="0.01" />
            <FloatingNumberInput v-model="form.tax_amount" :label="$t('expenses.form.tax_amount')" :step="0.01" />
            <FloatingNumberInput v-model="form.subtotal" :label="$t('expenses.form.subtotal')" :step="0.01" />
            <div class="rounded-sm border border-dashed border-stone-200 bg-stone-50 p-3 text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                {{ $t('expenses.form.business_currency', { currency: String(tenantCurrencyCode || 'CAD').toUpperCase() }) }}
            </div>
            <FloatingInput v-model="form.expense_date" type="date" :label="$t('expenses.form.expense_date')" :required="true" />
            <FloatingInput v-model="form.due_date" type="date" :label="$t('expenses.form.due_date')" />
            <FloatingInput v-model="form.paid_date" type="date" :label="$t('expenses.form.paid_date')" />
            <FloatingSelect
                v-model="form.payment_method"
                :label="$t('expenses.form.payment_method')"
                :options="paymentMethodOptions"
                :placeholder="$t('expenses.form.payment_method')"
            />
            <FloatingSelect
                v-model="form.status"
                :label="$t('expenses.form.status')"
                :options="statusOptions"
                :placeholder="$t('expenses.form.status')"
                :required="true"
            />
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div class="flex items-center gap-2 rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                <Checkbox v-model:checked="form.reimbursable" />
                <span class="text-sm text-stone-700 dark:text-neutral-300">{{ $t('expenses.form.reimbursable') }}</span>
            </div>
            <div class="flex items-center gap-2 rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                <Checkbox v-model:checked="form.is_recurring" />
                <span class="text-sm text-stone-700 dark:text-neutral-300">{{ $t('expenses.form.is_recurring') }}</span>
            </div>
        </div>

        <div v-if="form.reimbursable" class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <FloatingSelect
                v-model="form.team_member_id"
                :label="$t('expenses.form.team_member')"
                :options="teamMemberOptions"
                :placeholder="$t('expenses.form.team_member')"
            />
            <div class="rounded-sm border border-dashed border-stone-200 bg-stone-50 p-3 text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                {{ $t('expenses.form.reimbursement_help') }}
            </div>
        </div>

        <div v-if="form.is_recurring" class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <FloatingSelect
                v-model="form.recurrence_frequency"
                :label="$t('expenses.form.recurrence_frequency')"
                :options="recurrenceOptions"
                :placeholder="$t('expenses.form.recurrence_frequency')"
            />
            <FloatingNumberInput
                v-model="form.recurrence_interval"
                :label="$t('expenses.form.recurrence_interval')"
                :step="1"
                :min="1"
            />
            <FloatingInput
                v-model="form.recurrence_ends_at"
                type="date"
                :label="$t('expenses.form.recurrence_ends_at')"
            />
            <div class="rounded-sm border border-dashed border-stone-200 bg-stone-50 p-3 text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                {{ recurrencePreview
                    ? $t('expenses.form.recurrence_preview', { date: recurrencePreview })
                    : $t('expenses.form.recurrence_help') }}
            </div>
        </div>

        <FloatingTextarea v-model="form.description" :label="$t('expenses.form.description')" />
        <FloatingTextarea v-model="form.notes" :label="$t('expenses.form.notes')" />

        <div class="space-y-3 rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="space-y-1">
                <p class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                    {{ $t('expenses.form.attachments') }}
                </p>
                <p class="text-xs text-stone-500 dark:text-neutral-500">
                    {{ $t('expenses.form.attachments_help') }}
                </p>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <button
                    type="button"
                    class="inline-flex items-center justify-center gap-2 rounded-sm border border-transparent bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700"
                    @click="openCameraCapture"
                >
                    {{ $t('expenses.actions.capture_receipt') }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center justify-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                    @click="openFileBrowser"
                >
                    {{ $t('expenses.actions.browse_files') }}
                </button>
            </div>

            <p class="text-xs text-stone-500 dark:text-neutral-500">
                {{ $t('expenses.form.mobile_capture_hint') }}
            </p>

            <input
                ref="fileInput"
                type="file"
                multiple
                accept=".pdf,image/*"
                class="hidden"
                @change="onFilesChange"
            >

            <input
                ref="cameraInput"
                type="file"
                accept="image/*"
                capture="environment"
                class="hidden"
                @change="onFilesChange"
            >

            <div v-if="selectedFiles.length" class="space-y-2">
                <div
                    v-for="file in selectedFiles"
                    :key="`${file.name}-${file.size}`"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                >
                    {{ file.name }}
                </div>
            </div>

            <div v-if="expense?.attachments?.length" class="space-y-2">
                <p class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-500">
                    {{ $t('expenses.form.existing_attachments') }}
                </p>
                <a
                    v-for="attachment in expense.attachments"
                    :key="attachment.id"
                    :href="attachment.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="block rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                >
                    {{ attachment.original_name || $t('expenses.attachments.file') }}
                </a>
            </div>
        </div>

        <div v-if="Object.keys(form.errors).length" class="rounded-sm border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            <div v-for="(messages, field) in form.errors" :key="field">
                {{ Array.isArray(messages) ? messages[0] : messages }}
            </div>
        </div>

        <div class="flex justify-end">
            <button
                type="submit"
                class="inline-flex items-center rounded-sm border border-transparent bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-60"
                :disabled="form.processing"
            >
                {{ expense?.id ? $t('expenses.actions.update_expense') : $t('expenses.actions.create_expense') }}
            </button>
        </div>
    </form>
</template>
