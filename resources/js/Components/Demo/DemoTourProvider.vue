<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';

const page = usePage();
const demo = computed(() => page.props.demo || {});
const isGuided = computed(() => Boolean(demo.value?.enabled && demo.value?.is_guided));
const userId = computed(() => page.props.auth?.user?.id || null);

const steps = ref([]);
const progressMap = ref({});
const currentIndex = ref(0);
const isOpen = ref(true);
const isReady = ref(false);
const isFindingTarget = ref(false);
const statusMessage = ref('');
const targetEl = ref(null);
const highlightRect = ref(null);
const tooltipRef = ref(null);
const tooltipStyle = ref({});
const resolvedPlacement = ref('bottom');

const sortedSteps = computed(() => steps.value.slice().sort((a, b) => a.order_index - b.order_index));
const currentStep = computed(() => sortedSteps.value[currentIndex.value] || null);
const totalSteps = computed(() => sortedSteps.value.length);

const hasStep = computed(() => Boolean(currentStep.value));

const getStorage = () => {
    if (typeof window === 'undefined') {
        return null;
    }
    try {
        return window.localStorage;
    } catch (error) {
        return null;
    }
};

const storageKey = (suffix) => {
    const id = userId.value || 'guest';
    return `demo_tour_${suffix}_${id}`;
};

const readStorage = (suffix) => {
    const storage = getStorage();
    if (!storage) {
        return null;
    }
    const raw = storage.getItem(storageKey(suffix));
    if (!raw) {
        return null;
    }
    try {
        return JSON.parse(raw);
    } catch (error) {
        return null;
    }
};

const writeStorage = (suffix, value) => {
    const storage = getStorage();
    if (!storage) {
        return;
    }
    storage.setItem(storageKey(suffix), JSON.stringify(value));
};

const removeStorage = (suffix) => {
    const storage = getStorage();
    if (!storage) {
        return;
    }
    storage.removeItem(storageKey(suffix));
};

const isStepDone = (step) => {
    if (!step) {
        return false;
    }
    const status = progressMap.value[step.key]?.status || 'pending';
    return status === 'done' || status === 'skipped';
};

const canAdvance = computed(() => {
    const step = currentStep.value;
    if (!step) {
        return false;
    }
    const completionType = step.completion?.type;
    if (completionType === 'event') {
        return isStepDone(step);
    }
    return true;
});

const progressText = computed(() => {
    if (!totalSteps.value) {
        return '';
    }
    return `${Math.min(currentIndex.value + 1, totalSteps.value)} / ${totalSteps.value}`;
});

const preferredPlacement = computed(() => currentStep.value?.placement || 'bottom');

const arrowClass = computed(() => {
    const placement = resolvedPlacement.value;
    if (!currentStep.value?.selector || placement === 'center') {
        return 'hidden';
    }
    if (placement === 'top') {
        return 'left-1/2 -translate-x-1/2 -bottom-1.5';
    }
    if (placement === 'bottom') {
        return 'left-1/2 -translate-x-1/2 -top-1.5';
    }
    if (placement === 'left') {
        return 'top-1/2 -translate-y-1/2 -right-1.5';
    }
    return 'top-1/2 -translate-y-1/2 -left-1.5';
});

const updateProgressMap = (stepKey, payload) => {
    progressMap.value = {
        ...progressMap.value,
        [stepKey]: {
            ...(progressMap.value[stepKey] || {}),
            ...payload,
        },
    };
    writeStorage('progress', progressMap.value);
};

const updateCurrentStepKey = () => {
    const step = currentStep.value;
    if (!step) {
        return;
    }
    writeStorage('current_step', step.key);
};

const restoreCurrentIndex = () => {
    if (!sortedSteps.value.length) {
        return;
    }
    const savedKey = readStorage('current_step');
    if (savedKey) {
        const index = sortedSteps.value.findIndex((step) => step.key === savedKey);
        if (index >= 0) {
            currentIndex.value = index;
            return;
        }
    }
    const pendingIndex = sortedSteps.value.findIndex((step) => !isStepDone(step));
    if (pendingIndex >= 0) {
        currentIndex.value = pendingIndex;
        return;
    }
    currentIndex.value = Math.max(sortedSteps.value.length - 1, 0);
};

const fetchSteps = async () => {
    const response = await axios.get(route('demo.tour.steps'), {
        headers: { Accept: 'application/json' },
    });
    steps.value = response?.data?.steps || [];
};

