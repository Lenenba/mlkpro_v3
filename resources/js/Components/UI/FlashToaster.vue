<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    duration: {
        type: Number,
        default: 4500,
    },
    topRightTypes: {
        type: Array,
        default: () => ['success'],
    },
    bottomRightTypes: {
        type: Array,
        default: () => ['warning', 'error'],
    },
});

const page = usePage();
const { t } = useI18n();
const toasts = ref([]);
const toastCounter = ref(0);
const timeouts = new Map();
const lastValidationSignature = ref('');

const flash = computed(() => ({
    success: page.props.flash?.success,
    warning: page.props.flash?.warning,
    error: page.props.flash?.error,
}));

const isTechnicalMessage = (message) => {
    if (!message) {
        return false;
    }
    return /(sqlstate|integrity constraint|syntax error|pdoexception|database error)/i.test(message);
};

const rawValidationMessages = computed(() => {
    const errors = page.props.errors;
    if (!errors || typeof errors !== 'object') {
        return [];
    }

    return Object.values(errors).flatMap((value) => {
        if (Array.isArray(value)) {
            return value.map((entry) => String(entry)).filter(Boolean);
        }
        if (value) {
            return [String(value)];
        }
        return [];
    });
});

const safeValidationMessages = computed(() =>
    rawValidationMessages.value.filter((message) => !isTechnicalMessage(message))
);

const validationCount = computed(() => safeValidationMessages.value.length || rawValidationMessages.value.length);
const hasValidationErrors = computed(() => rawValidationMessages.value.length > 0);
const validationSignature = computed(() =>
    hasValidationErrors.value
        ? `${validationCount.value}:${rawValidationMessages.value.join('|')}`
        : ''
);

const validationToastMessage = computed(() => {
    if (!hasValidationErrors.value) {
        return '';
    }
    if (safeValidationMessages.value.length === 0) {
        return t('alerts.validation_toast.generic');
    }
    if (validationCount.value === 1) {
        return t('alerts.validation_toast.single');
    }
    return t('alerts.validation_toast.plural', { count: validationCount.value });
});

const pushToast = (type, message) => {
    const normalized = typeof message === 'string' ? message.trim() : message;
    if (!normalized) {
        return;
    }

    toastCounter.value += 1;
    const id = `${Date.now()}-${toastCounter.value}`;
    const position = props.bottomRightTypes.includes(type) ? 'bottom-right' : 'top-right';
    const duration = Number(props.duration) > 0 ? Number(props.duration) : 4500;

    const toast = {
        id,
        type,
        message: normalized,
        position,
        duration,
    };

    toasts.value = [...toasts.value, toast];
    const timeoutId = setTimeout(() => dismissToast(id), duration);
    timeouts.set(id, timeoutId);
};

const dismissToast = (id) => {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
    const timeoutId = timeouts.get(id);
    if (timeoutId) {
        clearTimeout(timeoutId);
        timeouts.delete(id);
    }
};

const topToasts = computed(() => toasts.value.filter((toast) => toast.position === 'top-right'));
const bottomToasts = computed(() => toasts.value.filter((toast) => toast.position === 'bottom-right'));

const toneClasses = (type) => {
    switch (type) {
        case 'success':
            return {
                border: 'border-emerald-200/80 dark:border-emerald-700/60',
                text: 'text-emerald-700 dark:text-emerald-200',
                iconBg: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/60 dark:text-emerald-200',
                glow: 'shadow-emerald-500/20',
                bar: 'bg-emerald-500',
                panel: 'bg-gradient-to-br from-white via-emerald-50 to-white dark:from-neutral-900 dark:via-emerald-950/40 dark:to-neutral-900',
            };
        case 'warning':
            return {
                border: 'border-amber-200/80 dark:border-amber-700/60',
                text: 'text-amber-800 dark:text-amber-200',
                iconBg: 'bg-amber-100 text-amber-700 dark:bg-amber-900/60 dark:text-amber-200',
                glow: 'shadow-amber-500/20',
                bar: 'bg-amber-500',
                panel: 'bg-gradient-to-br from-white via-amber-50 to-white dark:from-neutral-900 dark:via-amber-950/40 dark:to-neutral-900',
            };
        default:
            return {
                border: 'border-rose-200/80 dark:border-rose-700/60',
                text: 'text-rose-700 dark:text-rose-200',
                iconBg: 'bg-rose-100 text-rose-700 dark:bg-rose-900/60 dark:text-rose-200',
                glow: 'shadow-rose-500/20',
                bar: 'bg-rose-500',
                panel: 'bg-gradient-to-br from-white via-rose-50 to-white dark:from-neutral-900 dark:via-rose-950/40 dark:to-neutral-900',
            };
    }
};

const iconPath = (type) => {
    switch (type) {
        case 'success':
            return 'M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10zm-2.2-9 1.7 1.7 3.8-3.9';
        case 'warning':
            return 'M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0ZM12 9v4m0 4h.01';
        default:
            return 'M18 6 6 18M6 6l12 12';
    }
};

const typeLabel = (type) => {
    switch (type) {
        case 'success':
            return t('alerts.success.title');
        case 'warning':
            return t('alerts.warning.title');
        default:
            return t('alerts.error.title');
    }
};

watch(
    flash,
    (next, prev = {}) => {
        if (next?.success && next.success !== prev?.success) {
            pushToast('success', next.success);
        }
        if (next?.warning && next.warning !== prev?.warning) {
            pushToast('warning', next.warning);
        }
        if (next?.error && next.error !== prev?.error) {
            pushToast('error', next.error);
        }
    },
    { immediate: true, deep: true }
);

