<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

const page = usePage();
const demo = computed(() => page.props.demo || {});
const showBanner = computed(() => Boolean(demo.value?.is_demo_user || demo.value?.is_demo));
const canReset = computed(() => Boolean(demo.value?.allow_reset));
const isGuided = computed(() => Boolean(demo.value?.is_guided));

const resetDemo = () => {
    if (!canReset.value) {
        return;
    }
    if (!confirm('Reset demo data for this account?')) {
        return;
    }
    router.post(route('demo.reset'), {}, {
        preserveScroll: true,
        onSuccess: () => {
            if (typeof window !== 'undefined') {
                window.dispatchEvent(new CustomEvent('demo:reset-complete'));
            }
        },
    });
};

const restartTour = () => {
    if (!isGuided.value || typeof window === 'undefined') {
        return;
    }
    window.dispatchEvent(new CustomEvent('demo:restart-tour'));
};
</script>

<template>
    <div
        v-if="showBanner"
        class="rounded-sm border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                    Demo mode
                </div>
                <div class="text-sm font-medium">
                    You are exploring the demo workspace.
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    :disabled="!canReset"
                    class="rounded-sm border border-emerald-200 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 disabled:opacity-50 dark:border-emerald-500/30 dark:bg-neutral-900 dark:text-emerald-200 dark:hover:bg-neutral-800"
                    @click="resetDemo"
                >
                    Reset demo
                </button>
                <Link
                    v-if="isGuided"
                    :href="route('demo.checklist')"
                    class="rounded-sm border border-emerald-200 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 dark:border-emerald-500/30 dark:bg-neutral-900 dark:text-emerald-200 dark:hover:bg-neutral-800"
                >
                    Open checklist
                </Link>
                <button
                    v-if="isGuided"
                    type="button"
                    class="rounded-sm border border-emerald-600 bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700"
                    @click="restartTour"
                >
                    Restart tour
                </button>
            </div>
        </div>
    </div>
</template>