const fetchProgress = async () => {
    const response = await axios.get(route('demo.tour.progress'), {
        headers: { Accept: 'application/json' },
    });
    const progress = response?.data?.progress || [];
    const map = {};
    progress.forEach((entry) => {
        map[entry.step_key] = {
            status: entry.status,
            completed_at: entry.completed_at,
            metadata: entry.metadata,
        };
    });
    const local = readStorage('progress') || {};
    const statusRank = (status) => {
        if (status === 'done') {
            return 2;
        }
        if (status === 'skipped') {
            return 1;
        }
        return 0;
    };
    Object.entries(local).forEach(([stepKey, entry]) => {
        if (!entry?.status) {
            return;
        }
        const serverStatus = map[stepKey]?.status;
        if (statusRank(entry.status) > statusRank(serverStatus)) {
            map[stepKey] = entry;
        }
    });
    progressMap.value = map;
    writeStorage('progress', map);
};

const refreshSteps = async () => {
    try {
        await fetchSteps();
    } catch (error) {
        // Ignore refresh errors and keep existing steps.
    }
};

const loadTourState = async () => {
    if (!isGuided.value) {
        return;
    }
    isReady.value = false;
    statusMessage.value = '';
    try {
        await Promise.all([fetchSteps(), fetchProgress()]);
    } catch (error) {
        const storedProgress = readStorage('progress');
        if (storedProgress) {
            progressMap.value = storedProgress;
        }
    } finally {
        restoreCurrentIndex();
        isReady.value = true;
    }
};

const markStep = async (step, status, metadata = null) => {
    if (!step) {
        return;
    }
    updateProgressMap(step.key, {
        status,
        completed_at: status === 'done' || status === 'skipped' ? new Date().toISOString() : null,
        metadata,
    });
    try {
        const response = await axios.post(
            route('demo.tour.progress.update'),
            { step_key: step.key, status, metadata },
            { headers: { Accept: 'application/json' } }
        );
        const payload = response?.data?.progress;
        if (payload?.step_key) {
            updateProgressMap(payload.step_key, {
                status: payload.status,
                completed_at: payload.completed_at,
                metadata: payload.metadata,
            });
        }
    } catch (error) {
        // Keep local progress as fallback.
    }
};

const showCompletionMessage = (message) => {
    statusMessage.value = message;
    setTimeout(() => {
        if (statusMessage.value === message) {
            statusMessage.value = '';
        }
    }, 2500);
};

const markStepDone = async (step, metadata = null) => {
    if (!step || isStepDone(step)) {
        return;
    }
    await markStep(step, 'done', metadata);
    showCompletionMessage('Step completed. Click Next to continue.');
};

const markStepSkipped = async (step) => {
    if (!step) {
        return;
    }
    await markStep(step, 'skipped', { skipped_at: new Date().toISOString() });
    showCompletionMessage('Step skipped. Continue when ready.');
};

const waitForSelector = (selector, timeoutMs = 6000) => new Promise((resolve) => {
    if (!selector || typeof document === 'undefined') {
        resolve(null);
        return;
    }
    const started = Date.now();
    const check = () => {
        const element = document.querySelector(selector);
        if (element) {
            resolve(element);
            return;
        }
        if (Date.now() - started >= timeoutMs) {
            resolve(null);
            return;
        }
        requestAnimationFrame(check);
    };
    check();
});

const updateHighlight = () => {
    const element = targetEl.value;
    if (!element) {
        highlightRect.value = null;
        return;
    }
    const rect = element.getBoundingClientRect();
    const padding = 6;
    highlightRect.value = {
        top: rect.top - padding,
        left: rect.left - padding,
        width: rect.width + padding * 2,
        height: rect.height + padding * 2,
    };
};

const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

const resolvePlacement = (preferred, target, tooltipRect) => {
    const padding = 12;
    const spaces = {
        top: target.top - padding,
        bottom: window.innerHeight - (target.top + target.height) - padding,
        left: target.left - padding,
        right: window.innerWidth - (target.left + target.width) - padding,
    };

    const fits = (placement) => {
        if (placement === 'top' || placement === 'bottom') {
            return spaces[placement] >= tooltipRect.height + 12;
        }
        return spaces[placement] >= tooltipRect.width + 12;
    };

    const candidates = [preferred, 'bottom', 'top', 'right', 'left'].filter(
        (value, index, array) => value && array.indexOf(value) === index
    );
    for (const candidate of candidates) {
        if (candidate === 'center') {
            continue;
        }
        if (fits(candidate)) {
            return candidate;
        }
    }

    return Object.entries(spaces).sort((a, b) => b[1] - a[1])[0][0];
};