watch(
    validationSignature,
    (signature) => {
        if (!signature || !hasValidationErrors.value) {
            lastValidationSignature.value = '';
            return;
        }
        if (signature === lastValidationSignature.value) {
            return;
        }
        pushToast('error', validationToastMessage.value);
        lastValidationSignature.value = signature;
    },
    { immediate: true }
);

onBeforeUnmount(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('mlk-toast', handleExternalToast);
    }
    timeouts.forEach((timeoutId) => clearTimeout(timeoutId));
    timeouts.clear();
});

const handleExternalToast = (event) => {
    const payload = event?.detail || {};
    pushToast(payload.type || 'success', payload.message || '');
};

onMounted(() => {
    if (typeof window !== 'undefined') {
        window.addEventListener('mlk-toast', handleExternalToast);
    }
});
</script>

<template>
    <div aria-live="polite" class="pointer-events-none">
        <TransitionGroup
            name="toast"
            tag="div"
            class="fixed z-[100] flex w-full max-w-sm flex-col gap-3"
            style="top: calc(1rem + env(safe-area-inset-top)); right: 1rem;"
        >
            <div
                v-for="toast in topToasts"
                :key="toast.id"
                class="pointer-events-auto"
            >
                <div
                    class="relative overflow-hidden rounded-xl border shadow-lg backdrop-blur"
                    :class="[toneClasses(toast.type).border, toneClasses(toast.type).panel, toneClasses(toast.type).glow]"
                    :role="toast.type === 'error' ? 'alert' : 'status'"
                >
                    <div class="flex items-start gap-3 px-4 py-3">
                        <div class="mt-0.5 flex size-9 items-center justify-center rounded-full" :class="toneClasses(toast.type).iconBg">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path :d="iconPath(toast.type)"></path>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-xs font-semibold uppercase tracking-wide" :class="toneClasses(toast.type).text">
                                {{ typeLabel(toast.type) }}
                            </div>
                            <div class="mt-1 text-sm text-stone-700 dark:text-neutral-200">
                                {{ toast.message }}
                            </div>
                        </div>
                        <button
                            type="button"
                            class="text-stone-400 transition hover:text-stone-600 dark:text-neutral-400 dark:hover:text-neutral-200"
                            @click="dismissToast(toast.id)"
                        >
                            <span class="sr-only">Close</span>
                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M6.28 5.22a.75.75 0 0 1 1.06 0L10 7.88l2.66-2.66a.75.75 0 1 1 1.06 1.06L11.06 8.94l2.66 2.66a.75.75 0 0 1-1.06 1.06L10 10l-2.66 2.66a.75.75 0 0 1-1.06-1.06l2.66-2.66-2.66-2.66a.75.75 0 0 1 0-1.06z" />
                            </svg>
                        </button>
                    </div>
                    <div class="h-1 w-full bg-white/70 dark:bg-neutral-800/70">
                        <div
                            class="h-full origin-left toast-progress"
                            :class="toneClasses(toast.type).bar"
                            :style="{ animationDuration: `${toast.duration}ms` }"
                        ></div>
                    </div>
                </div>
            </div>
        </TransitionGroup>

        <TransitionGroup
            name="toast"
            tag="div"
            class="fixed z-[100] flex w-full max-w-sm flex-col gap-3"
            style="bottom: calc(1rem + env(safe-area-inset-bottom)); right: 1rem;"
        >
            <div
                v-for="toast in bottomToasts"
                :key="toast.id"
                class="pointer-events-auto"
            >
                <div
                    class="relative overflow-hidden rounded-xl border shadow-lg backdrop-blur"
                    :class="[toneClasses(toast.type).border, toneClasses(toast.type).panel, toneClasses(toast.type).glow]"
                    :role="toast.type === 'error' ? 'alert' : 'status'"
                >
                    <div class="flex items-start gap-3 px-4 py-3">
                        <div class="mt-0.5 flex size-9 items-center justify-center rounded-full" :class="toneClasses(toast.type).iconBg">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path :d="iconPath(toast.type)"></path>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-xs font-semibold uppercase tracking-wide" :class="toneClasses(toast.type).text">
                                {{ typeLabel(toast.type) }}
                            </div>
                            <div class="mt-1 text-sm text-stone-700 dark:text-neutral-200">
                                {{ toast.message }}
                            </div>
                        </div>
                        <button
                            type="button"
                            class="text-stone-400 transition hover:text-stone-600 dark:text-neutral-400 dark:hover:text-neutral-200"
                            @click="dismissToast(toast.id)"
                        >
                            <span class="sr-only">Close</span>
                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M6.28 5.22a.75.75 0 0 1 1.06 0L10 7.88l2.66-2.66a.75.75 0 1 1 1.06 1.06L11.06 8.94l2.66 2.66a.75.75 0 0 1-1.06 1.06L10 10l-2.66 2.66a.75.75 0 0 1-1.06-1.06l2.66-2.66-2.66-2.66a.75.75 0 0 1 0-1.06z" />
                            </svg>
                        </button>
                    </div>
                    <div class="h-1 w-full bg-white/70 dark:bg-neutral-800/70">
                        <div
                            class="h-full origin-left toast-progress"
                            :class="toneClasses(toast.type).bar"
                            :style="{ animationDuration: `${toast.duration}ms` }"
                        ></div>
                    </div>
                </div>
            </div>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
    transition: opacity 0.25s ease, transform 0.25s ease;
}
.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateX(16px) translateY(-6px);
}
.toast-progress {
    animation-name: toast-progress;
    animation-timing-function: linear;
    animation-fill-mode: forwards;
}
@keyframes toast-progress {
    from {
        transform: scaleX(1);
    }
    to {
        transform: scaleX(0);
    }
}
</style>
