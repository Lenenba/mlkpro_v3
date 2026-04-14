<script setup>
import { computed, onMounted, onUnmounted, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    id: {
        type: String,
        required: true,
    },
    expense: {
        type: Object,
        default: null,
    },
    action: {
        type: String,
        default: '',
    },
    reloadOnly: {
        type: Array,
        default: () => [],
    },
    preserveState: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['start', 'finished', 'submitted', 'closed', 'opened']);
const { t } = useI18n();

const workflowRoute = {
    submit: 'expense.submit',
    approve: 'expense.approve',
    mark_due: 'expense.mark-due',
    mark_paid: 'expense.mark-paid',
    mark_reimbursed: 'expense.mark-reimbursed',
    cancel: 'expense.cancel',
};

const form = useForm({
    comment: '',
    paid_date: '',
    reimbursement_reference: '',
});

const today = () => new Date().toISOString().slice(0, 10);
const overlaySelector = computed(() => `#${props.id}`);
const routeName = computed(() => workflowRoute[props.action] || null);
const requiresPaidDate = computed(() => ['mark_paid', 'mark_reimbursed'].includes(props.action));
const requiresReimbursementReference = computed(() => props.action === 'mark_reimbursed');
const actionLabel = computed(() => props.action ? t(`expenses.actions.${props.action}`) : '');
const title = computed(() => props.action ? t('expenses.workflow.modal_title', { action: actionLabel.value }) : '');
const description = computed(() => props.action ? t(`expenses.workflow.descriptions.${props.action}`) : '');
const confirmClass = computed(() => (
    props.action === 'cancel'
        ? 'bg-rose-600 hover:bg-rose-700 focus:bg-rose-700'
        : 'bg-red-600 hover:bg-red-700 focus:bg-red-700'
));

const resetForm = () => {
    form.reset();
    form.clearErrors();
    form.comment = '';
    form.paid_date = requiresPaidDate.value
        ? (props.expense?.paid_date || today())
        : '';
    form.reimbursement_reference = '';
};

watch(() => [props.expense?.id, props.action], () => {
    resetForm();
}, { immediate: true });

const closeOverlay = () => {
    if (window.HSOverlay) {
        window.HSOverlay.close(overlaySelector.value);
    }
};

const handleOverlayOpen = () => {
    resetForm();
    emit('opened');
};

const handleOverlayClose = () => {
    resetForm();
    emit('closed');
};

onMounted(() => {
    const overlay = document.getElementById(props.id);
    if (!overlay) {
        return;
    }

    overlay.addEventListener('open.hs.overlay', handleOverlayOpen);
    overlay.addEventListener('close.hs.overlay', handleOverlayClose);
});

onUnmounted(() => {
    const overlay = document.getElementById(props.id);
    if (!overlay) {
        return;
    }

    overlay.removeEventListener('open.hs.overlay', handleOverlayOpen);
    overlay.removeEventListener('close.hs.overlay', handleOverlayClose);
});

const submit = () => {
    if (!props.expense?.id || !routeName.value) {
        return;
    }

    const payload = {};

    if (form.comment) {
        payload.comment = form.comment;
    }

    if (requiresPaidDate.value) {
        payload.paid_date = form.paid_date || today();
    }

    if (requiresReimbursementReference.value && form.reimbursement_reference) {
        payload.reimbursement_reference = form.reimbursement_reference;
    }

    form.clearErrors();

    form.transform(() => payload).patch(route(routeName.value, props.expense.id), {
        preserveScroll: true,
        preserveState: props.preserveState,
        only: props.reloadOnly.length ? props.reloadOnly : undefined,
        onStart: () => emit('start'),
        onSuccess: () => {
            emit('submitted', {
                expenseId: props.expense?.id,
                action: props.action,
            });
            closeOverlay();
        },
        onFinish: () => emit('finished'),
    });
};
</script>

<template>
    <div
        :id="id"
        class="hs-overlay hidden size-full fixed top-0 start-0 z-[85] overflow-x-hidden overflow-y-auto [--close-when-click-inside:true] pointer-events-none"
        role="dialog"
        tabindex="-1"
        :aria-labelledby="`${id}-label`"
    >
        <div class="hs-overlay-open:mt-7 hs-overlay-open:opacity-100 hs-overlay-open:duration-300 mt-0 opacity-0 ease-out transition-all sm:max-w-lg sm:w-full m-3 sm:mx-auto min-h-[calc(100%-3.5rem)] flex items-center">
            <div class="relative flex w-full flex-col overflow-hidden rounded-sm bg-white shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] pointer-events-auto dark:bg-neutral-900 dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)]">
                <div class="absolute end-3 top-3">
                    <button
                        type="button"
                        class="inline-flex size-8 items-center justify-center rounded-sm border border-transparent bg-stone-100 text-stone-700 hover:bg-stone-200 focus:outline-none focus:bg-stone-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                        aria-label="Close"
                        :data-hs-overlay="overlaySelector"
                    >
                        <span class="sr-only">Close</span>
                        <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-3 border-b border-stone-200 px-5 py-5 dark:border-neutral-800">
                    <div class="space-y-1 pr-10">
                        <h3 :id="`${id}-label`" class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ title }}
                        </h3>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ description }}
                        </p>
                    </div>

                    <div v-if="expense" class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-sm dark:border-neutral-800 dark:bg-neutral-950">
                        <p class="font-medium text-stone-800 dark:text-neutral-100">
                            {{ t('expenses.workflow.expense_context', { name: expense.title }) }}
                        </p>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-500">
                            {{ t('expenses.workflow.current_status', { status: t(`expenses.status.${expense.status}`) }) }}
                        </p>
                    </div>
                </div>

                <form class="space-y-4 px-5 py-5" @submit.prevent="submit">
                    <FloatingTextarea
                        v-model="form.comment"
                        :label="t('expenses.workflow.comment_label')"
                    />

                    <p class="-mt-2 text-xs text-stone-500 dark:text-neutral-500">
                        {{ t(`expenses.workflow.comment_placeholders.${action}`) }}
                    </p>

                    <div v-if="requiresPaidDate" class="space-y-2">
                        <FloatingInput
                            v-model="form.paid_date"
                            type="date"
                            :label="t('expenses.form.paid_date')"
                            :required="true"
                        />
                        <p class="text-xs text-stone-500 dark:text-neutral-500">
                            {{ t('expenses.workflow.paid_date_help') }}
                        </p>
                    </div>

                    <FloatingInput
                        v-if="requiresReimbursementReference"
                        v-model="form.reimbursement_reference"
                        :label="t('expenses.form.reimbursement_reference')"
                    />

                    <div v-if="Object.keys(form.errors).length" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-900/40 dark:bg-rose-950/40 dark:text-rose-300">
                        <div v-for="(messages, field) in form.errors" :key="field">
                            {{ Array.isArray(messages) ? messages[0] : messages }}
                        </div>
                    </div>

                    <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="inline-flex w-full items-center justify-center rounded-sm border border-stone-200 bg-white px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800 sm:w-auto"
                            :data-hs-overlay="overlaySelector"
                        >
                            {{ t('expenses.workflow.dismiss') }}
                        </button>
                        <button
                            type="submit"
                            class="inline-flex w-full items-center justify-center rounded-sm border border-transparent px-4 py-2 text-sm font-medium text-white focus:outline-none disabled:opacity-60 sm:w-auto"
                            :class="confirmClass"
                            :disabled="form.processing || !expense?.id || !routeName"
                        >
                            {{ actionLabel }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
