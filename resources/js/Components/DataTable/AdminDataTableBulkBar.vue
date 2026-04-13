<script setup>
import { computed, useSlots } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    buildBulkActionSummary,
    resolveBulkActionFeedbackType,
} from '@/utils/bulkActions';

const props = defineProps({
    count: {
        type: Number,
        default: 0,
    },
    label: {
        type: String,
        default: '',
    },
    containerClass: {
        type: [String, Array, Object],
        default: '',
    },
    result: {
        type: Object,
        default: null,
    },
});

const { t } = useI18n();
const slots = useSlots();
const result = computed(() => (props.result && typeof props.result === 'object' ? props.result : null));
const hasResult = computed(() => Boolean(result.value));
const resultType = computed(() => resolveBulkActionFeedbackType(result.value));
const resultSummary = computed(() => buildBulkActionSummary(result.value, t));
const resultErrors = computed(() => (Array.isArray(result.value?.errors) ? result.value.errors.slice(0, 3) : []));
const remainingErrorCount = computed(() => Math.max(0, (result.value?.errors?.length || 0) - resultErrors.value.length));
const showSelectionSummary = computed(() => props.count > 0 && (!!props.label || !!slots.summary));
const showActions = computed(() => props.count > 0 && !!slots.default);
const resultTypeLabel = computed(() => {
    const key = resultType.value === 'warning'
        ? 'alerts.warning.title'
        : resultType.value === 'error'
            ? 'alerts.error.title'
            : 'alerts.success.title';

    return String(t(key)).replace(/[.!]+$/u, '');
});

const containerToneClass = computed(() => {
    if (props.count > 0) {
        return 'border-emerald-200/80 bg-emerald-50/60 dark:border-emerald-500/20 dark:bg-emerald-500/10';
    }

    switch (resultType.value) {
        case 'warning':
            return 'border-amber-200/80 bg-amber-50/60 dark:border-amber-500/20 dark:bg-amber-500/10';
        case 'error':
            return 'border-rose-200/80 bg-rose-50/60 dark:border-rose-500/20 dark:bg-rose-500/10';
        default:
            return 'border-emerald-200/80 bg-emerald-50/60 dark:border-emerald-500/20 dark:bg-emerald-500/10';
    }
});

const resultPanelClasses = computed(() => {
    switch (resultType.value) {
        case 'warning':
            return {
                shell: 'border-amber-200/80 bg-white/75 text-amber-900 dark:border-amber-500/20 dark:bg-neutral-950/40 dark:text-amber-100',
                badge: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-200',
                detail: 'text-amber-800/90 dark:text-amber-100/80',
                bullet: 'bg-amber-500',
            };
        case 'error':
            return {
                shell: 'border-rose-200/80 bg-white/75 text-rose-900 dark:border-rose-500/20 dark:bg-neutral-950/40 dark:text-rose-100',
                badge: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-200',
                detail: 'text-rose-800/90 dark:text-rose-100/80',
                bullet: 'bg-rose-500',
            };
        default:
            return {
                shell: 'border-emerald-200/80 bg-white/75 text-emerald-900 dark:border-emerald-500/20 dark:bg-neutral-950/40 dark:text-emerald-100',
                badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200',
                detail: 'text-emerald-800/90 dark:text-emerald-100/80',
                bullet: 'bg-emerald-500',
            };
    }
});

const isVisible = computed(() =>
    showSelectionSummary.value
    || showActions.value
    || hasResult.value
);
</script>

<template>
    <div
        v-if="isVisible"
        :class="[
            'flex flex-col gap-3 rounded-sm border px-4 py-3 shadow-sm',
            containerToneClass,
            containerClass,
        ]"
    >
        <div v-if="showSelectionSummary || hasResult" class="min-w-0 flex-1 space-y-3">
            <div
                v-if="showSelectionSummary"
                class="min-w-0 text-sm font-semibold text-stone-800 dark:text-neutral-100"
            >
                <slot name="summary">
                    {{ label }}
                </slot>
            </div>

            <div
                v-if="hasResult"
                class="rounded-sm border px-3 py-2.5"
                :class="resultPanelClasses.shell"
                :role="resultType === 'error' ? 'alert' : 'status'"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide"
                        :class="resultPanelClasses.badge">
                        {{ resultTypeLabel }}
                    </span>
                    <p class="text-sm font-semibold">
                        {{ resultSummary }}
                    </p>
                </div>

                <div
                    v-if="resultErrors.length"
                    class="mt-2 space-y-1 text-xs"
                    :class="resultPanelClasses.detail"
                >
                    <p class="font-semibold">
                        {{ t('alerts.bulk_action.errors_title') }}
                    </p>
                    <div
                        v-for="(error, index) in resultErrors"
                        :key="`${index}-${error}`"
                        class="flex items-start gap-2"
                    >
                        <span class="mt-1.5 size-1.5 shrink-0 rounded-full" :class="resultPanelClasses.bullet"></span>
                        <span>{{ error }}</span>
                    </div>
                    <p v-if="remainingErrorCount" class="font-medium">
                        {{ t('alerts.bulk_action.more_errors', { count: remainingErrorCount }) }}
                    </p>
                </div>
            </div>
        </div>

        <div v-if="showActions" class="flex flex-wrap items-center gap-2 md:justify-end">
            <slot />
        </div>
    </div>
</template>
