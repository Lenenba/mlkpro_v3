<script setup>
const props = defineProps({
    currentStep: {
        type: Number,
        required: true,
    },
    steps: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['select']);

const stateClasses = {
    current: {
        card: 'border-green-600 bg-green-50 shadow-sm dark:border-green-500 dark:bg-green-500/10',
        badge: 'bg-green-600 text-white dark:bg-green-500 dark:text-neutral-950',
        tone: 'text-green-700 dark:text-green-300',
        status: 'border-green-200 bg-green-100 text-green-700 dark:border-green-500/30 dark:bg-green-500/20 dark:text-green-200',
    },
    complete: {
        card: 'border-emerald-200 bg-emerald-50 shadow-sm dark:border-emerald-500/30 dark:bg-emerald-500/10',
        badge: 'bg-emerald-600 text-white dark:bg-emerald-500 dark:text-neutral-950',
        tone: 'text-emerald-700 dark:text-emerald-300',
        status: 'border-emerald-200 bg-emerald-100 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/20 dark:text-emerald-200',
    },
    attention: {
        card: 'border-amber-200 bg-amber-50 shadow-sm dark:border-amber-500/30 dark:bg-amber-500/10',
        badge: 'bg-amber-500 text-white dark:bg-amber-400 dark:text-neutral-950',
        tone: 'text-amber-700 dark:text-amber-300',
        status: 'border-amber-200 bg-amber-100 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/20 dark:text-amber-200',
    },
    upcoming: {
        card: 'border-stone-200 bg-white hover:border-stone-300 hover:shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-neutral-600',
        badge: 'bg-stone-100 text-stone-600 dark:bg-neutral-800 dark:text-neutral-300',
        tone: 'text-stone-700 dark:text-neutral-200',
        status: 'border-stone-200 bg-stone-100 text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
    },
};

const classesFor = (state) => stateClasses[state] || stateClasses.upcoming;

const selectStep = (stepId) => {
    emit('select', stepId);
};
</script>

<template>
    <div class="overflow-x-auto pb-1">
        <div class="flex min-w-max gap-3">
            <button
                v-for="step in steps"
                :key="step.id"
                type="button"
                class="min-w-[220px] rounded-lg border px-4 py-3 text-left transition"
                :class="classesFor(step.state).card"
                @click="selectStep(step.id)"
            >
                <div class="flex items-start gap-3">
                    <div
                        class="flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold"
                        :class="classesFor(step.state).badge"
                    >
                        {{ step.id }}
                    </div>

                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-semibold" :class="classesFor(step.state).tone">
                                {{ step.title }}
                            </p>
                            <span
                                class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide"
                                :class="classesFor(step.state).status"
                            >
                                {{ step.statusLabel }}
                            </span>
                        </div>

                        <p class="mt-2 text-xs leading-5 text-stone-500 dark:text-neutral-400">
                            {{ step.summary }}
                        </p>
                    </div>
                </div>
            </button>
        </div>
    </div>
</template>
