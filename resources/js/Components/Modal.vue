<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: '2xl',
    },
    closeable: {
        type: Boolean,
        default: true,
    },
    position: {
        type: String,
        default: 'top',
    },
    fullScreenMobile: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close']);
const dialog = ref();
const showSlot = ref(props.show);
const previouslyFocusedElement = ref(null);
let closeTimer;

const cancelPendingClose = () => {
    if (closeTimer) {
        clearTimeout(closeTimer);
        closeTimer = undefined;
    }
};

watch(
    () => props.show,
    async () => {
        if (props.show) {
            cancelPendingClose();
            previouslyFocusedElement.value ??= document.activeElement;
            document.body.style.overflow = 'hidden';
            showSlot.value = true;

            await nextTick();
            if (dialog.value && !dialog.value.open) {
                dialog.value.showModal();
            }
        } else {
            document.body.style.overflow = '';

            cancelPendingClose();
            closeTimer = setTimeout(() => {
                if (dialog.value?.open) {
                    dialog.value.close();
                }
                showSlot.value = false;
                previouslyFocusedElement.value?.focus?.();
                previouslyFocusedElement.value = null;
                closeTimer = undefined;
            }, 200);
        }
    },
);

const close = () => {
    if (props.closeable) {
        emit('close');
    }
};

const closeOnEscape = (e) => {
    if (e.key === 'Escape') {
        e.preventDefault();

        if (props.show) {
            close();
        }
    }
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));

onUnmounted(() => {
    cancelPendingClose();
    document.removeEventListener('keydown', closeOnEscape);

    document.body.style.overflow = '';
});

const maxWidthClass = computed(() => {
    return {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
        '3xl': 'sm:max-w-3xl',
        '4xl': 'sm:max-w-4xl',
        '5xl': 'sm:max-w-5xl',
    }[props.maxWidth];
});

const positionClass = computed(() => {
    return {
        top: 'items-start',
        center: 'items-start sm:items-center',
    }[props.position] ?? 'items-start';
});
</script>

<template>
    <dialog
        class="z-50 m-0 min-h-full min-w-full overflow-y-auto bg-transparent backdrop:bg-transparent"
        ref="dialog"
        @cancel.prevent="close"
    >
        <div
            class="fixed inset-0 z-50 overflow-y-auto"
            :class="fullScreenMobile ? 'p-0 sm:px-6 sm:py-6' : 'px-4 py-6 sm:px-6'"
            scroll-region
        >
            <Transition
                enter-active-class="ease-out duration-300"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="ease-in duration-200"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-show="show"
                    class="fixed inset-0 transform transition-all"
                    @click="close"
                >
                    <div
                        class="absolute inset-0 bg-stone-900/60 dark:bg-neutral-900/80"
                    />
                </div>
            </Transition>

            <div class="flex min-h-full justify-center" :class="positionClass">
                <Transition
                    enter-active-class="ease-out duration-300"
                    enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-active-class="ease-in duration-200"
                    leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                >
                    <div
                        v-show="show"
                        class="w-full max-w-full min-w-0 transform overflow-hidden bg-white text-stone-900 shadow-xl transition-all dark:bg-neutral-900 dark:text-neutral-100"
                        :class="[
                            maxWidthClass,
                            fullScreenMobile
                                ? 'min-h-dvh rounded-none border-0 sm:min-h-0 sm:rounded-sm sm:border sm:border-stone-200 sm:dark:border-neutral-700'
                                : 'rounded-sm border border-stone-200 dark:border-neutral-700',
                        ]"
                    >
                        <slot v-if="showSlot" />
                    </div>
                </Transition>
            </div>
        </div>
    </dialog>
</template>