const updateTooltipPosition = () => {
    if (!tooltipRef.value) {
        return;
    }
    const tooltipRect = tooltipRef.value.getBoundingClientRect();
    const padding = 12;
    const target = highlightRect.value;

    let top = (window.innerHeight - tooltipRect.height) / 2;
    let left = (window.innerWidth - tooltipRect.width) / 2;
    let placement = preferredPlacement.value;

    if (!target || placement === 'center') {
        resolvedPlacement.value = 'center';
        tooltipStyle.value = { top: `${top}px`, left: `${left}px` };
        return;
    }

    placement = resolvePlacement(placement, target, tooltipRect);
    resolvedPlacement.value = placement;

    if (target && placement !== 'center') {
        if (placement === 'top') {
            top = target.top - tooltipRect.height - 12;
            left = target.left + (target.width - tooltipRect.width) / 2;
        } else if (placement === 'bottom') {
            top = target.top + target.height + 12;
            left = target.left + (target.width - tooltipRect.width) / 2;
        } else if (placement === 'left') {
            top = target.top + (target.height - tooltipRect.height) / 2;
            left = target.left - tooltipRect.width - 12;
        } else if (placement === 'right') {
            top = target.top + (target.height - tooltipRect.height) / 2;
            left = target.left + target.width + 12;
        }
    }

    top = clamp(top, padding, window.innerHeight - tooltipRect.height - padding);
    left = clamp(left, padding, window.innerWidth - tooltipRect.width - padding);
    tooltipStyle.value = { top: `${top}px`, left: `${left}px` };
};

const hasMissingParams = (params) => {
    if (!params) {
        return false;
    }
    return Object.values(params).some((value) => value === null || value === undefined || value === '');
};

const isOnStepRoute = (step) => {
    if (!step?.route_name) {
        return true;
    }
    try {
        return route().current(step.route_name, step.route_params || {});
    } catch (error) {
        return false;
    }
};

const navigateToStep = (step) => {
    if (!step?.route_name) {
        return;
    }
    if (isOnStepRoute(step)) {
        return;
    }
    if (hasMissingParams(step.route_params || {})) {
        statusMessage.value = 'Complete previous steps to unlock this page.';
        return;
    }
    router.visit(route(step.route_name, step.route_params || {}), {
        preserveScroll: true,
    });
};

