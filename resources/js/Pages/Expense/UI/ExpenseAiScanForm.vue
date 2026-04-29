<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    id: {
        type: String,
        default: null,
    },
    pettyCash: {
        type: Object,
        default: () => ({}),
    },
});

const emit = defineEmits(['submitted']);
const { t } = useI18n();
const fileInput = ref(null);
const cameraInput = ref(null);
const overlayTarget = computed(() => (props.id ? `#${props.id}` : null));
const canCreatePettyCash = computed(() => Boolean(props.pettyCash?.canCreate));
const canPostPettyCash = computed(() => Boolean(props.pettyCash?.canPost));
const defaultResponsibleId = computed(() => props.pettyCash?.account?.responsible_user_id
    || props.pettyCash?.responsibleOptions?.[0]?.id
    || '');
const responsibleOptions = computed(() => (props.pettyCash?.responsibleOptions || []).map((item) => ({
    value: item.id,
    label: item.name,
})));
const pettyCashStatusOptions = computed(() => [
    {
        value: 'draft',
        label: t('expenses.petty_cash.status.draft'),
    },
    ...(canPostPettyCash.value ? [{
        value: 'posted',
        label: t('expenses.petty_cash.status.posted'),
    }] : []),
]);

const form = useForm({
    document: null,
    note: '',
    petty_cash_create: false,
    petty_cash_status: canPostPettyCash.value ? 'posted' : 'draft',
    petty_cash_responsible_user_id: defaultResponsibleId.value,
    petty_cash_note: '',
});

const selectedFileName = computed(() => form.document?.name || '');

const closeOverlay = () => {
    if (overlayTarget.value && window.HSOverlay) {
        window.HSOverlay.close(overlayTarget.value);
    }
};

const resetForm = () => {
    form.reset();
    form.clearErrors();
    form.document = null;
    form.note = '';
    form.petty_cash_create = false;
    form.petty_cash_status = canPostPettyCash.value ? 'posted' : 'draft';
    form.petty_cash_responsible_user_id = defaultResponsibleId.value;
    form.petty_cash_note = '';

    if (fileInput.value) {
        fileInput.value.value = '';
    }

    if (cameraInput.value) {
        cameraInput.value.value = '';
    }
};

const applyFile = (event) => {
    const file = event?.target?.files?.[0] || null;
    form.document = file;

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

const submit = () => {
    form.clearErrors();

    form.post(route('expense.scan-ai'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            emit('submitted');
            resetForm();
            closeOverlay();
        },
    });
};
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <div class="space-y-3 rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="space-y-1">
                <p class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                    {{ $t('expenses.ai_scan.upload_title') }}
                </p>
                <p class="text-xs text-stone-500 dark:text-neutral-500">
                    {{ $t('expenses.ai_scan.upload_hint') }}
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
                    {{ $t('expenses.ai_scan.choose_document') }}
                </button>
            </div>

            <p class="text-xs text-stone-500 dark:text-neutral-500">
                {{ $t('expenses.ai_scan.mobile_hint') }}
            </p>

            <input
                ref="fileInput"
                type="file"
                accept=".pdf,image/*"
                class="hidden"
                @change="applyFile"
            >

            <input
                ref="cameraInput"
                type="file"
                accept="image/*"
                capture="environment"
                class="hidden"
                @change="applyFile"
            >

            <div v-if="selectedFileName" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                {{ selectedFileName }}
            </div>
        </div>

        <FloatingTextarea
            v-model="form.note"
            :label="$t('expenses.ai_scan.note_label')"
        />

        <p class="-mt-2 text-xs text-stone-500 dark:text-neutral-500">
            {{ $t('expenses.ai_scan.note_hint') }}
        </p>

        <div v-if="canCreatePettyCash" class="space-y-3 rounded-sm border border-emerald-200 bg-emerald-50/70 p-4 dark:border-emerald-500/30 dark:bg-emerald-500/10">
            <label class="flex items-start gap-3 text-sm text-stone-800 dark:text-neutral-100">
                <input
                    v-model="form.petty_cash_create"
                    type="checkbox"
                    class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-600 dark:border-neutral-700 dark:bg-neutral-900"
                >
                <span>
                    <span class="block font-medium">{{ $t('expenses.ai_scan.petty_cash_create') }}</span>
                    <span class="mt-0.5 block text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('expenses.ai_scan.petty_cash_hint') }}
                    </span>
                </span>
            </label>

            <div v-if="form.petty_cash_create" class="grid gap-3 md:grid-cols-2">
                <FloatingSelect
                    v-model="form.petty_cash_status"
                    :label="$t('expenses.ai_scan.petty_cash_status')"
                    :options="pettyCashStatusOptions"
                    required
                />
                <FloatingSelect
                    v-model="form.petty_cash_responsible_user_id"
                    :label="$t('expenses.ai_scan.petty_cash_responsible')"
                    :options="responsibleOptions"
                    required
                />
                <div class="md:col-span-2">
                    <FloatingTextarea
                        v-model="form.petty_cash_note"
                        :label="$t('expenses.ai_scan.petty_cash_note')"
                    />
                </div>
            </div>
        </div>

        <div v-if="Object.keys(form.errors).length" class="rounded-sm border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 dark:border-rose-900/40 dark:bg-rose-950/40 dark:text-rose-300">
            <div v-for="(messages, field) in form.errors" :key="field">
                {{ Array.isArray(messages) ? messages[0] : messages }}
            </div>
        </div>

        <div class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-3 text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-400">
            {{ $t('expenses.ai_scan.review_notice') }}
        </div>

        <div class="flex justify-end">
            <button
                type="submit"
                class="inline-flex items-center rounded-sm border border-transparent bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-60"
                :disabled="form.processing || !form.document"
            >
                {{ t('expenses.actions.scan_with_ai') }}
            </button>
        </div>
    </form>
</template>
