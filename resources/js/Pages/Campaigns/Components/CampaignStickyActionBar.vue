<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

defineProps({
    currentStepLabel: {
        type: String,
        required: true,
    },
    currentStepTitle: {
        type: String,
        required: true,
    },
    guidance: {
        type: String,
        default: '',
    },
    showPrevious: {
        type: Boolean,
        default: true,
    },
    previousDisabled: {
        type: Boolean,
        default: false,
    },
    saveDisabled: {
        type: Boolean,
        default: false,
    },
    primaryDisabled: {
        type: Boolean,
        default: false,
    },
    previousLabel: {
        type: String,
        required: true,
    },
    saveLabel: {
        type: String,
        default: '',
    },
    primaryLabel: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(['previous', 'save', 'primary']);
</script>

<template>
    <div class="sticky bottom-4 z-20 mt-6">
        <div class="rounded-xl border border-stone-200 bg-white/95 px-4 py-4 shadow-lg backdrop-blur dark:border-neutral-700 dark:bg-neutral-900/95">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                        {{ currentStepLabel }}
                    </div>
                    <div class="mt-1 text-sm font-semibold text-stone-900 dark:text-neutral-100">
                        {{ currentStepTitle }}
                    </div>
                    <p v-if="guidance" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ guidance }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <SecondaryButton
                        v-if="showPrevious"
                        type="button"
                        :disabled="previousDisabled"
                        @click="emit('previous')"
                    >
                        {{ previousLabel }}
                    </SecondaryButton>

                    <SecondaryButton
                        v-if="saveLabel"
                        type="button"
                        :disabled="saveDisabled"
                        @click="emit('save')"
                    >
                        {{ saveLabel }}
                    </SecondaryButton>

                    <PrimaryButton
                        type="button"
                        :disabled="primaryDisabled"
                        @click="emit('primary')"
                    >
                        {{ primaryLabel }}
                    </PrimaryButton>
                </div>
            </div>
        </div>
    </div>
</template>