const attachToStep = async () => {
    const step = currentStep.value;
    if (!step || !isOpen.value) {
        return;
    }
    updateCurrentStepKey();
    statusMessage.value = '';
    targetEl.value = null;
    highlightRect.value = null;

    if (!isOnStepRoute(step)) {
        statusMessage.value = 'Navigate to this page to continue.';
        await nextTick();
        updateTooltipPosition();
        return;
    }

    if (!step.selector) {
        await nextTick();
        updateTooltipPosition();
        return;
    }

    isFindingTarget.value = true;
    const element = await waitForSelector(step.selector);
    isFindingTarget.value = false;
    if (!element) {
        statusMessage.value = 'We could not find the highlighted item on this screen.';
        await nextTick();
        updateTooltipPosition();
        return;
    }
    targetEl.value = element;
    updateHighlight();
    if (element.scrollIntoView) {
        element.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    await nextTick();
    updateTooltipPosition();

    if (step.completion?.type === 'view') {
        markStepDone(step, { auto: 'view' });
    }
};

const goToStep = (index, navigate = true) => {
    if (index < 0 || index >= sortedSteps.value.length) {
        return;
    }
    currentIndex.value = index;
    if (navigate) {
        navigateToStep(sortedSteps.value[index]);
    }
};

const goNext = async () => {
    const step = currentStep.value;
    if (!step) {
        return;
    }
    const completion = step.completion?.type || 'manual';
    if (!isStepDone(step) && completion !== 'event') {
        await markStepDone(step, { action: 'next' });
    }
    const nextIndex = currentIndex.value + 1;
    if (nextIndex >= sortedSteps.value.length) {
        isOpen.value = false;
        return;
    }
    goToStep(nextIndex);
};

const goBack = () => {
    const prevIndex = currentIndex.value - 1;
    if (prevIndex < 0) {
        return;
    }
    goToStep(prevIndex);
};

const skipStep = async () => {
    const step = currentStep.value;
    if (!step) {
        return;
    }
    await markStepSkipped(step);
    const nextIndex = currentIndex.value + 1;
    if (nextIndex >= sortedSteps.value.length) {
        isOpen.value = false;
        return;
    }
    goToStep(nextIndex);
};

const closeTour = () => {
    isOpen.value = false;
};

const resetTour = async () => {
    try {
        await axios.post(route('demo.tour.reset'), {}, { headers: { Accept: 'application/json' } });
    } catch (error) {
        // Ignore API errors and rely on local reset.
    }
    progressMap.value = {};
    removeStorage('progress');
    removeStorage('current_step');
    currentIndex.value = 0;
    isOpen.value = true;
    await nextTick();
    attachToStep();
};

const handleEventCompletion = async (eventName) => {
    const step = sortedSteps.value.find(
        (entry) => entry.completion?.type === 'event' && entry.completion?.event === eventName
    );
    if (step) {
        await markStepDone(step, { event: eventName });
        await refreshSteps();
    }
};

const handleRestart = () => {
    resetTour();
};

const handleResetComplete = async () => {
    progressMap.value = {};
    removeStorage('progress');
    removeStorage('current_step');
    await loadTourState();
};

const handleViewportChange = () => {
    updateHighlight();
    updateTooltipPosition();
};

let restartListener = null;
let resetListener = null;
let eventListeners = [];

const bindEventListeners = () => {
    if (typeof window === 'undefined') {
        return;
    }
    restartListener = () => handleRestart();
    window.addEventListener('demo:restart-tour', restartListener);
    resetListener = () => handleResetComplete();
    window.addEventListener('demo:reset-complete', resetListener);

    const uniqueEvents = Array.from(new Set(
        sortedSteps.value
            .map((step) => step.completion?.event)
            .filter(Boolean)
    ));
    eventListeners = uniqueEvents.map((eventName) => {
        const handler = () => handleEventCompletion(eventName);
        window.addEventListener(eventName, handler);
        return { eventName, handler };
    });
};

const unbindEventListeners = () => {
    if (typeof window === 'undefined') {
        return;
    }
    if (restartListener) {
        window.removeEventListener('demo:restart-tour', restartListener);
    }
    if (resetListener) {
        window.removeEventListener('demo:reset-complete', resetListener);
    }
    eventListeners.forEach(({ eventName, handler }) => {
        window.removeEventListener(eventName, handler);
    });
    eventListeners = [];
    restartListener = null;
    resetListener = null;
};

watch([sortedSteps, progressMap], () => {
    if (!sortedSteps.value.length) {
        return;
    }
    restoreCurrentIndex();
});

watch(currentStep, () => {
    if (!isReady.value) {
        return;
    }
    attachToStep();
});

watch(() => page.url, () => {
    if (!isReady.value) {
        return;
    }
    attachToStep();
});

watch(sortedSteps, () => {
    unbindEventListeners();
    bindEventListeners();
});

onMounted(async () => {
    if (!isGuided.value) {
        return;
    }
    await loadTourState();
    bindEventListeners();
    attachToStep();
    window.addEventListener('resize', handleViewportChange);
    window.addEventListener('scroll', handleViewportChange, true);
});

onBeforeUnmount(() => {
    unbindEventListeners();
    window.removeEventListener('resize', handleViewportChange);
    window.removeEventListener('scroll', handleViewportChange, true);
});
</script>

<template>
    <div>
        <slot />
        <Teleport to="body">
            <div v-if="isGuided && isReady && hasStep && isOpen" class="fixed inset-0 z-[120]">
                <div
                    v-if="highlightRect"
                    class="absolute rounded-lg border border-emerald-400/80 shadow-[0_0_0_9999px_rgba(15,23,42,0.55)]"
                    :style="{
                        top: `${highlightRect.top}px`,
                        left: `${highlightRect.left}px`,
                        width: `${highlightRect.width}px`,
                        height: `${highlightRect.height}px`,
                    }"
                ></div>
                <div
                    ref="tooltipRef"
                    class="absolute z-[130] w-80 max-w-xs rounded-sm border border-stone-200 bg-white p-4 text-sm text-stone-700 shadow-lg dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                    :style="tooltipStyle"
                >
                    <div class="absolute h-3 w-3 rotate-45 border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900" :class="arrowClass"></div>
                    <div class="flex items-start justify-between gap-2">
                        <div class="text-xs font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-400">
                            Guided demo
                        </div>
                        <button
                            type="button"
                            class="text-xs text-stone-400 hover:text-stone-600 dark:text-neutral-500 dark:hover:text-neutral-300"
                            @click="closeTour"
                        >
                            Close
                        </button>
                    </div>
                    <h3 class="mt-2 text-base font-semibold text-stone-800 dark:text-neutral-100">
                        {{ currentStep.title }}
                    </h3>
                    <p class="mt-1 text-sm text-stone-600 dark:text-neutral-300">
                        {{ currentStep.description }}
                    </p>
                    <p v-if="statusMessage" class="mt-2 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                        {{ statusMessage }}
                    </p>
                    <p v-if="isFindingTarget" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                        Locating the highlighted element...
                    </p>
                    <div class="mt-4 flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                        <span>Step {{ progressText }}</span>
                        <span v-if="!isOnStepRoute(currentStep)">Navigate to continue</span>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                :disabled="currentIndex === 0"
                                @click="goBack"
                            >
                                Back
                            </button>
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                @click="skipStep"
                            >
                                Skip
                            </button>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                v-if="!isOnStepRoute(currentStep)"
                                type="button"
                                class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"
                                @click="navigateToStep(currentStep)"
                            >
                                Open page
                            </button>
                            <button
                                type="button"
                                class="rounded-sm border border-emerald-600 bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                                :disabled="!canAdvance"
                                @click="goNext"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
